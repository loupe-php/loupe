<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

enum Aggregate: string
{
    case Max = 'MAX';
    case Min = 'MIN';

    public function buildSql(string $attribute): string
    {
        return $this->value . '(' . $attribute . ')';
    }

    public static function tryFromCaseInsensitive(string $value): null|self
    {
        return self::tryFrom(strtoupper($value));
    }
}
