-- =====================================================================================
-- Pockets POS — 2026-10-28 live deploy: Packs & Units (MariaDB 10.3+)
-- Safe to run more than once. Only ADDS a table and empty (NULL) columns:
--   no existing row is changed, sale_invoice_seq and invoice numbers are not touched.
-- PHP also applies this on first use (includes/product_packs.php psPackEnsureSchema);
-- running it first avoids the ALTERs happening during trading hours.
-- The role-change feature (users.php / platform_company.php) needs no schema change.
-- Rollback: see docs/PACKS_AND_UNITS.md §1.
-- =====================================================================================

SET NAMES utf8mb4;

-- 1. Packs of a product (price in the company base currency; NULL = receive / count only)
CREATE TABLE IF NOT EXISTS product_packs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_name VARCHAR(250) NOT NULL,
    product_id VARCHAR(250) NOT NULL,
    name VARCHAR(40) NOT NULL,
    factor DECIMAL(12,3) NOT NULL,
    price DECIMAL(12,2) NULL DEFAULT NULL,
    barcode VARCHAR(64) NULL DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    created_by VARCHAR(250) NOT NULL DEFAULT '',
    updated_at DATETIME NULL,
    updated_by VARCHAR(250) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_packs_name (company_name, product_id, name),
    KEY idx_product_packs_product (company_name, product_id, active),
    KEY idx_product_packs_barcode (company_name, barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. A product's single (base) unit name, e.g. 'packet'. NULL = unchanged behaviour.
ALTER TABLE goods
  ADD COLUMN IF NOT EXISTS base_unit VARCHAR(40) NULL DEFAULT NULL;

-- 3. How each sale line was sold (snapshot). NULL on every existing and every single-unit line.
ALTER TABLE online_sales
  ADD COLUMN IF NOT EXISTS sell_unit VARCHAR(40) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS sell_qty DECIMAL(12,3) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS sell_factor DECIMAL(12,3) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS sell_price DECIMAL(12,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS pack_id INT UNSIGNED NULL DEFAULT NULL;

-- Check: should return 1, 1, 5
SELECT COUNT(*) AS product_packs_table FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'product_packs';
SELECT COUNT(*) AS goods_base_unit FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'goods' AND column_name = 'base_unit';
SELECT COUNT(*) AS online_sales_pack_columns FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'online_sales'
  AND column_name IN ('sell_unit', 'sell_qty', 'sell_factor', 'sell_price', 'pack_id');
