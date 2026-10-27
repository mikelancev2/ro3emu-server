<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/../includes/csrf.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    respond(403, ['ok' => false, 'error' => 'bad_csrf']);
}
unset($_SESSION['account'], $_SESSION['authenticated_at']);
session_regenerate_id(true);
respond(200, ['ok' => true]);
