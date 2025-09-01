<?php

/**
 *
 * Copyright (c) 2025 F3::Factory, All rights reserved.
 *
 * This file is part of the Fat-Free Framework (http://fatfreeframework.com).
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

namespace F3\DB\Jig;

use F3\DB\Jig;

/**
 * Flat-file DB mapper
 */
class Mapper extends \F3\DB\Cursor
{
    // Document identifier
    protected ?string $id = null;
    // Document contents
    protected array $document = [];
    // field map-reduce handlers
    protected array $_reduce = [];

    public function __construct(
        // Flat-file DB wrapper
        protected Jig $db,
        // Data file
        protected string $file
    ) {
        $this->reset();
    }

    /**
     * Return database type
     */
    public function dbtype(): string
    {
        return 'Jig';
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
        return ($key == '_id') ? false : ($this->document[$key] = $val);
    }

    /**
     * Retrieve value of field
     */
    public function &get(string $key): mixed
    {
        if ($key == '_id')
            return $this->id;
        if (array_key_exists($key, $this->document))
            return $this->document[$key];
        throw new \Exception(sprintf(self::E_Field, $key));
    }

    /**
     * Delete field
     */
    public function clear(string $key): void
    {
        if ($key != '_id')
            unset($this->document[$key]);
    }

    /**
     * Convert array to mapper object
     */
    public function factory(string $id, array $row): static
    {
        $mapper = clone($this);
        $mapper->reset();
        $mapper->id = $id;
        foreach ($row as $field => $val)
            $mapper->document[$field] = $val;
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
        return $obj->document + ['_id' => $this->id];
    }

    /**
     * Convert tokens in string expression to variable names
     */
    protected function token(string $str): string
    {
        $str = preg_replace_callback(
            '/(?<!\w)@(\w[\w.\[\]]*)/',
            fn($token)
                => // Convert from JS dot notation to PHP array notation
                '$'.preg_replace_callback(
                    '/(\.\w+)|\[((?:[^\[\]]*|(?R))*)]/',
                    function ($expr) {
                        $fw = \F3\Base::instance();
                        return
                            '['.
                            ($expr[1] ?
                                $fw->stringify(substr($expr[1], 1)) :
                                (preg_match(
                                    '/^\w+/',
                                    $mix = $this->token($expr[2]),
                                ) ?
                                    $fw->stringify($mix) :
                                    $mix)).
                            ']';
                    },
                    $token[1],
                ),
            $str,
        );
        return trim($str);
    }

