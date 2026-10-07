<?php
defined('IN_DISCUZ') || exit('Access Denied');

// Discuz 插件开关和游戏管理页开关任一个关闭，都应停止普通玩家访问。
// pm_config 缺少开关时保留旧行为；类型转换与全局配置 API 保持一致。
function pm_game_is_closed($settings)
{
    if (empty($settings['is_open'])) return true;
    $config = DB::fetch_first("SELECT value, data_type FROM pm_config WHERE `key` = 'is_open'");
    if (!$config) return false;
    switch ($config['data_type']) {
        case 'integer': $value = intval($config['value']); break;
        case 'boolean': $value = boolval($config['value']); break;
        default: $value = strval($config['value']); break;
    }
    return !$value;
}

function pm_game_is_staff($settings)
{
    global $_G;
    return !empty($_G['uid']) && in_array($_G['username'], explode(',', $settings['poke_smgly'] ?? ''), true);
}
