<?php

// OK

class Log
{
	protected static $time;
	protected static $serverRootPath;
	protected static $enabled = true;

	static function start($serverRootPath, $mode = E_ALL)
	{
		self::$time = microtime(true);
		self::$serverRootPath = $serverRootPath;

		error_reporting($mode);
		set_error_handler(array(__CLASS__,'errorHandler'),$mode);
	}

	public static function disable() { self::$enabled = false; }
	public static function enabled() { self::$enabled = true; }

	public static function errorHandler($code,$message = false,$file = false,$line = false)
	{
		if(!self::$enabled) return;

		if(is_object($code))
		{
			$type = 'EXCEPTION';
			$message = '['.$code->getCode().'] '.$code->getMessage();
			$file = $code->getFile();
			$line = $code->getLine();
		}
		else
		{
			if (!(error_reporting() & $code)) return;

			switch($code)
			{
				case E_USER_ERROR:
				case E_ERROR: $type = 'ERROR'; while(ob_get_level() > 1) ob_end_clean(); break;

				case E_USER_WARNING:
				case E_WARNING: $type = 'WARNING'; break;

				case E_USER_NOTICE:
				case E_NOTICE: $type = 'NOTICE'; break;

				default: $type = 'FATAL';
			}
		}

		if(self::$serverRootPath) $file = str_ireplace(self::$serverRootPath,'',$file);

		$message = getmypid().' ['.(round(microtime(true) - self::$time,4)).'] '. $type.' "'.$message.'"';

		$backtrace = array();

		if(!is_object($code)) foreach(debug_backtrace() as $v) // Бэктрейс только на ошибках, так как с эксепшенов будет только до вызова этого метода внутри CMS.
		{
			if(!($v && isset($v['line']) && isset($v['file']))) continue;
			if(self::$serverRootPath) $v['file'] = str_ireplace(self::$serverRootPath,'',$v['file']);
			$backtrace[$v['file']][$v['line']] = true;
		}

		if($backtrace)
		{
			foreach($backtrace as $k => $v) $backtrace[$k] = $k.' [ '.implode(' ,',array_keys($v)).' ]';
			$message .= ' trace: '.implode(', ',$backtrace);
		}
		else
		{
			$message .= ' in '.$file.':'.$line;
		}

		syslog(LOG_WARNING, $message);

		return true;
	}

	public static function debug($message)
	{
		if(is_array($message) || is_object($message)) $message = 'DUMP: '.var_export($message,true);
		$message = getmypid().' ['.(round(microtime(true) - self::$time,4)).'] DEBUG: '.$message;
		syslog(LOG_INFO, $message);
	}
}