-- Buyer shared preferences for sellers (orders-backed relationship).
CREATE TABLE IF NOT EXISTS `buyer_seller_relationships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `buyer_user_id` bigint(20) unsigned NOT NULL,
  `org_id` bigint(20) unsigned NOT NULL,
  `relationship_type` varchar(40) NOT NULL DEFAULT 'shopper',
  `interests` varchar(500) NOT NULL DEFAULT '',
  `preferred_contact` varchar(40) NOT NULL DEFAULT 'message',
  `delivery_preference` varchar(80) NOT NULL DEFAULT '',
  `budget_range` varchar(40) NOT NULL DEFAULT '',
  `needs_note` text,
  `share_with_seller` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_buyer_seller_rel` (`buyer_user_id`,`org_id`),
  KEY `idx_buyer_seller_rel_org` (`org_id`,`updated_at`),
  KEY `idx_buyer_seller_rel_buyer` (`buyer_user_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
