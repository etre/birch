<?php

// OK

class Autoloader
{
	protected static $dirs = array();
	protected static $classes = array();

	protected static $file;
	protected static $time;

	public static function start($cacheFile)
	{
		self::$file = $cacheFile;
		self::$time = (file_exists($cacheFile)) ? filemtime($cacheFile) : false;

		spl_autoload_register(array(__CLASS__,'check'),true,true);
	}

	protected static function scan($d,$r,&$tmp)
	{
		$d = rtrim($d,'/');
		if(!is_dir($d)) throw new Exception(__METHOD__.': Unable to scan directory: '.$d);
		foreach(scandir($d) as $f)
		{
			if($f{0} == '.') continue; // Пропускаем скрытые
			$ff = $d.'/'.$f;
			if($r && is_dir($ff)) { self::scan($ff,true,$tmp); continue; } // Если включена рекурсия для директории - сканируем поддиректории
			if(!is_file($ff) || $ff == __FILE__ || pathinfo($f,PATHINFO_EXTENSION) != 'php') continue; // Пропускаем не php, не файлы и __FILE__
			$tmp[$ff] = $ff;
		}
	}

	public static function check($class)
	{
		if(!self::$dirs) throw new Exception(__CLASS__.': no directories provided to scan. Use '.__CLASS__.'::add() method to add some.');

		if(!self::$time) // Сканирование директорий и создание файла конфига
		{
			self::$classes = $tmp = array();

			foreach(self::$dirs as $d => $recursive) self::scan($d,$recursive,$tmp); // Поиск файлов

			if($tmp) // Парсинг файлов
			{
				foreach($tmp as $f)
				{
					if(filemtime($f) > time()) if(!touch($f,time())) throw new Exception(__METHOD__.' unable to reset file modification time.'); // Сбиваем время модификации, если оно из будущего. Иначе всегда будет срабатывать перескан.

					// Парсим файл, находим классы с учетом комментариев
					foreach(((preg_match_all('/^(?:\s)*(?:abstract|final)?(?:\s)*class\s+(\w+)/im',(preg_replace('/(?:\/\*).*?(?:(?:\*\/)|\Z)/s','',file_get_contents($f))),$m) && isset($m[1]) && $m[1]) ? $m[1] : array()) as $v)
					{
						$v = strtolower($v);
						if(isset(self::$classes[$v])) throw new Exception(__METHOD__.': duplicate class "'.$v.'" found in "'.self::$classes[$v].'" and "'.$f.'"');
						self::$classes[$v] = $f;
					}
				}

				if(self::$classes) // Записываем конфигурацию
				{
					$tmp = "<?php \n".'class __'.__CLASS__.'Cache { public static function out() { return '.var_export(self::$classes,true).'; }} return __'.__CLASS__.'Cache::out();';
					if(!file_put_contents(self::$file,$tmp)) throw new Exception(__METHOD__.': unable to create/update config file: '.self::$file);
					self::$time = true;
				}
				else throw new Exception(__METHOD__.': unable to find even a single class occurrence in supplied directories.');
			}
		}
		else // Загрузка файла конфига
		{
			if(!self::$classes)
			{
				self::$classes = include_once(self::$file);
				if(!is_array(self::$classes)) throw new Exception(__METHOD__.': invalid data in storage file class');
			}
		}

		$class = strtolower($class);

		// Перескан в случае отсутствия класса в списке, отсутствия файла из списка и даты последнего изменения файла больше даты конфига.
		if(!isset(self::$classes[$class]) || !file_exists(self::$classes[$class]) || (self::$time !== true && filemtime(self::$classes[$class]) > self::$time))
		{
			if(self::$time === true) throw new Exception(__METHOD__.': unable to find class: '.$class); // Только что сканировали - ничего не нашли.
			self::$time = false;
			return self::check($class);
		}

		$tmp = get_declared_classes();
		include(self::$classes[$class]);
		$tmp = array_change_key_case(array_flip(array_diff_key(get_declared_classes(),$tmp)),CASE_LOWER);

		if(!isset($tmp[$class])) throw new Exception(__METHOD__.': unable to find class "'.$class.'" in file "'.self::$classes[$class].'"');

		return true;
	}

	// Директория для сканировани / рекурсивный обход поддиректорий
	public static function add($directory, $recursive = false) { self::$dirs[$directory] = (bool) $recursive; }
}