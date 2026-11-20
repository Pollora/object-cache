<?php

declare(strict_types=1);

use Brain\Monkey;
use Pollora\ObjectCache\Tests\Feature\FeatureTestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| WordPress functions are stubbed with Brain Monkey, and Mockery's expectations
| count as assertions. The Feature tests boot a Laravel application with
| Testbench, to test the service provider and the commands.
|
*/

pest()->extend(FeatureTestCase::class)->in('Feature');

pest()->beforeEach(function (): void {
    Monkey\setUp();
})->afterEach(function (): void {
    if ($container = Mockery::getContainer()) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    Monkey\tearDown();
})->in('Unit', 'Feature');
