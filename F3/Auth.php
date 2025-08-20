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

namespace F3;

use F3\DB\Cursor;

/**
 * Authorization/authentication plug-in
 */
class Auth
{

    use Prefab;

    //region Error messages
    protected const
        E_LDAP = 'LDAP connection failure',
        E_SMTP = 'SMTP connection failure';
    //endregion

    /** Auth storage */
    protected object|string $storage;

    /** Mapper object */
    protected Cursor $mapper;

    /** Storage options */
    protected ?array $args;

    /** Custom compare function */
    protected ?\Closure $func;

    /**
     * Jig storage handler
     */
    protected function _jig(string $id, string $pw, ?string $realm = null): bool
    {
        $success = (bool)
        call_user_func_array(
            [$this->mapper, 'load'],
            [
                array_merge(
                    [
                        '@'.$this->args['id'].'==?'.
                        ($this->func ? '' : ' AND @'.$this->args['pw'].'==?').
                        (isset($this->args['realm']) ?
                            (' AND @'.$this->args['realm'].'==?') : ''),
                        $id,
                    ],
                    ($this->func ? [] : [$pw]),
                    (isset($this->args['realm']) ? [$realm] : []),
                ),
            ],
        );
        if ($success && $this->func)
            $success = call_user_func($this->func, $pw, $this->mapper->get($this->args['pw']));
        return $success;
    }

    /**
     * MongoDB storage handler
     */
    protected function _mongo(string $id, string $pw, ?string $realm=null): bool
    {
        $success = (bool)
        $this->mapper->load(
            [$this->args['id'] => $id] +
            ($this->func ? [] : [$this->args['pw'] => $pw]) +
            (isset($this->args['realm']) ?
                [$this->args['realm'] => $realm] : []),
        );
        if ($success && $this->func)
            $success = call_user_func($this->func, $pw, $this->mapper->get($this->args['pw']));
        return $success;
    }

    /**
     * SQL storage handler
     */
    protected function _sql(string $id, string $pw, ?string $realm=null): bool
    {
        $success = (bool)
        call_user_func_array(
            [$this->mapper, 'load'],
            [
                array_merge(
                    [
                        $this->args['id'].'=?'.
                        ($this->func ? '' : ' AND '.$this->args['pw'].'=?').
                        (isset($this->args['realm']) ?
                            (' AND '.$this->args['realm'].'=?') : ''),
                        $id,
                    ],
                    ($this->func ? [] : [$pw]),
                    (isset($this->args['realm']) ? [$realm] : []),
                ),
            ],
        );
        if ($success && $this->func)
            $success = call_user_func($this->func, $pw, $this->mapper->get($this->args['pw']));
        return $success;
    }

    /**
     * LDAP storage handler
     */
    protected function _ldap(string $id, string $pw): bool
    {
        $port = (int) ($this->args['port'] ?: 389);
        $filter = $this->args['filter'] = $this->args['filter'] ?: "uid=".$id;
        $this->args['attr'] = $this->args['attr'] ?: ["uid"];
        array_walk(
            $this->args['attr'],
            function ($attr) use (&$filter, $id) {
                $filter = str_ireplace($attr."=*", $attr."=".$id, $filter);
            },
        );
        $dc = @ldap_connect($this->args['dc'].':'.$port);
        if ($dc &&
            ldap_set_option($dc, LDAP_OPT_PROTOCOL_VERSION, 3) &&
            ldap_set_option($dc, LDAP_OPT_REFERRALS, 0) &&
            ldap_bind($dc, $this->args['rdn'], $this->args['pw']) &&
            ($result = ldap_search(
                $dc,
                $this->args['base_dn'],
                $filter,
                $this->args['attr'],
            )) &&
            ldap_count_entries($dc, $result) &&
            ($info = ldap_get_entries($dc, $result)) &&
            $info['count'] == 1 &&
            @ldap_bind($dc, $info[0]['dn'], $pw) &&
            @ldap_close($dc)) {
            return in_array(
                $id,
                (array_map(function ($value) {
                    return $value[0];
                },
                    array_intersect_key(
                        $info[0],
                        array_flip($this->args['attr']),
                    ))),
                true,
            );
        }
        throw new \Exception(self::E_LDAP);
    }

    /**
     * SMTP storage handler
     */
    protected function _smtp(string $id, string $pw): bool
    {
        $socket = @fsockopen(
            (strtolower($this->args['scheme']) == 'ssl' ?
                'ssl://' : '').$this->args['host'],
            $this->args['port'],
        );
        $dialog = function ($cmd = null) use ($socket) {
            if (!is_null($cmd))
                fputs($socket, $cmd."\r\n");
            $reply = '';
            while (!feof($socket) &&
                ($info = stream_get_meta_data($socket)) &&
                !$info['timed_out'] && $str = fgets($socket, 4096)) {
                $reply .= $str;
                if (preg_match(
                    '/(?:^|\n)\d{3} .+\r\n/s',
                    $reply,
                ))
                    break;
            }
            return $reply;
        };
        if ($socket) {
            stream_set_blocking($socket, true);
            $dialog();
            $fw = Base::instance();
            $dialog('EHLO '.$fw->HOST);
            if (strtolower($this->args['scheme']) == 'tls') {
                $dialog('STARTTLS');
                stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT,
                );
                $dialog('EHLO '.$fw->HOST);
            }
            // Authenticate
            $dialog('AUTH LOGIN');
            $dialog(base64_encode($id));
            $reply = $dialog(base64_encode($pw));
            $dialog('QUIT');
            fclose($socket);
            return \str_starts_with($reply, '235 ');
        }
        throw new \Exception(self::E_SMTP);
    }

    /**
     * Login auth mechanism
     */
    public function login(string $id, string $pw, ?string $realm = null): bool
    {
        return $this->{'_'.$this->storage}($id, $pw, $realm);
    }

    /**
     * HTTP basic auth mechanism
     */
    public function basic(?callable $func = null): bool
    {
        $fw = Base::instance();
        $realm = $fw->REALM;
        $hdr = $fw->SERVER['HTTP_AUTHORIZATION']
            ?? $fw->SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $fw->HEADERS['Authorization']
            ?? null;
        if (!empty($hdr))
            [$fw->SERVER['PHP_AUTH_USER'], $fw->SERVER['PHP_AUTH_PW']] =
                \explode(':', \base64_decode(\substr($hdr, 6)));
        if (isset($fw->SERVER['PHP_AUTH_USER'], $fw->SERVER['PHP_AUTH_PW']) &&
            $this->login(
                $fw->SERVER['PHP_AUTH_USER'],
                $func ?
                    $fw->call($func, $fw->SERVER['PHP_AUTH_PW']) :
                    $fw->SERVER['PHP_AUTH_PW'],
                $realm,
            ))
            return true;
        $fw->header('WWW-Authenticate: Basic realm="'.$realm.'"');
        $fw->status(401);
        return false;
    }

    public function __construct(object|string $storage, ?array $args = null, ?callable $func = null)
    {
        if (\is_object($storage) && \is_a($storage, Cursor::class)) {
            $this->storage = $storage->dbtype();
            $this->mapper = $storage;
        } else
            $this->storage = $storage;
        $this->args = $args;
        $this->func = $func;
    }

}
