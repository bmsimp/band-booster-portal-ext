-- IF NOT EXISTS on all three tables below: scripts/setup-test-db.sh reseeds
-- the headless test DB from a mysqldump of the LIVE 'db' database (see its
-- own header comment), which — now that these tables exist there too —
-- means a "fresh" Civi\Test install already finds them present before
-- install() ever runs. Without IF NOT EXISTS, a plain CREATE TABLE hard-
-- fails ("already exists") on every `--reset`, regardless of whether the
-- seeded copy's schema is current. This is safe: the live db's schema is
-- kept current via CRM_Boosterportal_Upgrader's numbered upgrade_NNNN()
-- steps (see upgrade_1001()), so whatever the seed carries is always the
-- up-to-date shape by the time a fresh install would try to (re)create it.
--
-- Mirror of the QBO customer tree (§5.4). Snapshot only; no FK to civicrm_*.
CREATE TABLE IF NOT EXISTS boosterportal_qbo_customer (
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

-- Nightly balance history: a PER-DAY snapshot, what makes drift diffable
-- (§5.4 design intent, confirmed by spec review). UNIQUE KEY uq_day means a
-- same-day re-run upserts that day's row (ON DUPLICATE KEY UPDATE in
-- Mirror::insertHistory() — latest refresh wins) rather than appending a
-- second row for the same customer/day. An earlier revision of this file
-- dropped this constraint to satisfy what turned out to be a self-
-- contradictory test assertion; the design's per-day-snapshot intent is
-- authoritative, so it's restored here. See CRM_Boosterportal_Upgrader::
-- upgrade_1001() for how the already-installed live site picks this up.
CREATE TABLE IF NOT EXISTS boosterportal_qbo_balance_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  qbo_id VARCHAR(32) NOT NULL,
  balance DECIMAL(12,2) NOT NULL,
  balance_with_jobs DECIMAL(12,2) NULL,
  synced_on DATE NOT NULL,
  UNIQUE KEY uq_day (qbo_id, synced_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Magic-link tokens (used from Task 15; created here so schema lives in one place).
CREATE TABLE IF NOT EXISTS boosterportal_login_token (
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

-- Reconciliation findings (§6, Task 13). Recomputed on every run: Recon::store()
-- deletes and rewrites the rows for whichever check_num values that run
-- produced (see its docblock), so this table always reflects the LAST run's
-- findings, not an ever-growing history. IF NOT EXISTS for the same reason as
-- the three tables above — scripts/setup-test-db.sh's mysqldump reseed of the
-- headless test DB already carries this table once it exists on the live 'db'
-- database too; schema changes after that point are upgrade_NNNN() steps
-- (CRM_Boosterportal_Upgrader::upgrade_1002()), same convention as uq_day above.
CREATE TABLE IF NOT EXISTS boosterportal_recon_finding (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  check_num INT NOT NULL,
  severity ENUM('CRITICAL','ERROR','WARNING') NOT NULL,
  title VARCHAR(255) NOT NULL,
  detail TEXT NOT NULL,
  found_at DATETIME NOT NULL,
  INDEX idx_severity (severity),
  INDEX idx_check (check_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
