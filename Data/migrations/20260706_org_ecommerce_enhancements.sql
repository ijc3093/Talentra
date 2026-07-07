-- Ecommerce enhancements: SEO/PIM fields, order fulfillment tracking (2026-07-06)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @db := DATABASE();

-- Product SEO / PIM fields
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'seo_title') = 0,
  'ALTER TABLE `org_products`
     ADD COLUMN `seo_title` varchar(200) DEFAULT NULL AFTER `description`,
     ADD COLUMN `seo_description` varchar(320) DEFAULT NULL AFTER `seo_title`,
     ADD COLUMN `slug` varchar(220) DEFAULT NULL AFTER `seo_description`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND INDEX_NAME = 'idx_org_products_slug') = 0,
  'ALTER TABLE `org_products` ADD KEY `idx_org_products_slug` (`org_id`,`slug`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Order logistics / fulfillment
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'tracking_number') = 0,
  'ALTER TABLE `org_orders`
     ADD COLUMN `tracking_number` varchar(80) DEFAULT NULL AFTER `delivery_address`,
     ADD COLUMN `carrier` varchar(60) DEFAULT NULL AFTER `tracking_number`,
     ADD COLUMN `shipped_at` datetime DEFAULT NULL AFTER `carrier`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
