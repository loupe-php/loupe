<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter;

use Doctrine\Common\Lexer\Token;
use Loupe\Loupe\Exception\FilterFormatException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\Ast;
use Loupe\Loupe\Internal\Filter\Ast\Concatenator;
use Loupe\Loupe\Internal\Filter\Ast\Filter;
use Loupe\Loupe\Internal\Filter\Ast\FilterValue;
use Loupe\Loupe\Internal\Filter\Ast\GeoBoundingBox;
use Loupe\Loupe\Internal\Filter\Ast\GeoDistance;
use Loupe\Loupe\Internal\Filter\Ast\Group;
use Loupe\Loupe\Internal\Filter\Ast\Node;
use Loupe\Loupe\Internal\Filter\Ast\Operator;
use Loupe\Loupe\Internal\LoupeTypes;

class Parser
{
    private Ast $ast;

    private \SplStack $groups;

    private Lexer $lexer;

    public function __construct(
        private Engine $engine
    ) {
        $this->lexer = new Lexer();
        $this->groups = new \SplStack();
    }

    public function getAst(string $string): Ast
    {
        $this->lexer->setInput($string);
        $this->ast = new Ast();

        $this->lexer->moveNext();

        $start = true;

        while (true) {
            if (!$this->lexer->lookahead) {
                break;
            }

            $this->lexer->moveNext();

            if ($start && !$this->lexer->token?->isA(
                Lexer::T_ATTRIBUTE_NAME,
                Lexer::T_GEO_RADIUS,
                Lexer::T_GEO_BOUNDING_BOX,
                Lexer::T_OPEN_PARENTHESIS
            )) {
                $this->syntaxError('an attribute name, _geoRadius() or \'(\'');
            }

            $start = false;

            if ($this->lexer->token?->type === Lexer::T_GEO_RADIUS) {
                $this->handleGeoRadius();
                continue;
            }

            if ($this->lexer->token?->type === Lexer::T_GEO_BOUNDING_BOX) {
                $this->handleGeoBoundingBox();
                continue;
            }

            if ($this->lexer->token?->type === Lexer::T_ATTRIBUTE_NAME) {
                $this->handleAttribute();
                continue;
            }

            if ($this->lexer->token?->isA(Lexer::T_AND, Lexer::T_OR)) {
                $this->addNode(Concatenator::fromString((string) $this->lexer->token->value));
            }

            if ($this->lexer->token?->isA(Lexer::T_OPEN_PARENTHESIS)) {
                $this->groups->push(new Group());
            }

            if ($this->lexer->token?->isA(Lexer::T_CLOSE_PARENTHESIS)) {
                $activeGroup = $this->groups->isEmpty() ? null : $this->groups->pop();

                if ($activeGroup instanceof Group) {
                    $this->addNode($activeGroup);
                } else {
                    $this->syntaxError('an opened group statement');
                }
            }
        }

        if (!$this->groups->isEmpty()) {
            $this->syntaxError('a closing parenthesis');
        }

        return $this->ast;
    }

    private function addNode(Node $node): self
    {
        // Ignore empty groups
        if ($node instanceof Group && $node->isEmpty()) {
            return $this;
        }

        $activeGroup = $this->groups->isEmpty() ? null : $this->groups->top();

        if ($activeGroup instanceof Group) {
            $activeGroup->addChild($node);
            return $this;
        }

        $this->ast->addNode($node);

        return $this;
    }

    private function assertAndExtractFloat(?Token $token, bool $allowNegative = false): float
    {
        $multipler = 1;
        if ($allowNegative && $token !== null && $token->type === Lexer::T_MINUS) {
            $multipler = -1;
            $this->lexer->moveNext();
            $token = $this->lexer->token;
        }

        $this->assertFloat($token);
        return (float) $this->lexer->token?->value * $multipler;
    }

    private function assertClosingParenthesis(?Token $token): void
    {
        $this->assertTokenTypes($token, [Lexer::T_CLOSE_PARENTHESIS], "')'");
    }

    private function assertComma(?Token $token): void
    {
        $this->assertTokenTypes($token, [Lexer::T_COMMA], "','");
    }

    private function assertFloat(?Token $token): void
    {
        $this->assertTokenTypes($token, [Lexer::T_FLOAT], 'valid float value');
    }

    private function assertOpeningParenthesis(?Token $token): void
    {
        $this->assertTokenTypes($token, [Lexer::T_OPEN_PARENTHESIS], "'('");
    }

