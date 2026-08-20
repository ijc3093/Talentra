-- Feed engagement: attention watches, sounds, stitch/duet, post product tags
-- Idempotent where possible (CREATE IF NOT EXISTS / guarded ALTERs via ensure_*).

CREATE TABLE IF NOT EXISTS `public_sounds` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `created_by` INT(11) NOT NULL DEFAULT 0,
  `title` VARCHAR(120) NOT NULL DEFAULT '',
  `artist` VARCHAR(120) NOT NULL DEFAULT '',
  `source_post_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `use_count` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sounds_title_artist` (`title`, `artist`),
  KEY `idx_sounds_use` (`use_count`),
  KEY `idx_sounds_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `public_post_watch_events` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT(20) UNSIGNED NOT NULL,
  `user_id` INT(11) NOT NULL,
  `watch_ms` INT(11) NOT NULL DEFAULT 0,
  `duration_ms` INT(11) NOT NULL DEFAULT 0,
  `completed` TINYINT(1) NOT NULL DEFAULT 0,
  `skipped` TINYINT(1) NOT NULL DEFAULT 0,
  `source` ENUM('reel','feed','story') NOT NULL DEFAULT 'feed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_watch_post_created` (`post_id`, `created_at`),
  KEY `idx_watch_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `public_post_products` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT(20) UNSIGNED NOT NULL,
  `product_id` INT(11) NOT NULL,
  `org_id` INT(11) NOT NULL DEFAULT 0,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_product` (`post_id`, `product_id`),
  KEY `idx_ppp_post` (`post_id`),
  KEY `idx_ppp_product` (`product_id`),
  KEY `idx_ppp_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
