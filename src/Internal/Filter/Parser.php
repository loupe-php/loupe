<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter;

use Doctrine\Common\Lexer\Token;
use Loupe\Loupe\Exception\FilterFormatException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\Ast;
use Loupe\Loupe\Internal\Filter\Ast\Concatenator;
use Loupe\Loupe\Internal\Filter\Ast\Filter;
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

    public function __construct(?Lexer $lexer = null)
    {
        $this->lexer = $lexer ?? new Lexer();
        $this->groups = new \SplStack();
    }

    public function getAst(string $string, Engine $engine): Ast
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
                Lexer::T_OPEN_PARENTHESIS
            )) {
                $this->syntaxError('an attribute name, _geoRadius() or \'(\'');
            }

            $start = false;

            if ($this->lexer->token?->type === Lexer::T_GEO_RADIUS) {
                $this->handleGeoRadius($engine);
                continue;
            }

            if ($this->lexer->token?->type === Lexer::T_ATTRIBUTE_NAME) {
                $this->handleAttribute($engine);
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

    private function assertStringOrFloat(?Token $token): void
    {
        $this->assertTokenTypes($token, [Lexer::T_FLOAT, Lexer::T_STRING], 'valid string or float value');
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

    private function handleAttribute(Engine $engine): void
    {
        $attributeName = (string) $this->lexer->token?->value;

        $this->validateFilterableAttribute($engine, $attributeName);

        $this->assertOperator($this->lexer->lookahead);
        $this->lexer->moveNext();
        $operator = (string) $this->lexer->token?->value;

        if ($this->lexer->token?->type === Lexer::T_IS) {
            $this->handleIs($attributeName, $engine);
            return;
        }

        // Greater than or smaller than operators
        if ($this->lexer->lookahead?->type === Lexer::T_EQUALS) {
            $this->lexer->moveNext();
            $operator .= $this->lexer->token?->value;
        }

        if ($this->lexer->token?->type === Lexer::T_NOT) {
            if ($this->lexer->lookahead?->type !== Lexer::T_IN) {
                $this->syntaxError('must be followed by IN ()', $this->lexer->lookahead);
            }

            $this->lexer->moveNext();
            $operator .= ' ' . $this->lexer->token?->value;
        }

        if ($this->lexer->token?->type === Lexer::T_IN) {
            $this->handleIn($attributeName, $operator);
            return;
        }

        $this->assertStringOrFloat($this->lexer->lookahead);

        $this->lexer->moveNext();

        if ($this->lexer->token?->type === Lexer::T_FLOAT) {
            /** @var float $value */
            $value = LoupeTypes::convertValueToType($this->lexer->token->value, LoupeTypes::TYPE_NUMBER);
        } else {
            /** @var string $value */
            $value = LoupeTypes::convertValueToType($this->lexer->token?->value, LoupeTypes::TYPE_STRING);
        }

        $this->addNode(new Filter($attributeName, Operator::fromString($operator), $value));
    }

    private function handleGeoRadius(Engine $engine): void
    {
        $this->assertOpeningParenthesis($this->lexer->lookahead);
        $this->lexer->moveNext();
        $this->lexer->moveNext();

        $attributeName = (string) $this->lexer->token?->value;

        $this->validateFilterableAttribute($engine, $attributeName);

        $this->lexer->moveNext();
        $this->assertFloat($this->lexer->lookahead);
        $this->lexer->moveNext();
        $lat = (float) $this->lexer->token?->value;
        $this->assertComma($this->lexer->lookahead);
        $this->lexer->moveNext();
        $this->assertFloat($this->lexer->lookahead);
        $this->lexer->moveNext();
        $lng = (float) $this->lexer->token?->value;
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
            $this->assertStringOrFloat($this->lexer->token);
            $values[] = $this->lexer->token?->value;

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

        $this->addNode(new Filter($attributeName, Operator::fromString($operator), $values));
    }

    private function handleIs(mixed $attributeName, Engine $engine): void
    {
        if ($this->lexer->lookahead?->type === Lexer::T_NULL) {
            $this->addNode(new Filter($attributeName, Operator::Equals, LoupeTypes::VALUE_NULL));
            return;
        }

        if ($this->lexer->lookahead?->type === Lexer::T_EMPTY) {
            $this->addNode(new Filter($attributeName, Operator::Equals, LoupeTypes::VALUE_EMPTY));
            return;
        }

        if ($this->lexer->lookahead?->type === Lexer::T_NOT && $this->lexer->glimpse()?->type === Lexer::T_NULL) {
            $this->addNode(new Filter($attributeName, Operator::NotEquals, LoupeTypes::VALUE_NULL));
            return;
        }

        if ($this->lexer->lookahead?->type === Lexer::T_NOT && $this->lexer->glimpse()?->type === Lexer::T_EMPTY) {
            $this->addNode(new Filter($attributeName, Operator::NotEquals, LoupeTypes::VALUE_EMPTY));
            return;
        }

        $this->syntaxError('"NULL", "NOT NULL", "EMPTY" or "NOT EMPTY" after is', $this->lexer->lookahead);
    }

    private function syntaxError(string $expected = '', Token $token = null): void
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

    private function validateFilterableAttribute(Engine $engine, string $attributeName): void
    {
        $allowedAttributeNames = $engine->getConfiguration()->getFilterableAttributes();
        if (!\in_array($attributeName, $allowedAttributeNames, true)) {
            $this->syntaxError('filterable attribute');
        }
    }
}
