<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Import\GlobalNamespaceImportFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer;
use Symplify\CodingStandard\Fixer\ArrayNotation\ArrayOpenerAndCloserNewlineFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])

    // add a single rule
    ->withRules([
        GlobalNamespaceImportFixer::class,
        NoUnusedImportsFixer::class,
    ])
    ->withConfiguredRule(OrderedImportsFixer::class, [
        'imports_order' => ['class', 'function', 'const'],
        'sort_algorithm' => 'alpha',
    ])
    ->withSkip([
        ArrayOpenerAndCloserNewlineFixer::class,
        NotOperatorWithSuccessorSpaceFixer::class,
        PhpdocLineSpanFixer::class,
    ])

    // add sets - group of rules, from easiest to more complex ones
    // uncomment one, apply one, commit, PR, merge and repeat
    ->withPreparedSets(
        spaces: true,
        namespaces: true,
        docblocks: true,
        arrays: true,
        comments: true,
    );
