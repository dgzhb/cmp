<?php

###  some VERY COMMON FUNC to the platform...

if(!function_exists('mg_autoload_function')){
	throw new Exception("mg_autoload_function is not defined");
}
if(!function_exists('__autoload')){
	if(function_exists('spl_autoload_register')) {
		spl_autoload_register('mg_autoload_function');
	} else {
		function __autoload($class_name) {
			mg_autoload_function($class_name);
		}
	}
} else {
	if(function_exists('spl_autoload_register')) {
		spl_autoload_register('__autoload');//why?...
		spl_autoload_register('mg_autoload_function');
	} else {
		throw new Exception("spl_autoload_register func not exists");
	}
}


//---------------------------------------------------------Json{
if(!function_exists("my_json_encode")){
	function my_json_encode($o,$wellformat=false){
		if($wellformat){
			if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
				$s=json_encode($o,JSON_PRETTY_PRINT);
			}else{
				$s=json_encode($o);//will have {"a":"b"} instead of {a:"b"}, but encode speed might slightly inproved
				$s=preg_replace('/","/',"\",\n\"",$s);//dirty work for tmp...
			}
		}else{
			$s=json_encode($o);//will have {"a":"b"} instead of {a:"b"}, but encode speed might slightly inproved
		}
		return $s;
	}
	function my_json_decode($s){
		$o=json_decode($s,true);//true->array, false->obj,  the json_decode not support {a:"b"} but only support {"a":"b"}. it sucks
		return $o;
	}
}
//---------------------------------------------------------}Json

function println($s,$wellformat=false){
	if(is_array($s) || is_object($s)){
		$s=my_json_encode($s,$wellformat);
	}
	print $s ."\n";//.PHP_EOL;
}

function _gzip_output($buffer){
	$len = strlen($buffer);
	if(substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')){
		$gzbuffer = gzencode($buffer);
		$gzlen = strlen($gzbuffer);
		if ($len > $gzlen) {
			header("Content-Length: $gzlen");
			header("Content-Encoding: gzip");
			print $gzbuffer;
			return;
		}
	}
	header("Content-Length: $len");
	print $buffer;
	return;
}

function unicode2any($str,$target_encoding="UTF-8"){
	$str = rawurldecode($str);
	//print $str."\n\n";
	preg_match_all("/(?:%u.{4})|.{4};|&#\d+;|.+/U",$str,$r);
	$ar = $r[0];
	foreach($ar as $k=>$v) {
		if(substr($v,0,2) == "&#") {
			$ar[$k] = iconv("UCS-2",$target_encoding,pack("n",substr($v,2,-1)));
		}
		elseif(substr($v,0,2) == "%u"){
			$ar[$k] = iconv("UCS-2",$target_encoding,pack("H4",substr($v,-4)));
		}
		elseif(substr($v,0,3) == ""){
			$ar[$k] = iconv("UCS-2",$target_encoding,pack("H4",substr($v,3,-1)));
		}
	}
	return join("",$ar);
}

function getServerTimeZone(){
	//The plus and minus signs (+/-) are not intuitive. For example,
	//"Etc/GMT-10" actually refers to the timezone "(GMT+10:00)
	$server_timezone = getConf('SERVER_TIMEZONE');
	//$server_timezone = str_ireplace("etc/", "", $server_timezone)
		/*if (strpos($server_timezone, "-") !== false) {
			$server_timezone = str_replace("-", "+", $server_timezone);
		} else if (strpos($server_timezone, "+") !== false) {
			$server_timezone = str_replace("+", "-", $server_timezone);
		}*/
	$GMT_TIMEZONE = getConf('GMT_TIMEZONE');
	return $GMT_TIMEZONE[$server_timezone];
}

function adjust_timezone(){
	$SERVER_TIMEZONE=getConf('SERVER_TIMEZONE');
	if($SERVER_TIMEZONE==''){
		throw new Exception("FTL00002_SERVER_TIMEZONE_must_be_config");
	}else{
		$ini_get_date_timezone=ini_get("date.timezone");
		if($SERVER_TIMEZONE!=ini_get("date.timezone")){
			ini_set("date.timezone",$SERVER_TIMEZONE);
		}
	}
}

//目前主要用在写系统错误日志:
//比如说这样的
//quicklog_must("DEV-CHECK","af() is deprecated!!!\n".substr(debug_stack(),0,4096));
function debug_stack($s="") {
	$rt="";
	if(!function_exists('debug_backtrace'))
	{
		$rt.= 'function debug_backtrace does not exists'."\r\n";
		return $rt;
	}
	//$rt.= "\r\n".'----------------'."\r\n";
	//$rt.= 'Debug backtrace:'."\r\n";
	//$rt.= '----------------'."\r\n";
	foreach(debug_backtrace() as $t)
	{
		$rt.= "\t" . '@ ';
		if(isset($t['file'])) $rt.= basename($t['file']) . ':' . $t['line'];
		else
		{
			// if file was not set, I assumed the functioncall
			// was from PHP compiled source (ie XML-callbacks).
			$rt.= '<PHP inner-code>';
		}

		$rt.= ' -- ';

		if(isset($t['class'])) $rt.= $t['class'] . $t['type'];

		$rt.= $t['function'];

		if(isset($t['args']) && sizeof($t['args']) > 0) $rt.= '(...)';
		else $rt.= '()';

		//$rt.= PHP_EOL;
		$rt.= '\n';
	}
	return $rt;
}

function _getbarcode($defaultLen=23,$seed='0123456789ABCDEF'){
	list($usec, $sec) = explode(" ", microtime());
	srand($sec + $usec * 100000);
	$len = strlen($seed) - 1;
	for ($i = 0; $i < $defaultLen; $i++) {
		$code .= substr($seed, rand(0, $len), 1);
	}
	return $code;
}

/////////////////////
//for 32bit-2038 bug
function my_strtotime($s){//for DateTime to timestamp
	if(strlen($s)>10){
		$o=date_create_from_format('Y-m-d H:i:s',$s,new DateTimeZone('UTC'));//DateTimeZone::UTC
	}else{
		$o=date_create_from_format('Y-m-d H:i:s',$s.' 00:00:00',new DateTimeZone('UTC'));
	}
	if(!$o) return null;
	return $o->format('U');
}
function my_isoDate($s){
	if($s){
		$o=date_create_from_format('U',$s);
		if(!$o){
			//try U.u
			$o=date_create_from_format('U.u',$s);
		}
		if($o){
			return $o->format('Y-m-d');
		}else{
			//return null;
			throw new Exception("my_isoDate $s");
		}
	}else{
		return date_create()->format('Y-m-d');
	}
}
function my_isoDateTime($s){
	if($s){
		$o=date_create_from_format('U',$s);
		if(!$o){
			//try U.u
			$o=date_create_from_format('U.u',$s);
		}
		if($o){
			return $o->format('Y-m-d H:i:s');
		}else{
			//return null;
			throw new Exception("my_isoDate $s");
		}
	}else{
		return date_create()->format('Y-m-d H:i:s');
	}
}

