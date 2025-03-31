<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

class Cropper implements AbstractTransformer
{
    public function __construct(
        private FormatterOptions $options
    ) {
    }

    /**
     * Transform the text and matches according to the transformer's rules.
     * Each transformer may modify both the text and the matched spans.
     * The spans should be adjusted if the text is modified in a way that affects match positions.
     *
     * @param array<array{start:int, end:int}> $spans
     * @return array{0: string, 1: array<array{start:int, end:int}>}
     */
    public function transform(string $text, array $spans): array
    {
        if (empty($spans)) {
            return [$text, $spans];
        }

        $cropLength = $this->options->getCropLength();
        $cropMarker = $this->options->getCropMarker();

        $result = '';

        // TODO: Implement cropping
        // Important: The spans should be adjusted to reflect the new text snippets
        // Otherwise, the highlighter will not work correctly

        return [$result, $spans];
    }
}
