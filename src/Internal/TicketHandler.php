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

    private const FAILSAFE_TRIGGER_THRESHOLD = 5; // Every 5 SLEEP_BETWEEN_TURNS we also trigger our failsafe detection

    private const SLEEP_BETWEEN_TURNS = 2;

    private int $currentTicket = 0;

    public function __construct(
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger
    ) {
    }

    public function claimTicket(): int
    {
        if ($this->currentTicket !== 0) {
            return $this->currentTicket;
        }

        return $this->currentTicket = (int) $this->connectionPool->ticketConnection->transactional(function () {
            try {
                $this->connectionPool->ticketConnection->executeStatement(\sprintf('INSERT INTO %s DEFAULT VALUES', self::TABLE_NAME));
            } catch (TableNotFoundException) {
                $this->updateSchema();
                $this->connectionPool->ticketConnection->executeStatement(\sprintf('INSERT INTO %s DEFAULT VALUES', self::TABLE_NAME));
            }

            return $this->connectionPool->ticketConnection->lastInsertId();
        });
    }

    public function waitForTicket(\Closure $closure): void
    {
        $this->claimTicket();
        $i = 0;

        while (true) {
            $i++;
            $this->log('Starting new loop');

            if (!$this->isMyTurn()) {
                $this->log('Not my turn, sleeping');

                sleep(self::SLEEP_BETWEEN_TURNS);

                // After n turns where it's not our turn, we check if there is even another writer active. If so,
                // we continue in our loop, otherwise it is now our turn!
                if ($i === self::FAILSAFE_TRIGGER_THRESHOLD) {
                    $this->log('Failsafe process started');

                    $i = 0;
                    if ($this->isOtherWriterActive()) {
                        $this->log('Some other writer is active, continue');
                        continue;
                    }

                    // Okay, so it's not our turn but also no other worker is active anymore. That means the process
                    // of the current ticket has not been deleted/acknowledged or it just happened now. We need to check
                    // if it's our turn now anyway or if not, delete the non-acknowledged ticket number and then continue in
                    // our loop - because maybe it's still not our turn
                    $currentTicket = $this->getCurrentTicket();

                    $this->log('No other writer is active. Current ticket: ' . $currentTicket);

                    // It's our turn now, do not delete but continue the loop which will do that.
                    if ($currentTicket === $this->currentTicket) {
                        $this->log('Detected that it is our own process, continuing');
                        continue;
                    }

                    try {
                        $this->log('Deleting the current ticket');
                        $this->beginExclusiveTransaction($this->connectionPool->ticketConnection);
                        $this->connectionPool->ticketConnection->delete(self::TABLE_NAME, [
                            'id' => $currentTicket,
                        ]);
                        $this->commitExclusiveTransaction($this->connectionPool->ticketConnection);
                    } catch (LockWaitTimeoutException $e) {
                        $this->log('Could not delete the current ticket: ' . $e->getMessage());

                        // noop - maybe two or more processes detected no other process was active at the same time so
                        // only one will be able to reset the ticket.
                    }
                }

                continue;
            }

            // Try to acquire our exclusive lock now so that we can start working on our stuff. Should not fail but
            // still could if multiple workers concurrently decided it's not their turn but there's also no other writer
            // active. In this case they could make it to this point at the very same time, causing all but one of them
            // to not being able to start the transaction. In this case, we just stay in the loop and wait again until

            try {
                $this->log('Acquiring lock for my work');
                $this->beginExclusiveTransaction($this->connectionPool->loupeConnection);
            } catch (LockWaitTimeoutException $e) {
                $this->log('Acquiring lock failed: ' . $e->getMessage());

                continue;
            }

            try {
                $this->log('Doing my work now');
                $closure();

                // Acknowledge our ticket to mark it done
                $this->log('Done with my work. Deleting my ticket');
                $this->connectionPool->ticketConnection->delete(self::TABLE_NAME, [
                    'id' => $this->currentTicket,
                ]);
                $this->currentTicket = 0;
                $this->commitExclusiveTransaction($this->connectionPool->loupeConnection);
                return;
            } catch (\Throwable $e) {
                $this->log('Could not delete my ticket: ' . $e->getMessage());

                $this->rollbackExclusiveTransaction($this->connectionPool->loupeConnection);
                throw $e;
            }
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

    private function commitExclusiveTransaction(Connection $connection): void
    {
        // cannot use Doctrine's commit() here because this tracks the transaction level internally so when you
        // use native BEGIN EXCLUSIVE queries, Doctrine won't know there's actually one running.
        $connection->executeStatement('COMMIT');
    }

    private function getCurrentTicket(): ?int
    {
        $current = $this->connectionPool->ticketConnection->fetchOne(\sprintf('SELECT id FROM %s ORDER BY id LIMIT 1', self::TABLE_NAME));
        return $current ? (int) $current : null;
    }

    private function getSchema(): Schema
    {
        $schema = new Schema();

        $table = $schema->createTable(self::TABLE_NAME);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true)
            ->setAutoincrement(true)
        ;

        $table->setPrimaryKey(['id']);

        return $schema;
    }

    private function isMyTurn(): bool
    {
        return $this->getCurrentTicket() === $this->currentTicket;
    }

    private function isOtherWriterActive(): bool
    {
        $this->connectionPool->loupeConnection->executeStatement('PRAGMA busy_timeout = 0'); // try immediately

        try {
            $this->beginExclusiveTransaction($this->connectionPool->loupeConnection); // acquire writer lock if possible
            $this->rollbackExclusiveTransaction($this->connectionPool->loupeConnection);
            return false;
        } catch (LockWaitTimeoutException) {
            return true; // we have another worker
        } finally {
            // Reset to previous set busy timeout
            $this->connectionPool->loupeConnection->executeStatement('PRAGMA busy_timeout = ' . LoupeFactory::SQLITE_BUSY_TIMEOUT);
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

    private function updateSchema(): void
    {
        $schemaManager = $this->connectionPool->ticketConnection->createSchemaManager();
        $comparator = $schemaManager->createComparator();

        $schemaDiff = $comparator->compareSchemas($schemaManager->introspectSchema(), $this->getSchema());
        try {
            $schemaManager->alterSchema($schemaDiff);
        } catch (TableExistsException|LockWaitTimeoutException $e) {
            // noop - either it's already here or another process is creating it at the very same time
        }
    }
}
