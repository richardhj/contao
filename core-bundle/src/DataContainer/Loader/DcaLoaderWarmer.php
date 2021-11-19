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

use Contao\CoreBundle\DataContainer\Config\DcaConfiguration;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\GlobFileLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Loads the DCA on cache warmup. Triggers the DcaLoader.
 */
class DcaLoaderWarmer implements CacheWarmerInterface
{
    private FileLocatorInterface $resourceLocator;
    private DcaLoader $dcaLoader;
    private string $environment;
    private ContaoFramework $framework;

    public function __construct(FileLocatorInterface $resourceLocator, DcaLoader $dcaLoader, string $environment, ContaoFramework $framework)
    {
        $this->resourceLocator = $resourceLocator;
        $this->dcaLoader = $dcaLoader;
        $this->environment = $environment;
        $this->framework = $framework;
    }

    public function warmUp($cacheDir = null): array
    {
        $this->framework->initialize();

        // First, fetch all tables we have DCAs for
        $loaderResolver = new LoaderResolver([
            new GlobFileLoader($this->resourceLocator),
            new TablesLoader($this->resourceLocator),
        ]);
        $loader = new DelegatingLoader($loaderResolver);

        $tables = $loader->import('dca/*.{php,xml,yaml,yml}', 'glob');

        // Then, foreach table, load the DCA
        $loaderResolver = new LoaderResolver([
            new GlobFileLoader($this->resourceLocator),
            new YamlDcaLoader($this->resourceLocator),
        ]);
        $loader = new DelegatingLoader($loaderResolver);

        foreach (array_unique($tables) as $table) {
            // First, load the legacy DCA
            // We do not validate the legacy DCA and just add it to the DCA holder
            $dcaLoader = new \Contao\DcaLoader($table);
            $dcaLoader->load();

            $this->dcaLoader->addConfig($table, $GLOBALS['TL_DCA'][$table] ?? []);

            // Then, load the DCA in new config format,
            // validate it, and add it to the DCA holder
            $dca = $loader->import("dca/{$table}.{xml,yaml,yml}", 'glob');

            $config = (new Processor())->processConfiguration((new DcaConfiguration()), [$dca]);

            $this->dcaLoader->appendConfig($table, $config);
        }

        // Single point of truth
        $GLOBALS['TL_DCA'] = $this->dcaLoader->getDca();

        $this->dcaLoader->persist();

        return [];
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function refresh(): void
    {
        $this->dcaLoader->clear();

        $this->warmUp();
    }

    /**
     * Auto refresh in dev mode.
     */
    public function onKernelRequest(): void
    {
        if ('dev' === $this->environment) {
            $this->refresh();
        }
    }
}