    /**
     * Return records that match criteria
     * @return static[]
     */
    public function find(
        array|string|null $filter = null,
        ?array $options = null,
        int|array $ttl = 0,
        bool $log = true
    ): array {
        if (!$options)
            $options = [];
        $options += [
            'order' => null,
            'limit' => 0,
            'offset' => 0,
            'group' => null,
        ];
        $fw = \F3\Base::instance();
        $cache = \F3\Cache::instance();
        $db = $this->db;
        $now = microtime(true);
        $data = [];
        $tag = '';
        if (is_array($ttl))
            [$ttl, $tag] = $ttl;
        if (!$fw->CACHE || !$ttl || !($cached = $cache->exists(
                $hash = $fw->hash(
                        $this->db->dir().
                        $fw->stringify([$filter, $options]),
                    ).($tag ? '.'.$tag : '').'.jig',
                $data,
            )) ||
            $cached[0] + $ttl < microtime(true)) {
            $data = $db->read($this->file);
            if (!$data)
                return [];
            foreach ($data as $id => &$doc) {
                $doc['_id'] = $id;
                unset($doc);
            }
            if ($filter) {
                if (!is_array($filter))
                    return [];
                // Normalize equality operator
                $expr = preg_replace('/(?<=[^<>!=])=(?!=)/', '==', $filter[0]);
                // Prepare query arguments
                $args = isset($filter[1]) && is_array($filter[1]) ?
                    $filter[1] :
                    array_slice($filter, 1, null, true);
                $args = is_array($args) ? $args : [1 => $args];
                $keys = $vals = [];
                $tokens = array_slice(
                    token_get_all('<?php '.$this->token($expr)),
                    1,
                );
                $data = array_filter(
                    $data,
                    function ($_row) use ($fw, $args, $tokens) {
                        $_expr = '';
                        $ctr = 0;
                        $named = false;
                        foreach ($tokens as $token) {
                            if (is_string($token))
                                if ($token == '?') {
                                    // Positional
                                    ++$ctr;
                                    $key = $ctr;
                                } else {
                                    if ($token == ':')
                                        $named = true;
                                    else
                                        $_expr .= $token;
                                    continue;
                                }
                            elseif ($named &&
                                token_name($token[0]) == 'T_STRING') {
                                $key = ':'.$token[1];
                                $named = false;
                            } else {
                                $_expr .= $token[1];
                                continue;
                            }
                            $_expr .= $fw->stringify(
                                is_string($args[$key]) ?
                                    addcslashes($args[$key], '\'') :
                                    $args[$key],
                            );
                        }
                        // Avoid conflict with user code
                        unset($fw, $tokens, $args, $ctr, $token, $key, $named);
                        extract($_row);
                        // Evaluate pseudo-SQL expression
                        return eval('return '.$_expr.';');
                    },
                );
            }
            if (isset($options['group'])) {
                $cols = array_reverse($fw->split($options['group']));
                // sort into groups
                $data = $this->sort($data, $options['group']);
                foreach ($data as $i => &$row) {
                    if (!isset($prev)) {
                        $prev = $row;
                        $prev_i = $i;
                    }
                    $drop = false;
                    foreach ($cols as $col)
                        if ($prev_i != $i && array_key_exists($col, $row) &&
                            array_key_exists($col, $prev) && $row[$col] == $prev[$col])
                            // reduce/modify
                            $drop = !isset($this->_reduce[$col]) || call_user_func_array(
                                    $this->_reduce[$col][0],
                                    [&$prev, &$row],
                                ) !== false;
                        elseif (isset($this->_reduce[$col])) {
                            $null = null;
                            // initial
                            call_user_func_array($this->_reduce[$col][0], [&$row, &$null]);
                        }
                    if ($drop)
                        unset($data[$i]);
                    else {
                        $prev =& $row;
                        $prev_i = $i;
                    }
                    unset($row);
                }
                // finalize
                if ($this->_reduce[$col][1])
                    foreach ($data as $i => &$row) {
                        $row = call_user_func($this->_reduce[$col][1], $row);
                        if (!$row)
                            unset($data[$i]);
                        unset($row);
                    }
            }
            if (isset($options['order']))
                $data = $this->sort($data, $options['order']);
            $data = array_slice(
                $data,
                $options['offset'],
                $options['limit'] ?: null,
                true,
            );
            if ($fw->CACHE && $ttl)
                // Save to cache backend
                $cache->set($hash, $data, $ttl);
        }
        $out = [];
        foreach ($data as $id => &$doc) {
            unset($doc['_id']);
            $out[] = $this->factory($id, $doc);
            unset($doc);
        }
        if ($log && isset($args)) {
            if ($filter)
                foreach ($args as $key => $val) {
                    $vals[] = $fw->stringify(is_array($val) ? $val[0] : $val);
                    $keys[] = '/'.(is_numeric($key) ? '\?' : preg_quote($key)).'/';
                }
            $db->jot(
                '('.sprintf('%.1f', 1e3 * (microtime(true) - $now)).'ms) '.
                $this->file.' [find] '.
                ($filter ? preg_replace($keys, $vals, $filter[0], 1) : ''),
            );
        }
        return $out;
    }

    /**
     * Sort a collection
     */
    protected function sort(array $data, string $cond): array
    {
        $cols = \F3\Base::instance()->split($cond);
        uasort(
            $data,
            function ($val1, $val2) use ($cols) {
                foreach ($cols as $col) {
                    $parts = explode(' ', $col, 2);
                    $order = empty($parts[1]) ?
                        SORT_ASC :
                        constant($parts[1]);
                    $col = $parts[0];
                    if (!array_key_exists($col, $val1))
                        $val1[$col] = null;
                    if (!array_key_exists($col, $val2))
                        $val2[$col] = null;
                    [$v1, $v2] = [$val1[$col], $val2[$col]];
                    if ($out = strnatcmp($v1 ?: '', $v2 ?: '') *
                        (($order == SORT_ASC) * 2 - 1))
                        return $out;
                }
                return 0;
            },
        );
        return $data;
    }

    /**
     * Add reduce handler for grouped fields
     */
    public function reduce(string $key, callable $handler, ?callable $finalize = null): void
    {
        $this->_reduce[$key] = [$handler, $finalize];
    }

    /**
     *
     * Count records that match criteria
     */
    public function count(
        array|string|null $filter = null,
        ?array $options = null,
        int|array $ttl = 0
    ): int {
        $now = microtime(true);
        $out = count($this->find($filter, $options, $ttl, false));
        $this->db->jot(
            '('.sprintf('%.1f', 1e3 * (microtime(true) - $now)).'ms) '.
            $this->file.' [count] '.($filter ? json_encode($filter) : ''),
        );
        return $out;
    }

