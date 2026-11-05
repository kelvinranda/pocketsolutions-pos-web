<?php
/**
 * Legacy customers UI against table `customers` (fullname / contact / provisional_number / …).
 * Phase 5.1+ authoritative master is customer_master — use customers_list.php.
 * This page is retained so legacy data remains reachable; it is not the customer master.
 * Phase 5.4: session required for writes; no raw SQL in UI; no hard-delete; does not
 * write customer_master_id. Do not delete the existing legacy rows.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
// 2026-11-02: the legacy `customers` table has no company, so it showed every company's customers (even
// signed out). Retired: everyone goes to the company's own customer list. Legacy rows stay in the database.
header('Location: customers_list.php');
exit;
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
mysqli_report(MYSQLI_REPORT_OFF);

// Initialize variables
$customers = [];
$fullname = $contact = $address = "";
$search_term = "";
$error = "";
$success = "";
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die('Connection failed.');
}

// Address is optional in this schema. Save and select it only when a real column exists.
$addressColumn = null;
$columnResult = mysqli_query($conn, 'SHOW COLUMNS FROM customers');
if ($columnResult) {
    $addressNames = ['address', 'customer_address', 'cust_address', 'customeraddress'];
    while ($column = mysqli_fetch_assoc($columnResult)) {
        if (in_array(strtolower($column['Field']), $addressNames, true)) {
            $addressColumn = $column['Field'];
            break;
        }
    }
}

// Fetch existing customers. SELECT * already includes an address column when one exists.
$sql = "SELECT * FROM customers";
if (!empty($_POST['search_term'])) {
    $search_term = trim((string)$_POST['search_term']);
    $searchEsc = mysqli_real_escape_string($conn, $search_term);
    $sql .= " WHERE fullname LIKE '%$searchEsc%' OR contact LIKE '%$searchEsc%'";
}

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $customers[] = $row;
    }
} else {
    error_log('legacy customers list query failed');
    $error = 'Legacy customers could not be loaded.';
}

// Handle form submission for new customer (legacy table only — not customer_master).
// Health-check 2026-10-13: the legacy table is read-only now — new customers go to Customers.
if (false && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_customer'])) {
    if (!function_exists('psCsrfValidate')) {
        require_once __DIR__ . '/includes/security.php';
    }
    if (!$signedIn) {
        $error = 'You must be signed in to add a legacy customer.';
    } elseif (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $fullname = trim((string)($_POST['fullname'] ?? ''));
        $contact = trim((string)($_POST['contact'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $fullnameEsc = mysqli_real_escape_string($conn, $fullname);
        $contactEsc = mysqli_real_escape_string($conn, $contact);
        $addressEsc = mysqli_real_escape_string($conn, $address);

        // Validate required fields
        if ($fullname === '' || $contact === '' || $address === '') {
            $error = 'All fields are required.';
        } else {
            // Insert new customer. Persist address only when that column exists.
            if ($addressColumn !== null) {
                $safeAddressColumn = str_replace('`', '``', $addressColumn);
                $insert_sql = "INSERT INTO customers (fullname, contact, `$safeAddressColumn`, date_created) VALUES ('$fullnameEsc', '$contactEsc', '$addressEsc', NOW())";
            } else {
                $insert_sql = "INSERT INTO customers (fullname, contact, date_created) VALUES ('$fullnameEsc', '$contactEsc', NOW())";
            }

            if (mysqli_query($conn, $insert_sql)) {
                $success = 'Legacy customer added. Prefer the customer master (Customers list) for POS identity.';
                $customers[] = [
                    'id' => mysqli_insert_id($conn),
                    'fullname' => $fullname,
                    'contact' => $contact,
                    'address' => $address,
                    'date_created' => date('Y-m-d H:i:s'),
                ];
                $fullname = $contact = $address = '';
            } else {
                error_log('legacy customers insert failed');
                $error = 'The legacy customer could not be saved.';
            }
        }
    }
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <!-- Legacy in-page menu removed 2026-10-13: the shared sidebar (ps_open) is the only menu. -->

    <div class="content">
        <h1>Legacy Customers</h1>
        <p class="hint">
            Customers recorded before the current customer system.
        </p>

        <p class="hint">To add or edit a customer, use <a href="customers_list.php">Customers</a>. This old list is kept read-only for reference.</p>

        <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <input type="text" name="search_term" placeholder="Search by Name or Contact" value="<?php echo htmlspecialchars($search_term); ?>">
            <button type="submit">Search</button>
        </form>

        <div class="error"><?php if (!empty($error)) echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="success"><?php if (!empty($success)) echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>

        <h2>List of Customers</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Contact</th>
                    <th>Address</th>
                    <th>Date Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($customers)): ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($customer['id'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($customer['fullname'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($customer['contact'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($customer['address'] ?? $customer['customer_address'] ?? $customer['cust_address'] ?? $customer['customeraddress'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string) ($customer['date_created'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No customers found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <button class="back-button" onclick="window.location.href='admin_dashboard.php'">Back to Dashboard</button>
    </div>
<?php ps_close(); ?>
</body>
</html>