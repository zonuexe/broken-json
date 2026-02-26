# zonuexe/broken-json

Repair and decode broken JSON in PHP, with a focus on truncated payloads from logs and storage.

## Why This Exists

In real systems, large JSON payloads can be cut off (for example, DB column limits or transport issues).
This library tries to recover those payloads into usable PHP values and gives you repair metadata.

## Quick Start

```php
<?php

use zonuexe\BrokenJson\DecoderFactory;

$json = '{"foo": {"name": "foo", "bar": {"buz": ["text data", "huge.........text';

$decoder = DecoderFactory::create();
$result = $decoder->decodeString($json);

// decoded value after repair
$data = $result->value;
#=> ['foo' => ['name' => 'foo', 'bar' => ['buz' => ['text data', 'huge.........text']]]]

// recovery metadata
$isRecovered = $result->isRecovered;
#=> true
$isPartial = $result->isPartial;
#=> true
$repairs = $result->repairs;
#=> [RepairAction(type: close_string), RepairAction(type: close_container), ...]
$issues = $result->issues;
#=> []
```

## API

- `DecoderFactory::create(?DecodeOptions $options = null, ?Utf8Sanitizer $utf8Sanitizer = null): Decoder`
- `Decoder::decodeString(string $json): DecodeResult`
- `Decoder::decodeChunks(iterable<string> $chunks): DecodeResult`
- `Decoder::decodeStream(resource $stream): DecodeResult`
- `Decoder::decodeFile(string $path): DecodeResult`
- `Decoder::decode(string $json): list{mixed, list<RepairAction>}` (legacy compatibility)

## UTF-8 Sanitization

`DecoderFactory` auto-selects a UTF-8 sanitizer backend in this order:

1. `MbstringUtf8Sanitizer` (`mb_scrub`)
2. `IconvUtf8Sanitizer` (`iconv`)

If neither backend is available, factory creation fails with `LogicException`.

You can also inject your own sanitizer implementation:

```php
<?php

use zonuexe\BrokenJson\DecoderFactory;
use zonuexe\BrokenJson\Internal\Utf8Sanitizer;

$sanitizer = new class () implements Utf8Sanitizer {
    public function sanitize(string $value): string
    {
        return $value;
    }
};

$decoder = DecoderFactory::create(utf8Sanitizer: $sanitizer);
```

## Result Object

`DecodeResult` includes:

- `value`: decoded PHP value (or `null` if decoding still fails)
- `isRecovered`: whether any repair was applied
- `isPartial`: whether the result may be partial/incomplete
- `repairs`: list of applied repair actions
- `issues`: list of decoding/recovery issues
- `repairedJson`: repaired JSON string used for final decode

## JSON Serialization

`DecodeResult` implements `JsonSerializable`, so it can be passed directly to `json_encode()`.

```php
$json = json_encode($result, JSON_THROW_ON_ERROR);
#=> {"value":{"foo":{"name":"foo","bar":{"buz":["text data","huge.........text"]}}},"isRecovered":true,...}
```

## Fuzzing

This repository includes a `php-fuzzer` target for crash-oriented stress testing.

```bash
make fuzz
#=> runs bounded fuzzing (default: 5000 runs, 5s timeout/input)
#=> override: make fuzz FUZZ_MAX_RUNS=20000 FUZZ_TIMEOUT=3
```

To replay or minimize a crash input:

```bash
make fuzz-single INPUT=crash-xxxx.txt
#=> executes one input against the fuzz target

make fuzz-minimize INPUT=crash-xxxx.txt
#=> creates minimized-*.txt candidates
```

## Repair Policy

Use `DecodeOptions` to control behavior:

- `conservative`: minimal safe repairs
- `balanced` (default): practical repairs for common truncation cases
- `aggressive`: allows more permissive repairs

## Copyright

This package is licensed under the [MPL 2.0]. See [`LICENSE`](LICENSE) file.

```
Copyright (C) 2026  USAMI Kenta

This Source Code Form is subject to the terms of the Mozilla Public
License, v. 2.0. If a copy of the MPL was not distributed with this
file, You can obtain one at https://mozilla.org/MPL/2.0/.
```

[MPL 2.0]: https://www.mozilla.org/en-US/MPL/2.0/
