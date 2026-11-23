<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Redis;

use InvalidArgumentException;
use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Domain\Contracts\CacheStore;
use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Redis;
use RedisException;

/**
 * The persistent store, on Redis.
 *
 * `add` and `replace` are `SET` with `NX` and `XX`, and `adjust` is one Lua
 * script, so none of them can race between a read and a write. Values are
 * written by `ValueCodec`. A flush walks the keys of its prefix with `SCAN` and
 * removes them with `UNLINK` in batches: it never empties the database, which
 * on a shared Redis would be somebody else's cache.
 *
 * Any Redis error (the server is down, out of memory, read-only, the command
 * timed out) is reported as `StoreUnavailableException`, and the client is
 * dropped so the next call reconnects. The object cache then runs on its
 * request cache for the rest of the request.
 */
final class RedisCacheStore implements CacheStore
{
    /**
     * Adds a signed amount to a cached number, as `WP_Object_Cache::incr()` does.
     *
     * KEYS[1] is the key and ARGV[1] the amount. A missing key answers nil. A
     * number is read in the formats of `ValueCodec` (a decimal integer, `d:` and
     * `s:` followed by a numeric text); anything else counts as 0. The result
     * never goes below 0 and the time to live of the key is kept. The answer is
     * the stored text, which PHP decodes, so an integer keeps its exact value
     * (`INCRBY` does the integer arithmetic, not Lua's floating point). An
     * integer that would overflow becomes a float, as in PHP.
     */
    private const string ADJUST_SCRIPT = <<<'LUA'
local key = KEYS[1]
local delta = ARGV[1]
local stored = redis.call('GET', key)
if not stored then return false end
local ttl = redis.call('PTTL', key)

local function put(text)
  if ttl > 0 then redis.call('PSETEX', key, ttl, text) else redis.call('SET', key, text) end
  return text
end

local function fits(sign, digits)
  if #digits < 19 then return true end
  if #digits > 19 then return false end
  if sign == '-' then return digits <= '9223372036854775808' end
  return digits <= '9223372036854775807'
end

-- Reads a numeric text the way PHP's is_numeric() does: 'int' with its
-- normalised text, 'float' with its value, or nothing.
local function numeric(text)
  local t = text:match('^%s*(.-)%s*$')
  local sign, digits = t:match('^([+-]?)(%d+)$')
  if digits then
    digits = digits:gsub('^0+', '')
    if digits == '' then digits = '0' end
    if fits(sign, digits) then
      if sign == '-' and digits ~= '0' then return 'int', '-' .. digits end
      return 'int', digits
    end
    return 'float', tonumber(sign .. digits)
  end
  local sign2, rest = t:match('^([+-]?)(.+)$')
  if not rest then return nil end
  local mantissa, exponent = rest:match('^([^eE]+)[eE]([+-]?%d+)$')
  if not mantissa then mantissa = rest end
  if not (mantissa:match('^%d+%.?%d*$') or mantissa:match('^%.%d+$')) then return nil end
  local number = tonumber(sign2 .. mantissa .. (exponent and ('e' .. exponent) or ''))
  if not number then return nil end
  return 'float', number
end

local kind, value
if stored:match('^-?%d+$') then
  kind, value = 'int', stored
elseif stored:sub(1, 2) == 'd:' then
  local k, n = numeric(stored:sub(3))
  if k then kind, value = 'float', tonumber(n) end
elseif stored:sub(1, 2) == 's:' then
  kind, value = numeric(stored:sub(3))
end

if not kind then
  if delta:sub(1, 1) == '-' then return put('0') end
  return put(delta)
end

local number
if kind == 'int' then
  if value ~= stored then put(value) end
  local result = redis.pcall('INCRBY', key, delta)
  if type(result) == 'table' then
    number = tonumber(value) + tonumber(delta)
  else
    local text = redis.call('GET', key)
    if text:sub(1, 1) == '-' then return put('0') end
    return text
  end
else
  number = value + tonumber(delta)
end

if number < 0 then return put('0') end
return put('d:' .. string.format('%.17g', number))
LUA;

    private ?Redis $client = null;

    /**
     * @param  RedisConnector  $connector  Opens the connection on the first command.
     * @param  ValueCodec  $codec  Writes and reads the values.
     */
    public function __construct(
        private readonly RedisConnector $connector,
        private readonly ValueCodec $codec = new ValueCodec,
    ) {}

