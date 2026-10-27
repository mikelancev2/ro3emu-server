<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/../includes/csrf.php';
$config = require __DIR__ . '/../site-config.php';
site_session_start();

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (!is_string($_SESSION['account'] ?? null) || time() - (int) ($_SESSION['authenticated_at'] ?? 0) > 43200) {
    unset($_SESSION['account'], $_SESSION['authenticated_at']);
    respond(401, ['ok' => false, 'error' => 'not_authenticated']);
}
if ($config['accounts_api'] === '') {
    respond(503, ['ok' => false, 'error' => 'profile_disabled']);
}

$url = rtrim($config['accounts_api'], '/') . '/profile?account=' . rawurlencode($_SESSION['account']);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($response === false || $status === 0) {
    respond(502, ['ok' => false, 'error' => 'server_unreachable']);
}
http_response_code($status);
echo $response;
