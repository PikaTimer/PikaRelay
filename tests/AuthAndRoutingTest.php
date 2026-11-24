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

namespace PikaRelay\Tests;

use PikaRelay\App;
use PikaRelay\Http\Request;

final class AuthAndRoutingTest extends AppTestCase
{
    /**
     * Role matrix for secure mode: [method, path, body, role allowed, role refused].
     *
     * @return array<string, array{0: string, 1: string, 2: mixed, 3: string, 4: string}>
     */
    public function roleMatrix(): array
    {
        $status = ['unitID' => 'pr4-01', 'reading' => true];
        return [
            'put status' => ['PUT', '/readerStatus/pr4-01', $status, 'reader', 'client'],
            'list readers' => ['GET', '/readerList', null, 'client', 'reader'],
            'put reads' => ['POST', '/putReads/pr4-01', [], 'reader', 'client'],
            'get reads' => ['GET', '/getReads/pr4-01/0', null, 'client', 'reader'],
            'post command' => ['POST', '/commands/pr4-01', ['command' => 'start'], 'client', 'reader'],
            'get commands' => ['GET', '/commands/pr4-01', null, 'reader', 'client'],
        ];
    }

    /**
     * @dataProvider roleMatrix
     * @param mixed $body
     */
    public function testRoles(string $method, string $path, $body, string $allowed, string $refused): void
    {
        $this->call('PUT', '/readerStatus/pr4-01', ['unitID' => 'pr4-01'], 'reader');

        $ok = $this->call($method, $path, $body, $allowed);
        self::assertLessThan(300, $ok->status(), $ok->body());

        $denied = $this->call($method, $path, $body, $refused);
        self::assertSame(403, $denied->status());

        self::assertSame(401, $this->call($method, $path, $body)->status());
    }

    public function testReaderAndClientMayBothReadStatus(): void
    {
        $this->call('PUT', '/readerStatus/pr4-01', ['unitID' => 'pr4-01'], 'reader');
        self::assertSame(200, $this->call('GET', '/readerStatus/pr4-01', null, 'client')->status());
        self::assertSame(200, $this->call('GET', '/readerStatus/pr4-01', null, 'reader')->status());
    }

    public function testWrongSecretOrUnknownKeyIs401(): void
    {
        $wrong = $this->call('GET', '/readerList', null, null, ['X-Pika-Key' => 'client-key', 'X-Pika-Secret' => 'nope']);
        self::assertSame(401, $wrong->status());
        $unknown = $this->call('GET', '/readerList', null, null, ['X-Pika-Key' => 'who', 'X-Pika-Secret' => 'client-secret']);
        self::assertSame(401, $unknown->status());
        self::assertSame(['error' => 'Invalid API key or secret'], self::json($unknown));
    }

    public function testLastUsedIsRecorded(): void
    {
        $this->call('GET', '/readerList', null, 'client');
        self::assertSame(self::START_MS, (int) $this->db->value('SELECT last_used_ms FROM api_keys WHERE api_key = ?', ['client-key']));
    }

    public function testInsecureModeNeedsNoCredentials(): void
    {
        $this->config->set('secure_mode', false);
        self::assertSame(204, $this->call('PUT', '/readerStatus/pr4-01', ['unitID' => 'x'])->status());
        self::assertSame(200, $this->call('GET', '/readerList')->status());
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $response = $this->call('DELETE', '/readerList', null, 'client');
        self::assertSame(405, $response->status());
        self::assertSame('GET', $response->header('Allow'));
        self::assertSame(['error' => 'Method not allowed'], self::json($response));
    }

    public function testUnknownApiPathIsJson404(): void
    {
        $response = $this->call('GET', '/readerStatus', null, 'client');
        self::assertSame(404, $response->status());
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
    }

    public function testUnknownUiPathIsHtml404(): void
    {
        $response = $this->call('GET', '/nothing/here');
        self::assertSame(404, $response->status());
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
    }

    public function testJsonContentTypeIsRequired(): void
    {
        $response = $this->call('PUT', '/readerStatus/pr4-01', '{"a":1}', 'reader', ['Content-Type' => 'text/plain']);
        self::assertSame(415, $response->status());
    }

    public function testOversizedBodyIs413(): void
    {
        $big = '{"note":"' . str_repeat('a', 300000) . '"}';
        self::assertSame(413, $this->call('PUT', '/readerStatus/pr4-01', $big, 'reader')->status());
        $declared = $this->call('PUT', '/readerStatus/pr4-01', '{}', 'reader', ['Content-Length' => '999999999']);
        self::assertSame(413, $declared->status());
    }

    public function testMalformedJsonIs422(): void
    {
        self::assertSame(422, $this->call('PUT', '/readerStatus/pr4-01', '{"a":', 'reader')->status());
        self::assertSame(422, $this->call('PUT', '/readerStatus/pr4-01', '', 'reader')->status());
    }

    public function testSecurityHeadersOnEveryResponse(): void
    {
        foreach ([$this->call('GET', '/readerList', null, 'client'), $this->call('GET', '/nothing')] as $response) {
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
            self::assertSame('DENY', $response->header('X-Frame-Options'));
            self::assertStringContainsString("default-src 'self'", (string) $response->header('Content-Security-Policy'));
        }
    }

    public function testApiIsUnavailableBeforeSetup(): void
    {
        $app = new App(null, null, $this->clock);
        $response = $app->handle(new Request('GET', '/readerList'));
        self::assertSame(503, $response->status());
        self::assertSame(['error' => 'PikaRelay has not been set up yet'], self::json($response));
    }
}
