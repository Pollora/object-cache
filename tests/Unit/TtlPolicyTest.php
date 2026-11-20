<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\TtlPolicy;

it('keeps a time to live as it is when there is no maximum', function (int $ttl, int $expected): void {
    expect((new TtlPolicy)->normalize($ttl))->toBe($expected);
})->with([
    'never' => [0, 0],
    'an hour' => [3600, 3600],
    'negative' => [-1, 0],
]);

it('caps the time to live at the maximum', function (int $ttl, int $expected): void {
    expect((new TtlPolicy(maximum: 60))->normalize($ttl))->toBe($expected);
})->with([
    'never becomes the maximum' => [0, 60],
    'negative becomes the maximum' => [-5, 60],
    'under the maximum' => [10, 10],
    'at the maximum' => [60, 60],
    'over the maximum' => [3600, 60],
]);

it('treats a maximum of zero or less as no limit', function (): void {
    expect((new TtlPolicy(maximum: -1))->normalize(0))->toBe(0)
        ->and((new TtlPolicy(maximum: 0))->normalize(500))->toBe(500);
});
