<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

enum ConflictMode
{
    case Ignore;
    case Update;
}
