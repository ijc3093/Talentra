<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/../public_user/includes/platform_rent.php';
require_once dirname(__DIR__) . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/org_crm.php';

function org_ecommerce_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function org_ecommerce_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    org_crm_ensure_schema($dbh);
    // Delegate to shared shop ensure so seller + buyer stay on the same migration set.
    org_shop_ensure_schema($dbh);
}

/** @return array<string, string> */
function org_ecommerce_business_models(): array
{
    return [
        'retail' => 'B2C Retail',
        'wholesale' => 'B2B Wholesale',
        'manufacturing' => 'Manufacturing',
        'services' => 'Services',
        'subscription' => 'Subscription',
        'licensing' => 'Licensing',
        'advertising' => 'Advertising / Affiliate',
    ];
}

function org_ecommerce_get_business_model(PDO $dbh, int $orgId): string
{
    if ($orgId <= 0) {
        return 'retail';
    }
    try {
        $st = $dbh->prepare('SELECT business_model FROM organizations WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orgId]);
        $m = strtolower(trim((string)($st->fetchColumn() ?: 'retail')));
        return array_key_exists($m, org_ecommerce_business_models()) ? $m : 'retail';
    } catch (Throwable $e) {
        return 'retail';
    }
}

function org_ecommerce_save_business_model(PDO $dbh, int $orgId, string $model): bool
{
    $models = org_ecommerce_business_models();
    $model = strtolower(trim($model));
    if ($orgId <= 0 || !isset($models[$model])) {
        return false;
    }
    try {
        $st = $dbh->prepare('UPDATE organizations SET business_model = :m, updated_at = NOW() WHERE id = :id LIMIT 1');
        $st->execute([':m' => $model, ':id' => $orgId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string, mixed> */
function org_ecommerce_default_shop_settings(): array
{
    return [
        'store_name' => '',
        'full_name' => '',
        'tagline' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'payment_methods' => ['stripe', 'manual'],
        'delivery_notes' => '',
        'fulfillment_policy' => '',
        'return_policy' => '',
        'default_fulfillment_method' => 'fbm',
        'channels' => [
            'profile_shop' => true,
            'marketplace' => true,
            'social_feed' => true,
        ],
        'seo' => [
            'meta_description' => '',
        ],
        'address' => [
            'line1' => '',
            'line2' => '',
            'city' => '',
            'state' => '',
            'postal_code' => '',
            'country' => '',
        ],
        'custom_categories' => [],
        'custom_selling_types' => [],
    ];
}

/**
 * Seed public seller info from organization + linked publisher account.
 * @return array{store_name:string,full_name:string,contact_email:string,contact_phone:string}
 */
function org_ecommerce_seller_info_seed(PDO $dbh, int $orgId): array
{
    $seed = ['store_name' => '', 'full_name' => '', 'contact_email' => '', 'contact_phone' => ''];
    if ($orgId <= 0) {
        return $seed;
    }
    try {
        $st = $dbh->prepare('SELECT name, publisher_user_id FROM organizations WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orgId]);
        $org = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $seed['store_name'] = trim((string)($org['name'] ?? ''));
        $publisherUserId = (int)($org['publisher_user_id'] ?? 0);
        if ($publisherUserId > 0) {
            require_once dirname(__DIR__) . '/../public_user/includes/user_phone.php';
            $stU = $dbh->prepare('SELECT name, email, mobile FROM users WHERE id = :id LIMIT 1');
            $stU->execute([':id' => $publisherUserId]);
            $user = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
            $seed['full_name'] = trim((string)($user['name'] ?? ''));
            $seed['contact_email'] = trim((string)($user['email'] ?? ''));
            $seed['contact_phone'] = function_exists('user_phone_from_user_row')
                ? user_phone_from_user_row($user)
                : trim((string)($user['mobile'] ?? ''));
            if (strcasecmp($seed['contact_phone'], 'N/A') === 0) {
                $seed['contact_phone'] = '';
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $seed;
}

/**
 * Shop settings with empty seller contact fields filled from registration/publisher account.
 * @return array<string, mixed>
 */
function org_ecommerce_get_shop_settings_for_display(PDO $dbh, int $orgId): array
{
    $settings = org_ecommerce_get_shop_settings($dbh, $orgId);
    $seed = org_ecommerce_seller_info_seed($dbh, $orgId);
    if (trim((string)($settings['store_name'] ?? '')) === '' && $seed['store_name'] !== '') {
        $settings['store_name'] = $seed['store_name'];
    }
    if (trim((string)($settings['full_name'] ?? '')) === '' && $seed['full_name'] !== '') {
        $settings['full_name'] = $seed['full_name'];
    }
    if (trim((string)($settings['contact_email'] ?? '')) === '' && $seed['contact_email'] !== '') {
        $settings['contact_email'] = $seed['contact_email'];
    }
    if (trim((string)($settings['contact_phone'] ?? '')) === '' && $seed['contact_phone'] !== '') {
        $settings['contact_phone'] = $seed['contact_phone'];
    }
    return $settings;
}

/** Persist seed into shop_json when seller identity fields are still blank. */
function org_ecommerce_ensure_seller_info_seeded(PDO $dbh, int $orgId): array
{
    $settings = org_ecommerce_get_shop_settings($dbh, $orgId);
    $seed = org_ecommerce_seller_info_seed($dbh, $orgId);
    $patch = [];
    if (trim((string)($settings['store_name'] ?? '')) === '' && $seed['store_name'] !== '') {
        $patch['store_name'] = $seed['store_name'];
    }
    if (trim((string)($settings['full_name'] ?? '')) === '' && !empty($seed['full_name'])) {
        $patch['full_name'] = $seed['full_name'];
    }
    if (trim((string)($settings['contact_email'] ?? '')) === '' && $seed['contact_email'] !== '') {
        $patch['contact_email'] = $seed['contact_email'];
    }
    if (trim((string)($settings['contact_phone'] ?? '')) === '' && $seed['contact_phone'] !== '') {
        $patch['contact_phone'] = $seed['contact_phone'];
    }
    if ($patch) {
        org_ecommerce_save_shop_settings($dbh, $orgId, $patch);
        return org_ecommerce_get_shop_settings($dbh, $orgId);
    }
    return $settings;
}

/**
 * Save seller profile (name, contact, address) from POST into shop_json.
 * Used by sales management and shop settings.
 *
 * @return array{ok:bool,error?:string}
 */
function org_ecommerce_save_seller_profile_from_post(PDO $dbh, int $orgId, array $post): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid shop.'];
    }
    $storeName = trim((string)($post['store_name'] ?? ''));
    $fullName = trim((string)($post['full_name'] ?? ''));
    if ($storeName === '' && $fullName !== '') {
        $storeName = $fullName;
    }
    if ($storeName === '') {
        return ['ok' => false, 'error' => 'Store / seller name is required.'];
    }
    if (mb_strlen($storeName) > 120) {
        $storeName = mb_substr($storeName, 0, 120);
    }
    if (mb_strlen($fullName) > 120) {
        $fullName = mb_substr($fullName, 0, 120);
    }
    $email = trim((string)($post['contact_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid contact email.'];
    }
    $payload = [
        'store_name' => $storeName,
        'full_name' => $fullName,
        'tagline' => trim((string)($post['tagline'] ?? '')),
        'contact_email' => $email,
        'contact_phone' => trim((string)($post['contact_phone'] ?? '')),
        'address' => [
            'line1' => trim((string)($post['address_line1'] ?? '')),
            'line2' => trim((string)($post['address_line2'] ?? '')),
            'city' => trim((string)($post['address_city'] ?? '')),
            'state' => trim((string)($post['address_state'] ?? '')),
            'postal_code' => trim((string)($post['address_postal_code'] ?? '')),
            'country' => trim((string)($post['address_country'] ?? '')),
        ],
    ];
    if (!org_ecommerce_save_shop_settings($dbh, $orgId, $payload)) {
        return ['ok' => false, 'error' => 'Could not save seller profile.'];
    }
    try {
        $st = $dbh->prepare('UPDATE organizations SET name = :name, updated_at = NOW() WHERE id = :id LIMIT 1');
        $st->execute([':name' => $storeName, ':id' => $orgId]);
    } catch (Throwable $e) {
        // ignore org rename failure
    }
    return ['ok' => true];
}

/**
 * Whether seller has a usable full address (required before creating products).
 */
function org_ecommerce_seller_has_required_address(PDO $dbh, int $orgId): bool
{
    if ($orgId <= 0) {
        return false;
    }
    $settings = org_ecommerce_get_shop_settings($dbh, $orgId);
    $addr = is_array($settings['address'] ?? null) ? $settings['address'] : [];
    return org_ecommerce_address_is_complete($addr);
}

/**
 * @param array<string,mixed> $address
 */
function org_ecommerce_address_is_complete(array $address): bool
{
    $line1 = trim((string)($address['line1'] ?? ''));
    $city = trim((string)($address['city'] ?? ''));
    $state = trim((string)($address['state'] ?? ''));
    return $line1 !== '' && $city !== '' && $state !== '';
}

/**
 * Save only the seller business address into shop_json (keeps other profile fields).
 *
 * @return array{ok:bool,error?:string,address_text?:string,address?:array<string,string>}
 */
function org_ecommerce_save_seller_address_from_post(PDO $dbh, int $orgId, array $post): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid shop.'];
    }
    $address = [
        'line1' => trim((string)($post['address_line1'] ?? '')),
        'line2' => trim((string)($post['address_line2'] ?? '')),
        'city' => trim((string)($post['address_city'] ?? '')),
        'state' => trim((string)($post['address_state'] ?? '')),
        'postal_code' => trim((string)($post['address_postal_code'] ?? '')),
        'country' => trim((string)($post['address_country'] ?? '')),
    ];
    if (!org_ecommerce_address_is_complete($address)) {
        return ['ok' => false, 'error' => 'Address line 1, city, and state are required.'];
    }
    if ($address['country'] === '') {
        $address['country'] = 'United States';
    }
    if (!org_ecommerce_save_shop_settings($dbh, $orgId, ['address' => $address])) {
        return ['ok' => false, 'error' => 'Could not save address.'];
    }
    return [
        'ok' => true,
        'address' => $address,
        'address_text' => org_ecommerce_format_seller_address($address),
    ];
}

function org_ecommerce_format_seller_address(?array $address): string
{
    if (!is_array($address)) {
        return '';
    }
    require_once dirname(__DIR__) . '/../public_user/includes/org_shop.php';
    return org_shop_format_seller_address($address);
}

/** @return array<string, mixed> */
function org_ecommerce_get_shop_settings(PDO $dbh, int $orgId): array
{
    $defaults = org_ecommerce_default_shop_settings();
    if ($orgId <= 0) {
        return $defaults;
    }
    try {
        $st = $dbh->prepare('SELECT shop_json FROM org_settings WHERE org_id = :org LIMIT 1');
        $st->execute([':org' => $orgId]);
        $raw = $st->fetchColumn();
        if (!$raw) {
            return $defaults;
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        return array_replace_recursive($defaults, $decoded);
    } catch (Throwable $e) {
        return $defaults;
    }
}

/** @param array<string, mixed> $settings */
function org_ecommerce_save_shop_settings(PDO $dbh, int $orgId, array $settings): bool
{
    if ($orgId <= 0) {
        return false;
    }
    $current = org_ecommerce_get_shop_settings($dbh, $orgId);
    $merged = array_replace_recursive($current, $settings);
    // List fields must replace, not merge by numeric index.
    if (array_key_exists('custom_categories', $settings) && is_array($settings['custom_categories'])) {
        $merged['custom_categories'] = array_values($settings['custom_categories']);
    }
    if (array_key_exists('custom_selling_types', $settings) && is_array($settings['custom_selling_types'])) {
        $merged['custom_selling_types'] = array_values($settings['custom_selling_types']);
    }
    try {
        $st = $dbh->prepare('
            INSERT INTO org_settings (org_id, shop_json, updated_at)
            VALUES (:org, :json, NOW())
            ON DUPLICATE KEY UPDATE shop_json = VALUES(shop_json), updated_at = NOW()
        ');
        $st->execute([
            ':org' => $orgId,
            ':json' => json_encode($merged, JSON_UNESCAPED_UNICODE),
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Seller-only category names stored in shop_json (not shared across brands/orgs).
 *
 * @return list<string>
 */
function org_ecommerce_get_custom_categories(PDO $dbh, int $orgId): array
{
    $settings = org_ecommerce_get_shop_settings($dbh, $orgId);
    $raw = $settings['custom_categories'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $name) {
        $name = trim((string)$name);
        if ($name === '' || mb_strlen($name) > 80) {
            continue;
        }
        $key = mb_strtolower($name);
        if (!isset($out[$key])) {
            $out[$key] = $name;
        }
    }
    return array_values($out);
}

/**
 * @param list<string> $brandCategories
 * @return list<string>
 */
function org_ecommerce_product_category_options(PDO $dbh, int $orgId, array $brandCategories = []): array
{
    $map = [];
    foreach ($brandCategories as $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        $map[mb_strtolower($name)] = $name;
    }
    foreach (org_ecommerce_get_custom_categories($dbh, $orgId) as $name) {
        $map[mb_strtolower($name)] = $name;
    }
    // Categories mirror "What things are you selling" (platform + custom + used types).
    foreach (org_ecommerce_product_selling_type_options($dbh, $orgId) as $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        $map[mb_strtolower($name)] = $name;
    }
    // Also include categories already used on this seller's products.
    try {
        $st = $dbh->prepare("
            SELECT DISTINCT category
            FROM org_products
            WHERE org_id = :org AND is_deleted = 0
              AND category IS NOT NULL AND TRIM(category) <> ''
            ORDER BY category ASC
            LIMIT 200
        ");
        $st->execute([':org' => $orgId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = trim((string)($row['category'] ?? ''));
            if ($name === '') {
                continue;
            }
            $map[mb_strtolower($name)] = $name;
        }
    } catch (Throwable $e) {
        // ignore
    }
    $list = array_values($map);
    natcasesort($list);
    return array_values($list);
}

/**
 * @return array{ok:bool,error?:string,name?:string,categories?:list<string>}
 */
function org_ecommerce_add_custom_category(PDO $dbh, int $orgId, string $name): array
{
    $name = trim($name);
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid shop.'];
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'Category name is required.'];
    }
    if (mb_strlen($name) > 80) {
        $name = mb_substr($name, 0, 80);
    }
    $current = org_ecommerce_get_custom_categories($dbh, $orgId);
    foreach ($current as $existing) {
        if (strcasecmp($existing, $name) === 0) {
            $all = org_ecommerce_product_category_options($dbh, $orgId, []);
            return ['ok' => true, 'name' => $existing, 'categories' => $all, 'error' => ''];
        }
    }
    $current[] = $name;
    if (!org_ecommerce_save_shop_settings($dbh, $orgId, ['custom_categories' => $current])) {
        return ['ok' => false, 'error' => 'Could not save category.'];
    }
    return [
        'ok' => true,
        'name' => $name,
        'categories' => org_ecommerce_product_category_options($dbh, $orgId, []),
    ];
}

/**
 * @return list<string>
 */
function org_ecommerce_get_custom_selling_types(PDO $dbh, int $orgId): array
{
    $settings = org_ecommerce_get_shop_settings($dbh, $orgId);
    $raw = $settings['custom_selling_types'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $name) {
        $name = trim((string)$name);
        if ($name === '' || mb_strlen($name) > 80) {
            continue;
        }
        $key = mb_strtolower($name);
        if (!isset($out[$key])) {
            $out[$key] = $name;
        }
    }
    return array_values($out);
}

/**
 * @return list<string>
 */
function org_ecommerce_product_selling_type_options(PDO $dbh, int $orgId): array
{
    $map = [];
    if (!function_exists('org_product_type_platform_selling_labels')) {
        $schemaFile = dirname(__DIR__) . '/../public_user/includes/org_product_type_schemas.php';
        if (is_file($schemaFile)) {
            require_once $schemaFile;
        }
    }
    if (function_exists('org_product_type_platform_selling_labels')) {
        foreach (org_product_type_platform_selling_labels() as $name) {
            $map[mb_strtolower($name)] = $name;
        }
    }
    foreach (org_ecommerce_get_custom_selling_types($dbh, $orgId) as $name) {
        $map[mb_strtolower($name)] = $name;
    }
    try {
        $st = $dbh->prepare("
            SELECT DISTINCT selling_type
            FROM org_products
            WHERE org_id = :org AND is_deleted = 0
              AND selling_type IS NOT NULL AND TRIM(selling_type) <> ''
            ORDER BY selling_type ASC
            LIMIT 200
        ");
        $st->execute([':org' => $orgId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = trim((string)($row['selling_type'] ?? ''));
            if ($name === '') {
                continue;
            }
            $map[mb_strtolower($name)] = $name;
        }
    } catch (Throwable $e) {
        // ignore until migration runs
    }
    $list = array_values($map);
    natcasesort($list);
    return array_values($list);
}

/**
 * @return array{ok:bool,error?:string,name?:string,selling_types?:list<string>}
 */
function org_ecommerce_add_custom_selling_type(PDO $dbh, int $orgId, string $name): array
{
    $name = trim($name);
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid shop.'];
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required.'];
    }
    if (mb_strlen($name) > 80) {
        $name = mb_substr($name, 0, 80);
    }
    $current = org_ecommerce_get_custom_selling_types($dbh, $orgId);
    $savedName = $name;
    $already = false;
    foreach ($current as $existing) {
        if (strcasecmp($existing, $name) === 0) {
            $savedName = $existing;
            $already = true;
            break;
        }
    }
    if (!$already) {
        $current[] = $name;
    }
    // Keep Category options in sync with selling names.
    $categories = org_ecommerce_get_custom_categories($dbh, $orgId);
    $catExists = false;
    foreach ($categories as $existing) {
        if (strcasecmp($existing, $savedName) === 0) {
            $catExists = true;
            break;
        }
    }
    if (!$catExists) {
        $categories[] = $savedName;
    }
    $patch = [
        'custom_selling_types' => $current,
        'custom_categories' => $categories,
    ];
    if (!$already || !$catExists) {
        if (!org_ecommerce_save_shop_settings($dbh, $orgId, $patch)) {
            return ['ok' => false, 'error' => 'Could not save.'];
        }
    }
    return [
        'ok' => true,
        'name' => $savedName,
        'selling_types' => org_ecommerce_product_selling_type_options($dbh, $orgId),
        'categories' => org_ecommerce_product_category_options($dbh, $orgId, []),
    ];
}

function org_ecommerce_stripe_configured(): bool
{
    if (!class_exists('Config')) {
        $cfg = dirname(__DIR__, 2) . '/config.php';
        if (is_file($cfg)) {
            require_once $cfg;
        }
    }
    if (!class_exists('Config')) {
        return false;
    }
    try {
        $c = new Config();
        return trim($c->STRIPE_SECRET_KEY) !== '' && trim($c->STRIPE_PUBLISHABLE_KEY) !== '';
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function org_ecommerce_integrations(PDO $dbh, int $orgId): array
{
    org_ecommerce_ensure_schema($dbh);
    $shop = org_ecommerce_get_shop_settings($dbh, $orgId);
    $crm = org_crm_dashboard_stats($dbh, $orgId);
    $stripe = org_ecommerce_stripe_configured();
    $shopLive = platform_rent_shop_is_visible($dbh, $orgId);

    return [
        [
            'id' => 'payments',
            'name' => 'Payment gateway',
            'description' => 'Stripe checkout for online card payments.',
            'status' => $stripe ? 'connected' : 'setup',
            'action' => 'Configure STRIPE keys in config.php',
        ],
        [
            'id' => 'crm',
            'name' => 'CRM',
            'description' => 'Contacts, tickets, deals, and buyer import from shop.',
            'status' => ((int)$crm['contacts'] > 0 || (int)$crm['open_deals'] > 0) ? 'active' : 'ready',
            'action' => 'crm.php',
        ],
        [
            'id' => 'oms',
            'name' => 'Order management',
            'description' => 'Centralized order inbox with fulfillment tracking.',
            'status' => 'active',
            'action' => 'orders.php',
        ],
        [
            'id' => 'pim',
            'name' => 'Product catalog (PIM)',
            'description' => 'SKU, categories, SEO, and inventory across channels.',
            'status' => org_shop_product_count($dbh, $orgId) > 0 ? 'active' : 'ready',
            'action' => 'products.php',
        ],
        [
            'id' => 'social',
            'name' => 'Social commerce',
            'description' => 'Publish products to the public feed for discovery.',
            'status' => !empty($shop['channels']['social_feed']) ? 'active' : 'paused',
            'action' => 'products.php',
        ],
        [
            'id' => 'marketplace',
            'name' => 'Marketplace channel',
            'description' => 'List active products on the public marketplace.',
            'status' => ($shopLive && !empty($shop['channels']['marketplace'])) ? 'active' : 'paused',
            'action' => '../public_user/shop.php',
        ],
        [
            'id' => 'logistics',
            'name' => 'Logistics & fulfillment',
            'description' => 'Carrier and tracking numbers on shipped orders.',
            'status' => 'active',
            'action' => 'orders.php',
        ],
    ];
}

/** @return array<string, mixed> */
function org_ecommerce_dashboard_stats(PDO $dbh, int $orgId): array
{
    org_ecommerce_ensure_schema($dbh);
    $stats = [
        'revenue_mtd_cents' => 0,
        'orders_mtd' => 0,
        'orders_open' => 0,
        'products_active' => 0,
        'products_low_stock' => 0,
        'avg_order_cents' => 0,
        'shop_visible' => false,
    ];
    if ($orgId <= 0) {
        return $stats;
    }
    $stats['shop_visible'] = platform_rent_shop_is_visible($dbh, $orgId);
    try {
        $st = $dbh->prepare("
            SELECT COALESCE(SUM(total_cents),0), COUNT(*)
            FROM org_orders
            WHERE org_id = :org
              AND status IN ('paid','shipped','delivered')
              AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ");
        $st->execute([':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $stats['revenue_mtd_cents'] = (int)$row[0];
        $stats['orders_mtd'] = (int)$row[1];
        if ($stats['orders_mtd'] > 0) {
            $stats['avg_order_cents'] = (int)round($stats['revenue_mtd_cents'] / $stats['orders_mtd']);
        }

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_orders WHERE org_id = :org AND status IN ('pending','confirmed','paid')");
        $st->execute([':org' => $orgId]);
        $stats['orders_open'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_products WHERE org_id = :org AND is_deleted = 0 AND status = 'active'");
        $st->execute([':org' => $orgId]);
        $stats['products_active'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("
            SELECT COUNT(*) FROM org_products
            WHERE org_id = :org AND is_deleted = 0 AND status = 'active'
              AND stock_qty IS NOT NULL AND stock_qty > 0 AND stock_qty < 5
        ");
        $st->execute([':org' => $orgId]);
        $stats['products_low_stock'] = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        // ignore
    }
    return $stats;
}

/** @return list<array<string, mixed>> */
function org_ecommerce_top_products(PDO $dbh, int $orgId, int $limit = 8): array
{
    if ($orgId <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 20));
    try {
        $st = $dbh->prepare("
            SELECT product_id, product_title,
                   COUNT(*) AS order_count,
                   COALESCE(SUM(total_cents),0) AS revenue_cents
            FROM org_orders
            WHERE org_id = :org AND status NOT IN ('cancelled')
            GROUP BY product_id, product_title
            ORDER BY revenue_cents DESC, order_count DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function org_ecommerce_orders_by_status(PDO $dbh, int $orgId): array
{
    if ($orgId <= 0) {
        return [];
    }
    try {
        $st = $dbh->prepare("
            SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_cents),0) AS total_cents
            FROM org_orders
            WHERE org_id = :org
            GROUP BY status
            ORDER BY cnt DESC
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function org_ecommerce_low_stock_products(PDO $dbh, int $orgId, int $limit = 10): array
{
    if ($orgId <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 30));
    try {
        $st = $dbh->prepare("
            SELECT id, title, sku, stock_qty, status
            FROM org_products
            WHERE org_id = :org AND is_deleted = 0 AND status = 'active'
              AND stock_qty IS NOT NULL AND stock_qty > 0 AND stock_qty < 5
            ORDER BY stock_qty ASC, title ASC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_ecommerce_update_fulfillment(
    PDO $dbh,
    int $orgId,
    int $orderId,
    string $status,
    string $sellerNotes = '',
    string $trackingNumber = '',
    string $carrier = ''
): bool {
    $allowed = ['pending', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'];
    if ($orgId <= 0 || $orderId <= 0 || !in_array($status, $allowed, true)) {
        return false;
    }
    org_ecommerce_ensure_schema($dbh);
    $trackingNumber = trim($trackingNumber);
    $carrier = trim($carrier);
    $sellerNotes = trim($sellerNotes);

    // If seller enters carrier + tracking while still pending/paid, auto-mark shipped.
    if (
        $carrier !== ''
        && $trackingNumber !== ''
        && in_array($status, ['pending', 'confirmed', 'paid'], true)
    ) {
        $status = 'shipped';
    }

    try {
        $prevSt = $dbh->prepare('
            SELECT status, buyer_user_id, order_code, carrier, tracking_number, seller_notes
            FROM org_orders
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ');
        $prevSt->execute([':id' => $orderId, ':org' => $orgId]);
        $prev = $prevSt->fetch(PDO::FETCH_ASSOC);
        if (!$prev) {
            return false;
        }
        $prevStatus = strtolower(trim((string)($prev['status'] ?? '')));
        $buyerUserId = (int)($prev['buyer_user_id'] ?? 0);
        $orderCode = (string)($prev['order_code'] ?? '');

        // Keep existing values when the form field is left blank (avoid wiping on status-only saves).
        if ($carrier === '') {
            $carrier = trim((string)($prev['carrier'] ?? ''));
        }
        if ($trackingNumber === '') {
            $trackingNumber = trim((string)($prev['tracking_number'] ?? ''));
        }
        if ($sellerNotes === '') {
            $sellerNotes = trim((string)($prev['seller_notes'] ?? ''));
        }

        // Unique placeholders required: PDO ATTR_EMULATE_PREPARES=false cannot reuse :st.
        $sql = '
            UPDATE org_orders
            SET status = :status,
                seller_notes = :notes,
                tracking_number = :track,
                carrier = :carrier,
                paid_at = CASE
                    WHEN :status_paid = \'paid\' AND paid_at IS NULL THEN NOW()
                    ELSE paid_at
                END,
                shipped_at = CASE
                    WHEN :status_ship = \'shipped\' AND shipped_at IS NULL THEN NOW()
                    ELSE shipped_at
                END,
                delivered_at = CASE
                    WHEN :status_del = \'delivered\' AND delivered_at IS NULL THEN NOW()
                    ELSE delivered_at
                END,
                updated_at = NOW()
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ';
        $st = $dbh->prepare($sql);
        $st->execute([
            ':status' => $status,
            ':status_paid' => $status,
            ':status_ship' => $status,
            ':status_del' => $status,
            ':notes' => $sellerNotes !== '' ? $sellerNotes : null,
            ':track' => $trackingNumber !== '' ? $trackingNumber : null,
            ':carrier' => $carrier !== '' ? $carrier : null,
            ':id' => $orderId,
            ':org' => $orgId,
        ]);
        if ($st->rowCount() <= 0) {
            return false;
        }
        if ($status === 'paid') {
            org_shop_apply_order_fees($dbh, $orderId);
            $fbaShipped = org_shop_auto_fulfill_fba_order($dbh, $orderId);
            org_shop_issue_receipt($dbh, $orgId, $orderId);
            if ($fbaShipped && function_exists('org_shop_notify_buyer_order_fulfillment')) {
                org_shop_notify_buyer_order_fulfillment($dbh, $orgId, $orderId, 'shipped');
            }
            if ($status !== $prevStatus && function_exists('org_shop_notify_seller_order_status')) {
                org_shop_notify_seller_order_status($dbh, $orgId, $buyerUserId, 'paid', [$orderCode]);
                if ($fbaShipped) {
                    org_shop_notify_seller_order_status(
                        $dbh,
                        $orgId,
                        $buyerUserId,
                        'shipped',
                        [$orderCode],
                        'Platform Fulfillment'
                    );
                }
            }
            return true;
        }
        // Paid/shipped orders should always have a receipt (covers test pay → ship path).
        if (in_array($status, ['shipped', 'delivered'], true)
            || in_array($prevStatus, ['paid', 'shipped', 'delivered'], true)
        ) {
            org_shop_issue_receipt($dbh, $orgId, $orderId);
        }
        if (in_array($status, ['shipped', 'delivered'], true) && function_exists('org_shop_notify_buyer_order_fulfillment')) {
            org_shop_notify_buyer_order_fulfillment($dbh, $orgId, $orderId, $status, $trackingNumber, $carrier);
        }

        if ($status !== $prevStatus && function_exists('org_shop_notify_seller_order_status')) {
            $extra = '';
            if ($status === 'shipped') {
                $bits = array_filter([$carrier, $trackingNumber]);
                $extra = implode(' / ', $bits);
            } elseif ($status === 'cancelled') {
                $extra = $sellerNotes !== '' ? $sellerNotes : 'Cancelled by seller';
            }
            org_shop_notify_seller_order_status($dbh, $orgId, $buyerUserId, $status, [$orderCode], $extra);
        }
        return true;
    } catch (Throwable $e) {
        // Fallback: status + carrier/tracking without CASE reuse (native prepares).
        try {
            $up = $dbh->prepare('
                UPDATE org_orders
                SET status = :status,
                    seller_notes = :notes,
                    tracking_number = :track,
                    carrier = :carrier,
                    paid_at = IF(:is_paid = 1 AND paid_at IS NULL, NOW(), paid_at),
                    shipped_at = IF(:is_ship = 1 AND shipped_at IS NULL, NOW(), shipped_at),
                    delivered_at = IF(:is_del = 1 AND delivered_at IS NULL, NOW(), delivered_at),
                    updated_at = NOW()
                WHERE id = :id AND org_id = :org
                LIMIT 1
            ');
            $up->execute([
                ':status' => $status,
                ':notes' => $sellerNotes !== '' ? $sellerNotes : null,
                ':track' => $trackingNumber !== '' ? $trackingNumber : null,
                ':carrier' => $carrier !== '' ? $carrier : null,
                ':is_paid' => $status === 'paid' ? 1 : 0,
                ':is_ship' => $status === 'shipped' ? 1 : 0,
                ':is_del' => $status === 'delivered' ? 1 : 0,
                ':id' => $orderId,
                ':org' => $orgId,
            ]);
            if ($up->rowCount() > 0) {
                if (in_array($status, ['shipped', 'delivered'], true) && function_exists('org_shop_notify_buyer_order_fulfillment')) {
                    org_shop_notify_buyer_order_fulfillment($dbh, $orgId, $orderId, $status, $trackingNumber, $carrier);
                }
                return true;
            }
        } catch (Throwable $e2) {
            // continue to status-only fallback
        }
        $ok = org_shop_update_order_status($dbh, $orgId, $orderId, $status, $sellerNotes);
        if ($ok && ($carrier !== '' || $trackingNumber !== '')) {
            try {
                $dbh->prepare('
                    UPDATE org_orders
                    SET carrier = :carrier,
                        tracking_number = :track,
                        updated_at = NOW()
                    WHERE id = :id AND org_id = :org
                    LIMIT 1
                ')->execute([
                    ':carrier' => $carrier !== '' ? $carrier : null,
                    ':track' => $trackingNumber !== '' ? $trackingNumber : null,
                    ':id' => $orderId,
                    ':org' => $orgId,
                ]);
            } catch (Throwable $e3) {
                // ignore
            }
        }
        if ($ok && in_array($status, ['shipped', 'delivered'], true) && function_exists('org_shop_notify_buyer_order_fulfillment')) {
            org_shop_notify_buyer_order_fulfillment($dbh, $orgId, $orderId, $status, $trackingNumber, $carrier);
        }
        return $ok;
    }
}

function org_ecommerce_slugify(string $title): string
{
    $s = strtolower(trim($title));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'product';
}

function org_ecommerce_sync_buyer_to_crm(PDO $dbh, int $orgId, int $orderId, int $memberId = 0): bool
{
    if ($orgId <= 0 || $orderId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':id' => $orderId, ':org' => $orgId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return false;
        }
        $email = trim((string)($order['buyer_email'] ?? ''));
        $uid = (int)($order['buyer_user_id'] ?? 0);
        $name = trim((string)($order['buyer_name'] ?? '')) ?: ($email !== '' ? $email : 'Shop buyer');
        if ($email !== '') {
            $chk = $dbh->prepare('SELECT id FROM org_crm_contacts WHERE org_id = :org AND email = :email AND is_deleted = 0 LIMIT 1');
            $chk->execute([':org' => $orgId, ':email' => $email]);
            $existing = (int)($chk->fetchColumn() ?: 0);
            if ($existing > 0) {
                org_crm_log_interaction(
                    $dbh,
                    $orgId,
                    $existing,
                    $memberId,
                    'shop_order',
                    'Order ' . (string)$order['order_code'],
                    (string)($order['product_title'] ?? '') . ' — ' . org_shop_format_price((int)$order['total_cents']),
                    $orderId
                );
                return true;
            }
        }
        $res = org_crm_save_contact($dbh, $orgId, [
            'full_name' => $name,
            'email' => $email,
            'phone' => trim((string)($order['buyer_phone'] ?? '')),
            'lifecycle_stage' => 'customer',
            'lead_source' => 'shop',
            'linked_user_id' => $uid,
        ], null, $memberId);
        if (!empty($res['ok']) && !empty($res['contact_id'])) {
            org_crm_log_interaction(
                $dbh,
                $orgId,
                (int)$res['contact_id'],
                $memberId,
                'shop_order',
                'Order ' . (string)$order['order_code'],
                (string)($order['product_title'] ?? ''),
                $orderId
            );
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function org_ecommerce_integration_badge(string $status): string
{
    $map = [
        'connected' => 'badge-success',
        'active' => 'badge-success',
        'ready' => 'badge-info',
        'setup' => 'badge-warning',
        'paused' => 'badge-secondary',
    ];
    return $map[$status] ?? 'badge-light';
}
