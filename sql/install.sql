-- Mirror of the QBO customer tree (§5.4). Snapshot only; no FK to civicrm_*.
CREATE TABLE boosterportal_qbo_customer (
  qbo_id VARCHAR(32) NOT NULL PRIMARY KEY,
  display_name VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  parent_ref VARCHAR(32) NULL,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance_with_jobs DECIMAL(12,2) NULL,
  email VARCHAR(255) NULL,
  synced_at DATETIME NOT NULL,
  INDEX idx_parent_ref (parent_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nightly balance history: what makes drift diffable (§5.4).
--
-- Deliberately NOT deduped by (qbo_id, synced_on) via a UNIQUE key +
-- ON DUPLICATE KEY UPDATE, as first sketched in the Task 10 plan doc: doing
-- so collapses same-day re-runs into an UPDATE instead of a new row, which
-- contradicts Mirror.php's contract (and MirrorTest::testRefreshPopulatesMirrorAndHistory)
-- that every refresh() call appends a history row, never collapses one. In
-- production the nightly job runs once/day, so this still yields exactly one
-- row/day/customer in the common case; a manual same-day re-run just leaves
-- extra rows for that day, which reconciliation (Task 13) can collapse by
-- picking MAX(id) per (qbo_id, synced_on) if that ever matters.
CREATE TABLE boosterportal_qbo_balance_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  qbo_id VARCHAR(32) NOT NULL,
  balance DECIMAL(12,2) NOT NULL,
  balance_with_jobs DECIMAL(12,2) NULL,
  synced_on DATE NOT NULL,
  INDEX idx_qbo_id_day (qbo_id, synced_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Magic-link tokens (used from Task 15; created here so schema lives in one place).
CREATE TABLE boosterportal_login_token (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  request_ip VARCHAR(45) NULL,
  UNIQUE KEY uq_token (token_hash),
  INDEX idx_email_created (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
