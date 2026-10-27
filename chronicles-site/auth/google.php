<?php

declare(strict_types=1);

/*
 * Starts Google sign-in. Only reachable when CHRONICLES_GOOGLE_CLIENT_ID/CHRONICLES_GOOGLE_REDIRECT_URI are
 * set (site-config.php); otherwise the button that links here is never shown at all. The callback binds Google's
 * immutable identity to the game account only after the player chooses their Chronicles username and password.
 */

require __DIR__ . '/../includes/csrf.php';
$config = require __DIR__ . '/../site-config.php';

if ($config['google_client_id'] === '' || $config['google_redirect_uri'] === '') {
    http_response_code(404);
    exit;
}

site_session_start();
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
