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

    private StateSetBloomFilter $inMemoryStateSet;

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
        $this->inMemoryStateSet = StateSetBloomFilter::fromStatesArray([]);
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

        $this->dumpStateSetCache($this->inMemoryStateSet);
    }

    public function remove(int $state): void
    {
        $this->initialize();
        $this->inMemoryStateSet->remove($state);
    }

    /**
     * @param array<int, bool> $stateSet
^     */
    private function dumpStateSetCache(InMemoryStateSet|StateSetBloomFilter $stateSet): void
    {
        $cacheFile = $this->getStateSetCacheFile();

        if ($cacheFile === null) {
            return;
        }

        if ($stateSet instanceof InMemoryStateSet) {
            $stateSet = StateSetBloomFilter::fromStatesArray($stateSet->all());
        }

        file_put_contents($cacheFile, pack('N', round($stateSet->getProbability() * (2 ** 32))) . $stateSet->getBinaryData());
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
            $stateSet = new InMemoryStateSet($this->loadFromStorage());
        } else {
            if (!file_exists($cacheFile)) {
                $data = array_keys($this->loadFromStorage());
                $stateSet = StateSetBloomFilter::fromStatesArray($data);
                $this->dumpStateSetCache($stateSet);
            } else {
                $data = file_get_contents($cacheFile);
                $probability = unpack('N', substr($data, 0, 4))[1] / (2 ** 32);
                $data = substr($data, 4);
                $stateSet = StateSetBloomFilter::fromBinaryData($data, $probability);
            }
        }

        $this->inMemoryStateSet = $stateSet;
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
