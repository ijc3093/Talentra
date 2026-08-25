-- Admin-facing log of user Danger Zone / account-center actions.

CREATE TABLE IF NOT EXISTS `user_account_admin_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_type` varchar(40) NOT NULL,
  `title` varchar(255) NOT NULL,
  `detail` text NOT NULL,
  `admin_next` text NOT NULL,
  `still_using` tinyint(1) NOT NULL DEFAULT 1,
  `meta_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_account_admin_events_user` (`user_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
