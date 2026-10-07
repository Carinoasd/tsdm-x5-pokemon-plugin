-- Existing installations: run once before enabling battle request replay.
-- Runtime battle_ensure_tables also adds this column and table lazily.
SET @has_revision = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pm_battle' AND COLUMN_NAME = 'revision');
SET @ddl = IF(@has_revision = 0,
    'ALTER TABLE pm_battle ADD COLUMN revision int(10) unsigned NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE battle_revision_stmt FROM @ddl;
EXECUTE battle_revision_stmt;
DEALLOCATE PREPARE battle_revision_stmt;
CREATE TABLE IF NOT EXISTS `pm_battle_action` (
    `uid` mediumint(8) unsigned NOT NULL,
    `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `action` varchar(24) NOT NULL,
    `payload_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `battle_id` bigint(20) unsigned NOT NULL DEFAULT 0,
    `response_json` mediumtext DEFAULT NULL,
    `created_at` int(10) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`uid`, `request_id`),
    KEY `idx_uid_created` (`uid`, `created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
