<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatter;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class ResultFormatter
{
    /**
     * @param array<int, array{start: int, length: int, stopword: bool}> $matches
     */
    public function __construct(
        private Engine $engine,
        private string $text,
        private TokenCollection $queryTokens,
        private FormatterOptions $options,
        private bool $highlight = false,
        private string $highlightStartTag = '<em>',
        private string $highlightEndTag = '</em>',
        private bool $crop = false,
        private string $cropMarker = '...',
        private int $cropLength = 10
    ) {
    }

    public function getMatches(
        string $text,
        TokenCollection $queryTokens
    ): array {
        if ($text === '') {
            return [];
        }

        $matches = [];
        $stopWords = $this->engine->getConfiguration()->getStopWords();
        $textTokens = $this->engine->getTokenizer()->tokenize($text);

        foreach ($textTokens->all() as $textToken) {
            if ($this->matches($textToken, $queryTokens)) {
                $matches[] = [
                    'start' => $textToken->getStartPosition(),
                    'length' => $textToken->getLength(),
                    'stopword' => $textToken->isOneOf($stopWords),
                ];
            }
        }

        // Sort matches by start
        uasort($matches, function (array $a, array $b) {
            return $a['start'] <=> $b['start'];
        });

        return $matches;
    }

    public function getFormattedText(): ?string
    {
        return $this->formattedText;
    }

    /**
     * @return array<int, array{start: int, length: int, stopword: bool}>
     */
    public function getMatches(): array
    {
        return $this->matches;
    }
}
