-- Platform rent billing (2026-07-06)
-- Sellers pay monthly rent to keep shop organizations live.
--
-- Usage (MAMP example):
--   mysql -u root -p -P 8889 talentra < Data/migrations/20260706_platform_rent.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS `platform_plans` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `price_cents` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `billing_interval` enum('none','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `trial_days` int(11) NOT NULL DEFAULT 0,
  `max_products` int(11) NOT NULL DEFAULT 10,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_platform_plans_code` (`code`),
  KEY `idx_platform_plans_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `platform_plans` (`id`, `code`, `name`, `price_cents`, `currency`, `billing_interval`, `trial_days`, `max_products`, `is_active`, `sort_order`, `created_at`) VALUES
(1, 'shop_trial', 'Shop Trial', 0, 'USD', 'none', 30, 10, 1, 0, NOW()),
(2, 'shop_basic', 'Shop Basic', 499, 'USD', 'monthly', 0, 50, 1, 10, NOW()),
(3, 'shop_pro', 'Shop Pro', 999, 'USD', 'monthly', 0, 500, 1, 20, NOW());

CREATE TABLE IF NOT EXISTS `platform_payments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `plan_id` bigint(20) NOT NULL,
  `amount_cents` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `months_paid` int(11) NOT NULL DEFAULT 1,
  `payment_method` varchar(40) DEFAULT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `status` enum('confirmed','pending','refunded') NOT NULL DEFAULT 'confirmed',
  `notes` text,
  `recorded_by_admin_id` int(11) DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_payments_org` (`org_id`,`paid_at`),
  KEY `idx_platform_payments_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'rent_status') = 0,
  'ALTER TABLE `organizations`
     ADD COLUMN `platform_plan_id` bigint(20) DEFAULT NULL AFTER `business_model`,
     ADD COLUMN `rent_status` enum(\'trial\',\'active\',\'overdue\',\'suspended\') NOT NULL DEFAULT \'trial\' AFTER `platform_plan_id`,
     ADD COLUMN `rent_paid_until` datetime DEFAULT NULL AFTER `rent_status`,
     ADD COLUMN `rent_trial_ends_at` datetime DEFAULT NULL AFTER `rent_paid_until`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `organizations`
SET `platform_plan_id` = COALESCE(`platform_plan_id`, 1),
    `rent_status` = IF(`rent_status` IS NULL OR `rent_status` = '', 'trial', `rent_status`),
    `rent_trial_ends_at` = COALESCE(`rent_trial_ends_at`, DATE_ADD(`created_at`, INTERVAL 30 DAY)),
    `org_kind` = IF(`is_publisher_org` = 1 AND `org_kind` = 'community', 'shop', `org_kind`)
WHERE `is_publisher_org` = 1 OR `org_kind` = 'shop';

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND INDEX_NAME = 'idx_org_rent_status') = 0,
  'ALTER TABLE `organizations` ADD KEY `idx_org_rent_status` (`rent_status`,`rent_paid_until`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'platform_payments' AND CONSTRAINT_NAME = 'fk_platform_payments_org') = 0,
  'ALTER TABLE `platform_payments` ADD CONSTRAINT `fk_platform_payments_org` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'platform_payments' AND CONSTRAINT_NAME = 'fk_platform_payments_plan') = 0,
  'ALTER TABLE `platform_payments` ADD CONSTRAINT `fk_platform_payments_plan` FOREIGN KEY (`plan_id`) REFERENCES `platform_plans` (`id`) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
