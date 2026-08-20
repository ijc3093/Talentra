-- Platform moderation reports (public_user + organization → admin queue).
-- Safe to run on an existing DB. Runtime also CREATE IF NOT EXISTS via msb_reports_ensure_schema().

CREATE TABLE IF NOT EXISTS `public_user_reports` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reporter_id` INT(11) NOT NULL DEFAULT 0,
  `reporter_kind` ENUM('user','manager','staff') NOT NULL DEFAULT 'user',
  `reporter_org_id` BIGINT(20) NULL DEFAULT NULL,
  `reporter_label` VARCHAR(160) NULL DEFAULT NULL,
  `target_type` ENUM('post','user','message','product','org','other') NOT NULL DEFAULT 'other',
  `target_id` BIGINT(20) NOT NULL DEFAULT 0,
  `target_user_id` BIGINT(20) NULL DEFAULT NULL,
  `target_org_id` BIGINT(20) NULL DEFAULT NULL,
  `reason` VARCHAR(40) NOT NULL DEFAULT 'other',
  `details` TEXT NULL,
  `status` ENUM('pending','reviewed','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `admin_note` TEXT NULL,
  `reviewed_by_admin_id` INT(11) NULL DEFAULT NULL,
  `reviewed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reports_status_created` (`status`, `created_at`),
  KEY `idx_reports_reporter` (`reporter_id`, `created_at`),
  KEY `idx_reports_target` (`target_type`, `target_id`),
  KEY `idx_reports_target_user` (`target_user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
