<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use zonuexe\BrokenJson\Internal\IconvUtf8Sanitizer;
use zonuexe\BrokenJson\Internal\Utf8Sanitizer;
use function function_exists;

#[CoversClass(IconvUtf8Sanitizer::class)]
final class IconvUtf8SanitizerTest extends Utf8SanitizerTestCase
{
    protected function setUp(): void
    {
        if (!function_exists('iconv')) {
            self::markTestSkipped('iconv is not available.');
        }
    }

    protected function createSanitizer(): Utf8Sanitizer
    {
        return new IconvUtf8Sanitizer();
    }

    protected function assertInvalidUtf8Sanitized(string $sanitized): void
    {
        self::assertSame('(', $sanitized);
    }
}
