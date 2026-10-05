<?php
// api/payu/config.php
declare(strict_types=1);

/**
 * .env খোঁজে। হোস্টিং-এ ডকুরoot ও ফাইলের গভীরতা কোথায় হবে তা নির্ভর করে,
 * তাই একাধিক সম্ভাব্য পাথ চেষ্টা করা হয়। .env গিট-ইগনোর করা, তাই সার্ভারে
 * না থাকলেও getenv() থেকে হোস্ট-লেভেল env var পড়া যেতে পারে।
 */
function loadDotEnv(): ?string {
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

    $candidates = array_filter([
        __DIR__ . '/../../../.env',
        __DIR__ . '/../../../../.env',
        dirname(__DIR__, 3) . '/.env',
        $docRoot !== '' ? $docRoot . '/.env' : null,
        $docRoot !== '' ? $docRoot . '/../.env' : null,
        getcwd() . '/.env',
    ], static fn($p) => !empty($p));

    foreach (array_unique($candidates) as $path) {
        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (strpos($line, '=') === false) continue;

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // উদ্ধৃতি ও মন্তব্য ঠিক করা হচ্ছে
                if (strlen($value) > 1
                    && (($value[0] === '"' && str_ends_with($value, '"'))
                        || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                if (str_contains($value, ' #')) {
                    $value = rtrim(trim(substr($value, 0, strpos($value, ' #'))));
                }

                // হোস্ট-লেভেল env varকে .env-এর উপরে রাখা হচ্ছে না, যাতে সার্ভার
                // কনফিগ সবসময় আগে থাকে (putenv-এর পর getenv আপডেট হয়)।
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
            return $path;
        }
    }
    return null;
}

$loadedDotEnvPath = loadDotEnv();

// ১. CORS Headers (Astro ফ্রন্টএন্ড থেকೆ ডিকাপলড কলের জন্য)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ২. PayU মার্কেন্ট ক্রিয়েডেনশিয়াল (TEST বা PROD)
define('PAYU_MODE', getenv('PAYU_MODE') ?: 'TEST');
define('PAYU_MERCHANT_KEY', getenv('PAYU_MERCHANT_KEY') ?: '');
define('PAYU_MERCHANT_SALT', getenv('PAYU_MERCHANT_SALT') ?: '');

define('PAYU_BASE_URL', PAYU_MODE === 'PROD' 
    ? 'https://secure.payu.in/_payment' 
    : 'https://test.payu.in/_payment'
);

// ৩. সাইট ও কলব্যাক URLs
define('SITE_URL', rtrim(getenv('SITE_URL') ?: 'https://shibajidebnath.com', '/'));
define('PAYU_SUCCESS_URL', SITE_URL . '/courses/payment-success');
define('PAYU_FAILURE_URL', SITE_URL . '/courses/payment-failed');

define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'imshibaji@gmail.com');

/**
 * ক্রেডেনশিয়াল না থাকলে আগেই থেমে দেওয়া হচ্ছে। না হলে খালি salt দিয়ে hash
 * তৈরি হয়, PayU সেটা গ্রহণ করে না, আর কোথায় সমস্যা হয়েছে বোঝা যায় না —
 * এজন্যই স্পষ্ট একটি ত্রুটি দেখানো হচ্ছে।
 */
function guardPayUCredentials(?string $dotenvPath): void {
    $missing = [];
    if (PAYU_MERCHANT_KEY === '') {
        $missing[] = 'PAYU_MERCHANT_KEY';
    }
    if (PAYU_MERCHANT_SALT === '') {
        $missing[] = 'PAYU_MERCHANT_SALT';
    }
    if ($missing === []) {
        return;
    }

    $hint = $dotenvPath !== null
        ? 'Loaded .env from ' . $dotenvPath . ' but the value is empty.'
        : 'No readable .env was found. Searched relative to __DIR__, DOCUMENT_ROOT and getcwd().';

    error_log('[PayU Config Error] Missing ' . implode(', ', $missing) . '. ' . $hint);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'status'  => 'error',
        'code'    => 'payu_config_incomplete',
        'message' => 'Payment gateway is not configured on the server. Please contact support.',
        'missing' => $missing,
    ]);
    exit;
}

guardPayUCredentials($loadedDotEnvPath);

// ৪. অটোমেটিক course_catalog.json রেজোলিউশন ইঞ্জিন
function loadCourseCatalog(): array {
    $candidatePaths = [
        __DIR__ . '/../course_catalog.json',
        dirname(__DIR__, 2) . '/public/api/course_catalog.json',
        dirname(__DIR__, 2) . '/dist/api/course_catalog.json',
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/api/course_catalog.json',
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/course_catalog.json',
    ];
    foreach ($candidatePaths as $path) {
        if (!empty($path) && file_exists($path) && is_readable($path)) {
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
