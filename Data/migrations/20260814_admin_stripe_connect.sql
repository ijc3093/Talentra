-- Admin oversight: Stripe Connect columns on seller orgs + transfer id on orders.
-- Idempotent for phpMyAdmin: only ADD missing columns (MySQL 8+ / MariaDB 10.3+).
-- If you already saw #1060 Duplicate column — columns exist; skip this file.

SET @db := DATABASE();

-- organizations.stripe_connect_account_id
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'stripe_connect_account_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `organizations` ADD COLUMN `stripe_connect_account_id` VARCHAR(64) NULL DEFAULT NULL',
  'SELECT ''skip stripe_connect_account_id'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- organizations.stripe_connect_charges_enabled
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'stripe_connect_charges_enabled'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `organizations` ADD COLUMN `stripe_connect_charges_enabled` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''skip stripe_connect_charges_enabled'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- organizations.stripe_connect_payouts_enabled
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'stripe_connect_payouts_enabled'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `organizations` ADD COLUMN `stripe_connect_payouts_enabled` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''skip stripe_connect_payouts_enabled'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- organizations.stripe_connect_details_submitted
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'stripe_connect_details_submitted'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `organizations` ADD COLUMN `stripe_connect_details_submitted` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''skip stripe_connect_details_submitted'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- org_orders.stripe_transfer_id
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'stripe_transfer_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `stripe_transfer_id` VARCHAR(64) NULL DEFAULT NULL',
  'SELECT ''skip stripe_transfer_id'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
