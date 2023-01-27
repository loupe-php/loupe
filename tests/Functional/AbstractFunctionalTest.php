<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Terminal42\Loupe\Internal\Util;
use Terminal42\Loupe\Loupe;
use Terminal42\Loupe\LoupeFactory;

abstract class AbstractFunctionalTest extends TestCase
{
    /**
     * @var array<string, Loupe>
     */
    private static array $loupeInstances = [];

    protected function createTestDb(string $name, bool $forceEmpty = true): string
    {
        $path = __DIR__ . '/../../var/' . $name . '.db';

        $fs = new Filesystem();

        if ($forceEmpty) {
            $fs->remove($path);
        }

        if (! $fs->exists($path)) {
            $fs->dumpFile($path, '');
        }

        return __DIR__ . '/../../var/' . $name . '.db';
    }

    protected function getTests(string $directory, array $sectionInfo): array
    {
        $tests = [];
        $filterFile = $_SERVER['TEST_FILE'] ?? '';

        foreach (Finder::create()->files()->name('*.txt')->in($directory) as $test) {
            if ($filterFile && $test->getRelativePathname() !== $filterFile) {
                continue;
            }

            $testData = [
                'TEST_FILE' => $test->getRelativePathname(),
            ];

            $testData = array_merge($testData, $this->readTestFile($test, $sectionInfo));

            $tests[] = $testData;
        }

        return $tests;
    }

    /**
     * @param array<string, bool> $sectionInfo Key is the name of the section (e.g. "SEARCH"), value is a boolean indicating
     *                                          whether to decode JSON or not.
     */
    protected function readTestFile(SplFileInfo $testFile, array $sectionInfo): array
    {
        $tokens = preg_split('#(?:^|\n*)--([A-Z-]+)--\n#', $testFile->getContents(), -1, PREG_SPLIT_DELIM_CAPTURE);

        $section = null;
        $data = [];
        foreach ($tokens as $token) {
            if ($section === null && empty($token)) {
                continue; // skip leading blank
            }

            if ($section === null) {
                if (! isset($sectionInfo[$token])) {
                    throw new \RuntimeException(sprintf(
                        'The test file "%s" must not contain a section named "%s".',
                        $testFile->getRealPath(),
                        $token
                    ));
                }
                $section = $token;
                continue;
            }

            $sectionData = $token;

            $data[$section] = $sectionData;
            $section = $sectionData = null;
        }

        foreach ($data as $section => &$sectionData) {
            $jsonDecode = $sectionInfo[$section];

            if ($jsonDecode) {
                $sectionData = Util::decodeJson($sectionData);
            }
        }

        return $data;
    }

    protected function setupLoupe(array $loupeConfig, string $indexFixture = '', string $dbPath = ''): Loupe
    {
        $loupe = $this->createLoupe($loupeConfig, $dbPath);
        $this->indexFixture($loupe, $indexFixture);

        return $loupe;
    }

    /**
     * Shared across all tests for performance improvement. That way the indexing process doesn't have to
     * be repeated. Should only be used for idempotent tests (= searching only, not changing documents).
     */
    protected function setupSharedLoupe(array $loupeConfig, string $indexFixture = '', string $dbPath = '')
    {
        $loupe = $this->createLoupe($loupeConfig, $dbPath);

        $configHash = $loupe->getConfiguration()
            ->getHash();

        if (isset(self::$loupeInstances[$configHash])) {
            return self::$loupeInstances[$configHash];
        }

        $this->indexFixture($loupe, $indexFixture);

        return self::$loupeInstances[$configHash] = $loupe;
    }

    private function createLoupe(array $loupeConfig, string $dbPath = ''): Loupe
    {
        $factory = new LoupeFactory();

        if ($dbPath === '') {
            $loupe = $factory->createInMemory($loupeConfig);
        } else {
            $loupe = $factory->create($dbPath, $loupeConfig);
        }

        return $loupe;
    }

    private function indexFixture(Loupe $loupe, string $indexFixture = ''): void
    {
        if ($indexFixture === '') {
            return;
        }

        foreach ($this->loadDocumentsFromFixture($indexFixture) as $document) {
            $loupe->addDocument($document);
        }
    }

    private function loadDocumentsFromFixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . '/IndexData/' . $name . '.json'), true);
    }
}
