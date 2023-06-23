<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\StateSet;

use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Toflar\StateSetIndex\StateSet\InMemoryStateSet;
use Toflar\StateSetIndex\StateSet\StateSetInterface;

class StateSet implements StateSetInterface
{
    private bool $initialized = false;

    private InMemoryStateSet $inMemoryStateSet;

    public function __construct(
        private Engine $engine
    ) {
    }

    public function acceptString(int $state, string $string): StateSetInterface
    {
        // noop, we do not use the find methods on the stateset

        return $this;
    }

    public function add(int $state, int $parentState, int $mappedChar): StateSetInterface
    {
        $this->initialize();
        $this->inMemoryStateSet->add($state, $parentState, $mappedChar);

        return $this;
    }

    public function getAcceptedStrings(array $matchingStates = []): array
    {
        // noop, we do not use the find methods on the stateset

        return [];
    }

    public function getCharForState(int $state): int
    {
        $this->initialize();

        return $this->inMemoryStateSet->getCharForState($state);
    }

    public function getChildrenOfState(int $state): array
    {
        $this->initialize();

        return $this->inMemoryStateSet->getChildrenOfState($state);
    }

    public function persist(): void
    {
        $this->initialize();

        foreach ($this->inMemoryStateSet->all() as $state => $data) {
            $this->engine->upsert(
                IndexInfo::TABLE_NAME_STATE_SET,
                [
                    'state' => $state,
                    'parent' => $data[0],
                    'mapped_char' => $data[1],
                ],
                [
                    'state',
                    'parent',
                    'mapped_char',
                ]
            );
        }
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->inMemoryStateSet = new InMemoryStateSet();

        foreach ($this->engine->getConnection()->createQueryBuilder()
            ->select('state,parent,mapped_char')
            ->from(IndexInfo::TABLE_NAME_STATE_SET)
            ->executeQuery()
            ->iterateAssociative() as $row) {
            $this->inMemoryStateSet->add((int) $row['state'], (int) $row['parent'], (int) $row['mapped_char']);
        }

        $this->initialized = true;
    }
}
