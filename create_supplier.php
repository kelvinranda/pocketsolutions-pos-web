<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/supplier_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureSupplierMasterSchema($conn);
psEnsureSupplierAuditSchema($conn);

$supplier_code = $supplier_name = $contact_person = $phone = $email = $address = '';
$error = '';
$success = '';
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    $supplier_code = trim((string)($_POST['supplier_code'] ?? ''));
    $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
    $contact_person = trim((string)($_POST['contact_person'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if (!$signedIn) {
        $error = 'You must be signed in to create a supplier.';
    } else {
        $result = psCreateSupplier($conn, [
            'supplier_code' => $supplier_code,
            'supplier_name' => $supplier_name,
            'contact_person' => $contact_person,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
        ]);
        if ($result['ok']) {
            header('Location: suppliers_list.php?created=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The supplier could not be saved.');
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
    <title>Create Supplier</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Create Supplier</h1>
    <p class="hint">Suppliers are global. Branch is recorded on purchasing / GRV transactions, not on the supplier master.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!$signedIn): ?>
        <div class="error">You must be signed in to create a supplier.</div>
    <?php else: ?>
        <form method="POST" action="" class="supplier-form">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <label for="supplier_code">Supplier code</label>
            <input type="text" id="supplier_code" name="supplier_code" required maxlength="64"
                   value="<?php echo htmlspecialchars($supplier_code, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="supplier_name">Supplier name</label>
            <input type="text" id="supplier_name" name="supplier_name" required maxlength="250"
                   value="<?php echo htmlspecialchars($supplier_name, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="contact_person">Contact person</label>
            <input type="text" id="contact_person" name="contact_person" maxlength="250"
                   value="<?php echo htmlspecialchars($contact_person, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" maxlength="64"
                   value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" maxlength="250"
                   value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="address">Address</label>
            <textarea id="address" name="address" maxlength="500"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></textarea>

            <div class="supplier-actions">
                <button type="submit">Save supplier</button>
                <a href="suppliers_list.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
