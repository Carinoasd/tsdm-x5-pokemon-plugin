<?php
defined('IN_DISCUZ') || exit('Access Denied');
require_once __DIR__ . '/config_schema.php';

/** Read announcements and migrate the legacy pair only while the new key is absent. */
function pm_get_news_announcements()
{
    $row = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'news_announcements'");
    if (!$row) {
        $title = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'ann_title'");
        $url = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'ann_url'");
        $news = $title && $url && !empty($title['value'])
            ? [['title' => $title['value'], 'url' => $url['value']]] : [];
        $encoded = json_encode($news, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) throw new RuntimeException('Announcement encoding failed');
        pm_ensure_config_value_capacity($encoded);
        pm_config_upsert('news_announcements', $encoded, 'string');
        $row = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'news_announcements'");
    }
    $decoded = json_decode($row['value'] ?? '[]', true);
    return is_array($decoded) ? $decoded : [];
}
