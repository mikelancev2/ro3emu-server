<?php

declare(strict_types=1);

function donation_packages(): array
{
    return [
        'crystals-500' => ['crystals' => 500, 'amount_cents' => 990],
        'crystals-1000' => ['crystals' => 1000, 'amount_cents' => 1890],
        'crystals-2500' => ['crystals' => 2500, 'amount_cents' => 4490],
        'crystals-5000' => ['crystals' => 5000, 'amount_cents' => 8490],
        'crystals-10000' => ['crystals' => 10000, 'amount_cents' => 15990],
        'crystals-20000' => ['crystals' => 20000, 'amount_cents' => 29990],
    ];
}

function donation_api_request(string $method, string $path, array $fields = []): ?array
{
    $config = require __DIR__ . '/../site-config.php';
    if ($config['accounts_api'] === '') {
        return null;
    }
    $url = rtrim($config['accounts_api'], '/') . '/donations/' . ltrim($path, '/');
    if ($method === 'GET' && $fields !== []) {
        $url .= '?' . http_build_query($fields);
    }
    $ch = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        return null;
    }
    $decoded = $body === '' ? [] : json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}

function donation_base_url(): string
{
    return 'https://chronicles.com.br';
}
