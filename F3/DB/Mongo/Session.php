<?php

/*

	Copyright (c) 2009-2019 F3::Factory/Bong Cosca, All rights reserved.

	This file is part of the Fat-Free Framework (http://fatfreeframework.com).

	This is free software: you can redistribute it and/or modify it under the
	terms of the GNU General Public License as published by the Free Software
	Foundation, either version 3 of the License, or later.

	Fat-Free Framework is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
	General Public License for more details.

	You should have received a copy of the GNU General Public License along
	with Fat-Free Framework.  If not, see <http://www.gnu.org/licenses/>.

*/

namespace F3\DB\Mongo;

//! MongoDB-managed session handler
use F3\Base;

class Session extends Mapper implements \SessionHandlerInterface {

	protected
		//! Session ID
		$sid,
		//! Anti-CSRF token
		$_csrf,
		//! User agent
		$_agent,
		//! IP,
		$_ip,
		//! Suspect callback
		$onsuspect;

	/**
	*	Open session
	**/
    public function open(string $path, string $name): bool
    {
		return TRUE;
	}

	/**
	*	Close session
	**/
    public function close(): bool
    {
		$this->reset();
		$this->sid=NULL;
		return TRUE;
	}

	/**
	*	Return session data in serialized format
	**/
    public function read(string $id): false|string
    {
		$this->load(['session_id'=>$this->sid=$id]);
		if ($this->dry())
			return '';
		if ($this->get('ip')!=$this->_ip || $this->get('agent')!=$this->_agent) {
			$fw=Base::instance();
			if (!isset($this->onsuspect) ||
				$fw->call($this->onsuspect,[$this,$id])===FALSE) {
				// NB: `session_destroy` can't be called at that stage;
				// `session_start` not completed
				$this->destroy($id);
				$this->close();
				unset($fw->{'COOKIE.'.session_name()});
				$fw->error(403);
			}
		}
		return $this->get('data');
	}

	/**
	*	Write session data
	**/
    public function write(string $id, string $data): bool
    {
		$this->set('session_id',$id);
		$this->set('data',$data);
		$this->set('ip',$this->_ip);
		$this->set('agent',$this->_agent);
		$this->set('stamp',time());
		$this->save();
		return TRUE;
	}

	/**
	*	Destroy session
	**/
    public function destroy(string $id): bool
    {
		$this->erase(['session_id'=>$id]);
		return TRUE;
	}

	/**
	*	Garbage collector
	**/
    public function gc(int $max_lifetime): int|false
    {
		return (int) $this->erase(['$where'=>'this.stamp+'.$max_lifetime.'<'.time()]);
	}

	/**
	 *	Return session id (if session has started)
	 **/
    public function sid(): ?string
    {
		return $this->sid;
	}

	/**
	 *	Return anti-CSRF token
	 **/
    public function csrf(): string
    {
		return $this->_csrf;
	}

	/**
	 *	Return IP address
	 **/
    public function ip(): string
    {
		return $this->_ip;
	}

	/**
	 *	Return Unix timestamp
	 **/
    public function stamp(): false|string
    {
		if (!$this->sid)
			session_start();
		return $this->dry()?FALSE:$this->get('stamp');
	}

	/**
	 *	Return HTTP user agent
	 *	@return string
	 **/
    public function agent(): string
    {
		return $this->_agent;
	}

    /**
     * Register session handler
     */
	function __construct(\F3\DB\Mongo $db, string $table='sessions', ?callable $onSuspect=NULL, ?string $key=NULL)
    {
		parent::__construct($db,$table);
		$this->onsuspect=$onSuspect;
		session_set_save_handler($this);
		register_shutdown_function('session_commit');
		$fw=Base::instance();
		$headers=$fw->HEADERS;
		$this->_csrf=$fw->hash($fw->SEED.
			extension_loaded('openssl')?
				implode(unpack('L',openssl_random_pseudo_bytes(4))):
				mt_rand()
			);
		if ($key)
			$fw->$key=$this->_csrf;
		$this->_agent=$headers['User-Agent'] ?? '';
		$this->_ip=$fw->IP;
	}

}
