-- =====================================================================================
-- Fix: several products share the same Product ID inside one branch, so the POS treats
-- them as ONE product (pressing + on one item increases all of them).
--
-- Found in the 13 Oct 2026 database: 740 products all have Product ID "100"
--   First Street 665, EASTRIDGE MALL 40, SAKUBVA 38 (SAKUBVA's real item 100 is "Citro juice Box").
-- They were created outside the new web POS (it always gives a new unique number).
--
-- What this does
--   * In each branch, the OLDEST product keeps its ID; every other product with the same ID
--     gets the next free number (after the highest numeric Product ID in goods).
--   * Every change is saved in goods_product_id_fix_20261015 (old -> new), so it can be undone.
--   * Product names, prices, stock quantities and sales history are NOT changed.
--
-- Run: back up first, then phpMyAdmin > SQL tab > paste > Go. Running it again does nothing
-- once there are no duplicates left.
-- Needs MariaDB 10.2+ (window functions). Your server is MariaDB 10.6.
-- =====================================================================================

CREATE TABLE IF NOT EXISTS `goods_product_id_fix_20261015` (
  `goods_id` INT NOT NULL,
  `branch` VARCHAR(250) NOT NULL,
  `product` VARCHAR(250) NOT NULL,
  `old_product_id` VARCHAR(250) NOT NULL,
  `new_product_id` VARCHAR(250) NOT NULL,
  `fixed_at` DATETIME NOT NULL,
  PRIMARY KEY (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @ps_base := (SELECT COALESCE(MAX(CAST(`product_id` AS UNSIGNED)), 0) FROM `goods`);

-- 1) Work out the new numbers (oldest row per branch + ID keeps its ID).
INSERT IGNORE INTO `goods_product_id_fix_20261015` (`goods_id`, `branch`, `product`, `old_product_id`, `new_product_id`, `fixed_at`)
SELECT d.id, d.branch, d.product, d.product_id, CAST(CAST(@ps_base + ROW_NUMBER() OVER (ORDER BY d.id) AS UNSIGNED) AS CHAR), NOW()
FROM (
    SELECT g.id, g.branch, g.product, g.product_id,
           ROW_NUMBER() OVER (PARTITION BY g.branch, TRIM(g.product_id) ORDER BY g.id) AS k
    FROM `goods` g
) d
WHERE d.k > 1;

-- 2) Apply them (only rows not yet changed).
UPDATE `goods` g
JOIN `goods_product_id_fix_20261015` f ON f.goods_id = g.id AND g.product_id = f.old_product_id
SET g.product_id = f.new_product_id;

-- Check (run on its own afterwards; should return no rows):
--   SELECT branch, product_id, COUNT(*) n FROM goods GROUP BY branch, TRIM(product_id) HAVING n > 1;
-- See what changed:
--   SELECT * FROM goods_product_id_fix_20261015 ORDER BY goods_id;
-- Undo (only if needed):
--   UPDATE goods g JOIN goods_product_id_fix_20261015 f ON f.goods_id = g.id SET g.product_id = f.old_product_id;
