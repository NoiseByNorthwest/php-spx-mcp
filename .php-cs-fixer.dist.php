<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])
    ->name('*.php')
    ->name('server.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS'             => true,
        '@PER-CS:risky'       => true,
        'declare_strict_types' => true,
        'no_unused_imports'   => true,
        'ordered_imports'     => ['sort_algorithm' => 'alpha'],
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'constant_public', 'constant_protected', 'constant_private',
                'property_public', 'property_protected', 'property_private',
                'construct',
                'phpunit',
                'method_public', 'method_protected', 'method_private',
            ],
            'sort_algorithm' => 'none',
        ],
        'array_syntax'        => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'continue', 'break'],
        ],
        'native_function_invocation' => false,
    ])
    ->setFinder($finder);
