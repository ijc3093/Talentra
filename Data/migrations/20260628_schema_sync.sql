-- myStoryBook schema sync (2026-06-28)
-- Idempotent migration: safe to run on databases imported from an older myStoryBook.sql dump
-- or bootstrapped only via PHP ensure_schema helpers.
--
-- Usage (MAMP example):
--   mysql -u root -p -P 8889 private_project < Data/migrations/20260628_schema_sync.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. Live studio recordings (public_user/ajax/live_recording_upload.php)
--    Missing from myStoryBook.sql prior to 2026-06-28.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_video_live_recordings` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `live_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `original_name` varchar(190) NOT NULL DEFAULT '',
  `mime_type` varchar(120) NOT NULL DEFAULT '',
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `duration_seconds` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recording_blob` longblob NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_live_id` (`live_id`),
  KEY `idx_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Publisher authority columns present in dump but omitted from
--    publisher_authority_ensure_schema() CREATE/ALTER (legacy manual data).
-- ---------------------------------------------------------------------------
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'publisher_name_authority' AND COLUMN_NAME = 'registration_id') = 0,
  'ALTER TABLE `publisher_name_authority` ADD COLUMN `registration_id` varchar(40) NOT NULL DEFAULT \'\' AFTER `legal_entity_name`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'publisher_name_authority' AND COLUMN_NAME = 'registration_country') = 0,
  'ALTER TABLE `publisher_name_authority` ADD COLUMN `registration_country` varchar(80) NOT NULL DEFAULT \'US\' AFTER `registration_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Org post lifecycle columns (organization/posts.php)
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_posts' AND COLUMN_NAME = 'post_state') = 0,
  'ALTER TABLE `org_posts` ADD COLUMN `post_state` varchar(16) NOT NULL DEFAULT \'published\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_posts' AND COLUMN_NAME = 'deleted_at') = 0,
  'ALTER TABLE `org_posts` ADD COLUMN `deleted_at` datetime NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_posts' AND INDEX_NAME = 'idx_org_posts_feed_state') = 0,
  'ALTER TABLE `org_posts` ADD KEY `idx_org_posts_feed_state` (`org_id`,`is_deleted`,`is_visible`,`post_state`,`created_at`,`id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Publisher / org bridge columns (publisher_org_ensure_schema)
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'is_publisher_org') = 0,
  'ALTER TABLE `organizations` ADD COLUMN `is_publisher_org` tinyint(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'publisher_user_id') = 0,
  'ALTER TABLE `organizations` ADD COLUMN `publisher_user_id` bigint(20) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'publisher_category') = 0,
  'ALTER TABLE `organizations` ADD COLUMN `publisher_category` varchar(40) NOT NULL DEFAULT \'\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'managers' AND COLUMN_NAME = 'publisher_user_id') = 0,
  'ALTER TABLE `managers` ADD COLUMN `publisher_user_id` bigint(20) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'publisher_name_options' AND COLUMN_NAME = 'registered_user_id') = 0,
  'ALTER TABLE `publisher_name_options` ADD COLUMN `registered_user_id` int(10) UNSIGNED NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'publisher_name_options' AND COLUMN_NAME = 'org_id') = 0,
  'ALTER TABLE `publisher_name_options` ADD COLUMN `org_id` bigint(20) UNSIGNED NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Public user account columns (publisher_registry_ensure_schema / register.php)
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_kind') = 0,
  'ALTER TABLE `users` ADD COLUMN `account_kind` enum(\'personal\',\'publisher\') NOT NULL DEFAULT \'personal\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'publisher_category') = 0,
  'ALTER TABLE `users` ADD COLUMN `publisher_category` varchar(40) NOT NULL DEFAULT \'\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'publisher_tagline') = 0,
  'ALTER TABLE `users` ADD COLUMN `publisher_tagline` varchar(255) NOT NULL DEFAULT \'\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'policy_agreed') = 0,
  'ALTER TABLE `users` ADD COLUMN `policy_agreed` tinyint(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'policy_agreed_at') = 0,
  'ALTER TABLE `users` ADD COLUMN `policy_agreed_at` datetime NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'age_confirmed') = 0,
  'ALTER TABLE `users` ADD COLUMN `age_confirmed` tinyint(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'age_confirmed_at') = 0,
  'ALTER TABLE `users` ADD COLUMN `age_confirmed_at` datetime NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. Device metadata columns (device_profile.php)
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'public_posts' AND COLUMN_NAME = 'device_label') = 0,
  'ALTER TABLE `public_posts` ADD COLUMN `device_label` varchar(120) NOT NULL DEFAULT \'\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'public_posts' AND COLUMN_NAME = 'device_viewport') = 0,
  'ALTER TABLE `public_posts` ADD COLUMN `device_viewport` varchar(32) NOT NULL DEFAULT \'\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_video_lives' AND COLUMN_NAME = 'device_label') = 0,
  'ALTER TABLE `user_video_lives` ADD COLUMN `device_label` varchar(120) NOT NULL DEFAULT \'\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_video_lives' AND COLUMN_NAME = 'device_viewport') = 0,
  'ALTER TABLE `user_video_lives` ADD COLUMN `device_viewport` varchar(32) NOT NULL DEFAULT \'\'',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
