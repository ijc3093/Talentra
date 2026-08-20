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

/**
 * One Stripe Checkout session covering multiple cart orders (same currency).
 *
 * @param list<array{order_id:int,order_code:string,title:string,unit_cents:int,quantity:int,currency:string}> $lines
 * @return array{ok:bool, session_id?:string, checkout_url?:string, error?:string}
 */
function stripe_shop_create_multi_order_checkout_session(
    array $lines,
    int $buyerUserId,
    string $cancelUrl
): array {
    if (!stripe_shop_is_configured() || $lines === [] || $buyerUserId <= 0) {
        return ['ok' => false, 'error' => 'Stripe is not configured.'];
    }

    $orderIds = [];
    $currency = '';
    $params = [
        'mode' => 'payment',
        'success_url' => stripe_shop_public_base_url() . '/shop_checkout_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $cancelUrl !== '' ? $cancelUrl : (stripe_shop_public_base_url() . '/cart.php?checkout=cancel'),
        'metadata[buyer_user_id]' => (string)$buyerUserId,
        'metadata[kind]' => 'cart_multi',
    ];

    $i = 0;
    foreach ($lines as $line) {
        $orderId = (int)($line['order_id'] ?? 0);
        $unit = (int)($line['unit_cents'] ?? 0);
        $qty = max(1, min(99, (int)($line['quantity'] ?? 1)));
        $cur = strtolower(trim((string)($line['currency'] ?? 'usd')) ?: 'usd');
        $title = mb_substr(trim((string)($line['title'] ?? 'Order')), 0, 120);
        if ($orderId <= 0 || $unit <= 0) {
            continue;
        }
        if ($currency === '') {
            $currency = $cur;
        } elseif ($currency !== $cur) {
            return ['ok' => false, 'error' => 'mixed_currency'];
        }
        $orderIds[] = $orderId;
        $params["line_items[{$i}][quantity]"] = (string)$qty;
        $params["line_items[{$i}][price_data][currency]"] = $currency;
        $params["line_items[{$i}][price_data][unit_amount]"] = (string)$unit;
        $params["line_items[{$i}][price_data][product_data][name]"] = $title !== '' ? $title : ('Order #' . $orderId);
        $i++;
    }

    if ($orderIds === [] || $i === 0) {
        return ['ok' => false, 'error' => 'No payable lines.'];
    }

    $params['client_reference_id'] = 'cart-' . implode('-', array_slice($orderIds, 0, 4));
    $params['metadata[order_id]'] = (string)$orderIds[0];
    $params['metadata[order_ids]'] = implode(',', $orderIds);

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

/**
 * Seller monthly shop-rent Checkout (organization workspace pays the mall).
 *
 * @return array{ok:bool, session_id?:string, checkout_url?:string, error?:string}
 */
function stripe_shop_create_rent_checkout_session(
    int $orgId,
    int $planId,
    string $planName,
    int $unitAmountCents,
    int $months,
    string $currency,
    string $successUrl,
    string $cancelUrl
): array {
    if (!stripe_shop_is_configured() || $orgId <= 0 || $planId <= 0 || $unitAmountCents <= 0) {
        return ['ok' => false, 'error' => 'Stripe is not configured for rent payments.'];
    }

    $months = max(1, min(12, $months));
    $currency = strtolower(trim($currency) ?: 'usd');
    $planName = mb_substr(trim($planName) !== '' ? $planName : 'Shop rent', 0, 100);

    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => 'rent-' . $orgId . '-' . $planId,
        'metadata[kind]' => 'shop_rent',
        'metadata[org_id]' => (string)$orgId,
        'metadata[plan_id]' => (string)$planId,
        'metadata[months]' => (string)$months,
        'line_items[0][quantity]' => (string)$months,
        'line_items[0][price_data][currency]' => $currency,
        'line_items[0][price_data][unit_amount]' => (string)$unitAmountCents,
        'line_items[0][price_data][product_data][name]' => $planName . ' — shop rent',
        'line_items[0][price_data][product_data][description]' => $months . ' month' . ($months === 1 ? '' : 's') . ' platform shop rent',
    ];

    $session = stripe_shop_api_request('POST', 'checkout/sessions', $params);
    if (!$session || !empty($session['error'])) {
        return ['ok' => false, 'error' => (string)($session['error'] ?? 'Could not start rent checkout.')];
    }

    $sessionId = trim((string)($session['id'] ?? ''));
    $checkoutUrl = trim((string)($session['url'] ?? ''));
    if ($sessionId === '' || $checkoutUrl === '') {
        return ['ok' => false, 'error' => 'Invalid Stripe session response.'];
    }

    return ['ok' => true, 'session_id' => $sessionId, 'checkout_url' => $checkoutUrl];
}

/**
 * Customer Plus membership Checkout ($10/month).
 *
 * @return array{ok:bool, session_id?:string, checkout_url?:string, error?:string}
 */
