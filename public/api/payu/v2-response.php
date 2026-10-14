<?php
// public/api/payu/v2-response.php
//
// Checkout v2 non-seamless রিটার্ন হ্যান্ডলার।
//
// পার্থক্য legacy response.php থেকে:
//   • legacy তে PayU নিজেই POST করে status + reverse-hash পাঠায় → সেটা যাচাই করা যায়।
//   • v2-তে এমন কিছু আসে না। callBackActions-এ ক্রেতা ফিরে আসে শুধু txnid নিয়ে।
//     তাই টাকা আদৌ গেছে কি না জানতে verify_payment API আলাদা করে কল করতে হয়।
//     এই ফাইলটা ঠিক সেটাই করে, তারপর সাকসেস/ফেইল static পেজে টোকেন সহ পাঠায়।
declare(strict_types=1);

// config.php না থাকলে require_once fatal error দেয় → খালি 500, কোনো redirect
// হয় না, ক্রেতা সাদা পেজ দেখে। তাই আগেই পরীক্ষা করে নিজে redirect করা হচ্ছে।
$payuConfigPath = __DIR__ . '/config.php';
if (!is_file($payuConfigPath)) {
    error_log('[PayU v2] Missing required file: ' . $payuConfigPath);
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status'  => 'error',
        'code'    => 'payu_config_missing',
        'message' => 'Payment gateway is not installed on the server. Please contact support.',
    ]);
    exit;
}
require_once $payuConfigPath;

/**
 * ব্যর্থ/অনিশ্চিত পেমেন্টে ক্রেতাকে payment-failed পেজে পাঠানো হয়।
 * পরিমাণ ও কোর্স জানা থাকলে সেটাও পাঠানো হয়, নইলে খালি।
 */
function redirectToFailure(string $txnid, string $reason, string $course = '', string $plan = 'full', string $amount = '0'): void
{
    $url = SITE_URL . '/courses/payment-failed?' . http_build_query([
        'txnid'  => $txnid,
        'course' => $course,
        'plan'   => $plan,
        'amount' => $amount,
        'reason' => $reason,
    ]);
    header('Location: ' . $url, true, 302);
    exit;
}

$txnid = trim((string)($_REQUEST['txnid'] ?? $_REQUEST['txnId'] ?? $_REQUEST['txn_id'] ?? ''));

if ($txnid === '') {
    error_log('[PayU v2] Callback arrived without a txnid: ' . json_encode($_REQUEST));
    redirectToFailure('', 'Payment reference was missing from the gateway callback.');
}

// ── Step 2: verify_payment ────────────────────────────────────────────────
// সাইন ইনিশিয়াল রিকোয়েস্টের মতোই: sha512(body|date|salt), hex lowercase।
// শুধু অতিরিক্ত হেডার Info-Command: verify_payment লাগে।
$verifyBody = json_encode(['txnId' => [$txnid]]);
$verifyDate = gmdate('D, d M Y H:i:s \G\M\T');
$verifyHash = hash('sha512', $verifyBody . '|' . $verifyDate . '|' . PAYU_MERCHANT_SALT);
$verifyAuth = 'hmac username="' . PAYU_MERCHANT_KEY . '", algorithm="sha512", headers="date", signature="' . $verifyHash . '"';

$ch = curl_init(PAYU_V2_VERIFY_ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTPHEADER     => [
        'content-type: application/json',
        'date: ' . $verifyDate,
        'authorization: ' . $verifyAuth,
        'Info-Command: verify_payment',
    ],
    CURLOPT_POSTFIELDS => $verifyBody,
]);
$raw  = curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false) {
    error_log('[PayU v2 verify] transport failure: ' . $err);
    redirectToFailure($txnid, 'Could not reach the payment gateway to verify the payment.');
}

$json = json_decode((string)$raw, true);
error_log('[PayU v2 verify] HTTP ' . $code . ' txn=' . $txnid . ' body=' . substr((string)$raw, 0, 400));

