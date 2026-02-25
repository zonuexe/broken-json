<?php

declare(strict_types=1);

namespace zonuexe\BrokenJson;

enum RepairActionType: string
{
    case CompleteEscape = 'complete_escape';
    case CompleteUnicodeEscape = 'complete_unicode_escape';
    case CloseString = 'close_string';
    case CloseContainer = 'close_container';
    case RemoveTrailingComma = 'remove_trailing_comma';
    case InsertMissingValue = 'insert_missing_value';
    case RemoveTrailingColon = 'remove_trailing_colon';
}
