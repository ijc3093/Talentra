-- Organization CRM: contacts, interactions, tickets, deals
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `org_crm_contacts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `linked_user_id` bigint(20) DEFAULT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `company` varchar(120) DEFAULT NULL,
  `job_title` varchar(80) DEFAULT NULL,
  `lifecycle_stage` enum('lead','prospect','customer','partner','churned') NOT NULL DEFAULT 'lead',
  `lead_source` enum('manual','shop','referral','web','import','other') NOT NULL DEFAULT 'manual',
  `tags` varchar(255) DEFAULT NULL,
  `notes` text,
  `assigned_member_id` bigint(20) DEFAULT NULL,
  `last_contacted_at` datetime DEFAULT NULL,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_crm_contacts_org_stage` (`org_id`,`lifecycle_stage`,`updated_at`),
  KEY `idx_crm_contacts_org_email` (`org_id`,`email`),
  KEY `idx_crm_contacts_linked_user` (`linked_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_interactions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) NOT NULL,
  `member_id` bigint(20) DEFAULT NULL,
  `interaction_type` enum('note','call','email','meeting','shop_order','ticket','task') NOT NULL DEFAULT 'note',
  `subject` varchar(200) DEFAULT NULL,
  `body` text,
  `related_order_id` bigint(20) DEFAULT NULL,
  `related_ticket_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_interactions_contact` (`contact_id`,`created_at`),
  KEY `idx_crm_interactions_org` (`org_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_tickets` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `ticket_code` varchar(24) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `description` text,
  `status` enum('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `assigned_member_id` bigint(20) DEFAULT NULL,
  `requester_name` varchar(120) DEFAULT NULL,
  `requester_email` varchar(120) DEFAULT NULL,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_crm_ticket_code` (`ticket_code`),
  KEY `idx_crm_tickets_org_status` (`org_id`,`status`,`updated_at`),
  KEY `idx_crm_tickets_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_ticket_replies` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `ticket_id` bigint(20) NOT NULL,
  `member_id` bigint(20) DEFAULT NULL,
  `body` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_ticket_replies_ticket` (`ticket_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_deals` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `stage` enum('lead','qualified','proposal','negotiation','won','lost') NOT NULL DEFAULT 'lead',
  `amount_cents` int(11) NOT NULL DEFAULT '0',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `probability` tinyint(3) NOT NULL DEFAULT '20',
  `expected_close_date` date DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `assigned_member_id` bigint(20) DEFAULT NULL,
  `notes` text,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_crm_deals_org_stage` (`org_id`,`stage`,`expected_close_date`),
  KEY `idx_crm_deals_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
