-- ============================================================
-- TSDM Pokemon Plugin - 废弃字段清理脚本
-- ============================================================
-- 用途：在数据确认安全后，真正删除已废弃的字段
-- 注意：此脚本不可逆，执行前请务必备份数据库！
-- ============================================================

-- pm_data 表废弃字段
-- shop: 商店直接购买功能已移除，改用新的兑换系统
-- met: 旧版遇见概率，改用 encounter_rate 配置

ALTER TABLE pm_data DROP COLUMN IF EXISTS `shop`;
ALTER TABLE pm_data DROP COLUMN IF EXISTS `met`;

-- pm_mypm 表废弃字段
-- sx: 旧版属性缩写（如 '火'），改用 xs 统一编号

ALTER TABLE pm_mypm DROP COLUMN IF EXISTS `sx`;

-- pm_map 表废弃字段
-- exp: 旧版地图经验值，Boss 和经验系统已重构

ALTER TABLE pm_map DROP COLUMN IF EXISTS `exp`;

-- pm_itemdata 表废弃字段
-- ppkallow: 旧版PK道具标记，PK系统已完全重构

ALTER TABLE pm_itemdata DROP COLUMN IF EXISTS `ppkallow`;

-- pm_usersdata 表废弃字段
-- npcsg: 旧版NPC闪光标记
-- ppkname/ppktime/ppkround/ppk/ppkfight/ppkdodge/ppkot/ppkpriority: 旧版PK系统字段

ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `npcsg`;
ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `ppkname`;
ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `ppktime`;
ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `ppkround`;
ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `ppk`;
ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `ppkfight`;
ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `ppkdodge`;
ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `ppkot`;
ALTER TABLE pm_usersdata DROP COLUMN IF EXISTS `ppkpriority`;
