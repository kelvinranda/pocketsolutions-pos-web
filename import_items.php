<?php
require_once 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/item_import.php';

ini_set('display_errors', '0');
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psSecurityEnforceWebSession($conn, ['redirect' => true, 'login' => 'login.php']);

// Handling file upload to import data
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['import'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!isset($_SESSION['logger']) || trim((string)$_SESSION['logger']) === '') {
        echo "<div class='error'>You must be signed in to import products.</div>";
    } else {
        psImportUploadedSheet($conn);
    }

    }
}

$importBranches = psImportBranchChoices($conn);
$importBranchSelected = psSessionProductBranch();
if ($importBranchSelected !== '' && !in_array($importBranchSelected, $importBranches, true)) {
    $importBranchSelected = $importBranches ? (string)$importBranches[0] : '';
}
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Items</title>
    <script>
        function showColumnInfo() {
            alert("Excel columns for import (use heading names):\n\n" +
                  "Product ID (blank = auto-assign like Create Item; else preserved)\n" +
                  "Product\n" +
                  "Price\n" +
                  "Cost Price\n" +
                  "Category (from Categories & Groups; new names are added)\n" +
                  "Button Category (POS button group; new names are added)\n" +
                  "Pack Size (number, e.g. 500)\n" +
                  "Unit (code or name from Units of Measure, e.g. ml, kg, ea)\n" +
                  "Reorder Level (optional number; blank clears level when column present)\n" +
                  "Status (exported for reference — not applied on import; new rows ACTIVE; existing status unchanged)\n" +
                  "Branch (informational only — never overrides the branch chosen below)\n\n" +
                  "Identity is Product ID + the selected branch. Names are never used to match rows.\n" +
                  "Category and Button Category are separate fields and are never converted into each other.\n" +
                  "Reorder Level is read from the column heading, wherever that heading is.\n\n" +
                  "The target branch is chosen on the import screen.");
        }
    </script>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <div class="container">
        <h1>Import Items</h1>
        <button class="info-button" onclick="showColumnInfo()">View Required Columns</button>
        
        <form method="POST" action="" enctype="multipart/form-data">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <?php psImportBranchSelect($importBranches, $importBranchSelected); ?>
            <label for="excel_file">Excel file:</label>
            <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
            <button type="submit" name="import">Import Products</button>
        </form>
        
        <button class="back-button" onclick="window.location.href='admin_dashboard.php'">Back to Dashboard</button>
        <a class="template-button" href="pos_import_template.xlsx" download="pos_import_template.xlsx">Download Import Template</a>
    </div>
<?php ps_close(); ?>
</body>
</html>