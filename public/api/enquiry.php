<?php
// public/api/enquiry.php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// send-mail.php থেকে নেটিভ SMTP ড্রাইভার ইনক্লুড করা
require_once __DIR__ . '/send-mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

// ইনপুট সংগ্রহ ও স্যানিটাইজ
$courseSlug  = filter_input(INPUT_POST, 'course_slug', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
$courseTitle = filter_input(INPUT_POST, 'course_title', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
$paymentPlan = filter_input(INPUT_POST, 'payment_plan', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'full';
$amount      = filter_input(INPUT_POST, 'payable_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) ?? '0';

$fullName = trim((string)($_POST['full_name'] ?? ''));
$email    = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$phone    = trim((string)($_POST['phone'] ?? ''));

if (empty($courseSlug) || empty($fullName) || !$email || empty($phone)) {
    http_response_code(422);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#06080f;color:#f87171;padding:40px;">';
    echo '<h3>Application Error</h3><p>Required registration fields are invalid or missing.</p>';
    echo '<a href="javascript:history.back()" style="color:#38bdf8;">&larr; Go Back and Correct</a>';
    echo '</body></html>';
    exit;
}

$cleanPhone = preg_replace('/[^\d+]/', '', $phone);
$formattedAmount = '₹' . number_format((float)$amount, 2);

// ১. PostgreSQL ডাটাবেজে লিড লগ (কানেকশন ফেইল হলেও ফ্লো চলবে)
try {
    $dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $dbPort = $_ENV['DB_PORT'] ?? '5432';
    $dbName = $_ENV['DB_NAME'] ?? 'platform_db';
    $dbUser = $_ENV['DB_USER'] ?? 'postgres';
    $dbPass = $_ENV['DB_PASSWORD'] ?? '';

    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 2,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cohort_enquiries (
            id SERIAL PRIMARY KEY,
            course_slug VARCHAR(120) NOT NULL,
            course_title VARCHAR(255),
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(200) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            payment_plan VARCHAR(30) DEFAULT 'full',
            amount NUMERIC(10, 2) DEFAULT 0.00,
            status VARCHAR(30) DEFAULT 'initiated',
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $stmt = $pdo->prepare("
        INSERT INTO cohort_enquiries (course_slug, course_title, full_name, email, phone, payment_plan, amount)
        VALUES (:course_slug, :course_title, :full_name, :email, :phone, :payment_plan, :amount)
    ");

    $stmt->execute([
        ':course_slug'  => $courseSlug,
        ':course_title' => $courseTitle,
        ':full_name'    => $fullName,
        ':email'        => $email,
        ':phone'        => $cleanPhone,
        ':payment_plan' => $paymentPlan,
        ':amount'       => $amount,
    ]);
} catch (Throwable $e) {
    error_log('[Enquiry DB Warning] ' . $e->getMessage());
}

// ২. send-mail.php-এর Gmail SMTP দিয়ে অ্যাডমিন অ্যালার্ট পাঠানো
$adminSubject = "[Cohort Application] {$fullName} — {$courseTitle}";
$adminBody = "Hi Shibaji,\n\n"
           . "A new cohort candidate has initiated registration:\n\n"
           . "--------------------------------------------------\n"
           . "Program: {$courseTitle}\n"
           . "Candidate Name: {$fullName}\n"
           . "Candidate Email: {$email}\n"
           . "Candidate Phone: {$cleanPhone}\n"
           . "Payment Schedule: " . strtoupper($paymentPlan) . "\n"
           . "Payable Amount: {$formattedAmount}\n"
           . "--------------------------------------------------\n\n"
           . "The candidate has been forwarded to the PayU checkout terminal.\n";

sendViaGmailSMTP(SMTP_GMAIL_USER, $adminSubject, $adminBody, $email, $fullName);

// ৩. ক্যান্ডিডেটকে একনলেজমেন্ট মেইল পাঠানো
$candidateSubject = "Application Logged: {$courseTitle} — Shibaji Debnath";
$candidateBody = "Hi {$fullName},\n\n"
               . "Thank you for applying for '{$courseTitle}'.\n\n"
               . "Your registration profile has been successfully recorded on our system. Please complete your transaction at the verified payment terminal to confirm your seat.\n\n"
               . "Admission Breakdown:\n"
               . "--------------------------------------------------\n"
               . "Cohort: {$courseTitle}\n"
               . "Plan: " . strtoupper($paymentPlan) . "\n"
               . "Total Payable: {$formattedAmount}\n"
               . "--------------------------------------------------\n\n"
               . "If you need any guidance during the process, feel free to reply directly to this email.\n\n"
               . "Best regards,\n\n"
               . "Shibaji Debnath\n"
               . "CTO | Senior System Architect\n"
               . "https://shibajidebnath.com\n";

sendViaGmailSMTP($email, $candidateSubject, $candidateBody, SMTP_GMAIL_USER, "Shibaji Debnath");

// ৪. চেকআউট পেজে রিডাইরেক্ট
$queryParams = http_build_query([
    'course' => $courseSlug,
    'plan'   => $paymentPlan,
    'name'   => $fullName,
    'email'  => $email,
    'phone'  => $cleanPhone,
]);

header('Location: /courses/checkout?' . $queryParams, true, 303);
exit;