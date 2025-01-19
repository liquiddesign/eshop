<?php

namespace Eshop\Services;

use Nette\Caching\Cache;
use Nette\Caching\Storage;

readonly class LocalCache
{
	public const PRODUCTS = 'products';

	private Cache $cache;

	public function __construct(Storage $storage)
	{
		$this->cache = new Cache($storage);
	}

	public function getCache(): Cache
	{
		return $this->cache;
	}
}