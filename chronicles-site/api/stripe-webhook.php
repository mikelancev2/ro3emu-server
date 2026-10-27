<?php

declare(strict_types=1);

require __DIR__ . '/../includes/donations.php';
require __DIR__ . '/../includes/stripe.php';

$payload = file_get_contents('php://input');
$event = is_string($payload) ? stripe_verify_webhook($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '')) : null;
if ($event === null) {
    http_response_code(400);
    exit;
}
if (($event['type'] ?? '') !== 'checkout.session.completed') {
    http_response_code(200);
    exit;
}
$session = $event['data']['object'] ?? null;
$metadata = is_array($session) ? ($session['metadata'] ?? null) : null;
if (!is_array($session) || !is_array($metadata) || ($session['payment_status'] ?? '') !== 'paid') {
    http_response_code(200);
    exit;
}
$completed = donation_api_request('POST', 'complete', [
    'session_id' => (string) ($session['id'] ?? ''),
    'order_id' => (string) ($metadata['order_id'] ?? ''),
    'package_id' => (string) ($metadata['package_id'] ?? ''),
    'payment_intent' => (string) ($session['payment_intent'] ?? ''),
    'event_id' => (string) ($event['id'] ?? ''),
    'amount_cents' => (string) ($session['amount_total'] ?? ''),
    'currency' => (string) ($session['currency'] ?? ''),
]);
http_response_code($completed === null ? 500 : 200);
