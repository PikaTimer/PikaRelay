<?php

declare(strict_types=1);

namespace PikaRelay\Auth;

use PikaRelay\Clock;
use PikaRelay\Config;
use PikaRelay\Db\Database;
use PikaRelay\Http\HttpException;
use PikaRelay\Http\Request;

/**
 * API key checks for the REST endpoints.
 *
 * In secure mode every call carries X-Pika-Key and X-Pika-Secret. A key has one role:
 *   reader - a PikaReader: post status, post reads, fetch and acknowledge commands
 *   client - a PikaDownloader: read status and reads, post commands
 * In insecure mode no credentials are needed.
 */
final class ApiAuth
{
    public const ROLE_READER = 'reader';
    public const ROLE_CLIENT = 'client';
    public const ROLES = [self::ROLE_READER, self::ROLE_CLIENT];

    /** last_used_ms is only rewritten when it is older than this, to avoid a write per poll. */
    private const LAST_USED_GRANULARITY_MS = 60000;

    /** @var Config */
    private $config;

    /** @var Database */
    private $db;

    /** @var Clock */
    private $clock;

    public function __construct(Config $config, Database $db, Clock $clock)
    {
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock;
    }

    public static function hashSecret(string $secret, string $pepper): string
    {
        return hash_hmac('sha256', $secret, $pepper);
    }

    /**
     * Checks the caller's credentials. Returns the API key row id, or null in insecure mode.
     *
     * @param string[] $roles roles allowed to make this call
     */
    public function requireRole(Request $request, array $roles): ?int
    {
        if (!$this->config->isSecure()) {
            return null;
        }
        $key = (string) $request->header('x-pika-key');
        $secret = (string) $request->header('x-pika-secret');
        if ($key === '' || $secret === '') {
            throw new HttpException(401, 'Missing X-Pika-Key / X-Pika-Secret headers');
        }
        if (!preg_match('/^[\x21-\x7E]{1,128}$/', $key) || strlen($secret) > 128) {
            throw new HttpException(401, 'Invalid API key or secret');
        }

        $row = $this->db->one('SELECT id, secret_hash, role, last_used_ms FROM api_keys WHERE api_key = ?', [$key]);
        $expected = $row === null ? str_repeat('0', 64) : (string) $row['secret_hash'];
        $actual = self::hashSecret($secret, (string) $this->config->get('pepper'));
        if (!hash_equals($expected, $actual) || $row === null) {
            throw new HttpException(401, 'Invalid API key or secret');
        }
        if (!in_array($row['role'], $roles, true)) {
            throw new HttpException(403, 'This API key is not allowed to make this request');
        }

        $now = $this->clock->nowMs();
        if ($row['last_used_ms'] === null || $now - (int) $row['last_used_ms'] >= self::LAST_USED_GRANULARITY_MS) {
            $this->db->run('UPDATE api_keys SET last_used_ms = ? WHERE id = ?', [$now, (int) $row['id']]);
        }
        return (int) $row['id'];
    }
}