    /**
     * Read one entry.
     */
    public function get(string $key): ?CacheValue
    {
        $stored = $this->run(fn (Redis $redis): mixed => $this->checked($redis, $redis->get($key)));

        return is_string($stored) ? $this->codec->decode($stored) : null;
    }

    /**
     * Read several entries with one `MGET`.
     *
     * @param  list<string>  $keys
     * @return array<string, CacheValue>
     */
    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $stored = $this->run(fn (Redis $redis): mixed => $this->checked($redis, $redis->mget($keys)));
        $found = [];

        foreach ($keys as $index => $key) {
            $value = is_array($stored) && is_string($stored[$index] ?? false) ? $this->codec->decode($stored[$index]) : null;

            if ($value instanceof CacheValue) {
                $found[$key] = $value;
            }
        }

        return $found;
    }

    /**
     * Write an entry, replacing any existing one.
     */
    public function set(string $key, mixed $value, int $ttl): bool
    {
        return $this->write($key, $value, $ttl, []);
    }

    /**
     * Write an entry only if the key is absent (`SET NX`).
     */
    public function add(string $key, mixed $value, int $ttl): bool
    {
        return $this->write($key, $value, $ttl, ['nx']);
    }

    /**
     * Write an entry only if the key exists (`SET XX`).
     */
    public function replace(string $key, mixed $value, int $ttl): bool
    {
        return $this->write($key, $value, $ttl, ['xx']);
    }

    /**
     * Remove an entry.
     */
    public function delete(string $key): bool
    {
        return $this->run(fn (Redis $redis): bool => $this->checked($redis, $redis->unlink($key)) > 0);
    }

    /**
     * Add a signed amount to a number, in one script.
     *
     * @return int|float|false The new value, or false when the key is absent.
     */
    public function adjust(string $key, int $delta): int|float|false
    {
        $stored = $this->run(fn (Redis $redis): mixed => $this->checked($redis, $redis->eval(self::ADJUST_SCRIPT, [$key, $delta], 1)));
        $value = is_string($stored) ? $this->codec->decode($stored) : null;

        return $value instanceof CacheValue && (is_int($value->value) || is_float($value->value)) ? $value->value : false;
    }

    /**
     * Remove every entry whose key starts with the prefix.
     *
     * @throws InvalidArgumentException When the prefix is empty.
     */
    public function deleteByPrefix(string $prefix): int
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('A prefix flush needs a prefix: an empty one would empty the whole database.');
        }

        $pattern = addcslashes($prefix, '*?[]\\').'*';

        return $this->run(function (Redis $redis) use ($pattern): int {
            $removed = 0;
            $cursor = null;

            do {
                $keys = $redis->scan($cursor, $pattern, 1000);

                if (is_array($keys) && $keys !== []) {
                    $removed += (int) $redis->unlink($keys);
                }
            } while ($cursor !== 0 && $cursor !== null);

            return $removed;
        });
    }

    /**
     * @param  list<string>  $options  `nx` or `xx`, or none.
     */
    private function write(string $key, mixed $value, int $ttl, array $options): bool
    {
        $encoded = $this->codec->encode($value);

        if ($ttl > 0) {
            $options['ex'] = $ttl;
        }

        return $this->run(fn (Redis $redis): bool => $this->checked($redis, $redis->set($key, $encoded, $options === [] ? null : $options)) === true);
    }

    /**
     * Run a command on the client, connecting first when needed.
     *
     * @template T
     *
     * @param  callable(Redis): T  $operation
     * @return T
     *
     * @throws StoreUnavailableException
     */
    private function run(callable $operation): mixed
    {
        try {
            $this->client ??= $this->connector->connect();

            return $operation($this->client);
        } catch (RedisException $redisException) {
            $this->client = null;

            throw new StoreUnavailableException($redisException->getMessage(), 0, $redisException);
        }
    }

    /**
     * A phpredis command that failed without throwing answers false and leaves
     * the message behind; report it as the error it is.
     *
     * @throws RedisException
     */
    private function checked(Redis $redis, mixed $result): mixed
    {
        if ($result === false && ($error = $redis->getLastError()) !== null) {
            $redis->clearLastError();

            throw new RedisException($error);
        }

        return $result;
    }
}
