<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\WordPress\RequestSignature;

function signature(string $uri, string $method = 'GET', array $cookies = [], bool $dynamic = false, string $sapi = 'fpm-fcgi', int $blog = 1): ?string
{
    return RequestSignature::resolve($sapi, ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri], $cookies, $dynamic, $blog);
}

it('keeps one profile per first segment of the path, query string ignored', function (): void {
    expect(signature('/shop/a-product/?x=1'))->toBe('front:1:shop')
        ->and(signature('/shop/'))->toBe('front:1:shop')
        ->and(signature('/Blog/post'))->toBe('front:1:blog')
        ->and(signature('/'))->toBe('front:1:_home')
        ->and(signature('/?s=term'))->toBe('front:1:_home');
});

it('groups odd segments together instead of making a profile for each', function (): void {
    expect(signature('/'.str_repeat('a', 41).'/x'))->toBe('front:1:_other')
        ->and(signature('/caf%C3%A9/x'))->toBe('front:1:_other')
        ->and(signature('//x'))->toBe('front:1:_home');
});

it('tells a signed-in visitor from an anonymous one', function (): void {
    expect(signature('/shop/', cookies: ['wordpress_logged_in_abc' => 'x']))->toBe('front:1:shop:member')
        ->and(signature('/shop/', cookies: ['wordpress_test_cookie' => 'x']))->toBe('front:1:shop');
});

it('keeps the blogs of a network apart', function (): void {
    expect(signature('/shop/', blog: 3))->toBe('front:3:shop');
});

it('takes no part for anything but a page of the front fetched with GET or HEAD', function (): void {
    expect(signature('/shop/', 'POST'))->toBeNull()
        ->and(signature('/shop/', 'PUT'))->toBeNull()
        ->and(signature('/shop/', 'HEAD'))->toBe('front:1:shop')
        ->and(signature('/shop/', dynamic: true))->toBeNull()
        ->and(signature('/shop/', sapi: 'cli'))->toBeNull()
        ->and(signature('/shop/', sapi: 'phpdbg'))->toBeNull()
        ->and(signature('/cms/wp-admin/index.php'))->toBeNull()
        ->and(signature('/cms/wp-login.php'))->toBeNull()
        ->and(signature('/wp-json/wp/v2/posts'))->toBeNull()
        ->and(signature('/cms/wp-cron.php'))->toBeNull()
        ->and(signature('/xmlrpc.php'))->toBeNull()
        ->and(signature('/wp-comments-post.php'))->toBeNull();
});

it('takes no part without a method or an address', function (): void {
    expect(RequestSignature::resolve('fpm-fcgi', [], [], false, 1))->toBeNull()
        ->and(RequestSignature::resolve('fpm-fcgi', ['REQUEST_METHOD' => 'GET'], [], false, 1))->toBe('front:1:_home');
});
