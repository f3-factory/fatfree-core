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

use MongoDB\Client;
use MongoDB\Database;

/**
 * MongoDB wrapper
 */
class Mongo
{
    const
        E_Profiler = 'MongoDB profiler is disabled';

    // UUID
    protected string $uuid;
    // MongoDB object
    protected Database $db;
    // MongoDB log;
    protected string|false $log = '';

    public function __construct(
        // Data source name
        protected string $dsn,
        string $dbname,
        ?array $options = null
    ) {
        $this->uuid = \F3\Base::instance()->hash($this->dsn);
        $this->db = new Client($dsn, $options ?: [])->$dbname;
        $this->db->command(['profile' => 2]);
    }

    /**
     * Return data source name
     */
    public function dsn(): string
    {
        return $this->dsn;
    }

    /**
     * Return UUID
     */
    public function uuid(): string
    {
        return $this->uuid;
    }

    /**
     * Return MongoDB profiler results (or disable logging)
     */
    public function log(bool $flag = true): false|string
    {
        if ($flag) {
            $cursor = $this->db->selectCollection('system.profile')->find();
            foreach (iterator_to_array($cursor) as $frame)
                if (!preg_match('/\.system\..+$/', $frame['ns']))
                    $this->log .= date(
                            'r',
                            $this->legacy() ?
                                $frame['ts']->sec : (round((string) $frame['ts']) / 1000),
                        ).
                        ' ('.sprintf('%.1f', $frame['millis']).'ms) '.
                        $frame['ns'].' ['.$frame['op'].'] '.
                        (empty($frame['query']) ?
                            '' : json_encode($frame['query'])).
                        (empty($frame['command']) ?
                            '' : json_encode($frame['command'])).
                        PHP_EOL;
        } else {
            $this->log = false;
            $this->db->command(['profile' => -1]);
        }
        return $this->log;
    }

    /**
     * Intercept native call to re-enable profiler
     */
    public function drop(): void
    {
        $this->db->drop();
        if ($this->log !== false) {
            $this->db->command(['profile' => 2]);
        }
    }

    /**
     * Redirect call to MongoDB object
     */
    public function __call(string $func, array $args): mixed
    {
        return call_user_func_array([$this->db, $func], $args);
    }

    // Prohibit cloning
    private function __clone() {}


}
