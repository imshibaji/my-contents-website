<?php
declare(strict_types=1);

// Error suppression from polluting JSON
error_reporting(0);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// ==========================================
// SMTP Credentials & Global Config
// ==========================================
define('SMTP_GMAIL_USER', 'imshibaji@gmail.com');
define('SMTP_GMAIL_PASS', 'YOUR_16_DIGIT_GMAIL_APP_PASSWORD'); // 👈 আপনার ১৬ ডিজিটের অ্যাপ পাসওয়ার্ড দিন

/**
 * Lightweight Native Socket SMTP Sender (Re-usable Helper)
 */
function sendViaGmailSMTP(
    string $to, 
    string $subject, 
    string $body, 
    string $replyToEmail, 
    string $replyToName, 
    string $smtpUser = SMTP_GMAIL_USER, 
    string $smtpPass = SMTP_GMAIL_PASS,
    bool $isHtml = false
): bool {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client("ssl://smtp.gmail.com:465", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) return false;

    $read = function() use ($socket) {
        $data = "";
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === " ") break;
        }
        return $data;
    };

    $write = function(string $cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
    };

    $read();
    $write("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $read();
    $write("AUTH LOGIN");
    $read();
    $write(base64_encode($smtpUser));
    $read();
    $write(base64_encode($smtpPass));
    $authRes = $read();
    if (strpos($authRes, '235') === false) {
        fclose($socket);
        return false;
    }

    $write("MAIL FROM:<{$smtpUser}>");
    $read();
    $write("RCPT TO:<{$to}>");
    $read();
    $write("DATA");
    $read();

    $contentType = $isHtml ? "text/html; charset=UTF-8" : "text/plain; charset=UTF-8";

    $headers = "From: Shibaji Debnath <{$smtpUser}>\r\n";
    $headers .= "Reply-To: {$replyToName} <{$replyToEmail}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Content-Type: {$contentType}\r\n";
    $headers .= "X-Mailer: Native-PHP-SMTP\r\n\r\n";

    $write($headers . $body . "\r\n.");
    $res = $read();
    $write("QUIT");
    fclose($socket);

    return strpos($res, '250') !== false;
}

// =========================================================================
// যদি অন্য কোনো ফাইল (যেমন enquiry.php) এটি include করে, তবে নিচের অংশ থামবে
// =========================================================================
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== basename(__FILE__)) {
    return;
}

// =========================================================================
// কন্টাক্ট ফর্ম প্রসেসিং (সরাসরি /api/send-mail.php হিট হলে এটি চলবে)
// =========================================================================
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Rate Limiting (IP-based, max 5 requests per 10 mins)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitFile = sys_get_temp_dir() . '/rate_' . md5($ip) . '.json';
$now = time();

if (file_exists($rateLimitFile)) {
    $data = json_decode((string)file_get_contents($rateLimitFile), true);
    if ($data && ($now - $data['start_time']) < 600) {
        if ($data['count'] >= 5) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many requests. Please retry in 10 minutes.']);
            exit;
        }
        $data['count']++;
    } else {
        $data = ['start_time' => $now, 'count' => 1];
    }
} else {
    $data = ['start_time' => $now, 'count' => 1];
}
file_put_contents($rateLimitFile, json_encode($data));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload.']);
    exit;
}

// 1. Honeypot check
if (!empty($input['company_website_url'])) {
    echo json_encode(['success' => true]);
    exit;
}

// 2. Time-trap check
$formInitTime = isset($input['form_timestamp']) ? (int)$input['form_timestamp'] : 0;
if ($formInitTime === 0 || ($now - $formInitTime) < 3) {
    echo json_encode(['success' => true]);
    exit;
}

// 3. CSRF Validation
$submittedToken = $input['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (empty($submittedToken) || empty($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token mismatch. Please refresh and retry.']);
    exit;
}

// Input Sanitization
$name = trim(htmlspecialchars((string)($input['name'] ?? ''), ENT_QUOTES, 'UTF-8'));
$email = filter_var(trim((string)($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$scope = trim(htmlspecialchars((string)($input['engagement_scope'] ?? 'General Advisory'), ENT_QUOTES, 'UTF-8'));
$message = trim(htmlspecialchars((string)($input['message'] ?? ''), ENT_QUOTES, 'UTF-8'));

if (empty($name) || !$email || empty($message)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Please provide a valid name, email, and description.']);
    exit;
}

// কন্টাক্ট মেইল ডিসপ্যাচ
$adminSubject = "Advisory Inquiry: {$name} [{$scope}]";
$adminBody = "Hi Shibaji,\n\nYou have received a new consultation inquiry:\n\n"
           . "Name: {$name}\n"
           . "Email: {$email}\n"
           . "Scope: {$scope}\n"
           . "IP: {$ip}\n\n"
           . "Message:\n{$message}\n";

$adminSent = sendViaGmailSMTP(SMTP_GMAIL_USER, $adminSubject, $adminBody, $email, $name);

$clientSubject = "Inquiry Received: Technical Consultation with Shibaji Debnath";
$clientBody = "Hi {$name},\n\n"
            . "Thank you for reaching out regarding '{$scope}'.\n\n"
            . "I have received your project details and system requirements. I will review your inquiry and follow up within 24 business hours.\n\n"
            . "Summary of your submission:\n"
            . "--------------------------------------------------\n"
            . "Objective: {$scope}\n"
            . "Message: {$message}\n"
            . "--------------------------------------------------\n\n"
            . "Best regards,\n\n"
            . "Shibaji Debnath\n"
            . "CTO | Senior System Architect\n"
            . "https://shibajidebnath.com\n";

if ($adminSent) {
    sendViaGmailSMTP($email, $clientSubject, $clientBody, SMTP_GMAIL_USER, "Shibaji Debnath");

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    echo json_encode([
        'success' => true,
        'new_token' => $_SESSION['csrf_token']
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server failed to deliver email via SMTP.'
    ]);
}