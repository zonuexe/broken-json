<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use zonuexe\BrokenJson\DecodeOptions;
use zonuexe\BrokenJson\Decoder;
use zonuexe\BrokenJson\DecoderFactory;
use zonuexe\BrokenJson\Repair\RepairingScanner;
use function file_put_contents;
use function fopen;
use function fwrite;
use function rewind;
use function sys_get_temp_dir;
use function tempnam;
use const DIRECTORY_SEPARATOR;

#[CoversClass(DecoderFactory::class)]
#[CoversClass(Decoder::class)]
#[CoversClass(RepairingScanner::class)]
final class DecoderTest extends TestCase
{
    public function testItRecoversTruncatedNestedJson(): void
    {
        $json = '{"foo": {"name": "foo", "bar": {"buz": ["text data", "huge.........text';
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString($json);

        self::assertTrue($result->isRecovered);
        self::assertSame('huge.........text', $result->value['foo']['bar']['buz'][1]);
    }

    public function testItInsertsNullForDanglingColonInBalancedPolicy(): void
    {
        $decoder = DecoderFactory::create(new DecodeOptions());

        $result = $decoder->decodeString('{"foo":');

        self::assertSame(['foo' => null], $result->value);
        self::assertTrue($result->isRecovered);
    }

    public function testItDoesNotInsertNullForDanglingColonInConservativePolicy(): void
    {
        $decoder = DecoderFactory::create(new DecodeOptions(repairPolicy: DecodeOptions::POLICY_CONSERVATIVE));

        $result = $decoder->decodeString('{"foo":');

        self::assertNull($result->value);
        self::assertTrue($result->isPartial);
        self::assertNotSame([], $result->issues);
    }

    public function testItRemovesTrailingComma(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('[1, 2,');

        self::assertSame([1, 2], $result->value);
    }

    public function testItCanDecodeFromStream(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, '{"a":"b');
        rewind($stream);

        $decoder = DecoderFactory::create();
        $result = $decoder->decodeStream($stream);

        self::assertSame(['a' => 'b'], $result->value);
    }

    public function testItCanDecodeFromFile(): void
    {
        $path = tempnam(sys_get_temp_dir().DIRECTORY_SEPARATOR, 'broken-json-');
        self::assertNotFalse($path);
        file_put_contents($path, '[10, 20, 30');

        $decoder = DecoderFactory::create();
        $result = $decoder->decodeFile($path);

        self::assertSame([10, 20, 30], $result->value);
    }

    public function testLegacyDecodeReturnsTuple(): void
    {
        $decoder = DecoderFactory::create();

        [$value, $repairs] = $decoder->decode('{"x":"y');

        self::assertSame(['x' => 'y'], $value);
        self::assertNotSame([], $repairs);
    }
}
