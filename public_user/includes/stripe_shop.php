<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';

function stripe_shop_cfg(): Config
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = new Config();
    }
    return $cfg;
}

function stripe_shop_is_configured(): bool
{
    $cfg = stripe_shop_cfg();
    return trim($cfg->STRIPE_SECRET_KEY) !== '' && trim($cfg->STRIPE_PUBLISHABLE_KEY) !== '';
}

function stripe_shop_publishable_key(): string
{
    return trim(stripe_shop_cfg()->STRIPE_PUBLISHABLE_KEY);
}

function stripe_shop_public_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/public_user/index.php'));
    $publicUserDir = dirname($script);
    if (basename($publicUserDir) === 'ajax') {
        $publicUserDir = dirname($publicUserDir);
    }
    return rtrim($scheme . '://' . $host . $publicUserDir, '/');
}

/** @return array<string, mixed>|null */
function stripe_shop_api_request(string $method, string $path, array $params = []): ?array
{
    $secret = trim(stripe_shop_cfg()->STRIPE_SECRET_KEY);
    if ($secret === '') {
        return null;
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $ch = curl_init();
    $method = strtoupper($method);

    if ($method === 'GET' && $params) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $secret . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    if ($code >= 400) {
        return ['error' => $data['error']['message'] ?? 'Stripe request failed.'];
    }
    return $data;
}

/**
 * @return array{ok:bool, session_id?:string, checkout_url?:string, error?:string}
 */
function stripe_shop_create_checkout_session(
    int $orderId,
    string $orderCode,
    string $productTitle,
    int $unitAmountCents,
    int $quantity,
    string $currency,
    int $buyerUserId,
    string $cancelUrl
): array {
    if (!stripe_shop_is_configured() || $orderId <= 0 || $unitAmountCents <= 0) {
        return ['ok' => false, 'error' => 'Stripe is not configured.'];
    }

    $currency = strtolower(trim($currency) ?: 'usd');
    $quantity = max(1, min(99, $quantity));
    $base = stripe_shop_public_base_url();

    $params = [
        'mode' => 'payment',
        'success_url' => $base . '/shop_checkout_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $cancelUrl !== '' ? $cancelUrl : ($base . '/my_orders.php?checkout=cancel'),
        'client_reference_id' => $orderCode,
        'metadata[order_id]' => (string)$orderId,
        'metadata[order_code]' => $orderCode,
        'metadata[buyer_user_id]' => (string)$buyerUserId,
        'line_items[0][quantity]' => (string)$quantity,
        'line_items[0][price_data][currency]' => $currency,
        'line_items[0][price_data][unit_amount]' => (string)$unitAmountCents,
        'line_items[0][price_data][product_data][name]' => mb_substr($productTitle, 0, 120),
    ];

    $session = stripe_shop_api_request('POST', 'checkout/sessions', $params);
    if (!$session || !empty($session['error'])) {
        return ['ok' => false, 'error' => (string)($session['error'] ?? 'Could not start checkout.')];
    }

    $sessionId = trim((string)($session['id'] ?? ''));
    $checkoutUrl = trim((string)($session['url'] ?? ''));
    if ($sessionId === '' || $checkoutUrl === '') {
        return ['ok' => false, 'error' => 'Invalid Stripe session response.'];
    }

    return ['ok' => true, 'session_id' => $sessionId, 'checkout_url' => $checkoutUrl];
}

/** @return array<string, mixed>|null */
function stripe_shop_retrieve_session(string $sessionId): ?array
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return null;
    }
    $data = stripe_shop_api_request('GET', 'checkout/sessions/' . rawurlencode($sessionId), ['expand[]' => 'payment_intent']);
    return is_array($data) ? $data : null;
}

function stripe_shop_verify_webhook(string $payload, string $sigHeader): bool
{
    $secret = trim(stripe_shop_cfg()->STRIPE_WEBHOOK_SECRET);
    if ($secret === '' || $payload === '' || $sigHeader === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $sigHeader) as $piece) {
        $kv = explode('=', trim($piece), 2);
        if (count($kv) === 2) {
            $parts[$kv[0]] = $kv[1];
        }
    }

    $timestamp = (string)($parts['t'] ?? '');
    $signature = (string)($parts['v1'] ?? '');
    if ($timestamp === '' || $signature === '') {
        return false;
    }

    if (abs(time() - (int)$timestamp) > 300) {
        return false;
    }

    $signed = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    return hash_equals($expected, $signature);
}
