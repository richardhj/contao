<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\DataContainer\Loader;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Loads the DCA from/into a volatile cache.
 */
class DcaLoader {

    private const CACHE_KEY = 'contao.dca';

    private Dca $dca;

    private CacheItemPoolInterface $cachePool;

    public function __construct(CacheItemPoolInterface $cachePool)
    {
        $this->cachePool = $cachePool;

        // Restore from cache
        $dca = $cachePool->getItem(self::CACHE_KEY);

        if ($dca->isHit()) {
            $this->dca = $dca->get();
        } else {
            $this->dca = new Dca();
        }
    }

    public function getDca(): Dca
    {
        return $this->dca;
    }

    public function addConfig(string $table, array $config)
    {
        $this->dca->add($table, $config);
    }

    public function appendConfig(string $table, array $config)
    {
        $this->dca->merge($table, $config);
    }

    public function clear()
    {
        $this->dca->clear();
    }

    public function persist(): void
    {
        $dca = $this->cachePool->getItem(self::CACHE_KEY);
        $dca->set($this->dca);

        $this->cachePool->save($dca);
    }
}
