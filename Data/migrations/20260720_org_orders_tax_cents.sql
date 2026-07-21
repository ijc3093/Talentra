-- Sales tax collected from the customer (matches checkout Order Summary).
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'org_orders'
    AND COLUMN_NAME = 'tax_cents'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE org_orders ADD COLUMN tax_cents INT NOT NULL DEFAULT 0 AFTER shipping_fee_cents',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
