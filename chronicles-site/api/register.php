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
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$confirmation = (string) ($_POST['password_confirmation'] ?? '');
$google = $_SESSION['google_identity'] ?? null;
$googleSubject = null;
if (is_array($google) && ($google['provider'] ?? null) === 'google' && is_string($google['subject'] ?? null)
    && is_string($google['email'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,255}$/', $google['subject'])
    && filter_var($google['email'], FILTER_VALIDATE_EMAIL)) {
    $googleSubject = $google['subject'];
    $email = strtolower($google['email']);
}

if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $name)) {
    respond(400, ['ok' => false, 'error' => 'bad_name']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    respond(400, ['ok' => false, 'error' => 'bad_email']);
}
if (strlen($password) < 8 || strlen($password) > 256) {
    respond(400, ['ok' => false, 'error' => 'bad_password']);
}
if (!hash_equals($password, $confirmation)) {
    respond(400, ['ok' => false, 'error' => 'password_mismatch']);
}

$ch = curl_init(rtrim($config['accounts_api'], '/') . '/register');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'google_subject' => $googleSubject ?? '',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $status === 0) {
    respond(502, ['ok' => false, 'error' => 'server_unreachable']);
}

$body = is_string($response) ? json_decode($response, true) : null;
if ($status === 200 && is_array($body) && ($body['ok'] ?? false) === true && is_string($body['account'] ?? null)) {
    session_regenerate_id(true);
    $_SESSION['account'] = $body['account'];
    $_SESSION['authenticated_at'] = time();
    unset($_SESSION['google_identity']);
}

http_response_code($status);
echo $response;
