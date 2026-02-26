<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

use Generator;
use JsonException;
use zonuexe\BrokenJson\Internal\Utf8Sanitizer;
use zonuexe\BrokenJson\Repair\RepairingScanner;
use function assert;
use function fclose;
use function fopen;
use function fread;
use function is_resource;
use function json_decode;
use function max;

final readonly class Decoder
{
    public function __construct(
        private DecodeOptions $options,
        private Utf8Sanitizer $utf8Sanitizer,
    ) {
    }

    /**
     * @return list{mixed, list<RepairAction>}
     */
    public function decode(string $json): array
    {
        $result = $this->decodeString($json);

        return [$result->value, $result->repairs];
    }

    public function decodeString(string $json): DecodeResult
    {
        return $this->decodeChunks([$json]);
    }

    /**
     * @param iterable<string> $chunks
     */
    public function decodeChunks(iterable $chunks): DecodeResult
    {
        return $this->decodeIterable($chunks);
    }

    /**
     * @param resource $stream
     */
    public function decodeStream($stream): DecodeResult
    {
        assert(is_resource($stream));

        $chunks = static function () use ($stream): Generator {
            while (true) {
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }

                yield $chunk;
            }
        };

        return $this->decodeIterable($chunks());
    }

    public function decodeFile(string $path): DecodeResult
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return new DecodeResult(
                value: null,
                isRecovered: false,
                isPartial: true,
                repairs: [],
                issues: [new DecodeIssue(DecodeIssueType::FileOpenFailed, "Failed to open '{$path}' for reading.")],
                repairedJson: '',
            );
        }

        try {
            return $this->decodeStream($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param iterable<string> $chunks
     */
    private function decodeIterable(iterable $chunks): DecodeResult
    {
        $scanner = new RepairingScanner($this->options);
        foreach ($chunks as $chunk) {
            $scanner->push($this->utf8Sanitizer->sanitize($chunk));
        }

        $scanResult = $scanner->finalize();
        $repairedJson = $scanResult['json'];
        $repairs = $scanResult['repairs'];
        $issues = $scanResult['issues'];
        $depth = max(1, $this->options->depth);

        try {
            $value = json_decode(
                json: $repairedJson,
                associative: $this->options->assoc,
                depth: $depth,
                flags: $this->options->decodeFlags | JSON_THROW_ON_ERROR,
            );

            return new DecodeResult(
                value: $value,
                isRecovered: $repairs !== [],
                isPartial: $repairs !== [] || $issues !== [],
                repairs: $repairs,
                issues: $issues,
                repairedJson: $repairedJson,
            );
        } catch (JsonException $jsonException) {
            $issues[] = new DecodeIssue(
                type: DecodeIssueType::JsonDecodeFailed,
                message: $jsonException->getMessage(),
            );

            return new DecodeResult(
                value: null,
                isRecovered: $repairs !== [],
                isPartial: true,
                repairs: $repairs,
                issues: $issues,
                repairedJson: $repairedJson,
            );
        }
    }
}
