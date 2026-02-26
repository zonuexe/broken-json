<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use zonuexe\BrokenJson\DecodeOptions;
use zonuexe\BrokenJson\Decoder;
use zonuexe\BrokenJson\DecoderFactory;
use zonuexe\BrokenJson\Internal\IconvUtf8Sanitizer;
use zonuexe\BrokenJson\Internal\MbstringUtf8Sanitizer;
use zonuexe\BrokenJson\Internal\Utf8Sanitizer;
use zonuexe\BrokenJson\Tests\Support\PrivateHelper;
use function function_exists;

#[CoversClass(DecoderFactory::class)]
#[CoversClass(Decoder::class)]
#[CoversClass(IconvUtf8Sanitizer::class)]
#[CoversClass(MbstringUtf8Sanitizer::class)]
#[UsesClass(DecodeOptions::class)]
final class DecoderFactoryTest extends TestCase
{
    use PrivateHelper;

    public function testCreateReturnsDecoder(): void
    {
        self::assertInstanceOf(Decoder::class, DecoderFactory::create());
    }

    public function testCreateUsesInjectedUtf8Sanitizer(): void
    {
        $sanitizer = new class () implements Utf8Sanitizer {
            public function sanitize(string $value): string
            {
                return $value;
            }
        };
        $decoder = DecoderFactory::create(utf8Sanitizer: $sanitizer);
        $actual = $this->readPrivateProperty($decoder, 'utf8Sanitizer');

        self::assertSame($sanitizer, $actual);
    }

    public function testCreatePrefersMbstringSanitizerWhenAvailable(): void
    {
        if (!function_exists('mb_scrub')) {
            self::markTestSkipped('mbstring (mb_scrub) is not available.');
        }

        $decoder = DecoderFactory::create();
        $utf8Sanitizer = $this->readPrivateProperty($decoder, 'utf8Sanitizer');

        self::assertInstanceOf(MbstringUtf8Sanitizer::class, $utf8Sanitizer);
    }
}
