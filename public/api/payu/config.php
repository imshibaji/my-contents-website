<?php
// api/payu/config.php
declare(strict_types=1);

// ১. CORS Headers (Astro ফ্রন্টএন্ড থেকে ডিকাপলড কলের জন্য)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ২. PayU মার্চেন্ট ক্রেডেনশিয়াল (TEST বা PROD)
define('PAYU_MODE', 'TEST'); // লাইভ প্রোডাকশনে নেওয়ার সময় 'PROD' করুন
define('PAYU_MERCHANT_KEY', '59xiPf');
define('PAYU_MERCHANT_SALT', 'BwhFF78ZpGIXAu3rCG7Qa1DkgEzI1mxQ');

define('PAYU_BASE_URL', PAYU_MODE === 'PROD' 
    ? 'https://secure.payu.in/_payment' 
    : 'https://test.payu.in/_payment'
);

// ৩. সাইট ও কলব্যাক URLs
define('SITE_URL', 'https://shibajidebnath.com');
define('PAYU_SUCCESS_URL', SITE_URL . '/courses/payment-success');
define('PAYU_FAILURE_URL', SITE_URL . '/courses/payment-failed');

define('ADMIN_EMAIL', 'imshibaji@gmail.com');

// ৪. অটোমেটিক course_catalog.json রেজোলিউশন ইঞ্জিন (Multi-path fallback)
function loadCourseCatalog(): array {
    $candidatePaths = [
        // ১. config.php যদি api/payu বা public/api/payu-তে থাকে (api/course_catalog.json)
        __DIR__ . '/../course_catalog.json',
        // ২. রুট থেকে public/api/course_catalog.json
        dirname(__DIR__, 2) . '/public/api/course_catalog.json',
        // ৩. রুট থেকে dist/api/course_catalog.json (প্রোডাকশন বিল্ড)
        dirname(__DIR__, 2) . '/dist/api/course_catalog.json',
        // ৪. ওয়েব সার্ভারের DOCUMENT_ROOT ফলব্যাক
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/api/course_catalog.json',
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/course_catalog.json',
    ];

    foreach ($candidatePaths as $path) {
        if (!empty($path) && file_exists($path) && is_readable($path)) {
            // PHP file cache বাইপাস করে সবসময় লেটেস্ট ফাইল রিড করা
            clearstatcache(true, $path);
            $content = (string)file_get_contents($path);
            $decoded = json_decode($content, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }
    }

    error_log('[PayU Config Warning] course_catalog.json could not be located in candidate paths.');
    return [];
}

// গ্লোবাল ভ্যারিয়েবলে ক্যাটালগ লোড করা
$COURSE_CATALOG = loadCourseCatalog();

// ৫. লাইটওয়েট ইমেইল নোটিফিকেশন ডিসপ্যাচার
function dispatchNotificationMail(string $to, string $subject, string $htmlBody): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Shibaji Debnath Advisory <no-reply@shibajidebnath.com>\r\n";
    $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    return @mail($to, $subject, $htmlBody, $headers);
}