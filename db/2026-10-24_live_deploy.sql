-- =====================================================================================
-- Pockets POS — 2026-10-24 live deploy (MariaDB 10.3+)
-- Safe to run more than once. Does not change sale_invoice_seq or any sale/invoice numbers.
-- Does not turn on picking slip or loyalty (admins do that per company after deploy).
-- PHP also applies this on first use of Receipts / Loyalty / Suppliers / Tax / Security audit.
-- Desktop tills: SQLite schema v13 is applied by the new exe, not by this file.
-- =====================================================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------------------------------
-- 1. Two-document print: customer receipt + storeroom picking slip
-- -------------------------------------------------------------------------------------

ALTER TABLE print_jobs ENGINE=InnoDB;

ALTER TABLE print_jobs
  ADD COLUMN IF NOT EXISTS document VARCHAR(20) NOT NULL DEFAULT 'RECEIPT';

SET @exist := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'print_jobs' AND index_name = 'idx_print_jobs_invoice'
);
SET @sql := IF(@exist = 0, 'ALTER TABLE print_jobs ADD KEY idx_print_jobs_invoice (invoice_number)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------------------
-- 2. Cash-control settings + receipt reprint audit
-- -------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cash_control_settings (
    company_name VARCHAR(250) NOT NULL,
    setting_key VARCHAR(64) NOT NULL,
    setting_value VARCHAR(250) NOT NULL,
    updated_by VARCHAR(250) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (company_name, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_reprints (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(250) NOT NULL DEFAULT '',
    branch VARCHAR(250) NOT NULL DEFAULT '',
    invoice_number VARCHAR(64) NOT NULL,
    reprinted_by VARCHAR(250) NOT NULL,
    reprinted_at DATETIME NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'web',
    KEY idx_rr_invoice (invoice_number),
    KEY idx_rr_at (reprinted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE receipt_reprints
  ADD COLUMN IF NOT EXISTS approved_by VARCHAR(250) NULL,
  ADD COLUMN IF NOT EXISTS outcome VARCHAR(16) NOT NULL DEFAULT 'PRINTED',
  ADD COLUMN IF NOT EXISTS document VARCHAR(20) NOT NULL DEFAULT 'RECEIPT',
  ADD COLUMN IF NOT EXISTS note VARCHAR(250) NULL;

-- -------------------------------------------------------------------------------------
-- 3. Loyalty (programme stays off until an admin saves and enables it)
-- -------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS loyalty_programme (
    company_name VARCHAR(250) NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    points_per_unit DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    min_purchase DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    point_value DECIMAL(14,4) NOT NULL DEFAULT 0.0100,
    min_redeem_points INT NOT NULL DEFAULT 100,
    max_redeem_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    expiry_months INT NOT NULL DEFAULT 0,
    excluded_methods VARCHAR(120) NOT NULL DEFAULT '',
    refund_policy VARCHAR(16) NOT NULL DEFAULT 'PROPORTIONAL',
    updated_by VARCHAR(250) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_ledger (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(250) NOT NULL,
    customer_master_id INT NOT NULL,
    entry_type VARCHAR(16) NOT NULL,
    points INT NOT NULL,
    base_amount DECIMAL(14,2) NULL,
    base_currency CHAR(3) NULL,
    reference_type VARCHAR(16) NOT NULL,
    reference_id VARCHAR(64) NOT NULL,
    branch VARCHAR(250) NOT NULL DEFAULT '',
    reason VARCHAR(250) NOT NULL DEFAULT '',
    expires_on DATE NULL,
    created_by VARCHAR(250) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_loyalty_once (company_name, reference_type, reference_id, entry_type),
    KEY idx_loyalty_customer (company_name, customer_master_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------------------------
-- 4. Suppliers locked to the signed-in company
-- -------------------------------------------------------------------------------------

ALTER TABLE supplier_master
  ADD COLUMN IF NOT EXISTS company_name VARCHAR(250) NULL DEFAULT NULL;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'supplier_master' AND index_name = 'idx_supplier_master_company'
);
SET @sql := IF(@exist = 0, 'ALTER TABLE supplier_master ADD KEY idx_supplier_master_company (company_name)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Creator's company first (skip if supplier_audit is not on this database yet).
SET @has_audit := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'supplier_audit'
);
SET @sql := IF(@has_audit > 0,
  'UPDATE supplier_master s
   INNER JOIN supplier_audit a ON a.supplier_id = s.id AND a.action = ''CREATE''
   INNER JOIN users u ON LOWER(TRIM(u.email)) = LOWER(TRIM(a.changed_by))
   SET s.company_name = u.company_name
   WHERE (s.company_name IS NULL OR s.company_name = '''')
     AND u.company_name IS NOT NULL AND u.company_name <> ''''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Then majority company from PO / GRV branch (skip a table that is not there).
SET @has_po := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'purchase_orders'
);
SET @has_grv := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'goods_receipts'
);
SET @po_sql := IF(@has_po > 0,
  'SELECT po.supplier_id, b.company_name, COUNT(*) AS n
   FROM purchase_orders po
   INNER JOIN branchies b ON LOWER(TRIM(b.branch_name)) = LOWER(TRIM(po.branch))
   WHERE po.supplier_id > 0 AND b.company_name IS NOT NULL AND b.company_name <> ''''
   GROUP BY po.supplier_id, b.company_name',
  NULL);
SET @grv_sql := IF(@has_grv > 0,
  'SELECT gr.supplier_id, b.company_name, COUNT(*) AS n
   FROM goods_receipts gr
   INNER JOIN branchies b ON LOWER(TRIM(b.branch_name)) = LOWER(TRIM(gr.branch))
   WHERE gr.supplier_id IS NOT NULL AND gr.supplier_id > 0
     AND b.company_name IS NOT NULL AND b.company_name <> ''''
   GROUP BY gr.supplier_id, b.company_name',
  NULL);
SET @votes := CONCAT_WS(' UNION ALL ', @po_sql, @grv_sql);
SET @sql := IF(@votes IS NULL OR @votes = '',
  'SELECT 1',
  CONCAT(
    'UPDATE supplier_master s
     INNER JOIN (
        SELECT supplier_id, company_name FROM (
            SELECT x.supplier_id, x.company_name,
                   ROW_NUMBER() OVER (PARTITION BY x.supplier_id ORDER BY x.n DESC) AS rn
            FROM (', @votes, ') x
        ) ranked
        WHERE rn = 1
     ) v ON v.supplier_id = s.id
     SET s.company_name = v.company_name
     WHERE s.company_name IS NULL OR s.company_name = '''''
  ));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'supplier_master' AND index_name = 'uniq_supplier_master_company_code'
);
SET @sql := IF(@exist = 0, 'ALTER TABLE supplier_master ADD UNIQUE KEY uniq_supplier_master_company_code (company_name, supplier_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'supplier_master' AND index_name = 'uniq_supplier_master_code'
);
SET @sql := IF(@exist > 0, 'ALTER TABLE supplier_master DROP INDEX uniq_supplier_master_code', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------------------
-- 5. Tax rates / settings locked to the signed-in company
-- -------------------------------------------------------------------------------------

ALTER TABLE tax_rates
  ADD COLUMN IF NOT EXISTS company_name VARCHAR(250) NOT NULL DEFAULT '';

SET @exist := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'tax_rates' AND index_name = 'idx_tax_rates_company'
);
SET @sql := IF(@exist = 0, 'ALTER TABLE tax_rates ADD KEY idx_tax_rates_company (company_name)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'tax_rates' AND index_name = 'uniq_tax_rate_code_from'
);
SET @sql := IF(@exist > 0, 'ALTER TABLE tax_rates DROP INDEX uniq_tax_rate_code_from', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Blank rates take the first real company; other companies get a copy of that template.
SET @tax_template := (
  SELECT c FROM (
    SELECT TRIM(company_name) AS c FROM users WHERE company_name IS NOT NULL AND TRIM(company_name) <> ''
    UNION
    SELECT TRIM(company_name) FROM branchies WHERE company_name IS NOT NULL AND TRIM(company_name) <> ''
  ) x
  LIMIT 1
);

UPDATE tax_rates
SET company_name = IFNULL(@tax_template, company_name)
WHERE company_name = '' AND @tax_template IS NOT NULL AND @tax_template <> '';

INSERT INTO tax_rates
    (code, description, classification, rate_percent, pricing_mode, effective_from, effective_to,
     is_active, created_by, created_at, updated_by, updated_at, company_name)
SELECT src.code, src.description, src.classification, src.rate_percent, src.pricing_mode,
       src.effective_from, src.effective_to, src.is_active, src.created_by, src.created_at,
       src.updated_by, src.updated_at, dest.co
FROM tax_rates src
CROSS JOIN (
    SELECT DISTINCT TRIM(company_name) AS co FROM (
        SELECT company_name FROM users WHERE company_name IS NOT NULL AND TRIM(company_name) <> ''
        UNION
        SELECT company_name FROM branchies WHERE company_name IS NOT NULL AND TRIM(company_name) <> ''
    ) u
) dest
WHERE @tax_template IS NOT NULL
  AND src.company_name = @tax_template
  AND dest.co <> @tax_template
  AND NOT EXISTS (SELECT 1 FROM tax_rates t WHERE t.company_name = dest.co LIMIT 1);

SET @exist := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'tax_rates' AND index_name = 'uniq_tax_rate_company_code_from'
);
SET @sql := IF(@exist = 0, 'ALTER TABLE tax_rates ADD UNIQUE KEY uniq_tax_rate_company_code_from (company_name, code, effective_from)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tax_rate_audit (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tax_rate_id INT NULL DEFAULT NULL,
    code VARCHAR(32) NOT NULL DEFAULT '',
    action VARCHAR(32) NOT NULL,
    actor VARCHAR(250) NOT NULL DEFAULT '',
    details TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_tax_rate_audit_code (code),
    KEY idx_tax_rate_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tax_rate_audit
  ADD COLUMN IF NOT EXISTS company_name VARCHAR(250) NOT NULL DEFAULT '';

UPDATE tax_rate_audit a
INNER JOIN users u ON u.email = a.actor
SET a.company_name = u.company_name
WHERE a.company_name = '' AND u.company_name <> '';

CREATE TABLE IF NOT EXISTS tax_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value VARCHAR(250) NOT NULL DEFAULT '',
    updated_by VARCHAR(250) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL,
    company_name VARCHAR(250) NOT NULL DEFAULT '',
    PRIMARY KEY (company_name, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tax_settings
  ADD COLUMN IF NOT EXISTS company_name VARCHAR(250) NOT NULL DEFAULT '';

SET @pk := (
  SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'tax_settings' AND index_name = 'PRIMARY'
);
SET @sql := IF(@pk = 'setting_key',
  'ALTER TABLE tax_settings DROP PRIMARY KEY, ADD PRIMARY KEY (company_name, setting_key)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO tax_settings (setting_key, setting_value, updated_by, updated_at, company_name)
SELECT s.setting_key, s.setting_value, s.updated_by, s.updated_at, dest.co
FROM tax_settings s
CROSS JOIN (
    SELECT DISTINCT TRIM(company_name) AS co FROM (
        SELECT company_name FROM users WHERE company_name IS NOT NULL AND TRIM(company_name) <> ''
        UNION
        SELECT company_name FROM branchies WHERE company_name IS NOT NULL AND TRIM(company_name) <> ''
    ) u
) dest
WHERE s.company_name = ''
  AND dest.co <> ''
  AND NOT EXISTS (SELECT 1 FROM tax_settings t WHERE t.company_name = dest.co LIMIT 1);

-- -------------------------------------------------------------------------------------
-- 6. Security audit scoped to company
-- -------------------------------------------------------------------------------------

ALTER TABLE security_audit
  ADD COLUMN IF NOT EXISTS company_name VARCHAR(250) NOT NULL DEFAULT '';

SET @exist := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'security_audit' AND index_name = 'idx_security_audit_company'
);
SET @sql := IF(@exist = 0, 'ALTER TABLE security_audit ADD KEY idx_security_audit_company (company_name)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE security_audit a
INNER JOIN users u ON u.email = a.actor
SET a.company_name = u.company_name
WHERE a.company_name = '' AND u.company_name <> '';
