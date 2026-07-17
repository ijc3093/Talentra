-- Commerce brand systems (McDonald's, Wendy's, etc.) — idempotent
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS `commerce_brands` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `name` varchar(120) NOT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `accent_color` varchar(16) DEFAULT NULL,
  `icon_letter` char(1) DEFAULT NULL,
  `system_json` json DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commerce_brands_slug` (`slug`),
  KEY `idx_commerce_brands_active_sort` (`is_active`, `sort_order`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'commerce_brand_id') = 0,
  'ALTER TABLE `organizations` ADD COLUMN `commerce_brand_id` int unsigned DEFAULT NULL AFTER `business_model`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `commerce_brands` (`slug`, `name`, `tagline`, `accent_color`, `icon_letter`, `system_json`, `sort_order`, `is_active`)
SELECT * FROM (
  SELECT
    'mcdonalds' AS slug,
    'McDonald''s' AS name,
    'Quick-service restaurant — burgers, fries, and pickup-ready orders.' AS tagline,
    '#DA291C' AS accent_color,
    'M' AS icon_letter,
    CAST('{"model":"quick_service","default_fulfillment":"fbm","pickup_enabled":true,"delivery_enabled":true,"menu_categories":["Burgers","Chicken","Breakfast","Fries & Sides","Drinks","Desserts"],"seller_label":"McDonald''s seller","order_hint":"Orders are prepared fresh for pickup or delivery — just like a McDonald''s counter."}' AS JSON) AS system_json,
    10 AS sort_order,
    1 AS is_active
  UNION ALL SELECT
    'wendys', 'Wendy''s',
    'Made fresh, never frozen — combos, salads, and Frosty favorites.',
    '#E21836', 'W',
    CAST('{"model":"quick_service","default_fulfillment":"fbm","pickup_enabled":true,"delivery_enabled":true,"menu_categories":["Burgers","Chicken & Wraps","Salads","Frosty & Desserts","Combos","Beverages"],"seller_label":"Wendy''s seller","order_hint":"Wendy''s-style made-to-order flow — sellers confirm, then prepare for pickup or delivery."}' AS JSON),
    20, 1
  UNION ALL SELECT
    'subway', 'Subway',
    'Build-your-own subs — fresh ingredients, fast fulfillment.',
    '#009639', 'S',
    CAST('{"model":"quick_service","default_fulfillment":"fbm","pickup_enabled":true,"delivery_enabled":true,"menu_categories":["Subs","Wraps","Salads","Sides","Drinks","Cookies"],"seller_label":"Subway seller","order_hint":"Customize and fulfill sandwich orders with Subway-style speed."}' AS JSON),
    30, 1
  UNION ALL SELECT
    'starbucks', 'Starbucks',
    'Coffeehouse — drinks, food, and mobile-order pickup.',
    '#00704A', '★',
    CAST('{"model":"coffeehouse","default_fulfillment":"fbm","pickup_enabled":true,"delivery_enabled":true,"menu_categories":["Hot Coffees","Cold Drinks","Frappuccino","Breakfast","Bakery","Snacks"],"seller_label":"Starbucks seller","order_hint":"Barista-style prep — name on cup, ready for pickup or delivery."}' AS JSON),
    40, 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `commerce_brands` LIMIT 1);

SET FOREIGN_KEY_CHECKS = 1;
