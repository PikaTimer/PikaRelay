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

namespace PikaRelay\Api;

use PikaRelay\Auth\ApiAuth;
use PikaRelay\Clock;
use PikaRelay\Db\Database;
use PikaRelay\Http\HttpException;
use PikaRelay\Http\Request;
use PikaRelay\Http\Response;
use PikaRelay\Http\Validator;

/**
 * Command queue: PikaDownloader clients post commands; the PikaReader polls, runs and
 * acknowledges them. Commands expire so a reader that comes back online much later never
 * runs a stale one. There is deliberately no "stop" command.
 */
final class CommandsController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var Database */
    private $db;

    /** @var ApiAuth */
    private $auth;

    /** @var Clock */
    private $clock;

    /** @var int */
    private $ttlMs;

    /** @var int */
    private $maxRewindMs;

    public function __construct(Database $db, ApiAuth $auth, Clock $clock, int $ttlMs, int $maxRewindMs)
    {
        $this->db = $db;
        $this->auth = $auth;
        $this->clock = $clock;
        $this->ttlMs = $ttlMs;
        $this->maxRewindMs = $maxRewindMs;
    }

    /** POST /commands/{id} */
    public function post(Request $request, string $readerId): Response
    {
        $keyId = $this->auth->requireRole($request, [ApiAuth::ROLE_CLIENT]);
        $readerId = Validator::readerId($readerId);
        $command = Validator::command(Validator::jsonBody($request, self::MAX_BODY_BYTES), $this->maxRewindMs);
        if ($this->db->value('SELECT 1 FROM readers WHERE id = ?', [$readerId]) === null) {
            throw new HttpException(404, 'Unknown reader');
        }
        $now = $this->clock->nowMs();
        $expires = $now + $this->ttlMs;
        $this->db->run(
            'INSERT INTO commands (reader_id, command, args_json, created_ms, expires_ms, api_key_id) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $readerId,
                $command['command'],
                (string) json_encode((object) $command['args'], Response::JSON_FLAGS | JSON_THROW_ON_ERROR),
                $now,
                $expires,
                $keyId,
            ]
        );
        $id = (int) $this->db->pdo()->lastInsertId();
        return Response::json(['id' => $id, 'created' => $now, 'expires' => $expires], 201);
    }

    /** GET /commands/{id}: pending (not acknowledged, not expired) commands, oldest first. */
    public function pending(Request $request, string $readerId): Response
    {
        $this->auth->requireRole($request, [ApiAuth::ROLE_READER]);
        $readerId = Validator::readerId($readerId);
        $now = $this->clock->nowMs();
        $rows = $this->db->all(
            'SELECT id, command, args_json, created_ms, expires_ms FROM commands'
            . ' WHERE reader_id = ? AND acked_ms IS NULL AND expires_ms > ? ORDER BY id',
            [$readerId, $now]
        );
        $commands = [];
        foreach ($rows as $row) {
            $args = json_decode((string) $row['args_json'], true);
            $commands[] = ['id' => (int) $row['id'], 'command' => (string) $row['command']]
                + (is_array($args) ? $args : [])
                + ['created' => (int) $row['created_ms'], 'expires' => (int) $row['expires_ms']];
        }
        return Response::json(['serverTime' => $now, 'commands' => $commands]);
    }

    /** DELETE /commands/{id}/{commandId}: acknowledge a command. Repeating it is harmless. */
    public function ack(Request $request, string $readerId, string $commandId): Response
    {
        $this->auth->requireRole($request, [ApiAuth::ROLE_READER]);
        $readerId = Validator::readerId($readerId);
        $id = Validator::idSegment($commandId, 'command id');
        $now = $this->clock->nowMs();
        $updated = $this->db->run(
            'UPDATE commands SET acked_ms = ? WHERE id = ? AND reader_id = ? AND acked_ms IS NULL',
            [$now, $id, $readerId]
        )->rowCount();
        if ($updated === 0
            && $this->db->value('SELECT 1 FROM commands WHERE id = ? AND reader_id = ?', [$id, $readerId]) === null) {
            throw new HttpException(404, 'Unknown command');
        }
        return Response::noContent();
    }
}
