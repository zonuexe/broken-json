<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests;

use AssertionError;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use zonuexe\BrokenJson\DecodeIssue;
use zonuexe\BrokenJson\DecodeIssueType;
use zonuexe\BrokenJson\DecodeOptions;
use zonuexe\BrokenJson\Decoder;
use zonuexe\BrokenJson\DecodeResult;
use zonuexe\BrokenJson\DecoderFactory;
use zonuexe\BrokenJson\Internal\IconvUtf8Sanitizer;
use zonuexe\BrokenJson\Internal\MbstringUtf8Sanitizer;
use zonuexe\BrokenJson\Internal\Utf8Sanitizer;
use zonuexe\BrokenJson\Repair\RepairingScanner;
use zonuexe\BrokenJson\RepairAction;
use zonuexe\BrokenJson\RepairActionType;
use zonuexe\BrokenJson\Tests\Support\PrivateHelper;
use function array_map;
use function file_put_contents;
use function fopen;
use function fwrite;
use function json_decode;
use function json_encode;
use function property_exists;
use function rewind;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use const DIRECTORY_SEPARATOR;

#[CoversClass(DecodeIssue::class)]
#[CoversClass(DecodeIssueType::class)]
#[CoversClass(DecodeOptions::class)]
#[CoversClass(DecodeResult::class)]
#[CoversClass(Decoder::class)]
#[CoversClass(DecoderFactory::class)]
#[CoversClass(RepairAction::class)]
#[CoversClass(RepairActionType::class)]
#[CoversClass(RepairingScanner::class)]
#[UsesClass(IconvUtf8Sanitizer::class)]
#[UsesClass(MbstringUtf8Sanitizer::class)]
final class DecoderTest extends TestCase
{
    use PrivateHelper;

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

    /**
     * @phpstan-param list<RepairActionType> $expectedRepairTypes
     * @phpstan-param list<DecodeIssueType> $expectedIssueTypes
     */
    #[DataProvider('provideRepairPolicyAndTailCases')]
    public function testRepairPolicyAndTailCase(
        string $input,
        string $policy,
        mixed $expectedValue,
        string $expectedRepairedJson,
        bool $expectedRecovered,
        bool $expectedPartial,
        array $expectedRepairTypes,
        array $expectedIssueTypes,
    ): void {
        $result = DecoderFactory::create(new DecodeOptions(repairPolicy: $policy))->decodeString($input);
        $repairTypes = array_map(
            static fn ($repairAction): RepairActionType => $repairAction->type,
            $result->repairs,
        );
        $issueTypes = array_map(
            static fn ($decodeIssue): DecodeIssueType => $decodeIssue->type,
            $result->issues,
        );

        self::assertSame($expectedValue, $result->value);
        self::assertSame($expectedRepairedJson, $result->repairedJson);
        self::assertSame($expectedRecovered, $result->isRecovered);
        self::assertSame($expectedPartial, $result->isPartial);
        self::assertSame($expectedRepairTypes, $repairTypes);
        self::assertSame($expectedIssueTypes, $issueTypes);
    }

    /**
     * @phpstan-param list<string> $chunks
     */
    #[DataProvider('provideDecodeAcrossSourcesCases')]
    public function testItCanDecodeAcrossSources(string $source, array $chunks, mixed $expected): void
    {
        $result = $this->decodeWithSource($source, $chunks);

        self::assertSame($expected, $result->value);
    }

    public function testDecodeStreamUtf8BoundaryAt8192CanRecoverWhenSanitizerPreservesBytes(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        $payload = '{"x":"' . str_repeat('a', 8185) . "\xC3\xA9" . '"}';
        fwrite($stream, $payload);
        rewind($stream);

        $result = DecoderFactory::create(utf8Sanitizer: $this->createPassthroughUtf8Sanitizer())
            ->decodeStream($stream);

        self::assertSame(['x' => str_repeat('a', 8185) . 'é'], $result->value);
    }

