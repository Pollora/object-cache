<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Exceptions\ForeignDropInException;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/object-cache-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->source = $this->dir.'/source.php';
    $this->target = $this->dir.'/object-cache.php';
    file_put_contents($this->source, "<?php\n// ".DropInInstaller::MARKER." 2\n");
    $this->installer = new DropInInstaller($this->source, $this->target);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

it('says where WordPress looks for the drop-in', function (): void {
    expect($this->installer->target())->toBe($this->target);
});

it('classifies what is at the target', function (): void {
    expect($this->installer->status())->toBe(DropInStatus::Missing);

    copy($this->source, $this->target);
    expect($this->installer->status())->toBe(DropInStatus::Current);

    file_put_contents($this->target, "<?php\n// ".DropInInstaller::MARKER." 1\n");
    expect($this->installer->status())->toBe(DropInStatus::Outdated);

    file_put_contents($this->target, "<?php\n// another cache\n");
    expect($this->installer->status())->toBe(DropInStatus::Foreign);
});

it('installs the drop-in, and says there was none', function (): void {
    expect($this->installer->install())->toBe(DropInStatus::Missing)
        ->and(file_get_contents($this->target))->toBe(file_get_contents($this->source))
        ->and($this->installer->status())->toBe(DropInStatus::Current);
});

it('does nothing when the drop-in is current', function (): void {
    copy($this->source, $this->target);
    touch($this->target, 1_000_000_000);
    clearstatcache();
    $before = filemtime($this->target);

    expect($this->installer->install())->toBe(DropInStatus::Current);

    clearstatcache();

    expect(filemtime($this->target))->toBe($before)
        ->and($before)->toBeLessThan(time() - 1000);
});

it('updates an outdated drop-in', function (): void {
    file_put_contents($this->target, "<?php\n// ".DropInInstaller::MARKER." 1\n");

    expect($this->installer->install())->toBe(DropInStatus::Outdated)
        ->and($this->installer->status())->toBe(DropInStatus::Current);
});

it('refuses to replace another object cache, unless forced', function (): void {
    file_put_contents($this->target, "<?php\n// another cache\n");

    expect(fn () => $this->installer->install())->toThrow(ForeignDropInException::class, 'Use --force')
        ->and(file_get_contents($this->target))->toContain('another cache')
        ->and($this->installer->install(force: true))->toBe(DropInStatus::Foreign)
        ->and($this->installer->status())->toBe(DropInStatus::Current);
});

it('fails clearly when it cannot write', function (): void {
    $installer = new DropInInstaller($this->source, $this->dir.'/missing/object-cache.php');

    expect(fn () => $installer->install())->toThrow(RuntimeException::class, 'Cannot write');
});

it('removes its own drop-in, and says what it removed', function (): void {
    copy($this->source, $this->target);

    expect($this->installer->uninstall())->toBe(DropInStatus::Current)
        ->and(file_exists($this->target))->toBeFalse();
});

it('removes an outdated drop-in of its own', function (): void {
    file_put_contents($this->target, "<?php\n// ".DropInInstaller::MARKER." 1\n");

    expect($this->installer->uninstall())->toBe(DropInStatus::Outdated)
        ->and(file_exists($this->target))->toBeFalse();
});

it('has nothing to remove when there is no drop-in', function (): void {
    expect($this->installer->uninstall())->toBe(DropInStatus::Missing);
});

it('never removes another object cache', function (): void {
    file_put_contents($this->target, "<?php\n// another cache\n");

    expect(fn () => $this->installer->uninstall())->toThrow(ForeignDropInException::class)
        ->and(file_exists($this->target))->toBeTrue();
});

it('fails clearly when it cannot remove', function (): void {
    $dir = $this->dir.'/locked';
    mkdir($dir);
    $target = $dir.'/object-cache.php';
    file_put_contents($target, "<?php\n// ".DropInInstaller::MARKER." 1\n");
    chmod($dir, 0555);

    $installer = new DropInInstaller($this->source, $target);

    try {
        if (is_writable($dir)) {
            $this->markTestSkipped('Permissions are not enforced here (running as root).');
        }

        expect(fn () => $installer->uninstall())->toThrow(RuntimeException::class, 'Cannot remove');
    } finally {
        chmod($dir, 0755);
        unlink($target);
        rmdir($dir);
    }
});
