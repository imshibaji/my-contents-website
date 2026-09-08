<?php
// public/api/payu/response.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// PayU থেকে আসা POST ডেটা রিসিভ করা
$status      = $_POST['status'] ?? '';
$firstname   = $_POST['firstname'] ?? '';
$amount      = $_POST['amount'] ?? '0.00';
$txnid       = $_POST['txnid'] ?? '';
$postedHash  = $_POST['hash'] ?? '';
$key         = $_POST['key'] ?? '';
$productinfo = $_POST['productinfo'] ?? '';
$email       = $_POST['email'] ?? '';
$phone       = $_POST['phone'] ?? '';
$udf1        = $_POST['udf1'] ?? ''; // course_slug
$udf2        = $_POST['udf2'] ?? 'full'; // plan
$errorMsg    = $_POST['error_Message'] ?? $_POST['unmappedstatus'] ?? 'Transaction was declined or cancelled.';

// রিভার্স হ্যাশ ভ্যালিডেশন
// Formula: sha512(SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
$reverseSequence = PAYU_MERCHANT_SALT . '|' . $status . '||||||' . $udf2 . '|' . $udf1 . '|' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amount . '|' . $txnid . '|' . $key;
$calculatedHash = strtolower(hash('sha512', $reverseSequence));

$isValid = ($calculatedHash === strtolower($postedHash));

if ($status === 'success' && $isValid) {
    // ১. সফল পেমেন্ট ইমেইল
    $adminSub = "✅ [Payment Verified] ₹{$amount} - {$firstname}";
    $adminBody = "
        <div style='font-family: monospace; background:#070e1c; color:#fff; padding:20px; border-radius:10px;'>
            <h2 style='color:#10b981;'>Payment Verified Successfully</h2>
            <p><strong>Txn ID:</strong> {$txnid}</p>
            <p><strong>Candidate:</strong> {$firstname} ({$email})</p>
            <p><strong>WhatsApp:</strong> {$phone}</p>
            <p><strong>Course:</strong> {$udf1}</p>
            <p><strong>Amount:</strong> ₹{$amount}</p>
        </div>
    ";
    dispatchNotificationMail(ADMIN_EMAIL, $adminSub, $adminBody);

    // সাকসেস পেজে রিডাইরেক্ট
    $redirectUrl = SITE_URL . '/courses/payment-success?' . http_build_query([
        'txnid'  => $txnid,
        'amount' => $amount,
        'course' => $udf1,
        'status' => 'confirmed'
    ]);
    header("Location: " . $redirectUrl);
    exit;
} else {
    // ২. ব্যর্থ বা ক্যানসেলড পেমেন্ট টেলিমেট্রি ইমেইল
    $failSub = "⚠️ [Payment Failed/Cancelled] ₹{$amount} - {$firstname}";
    $failBody = "
        <div style='font-family: monospace; background:#1c0d0d; color:#fff; padding:20px; border-radius:10px;'>
            <h2 style='color:#f43f5e;'>Transaction Interrupted / Failed</h2>
            <p><strong>Txn ID:</strong> {$txnid}</p>
            <p><strong>Candidate:</strong> {$firstname} ({$email})</p>
            <p><strong>Course:</strong> {$udf1}</p>
            <p><strong>Reason:</strong> " . htmlspecialchars($errorMsg) . "</p>
        </div>
    ";
    dispatchNotificationMail(ADMIN_EMAIL, $failSub, $failBody);

    // 👈 এখন ব্যর্থ হলে /courses/payment-failed/ ইউআরএলে যাবে
    $failUrl = SITE_URL . '/courses/payment-failed?' . http_build_query([
        'txnid'  => $txnid,
        'course' => $udf1,
        'plan'   => $udf2,
        'amount' => $amount,
        'reason' => $errorMsg
    ]);
    header("Location: " . $failUrl);
    exit;
}