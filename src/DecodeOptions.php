<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;
use ValueError;

final readonly class DecodeOptions
{
    public const string POLICY_CONSERVATIVE = 'conservative';

    public const string POLICY_BALANCED = 'balanced';

    public const string POLICY_AGGRESSIVE = 'aggressive';

    /**
     * @param int-mask-of<JSON_BIGINT_AS_STRING|JSON_OBJECT_AS_ARRAY|JSON_INVALID_UTF8_IGNORE|JSON_INVALID_UTF8_SUBSTITUTE> $decodeFlags
     */
    public function __construct(
        public string $repairPolicy = self::POLICY_BALANCED,
        public bool $assoc = true,
        public int $depth = 512,
        public int $decodeFlags = 0,
    ) {
        if ($depth < 1) {
            throw new ValueError('depth must be greater than 0.');
        }
    }

    public function shouldInsertNullForDanglingColon(): bool
    {
        return $this->repairPolicy !== self::POLICY_CONSERVATIVE;
    }
}
