<?php
// public/api/payu/init.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// ১. ইনপুট হ্যান্ডলিং (JSON অথবা Form POST)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$data = [];

if (stripos($contentType, 'application/json') !== false) {
    $rawInput = file_get_contents('php://input');
    $data = !empty($rawInput) ? (json_decode($rawInput, true) ?? []) : [];
} else {
    $data = $_POST;
}

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or empty payload.']);
    exit;
}

// ২. ফিল্ড স্যানিটাইজেশন
$firstname   = trim((string)($data['firstname'] ?? $data['full_name'] ?? ''));
$email       = trim((string)($data['email'] ?? ''));
$phone       = trim((string)($data['phone'] ?? ''));
$courseSlug  = trim((string)($data['course_id'] ?? $data['course_slug'] ?? ''));
$paymentPlan = trim((string)($data['payment_plan'] ?? $data['plan'] ?? 'full'));

if (empty($firstname) || empty($email) || empty($phone) || empty($courseSlug)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'message' => 'All fields (Name, Email, Phone, Course) are required.'
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit;
}

// ৩. ক্যাটালগ ভ্যালিডেশন
if (!isset($COURSE_CATALOG[$courseSlug])) {
    http_response_code(404);
    echo json_encode([
        'status'  => 'error',
        'message' => "Requested course '{$courseSlug}' not found in server catalog."
    ]);
    exit;
}

$course = $COURSE_CATALOG[$courseSlug];

if ($paymentPlan === 'installment' && isset($course['price_installment']) && (float)$course['price_installment'] > 0) {
    $price = (float)$course['price_installment'];
    $planLabel = "Milestone Split";
} else {
    $price = (float)($course['price_full'] ?? 0.00);
    $planLabel = "Full Cohort Access";
}

$txnid = 'TXN_' . strtoupper(bin2hex(random_bytes(4))) . '_' . time();

// ৪. ফ্রি কোর্স ফ্লো (Price <= 0)
if ($price <= 0.00) {
    $adminSub = "🎁 [Free Enrollment] " . htmlspecialchars($firstname) . " - " . htmlspecialchars($course['title']);
    $adminBody = "
    <div style='font-family: monospace, sans-serif; background-color: #0b1329; color: #f8fafc; padding: 24px; border-radius: 12px;'>
        <h2 style='color: #38bdf8; margin-top: 0;'>Free Course Registration Confirmed</h2>
        <p><strong>Candidate:</strong> " . htmlspecialchars($firstname) . " (" . htmlspecialchars($email) . ")</p>
        <p><strong>Phone:</strong> " . htmlspecialchars($phone) . "</p>
        <p><strong>Course:</strong> " . htmlspecialchars($course['title']) . "</p>
        <p><strong>Txn ID:</strong> " . $txnid . "</p>
        <p><strong>Status:</strong> <span style='color: #10b981;'>CONFIRMED (Zero Cost)</span></p>
    </div>
    ";
    dispatchNotificationMail(ADMIN_EMAIL, $adminSub, $adminBody);

    $studentSub = "Access Confirmed: " . htmlspecialchars($course['title']);
    $studentBody = "
    <div style='font-family: sans-serif; background-color: #ffffff; color: #1e293b; padding: 28px; border: 1px solid #e2e8f0; border-radius: 12px;'>
        <h2 style='color: #0284c7;'>Welcome to the Program!</h2>
        <p>Hi " . htmlspecialchars($firstname) . ",</p>
        <p>Your enrollment for <strong>" . htmlspecialchars($course['title']) . "</strong> is verified and complete.</p>
        <p>Curriculum coordinates and environment access will be dispatched shortly.</p>
        <p>Best regards,<br><strong>Shibaji Debnath</strong></p>
    </div>
    ";
    dispatchNotificationMail($email, $studentSub, $studentBody);

    echo json_encode([
        'status'       => 'success',
        'is_free'      => true,
        'redirect_url' => SITE_URL . '/courses/payment-success?txnid=' . urlencode($txnid) . '&course=' . urlencode($courseSlug) . '&amount=0&free=1'
    ]);
    exit;
}

// ৫. পেইড কোর্স ফ্লো (PayU SHA-512 হ্যাশ জেনারেশন)
$amount = number_format($price, 2, '.', '');
$cleanTitle = (string)preg_replace('/[^a-zA-Z0-9_\- ]/', '', $course['title']);
$productinfo = substr(trim($cleanTitle . ' ' . $planLabel), 0, 80);

$leadSub = "🚀 [Checkout Initiated] ₹" . $amount . " - " . htmlspecialchars($firstname);
$leadBody = "
<div style='font-family: monospace, sans-serif; background-color: #0b1329; color: #f8fafc; padding: 24px; border-radius: 12px;'>
    <h2 style='color: #38bdf8; margin-top: 0;'>PayU Terminal Initiated</h2>
    <p><strong>Candidate:</strong> " . htmlspecialchars($firstname) . " (" . htmlspecialchars($email) . ")</p>
    <p><strong>WhatsApp:</strong> " . htmlspecialchars($phone) . "</p>
    <p><strong>Course:</strong> " . htmlspecialchars($course['title']) . "</p>
    <p><strong>Plan:</strong> " . htmlspecialchars($planLabel) . "</p>
    <p><strong>Amount:</strong> ₹" . $amount . " INR</p>
    <p><strong>Txn ID:</strong> " . $txnid . "</p>
</div>
";
dispatchNotificationMail(ADMIN_EMAIL, $leadSub, $leadBody);

$udf1 = $courseSlug;
$udf2 = $paymentPlan;
$udf3 = '';
$udf4 = '';
$udf5 = '';

$hashSequence = PAYU_MERCHANT_KEY . '|' . $txnid . '|' . $amount . '|' . $productinfo . '|' . $firstname . '|' . $email . '|' . $udf1 . '|' . $udf2 . '|' . $udf3 . '|' . $udf4 . '|' . $udf5 . '||||||' . PAYU_MERCHANT_SALT;
$hash = strtolower(hash('sha512', $hashSequence));

echo json_encode([
    'status'  => 'success',
    'is_free' => false,
    'action'  => PAYU_BASE_URL,
    'params'  => [
        'key'         => PAYU_MERCHANT_KEY,
        'txnid'       => $txnid,
        'amount'      => $amount,
        'productinfo' => $productinfo,
        'firstname'   => $firstname,
        'email'       => $email,
        'phone'       => $phone,
        'surl'        => PAYU_SUCCESS_URL,
        'furl'        => PAYU_FAILURE_URL,
        'hash'        => $hash,
        'udf1'        => $udf1,
        'udf2'        => $udf2,
    ]
]);
exit; // <--- এক্সিকিউশন নিশ্চিতভাবে থামানোর জন্য