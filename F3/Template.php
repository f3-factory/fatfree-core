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

namespace F3;

//! XML-style template engine
class Template extends Preview {

	const E_Method='Call to undefined method %s()';

	//! Template tags
	protected string $tags;

	//! Custom tag handlers
	protected array $custom=[];

	/**
	 * Template -set- tag handler
	 */
	protected function _set(array $node): string {
		$out='';
		foreach ($node['@attrib'] as $key=>$val)
			$out.='$'.$key.'='.
				(preg_match('/\{\{(.+?)\}\}/',$val?:'')?
					$this->token($val):
					Base::instance()->stringify($val)).'; ';
		return '<?php '.$out.'?>';
	}

	/**
	 * Template -include- tag handler
	 */
	protected function _include(array $node): string {
		$attrib=$node['@attrib'];
		$hive=isset($attrib['with']) &&
			($attrib['with']=$this->token($attrib['with'])) &&
			preg_match_all('/(\w+)\h*=\h*(.+?)(?=,|$)/',
				$attrib['with'],$pairs,PREG_SET_ORDER)?
					('['.implode(',',
						array_map(function($pair) {
							return '\''.$pair[1].'\'=>'.
								(preg_match('/^\'.*\'$/',$pair[2]) ||
									preg_match('/\$/',$pair[2])?
									$pair[2]:Base::instance()->stringify(
										Base::instance()->cast($pair[2])));
						},$pairs)).']+get_defined_vars()'):
					'get_defined_vars()';
		$ttl=isset($attrib['ttl'])?(int)$attrib['ttl']:0;
		return
			'<?php '.(isset($attrib['if'])?
				('if ('.$this->token($attrib['if']).') '):'').
				('echo $this->render('.
					(preg_match('/^\{\{(.+?)\}\}$/',$attrib['href'])?
						$this->token($attrib['href']):
						Base::instance()->stringify($attrib['href'])).','.
					'NULL,'.$hive.','.$ttl.'); ?>');
	}

	/**
	 * Template -exclude- tag handler
	 */
	protected function _exclude(): string {
		return '';
	}

	/**
	 * Template -ignore- tag handler
	 */
	protected function _ignore(array $node): string {
		return $node[0];
	}

	/**
	 * Template -loop- tag handler
	 */
	protected function _loop(array $node): string {
		$attrib=$node['@attrib'];
		unset($node['@attrib']);
		return
			'<?php for ('.
				$this->token($attrib['from']).';'.
				$this->token($attrib['to']).';'.
				$this->token($attrib['step']).'): ?>'.
				$this->build($node).
			'<?php endfor; ?>';
	}

	/**
	 * Template -repeat- tag handler
	 */
	protected function _repeat(array $node): string {
		$attrib=$node['@attrib'];
		unset($node['@attrib']);
		return
			'<?php '.
				(isset($attrib['counter'])?
					(($ctr=$this->token($attrib['counter'])).'=0; '):'').
				'foreach (('.
				$this->token($attrib['group']).'?:[]) as '.
				(isset($attrib['key'])?
					($this->token($attrib['key']).'=>'):'').
				$this->token($attrib['value']).'):'.
				(isset($ctr)?(' '.$ctr.'++;'):'').' ?>'.
				$this->build($node).
			'<?php endforeach; ?>';
	}

	/**
	 * Template -check- tag handler
	 */
	protected function _check(array $node): string {
		$attrib=$node['@attrib'];
		unset($node['@attrib']);
		// Grab <true> and <false> blocks
		foreach ($node as $pos=>$block)
			if (isset($block['true']))
				$true=[$pos,$block];
			elseif (isset($block['false']))
				$false=[$pos,$block];
		if (isset($true,$false) && $true[0]>$false[0])
			// Reverse <true> and <false> blocks
			list($node[$true[0]],$node[$false[0]])=[$false[1],$true[1]];
		return
			'<?php if ('.$this->token($attrib['if']).'): ?>'.
				$this->build($node).
			'<?php endif; ?>';
	}

	/**
	 * Template -true- tag handler
	 */
	protected function _true(array $node): string {
		return $this->build($node);
	}

	/**
	 * Template -false- tag handler
	 */
	protected function _false(array $node): string {
		return '<?php else: ?>'.$this->build($node);
	}

	/**
	 * Template -switch- tag handler
	 */
	protected function _switch(array $node): string {
		$attrib=$node['@attrib'];
		unset($node['@attrib']);
		foreach ($node as $pos=>$block)
			if (is_string($block) && !preg_replace('/\s+/','',$block))
				unset($node[$pos]);
		return
			'<?php switch ('.$this->token($attrib['expr']).'): ?>'.
				$this->build($node).
			'<?php endswitch; ?>';
	}

