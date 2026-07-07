-- Organization business models (2026-07-06)
-- Retail, wholesale, manufacturing, services, subscription, licensing, advertising/affiliate
--
-- Usage (MAMP example):
--   mysql -u root -p -P 8889 talentra < Data/migrations/20260706_org_business_models.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- 1. organizations.business_model
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'business_model') = 0,
  'ALTER TABLE `organizations` ADD COLUMN `business_model` enum(\'retail\',\'wholesale\',\'manufacturing\',\'services\',\'subscription\',\'licensing\',\'advertising\') NOT NULL DEFAULT \'retail\' AFTER `org_kind`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `organizations`
SET `business_model` = 'advertising'
WHERE `is_publisher_org` = 1
  AND `publisher_category` IN ('news', 'media', 'entertainment');

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND INDEX_NAME = 'idx_org_business_model') = 0,
  'ALTER TABLE `organizations` ADD KEY `idx_org_business_model` (`business_model`,`status`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. org_products — offer types and pricing models
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND COLUMN_NAME = 'offer_type') = 0,
  'ALTER TABLE `org_products`
     ADD COLUMN `offer_type` enum(\'physical\',\'digital\',\'service\',\'subscription\',\'license\') NOT NULL DEFAULT \'physical\' AFTER `description`,
     ADD COLUMN `pricing_model` enum(\'one_time\',\'recurring\',\'quote\',\'free\',\'wholesale_tier\') NOT NULL DEFAULT \'one_time\' AFTER `offer_type`,
     ADD COLUMN `cost_cents` int(11) DEFAULT NULL AFTER `price_cents`,
     ADD COLUMN `wholesale_price_cents` int(11) DEFAULT NULL AFTER `cost_cents`,
     ADD COLUMN `min_order_qty` int(11) NOT NULL DEFAULT 1 AFTER `wholesale_price_cents`,
     ADD COLUMN `billing_interval` enum(\'none\',\'weekly\',\'monthly\',\'yearly\') NOT NULL DEFAULT \'none\' AFTER `min_order_qty`,
     ADD COLUMN `download_file_path` varchar(500) DEFAULT NULL AFTER `cover_image_path`,
     ADD COLUMN `license_terms` text AFTER `download_file_path`,
     ADD COLUMN `affiliate_url` varchar(500) DEFAULT NULL AFTER `license_terms`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_products' AND INDEX_NAME = 'idx_org_products_offer_type') = 0,
  'ALTER TABLE `org_products` ADD KEY `idx_org_products_offer_type` (`org_id`,`offer_type`,`status`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. org_product_price_tiers — wholesale quantity breaks
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_product_price_tiers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `min_qty` int(11) NOT NULL DEFAULT 1,
  `unit_price_cents` int(11) NOT NULL DEFAULT 0,
  `label` varchar(80) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_price_tiers_product` (`product_id`,`min_qty`),
  KEY `idx_org_price_tiers_org` (`org_id`),
  CONSTRAINT `fk_org_price_tiers_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_org_price_tiers_product` FOREIGN KEY (`product_id`) REFERENCES `org_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 4. org_subscriptions — recurring access fees
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_subscriptions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `buyer_user_id` bigint(20) NOT NULL,
  `status` enum('active','paused','cancelled','expired') NOT NULL DEFAULT 'active',
  `price_cents` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `billing_interval` enum('weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_billing_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_subscriptions_org_status` (`org_id`,`status`,`next_billing_at`),
  KEY `idx_org_subscriptions_buyer` (`buyer_user_id`,`status`),
  KEY `idx_org_subscriptions_product` (`product_id`),
  CONSTRAINT `fk_org_subscriptions_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_org_subscriptions_product` FOREIGN KEY (`product_id`) REFERENCES `org_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 5. org_orders — order type + subscription link
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'order_type') = 0,
  'ALTER TABLE `org_orders`
     ADD COLUMN `order_type` enum(\'purchase\',\'wholesale\',\'service\',\'subscription\',\'license\',\'affiliate\') NOT NULL DEFAULT \'purchase\' AFTER `status`,
     ADD COLUMN `subscription_id` bigint(20) DEFAULT NULL AFTER `order_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND INDEX_NAME = 'idx_org_orders_type') = 0,
  'ALTER TABLE `org_orders` ADD KEY `idx_org_orders_type` (`org_id`,`order_type`,`created_at`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND INDEX_NAME = 'idx_org_orders_subscription') = 0,
  'ALTER TABLE `org_orders` ADD KEY `idx_org_orders_subscription` (`subscription_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND CONSTRAINT_NAME = 'fk_org_orders_subscription') = 0,
  'ALTER TABLE `org_orders` ADD CONSTRAINT `fk_org_orders_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `org_subscriptions` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
