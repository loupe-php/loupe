<?php

namespace Terminal42\Loupe\Index;

use Doctrine\DBAL\Connection;
use Terminal42\Loupe\Internal\IndexManager;

class Index
{
    public function __construct(private IndexManager $indexManager, private string $name)
    {

    }

    public function addDocument(array $document): self
    {
        $this->indexManager->addDocument($document, $this->name);

        return $this;
    }

    public function search(array $parameters): array
    {
        return $this->indexManager->search($parameters, $this->name);
    }
}