<?php
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/customer_master.php';

ini_set('display_errors', '0');
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psSecurityEnforceWebSession($conn, ['redirect' => true, 'login' => 'login.php']);

psEnsureCustomerMasterSchema($conn);
psEnsureCustomerAuditSchema($conn);

$customer_type = PS_CUSTOMER_TYPE_INDIVIDUAL;
$first_name = $last_name = $business_name = $phone = $email = $address = $national_id = $notes = '';
$error = '';
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    $customer_type = strtoupper(trim((string)($_POST['customer_type'] ?? PS_CUSTOMER_TYPE_INDIVIDUAL)));
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $business_name = trim((string)($_POST['business_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $national_id = trim((string)($_POST['national_id'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!$signedIn) {
        $error = 'You must be signed in to create a customer.';
    } else {
        $result = psCreateCustomer($conn, [
            'customer_type' => $customer_type,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'business_name' => $business_name,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'national_id' => $national_id,
            'notes' => $notes,
        ]);
        if ($result['ok']) {
            $id = (int)($result['id'] ?? 0);
            header('Location: customer_view.php?id=' . $id . '&created=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The customer could not be saved.');
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
    <title>Create Customer</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Create Customer</h1>
    <p class="hint">
        Customers are global. A stable code (CUS-######) is allocated on save.
        New customers are Active by default.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!$signedIn): ?>
        <div class="error">You must be signed in to create a customer.</div>
    <?php else: ?>
        <form method="POST" action="" class="customer-form">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <label for="customer_type">Customer type</label>
            <select id="customer_type" name="customer_type" required>
                <option value="<?php echo PS_CUSTOMER_TYPE_INDIVIDUAL; ?>"<?php echo $customer_type === PS_CUSTOMER_TYPE_INDIVIDUAL ? ' selected' : ''; ?>>Individual</option>
                <option value="<?php echo PS_CUSTOMER_TYPE_BUSINESS; ?>"<?php echo $customer_type === PS_CUSTOMER_TYPE_BUSINESS ? ' selected' : ''; ?>>Business</option>
            </select>

            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" maxlength="250"
                   value="<?php echo htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" maxlength="250"
                   value="<?php echo htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="business_name">Business name</label>
            <input type="text" id="business_name" name="business_name" maxlength="250"
                   value="<?php echo htmlspecialchars($business_name, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" maxlength="64"
                   value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" maxlength="250"
                   value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="national_id">National ID</label>
            <input type="text" id="national_id" name="national_id" maxlength="64"
                   value="<?php echo htmlspecialchars($national_id, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="address">Address</label>
            <textarea id="address" name="address" maxlength="500"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></textarea>

            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" maxlength="4000"><?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?></textarea>

            <div class="customer-actions">
                <button type="submit">Save customer</button>
                <a href="customers_list.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
