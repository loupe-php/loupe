<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\StateSetIndex;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Toflar\StateSetIndex\StateSet\InMemoryStateSet;
use Toflar\StateSetIndex\StateSet\StateSetInterface;

class StateSet implements StateSetInterface
{
    private const STATES_PER_QUERY = 10_000;

    /**
     * @var array<int, true>
     */
    private array $addedStates = [];

    private bool $cleared = false;

    private bool $initialized = false;

    private InMemoryStateSet $inMemoryStateSet;

    /**
     * @var array<int, true>
     */
    private array $removedStates = [];

    public function __construct(private readonly Engine $engine)
    {
    }

    public function add(int $state): void
    {
        $this->initialize();

        if ($this->inMemoryStateSet->has($state)) {
            return;
        }

        $this->inMemoryStateSet->add($state);

        if (isset($this->removedStates[$state])) {
            unset($this->removedStates[$state]);
        } else {
            $this->addedStates[$state] = true;
        }
    }

    public function all(): array
    {
        $this->initialize();

        return $this->inMemoryStateSet->all();
    }

    public function clear(): void
    {
        $this->initialize();

        if ([] === $this->inMemoryStateSet->all()) {
            return;
        }

        $this->inMemoryStateSet = new InMemoryStateSet([]);
        $this->cleared = true;
        $this->addedStates = [];
        $this->removedStates = [];
    }

    public function has(int $state): bool
    {
        $this->initialize();

        return $this->inMemoryStateSet->has($state);
    }

    public function persist(): void
    {
        $this->initialize();

        if (!$this->cleared && [] === $this->addedStates && [] === $this->removedStates) {
            return;
        }

        $connection = $this->engine->getConnection();

        if ($this->cleared) {
            $connection->executeStatement('DELETE FROM '.IndexInfo::TABLE_NAME_STATE_SET);
            $connection->executeStatement('DELETE FROM sqlite_sequence WHERE name = ?', [IndexInfo::TABLE_NAME_STATE_SET]);
        }

        // States are integers, so they can be inlined instead of being bound as parameters
        foreach (array_chunk(array_keys($this->removedStates), self::STATES_PER_QUERY) as $chunk) {
            $connection->executeStatement(\sprintf('DELETE FROM %s WHERE state IN (%s)', IndexInfo::TABLE_NAME_STATE_SET, implode(',', $chunk)));
        }

        foreach (array_chunk(array_keys($this->addedStates), self::STATES_PER_QUERY) as $chunk) {
            $connection->executeStatement(\sprintf('INSERT OR IGNORE INTO %s (state) VALUES (%s)', IndexInfo::TABLE_NAME_STATE_SET, implode('),(', $chunk)));
        }

        $all = $this->inMemoryStateSet->all();
        $all = array_combine($this->inMemoryStateSet->all(), array_fill(0, \count($all), true));
        $this->dumpStateSetCache($all);
        $this->resetChanges();
    }

    public function remove(int $state): void
    {
        $this->initialize();

        if (!$this->inMemoryStateSet->has($state)) {
            return;
        }

        $this->inMemoryStateSet->remove($state);

        if (isset($this->addedStates[$state])) {
            unset($this->addedStates[$state]);
        } else {
            $this->removedStates[$state] = true;
        }
    }

    public function reset(): void
    {
        $this->initialized = false;
        $this->resetChanges();

        $cacheFile = $this->getStateSetCacheFile();
        if (null !== $cacheFile && file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * @param array<int, bool> $stateSet
     * ^ */
    private function dumpStateSetCache(array $stateSet): void
    {
        $cacheFile = $this->getStateSetCacheFile();

        if (null === $cacheFile) {
            return;
        }

        file_put_contents($cacheFile, pack('Q*', ...array_keys($stateSet)));
    }

    private function getStateSetCacheFile(): string|null
    {
        if (null === $this->engine->getDataDir()) {
            return null;
        }

        return $this->engine->getDataDir().'/state_set.bin';
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $cacheFile = $this->getStateSetCacheFile();

        if (null === $cacheFile) {
            $data = $this->loadFromStorage();
        } else {
            if (!file_exists($cacheFile)) {
                $data = $this->loadFromStorage();
                $this->dumpStateSetCache($data);
            } else {
                $data = (array) unpack('Q*', (string) file_get_contents($cacheFile));
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

        try {
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
        } catch (TableNotFoundException) {
            return [];
        }
    }

    private function resetChanges(): void
    {
        $this->addedStates = [];
        $this->removedStates = [];
        $this->cleared = false;
    }
}
