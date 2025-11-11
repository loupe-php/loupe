<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Loupe\Loupe\LoupeFactory;
use Psr\Log\LoggerInterface;

class TicketHandler
{
    public const TABLE_NAME = 'tickets';

    /**
     * Require the same head to be observed in this many consecutive
     * failsafe runs (with no active writer) before we try to delete it.
     * This gives the rightful next worker a grace window to start.
     */
    private const FAILSAFE_STABLE_CYCLES = 2;

    private const FAILSAFE_TRIGGER_THRESHOLD = 5; // every 5 sleeps we trigger failsafe

    private const SLEEP_BETWEEN_TURNS = 2;       // seconds

    private int $currentTicket = 0;

    private ?int $failsafeObservedId = null;

    private int $failsafeStableCount = 0;

    private bool $signalHandlerInstalled = false;

    private bool $writeLockAlreadyAcquired = false;

    public function __construct(
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger
    ) {
        $this->installSignalHandlerOnce();
    }

    /**
     * Block until it's our turn, then acquire and keep the write lock.
     * Call release() to delete the ticket and free the lock.
     */
    public function acquire(): void
    {
        if ($this->writeLockAlreadyAcquired) {
            return;
        }

        // Claim ticket if not already done - usually done beforehand (as early as possible in the process)
        $this->claimTicket();

        $i = 0;
        while (true) {
            $i++;
            $this->log('Acquire loop: checking if it is my turn');

            if ($this->isMyTurn()) {
                try {
                    $this->log('Acquiring writer lock (BEGIN EXCLUSIVE)');
                    $this->beginExclusiveTransaction($this->connectionPool->loupeConnection);
                    $this->writeLockAlreadyAcquired = true;
                    $this->log('Writer lock acquired');
                    return;
                } catch (LockWaitTimeoutException $e) {
                    $this->log('Acquire lock failed: ' . $e->getMessage());
                    usleep(200_000); // 200ms backoff
                    continue;
                }
            }

            $this->log('Not my turn, sleeping');
            sleep(self::SLEEP_BETWEEN_TURNS);

            if ($i === self::FAILSAFE_TRIGGER_THRESHOLD) {
                $i = 0;
                $this->runFailsafe();
            }
        }
    }

    public function claimTicket(): int
    {
        if ($this->currentTicket !== 0) {
            return $this->currentTicket;
        }

        return $this->currentTicket = (int) $this->connectionPool->ticketConnection->transactional(function () {
            try {
                $this->connectionPool->ticketConnection->executeStatement(
                    \sprintf('INSERT INTO %s DEFAULT VALUES', self::TABLE_NAME)
                );
            } catch (TableNotFoundException) {
                $this->updateSchema();
                $this->connectionPool->ticketConnection->executeStatement(
                    \sprintf('INSERT INTO %s DEFAULT VALUES', self::TABLE_NAME)
                );
            }

            return $this->connectionPool->ticketConnection->lastInsertId();
        });
    }

    /**
     * Delete our ticket and release the writer lock.
     */
    public function release(): void
    {
        // Acknowledge/delete our ticket under ticket DB exclusive transaction
        if ($this->currentTicket !== 0) {
            try {
                $this->beginExclusiveTransaction($this->connectionPool->ticketConnection);
                $this->connectionPool->ticketConnection->delete(self::TABLE_NAME, [
                    'id' => $this->currentTicket,
                ]);
                $this->commitExclusiveTransaction($this->connectionPool->ticketConnection);
                $this->log('Released ticket ' . $this->currentTicket);
            } catch (LockWaitTimeoutException $e) {
                $this->log('Could not acquire ticket DB lock to delete my ticket: ' . $e->getMessage());
                // If this fails, another worker's failsafe will eventually clean up.
            } finally {
                $this->currentTicket = 0;
            }
        }

        if ($this->writeLockAlreadyAcquired) {
            $this->commitExclusiveTransaction($this->connectionPool->loupeConnection);
            $this->writeLockAlreadyAcquired = false;
            $this->log('Writer lock released');
        }
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function beginExclusiveTransaction(Connection $connection): void
    {
        // cannot use Doctrine's beginTransaction() here because this would use just "BEGIN"
        $connection->executeStatement('BEGIN EXCLUSIVE'); // acquire writer lock if possible
    }

    private function cleanupAndExit(int $signal): void
    {
        $this->log('Signal received: ' . $signal . ' — cleaning up');

        // Try to release our own lock if we held it (ignore errors if we didn't)
        try {
            $this->rollbackExclusiveTransaction($this->connectionPool->loupeConnection);
        } catch (\Throwable) {
        }

        // If we had no ticket, no need to do anything
        if ($this->currentTicket === 0) {
            return;
        }

        // Delete our own ticket so others can progress
        try {
            $this->beginExclusiveTransaction($this->connectionPool->ticketConnection);
            $this->connectionPool->ticketConnection->delete(self::TABLE_NAME, [
                'id' => $this->currentTicket,
            ]);
            $this->commitExclusiveTransaction($this->connectionPool->ticketConnection);
            $this->log('Deleted my ticket on shutdown: ' . $this->currentTicket);
        } catch (\Throwable $e) {
            $this->log('Failed to delete my ticket on shutdown: ' . $e->getMessage());
            try {
                $this->rollbackExclusiveTransaction($this->connectionPool->ticketConnection);
            } catch (\Throwable) {
            }
        } finally {
            $this->currentTicket = 0;
        }

        exit($signal === SIGINT ? 130 : 143);
    }

    private function commitExclusiveTransaction(Connection $connection): void
    {
        $connection->executeStatement('COMMIT');
    }

    private function getCurrentTicket(): ?int
    {
        $current = $this->connectionPool->ticketConnection->fetchOne(
            \sprintf('SELECT id FROM %s ORDER BY id LIMIT 1', self::TABLE_NAME)
        );
        return $current ? (int) $current : null;
    }

    private function getSchema(): Schema
    {
        $schema = new Schema();
        $table = $schema->createTable(self::TABLE_NAME);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true)
            ->setAutoincrement(true);

        $table->setPrimaryKey(['id']);

        return $schema;
    }

