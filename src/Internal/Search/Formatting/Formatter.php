<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Formatter
{
    private const TEMP_HIGHLIGHT_END_TAG = '[~/MATCH~]';

    private const TEMP_HIGHLIGHT_START_TAG = '[~MATCH~]';

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
        if ($shouldHighlight) {
            $transformers[] = new Highlighter($this->matcher, $options->getHighlightStartTag(), $options->getHighlightEndTag());
        }
        if ($shouldCrop) {
            if (!$shouldHighlight) {
                $transformers[] = new Highlighter($this->matcher, self::TEMP_HIGHLIGHT_START_TAG, self::TEMP_HIGHLIGHT_END_TAG);
            }
            $transformers[] = new Cropper($options->getCropLength(), $options->getCropMarker());
            if (!$shouldHighlight) {
                $transformers[] = new Unhighlighter(self::TEMP_HIGHLIGHT_START_TAG, self::TEMP_HIGHLIGHT_END_TAG);
            }
        }

        $formattedText = $text;
        foreach ($transformers as $transformer) {
            $formattedText = $transformer->transform($formattedText, $matches);
        }

        return new FormatterResult($formattedText, $matches);
    }
}
