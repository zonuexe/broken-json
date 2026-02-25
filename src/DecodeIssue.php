<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

use JsonSerializable;

final readonly class DecodeIssue implements JsonSerializable
{
    /**
     * @param int<-1, max> $position
     */
    public function __construct(
        public DecodeIssueType $type,
        public string $message,
        public int $position = -1,
    ) {
    }

    /**
     * @phpstan-return array{type: string, message: string, position: int<-1, max>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'message' => $this->message,
            'position' => $this->position,
        ];
    }

    /**
     * @phpstan-return array{type: string, message: string, position: int<-1, max>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
