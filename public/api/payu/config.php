<?php
// api/payu/config.php
declare(strict_types=1);

// এনভ লোডিং এখন এক জায়গায় — public/api/env-loader.php। ফাইলটি না থাকলে
// require_once fatal error দেয়, ফলে পুরো পেমেন্ট API খালি 500 হয়ে যায় এবং
// কারণটা ব্রাউজারে দেখা যায় না — তাই আগে ফাইলটি আছে কি না যাচাই করা হচ্ছে।
if (!is_file($envLoader = __DIR__ . '/../env-loader.php')) {
    error_log('[Env Loader] Missing required file: ' . $envLoader);
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    exit('{"status":"error","code":"env_loader_missing","message":"Server configuration is incomplete."}');
}
require_once $envLoader;

// envFileValues() parses .env on first call; the loader is already required
// above, so there is nothing more to do here.
$credentialFilePath = credentialFile();

// ১. CORS Headers (Astro ফ্রন্টএন্ড থেকে ডিকাপলড কলের জন্য)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ২. PayU মার্কেন্ট ক্রিয়েডেনশিয়াল (TEST বা PROD)
define('PAYU_MERCHANT_KEY', env('PAYU_MERCHANT_KEY', ''));
define('PAYU_MERCHANT_SALT', env('PAYU_MERCHANT_SALT', ''));

// ৩. সাইট ও কলব্যাক URLs
define('SITE_URL', rtrim(env('SITE_URL', 'https://shibajidebnath.com'), '/'));
define('PAYU_SUCCESS_URL', SITE_URL . '/courses/payment-success');
define('PAYU_FAILURE_URL', SITE_URL . '/courses/payment-failed');

define('ADMIN_EMAIL', env('ADMIN_EMAIL', 'imshibaji@gmail.com'));

// PAYU_MODE-এ হার্ডকোড 'TEST' ডিফল্ট দিলে সার্ভারে এই ভ্যারিয়েবলটি না থাকলে
// কাস্টমার চুপচাপ test.payu.in-এ চলে যেত, টাকা আসত না, আর কোনো ত্রুটিও দেখাত না।
// তাই ডিফল্টটি এখন SITE_URL থেকে বের করা হয়: লোকাল ঠিকানা হলে TEST, নয়তো PROD।
$payuDefaultMode = preg_match(
    '~^(https?://)?(localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\])(:\d+)?(/|$)~i',
    SITE_URL
) ? 'TEST' : 'PROD';

define('PAYU_MODE', strtoupper(env('PAYU_MODE', $payuDefaultMode)));
define('PAYU_MODE_SOURCE', env('PAYU_MODE') !== null ? 'env' : 'inferred from SITE_URL');

define('PAYU_BASE_URL', PAYU_MODE === 'PROD'
    ? 'https://secure.payu.in/_payment'
    : 'https://test.payu.in/_payment'
);

// ৪. কারেন্সি — PayU-র এই মার্চেন্টের জন্য transactionCurrency বাধ্যতামূলক।
// এটা না পাঠালে গেটওয়ে "transactionCurrency is mandatory for this merchant"
// ত্রুটি দেয়। টাকার পরিমাণ সবসময় INR-তে, তাই এখানে IN র বসানো হলো।
define('PAYU_TRANSACTION_CURRENCY', strtoupper(env('PAYU_TRANSACTION_CURRENCY', 'INR')));
// মার্চেন্ট অ্যাকাউন্টের কারেন্সি। transactionCurrency এটির সমান হলে
// amountToBeConverted দিতে হয় না; আলাদা হলে দুটোই বাধ্যতামূলক।
define('PAYU_ACCOUNT_CURRENCY', strtoupper(env('PAYU_ACCOUNT_CURRENCY', 'INR')));

