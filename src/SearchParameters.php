<?php

declare(strict_types=1);

namespace Terminal42\Loupe;

use Terminal42\Loupe\Internal\Search\Sorting\Relevance;

class SearchParameters
{
    public array $attributesToHighlight = [];

    public array $attributesToRetrieve = ['*'];

    public string $filter = '';

    public int $hitsPerPage = 20;

    public int $page = 1;

    public string $query = '';

    public bool $showMatchesPosition = false;

    public array $sort = [Relevance::RELEVANCE_ALIAS . ':desc'];

    public static function fromArray(array $search): self
    {
        $parameters = new self();

        foreach ($search as $k => $v) {
            if ($k === 'q') {
                $k = 'query';
            }
            $parameters->{$k} = $v;
        }

        return $parameters;
    }
}
