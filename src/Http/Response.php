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

final class Response
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @var int */
    private $status;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * @param mixed $data
     */
    public static function json($data, int $status = 200): Response
    {
        return self::rawJson((string) json_encode($data, self::JSON_FLAGS | JSON_THROW_ON_ERROR), $status);
    }

    /**
     * For responses assembled from JSON fragments already stored in the database.
     */
    public static function rawJson(string $json, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], $json);
    }

    public static function noContent(): Response
    {
        return new Response(204);
    }

    public static function html(string $html, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function redirect(string $url): Response
    {
        return new Response(303, ['Location' => $url]);
    }

    public function withHeader(string $name, string $value): Response
    {
        $copy = clone $this;
        $copy->headers[$name] = $value;
        return $copy;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Decoded JSON body (tests and tools).
     *
     * @return mixed
     */
    public function decoded()
    {
        return json_decode($this->body, true);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}
