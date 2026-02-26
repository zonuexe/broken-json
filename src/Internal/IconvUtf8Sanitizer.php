<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Internal;

use function iconv;
use function preg_match;

final readonly class IconvUtf8Sanitizer implements Utf8Sanitizer
{
    public function sanitize(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($sanitized !== false) {
            return $sanitized;
        }

        return $value;
    }
}
