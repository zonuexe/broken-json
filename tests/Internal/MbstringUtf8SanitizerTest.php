<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use zonuexe\BrokenJson\Internal\MbstringUtf8Sanitizer;
use zonuexe\BrokenJson\Internal\Utf8Sanitizer;
use function function_exists;
use function str_contains;

#[CoversClass(MbstringUtf8Sanitizer::class)]
final class MbstringUtf8SanitizerTest extends Utf8SanitizerTestCase
{
    protected function setUp(): void
    {
        if (!function_exists('mb_scrub')) {
            self::markTestSkipped('mbstring (mb_scrub) is not available.');
        }
    }

    protected function createSanitizer(): Utf8Sanitizer
    {
        return new MbstringUtf8Sanitizer();
    }

    protected function assertInvalidUtf8Sanitized(string $sanitized): void
    {
        self::assertTrue(str_contains($sanitized, '('));
        self::assertNotSame("\xC3\x28", $sanitized);
    }
}
