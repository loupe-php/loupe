<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Terminal42\Loupe\Exception\InvalidConfigurationException;
use voku\helper\UTF8;

final class Configuration
{
    public const GEO_ATTRIBUTE_NAME = '_geo';

    public const MAX_ATTRIBUTE_NAME_LENGTH = 30;

    public function __construct(
        private array $configuration
    ) {
        try {
            $this->configuration = (new Processor())->process(
                self::getConfigTreeBuilder()->buildTree(),
                [$this->configuration]
            );
        } catch (\Exception $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), 0, $exception);
        }
    }

    public static function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('loupe');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode
            ->children()
            ->scalarNode('primaryKey')
            ->defaultValue('id')
            ->end()
            ->arrayNode('searchableAttributes')
            ->requiresAtLeastOneElement()
            ->defaultValue(['*'])
            ->scalarPrototype()
            ->end()
            ->validate()
            ->always(function (array $attributes) {
                foreach ($attributes as $attribute) {
                    self::validateAttributeName($attribute);
                }

                return $attributes;
            })
            ->end()
            ->end()
            ->arrayNode('filterableAttributes')
            ->defaultValue([])
            ->scalarPrototype()
            ->end()
            ->validate()
            ->always(function (array $attributes) {
                foreach ($attributes as $attribute) {
                    self::validateAttributeName($attribute);
                }

                return $attributes;
            })
            ->end()
            ->end()
            ->arrayNode('sortableAttributes')
            ->defaultValue([])
            ->scalarPrototype()
            ->end()
            ->validate()
            ->always(function (array $attributes) {
                foreach ($attributes as $attribute) {
                    self::validateAttributeName($attribute);
                }

                return $attributes;
            })
            ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }

    public function getFilterableAndSortableAttributes(): array
    {
        return array_unique(array_merge($this->getFilterableAttributes(), $this->getSortableAttributes()));
    }

    public function getFilterableAttributes(): array
    {
        return $this->getValue('filterableAttributes');
    }

    public function getHash(): string
    {
        return sha1(LoupeTypes::convertToString($this->configuration));
    }

    public function getLevenshteinDistanceForTerm(string $term): int
    {
        $termLength = (int) UTF8::strlen($term);

        return match (true) {
            $termLength >= 9 => 2,
            $termLength >= 5 => 2,
            default => 0
        };
    }

    public function getPrimaryKey(): string
    {
        return $this->getValue('primaryKey');
    }

    public function getSearchableAttributes(): array
    {
        return $this->getValue('searchableAttributes');
    }

    public function getSortableAttributes(): array
    {
        return $this->getValue('sortableAttributes');
    }

    public function getValue(string $configKey): mixed
    {
        return $this->configuration[$configKey] ?? null;
    }

    public static function validateAttributeName(string $name): void
    {
        if ($name === self::GEO_ATTRIBUTE_NAME) {
            return;
        }

        if (strlen($name) > self::MAX_ATTRIBUTE_NAME_LENGTH
            || ! preg_match('/^[a-zA-Z\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name)
        ) {
            throw InvalidConfigurationException::becauseInvalidAttributeName($name);
        }
    }
}
