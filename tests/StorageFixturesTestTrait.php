<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests;

use PHPUnit\Framework\Attributes\AfterClass;
use Symfony\Component\Filesystem\Filesystem;

trait StorageFixturesTestTrait
{
    /**
     * @var string[]
     */
    protected array $tmpDataDirs = [];

    protected function createTemporaryDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/' . uniqid('lt');
        $this->tmpDataDirs[] = $dir;

        $fs = new Filesystem();
        ($fs)->mkdir($dir);

        return $dir;
    }

    #[AfterClass]
    private function clearTemporaryDirectory(): void
    {
        $fs = new Filesystem();
        foreach (array_filter($this->tmpDataDirs) as $dir) {
            $fs->remove($dir);
        }
    }
}
