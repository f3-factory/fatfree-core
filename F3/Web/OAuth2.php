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

/**
 * Lightweight OAuth2 client
 */
class OAuth2 extends \F3\Magic
{
    // Scopes and claims
    protected array $args = [];
    // Encoding
    protected int $enc_type = PHP_QUERY_RFC1738;

    /**
     * Return OAuth2 authentication URI
     */
    public function uri(string $endpoint, bool $query = true): string
    {
        return $endpoint.($query ? ('?'.
                http_build_query($this->args, '', '&', $this->enc_type)) : '');
    }

    /**
     * Send request to API/token endpoint
     */
    public function request(string $uri, string $method, ?string $token = null): false|array|string
    {
        $options = [
            'method' => $method,
            'content' => http_build_query($this->args, '', '&', $this->enc_type),
            'header' => ['Accept: application/json'],
        ];
        if ($token)
            $options['header'][] = 'Authorization: Bearer '.$token;
        elseif ($method == 'POST' && isset($this->args['client_id']))
            $options['header'][] = 'Authorization: Basic '.
                base64_encode(
                    $this->args['client_id'].':'.
                    $this->args['client_secret'],
                );
        $response = \F3\Web::instance()->request($uri, $options);
        if ($response['error'])
            throw new \Exception($response['error']);
        if (isset($response['body'])) {
            if (preg_grep(
                '/^Content-Type:.*application\/json/i',
                $response['headers'],
            )) {
                $token = json_decode($response['body'], true);
                if (isset($token['error_description']))
                    throw new \Exception($token['error_description']);
                if (isset($token['error']))
                    throw new \Exception($token['error']);
                return $token;
            } else
                return $response['body'];
        }
        return false;
    }

    /**
     * Parse JSON Web token
     */
    public function jwt(string $token): array
    {
        return json_decode(
            base64_decode(
                str_replace(['-', '_'], ['+', '/'], explode('.', $token)[1]),
            ),
            true,
        );
    }

    /**
     * change default url encoding type, i.E. PHP_QUERY_RFC3986
     */
    public function setEncoding(int $type = PHP_QUERY_RFC1738): void
    {
        $this->enc_type = $type;
    }

    /**
     * URL-safe base64 encoding
     */
    public function b64url(string $data): string
    {
        return trim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Return TRUE if scope/claim exists
     */
    public function exists(string $key): bool
    {
        return isset($this->args[$key]);
    }

    /**
     * Bind value to scope/claim
     */
    public function set(string $key, mixed $val): mixed
    {
        return $this->args[$key] = $val;
    }

    /**
     * Return value of scope/claim
     */
    public function &get(string $key): mixed
    {
        if (isset($this->args[$key]))
            $val =& $this->args[$key];
        else
            $val = null;
        return $val;
    }

    /**
     * Remove scope/claim
     */
    public function clear(?string $key = null): void
    {
        if ($key)
            unset($this->args[$key]);
        else
            $this->args = [];
    }

}

