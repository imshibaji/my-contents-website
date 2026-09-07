<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Secure Session Initialization
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

// Rate Limiting (IP-based, max 5 requests per 10 mins)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitFile = sys_get_temp_dir() . '/rate_' . md5($ip) . '.json';
$now = time();

if (file_exists($rateLimitFile)) {
    $data = json_decode((string)file_get_contents($rateLimitFile), true);
    if ($data && ($now - $data['start_time']) < 600) {
        if ($data['count'] >= 5) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again after 10 minutes.']);
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

// Method Validation
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

// 1. Bot Security: Honeypot Check
if (!empty($input['company_website_url'])) {
    echo json_encode(['success' => true]);
    exit;
}

// 2. Bot Security: Time-Trap (Humans take > 3 seconds)
$formInitTime = isset($input['form_timestamp']) ? (int)$input['form_timestamp'] : 0;
if ($formInitTime === 0 || ($now - $formInitTime) < 3) {
    echo json_encode(['success' => true]);
    exit;
}

// 3. Security: CSRF Validation
$submittedToken = $input['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (empty($submittedToken) || empty($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token mismatch. Please refresh and retry.']);
    exit;
}

// Data Sanitization
$name = trim(htmlspecialchars((string)($input['name'] ?? ''), ENT_QUOTES, 'UTF-8'));
$email = filter_var(trim((string)($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$scope = trim(htmlspecialchars((string)($input['engagement_scope'] ?? 'General Advisory'), ENT_QUOTES, 'UTF-8'));
$message = trim(htmlspecialchars((string)($input['message'] ?? ''), ENT_QUOTES, 'UTF-8'));

if (empty($name) || !$email || empty($message)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Please provide a valid name, email address, and message.']);
    exit;
}

// Configuration
$myEmail = 'imshibaji@gmail.com';
$systemSender = 'no-reply@shibajidebnath.com';

// -------------------------------------------------------------
// ১. আপনার জন্য ইমেইল (Admin Alert)
// -------------------------------------------------------------
$adminSubject = "New Advisory Inquiry: {$name} [{$scope}]";
$adminBody = "Hi Shibaji,\n\n";
$adminBody .= "You have received a new consultation inquiry from your website:\n\n";
$adminBody .= "--------------------------------------------------\n";
$adminBody .= "Name: {$name}\n";
$adminBody .= "Email: {$email}\n";
$adminBody .= "Scope: {$scope}\n";
$adminBody .= "Timestamp: " . date('Y-m-d H:i:s T') . "\n";
$adminBody .= "IP Address: {$ip}\n";
$adminBody .= "--------------------------------------------------\n\n";
$adminBody .= "Project Context / System Bottleneck:\n";
$adminBody .= "{$message}\n\n";
$adminBody .= "--------------------------------------------------\n";
$adminBody .= "Reply directly to this email to contact the client.";

$adminHeaders = [
    'From' => "Portfolio Portal <{$systemSender}>",
    'Reply-To' => "{$name} <{$email}>",
    'X-Mailer' => 'PHP/' . phpversion(),
    'Content-Type' => 'text/plain; charset=UTF-8'
];

$adminSent = mail($myEmail, $adminSubject, $adminBody, $adminHeaders);

// -------------------------------------------------------------
// ২. ক্লায়েন্টের জন্য কনফার্মেশন ইমেইল (Client Auto-Responder)
// -------------------------------------------------------------
$clientSubject = "Inquiry Received: Technical Consultation with Shibaji Debnath";
$clientBody = "Hi {$name},\n\n";
$clientBody .= "Thank you for reaching out regarding '{$scope}'.\n\n";
$clientBody .= "I have received your project details and system requirements. I review incoming architecture and advisory inquiries personally and will get back to you within 24 business hours.\n\n";
$clientBody .= "Summary of your submission:\n";
$clientBody .= "--------------------------------------------------\n";
$clientBody .= "Objective: {$scope}\n";
$clientBody .= "Message: {$message}\n";
$clientBody .= "--------------------------------------------------\n\n";
$clientBody .= "Best regards,\n\n";
$clientBody .= "Shibaji Debnath\n";
$clientBody .= "Chief Technology Officer | Senior System Architect\n";
$clientBody .= "Website: https://shibajidebnath.com\n";
$clientBody .= "Email: {$myEmail}\n";
$clientBody .= "LinkedIn: https://linkedin.com/in/shibaji\n";
$clientBody .= "GitHub: https://github.com/imshibaji";

$clientHeaders = [
    'From' => "Shibaji Debnath <{$systemSender}>",
    'Reply-To' => "Shibaji Debnath <{$myEmail}>",
    'X-Mailer' => 'PHP/' . phpversion(),
    'Content-Type' => 'text/plain; charset=UTF-8'
];

// ক্লায়েন্টকে অটোমেটিক কনফার্মেশন পাঠানো
$clientSent = mail($email, $clientSubject, $clientBody, $clientHeaders);

// -------------------------------------------------------------
// Response Delivery
// -------------------------------------------------------------
if ($adminSent) {
    // সফল হলে পরবর্তী রিকোয়েস্টের জন্য নতুন CSRF তৈরি
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    
    echo json_encode([
        'success' => true,
        'client_notified' => $clientSent,
        'new_token' => $_SESSION['csrf_token']
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Server failed to send email. Please email imshibaji@gmail.com directly.'
    ]);
}