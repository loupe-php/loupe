<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Search\Formatting\Matcher\Matcher;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Formatter
{
    private Matcher $matcher;

    public function __construct(Engine $engine)
    {
        $this->matcher = new Matcher($engine);
    }

    public function format(
        string $attribute,
        string $text,
        TokenCollection $queryTerms,
        FormatterOptions $options
    ): FormatterResult {
        $matches = $this->matcher->calculateMatches($text, $queryTerms);
        $spans = $this->matcher->calculateMatchSpans($matches);

        $transformers = [];
        if ($options->shouldHighlightAttribute($attribute)) {
            $transformers[] = new Highlighter($spans, $options->getHighlightStartTag(), $options->getHighlightEndTag());
        }
        if ($options->shouldCropAttribute($attribute)) {
            if (! $options->shouldHighlightAttribute($attribute)) {
                $transformers[] = new Highlighter('[~MATCH~]', '[~/MATCH~]');
            }
            $transformers[] = new Cropper($options->getCropLength(), $options->getCropMarker());
            if (! $options->shouldHighlightAttribute($attribute)) {
                $transformers[] = new Unhighlighter('[~MATCH~]', '[~/MATCH~]');
            }
        }

        $formattedText = $text;
        foreach ($transformers as $transformer) {
            $formattedText = $transformer->transform($formattedText, $matches, $spans);
        }

        return new FormatterResult($formattedText, $matches);
    }
}
