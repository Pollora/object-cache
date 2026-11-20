<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Redis;

/**
 * A client that fails the way an old phpredis does: a command answers false and
 * leaves its message in getLastError(), instead of throwing.
 */
final class FailingRedis extends Redis
{
    public ?string $error = 'OOM command not allowed when used memory > maxmemory';

    public int $errorsCleared = 0;

    public function get(string $key): mixed
    {
        return false;
    }

    public function getLastError(): ?string
    {
        return $this->error;
    }

    public function clearLastError(): bool
    {
        $this->errorsCleared++;
        $this->error = null;

        return true;
    }
}
