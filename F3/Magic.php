<?php

/**
 *
 * Copyright (c) 2025 F3::Factory, All rights reserved.
 *
 * This file is part of the Fat-Free Framework (https://fatfreeframework.com).
 *
 * This is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or later.
 *
 * Fat-Free Framework is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with Fat-Free Framework. If not, see <http://www.gnu.org/licenses/>.
 */

namespace F3;

/**
 * PHP magic wrapper
 */
abstract class Magic implements \ArrayAccess
{
    /**
     * Return TRUE if key is not empty
     */
    abstract public function exists(string $key): bool;

    /**
     * Bind value to key
     */
    abstract public function set(string $key, mixed $val): mixed;

    /**
     * Retrieve contents of key
     */
    abstract public function &get(string $key): mixed;

    /**
     * Unset key
     */
    abstract public function clear(string $key): void;

    /**
     * Convenience method for checking property value
     */
    public function offsetExists(mixed $offset): bool
    {
        return Base::instance()->visible($this, $offset)
            ? isset($this->$offset)
            : ($this->exists($offset) && $this->get($offset) !== null);
    }

    /**
     * Convenience method for assigning property value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        Base::instance()->visible($this, $offset)
            ? ($this->$offset = $value)
            : $this->set($offset, $value);
    }

    /**
     * Convenience method for retrieving property value
     */
    public function &offsetGet(mixed $offset): mixed
    {
        if (Base::instance()->visible($this, $offset))
            $val =& $this->$offset;
        else
            $val =& $this->get($offset);
        return $val;
    }

    /**
     * Convenience method for removing property value
     */
    public function offsetUnset(mixed $offset): void
    {
        if (Base::instance()->visible($this, $offset))
            unset($this->$offset);
        else
            $this->clear($offset);
    }

    /**
     * Alias for offsetExists()
     */
    public function __isset(mixed $key)
    {
        return $this->offsetExists($key);
    }

    /**
     * Alias for offsetSet()
     */
    public function __set(mixed $key, mixed $val): void
    {
        $this->offsetSet($key, $val);
    }

    /**
     * Alias for offsetGet()
     */
    public function &__get(mixed $key): mixed
    {
        $val =& $this->offsetGet($key);
        return $val;
    }

    /**
     * Alias for offsetUnset()
     **/
    public function __unset(mixed $key)
    {
        $this->offsetUnset($key);
    }

}
