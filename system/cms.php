<?php

define('DISALLOWED_SYMBOLS_URL','<>%@#$');

define('CMS_SKIP_MODULE',null);		// Возвращаемое значение из модуля обозначающее пропуск исполнения модуля и записи его результатов в HEAP
define('CMS_MAX_RESTART',2);	    // МАксимальное число рестартов модуля до срабатывания защиты от бесконечного цикла.
define('CMS_ANY_NAME_DIR','@');		// Директория "любое имя"
define('CMS_MOUNT_DIR','@@');		// Маунт-директория
define('CMS_FLAG_SHIFT','s');		// флаг модуля смещения
define('CMS_FLAG_FINAL','f');		// финальный флаг

// Module execution class
class CMSMod
{
    public $name;		// имя без учета расширения и флагов (для heap)
    public $path;		// путь + имя файла внутри директории контента (контент рут)
    public $method;		// метод исполнения
    public $sort;		// приоритет (0-9) или F - 100 или S - 111
    public $entry;		// уровень входа
    public $current;	// имя текущей директории
    public $location;	// массив пути 0=>...
    public $level;		// уровень модуля
    public $local;		// признак локального запуска

    public function __construct($name,$method,$sort,$path,$current,$level,$entry,$location)
    {
        if(!method_exists($this, $method)) throw new Exception(HTTP_500);
        list($this->name, $this->method, $this->sort, $this->path, $this->current, $this->level, $this->entry, $this->location) = func_get_args();
        $this->local = ($this->entry == $this->level);
    }

    // Инстанциирование класса с произвольными аргументами. Аналог call_user_method но для класса
    public static function __factory(array $args = array())
    {
        static $ref;
        if($ref === null) $ref = new ReflectionClass(__CLASS__);
        return $ref->newInstanceArgs($args);
    }

    public function __invoke() { return call_user_func(array($this,$this->method)); }

    public static function __methods()
    {
        $tmp = array();
        foreach(get_class_methods(__CLASS__) as $v) if($v{0} != '_') $tmp[$v] = $v;
        return $tmp;
    }

    // Методы исполнения для указанных расширений

    public static $priority = array('tpl'=>1,'phtml'=>1); // Приоритет по умолчанию для типов данных. Для правильной сборки шаблонным файлам присваивается 1 по умолчанию

    public function php() { return include($this->path); }

    public function html() { return file_get_contents($this->path); }

    public function js() { return '<script type="text/javascript">'.file_get_contents($this->path).'</script>'; }

    public function phtml()
    {
	    ob_start();
        extract((array) CMS::Heap());
        if(CMS_SKIP_MODULE === include($this->path))
        {
	        ob_end_clean();
	        return CMS_SKIP_MODULE;
        }
	    return ob_get_clean();
    }

	// Смарти шаблоны
    public function tpl()
    {
	    static $obj;
	    if($obj === null)
	    {
		    $obj  = new Smarty();
		    $obj->setCompileDir(PATH_CACHE);

		    // Отключаем smarty автолоадер
		    foreach(spl_autoload_functions() as $v) if($v != array('Autoloader','check')) spl_autoload_unregister($v);
	    }

	    $obj->assign((array) CMS::Heap());
	    return $obj->fetch($this->path);
    }

	// Links access & navigation modules
	public function link() { return CMS_SKIP_MODULE; }
	public function lock() { return CMS_SKIP_MODULE; }
}


class CMS
{
	protected static $heap;
    protected static $url;
	protected static $queue;

	public static function Queue() { return self::$queue; }
	public static function &Heap() { return self::$heap; }
    public static function Url() { return self::$url; }

	public static function Start($content,$url)
	{
        self::$heap = new stdClass();
		$content = rtrim($content,'/');
		$url = rtrim($url,'/');

		try
		{
			if($url !== '') if(preg_match('/[\\'.implode('\\',str_split(DISALLOWED_SYMBOLS_URL)).']/', $url)) throw new Exception(HTTP_404);
			self::$url = $url = preg_split('/[\\/]/i',$url);

			$queue = self::__queue($content,$url); // Создаем массив с данными модулей для выполнения
			return self::__execute($queue);
		}
		catch(Exception $e)
		{
			$code = ($e->getCode()) ? $e->getCode() : ((is_numeric($e->getMessage())) ? (int) $e->getMessage() : HTTP_500);
			if(isset($_SERVER['SERVER_PROTOCOL']) && $_SERVER['SERVER_PROTOCOL']) header($_SERVER['SERVER_PROTOCOL'].' '.$code);

			if($code === HTTP_500) Log::errorHandler($e); // Логгирование ошибок

			// TODO Редирект по коду в директорию или загрузка файлов

			return $code.': '.($e->getMessage() ? $e->getMessage() : 'ERROR');
		}
	}

	// Сканируем указанную директорию
	// Проверки на существование директории НЕТ !!!
	protected static function __scandir($dir)
	{
		$tmp = array();
		$handle = opendir($dir);
		if(!$handle) return false;

		while(false !== ($file = readdir($handle)))
		{
			if($file{0} == '.') continue;
			$tmp[$file] = ! is_dir($dir.'/'.$file); // true если НЕ директория
		}

		closedir($handle);
		return $tmp;
	}

