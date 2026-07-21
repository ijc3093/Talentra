-- Employee earnings account: balance after manager approves time cards.
CREATE TABLE IF NOT EXISTS org_member_earnings_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  org_member_id INT NOT NULL,
  balance_cents BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_org_member_earn_acct (org_id, org_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS org_member_earnings_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  org_member_id INT NOT NULL,
  amount_cents INT NOT NULL,
  balance_after_cents BIGINT NOT NULL DEFAULT 0,
  txn_type VARCHAR(40) NOT NULL,
  reference_type VARCHAR(40) NOT NULL DEFAULT 'time_card',
  reference_id INT NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  created_by_member_id INT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_earn_txn_idempotent (org_id, reference_type, reference_id, txn_type),
  KEY idx_earn_txn_member (org_id, org_member_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
