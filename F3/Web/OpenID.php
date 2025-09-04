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
 * OpenID consumer
 */
class OpenID extends \F3\Magic
{

    // OpenID provider endpoint URL
    protected string $url;
    // HTTP request parameters
    protected array $args = [];

    /**
     * Determine OpenID provider
     */
    protected function discover(string $proxy): false|string
    {
        // Normalize
        if (!preg_match('/https?:\/\//i', $this->args['endpoint']))
            $this->args['endpoint'] = 'https://'.$this->args['endpoint'];
        $url = parse_url($this->args['endpoint']);
        // Remove fragment; reconnect parts
        $this->args['endpoint'] = $url['scheme'].'://'.
            (isset($url['user']) ?
                ($url['user'].
                    (isset($url['pass']) ? (':'.$url['pass']) : '').'@') : '').
            strtolower($url['host']).($url['path'] ?? '/').
            (isset($url['query']) ? ('?'.$url['query']) : '');
        // HTML-based discovery of OpenID provider
        $req = \F3\Web::instance()->request($this->args['endpoint'], ['proxy' => $proxy]);
        if (!$req)
            return false;
        $type = array_values(preg_grep('/Content-Type:/', $req['headers']));
        if ($type &&
            preg_match('/application\/xrds\+xml|text\/xml/', $type[0]) &&
            ($sxml = simplexml_load_string($req['body'])) &&
            ($xrds = json_decode(json_encode($sxml), true)) &&
            isset($xrds['XRD'])) {
            // XRDS document
            $svc = $xrds['XRD']['Service'];
            if (isset($svc[0]))
                $svc = $svc[0];
            $svc_type = is_array($svc['Type']) ? $svc['Type'] : [$svc['Type']];
            if (preg_grep(
                '/https?:\/\/specs\.openid\.net\/auth\/2.0\/'.
                '(?:server|signon)/',
                $svc_type,
            )) {
                $this->args['provider'] = $svc['URI'];
                if (isset($svc['LocalID']))
                    $this->args['localidentity'] = $svc['LocalID'];
                elseif (isset($svc['CanonicalID']))
                    $this->args['localidentity'] = $svc['CanonicalID'];
            }
            $this->args['server'] = $svc['URI'];
            if (isset($svc['Delegate']))
                $this->args['delegate'] = $svc['Delegate'];
        } else {
            $len = strlen($req['body']);
            $ptr = 0;
            // Parse document
            while ($ptr < $len)
                if (preg_match(
                    '/^<link\b((?:\h+\w+\h*=\h*'.
                    '(?:"(?:.+?)"|\'(?:.+?)\'))*)\h*\/?>/is',
                    substr($req['body'], $ptr),
                    $parts,
                )) {
                    if ($parts[1] &&
                        // Process attributes
                        preg_match_all(
                            '/\b(rel|href)\h*=\h*'.
                            '(?:"(.+?)"|\'(.+?)\')/s',
                            $parts[1],
                            $attr,
                            PREG_SET_ORDER,
                        )) {
                        $node = [];
                        foreach ($attr as $kv)
                            $node[$kv[1]] = $kv[2] ?? $kv[3];
                        if (isset($node['rel']) &&
                            preg_match(
                                '/openid2?\.(\w+)/',
                                $node['rel'],
                                $var,
                            ) &&
                            isset($node['href']))
                            $this->args[$var[1]] = $node['href'];
                    }
                    $ptr += strlen($parts[0]);
                } else
                    ++$ptr;
        }
        // Get OpenID provider's endpoint URL
        if (isset($this->args['provider'])) {
            // OpenID 2.0
            $this->args['ns'] = 'http://specs.openid.net/auth/2.0';
            if (isset($this->args['localidentity']))
                $this->args['identity'] = $this->args['localidentity'];
            if (isset($this->args['trust_root']))
                $this->args['realm'] = $this->args['trust_root'];
        } elseif (isset($this->args['server'])) {
            // OpenID 1.1
            $this->args['ns'] = 'http://openid.net/signon/1.1';
            if (isset($this->args['delegate']))
                $this->args['identity'] = $this->args['delegate'];
        }
        if (isset($this->args['provider'])) {
            // OpenID 2.0
            if (empty($this->args['claimed_id']))
                $this->args['claimed_id'] = $this->args['identity'];
            return $this->args['provider'];
        } elseif (isset($this->args['server']))
            // OpenID 1.1
            return $this->args['server'];
        else
            return false;
    }

    /**
     * Initiate OpenID authentication sequence
     * Return FALSE on failure or redirect to OpenID provider URL
     */
    public function auth(?string $proxy = null, array $attr = [], ?array $reqd = null): bool
    {
        $fw = \F3\Base::instance();
        $root = $fw->SCHEME.'://'.$fw->HOST;
        if (empty($this->args['trust_root']))
            $this->args['trust_root'] = $root.$fw->BASE.'/';
        if (empty($this->args['return_to']))
            $this->args['return_to'] = $root.$_SERVER['REQUEST_URI'];
        $this->args['mode'] = 'checkid_setup';
        if ($this->url = $this->discover($proxy)) {
            if ($attr) {
                $this->args['ns.ax'] = 'http://openid.net/srv/ax/1.0';
                $this->args['ax.mode'] = 'fetch_request';
                foreach ($attr as $key => $val)
                    $this->args['ax.type.'.$key] = $val;
                $this->args['ax.required'] = is_string($reqd) ?
                    $reqd : implode(',', $reqd);
            }
            $var = [];
            foreach ($this->args as $key => $val)
                $var['openid.'.$key] = $val;
            $fw->reroute($this->url.'?'.http_build_query($var));
        }
        return false;
    }

    /**
     * Return TRUE if OpenID verification was successful
     */
    public function verified(?string $proxy = null): bool
    {
        preg_match_all(
            '/(?<=^|&)openid\.([^=]+)=([^&]+)/',
            \F3\Base::instance()->QUERY,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $match)
            $this->args[$match[1]] = urldecode($match[2]);
        if (isset($this->args['mode']) &&
            $this->args['mode'] != 'error' &&
            $this->url = $this->discover($proxy)) {
            $this->args['mode'] = 'check_authentication';
            $var = [];
            foreach ($this->args as $key => $val)
                $var['openid.'.$key] = $val;
            $req = \F3\Web::instance()->request(
                $this->url,
                [
                    'method' => 'POST',
                    'content' => http_build_query($var),
                    'proxy' => $proxy,
                ],
            );
            return (bool) preg_match('/is_valid:true/i', $req['body']);
        }
        return false;
    }

    /**
     * Return OpenID response fields
     */
    public function response(): array
    {
        return $this->args;
    }

    /**
     * Return TRUE if OpenID request parameter exists
     */
    public function exists(string $key): bool
    {
        return isset($this->args[$key]);
    }

    /**
     * Bind value to OpenID request parameter
     */
    public function set(string $key, mixed $val): mixed
    {
        return $this->args[$key] = $val;
    }

    /**
     * Return value of OpenID request parameter
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
     * Remove OpenID request parameter
     */
    public function clear(string $key): void
    {
        unset($this->args[$key]);
    }

}
