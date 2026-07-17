SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'attributes_json') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `attributes_json` json DEFAULT NULL AFTER `selling_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
