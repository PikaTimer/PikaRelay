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
 * Tag reads: PikaReaders upload them, PikaDownloaders poll for them.
 *
 * Each read is stored once (unique on reader, chip, epochMilli, reader port, antenna) and is
 * always returned as the same JSON text, so a client that sees a read twice (overlapping
 * polls, rewinds) can drop the duplicate by simple equality.
 */
final class ReadsController
{
    private const MAX_BODY_BYTES = 2097152;

    /** @var Database */
    private $db;

    /** @var ApiAuth */
    private $auth;

    /** @var Clock */
    private $clock;

    /** @var int */
    private $settleMs;

    /** @var int */
    private $pageLimit;

    /** @var int */
    private $maxBatch;

    public function __construct(Database $db, ApiAuth $auth, Clock $clock, int $settleMs, int $pageLimit, int $maxBatch)
    {
        $this->db = $db;
        $this->auth = $auth;
        $this->clock = $clock;
        $this->settleMs = $settleMs;
        $this->pageLimit = $pageLimit;
        $this->maxBatch = $maxBatch;
    }

    /** POST /putReads/{id}: body is a JSON array of PikaReader READ objects. */
    public function put(Request $request, string $readerId): Response
    {
        $this->auth->requireRole($request, [ApiAuth::ROLE_READER]);
        $readerId = Validator::readerId($readerId);
        $body = Validator::jsonBody($request, self::MAX_BODY_BYTES);
        if (!is_array($body)) {
            throw new HttpException(422, 'Body must be a JSON array of reads');
        }
        if (count($body) > $this->maxBatch) {
            throw new HttpException(413, 'At most ' . $this->maxBatch . ' reads per request');
        }
        $reads = [];
        foreach ($body as $i => $read) {
            $reads[] = Validator::read($read, 'reads[' . $i . ']');
        }

        $now = $this->clock->nowMs();
        $new = $this->db->transaction(function (Database $db) use ($readerId, $reads, $now): int {
            $db->ensureReader($readerId, $now);
            $stmt = $db->pdo()->prepare($db->insertIgnoreSql(
                'tag_reads',
                ['reader_id', 'chip', 'epoch_milli', 'rfid_reader', 'antenna', 'received_ms', 'data'],
                'id'
            ));
            $new = 0;
            $newest = 0;
            foreach ($reads as $read) {
                $stmt->execute([
                    $readerId, $read['chip'], $read['epochMilli'], $read['reader'], $read['antenna'], $now, $read['json'],
                ]);
                $new += $stmt->rowCount();
                $newest = max($newest, $read['epochMilli']);
            }
            if ($newest > 0) {
                $db->run(
                    'UPDATE readers SET last_read_ms = ? WHERE id = ? AND (last_read_ms IS NULL OR last_read_ms < ?)',
                    [$newest, $readerId, $newest]
                );
            }
            return $new;
        });

        return Response::json(['received' => count($reads), 'new' => $new]);
    }

    /**
     * GET /getReads/{id}/{since}: reads the relay *received* after {since} (epoch ms).
     *
     * Send the returned serverTime as {since} on the next poll. Only reads received at least
     * settle_ms ago are returned, so uploads still being written are picked up next time
     * instead of being skipped. Results may overlap the previous page; never miss reads.
     */
    public function since(Request $request, string $readerId, string $since): Response
    {
        $this->auth->requireRole($request, [ApiAuth::ROLE_CLIENT]);
        $readerId = Validator::readerId($readerId);
        $sinceMs = Validator::epochMsSegment($since, 'since');
        $this->requireReader($readerId);

        $cutoff = $this->clock->nowMs() - $this->settleMs;
        if ($sinceMs >= $cutoff) {
            return self::page($sinceMs, false, null, []);
        }
        $rows = $this->db->all(
            'SELECT received_ms AS t, data FROM tag_reads WHERE reader_id = ? AND received_ms > ? AND received_ms <= ?'
            . ' ORDER BY received_ms, id LIMIT ' . ($this->pageLimit + 1),
            [$readerId, $sinceMs, $cutoff]
        );
        if (count($rows) <= $this->pageLimit) {
            return self::page($cutoff, false, null, $rows);
        }

        list($rows, $last, $split) = $this->trimPage($rows);
        if ($split) {
            // Rows from the last millisecond were dropped; resume just before it.
            return self::page($last - 1, true, null, $rows);
        }
        // The whole page shares one millisecond: return all of that millisecond.
        $rows = $this->db->all(
            'SELECT received_ms AS t, data FROM tag_reads WHERE reader_id = ? AND received_ms = ? ORDER BY id',
            [$readerId, $last]
        );
        return self::page($last, true, null, $rows);
    }

    /**
     * GET /getReads/{id}/{from}/{to}: reads whose *read time* (epochMilli) is in [from, to).
     * When "more" is true, request again with {from} = the returned "next".
     */
    public function range(Request $request, string $readerId, string $from, string $to): Response
    {
        $this->auth->requireRole($request, [ApiAuth::ROLE_CLIENT]);
        $readerId = Validator::readerId($readerId);
        $fromMs = Validator::epochMsSegment($from, 'from');
        $toMs = Validator::epochMsSegment($to, 'to');
        if ($fromMs >= $toMs) {
            throw new HttpException(422, 'from must be earlier than to');
        }
        $this->requireReader($readerId);
        $now = $this->clock->nowMs();

        $rows = $this->db->all(
            'SELECT epoch_milli AS t, data FROM tag_reads WHERE reader_id = ? AND epoch_milli >= ? AND epoch_milli < ?'
            . ' ORDER BY epoch_milli, id LIMIT ' . ($this->pageLimit + 1),
            [$readerId, $fromMs, $toMs]
        );
        if (count($rows) <= $this->pageLimit) {
            return self::page($now, false, null, $rows);
        }
        list($rows, $last, $split) = $this->trimPage($rows);
        if ($split) {
            return self::page($now, true, $last, $rows);
        }
        $rows = $this->db->all(
            'SELECT epoch_milli AS t, data FROM tag_reads WHERE reader_id = ? AND epoch_milli = ? ORDER BY id',
            [$readerId, $last]
        );
        return self::page($now, $last + 1 < $toMs, $last + 1 < $toMs ? $last + 1 : null, $rows);
    }

    /**
     * Cuts a page of pageLimit+1 rows (ordered by t) down to whole milliseconds.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: bool} rows, last t, whether rows were dropped
     */
    private function trimPage(array $rows): array
    {
        $rows = array_slice($rows, 0, $this->pageLimit);
        $last = (int) $rows[count($rows) - 1]['t'];
        if ((int) $rows[0]['t'] === $last) {
            return [$rows, $last, false];
        }
        while ($rows && (int) $rows[count($rows) - 1]['t'] === $last) {
            array_pop($rows);
        }
        return [$rows, $last, true];
    }

    private function requireReader(string $readerId): void
    {
        if ($this->db->value('SELECT 1 FROM readers WHERE id = ?', [$readerId]) === null) {
            throw new HttpException(404, 'Unknown reader');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function page(int $serverTime, bool $more, ?int $next, array $rows): Response
    {
        $reads = [];
        foreach ($rows as $row) {
            $reads[] = (string) $row['data'];
        }
        return Response::rawJson(
            '{"serverTime":' . $serverTime
            . ',"more":' . ($more ? 'true' : 'false')
            . ($next === null ? '' : ',"next":' . $next)
            . ',"reads":[' . implode(',', $reads) . ']}'
        );
    }
}
