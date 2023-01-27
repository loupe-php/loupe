<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Terminal42\Loupe\Exception\InvalidJsonException;

class Util
{
    public static function decodeJson(string $data): array
    {
        $data = json_decode($data, true);

        if (! is_array($data)) {
            throw new InvalidJsonException(json_last_error_msg());
        }

        return $data;
    }

    public static function encodeJson(array $data, int $flags = 0): string
    {
        $json = json_encode($data, $flags);

        if ($json === false) {
            throw new InvalidJsonException(json_last_error_msg());
        }

        return $json;
    }

    /**
     * Unfortunately, we cannot use proper UPSERTs here (ON DUPLICATE() UPDATE) as somehow RETURNING does not work
     * properly with Doctrine. Maybe we can improve that one day.
     *
     * @return int The ID of the $insertIdColumn (either new when INSERT or existing when UPDATE)
     */
    public static function upsert(
        Connection $connection,
        string $table,
        array $insertData,
        array $uniqueIndexColumns,
        string $insertIdColumn = '',
        array $updateSet = []
    ): ?int {
        if (count($insertData) === 0) {
            throw new \InvalidArgumentException('Need to provide data to insert.');
        }

        $qb = $connection->createQueryBuilder()
            ->select(array_filter(array_merge([$insertIdColumn], $uniqueIndexColumns)))
            ->from($table);

        foreach ($uniqueIndexColumns as $uniqueIndexColumn) {
            $qb->andWhere($uniqueIndexColumn . '=' . $qb->createPositionalParameter($insertData[$uniqueIndexColumn]));
        }

        $existing = $qb->executeQuery()
            ->fetchAssociative();

        if ($existing === false) {
            $connection->insert($table, $insertData);

            return (int) $connection->lastInsertId();
        }

        $qb = $connection->createQueryBuilder()
            ->update($table);

        if (count($updateSet) === 0) {
            foreach ($insertData as $columnName => $value) {
                $qb->set($columnName, $qb->createPositionalParameter($value));
            }
        } else {
            foreach ($updateSet as $columnName => $value) {
                $qb->set($columnName, $value);
            }
        }

        foreach ($uniqueIndexColumns as $uniqueIndexColumn) {
            $qb->andWhere($uniqueIndexColumn . '=' . $qb->createPositionalParameter($insertData[$uniqueIndexColumn]));
        }

        $qb->executeQuery();

        return $insertIdColumn !== '' ? (int) $existing[$insertIdColumn] : null;
    }
}
