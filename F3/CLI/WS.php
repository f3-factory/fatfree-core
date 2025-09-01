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


namespace F3\CLI;

use JetBrains\PhpStorm\NoReturn;

/**
 * RFC6455 WebSocket server
 */
class WS
{

    const
        //! UUID magic string
        Magic = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
        //! Max packet size
        Packet = 65536;

    //@{ Mask bits for first byte of header
    const
        Text = 0x01,
        Binary = 0x02,
        Close = 0x08,
        Ping = 0x09,
        Pong = 0x0a,
        OpCode = 0x0f,
        Finale = 0x80;
    //@}

    //@{ Mask bits for second byte of header
    const
        Length = 0x7f;
    //@}

    protected $addr;
    protected $ctx;
    protected $wait;
    protected $sockets;
    protected $protocol;
    protected array $agents = [];
    protected array $events = [];

    /**
     * Allocate stream socket
     * @param $socket resource
     */
    public function alloc($socket): void
    {
        if (is_bool($buf = $this->read($socket)))
            return;
        // Get WebSocket headers
        $hdrs = [];
        $EOL = "\r\n";
        $verb = null;
        $uri = null;
        foreach (explode($EOL, trim($buf)) as $line)
            if (preg_match(
                '/^(\w+)\s(.+)\sHTTP\/[\d.]{1,3}$/',
                trim($line),
                $match,
            )) {
                $verb = $match[1];
                $uri = $match[2];
            } elseif (preg_match('/^(.+): (.+)/', trim($line), $match))
                // Standardize header
                $hdrs[strtr(
                    ucwords(
                        strtolower(
                            strtr($match[1], '-', ' '),
                        ),
                    ),
                    ' ',
                    '-',
                )] = $match[2];
            else {
                $this->close($socket);
                return;
            }
        if (empty($hdrs['Upgrade']) &&
            empty($hdrs['Sec-Websocket-Key'])) {
            // Not a WebSocket request
            if ($verb && $uri)
                $this->write(
                    $socket,
                    'HTTP/1.1 400 Bad Request'.$EOL.
                    'Connection: close'.$EOL.$EOL,
                );
            $this->close($socket);
            return;
        }
        // Handshake
        $buf = 'HTTP/1.1 101 Switching Protocols'.$EOL.
            'Upgrade: websocket'.$EOL.
            'Connection: Upgrade'.$EOL;
        if (isset($hdrs['Sec-Websocket-Protocol']))
            $buf .= 'Sec-WebSocket-Protocol: '.
                $hdrs['Sec-Websocket-Protocol'].$EOL;
        $buf .= 'Sec-WebSocket-Accept: '.
            base64_encode(
                sha1($hdrs['Sec-Websocket-Key'].WS::Magic, true),
            ).$EOL.$EOL;
        if ($this->write($socket, $buf)) {
            // Connect agent to server
            $this->sockets[(int) $socket] = $socket;
            $this->agents[(int) $socket] =
                new Agent($this, $socket, $verb, $uri, $hdrs);
        }
    }

    /**
     * Close stream socket
     * @param $socket resource
     */
    public function close($socket): void
    {
        if (isset($this->agents[(int) $socket]))
            unset($this->sockets[(int) $socket], $this->agents[(int) $socket]);
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        @fclose($socket);
    }

    /**
     * Read from stream socket
     * @param $socket resource
     */
    public function read($socket, int $len = 0): false|string
    {
        if (!$len)
            $len = WS::Packet;
        if (is_string($buf = @fread($socket, $len)) &&
            strlen($buf) && strlen($buf) < $len)
            return $buf;
        if (isset($this->events['error']) &&
            is_callable($func = $this->events['error']))
            $func($this);
        $this->close($socket);
        return false;
    }

    /**
     * Write to stream socket
     * @param $socket resource
     */
    public function write($socket, string $buf): false|int
    {
        for ($i = 0, $bytes = 0; $i < strlen($buf); $i += $bytes) {
            if (($bytes = @fwrite($socket, substr($buf, $i))) &&
                @fflush($socket))
                continue;
            if (isset($this->events['error']) &&
                is_callable($func = $this->events['error']))
                $func($this);
            $this->close($socket);
            return false;
        }
        return $bytes;
    }

    /**
     * Return socket agents
     */
    public function agents(?string $uri = null): array
    {
        return array_filter(
            $this->agents,
            fn(Agent $val) => !$uri || $val->uri() == $uri,
        );
    }

    /**
     * Return event handlers
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Bind function to event handler
     */
    public function on(string $event, callable $func): static
    {
        $this->events[$event] = $func;
        return $this;
    }

    /**
     * Terminate server
     */
    #[NoReturn]
    public function kill(): never
    {
        die;
    }

