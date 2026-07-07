-- CRM lifecycle: Capture, Convert, Serve, Collect, Retain
SET FOREIGN_KEY_CHECKS = 0;

-- Extend lead capture sources
ALTER TABLE `org_crm_contacts`
  MODIFY `lead_source` enum('manual','shop','referral','web','portal','phone','import','other') NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS `org_crm_quotes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `quote_code` varchar(24) NOT NULL,
  `title` varchar(200) NOT NULL,
  `amount_cents` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `status` enum('draft','sent','pending_approval','approved','rejected','expired') NOT NULL DEFAULT 'draft',
  `valid_until` date DEFAULT NULL,
  `notes` text,
  `assigned_member_id` bigint(20) DEFAULT NULL,
  `approved_by_member_id` bigint(20) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_crm_quote_code` (`quote_code`),
  KEY `idx_crm_quotes_org_status` (`org_id`,`status`,`created_at`),
  KEY `idx_crm_quotes_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_reminders` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `deal_id` bigint(20) DEFAULT NULL,
  `quote_id` bigint(20) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `body` text,
  `due_at` datetime NOT NULL,
  `status` enum('pending','done','dismissed') NOT NULL DEFAULT 'pending',
  `assigned_member_id` bigint(20) DEFAULT NULL,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_reminders_org_due` (`org_id`,`status`,`due_at`),
  KEY `idx_crm_reminders_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_contact_addresses` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) NOT NULL,
  `label` varchar(60) DEFAULT 'Primary',
  `line1` varchar(200) NOT NULL,
  `line2` varchar(200) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(80) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_addresses_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_contact_files` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(80) DEFAULT NULL,
  `uploaded_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_files_contact` (`contact_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_bookings` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 60,
  `status` enum('scheduled','in_progress','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  `location` varchar(255) DEFAULT NULL,
  `fieldworker_member_id` bigint(20) DEFAULT NULL,
  `notes` text,
  `is_repeat` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_bookings_org_sched` (`org_id`,`status`,`scheduled_at`),
  KEY `idx_crm_bookings_contact` (`contact_id`),
  KEY `idx_crm_bookings_fieldworker` (`fieldworker_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_invoices` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `invoice_code` varchar(24) NOT NULL,
  `title` varchar(200) NOT NULL,
  `amount_cents` int(11) NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `status` enum('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `due_date` date DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `related_order_id` bigint(20) DEFAULT NULL,
  `related_quote_id` bigint(20) DEFAULT NULL,
  `notes` text,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_crm_invoice_code` (`invoice_code`),
  KEY `idx_crm_invoices_org_status` (`org_id`,`status`,`due_date`),
  KEY `idx_crm_invoices_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_feedback` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `contact_id` bigint(20) DEFAULT NULL,
  `booking_id` bigint(20) DEFAULT NULL,
  `rating` tinyint(3) NOT NULL DEFAULT 5,
  `comment` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_feedback_org` (`org_id`,`created_at`),
  KEY `idx_crm_feedback_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `org_crm_campaigns` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `channel` enum('email','sms','social','other') NOT NULL DEFAULT 'email',
  `message` text,
  `status` enum('draft','scheduled','sent','cancelled') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `recipient_count` int(11) NOT NULL DEFAULT 0,
  `created_by_member_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_campaigns_org` (`org_id`,`status`,`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
