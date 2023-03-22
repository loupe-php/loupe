<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use PhpCsFixer\Fixer\Operator\ConcatSpaceFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

return static function (ECSConfig $ecsConfig): void {
    $ecsConfig->paths([__DIR__ . '/src', __DIR__ . '/tests']);

    $ecsConfig->sets([
         SetList::PSR_12,
         SetList::CLEAN_CODE,
         SetList::ARRAY,
         SetList::COMMON,
         SetList::COMMENTS,
         SetList::DOCBLOCK,
         SetList::SPACES,
         SetList::STRICT,
         SetList::SYMPLIFY,
         SetList::PHPUNIT,
    ]);

    $ecsConfig->ruleWithConfiguration(OrderedClassElementsFixer::class, ['sort_algorithm' => 'alpha']);
   // $ecsConfig->ruleWithConfiguration(ConcatSpaceFixer::class, ['spacing' => 'none']);

};
