<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Terminal42\Loupe\Internal\Util;
use Terminal42\Loupe\Loupe;
use Terminal42\Loupe\LoupeFactory;

class FunctionalTest extends TestCase
{
    /**
     * @var array<string, Loupe>
     */
    private array $loupeInstances = [];

    public function testFunctional(): void
    {
        foreach ($this->getTests(__DIR__ . '/Tests') as $testData) {
            $this->setName(sprintf('[Successful "%s"] %s', $testData['INDEX_FIXTURE'], $testData['TEST']));

            $loupe = $this->setupLoupe(
                'successful-' . $testData['INDEX_FIXTURE'],
                $testData['INDEX_CONFIG'],
                $testData['INDEX_FIXTURE']
            );

            $results = $loupe->search($testData['SEARCH']);

            unset($results['processingTimeMs']);

            $this->assertSame($testData['EXPECT'], $results);
        }
    }

    private function getDocumentFixtures(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'), true);
    }

    private function getTestDb(string $key): string
    {
        return __DIR__ . '/../../var/' . $key . '.db';
    }

    private function getTests(string $directory): array
    {
        $tests = [];

        foreach (Finder::create()->files()->name('*.txt')->in($directory) as $test) {
            $testData = [
                'INDEX_FIXTURE' => $test->getRelativePath(),
                'INDEX_CONFIG' => require_once Path::join($test->getPath(), 'index-config.php'),
            ];

            $testData = array_merge($testData, $this->readTestFile($test));

            $tests[] = $testData;
        }

        return $tests;
    }

    private function readTestFile(SplFileInfo $testFile): array
    {
        $tokens = preg_split('#(?:^|\n*)--([A-Z-]+)--\n#', $testFile->getContents(), -1, PREG_SPLIT_DELIM_CAPTURE);

        $sectionInfo = [
            'TEST' => false,
            'SEARCH' => true,
            'EXPECT' => true,
        ];

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

            if ($sectionInfo[$section]) {
                $sectionData = Util::decodeJson($sectionData);
            }

            $data[$section] = $sectionData;
            $section = $sectionData = null;
        }

        return $data;
    }

    private function setupLoupe(string $key, array $indexConfig, string $indexFixture): Loupe
    {
        if (isset($this->loupeInstances[$key])) {
            return $this->loupeInstances[$key];
        }

        $dbPath = $this->getTestDb($key);

        $fs = new Filesystem();
        $fs->remove($dbPath);
        $fs->dumpFile($dbPath, '');

        $factory = new LoupeFactory();
        $loupe = $factory->create($dbPath, $indexConfig);

        // Index
        foreach ($this->getDocumentFixtures($indexFixture) as $document) {
            $loupe->addDocument($document);
        }

        return $this->loupeInstances[$key] = $loupe;
    }
}
