<?php

declare(strict_types=1);

const CHRONICLES_STRIPE_API = 'https://api.stripe.com/v1';

function stripe_secret_key(): string
{
    return (string) (getenv('CHRONICLES_STRIPE_SECRET_KEY') ?: '');
}

function stripe_webhook_secret(): string
{
    return (string) (getenv('CHRONICLES_STRIPE_WEBHOOK_SECRET') ?: '');
}

function stripe_is_configured(): bool
{
    return stripe_secret_key() !== '' && stripe_webhook_secret() !== '';
}

function stripe_request(string $method, string $path, array $fields = [], ?string $idempotencyKey = null): ?array
{
    $secret = stripe_secret_key();
    if ($secret === '') {
        return null;
    }
    $headers = ['Authorization: Bearer ' . $secret, 'Content-Type: application/x-www-form-urlencoded'];
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    $ch = curl_init(CHRONICLES_STRIPE_API . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($fields) : null,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        error_log('[chronicles-stripe] request failed status=' . $status . ' path=' . $path);
        return null;
    }
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}

function stripe_create_checkout(array $order): ?array
{
    $fields = [
        'mode' => 'payment',
        'payment_method_types[0]' => 'card',
        'success_url' => donation_base_url() . '/donation-success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => donation_base_url() . '/panel.php?payment=cancelled#shop',
        'client_reference_id' => $order['orderId'],
        'metadata[order_id]' => $order['orderId'],
        'metadata[package_id]' => $order['packageId'],
        'line_items[0][price_data][currency]' => $order['currency'],
        'line_items[0][price_data][product_data][name]' => number_format((int) $order['crystals'], 0, '', '.') . ' Sacred Relic Crystals',
        'line_items[0][price_data][product_data][description]' => 'Pacote digital para o servidor Chronicles',
        'line_items[0][price_data][unit_amount]' => (string) $order['amountCents'],
        'line_items[0][quantity]' => '1',
    ];
    return stripe_request('POST', '/checkout/sessions', $fields, 'chronicles-' . $order['orderId']);
}

function stripe_expire_checkout(string $sessionId): void
{
    stripe_request('POST', '/checkout/sessions/' . rawurlencode($sessionId) . '/expire');
}

function stripe_verify_webhook(string $payload, string $signatureHeader): ?array
{
    $secret = stripe_webhook_secret();
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
        if ($key === 't') {
            $timestamp = ctype_digit($value) ? (int) $value : null;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }
    if ($secret === '' || $timestamp === null || abs(time() - $timestamp) > 300 || $signatures === []) {
        return null;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    $valid = false;
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        return null;
    }
    $event = json_decode($payload, true);
    return is_array($event) ? $event : null;
}