// ৫. কারেন্সি ফিল্ড কীভাবে পাঠানো হবে।
//
// PayU-র দুটো আলাদা ইন্টিগ্রেশন আছে:
//   • Legacy form redirect (/_payment)  → salt + SHA-512 hash, optional
//     transactionCurrency
//   • Checkout v2 (JSON API)            → API key + secret key, mandatory `currency`
//
// এই অ্যাকাউন্টে দুটো আলাদা আলাদা ত্রুটি আসছে:
//   • ফিল্ড না পাঠালে → "transactionCurrency is mandatory for this merchant"
//     (অর্থাৎ অ্যাকাউন্টটি multi-currency হিসেবে চিহ্নিত)
//   • ফিল্ড পাঠালে   → "Invalid API version for transactionCurrency request"
//     (অর্থাৎ /_payment এন্ডপয়েন্ট এই ফিল্ডটি চেনে না)
//
// দুটো একসাথে সত্য হতে পারে না, তাই কোনো একটি প্যারামিটার ঠিক করলেই ঠিক হবে না।
// কোন ইন্টিগ্রেশনে অ্যাকাউন্টটি প্রোভিশন করা, সেটা PayU ঠিক করবে। ততক্ষণ এটা
// একটি সুইচ, যাতে প্রতিবার কোড বদলে ডিপ্লয় না করেই টগল করা যায়:
//   none        → legacy flow, ফিল্ড পাঠায় না            (ডিফল্ট)
//   transaction → legacy flow, ফিল্ড পাঠায়               (multi-currency অ্যাকাউন্ট)
//   v2          → ফিল্ডটি Checkout v2 JSON API-তে যায়, API key দরকার
define('PAYU_CURRENCY_MODE', strtolower(env('PAYU_CURRENCY_MODE', 'none')));

// গেটওয়ে URL ওভাররাইড। টেস্ট কী দিয়ে প্রোড গেটওয়ে দেখা যায় কিনা যাচাই করতে
// এটা কাজে লাগে, এবং PayU যদি অন্য URL বলে তাহলে কোড ছুঁতে হয় না।
define('PAYU_GATEWAY_URL', rtrim((string)env(
    'PAYU_GATEWAY_URL',
    PAYU_MODE === 'PROD'
        ? 'https://secure.payu.in/_payment'
        : 'https://test.payu.in/_payment'
), '/'));

// ৬. কোন PayU API ব্যবহার হবে — legacy | v2
//
// এই অ্যাকাউন্টে (টেস্ট কী 59xiPf) legacy ফর্ম এন্ডপয়েন্ট কাজ করছে না। সরাসরি
// পরীক্ষা করে দেখা গেছে:
//
//   /_payment + কোনো কারেন্সি ফিল্ড নেই  → "transactionCurrency is mandatory
//                                          for this merchant"
//   /_payment + transactionCurrency=INR   → "Invalid API version for
//                                          transactionCurrency request"
//   /_payment + currency / txnCurrency    → "mandatory" (কোনোটিই চেনা হয়নি)
//   /_payment + transactionCurrency=inr   → "Please pass ISO 3-alpha
//                                          uppercase currency code"
//
// অর্থাৎ legacy এন্ডপয়েন্টটি এই মার্চেন্টকে multi-currency হিসেবে দেখে, কিন্তু
// নিজের API ভার্সনে সেই ফিল্ডটি চেনে না। কোনো প্যারামিটার দিয়ে এটা ঠিক করা
// যায় না — কোডে সীমাবদ্ধতা নয়, অ্যাকাউন্ট প্রোভিশনিং সমস্যা।
//
// সমাধান: Checkout v2 JSON API, যেটা আলাদা এন্ডপয়েন্ট ও HMAC অথেন্টিকেশন ব্যবহার
// করে। apitest.payu.in-এ কী 59xiPf গ্রহণ করা হয় (401 Unauthorized মানে সিগনেচার
// যাচাই পর্যন্ত পৌঁছেছে), তাই টেস্টে এটাই কাজ করার কথা।
define('PAYU_API_VERSION', strtolower(env('PAYU_API_VERSION', 'legacy')));

