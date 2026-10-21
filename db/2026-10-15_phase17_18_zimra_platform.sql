-- =====================================================================================
-- Pockets POS — database changes for Phase 17 (ZIMRA fiscalisation) and
-- Phase 18 (companies register, Super Admin / Pocket Solutions administration)
--
-- Prepared 2026-10-15 against the database uploaded on 13 Oct 2026 (pocketso_android_pos).
-- Target: MariaDB 10.3 or newer (XAMPP and the cPanel host both run MariaDB).
--
-- HOW TO RUN
--   1. Take a backup first (phpMyAdmin > Export, or Backup & Recovery in the POS).
--   2. phpMyAdmin > select the POS database > SQL tab > paste this whole file > Go.
--      (or: mysql -u USER -p DBNAME < 2026-10-15_phase17_18_zimra_platform.sql)
--   3. Safe to run more than once: every statement checks first (IF NOT EXISTS / INSERT IGNORE).
--      Nothing is dropped, renamed or deleted, and no existing row is changed.
--
-- The POS also creates these tables by itself on first use; running this file up front just makes
-- sure they exist on hosts where the web database user may not create tables.
-- =====================================================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------------------------------
-- PHASE 17 — ZIMRA FDMS fiscalisation (7 new tables)
--   zimra_devices      one fiscal device per branch + environment (keys encrypted, certificate, logo)
--   zimra_taxes        taxes from ZIMRA GetConfig
--   zimra_tax_map      POS tax code -> ZIMRA tax
--   zimra_fiscal_days  fiscal days (open / close / counters)
--   zimra_receipts     fiscal invoices and credit notes (signed JSON, status, QR)
--   zimra_api_log      every call to ZIMRA (secrets masked)
--   zimra_audit        set-up and fiscal day actions
-- -------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `zimra_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `branch` varchar(250) NOT NULL,
  `company_name` varchar(250) NOT NULL DEFAULT '',
  `environment` varchar(12) NOT NULL DEFAULT 'test',
  `device_id` int(10) unsigned NOT NULL,
  `device_serial_no` varchar(50) NOT NULL,
  `model_name` varchar(100) NOT NULL,
  `model_version` varchar(50) NOT NULL,
  `activation_key_enc` text DEFAULT NULL,
  `private_key_enc` text DEFAULT NULL,
  `certificate_pem` text DEFAULT NULL,
  `certificate_valid_till` datetime DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'NEW',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `operating_mode` varchar(20) DEFAULT NULL,
  `taxpayer_name` varchar(250) DEFAULT NULL,
  `taxpayer_tin` varchar(20) DEFAULT NULL,
  `vat_number` varchar(20) DEFAULT NULL,
  `fdms_branch_name` varchar(250) DEFAULT NULL,
  `fdms_branch_address` text DEFAULT NULL,
  `fdms_branch_contacts` text DEFAULT NULL,
  `qr_url` varchar(250) DEFAULT NULL,
  `day_max_hrs` smallint(5) unsigned DEFAULT NULL,
  `day_end_notification_hrs` smallint(5) unsigned DEFAULT NULL,
  `config_json` mediumtext DEFAULT NULL,
  `config_synced_at` datetime DEFAULT NULL,
  `last_fiscal_day_no` int(10) unsigned NOT NULL DEFAULT 0,
  `last_receipt_global_no` int(10) unsigned NOT NULL DEFAULT 0,
  `print_form` varchar(12) NOT NULL DEFAULT 'Receipt48',
  `created_by` varchar(250) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `receipt_logo` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zimra_env_device` (`environment`,`device_id`),
  UNIQUE KEY `uq_zimra_company_branch_env` (`company_name`,`branch`,`environment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `zimra_taxes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_row_id` int(10) unsigned NOT NULL,
  `tax_id` int(10) unsigned NOT NULL,
  `tax_code` varchar(3) DEFAULT NULL,
  `tax_name` varchar(100) NOT NULL,
  `tax_percent` decimal(5,2) DEFAULT NULL,
  `valid_from` datetime DEFAULT NULL,
  `valid_till` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zimra_device_tax` (`device_row_id`,`tax_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `zimra_tax_map` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_row_id` int(10) unsigned NOT NULL,
  `pos_tax_code` varchar(32) NOT NULL,
  `tax_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zimra_tax_map` (`device_row_id`,`pos_tax_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `zimra_fiscal_days` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_row_id` int(10) unsigned NOT NULL,
  `fiscal_day_no` int(10) unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'OPEN',
  `open_state` varchar(12) NOT NULL DEFAULT 'PENDING',
  `opened_at` datetime NOT NULL,
  `opened_by` varchar(250) NOT NULL,
  `open_error` text DEFAULT NULL,
  `last_receipt_counter` int(10) unsigned NOT NULL DEFAULT 0,
  `last_receipt_hash` varchar(100) DEFAULT NULL,
  `close_requested_at` datetime DEFAULT NULL,
  `close_requested_by` varchar(250) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `counters_json` mediumtext DEFAULT NULL,
  `close_error` text DEFAULT NULL,
  `server_signature_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zimra_device_day` (`device_row_id`,`fiscal_day_no`),
  KEY `ix_zimra_day_status` (`device_row_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `zimra_receipts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_row_id` int(10) unsigned NOT NULL,
  `fiscal_day_row_id` int(10) unsigned NOT NULL,
  `source_type` varchar(12) NOT NULL,
  `source_ref` varchar(64) NOT NULL,
  `invoice_number` varchar(64) NOT NULL,
  `branch` varchar(250) NOT NULL,
  `receipt_type` varchar(16) NOT NULL,
  `receipt_currency` char(3) NOT NULL,
  `receipt_counter` int(10) unsigned NOT NULL,
  `receipt_global_no` int(10) unsigned NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `receipt_date` datetime NOT NULL,
  `receipt_total` decimal(14,2) NOT NULL,
  `receipt_hash` varchar(100) NOT NULL,
  `receipt_signature` text NOT NULL,
  `request_json` mediumtext NOT NULL,
  `line_map_json` mediumtext DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'PENDING',
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `zimra_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `server_date` datetime DEFAULT NULL,
  `server_signature_json` text DEFAULT NULL,
  `validation_errors_json` text DEFAULT NULL,
  `qr_url` varchar(250) NOT NULL,
  `verification_code` char(19) NOT NULL,
  `original_receipt_row_id` int(10) unsigned DEFAULT NULL,
  `created_by` varchar(250) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zimra_device_global` (`device_row_id`,`receipt_global_no`),
  UNIQUE KEY `uq_zimra_day_counter` (`fiscal_day_row_id`,`receipt_counter`),
  UNIQUE KEY `uq_zimra_source` (`source_type`,`source_ref`),
  KEY `ix_zimra_invoice` (`invoice_number`,`branch`),
  KEY `ix_zimra_receipt_status` (`device_row_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `zimra_api_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_row_id` int(10) unsigned DEFAULT NULL,
  `endpoint` varchar(100) NOT NULL,
  `http_status` smallint(6) DEFAULT NULL,
  `operation_id` varchar(100) DEFAULT NULL,
  `error_code` varchar(20) DEFAULT NULL,
  `request_body` mediumtext DEFAULT NULL,
  `response_body` mediumtext DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `actor` varchar(250) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_zimra_api_device` (`device_row_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `zimra_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_row_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `actor` varchar(250) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_zimra_audit_device` (`device_row_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Receipt logo (added after the first ZIMRA build — needed if zimra_devices already existed).
ALTER TABLE `zimra_devices` ADD COLUMN IF NOT EXISTS `receipt_logo` MEDIUMTEXT NULL DEFAULT NULL;

-- One device per branch PER COMPANY (two companies can both have a branch called e.g. "RUSAPE").
-- Needed if you ran an earlier copy of this file.
ALTER TABLE `zimra_devices` DROP INDEX IF EXISTS `uq_zimra_branch_env`;
ALTER TABLE `zimra_devices` ADD UNIQUE KEY IF NOT EXISTS `uq_zimra_company_branch_env` (`company_name`,`branch`,`environment`);


-- -------------------------------------------------------------------------------------
-- PHASE 18 — Companies register and Super Admin (Pocket Solutions staff)
--   companies       one row per client. company_name is the fixed key already used by
--                   users.company_name, branchies.company_name, etc.; display_name can be edited.
--                   status ACTIVE | SUSPENDED. provider_managed = 1 lets Super Admins manage it.
--   platform_audit  every Super Admin action (company changes, password resets, enter/exit company).
--   Super Admin accounts are ordinary users rows with level = 'SuperAdmin'
--   and company_name = '__platform__' (no new column needed).
-- -------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `companies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `display_name` varchar(250) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'ACTIVE',
  `provider_managed` tinyint(1) NOT NULL DEFAULT 0,
  `managed_since` datetime DEFAULT NULL,
  `managed_reference` varchar(250) DEFAULT NULL,
  `contact_name` varchar(250) DEFAULT NULL,
  `contact_email` varchar(250) DEFAULT NULL,
  `contact_phone` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `suspended_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `created_by` varchar(250) NOT NULL DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(250) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_companies_name` (`company_name`),
  KEY `idx_companies_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `platform_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `occurred_at` datetime NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor` varchar(250) NOT NULL DEFAULT '',
  `company_name` varchar(250) DEFAULT NULL,
  `action_code` varchar(64) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `detail_json` text DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_platform_audit_company` (`company_name`,`occurred_at`),
  KEY `idx_platform_audit_time` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Register every company that already has users or branches (all start ACTIVE, self-managed).
INSERT IGNORE INTO `companies` (`company_name`, `display_name`, `status`, `provider_managed`, `created_at`, `created_by`)
SELECT DISTINCT CONVERT(TRIM(`company_name`) USING utf8mb4), CONVERT(TRIM(`company_name`) USING utf8mb4), 'ACTIVE', 0, NOW(), 'backfill'
FROM `users`
WHERE TRIM(`company_name`) <> '' AND TRIM(`company_name`) <> '__platform__';

-- Same for branches (only when branchies has a company_name column — older databases do not).
SET @ps_has_branch_company := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'branchies' AND column_name = 'company_name');
SET @ps_sql := IF(@ps_has_branch_company > 0,
    'INSERT IGNORE INTO `companies` (`company_name`, `display_name`, `status`, `provider_managed`, `created_at`, `created_by`)
     SELECT DISTINCT CONVERT(TRIM(`company_name`) USING utf8mb4), CONVERT(TRIM(`company_name`) USING utf8mb4), ''ACTIVE'', 0, NOW(), ''backfill''
     FROM `branchies` WHERE TRIM(`company_name`) <> '''' AND TRIM(`company_name`) <> ''__platform__''',
    'SELECT ''branchies has no company_name column - skipped'' AS note');
PREPARE ps_stmt FROM @ps_sql;
EXECUTE ps_stmt;
DEALLOCATE PREPARE ps_stmt;

-- -------------------------------------------------------------------------------------
-- FIRST SUPER ADMIN (choose ONE way, then sign in — you land on the Companies page)
--
--  (a) Command line on the server (recommended; asks for the password):
--        php cli_create_super_admin.php --login=pocketsadmin --first=Your --last=Name
--
--  (b) No command line (shared hosting): create a normal user with Create User in any company
--      (e.g. login "pocketsadmin", a strong password), then promote it here.
--      Remove the leading "-- " from the UPDATE line and change the login:
--
-- UPDATE `users` SET `level` = 'SuperAdmin', `company_name` = '__platform__', `branch` = '' WHERE `email` = 'pocketsadmin' LIMIT 1;
--
--  Later Super Admins can be added from the console page "Super Admins".
-- -------------------------------------------------------------------------------------

-- Check: run these two lines on their own afterwards (phpMyAdmin > SQL tab):
--   SHOW TABLES LIKE 'zimra%';
--   SELECT id, company_name, status, provider_managed FROM `companies` ORDER BY company_name;
