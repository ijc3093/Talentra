-- Product receive options: Delivery (carrier / seller trip) and in-store Pick up.
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'delivery_enabled') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `delivery_enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `fulfillment_method`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'pickup_enabled') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `pickup_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `delivery_enabled`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'delivery_carriers') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `delivery_carriers` varchar(255) DEFAULT NULL AFTER `pickup_enabled`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
