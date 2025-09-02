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
 * Session-based pseudo-mapper
 */
class Basket extends Magic
{
    //region Error messages
    const
        E_Field = 'Undefined field %s';
    //endregion

    // Current item identifier
    protected string|int|null $id = null;
    // Current item contents
    protected array $item = [];

    /**
     * Return TRUE if field is defined
     */
    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->item);
    }

    /**
     * Assign value to field
     */
    public function set(string $key, $val): mixed
    {
        return ($key == '_id') ? false : ($this->item[$key] = $val);
    }

    /**
     * Retrieve value of field
     */
    public function &get(string $key): mixed
    {
        if ($key == '_id')
            return $this->id;
        if (array_key_exists($key, $this->item))
            return $this->item[$key];
        throw new \Exception(sprintf(self::E_Field, $key));
    }

    /**
     * Delete field
     */
    public function clear(string $key): void
    {
        unset($this->item[$key]);
    }

    /**
     * Return items that match key/value pair;
     * If no key/value pair specified, return all items
     * @return static[]
     */
    public function find(?string $key = null, mixed $val = null): array
    {
        $out = [];
        if (isset($_SESSION[$this->key])) {
            foreach ($_SESSION[$this->key] as $id => $item)
                if (!isset($key) ||
                    array_key_exists($key, $item) && $item[$key] == $val ||
                    $key == '_id' && $id == $val) {
                    $obj = clone($this);
                    $obj->id = $id;
                    $obj->item = $item;
                    $out[] = $obj;
                }
        }
        return $out;
    }

    /**
     * Return first item that matches key/value pair
     */
    public function findOne($key, $val): ?static
    {
        return ($data = $this->find($key, $val)) ? $data[0] : null;
    }

    /**
     * Map current item to matching key/value pair
     */
    public function load(string $key, mixed $val): ?array
    {
        if ($found = $this->find($key, $val)) {
            $this->id = $found[0]->id;
            return $this->item = $found[0]->item;
        }
        $this->reset();
        return null;
    }

    /**
     * Return TRUE if current item is empty/undefined
     */
    public function dry(): bool
    {
        return !$this->item;
    }

    /**
     *
     * Return number of items in basket
     */
    public function count(): int
    {
        return isset($_SESSION[$this->key]) ? count($_SESSION[$this->key]) : 0;
    }

    /**
     * Save current item
     */
    public function save(): array
    {
        if (!$this->id)
            $this->id = uniqid('', true);
        $_SESSION[$this->key][$this->id] = $this->item;
        return $this->item;
    }

    /**
     * Erase item matching key/value pair
     */
    public function erase(string $key, mixed $val): bool
    {
        $found = $this->find($key, $val);
        if ($found && $id = $found[0]->id) {
            unset($_SESSION[$this->key][$id]);
            if ($id == $this->id)
                $this->reset();
            return true;
        }
        return false;
    }

    /**
     * Reset cursor
     */
    public function reset(): void
    {
        $this->id = null;
        $this->item = [];
    }

    /**
     * Empty basket
     */
    public function drop(): void
    {
        unset($_SESSION[$this->key]);
    }

    /**
     * Hydrate item using hive array variable
     * @param array|string $var assoc-array or string of hive key that hold an assoc-array
     */
    public function copyFrom(array|string $var): void
    {
        if (is_string($var))
            $var = Base::instance()->$var;
        foreach ($var as $key => $val)
            $this->set($key, $val);
    }

    /**
     * Populate hive array variable with item contents
     */
    public function copyTo(string $key): void
    {
        $var =& Base::instance()->ref($key);
        foreach ($this->item as $key => $field)
            $var[$key] = $field;
    }

    /**
     * Check out basket contents
     */
    public function checkout(): array
    {
        if (isset($_SESSION[$this->key])) {
            $out = $_SESSION[$this->key];
            unset($_SESSION[$this->key]);
            return $out;
        }
        return [];
    }

    public function __construct(
        // Session key
        protected string $key = 'basket',
    ) {
        if (session_status() != PHP_SESSION_ACTIVE)
            session_start();
        Base::instance()->sync('SESSION');
        $this->reset();
    }

}
