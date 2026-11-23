<?php

declare(strict_types=1);

/*
 * The drop-in file, loaded the way WordPress loads it: in a PHP process of its
 * own, where nothing has defined a wp_cache_* function.
 */
function runDropIn(string $mode, bool $withExtensions): array
{
    $root = dirname(__DIR__, 2);
    $command = sprintf(
        '%s %s %s %s %s %s',
        escapeshellarg(PHP_BINARY),
        $withExtensions ? '' : '-n',
        escapeshellarg($root.'/tests/Support/dropin-runner.php'),
        escapeshellarg($root),
        escapeshellarg($root.'/resources/drop-in/object-cache.php'),
        $mode,
    );

    $output = shell_exec($command.' 2>/dev/null');

    return is_string($output) ? (array) json_decode($output, true) : [];
}

it('defines no wp_cache function without phpredis, so WordPress keeps its own cache', function (): void {
    $result = runDropIn('bare', withExtensions: false);

    expect($result)->toBe(['init' => false]);
});

it('defines no wp_cache function when the package is not wired into the container', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('phpredis is not loaded.');
    }

    expect(runDropIn('load', withExtensions: true))->toBe(['init' => false]);
});

it('defines every wp_cache function of the object cache API, and serves them', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('phpredis is not loaded.');
    }

    $result = runDropIn('use', withExtensions: true);

    expect($result)->toMatchArray([
        'init' => true,
        'value' => ['v'],
        'found' => true,
        'missing' => false,
        'missing_found' => false,
        'incr' => 3,
        'flush' => true,
        'after_flush' => false,
        'supports' => true,
        'close' => true,
        'functions' => [],
    ]);
});

it('carries the marker the installer recognises it by', function (): void {
    expect(file_get_contents(dirname(__DIR__, 2).'/resources/drop-in/object-cache.php'))->toContain('Pollora-Object-Cache-Drop-In: 1');
});
