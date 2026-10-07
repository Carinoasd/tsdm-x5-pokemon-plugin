-- Preserve complete multi-entry announcements and other configuration JSON.
-- Safe to rerun. Run outside a transaction: ALTER TABLE commits implicitly.
ALTER TABLE pm_config MODIFY COLUMN `value` LONGTEXT NOT NULL;
