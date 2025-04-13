<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Engine;
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

        $shouldHighlight = $options->shouldHighlightAttribute($attribute);
        $shouldCrop = $options->shouldCropAttribute($attribute);

        $transformers = [];
        if ($shouldHighlight || $shouldCrop) {
            $transformers[] = new Highlighter($this->matcher, $options->getHighlightStartTag(), $options->getHighlightEndTag());
        }
        if ($shouldCrop) {
            $transformers[] = new Cropper($options->getCropLength(), $options->getCropMarker(), $options->getHighlightStartTag(), $options->getHighlightEndTag());
        }
        if (!$shouldHighlight) {
            $transformers[] = new Unhighlighter($options->getHighlightStartTag(), $options->getHighlightEndTag());
        }

        $formattedText = $text;
        foreach ($transformers as $transformer) {
            $formattedText = $transformer->transform($formattedText, $matches);
        }

        return new FormatterResult($formattedText, $matches);
    }
}
