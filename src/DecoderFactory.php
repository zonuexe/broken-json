<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

use LogicException;
use zonuexe\BrokenJson\Internal\IconvUtf8Sanitizer;
use zonuexe\BrokenJson\Internal\MbstringUtf8Sanitizer;
use zonuexe\BrokenJson\Internal\Utf8Sanitizer;
use function function_exists;

final class DecoderFactory
{
    public static function create(?DecodeOptions $options = null, ?Utf8Sanitizer $utf8Sanitizer = null): Decoder
    {
        return new Decoder(
            $options ?? new DecodeOptions(),
            $utf8Sanitizer ?? self::createUtf8Sanitizer(),
        );
    }

    private static function createUtf8Sanitizer(): Utf8Sanitizer
    {
        return match (true) {
            function_exists('mb_scrub') => new MbstringUtf8Sanitizer(),
            function_exists('iconv') => new IconvUtf8Sanitizer(),
            default => throw new LogicException('No UTF-8 sanitizer backend is available. Install iconv or mbstring.'),
        };
    }
}
