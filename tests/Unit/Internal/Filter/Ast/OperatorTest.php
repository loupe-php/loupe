<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Filter\Ast;

use Loupe\Loupe\Internal\Filter\Ast\Operator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperatorTest extends TestCase
{
    public static function negativeAndOppositeProvider(): \Generator
    {
        yield 'IN' => [
            Operator::In,
            false,
            Operator::NotIn,
        ];

        yield 'NOT IN' => [
            Operator::NotIn,
            true,
            Operator::In,
        ];

        yield '=' => [
            Operator::Equals,
            false,
            Operator::NotEquals,
        ];

        yield '!=' => [
            Operator::NotEquals,
            true,
            Operator::Equals,
        ];

        yield '>' => [
            Operator::GreaterThan,
            false,
            Operator::LowerThanOrEquals,
        ];

        yield '>=' => [
            Operator::GreaterThanOrEquals,
            false,
            Operator::LowerThan,
        ];

        yield '<' => [
            Operator::LowerThan,
            false,
            Operator::GreaterThanOrEquals,
        ];

        yield '<=' => [
            Operator::LowerThanOrEquals,
            false,
            Operator::GreaterThan,
        ];
    }

    #[DataProvider('negativeAndOppositeProvider')]
    public function testNegativeAndOpposite(Operator $operator, bool $expectNegative, Operator $expectedOpposite): void
    {
        $this->assertSame($expectNegative, $operator->isNegative());
        $this->assertSame($expectedOpposite, $operator->opposite());
    }
}
