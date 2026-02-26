<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Internal;

use function mb_scrub;
use function preg_match;

final readonly class MbstringUtf8Sanitizer implements Utf8Sanitizer
{
    public function sanitize(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        return mb_scrub($value, 'UTF-8');
    }
}
