<?php

declare(strict_types=1);

namespace PikaRelay\Tests;

use PHPUnit\Framework\TestCase;
use PikaRelay\Http\HttpException;
use PikaRelay\Http\Validator;

final class ValidatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function readerIds(): array
    {
        return [
            'hostname' => ['pr4-01', true],
            'dots and underscores' => ['Finish_Line.2', true],
            'single char' => ['a', true],
            '64 chars' => [str_repeat('a', 64), true],
            '65 chars' => [str_repeat('a', 65), false],
            'empty' => ['', false],
            'leading dash' => ['-x', false],
            'space' => ['a b', false],
            'slash' => ['a/b', false],
            'unicode' => ['café', false],
            'traversal' => ['..', false],
        ];
    }

    /**
     * @dataProvider readerIds
     */
    public function testReaderId(string $id, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(HttpException::class);
        }
        self::assertSame($id, Validator::readerId($id));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public function reads(): array
    {
        return [
            'minimal' => ['{"chip":"0","epochMilli":1}', null],
            'hex chip' => ['{"chip":"E2801160","epochMilli":1795961730123}', null],
            'trigger read' => ['{"chip":"0","reader":0,"antenna":0,"rssi":0.0,"epochMilli":1795961730123}', null],
            'null optional' => ['{"chip":"1","epochMilli":5,"tz":null,"reader":null}', null],
            'chip as number' => ['{"chip":1234,"epochMilli":1}', 'chip'],
            'chip with space' => ['{"chip":"12 34","epochMilli":1}', 'chip'],
            'epoch as float' => ['{"chip":"1","epochMilli":1.5}', 'epochMilli'],
            'epoch as string' => ['{"chip":"1","epochMilli":"1795961730123"}', 'epochMilli'],
            'epoch too large' => ['{"chip":"1","epochMilli":99999999999999}', 'epochMilli'],
            'epoch zero' => ['{"chip":"1","epochMilli":0}', 'epochMilli'],
            'negative antenna' => ['{"chip":"1","epochMilli":1,"antenna":-1}', 'antenna'],
            'rssi as string' => ['{"chip":"1","epochMilli":1,"rssi":"-50"}', 'rssi'],
            'nested value' => ['{"chip":"1","epochMilli":1,"x":{"y":1}}', 'plain value'],
            'bad key' => ['{"chip":"1","epochMilli":1,"a\\"b":1}', 'key'],
            'escaped control char' => ['{"chip":"1","epochMilli":1,"tz":"\\u0007"}', 'control'],
            'not an object' => ['["chip"]', 'object'],
        ];
    }

    /**
     * @dataProvider reads
     */
    public function testRead(string $json, ?string $errorContains): void
    {
        $decoded = json_decode($json, false, 10, JSON_BIGINT_AS_STRING);
        if ($errorContains !== null) {
            $this->expectException(HttpException::class);
            $this->expectExceptionMessage($errorContains);
        }
        $read = Validator::read($decoded, 'read');
        self::assertSame($json, $read['json'], 'normalized JSON matches compact input');
    }

    public function testReadDefaultsMissingReaderAndAntennaToMinusOne(): void
    {
        $read = Validator::read(json_decode('{"chip":"1","epochMilli":1}'), 'read');
        self::assertSame(-1, $read['reader']);
        self::assertSame(-1, $read['antenna']);
    }

    public function testReaderJsonDepthLimit(): void
    {
        $deep = json_decode(str_repeat('{"a":', 9) . '1' . str_repeat('}', 9));
        $this->expectException(HttpException::class);
        Validator::readerJson($deep, 'status');
    }

    public function testCredentials(): void
    {
        self::assertTrue(Validator::isCredential('abcd'));
        self::assertTrue(Validator::isCredential('!~#$%^&*()'));
        self::assertFalse(Validator::isCredential('abc'));
        self::assertFalse(Validator::isCredential('has space'));
        self::assertFalse(Validator::isCredential(str_repeat('a', 129)));
        self::assertFalse(Validator::isCredential("tab\there"));
    }
}
