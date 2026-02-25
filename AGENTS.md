# AGENTS.md

## Implementation
- Recover and decode broken JSON, especially truncated JSON.
- Use this flow: tolerant scan -> minimal repair -> validate with `json_decode(JSON_THROW_ON_ERROR)`.
- Keep the repair policies: `conservative`, `balanced`, and `aggressive`.
- Return metadata with each result: `isRecovered`, `isPartial`, `repairs`, `issues`.

## QA
- Install dependencies: `make install`
- Format check: `make formatter`
- Static analysis: `make phpstan`
- Unit tests: `make phpunit`
- Full local check: `make qa`
- Run only when needed: `make formatter-fix`, `make infection`

## Coding Style
- Import PHP global functions with `use function`.
- Example: `use function strpos;`
- For tuple-like PHPDoc arrays, prefer `list{...}` over `array{0:..., 1:...}`.
- Exception: use `array{...}` when optional tuple elements are needed (for example `array{0:mixed, 1:list<RepairAction>, 2?:mixed}`).
- Aggregate similar test cases with Data Providers.
- Data Providers must use `yield`, declare `: iterable`, and include a `@phpstan-return` type.
- Use `list{...}` for simple/small cases; use associative array shapes for larger/complex cases.
