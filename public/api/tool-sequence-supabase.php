<?php
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

require_once __DIR__ . '/send-mail.php';

$SUPABASE_URL = env('PUBLIC_SUPABASE_URL', '');
$SUPABASE_KEY = env('SUPABASE_SERVICE_KEY', '');

// Without a service key every Supabase call answers with an error object rather
// than a row list. Iterating that object gave strings where arrays were
// expected and killed the script with a TypeError, so refuse to run instead.
if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    error_log('[Supabase] PUBLIC_SUPABASE_URL or SUPABASE_SERVICE_KEY is not set.');
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'error',
        'code' => 'supabase_config_incomplete',
        'message' => 'Lead sequences are not configured on the server.',
        'missing' => array_values(array_filter([
            $SUPABASE_URL === '' ? 'PUBLIC_SUPABASE_URL' : null,
            $SUPABASE_KEY === '' ? 'SUPABASE_SERVICE_KEY' : null,
        ])),
    ]);
    exit;
}

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
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        error_log('[Supabase] request failed: ' . $err . ' — ' . $url);
        return [];
    }

    $decoded = json_decode((string)$res, true);

    // PostgREST reports problems as {"message": "..."} or {"code": "..."} in the
    // same 200 response it uses for data. Treat those as a failure, not rows,
    // or the caller iterates over scalar values.
    if (!is_array($decoded)) {
        return [];
    }
    if (isset($decoded['message']) || isset($decoded['code'])) {
        error_log('[Supabase] ' . $url . ' -> ' . substr((string)$res, 0, 300));
        return [];
    }

    return $decoded;
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
// Only list-shaped payloads are rows; anything else is skipped rather than
// indexed blindly.
foreach(array_filter($seqRows, 'is_array') as $r){
    // Supabase returns `steps` as jsonb, so PHP usually hands it back already
    // decoded as an array. json_decode() only accepts a string, so passing an
    // array raised a TypeError and killed the whole script with a 500. Accept
    // either shape.
    $steps = is_array($r['steps']) ? $r['steps'] : json_decode((string)$r['steps'], true);
    $seqMap[$r['tool']] = is_array($steps) ? $steps : [];
}

foreach(array_filter($leads, 'is_array') as $l){
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
