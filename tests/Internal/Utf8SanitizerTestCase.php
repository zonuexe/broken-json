<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests\Internal;

use PHPUnit\Framework\TestCase;
use zonuexe\BrokenJson\Internal\Utf8Sanitizer;
use function preg_match;

abstract class Utf8SanitizerTestCase extends TestCase
{
    abstract protected function createSanitizer(): Utf8Sanitizer;

    protected function assertInvalidUtf8Sanitized(string $sanitized): void
    {
        self::assertNotSame("\xC3\x28", $sanitized);
    }

    public function testItKeepsValidUtf8Unchanged(): void
    {
        $sanitizer = $this->createSanitizer();

        self::assertSame('hello', $sanitizer->sanitize('hello'));
    }

    public function testItSanitizesInvalidUtf8ToValidUtf8(): void
    {
        $sanitizer = $this->createSanitizer();
        $sanitized = $sanitizer->sanitize("\xC3\x28");

        self::assertSame(1, preg_match('//u', $sanitized));
        $this->assertInvalidUtf8Sanitized($sanitized);
    }
}
