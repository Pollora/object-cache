<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Infrastructure\Redis\ValueCodec;

it('writes an integer as its decimal text, so Redis can do arithmetic on it', function (): void {
    $codec = new ValueCodec;

    expect($codec->encode(42))->toBe('42')
        ->and($codec->encode(-7))->toBe('-7')
        ->and($codec->encode(PHP_INT_MAX))->toBe((string) PHP_INT_MAX);
});

it('tags every other scalar', function (): void {
    $codec = new ValueCodec;

    expect($codec->encode('5'))->toBe('s:5')
        ->and($codec->encode(''))->toBe('s:')
        ->and($codec->encode(1.5))->toBe('d:1.5')
        ->and($codec->encode(2.0))->toBe('d:2')
        ->and($codec->encode(null))->toBe('n:')
        ->and($codec->encode(true))->toBe('b:1')
        ->and($codec->encode(false))->toBe('b:0');
});

it('serializes arrays and objects, and says with which serializer', function (): void {
    expect((new ValueCodec)->encode(['a' => 1]))->toBe('x:p:a:1:{s:1:"a";i:1;}');

    if (function_exists('igbinary_serialize')) {
        expect((new ValueCodec(ValueCodec::SERIALIZER_IGBINARY))->encode(['a' => 1]))->toStartWith('x:i:');
    }
});

it('reads back what it wrote, with the same type', function (mixed $value): void {
    $codec = new ValueCodec;
    $decoded = $codec->decode($codec->encode($value));

    expect($decoded)->toBeInstanceOf(CacheValue::class)
        ->and($decoded->value)->toEqual($value)
        ->and(get_debug_type($decoded->value))->toBe(get_debug_type($value));
})->with([
    'int' => [42],
    'zero' => [0],
    'min int' => [PHP_INT_MIN],
    'float' => [1.5],
    'whole float' => [2.0],
    'infinity' => [INF],
    'string' => ['text'],
    'numeric string' => ['5'],
    'empty string' => [''],
    'null' => [null],
    'true' => [true],
    'false' => [false],
    'array' => [['a' => [1, 2], 'b' => null]],
    'object' => [new ArrayObject([1, 2])],
]);

it('reads back what igbinary wrote, and what PHP wrote before the switch', function (): void {
    if (! function_exists('igbinary_serialize')) {
        $this->markTestSkipped('igbinary is not installed');
    }

    $igbinary = new ValueCodec(ValueCodec::SERIALIZER_IGBINARY);
    $php = new ValueCodec;

    expect($igbinary->decode($igbinary->encode(['a' => 1]))->value)->toBe(['a' => 1])
        ->and($igbinary->decode($php->encode(['a' => 1]))->value)->toBe(['a' => 1])
        ->and($php->decode($igbinary->encode(['a' => 1]))->value)->toBe(['a' => 1]);
});

it('takes what it cannot read for a miss', function (string $stored): void {
    expect((new ValueCodec)->decode($stored))->toBeNull();
})->with([
    'nothing' => [''],
    'unknown tag' => ['q:1'],
    'bad boolean' => ['b:2'],
    'bad float' => ['d:abc'],
    'a string where a float must be' => ['d:s:3:"abc";'],
    'cut-short serialization' => ['x:p:a:2:{s:1:"a";'],
    'serialized false is not an array' => ['x:p:b:0;'],
    'unknown serializer' => ['x:z:abc'],
    'garbage' => ['not a value'],
    'a serialized scalar where an array must be' => ['x:p:i:5;'],
]);

it('keeps a serialized false apart from a failure', function (): void {
    $codec = new ValueCodec;

    expect($codec->decode($codec->encode(false)))->toBeInstanceOf(CacheValue::class);
});

it('builds objects of any class by default, and of none when told so', function (): void {
    $stored = (new ValueCodec)->encode(new ArrayObject([1]));

    expect((new ValueCodec)->decode($stored)->value)->toBeInstanceOf(ArrayObject::class)
        ->and((new ValueCodec(allowedClasses: false))->decode($stored)->value)->not->toBeInstanceOf(ArrayObject::class)
        ->and((new ValueCodec(allowedClasses: [ArrayObject::class]))->decode($stored)->value)->toBeInstanceOf(ArrayObject::class)
        ->and((new ValueCodec(allowedClasses: [stdClass::class]))->decode($stored)->value)->not->toBeInstanceOf(ArrayObject::class);
});

it('refuses an unknown serializer', function (): void {
    expect(fn () => new ValueCodec('json'))->toThrow(InvalidArgumentException::class, 'Unknown object cache serializer "json"');
});

it('raises no warning on a payload it cannot read', function (string $stored): void {
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        (new ValueCodec)->decode($stored);
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toBe([]);
})->with(['cut short' => ['x:p:a:2:{s:1:"a";'], 'not serialized' => ['x:p:nonsense'], 'bad float' => ['d:abc']]);

it('swallows the warning of a corrupt value, and gives the error handler back', function (): void {
    $seen = [];
    $previous = set_error_handler(static function (int $level, string $message) use (&$seen): bool {
        $seen[] = $message;

        return true;
    });

    $display = ini_set('display_errors', '1');
    ob_start();

    try {
        $value = (new ValueCodec)->decode('x:p:a:1:{broken');
        trigger_error('after', E_USER_WARNING);
    } finally {
        $output = ob_get_clean();
        ini_set('display_errors', (string) $display);
        restore_error_handler();
    }

    expect($value)->toBeNull()
        ->and($output)->toBe('')
        ->and($seen)->toBe(['after']);
});
