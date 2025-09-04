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
 * Google ReCAPTCHA v2 plug-in
 */
class Recaptcha
{
    // API URL
    const URL_Recaptcha = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Verify reCAPTCHA response
     */
    public static function verify(string $secret, ?string $response = null): bool
    {
        $fw = \F3\Base::instance();
        if (!isset($response))
            $response = $fw->{'POST.g-recaptcha-response'};
        if (!$response)
            return false;
        $web = \F3\Web::instance();
        $out = $web->request(self::URL_Recaptcha, [
            'method' => 'POST',
            'content' => http_build_query([
                'secret' => $secret,
                'response' => $response,
                'remoteip' => $fw->IP,
            ]),
        ]);
        return isset($out['body']) &&
            ($json = json_decode($out['body'], true)) &&
            isset($json['success']) && $json['success'];
    }

}
