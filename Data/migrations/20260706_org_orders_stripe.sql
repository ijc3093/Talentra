-- Stripe payment fields on org_orders (safe for existing DBs)
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'stripe_checkout_session_id') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `stripe_checkout_session_id` varchar(255) DEFAULT NULL AFTER `delivery_address`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'stripe_payment_intent_id') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `stripe_payment_intent_id` varchar(255) DEFAULT NULL AFTER `stripe_checkout_session_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND COLUMN_NAME = 'paid_at') = 0,
  'ALTER TABLE `org_orders` ADD COLUMN `paid_at` datetime DEFAULT NULL AFTER `stripe_payment_intent_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'org_orders' AND INDEX_NAME = 'idx_org_orders_stripe_session') = 0,
  'ALTER TABLE `org_orders` ADD KEY `idx_org_orders_stripe_session` (`stripe_checkout_session_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
