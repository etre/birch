<?php

// OK

class Links
{
	// В случае изменения, так-же поменять названия в CMSMod классе
	const LINK = 'link'; // Расширение файла линков
	const LOCK = 'lock'; // Расширение файла доступа

	protected static $links;
	protected static $dir;
	protected static $file;

	public static function start($contentDir,$cacheFile)
	{
		self::$dir = rtrim($contentDir,'/');
		self::$file = $cacheFile;
	}

	// Links::get(name, ... additional dirs ... )
	public static function get($name) { return self::__callStatic($name,array_slice(func_get_args(),1)); }

	// Ищем .link файлы в контентной директории
	protected static function scan($dir = false)
	{
		if(!$dir)
		{
			self::$links = array();
			$dir = self::$dir;
		}

		$remove = strlen(self::$dir) + 1; // приводим пути к относительным от директории контента
		$link= strlen(self::LINK) + 1; // Учитываем точку
		$lock= strlen(self::LOCK) + 1;

		foreach(scandir($dir) as $d)
		{
			if($d{0} == '.' || $d == CMS_MOUNT_DIR) continue;
			$p = $dir.'/'.$d;
			if(is_dir($p)) { self::scan($p); continue; }
			if(strtolower(substr($d,-$link)) === '.'.self::LINK) // Чешется оптимизировать, но этот кусок запускается довольно редко.
			{
				$type = self::LINK;
				$d = substr($d, 0, -$link);
			}
			elseif(strtolower(substr($d,-$lock)) === '.'.self::LOCK)
			{
				$type = self::LOCK;
				$d = substr($d, 0, -$lock);
			}
			else continue;
			$title = (filesize($p) > 0) ? file_get_contents($p) : null;
			if(isset(self::$links[$d])) throw new Exception(__METHOD__.': duplicate link file "'.$d.'" find in '.(self::$dir.self::$links[$d]).' and '.$dir);
			self::$links[$d] = array($type,'/'.substr($dir,$remove),$title);
		}
	}

	// Load cached settings file
	protected static function load()
	{
		if(self::$links !== null) return true;
		if(!file_exists(self::$file)) return false;
		self::$links = include_once(self::$file);
		return true;
	}

	protected static function save()
	{
		$tmp = "<?php \n".'class __'.__CLASS__.'Cache { public static function out() { return '.var_export(self::$links,true).'; }} return __'.__CLASS__.'Cache::out();';
		if(!file_put_contents(self::$file,$tmp)) throw new Exception(__METHOD__.': unable to create/update config file: '.self::$file);
	}

	// link(... additional dirs ...)
	public static function __callStatic($name,$args = array())
	{
		if(self::$file === null) throw new Exception(__CLASS__.': must be initialized first. Use '.__CLASS__.'::start()');

		if(!self::load() || !isset(self::$links[$name])) // Сканируем линки
		{
			self::scan();
			self::save();

			if(!isset(self::$links[$name])) throw new Exception(__CLASS__.': unable to find "'.$name.'" link file');
		}

		return ($args) ? (self::$links[$name][1].'/'.implode('/',$args)) : self::$links[$name][1];
	}

	// Проверка на возможность доступа набора rights по текущему пути
	// granted -> array of granted locks
	public static function access(array $granted = array(), $throwExceptionIfDenied = false)
	{
		if(!$granted) return true;
		$granted = array_flip(array_values($granted));
		foreach(self::getLinksFromCMSQueue() as $k=>$v) { if($v[0] == self::LOCK && !isset($granted[$k])) if($throwExceptionIfDenied) throw new Exception(HTTP_403); else return false; }
		return true;
	}

	// Выводим все локи
	public static function locks($titles = false)
	{
		static $cache;
		if($cache !== null) return $cache;

		if(!self::load()) { self::scan(); self::save(); }

		$cache = array();
		foreach(self::$links as $k=>$v) if($v[0] === self::LOCK) $cache[$k] = ($titles) ? array($v[1],$v[2]): $v[1];
		return $cache;
	}

	protected static function getLinksFromCMSQueue()
	{
		static $cache;
		if($cache !== null) return $cache;

		if(!class_exists('CMS',false)) throw new Exception(__METHOD__.': initialized CMS subsystem required first.');

		foreach(CMS::Queue() as $v)
		{
			if($v[1] !== self::LINK && $v[1] !== self::LOCK) continue;
			$cache[$v[0]] = array($v[1],($v[5] == $v[6])); // array(0=>type, 1=>local)
		}

		return $cache;
	}

	// Методы для меню
	public static function local($name = false)
	{
		$tmp = self::getLinksFromCMSQueue();
		if($name) return (isset($tmp[$name])) ? $tmp[$name][1] : null;
		foreach($tmp as $k=>$v) if(!$v[1]) unset($tmp[$k]);
		return array_keys($tmp);
	}

	public static function passed($name = false)
	{
		$tmp = self::getLinksFromCMSQueue();
		return isset($tmp[$name]);
	}
}