<?php

/**
 * 重建 Discuz X2 所有系统缓存（包括插件缓存）
 *
 * 在容器内运行：
 *   docker exec tsdm-pokemon-plugin-php-1 php /var/www/html/source/plugin/pokemon/rebuild_cache.php
 *
 * 工作原理：
 *   通过 Discuz X2 的 discuz_core 引擎初始化数据库连接，
 *   然后调用 updatecache() 重新生成 pre_common_syscache 中的所有缓存行。
 */

// Discuz X2 的 class_core.php 会自行 define IN_DISCUZ 和 DISCUZ_ROOT，
// 不要提前定义，否则会产生 Notice。
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['REQUEST_URI']     = '/misc.php?mod=initsys';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/misc.php';
$_SERVER['SCRIPT_NAME']     = '/misc.php';
$_SERVER['PHP_SELF']        = '/misc.php';
$_SERVER['DOCUMENT_ROOT']   = '/var/www/html';

define('APPTYPEID', 100);
define('CURSCRIPT', 'misc');

require '/var/www/html/source/class/class_core.php';

$discuz = &discuz_core::instance();

// 最小化初始化：只需要数据库和设置，不需要 session / user / cron
$discuz->init_session = false;
$discuz->init_user    = false;
$discuz->init_cron    = false;
$discuz->init_mobile  = false;
$discuz->init();

require_once libfile('function/cache');

// 不传参 = 重建所有缓存（setting, plugin, style, cron 等）
updatecache();

echo "All caches rebuilt successfully.\n";