    /**
     * Return record at specified offset using criteria of previous load() call and make it active
     */
    public function skip(int $ofs = 1): ?static
    {
        $this->document = ($out = parent::skip($ofs)) ? $out->document : [];
        $this->id = $out ? $out->id : null;
        if ($this->document && isset($this->trigger['load']))
            \F3\Base::instance()->call($this->trigger['load'], [$this]);
        return $out;
    }

    /**
     * Insert new record
     */
    public function insert(): static
    {
        if ($this->id)
            return $this->update();
        $db = $this->db;
        $now = microtime(true);
        while (($id = uniqid('', true)) &&
            ($data =& $db->read($this->file)) && isset($data[$id]) &&
            !connection_aborted())
            usleep(mt_rand(0, 100));
        $this->id = $id;
        $pkey = ['_id' => $this->id];
        if (isset($this->trigger['beforeInsert']) &&
            \F3\Base::instance()->call(
                $this->trigger['beforeInsert'],
                [$this, $pkey],
            ) === false)
            return $this;
        $data[$id] = $this->document;
        $db->write($this->file, $data);
        $db->jot(
            '('.sprintf('%.1f', 1e3 * (microtime(true) - $now)).'ms) '.
            $this->file.' [insert] '.json_encode($this->document),
        );
        if (isset($this->trigger['afterInsert']))
            \F3\Base::instance()->call(
                $this->trigger['afterInsert'],
                [$this, $pkey],
            );
        $this->load(['@_id=?', $this->id]);
        return $this;
    }

    /**
     * Update current record
     */
    public function update(): static
    {
        $db = $this->db;
        $now = microtime(true);
        $data =& $db->read($this->file);
        if (isset($this->trigger['beforeUpdate']) &&
            \F3\Base::instance()->call(
                $this->trigger['beforeUpdate'],
                [$this, ['_id' => $this->id]],
            ) === false)
            return $this;
        $data[$this->id] = $this->document;
        $db->write($this->file, $data);
        $db->jot(
            '('.sprintf('%.1f', 1e3 * (microtime(true) - $now)).'ms) '.
            $this->file.' [update] '.json_encode($this->document),
        );
        if (isset($this->trigger['afterUpdate']))
            \F3\Base::instance()->call(
                $this->trigger['afterUpdate'],
                [$this, ['_id' => $this->id]],
            );
        return $this;
    }

    /**
     * Delete current record
     * @param string|array|null $filter string|array
     * @param $quick bool when quick mode is active, no deletion events are triggered
     */
    public function erase(string|array|null $filter = null, bool $quick = false): int
    {
        $db = $this->db;
        $now = microtime(true);
        $data =& $db->read($this->file);
        $pkey = ['_id' => $this->id];
        if ($filter) {
            $out = 0;
            foreach ($this->find($filter) as $mapper)
                $out += $mapper->erase();
            return $out;
        } elseif (isset($this->id)) {
            unset($data[$this->id]);
            parent::erase();
        } else
            return false;
        if (!$quick && isset($this->trigger['beforeErase']) &&
            \F3\Base::instance()->call(
                $this->trigger['beforeErase'],
                [$this, $pkey],
            ) === false)
            return false;
        $db->write($this->file, $data);
        if ($filter) {
            $args = isset($filter[1]) && is_array($filter[1]) ?
                $filter[1] :
                array_slice($filter, 1, null, true);
            $args = is_array($args) ? $args : [1 => $args];
            foreach ($args as $key => $val) {
                $vals[] = \F3\Base::instance()->stringify(is_array($val) ? $val[0] : $val);
                $keys[] = '/'.(is_numeric($key) ? '\?' : preg_quote($key)).'/';
            }
        }
        $db->jot(
            '('.sprintf('%.1f', 1e3 * (microtime(true) - $now)).'ms) '.
            $this->file.' [erase] '.
            ($filter ? preg_replace($keys, $vals, $filter[0], 1) : ''),
        );
        if (!$quick && isset($this->trigger['afterErase']))
            \F3\Base::instance()->call(
                $this->trigger['afterErase'],
                [$this, $pkey],
            );
        return true;
    }

    /**
     * Reset cursor
     */
    public function reset(): void
    {
        $this->id = null;
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
    public function fields(bool $adhoc = true): array
    {
        return array_keys($this->document);
    }

}
