<?php

$header = <<<'EOF'
This file is part of php-comp/http-client.

@author   https://github.com/inhere
@link     https://github.com/php-comp/http-client
@license  MIT
EOF;

$rules = [
    '@PSR2' => true,
    'array_syntax' => [
        'syntax' => 'short'
    ],
    'list_syntax' => [
        'syntax' => 'short'
    ],
    'class_attributes_separation' => true,
    'declare_strict_types' => true,
    'global_namespace_import' => [
        'import_constants' => true,
        'import_functions' => true,
    ],
    'header_comment' => [
        'comment_type' => 'PHPDoc',
        'header'    => $header,
        'separate'  => 'bottom'
    ],
    'no_unused_imports' => true,
    'return_type_declaration' => [
        'space_before' => 'one',
    ],
    'single_quote' => true,
    'standardize_not_equals' => true,
    'void_return' => true, // add :void for method
];

return PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules($rules)
    ->setFinder(
        PhpCsFixer\Finder::create()
            // ->exclude('test')
            ->exclude('runtime')
            ->exclude('vendor')
            ->in(__DIR__)
    )
    ->setUsingCache(false);
