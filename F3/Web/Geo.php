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

namespace F3\Web;

use F3\Matrix;

/**
 * Geo plug-in
 */
class Geo
{
    use \F3\Prefab;

    /**
     * Return information about specified Unix time zone
     * @return array{offset: int, country: string, latitude: float, longitude: float, dst: bool}
     */
    public function tzinfo(string $zone): array
    {
        $ref = new \DateTimeZone($zone);
        $loc = $ref->getLocation();
        $trn = $ref->getTransitions($now = time(), $now);
        $out = [
            'offset' => $ref->getOffset(new \DateTime('now', new \DateTimeZone('UTC'))) / 3600,
            'country' => $loc['country_code'],
            'latitude' => $loc['latitude'],
            'longitude' => $loc['longitude'],
            'dst' => $trn[0]['isdst'],
        ];
        unset($ref);
        return $out;
    }

    /**
     * Return geolocation data based on specified/auto-detected IP address
     * @return false|array{status: string, country: string, country_code: string, region_code:
     *     string, region_name: string, city: string, zip: string, laitude: float, longitude:
     *     float, timezone: string, isp: string, org: string, as: string, query: string}
     */
    public function location(?string $ip = null): false|array
    {
        $fw = \F3\Base::instance();
        $web = \F3\Web::instance();
        if (!$ip)
            $ip = $fw->IP;
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 |
            FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE,
        );
        if (($req = $web->request(
                'http://ip-api.com/json/'.
                ($public ? $ip : ''),
            )) &&
            $data = json_decode($req['body'], true)) {
            $out = [];
            $mtx = Matrix::instance();
            $mtx->changeKey($data, 'lat', 'latitude');
            $mtx->changeKey($data, 'lon', 'longitude');
            $mtx->changeKey($data, 'region', 'region_code');
            foreach ($data as $key => $val)
                $out[$fw->snakeCase($key)] = $val;
            return $out;
        }
        return false;
    }

    /**
     * Return weather data based on specified latitude/longitude
     * @return false|array{
     *     'coord':array{'lon': float, 'lat': float},
     *     'weather': array<int,
     *          array{'id': int, 'main': string, 'description': string, 'icon': string}>,
     *     'base':string,
     *     'main': array{
     *          'temp': float, 'feels_like': float, 'temp_min': float, 'temp_max':float,
     *          'pressure': float, 'humidity': float, 'sea_level': float, 'grnd_level':float},
     *     'visibility': int, 'wind': array{'speed': float, 'deg': float, 'gust': float},
     *     'clouds': array{'all': int},
     *     'dt': int,
     *     'sys': array{'country': string, 'sunrise': int, 'sunset': int},
     *     'timezone': int,
     *     'id': int,
     *     'name': string,
     *     'cod':int
     * }
     */
    public function weather(float $latitude, float $longitude, string $key): false|array
    {
        $web = \F3\Web::instance();
        $query = [
            'lat' => $latitude,
            'lon' => $longitude,
            'APPID' => $key,
            'units' => 'metric',
        ];
        return ($req = $web->request(
            'http://api.openweathermap.org/data/2.5/weather?'.
            http_build_query($query),
        )) ?
            json_decode($req['body'], true) :
            false;
    }

}
