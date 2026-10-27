<?php

declare(strict_types=1);

/*
 * Google's redirect back. A verified immutable Google subject either resolves to its already linked Chronicles
 * account or remains in this browser session until the normal registration form creates that account atomically.
 * OAuth tokens are discarded immediately and never reach the game database.
 */

require __DIR__ . '/../includes/csrf.php';
$config = require __DIR__ . '/../site-config.php';

if ($config['google_client_id'] === '' || $config['google_client_secret'] === '' || $config['google_redirect_uri'] === ''
    || $config['accounts_api'] === '') {
    http_response_code(404);
    exit;
}

site_session_start();

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
$tokenStatus = curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
curl_close($tokenCh);
$token = is_string($tokenResponse) ? json_decode($tokenResponse, true) : null;
$accessToken = is_array($token) ? ($token['access_token'] ?? null) : null;

if ($tokenStatus !== 200 || !is_string($accessToken)) {
    failBack();
}

$userCh = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
curl_setopt_array($userCh, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$userResponse = curl_exec($userCh);
$userStatus = curl_getinfo($userCh, CURLINFO_HTTP_CODE);
curl_close($userCh);
$profile = is_string($userResponse) ? json_decode($userResponse, true) : null;
$email = is_array($profile) && ($profile['email_verified'] ?? false) === true ? ($profile['email'] ?? null) : null;
$subject = is_array($profile) ? ($profile['sub'] ?? null) : null;

if ($userStatus !== 200 || !is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !is_string($subject) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/', $subject)) {
    failBack();
}

$lookup = curl_init(rtrim($config['accounts_api'], '/') . '/google?subject=' . rawurlencode($subject));
curl_setopt_array($lookup, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
$lookupResponse = curl_exec($lookup);
$lookupStatus = curl_getinfo($lookup, CURLINFO_HTTP_CODE);
curl_close($lookup);
$identity = is_string($lookupResponse) ? json_decode($lookupResponse, true) : null;
if ($lookupStatus === 200 && is_array($identity) && ($identity['ok'] ?? false) === true && is_string($identity['account'] ?? null)) {
    session_regenerate_id(true);
    $_SESSION['account'] = $identity['account'];
    $_SESSION['authenticated_at'] = time();
    unset($_SESSION['google_identity']);
    header('Location: /panel.php');
    exit;
}
if ($lookupStatus !== 404) {
    failBack();
}

$_SESSION['google_identity'] = [
    'provider' => 'google',
    'subject' => $subject,
    'email' => strtolower($email),
];
header('Location: /?open_account=1');
exit;
