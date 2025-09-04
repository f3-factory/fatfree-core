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

namespace F3\Web\Google;

/**
 * Google Static Maps API v2 plug-in
 * @method self key(string $apiKey)
 * @method self center(string $locationName)
 * @method self zoom(int $level)
 * @method self size(string $widthXheight)
 * @method self sensor(bool $sensor)
 * @method self maptype(string $type)
 * @method self markers(string $markers)
 */
class StaticMap
{
    // API URL
    const string URL_Static = 'http://maps.googleapis.com/maps/api/staticmap';

    // Query arguments
    protected array $query = [];

    /**
     * Specify API key-value pair via magic call
     */
    public function __call(string $func, array $args)
    {
        $this->query[] = [$func, $args[0]];
        return $this;
    }

    /**
     * Generate map
     */
    public function dump(): false|string
    {
        $web = \F3\Web::instance();
        $req = $web->request(
            self::URL_Static.'?'.array_reduce(
                $this->query,
                fn($out, $item) => ($out .= ($out ? '&' : '').
                    urlencode($item[0]).'='.urlencode($item[1]??'')),
            ),
        );
        return ($req) && $req['body'] ? $req['body'] : false;
    }

}
