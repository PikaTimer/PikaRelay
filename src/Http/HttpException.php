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

use RuntimeException;

/**
 * Thrown to end a request with a specific HTTP status. The message is shown to the caller,
 * so it must never contain internal details.
 */
final class HttpException extends RuntimeException
{
    /** @var int */
    private $status;

    /** @var array<string, string> */
    private $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, string $message, array $headers = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->headers = $headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
