-- Per-product shipping fee for buyer Delivery (0 = free trip).
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'shipping_fee_cents') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `shipping_fee_cents` int(11) NOT NULL DEFAULT 0 AFTER `delivery_carriers`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'shipping_fee_cents') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `shipping_fee_cents` int(11) NOT NULL DEFAULT 0 AFTER `discount_cents`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
