-- ============================================================
-- TSDM Pokemon Plugin - 数据表重整归一脚本
-- ============================================================
-- 用途：将旧版多张离散表合并重整为 X5 规范的表结构
-- 注意：此脚本不可逆，执行前请务必备份数据库！
-- ============================================================

-- ============================================================
-- 第一阶段：pm_box 数据迁移
-- ============================================================
-- 从旧版 pm_usersdata.boxnum 推断盒子配置
-- Boxnum 为 9 表示默认9个盒子，这里不做自动迁移
-- 仅创建空表供后续使用

-- ============================================================
-- 第二阶段：pm_evolution 数据预填充
-- ============================================================
-- 从旧版 pm_itemdata.sitemid 中提取进化关系
-- sitemid 格式如 "pm_evolution:123" 表示可进化为 #123

INSERT IGNORE INTO pm_evolution (`from_id`, `to_id`, `method`, `condition_value`)
SELECT
    CAST(REGEXP_REPLACE(sitemid, '[^0-9]', '') AS UNSIGNED) AS to_id,
    id AS from_id,
    'item',
    sitemname
FROM pm_itemdata
WHERE sitemid REGEXP '^[0-9]+$'
    AND CAST(REGEXP_REPLACE(sitemid, '[^0-9]', '') AS UNSIGNED) > 0;

-- ============================================================
-- 第三阶段：pm_config 数据标准化
-- ============================================================

-- 3.1 删除旧版 datatables 配置（X5 由插件 JSON 管理）
DELETE FROM pm_config WHERE `key` = 'datatables';

-- 3.2 添加新配置项
INSERT IGNORE INTO pm_config (`key`, `value`, `data_type`) VALUES
    ('is_open', '1', 'string'),
    ('is_enable_catch', '1', 'boolean'),
    ('medical_price', '0', 'integer'),
    ('egg_price', '0', 'integer'),
    ('is_enable_pvp', '1', 'boolean'),
    ('news_announcements', '[]', 'string'),
    ('max_party_size', '6', 'integer'),
    ('max_box_count', '32', 'integer'),
    ('shiny_rate', '0.001', 'float'),
    ('exp_rate', '1.0', 'float');

-- ============================================================
-- 第四阶段：数据完整性修复
-- ============================================================

-- 4.1 修复 pm_mypm 中孤立的 uid（用户已删除但宠物未清理）
-- DELETE FROM pm_mypm WHERE uid NOT IN (SELECT uid FROM pre_common_member);

-- 4.2 修复 pm_myitem 中孤立的 uid
-- DELETE FROM pm_myitem WHERE uid NOT IN (SELECT uid FROM pre_common_member);

-- 4.3 修复 pm_myskill 中孤立的 petid
-- DELETE FROM pm_myskill WHERE petid NOT IN (SELECT id FROM pm_mypm);

-- 4.4 修复 pm_usersdata 中不一致的数据
UPDATE pm_usersdata SET boxnum = 9 WHERE boxnum < 1 OR boxnum > 32;

-- ============================================================
-- 第五阶段：索引重建（InnoDB 优化）
-- ============================================================

-- 5.1 pm_data FULLTEXT 索引在 InnoDB 中重建
-- ALTER TABLE pm_data DROP INDEX mapid;
-- ALTER TABLE pm_data ADD FULLTEXT INDEX ft_mapid (mapid);

-- ============================================================
-- 完成标记
-- ============================================================
INSERT INTO pm_migration_log (`step`, `status`, `message`, `executed_at`)
VALUES ('restructure_v1', 1, '数据表重整脚本执行完毕', UNIX_TIMESTAMP());
