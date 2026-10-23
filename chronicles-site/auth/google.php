<?php

declare(strict_types=1);

/*
 * Starts Google sign-in. Only reachable when CHRONICLES_GOOGLE_CLIENT_ID/CHRONICLES_GOOGLE_REDIRECT_URI are
 * set (site-config.php); otherwise the button that links here is never shown at all. Google only verifies
 * the player is a real person with that email — the actual game account is still created on the normal
 * registration form (see google-callback.php), nothing is stored here or there about the Google identity.
 */

$config = require __DIR__ . '/../site-config.php';

if ($config['google_client_id'] === '' || $config['google_redirect_uri'] === '') {
    http_response_code(404);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = http_build_query([
    'client_id' => $config['google_client_id'],
    'redirect_uri' => $config['google_redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email',
    'state' => $state,
    'prompt' => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
