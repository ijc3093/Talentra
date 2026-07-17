-- Commerce seller approval requests (extends publisher_name_authority) — idempotent
SET NAMES utf8mb4;

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'publisher_name_authority' AND COLUMN_NAME = 'commerce_brand_id') = 0,
  'ALTER TABLE `publisher_name_authority` ADD COLUMN `commerce_brand_id` int unsigned DEFAULT NULL AFTER `publisher_category`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'publisher_name_authority' AND COLUMN_NAME = 'applicant_username') = 0,
  'ALTER TABLE `publisher_name_authority` ADD COLUMN `applicant_username` varchar(120) NOT NULL DEFAULT \'\' AFTER `commerce_brand_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'publisher_name_authority' AND COLUMN_NAME = 'applicant_email') = 0,
  'ALTER TABLE `publisher_name_authority` ADD COLUMN `applicant_email` varchar(120) NOT NULL DEFAULT \'\' AFTER `applicant_username`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'publisher_name_authority' AND INDEX_NAME = 'idx_pna_commerce_brand_email') = 0,
  'ALTER TABLE `publisher_name_authority` ADD KEY `idx_pna_commerce_brand_email` (`commerce_brand_id`, `applicant_email`, `status`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
