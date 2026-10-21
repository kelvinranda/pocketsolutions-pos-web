-- Phase 21 — Desktop (offline) tills: pairing, sync map, sync differences.
-- Safe to run more than once. The web POS also creates these itself the first time
-- Desktop Tills or api/v1 is opened, so running this file is optional.
-- Old app / mobile app: not affected (new tables + 3 new NULL columns on online_sales_ref).

CREATE TABLE IF NOT EXISTS desktop_tills (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(250) NOT NULL,
    branch VARCHAR(250) NOT NULL,
    till_code VARCHAR(16) NOT NULL,
    name VARCHAR(100) NOT NULL,
    invoice_prefix VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
    token_hash CHAR(64) NULL,
    pairing_hash CHAR(64) NULL,
    pairing_expires DATETIME NULL,
    register_id INT NULL,
    machine_name VARCHAR(120) NULL,
    app_version VARCHAR(40) NULL,
    registered_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    last_push_at DATETIME NULL,
    created_by VARCHAR(250) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_dt_company_code (company_name, till_code),
    UNIQUE KEY uniq_dt_token (token_hash),
    UNIQUE KEY uniq_dt_pairing (pairing_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS desktop_sync_map (
    client_uuid VARCHAR(64) NOT NULL PRIMARY KEY,
    till_id INT NOT NULL,
    kind VARCHAR(20) NOT NULL,
    server_ref VARCHAR(64) NOT NULL DEFAULT '',
    till_ref VARCHAR(64) NULL,
    result_json TEXT NULL,
    synced_at DATETIME NOT NULL,
    KEY idx_dsm_till (till_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS desktop_sync_issues (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(250) NOT NULL,
    branch VARCHAR(250) NOT NULL,
    till_id INT NOT NULL,
    kind VARCHAR(24) NOT NULL,
    client_uuid VARCHAR(64) NULL,
    invoice_number VARCHAR(64) NULL,
    till_ref VARCHAR(64) NULL,
    product_id VARCHAR(250) NULL,
    detail VARCHAR(500) NOT NULL DEFAULT '',
    till_value VARCHAR(64) NULL,
    server_value VARCHAR(64) NULL,
    occurred_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    resolved_by VARCHAR(250) NULL,
    KEY idx_dsi_company (company_name, resolved_at),
    KEY idx_dsi_till (till_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales header: which desktop till made the sale and its own receipt number (T01-000123).
ALTER TABLE online_sales_ref ADD COLUMN IF NOT EXISTS till_invoice_number VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE online_sales_ref ADD COLUMN IF NOT EXISTS desktop_till_id INT NULL DEFAULT NULL;
ALTER TABLE online_sales_ref ADD COLUMN IF NOT EXISTS sale_source VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE online_sales_ref ADD INDEX IF NOT EXISTS idx_osr_till_invoice (till_invoice_number);
