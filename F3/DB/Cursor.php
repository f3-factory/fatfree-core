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

namespace F3\DB;

use F3\Magic;

/**
 * Simple cursor implementation
 */
abstract class Cursor extends Magic implements \IteratorAggregate
{

    //region Error messages
    const
        E_Field = 'Undefined field %s';
    //endregion

    // Query results
    protected array $query = [];
    // Current position
    protected int $ptr = 0;
    // Event listeners
    protected array $trigger = [];

    /**
     * Return database type
     */
    abstract public function dbtype(): string;

    /**
     * Return field names
     */
    abstract public function fields(): array;

    /**
     * Return fields of mapper object as an associative array
     */
    abstract public function cast(?object $obj = null): array;

    /**
     * Return records (array of mapper objects) that match criteria
     * @return static[]
     */
    abstract public function find(
        array|string|null $filter = null,
        ?array $options = null,
        int|array $ttl = 0,
    ): array;

    /**
     * Count records that match criteria
     */
    abstract public function count(
        array|string|null $filter = null,
        ?array $options = null,
        int|array $ttl = 0,
    ): int;

    /**
     * Insert new record
     */
    abstract public function insert(): static;

    /**
     * Update current record
     */
    abstract public function update(): static;

    /**
     * Hydrate mapper object using hive array variable
     */
    abstract public function copyFrom(array|string $var, ?callable $func = null): void;

    /**
     * Populate hive array variable with mapper fields
     */
    abstract public function copyTo(string $key): void;

    /**
     * Get cursor's equivalent external iterator
     * returns ArrayIterator
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->cast());
    }

    /**
     * Return TRUE if current cursor position is not mapped to any record
     */
    public function dry(): bool
    {
        return empty($this->query[$this->ptr]);
    }

    /**
     * Return first record (mapper object) that matches criteria
     */
    public function findOne(
        array|string|null $filter = null,
        ?array $options = null,
        int $ttl = 0,
    ): ?static {
        if (!$options)
            $options = [];
        // Override limit
        $options['limit'] = 1;
        return ($data = $this->find($filter, $options, $ttl)) ? $data[0] : null;
    }

    /**
     * Return array containing subset of records matching criteria,
     * total number of records in superset, specified limit, number of
     * subsets available, and actual subset position
     */
    public function paginate(
        int $pos = 0,
        int $size = 10,
        array|string|null $filter = null,
        ?array $options = null,
        int $ttl = 0,
        bool $bounce = true,
    ): array {
        $total = $this->count($filter, $options, $ttl);
        $count = (int) ceil($total / $size);
        if ($bounce)
            $pos = max(0, min($pos, $count - 1));
        return [
            'subset' => ($bounce || $pos < $count) ? $this->find(
                $filter,
                array_merge(
                    $options ?: [],
                    ['limit' => $size, 'offset' => $pos * $size],
                ),
                $ttl,
            ) : [],
            'total' => $total,
            'limit' => $size,
            'count' => $count,
            'pos' => $bounce ? ($pos < $count ? $pos : 0) : $pos,
        ];
    }

    /**
     * Map to first record that matches criteria
     */
    public function load(
        string|array|null $filter = null,
        ?array $options = null,
        int $ttl = 0,
    ): ?static {
        $this->reset();
        return ($this->query = $this->find($filter, $options, $ttl))
        && $this->skip(0)
            ? $this->query[$this->ptr]
            : null;
    }

    /**
     * Return the count of records loaded
     */
    public function loaded(): int
    {
        return count($this->query);
    }

    /**
     * Map to first record in cursor
     */
    public function first(): ?static
    {
        return $this->skip(-$this->ptr);
    }

    /**
     * Map to last record in cursor
     */
    public function last(): ?static
    {
        return $this->skip(($ofs = count($this->query) - $this->ptr) ? $ofs - 1 : 0);
    }

    /**
     * Map to nth record relative to current cursor position
     */
    public function skip(int $ofs = 1): ?static
    {
        $this->ptr += $ofs;
        return $this->ptr > -1 && $this->ptr < count($this->query) ?
            $this->query[$this->ptr] : null;
    }

    /**
     * Map next record
     */
    public function next(): ?static
    {
        return $this->skip();
    }

    /**
     * Map previous record
     */
    public function prev(): ?static
    {
        return $this->skip(-1);
    }

    /**
     * Return whether current iterator position is valid.
     */
    public function valid(): bool
    {
        return !$this->dry();
    }

    /**
     * Save mapped record
     */
    public function save(): static
    {
        return $this->query ? $this->update() : $this->insert();
    }

    /**
     * Delete current record
     */
    public function erase(): int
    {
        $this->query = array_slice($this->query, 0, $this->ptr, true) +
            array_slice($this->query, $this->ptr, null, true);
        $this->skip(0);
        return 1;
    }

    /**
     * Define onload trigger
     */
    public function onLoad(callable $func): callable
    {
        return $this->trigger['load'] = $func;
    }

    /**
     * Define beforeInsert trigger
     */
    public function beforeInsert(callable $func): callable
    {
        return $this->trigger['beforeInsert'] = $func;
    }

    /**
     * Define afterInsert trigger
     */
    public function afterInsert(callable $func): callable
    {
        return $this->trigger['afterInsert'] = $func;
    }

    /**
     * Define onInsert trigger
     */
    public function onInsert(callable $func): callable
    {
        return $this->afterInsert($func);
    }

    /**
     * Define beforeUpdate trigger
     */
    public function beforeUpdate(callable $func): callable
    {
        return $this->trigger['beforeUpdate'] = $func;
    }

    /**
     * Define afterUpdate trigger
     */
    public function afterUpdate(callable $func): callable
    {
        return $this->trigger['afterUpdate'] = $func;
    }

    /**
     * Define onUpdate trigger
     */
    public function onUpdate(callable $func): callable
    {
        return $this->afterUpdate($func);
    }

    /**
     * Define beforeSave trigger
     */
    public function beforeSave(callable $func): callable
    {
        $this->trigger['beforeInsert'] = $func;
        $this->trigger['beforeUpdate'] = $func;
        return $func;
    }

    /**
     * Define afterSave trigger
     */
    public function afterSave(callable $func): callable
    {
        $this->trigger['afterInsert'] = $func;
        $this->trigger['afterUpdate'] = $func;
        return $func;
    }

    /**
     * Define onSave trigger
     */
    public function onSave(callable $func): callable
    {
        return $this->afterSave($func);
    }

    /**
     * Define beforeErase trigger
     */
    public function beforeErase(callable $func): callable
    {
        return $this->trigger['beforeErase'] = $func;
    }

    /**
     * Define afterErase trigger
     */
    public function afterErase(callable $func): callable
    {
        return $this->trigger['afterErase'] = $func;
    }

    /**
     * Define onErase trigger
     */
    public function onErase(callable $func): callable
    {
        return $this->afterErase($func);
    }

    /**
     * Reset cursor
     */
    public function reset(): void
    {
        $this->query = [];
        $this->ptr = 0;
    }

}
