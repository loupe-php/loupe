<?php

namespace Terminal42\Loupe;

use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Terminal42\Loupe\Index\Index;
use Terminal42\Loupe\Internal\IndexManager;
use Terminal42\Loupe\Internal\Util;

final class Loupe
{
    public function __construct(private IndexManager $indexManager)
    {
    }


    public function getIndex(string $name): Index
    {
        return $this->indexManager->getIndex($name);
    }

    public function getSchema(): Schema
    {
        return $this->indexManager->getSchema();
    }

    public function createSchema(): void
    {
        $this->indexManager->createSchema();
    }

    public static function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('loupe');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode->children()
            ->arrayNode('indexes')
                ->isRequired()
                ->info('An array of all the desired indexes')
                ->ignoreExtraKeys()
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('primaryKey')
                            ->defaultValue('id')
                        ->end()
                        ->arrayNode('searchableAttributes')
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['*'])
                            ->scalarPrototype()->end()
                            ->validate()
                                ->always(function(array $attributes) {
                                    foreach ($attributes as $attribute) {
                                        Util::validateAttributeName($attribute);
                                    }

                                    return $attributes;
                                })
                            ->end()
                        ->end()
                        ->arrayNode('filterableAttributes')
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->validate()
                                ->always(function(array $attributes) {
                                    foreach ($attributes as $attribute) {
                                        Util::validateAttributeName($attribute);
                                    }

                                    return $attributes;
                                })
                            ->end()
                        ->end()
                        ->arrayNode('sortableAttributes')
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->validate()
                                ->always(function(array $attributes) {
                                    foreach ($attributes as $attribute) {
                                        Util::validateAttributeName($attribute);
                                    }

                                    return $attributes;
                                })
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}