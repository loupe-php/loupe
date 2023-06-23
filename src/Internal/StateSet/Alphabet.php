<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\StateSet;

use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Toflar\StateSetIndex\Alphabet\AlphabetInterface;
use Toflar\StateSetIndex\Alphabet\InMemoryAlphabet;

class Alphabet implements AlphabetInterface
{
    private bool $initialized = false;

    private InMemoryAlphabet $inMemoryAlphabet;

    public function __construct(
        private Engine $engine
    ) {
    }

    public function map(string $char, int $alphabetSize): int
    {
        if (! $this->initialized) {
            $this->inMemoryAlphabet = new InMemoryAlphabet(
                $this->engine->getConnection()
                    ->createQueryBuilder()
                    ->select('char, label')
                    ->from(IndexInfo::TABLE_NAME_ALPHABET)
                    ->fetchAllKeyValue()
            );

            $this->initialized = true;
        }

        return $this->inMemoryAlphabet->map($char, $alphabetSize);
    }

    public function persist(): void
    {
        if (! $this->initialized) {
            return;
        }

        foreach ($this->inMemoryAlphabet->all() as $char => $label) {
            $this->engine->upsert(
                IndexInfo::TABLE_NAME_ALPHABET,
                [
                    'char' => $char,
                    'label' => $label,
                ],
                ['char', 'label'],
            );
        }
    }
}
