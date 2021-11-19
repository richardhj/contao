<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Menu;

class BackendModules
{
    private array $modules = [];

    public function addModule(string $category, string $name, array $module)
    {
        $this->modules[$category][$name] = $module;
    }

    public function getModules(): array
    {
        return $this->modules;
    }
}
