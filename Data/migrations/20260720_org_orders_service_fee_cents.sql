-- Buyer-paid online service fee ($1.99) collected for the platform/admin.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'org_orders'
    AND COLUMN_NAME = 'service_fee_cents'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE org_orders ADD COLUMN service_fee_cents INT NOT NULL DEFAULT 0 AFTER tax_cents',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
