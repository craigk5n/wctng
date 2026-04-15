<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    ->exclude('config/jwt');

return (new PhpCsFixer\Config())
    ->setRules([
        // PER Coding Style 3.0 — the current PHP-FIG living standard,
        // formally replaces PSR-12. `@PER-CS3x0` is the non-deprecated
        // alias (`@PER-CS3.0` still works but triggers a deprecation
        // warning in php-cs-fixer 3.94+).
        '@PER-CS3x0' => true,
        // Opt-in PHP 8.3 syntax migrations (match expressions, etc.).
        // Our baseline is 8.3 since PBP-S7; matching the fixer ruleset
        // to that baseline keeps the formatter honest.
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
