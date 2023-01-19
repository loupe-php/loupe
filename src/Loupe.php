<?php

namespace Terminal42\Loupe;

use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Util;

final class Loupe
{
    public function __construct(private Engine $engine)
    {
    }

    public function addDocument(array $document): self
    {
        $this->engine->addDocument($document);

        return $this;
    }

    public function search(array $parameters): array
    {
        return $this->engine->search($parameters);
    }
}