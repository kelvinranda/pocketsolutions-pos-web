-- =====================================================================================
-- Pockets POS — Phase 20: button colours, product variants, sell by weight / volume
-- Run AFTER 2026-10-16_phase19_catalogue_lists.sql. MariaDB 10.3+. Safe to run more than once.
-- Adds columns only (existing rows get the defaults shown). The POS also adds them on first use.
-- =====================================================================================

SET NAMES utf8mb4;

-- Till button colour for a Button group ('#rrggbb'; NULL = automatic).
ALTER TABLE `product_categories` ADD COLUMN IF NOT EXISTS `color` VARCHAR(7) NULL DEFAULT NULL;

-- 1 = the cashier types the amount (e.g. 1.25 kg); price is per 1 unit.
ALTER TABLE `goods` ADD COLUMN IF NOT EXISTS `sell_by_measure` TINYINT(1) NOT NULL DEFAULT 0;

-- Variants: products created together share variant_group; the till shows one card per group.
ALTER TABLE `goods` ADD COLUMN IF NOT EXISTS `variant_group` VARCHAR(40) NULL DEFAULT NULL;
ALTER TABLE `goods` ADD COLUMN IF NOT EXISTS `variant_base` VARCHAR(250) NULL DEFAULT NULL;
ALTER TABLE `goods` ADD COLUMN IF NOT EXISTS `variant_label` VARCHAR(120) NULL DEFAULT NULL;
