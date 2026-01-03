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
 * Cache-based session handler
 */
class Session extends Magic implements \SessionHandlerInterface
{
    use SessionHandler {
        SessionHandler::close as private closeSession;
    }

    /**
     * Cache instance
     */
    protected Cache $_cache;
    /**
     * Session meta data
     */
    protected array $_data = [];

    public const string E_NO_CACHE = 'Cannot initialize cache-based session handler without active cache engine';

    /**
     * Close session
     */
    public function close(): bool
    {
        $this->_data = [];
        return $this->closeSession();
    }

    /**
     * Return session data in serialized format
     */
    public function read(string $id): false|string
    {
        $this->sid = $id;
        if (!$data = $this->_cache->get($id.'.@'))
            return '';
        $this->_data = $data;
        $threadLevel = $this->getThreatLevel($data['ip'], $data['agent']);
        if (($threadLevel >= $this->threatLevelThreshold))
            $this->handleSuspiciousSession();
        else {
            $data['ip'] = $this->_ip;
            $data['agent'] = $this->_agent;
            if ($this->onRead)
                \F3\Base::instance()->call($this->onRead, [$this, $threadLevel]);
        }
        return $data['data'];
    }

    /**
     * Write session data
     */
    public function write(string $id, string $data): bool
    {
        $fw = Base::instance();
        $this->_cache->set(
            $id.'.@',
            [
                'data' => $data,
                'ip' => $this->_ip,
                'agent' => $this->_agent,
                'stamp' => \time(),
            ],
            $fw->JAR->expire,
        );
        return true;
    }

    /**
     * Destroy session
     */
    public function destroy(string $id): bool
    {
        $this->_cache->clear($id.'.@');
        return true;
    }

    /**
     * Garbage collector
     */
    public function gc(int $max_lifetime): int|false
    {
        return (int) $this->_cache->reset('.@', $max_lifetime);
    }

    /**
     *    Return Unix timestamp
     **/
    public function stamp(): false|string
    {
        if (!$this->sid)
            \F3\Base::instance()->session_start();
        return $this->_cache->exists($this->sid.'.@', $data) ?
            $data['stamp'] : false;
    }

    /**
     * Register session handler
     */
    public function __construct(?Cache $cache = null)
    {
        $this->_cache = $cache ?: Cache::instance();
        if (!$this->_cache->engine())
            throw new \Exception(self::E_NO_CACHE);
        $this->register();
    }

    /**
     * check latest meta data existence
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool
    {
        return isset($this->_data[$key]);
    }

    /**
     * get meta data from latest session
     * @param string $key
     * @return mixed
     */
    public function &get(string $key): mixed
    {
        return $this->_data[$key];
    }

    public function set(string $key, mixed $val): mixed
    {
        throw new \Exception('Unable to set data on previous session');
    }

    public function clear(string $key): void
    {
        throw new \Exception('Unable to clear data on previous session');
    }
}
