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
        TokenCollection $terms,
        FormatterOptions $options
    ): FormatterResult {
        $matches = $this->matcher->calculateMatches($text, $terms);
        $spans = $this->matcher->mergeMatchesIntoSpans($matches);

        $transformers = [];
        if ($options->shouldCropAttribute($attribute)) {
            $transformers[] = new Cropper($options);
        }
        if ($options->shouldHighlightAttribute($attribute)) {
            $transformers[] = new Highlighter($options);
        }

        $formattedText = $text;
        $updatedSpans = $spans;
        foreach ($transformers as $transformer) {
            [$formattedText, $updatedSpans] = $transformer->transform($formattedText, $updatedSpans);
        }

        return new FormatterResult($formattedText, $matches);
    }
}
