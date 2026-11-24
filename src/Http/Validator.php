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

namespace PikaRelay\Http;

use stdClass;

/**
 * Input validation. PikaRelay is internet-facing, so input is validated and rejected
 * (422/413) when it does not fit; it is never "cleaned up" and accepted.
 */
final class Validator
{
    public const READER_ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';
    /** Keys PikaReader sends include spaces and slashes (e.g. "Raw Tag Reads/s"). */
    public const JSON_KEY = '#^[A-Za-z0-9 _/.\-]{1,64}$#';
    public const CHIP = '/^[0-9A-Fa-f]{1,64}$/';
    public const PROFILE = '/^[A-Za-z0-9][A-Za-z0-9 _.()\-]{0,63}$/';
    /** API keys and secrets travel in HTTP headers: printable ASCII, no spaces. */
    public const CREDENTIAL = '/^[\x21-\x7E]{4,128}$/';

    /** 2100-01-01T00:00:00Z; anything later is not a plausible timestamp. */
    public const MAX_EPOCH_MS = 4102444800000;

    public const COMMANDS = ['start', 'rewind', 'setProfile', 'forceSetProfile'];

    private const MAX_STRING = 1024;
    private const MAX_OBJECT_KEYS = 200;
    private const MAX_ARRAY_ITEMS = 1000;
    private const MAX_READ_KEYS = 32;

    public static function readerId(string $id): string
    {
        if (!preg_match(self::READER_ID, $id)) {
            throw new HttpException(422, 'Invalid reader id: use 1-64 letters, digits, ".", "_" or "-", starting with a letter or digit');
        }
        return $id;
    }

    /**
     * A non-negative epoch-millisecond value from a URL segment.
     */
    public static function epochMsSegment(string $value, string $name): int
    {
        if (!preg_match('/^[0-9]{1,13}$/', $value) || (int) $value > self::MAX_EPOCH_MS) {
            throw new HttpException(422, $name . ' must be a time in epoch milliseconds');
        }
        return (int) $value;
    }

