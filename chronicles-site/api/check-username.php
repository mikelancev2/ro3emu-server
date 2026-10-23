<?php

declare(strict_types=1);

/*
 * Read-only proxy for the "is this name taken?" check (the Verify button). No CSRF: it changes nothing,
 * so the usual game-server rate limit is the only protection it needs.
 */

header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/../site-config.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['available' => false, 'error' => 'method_not_allowed']);
}
if ($config['accounts_api'] === '') {
    respond(503, ['available' => false, 'error' => 'registration_disabled']);
}

$name = trim((string) ($_GET['name'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $name)) {
    respond(400, ['available' => false, 'error' => 'bad_name']);
}

$ch = curl_init(rtrim($config['accounts_api'], '/') . '/available?name=' . urlencode($name));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $status === 0) {
    respond(502, ['available' => false, 'error' => 'server_unreachable']);
}

http_response_code($status);
echo $response;
