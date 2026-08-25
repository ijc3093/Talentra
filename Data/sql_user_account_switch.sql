-- Multi-account switcher: one person can keep several users (personal, publisher, commerce)
-- and switch without losing the linked set.

CREATE TABLE IF NOT EXISTS `user_account_switch` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bundle_id` char(36) NOT NULL,
  `user_id` int(11) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_account_switch_user` (`user_id`),
  KEY `idx_user_account_switch_bundle` (`bundle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
