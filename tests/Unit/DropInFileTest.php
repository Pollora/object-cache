<?php

declare(strict_types=1);
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;

/*
 * The drop-in file, loaded the way WordPress loads it: in a PHP process of its
 * own, where nothing has defined a wp_cache_* function.
 */
function runDropIn(string $mode, bool $withExtensions, ?string $dropIn = null, ?string $errorLog = null): array
{
    $root = dirname(__DIR__, 2);
    $command = sprintf(
        '%s %s %s %s %s %s %s',
        escapeshellarg(PHP_BINARY),
        $withExtensions ? '' : '-n',
        $errorLog === null ? '' : '-d '.escapeshellarg('error_log='.$errorLog),
        escapeshellarg($root.'/tests/Support/dropin-runner.php'),
        escapeshellarg($root),
        escapeshellarg($dropIn ?? $root.'/resources/drop-in/object-cache.php'),
        $mode,
    );

    $output = shell_exec($command.' 2>/dev/null');

    return is_string($output) ? (array) json_decode($output, true) : [];
}

it('defines no wp_cache function without phpredis, so WordPress keeps its own cache', function (): void {
    $result = runDropIn('bare', withExtensions: false);

    expect($result)->toBe(['init' => false]);
});

it('defines no wp_cache function when nothing configures the cache', function (): void {
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
    expect(file_get_contents(dirname(__DIR__, 2).'/resources/drop-in/object-cache.php'))->toContain('Pollora-Object-Cache-Drop-In: 2');
});

it('stays inert, instead of stopping the site, when the autoloader it names cannot be read', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('phpredis is not loaded.');
    }

    $dir = sys_get_temp_dir().'/dropin-unreadable-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $autoload = $dir.'/autoload.php';
    file_put_contents($autoload, '<?php throw new RuntimeException("must not be loaded");');
    chmod($autoload, 0000);

    try {
        if (is_readable($autoload) || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('This user can read any file (root): the case cannot be set up.');
        }

        $root = dirname(__DIR__, 2);
        (new DropInInstaller($root.'/resources/drop-in/object-cache.php', $dir.'/object-cache.php', $autoload))->install();

        expect(runDropIn('load', withExtensions: true, dropIn: $dir.'/object-cache.php'))->toBe(['init' => false]);
    } finally {
        chmod($autoload, 0600);
        unlink($autoload);

        if (is_file($dir.'/object-cache.php')) {
            unlink($dir.'/object-cache.php');
        }

        rmdir($dir);
    }
});

/**
 * What the drop-in says in the error log when it names the given autoloader (null: names none).
 *
 * @return array{result: array<string, mixed>, log: string}
 */
function dropInNaming(?string $autoload): array
{
    $dir = sys_get_temp_dir().'/dropin-log-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $root = dirname(__DIR__, 2);
    (new DropInInstaller($root.'/resources/drop-in/object-cache.php', $dir.'/object-cache.php', $autoload ?? ''))->install();
    $result = runDropIn('load', withExtensions: true, dropIn: $dir.'/object-cache.php', errorLog: $dir.'/php.log');
    $log = is_file($dir.'/php.log') ? (string) file_get_contents($dir.'/php.log') : '';

    foreach (glob($dir.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($dir);

    return ['result' => $result, 'log' => $log];
}

it('says in the error log, once and by name, that the autoloader it names cannot be read', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('phpredis is not loaded.');
    }

    $said = dropInNaming('/no/such/home/vendor/autoload.php');

    expect($said['result'])->toBe(['init' => false])
        ->and($said['log'])->toContain('Pollora object cache: the Composer autoloader /no/such/home/vendor/autoload.php cannot be read by the web server')
        ->and($said['log'])->toContain('wp object-cache enable')
        ->and(substr_count($said['log'], 'Pollora object cache'))->toBe(1);
});

it('says nothing when the autoloader is readable, or when the drop-in names none', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('phpredis is not loaded.');
    }

    $readable = dropInNaming(dirname(__DIR__, 2).'/vendor/autoload.php');
    $none = dropInNaming(null);

    expect($readable['log'])->toBe('')
        ->and($none['log'])->toBe('')
        ->and($none['result'])->toBe(['init' => false]);
});
