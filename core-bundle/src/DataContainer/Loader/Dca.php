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

/**
 * Holds the Data Container configs, i.e., the DCA.
 */
class Dca implements \Serializable, \ArrayAccess {

    /** @var array<string, array> */
    private array $configs = [];

    public function add(string $table, array $config)
    {
        $this->configs[$table] = $config;
    }

    public function merge(string $table, array $config)
    {
        $this->configs[$table] = array_replace_recursive($this->configs[$table] ?? [], $config);
    }

    public function clear()
    {
        $this->configs = [];
    }

    public function serialize(): string
    {
        return serialize($this->configs);
    }

    public function unserialize($data): void
    {
        $this->configs = unserialize($data);
    }

    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->configs);
    }

    public function offsetGet($offset)
    {
        return $this->configs[$offset] ?? null;
    }

    public function offsetSet($offset, $value)
    {
        $this->merge($offset, $value);
    }

    public function offsetUnset($offset)
    {
        throw new \BadFunctionCallException('Unsetting DCA is not allowed by array access.');
    }
}
