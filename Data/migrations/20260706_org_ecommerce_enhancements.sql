-- Organization ecommerce enhancements + Amazon-style commerce fields (idempotent)
-- Safe to run multiple times on databases that already applied older partial migrations.
--
-- Usage (MAMP example):
--   mysql -u root -p -P 8889 talentra < Data/migrations/20260706_org_ecommerce_enhancements.sql
--
-- Related migrations (run separately if bootstrapping a fresh DB):
--   20260706_org_shop_commerce.sql
--   20260706_org_business_models.sql
--   20260706_platform_rent.sql
--   20260706_org_orders_stripe.sql
--   20260706_org_cart.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- org_products — SEO / PIM
-- ---------------------------------------------------------------------------
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
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'bullet_points') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `bullet_points` text DEFAULT NULL AFTER `slug`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'search_keywords') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `search_keywords` varchar(500) DEFAULT NULL AFTER `bullet_points`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'fulfillment_method') = 0,
  'ALTER TABLE `org_products` ADD COLUMN `fulfillment_method` enum(\'fba\',\'fbm\') NOT NULL DEFAULT \'fbm\' AFTER `search_keywords`',
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

-- ---------------------------------------------------------------------------
-- organizations — seller plan (Individual vs Professional)
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'seller_plan') = 0,
  'ALTER TABLE `organizations` ADD COLUMN `seller_plan` enum(\'individual\',\'professional\') NOT NULL DEFAULT \'individual\' AFTER `business_model`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- org_orders — logistics, fulfillment model, seller fees / payout
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'tracking_number') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `tracking_number` varchar(120) DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'carrier') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `carrier` varchar(80) DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'shipped_at') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `shipped_at` datetime DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'delivered_at') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `delivered_at` datetime DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'fulfillment_method') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `fulfillment_method` enum(\'fba\',\'fbm\') NOT NULL DEFAULT \'fbm\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'delivery_option') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `delivery_option` enum(\'pickup\',\'home_delivery\',\'same_day\') NOT NULL DEFAULT \'home_delivery\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'referral_fee_cents') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `referral_fee_cents` int(11) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'fulfillment_fee_cents') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `fulfillment_fee_cents` int(11) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'platform_fee_cents') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `platform_fee_cents` int(11) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'seller_payout_cents') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `seller_payout_cents` int(11) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'payout_status') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `payout_status` enum(\'pending\',\'scheduled\',\'paid\') NOT NULL DEFAULT \'pending\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Reviews, returns, cart (also in 20260706_org_cart.sql — IF NOT EXISTS is safe)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_product_reviews` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `order_id` bigint(20) DEFAULT NULL,
  `buyer_user_id` bigint(20) NOT NULL,
  `rating` tinyint(4) NOT NULL DEFAULT 5,
  `review_text` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_review_order_buyer` (`order_id`,`buyer_user_id`),
  KEY `idx_reviews_product` (`product_id`,`created_at`),
  KEY `idx_reviews_org` (`org_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_order_returns` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `buyer_user_id` bigint(20) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `status` enum('requested','approved','rejected','refunded') NOT NULL DEFAULT 'requested',
  `seller_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_returns_order` (`order_id`),
  KEY `idx_returns_buyer` (`buyer_user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_cart_items` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `org_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cart_user_product` (`user_id`,`product_id`),
  KEY `idx_cart_user` (`user_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
