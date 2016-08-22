<?php

/*

	Copyright (c) 2009-2015 F3::Factory/Bong Cosca, All rights reserved.

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

//! Custom logger
class Log extends \Psr\Log\AbstractLogger {

	protected static $levels;

	protected
		//! File name
		$file;

	/**
	*	Write specified text to log file
	*	@return string
	*	@param $text string
	*	@param $format string
	**/
	function write($text,$format='r') {
		$fw=Base::instance();
		$fw->write(
			$this->file,
			date($format).
				(isset($_SERVER['REMOTE_ADDR'])?
					(' ['.$_SERVER['REMOTE_ADDR'].']'):'').' '.
			trim($text).PHP_EOL,
			TRUE
		);
	}

	/**
	*	Erase log
	*	@return NULL
	**/
	function erase() {
		@unlink($this->file);
	}

	/**
	*	Instantiate class
	*	@param $file string
	**/
	function __construct($file) {
		$fw=Base::instance();
		if (!is_dir($dir=$fw->get('LOGS')))
			mkdir($dir,Base::MODE,TRUE);
		$this->file=$dir.$file;
	}

	/**
	 * Logs with an arbitrary level.
	 * @param string $level
	 * @param string $message
	 * @param array $context
	 */
	public function log($level,$message,array $context=array()) {
		if (self::getLogLevel($level)===null)
			throw new \Psr\Log\InvalidArgumentException("'$level' is not a valid log level");
		$this->write(strtoupper($level).' '.$this->interpolate((string)$message,$context));
	}

	/**
	 * Interpolates context values into the message placeholders.
	 * @param string $message
	 * @param array $context
	 */
	protected function interpolate($message,array $context=array())
	{
		$replace=array();
		foreach ($context as $key=>$val) {
			$replace['{'.$key.'}']=(string)$val;
		}
		return strtr($message,$replace);
	}

	public static function getLogLevel($level=null) {
		if (!self::$levels) {
			$reflection=new ReflectionClass('\Psr\Log\LogLevel');
			self::$levels=array_flip($reflection->getConstants());
		}
		if ($level===null)
			return self::$levels;
		return isset(self::$levels[$level])?self::$levels[$level]:null;
	}
}
