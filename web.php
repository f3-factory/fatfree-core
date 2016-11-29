<?php

/*

	Copyright (c) 2009-2016 F3::Factory/Bong Cosca, All rights reserved.

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

//! Wrapper for various HTTP utilities
class Web extends Prefab {

	//@{ Error messages
	const
		E_Request='No suitable HTTP request engine found';
	//@}

	protected
		//! HTTP request engine
		$wrapper;

	/**
	*	Detect MIME type using file extension
	*	@return string
	*	@param $file string
	**/
	function mime($file) {
		if (preg_match('/\w+$/',$file,$ext)) {
			$map=[
				'au'=>'audio/basic',
				'avi'=>'video/avi',
				'bmp'=>'image/bmp',
				'bz2'=>'application/x-bzip2',
				'css'=>'text/css',
				'dtd'=>'application/xml-dtd',
				'doc'=>'application/msword',
				'gif'=>'image/gif',
				'gz'=>'application/x-gzip',
				'hqx'=>'application/mac-binhex40',
				'html?'=>'text/html',
				'jar'=>'application/java-archive',
				'jpe?g'=>'image/jpeg',
				'js'=>'application/x-javascript',
				'midi'=>'audio/x-midi',
				'mp3'=>'audio/mpeg',
				'mpe?g'=>'video/mpeg',
				'ogg'=>'audio/vorbis',
				'pdf'=>'application/pdf',
				'png'=>'image/png',
				'ppt'=>'application/vnd.ms-powerpoint',
				'ps'=>'application/postscript',
				'qt'=>'video/quicktime',
				'ram?'=>'audio/x-pn-realaudio',
				'rdf'=>'application/rdf',
				'rtf'=>'application/rtf',
				'sgml?'=>'text/sgml',
				'sit'=>'application/x-stuffit',
				'svg'=>'image/svg+xml',
				'swf'=>'application/x-shockwave-flash',
				'tgz'=>'application/x-tar',
				'tiff'=>'image/tiff',
				'txt'=>'text/plain',
				'wav'=>'audio/wav',
				'xls'=>'application/vnd.ms-excel',
				'xml'=>'application/xml',
				'zip'=>'application/x-zip-compressed'
			];
			foreach ($map as $key=>$val)
				if (preg_match('/'.$key.'/',strtolower($ext[0])))
					return $val;
		}
		return 'application/octet-stream';
	}

	/**
	*	Return the MIME types stated in the HTTP Accept header as an array;
	*	If a list of MIME types is specified, return the best match; or
	*	FALSE if none found
	*	@return array|string|FALSE
	*	@param $list string|array
	**/
	function acceptable($list=NULL) {
		$accept=[];
		foreach (explode(',',str_replace(' ','',@$_SERVER['HTTP_ACCEPT']))
			as $mime)
			if (preg_match('/(.+?)(?:;q=([\d\.]+)|$)/',$mime,$parts))
				$accept[$parts[1]]=isset($parts[2])?$parts[2]:1;
		if (!$accept)
			$accept['*/*']=1;
		else {
			krsort($accept);
			arsort($accept);
		}
		if ($list) {
			if (is_string($list))
				$list=explode(',',$list);
			foreach ($accept as $mime=>$q)
				if ($q && $out=preg_grep('/'.
					str_replace('\*','.*',preg_quote($mime,'/')).'/',$list))
					return current($out);
			return FALSE;
		}
		return $accept;
	}

	/**
	*	Transmit file to HTTP client; Return file size if successful,
	*	FALSE otherwise
	*	@return int|FALSE
	*	@param $file string
	*	@param $mime string
	*	@param $kbps int
	*	@param $force bool
	*	@param $name string
	*	@param $flush bool
	**/
	function send($file,$mime=NULL,$kbps=0,$force=TRUE,$name=NULL,$flush=TRUE) {
		if (!is_file($file))
			return FALSE;
		$size=filesize($file);
		if (PHP_SAPI!='cli') {
			header('Content-Type: '.($mime?:$this->mime($file)));
			if ($force)
				header('Content-Disposition: attachment; '.
					'filename="'.($name!==NULL?$name:basename($file)).'"');
			header('Accept-Ranges: bytes');
			header('Content-Length: '.$size);
			header('X-Powered-By: '.Base::instance()->get('PACKAGE'));
		}
		if(!$kbps && $flush) {
			while (ob_get_level())
				ob_end_clean();
			readfile($file);
			return $size;
		}
		$ctr=0;
		$handle=fopen($file,'rb');
		$start=microtime(TRUE);
		while (!feof($handle) &&
			($info=stream_get_meta_data($handle)) &&
			!$info['timed_out'] && !connection_aborted()) {
			if ($kbps) {
				// Throttle output
				$ctr++;
				if ($ctr/$kbps>$elapsed=microtime(TRUE)-$start)
					usleep(1e6*($ctr/$kbps-$elapsed));
			}
			// Send 1KiB and reset timer
			echo fread($handle,1024);
			if ($flush) {
				ob_flush();
				flush();
			}
		}
		fclose($handle);
		return $size;
	}

	/**
	*	Receive file(s) from HTTP client
	*	@return array|bool
	*	@param $func callback
	*	@param $overwrite bool
	*	@param $slug callback|bool
	**/
	function receive($func=NULL,$overwrite=FALSE,$slug=TRUE) {
		$fw=Base::instance();
		$dir=$fw->get('UPLOADS');
		if (!is_dir($dir))
			mkdir($dir,Base::MODE,TRUE);
		if ($fw->get('VERB')=='PUT') {
			$tmp=$fw->get('TEMP').$fw->get('SEED').'.'.$fw->hash(uniqid());
			if (!$fw->get('RAW'))
				$fw->write($tmp,$fw->get('BODY'));
			else {
				$src=@fopen('php://input','r');
				$dst=@fopen($tmp,'w');
				if (!$src || !$dst)
					return FALSE;
				while (!feof($src) &&
					($info=stream_get_meta_data($src)) &&
					!$info['timed_out'] && $str=fgets($src,4096))
					fputs($dst,$str,strlen($str));
				fclose($dst);
				fclose($src);
			}
			$base=basename($fw->get('URI'));
			$file=[
				'name'=>$dir.
					($slug && preg_match('/(.+?)(\.\w+)?$/',$base,$parts)?
						(is_callable($slug)?
							$slug($base):
							($this->slug($parts[1]).
								(isset($parts[2])?$parts[2]:''))):
						$base),
				'tmp_name'=>$tmp,
				'type'=>$this->mime($base),
				'size'=>filesize($tmp)
			];
			return (!file_exists($file['name']) || $overwrite) &&
				(!$func || $fw->call($func,[$file])!==FALSE) &&
				rename($tmp,$file['name']);
		}
		$fetch=function($arr) use(&$fetch) {
			if (!is_array($arr))
				return [$arr];
			$data=[];
			foreach($arr as $k=>$sub)
				$data=array_merge($data,$fetch($sub));
			return $data;
		};
		$out=[];
		foreach ($_FILES as $name=>$item) {
			$files=[];
			foreach ($item as $k=>$mix)
				foreach ($fetch($mix) as $i=>$val)
					$files[$i][$k]=$val;
			foreach ($files as $file) {
				if (empty($file['name']))
					continue;
				$base=basename($file['name']);
				$file['name']=$dir.
					($slug && preg_match('/(.+?)(\.\w+)?$/',$base,$parts)?
						(is_callable($slug)?
							$slug($base,$name):
							($this->slug($parts[1]).
								(isset($parts[2])?$parts[2]:''))):
						$base);
				$out[$file['name']]=!$file['error'] &&
					(!file_exists($file['name']) || $overwrite) &&
					(!$func || $fw->call($func,[$file,$name])!==FALSE) &&
					move_uploaded_file($file['tmp_name'],$file['name']);
			}
		}
		return $out;
	}

	/**
	*	Return upload progress in bytes, FALSE on failure
	*	@return int|FALSE
	*	@param $id string
	**/
	function progress($id) {
		// ID returned by session.upload_progress.name
		return ini_get('session.upload_progress.enabled') &&
			isset($_SESSION[$id]['bytes_processed'])?
				$_SESSION[$id]['bytes_processed']:FALSE;
	}

	/**
	*	HTTP request via cURL
	*	@return array
	*	@param $url string
	*	@param $options array
	**/
	protected function _curl($url,$options) {
		$curl=curl_init($url);
		if (!ini_get('open_basedir'))
			curl_setopt($curl,CURLOPT_FOLLOWLOCATION,
				$options['follow_location']);
		curl_setopt($curl,CURLOPT_MAXREDIRS,
			$options['max_redirects']);
		curl_setopt($curl,CURLOPT_PROTOCOLS,CURLPROTO_HTTP|CURLPROTO_HTTPS);
		curl_setopt($curl,CURLOPT_REDIR_PROTOCOLS,CURLPROTO_HTTP|CURLPROTO_HTTPS);
		curl_setopt($curl,CURLOPT_CUSTOMREQUEST,$options['method']);
		if (isset($options['header']))
			curl_setopt($curl,CURLOPT_HTTPHEADER,$options['header']);
		if (isset($options['content']))
			curl_setopt($curl,CURLOPT_POSTFIELDS,$options['content']);
		curl_setopt($curl,CURLOPT_ENCODING,'gzip,deflate');
		$timeout=isset($options['timeout'])?
			$options['timeout']:
			ini_get('default_socket_timeout');
		curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,$timeout);
		curl_setopt($curl,CURLOPT_TIMEOUT,$timeout);
		$headers=[];
		curl_setopt($curl,CURLOPT_HEADERFUNCTION,
			// Callback for response headers
			function($curl,$line) use(&$headers) {
				if ($trim=trim($line))
					$headers[]=$trim;
				return strlen($line);
			}
		);
		curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,2);
		curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,FALSE);
		ob_start();
		curl_exec($curl);
		$err=curl_error($curl);
		curl_close($curl);
		$body=ob_get_clean();
		if (!$err &&
			$options['follow_location'] &&
			preg_match('/^Location: (.+)$/m',implode(PHP_EOL,$headers),$loc)) {
			$options['max_redirects']--;
			if($loc[1][0] == '/') {
				$parts=parse_url($url);
				$loc[1]=$parts['scheme'].'://'.$parts['host'].
					((isset($parts['port']) && !in_array($parts['port'],[80,443]))
						?':'.$parts['port']:'').$loc[1];
			}
			return $this->request($loc[1],$options);
		}
		return [
			'body'=>$body,
			'headers'=>$headers,
			'engine'=>'cURL',
			'cached'=>FALSE,
			'error'=>$err
		];
	}

	/**
	*	HTTP request via PHP stream wrapper
	*	@return array
	*	@param $url string
	*	@param $options array
	**/
	protected function _stream($url,$options) {
		$eol="\r\n";
		$options['header']=implode($eol,$options['header']);
		$body=@file_get_contents($url,FALSE,
			stream_context_create(['http'=>$options]));
		$headers=isset($http_response_header)?
			$http_response_header:[];
		$err='';
		if (is_string($body)) {
			$match=NULL;
			foreach ($headers as $header)
				if (preg_match('/Content-Encoding: (.+)/',$header,$match))
					break;
			if ($match)
				switch ($match[1]) {
					case 'gzip':
						$body=gzdecode($body);
						break;
					case 'deflate':
						$body=gzuncompress($body);
						break;
				}
		}
		else {
			$tmp=error_get_last();
			$err=$tmp['message'];
		}
		return [
			'body'=>$body,
			'headers'=>$headers,
			'engine'=>'stream',
			'cached'=>FALSE,
			'error'=>$err
		];
	}

	/**
	*	HTTP request via low-level TCP/IP socket
	*	@return array
	*	@param $url string
	*	@param $options array
	**/
	protected function _socket($url,$options) {
		$eol="\r\n";
		$headers=[];
		$body='';
		$parts=parse_url($url);
		$empty=empty($parts['port']);
		if ($parts['scheme']=='https') {
			$parts['host']='ssl://'.$parts['host'];
			if ($empty)
				$parts['port']=443;
		}
		elseif ($empty)
			$parts['port']=80;
		if (empty($parts['path']))
			$parts['path']='/';
		if (empty($parts['query']))
			$parts['query']='';
		if ($socket=@fsockopen($parts['host'],$parts['port'],$code,$err)) {
			stream_set_blocking($socket,TRUE);
			stream_set_timeout($socket,isset($options['timeout'])?
				$options['timeout']:ini_get('default_socket_timeout'));
			fputs($socket,$options['method'].' '.$parts['path'].
				($parts['query']?('?'.$parts['query']):'').' HTTP/1.0'.$eol
			);
			fputs($socket,implode($eol,$options['header']).$eol.$eol);
			if (isset($options['content']))
				fputs($socket,$options['content'].$eol);
			// Get response
			$content='';
			while (!feof($socket) &&
				($info=stream_get_meta_data($socket)) &&
				!$info['timed_out'] && !connection_aborted() &&
				$str=fgets($socket,4096))
				$content.=$str;
			fclose($socket);
			$html=explode($eol.$eol,$content,2);
			$body=isset($html[1])?$html[1]:'';
			$headers=array_merge($headers,$current=explode($eol,$html[0]));
			$match=NULL;
			foreach ($current as $header)
				if (preg_match('/Content-Encoding: (.+)/',$header,$match))
					break;
			if ($match)
				switch ($match[1]) {
					case 'gzip':
						$body=gzdecode($body);
						break;
					case 'deflate':
						$body=gzuncompress($body);
						break;
				}
			if ($options['follow_location'] &&
				preg_match('/Location: (.+?)'.preg_quote($eol).'/',
				$html[0],$loc)) {
				$options['max_redirects']--;
				return $this->request($loc[1],$options);
			}
		}
		return [
			'body'=>$body,
			'headers'=>$headers,
			'engine'=>'socket',
			'cached'=>FALSE,
			'error'=>$err
		];
	}

	/**
	*	Specify the HTTP request engine to use; If not available,
	*	fall back to an applicable substitute
	*	@return string
	*	@param $arg string
	**/
	function engine($arg='curl') {
		$arg=strtolower($arg);
		$flags=[
			'curl'=>extension_loaded('curl'),
			'stream'=>ini_get('allow_url_fopen'),
			'socket'=>function_exists('fsockopen')
		];
		if ($flags[$arg])
			return $this->wrapper=$arg;
		foreach ($flags as $key=>$val)
			if ($val)
				return $this->wrapper=$key;
		user_error(self::E_Request,E_USER_ERROR);
	}

	/**
	*	Replace old headers with new elements
	*	@return NULL
	*	@param $old array
	*	@param $new string|array
	**/
	function subst(array &$old,$new) {
		if (is_string($new))
			$new=[$new];
		foreach ($new as $hdr) {
			$old=preg_grep('/'.preg_quote(strstr($hdr,':',TRUE),'/').':.+/',
				$old,PREG_GREP_INVERT);
			array_push($old,$hdr);
		}
	}

	/**
	*	Submit HTTP request; Use HTTP context options (described in
	*	http://www.php.net/manual/en/context.http.php) if specified;
	*	Cache the page as instructed by remote server
	*	@return array|FALSE
	*	@param $url string
	*	@param $options array
	**/
	function request($url,array $options=NULL) {
		$fw=Base::instance();
		$parts=parse_url($url);
		if (empty($parts['scheme'])) {
			// Local URL
			$url=$fw->get('SCHEME').'://'.
				$fw->get('HOST').
				($url[0]!='/'?($fw->get('BASE').'/'):'').$url;
			$parts=parse_url($url);
		}
		elseif (!preg_match('/https?/',$parts['scheme']))
			return FALSE;
		if (!is_array($options))
			$options=[];
		if (empty($options['header']))
			$options['header']=[];
		elseif (is_string($options['header']))
			$options['header']=[$options['header']];
		if (!$this->wrapper)
			$this->engine();
		if ($this->wrapper!='stream') {
			// PHP streams can't cope with redirects when Host header is set
			foreach ($options['header'] as &$header)
				if (preg_match('/^Host:/',$header)) {
					$header='Host: '.$parts['host'];
					unset($header);
					break;
				}
			$this->subst($options['header'],'Host: '.$parts['host']);
		}
		$this->subst($options['header'],
			[
				'Accept-Encoding: gzip,deflate',
				'User-Agent: '.(isset($options['user_agent'])?
					$options['user_agent']:
					'Mozilla/5.0 (compatible; '.php_uname('s').')'),
				'Connection: close'
			]
		);
		if (isset($options['content']) && is_string($options['content'])) {
			if ($options['method']=='POST' &&
				!preg_grep('/^Content-Type:/',$options['header']))
				$this->subst($options['header'],
					'Content-Type: application/x-www-form-urlencoded');
			$this->subst($options['header'],
				'Content-Length: '.strlen($options['content']));
		}
		if (isset($parts['user'],$parts['pass']))
			$this->subst($options['header'],
				'Authorization: Basic '.
					base64_encode($parts['user'].':'.$parts['pass'])
			);
		$options+=[
			'method'=>'GET',
			'header'=>$options['header'],
			'follow_location'=>TRUE,
			'max_redirects'=>20,
			'ignore_errors'=>FALSE
		];
		$eol="\r\n";
		if ($fw->get('CACHE') &&
			preg_match('/GET|HEAD/',$options['method'])) {
			$cache=Cache::instance();
			if ($cache->exists(
				$hash=$fw->hash($options['method'].' '.$url).'.url',$data)) {
				if (preg_match('/Last-Modified: (.+?)'.preg_quote($eol).'/',
					implode($eol,$data['headers']),$mod))
					$this->subst($options['header'],
						'If-Modified-Since: '.$mod[1]);
			}
		}
		$result=$this->{'_'.$this->wrapper}($url,$options);
		if ($result && isset($cache)) {
			if (preg_match('/HTTP\/1\.\d 304/',
				implode($eol,$result['headers']))) {
				$result=$cache->get($hash);
				$result['cached']=TRUE;
			}
			elseif (preg_match('/Cache-Control:(?:.*)max-age=(\d+)(?:,?.*'.
				preg_quote($eol).')/',implode($eol,$result['headers']),$exp))
				$cache->set($hash,$result,$exp[1]);
		}
		$req=[$options['method'].' '.$url];
		foreach ($options['header'] as $header)
			array_push($req,$header);
		return array_merge(['request'=>$req],$result);
	}

	/**
	*	Strip Javascript/CSS files of extraneous whitespaces and comments;
	*	Return combined output as a minified string
	*	@return string
	*	@param $files string|array
	*	@param $mime string
	*	@param $header bool
	*	@param $path string
	**/
	function minify($files,$mime=NULL,$header=TRUE,$path=NULL) {
		$fw=Base::instance();
		if (is_string($files))
			$files=$fw->split($files);
		if (!$mime)
			$mime=$this->mime($files[0]);
		preg_match('/\w+$/',$files[0],$ext);
		$cache=Cache::instance();
		$dst='';
		if (!isset($path))
			$path=$fw->get('UI').';./';
		foreach ($fw->split($path,FALSE) as $dir)
			foreach ($files as $file)
				if (is_file($save=$fw->fixslashes($dir.$file)) &&
					is_bool(strpos($save,'../')) &&
					preg_match('/\.(css|js)$/i',$file)) {
					if ($fw->get('CACHE') &&
						($cached=$cache->exists(
							$hash=$fw->hash($save).'.'.$ext[0],$data)) &&
						$cached[0]>filemtime($save))
						$dst.=$data;
					else {
						$data='';
						$src=$fw->read($save);
						for ($ptr=0,$len=strlen($src);$ptr<$len;) {
							if (preg_match('/^@import\h+url'.
								'\(\h*([\'"])((?!(?:https?:)?\/\/).+?)\1\h*\)[^;]*;/',
								substr($src,$ptr),$parts)) {
								$path=dirname($file);
								$data.=$this->minify(
									($path?($path.'/'):'').$parts[2],
									$mime,$header
								);
								$ptr+=strlen($parts[0]);
								continue;
							}
							if ($src[$ptr]=='/') {
								if ($src[$ptr+1]=='*') {
									// Multiline comment
									$str=strstr(
										substr($src,$ptr+2),'*/',TRUE);
									$ptr+=strlen($str)+4;
								}
								elseif ($src[$ptr+1]=='/') {
									// Single-line comment
									$str=strstr(
										substr($src,$ptr+2),"\n",TRUE);
									$ptr+=(empty($str))?
										strlen(substr($src,$ptr)):strlen($str)+2;
								}
								else {
									// Presume it's a regex pattern
									$regex=TRUE;
									// Backtrack and validate
									for ($ofs=$ptr;$ofs;$ofs--) {
										// Pattern should be preceded by
										// open parenthesis, colon,
										// object property or operator
										if (preg_match(
											'/(return|[(:=!+\-*&|])$/',
											substr($src,0,$ofs))) {
											$data.='/';
											$ptr++;
											while ($ptr<$len) {
												$data.=$src[$ptr];
												$ptr++;
												if ($src[$ptr-1]=='\\') {
													$data.=$src[$ptr];
													$ptr++;
												}
												elseif ($src[$ptr-1]=='/')
													break;
											}
											break;
										}
										elseif (!ctype_space($src[$ofs-1])) {
											// Not a regex pattern
											$regex=FALSE;
											break;
										}
									}
									if (!$regex) {
										// Division operator
										$data.=$src[$ptr];
										$ptr++;
									}
								}
								continue;
							}
							if (in_array($src[$ptr],['\'','"'])) {
								$match=$src[$ptr];
								$data.=$match;
								$ptr++;
								// String literal
								while ($ptr<$len) {
									$data.=$src[$ptr];
									$ptr++;
									if ($src[$ptr-1]=='\\') {
										$data.=$src[$ptr];
										$ptr++;
									}
									elseif ($src[$ptr-1]==$match)
										break;
								}
								continue;
							}
							if (ctype_space($src[$ptr])) {
								if ($ptr+1<strlen($src) &&
									preg_match('/[\w'.($ext[0]=='css'?
										'#\.%+\-*()\[\]':'\$').']{2}|'.
										'[+\-]{2}/',
										substr($data,-1).$src[$ptr+1]))
									$data.=' ';
								$ptr++;
								continue;
							}
							$data.=$src[$ptr];
							$ptr++;
						}
						if ($fw->get('CACHE'))
							$cache->set($hash,$data);
						$dst.=$data;
					}
				}
		if (PHP_SAPI!='cli' && $header)
			header('Content-Type: '.$mime.'; charset='.$fw->get('ENCODING'));
		return $dst;
	}

	/**
	*	Retrieve RSS feed and return as an array
	*	@return array|FALSE
	*	@param $url string
	*	@param $max int
	*	@param $tags string
	**/
	function rss($url,$max=10,$tags=NULL) {
		if (!$data=$this->request($url))
			return FALSE;
		// Suppress errors caused by invalid XML structures
		libxml_use_internal_errors(TRUE);
		$xml=simplexml_load_string($data['body'],
			NULL,LIBXML_NOBLANKS|LIBXML_NOERROR);
		if (!is_object($xml))
			return FALSE;
		$out=[];
		if (isset($xml->channel)) {
			$out['source']=(string)$xml->channel->title;
			$max=min($max,count($xml->channel->item));
			for ($i=0;$i<$max;$i++) {
				$item=$xml->channel->item[$i];
				$list=[''=>NULL]+$item->getnamespaces(TRUE);
				$fields=[];
				foreach ($list as $ns=>$uri)
					foreach ($item->children($uri) as $key=>$val)
						$fields[$ns.($ns?':':'').$key]=(string)$val;
				$out['feed'][]=$fields;
			}
		}
		else
			return FALSE;
		Base::instance()->scrub($out,$tags);
		return $out;
	}

	/**
	*	Retrieve information from whois server
	*	@return string|FALSE
	*	@param $addr string
	*	@param $server string
	**/
	function whois($addr,$server='whois.internic.net') {
		$socket=@fsockopen($server,43,$errno,$errstr);
		if (!$socket)
			// Can't establish connection
			return FALSE;
		// Set connection timeout parameters
		stream_set_blocking($socket,FALSE);
		stream_set_timeout($socket,ini_get('default_socket_timeout'));
		// Send request
		fputs($socket,$addr."\r\n");
		$info=stream_get_meta_data($socket);
		// Get response
		$response='';
		while (!feof($socket) && !$info['timed_out']) {
			$response.=fgets($socket,4096); // MDFK97
			$info=stream_get_meta_data($socket);
		}
		fclose($socket);
		return $info['timed_out']?FALSE:trim($response);
	}

	/**
	*	Return a URL/filesystem-friendly version of string
	*	@return string
	*	@param $text string
	**/
	function slug($text) {
		return trim(strtolower(preg_replace('/([^\pL\pN])+/u','-',
			trim(strtr(str_replace('\'','',$text),
			[
				'Ç�'=>'A','Ð�'=>'A','Ä€'=>'A','Ä‚'=>'A','Ä„'=>'A','Ã…'=>'A',
				'Çº'=>'A','Ã„'=>'Ae','Ã�'=>'A','Ã€'=>'A','Ãƒ'=>'A','Ã‚'=>'A',
				'Ã†'=>'AE','Ç¼'=>'AE','Ð‘'=>'B','Ã‡'=>'C','Ä†'=>'C','Äˆ'=>'C',
				'ÄŒ'=>'C','ÄŠ'=>'C','Ð¦'=>'C','Ð§'=>'Ch','Ã�'=>'Dj','Ä�'=>'Dj',
				'ÄŽ'=>'Dj','Ð”'=>'Dj','Ã‰'=>'E','Ä˜'=>'E','Ð�'=>'E','Ä–'=>'E',
				'ÃŠ'=>'E','Äš'=>'E','Ä’'=>'E','Ãˆ'=>'E','Ð•'=>'E','Ð­'=>'E',
				'Ã‹'=>'E','Ä”'=>'E','Ð¤'=>'F','Ð“'=>'G','Ä¢'=>'G','Ä '=>'G',
				'Äœ'=>'G','Äž'=>'G','Ð¥'=>'H','Ä¤'=>'H','Ä¦'=>'H','Ã�'=>'I',
				'Ä¬'=>'I','Ä°'=>'I','Ä®'=>'I','Äª'=>'I','Ã�'=>'I','ÃŒ'=>'I',
				'Ð˜'=>'I','Ç�'=>'I','Ä¨'=>'I','ÃŽ'=>'I','Ä²'=>'IJ','Ä´'=>'J',
				'Ð™'=>'J','Ð¯'=>'Ja','Ð®'=>'Ju','Ðš'=>'K','Ä¶'=>'K','Ä¹'=>'L',
				'Ð›'=>'L','Å�'=>'L','Ä¿'=>'L','Ä»'=>'L','Ä½'=>'L','Ðœ'=>'M',
				'Ð�'=>'N','Åƒ'=>'N','Ã‘'=>'N','Å…'=>'N','Å‡'=>'N','ÅŒ'=>'O',
				'Ðž'=>'O','Ç¾'=>'O','Ç‘'=>'O','Æ '=>'O','ÅŽ'=>'O','Å�'=>'O',
				'Ã˜'=>'O','Ã–'=>'Oe','Ã•'=>'O','Ã“'=>'O','Ã’'=>'O','Ã”'=>'O',
				'Å’'=>'OE','ÐŸ'=>'P','Å–'=>'R','Ð '=>'R','Å˜'=>'R','Å”'=>'R',
				'Åœ'=>'S','Åž'=>'S','Å '=>'S','È˜'=>'S','Åš'=>'S','Ð¡'=>'S',
				'Ð¨'=>'Sh','Ð©'=>'Shch','Å¤'=>'T','Å¦'=>'T','Å¢'=>'T','Èš'=>'T',
				'Ð¢'=>'T','Å®'=>'U','Å°'=>'U','Å¬'=>'U','Å¨'=>'U','Å²'=>'U',
				'Åª'=>'U','Ç›'=>'U','Ç™'=>'U','Ã™'=>'U','Ãš'=>'U','Ãœ'=>'Ue',
				'Ç—'=>'U','Ç•'=>'U','Ð£'=>'U','Æ¯'=>'U','Ç“'=>'U','Ã›'=>'U',
				'Ð’'=>'V','Å´'=>'W','Ð«'=>'Y','Å¶'=>'Y','Ã�'=>'Y','Å¸'=>'Y',
				'Å¹'=>'Z','Ð—'=>'Z','Å»'=>'Z','Å½'=>'Z','Ð–'=>'Zh','Ã¡'=>'a',
				'Äƒ'=>'a','Ã¢'=>'a','Ã '=>'a','Ä�'=>'a','Ç»'=>'a','Ã¥'=>'a',
				'Ã¤'=>'ae','Ä…'=>'a','ÇŽ'=>'a','Ã£'=>'a','Ð°'=>'a','Âª'=>'a',
				'Ã¦'=>'ae','Ç½'=>'ae','Ð±'=>'b','Ä�'=>'c','Ã§'=>'c','Ñ†'=>'c',
				'Ä‹'=>'c','Ä‰'=>'c','Ä‡'=>'c','Ñ‡'=>'ch','Ã°'=>'dj','Ä�'=>'dj',
				'Ð´'=>'dj','Ä‘'=>'dj','Ñ�'=>'e','Ã©'=>'e','Ñ‘'=>'e','Ã«'=>'e',
				'Ãª'=>'e','Ðµ'=>'e','Ä•'=>'e','Ã¨'=>'e','Ä™'=>'e','Ä›'=>'e',
				'Ä—'=>'e','Ä“'=>'e','Æ’'=>'f','Ñ„'=>'f','Ä¡'=>'g','Ä�'=>'g',
				'ÄŸ'=>'g','Ð³'=>'g','Ä£'=>'g','Ñ…'=>'h','Ä¥'=>'h','Ä§'=>'h',
				'Ç�'=>'i','Ä­'=>'i','Ð¸'=>'i','Ä«'=>'i','Ä©'=>'i','Ä¯'=>'i',
				'Ä±'=>'i','Ã¬'=>'i','Ã®'=>'i','Ã­'=>'i','Ã¯'=>'i','Ä³'=>'ij',
				'Äµ'=>'j','Ð¹'=>'j','Ñ�'=>'ja','ÑŽ'=>'ju','Ä·'=>'k','Ðº'=>'k',
				'Ä¾'=>'l','Å‚'=>'l','Å€'=>'l','Äº'=>'l','Ä¼'=>'l','Ð»'=>'l',
				'Ð¼'=>'m','Å†'=>'n','Ã±'=>'n','Å„'=>'n','Ð½'=>'n','Åˆ'=>'n',
				'Å‰'=>'n','Ã³'=>'o','Ã²'=>'o','Ç’'=>'o','Å‘'=>'o','Ð¾'=>'o',
				'Å�'=>'o','Âº'=>'o','Æ¡'=>'o','Å�'=>'o','Ã´'=>'o','Ã¶'=>'oe',
				'Ãµ'=>'o','Ã¸'=>'o','Ç¿'=>'o','Å“'=>'oe','Ð¿'=>'p','Ñ€'=>'r',
				'Å™'=>'r','Å•'=>'r','Å—'=>'r','Å¿'=>'s','Å�'=>'s','È™'=>'s',
				'Å¡'=>'s','Å›'=>'s','Ñ�'=>'s','ÅŸ'=>'s','Ñˆ'=>'sh','Ñ‰'=>'shch',
				'ÃŸ'=>'ss','Å£'=>'t','Ñ‚'=>'t','Å§'=>'t','Å¥'=>'t','È›'=>'t',
				'Ñƒ'=>'u','Ç˜'=>'u','Å­'=>'u','Ã»'=>'u','Ãº'=>'u','Å³'=>'u',
				'Ã¹'=>'u','Å±'=>'u','Å¯'=>'u','Æ°'=>'u','Å«'=>'u','Çš'=>'u',
				'Çœ'=>'u','Ç”'=>'u','Ç–'=>'u','Å©'=>'u','Ã¼'=>'ue','Ð²'=>'v',
				'Åµ'=>'w','Ñ‹'=>'y','Ã¿'=>'y','Ã½'=>'y','Å·'=>'y','Åº'=>'z',
				'Å¾'=>'z','Ð·'=>'z','Å¼'=>'z','Ð¶'=>'zh','ÑŒ'=>'','ÑŠ'=>''
			]+Base::instance()->get('DIACRITICS'))))),'-');
	}

	/**
	*	Return chunk of text from standard Lorem Ipsum passage
	*	@return string
	*	@param $count int
	*	@param $max int
	*	@param $std bool
	**/
	function filler($count=1,$max=20,$std=TRUE) {
		$out='';
		if ($std)
			$out='Lorem ipsum dolor sit amet, consectetur adipisicing elit, '.
				'sed do eiusmod tempor incididunt ut labore et dolore magna '.
				'aliqua.';
		$rnd=explode(' ',
			'a ab ad accusamus adipisci alias aliquam amet animi aperiam '.
			'architecto asperiores aspernatur assumenda at atque aut beatae '.
			'blanditiis cillum commodi consequatur corporis corrupti culpa '.
			'cum cupiditate debitis delectus deleniti deserunt dicta '.
			'dignissimos distinctio dolor ducimus duis ea eaque earum eius '.
			'eligendi enim eos error esse est eum eveniet ex excepteur '.
			'exercitationem expedita explicabo facere facilis fugiat harum '.
			'hic id illum impedit in incidunt ipsa iste itaque iure iusto '.
			'laborum laudantium libero magnam maiores maxime minim minus '.
			'modi molestiae mollitia nam natus necessitatibus nemo neque '.
			'nesciunt nihil nisi nobis non nostrum nulla numquam occaecati '.
			'odio officia omnis optio pariatur perferendis perspiciatis '.
			'placeat porro possimus praesentium proident quae quia quibus '.
			'quo ratione recusandae reiciendis rem repellat reprehenderit '.
			'repudiandae rerum saepe sapiente sequi similique sint soluta '.
			'suscipit tempora tenetur totam ut ullam unde vel veniam vero '.
			'vitae voluptas');
		for ($i=0,$add=$count-(int)$std;$i<$add;$i++) {
			shuffle($rnd);
			$words=array_slice($rnd,0,mt_rand(3,$max));
			$out.=' '.ucfirst(implode(' ',$words)).'.';
		}
		return $out;
	}

}

if (!function_exists('gzdecode')) {

	/**
	*	Decode gzip-compressed string
	*	@param $str string
	**/
	function gzdecode($str) {
		$fw=Base::instance();
		if (!is_dir($tmp=$fw->get('TEMP')))
			mkdir($tmp,Base::MODE,TRUE);
		file_put_contents($file=$tmp.'/'.$fw->get('SEED').'.'.
			$fw->hash(uniqid(NULL,TRUE)).'.gz',$str,LOCK_EX);
		ob_start();
		readgzfile($file);
		$out=ob_get_clean();
		@unlink($file);
		return $out;
	}

}
