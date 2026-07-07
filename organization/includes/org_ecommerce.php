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
    $migration = dirname(__DIR__, 2) . '/Data/migrations/20260706_org_ecommerce_enhancements.sql';
    if (!is_file($migration)) {
        return;
    }
    try {
        $sql = (string)file_get_contents($migration);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '' || stripos($stmt, 'SET ') === 0) {
                continue;
            }
            $dbh->exec($stmt);
        }
    } catch (Throwable $e) {
        // columns may already exist
    }
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
        'tagline' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'payment_methods' => ['stripe', 'manual'],
        'delivery_notes' => '',
        'fulfillment_policy' => '',
        'return_policy' => '',
        'channels' => [
            'profile_shop' => true,
            'marketplace' => true,
            'social_feed' => true,
        ],
        'seo' => [
            'meta_description' => '',
        ],
    ];
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
              AND stock_qty IS NOT NULL AND stock_qty <= 5
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
              AND stock_qty IS NOT NULL AND stock_qty <= 5
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
    try {
        $sql = '
            UPDATE org_orders
            SET status = :st,
                seller_notes = :notes,
                tracking_number = :track,
                carrier = :carrier,
                shipped_at = CASE WHEN :st = \'shipped\' AND shipped_at IS NULL THEN NOW() ELSE shipped_at END,
                updated_at = NOW()
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ';
        $st = $dbh->prepare($sql);
        $st->execute([
            ':st' => $status,
            ':notes' => $sellerNotes !== '' ? $sellerNotes : null,
            ':track' => trim($trackingNumber) !== '' ? trim($trackingNumber) : null,
            ':carrier' => trim($carrier) !== '' ? trim($carrier) : null,
            ':id' => $orderId,
            ':org' => $orgId,
        ]);
        if ($st->rowCount() <= 0) {
            return false;
        }
        if ($status === 'paid') {
            org_shop_issue_receipt($dbh, $orgId, $orderId);
        }
        return true;
    } catch (Throwable $e) {
        return org_shop_update_order_status($dbh, $orgId, $orderId, $status, $sellerNotes);
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
