<?php
// api/payu/init.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON Payload']);
    exit;
}

$firstname   = trim($data['firstname'] ?? '');
$email       = trim($data['email'] ?? '');
$phone       = trim($data['phone'] ?? '');
$courseSlug  = trim($data['course_id'] ?? '');
$paymentPlan = trim($data['payment_plan'] ?? 'full'); // 'full' or 'installment'

if (empty($firstname) || empty($email) || empty($phone) || empty($courseSlug)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'All fields (Name, Email, Phone, Course) are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email address provided.']);
    exit;
}

if (!isset($COURSE_CATALOG[$courseSlug])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Requested course not found in catalog.']);
    exit;
}

$course = $COURSE_CATALOG[$courseSlug];

// Determine price based on selected payment plan
if ($paymentPlan === 'installment' && isset($course['price_installment'])) {
    $price = (float)$course['price_installment'];
    $planLabel = "Quarterly Milestone (1/4)";
} else {
    $price = (float)($course['price_full'] ?? 0.00);
    $planLabel = "Full 1-Year Payment";
}

$txnid = 'TXN_' . strtoupper(bin2hex(random_bytes(4))) . '_' . time();

// Condition 1: Free Course Enrollment Flow (Price = 0)
if ($price <= 0.00) {
    $adminSub = "🎁 [Free Enrollment] " . htmlspecialchars($firstname) . " - " . htmlspecialchars($course['title']);
    $adminBody = "
    <div style='font-family: monospace, sans-serif; background-color: #0b1329; color: #f8fafc; padding: 24px; border-radius: 12px;'>
        <h2 style='color: #38bdf8; margin-top: 0;'>New Free Course Registration</h2>
        <p><strong>Student:</strong> " . htmlspecialchars($firstname) . " (" . htmlspecialchars($email) . ")</p>
        <p><strong>Phone:</strong> " . htmlspecialchars($phone) . "</p>
        <p><strong>Course:</strong> " . htmlspecialchars($course['title']) . "</p>
        <p><strong>Txn ID:</strong> " . $txnid . "</p>
        <p><strong>Status:</strong> <span style='color: #10b981;'>COMPLETED (Free Access)</span></p>
    </div>
    ";
    @dispatchNotificationMail(ADMIN_EMAIL, $adminSub, $adminBody);

    $studentSub = "Access Confirmed: " . htmlspecialchars($course['title']);
    $studentBody = "
    <div style='font-family: sans-serif; background-color: #ffffff; color: #1e293b; padding: 28px; border: 1px solid #e2e8f0; border-radius: 12px;'>
        <h2 style='color: #0284c7;'>Welcome to the Course!</h2>
        <p>Hi " . htmlspecialchars($firstname) . ",</p>
        <p>Your enrollment for <strong>" . htmlspecialchars($course['title']) . "</strong> is verified and complete.</p>
        <p>You will receive your curriculum resources and workspace coordinates shortly.</p>
        <p>Regards,<br><strong>Shibaji Debnath</strong></p>
    </div>
    ";
    @dispatchNotificationMail($email, $studentSub, $studentBody);

    echo json_encode([
        'status' => 'success',
        'is_free' => true,
        'redirect_url' => SITE_URL . '/courses/payment-success?txnid=' . urlencode($txnid) . '&course=' . urlencode($courseSlug) . '&free=1'
    ]);
    exit;
}

// Condition 2: Paid Gateway Flow (PayU SHA-512 Generation)
$amount = number_format($price, 2, '.', '');
$cleanTitle = (string)preg_replace('/[^a-zA-Z0-9_\- ]/', '', $course['title']);
$productinfo = substr(trim($cleanTitle . ' ' . $planLabel), 0, 80);

// Immediate Lead / Checkout Initiated Telemetry Email
$leadSub = "🚀 [Checkout Initiated] ₹" . $amount . " - " . htmlspecialchars($firstname);
$leadBody = "
<div style='font-family: monospace, sans-serif; background-color: #0b1329; color: #f8fafc; padding: 24px; border-radius: 12px;'>
    <h2 style='color: #38bdf8; margin-top: 0;'>Checkout Terminal Session Started</h2>
    <p>A user initiated payment and is entering the PayU gateway.</p>
    <hr style='border: 0; border-top: 1px solid #1e293b; margin: 16px 0;' />
    <p><strong>Student Name:</strong> " . htmlspecialchars($firstname) . "</p>
    <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
    <p><strong>Phone:</strong> " . htmlspecialchars($phone) . "</p>
    <p><strong>Course:</strong> " . htmlspecialchars($course['title']) . "</p>
    <p><strong>Selected Plan:</strong> " . htmlspecialchars($planLabel) . "</p>
    <p><strong>Amount:</strong> ₹" . $amount . " INR</p>
    <p><strong>Txn ID:</strong> " . $txnid . "</p>
    <p><strong>Status:</strong> <span style='color: #f59e0b;'>PENDING GATEWAY COMPLETION</span></p>
</div>
";
@dispatchNotificationMail(ADMIN_EMAIL, $leadSub, $leadBody);

// User Defined Fields (UDF)
$udf1 = $courseSlug;
$udf2 = $paymentPlan;
$udf3 = '';
$udf4 = '';
$udf5 = '';

// Formula: sha512(key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||SALT)
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