// সফল উত্তরে result একটি array; ব্যর্থ হলে {"status":0,"msg":"Invalid Transaction ID"}।
$row = (is_array($json) && isset($json['result'][0]) && is_array($json['result'][0]))
    ? $json['result'][0]
    : null;

if ($row === null || strtolower((string)($row['status'] ?? '')) !== 'success') {
    $reason = (string)($row['errorMessage'] ?? $row['field9'] ?? $json['msg'] ?? 'Payment was not completed.');
    if ($reason === '' || $reason === 'No Error') {
        $reason = 'Payment was not completed.';
    }
    redirectToFailure(
        $txnid,
        $reason,
        (string)($row['udf1'] ?? ''),
        (string)($row['udf2'] ?? 'full'),
        (string)($row['amount'] ?? '0')
    );
}

// ── যাচাই সফল ────────────────────────────────────────────────────────────
$amount = (string)($row['amount'] ?? '0');
$course = (string)($row['udf1'] ?? '');
$plan   = (string)($row['udf2'] ?? 'full');
$name   = (string)($row['firstName'] ?? '');
$email  = (string)($row['email'] ?? '');
$mihpay = (string)($row['mihpayId'] ?? '');

// custom payment-এ udf1 = "custom-payment" (স্লাগ)। রসিদে পেয়ারের লেখা purpose
// (udf3) দেখানো হয়, স্লাগ নয়।
$customTitle  = trim((string)($row['udf3'] ?? ''));
$displayTitle = $customTitle !== '' ? $customTitle : $course;

// অ্যাডমিনকে যাচাইকৃত পেমেন্ট নোটিফিকেশন।
$adminSub = "✅ [Payment Verified] ₹{$amount} - {$name}";
$adminBody = "
    <div style='font-family: monospace; background:#070e1c; color:#fff; padding:20px; border-radius:10px;'>
        <h2 style='color:#10b981;'>Payment Verified via API (v2)</h2>
        <p><strong>Txn ID:</strong> {$txnid}</p>
        <p><strong>PayU Payment ID:</strong> {$mihpay}</p>
        <p><strong>Candidate:</strong> {$name} ({$email})</p>
        <p><strong>Course:</strong> " . htmlspecialchars($displayTitle) . "</p>
        <p><strong>Plan:</strong> " . htmlspecialchars($plan) . "</p>
        <p><strong>Amount:</strong> ₹{$amount}</p>
    </div>
";
dispatchNotificationMail(ADMIN_EMAIL, $adminSub, $adminBody);

// ক্রেতা/স্টুডেন্টকে পেমেন্ট নিশ্চিতকরণ রসিদ। success পেজ "invoice ... dispatched
// to your email" প্রতিশ্রুতি দেয়, তাই এটা না পাঠালে সেটা মিথ্যা হয়ে যেত।
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $studentSub = "Payment Confirmed: " . htmlspecialchars($displayTitle) . " (₹{$amount})";
    $studentBody = "
        <div style='font-family: sans-serif; background:#ffffff; color:#1e293b; padding:28px; border:1px solid #e2e8f0; border-radius:12px;'>
            <h2 style='color:#0284c7; margin-top:0;'>Payment Received — Thank You!</h2>
            <p>Hi " . htmlspecialchars($name) . ",</p>
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

// টোকেন যোগ করা হচ্ছে যাতে success পেজ URL-এর amount/course বিশ্বাস না করে।
$successQuery = [
    'txnid'  => $txnid,
    'amount' => $amount,
    'course' => $course,
    'status' => 'confirmed',
    'token'  => enrollmentToken($txnid, $amount, $course),
];

// custom payment-এ udf1 = "custom-payment" (স্লাগ)। সেটা রসিদে না দেখিয়ে
// init.php যে purpose পাঠিয়েছিল (udf3) সেটাই দেখানো হয়।
if ($customTitle !== '') {
    $successQuery['title'] = $customTitle;
}

$redirectUrl = SITE_URL . '/courses/payment-success?' . http_build_query($successQuery);
header('Location: ' . $redirectUrl, true, 302);
exit;
