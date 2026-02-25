<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

final readonly class RepairAction
{
    public function __construct(
        public RepairActionType $type,
        public int $position,
        public string $detail,
    ) {
    }
}
