<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

final readonly class DecodeIssue
{
    public function __construct(
        public DecodeIssueType $type,
        public string $message,
        public int $position = -1,
    ) {
    }
}
