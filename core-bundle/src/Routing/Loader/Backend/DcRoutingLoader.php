<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Routing\Loader\Backend;

use Contao\Controller;
use Contao\CoreBundle\Controller\BackendDcController;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class DcRoutingLoader extends Loader
{
    private bool $isLoaded = false;
    private ContaoFramework $framework;

    public function __construct(ContaoFramework $framework)
    {
        parent::__construct();

        $this->framework = $framework;
    }

    public function load($resource, string $type = null)
    {
        if (true === $this->isLoaded) {
            throw new \RuntimeException('Do not add the "contao_data_container" loader twice');
        }

        $this->framework->initialize();

        $routes = new RouteCollection();

        foreach ($GLOBALS['BE_MOD'] as $modules) {
            foreach ($modules as $module => $moduleConfig) {
                $tables = $moduleConfig['tables'] ?? [];

                if (empty($tables)) {
                    continue;
                }

                foreach ($tables as $table) {
                    Controller::loadDataContainer($table);
                }

                $table = array_shift($tables);

                $routes->add(sprintf('contao_%s_showAll', $module), $this->addRoute($module, 'showAll'));
                $routes->add(sprintf('contao_%s_edit', $module), $this->addRoute($module, 'edit', true));

                foreach ($tables as $table) {
                    $routes->add(sprintf('contao_%s_%s_showAll', $module, $table), $this->addRoute($module, 'showAll', false, $table));
                    $routes->add(sprintf('contao_%s_%s_edit', $module, $table), $this->addRoute($module, 'edit', true, $table));
                }
            }
        }

        $this->isLoaded = true;

        return $routes;
    }

    public function supports($resource, string $type = null): bool
    {
        return 'contao_data_container' === $type;
    }

    private function addRoute(string $module, string $action, bool $hasId = false, string $ctable = null): Route
    {
        $defaults = [
            'module' => $module,
            'table' => $ctable,
            'action' => $action,
            '_scope' => 'backend',
            '_token_check' => true,
        ];
        $requirements = [
            'id' => '\d+',
        ];

        switch (true) {
            case $ctable && $hasId:
                $defaults += ['_controller' => BackendDcController::class.'::childTableItemAction'];
                $path = sprintf('/contao/%s/%s/{id}', $module, $ctable);
                break;

            case $ctable:
                $defaults += ['_controller' => BackendDcController::class.'::childTableListAction'];
                $path = sprintf('/contao/%s/{id}/%s', $module, $ctable);
                break;

            case $hasId:
                $defaults += ['_controller' => BackendDcController::class.'::itemAction'];
                $path = sprintf('/contao/%s/{id}', $module);
                break;

            default:
                $defaults += ['_controller' => BackendDcController::class.'::listAction'];
                $path = sprintf('/contao/%s', $module);
        }

        return new Route($path, $defaults, $requirements);
    }
}
