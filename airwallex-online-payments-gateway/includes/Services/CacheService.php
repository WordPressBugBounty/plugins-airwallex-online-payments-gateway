<?php

namespace Airwallex\Services;

use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheInterface;

class CacheService implements CacheInterface {

	const PREFIX = 'awx_';

	/**
	 * Prefix of the cache key
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Set the prefix according to the salt provided
	 *
	 * @param string $salt
	 */
	public function __construct( $salt = '' ) {
		$this->prefix = self::PREFIX . ( $salt ? md5( $salt ) : '' ) . '_';
	}

	/**
	 * Set/update the value of a cache key
	 *
	 * @param string $key
	 * @param $value
	 * @param int $maxAge
	 * @return bool
	 */
	public function set(string $key, $value, int $maxAge = 7200 ): bool {
		return set_transient( $this->prefix . $key, $value, $maxAge );
	}

	/**
	 * Get cache value according to cache key
	 *
	 * @param string $key
	 * @return mixed|null
	 */
	public function get(string $key ) {
		$return = get_transient( $this->prefix . $key );
		return false === $return ? null : $return;
	}

	/**
	 * Remove cache value according to cache key
	 *
	 * @param string $key
	 * @return bool True if the cache was deleted, false otherwise.
	 */
	public function remove( string $key ): bool {
		return delete_transient( $this->prefix . $key );
	}
}