    private function assertOperator(?Token $token): void
    {
        $type = $token->type ?? null;

        if (!\is_int($type) || $type < 10 || $type > 30) {
            $this->syntaxError('valid operator', $token);
        }
    }

    private function assertStringOrFloatOrBoolean(?Token $token): void
    {
        $this->assertTokenTypes($token, [
            Lexer::T_FLOAT,
            Lexer::T_STRING,
            Lexer::T_TRUE,
            Lexer::T_FALSE,
        ], 'valid string, float or boolean value');
    }

    /**
     * @param array<int> $types
     */
    private function assertTokenTypes(?Token $token, array $types, string $error): void
    {
        $type = $token->type ?? null;

        if ($type === null || !\in_array($type, $types, true)) {
            $this->syntaxError($error, $token);
        }
    }

    private function getTokenValueBasedOnType(): float|string|bool
    {
        $value = $this->lexer->token?->value;

        if ($value === null) {
            $this->syntaxError('NULL is not supported, use IS NULL or IS NOT NULL');
        }

        return match ($this->lexer->token?->type) {
            Lexer::T_FLOAT => LoupeTypes::convertToFloat($value),
            Lexer::T_STRING => LoupeTypes::convertToString($value),
            Lexer::T_FALSE => false,
            Lexer::T_TRUE => true,
            default => throw new FilterFormatException('This should never happen, please file a bug report.')
        };
    }

    private function handleAttribute(): void
    {
        $attributeName = (string) $this->lexer->token?->value;

        $this->validateFilterableAttribute($attributeName);

        $this->assertOperator($this->lexer->lookahead);
        $this->lexer->moveNext();
        $operator = (string) $this->lexer->token?->value;

        if ($this->lexer->token?->type === Lexer::T_IS) {
            $this->handleIs($attributeName);
            return;
        }

        // Greater than or smaller than operators
        if ($this->lexer->lookahead?->type === Lexer::T_EQUALS) {
            $this->lexer->moveNext();
            $operator .= $this->lexer->token?->value;
        }

        if ($this->lexer->token?->type === Lexer::T_NOT) {
            if (!\in_array($this->lexer->lookahead?->type, [Lexer::T_IN, Lexer::T_BETWEEN], true)) {
                $this->syntaxError('NOT must be followed by IN () or BETWEEN', $this->lexer->lookahead);
            }

            $this->lexer->moveNext();
            $operator .= ' ' . $this->lexer->token?->value;
        }

        if ($this->lexer->token?->type === Lexer::T_BETWEEN) {
            $this->handleBetween($attributeName, $operator);
            return;
        }

        if ($this->lexer->token?->type === Lexer::T_IN) {
            $this->handleIn($attributeName, $operator);
            return;
        }

        $this->assertStringOrFloatOrBoolean($this->lexer->lookahead);

        $this->lexer->moveNext();

        $this->addNode(new Filter($attributeName, Operator::fromString($operator), new FilterValue($this->getTokenValueBasedOnType())));
    }

    private function handleBetween(string $attributeName, string $operator): void
    {
        $values = [];
        $this->assertFloat($this->lexer->lookahead);
        $this->lexer->moveNext();
        $values[] = $this->getTokenValueBasedOnType();
        $this->assertTokenTypes($this->lexer->lookahead, [Lexer::T_AND], "'AND'");
        $this->lexer->moveNext();
        $this->assertFloat($this->lexer->lookahead);
        $this->lexer->moveNext();
        $values[] = $this->getTokenValueBasedOnType();

        $this->addNode(new Filter($attributeName, Operator::fromString($operator), new FilterValue($values)));
    }

    private function handleGeoBoundingBox(): void
    {
        $startPosition = ($this->lexer->lookahead->position ?? 0) + 1;

        $this->assertOpeningParenthesis($this->lexer->lookahead);
        $this->lexer->moveNext();
        $this->lexer->moveNext();

        $attributeName = (string) $this->lexer->token?->value;

        $this->validateFilterableAttribute($attributeName);

        $this->lexer->moveNext();
        $this->lexer->moveNext();
        $north = $this->assertAndExtractFloat($this->lexer->token, true);
        $this->assertComma($this->lexer->lookahead);

        $this->lexer->moveNext();
        $this->lexer->moveNext();
        $east = $this->assertAndExtractFloat($this->lexer->token, true);
        $this->assertComma($this->lexer->lookahead);

        $this->lexer->moveNext();
        $this->lexer->moveNext();
        $south = $this->assertAndExtractFloat($this->lexer->token, true);
        $this->assertComma($this->lexer->lookahead);

        $this->lexer->moveNext();
        $this->lexer->moveNext();
        $west = $this->assertAndExtractFloat($this->lexer->token, true);
        $this->assertClosingParenthesis($this->lexer->lookahead);

        try {
            $this->addNode(new GeoBoundingBox($attributeName, $north, $east, $south, $west));
        } catch (\InvalidArgumentException $e) {
            $this->syntaxError(
                $e->getMessage(),
                // create a fake token to show the user the whole value for better developer experience as we don't know
                // which latitude or longitude value caused the exception
                new Token(implode(', ', [$attributeName, $north, $east, $south, $west]), Lexer::T_FLOAT, $startPosition),
            );
        }

        $this->lexer->moveNext();
    }