function stripe_shop_create_membership_checkout_session(
    int $userId,
    int $months,
    string $successUrl,
    string $cancelUrl
): array {
    if (!stripe_shop_is_configured() || $userId <= 0) {
        return ['ok' => false, 'error' => 'Stripe is not configured for membership.'];
    }

    $months = max(1, min(12, $months));
    $unitAmount = 1000; // $10.00
    if (function_exists('buyer_membership_price_cents')) {
        $unitAmount = max(1, buyer_membership_price_cents());
    }

    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => 'membership-' . $userId,
        'metadata[kind]' => 'buyer_membership',
        'metadata[user_id]' => (string)$userId,
        'metadata[months]' => (string)$months,
        'line_items[0][quantity]' => (string)$months,
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => (string)$unitAmount,
        'line_items[0][price_data][product_data][name]' => 'Customer Plus membership',
        'line_items[0][price_data][product_data][description]' => $months . ' month' . ($months === 1 ? '' : 's') . ' — $0 service fee on shop orders',
    ];

    $session = stripe_shop_api_request('POST', 'checkout/sessions', $params);
    if (!$session || !empty($session['error'])) {
        return ['ok' => false, 'error' => (string)($session['error'] ?? 'Could not start membership checkout.')];
    }

    $sessionId = trim((string)($session['id'] ?? ''));
    $checkoutUrl = trim((string)($session['url'] ?? ''));
    if ($sessionId === '' || $checkoutUrl === '') {
        return ['ok' => false, 'error' => 'Invalid Stripe session response.'];
    }

    return ['ok' => true, 'session_id' => $sessionId, 'checkout_url' => $checkoutUrl];
}

/** Absolute URL helper for public_user pages. */
function stripe_shop_public_user_base_url(): string
{
    return stripe_shop_public_base_url();
}

/** Absolute URL for organization workspace pages (rent checkout return). */
function stripe_shop_organization_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/organization/shop_rent.php'));
    $dir = dirname($script);
    if (basename($dir) === 'ajax') {
        $dir = dirname($dir);
    }
    // If called from public_user, point at sibling organization folder.
    if (basename($dir) === 'public_user') {
        $dir = dirname($dir) . '/organization';
    }
    return rtrim($scheme . '://' . $host . $dir, '/');
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

/**
 * Create a Stripe Connect Express account for a seller org.
 *
 * @return array{ok:bool,account_id?:string,error?:string}
 */
function stripe_shop_connect_create_express_account(string $email, string $country = 'US'): array
{
    if (!stripe_shop_is_configured()) {
        return ['ok' => false, 'error' => 'Stripe is not configured.'];
    }
    $email = trim($email);
    $country = strtoupper(trim($country) ?: 'US');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'A valid seller email is required for Connect.'];
    }
    $params = [
        'type' => 'express',
        'country' => $country,
        'email' => $email,
        'capabilities[card_payments][requested]' => 'true',
        'capabilities[transfers][requested]' => 'true',
        'business_type' => 'individual',
    ];
    $account = stripe_shop_api_request('POST', 'accounts', $params);
    if (!$account || !empty($account['error'])) {
        return ['ok' => false, 'error' => (string)($account['error'] ?? 'Could not create Connect account.')];
    }
    $accountId = trim((string)($account['id'] ?? ''));
    if ($accountId === '') {
        return ['ok' => false, 'error' => 'Invalid Connect account response.'];
    }
    return ['ok' => true, 'account_id' => $accountId];
}

/**
 * @return array{ok:bool,url?:string,error?:string}
 */
function stripe_shop_connect_account_link(string $accountId, string $refreshUrl, string $returnUrl): array
{
    if (!stripe_shop_is_configured() || trim($accountId) === '') {
        return ['ok' => false, 'error' => 'Stripe Connect is not ready.'];
    }
    $params = [
        'account' => $accountId,
        'refresh_url' => $refreshUrl,
        'return_url' => $returnUrl,
        'type' => 'account_onboarding',
    ];
    $link = stripe_shop_api_request('POST', 'account_links', $params);
    if (!$link || !empty($link['error'])) {
        return ['ok' => false, 'error' => (string)($link['error'] ?? 'Could not start onboarding.')];
    }
    $url = trim((string)($link['url'] ?? ''));
    if ($url === '') {
        return ['ok' => false, 'error' => 'Invalid onboarding link.'];
    }
    return ['ok' => true, 'url' => $url];
}

/** @return array<string, mixed>|null */
function stripe_shop_connect_retrieve_account(string $accountId): ?array
{
    $accountId = trim($accountId);
    if ($accountId === '' || !stripe_shop_is_configured()) {
        return null;
    }
    $data = stripe_shop_api_request('GET', 'accounts/' . rawurlencode($accountId));
    return is_array($data) && empty($data['error']) ? $data : null;
}

/**
 * Transfer seller payout to a connected account after platform capture.
 *
 * @return array{ok:bool,transfer_id?:string,error?:string}
 */
function stripe_shop_connect_create_transfer(
    int $amountCents,
    string $currency,
    string $destinationAccountId,
    string $transferGroup = '',
    array $metadata = []
): array {
    if (!stripe_shop_is_configured() || $amountCents <= 0 || trim($destinationAccountId) === '') {
        return ['ok' => false, 'error' => 'Transfer not available.'];
    }
    $currency = strtolower(trim($currency) ?: 'usd');
    $params = [
        'amount' => (string)$amountCents,
        'currency' => $currency,
        'destination' => $destinationAccountId,
    ];
    if ($transferGroup !== '') {
        $params['transfer_group'] = mb_substr($transferGroup, 0, 100);
    }
    foreach ($metadata as $k => $v) {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
        if ($key === '') {
            continue;
        }
        $params['metadata[' . $key . ']'] = mb_substr((string)$v, 0, 500);
    }
    $transfer = stripe_shop_api_request('POST', 'transfers', $params);
    if (!$transfer || !empty($transfer['error'])) {
        return ['ok' => false, 'error' => (string)($transfer['error'] ?? 'Transfer failed.')];
    }
    $tid = trim((string)($transfer['id'] ?? ''));
    if ($tid === '') {
        return ['ok' => false, 'error' => 'Invalid transfer response.'];
    }
    return ['ok' => true, 'transfer_id' => $tid];
}
