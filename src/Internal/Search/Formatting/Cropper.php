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
     * Crop text around the matches in the text.
     *
     * @param array{starts: array<int>, ends: array<int>} $spans
     * @return array{0: string, 1: array{starts: array<int>, ends: array<int>}}
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
