-- What the seller is selling (Car, Cup, etc.) — per product + seller custom list in shop_json.
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'selling_type') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `selling_type` varchar(80) DEFAULT NULL AFTER `category`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
