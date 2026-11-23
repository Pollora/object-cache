<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\Prefetch\IncompleteObjects;

function missingClassObject(): mixed
{
    return unserialize('O:20:"Not_Loaded_Anywhere_":1:{s:1:"a";i:1;}');
}

it('sees an object whose class is missing', function (): void {
    expect(IncompleteObjects::in(missingClassObject()))->toBeTrue();
});

it('sees one nested in an array or in another object', function (): void {
    $holder = new stdClass;
    $holder->inner = ['deep' => [missingClassObject()]];

    expect(IncompleteObjects::in(['a' => 1, 'b' => [missingClassObject()]]))->toBeTrue()
        ->and(IncompleteObjects::in($holder))->toBeTrue();
});

it('sees one held by a private property', function (): void {
    $holder = new class(missingClassObject())
    {
        public function __construct(private readonly mixed $hidden) {}
    };

    expect(IncompleteObjects::in($holder))->toBeTrue();
});

it('finds nothing in scalars, arrays and objects of loaded classes', function (): void {
    $object = new stdClass;
    $object->list = [1, 'two', 3.0, null, false];

    expect(IncompleteObjects::in('text'))->toBeFalse()
        ->and(IncompleteObjects::in(42))->toBeFalse()
        ->and(IncompleteObjects::in(null))->toBeFalse()
        ->and(IncompleteObjects::in([]))->toBeFalse()
        ->and(IncompleteObjects::in($object))->toBeFalse();
});

it('ends on a cycle of objects', function (): void {
    $a = new stdClass;
    $b = new stdClass;
    $a->other = $b;
    $b->other = $a;

    expect(IncompleteObjects::in($a))->toBeFalse();

    $b->bad = missingClassObject();

    expect(IncompleteObjects::in($a))->toBeTrue();
});
