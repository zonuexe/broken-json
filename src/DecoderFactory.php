<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

final class DecoderFactory
{
    public static function create(?DecodeOptions $options = null): Decoder
    {
        return new Decoder($options ?? new DecodeOptions());
    }
}
