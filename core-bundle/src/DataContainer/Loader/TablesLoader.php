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
use Symfony\Component\Config\Loader\FileLoader;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;
use Webmozart\PathUtil\Path;

/**
 * The purpose of this loader is to extract the table name from the Data Container.
 */
class TablesLoader extends FileLoader {

    public function load($resource, string $type = null)
    {
        return Path::getFilenameWithoutExtension($resource);
    }

    public function supports($resource, string $type = null): bool
    {
        return is_string($resource);
    }
}
