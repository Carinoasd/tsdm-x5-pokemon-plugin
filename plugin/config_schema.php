<?php
defined('IN_DISCUZ') || exit('Access Denied');

/** Upgrade legacy configuration storage before writing a value it cannot hold. */
function pm_ensure_config_value_capacity($value)
{
    if (strlen($value) <= 255) return;
    $column = DB::fetch_first("SHOW COLUMNS FROM pm_config LIKE 'value'");
    if (!$column) throw new RuntimeException('Configuration value column is missing');
    if (strtolower($column['Type']) !== 'longtext') {
        if (DB::query('ALTER TABLE pm_config MODIFY COLUMN `value` LONGTEXT NOT NULL') === false) {
            throw new RuntimeException('Configuration storage upgrade failed');
        }
    }
}
