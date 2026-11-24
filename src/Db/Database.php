<?php
/*
 * Copyright (C) 2026 John Garner
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


declare(strict_types=1);

namespace PikaRelay\Db;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Thin PDO wrapper over SQLite or MySQL/MariaDB. All SQL elsewhere is written to run on both;
 * the few dialect differences are handled here and in Schema.
 *
 * Note: PDO on PHP 7.4 returns SQLite numbers as strings, so callers cast numeric columns.
 */
final class Database
{
    /** @var PDO */
    private $pdo;

    /** @var string 'sqlite' or 'mysql' */
    private $driver;

    public function __construct(PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo = $pdo;
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->driver !== 'sqlite' && $this->driver !== 'mysql') {
            throw new RuntimeException('Unsupported database driver: ' . $this->driver);
        }
    }

    /**
     * @param array<string, mixed> $db the 'db' section of the config
     */
    public static function fromConfig(array $db, string $root): Database
    {
        $driver = (string) ($db['driver'] ?? '');
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . self::sqlitePath((string) ($db['sqlite_path'] ?? ''), $root), null, null, [
                PDO::ATTR_TIMEOUT => 10,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            try {
                // WAL lets readers and writers work concurrently; not every filesystem supports it.
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA synchronous = NORMAL');
            } catch (Throwable $e) {
                // Fall back to the default rollback journal.
            }
            return new Database($pdo);
        }
        if ($driver === 'mysql') {
            $dsn = 'mysql:host=' . ($db['host'] ?? 'localhost')
                . ';port=' . (int) ($db['port'] ?? 3306)
                . ';dbname=' . ($db['name'] ?? '')
                . ';charset=utf8mb4';
            $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]);
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
            return new Database($pdo);
        }
        throw new RuntimeException('Config has no valid database driver');
    }

    /**
     * Resolves a configured SQLite path; relative paths are relative to the install folder.
     */
    public static function sqlitePath(string $path, string $root): string
    {
        if ($path === '') {
            throw new RuntimeException('Config has no SQLite database path');
        }
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path;
        }
        return rtrim($root, '/') . '/' . $path;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return mixed first column of the first row, or null
     */
    public function value(string $sql, array $params = [])
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * INSERT that silently skips rows that would violate a unique key. $noopColumn is any
     * column of the table (MySQL needs one for its ON DUPLICATE KEY no-op). rowCount() of the
     * executed statement is 1 for an inserted row and 0 for a skipped one on both drivers.
     *
     * @param string[] $columns
     */
    public function insertIgnoreSql(string $table, array $columns, string $noopColumn): string
    {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $cols = implode(', ', $columns);
        if ($this->driver === 'sqlite') {
            return "INSERT OR IGNORE INTO $table ($cols) VALUES ($placeholders)";
        }
        return "INSERT INTO $table ($cols) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $noopColumn = $noopColumn";
    }

    /**
     * Runs $fn inside a transaction and returns its result.
     *
     * @return mixed
     */
    public function transaction(callable $fn)
    {
        if ($this->driver === 'sqlite') {
            // Take the write lock up front so concurrent writers wait (busy_timeout) instead of deadlocking.
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $fn($this);
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ensures a readers row exists for $readerId.
     */
    public function ensureReader(string $readerId, int $nowMs): void
    {
        $this->run($this->insertIgnoreSql('readers', ['id', 'first_seen_ms'], 'id'), [$readerId, $nowMs]);
    }
}
