<?php

/**
 * 全局配置API
 *
 * 端点:
 * - GET ?action=global_config 获取全局配置
 */

// 如果通过路由访问，加载API辅助函数
if (defined('API_ROUTED')) {
    require_once __DIR__ . '/index.php';
} elseif (!defined('IN_DISCUZ')) {
    require_once __DIR__ . '/bootstrap.php';
    require_once __DIR__ . '/index.php';
}

global $_G;

$action = get_param('action', '');

switch ($action) {
    case 'global_config':
        api_get_global_config();
        break;

    default:
        api_error('Invalid action', 400);
}

/**
 * 获取全局配置
 */
function api_get_global_config()
{
    require_once __DIR__ . '/../announcements.php';
    $news_announcements = pm_get_news_announcements();
    $config = array();

    $rows = DB::fetch_all("SELECT * FROM pm_config");
    foreach ($rows as $obj) {
        $value = null;
        switch ($obj['data_type']) {
            case "string":
                $value = strval($obj['value']);
                // 对于 news_announcements，解析 JSON 字符串为数组
                if ($obj['key'] === 'news_announcements') {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $value = $decoded;
                    } else {
                        $value = array(); // JSON 解析失败，返回空数组
                    }
                }
                break;
            case "integer":
                $value = intval($obj['value']);
                break;
            case "boolean":
                $value = boolval($obj['value']);
                break;
        }
        $config[$obj['key']] = $value;
    }

    $config['news_announcements'] = $news_announcements;

    api_success($config);
}