	// создаем очередь выполнения
	protected static function __queue($dir,$url)
	{
		$methods = CMSMod::__methods(); // Возможные методы для запуска
		$level = 0;
		$queue = $location = $specials = array();
		$entry = count($url) - 1; // Уровень входа

		// Убираем начальный пустой символ из урла и добавляем его в конец
		// Парсинг директорий происходит в шаге назад от остального алгоритма.
		$location[] = array_shift($url);
		$url[] = null;

		$iv = $current = false;

		foreach ($url as $v)
		{
			// ДЛя маунт-диры смотреть v на предмет маунта и прокручивать через continue до v!==null наполняя location
			if($iv === CMS_MOUNT_DIR && $v !== null)
			{
				$location[] = $v;
				continue;
			}

			$iv = $v;

			$tmp = self::__scandir($dir);

			if($v !== null) // Пропускаем последний искусственный пункт в урле
			{
				if(!isset($tmp[$v])) // Директория не найдена
				{
					if(isset($tmp[CMS_ANY_NAME_DIR]))	// есть Плейсхолдер
					{
						$v = CMS_ANY_NAME_DIR;
					}
					elseif(isset($tmp[CMS_MOUNT_DIR]))	// MOUNT директория
					{
						$v = CMS_MOUNT_DIR;
					}
					else
					{
						throw new Exception(HTTP_404);
					}
				}
				else
				{
					if($tmp[$v]) throw new Exception(HTTP_404); // Не директория
				}
			}

			$tmp = array_filter($tmp); // Убираем директории
			$data = array();

			// ОБРАТНАЯ сортировка от приоритета !!!
			// То есть чем больше число приоритета, тем позднее будет выполнен модуль
			foreach($tmp as $kk => $sort)
			{
				// Флаг может быть только ОДИН !!!
				if(preg_match('/^([^\.]+).?(?:(['.CMS_FLAG_FINAL.'|'.CMS_FLAG_SHIFT.'|0-9]?)\.)([^\.]+)$/',$kk,$match))
				{
					$sort = 0;

					list($trash,$name,$flag,$method) = $match;

					if(!isset($methods[$method])) // Пропускаем неизвестные модули
					{
						unset($tmp[$kk]);
						continue;
					}

					if(isset(CMSMod::$priority[$method])) $sort = CMSMod::$priority[$method]; // Смарти имеет небольшой приоритет

					if($flag)
					{
						$sort = is_numeric($flag) ? $flag : 0;

						// Кодируем флаги
						if($flag == CMS_FLAG_FINAL) $sort = 100;
						elseif($flag == CMS_FLAG_SHIFT)
						{
							$sort = 111;
							$specials[$kk] = array($name,$method,$sort,$dir.'/'.$kk,$current,$level,$entry,$location);
							unset($tmp[$kk]);
							continue;
						}
					}

					$tmp[$kk] = $sort;

					// Сохраняем данные для модулей в отдельный массив, чтобы не мешать сортировке и не плодить сущности :)
					$data[$kk] = array($name,$method,$sort,$dir.'/'.$kk,$current,$level,$entry,$location);
				}
				else // Пропускаем файлы не соответствующие маске
				{
					unset($tmp[$kk]);
					continue;
				}
			}

			asort($tmp);

			// Заполням данными очередь внутри директории
			foreach(array_reverse($tmp) as $kk => $vv)
			{
				if(!isset($data[$kk])) continue;
				$queue[] = $data[$kk];
			}

			$dir = $dir.'/'.$v;
			$location[] = $current = $iv;
			if($v === CMS_MOUNT_DIR) $iv = $v; // MOUNT директория

			$level ++;
		}

		foreach(array_reverse($specials) as $v) $queue[] = $v; // добавляем модули с флагом SPECIAL в самое начало // Быстрее чем array_merge($queue,array_values($special)); :)

		// Массив формируется по уровням level в зависимости от url
		return array_reverse($queue);
	}

	protected static function __execute($queue)
	{
		if(!$queue || !is_array($queue)) throw new Exception(HTTP_404);
		$skip = false;
		self::$queue = &$queue; // Для runtime доступа к очереди

		$result = $level = null;

		foreach($queue as $v)
		{
			if(isset(self::$heap->{$v[0]}) && $level != $v[5]) continue; // В пределах текущего уровня перезаписываем имя

			// Пропускаем уровни в случае если special возвращает не false.
			if($skip !== false) if($v[5] > $skip) continue; else $skip = false; // v[5] - level значение

			$obj = CMSMod::__factory($v);
			$result = $obj->__invoke();

			if($result !== null)
			{
				if($v[2] == 111) $skip = ($obj->level > 0) ? $obj->level : 0; // SPECIAL логика. + Возможность изменения уровня возврата для SPECIAL флагов
				if($v[2] == 100) break; // Final module вернул контент

				// Пишем если есть что записывать
				self::$heap->{$v[0]} = $result; // v[0] - name
				$result = null;
			}

			unset($obj);

			if($level != $v[5]) $level = $v[5];
		}

		if($result === null) throw new Exception(HTTP_404);

		return $result;
	}
}