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

    public function clear(): void
    {
        $this->initialize();
        $this->inMemoryStateSet = new InMemoryStateSet([]);
    }

    public function has(int $state): bool
    {
        $this->initialize();
        return $this->inMemoryStateSet->has($state);
    }

    public function persist(): void
    {
        $this->initialize();
        $this->engine->getConnection()->executeStatement('DELETE FROM ' . IndexInfo::TABLE_NAME_STATE_SET);
        $this->engine->getConnection()->executeStatement('DELETE FROM sqlite_sequence WHERE name = ?', [IndexInfo::TABLE_NAME_STATE_SET]);
        $values = [];
        foreach ($this->inMemoryStateSet->all() as $state) {
            $values[] = '(' . $state . ')';
        }

        if ($values !== []) {
            $this->engine->getConnection()->executeStatement(sprintf('INSERT INTO ' . IndexInfo::TABLE_NAME_STATE_SET . ' (state) VALUES %s', implode(',', $values)));
        }

        $all = $this->inMemoryStateSet->all();
        $all = array_combine($this->inMemoryStateSet->all(), array_fill(0, \count($all), true));
        $this->dumpStateSetCache($all);
    }

    public function remove(int $state): void
    {
        $this->initialize();
        $this->inMemoryStateSet->remove($state);
    }

    /**
     * @param array<int, bool> $stateSet
^     */
    private function dumpStateSetCache(array $stateSet): void
    {
        $cacheFile = $this->getStateSetCacheFile();

        if ($cacheFile === null) {
            return;
        }

        file_put_contents($cacheFile, pack('N*', ...array_keys($stateSet)));
    }

    private function getStateSetCacheFile(): ?string
    {
        if ($this->engine->getDataDir() === null) {
            return null;
        }

        return $this->engine->getDataDir() . '/state_set.bin';
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $cacheFile = $this->getStateSetCacheFile();

        if ($cacheFile === null) {
            $data = $this->loadFromStorage();
        } else {
            if (!file_exists($cacheFile)) {
                $data = $this->loadFromStorage();
                $this->dumpStateSetCache($data);
            } else {
                $data = (array) unpack('N*', (string) file_get_contents($cacheFile));
                $data = array_combine($data, array_fill(0, \count($data), true));
            }
        }

        $this->inMemoryStateSet = new InMemoryStateSet($data);
        $this->initialized = true;
    }

    /**
     * @return array<int, bool>
     */
    private function loadFromStorage(): array
    {
        $storage = [];

        foreach ($this->engine->getConnection()
            ->createQueryBuilder()
            ->select('state')
            ->from(IndexInfo::TABLE_NAME_STATE_SET)
            ->executeQuery()
            ->iterateAssociative() as $row
        ) {
            $storage[(int) $row['state']] = true;
        }

        return $storage;
    }
}
