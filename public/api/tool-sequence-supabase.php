<?php
declare(strict_types=1);

// Load .env
$dotenvPath = dirname(__DIR__, 2) . '/.env';
if (file_exists($dotenvPath)) {
    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || trim($line) === '') continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

require_once __DIR__ . '/send-mail.php';

$SUPABASE_URL = getenv('PUBLIC_SUPABASE_URL') ?: 'https://ojmfrxteuirqqexnheta.supabase.co';
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_KEY') ?: '';

$headers = [
    'apikey: ' . $SUPABASE_KEY,
    'Authorization: Bearer ' . $SUPABASE_KEY,
    'Content-Type: application/json'
];

function supabaseGet($url, $headers){
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}

function supabasePatch($url, $headers, $data){
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Length: '.strlen(json_encode($data))]),
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 15
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$leads = supabaseGet($SUPABASE_URL.'/rest/v1/tool_leads?select=id,email,name,tool,step,created_at&step=lt.5', $headers);

$seqRows = supabaseGet($SUPABASE_URL.'/rest/v1/tool_sequences?select=tool,steps', $headers);
$seqMap = [];
foreach($seqRows as $r){ $seqMap[$r['tool']] = json_decode($r['steps'], true); }

foreach($leads as $l){
    $steps = $seqMap[$l['tool']] ?? [];
    if(!isset($steps[$l['step']])) continue;
    $days = floor((time() - strtotime($l['created_at']))/86400);
    if($days !== (int)$l['step']) continue;
    
    $msg = $steps[$l['step']];
    $subject = str_replace('{name}', $l['name'], $msg['subject']);
    $body = str_replace('{name}', $l['name'], $msg['body']);
    $sent = sendViaGmailSMTP($l['email'], $subject, $body, SMTP_GMAIL_USER, 'Shibaji Debnath');
    if($sent){
        supabasePatch(
            $SUPABASE_URL.'/rest/v1/tool_leads?id=eq.'.$l['id'],
            $headers,
            ['step' => $l['step']+1, 'last_sent_at' => gmdate('c')]
        );
    }
}
echo "Sequence run complete\n";
