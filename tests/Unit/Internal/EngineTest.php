<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    public function testFailedIndexOperationRollsBackAndReleasesTicket(): void
    {
        $engine = $this->createEngine();
        $engine->addDocuments([['id' => 1, 'title' => 'Existing document']]);
        $engine->getConnection()->executeStatement(
            "CREATE TRIGGER fail_term_relation BEFORE INSERT ON terms_documents BEGIN SELECT RAISE(ABORT, 'Forced failure'); END",
        );

        try {
            $engine->addDocuments([['id' => 2, 'title' => 'Retry succeeds']]);
            $this->fail('The index operation should have failed.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('Forced failure', $exception->getMessage());
        }

        $this->assertSame(1, $engine->countDocuments());
        $engine->getConnection()->executeStatement('DROP TRIGGER fail_term_relation');
        $engine->addDocuments([['id' => 2, 'title' => 'Retry succeeds']]);

        $this->assertSame(2, $engine->countDocuments());
        $result = $engine->search(SearchParameters::create()->withQuery('retry'));
        $this->assertSame(1, $result->getTotalHits());
    }

    private function createEngine(): Engine
    {
        $loupe = (new LoupeFactory())->createInMemory(Configuration::create());
        $engineProperty = new \ReflectionProperty($loupe, 'engine');
        $engine = $engineProperty->getValue($loupe);
        $this->assertInstanceOf(Engine::class, $engine);

        return $engine;
    }
}
