<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/../includes/csrf.php';
$config = require __DIR__ . '/../site-config.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if ($config['accounts_api'] === '') {
    respond(503, ['ok' => false, 'error' => 'login_disabled']);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    respond(403, ['ok' => false, 'error' => 'bad_csrf']);
}

$name = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $name) || strlen($password) < 1 || strlen($password) > 256) {
    respond(401, ['ok' => false, 'error' => 'invalid_credentials']);
}

$ch = curl_init(rtrim($config['accounts_api'], '/') . '/login');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['name' => $name, 'password' => $password]),
    CURLOPT_HTTPHEADER => ['X-Chronicles-Client-IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($response === false || $status === 0) {
    respond(502, ['ok' => false, 'error' => 'server_unreachable']);
}

$profile = json_decode($response, true);
if ($status !== 200 || !is_array($profile) || ($profile['ok'] ?? false) !== true || !is_string($profile['account'] ?? null)) {
    respond($status === 429 ? 429 : 401, ['ok' => false, 'error' => $status === 429 ? 'rate_limited' : 'invalid_credentials']);
}

session_regenerate_id(true);
$_SESSION['account'] = $profile['account'];
$_SESSION['authenticated_at'] = time();
respond(200, $profile);
