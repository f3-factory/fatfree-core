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

use F3\Prefab;

/**
 * Pingback 1.0 protocol (client and server) implementation
 * @deprecated
 */
class Pingback
{
    use Prefab;

    // Transaction history
    protected string $log = '';

    /**
     * Return TRUE if URL points to a pingback-enabled resource
     */
    protected function enabled(string $url): false|string
    {
        $web = \F3\Web::instance();
        $req = $web->request($url);
        $found = false;
        if ($req['body']) {
            // Look for pingback header
            foreach ($req['headers'] as $header)
                if (preg_match('/^X-Pingback:\h*(.+)/', $header, $href)) {
                    $found = $href[1];
                    break;
                }
            if (!$found &&
                // Scan page for pingback link tag
                preg_match('/<link\h+(.+?)\h*\/?>/i', $req['body'], $parts) &&
                preg_match('/rel\h*=\h*"pingback"/i', $parts[1]) &&
                preg_match('/href\h*=\h*"\h*(.+?)\h*"/i', $parts[1], $href))
                $found = $href[1];
        }
        return $found;
    }

    /**
     * Load local page contents, parse HTML anchor tags, find permalinks,
     * and send XML-RPC calls to corresponding pingback servers
     */
    public function inspect(string $source): void
    {
        $fw = \F3\Base::instance();
        $web = \F3\Web::instance();
        $parts = parse_url($source);
        if (empty($parts['scheme']) || empty($parts['host']) ||
            $parts['host'] == $fw->HOST) {
            $req = $web->request($source);
            $doc = new \DOMDocument('1.0', $fw->ENCODING);
            $doc->strictErrorChecking = false;
            $doc->recover = true;
            if ($req['body'] && $doc->loadhtml($req['body'])) {
                // Parse anchor tags
                $links = $doc->getElementsByTagName('a');
                foreach ($links as $link) {
                    $permalink = $link->getattribute('href');
                    // Find pingback-enabled resources
                    if ($permalink && $found = $this->enabled($permalink)) {
                        $req = $web->request(
                            $found,
                            [
                                'method' => 'POST',
                                'header' => 'Content-Type: application/xml',
                                'content' => xmlrpc_encode_request(
                                    'pingback.ping',
                                    [$source, $permalink],
                                    ['encoding' => $fw->ENCODING],
                                ),
                            ],
                        );
                        if ($req['body'])
                            $this->log .= date('r').' '.
                                $permalink.' [permalink:'.$found.']'.PHP_EOL.
                                $req['body'].PHP_EOL;
                    }
                }
            }
            unset($doc);
        }
    }

    /**
     * Receive ping, check if local page is pingback-enabled, verify
     * source contents, and return XML-RPC response
     */
    public function listen(callable $func, ?string $path = null): never
    {
        $fw = \F3\Base::instance();
        if (PHP_SAPI != 'cli') {
            header('X-Powered-By: '.$fw->PACKAGE);
            header(
                'Content-Type: application/xml; '.
                'charset='.$charset = $fw->ENCODING,
            );
        }
        if (!$path)
            $path = $fw->BASE;
        $web = \F3\Web::instance();
        $args = xmlrpc_decode_request($fw->BODY, $method, $charset);
        $options = ['encoding' => $charset];
        if ($method == 'pingback.ping' && isset($args[0], $args[1])) {
            [$source, $permalink] = $args;
            $doc = new \DOMDocument('1.0', $fw->ENCODING);
            // Check local page if pingback-enabled
            $parts = parse_url($permalink);
            if ((empty($parts['scheme']) ||
                    $parts['host'] == $fw->HOST) &&
                preg_match(
                    '/^'.preg_quote($path, '/').'/'.
                    ($fw->CASELESS ? 'i' : ''),
                    $parts['path'],
                ) &&
                $this->enabled($permalink)) {
                // Check source
                $parts = parse_url($source);
                if ((empty($parts['scheme']) ||
                        $parts['host'] == $fw->HOST) &&
                    ($req = $web->request($source)) &&
                    $doc->loadHTML($req['body'])) {
                    $links = $doc->getElementsByTagName('a');
                    foreach ($links as $link) {
                        if ($link->getattribute('href') == $permalink) {
                            call_user_func_array($func, [$source, $req['body']]);
                            // Success
                            die(xmlrpc_encode_request(null, $source, $options));
                        }
                    }
                    // No link to local page
                    die(xmlrpc_encode_request(null, 0x11, $options));
                }
                // Source failure
                die(xmlrpc_encode_request(null, 0x10, $options));
            }
            // Doesn't exist (or not pingback-enabled)
            die(xmlrpc_encode_request(null, 0x21, $options));
        }
        // Access denied
        die(xmlrpc_encode_request(null, 0x31, $options));
    }

    /**
     * Return transaction history
     */
    public function log(): string
    {
        return $this->log;
    }

    public function __construct()
    {
        // Suppress errors caused by invalid HTML structures
        libxml_use_internal_errors(true);
    }

}
