<?php

declare(strict_types=1);

namespace PikaRelay\Web;

use PikaRelay\App;
use PikaRelay\Auth\AdminAuth;
use PikaRelay\Config;
use PikaRelay\Db\Database;
use PikaRelay\Db\Schema;
use PikaRelay\Http\HttpException;
use PikaRelay\Http\Request;
use PikaRelay\Http\Response;
use Throwable;

/**
 * First-run setup. Only reachable while no config file exists: writing the config is what
 * disables it, and Config::create refuses to overwrite an existing file.
 */
final class SetupController
{
    public const SQLITE_PATH = '#^[A-Za-z0-9_./ -]{1,240}\.(sqlite|sqlite3|db)$#';
    public const MYSQL_HOST = '/^[A-Za-z0-9.\-]{1,253}$/';
    public const MYSQL_NAME = '/^[A-Za-z0-9_$\-]{1,64}$/';
    public const MYSQL_USER = '/^[\x21-\x7E]{1,80}$/';

    /** @var App */
    private $app;

    /** @var Request */
    private $request;

    public function __construct(App $app, Request $request)
    {
        $this->app = $app;
        $this->request = $request;
    }

    public function handle(): Response
    {
        if ($this->request->method() === 'POST' && $this->request->path() === '/setup') {
            return $this->submit();
        }
        if ($this->request->method() !== 'GET') {
            throw new HttpException(405, 'Method not allowed', ['Allow' => 'GET']);
        }
        return $this->form([
            'admin_user' => 'admin',
            'driver' => extension_loaded('pdo_sqlite') ? 'sqlite' : 'mysql',
            'sqlite_path' => 'data/pikarelay.sqlite',
            'mysql_host' => 'localhost',
            'mysql_port' => '3306',
            'mysql_name' => '',
            'mysql_user' => '',
            'secure_mode' => '1',
        ], []);
    }

