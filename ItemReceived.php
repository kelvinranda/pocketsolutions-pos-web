<?php
/**
 * Items received / GRV report — admin shell page.
 * Session/auth must run before any HTML so ps_open() sees admin level.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/ps_layout.php';

// ============================================================
// DATABASE CONNECTION
// ============================================================

$conn = mysqli_connect(
    $servername,
    $username,
    $password,
    $dbname
);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
psBranchRequireAdminRedirect();


// ============================================================
// GET SELECTED DATES
// ============================================================

$date_filter1 = isset($_GET['date_from'])
    ? $_GET['date_from']
    : date('Y-m-d');

$date_filter2 = isset($_GET['date_to'])
    ? $_GET['date_to']
    : date('Y-m-d');


// Validate dates

if (!DateTime::createFromFormat('Y-m-d', $date_filter1)) {
    $date_filter1 = date('Y-m-d');
}

if (!DateTime::createFromFormat('Y-m-d', $date_filter2)) {
    $date_filter2 = date('Y-m-d');
}


// ============================================================
// GRV PER ITEM
// ============================================================

function GRV_per_item($conn, $date_filter1, $date_filter2)
{
    try {

        // ----------------------------------------------------
        // TRUNCATE GRV REPORT
        // ----------------------------------------------------

        $truncateSql = "TRUNCATE TABLE `grv_report`";

        if (!mysqli_query($conn, $truncateSql)) {
            throw new Exception(
                "Unable to clear grv_report: " . mysqli_error($conn)
            );
        }


        // ----------------------------------------------------
        // FIND DISTINCT PRODUCTS
        // ----------------------------------------------------

        $productSql = "
            SELECT DISTINCT product AS each_product
            FROM `goods_receipts`
            WHERE product <> ''
            AND product IS NOT NULL
        ";

        $productResult = mysqli_query($conn, $productSql);

        if (!$productResult) {
            throw new Exception(
                "Unable to get products: " . mysqli_error($conn)
            );
        }


        // ----------------------------------------------------
        // LOOP THROUGH PRODUCTS
        // ----------------------------------------------------

        while ($productRow = mysqli_fetch_assoc($productResult)) {

            $eachProduct = $productRow['each_product'];


            // ------------------------------------------------
            // COUNT GRV QUANTITY FOR THIS PRODUCT
            // ------------------------------------------------

            /*
             * Your original VB.NET code used:
             *
             * DATE(date_) = date_filter1
             *
             * This PHP version supports the selected
             * date range.
             */

            $quantitySql = "
                SELECT COALESCE(SUM(quantity), 0) AS total_quantity
                FROM `goods_receipts`
                WHERE product = ?
                AND DATE(date_) BETWEEN ? AND ?
            ";

            $stmt = mysqli_prepare($conn, $quantitySql);

            if (!$stmt) {
                throw new Exception(
                    "Prepare error: " . mysqli_error($conn)
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                "sss",
                $eachProduct,
                $date_filter1,
                $date_filter2
            );

            mysqli_stmt_execute($stmt);

            $quantityResult = mysqli_stmt_get_result($stmt);

            $quantityRow = mysqli_fetch_assoc($quantityResult);

            $totalQuantity = $quantityRow['total_quantity'] ?? 0;

            mysqli_stmt_close($stmt);


            // ------------------------------------------------
            // INSERT INTO GRV REPORT
            // ------------------------------------------------

            /*
             * Original VB.NET:
             *
             * INSERT INTO grv_report
             * (product,qty,grv_value)
             * VALUES (...)
             *
             * We use a prepared statement here to avoid
             * SQL injection and quotation problems.
             */

            $insertSql = "
                INSERT INTO `grv_report`
                (
                    product,
                    qty,
                    grv_value
                )
                VALUES
                (
                    ?,
                    ?,
                    ?
                )
            ";

            $insertStmt = mysqli_prepare($conn, $insertSql);

            if (!$insertStmt) {
                throw new Exception(
                    "Insert prepare error: " . mysqli_error($conn)
                );
            }

            /*
             * Your original program puts the same quantity
             * into qty and grv_value.
             */

            $grvValue = $totalQuantity;

            mysqli_stmt_bind_param(
                $insertStmt,
                "sii",
                $eachProduct,
                $totalQuantity,
                $grvValue
            );

            if (!mysqli_stmt_execute($insertStmt)) {
                throw new Exception(
                    "Insert error: " . mysqli_stmt_error($insertStmt)
                );
            }

            mysqli_stmt_close($insertStmt);
        }


        mysqli_free_result($productResult);

        return true;

    } catch (Exception $e) {

        die(
            "GRV_per_item ERROR: " .
            htmlspecialchars($e->getMessage())
        );
    }
}


