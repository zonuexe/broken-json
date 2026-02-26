<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson\Repair;

use zonuexe\BrokenJson\DecodeIssue;
use zonuexe\BrokenJson\DecodeIssueType;
use zonuexe\BrokenJson\DecodeOptions;
use zonuexe\BrokenJson\RepairAction;
use zonuexe\BrokenJson\RepairActionType;
use function array_pop;
use function count;
use function ctype_space;
use function ctype_xdigit;
use function in_array;
use function str_repeat;
use function strlen;
use function substr;

final class RepairingScanner
{
    private string $buffer = '';

    private bool $inString = false;

    private bool $escaping = false;

    private int $unicodeRemaining = 0;

    /**
     * @var list<'{'|'['>
     */
    private array $stack = [];

    /**
     * @var list<RepairAction>
     */
    private array $repairs = [];

    /**
     * @var list<DecodeIssue>
     */
    private array $issues = [];

    public function __construct(
        private readonly DecodeOptions $options,
    ) {
    }

    public function push(string $chunk): void
    {
        $length = strlen($chunk);
        for ($index = 0; $index < $length; ++$index) {
            $char = $chunk[$index];

            if ($this->inString) {
                $this->buffer .= $char;

                if ($this->unicodeRemaining > 0) {
                    if (ctype_xdigit($char)) {
                        --$this->unicodeRemaining;
                        continue;
                    }

                    $this->issues[] = new DecodeIssue(
                        DecodeIssueType::InvalidUnicodeEscape,
                        'Encountered an invalid unicode escape in a truncated string.',
                        strlen($this->buffer) - 1,
                    );
                    $this->unicodeRemaining = 0;
                }

                if ($this->escaping) {
                    $this->escaping = false;
                    if ($char === 'u') {
                        $this->unicodeRemaining = 4;
                    }
                    continue;
                }

                if ($char === '\\') {
                    $this->escaping = true;
                    continue;
                }

                if ($char === '"') {
                    $this->inString = false;
                }

                continue;
            }

            $this->buffer .= $char;

            if ($char === '"') {
                $this->inString = true;
                continue;
            }

            if (ctype_space($char)) {
                continue;
            }

            if ($char === '{' || $char === '[') {
                $this->stack[] = $char;
                continue;
            }

            if ($char === '}' || $char === ']') {
                $this->consumeCloser($char);
            }
        }
    }

    /**
     * @return array{json:string, repairs:list<RepairAction>, issues:list<DecodeIssue>}
     */
    public function finalize(): array
    {
        if ($this->inString) {
            if ($this->escaping) {
                $this->buffer .= '\\';
                $this->repairs[] = new RepairAction(
                    RepairActionType::CompleteEscape,
                    strlen($this->buffer) - 1,
                    'Added a trailing backslash to complete a dangling escape sequence.',
                );
            }

            if ($this->unicodeRemaining > 0) {
                $this->buffer .= str_repeat('0', $this->unicodeRemaining);
                $this->repairs[] = new RepairAction(
                    RepairActionType::CompleteUnicodeEscape,
                    strlen($this->buffer) - 1,
                    'Filled missing unicode escape digits with zero.',
                );
            }

            $this->buffer .= '"';
            $this->repairs[] = new RepairAction(
                RepairActionType::CloseString,
                strlen($this->buffer) - 1,
                'Added a closing quote to repair a truncated string.',
            );

            $this->inString = false;
            $this->escaping = false;
            $this->unicodeRemaining = 0;
        }

        $this->repairDanglingTail();

        while (count($this->stack) !== 0) {
            $this->stripTrailingComma();
            $opener = array_pop($this->stack);
            $closer = $opener === '{' ? '}' : ']';
            $this->buffer .= $closer;

            $this->repairs[] = new RepairAction(
                RepairActionType::CloseContainer,
                strlen($this->buffer) - 1,
                "Added '{$closer}' to close a truncated container.",
            );
        }

        return [
            'json' => $this->buffer,
            'repairs' => $this->repairs,
            'issues' => $this->issues,
        ];
    }

    private function consumeCloser(string $closer): void
    {
        $expectedOpener = $closer === '}' ? '{' : '[';
        $actualOpener = array_pop($this->stack);

        if ($actualOpener === null) {
            $this->issues[] = new DecodeIssue(
                DecodeIssueType::UnexpectedCloser,
                "Unexpected closing token '{$closer}' without matching opener.",
                strlen($this->buffer) - 1,
            );
            return;
        }

        if ($actualOpener !== $expectedOpener) {
            $this->issues[] = new DecodeIssue(
                DecodeIssueType::MismatchedCloser,
                "Mismatched closing token '{$closer}'.",
                strlen($this->buffer) - 1,
            );
        }
    }

    private function repairDanglingTail(): void
    {
        while (true) {
            $index = $this->lastNonWhitespaceIndex();
            if ($index < 0) {
                return;
            }

            $tailChar = $this->buffer[$index];
            if ($tailChar === ',') {
                $this->buffer = substr($this->buffer, 0, $index) . substr($this->buffer, $index + 1);
                $this->repairs[] = new RepairAction(
                    RepairActionType::RemoveTrailingComma,
                    $index,
                    'Removed trailing comma at end of input.',
                );
                continue;
            }

            if ($tailChar === ':') {
                if ($this->options->shouldInsertNullForDanglingColon()) {
                    $this->buffer .= ' null';
                    $this->repairs[] = new RepairAction(
                        RepairActionType::InsertMissingValue,
                        strlen($this->buffer) - 1,
                        "Inserted 'null' for a dangling colon.",
                    );
                } else {
                    $this->buffer = substr($this->buffer, 0, $index) . substr($this->buffer, $index + 1);
                    $this->repairs[] = new RepairAction(
                        RepairActionType::RemoveTrailingColon,
                        $index,
                        'Removed dangling colon at end of input.',
                    );
                }
            }

            return;
        }
    }

    private function stripTrailingComma(): void
    {
        $index = $this->lastNonWhitespaceIndex();
        if ($index < 0 || !in_array($this->buffer[$index], [','], true)) {
            return;
        }

        $this->buffer = substr($this->buffer, 0, $index) . substr($this->buffer, $index + 1);
        $this->repairs[] = new RepairAction(
            RepairActionType::RemoveTrailingComma,
            $index,
            'Removed trailing comma before closing a container.',
        );
    }

    private function lastNonWhitespaceIndex(): int
    {
        for ($index = strlen($this->buffer) - 1; $index >= 0; --$index) {
            if (!ctype_space($this->buffer[$index])) {
                return $index;
            }
        }

        return -1;
    }
}