    #[DataProvider('provideDecodeStreamChunkCountCases')]
    public function testDecodeStreamChunkCount(int $valueLength, int $expectedChunkCount): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, '{"x":"' . str_repeat('a', $valueLength) . '"}');
        rewind($stream);

        $chunkCounter = 0;
        DecoderFactory::create(
            utf8Sanitizer: $this->createCountingUtf8Sanitizer($chunkCounter),
        )->decodeStream($stream);

        self::assertSame($expectedChunkCount, $chunkCounter);
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

    /**
     * @phpstan-param list<RepairActionType> $expectedRepairTypes
     * @phpstan-param list<DecodeIssueType> $expectedIssueTypes
     */
    #[DataProvider('provideResultStateCases')]
    public function testResultState(
        string $input,
        bool $expectedRecovered,
        bool $expectedPartial,
        array $expectedRepairTypes,
        array $expectedIssueTypes,
    ): void {
        $result = DecoderFactory::create()->decodeString($input);
        $repairTypes = array_map(
            static fn ($repairAction): RepairActionType => $repairAction->type,
            $result->repairs,
        );
        $issueTypes = array_map(
            static fn ($decodeIssue): DecodeIssueType => $decodeIssue->type,
            $result->issues,
        );

        self::assertSame($expectedRecovered, $result->isRecovered);
        self::assertSame($expectedPartial, $result->isPartial);
        self::assertSame($expectedRepairTypes, $repairTypes);
        self::assertSame($expectedIssueTypes, $issueTypes);
    }

    public function testRepairActionPositionsAreNonNegative(): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString('{"x":"abc\\');

        foreach ($result->repairs as $repairAction) {
            self::assertGreaterThanOrEqual(0, $repairAction->position);
        }
    }

    /**
     * @phpstan-param list<DecodeIssueType> $expectedIssueTypes
     */
    #[DataProvider('provideIssueSetCases')]
    public function testItCollectsExpectedIssueSet(string $input, array $expectedIssueTypes): void
    {
        $issueTypes = array_map(
            static fn ($decodeIssue): DecodeIssueType => $decodeIssue->type,
            DecoderFactory::create()->decodeString($input)->issues,
        );

        self::assertSame($expectedIssueTypes, $issueTypes);
    }

    /**
     * @phpstan-param list<string> $expectedTypes
     */
    #[DataProvider('provideRepairOutcomeCases')]
    public function testItProducesExpectedRepairOutcome(string $input, string $expectedRepairedJson, array $expectedTypes): void
    {
        $decoder = DecoderFactory::create();

        $result = $decoder->decodeString($input);
        $repairTypes = array_map(
            static fn ($repairAction): string => $repairAction->type->value,
            $result->repairs,
        );

        self::assertSame($expectedRepairedJson, $result->repairedJson);
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

    #[DataProvider('provideSerializationProjectionCases')]
    public function testSerializationProjection(
        string $kind,
        mixed $expectedValue,
        bool $expectedRecovered,
        bool $expectedPartial,
        string $expectedRepairedJson,
        string $expectedFirstType,
        int $expectedFirstPosition,
    ): void {
        $result = $this->createResultForSerializationCase($kind);
        $serialized = $result->toArray();

        self::assertSame($serialized, $result->jsonSerialize());
        self::assertSame($expectedValue, $serialized['value']);
        self::assertSame($expectedRecovered, $serialized['isRecovered']);
        self::assertSame($expectedPartial, $serialized['isPartial']);
        self::assertSame($expectedRepairedJson, $serialized['repairedJson']);
        if ($expectedRecovered) {
            self::assertSame($expectedFirstType, $serialized['repairs'][0]['type']);
            self::assertSame($expectedFirstPosition, $serialized['repairs'][0]['position']);
            return;
        }

        self::assertSame($expectedFirstType, $serialized['issues'][0]['type']);
        self::assertSame($expectedFirstPosition, $serialized['issues'][0]['position']);
    }

    /**
     * @phpstan-param list<string> $expectedFragments
     */
    #[DataProvider('provideJsonSerializationContainsCases')]
    public function testJsonSerializationContainsExpectedFragments(string $input, array $expectedFragments): void
    {
        $decoder = DecoderFactory::create();
        $result = $decoder->decodeString($input);
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        foreach ($expectedFragments as $expectedFragment) {
            self::assertStringContainsString($expectedFragment, $json);
        }
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

    public function testDecodeOptionsDefaultsAreStable(): void
    {
        $options = new DecodeOptions();

        self::assertSame(DecodeOptions::POLICY_BALANCED, $options->repairPolicy);
        self::assertTrue($options->assoc);
        self::assertSame(512, $options->depth);
        self::assertSame(0, $options->decodeFlags);
    }

    public function testDecodeOptionsDepthMustBePositive(): void
    {
        $this->expectException(AssertionError::class);

        new DecodeOptions(depth: 0);
    }

    public function testDepthOneCanFailForNestedJson(): void
    {
        $decoder = DecoderFactory::create(new DecodeOptions(depth: 1));

        $result = $decoder->decodeString('{}');

        self::assertNull($result->value);
        self::assertSame(DecodeIssueType::JsonDecodeFailed, $result->issues[0]->type);
    }

    public function testScannerFinalizeResetsStringFlagsToStableState(): void
    {
        $scanner = new RepairingScanner(new DecodeOptions());
        $scanner->push('{"x":"abc\\');
        $scanner->finalize();

        self::assertFalse($this->readPrivateProperty($scanner, 'inString'));
        self::assertFalse($this->readPrivateProperty($scanner, 'escaping'));
        self::assertSame(0, $this->readPrivateProperty($scanner, 'unicodeRemaining'));
    }

    public function testScannerInvalidUnicodeEscapeClearsUnicodeRemainingCounter(): void
    {
        $scanner = new RepairingScanner(new DecodeOptions());
        $scanner->push('{"x":"\u12g"}');

        self::assertSame(0, $this->readPrivateProperty($scanner, 'unicodeRemaining'));
    }

    public function testScannerLastNonWhitespaceIndexReturnsMinusOneForWhitespaceBuffer(): void
    {
        $scanner = new RepairingScanner(new DecodeOptions());
        $this->writePrivateProperty($scanner, 'buffer', " \n\t ");

        self::assertSame(-1, $this->invokePrivateMethod($scanner, 'lastNonWhitespaceIndex'));
    }

    public function testScannerStripTrailingCommaHandlesIndexZero(): void
    {
        $scanner = new RepairingScanner(new DecodeOptions());
        $this->writePrivateProperty($scanner, 'buffer', ',');
        $this->writePrivateProperty($scanner, 'repairs', []);

        $this->invokePrivateMethod($scanner, 'stripTrailingComma');
        $repairs = $this->readPrivateProperty($scanner, 'repairs');

        self::assertSame('', $this->readPrivateProperty($scanner, 'buffer'));
        self::assertIsArray($repairs);
        self::assertCount(1, $repairs);
        self::assertInstanceOf(RepairAction::class, $repairs[0]);
        self::assertSame(RepairActionType::RemoveTrailingComma, $repairs[0]->type);
        self::assertSame(0, $repairs[0]->position);
    }

    public function testScannerStripTrailingCommaPreservesBufferPrefix(): void
    {
        $scanner = new RepairingScanner(new DecodeOptions());
        $this->writePrivateProperty($scanner, 'buffer', 'abc,');
        $this->writePrivateProperty($scanner, 'repairs', []);

        $this->invokePrivateMethod($scanner, 'stripTrailingComma');
        $repairs = $this->readPrivateProperty($scanner, 'repairs');

        self::assertSame('abc', $this->readPrivateProperty($scanner, 'buffer'));
        self::assertIsArray($repairs);
        self::assertCount(1, $repairs);
        self::assertInstanceOf(RepairAction::class, $repairs[0]);
        self::assertSame(RepairActionType::RemoveTrailingComma, $repairs[0]->type);
        self::assertSame(3, $repairs[0]->position);
    }

    public function testScannerStripTrailingCommaPreservesTrailingWhitespace(): void
    {
        $scanner = new RepairingScanner(new DecodeOptions());
        $this->writePrivateProperty($scanner, 'buffer', 'abc,   ');
        $this->writePrivateProperty($scanner, 'repairs', []);

        $this->invokePrivateMethod($scanner, 'stripTrailingComma');
        $repairs = $this->readPrivateProperty($scanner, 'repairs');

        self::assertSame('abc   ', $this->readPrivateProperty($scanner, 'buffer'));
        self::assertIsArray($repairs);
        self::assertCount(1, $repairs);
        self::assertInstanceOf(RepairAction::class, $repairs[0]);
        self::assertSame(RepairActionType::RemoveTrailingComma, $repairs[0]->type);
        self::assertSame(3, $repairs[0]->position);
    }

    /**
     * @phpstan-param array<value-of<RepairActionType>, 0|positive-int> $expectedPositionsByType
     */
    #[DataProvider('provideRepairPositionCases')]
    public function testRepairActionPositionsAreExact(string $input, array $expectedPositionsByType): void
    {
        $result = DecoderFactory::create()->decodeString($input);
        $actualPositionsByType = [];
        foreach ($result->repairs as $repairAction) {
            $actualPositionsByType[$repairAction->type->value] = $repairAction->position;
        }

        self::assertSame($expectedPositionsByType, $actualPositionsByType);
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
     * @phpstan-return iterable<array{input: string, policy: string, expectedValue: mixed, expectedRepairedJson: string, expectedRecovered: bool, expectedPartial: bool, expectedRepairTypes: list<RepairActionType>, expectedIssueTypes: list<DecodeIssueType>}>
     */
    public static function provideRepairPolicyAndTailCases(): iterable
    {
        yield 'balanced-dangling-colon' => [
            'input' => '{"foo":',
            'policy' => DecodeOptions::POLICY_BALANCED,
            'expectedValue' => ['foo' => null],
            'expectedRepairedJson' => '{"foo": null}',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::InsertMissingValue, RepairActionType::CloseContainer],
            'expectedIssueTypes' => [],
        ];
        yield 'aggressive-dangling-colon' => [
            'input' => '{"foo":',
            'policy' => DecodeOptions::POLICY_AGGRESSIVE,
            'expectedValue' => ['foo' => null],
            'expectedRepairedJson' => '{"foo": null}',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::InsertMissingValue, RepairActionType::CloseContainer],
            'expectedIssueTypes' => [],
        ];
        yield 'conservative-dangling-colon' => [
            'input' => '{"foo":',
            'policy' => DecodeOptions::POLICY_CONSERVATIVE,
            'expectedValue' => null,
            'expectedRepairedJson' => '{"foo"}',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::RemoveTrailingColon, RepairActionType::CloseContainer],
            'expectedIssueTypes' => [DecodeIssueType::JsonDecodeFailed],
        ];
        yield 'balanced-comma-then-colon' => [
            'input' => '{"a":,',
            'policy' => DecodeOptions::POLICY_BALANCED,
            'expectedValue' => ['a' => null],
            'expectedRepairedJson' => '{"a": null}',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::RemoveTrailingComma, RepairActionType::InsertMissingValue, RepairActionType::CloseContainer],
            'expectedIssueTypes' => [],
        ];
        yield 'balanced-trailing-comma-with-whitespace' => [
            'input' => '{"a":1,   ',
            'policy' => DecodeOptions::POLICY_BALANCED,
            'expectedValue' => ['a' => 1],
            'expectedRepairedJson' => '{"a":1   }',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::RemoveTrailingComma, RepairActionType::CloseContainer],
            'expectedIssueTypes' => [],
        ];
        yield 'conservative-dangling-colon-with-whitespace' => [
            'input' => '{"a":   ',
            'policy' => DecodeOptions::POLICY_CONSERVATIVE,
            'expectedValue' => null,
            'expectedRepairedJson' => '{"a"   }',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::RemoveTrailingColon, RepairActionType::CloseContainer],
            'expectedIssueTypes' => [DecodeIssueType::JsonDecodeFailed],
        ];
        yield 'array-trailing-comma' => [
            'input' => '[1, 2,',
            'policy' => DecodeOptions::POLICY_BALANCED,
            'expectedValue' => [1, 2],
            'expectedRepairedJson' => '[1, 2]',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::RemoveTrailingComma, RepairActionType::CloseContainer],
            'expectedIssueTypes' => [],
        ];
        yield 'object-trailing-comma' => [
            'input' => '{"a":1,',
            'policy' => DecodeOptions::POLICY_BALANCED,
            'expectedValue' => ['a' => 1],
            'expectedRepairedJson' => '{"a":1}',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::RemoveTrailingComma, RepairActionType::CloseContainer],
            'expectedIssueTypes' => [],
        ];
        yield 'single-comma-input' => [
            'input' => ',',
            'policy' => DecodeOptions::POLICY_BALANCED,
            'expectedValue' => null,
            'expectedRepairedJson' => '',
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairTypes' => [RepairActionType::RemoveTrailingComma],
            'expectedIssueTypes' => [DecodeIssueType::JsonDecodeFailed],
        ];
    }

    /**
     * @phpstan-return iterable<list{int, int}>
     */
    public static function provideDecodeStreamChunkCountCases(): iterable
    {
        yield '16383 bytes => 2 chunks' => [16376, 2];
        yield '16385 bytes => 3 chunks' => [16377, 3];
        yield '16386 bytes => 3 chunks' => [16379, 3];
    }

    /**
     * @phpstan-return iterable<array{source: string, chunks: list<string>, expected: mixed}>
     */
    public static function provideDecodeAcrossSourcesCases(): iterable
    {
        yield 'stream-source' => [
            'source' => 'stream',
            'chunks' => ['{"a":"b'],
            'expected' => ['a' => 'b'],
        ];
        yield 'file-source' => [
            'source' => 'file',
            'chunks' => ['[10, 20, 30'],
            'expected' => [10, 20, 30],
        ];
        yield 'chunks-source' => [
            'source' => 'chunks',
            'chunks' => ['{"x":"ab', 'c\\'],
            'expected' => ['x' => 'abc\\'],
        ];
    }

    /**
     * @phpstan-return iterable<array{input: string, expectedRecovered: bool, expectedPartial: bool, expectedRepairTypes: list<RepairActionType>, expectedIssueTypes: list<DecodeIssueType>}>
     */
    public static function provideResultStateCases(): iterable
    {
        yield 'valid-json' => [
            'input' => '{"ok":true,"count":1}',
            'expectedRecovered' => false,
            'expectedPartial' => false,
            'expectedRepairTypes' => [],
            'expectedIssueTypes' => [],
        ];
        yield 'empty-input' => [
            'input' => '',
            'expectedRecovered' => false,
            'expectedPartial' => true,
            'expectedRepairTypes' => [],
            'expectedIssueTypes' => [DecodeIssueType::JsonDecodeFailed],
        ];
        yield 'unexpected-closer' => [
            'input' => '}',
            'expectedRecovered' => false,
            'expectedPartial' => true,
            'expectedRepairTypes' => [],
            'expectedIssueTypes' => [DecodeIssueType::UnexpectedCloser, DecodeIssueType::JsonDecodeFailed],
        ];
    }

    /**
     * @phpstan-return iterable<array{kind: string, expectedValue: mixed, expectedRecovered: bool, expectedPartial: bool, expectedRepairedJson: string, expectedFirstType: string, expectedFirstPosition: int}>
     */
    public static function provideSerializationProjectionCases(): iterable
    {
        yield 'recovered-json' => [
            'kind' => 'recovered-json',
            'expectedValue' => ['x' => 'abc\\'],
            'expectedRecovered' => true,
            'expectedPartial' => true,
            'expectedRepairedJson' => '{"x":"abc\\\\"}',
            'expectedFirstType' => 'complete_escape',
            'expectedFirstPosition' => 10,
        ];
        yield 'file-open-failed' => [
            'kind' => 'file-open-failed',
            'expectedValue' => null,
            'expectedRecovered' => false,
            'expectedPartial' => true,
            'expectedRepairedJson' => '',
            'expectedFirstType' => 'file_open_failed',
            'expectedFirstPosition' => -1,
        ];
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
     * @phpstan-return iterable<list{string, string, list<string>}>
     */
    public static function provideRepairOutcomeCases(): iterable
    {
        yield ['{"foo":', '{"foo": null}', ['insert_missing_value', 'close_container']];
        yield ['[1,2,', '[1,2]', ['remove_trailing_comma', 'close_container']];
        yield ['{"x":"abc\\', '{"x":"abc\\\\"}', ['complete_escape', 'close_string', 'close_container']];
    }

    /**
     * @phpstan-return iterable<list{string, DecodeIssueType, int}>
     */
    public static function provideIssuePositionCases(): iterable
    {
        yield ['}', DecodeIssueType::UnexpectedCloser, 0];
        yield ['{]', DecodeIssueType::MismatchedCloser, 1];
        yield ['', DecodeIssueType::JsonDecodeFailed, -1];
        yield ['{"x":"\u12g"}', DecodeIssueType::InvalidUnicodeEscape, 10];
    }

    /**
     * @phpstan-return iterable<array{input: string, expectedFragments: list<string>}>
     */
    public static function provideJsonSerializationContainsCases(): iterable
    {
        yield 'recovered-payload' => [
            'input' => '{"x":"abc\\',
            'expectedFragments' => ['"isRecovered":true', '"type":"complete_escape"'],
        ];
        yield 'decode-failed-payload' => [
            'input' => '',
            'expectedFragments' => ['"type":"json_decode_failed"'],
        ];
    }

    /**
     * @phpstan-return iterable<array{input: string, expectedIssueTypes: list<DecodeIssueType>}>
     */
    public static function provideIssueSetCases(): iterable
    {
        yield 'unexpected-closer-only' => [
            'input' => '}',
            'expectedIssueTypes' => [DecodeIssueType::UnexpectedCloser, DecodeIssueType::JsonDecodeFailed],
        ];
        yield 'mismatched-closer-only' => [
            'input' => '{]',
            'expectedIssueTypes' => [DecodeIssueType::MismatchedCloser, DecodeIssueType::JsonDecodeFailed],
        ];
        yield 'empty-input-decode-failed' => [
            'input' => '',
            'expectedIssueTypes' => [DecodeIssueType::JsonDecodeFailed],
        ];
        yield 'invalid-unicode-then-decode-failed' => [
            'input' => '{"x":"\u12g"}',
            'expectedIssueTypes' => [DecodeIssueType::InvalidUnicodeEscape, DecodeIssueType::JsonDecodeFailed],
        ];
    }

    /**
     * @phpstan-return iterable<array{input: string, expectedPositionsByType: array<value-of<RepairActionType>, 0|positive-int>}>
     */
    public static function provideRepairPositionCases(): iterable
    {
        yield 'dangling-escape' => [
            'input' => '{"x":"abc\\',
            'expectedPositionsByType' => [
                'complete_escape' => 10,
                'close_string' => 11,
                'close_container' => 12,
            ],
        ];
        yield 'incomplete-unicode' => [
            'input' => '{"x":"\u12',
            'expectedPositionsByType' => [
                'complete_unicode_escape' => 11,
                'close_string' => 12,
                'close_container' => 13,
            ],
        ];
        yield 'dangling-colon-inserts-null' => [
            'input' => '{"foo":',
            'expectedPositionsByType' => [
                'insert_missing_value' => 11,
                'close_container' => 12,
            ],
        ];
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
     * @param list<string> $chunks
     */
    private function decodeWithSource(string $source, array $chunks): DecodeResult
    {
        $decoder = DecoderFactory::create();
        if ($source === 'chunks') {
            return $decoder->decodeChunks($this->provideChunks(...$chunks));
        }

        $payload = '';
        foreach ($chunks as $chunk) {
            $payload .= $chunk;
        }

        if ($source === 'stream') {
            $stream = fopen('php://temp', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, $payload);
            rewind($stream);

            return $decoder->decodeStream($stream);
        }

        $path = tempnam(sys_get_temp_dir() . DIRECTORY_SEPARATOR, 'broken-json-');
        self::assertNotFalse($path);
        file_put_contents($path, $payload);

        return $decoder->decodeFile($path);
    }

    private function createResultForSerializationCase(string $kind): DecodeResult
    {
        if ($kind === 'recovered-json') {
            return DecoderFactory::create()->decodeString('{"x":"abc\\');
        }

        return DecoderFactory::create()->decodeFile('/path/that/does/not/exist.json');
    }

    private function createPassthroughUtf8Sanitizer(): Utf8Sanitizer
    {
        return new class () implements Utf8Sanitizer {
            public function sanitize(string $value): string
            {
                return $value;
            }
        };
    }

    private function createCountingUtf8Sanitizer(int &$chunkCounter): Utf8Sanitizer
    {
        return new class ($chunkCounter) implements Utf8Sanitizer {
            public function __construct(
                private int &$chunkCounter,
            ) {
            }

            public function sanitize(string $value): string
            {
                ++$this->chunkCounter;

                return $value;
            }
        };
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