// ============================================================
// LOAD GRV DATA
// ============================================================

function load_dataGRV($conn)
{
    $sql = "
        SELECT
            id,
            product,
            qty,
            grv_value
        FROM `grv_report`
        ORDER BY id ASC
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        die(
            "load_dataGRV ERROR: " .
            htmlspecialchars(mysqli_error($conn))
        );
    }

    return $result;
}


// ============================================================
// RUN GRV PER ITEM
// ============================================================

GRV_per_item(
    $conn,
    $date_filter1,
    $date_filter2
);


// ============================================================
// LOAD THE DATA
// ============================================================

$result = load_dataGRV($conn);


// ============================================================
// CALCULATE TOTALS
// ============================================================

$totalQty = 0;
$totalValue = 0;

$rows = [];

while ($row = mysqli_fetch_assoc($result)) {

    $rows[] = $row;

    $totalQty += (float)$row['qty'];

    $totalValue += (float)$row['grv_value'];
}

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items Received</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Items Received'); ?>

<h1>Items Received</h1>
<p class="ps-page-lead">Goods received (GRV) quantities and values for the selected date range.</p>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Choose a date range and load the GRV report.</p>
    </div>
    <div class="ps-card-content">
        <form method="GET" class="ps-form-inline filter-form">
            <label>
                <span class="ps-label">Date from</span>
                <input class="ps-input" type="date" name="date_from" value="<?php echo $h($date_filter1); ?>" required>
            </label>
            <label>
                <span class="ps-label">Date to</span>
                <input class="ps-input" type="date" name="date_to" value="<?php echo $h($date_filter2); ?>" required>
            </label>
            <button class="ps-btn ps-btn-default" type="submit">Load report</button>
        </form>
    </div>
</div>

<div class="ps-kpi-grid" aria-label="Summary">
    <div class="ps-kpi">
        <div class="ps-kpi-label">Products</div>
        <div class="ps-kpi-value"><?php echo (int)count($rows); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Total qty</div>
        <div class="ps-kpi-value"><?php echo $h(number_format($totalQty, 2)); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Total value</div>
        <div class="ps-kpi-value"><?php echo $h(number_format($totalValue, 2)); ?></div>
    </div>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">GRV by product</h2>
        <p class="ps-card-desc"><?php echo $h($date_filter1); ?> → <?php echo $h($date_filter2); ?></p>
    </div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th class="num">Qty</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $product = (string)($row['product'] ?? ''); ?>
                        <tr>
                            <td><?php echo $h($row['id'] ?? ''); ?></td>
                            <td title="<?php echo $h($product); ?>"><?php echo $h($product); ?></td>
                            <td class="num"><?php echo $h(number_format((float)($row['qty'] ?? 0), 2)); ?></td>
                            <td class="num"><?php echo $h(number_format((float)($row['grv_value'] ?? 0), 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="ps-muted">No GRV records found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <?php if (count($rows) > 0): ?>
                <tfoot>
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="num"><?php echo $h(number_format($totalQty, 2)); ?></td>
                        <td class="num"><?php echo $h(number_format($totalValue, 2)); ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php ps_close(); ?>
</body>
</html>
<?php
mysqli_close($conn);
?>