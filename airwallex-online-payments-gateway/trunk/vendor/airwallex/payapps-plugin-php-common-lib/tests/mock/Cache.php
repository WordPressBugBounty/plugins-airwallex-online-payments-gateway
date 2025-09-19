<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\mock;

use Airwallex;
use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheInterface;

class Cache implements CacheInterface
{
    /**
     * @var array
     */
    public $cache = [];

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->cache[$key] ?? null;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     *
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        $this->cache[$key] = $value;
        return true;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function remove(string $key): bool
    {
        unset($this->cache[$key]);
        return true;
    }
}