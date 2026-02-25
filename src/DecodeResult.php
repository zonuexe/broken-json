<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

final readonly class DecodeResult
{
    /**
     * @param list<RepairAction> $repairs
     * @param list<DecodeIssue> $issues
     */
    public function __construct(
        public mixed $value,
        public bool $isRecovered,
        public bool $isPartial,
        public array $repairs,
        public array $issues,
        public string $repairedJson,
    ) {
    }
}
