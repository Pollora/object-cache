<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\GroupRegistry;

it('knows no group until it is told', function (): void {
    $groups = new GroupRegistry;

    expect($groups->isGlobal('users'))->toBeFalse()
        ->and($groups->isNonPersistent('counts'))->toBeFalse();
});

it('registers global groups, one or several', function (): void {
    $groups = new GroupRegistry;
    $groups->addGlobal('users');
    $groups->addGlobal(['sites', 'networks']);

    expect($groups->isGlobal('users'))->toBeTrue()
        ->and($groups->isGlobal('sites'))->toBeTrue()
        ->and($groups->isGlobal('networks'))->toBeTrue()
        ->and($groups->isGlobal('posts'))->toBeFalse()
        ->and($groups->isNonPersistent('users'))->toBeFalse();
});

it('registers non-persistent groups, one or several', function (): void {
    $groups = new GroupRegistry;
    $groups->addNonPersistent('counts');
    $groups->addNonPersistent(['plugins', 'theme_json']);

    expect($groups->isNonPersistent('counts'))->toBeTrue()
        ->and($groups->isNonPersistent('plugins'))->toBeTrue()
        ->and($groups->isNonPersistent('theme_json'))->toBeTrue()
        ->and($groups->isNonPersistent('posts'))->toBeFalse()
        ->and($groups->isGlobal('counts'))->toBeFalse();
});

it('covers a family of groups with a trailing wildcard', function (): void {
    $groups = new GroupRegistry;
    $groups->addNonPersistent('wc_cache_*');
    $groups->addGlobal('net_*');

    expect($groups->isNonPersistent('wc_cache_orders_1'))->toBeTrue()
        ->and($groups->isNonPersistent('wc_cache_'))->toBeTrue()
        ->and($groups->isNonPersistent('wc_cache'))->toBeFalse()
        ->and($groups->isNonPersistent('xwc_cache_orders'))->toBeFalse()
        ->and($groups->isGlobal('net_options'))->toBeTrue()
        ->and($groups->isGlobal('options'))->toBeFalse();
});

it('does not read a wildcard in the middle of a name', function (): void {
    $groups = new GroupRegistry;
    $groups->addGlobal('a*b');

    expect($groups->isGlobal('axb'))->toBeFalse()
        ->and($groups->isGlobal('a*b'))->toBeTrue();
});
