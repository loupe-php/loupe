<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DatabaseSizeCommand
{
    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $databasePath = $input->getArgument('database');

        if (!\is_string($databasePath) || !is_file($databasePath)) {
            $io->error('The database path must point to an existing file.');

            return Command::INVALID;
        }

        $measurements = (new DatabaseSizeReporter($databasePath))->measure();
        $objects = [
            ['name' => 'Database file', 'type' => 'database', 'table' => '-', 'bytes' => $measurements['fileSize']],
            ['name' => 'Free pages', 'type' => 'free', 'table' => '-', 'bytes' => $measurements['freeSize']],
            ...$measurements['objects'],
        ];

        $io->title('Database storage');
        $io->table(
            ['Object', 'Type', 'Table', 'Bytes', 'MiB'],
            array_map($this->formatRow(...), $objects),
        );

        return Command::SUCCESS;
    }

    /**
     * @param array{name: string, type: string, table: string, bytes: int} $object
     *
     * @return array{string, string, string, TableCell, TableCell}
     */
    private function formatRow(array $object): array
    {
        $rightAligned = new TableCellStyle(['align' => 'right']);

        return [
            $object['name'],
            $object['type'],
            $object['table'],
            new TableCell(number_format($object['bytes'], 0, '.', ''), ['style' => $rightAligned]),
            new TableCell(number_format($object['bytes'] / 1024 / 1024, 2, '.', ''), ['style' => $rightAligned]),
        ];
    }
}
