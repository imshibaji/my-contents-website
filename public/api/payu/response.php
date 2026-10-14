<?php
// public/api/payu/response.php
declare(strict_types=1);

// config.php না থাকলে require_once fatal error দেয়, ফলে খালি 500 body আসে এবং
// কোনো redirect হয় না — কাস্টমার browser-এ খালি সাদা পেজ দেখায়। তাই ফাইলটি
// আগে পরীক্ষা করে নিজে থেকেই redirect করে দেওয়া হচ্ছে।
$payuConfigPath = __DIR__ . '/config.php';
if (!is_file($payuConfigPath)) {
    error_log('[PayU] Missing required file: ' . $payuConfigPath);
    $fallbackCourse = isset($_POST['udf1']) ? (string)$_POST['udf1'] : '';
    $fallbackPlan   = isset($_POST['udf2']) ? (string)$_POST['udf2'] : 'full';
    $fallbackAmount = isset($_POST['amount']) ? (string)$_POST['amount'] : '0';
    $fallbackTxn    = isset($_POST['txnid']) ? (string)$_POST['txnid'] : '';

    header('Location: ' . (defined('SITE_URL') ? SITE_URL : 'https://shibajidebnath.com')
        . '/courses/payment-failed?txnid=' . urlencode($fallbackTxn)
        . '&course=' . urlencode($fallbackCourse)
        . '&plan=' . urlencode($fallbackPlan)
        . '&amount=' . urlencode($fallbackAmount)
        . '&reason=' . urlencode('Payment gateway unavailable'), true, 302);
    exit;
}
require_once $payuConfigPath;

// ── GET মোড: টোকেন যাচাই ────────────────────────────────────────────────
// সাকসেস পেজ ফেচ করে এখানে আসে। সঠিক টোকেন ছাড়া কিছু প্রকাশ করা হয় না,
// শুধু verified:false — তাই ভুল URL বানিয়ে কিছু দেখানো যায় না।
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $vTxnid  = (string)($_GET['txnid'] ?? '');
    $vToken  = (string)($_GET['token'] ?? '');
    $vAmount = (string)($_GET['amount'] ?? '');
    $vCourse = (string)($_GET['course'] ?? '');

    $expected = ($vTxnid !== '' && $vAmount !== '' && $vCourse !== '')
        ? enrollmentToken($vTxnid, $vAmount, $vCourse)
        : '';

    // hash_equal সময়-আধারিত তুলনা করে, তাই ভুল অনুমান থেকে রক্ষা করে।
    $ok = $expected !== ''
        && $vToken !== ''
        && hash_equals($expected, $vToken);

    echo json_encode([
        'verified' => $ok,
        'txnid'    => $ok ? $vTxnid : null,
        'amount'   => $ok ? $vAmount : null,
        'course'   => $ok ? $vCourse : null,
    ]);
    exit;
}

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
$udf3        = $_POST['udf3'] ?? '';
$udf4        = $_POST['udf4'] ?? '';
$udf5        = $_POST['udf5'] ?? '';
$errorMsg    = $_POST['error_Message'] ?? $_POST['unmappedstatus'] ?? 'Transaction was declined or cancelled.';

// রিভার্স হ্যাশ ভ্যালিডেশন
// Formula: sha512(SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
$reverseSequence = PAYU_MERCHANT_SALT . '|' . $status . '||||||' . $udf5 . '|' . $udf4 . '|' . $udf3 . '|' . $udf2 . '|' . $udf1 . '|' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amount . '|' . $txnid . '|' . $key;
$calculatedHash = strtolower(hash('sha512', $reverseSequence));

$isValid = ($calculatedHash === strtolower($postedHash));

if ($status === 'success' && $isValid) {
    // ১. সফল পেমেন্ট ইমেইল
    // custom payment-এ udf1 স্লাগ; রসিদে আসল purpose (udf3) দেখানো হয়।
    $displayTitle = $udf3 !== '' ? $udf3 : $udf1;
    $adminSub = "✅ [Payment Verified] ₹{$amount} - {$firstname}";
    $adminBody = "
        <div style='font-family: monospace; background:#070e1c; color:#fff; padding:20px; border-radius:10px;'>
            <h2 style='color:#10b981;'>Payment Verified Successfully</h2>
            <p><strong>Txn ID:</strong> {$txnid}</p>
            <p><strong>Candidate:</strong> {$firstname} ({$email})</p>
            <p><strong>WhatsApp:</strong> {$phone}</p>
            <p><strong>Course:</strong> " . htmlspecialchars($displayTitle) . "</p>
            <p><strong>Amount:</strong> ₹{$amount}</p>
        </div>
    ";
    dispatchNotificationMail(ADMIN_EMAIL, $adminSub, $adminBody);

    // স্টুডেন্টকে পেমেন্ট নিশ্চিতকরণ রসিদ (v2-response.php-র সাথে সমরূপ)।
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $studentSub = "Payment Confirmed: " . htmlspecialchars($displayTitle) . " (₹{$amount})";
        $studentBody = "
            <div style='font-family: sans-serif; background:#ffffff; color:#1e293b; padding:28px; border:1px solid #e2e8f0; border-radius:12px;'>
                <h2 style='color:#0284c7; margin-top:0;'>Payment Received — Thank You!</h2>
                <p>Hi " . htmlspecialchars($firstname) . ",</p>
                <p>We have received and verified your payment. Your seat is confirmed.</p>
                <table style='border-collapse:collapse; margin:16px 0;'>
                    <tr><td style='padding:4px 12px 4px 0; color:#64748b;'>Program</td><td style='padding:4px 0;'><strong>" . htmlspecialchars($displayTitle) . "</strong></td></tr>
                    <tr><td style='padding:4px 12px 4px 0; color:#64748b;'>Amount Paid</td><td style='padding:4px 0;'><strong>₹{$amount}</strong></td></tr>
                    <tr><td style='padding:4px 12px 4px 0; color:#64748b;'>Transaction ID</td><td style='padding:4px 0;'><code>{$txnid}</code></td></tr>
                </table>
                <p>Your admission invoice and sandbox access details will follow shortly.</p>
                <p style='margin-bottom:0;'>Best regards,<br><strong>Shibaji Debnath</strong><br>CTO | Senior System Architect</p>
            </div>
        ";
        dispatchNotificationMail($email, $studentSub, $studentBody);
    }

    // সাকসেস পেজে রিডাইরেক্ট। টোকেন যোগ করা হচ্ছে যাতে পেজটি প্রকৃত
    // যাচাইকৃত তথ্য দেখায়, URL থেকে আসা amount/course বিশ্বাস না করে।
    $successQuery = [
        'txnid'  => $txnid,
        'amount' => $amount,
        'course' => $udf1,
        'status' => 'confirmed',
        'token'  => enrollmentToken($txnid, $amount, $udf1),
    ];

    // custom payment-এ udf1 স্লাগ ("custom-payment"); আসল purpose আসে udf3-তে।
    $customTitle = trim((string)$udf3);
    if ($customTitle !== '') {
        $successQuery['title'] = $customTitle;
    }

    $redirectUrl = SITE_URL . '/courses/payment-success?' . http_build_query($successQuery);
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