<?php

declare(strict_types=1);

namespace PikaRelay\Tests;

final class ReadsApiTest extends AppTestCase
{
    public function testUploadCountsNewReadsAndIgnoresDuplicates(): void
    {
        $reads = [self::read('1001', self::START_MS - 5000), self::read('1002', self::START_MS - 4000)];
        $first = $this->call('POST', '/putReads/pr4-01', $reads, 'reader');
        self::assertSame(200, $first->status(), $first->body());
        self::assertSame(['received' => 2, 'new' => 2], self::json($first));

        $reads[] = self::read('1003', self::START_MS - 3000);
        $second = $this->call('POST', '/putReads/pr4-01', $reads, 'reader');
        self::assertSame(['received' => 3, 'new' => 1], self::json($second));

        self::assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM tag_reads'));
        self::assertSame(self::START_MS - 3000, (int) $this->db->value('SELECT last_read_ms FROM readers WHERE id = ?', ['pr4-01']));
    }

    public function testSameChipOnDifferentAntennaIsADifferentRead(): void
    {
        $t = self::START_MS - 1000;
        $response = $this->call('POST', '/putReads/pr4-01', [
            self::read('1001', $t, ['antenna' => 1]),
            self::read('1001', $t, ['antenna' => 2]),
            self::read('1001', $t, ['antenna' => 2]),
        ], 'reader');
        self::assertSame(['received' => 3, 'new' => 2], self::json($response));
    }

    public function testReadsMissingOptionalFieldsAreAccepted(): void
    {
        $response = $this->call('POST', '/putReads/pr4-01', [['chip' => 'ABCDEF', 'epochMilli' => self::START_MS]], 'reader');
        self::assertSame(['received' => 1, 'new' => 1], self::json($response));
    }

