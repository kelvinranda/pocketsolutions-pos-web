-- =====================================================================================
-- Pockets POS — Phase 19: managed Categories / Button groups (per company) and Units of Measure (shared)
-- Prepared 2026-10-16. MariaDB 10.3+. Safe to run more than once. Nothing is dropped or changed
-- in existing tables; goods keeps storing category / button_category names and the unit code.
-- The POS also creates these tables on first use; running this file up front is optional.
-- =====================================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(250) NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'category',
  `name` varchar(100) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(250) NOT NULL DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_categories` (`company_name`,`kind`,`name`),
  KEY `idx_product_categories_company` (`company_name`,`kind`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `units_of_measure` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(16) NOT NULL,
  `name` varchar(60) NOT NULL,
  `dimension` varchar(16) NOT NULL DEFAULT 'count',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_units_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Starting units (Super Admins can add more on "Units of Measure").
INSERT IGNORE INTO `units_of_measure` (`code`, `name`, `dimension`, `sort_order`) VALUES
('ea','Each (single item)','count',10),('pack','Pack','count',20),('box','Box','count',30),('ctn','Carton','count',40),
('dozen','Dozen','count',50),('pair','Pair','count',60),('set','Set','count',70),('bottle','Bottle','count',80),
('can','Can','count',90),('bag','Bag','count',100),('roll','Roll','count',110),
('mg','Milligrams','weight',120),('g','Grams','weight',130),('kg','Kilograms','weight',140),('t','Tonnes','weight',150),
('ml','Millilitres','volume',160),('l','Litres','volume',170),('gal','Gallons','volume',180),
('mm','Millimetres','length',190),('cm','Centimetres','length',200),('m','Metres','length',210);

-- Each company's lists start with the names its products already use.
INSERT IGNORE INTO `product_categories` (`company_name`, `kind`, `name`, `created_by`)
SELECT DISTINCT `company_name`, 'category', LEFT(TRIM(`category`), 100), 'existing products'
FROM `goods` WHERE TRIM(`category`) <> '' AND TRIM(`company_name`) <> '';

INSERT IGNORE INTO `product_categories` (`company_name`, `kind`, `name`, `created_by`)
SELECT DISTINCT `company_name`, 'button', LEFT(TRIM(`button_category`), 100), 'existing products'
FROM `goods` WHERE TRIM(`button_category`) <> '' AND TRIM(`company_name`) <> '';

-- Check afterwards (run on their own):
--   SELECT company_name, kind, COUNT(*) FROM product_categories GROUP BY company_name, kind;
--   SELECT code, name FROM units_of_measure ORDER BY sort_order;