define('PAYU_V2_ENDPOINT', rtrim((string)env(
    'PAYU_V2_ENDPOINT',
    PAYU_MODE === 'PROD'
        ? 'https://api.payu.in/v2/payments'
        : 'https://apitest.payu.in/v2/payments'
), '/'));

// v2-তে billingDetails-এর address1 বাধ্যতামূলক, কিন্তু enquiry ফর্মে ঠিকানা
// জিজ্ঞেস করা হয় না। তাই ডিফল্ট হিসেবে ব্যবসার নিজের ঠিকানা বসানো হলো।
// শপ বা ব্যক্তিগত ঠিকানা দরকার হলে এখানে বদলানো যাবে।
/**
 * সাকসেস পেজে পাঠানো স্বাক্ষর টোকেন।
 *
 * পেজটি static SSG, তাই ব্রাউজারে HMAC যাচাই করা সম্ভব না — salt ব্রাউজারে
 * গেলে পুরো ক্রেডেনশিয়াল লিক। তাই salt দিয়ে টোকেন তৈরি করে পাঠানো হয়, আর
 * যাচাই করে response.php-এর GET মোড।
 *
 * কেন দরকার: URL param থেকে আসা amount/course অবিশ্বাসযোগ্য। ভুল URL বানিয়ে
 * যে কেউ নিজে "সফল পেমেন্ট" দেখাতে পারে, আর সেখান থেকেই Google Ads-এ
 * purchase conversion ফায়ার হয় — যা বিজ্ঞাপনের হিসাব নষ্ট করে।
 */
function enrollmentToken(string $txnid, string $amount, string $courseSlug): string
{
    return hash_hmac(
        'sha512',
        $txnid . '|' . $amount . '|' . $courseSlug,
        PAYU_MERCHANT_SALT
    );
}

define('PAYU_BILLING_ADDRESS', [
    'address1' => env('PAYU_BILLING_ADDRESS1', 'Kolkata'),
    'city'     => env('PAYU_BILLING_CITY', 'Kolkata'),
    'state'    => env('PAYU_BILLING_STATE', 'West Bengal'),
    'country'  => env('PAYU_BILLING_COUNTRY', 'India'),
    'zipCode'  => env('PAYU_BILLING_ZIP', '700001'),
]);

// কোন গেটওয়ে চলছে তা সার্ভার লগে সবসময় লেখা হয়। এতে ভুল মোড নীরবে
// থাকতে পারে না — "কেন টাকা আসছে না" জিজ্ঞেসের উত্তর লগে থাকবে।
error_log('[PayU] mode=' . PAYU_MODE . ' (' . PAYU_MODE_SOURCE . ') gateway=' . PAYU_BASE_URL
    . ' site=' . SITE_URL);

/**
 * ক্রেডেনশিয়াল না থাকলে আগেই থেমে দেওয়া হচ্ছে। না হলে খালি salt দিয়ে hash
 * তৈরি হয়, PayU সেটা গ্রহণ করে না, আর কোথায় সমস্যা হয়েছে বোঝা যায় না —
 * এজন্যই স্পষ্ট একটি ত্রুটি দেখানো হচ্ছে।
 */
function guardPayUCredentials(?string $credentialFilePath): void {
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

    $hint = $credentialFilePath !== null
        ? 'Read ' . basename($credentialFilePath) . ' but the value is empty or blank.'
        : 'No api/credential.php found. Copy api/credential.php.example on the server '
          . 'and fill in the values, or set host environment variables in hPanel.';

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

guardPayUCredentials($credentialFilePath);

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

// ৫. লাইটওয়েট ইমেইল নোটিফিকেশন ডিসপ্যাচার
function dispatchNotificationMail(string $to, string $subject, string $htmlBody): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Shibaji Debnath Advisory <no-reply@shibajidebnath.com>\r\n";
    $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    return @mail($to, $subject, $htmlBody, $headers);
}