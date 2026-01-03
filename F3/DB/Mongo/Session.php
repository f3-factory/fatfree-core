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

namespace F3\DB\Mongo;

use F3\SessionHandler;

/**
 * MongoDB-managed session handler
 */
class Session extends Mapper implements \SessionHandlerInterface
{
    use SessionHandler {
        SessionHandler::close as private closeSession;
    }

    /**
     * Close session
     */
    public function close(): bool
    {
        $this->reset();
        return $this->closeSession();
    }

    /**
     * Return session data in serialized format
     */
    public function read(string $id): false|string
    {
        $this->load(['session_id' => $this->sid = $id]);
        if ($this->dry())
            return '';
        $threadLevel = $this->getThreatLevel($this->get('ip'), $this->get('agent'));
        if (($threadLevel >= $this->threatLevelThreshold))
            $this->handleSuspiciousSession();
        else {
            $this->set('ip', $this->_ip);
            $this->set('agent', $this->_agent);
            if ($this->onRead)
                \F3\Base::instance()->call($this->onRead, [$this, $threadLevel]);
        }
        return $this->get('data');
    }

    /**
     * Write session data
     */
    public function write(string $id, string $data): bool
    {
        $this->set('session_id', $id);
        $this->set('data', $data);
        $this->set('ip', $this->_ip);
        $this->set('agent', $this->_agent);
        $this->set('stamp', time());
        $this->save();
        return true;
    }

    /**
     * Destroy session
     */
    public function destroy(string $id): bool
    {
        $this->erase(['session_id' => $id]);
        return true;
    }

    /**
     * Garbage collector
     */
    public function gc(int $max_lifetime): int|false
    {
        return (int) $this->erase(['$where' => 'this.stamp+'.$max_lifetime.'<'.time()]);
    }

    /**
     * Return Unix timestamp
     */
    public function stamp(): false|string
    {
        if (!$this->sid)
            \F3\Base::instance()->session_start();
        return $this->dry() ? false : $this->get('stamp');
    }

    /**
     * Register session handler
     */
    public function __construct(
        \F3\DB\Mongo $db,
        string $table = 'sessions',
    ) {
        parent::__construct($db, $table);
        $this->register();
    }

}
