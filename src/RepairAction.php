<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

final readonly class RepairAction
{
    /**
     * @param 0|positive-int $position
     */
    public function __construct(
        public RepairActionType $type,
        public int $position,
        public string $detail,
    ) {
    }

    /**
     * @phpstan-return array{type: string, position: 0|positive-int, detail: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'position' => $this->position,
            'detail' => $this->detail,
        ];
    }
}
