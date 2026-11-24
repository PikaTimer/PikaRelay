<?php

declare(strict_types=1);

namespace PikaRelay;

use RuntimeException;

/**
 * The relay configuration, stored as a PHP file that returns an array so a misconfigured
 * web server never serves its contents as text.
 *
 * Keys:
 *   db              array  driver ('sqlite'|'mysql'), sqlite_path, host, port, name, user, password
 *   admin_user      string admin login name
 *   admin_hash      string password_hash() of the admin password; '' puts the login into recovery mode
 *   secure_mode     bool   true = REST calls need an API key/secret
 *   pepper          string server secret mixed into API secret hashes
 *   settle_ms       int    getReads only returns reads received at least this long ago
 *   command_ttl_sec int    commands expire after this many seconds
 *   max_rewind_days int    longest time range a rewind command may request
 */
final class Config
{
    public const DEFAULTS = [
        'db' => [],
        'admin_user' => 'admin',
        'admin_hash' => '',
        'secure_mode' => true,
        'pepper' => '',
        'settle_ms' => 2000,
        'command_ttl_sec' => 600,
        'max_rewind_days' => 7,
    ];

    /** @var string */
    private $path;

    /** @var array<string, mixed> */
    private $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $path, array $data)
    {
        $this->path = $path;
        $this->data = $data;
    }

    public static function defaultPath(): string
    {
        $env = getenv('PIKA_CONFIG');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return PIKA_ROOT . '/config/config.php';
    }

    /**
     * Loads the config, or returns null when the file does not exist (the relay is not set up).
     */
    public static function load(string $path): ?Config
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new RuntimeException('Config file ' . $path . ' is invalid: it must return an array.');
        }
        return new Config($path, $data);
    }

    /**
     * Writes a brand-new config file. Fails if one already exists, so setup cannot be re-run
     * over an existing installation.
     *
     * @param array<string, mixed> $data
     */
    public static function create(string $path, array $data): Config
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create ' . $path . ' (it already exists or the folder is not writable).');
        }
        $contents = self::render($data);
        $ok = fwrite($handle, $contents) === strlen($contents);
        $ok = fclose($handle) && $ok;
        if (!$ok) {
            @unlink($path);
            throw new RuntimeException('Could not write ' . $path . '.');
        }
        @chmod($path, 0600);
        self::invalidateCache($path);
        return new Config($path, $data);
    }

    /**
     * @return mixed
     */
    public function get(string $key)
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        return self::DEFAULTS[$key] ?? null;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isSecure(): bool
    {
        return (bool) $this->get('secure_mode');
    }

    /**
     * Atomically replaces the config file with the current values.
     */
    public function save(): void
    {
        $dir = dirname($this->path);
        $tmp = $dir . '/.config.php.' . bin2hex(random_bytes(6));
        $contents = self::render($this->data);
        if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($tmp);
            throw new RuntimeException('Could not write a new config file in ' . $dir . '. Check that PHP can write to that folder.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace ' . $this->path . '. Check that PHP can write to it.');
        }
        self::invalidateCache($this->path);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function render(array $data): string
    {
        return "<?php\n"
            . "// PikaRelay configuration, written by the setup and admin pages.\n"
            . "// Admin password recovery: set 'admin_hash' => '' and log in with a blank password.\n"
            . 'return ' . var_export($data, true) . ";\n";
    }

    private static function invalidateCache(string $path): void
    {
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
