-- myStoryBook: optional music line on post cards (feed.php, public.php)
-- Idempotent migration — safe to run multiple times.
--
-- Usage (MAMP example):
--   mysql -u root -p -P 8889 private_project < Data/migrations/20260705_public_posts_music.sql

SET NAMES utf8mb4;

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'public_posts' AND COLUMN_NAME = 'music_title') = 0,
  'ALTER TABLE `public_posts` ADD COLUMN `music_title` varchar(120) NOT NULL DEFAULT \'\' AFTER `device_viewport`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'public_posts' AND COLUMN_NAME = 'music_artist') = 0,
  'ALTER TABLE `public_posts` ADD COLUMN `music_artist` varchar(120) NOT NULL DEFAULT \'\' AFTER `music_title`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
