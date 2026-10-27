<?php

declare(strict_types=1);

require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/donations.php';
require __DIR__ . '/../includes/stripe.php';
site_session_start();

function checkout_fail(string $reason): never
{
    error_log('[chronicles-donation] checkout failed: ' . $reason);
    header('Location: ../panel.php?payment=error#shop', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? null)) {
    checkout_fail('invalid request');
}
$account = $_SESSION['account'] ?? null;
$packageId = (string) ($_POST['package_id'] ?? '');
$roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_string($account) || $roleId === false || $roleId <= 0 || !isset(donation_packages()[$packageId])) {
    checkout_fail('invalid order');
}
$order = donation_api_request('POST', 'prepare', [
    'account' => $account,
    'role' => $roleId,
    'package' => $packageId,
]);
if (!is_array($order) || ($order['ok'] ?? false) !== true || !is_string($order['orderId'] ?? null)) {
    checkout_fail('prepare');
}
$session = stripe_create_checkout($order);
if (!is_array($session) || !is_string($session['id'] ?? null) || !is_string($session['url'] ?? null)) {
    checkout_fail('stripe session');
}
$attached = donation_api_request('POST', 'attach', [
    'order_id' => $order['orderId'],
    'session_id' => $session['id'],
]);
if ($attached === null) {
    stripe_expire_checkout($session['id']);
    checkout_fail('attach');
}
header('Location: ' . $session['url'], true, 303);
exit;
