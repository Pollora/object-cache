<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Management\Manager;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WpCli\ObjectCacheCommand;
use Pollora\ObjectCache\Tests\Support\Environments;
use Pollora\ObjectCache\Tests\Support\RecordingOutput;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/oc-cli-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    file_put_contents($this->dir.'/source.php', "<?php\n// ".DropInInstaller::MARKER." 2\n");
    $this->target = $this->dir.'/object-cache.php';
    $this->output = new RecordingOutput;
    $this->settings = Settings::fromArray(['prefix' => 'cli-test', 'redis' => ['host' => '127.0.0.1', 'port' => 1, 'connect_timeout' => 0.2]]);
    $this->command = fn (?Settings $settings): ObjectCacheCommand => new ObjectCacheCommand(
        fn (): Manager => new Manager(new DropInInstaller($this->dir.'/source.php', $this->target), Environments::make()),
        $this->output,
        fn (): ?Settings => $settings,
    );
});

afterEach(function (): void {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->dir);
});

it('installs, and says so, then says it is already there', function (): void {
    ($this->command)($this->settings)->enable();
    ($this->command)($this->settings)->enable();

    expect($this->output->success)->toBe(['Installed the drop-in.', 'Already installed.'])
        ->and($this->output->warning)->toBe([]);
});

it('updates an outdated drop-in and replaces another one only when forced', function (): void {
    file_put_contents($this->target, "<?php\n// ".DropInInstaller::MARKER." 1\n");
    ($this->command)($this->settings)->enable();
    file_put_contents($this->target, "<?php\n// another object cache\n");
    ($this->command)($this->settings)->enable();
    ($this->command)($this->settings)->enable([], ['force' => true]);

    expect($this->output->success)->toBe(['Updated the drop-in.', 'Replaced the other object cache.'])
        ->and($this->output->error)->toHaveCount(1)
        ->and($this->output->error[0])->toContain('belongs to another object cache');
});

it('warns that nothing configures the cache', function (): void {
    ($this->command)(null)->enable();

    expect($this->output->warning[0])->toContain('WP_OBJECT_CACHE_CONFIG');
});

it('reports a drop-in that cannot be written', function (): void {
    $command = new ObjectCacheCommand(
        fn (): Manager => new Manager(new DropInInstaller($this->dir.'/source.php', $this->dir.'/missing-dir/object-cache.php'), Environments::make()),
        $this->output,
        fn (): ?Settings => $this->settings,
    );
    $command->enable();
    $command->disable();

    expect($this->output->error)->toHaveCount(1)
        ->and($this->output->error[0])->toContain('Cannot write')
        ->and($this->output->success)->toBe(['Nothing to remove.']);
});

it('removes the drop-in, and refuses another cache\'s', function (): void {
    ($this->command)($this->settings)->enable();
    ($this->command)($this->settings)->disable();
    file_put_contents($this->target, "<?php\n// another object cache\n");
    ($this->command)($this->settings)->disable();

    expect($this->output->success)->toBe(['Installed the drop-in.', 'Removed the drop-in.'])
        ->and($this->output->error[0])->toContain('not removed');
});

it('shows the status, and fails when the cache does not work', function (): void {
    ($this->command)($this->settings)->status();

    expect($this->output->lines)->toHaveCount(7)
        ->and($this->output->lines[0])->toStartWith('Drop-in:')
        ->and($this->output->error)->toBe(['The object cache is not working.']);
});

it('empties the keys of the site, and refuses without settings', function (): void {
    (new RedisHarness)->store();
    $live = Settings::fromArray(['prefix' => 'cli-test', 'redis' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => RedisHarness::database()]]);

    ($this->command)($live)->flush();
    ($this->command)(null)->flush();

    expect($this->output->success)->toBe(['Emptied the keys of this site.'])
        ->and($this->output->error)->toHaveCount(1)
        ->and($this->output->error[0])->toContain('WP_OBJECT_CACHE_CONFIG');
});
