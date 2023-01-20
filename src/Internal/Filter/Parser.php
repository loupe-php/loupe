<?php

namespace Terminal42\Loupe\Internal\Filter;

use Doctrine\Common\Lexer\Token;
use Terminal42\Loupe\Exception\FilterFormatException;
use Terminal42\Loupe\Internal\Filter\Ast\Ast;
use Terminal42\Loupe\Internal\Filter\Ast\Concatenator;
use Terminal42\Loupe\Internal\Filter\Ast\Filter;
use Terminal42\Loupe\Internal\Filter\Ast\Group;
use Terminal42\Loupe\Internal\Filter\Ast\Node;
use Terminal42\Loupe\Internal\Filter\Ast\Operator;
use Terminal42\Loupe\Internal\LoupeTypes;

class Parser
{
    private Lexer $lexer;
    private Ast $ast;
    private ?Group $currentGroup = null;

    public function __construct(?Lexer $lexer = null)
    {
        $this->lexer = $lexer ?? new Lexer();
    }

    /**
     * @throws FilterFormatException
     */
    public function getAst(string $string, array $allowedAttributeNames = []): Ast
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

            if ($start && !$this->lexer->token->isA(Lexer::T_ATTRIBUTE_NAME, Lexer::T_OPEN_PARENTHESIS)) {
                $this->syntaxError('an attribute name or \'(\'');
            }

            $start = false;

            if ($this->lexer->token->type === Lexer::T_ATTRIBUTE_NAME) {
                $attributeName = $this->lexer->token->value;

                if (count($allowedAttributeNames) !== 0 && !in_array($attributeName, $allowedAttributeNames, true)) {
                    $this->syntaxError('filterable attribute');
                }

                $this->assertOperator($this->lexer->lookahead);
                $this->lexer->moveNext();
                $operator = $this->lexer->token->value;

                $this->assertStringOrFloat($this->lexer->lookahead);
                $this->lexer->moveNext();

                if ($this->lexer->token->type === Lexer::T_FLOAT) {
                    $value = LoupeTypes::convertValueToType($this->lexer->token->value, LoupeTypes::TYPE_NUMBER);
                } else {
                    $value = LoupeTypes::convertValueToType($this->lexer->token->value, LoupeTypes::TYPE_STRING);
                }

                $this->addNode(new Filter($attributeName, Operator::fromString($operator), $value));
            }

            if ($this->lexer->token->isA(Lexer::T_AND, Lexer::T_OR)) {
                $this->addNode(Concatenator::fromString($this->lexer->token->value));
            }

            if ($this->lexer->token->isA(Lexer::T_OPEN_PARENTHESIS)) {
                $this->currentGroup = new Group();
            }

            if ($this->lexer->token->isA(Lexer::T_CLOSE_PARENTHESIS)) {
                if (null === $this->currentGroup) {
                    $this->syntaxError('an opened group statement');
                }

                $currentGroup = $this->currentGroup;
                $this->currentGroup = null;
                $this->addNode($currentGroup);
            }
        }

        if (null !== $this->currentGroup) {
            $this->syntaxError('a closing parenthesis');
        }

        return $this->ast;
    }

    private function addNode(Node $node): self
    {
        if (null !== $this->currentGroup) {
            $this->currentGroup->addChild($node);
            return $this;
        }

        $this->ast->addNode($node);

        return $this;
    }

    private function assertOperator(?Token $token): void
    {
        $type = $token->type ?? null;

        if (null === $type || $type < 10 || $type > 30) {
            $this->syntaxError('valid operator', $token);
        }
    }

    private function assertStringOrFloat(?Token $token): void
    {
        $type = $token->type ?? null;

        if (null === $type || ($type !== Lexer::T_FLOAT && $type !== Lexer::T_STRING)) {
            $this->syntaxError('valid string or float value', $token);
        }
    }

    private function syntaxError(string $expected = '', Token $token = null)
    {
        if ($token === null) {
            $token = $this->lexer->token;
        }

        $tokenPos = $token->position ?? '-1';

        $message  = sprintf('Col %d: Error: ', $tokenPos);
        $message .= $expected !== '' ? sprintf('Expected %s, got ', $expected) : 'Unexpected ';
        $message .= $this->lexer->lookahead === null ? 'end of string.' : sprintf("'%s'", $token->value);

        throw new FilterFormatException($message);
    }
}