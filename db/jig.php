<?php

/*
	Copyright (c) 2009-2014 F3::Factory/Bong Cosca, All rights reserved.

	This file is part of the Fat-Free Framework (http://fatfree.sf.net).

	THE SOFTWARE AND DOCUMENTATION ARE PROVIDED "AS IS" WITHOUT WARRANTY OF
	ANY KIND, EITHER EXPRESSED OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
	IMPLIED WARRANTIES OF MERCHANTABILITY AND/OR FITNESS FOR A PARTICULAR
	PURPOSE.

	Please see the license.txt file for more information.
*/

namespace DB;

//! In-memory/flat-file DB wrapper
class Jig {

	//@{ Storage formats
	const
		FORMAT_JSON=0,
		FORMAT_Serialized=1;
	//@}

	protected
		//! UUID
		$uuid,
		//! Storage location
		$dir,
		//! Current storage format
		$format,
		//! Jig log
		$log,
		//! Memory-held data
		$data;

	/**
	*	Read data from memory/file
	*	@return array
	*	@param $file string
	**/
	function read($file) {
		if (!$this->dir)
			return isset($this->data[$file])?$this->data[$file]:array();
		$fw=\Base::instance();
		if (!is_file($dst=$this->dir.$file))
			return array();
		$raw=$fw->read($dst);
		switch ($this->format) {
			case self::FORMAT_JSON:
				$data=json_decode($raw,TRUE);
				break;
			case self::FORMAT_Serialized:
				$data=$fw->unserialize($raw);
				break;
		}
		return $data;
	}

	/**
	*	Write data to memory/file
	*	@return int
	*	@param $file string
	*	@param $data array
	**/
	function write($file,array $data=NULL) {
		if (!$this->dir)
			return count($this->data[$file]=$data);
		$fw=\Base::instance();
		switch ($this->format) {
			case self::FORMAT_JSON:
				$out=json_encode($data,@constant('JSON_PRETTY_PRINT'));
				break;
			case self::FORMAT_Serialized:
				$out=$fw->serialize($data);
				break;
		}
		return $fw->write($this->dir.'/'.$file,$out);
	}

	/**
	*	Return directory
	*	@return string
	**/
	function dir() {
		return $this->dir;
	}

	/**
	*	Return UUID
	*	@return string
	**/
	function uuid() {
		return $this->uuid;
	}

	/**
	*	Return profiler results
	*	@return string
	**/
	function log() {
		return $this->log;
	}

	/**
	*	Jot down log entry
	*	@return NULL
	*	@param $frame string
	**/
	function jot($frame) {
		if ($frame)
			$this->log.=date('r').' '.$frame.PHP_EOL;
	}

	/**
	*	Clean storage
	*	@return NULL
	**/
	function drop() {
		if (!$this->dir)
			$this->data=array();
		elseif ($glob=@glob($this->dir.'/*',GLOB_NOSORT))
			foreach ($glob as $file)
				@unlink($file);
	}

	/**
	*	Instantiate class
	*	@param $dir string
	*	@param $format int
	**/
	function __construct($dir=NULL,$format=self::FORMAT_JSON) {
		if ($dir && !is_dir($dir))
			mkdir($dir,\Base::MODE,TRUE);
		$this->uuid=\Base::instance()->hash($this->dir=$dir);
		$this->format=$format;
	}

}
