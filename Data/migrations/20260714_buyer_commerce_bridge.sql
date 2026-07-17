-- Buyer↔seller commerce bridge (addresses, promo on orders)
-- Idempotent: safe to re-run.

SET @db := DATABASE();

-- Buyer saved shipping / billing addresses (public_user prefs + checkout)
CREATE TABLE IF NOT EXISTS `buyer_shipping_addresses` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `label` varchar(80) NOT NULL DEFAULT 'Home',
  `full_name` varchar(120) NOT NULL DEFAULT '',
  `phone` varchar(40) DEFAULT NULL,
  `line1` varchar(200) NOT NULL,
  `line2` varchar(200) DEFAULT NULL,
  `city` varchar(100) NOT NULL DEFAULT '',
  `region` varchar(100) DEFAULT NULL,
  `postal_code` varchar(32) DEFAULT NULL,
  `country` varchar(80) NOT NULL DEFAULT 'US',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_buyer_addr_user` (`user_id`,`is_default`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Promo snapshot on org_orders (seller codes from shop_json.promotions)
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'promo_code') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `promo_code` varchar(40) DEFAULT NULL AFTER `total_cents`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'discount_cents') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `discount_cents` int(11) NOT NULL DEFAULT 0 AFTER `promo_code`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND INDEX_NAME = 'idx_org_orders_promo') = 0,
  'ALTER TABLE `org_orders` ADD KEY `idx_org_orders_promo` (`org_id`,`promo_code`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
