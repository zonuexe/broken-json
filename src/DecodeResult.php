<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

use function array_map;

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

    /**
     * @phpstan-return array{
     *     value: mixed,
     *     isRecovered: bool,
     *     isPartial: bool,
     *     repairedJson: string,
     *     repairs: list<array{type: string, position: 0|positive-int, detail: string}>,
     *     issues: list<array{type: string, message: string, position: int<-1, max>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'isRecovered' => $this->isRecovered,
            'isPartial' => $this->isPartial,
            'repairedJson' => $this->repairedJson,
            'repairs' => array_map(
                static fn (RepairAction $repairAction): array => $repairAction->toArray(),
                $this->repairs,
            ),
            'issues' => array_map(
                static fn (DecodeIssue $decodeIssue): array => $decodeIssue->toArray(),
                $this->issues,
            ),
        ];
    }
}
