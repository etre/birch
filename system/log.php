<?php

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
		set_exception_handler(array(__CLASS__,'errorHandler'));
		register_shutdown_function(array(__CLASS__,'shutdownHandler'));
	}

	public static function disable() { self::$enabled = false; }
	public static function enabled() { self::$enabled = true; }

	public static function errorHandler($code,$message = false,$file = false,$line = false,$vars = null, $backtrace = false)
	{
		if(!self::$enabled) return true;

		$log = LOG_ERR;

		if(is_object($code))
		{
			$type = 'EXCEPTION';
			$message = '['.$code->getCode().'] '.$code->getMessage();
			$file = $code->getFile();
			$line = $code->getLine();
		}
		else
		{
			if (!(error_reporting() & $code)) return true;

			switch($code)
			{
				// Fatal errors
				case E_COMPILE_ERROR:
				case E_CORE_ERROR:
				case E_PARSE:
				case E_USER_ERROR:
				case E_ERROR: $type = 'ERROR'; while(ob_get_level() > 1) ob_end_clean(); $log = LOG_ERR; break;

				// Warnings
				case E_COMPILE_WARNING:
				case E_CORE_WARNING:
				case E_USER_WARNING:
				case E_RECOVERABLE_ERROR:
				case E_WARNING: $type = 'WARNING'; $log = LOG_WARNING; break;

				// Notices
				case E_STRICT:
				case E_USER_DEPRECATED:
				case E_DEPRECATED:
				case E_USER_NOTICE:
				case E_NOTICE: $type = 'NOTICE'; $log = LOG_NOTICE; break;

				default: $type = 'UNKNOWN';
			}
		}

		if(self::$serverRootPath) $file = str_ireplace(self::$serverRootPath,'',$file);

		$message = getmypid().(defined('SERVER_NAME')?(' '.SERVER_NAME):'').' ['.(round(microtime(true) - self::$time,4)).'] '. $type.' "'.$message.'"';

		if(!is_object($code))
		{
			if(!$backtrace) $backtrace = debug_backtrace();

			foreach($backtrace as $k=>$v) // Бэктрейс только на ошибках, так как с эксепшенов будет только до вызова этого метода внутри CMS.
			{
				unset($backtrace[$k]);
				if(!($v && isset($v['line']) && isset($v['file']))) continue;
				if(self::$serverRootPath) $v['file'] = str_ireplace(self::$serverRootPath,'',$v['file']);
				$backtrace[$v['file']][$v['line']] = true;
			}
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

		syslog($log, $message);

		return true;
	}

	public static function shutdownHandler()
	{
		if ($error = error_get_last() and $error['type'] & (E_ERROR|E_PARSE|E_COMPILE_ERROR|E_CORE_ERROR)) self::errorHandler($error['type'], $error['message'], $error['file'], $error['line'], null, debug_backtrace());
	}

	public static function debug($message)
	{
		if(is_array($message) || is_object($message)) $message = 'DUMP: '.var_export($message,true);
		$message = getmypid().(defined('SERVER_NAME')?(' '.SERVER_NAME):'').' ['.(round(microtime(true) - self::$time,4)).'] DEBUG: '.$message;
		syslog(LOG_INFO, $message);
	}
}