    public static function idSegment(string $value, string $name): int
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/', $value)) {
            throw new HttpException(422, $name . ' must be a positive integer');
        }
        return (int) $value;
    }

    /**
     * Checks the Content-Type, reads at most $maxBytes and decodes the JSON. Objects decode to
     * stdClass so that {} and [] stay distinguishable.
     *
     * @return mixed
     */
    public static function jsonBody(Request $request, int $maxBytes)
    {
        $type = strtolower(trim((string) $request->header('content-type')));
        if ($type !== 'application/json' && strpos($type, 'application/json;') !== 0) {
            throw new HttpException(415, 'Content-Type must be application/json');
        }
        $body = $request->body($maxBytes);
        if (trim($body) === '') {
            throw new HttpException(422, 'Request body is empty');
        }
        $data = json_decode($body, false, 10, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException(422, 'Request body is not valid JSON: ' . json_last_error_msg());
        }
        return $data;
    }

    /**
     * Generic shape check for JSON that comes from a PikaReader. Its keys change over time, so
     * only the form is checked: key names, string contents, sizes and nesting depth.
     *
     * @param mixed $value
     */
    public static function readerJson($value, string $where, int $depth = 0): void
    {
        if ($depth > 8) {
            throw new HttpException(422, $where . ' is nested too deeply');
        }
        if ($value instanceof stdClass) {
            $props = get_object_vars($value);
            if (count($props) > self::MAX_OBJECT_KEYS) {
                throw new HttpException(422, $where . ' has too many keys');
            }
            foreach ($props as $key => $child) {
                $key = (string) $key;
                if (!preg_match(self::JSON_KEY, $key)) {
                    throw new HttpException(422, $where . ' has an invalid key name');
                }
                self::readerJson($child, $where . '.' . $key, $depth + 1);
            }
        } elseif (is_array($value)) {
            if (count($value) > self::MAX_ARRAY_ITEMS) {
                throw new HttpException(422, $where . ' has too many items');
            }
            foreach ($value as $i => $child) {
                self::readerJson($child, $where . '[' . $i . ']', $depth + 1);
            }
        } elseif (is_string($value)) {
            self::plainString($value, $where);
        } elseif (!is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
            throw new HttpException(422, $where . ' has an unsupported value');
        }
    }

    /**
     * Validates one tag read and returns its indexed fields plus its normalized JSON.
     *
     * @param mixed $read
     * @return array{chip: string, epochMilli: int, reader: int, antenna: int, json: string}
     */
    public static function read($read, string $where): array
    {
        if (!$read instanceof stdClass) {
            throw new HttpException(422, $where . ' must be an object');
        }
        $props = get_object_vars($read);
        if (count($props) > self::MAX_READ_KEYS) {
            throw new HttpException(422, $where . ' has too many keys');
        }
        foreach ($props as $key => $value) {
            $key = (string) $key;
            if (!preg_match(self::JSON_KEY, $key)) {
                throw new HttpException(422, $where . ' has an invalid key name');
            }
            if (is_array($value) || is_object($value)) {
                throw new HttpException(422, $where . '.' . $key . ' must be a plain value');
            }
            if (is_string($value)) {
                self::plainString($value, $where . '.' . $key);
            }
        }

        $chip = $props['chip'] ?? null;
        if (!is_string($chip) || !preg_match(self::CHIP, $chip)) {
            throw new HttpException(422, $where . '.chip must be a string of 1-64 decimal or hex digits');
        }
        $epoch = $props['epochMilli'] ?? null;
        if (!is_int($epoch) || $epoch < 1 || $epoch > self::MAX_EPOCH_MS) {
            throw new HttpException(422, $where . '.epochMilli must be a time in epoch milliseconds');
        }
        $reader = self::optionalSmallInt($props, 'reader', $where);
        $antenna = self::optionalSmallInt($props, 'antenna', $where);
        if (array_key_exists('rssi', $props) && $props['rssi'] !== null
            && !is_int($props['rssi']) && !is_float($props['rssi'])) {
            throw new HttpException(422, $where . '.rssi must be a number');
        }

        return [
            'chip' => $chip,
            'epochMilli' => $epoch,
            'reader' => $reader,
            'antenna' => $antenna,
            'json' => (string) json_encode($read, Response::JSON_FLAGS | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Validates a command posted by a PikaDownloader client against the fixed vocabulary.
     *
     * @param mixed $body
     * @return array{command: string, args: array<string, mixed>}
     */
    public static function command($body, int $maxRewindMs): array
    {
        if (!$body instanceof stdClass) {
            throw new HttpException(422, 'Command must be a JSON object');
        }
        $props = get_object_vars($body);
        $command = $props['command'] ?? null;
        if (!is_string($command) || !in_array($command, self::COMMANDS, true)) {
            throw new HttpException(422, 'command must be one of: ' . implode(', ', self::COMMANDS));
        }

        $args = [];
        if ($command === 'start') {
            $allowed = ['command'];
        } elseif ($command === 'rewind') {
            $allowed = ['command', 'from', 'to'];
            foreach (['from', 'to'] as $key) {
                $v = $props[$key] ?? null;
                if (!is_int($v) || $v < 0 || $v > self::MAX_EPOCH_MS) {
                    throw new HttpException(422, $key . ' must be a time in epoch milliseconds');
                }
                $args[$key] = $v;
            }
            if ($args['from'] >= $args['to']) {
                throw new HttpException(422, 'from must be earlier than to');
            }
            if ($args['to'] - $args['from'] > $maxRewindMs) {
                throw new HttpException(422, 'rewind range is longer than ' . intdiv($maxRewindMs, 86400000) . ' days');
            }
        } else {
            $allowed = ['command', 'profile'];
            $profile = $props['profile'] ?? null;
            if (!is_string($profile) || !preg_match(self::PROFILE, $profile)) {
                throw new HttpException(422, 'profile must be a profile id or name (letters, digits, spaces, "_", ".", "-", "(", ")")');
            }
            $args['profile'] = $profile;
        }

        $unknown = array_diff(array_map('strval', array_keys($props)), $allowed);
        if ($unknown) {
            throw new HttpException(422, 'Unexpected field for ' . $command . ': ' . self::safeList($unknown));
        }
        return ['command' => $command, 'args' => $args];
    }

    public static function isCredential(string $value): bool
    {
        return (bool) preg_match(self::CREDENTIAL, $value);
    }

    private static function plainString(string $value, string $where): void
    {
        if (strlen($value) > self::MAX_STRING) {
            throw new HttpException(422, $where . ' is too long');
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value)) {
            throw new HttpException(422, $where . ' contains control or escape characters');
        }
    }

    /**
     * @param array<string, mixed> $props
     */
    private static function optionalSmallInt(array $props, string $key, string $where): int
    {
        if (!array_key_exists($key, $props) || $props[$key] === null) {
            return -1;
        }
        $v = $props[$key];
        if (!is_int($v) || $v < 0 || $v > 65535) {
            throw new HttpException(422, $where . '.' . $key . ' must be an integer from 0 to 65535');
        }
        return $v;
    }

    /**
     * @param string[] $names
     */
    private static function safeList(array $names): string
    {
        $out = [];
        foreach ($names as $name) {
            $out[] = preg_match(self::JSON_KEY, $name) ? $name : '(invalid name)';
        }
        return implode(', ', $out);
    }
}