    /**
     *    Execute the server process
     */
    public function run(): void
    {
        // Assign signal handlers
        declare(ticks=1);
        pcntl_signal(SIGINT, [$this, 'kill']);
        pcntl_signal(SIGTERM, [$this, 'kill']);
        gc_enable();
        // Activate WebSocket listener
        $listen = stream_socket_server(
            $this->addr,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $this->ctx,
        );
        $socket = socket_import_stream($listen);
        register_shutdown_function(function () use ($listen) {
            foreach ($this->sockets as $socket)
                if ($socket != $listen)
                    $this->close($socket);
            $this->close($listen);
            if (isset($this->events['stop']) &&
                is_callable($func = $this->events['stop']))
                $func($this);
        });
        if ($errstr)
            throw new \Exception($errstr);
        if (isset($this->events['start']) &&
            is_callable($func = $this->events['start']))
            $func($this);
        $this->sockets = [(int) $listen => $listen];
        $empty = [];
        $wait = $this->wait;
        while (true) {
            $active = $this->sockets;
            $mark = microtime(true);
            $count = @stream_select(
                $active,
                $empty,
                $empty,
                (int) $wait,
                round(1e6 * ($wait - (int) $wait)),
            );
            if (is_bool($count) && $wait) {
                if (isset($this->events['error']) &&
                    is_callable($func = $this->events['error']))
                    $func($this);
                die;
            }
            if ($count) {
                // Process active connections
                foreach ($active as $socket) {
                    if (!is_resource($socket))
                        continue;
                    if ($socket == $listen) {
                        if ($socket = @stream_socket_accept($listen, 0))
                            $this->alloc($socket);
                        elseif (isset($this->events['error']) &&
                            is_callable($func = $this->events['error']))
                            $func($this);
                    } else {
                        $id = (int) $socket;
                        if (isset($this->agents[$id]))
                            $this->agents[$id]->fetch();
                    }
                }
                $wait -= microtime(true) - $mark;
                while ($wait < 1e-6) {
                    $wait += $this->wait;
                    $count = 0;
                }
            }
            if (!$count) {
                $mark = microtime(true);
                foreach ($this->sockets as $id => $socket) {
                    if (!is_resource($socket))
                        continue;
                    if ($socket != $listen &&
                        isset($this->agents[$id]) &&
                        isset($this->events['idle']) &&
                        is_callable($func = $this->events['idle']))
                        $func($this->agents[$id]);
                }
                $wait = $this->wait - microtime(true) + $mark;
            }
            gc_collect_cycles();
        }
    }

    /**
     * @param $ctx resource
     */
    public function __construct(string $addr, $ctx = null, int $wait = 60)
    {
        $this->addr = $addr;
        $this->ctx = $ctx ?: stream_context_create();
        $this->wait = $wait;
        $this->events = [];
    }

}

//! RFC6455 remote socket
class Agent
{

    protected string $id;
    protected $flag;

    /**
     * @param $socket resource
     */
    public function __construct(
        protected WS $server,
        protected $socket,
        protected string $verb,
        protected string $uri,
        protected array $headers = []
    ) {
        $this->id = stream_socket_get_name($socket, true);

        if (isset($server->events()['connect']) &&
            is_callable($func = $server->events()['connect']))
            $func($this);
    }

    /**
     * Return server instance
     */
    public function server(): WS
    {
        return $this->server;
    }

    /**
     * Return socket ID
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return socket
     * @return resource
     */
    public function socket()
    {
        return $this->socket;
    }

    /**
     * Return request method
     */
    public function verb(): string
    {
        return $this->verb;
    }

    /**
     * Return request URI
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Return socket headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Frame and transmit payload
     * @param $op int
     * @param $data string
     **@return string|FALSE
     */
    public function send(int $op, string $data = ''): false|string
    {
        $server = $this->server;
        $mask = WS::Finale | $op & WS::OpCode;
        $len = strlen($data);
        if ($len > 0xffff)
            $buf = pack('CCNN', $mask, 0x7f, $len);
        elseif ($len > 0x7d)
            $buf = pack('CCn', $mask, 0x7e, $len);
        else
            $buf = pack('CC', $mask, $len);
        $buf .= $data;
        if (is_bool($server->write($this->socket, $buf)))
            return false;
        if (!in_array($op, [WS::Pong, WS::Close]) &&
            isset($this->server->events()['send']) &&
            is_callable($func = $this->server->events()['send']))
            $func($this, $op, $data);
        return $data;
    }

    /**
     * Retrieve and unmask payload
     */
    public function fetch(): void
    {
        // Unmask payload
        $server = $this->server;
        if (is_bool($buf = $server->read($this->socket)))
            return;
        while ($buf) {
            $op = ord($buf[0]) & WS::OpCode;
            $len = ord($buf[1]) & WS::Length;
            $pos = 2;
            if ($len == 0x7e) {
                $len = ord($buf[2]) * 256 + ord($buf[3]);
                $pos += 2;
            } elseif ($len == 0x7f) {
                for ($i = 0, $len = 0; $i < 8; ++$i)
                    $len = $len * 256 + ord($buf[$i + 2]);
                $pos += 8;
            }
            for ($i = 0, $mask = []; $i < 4; ++$i)
                $mask[$i] = ord($buf[$pos + $i]);
            $pos += 4;
            if (strlen($buf) < $len + $pos)
                return;
            for ($i = 0, $data = ''; $i < $len; ++$i)
                $data .= chr(ord($buf[$pos + $i]) ^ $mask[$i % 4]);
            // Dispatch
            switch ($op & WS::OpCode) {
                case WS::Ping:
                    $this->send(WS::Pong);
                    break;
                case WS::Close:
                    $server->close($this->socket);
                    break;
                case WS::Text:
                    $data = trim($data);
                case WS::Binary:
                    if (isset($this->server->events()['receive']) &&
                        is_callable($func = $this->server->events()['receive']))
                        $func($this, $op, $data);
                    break;
            }
            $buf = substr($buf, $len + $pos);
        }
    }

    /**
     *    Destroy object
     */
    public function __destruct()
    {
        if (isset($this->server->events()['disconnect']) &&
            is_callable($func = $this->server->events()['disconnect']))
            $func($this);
    }

}
