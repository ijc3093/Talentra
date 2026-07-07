-- Organization shop / commerce schema (2026-07-06)
-- Seller workspace: products catalog, orders inbox, storefront settings.
--
-- Usage (MAMP example):
--   mysql -u root -p -P 8889 talentra < Data/migrations/20260706_org_shop_commerce.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- 1. organizations.org_kind — community vs shop tenant
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'org_kind') = 0,
  'ALTER TABLE `organizations` ADD COLUMN `org_kind` enum(\'community\',\'shop\') NOT NULL DEFAULT \'community\' AFTER `publisher_category`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `organizations`
SET `org_kind` = 'shop'
WHERE `is_publisher_org` = 1
  AND (`org_kind` IS NULL OR `org_kind` = '' OR `org_kind` = 'community');

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND INDEX_NAME = 'idx_org_kind_status') = 0,
  'ALTER TABLE `organizations` ADD KEY `idx_org_kind_status` (`org_kind`,`status`,`created_at`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. org_settings.shop_json — storefront contact, payment, delivery notes
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_settings' AND COLUMN_NAME = 'shop_json') = 0,
  'ALTER TABLE `org_settings` ADD COLUMN `shop_json` json DEFAULT NULL AFTER `a11y_json`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. org_products — seller catalog (stored in organization / rented workspace)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_products` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text,
  `price_cents` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `stock_qty` int(11) DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `status` enum('draft','active','sold_out','archived') NOT NULL DEFAULT 'draft',
  `cover_image_path` varchar(500) DEFAULT NULL,
  `public_post_id` bigint(20) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_org_products_org_status` (`org_id`,`status`,`sort_order`,`created_at`),
  KEY `idx_org_products_public_post` (`public_post_id`),
  KEY `idx_org_products_sku` (`org_id`,`sku`),
  CONSTRAINT `fk_org_products_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 4. org_product_images — extra product photos
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_product_images` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_product_images_product` (`product_id`,`sort_order`),
  KEY `idx_org_product_images_org` (`org_id`,`created_at`),
  CONSTRAINT `fk_org_product_images_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_org_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `org_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 5. org_orders — customer purchase requests from public_user storefront
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_orders` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `order_code` varchar(24) NOT NULL,
  `buyer_user_id` bigint(20) DEFAULT NULL,
  `buyer_name` varchar(120) DEFAULT NULL,
  `buyer_phone` varchar(40) DEFAULT NULL,
  `buyer_email` varchar(120) DEFAULT NULL,
  `product_id` bigint(20) NOT NULL,
  `product_title` varchar(200) NOT NULL,
  `unit_price_cents` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `total_cents` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','confirmed','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `buyer_notes` text,
  `seller_notes` text,
  `delivery_address` text,
  `assigned_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_orders_code` (`order_code`),
  KEY `idx_org_orders_org_status` (`org_id`,`status`,`created_at`),
  KEY `idx_org_orders_buyer` (`buyer_user_id`,`created_at`),
  KEY `idx_org_orders_product` (`product_id`),
  CONSTRAINT `fk_org_orders_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_org_orders_product` FOREIGN KEY (`product_id`) REFERENCES `org_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 6. org_posts.public_post_id — link internal update to public feed post
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_posts' AND COLUMN_NAME = 'public_post_id') = 0,
  'ALTER TABLE `org_posts` ADD COLUMN `public_post_id` bigint(20) DEFAULT NULL AFTER `deleted_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_posts' AND INDEX_NAME = 'idx_org_posts_public_post') = 0,
  'ALTER TABLE `org_posts` ADD KEY `idx_org_posts_public_post` (`public_post_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 7. org_order_receipts — payment receipt per order (seller + buyer record)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_order_receipts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `order_id` bigint(20) NOT NULL,
  `receipt_code` varchar(28) NOT NULL,
  `buyer_user_id` bigint(20) DEFAULT NULL,
  `buyer_name` varchar(120) DEFAULT NULL,
  `buyer_email` varchar(120) DEFAULT NULL,
  `buyer_phone` varchar(40) DEFAULT NULL,
  `seller_name` varchar(120) DEFAULT NULL,
  `product_title` varchar(200) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price_cents` int(11) NOT NULL DEFAULT 0,
  `tax_cents` int(11) NOT NULL DEFAULT 0,
  `total_cents` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `payment_method` varchar(40) DEFAULT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `status` enum('issued','void') NOT NULL DEFAULT 'issued',
  `notes` text,
  `file_path` varchar(500) DEFAULT NULL,
  `issued_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `voided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_order_receipts_code` (`receipt_code`),
  UNIQUE KEY `uq_org_order_receipts_order` (`order_id`),
  KEY `idx_org_order_receipts_org` (`org_id`,`issued_at`),
  KEY `idx_org_order_receipts_buyer` (`buyer_user_id`,`issued_at`),
  CONSTRAINT `fk_org_order_receipts_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_org_order_receipts_order` FOREIGN KEY (`order_id`) REFERENCES `org_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
