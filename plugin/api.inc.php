<?php

/**
 * API 模块入口 — Discuz 插件路由
 *
 * URL: plugin.php?id=pokemon:api&endpoint=xxx&action=yyy
 * 转发到 pokemon_system/api/*.php
 */
defined('IN_DISCUZ') || exit('Access Denied');

// 确保 DISCUZ_ROOT 已定义
if (!defined('DISCUZ_ROOT')) {
    define('DISCUZ_ROOT', dirname(dirname(dirname(dirname(__FILE__)))) . '/');
}

// 设置 JSON 响应头
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// 注册关闭函数来捕获致命错误
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line'],
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
    }
});

// 错误处理函数
function api_send_error($message, $code = 500, $extra = []) {
    $response = array_merge([
        'success' => false,
        'error' => $message,
        'code' => $code,
        'timestamp' => time()
    ], $extra);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 设置异常处理器
set_exception_handler(function($e) {
    api_send_error('Internal Server Error: ' . $e->getMessage(), 500, [
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
});

// 设置错误处理器
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    api_send_error("PHP Error [$errno]: $errstr", 500, [
        'file' => basename($errfile),
        'line' => $errline
    ]);
});

$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

// 验证端点名称安全性
if (!preg_match('/^[a-z_]+$/', $endpoint)) {
  api_send_error('Invalid API endpoint', 400);
}

// API 文件映射
$api_files = [
  'pokemon' => 'pokemon.php',
  'battle' => 'battle.php',
  'shop' => 'shop.php',
  'evolution' => 'evolution.php',
  'user' => 'user.php',
  'topics' => 'topics.php',
  'admin' => 'admin.php',
];

if (isset($api_files[$endpoint])) {
  $api_file = DISCUZ_ROOT . './source/plugin/pokemon/pokemon_system/api/' . $api_files[$endpoint];

  if (file_exists($api_file)) {
    define('API_ROUTED', true);
    include_once $api_file;
    exit;
  }
  
  api_send_error("API file not found: {$api_files[$endpoint]}", 404);
}

api_send_error("Unknown endpoint: {$endpoint}", 404);
