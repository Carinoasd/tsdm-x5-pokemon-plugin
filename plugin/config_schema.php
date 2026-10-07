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

/** One idempotent autocommit write; callers must finish schema upgrades first. */
function pm_config_upsert($key, $value, $data_type, $replace_existing = false)
{
    $sql = pm_sql(
        'INSERT INTO pm_config (`key`, `value`, `data_type`) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE '
            . ($replace_existing ? '`value` = VALUES(`value`), `data_type` = VALUES(`data_type`)' : '`key` = `key`'),
        $key, $value, $data_type
    );
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if (DB::query($sql, 'SILENT') !== false) return;
        $error = intval(DB::errno());
        if (!in_array($error, [1205, 1213], true)) throw new RuntimeException('Configuration write failed');
        // Defaults may accept another writer's value. Explicit saves must
        // retry their own write even if a different value now exists.
        if (!$replace_existing && DB::fetch_first(pm_sql('SELECT * FROM pm_config WHERE `key` = %s', $key))) return;
        if ($attempt === 2) throw new RuntimeException('Configuration write lock conflict');
        usleep(10000 * ($attempt + 1));
    }
}
