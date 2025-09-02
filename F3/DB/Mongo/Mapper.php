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

namespace F3\DB\Mongo;

use F3\DB\Mongo;

/**
 * MongoDB mapper
 */
class Mapper extends \F3\DB\Cursor
{

    // MongoDB wrapper
    protected Mongo $db;
    // Mongo collection
    protected $collection;
    // Mongo document
    protected $document = [];
    // Mongo cursor
    protected $cursor;
    // Defined fields
    protected ?array $fields;

    /**
     * Return database type
     */
    public function dbtype(): string
    {
        return 'Mongo';
    }

    /**
     * Return TRUE if field is defined
     */
    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->document);
    }

    /**
     * Assign value to field
     */
    public function set(string $key, mixed $val): mixed
    {
        return $this->document[$key] = $val;
    }

    /**
     * Retrieve value of field
     */
    public function &get(string $key): mixed
    {
        if ($this->exists($key))
            return $this->document[$key];
        throw new \Exception(sprintf(self::E_Field, $key));
    }

    /**
     * Delete field
     */
    public function clear($key): void
    {
        unset($this->document[$key]);
    }

    /**
     * Convert array to mapper object
     */
    public function factory(array $row): static
    {
        $mapper = clone($this);
        $mapper->reset();
        foreach ($row as $key => $val)
            $mapper->document[$key] = $val;
        $mapper->query = [clone($mapper)];
        if (isset($mapper->trigger['load']))
            \F3\Base::instance()->call($mapper->trigger['load'], [$mapper]);
        return $mapper;
    }

    /**
     * Return fields of mapper object as an associative array
     */
    public function cast(?object $obj = null): array
    {
        if (!$obj)
            $obj = $this;
        return $obj->document;
    }

    /**
     * Build query and execute
     * @return static[]
     */
    public function select(
        ?array $fields = null,
        string|array|null $filter = null,
        ?array $options = null,
        int|array $ttl = 0
    ): array {
        if (!$options)
            $options = [];
        $options += [
            'group' => null,
            'order' => null,
            'limit' => 0,
            'offset' => 0,
        ];
        $tag = '';
        if (is_array($ttl))
            [$ttl, $tag] = $ttl;
        $fw = \F3\Base::instance();
        $cache = \F3\Cache::instance();
        if (!($cached = $cache->exists(
                $hash = $fw->hash(
                        $this->db->dsn().
                        $fw->stringify([$fields, $filter, $options]),
                    ).($tag ? '.'.$tag : '').'.mongo',
                $result,
            )) || !$ttl || $cached[0] + $ttl < microtime(true)) {
            if ($options['group']) {
                $grp = $this->collection->group(
                    $options['group']['keys'],
                    $options['group']['initial'],
                    $options['group']['reduce'],
                    [
                        'condition' => $filter,
                        'finalize' => $options['group']['finalize'],
                    ],
                );
                $tmp = $this->db->selectcollection(
                    $fw->HOST.'.'.$fw->BASE.'.'.
                    uniqid('', true).'.tmp',
                );
                $tmp->batchinsert($grp['retval'], ['w' => 1]);
                $filter = [];
                $collection = $tmp;
            } else {
                $filter = $filter ?: [];
                $collection = $this->collection;
            }

            $this->cursor = $collection->find($filter, [
                'sort' => $options['order'],
                'limit' => $options['limit'],
                'skip' => $options['offset'],
            ]);
            $result = $this->cursor->toarray();

            if ($options['group'])
                $tmp->drop();
            if ($fw->CACHE && $ttl)
                // Save to cache backend
                $cache->set($hash, $result, $ttl);
        }
        $out = [];
        foreach ($result as $doc)
            $out[] = $this->factory($doc);
        return $out;
    }

    /**
     * Return records that match criteria
     */
    public function find(
        array|string|null $filter = null,
        ?array $options = null,
        int|array $ttl = 0,
    ): array {
        if (!$options)
            $options = [];
        $options += [
            'group' => null,
            'order' => null,
            'limit' => 0,
            'offset' => 0,
        ];
        return $this->select($this->fields, $filter, $options, $ttl);
    }

    /**
     * Count records that match criteria
     */
    public function count(
        array|string|null $filter = null,
        ?array $options = null,
        int|array $ttl = 0,
    ): int {
        $fw = \F3\Base::instance();
        $cache = \F3\Cache::instance();
        $tag = '';
        if (is_array($ttl))
            [$ttl, $tag] = $ttl;
        if (!($cached = $cache->exists(
                $hash = $fw->hash(
                        $fw->stringify(
                            [$filter],
                        ),
                    ).($tag ? '.'.$tag : '').'.mongo',
                $result,
            )) || !$ttl ||
            $cached[0] + $ttl < microtime(true)) {
            $result = $this->collection->count($filter ?: []);
            if ($fw->CACHE && $ttl)
                // Save to cache backend
                $cache->set($hash, $result, $ttl);
        }
        return $result;
    }

    /**
     * Return record at specified offset using criteria of previous
     * load() call and make it active
     */
    public function skip(int $ofs = 1): ?static
    {
        $this->document = ($out = parent::skip($ofs)) ? $out->document : [];
        if ($this->document && isset($this->trigger['load']))
            \F3\Base::instance()->call($this->trigger['load'], [$this]);
        return $out;
    }

    /**
     * Insert new record
     */
    public function insert(): static
    {
        if (isset($this->document['_id']))
            return $this->update();
        if (isset($this->trigger['beforeInsert']) &&
            \F3\Base::instance()->call(
                $this->trigger['beforeInsert'],
                [$this, ['_id' => $this->document['_id']]],
            ) === false)
            return $this->document;

        $result = $this->collection->insertone($this->document);
        $pkey = ['_id' => $result->getinsertedid()];

        if (isset($this->trigger['afterInsert']))
            \F3\Base::instance()->call(
                $this->trigger['afterInsert'],
                [$this, $pkey],
            );
        $this->load($pkey);
        return $this;
    }

    /**
     * Update current record
     */
    function update(): static
    {
        $pkey = ['_id' => $this->document['_id']];
        if (isset($this->trigger['beforeUpdate']) &&
            \F3\Base::instance()->call(
                $this->trigger['beforeUpdate'],
                [$this, $pkey],
            ) === false)
            return $this->document;
        $upsert = ['upsert' => true];

        $this->collection->replaceone($pkey, $this->document, $upsert);
        if (isset($this->trigger['afterUpdate']))
            \F3\Base::instance()->call(
                $this->trigger['afterUpdate'],
                [$this, $pkey],
            );
        return $this->document;
    }

    /**
     * Delete current record
     */
    public function erase(string|array|null $filter = null, bool $quick = true): int
    {
        if ($filter) {
            if (!$quick) {
                $out = 0;
                foreach ($this->find($filter) as $mapper)
                    $out += $mapper->erase();
                return $out;
            }
            return $this->collection->deletemany($filter);
        }
        $pkey = ['_id' => $this->document['_id']];
        if (isset($this->trigger['beforeErase']) &&
            \F3\Base::instance()->call(
                $this->trigger['beforeErase'],
                [$this, $pkey],
            ) === false)
            return false;
        $result = $this->collection->deleteone(['_id' => $this->document['_id']]);
        parent::erase();
        if (isset($this->trigger['afterErase']))
            \F3\Base::instance()->call(
                $this->trigger['afterErase'],
                [$this, $pkey],
            );
        return $result;
    }

    /**
     * Reset cursor
     */
    public function reset(): void
    {
        $this->document = [];
        parent::reset();
    }

    /**
     * Hydrate mapper object using hive array variable
     */
    public function copyFrom(array|string $var, ?callable $func = null): void
    {
        if (is_string($var))
            $var = \F3\Base::instance()->$var;
        if ($func)
            $var = call_user_func($func, $var);
        foreach ($var as $key => $val)
            $this->set($key, $val);
    }

    /**
     * Populate hive array variable with mapper fields
     */
    public function copyTo(string $key): void
    {
        $var =& \F3\Base::instance()->ref($key);
        foreach ($this->document as $key => $field)
            $var[$key] = $field;
    }

    /**
     * Return field names
     */
    public function fields(): array
    {
        return array_keys($this->document);
    }

    /**
     * Return the cursor from last query
     */
    public function cursor(): ?object
    {
        return $this->cursor;
    }

    public function __construct(\F3\DB\Mongo $db, string $collection, ?array $fields = null)
    {
        $this->db = $db;
        $this->collection = $db->selectcollection($collection);
        $this->fields = $fields;
        $this->reset();
    }

}
