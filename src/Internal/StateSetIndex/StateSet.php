<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\StateSetIndex;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
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

    public function add(int $state): void
    {
        $this->initialize();
        $this->inMemoryStateSet->add($state);
    }

    public function all(): array
    {
        $this->initialize();
        return $this->inMemoryStateSet->all();
    }

    public function has(int $state): bool
    {
        $this->initialize();
        return $this->inMemoryStateSet->has($state);
    }

    public function persist(): void
    {
        $this->initialize();

        foreach ($this->inMemoryStateSet->all() as $state => $data) {
            $this->engine->upsert(IndexInfo::TABLE_NAME_STATE_SET, [
                'state' => $state,
            ], ['state']);
        }
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->inMemoryStateSet = new InMemoryStateSet();

        foreach ($this->engine->getConnection()->createQueryBuilder()
            ->select('state')
            ->from(IndexInfo::TABLE_NAME_STATE_SET)
            ->executeQuery()
            ->iterateAssociative() as $row
        ) {
            $this->inMemoryStateSet->add((int) $row['state']);
        }

        $this->initialized = true;
    }
}
