<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use zonuexe\BrokenJson\DecodeIssueType;
use zonuexe\BrokenJson\DecodeOptions;
use zonuexe\BrokenJson\Decoder;
use zonuexe\BrokenJson\DecodeResult;
use zonuexe\BrokenJson\DecoderFactory;
use zonuexe\BrokenJson\Repair\RepairingScanner;
use zonuexe\BrokenJson\RepairActionType;
use function array_map;
use function file_put_contents;
use function fopen;
use function fwrite;
use function json_decode;
use function json_encode;
use function property_exists;
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
        $expected = json_decode(
            '{"foo":{"name":"foo","bar":{"buz":["text data","huge.........text"]}}}',
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertTrue($result->isRecovered);
        self::assertSame($expected, $result->value);
    }

    public function testItInsertsNullForDanglingColonInBalancedPolicy(): void
    {
        $decoder = DecoderFactory::create(new DecodeOptions());

        $result = $decoder->decodeString('{"foo":');

        self::assertSame(['foo' => null], $result->value);
        self::assertTrue($result->isRecovered);
    }

    #[DataProvider('providePoliciesThatInsertNull')]
    public function testPolicyInsertsNullForDanglingColon(string $policy): void
    {
        $decoder = DecoderFactory::create(new DecodeOptions(repairPolicy: $policy));

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

    public function testItReturnsFileOpenFailedIssueWhenFileCannotBeOpened(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeFile('/path/that/does/not/exist.json');

        self::assertNull($result->value);
        self::assertSame(DecodeIssueType::FileOpenFailed, $result->issues[0]->type);
    }

    public function testFileOpenFailureSetsExpectedFlagsAndPayload(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeFile('/path/that/does/not/exist.json');

        self::assertFalse($result->isRecovered);
        self::assertTrue($result->isPartial);
        self::assertSame('', $result->repairedJson);
    }

    public function testItCanDecodeFromChunks(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeChunks($this->provideChunks('{"x":"ab', 'c\\'));

        self::assertSame(['x' => 'abc\\'], $result->value);
    }

    /**
     * @param list<string> $chunks
     */
    #[DataProvider('provideChunkBoundaryCases')]
    public function testItCanDecodeAcrossManyChunkBoundaries(array $chunks, mixed $expected): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeChunks($this->provideChunks(...$chunks));

        self::assertSame($expected, $result->value);
    }

    public function testValidJsonDoesNotNeedRecovery(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('{"ok":true,"count":1}');

        $expected = [
            'ok' => true,
            'count' => 1,
        ];
        self::assertSame($expected, $result->value);
        self::assertFalse($result->isRecovered);
        self::assertFalse($result->isPartial);
        self::assertSame([], $result->repairs);
        self::assertSame([], $result->issues);
    }

    public function testConservativeDanglingColonRecordsExpectedRepairs(): void
    {
        $decoder = DecoderFactory::create(new DecodeOptions(repairPolicy: DecodeOptions::POLICY_CONSERVATIVE));

        $result = $decoder->decodeString('{"foo":');
        $repairTypes = array_map(
            static fn ($repairAction): RepairActionType => $repairAction->type,
            $result->repairs,
        );
        $issueTypes = array_map(
            static fn ($decodeIssue): DecodeIssueType => $decodeIssue->type,
            $result->issues,
        );

        self::assertNull($result->value);
        self::assertContains(RepairActionType::RemoveTrailingColon, $repairTypes);
        self::assertContains(RepairActionType::CloseContainer, $repairTypes);
        self::assertContains(DecodeIssueType::JsonDecodeFailed, $issueTypes);
    }

    #[DataProvider('provideRepairedJsonCases')]
    public function testItProducesExpectedRepairedJson(string $input, string $expectedRepairedJson): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString($input);

        self::assertSame($expectedRepairedJson, $result->repairedJson);
    }

    public function testRepairActionPositionsAreNonNegative(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('{"x":"abc\\');

        foreach ($result->repairs as $repairAction) {
            self::assertGreaterThanOrEqual(0, $repairAction->position);
        }
    }

    public function testItRepairsTrailingCommaInObject(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('{"a":1,');
        $repairTypes = array_map(
            static fn ($repairAction): RepairActionType => $repairAction->type,
            $result->repairs,
        );

        self::assertSame(['a' => 1], $result->value);
        self::assertContains(RepairActionType::RemoveTrailingComma, $repairTypes);
    }

    #[DataProvider('provideIssueCases')]
    public function testItCollectsExpectedIssueTypes(string $input, DecodeIssueType $expectedIssueType): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString($input);
        $issueTypes = array_map(
            static fn ($decodeIssue): DecodeIssueType => $decodeIssue->type,
            $result->issues,
        );

        self::assertContains($expectedIssueType, $issueTypes);
    }

    public function testItCollectsInvalidUnicodeAndDecodeFailedIssues(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('{"x":"\u12g"}');
        $issueTypes = array_map(
            static fn ($decodeIssue): DecodeIssueType => $decodeIssue->type,
            $result->issues,
        );

        self::assertNull($result->value);
        self::assertContains(DecodeIssueType::InvalidUnicodeEscape, $issueTypes);
        self::assertContains(DecodeIssueType::JsonDecodeFailed, $issueTypes);
    }

    /**
     * @param list<string> $expectedTypes
     */
    #[DataProvider('provideRepairActionSequenceCases')]
    public function testItProducesExpectedRepairActionSequence(string $input, array $expectedTypes): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString($input);
        $repairTypes = array_map(
            static fn ($repairAction): string => $repairAction->type->value,
            $result->repairs,
        );

        self::assertSame($expectedTypes, $repairTypes);
    }

    public function testLegacyDecodeReturnsTuple(): void
    {
        $decoder = DecoderFactory::create();

        [$value, $repairs] = $decoder->decode('{"x":"y');

        self::assertSame(['x' => 'y'], $value);
        self::assertNotSame([], $repairs);
    }

    public function testLegacyDecodeMatchesDecodeStringProjection(): void
    {
        $decoder = DecoderFactory::create();
        $input = '{"x":"abc\\';

        [$legacyValue, $legacyRepairs] = $decoder->decode($input);
        $result = $decoder->decodeString($input);
        $legacyRepairArrays = array_map(
            static fn ($repairAction): array => $repairAction->toArray(),
            $legacyRepairs,
        );
        $resultRepairArrays = array_map(
            static fn ($repairAction): array => $repairAction->toArray(),
            $result->repairs,
        );

        self::assertSame($result->value, $legacyValue);
        self::assertSame($resultRepairArrays, $legacyRepairArrays);
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

    public function testJsonSerializationContainsDecodeFailedIssueForEmptyInput(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('');
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"type":"json_decode_failed"', $json);
    }

    public function testToArrayAndJsonSerializeAreEquivalent(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('{"x":"abc\\');

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testEmptyChunksCollectJsonDecodeFailedIssue(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeChunks($this->provideChunks());
        $issueTypes = array_map(
            static fn ($decodeIssue): DecodeIssueType => $decodeIssue->type,
            $result->issues,
        );

        self::assertNull($result->value);
        self::assertContains(DecodeIssueType::JsonDecodeFailed, $issueTypes);
    }

    public function testAssocFalseReturnsObjectTree(): void
    {
        $decoder = DecoderFactory::create(new DecodeOptions(assoc: false));

        $result = $decoder->decodeString('{"a":1}');

        self::assertIsObject($result->value);
        self::assertTrue(property_exists($result->value, 'a'));
        self::assertSame(1, $result->value->a);
    }

    public function testDecodeFlagsCanKeepBigintAsString(): void
    {
        $decoder = DecoderFactory::create(new DecodeOptions(decodeFlags: JSON_BIGINT_AS_STRING));

        $result = $decoder->decodeString('{"n":92233720368547758070}');

        self::assertSame(['n' => '92233720368547758070'], $result->value);
    }

    public function testToArrayIncludesIssueShapeForFileOpenFailure(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeFile('/path/that/does/not/exist.json');
        $serialized = $result->toArray();

        self::assertSame('file_open_failed', $serialized['issues'][0]['type']);
        self::assertSame(-1, $serialized['issues'][0]['position']);
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
     * @phpstan-return iterable<list{string, DecodeIssueType}>
     */
    public static function provideIssueCases(): iterable
    {
        yield ['}', DecodeIssueType::UnexpectedCloser];
        yield ['{]', DecodeIssueType::MismatchedCloser];
        yield ['', DecodeIssueType::JsonDecodeFailed];
    }

    /**
     * @phpstan-return iterable<list<string>>
     */
    public static function providePoliciesThatInsertNull(): iterable
    {
        yield [DecodeOptions::POLICY_BALANCED];
        yield [DecodeOptions::POLICY_AGGRESSIVE];
    }

    /**
     * @phpstan-return iterable<list{list<string>, mixed}>
     */
    public static function provideChunkBoundaryCases(): iterable
    {
        yield [['{', '"a"', ':', '1', ',', '"b"', ':', '[', '2', ',', '3', ']'], json_decode('{"a":1,"b":[2,3]}', true, 512, JSON_THROW_ON_ERROR)];
        yield [['{"x":"\\', 'u1', '2'], ['x' => "\u{1200}"]];
        yield [['{"m":"ab', 'c', 'de', 'f'], ['m' => 'abcdef']];
    }

    /**
     * @phpstan-return iterable<list{string, list<string>}>
     */
    public static function provideRepairActionSequenceCases(): iterable
    {
        yield ['{"foo":', ['insert_missing_value', 'close_container']];
        yield ['[1,2,', ['remove_trailing_comma', 'close_container']];
        yield ['{"x":"abc\\', ['complete_escape', 'close_string', 'close_container']];
    }

    /**
     * @phpstan-return iterable<list{string, string}>
     */
    public static function provideRepairedJsonCases(): iterable
    {
        yield ['{"foo":', '{"foo": null}'];
        yield ['[1,2,', '[1,2]'];
        yield ['{"x":"abc\\', '{"x":"abc\\\\"}'];
    }

    /**
     * @phpstan-return iterable<list{string, DecodeIssueType, int}>
     */
    public static function provideIssuePositionCases(): iterable
    {
        yield ['}', DecodeIssueType::UnexpectedCloser, 0];
        yield ['{]', DecodeIssueType::MismatchedCloser, 1];
        yield ['', DecodeIssueType::JsonDecodeFailed, -1];
    }

    #[DataProvider('provideIssuePositionCases')]
    public function testItRecordsExpectedIssuePosition(string $input, DecodeIssueType $expectedType, int $expectedPosition): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString($input);
        $position = $this->findIssuePosition($result, $expectedType);

        self::assertSame($expectedPosition, $position);
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

    private function findIssuePosition(DecodeResult $result, DecodeIssueType $type): int
    {
        foreach ($result->issues as $decodeIssue) {
            if ($decodeIssue->type === $type) {
                return $decodeIssue->position;
            }
        }

        return -9999;
    }
}
