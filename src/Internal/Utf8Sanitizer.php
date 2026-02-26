<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Internal;

interface Utf8Sanitizer
{
    public function sanitize(string $value): string;
}
