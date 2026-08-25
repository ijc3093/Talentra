-- Talentra: relationship / family @tags
-- Safe to run more than once.
--
-- Do not ALTER user_backgrounds here. Live already has profile_link and
-- about_sidebar_json. Adding them again causes #1060 (duplicate column).
-- Hostinger MySQL also does not support ADD COLUMN IF NOT EXISTS (#1064).

CREATE TABLE IF NOT EXISTS `profile_people_tags` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `tagged_user_id` int(11) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `role_key` varchar(40) NOT NULL,
  `tagged_username` varchar(80) NOT NULL DEFAULT '',
  `tagged_name` varchar(160) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_owner_kind_tagged` (`owner_id`,`kind`,`tagged_user_id`),
  KEY `idx_owner_kind` (`owner_id`,`kind`),
  KEY `idx_tagged` (`tagged_user_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
