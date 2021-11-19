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

/**
 * Loads DCA files that are in YAML format.
 */
class YamlDcaLoader extends FileLoader {

    public function load($resource, string $type = null)
    {
        $configValues = Yaml::parse(file_get_contents($resource));

        return $configValues;
    }

    public function supports($resource, string $type = null)
    {
        return is_string($resource) && in_array(pathinfo($resource, PATHINFO_EXTENSION), ['yaml', 'yml'], true);
    }
}
