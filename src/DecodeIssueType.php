<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

enum DecodeIssueType: string
{
    case FileOpenFailed = 'file_open_failed';
    case JsonDecodeFailed = 'json_decode_failed';
    case InvalidUnicodeEscape = 'invalid_unicode_escape';
    case UnexpectedCloser = 'unexpected_closer';
    case MismatchedCloser = 'mismatched_closer';
}
