<?php
// api/payu/response.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/courses/payment-failed?reason=invalid_method');
    exit;
}

$status = $_POST['status'] ?? '';
$txnid = $_POST['txnid'] ?? '';
$amount = $_POST['amount'] ?? '';
$productinfo = $_POST['productinfo'] ?? '';
$firstname = $_POST['firstname'] ?? '';
$email = $_POST['email'] ?? '';
$postedHash = $_POST['hash'] ?? '';
$key = $_POST['key'] ?? '';
$courseSlug = $_POST['udf1'] ?? '';
$additionalCharges = $_POST['additionalCharges'] ?? '';

// Reverse Hash Verification
if (!empty($additionalCharges)) {
    $seq = $additionalCharges . '|' . PAYU_MERCHANT_SALT . '|' . $status . '||||||||||' . $courseSlug . '|' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amount . '|' . $txnid . '|' . $key;
} else {
    $seq = PAYU_MERCHANT_SALT . '|' . $status . '||||||||||' . $courseSlug . '|' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amount . '|' . $txnid . '|' . $key;
}

$calcHash = strtolower(hash('sha512', $seq));

if ($calcHash !== strtolower($postedHash)) {
    header('Location: ' . SITE_URL . '/courses/payment-failed?txnid=' . urlencode($txnid) . '&reason=hash_mismatch');
    exit;
}

if ($status === 'success') {
    $sub = "🎉 [Payment Confirmed] ₹$amount - " . htmlspecialchars($firstname);
    $body = "<p>Payment of ₹$amount confirmed for $productinfo by $firstname ($email). Txn: $txnid</p>";
    @dispatchNotificationMail(ADMIN_EMAIL, $sub, $body);

    header('Location: ' . SITE_URL . '/courses/payment-success?txnid=' . urlencode($txnid) . '&course=' . urlencode($courseSlug) . '&amount=' . urlencode($amount));
    exit;
} else {
    header('Location: ' . SITE_URL . '/courses/payment-failed?txnid=' . urlencode($txnid) . '&reason=' . urlencode($status));
    exit;
}