    private function submit(): Response
    {
        $r = $this->request;
        $values = [];
        foreach (['admin_user', 'driver', 'sqlite_path', 'mysql_host', 'mysql_port', 'mysql_name', 'mysql_user'] as $field) {
            $values[$field] = trim($r->form($field));
        }
        $values['secure_mode'] = $r->form('secure_mode') === '1' ? '1' : '';
        $password = $r->form('password');
        $mysqlPassword = $r->form('mysql_password');

        $errors = [];
        if (!preg_match(AdminAuth::USERNAME, $values['admin_user'])) {
            $errors[] = 'User name: use 1-64 letters, digits, ".", "_", "@" or "-".';
        }
        if (strlen($password) < AdminAuth::MIN_PASSWORD || strlen($password) > 200) {
            $errors[] = 'Password: use ' . AdminAuth::MIN_PASSWORD . ' to 200 characters.';
        } elseif (!hash_equals($password, $r->form('password2'))) {
            $errors[] = 'The two passwords do not match.';
        }

        $db = ['driver' => $values['driver']];
        if ($values['driver'] === 'sqlite' && extension_loaded('pdo_sqlite')) {
            if (!preg_match(self::SQLITE_PATH, $values['sqlite_path'])) {
                $errors[] = 'SQLite file: use letters, digits, "/", ".", "_", "-" or spaces, ending in .sqlite, .sqlite3 or .db.';
            } else {
                $db['sqlite_path'] = $values['sqlite_path'];
                $dir = dirname(Database::sqlitePath($values['sqlite_path'], PIKA_ROOT));
                if (!is_dir($dir) || !is_writable($dir)) {
                    $errors[] = 'SQLite file: the folder ' . $dir . ' does not exist or PHP cannot write to it.';
                }
            }
        } elseif ($values['driver'] === 'mysql' && extension_loaded('pdo_mysql')) {
            if (!preg_match(self::MYSQL_HOST, $values['mysql_host'])) {
                $errors[] = 'MySQL host: use a host name or IP address.';
            }
            if (!preg_match('/^[0-9]{1,5}$/', $values['mysql_port']) || (int) $values['mysql_port'] < 1 || (int) $values['mysql_port'] > 65535) {
                $errors[] = 'MySQL port: use a number from 1 to 65535.';
            }
            if (!preg_match(self::MYSQL_NAME, $values['mysql_name'])) {
                $errors[] = 'MySQL database: use 1-64 letters, digits, "_", "$" or "-".';
            }
            if (!preg_match(self::MYSQL_USER, $values['mysql_user'])) {
                $errors[] = 'MySQL user: use 1-80 printable characters without spaces.';
            }
            if (strlen($mysqlPassword) > 256 || preg_match('/[\x00-\x1F\x7F]/', $mysqlPassword)) {
                $errors[] = 'MySQL password: control characters are not allowed.';
            }
            $db += [
                'host' => $values['mysql_host'],
                'port' => (int) $values['mysql_port'],
                'name' => $values['mysql_name'],
                'user' => $values['mysql_user'],
                'password' => $mysqlPassword,
            ];
        } else {
            $errors[] = 'Choose a database type that this server supports.';
        }
        foreach ($this->checks() as $check) {
            if (!$check['ok'] && $check['fatal']) {
                $errors[] = $check['label'] . ': ' . $check['detail'];
            }
        }
        if ($errors) {
            return $this->form($values, $errors, 422);
        }

        try {
            $database = Database::fromConfig($db, PIKA_ROOT);
            Schema::install($database);
        } catch (Throwable $e) {
            return $this->form($values, ['Could not set up the database: ' . $e->getMessage()], 422);
        }
        if ($db['driver'] === 'sqlite') {
            @chmod(Database::sqlitePath($db['sqlite_path'], PIKA_ROOT), 0600);
        }

        try {
            Config::create(Config::defaultPath(), [
                'db' => $db,
                'admin_user' => $values['admin_user'],
                'admin_hash' => password_hash($password, PASSWORD_DEFAULT),
                'secure_mode' => $values['secure_mode'] === '1',
                'pepper' => bin2hex(random_bytes(32)),
                'settle_ms' => Config::DEFAULTS['settle_ms'],
                'command_ttl_sec' => Config::DEFAULTS['command_ttl_sec'],
                'max_rewind_days' => Config::DEFAULTS['max_rewind_days'],
                'created' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (Throwable $e) {
            return $this->form($values, [$e->getMessage()], 500);
        }

        return View::page($this->request, 'setup_done', 'PikaRelay set up', [
            'secure' => $values['secure_mode'] === '1',
        ]);
    }

    /**
     * @param array<string, string> $values
     * @param string[]              $errors
     */
    private function form(array $values, array $errors, int $status = 200): Response
    {
        return View::page($this->request, 'setup', 'Set up PikaRelay', [
            'values' => $values,
            'errors' => $errors,
            'checks' => $this->checks(),
            'hasSqlite' => extension_loaded('pdo_sqlite'),
            'hasMysql' => extension_loaded('pdo_mysql'),
        ], [], $status);
    }

    /**
     * @return array<int, array{label: string, ok: bool, detail: string, fatal: bool}>
     */
    private function checks(): array
    {
        $configDir = dirname(Config::defaultPath());
        $dataDir = PIKA_ROOT . '/data';
        $hasDriver = extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql');
        return [
            ['label' => 'PHP version', 'ok' => PHP_VERSION_ID >= 70400, 'detail' => PHP_VERSION, 'fatal' => true],
            ['label' => '64-bit PHP', 'ok' => PHP_INT_SIZE === 8, 'detail' => PHP_INT_SIZE === 8 ? 'yes' : 'Epoch-millisecond times need 64-bit PHP', 'fatal' => true],
            ['label' => 'Database drivers', 'ok' => $hasDriver,
                'detail' => $hasDriver
                    ? implode(', ', array_filter([extension_loaded('pdo_sqlite') ? 'SQLite' : '', extension_loaded('pdo_mysql') ? 'MySQL/MariaDB' : '']))
                    : 'Neither pdo_sqlite nor pdo_mysql is enabled',
                'fatal' => true],
            ['label' => 'Config folder writable', 'ok' => is_dir($configDir) && is_writable($configDir),
                'detail' => $configDir, 'fatal' => true],
            ['label' => 'Data folder writable', 'ok' => is_dir($dataDir) && is_writable($dataDir),
                'detail' => $dataDir . ' (only needed for an SQLite database stored there)', 'fatal' => false],
            ['label' => 'HTTPS', 'ok' => $this->request->isHttps(),
                'detail' => $this->request->isHttps() ? 'yes' : 'Not in use. Passwords and API secrets would cross the internet unencrypted.',
                'fatal' => false],
        ];
    }
}
