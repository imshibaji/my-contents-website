<?php
// public/api/tool-lead.php
declare(strict_types=1);

// env-loader.php না থাকলে require_once fatal error দেয়, ফলে পুরো API খালি 500 হয়ে
// যায় এবং কারণটা ব্রাউজারে দেখা যায় না। তাই আগে ফাইলটি আছে কি না যাচাই করা হচ্ছে।
if (!is_file($envLoader = __DIR__ . '/env-loader.php')) {
    error_log('[Env Loader] Missing required file: ' . $envLoader);
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    exit('{"status":"error","code":"env_loader_missing","message":"Server configuration is incomplete."}');
}
require_once $envLoader;
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/send-mail.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?? [];

$email = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$tool  = trim(htmlspecialchars($input['tool'] ?? 'VPS & Docker Readiness Checker', ENT_QUOTES));
$name  = trim(htmlspecialchars($input['name'] ?? 'Anonymous', ENT_QUOTES));
$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!$email) {
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>'Valid email required']);
    exit;
}

// Insert into Supabase.
// The service_role key bypasses row-level security, so it must come from
// api/credential.php or a host variable — never a literal here. Refuse to run
// without it rather than making an unauthenticated request that Supabase
// rejects with a confusing error.
$SUPABASE_URL = env('PUBLIC_SUPABASE_URL', '');
$SUPABASE_KEY = env('SUPABASE_SERVICE_KEY', '');

if ($SUPABASE_URL === '' || strlen($SUPABASE_KEY) < 10) {
    error_log('[tool-lead] PUBLIC_SUPABASE_URL or SUPABASE_SERVICE_KEY is not set.');
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'code'    => 'supabase_config_incomplete',
        'error'   => 'Lead capture is not configured on the server.',
    ]);
    exit;
}

$ch = curl_init($SUPABASE_URL . '/rest/v1/tool_leads?select=id');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $SUPABASE_KEY,
        'Authorization: Bearer ' . $SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'email' => $email,
        'name' => $name,
        'tool' => $tool,
        'step' => 0
    ]),
    CURLOPT_TIMEOUT => 15
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 201 && $httpCode !== 200) {
    error_log('[tool-lead] Supabase insert failed: ' . $response);
    echo json_encode(['success'=>false,'error'=>'Database error']);
    exit;
}

$adminSubject = "[Tool Lead] {$name} — {$tool}";
$adminBody = "Hi Shibaji,\n\nNew lead from Developer Tools:\n\nName: {$name}\nEmail: {$email}\nTool: {$tool}\nIP: {$ip}\nTime: " . date('c') . "\n\nView dashboard to follow up.";

$sent = sendViaGmailSMTP(SMTP_GMAIL_USER, $adminSubject, $adminBody, $email, $name);

if ($sent) {
    // Optionally send confirmation to user
    $clientSubject = "Thanks for trying {$tool}";
    $clientBody = "Hi {$name},\n\nThanks for using the {$tool} free tool.\n\nI'll send you the 5-part 'Deploy to $5 VPS' mini course shortly.\n\nBest,\nShibaji Debnath\nhttps://shibajidebnath.com";
    @sendViaGmailSMTP($email, $clientSubject, $clientBody, SMTP_GMAIL_USER, 'Shibaji Debnath');
    echo json_encode(['success'=>true]);
} else {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed to send email']);
}
