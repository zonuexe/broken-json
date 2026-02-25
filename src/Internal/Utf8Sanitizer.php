<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Internal;

use function function_exists;
use function iconv;
use function preg_match;

final class Utf8Sanitizer
{
    public static function sanitize(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        if (function_exists('iconv')) {
            $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($sanitized !== false) {
                return $sanitized;
            }
        }

        return $value;
    }
}
