<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Tests\Support;

use Closure;

trait PrivateHelper
{
    private function readPrivateProperty(object $object, string $propertyName): mixed
    {
        $reader = Closure::bind(
            static fn (): mixed => $object->{$propertyName},
            null,
            $object,
        );

        return $reader();
    }

    private function writePrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $writer = Closure::bind(
            static fn (): mixed => $object->{$propertyName} = $value,
            null,
            $object,
        );

        $writer();
    }

    private function invokePrivateMethod(object $object, string $methodName): mixed
    {
        $invoker = Closure::bind(
            static fn (): mixed => $object->{$methodName}(),
            null,
            $object,
        );

        return $invoker();
    }
}
