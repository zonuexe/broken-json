<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Fuzz;

use Error;
use JsonException;
use PhpFuzzer\Config;
use zonuexe\BrokenJson\DecodeOptions;
use zonuexe\BrokenJson\DecoderFactory;
use function intdiv;
use function json_encode;
use function strlen;
use function substr;

/** @var Config $config */

require __DIR__ . '/../vendor/autoload.php';

$decoder = DecoderFactory::create(new DecodeOptions());

$config->setTarget(static function (string $input) use ($decoder): void {
    $stringResult = $decoder->decodeString($input);
    $half = intdiv(strlen($input), 2);
    $chunkResult = $decoder->decodeChunks([
        substr($input, 0, $half),
        substr($input, $half),
    ]);

    if ($stringResult->toArray() !== $chunkResult->toArray()) {
        throw new Error('decodeString() and decodeChunks() produced different results.');
    }

    [$legacyValue, $legacyRepairs] = $decoder->decode($input);
    if ($legacyValue != $stringResult->value) {
        throw new Error('decode() and decodeString() value mismatch.');
    }

    if ($legacyRepairs != $stringResult->repairs) {
        throw new Error('decode() and decodeString() repair list mismatch.');
    }

    try {
        json_encode($stringResult->toArray(), JSON_THROW_ON_ERROR);
    } catch (JsonException $jsonException) {
        throw new Error('DecodeResult::toArray() is not JSON-serializable.', previous: $jsonException);
    }
});

$config->setMaxLen(16 * 1024);
$config->addDictionary(__DIR__ . '/json.dict');
