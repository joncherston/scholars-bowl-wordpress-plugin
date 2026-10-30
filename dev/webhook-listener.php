<?php
/**
 * A stand-in for the scoring app's /api/wordpress/notify endpoint, for local testing.
 * Verifies the signature the plugin sends and logs each message as one JSON line.
 *
 *   SBR_WEBHOOK_KEY=<value of option sbr_api_token_hash> SBR_WEBHOOK_LOG=/tmp/notify.log \
 *     php -S localhost:8091 dev/webhook-listener.php
 *
 * Verification is what the scoring app should do:
 *   expected = "sha256=" . hex(HMAC-SHA256(key, "<X-SBR-Timestamp>.<raw body>"))
 *   compare in constant time; reject timestamps more than 5 minutes from now.
 */

$key = (string) getenv('SBR_WEBHOOK_KEY');
$log = getenv('SBR_WEBHOOK_LOG') ?: '/tmp/sbr-notify.log';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
$ts = isset($_SERVER['HTTP_X_SBR_TIMESTAMP']) ? $_SERVER['HTTP_X_SBR_TIMESTAMP'] : '';
$sig = isset($_SERVER['HTTP_X_SBR_SIGNATURE']) ? $_SERVER['HTTP_X_SBR_SIGNATURE'] : '';
$expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $key);
$fresh = ctype_digit($ts) && abs(time() - intval($ts)) <= 300;
$valid = $key !== '' && hash_equals($expected, $sig) && $fresh;
$json = json_decode($body, true);

file_put_contents($log, json_encode(array(
    'received_at' => gmdate('c'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'content_type' => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
    'signature_valid' => $valid,
    'fresh' => $fresh,
    'payload' => $json,
)) . "\n", FILE_APPEND | LOCK_EX);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/api/wordpress/notify') {
    http_response_code(404);
    echo 'not found';
    return true;
}
http_response_code($valid ? 200 : 401);
header('Content-Type: application/json');
echo json_encode(array('ok' => $valid));
return true;
