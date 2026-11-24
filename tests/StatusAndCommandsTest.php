<?php

declare(strict_types=1);

namespace PikaRelay\Tests;

final class StatusAndCommandsTest extends AppTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function pikaStatus(): array
    {
        // Shape taken from PikaReader's StatusHandler.
        return [
            'reading' => true,
            'totalReads' => 1234,
            'unitID' => 'Finish Line',
            'profile' => ['id' => 'finish', 'name' => 'Finish Mats'],
            'readers' => [
                ['id' => 1, 'name' => 'Reader 1', 'reading' => true, 'portStatus' => ['1' => 'Connected', '2' => 'Disabled'],
                    'type' => 'IMPINJ', 'connected' => true, 'status' => 'Reading'],
            ],
            'Raw Tag Reads/s' => 42,
            'readerPortStats' => ['Reader 1' => ['labels' => ['Port 1'], 'status' => ['Connected'], 'readStrength' => [-55.5]]],
            'battery' => 87,
            'timestamp' => '2026-11-24T14:15:30.123456Z',
            'localTime' => '2026-11-24T08:15:30.123456',
            'timezone' => 'America/Chicago',
        ];
    }

    public function testStatusRoundTrip(): void
    {
        self::assertSame(204, $this->call('PUT', '/readerStatus/pr4-01', self::pikaStatus(), 'reader')->status());
        $this->clock->advance(3000);

        $got = self::json($this->call('GET', '/readerStatus/pr4-01', null, 'client'));
        self::assertSame('pr4-01', $got['readerId']);
        self::assertSame(self::START_MS, $got['updated']);
        self::assertSame(self::START_MS + 3000, $got['serverTime']);
        self::assertSame(self::pikaStatus(), $got['status']);

        $list = self::json($this->call('GET', '/readerList', null, 'client'));
        self::assertSame([[
            'id' => 'pr4-01',
            'name' => 'Finish Line',
            'updated' => self::START_MS,
            'reading' => true,
            'battery' => 87,
            'lastRead' => null,
        ]], $list['readers']);
    }

    public function testStatusMustBeAnObjectOfPlainData(): void
    {
        self::assertSame(422, $this->call('PUT', '/readerStatus/pr4-01', [1, 2], 'reader')->status());
        self::assertSame(422, $this->call('PUT', '/readerStatus/pr4-01', ['bad"key' => 1], 'reader')->status());
        self::assertSame(422, $this->call('PUT', '/readerStatus/pr4-01', ['name' => "line\nbreak"], 'reader')->status());
        self::assertSame(422, $this->call('PUT', '/readerStatus/pr4-01', ['name' => 'back\\slash'], 'reader')->status());
        self::assertSame(404, $this->call('GET', '/readerStatus/pr4-01', null, 'client')->status());
    }

    public function testCommandLifecycle(): void
    {
        $this->call('PUT', '/readerStatus/pr4-01', ['unitID' => 'pr4-01'], 'reader');

        $posted = $this->call('POST', '/commands/pr4-01', ['command' => 'rewind', 'from' => 1000, 'to' => 2000], 'client');
        self::assertSame(201, $posted->status(), $posted->body());
        $id = self::json($posted)['id'];
        $this->call('POST', '/commands/pr4-01', ['command' => 'forceSetProfile', 'profile' => 'Start Mats'], 'client');

        $pending = self::json($this->call('GET', '/commands/pr4-01', null, 'reader'))['commands'];
        self::assertCount(2, $pending);
        self::assertSame([
            'id' => $id,
            'command' => 'rewind',
            'from' => 1000,
            'to' => 2000,
            'created' => self::START_MS,
            'expires' => self::START_MS + 600000,
        ], $pending[0]);
        self::assertSame('Start Mats', $pending[1]['profile']);

        self::assertSame(204, $this->call('DELETE', '/commands/pr4-01/' . $id, null, 'reader')->status());
        self::assertSame(204, $this->call('DELETE', '/commands/pr4-01/' . $id, null, 'reader')->status(), 'ack is idempotent');
        self::assertSame(404, $this->call('DELETE', '/commands/other/' . $id, null, 'reader')->status());
        self::assertSame(404, $this->call('DELETE', '/commands/pr4-01/999', null, 'reader')->status());
        self::assertSame(422, $this->call('DELETE', '/commands/pr4-01/abc', null, 'reader')->status());

        $pending = self::json($this->call('GET', '/commands/pr4-01', null, 'reader'))['commands'];
        self::assertSame(['forceSetProfile'], array_column($pending, 'command'));
    }

    public function testCommandsExpire(): void
    {
        $this->call('PUT', '/readerStatus/pr4-01', ['unitID' => 'pr4-01'], 'reader');
        $this->call('POST', '/commands/pr4-01', ['command' => 'start'], 'client');
        $this->clock->advance(600000);
        self::assertSame([], self::json($this->call('GET', '/commands/pr4-01', null, 'reader'))['commands']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function invalidCommands(): array
    {
        return [
            'stop is not allowed' => [['command' => 'stop']],
            'unknown command' => [['command' => 'reboot']],
            'extra field' => [['command' => 'start', 'force' => true]],
            'rewind without to' => [['command' => 'rewind', 'from' => 1]],
            'rewind backwards' => [['command' => 'rewind', 'from' => 2000, 'to' => 1000]],
            'rewind too long' => [['command' => 'rewind', 'from' => 0, 'to' => 8 * 86400000]],
            'rewind as strings' => [['command' => 'rewind', 'from' => '1', 'to' => '2']],
            'profile missing' => [['command' => 'setProfile']],
            'profile with quote' => [['command' => 'setProfile', 'profile' => 'a"b']],
            'not an object' => [['start']],
        ];
    }

    /**
     * @dataProvider invalidCommands
     * @param mixed $command
     */
    public function testInvalidCommandsAreRejected($command): void
    {
        $this->call('PUT', '/readerStatus/pr4-01', ['unitID' => 'pr4-01'], 'reader');
        self::assertSame(422, $this->call('POST', '/commands/pr4-01', $command, 'client')->status());
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM commands'));
    }

    public function testCommandForUnknownReaderIs404(): void
    {
        self::assertSame(404, $this->call('POST', '/commands/ghost', ['command' => 'start'], 'client')->status());
    }
}
