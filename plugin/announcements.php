<?php
defined('IN_DISCUZ') || exit('Access Denied');

/** Read announcements and migrate the legacy pair only while the new key is absent. */
function pm_get_news_announcements()
{
    $row = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'news_announcements'");
    if (!$row) {
        $title = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'ann_title'");
        $url = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'ann_url'");
        $news = $title && $url && !empty($title['value'])
            ? [['title' => $title['value'], 'url' => $url['value']]] : [];
        // A simultaneous admin save wins; never replace a newer list with defaults.
        DB::query(pm_sql(
            "INSERT IGNORE INTO pm_config (`key`, `value`, `data_type`) VALUES ('news_announcements', %s, 'string')",
            json_encode($news, JSON_UNESCAPED_UNICODE)
        ));
        $row = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'news_announcements'");
    }
    $decoded = json_decode($row['value'] ?? '[]', true);
    return is_array($decoded) ? $decoded : [];
}