    public function testOneInvalidReadRejectsTheWholeBatch(): void
    {
        $response = $this->call('POST', '/putReads/pr4-01', [
            self::read('1001', self::START_MS),
            self::read('1002', self::START_MS, ['chip' => 'not hex!']),
        ], 'reader');
        self::assertSame(422, $response->status());
        self::assertStringContainsString('reads[1].chip', self::json($response)['error']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM tag_reads'));
        self::assertNull($this->db->value('SELECT id FROM readers'));
    }

    public function testBatchSizeLimit(): void
    {
        $this->app = $this->makeApp(10000, 3);
        $reads = [];
        for ($i = 0; $i < 4; $i++) {
            $reads[] = self::read((string) (1000 + $i), self::START_MS);
        }
        self::assertSame(413, $this->call('POST', '/putReads/pr4-01', $reads, 'reader')->status());
    }

    public function testBodyMustBeAnArray(): void
    {
        $response = $this->call('POST', '/putReads/pr4-01', self::read('1001', self::START_MS), 'reader');
        self::assertSame(422, $response->status());
    }

    public function testPollingReturnsOnlySettledReadsAndAdvancesTheCursor(): void
    {
        $this->call('POST', '/putReads/pr4-01', [self::read('1001', self::START_MS - 100)], 'reader');

        // Still inside the settle window: nothing yet, cursor stays where it was.
        $this->clock->advance(1000);
        $page = self::json($this->call('GET', '/getReads/pr4-01/0', null, 'client'));
        self::assertSame([], $page['reads']);
        self::assertFalse($page['more']);
        $cursor = $page['serverTime'];
        self::assertSame(self::START_MS + 1000 - 2000, $cursor);

        $this->clock->advance(1500);
        $page = self::json($this->call('GET', '/getReads/pr4-01/' . $cursor, null, 'client'));
        self::assertCount(1, $page['reads']);
        self::assertSame('1001', $page['reads'][0]['chip']);
        $cursor = $page['serverTime'];

        // A late upload of an *old* read is still delivered, because the cursor is receive time.
        $this->call('POST', '/putReads/pr4-01', [self::read('2002', self::START_MS - 86400000)], 'reader');
        $this->clock->advance(5000);
        $page = self::json($this->call('GET', '/getReads/pr4-01/' . $cursor, null, 'client'));
        self::assertSame(['2002'], array_column($page['reads'], 'chip'));

        $page = self::json($this->call('GET', '/getReads/pr4-01/' . $page['serverTime'], null, 'client'));
        self::assertSame([], $page['reads']);
    }

    public function testReadsAreReturnedByteForByte(): void
    {
        $read = self::read('1001', self::START_MS, ['rssi' => -60.0, 'extra key' => 'kept', 'type' => 'READ']);
        $this->call('POST', '/putReads/pr4-01', [$read], 'reader');
        $this->clock->advance(5000);
        $first = $this->call('GET', '/getReads/pr4-01/0', null, 'client')->body();
        $second = $this->call('GET', '/getReads/pr4-01/0', null, 'client')->body();
        self::assertSame($first, $second);
        self::assertStringContainsString('"rssi":-60.0', $first);
        self::assertStringContainsString('"extra key":"kept"', $first);
    }

    public function testTruncatedPageNeverSplitsAMillisecond(): void
    {
        $this->app = $this->makeApp(5, 2000);
        // Three uploads, each with its own receive time: 3 + 3 + 2 reads.
        $chip = 1000;
        foreach ([3, 3, 2] as $batch) {
            $reads = [];
            for ($i = 0; $i < $batch; $i++) {
                $reads[] = self::read((string) $chip++, self::START_MS);
            }
            $this->call('POST', '/putReads/pr4-01', $reads, 'reader');
            $this->clock->advance(10);
        }
        $this->clock->advance(5000);

        $seen = [];
        $cursor = 0;
        for ($guard = 0; $guard < 10; $guard++) {
            $page = self::json($this->call('GET', '/getReads/pr4-01/' . $cursor, null, 'client'));
            $seen = array_merge($seen, array_column($page['reads'], 'chip'));
            $cursor = $page['serverTime'];
            if (!$page['more']) {
                break;
            }
            // The first page stops before the second upload instead of splitting its millisecond.
            self::assertContains(count($page['reads']), [3]);
        }
        self::assertSame(['1000', '1001', '1002', '1003', '1004', '1005', '1006', '1007'], $seen);
    }

    public function testPageOfASingleMillisecondReturnsAllOfIt(): void
    {
        $this->app = $this->makeApp(2, 2000);
        $reads = [];
        for ($i = 0; $i < 4; $i++) {
            $reads[] = self::read((string) (1000 + $i), self::START_MS);
        }
        $this->call('POST', '/putReads/pr4-01', $reads, 'reader');
        $this->clock->advance(5000);
        $page = self::json($this->call('GET', '/getReads/pr4-01/0', null, 'client'));
        self::assertCount(4, $page['reads']);
        self::assertTrue($page['more']);
        $next = self::json($this->call('GET', '/getReads/pr4-01/' . $page['serverTime'], null, 'client'));
        self::assertSame([], $next['reads']);
        self::assertFalse($next['more']);
    }

    public function testRangeByReadTimeWithPaging(): void
    {
        $this->app = $this->makeApp(3, 2000);
        $reads = [];
        foreach ([100, 200, 200, 300, 400, 500] as $i => $offset) {
            $reads[] = self::read((string) (1000 + $i), self::START_MS + $offset);
        }
        $this->call('POST', '/putReads/pr4-01', $reads, 'reader');

        $from = self::START_MS + 100;
        $to = self::START_MS + 500;   // exclusive
        $chips = [];
        for ($guard = 0; $guard < 10; $guard++) {
            $page = self::json($this->call('GET', "/getReads/pr4-01/$from/$to", null, 'client'));
            $chips = array_merge($chips, array_column($page['reads'], 'chip'));
            if (!$page['more']) {
                self::assertArrayNotHasKey('next', $page);
                break;
            }
            $from = $page['next'];
        }
        self::assertSame(['1000', '1001', '1002', '1003', '1004'], $chips);
    }

    public function testRangeMustBeOrdered(): void
    {
        $this->call('POST', '/putReads/pr4-01', [self::read('1001', self::START_MS)], 'reader');
        self::assertSame(422, $this->call('GET', '/getReads/pr4-01/200/100', null, 'client')->status());
    }

    public function testUnknownReaderIs404(): void
    {
        $response = $this->call('GET', '/getReads/nobody/0', null, 'client');
        self::assertSame(404, $response->status());
        self::assertSame(['error' => 'Unknown reader'], self::json($response));
    }

    public function testInvalidSegmentsAre422(): void
    {
        self::assertSame(422, $this->call('GET', '/getReads/pr4-01/-5', null, 'client')->status());
        self::assertSame(422, $this->call('GET', '/getReads/pr4-01/12abc', null, 'client')->status());
        self::assertSame(422, $this->call('POST', '/putReads/bad%20id', [], 'reader')->status());
        self::assertSame(422, $this->call('POST', '/putReads/' . str_repeat('a', 65), [], 'reader')->status());
    }
}
