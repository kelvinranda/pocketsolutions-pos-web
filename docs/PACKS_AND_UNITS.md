# Packs and Units of Measure (2026-10-28)

A product keeps one **base stock unit** (bag, tin, egg) and may have **packs** (pack of 10 bags, tray of 6 tins,
crate of 30 eggs). Stock is always kept in base units in the existing ledger (`stock_movements`); packs only change
how quantities are entered, sold and shown. Products without packs or a base unit behave exactly as before.

Code: `includes/product_packs.php` (all rules), admin page `product_packs.php` (menu: Products › Packs & Units).

## 1. Data

| Where | What |
|---|---|
| `product_packs` (new table) | `company_name`, `product_id`, `name`, `factor` DECIMAL(12,3) base units per pack (> 0; whole and ≥ 2 for whole-unit products), `price` DECIMAL(12,2) in the company base currency (NULL = not sold, receive/count only), `barcode` (unique in the company, also against product barcodes), `active`, audit columns. |
| `goods.base_unit` (new column) | Label of one stock unit, e.g. `bag`. NULL/empty = "unit". |
| `online_sales.sell_unit / sell_qty / sell_factor / sell_price / pack_id` (new columns) | Snapshot of how a line was sold. `quantity` stays in **base units**; `unit_price` is the price per base unit (rounded half up, display only); `revenue` is exactly pack price × packs. NULL for every base-unit line and every old sale. |

The columns and table are added on first use by `psPackEnsureSchema()` (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN`
only; nothing is rewritten). No existing row is changed.

Desktop till (SQLite schema v14): `products.base_unit`, table `product_packs`, and `sell_unit, sell_qty_milli,
sell_factor_milli, sell_price_cents, pack_id` on `sale_lines` and `lan_sale_lines`. Added in place on start-up.

### Rollback

1. Restore the previous PHP files (the feature files are listed in the release note).
2. The new table and columns can stay: old code never reads them. Pack sales already made remain correct in stock,
   totals, tax and reports because `quantity` is in base units and `revenue` is the amount paid; only the "sold as
   1 pack" wording is lost.
3. Only if the schema must be removed: `DROP TABLE product_packs; ALTER TABLE goods DROP COLUMN base_unit;
   ALTER TABLE online_sales DROP COLUMN sell_unit, DROP COLUMN sell_qty, DROP COLUMN sell_factor, DROP COLUMN sell_price, DROP COLUMN pack_id;`
   — this loses the pack snapshots of past sales, so back up first.
4. Desktop: an older till build ignores the extra SQLite columns; do not downgrade a till with unsynced pack sales
   (sync first).

## 2. Rules

* Packs are sold and entered in **whole packs**. Products with packs or a base unit (and not sold by measure) are in
  **whole base units**: no half tins. Sell-by-measure items keep 3 decimals.
* Conversion: base = packs × factor (`psPackToBase`). Display: "109 bags = 10 packs + 9 bags", largest pack first
  (`psPackFormatStock`).
* Pack price is its own price (not single × factor), converted to the sale currency with the sale rate like every
  price. Line amount = pack price × packs, rounded to the currency's decimals. Tax is computed on that amount.
* Stock check at sale submission (web `psGuardSaleStock`) adds up base units of all lines of a product, under the
  ledger row lock (`FOR UPDATE`), so two tills cannot sell the last pack twice. The refusal uses no invoice number.
* Refund of a pack line: quantities in base units; amount = revenue × refunded / sold, half up; the whole line
  returns exactly what was paid. The REFUND stock movement adds the base units back.
* ZIMRA: a pack line is fiscalised as "Product (pack of 10)", quantity = packs, price = pack price.
* Changing, re-pricing or hiding a pack never changes past lines (snapshot).

## 3. Where it applies

Web: POS grid / cart / barcode, ticket review, receipt print, my receipt, sales history and invoice detail, refunds,
refund reconciliation, ZIMRA receipts, opening stock, GRV (with and without PO; cost per pack is converted to cost per
base unit), purchase orders, stock adjustment, transfers, stock take (packs + loose), inventory report note.

Desktop: catalogue download (`base_unit`, `packs` in `psDtCatalogue`; part of the catalogue hash), "Sell as" choice,
pack barcodes, stock check in base units, receipts, refunds pro rata, shop-network sharing, ZIMRA lines. The server
re-checks each pack line it receives: quantity = packs × factor, whole packs, total = pack price × packs; a pack
price that differs from the server's is reported (PRICE_DIFF), never changed.

## 4. Tests (isolated databases only)

`t_packs.php` (65 checks: 100 bags, pack sale → 90, single bags, 9 bags left → pack refused / 9 bags sold, ZWG,
refunds, receiving 10 packs = +100, adjustment, transfer between branches, barcodes, hidden packs, history),
`t_race.php` (two tills, last pack), `t_dtpacks.php` (real desktop events into the server), desktop
`PackTests` / `PackScreens` / `PackMigration`.
