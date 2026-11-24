<?php

declare(strict_types=1);

namespace PikaRelay\Auth;

use PikaRelay\Clock;
use PikaRelay\Config;
use PikaRelay\Db\Database;
use PikaRelay\Http\HttpException;
use PikaRelay\Http\Request;

/**
 * Session login for the single admin account stored in the config file.
 *
 * Recovery: when the config's admin_hash is empty, any user name with a blank password is
 * accepted and the admin is sent straight to the set-password page.
 */
final class AdminAuth
{
    public const MIN_PASSWORD = 8;
    public const USERNAME = '/^[A-Za-z0-9._@-]{1,64}$/';

    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW_MS = 900000;   // 15 minutes
    private const IDLE_TIMEOUT_MS = 14400000;   // 4 hours

    /** @var Config */
    private $config;

    /** @var Database|null */
    private $db;

    /** @var Clock */
    private $clock;

    /** @var Request */
    private $request;

    public function __construct(Config $config, ?Database $db, Clock $clock, Request $request)
    {
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock;
        $this->request = $request;
    }

    public static function startSession(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        session_name('PIKARELAY');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $request->basePath() . '/',
            'secure' => $request->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start([
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'use_trans_sid' => 0,
        ]);
    }

    public function isRecoveryMode(): bool
    {
        return (string) $this->config->get('admin_hash') === '';
    }

    public function isLoggedIn(): bool
    {
        if (empty($_SESSION['admin'])) {
            return false;
        }
        $now = $this->clock->nowMs();
        if ($now - (int) ($_SESSION['seen_ms'] ?? 0) > self::IDLE_TIMEOUT_MS) {
            $this->logout();
            return false;
        }
        $_SESSION['seen_ms'] = $now;
        return true;
    }

    /**
     * Returns null on success, or an error message for the login form.
     */
    public function login(string $username, string $password): ?string
    {
        $ip = $this->request->ip();
        $now = $this->clock->nowMs();
        if ($this->db !== null) {
            $failures = (int) $this->db->value(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempt_ms > ?',
                [$ip, $now - self::FAILURE_WINDOW_MS]
            );
            if ($failures >= self::MAX_FAILURES) {
                return 'Too many failed logins. Wait 15 minutes and try again.';
            }
        }

        if ($this->isRecoveryMode()) {
            $ok = $password === '';
        } else {
            $ok = hash_equals((string) $this->config->get('admin_user'), $username)
                && password_verify($password, (string) $this->config->get('admin_hash'));
        }

        if (!$ok) {
            if ($this->db !== null) {
                $this->db->run('INSERT INTO login_attempts (ip, attempt_ms) VALUES (?, ?)', [$ip, $now]);
                $this->db->run('DELETE FROM login_attempts WHERE attempt_ms < ?', [$now - 86400000]);
            }
            return 'Wrong user name or password.';
        }

        if ($this->db !== null) {
            $this->db->run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['admin'] = true;
        $_SESSION['seen_ms'] = $now;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public function checkCsrf(): void
    {
        $sent = $this->request->form('csrf');
        $expected = isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
        if ($expected === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(403, 'Your session has expired or the form is stale. Reload the page and try again.');
        }
    }

    /**
     * Stores a one-time message shown on the next page.
     *
     * @param array<string, string> $extra
     */
    public static function flash(string $type, string $message, array $extra = []): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message] + $extra;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function takeFlash(): array
    {
        $flash = isset($_SESSION['flash']) && is_array($_SESSION['flash']) ? $_SESSION['flash'] : [];
        unset($_SESSION['flash']);
        return $flash;
    }
}
