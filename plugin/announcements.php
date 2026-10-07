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
        // Preserve concurrent admin saves. These read-only callers have no outer
        // transaction, so retry only this idempotent insert after lock conflicts.
        $sql = pm_sql(
            "INSERT INTO pm_config (`key`, `value`, `data_type`) VALUES ('news_announcements', %s, 'string') ON DUPLICATE KEY UPDATE `key` = `key`",
            $encoded
        );
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (DB::query($sql, 'SILENT') !== false) break;
            $error = intval(DB::errno());
            if (!in_array($error, [1205, 1213], true)) throw new RuntimeException('Announcement migration failed');
            $row = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'news_announcements'");
            if ($row) break;
            if ($attempt === 2) throw new RuntimeException('Announcement migration lock conflict');
            usleep(10000 * ($attempt + 1));
        }
        $row = DB::fetch_first("SELECT * FROM pm_config WHERE `key` = 'news_announcements'");
    }
    $decoded = json_decode($row['value'] ?? '[]', true);
    return is_array($decoded) ? $decoded : [];
}
