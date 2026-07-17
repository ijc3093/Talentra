-- Buyer can hide orders from My Orders (seller records unchanged)
-- Idempotent: safe to re-run.

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'buyer_hidden_at') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `buyer_hidden_at` datetime DEFAULT NULL AFTER `updated_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND INDEX_NAME = 'idx_org_orders_buyer_hidden') = 0,
  'ALTER TABLE `org_orders` ADD KEY `idx_org_orders_buyer_hidden` (`buyer_user_id`,`buyer_hidden_at`,`created_at`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
