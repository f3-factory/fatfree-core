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


namespace F3\DB\SQL;

use F3\DB\SQL;
use F3\SessionHandler;

/**
 * SQL-managed session handler
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
        $this->load(['session_id=?', $this->sid = $id]);
        if ($this->dry())
            return '';
        if ($this->get('ip') != $this->_ip || $this->get('agent') != $this->_agent) {
            $this->handleSuspiciousSession();
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
        $this->set('stamp', \time());
        $this->save();
        return true;
    }

    /**
     * Destroy session
     */
    public function destroy(string $id): bool
    {
        $this->erase(['session_id=?', $id]);
        return true;
    }

    /**
     * Garbage collector
     */
    public function gc(int $max_lifetime): int|false
    {
        return (int) $this->erase(['stamp+?<?', $max_lifetime, time()]);
    }

    /**
     * Return Unix timestamp
     */
    public function stamp(): false|string
    {
        if (!$this->sid)
            \session_start();
        return $this->dry() ? false : $this->get('stamp');
    }

    /**
     * Register session handler
     * @param SQL $db
     * @param string $table
     * @param bool $force
     * @param callable|null $onSuspect
     * @param string|null $CsrfKeyName
     * @param string $type column type for data field
     */
    public function __construct(
        SQL $db,
        string $table = 'sessions',
        bool $force = true,
        ?callable $onSuspect = null,
        ?string $CsrfKeyName = null,
        string $type = 'TEXT'
    ) {
        if ($force) {
            $eol = "\n";
            $tab = "\t";
            $sqlsrv = preg_match('/mssql|sqlsrv|sybase/', $db->driver());
            $db->exec(
                ($sqlsrv ?
                    ('IF NOT EXISTS (SELECT * FROM sysobjects WHERE '.
                        'name='.$db->quote($table).' AND xtype=\'U\') '.
                        'CREATE TABLE dbo.') :
                    ('CREATE TABLE IF NOT EXISTS '.
                        ((($name = $db->name()) && $db->driver() != 'pgsql') ?
                            ($db->quotekey($name, false).'.') : ''))).
                $db->quotekey($table, false).' ('.$eol.
                ($sqlsrv ? $tab.$db->quotekey('id').' INT IDENTITY,'.$eol : '').
                $tab.$db->quotekey('session_id').' VARCHAR(255),'.$eol.
                $tab.$db->quotekey('data').' '.$type.','.$eol.
                $tab.$db->quotekey('ip').' VARCHAR(45),'.$eol.
                $tab.$db->quotekey('agent').' VARCHAR(300),'.$eol.
                $tab.$db->quotekey('stamp').' INTEGER,'.$eol.
                $tab.'PRIMARY KEY ('.$db->quotekey($sqlsrv ? 'id' : 'session_id').')'.$eol.
                ($sqlsrv ? ',CONSTRAINT [UK_session_id] UNIQUE(session_id)' : '').
                ');',
            );
        }
        parent::__construct($db, $table);
        $this->onSuspect = $onSuspect;
        $this->register($CsrfKeyName);
        if (\strlen($this->_agent) > 300) {
            $this->_agent = \substr($this->_agent, 0, 300);
        }
    }

}
