<?php

if($_SERVER['PATH_INFO'] == '/favicon.ico') die; // Для непрописанных в конфиге иконок.

ob_start();

define('PATH_ROOT',__DIR__.'/');				// Корень проекта
define('PATH_CACHE',PATH_ROOT.'cache/');		// Временные файлы / кэш
define('PATH_SYSTEM',PATH_ROOT.'system/');		// Системные файлы
define('PATH_CLASS',PATH_ROOT.'class/');		// Классы
define('PATH_CONTENT',PATH_ROOT.'content/');	// Контент для CMS

///////////////////////////////////////////////////////////////////////////////////

define('HTTP_200',200);
define('HTTP_400',400); // Bad syntax
define('HTTP_404',404);
define('HTTP_403',403);
define('HTTP_429',429); // User banned
define('HTTP_500',500); // Default internal error

///////////////////////////////////////////////////////////////////////////////////

include_once(PATH_SYSTEM.'log.php');
Log::start(PATH_ROOT);

include_once(PATH_SYSTEM.'autoload.php');
Autoloader::start(PATH_CACHE.'autoload.cache.php');
Autoloader::add(PATH_SYSTEM);
Autoloader::add(PATH_CLASS,true); // Рекурсивное сканирование директории !

Links::start(PATH_CONTENT,PATH_CACHE.'links.cache.php');

header('Content-Type: text/html; charset=UTF-8');
echo CMS::start(PATH_CONTENT, (!isset($_SERVER['PATH_INFO']) || !$_SERVER['PATH_INFO']) ? '/' : $_SERVER['PATH_INFO']);

ob_end_flush();