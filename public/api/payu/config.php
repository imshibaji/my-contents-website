<?php
// api/payu/config.php
declare(strict_types=1);

// 1. CORS Headers for decoupled Astro client calls
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. PayU Merchant Credentials (TEST or PROD)
define('PAYU_MODE', 'TEST'); // লাইভে তোলার সময় 'PROD' হিসেবে সেট করুন
define('PAYU_MERCHANT_KEY', 'YOUR_PAYU_KEY');
define('PAYU_MERCHANT_SALT', 'YOUR_PAYU_SALT');

define('PAYU_BASE_URL', PAYU_MODE === 'PROD' 
    ? 'https://secure.payu.in/_payment' 
    : 'https://test.payu.in/_payment'
);

// 3. Domain & Webhook URLs
define('SITE_URL', 'https://shibajidebnath.com');
define('PAYU_SUCCESS_URL', SITE_URL . '/api/payu/response.php');
define('PAYU_FAILURE_URL', SITE_URL . '/api/payu/response.php');

// 4. Admin Telemetry / Lead Capture Email
define('ADMIN_EMAIL', 'imshibaji@gmail.com');

// 5. Server-Side Catalog Mapping (Zero-Tampering Security)
// ক্লায়েন্ট থেকে পাঠানো স্লাগ ও পেমেন্ট প্ল্যান অনুযায়ী সঠিক মূল্য নির্ধারিত হবে
$COURSE_CATALOG = [
    'system-architecture-mastery' => [
        'title' => 'Enterprise System Architecture & Microservices (12 Months)',
        'price_full' => 80000.00,
        'price_installment' => 20000.00,
        'currency' => '₹'
    ],
    'sample-free-course' => [
        'title' => 'Introduction to Linux & Containerization Fundamentals',
        'price_full' => 0.00,
        'price_installment' => 0.00,
        'currency' => '₹'
    ]
];

// 6. Lightweight HTML Email Dispatcher
function dispatchNotificationMail(string $to, string $subject, string $htmlBody): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Shibaji Debnath Advisory <no-reply@shibajidebnath.com>\r\n";
    $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    return mail($to, $subject, $htmlBody, $headers);
}