    private function installSignalHandlerOnce(): void
    {
        if ($this->signalHandlerInstalled) {
            return;
        }

        $this->signalHandlerInstalled = true;

        if (!\function_exists('pcntl_signal')) {
            $this->log('Signal handling unavailable (pcntl extension not available)');
            return;
        }

        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGTERM, fn () => $this->cleanupAndExit(SIGTERM));
        pcntl_signal(SIGINT, fn () => $this->cleanupAndExit(SIGINT));
    }

    private function isMyTurn(): bool
    {
        return $this->getCurrentTicket() === $this->currentTicket;
    }

    private function isOtherWriterActive(): bool
    {
        // Try to acquire writer lock immediately
        $this->connectionPool->loupeConnection->executeStatement('PRAGMA busy_timeout = 0');

        try {
            $this->beginExclusiveTransaction($this->connectionPool->loupeConnection);
            $this->rollbackExclusiveTransaction($this->connectionPool->loupeConnection);
            return false;
        } catch (LockWaitTimeoutException) {
            return true;
        } finally {
            // Reset busy timeout
            $this->connectionPool->loupeConnection->executeStatement(
                'PRAGMA busy_timeout = ' . LoupeFactory::SQLITE_BUSY_TIMEOUT
            );
        }
    }

    private function log(string $message): void
    {
        $this->logger->info(\sprintf('[TicketHandler (Ticket: %d)] %s', $this->currentTicket, $message));
    }

    private function rollbackExclusiveTransaction(Connection $connection): void
    {
        // cannot use Doctrine's rollback() here because this tracks the transaction level internally so when you
        // use native BEGIN EXCLUSIVE queries, Doctrine won't know there's actually one running
        $connection->executeStatement('ROLLBACK');
    }

    private function runFailsafe(): void
    {
        $this->log('Failsafe: Started');

        if ($this->isOtherWriterActive()) {
            $this->log('Failsafe: Another writer is active. Skipping');
            $this->failsafeObservedId = null;
            $this->failsafeStableCount = 0;
            return;
        }

        $observedCurrent = $this->getCurrentTicket();
        $this->log('Failsafe: No writer active. Observed: ' . ($observedCurrent ?? 'null'));

        if ($observedCurrent === null) {
            $this->failsafeObservedId = null;
            $this->failsafeStableCount = 0;
            return;
        }

        if ($observedCurrent === $this->currentTicket) {
            $this->log('Failsafe: Head is my ticket. Skipping');
            $this->failsafeObservedId = null;
            $this->failsafeStableCount = 0;
            return;
        }

        if ($this->failsafeObservedId === $observedCurrent) {
            $this->failsafeStableCount++;
        } else {
            $this->failsafeObservedId = $observedCurrent;
            $this->failsafeStableCount = 1;
        }

        if ($this->failsafeStableCount < self::FAILSAFE_STABLE_CYCLES) {
            $this->log(\sprintf(
                'Failsafe: Deferring cleanup (Observed: %d, Failsafe Stable Count: %d)',
                $observedCurrent,
                $this->failsafeStableCount,
            ));
            return;
        }

        // Re-check writer activity just before taking the ticket lock
        if ($this->isOtherWriterActive()) {
            $this->log('Failsafe: Writer became active. Skipping delete');
            $this->failsafeObservedId = null;
            $this->failsafeStableCount = 0;
            return;
        }

        // Atomic cleanup: delete exactly the observed head if it is still the head
        try {
            $this->log('Failsafe: Attempting atomic cleanup');
            $this->beginExclusiveTransaction($this->connectionPool->ticketConnection);

            $freshCurrent = $this->getCurrentTicket();
            if (
                $freshCurrent !== null &&
                $freshCurrent === $observedCurrent &&
                $freshCurrent !== $this->currentTicket
            ) {
                $this->connectionPool->ticketConnection->delete(self::TABLE_NAME, [
                    'id' => $freshCurrent,
                ]);
                $this->log('Failsafe: Deleted ticket ' . $freshCurrent);
            } else {
                $this->log('Failsafe: Head changed or became my own. Skipping delete');
            }

            $this->commitExclusiveTransaction($this->connectionPool->ticketConnection);
        } catch (LockWaitTimeoutException $e) {
            $this->log('Failsafe: Could not acquire ticket lock: ' . $e->getMessage());
        } finally {
            $this->failsafeObservedId = null;
            $this->failsafeStableCount = 0;
        }
    }

    private function updateSchema(): void
    {
        $schemaManager = $this->connectionPool->ticketConnection->createSchemaManager();
        $comparator = $schemaManager->createComparator();

        $schemaDiff = $comparator->compareSchemas($schemaManager->introspectSchema(), $this->getSchema());
        try {
            $schemaManager->alterSchema($schemaDiff);
        } catch (TableExistsException|LockWaitTimeoutException) {
            // noop - already exists or being created concurrently
        }
    }
}
