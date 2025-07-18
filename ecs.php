<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\ClassAttributesSeparationFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    // ->withCache('cache/ecs')
    ->withParallel()
    ->withPreparedSets(
        psr12: true,
        common: false, // includes common rules arrays, comments, control structures, docblocks, namespaces, phpunit, spaces
        symplify: false,
        arrays: true,
        comments: true,
        docblocks: true,
        spaces: true,
        namespaces: true,
        controlStructures: true,
        phpunit: true,
        strict: true,
        cleanCode: true,
    )
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        ClassAttributesSeparationFixer::class,
        NotOperatorWithSuccessorSpaceFixer::class,
    ]);
