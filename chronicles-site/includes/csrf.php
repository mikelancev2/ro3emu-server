<?php

declare(strict_types=1);

/*
 * A plain session-bound CSRF token: generated once per browser session, embedded as a hidden form field,
 * checked before any state-changing request (account registration). The username-availability check is a
 * read, so it does not need this.
 */

function site_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('chronicles_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function csrf_token(): string
{
    site_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    site_session_start();
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
