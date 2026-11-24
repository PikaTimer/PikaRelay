<?php

declare(strict_types=1);

namespace PikaRelay\Api;

use PikaRelay\Auth\ApiAuth;
use PikaRelay\Clock;
use PikaRelay\Db\Database;
use PikaRelay\Http\HttpException;
use PikaRelay\Http\Request;
use PikaRelay\Http\Response;
use PikaRelay\Http\Validator;
use stdClass;

final class ReaderStatusController
{
    private const MAX_STATUS_BYTES = 262144;

    /** @var Database */
    private $db;

    /** @var ApiAuth */
    private $auth;

    /** @var Clock */
    private $clock;

    public function __construct(Database $db, ApiAuth $auth, Clock $clock)
    {
        $this->db = $db;
        $this->auth = $auth;
        $this->clock = $clock;
    }

    /** PUT /readerStatus/{id} */
    public function put(Request $request, string $readerId): Response
    {
        $this->auth->requireRole($request, [ApiAuth::ROLE_READER]);
        $readerId = Validator::readerId($readerId);
        $status = Validator::jsonBody($request, self::MAX_STATUS_BYTES);
        if (!$status instanceof stdClass) {
            throw new HttpException(422, 'Status must be a JSON object');
        }
        Validator::readerJson($status, 'status');
        $json = (string) json_encode($status, Response::JSON_FLAGS | JSON_THROW_ON_ERROR);

        $now = $this->clock->nowMs();
        $this->db->transaction(function (Database $db) use ($readerId, $json, $now): void {
            $db->ensureReader($readerId, $now);
            $db->run('UPDATE readers SET status_json = ?, status_ms = ? WHERE id = ?', [$json, $now, $readerId]);
        });
        return Response::noContent();
    }

    /** GET /readerStatus/{id} */
    public function get(Request $request, string $readerId): Response
    {
        $this->auth->requireRole($request, [ApiAuth::ROLE_CLIENT, ApiAuth::ROLE_READER]);
        $readerId = Validator::readerId($readerId);
        $row = $this->db->one('SELECT status_json, status_ms, last_read_ms FROM readers WHERE id = ?', [$readerId]);
        if ($row === null) {
            throw new HttpException(404, 'Unknown reader');
        }
        return Response::rawJson(
            '{"readerId":' . json_encode($readerId)
            . ',"serverTime":' . $this->clock->nowMs()
            . ',"updated":' . self::intOrNull($row['status_ms'])
            . ',"lastRead":' . self::intOrNull($row['last_read_ms'])
            . ',"status":' . ($row['status_json'] === null ? 'null' : (string) $row['status_json'])
            . '}'
        );
    }

    /** GET /readerList */
    public function list(Request $request): Response
    {
        $this->auth->requireRole($request, [ApiAuth::ROLE_CLIENT]);
        $readers = [];
        foreach ($this->db->all('SELECT id, status_json, status_ms, last_read_ms FROM readers ORDER BY id') as $row) {
            $summary = self::summarize($row);
            $readers[] = [
                'id' => (string) $row['id'],
                'name' => $summary['name'],
                'updated' => $row['status_ms'] === null ? null : (int) $row['status_ms'],
                'reading' => $summary['reading'],
                'battery' => $summary['battery'],
                'lastRead' => $row['last_read_ms'] === null ? null : (int) $row['last_read_ms'],
            ];
        }
        return Response::json(['serverTime' => $this->clock->nowMs(), 'readers' => $readers]);
    }

    /**
     * Pulls the commonly displayed fields out of a stored PikaReader status.
     *
     * @param array<string, mixed> $row readers row
     * @return array{name: string, reading: bool|null, battery: int|float|null, profile: string|null, totalReads: int|null}
     */
    public static function summarize(array $row): array
    {
        $status = $row['status_json'] === null ? [] : json_decode((string) $row['status_json'], true);
        if (!is_array($status)) {
            $status = [];
        }
        $battery = $status['battery'] ?? ($status['Battery'] ?? null);
        if ((!is_int($battery) && !is_float($battery)) || $battery < 0) {
            $battery = null;
        }
        $profile = null;
        if (isset($status['profile']) && is_array($status['profile']) && isset($status['profile']['name'])
            && is_string($status['profile']['name'])) {
            $profile = $status['profile']['name'];
        }
        return [
            'name' => isset($status['unitID']) && is_string($status['unitID']) ? $status['unitID'] : (string) $row['id'],
            'reading' => isset($status['reading']) && is_bool($status['reading']) ? $status['reading'] : null,
            'battery' => $battery,
            'profile' => $profile,
            'totalReads' => isset($status['totalReads']) && is_int($status['totalReads']) ? $status['totalReads'] : null,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function intOrNull($value): string
    {
        return $value === null ? 'null' : (string) (int) $value;
    }
}
