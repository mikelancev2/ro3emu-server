<?php

declare(strict_types=1);

/*
 * Google's redirect back. Verifies the CSRF state, exchanges the code for a verified email, then forgets
 * everything about Google: the email only pre-fills the normal registration form (index.php reads
 * $_SESSION['prefill_email'] once and unsets it). No Google identity, token or link is ever written to
 * disk or to the game server.
 */

$config = require __DIR__ . '/../site-config.php';

if ($config['google_client_id'] === '' || $config['google_client_secret'] === '' || $config['google_redirect_uri'] === '') {
    http_response_code(404);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function failBack(): never
{
    header('Location: /?google_error=1');
    exit;
}

$state = $_GET['state'] ?? null;
$code = $_GET['code'] ?? null;
$expectedState = $_SESSION['google_oauth_state'] ?? null;
unset($_SESSION['google_oauth_state']);

if (!is_string($state) || !is_string($expectedState) || !hash_equals($expectedState, $state) || !is_string($code)) {
    failBack();
}

$tokenCh = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($tokenCh, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'code' => $code,
        'client_id' => $config['google_client_id'],
        'client_secret' => $config['google_client_secret'],
        'redirect_uri' => $config['google_redirect_uri'],
        'grant_type' => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$tokenResponse = curl_exec($tokenCh);
curl_close($tokenCh);
$token = is_string($tokenResponse) ? json_decode($tokenResponse, true) : null;
$accessToken = is_array($token) ? ($token['access_token'] ?? null) : null;

if (!is_string($accessToken)) {
    failBack();
}

$userCh = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
curl_setopt_array($userCh, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$userResponse = curl_exec($userCh);
curl_close($userCh);
$profile = is_string($userResponse) ? json_decode($userResponse, true) : null;
$email = is_array($profile) && !empty($profile['email_verified']) ? ($profile['email'] ?? null) : null;

if (!is_string($email)) {
    failBack();
}

$_SESSION['prefill_email'] = $email;
header('Location: /?open_account=1');
exit;
