<?php

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
