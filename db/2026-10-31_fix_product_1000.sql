-- Pockets POS - 2026-10-31: give every product its own number in PERFECT HAIR and STERLING_MUTARE
-- (the old mobile app numbered every new product 1000). Upload the new mobile admin_products.php and
-- _bootstrap.php FIRST, and let phones send their pending sales, or new products keep getting 1000.
-- Each branch keeps ONE product as 1000 (the one with sales, else the oldest); the others get new numbers.
-- Run the statements in order. Safe to run again: once fixed, nothing matches.

-- 1. Backup of the rows that change
CREATE TABLE IF NOT EXISTS goods_backup_20261031_pid1000 AS SELECT * FROM goods WHERE 1 = 0;
INSERT INTO goods_backup_20261031_pid1000 SELECT g.* FROM goods g WHERE TRIM(g.product_id) = '1000' AND g.branch IN ('PERFECT HAIR','STERLING_MUTARE') AND g.id NOT IN (SELECT id FROM (SELECT id FROM goods_backup_20261031_pid1000) b);

-- 2. Which row keeps 1000 in each branch: the one with sales, else the oldest
DROP TEMPORARY TABLE IF EXISTS pid_keep;
CREATE TEMPORARY TABLE pid_keep AS SELECT g.branch, COALESCE((SELECT MIN(g2.id) FROM goods g2 WHERE g2.branch = g.branch AND TRIM(g2.product_id) = '1000' AND EXISTS (SELECT 1 FROM online_sales s WHERE s.branch = g2.branch AND TRIM(s.product_id) = '1000' AND s.product = g2.product)), MIN(g.id)) AS keep_id FROM goods g WHERE TRIM(g.product_id) = '1000' AND g.branch IN ('PERFECT HAIR','STERLING_MUTARE') GROUP BY g.branch;

-- 3. New numbers for all the others, after the highest number in use
SET @n := (SELECT MAX(CAST(product_id AS UNSIGNED)) FROM goods);
UPDATE goods SET product_id = (@n := @n + 1) WHERE TRIM(product_id) = '1000' AND branch IN ('PERFECT HAIR','STERLING_MUTARE') AND id NOT IN (SELECT keep_id FROM pid_keep) ORDER BY branch, id;

-- 4. Product change history follows its row
UPDATE product_audit pa JOIN goods g ON g.id = pa.goods_id SET pa.product_id = g.product_id WHERE TRIM(pa.product_id) = '1000' AND pa.branch IN ('PERFECT HAIR','STERLING_MUTARE') AND g.product_id <> '1000';

-- 5. Check: should return no rows
SELECT branch, TRIM(product_id) AS product_id, COUNT(*) AS n FROM goods GROUP BY branch, TRIM(product_id) HAVING n > 1;
