-- Product listing codes shown in Product table (e.g. PRD-09E82).
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'product_code') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `product_code` varchar(32) DEFAULT NULL AFTER `sku`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND INDEX_NAME = 'uq_org_products_code') = 0,
  'ALTER TABLE `org_products` ADD UNIQUE KEY `uq_org_products_code` (`org_id`,`product_code`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
