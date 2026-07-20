<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->ignoreErrorsOnExtensions(
        [
            // APCu is optional; Configuration only selects ApcuCachePool when the APCu functions are available.
            'ext-apcu',
            // PCNTL is optional; it enables immediate signal cleanup, while the ticket failsafe handles stale tickets without it.
            'ext-pcntl',
        ],
        [ErrorType::SHADOW_DEPENDENCY],
    )
;
