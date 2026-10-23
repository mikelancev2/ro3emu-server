<?php

declare(strict_types=1);

/*
 * Proxies account creation to the game server's loopback-only API (never reachable from the browser or the
 * internet directly). Passwords pass through this script but are never written anywhere by it — the game
 * server is the only place a password is ever hashed or stored.
 */

header('Content-Type: application/json; charset=utf-8');
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
    respond(503, ['ok' => false, 'error' => 'registration_disabled']);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    respond(403, ['ok' => false, 'error' => 'bad_csrf']);
}

$name = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmation = (string) ($_POST['password_confirmation'] ?? '');

if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $name)) {
    respond(400, ['ok' => false, 'error' => 'bad_name']);
}
if (strlen($password) < 8) {
    respond(400, ['ok' => false, 'error' => 'bad_password']);
}
if (!hash_equals($password, $confirmation)) {
    respond(400, ['ok' => false, 'error' => 'password_mismatch']);
}

$ch = curl_init(rtrim($config['accounts_api'], '/') . '/register');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['name' => $name, 'password' => $password]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $status === 0) {
    respond(502, ['ok' => false, 'error' => 'server_unreachable']);
}

http_response_code($status);
echo $response;
