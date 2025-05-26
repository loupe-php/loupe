<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\StateSetIndex;

use Toflar\StateSetIndex\StateSet\StateSetInterface;

class StateSetBloomFilter implements StateSetInterface
{
    private string $data;

    private float $falsePositiveProbability;

    private int $hashCount;

    /**
     * @var array<int,true>|null
     */
    private array|null $states;

    private function __construct()
    {
    }

    public function add(int $state): void
    {
        if ($this->states !== null) {
            $this->states[$state] = true;
        }

        $count = 0;

        foreach ($this->hashIterator($state) as $bit) {
            $this->setBit($bit);

            if (++$count >= $this->hashCount) {
                break;
            }
        }
    }

    public function all(): array
    {
        if ($this->states === null) {
            throw new \LogicException('States cannot be retrieved because they were omitted during initialization.');
        }

        return array_keys($this->states);
    }

    public static function fromBinaryData(string $data, float $falsePositiveProbability): self
    {
        $set = new self();

        $set->falsePositiveProbability = $falsePositiveProbability;
        $set->data = $data;
        $set->hashCount = self::calculateHashCount($falsePositiveProbability);
        $set->states = null;

        return $set;
    }

    /**
     * @param list<int> $states
     */
    public static function fromStatesArray(array $states, float $falsePositiveProbability = 0.1): self
    {
        $set = new self();

        $set->falsePositiveProbability = $falsePositiveProbability;
        $set->data = str_repeat("\x00", self::calculateSize(\count($states), $falsePositiveProbability) / 8);
        $set->hashCount = self::calculateHashCount($falsePositiveProbability);
        $set->states = [];

        foreach ($states as $state) {
            $set->add($state);
        }

        return $set;
    }

    public function getBinaryData(): string
    {
        return $this->data;
    }

    public function getProbability(): float
    {
        return $this->falsePositiveProbability;
    }

    public function has(int $state): bool
    {
        $count = 0;

        foreach ($this->hashIterator($state) as $bit) {
            if (!$this->getBit($bit)) {
                return false;
            }

            if (++$count >= $this->hashCount) {
                return true;
            }
        }
    }

    public function remove(int $state): void
    {
        if ($this->states === null) {
            throw new \LogicException('States cannot be removed from a bloom filter if they were omitted during initialization.');
        }

        unset($this->states[$state]);
    }

    private static function calculateBitsPerItem(float $falsePositiveProbability): float
    {
        return -log($falsePositiveProbability) / (log(2) ** 2);
    }

    private static function calculateHashCount(float $falsePositiveProbability): int
    {
        return (int) ceil(static::calculateBitsPerItem($falsePositiveProbability) * log(2));
    }

    private static function calculateSize(int $numberOfItems, float $falsePositiveProbability): int
    {
        // Grow in steps of 8 KiB to minimize number of rebuilds
        return (int) (ceil($numberOfItems * self::calculateBitsPerItem($falsePositiveProbability) / 65_536) * 65_536);
    }

    private function getBit(int $offset): bool
    {
        $byteOffset = intdiv($offset, 8);

        return ($this->data[$byteOffset] & \chr(1 << ($offset % 8))) !== "\x00";
    }

    /**
     * @return \Generator<int>
     */
    private function hashIterator(int $state): \Generator
    {
        $combinedHash = ($state << 4) % 0xFFFFFFFF;
        $secondHash = crc32(pack('N', $combinedHash));

        while (true) {
            yield $combinedHash % (\strlen($this->data) * 8);
            $combinedHash = ($combinedHash + $secondHash) % 0xFFFFFFFF;
        }
    }

    private function setBit(int $offset): void
    {
        $byteOffset = intdiv($offset, 8);
        $this->data[$byteOffset] = $this->data[$byteOffset] | \chr(1 << ($offset % 8));
    }
}
