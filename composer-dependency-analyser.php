<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07 for this package. It replaces the harness preset, so it repeats what
 * the preset allows for a WordPress repository, and adds the two extensions the
 * package uses but does not require.
 *
 * ext-redis and ext-igbinary are optional on purpose (they are listed under
 * "suggest" in composer.json): a site without them runs on WordPress's own
 * cache, and the code checks for them before it uses them. Without the
 * extension loaded, the analyser cannot resolve `Redis`, hence the two classes.
 */
$root = getcwd() ?: '.';

return (new Configuration)
    ->disableReportingUnmatchedIgnores()
    ->ignoreUnknownClassesRegex('~^(WP_|Walker_|Requests_|wpdb$)~')
    ->ignoreUnknownClasses(['Redis', 'RedisException'])
    ->ignoreUnknownFunctionsRegex('~.*~')
    ->ignoreErrorsOnExtensions(['ext-ctype', 'ext-filter', 'ext-json', 'ext-mbstring', 'ext-redis', 'ext-igbinary'], [ErrorType::SHADOW_DEPENDENCY])
    // Testbench installs the laravel/framework monolith, which replaces the illuminate/* packages the code requires.
    ->ignoreErrorsOnPackage('laravel/framework', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackages(['illuminate/cache', 'illuminate/console', 'illuminate/container', 'illuminate/contracts', 'illuminate/support'], [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPath($root.'/tests', [ErrorType::UNKNOWN_CLASS, ErrorType::UNKNOWN_FUNCTION, ErrorType::SHADOW_DEPENDENCY]);
