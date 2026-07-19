-- Organization employee time card (clock in / out)
-- Applied automatically by org_timecard_ensure_schema() when the Time card page is opened.

CREATE TABLE IF NOT EXISTS org_time_cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  org_member_id BIGINT UNSIGNED NOT NULL,
  employee_name VARCHAR(190) NOT NULL DEFAULT '',
  employee_role VARCHAR(40) NOT NULL DEFAULT 'staff',
  entry_type VARCHAR(20) NOT NULL DEFAULT 'regular',
  status VARCHAR(20) NOT NULL DEFAULT 'approved',
  clock_in DATETIME NOT NULL,
  clock_out DATETIME NULL,
  worked_seconds INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  approved_at DATETIME NULL,
  approved_by_member_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_org_time_cards_member (org_id, org_member_id),
  KEY idx_org_time_cards_open (org_id, org_member_id, clock_out),
  KEY idx_org_time_cards_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