    private function handleGeoRadius(): void
    {
        $this->assertOpeningParenthesis($this->lexer->lookahead);
        $this->lexer->moveNext();
        $this->lexer->moveNext();

        $attributeName = (string) $this->lexer->token?->value;

        $this->validateFilterableAttribute($attributeName);

        $this->lexer->moveNext();
        $this->lexer->moveNext();
        $lat = $this->assertAndExtractFloat($this->lexer->token, true);
        $this->assertComma($this->lexer->lookahead);
        $this->lexer->moveNext();
        $this->lexer->moveNext();
        $lng = $this->assertAndExtractFloat($this->lexer->token, true);
        $this->assertComma($this->lexer->lookahead);
        $this->lexer->moveNext();
        $this->assertFloat($this->lexer->lookahead);
        $this->lexer->moveNext();
        $distance = (float) $this->lexer->token?->value;
        $this->assertClosingParenthesis($this->lexer->lookahead);

        $this->addNode(new GeoDistance($attributeName, $lat, $lng, $distance));

        $this->lexer->moveNext();
    }

    private function handleIn(string $attributeName, string $operator): void
    {
        $this->assertOpeningParenthesis($this->lexer->lookahead);
        $this->lexer->moveNext();
        $this->lexer->moveNext();

        $values = [];

        while (true) {
            $this->assertStringOrFloatOrBoolean($this->lexer->token);
            $values[] = $this->getTokenValueBasedOnType();

            if ($this->lexer->lookahead === null) {
                $this->assertClosingParenthesis($this->lexer->token);
            }

            if ($this->lexer->lookahead?->type === Lexer::T_CLOSE_PARENTHESIS) {
                $this->lexer->moveNext();
                break;
            }

            $this->assertComma($this->lexer->lookahead);
            $this->lexer->moveNext();
            $this->lexer->moveNext();
        }

        $this->addNode(new Filter($attributeName, Operator::fromString($operator), new FilterValue($values)));
    }

    private function handleIs(mixed $attributeName): void
    {
        if ($this->lexer->lookahead?->type === Lexer::T_NULL) {
            $this->addNode(new Filter($attributeName, Operator::Equals, FilterValue::createNull()));
            return;
        }

        if ($this->lexer->lookahead?->type === Lexer::T_EMPTY) {
            $this->addNode(new Filter($attributeName, Operator::Equals, FilterValue::createEmpty()));
            return;
        }

        if ($this->lexer->lookahead?->type === Lexer::T_NOT && $this->lexer->glimpse()?->type === Lexer::T_NULL) {
            $this->addNode(new Filter($attributeName, Operator::NotEquals, FilterValue::createNull()));
            return;
        }

        if ($this->lexer->lookahead?->type === Lexer::T_NOT && $this->lexer->glimpse()?->type === Lexer::T_EMPTY) {
            $this->addNode(new Filter($attributeName, Operator::NotEquals, FilterValue::createEmpty()));
            return;
        }

        $this->syntaxError('"NULL", "NOT NULL", "EMPTY" or "NOT EMPTY" after is', $this->lexer->lookahead);
    }

    private function syntaxError(string $expected = '', ?Token $token = null): void
    {
        if ($token === null) {
            $token = $this->lexer->token;
        }

        $tokenPos = $token->position ?? '-1';

        $message = sprintf('Col %d: Error: ', $tokenPos);
        $message .= $expected !== '' ? sprintf('Expected %s, got ', $expected) : 'Unexpected ';
        $message .= $this->lexer->lookahead === null ? 'end of string.' : sprintf("'%s'", $token?->value);

        throw new FilterFormatException($message);
    }

    private function validateFilterableAttribute(string $attributeName): void
    {
        $allowedAttributeNames = $this->engine->getConfiguration()->getFilterableAttributes();
        if (!\in_array($attributeName, $allowedAttributeNames, true)) {
            $this->syntaxError('filterable attribute');
        }
    }
}
