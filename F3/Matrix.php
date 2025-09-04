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
 * Generic array utilities
 */
class Matrix
{
    use Prefab;

    /**
     * Retrieve values from a specified column of a multi-dimensional
     * array variable
     */
    public function pick(array $var, mixed $col): array
    {
        return array_map(fn($row) => $row[$col], $var);
    }

    /**
     * select a subset of fields from an input array
     * @param array|string $fields splittable string or array
     * @param array|string $data hive key or array
     */
    public function select(array|string $fields, array|string $data): array
    {
        return array_intersect_key(
            is_array($data) ? $data : \Base::instance()->get($data),
            array_flip(is_array($fields) ? $fields : \Base::instance()->split($fields)),
        );
    }

    /**
     * walk with a callback function through a subset of fields from an input array
     * the callback receives the value, index-key and the full input array as parameters
     * set value parameter as reference and you're able to modify the data as well
     * @param array|string $fields splittable string or array of fields
     * @param array|string $data hive key or input array
     * @param callable $callback (mixed &$value, string $key, array $data)
     * @return array modified subset data
     */
    public function walk(array|string $fields, array|string $data, callable $callback): array
    {
        $subset = $this->select($fields, $data);
        array_walk($subset, $callback, $data);
        return $subset;
    }

    /**
     * Rotate a two-dimensional array variable
     */
    public function transpose(array &$var): void
    {
        $out = [];
        foreach ($var as $keyx => $cols)
            foreach ($cols as $keyy => $valy)
                $out[$keyy][$keyx] = $valy;
        $var = $out;
    }

    /**
     * Sort a multi-dimensional array variable on a specified column
     */
    public function sort(array &$var, mixed $col, int $order = SORT_ASC): void
    {
        uasort(
            $var,
            function ($val1, $val2) use ($col, $order) {
                [$v1, $v2] = [$val1[$col], $val2[$col]];
                $out = is_numeric($v1) && is_numeric($v2) ?
                    Base::instance()->sign($v1 - $v2) : strcmp($v1, $v2);
                if ($order == SORT_DESC)
                    $out = -$out;
                return $out;
            },
        );
        $var = array_values($var);
    }

    /**
     * Change the key of a two-dimensional array element
     */
    public function changeKey(array &$var, string $old, string $new): void
    {
        $keys = array_keys($var);
        $vals = array_values($var);
        $keys[array_search($old, $keys)] = $new;
        $var = array_combine($keys, $vals);
    }

    /**
     * Return month calendar of specified date, with optional setting for
     * first day of week (0 for Sunday)
     */
    public function calendar(int|string $date = 'now', int $first = 0): false|array
    {
        $out = false;
        if (extension_loaded('calendar')) {
            if (is_string($date))
                $date = strtotime($date);
            $parts = getdate($date);
            $days = cal_days_in_month(CAL_GREGORIAN, $parts['mon'], $parts['year']);
            $ref = date('w', strtotime(date('Y-m', $parts[0]).'-01')) + (7 - $first) % 7;
            $out = [];
            for ($i = 0; $i < $days; ++$i)
                $out[floor(($ref + $i) / 7)][($ref + $i) % 7] = $i + 1;
        }
        return $out;
    }

}
