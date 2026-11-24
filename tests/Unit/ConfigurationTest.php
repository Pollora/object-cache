<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\WordPress\Configuration;

afterEach(function (): void {
    Configuration::forget();
});

it('has nothing to give until a source is registered', function (): void {
    expect(Configuration::exists())->toBeFalse()
        ->and(Configuration::settings())->toBeNull();
});

it('reads the settings from the registered source, each time it is asked', function (): void {
    $calls = 0;
    Configuration::provide(function () use (&$calls): array {
        $calls++;

        return ['prefix' => 'mine'];
    });

    expect(Configuration::exists())->toBeTrue()
        ->and(Configuration::settings())->toBeInstanceOf(Settings::class)
        ->and(Configuration::settings()?->prefix)->toBe('mine')
        ->and($calls)->toBe(2);
});

it('forgets the source', function (): void {
    Configuration::provide(static fn (): array => []);
    Configuration::forget();

    expect(Configuration::exists())->toBeFalse();
});
