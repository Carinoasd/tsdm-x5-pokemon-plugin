<?php
defined('IN_DISCUZ') || exit('Access Denied');

// 加载插件缓存
if (!isset($_G['cache']['plugin'])) {
    loadcache('plugin');
}

// 从Discuz缓存中读取插件配置
$settings = isset($_G['cache']['plugin']['pokemon']) ? $_G['cache']['plugin']['pokemon'] : array();

// 获取路由参数
$index = isset($_GET['index']) ? addslashes($_GET['index']) : 'game';

// API 路由 - 如果是API请求，路由到 api.php
if (isset($_GET['endpoint'])) {
  $endpoint = $_GET['endpoint'];

  // 验证端点名称安全性
  if (preg_match('/^[a-z_]+$/', $endpoint)) {
    // 设置JSON响应头
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // API文件映射（白名单）
    $api_files = [
      'pokemon' => 'pokemon.php',
      'battle' => 'battle.php',
      'shop' => 'shop.php',
      'evolution' => 'evolution.php',
      'user' => 'user.php',
      'topics' => 'topics.php',
      'admin' => 'admin.php',
      'config' => 'config.php',
    ];

    if (isset($api_files[$endpoint])) {
      $api_file = DISCUZ_ROOT . './source/plugin/pokemon/pokemon_system/api/' . $api_files[$endpoint];
      if (file_exists($api_file)) {
        // 定义常量表示已通过路由加载
        define('API_ROUTED', true);
        include_once $api_file;
        return;
      }
    }
  }

  // 如果到这里说明API路由失败
  echo json_encode([
    'success' => false,
    'error' => 'Invalid API endpoint',
    'code' => 400,
    'timestamp' => time()
  ], JSON_UNESCAPED_UNICODE);
  return;
}

// 插件开启检查
if (!isset($settings['is_open']) || !$settings['is_open']) {
  // 管理员始终可以访问
  if ($index !== 'admin') {
    $gmarray = explode(',', isset($settings['poke_smgly']) ? $settings['poke_smgly'] : '');
    if (!in_array($_G['username'], $gmarray)) {
      showmessage("系统关闭中");
    }
  }
}

// 路由分发（白名单）
$allowed_routes = ['game', 'admin'];
if (!in_array($index, $allowed_routes)) {
  showmessage('无效的路由');
}

// 游戏路由
if ($index === 'game') {
  include_once DISCUZ_ROOT . './source/plugin/pokemon/pokemon_system/game.php';
  return;
}

// 管理员路由
if ($index === 'admin') {
  include_once DISCUZ_ROOT . './source/plugin/pokemon/pokemon_system/admin.php';
  return;
}
