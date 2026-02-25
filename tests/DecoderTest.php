<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use zonuexe\BrokenJson\DecodeOptions;
use zonuexe\BrokenJson\Decoder;
use zonuexe\BrokenJson\DecoderFactory;
use zonuexe\BrokenJson\Repair\RepairingScanner;
use zonuexe\BrokenJson\RepairActionType;
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
        self::assertSame(
            ['foo' => ['name' => 'foo', 'bar' => ['buz' => ['text data', 'huge.........text']]]],
            $result->value,
        );
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
        $path = tempnam(sys_get_temp_dir() . DIRECTORY_SEPARATOR, 'broken-json-');
        self::assertNotFalse($path);
        file_put_contents($path, '[10, 20, 30');

        $decoder = DecoderFactory::create();
        $result = $decoder->decodeFile($path);

        self::assertSame([10, 20, 30], $result->value);
    }

    public function testItCanDecodeFromChunks(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeChunks($this->provideChunks('{"x":"ab', 'c\\'));

        self::assertSame(['x' => 'abc\\'], $result->value);
    }

    public function testLegacyDecodeReturnsTuple(): void
    {
        $decoder = DecoderFactory::create();

        [$value, $repairs] = $decoder->decode('{"x":"y');

        self::assertSame(['x' => 'y'], $value);
        self::assertNotSame([], $repairs);
    }

    #[DataProvider('provideStringRepairCases')]
    public function testItRepairsTruncatedStringCases(string $input, string $expected, RepairActionType $expectedActionType): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString($input);

        self::assertSame(['x' => $expected], $result->value);
        self::assertTrue($result->isRecovered);
        self::assertSame($expectedActionType, $result->repairs[0]->type);
    }

    public function testDecodeResultCanBeSerializedToArray(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('{"x":"abc\\');
        $serialized = $result->toArray();

        self::assertSame(['x' => 'abc\\'], $serialized['value']);
        self::assertTrue($serialized['isRecovered']);
        self::assertSame('complete_escape', $serialized['repairs'][0]['type']);
    }

    public function testDecodeResultIsJsonSerializable(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('{"x":"abc\\');
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"isRecovered":true', $json);
        self::assertStringContainsString('"type":"complete_escape"', $json);
    }

    /**
     * @phpstan-return iterable<list{string, string, RepairActionType}>
     */
    public static function provideStringRepairCases(): iterable
    {
        yield ['{"x":"abc\\', 'abc\\', RepairActionType::CompleteEscape];
        yield ['{"x":"\\u12', "\u{1200}", RepairActionType::CompleteUnicodeEscape];
    }

    /**
     * @phpstan-return Generator<string>
     */
    private function provideChunks(string ...$chunks): Generator
    {
        foreach ($chunks as $chunk) {
            yield $chunk;
        }
    }
}