	/**
	 * Template -case- tag handler
	 */
	protected function _case(array $node): string {
		$attrib=$node['@attrib'];
		unset($node['@attrib']);
		return
			'<?php case '.(preg_match('/\{\{(.+?)\}\}/',$attrib['value'])?
				$this->token($attrib['value']):
				Base::instance()->stringify($attrib['value'])).': ?>'.
				$this->build($node).
			'<?php '.(isset($attrib['break'])?
				'if ('.$this->token($attrib['break']).') ':'').
				'break; ?>';
	}

	/**
	 * Template -default- tag handler
	 */
	protected function _default(array $node): string {
		return
			'<?php default: ?>'.
				$this->build($node).
			'<?php break; ?>';
	}

	/**
	*	Assemble markup
	*	@param $node array|string
	**/
	function build($node): string {
		if (is_string($node))
			return parent::build($node);
		$out='';
		foreach ($node as $key=>$val)
			$out.=is_int($key)?$this->build($val):$this->{'_'.$key}($val);
		return $out;
	}

	/**
	 * Extend template with custom tag
	 */
	function extend(string $tag, callable|string $func): void {
		$this->tags.='|'.$tag;
		$this->custom['_'.$tag]=$func;
	}

	/**
	 * Call custom tag handler
	 */
	function __call(string $func, array $args): string|false {
		if ($func[0]=='_')
			return call_user_func_array($this->custom[$func],$args);
		if (method_exists($this,$func))
			return call_user_func_array([$this,$func],$args);
		user_error(sprintf(self::E_Method,$func),E_USER_ERROR);
		return false;
	}

	/**
	*	Parse string for template directives and tokens
	*	@return array
	**/
	function parse(string $text) {
		$text=parent::parse($text);
		// Build tree structure
		for ($ptr=0,$w=5,$len=strlen($text),$tree=[],$tmp='';$ptr<$len;)
			if (preg_match('/^(.{0,'.$w.'}?)<(\/?)(?:F3:)?'.
				'('.$this->tags.')\b((?:\s+[\w.:@!\-]+'.
				'(?:\h*=\h*(?:"(?:.*?)"|\'(?:.*?)\'))?|'.
				'\h*\{\{.+?\}\})*)\h*(\/?)>/is',
				substr($text,$ptr),$match)) {
				if (strlen($tmp) || isset($match[1]))
					$tree[]=$tmp.$match[1];
				// Element node
				if ($match[2]) {
					// Find matching start tag
					$stack=[];
					for($i=count($tree)-1;$i>=0;--$i) {
						$item=$tree[$i];
						if (is_array($item) &&
							array_key_exists($k=strtolower($match[3]),$item) &&
							!isset($item[$k][0])) {
							// Start tag found
							$tree[$i][$k]+=array_reverse($stack);
							$tree=array_slice($tree,0,$i+1);
							break;
						}
						else $stack[]=$item;
					}
				}
				else {
					// Start tag
					$node=&$tree[][strtolower($match[3])];
					$node=[];
					if ($match[4]) {
						// Process attributes
						preg_match_all(
							'/(?:(\{\{.+?\}\})|([^\s\/"\'=]+))'.
							'\h*(?:=\h*(?:"(.*?)"|\'(.*?)\'))?/s',
							$match[4],$attr,PREG_SET_ORDER);
						foreach ($attr as $kv)
							if (!empty($kv[1]) && !isset($kv[3]) && !isset($kv[4]))
								$node['@attrib'][]=$kv[1];
							else
								$node['@attrib'][$kv[1]?:$kv[2]]=
									(isset($kv[3]) && $kv[3]!==''?
										$kv[3]:
										(isset($kv[4]) && $kv[4]!==''?
											$kv[4]:NULL));
					}
				}
				$tmp='';
				$ptr+=strlen($match[0]);
				$w=5;
			}
			else {
				// Text node
				$tmp.=substr($text,$ptr,$w);
				$ptr+=$w;
				if ($w<50)
					++$w;
			}
		if (strlen($tmp))
			// Append trailing text
			$tree[]=$tmp;
		// Break references
		unset($node);
		return $tree;
	}

	/**
	*	Class constructor
	*	return object
	**/
	function __construct() {
		$ref=new \ReflectionClass(get_called_class());
		$this->tags='';
		foreach ($ref->getmethods() as $method)
			if (preg_match('/^_(?=[[:alpha:]])/',$method->name))
				$this->tags.=(strlen($this->tags)?'|':'').
					substr($method->name,1);
		parent::__construct();
	}

}
