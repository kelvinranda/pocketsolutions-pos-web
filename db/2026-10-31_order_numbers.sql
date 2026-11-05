-- =====================================================================================
-- Pockets POS — 2026-10-31 live deploy: restaurant order numbers (MariaDB 10.3+)
-- Safe to run more than once. Only ADDS empty columns and one new table: no existing row changes,
-- invoice numbers and ZIMRA numbers are not touched. Order numbers stay OFF until an admin turns them on
-- (web: Receipts page → "Restaurant order numbers").
-- PHP also adds these on first use; running this first avoids the ALTERs during trading hours.
-- =====================================================================================

SET NAMES utf8mb4;

-- 1. The order number printed on each sale (W01-001 web, T01-001 Windows till, M01-001 phone). NULL = none.
ALTER TABLE online_sales_ref
  ADD COLUMN IF NOT EXISTS order_no VARCHAR(24) NULL DEFAULT NULL;

-- 2. Web till sessions: the session's prefix (W01 …) and its last order number (starts at 0 = next is 001).
ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS order_seq INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS order_prefix VARCHAR(12) NULL DEFAULT NULL;

-- 3. Phones: each phone's own prefix per company (M01, M02 …), kept for good.
CREATE TABLE IF NOT EXISTS mobile_order_devices (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(250) NOT NULL,
    device_uuid VARCHAR(64) NOT NULL,
    prefix VARCHAR(12) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_mod_device (company_name, device_uuid),
    UNIQUE KEY uq_mod_prefix (company_name, prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Check: should return 1, 2, 1
SELECT COUNT(*) AS sales_order_no FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'online_sales_ref' AND column_name = 'order_no';
SELECT COUNT(*) AS session_columns FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name IN ('order_seq', 'order_prefix');
SELECT COUNT(*) AS phone_table FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mobile_order_devices';
