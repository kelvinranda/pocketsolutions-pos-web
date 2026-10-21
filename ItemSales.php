<?php
/**
 * Item sales report — admin shell page.
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
// GET DATE
// ============================================================

$date_filter1 = isset($_GET['date'])
    ? $_GET['date']
    : date('Y-m-d');


// Validate date

$dateObject = DateTime::createFromFormat('Y-m-d', $date_filter1);

if (!$dateObject || $dateObject->format('Y-m-d') !== $date_filter1) {
    $date_filter1 = date('Y-m-d');
}


// ============================================================
// SALES PER ITEM
// ============================================================

function sales_per_item($conn, $date_filter1)
{
    try {

        // ----------------------------------------------------
        // TRUNCATE z_reading
        // ----------------------------------------------------

        $truncateSql = "TRUNCATE TABLE `z_reading`";

        if (!mysqli_query($conn, $truncateSql)) {

            throw new Exception(
                "Unable to clear z_reading: " .
                mysqli_error($conn)
            );
        }


        // ----------------------------------------------------
        // FIND DISTINCT PRODUCTS
        // ----------------------------------------------------

        $productSql = "
            SELECT DISTINCT product AS each_product
            FROM `online_sales`
            WHERE product <> ''
            AND product IS NOT NULL
        ";

        $productResult = mysqli_query($conn, $productSql);

        if (!$productResult) {

            throw new Exception(
                "Unable to get products: " .
                mysqli_error($conn)
            );
        }


        // ----------------------------------------------------
        // PREPARE SALES QUERY
        // ----------------------------------------------------

        $salesSql = "
            SELECT COALESCE(SUM(quantity), 0) AS total_quantity
            FROM `online_sales`
            WHERE product = ?
            AND DATE(date_) = ?
        ";

        $salesStmt = mysqli_prepare(
            $conn,
            $salesSql
        );

        if (!$salesStmt) {

            throw new Exception(
                "Sales query prepare error: " .
                mysqli_error($conn)
            );
        }


        // ----------------------------------------------------
        // PREPARE INSERT QUERY
        // ----------------------------------------------------

        $insertSql = "
            INSERT INTO `z_reading`
            (
                cashier,
                total_currency
            )
            VALUES
            (
                ?,
                ?
            )
        ";

        $insertStmt = mysqli_prepare(
            $conn,
            $insertSql
        );

        if (!$insertStmt) {

            throw new Exception(
                "Insert query prepare error: " .
                mysqli_error($conn)
            );
        }


        // ----------------------------------------------------
        // PROCESS EACH PRODUCT
        // ----------------------------------------------------

        while ($productRow = mysqli_fetch_assoc($productResult)) {

            $eachProduct = $productRow['each_product'];


            // ----------------------------------------------
            // GET TOTAL SALES FOR PRODUCT
            // ----------------------------------------------

            mysqli_stmt_bind_param(
                $salesStmt,
                "ss",
                $eachProduct,
                $date_filter1
            );

            mysqli_stmt_execute($salesStmt);

            $salesResult = mysqli_stmt_get_result(
                $salesStmt
            );

            $salesRow = mysqli_fetch_assoc(
                $salesResult
            );

            $totalQuantity = (int)(
                $salesRow['total_quantity'] ?? 0
            );


            // ----------------------------------------------
            // INSERT INTO z_reading
            // ----------------------------------------------

            mysqli_stmt_bind_param(
                $insertStmt,
                "si",
                $eachProduct,
                $totalQuantity
            );

            if (!mysqli_stmt_execute($insertStmt)) {

                throw new Exception(
                    "Insert error for product " .
                    $eachProduct .
                    ": " .
                    mysqli_stmt_error($insertStmt)
                );
            }
        }


        mysqli_stmt_close($salesStmt);
        mysqli_stmt_close($insertStmt);

        mysqli_free_result($productResult);


        return true;

    } catch (Exception $e) {

        die(
            "sales_per_item ERROR: " .
            htmlspecialchars($e->getMessage())
        );
    }
}


// ============================================================
// LOAD Z_READING DATA
// ============================================================

function load_data($conn)
{
    $sql = "
        SELECT
            id,
            cashier,
            total_currency
        FROM `z_reading`
        ORDER BY id ASC
    ";

    $result = mysqli_query(
        $conn,
        $sql
    );

    if (!$result) {

        die(
            "load_data ERROR: " .
            htmlspecialchars(mysqli_error($conn))
        );
    }

    return $result;
}


// ============================================================
// RUN SALES PER ITEM
// ============================================================

sales_per_item(
    $conn,
    $date_filter1
);


// ============================================================
// LOAD DATA
// ============================================================

$result = load_data($conn);


// ============================================================
// STORE RESULTS
// ============================================================

$rows = [];

$totalSold = 0;

while ($row = mysqli_fetch_assoc($result)) {

    $rows[] = $row;

    $totalSold += (float)$row['total_currency'];
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
    <title>Item Sales</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Item Sales'); ?>

<h1>Item Sales</h1>
<p class="ps-page-lead">Quantity sold per product for the selected date.</p>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Choose a sales date and load the report.</p>
    </div>
    <div class="ps-card-content">
        <form method="GET" class="ps-form-inline filter-form">
            <label>
                <span class="ps-label">Sales date</span>
                <input class="ps-input" type="date" name="date" value="<?php echo $h($date_filter1); ?>" required>
            </label>
            <button class="ps-btn ps-btn-default" type="submit">Load sales</button>
        </form>
    </div>
</div>

<div class="ps-kpi-grid" aria-label="Summary">
    <div class="ps-kpi">
        <div class="ps-kpi-label">Products</div>
        <div class="ps-kpi-value"><?php echo (int)count($rows); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Total sold</div>
        <div class="ps-kpi-value"><?php echo $h(number_format($totalSold, 2)); ?></div>
    </div>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Sales by product</h2>
        <p class="ps-card-desc">Date: <?php echo $h($date_filter1); ?></p>
    </div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th class="num">Qty sold</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $product = (string)($row['cashier'] ?? ''); ?>
                        <tr>
                            <td><?php echo $h($row['id'] ?? ''); ?></td>
                            <td title="<?php echo $h($product); ?>"><?php echo $h($product); ?></td>
                            <td class="num"><?php echo $h(number_format((float)($row['total_currency'] ?? 0), 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="ps-muted">No sales found for this date.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <?php if (count($rows) > 0): ?>
                <tfoot>
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="num"><?php echo $h(number_format($totalSold, 2)); ?></td>
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