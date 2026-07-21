<?php
declare(strict_types=1);

require_once __DIR__ . '/platform_rent.php';
require_once __DIR__ . '/buyer_shipping.php';
require_once __DIR__ . '/org_product_type_schemas.php';

function org_shop_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once __DIR__ . '/msb_migrations.php';
    $base = dirname(__DIR__, 2) . '/Data/migrations/';
    // Order matters: base tables → stripe/business columns → enhancements → brands → buyer bridge.
    foreach ([
        '20260706_org_shop_commerce.sql',
        '20260706_org_orders_stripe.sql',
        '20260706_org_business_models.sql',
        '20260706_org_cart.sql',
        '20260706_org_ecommerce_enhancements.sql',
        '20260709_commerce_brands.sql',
        '20260714_buyer_commerce_bridge.sql',
        '20260714_separate_media_from_commerce.sql',
        '20260715_org_orders_buyer_hidden.sql',
        '20260715_org_products_delivery_pickup.sql',
        '20260715_org_products_shipping_fee.sql',
        '20260716_org_products_selling_type.sql',
        '20260716_org_products_attributes_json.sql',
        '20260716_org_products_product_code.sql',
        '20260720_org_orders_tax_cents.sql',
        '20260720_org_orders_service_fee_cents.sql',
    ] as $file) {
        msb_run_sql_migration_file($dbh, $base . $file);
    }
    if (!platform_rent_db_column_exists($dbh, 'org_orders', 'tax_cents')) {
        try {
            $dbh->exec('ALTER TABLE org_orders ADD COLUMN tax_cents INT NOT NULL DEFAULT 0 AFTER shipping_fee_cents');
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (!platform_rent_db_column_exists($dbh, 'org_orders', 'service_fee_cents')) {
        try {
            $dbh->exec('ALTER TABLE org_orders ADD COLUMN service_fee_cents INT NOT NULL DEFAULT 0 AFTER tax_cents');
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/** Sales tax rate applied to merchandise + shipping (customer-paid). */
function org_shop_sales_tax_rate(): float
{
    return 0.0825;
}

function org_shop_sales_tax_cents(int $taxableCents): int
{
    return (int)round(max(0, $taxableCents) * org_shop_sales_tax_rate());
}

/**
 * Fixed online order service fee paid by the customer to the platform/admin
 * when buying through shop / product detail (not charged to the seller).
 */
function org_shop_buyer_service_fee_cents(): int
{
    return 199; // $1.99
}

function org_shop_org_id_for_publisher(PDO $dbh, int $publisherUserId): int
{
    if ($publisherUserId <= 0) {
        return 0;
    }
    try {
        $st = $dbh->prepare('
            SELECT id FROM organizations
            WHERE publisher_user_id = :uid AND status = 1
            ORDER BY id ASC LIMIT 1
        ');
        $st->execute([':uid' => $publisherUserId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * True when an org row is a commerce seller (brand shop), not a media publisher (CNN, news, etc.).
 * Media publishers share the publisher-org account model but must stay out of marketplace commerce.
 */
function org_is_commerce_seller_row(?array $org): bool
{
    if (!$org) {
        return false;
    }
    $cat = strtolower(trim((string)($org['publisher_category'] ?? '')));
    if ($cat !== '' && $cat !== 'commerce') {
        return false;
    }
    $brandId = (int)($org['commerce_brand_id'] ?? 0);
    if ($brandId > 0) {
        return true;
    }
    // Commerce category assigned but brand not yet chosen still counts as a seller workspace.
    return $cat === 'commerce';
}

function org_is_commerce_seller(PDO $dbh, int $orgId): bool
{
    if ($orgId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            SELECT commerce_brand_id, publisher_category
            FROM organizations
            WHERE id = :id AND status = 1
            LIMIT 1
        ');
        $st->execute([':id' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return org_is_commerce_seller_row($row ?: null);
    } catch (Throwable $e) {
        return false;
    }
}

function org_is_commerce_seller_publisher(PDO $dbh, int $publisherUserId): bool
{
    if ($publisherUserId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            SELECT commerce_brand_id, publisher_category
            FROM organizations
            WHERE publisher_user_id = :uid AND status = 1
            ORDER BY id ASC
            LIMIT 1
        ');
        $st->execute([':uid' => $publisherUserId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return org_is_commerce_seller_row($row ?: null);
    } catch (Throwable $e) {
        return false;
    }
}

/** SQL fragment: organizations alias must be `o` (or pass $alias). */
function org_sql_commerce_seller_org(string $alias = 'o'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'o';
    return "(
        {$a}.commerce_brand_id IS NOT NULL
        AND {$a}.commerce_brand_id > 0
        AND LOWER(TRIM(COALESCE({$a}.publisher_category, ''))) IN ('', 'commerce')
    ) OR (
        LOWER(TRIM(COALESCE({$a}.publisher_category, ''))) = 'commerce'
    )";
}

function org_shop_max_products(PDO $dbh, int $orgId): int
{
    $snap = platform_rent_org_snapshot($dbh, $orgId);
    if (!$snap) {
        return 10;
    }
    $planMax = (int)($snap['plan_max_products'] ?? 0);
    return $planMax > 0 ? $planMax : 10;
}

function org_shop_product_count(PDO $dbh, int $orgId): int
{
    if ($orgId <= 0) {
        return 0;
    }
    try {
        $st = $dbh->prepare('
            SELECT COUNT(*) FROM org_products
            WHERE org_id = :org AND is_deleted = 0
        ');
        $st->execute([':org' => $orgId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function org_shop_format_price(int $cents, string $currency = 'USD'): string
{
    return platform_rent_format_money($cents, $currency);
}

function org_shop_cover_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return '../organization/' . ltrim($path, '/');
}

function org_shop_gen_order_code(int $orgId): string
{
    return 'ORD-' . $orgId . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/** Product listing code shown in Product table (e.g. PRD-09E82). */
function org_shop_gen_product_code(PDO $dbh, int $orgId): string
{
    for ($i = 0; $i < 12; $i++) {
        $code = 'PRD-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        try {
            $st = $dbh->prepare('SELECT 1 FROM org_products WHERE org_id = :org AND product_code = :code LIMIT 1');
            $st->execute([':org' => $orgId, ':code' => $code]);
            if (!$st->fetchColumn()) {
                return $code;
            }
        } catch (Throwable $e) {
            return $code;
        }
    }
    return 'PRD-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
}

/**
 * Ensure a product has a listing code; backfills older rows.
 */
function org_shop_ensure_product_code(PDO $dbh, int $orgId, int $productId, ?string $existing = null): string
{
    $existing = trim((string)$existing);
    if ($existing !== '') {
        return $existing;
    }
    if ($orgId <= 0 || $productId <= 0) {
        return '';
    }
    $code = org_shop_gen_product_code($dbh, $orgId);
    try {
        $st = $dbh->prepare('
            UPDATE org_products
            SET product_code = :code, updated_at = NOW()
            WHERE id = :id AND org_id = :org
              AND (product_code IS NULL OR TRIM(product_code) = \'\')
            LIMIT 1
        ');
        $st->execute([':code' => $code, ':id' => $productId, ':org' => $orgId]);
        if ($st->rowCount() > 0) {
            return $code;
        }
        $fresh = org_shop_get_product($dbh, $productId, $orgId);
        return trim((string)($fresh['product_code'] ?? $code));
    } catch (Throwable $e) {
        return $code;
    }
}

function org_shop_gen_receipt_code(int $orgId): string
{
    return 'RCP-' . $orgId . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/** @return list<array<string, mixed>> */
function org_shop_list_products(PDO $dbh, int $orgId, bool $activeOnly = false): array
{
    if ($orgId <= 0) {
        return [];
    }
    org_shop_ensure_schema($dbh);
    // Keep Product Table / shop status in sync: 0 stock => sold_out (hidden from shop).
    org_shop_sync_org_sold_out_stock($dbh, $orgId);
    $sql = '
        SELECT * FROM org_products
        WHERE org_id = :org AND is_deleted = 0
    ';
    if ($activeOnly) {
        $sql .= " AND status = 'active' AND (stock_qty IS NULL OR stock_qty > 0)";
    }
    $sql .= ' ORDER BY sort_order ASC, id DESC';
    try {
        $st = $dbh->prepare($sql);
        $st->execute([':org' => $orgId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $pid = (int)($row['id'] ?? 0);
            $code = trim((string)($row['product_code'] ?? ''));
            if ($pid > 0 && $code === '') {
                $row['product_code'] = org_shop_ensure_product_code($dbh, $orgId, $pid, $code);
            }
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Total units ordered per product (excludes cancelled orders).
 * @return array<int,int> product_id => quantity
 */
function org_shop_product_ordered_qty_map(PDO $dbh, int $orgId): array
{
    if ($orgId <= 0) {
        return [];
    }
    org_shop_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT product_id, COALESCE(SUM(GREATEST(COALESCE(quantity, 1), 1)), 0) AS ordered_qty
            FROM org_orders
            WHERE org_id = :org
              AND product_id IS NOT NULL
              AND product_id > 0
              AND status <> 'cancelled'
            GROUP BY product_id
        ");
        $st->execute([':org' => $orgId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            if ($pid > 0) {
                $out[$pid] = (int)($row['ordered_qty'] ?? 0);
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * When tracked stock hits 0, mark product sold_out so it leaves the public shop.
 */
function org_shop_mark_sold_out_if_empty(PDO $dbh, int $productId, int $orgId = 0): bool
{
    if ($productId <= 0) {
        return false;
    }
    try {
        $sql = '
            UPDATE org_products
            SET status = \'sold_out\', updated_at = NOW()
            WHERE id = :id
              AND is_deleted = 0
              AND stock_qty IS NOT NULL
              AND stock_qty <= 0
              AND status = \'active\'
        ';
        $params = [':id' => $productId];
        if ($orgId > 0) {
            $sql .= ' AND org_id = :org';
            $params[':org'] = $orgId;
        }
        $sql .= ' LIMIT 1';
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Bulk-sync all zero-stock active products for an org to sold_out.
 */
function org_shop_sync_org_sold_out_stock(PDO $dbh, int $orgId): int
{
    if ($orgId <= 0) {
        return 0;
    }
    try {
        $st = $dbh->prepare("
            UPDATE org_products
            SET status = 'sold_out', updated_at = NOW()
            WHERE org_id = :org
              AND is_deleted = 0
              AND stock_qty IS NOT NULL
              AND stock_qty <= 0
              AND status = 'active'
        ");
        $st->execute([':org' => $orgId]);
        return (int)$st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Inventory breakdown for Notification Inventory card.
 *
 * @return array{low:int,sold_out:int,draft:int,total:int}
 */
function org_shop_inventory_status_counts(PDO $dbh, int $orgId): array
{
    $out = ['low' => 0, 'sold_out' => 0, 'draft' => 0, 'total' => 0];
    if ($orgId <= 0) {
        return $out;
    }
    org_shop_sync_org_sold_out_stock($dbh, $orgId);
    try {
        $st = $dbh->prepare("
            SELECT COUNT(*) FROM org_products
            WHERE org_id = :org AND is_deleted = 0 AND status = 'active'
              AND stock_qty IS NOT NULL AND stock_qty > 0 AND stock_qty < 5
        ");
        $st->execute([':org' => $orgId]);
        $out['low'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("
            SELECT COUNT(*) FROM org_products
            WHERE org_id = :org AND is_deleted = 0 AND status = 'sold_out'
        ");
        $st->execute([':org' => $orgId]);
        $out['sold_out'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("
            SELECT COUNT(*) FROM org_products
            WHERE org_id = :org AND is_deleted = 0 AND status = 'draft'
        ");
        $st->execute([':org' => $orgId]);
        $out['draft'] = (int)($st->fetchColumn() ?: 0);

        $out['total'] = (int)$out['low'] + (int)$out['sold_out'] + (int)$out['draft'];
    } catch (Throwable $e) {
        // keep zeros
    }
    return $out;
}

/** @return list<array<string, mixed>> */
function org_shop_products_for_publisher(PDO $dbh, int $publisherUserId, bool $activeOnly = true): array
{
    if (!org_is_commerce_seller_publisher($dbh, $publisherUserId)) {
        return [];
    }
    if (!platform_rent_shop_visible_for_publisher($dbh, $publisherUserId)) {
        return [];
    }
    $orgId = org_shop_org_id_for_publisher($dbh, $publisherUserId);
    return org_shop_list_products($dbh, $orgId, $activeOnly);
}

function org_shop_get_product(PDO $dbh, int $productId, int $orgId = 0): ?array
{
    if ($productId <= 0) {
        return null;
    }
    try {
        $sql = 'SELECT * FROM org_products WHERE id = :id AND is_deleted = 0';
        $params = [':id' => $productId];
        if ($orgId > 0) {
            $sql .= ' AND org_id = :org';
            $params[':org'] = $orgId;
        }
        $sql .= ' LIMIT 1';
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function org_shop_resolve_unit_price_cents(array $product, int $quantity): int
{
    $quantity = max(1, $quantity);
    $base = (int)($product['price_cents'] ?? 0);
    return $base * $quantity;
}

function org_shop_create_order(
    PDO $dbh,
    int $productId,
    int $buyerUserId,
    int $quantity,
    string $buyerNotes = '',
    string $deliveryAddress = '',
    ?string $buyerName = null,
    ?string $buyerPhone = null,
    ?string $buyerEmail = null,
    string $deliveryOption = 'home_delivery',
    ?string $fulfillmentMethod = null,
    string $promoCode = ''
): array {
    org_shop_ensure_schema($dbh);
    $product = org_shop_get_product($dbh, $productId);
    if (!$product || (string)($product['status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'Product is not available.'];
    }

    $orgId = (int)($product['org_id'] ?? 0);
    if ($orgId <= 0 || !org_is_commerce_seller($dbh, $orgId)) {
        return ['ok' => false, 'error' => 'This publisher is not a commerce seller.'];
    }
    if (!platform_rent_shop_is_visible($dbh, $orgId)) {
        return ['ok' => false, 'error' => 'This shop is not accepting orders right now.'];
    }

    $quantity = max(1, min($quantity, 99));
    $stock = $product['stock_qty'] ?? null;
    if ($stock !== null && $stock !== '' && (int)$stock >= 0 && $quantity > (int)$stock) {
        return ['ok' => false, 'error' => 'Not enough stock available.'];
    }

    $unitPrice = (int)($product['price_cents'] ?? 0);
    $totalCents = org_shop_resolve_unit_price_cents($product, $quantity);
    $currency = (string)($product['currency'] ?? 'USD');
    $promoCode = strtoupper(trim($promoCode));
    $discountCents = 0;
    if ($promoCode !== '') {
        $discountCents = org_shop_promo_discount_cents($dbh, $orgId, $promoCode, $totalCents);
        if ($discountCents < 0) {
            return ['ok' => false, 'error' => 'Promotion code is not valid for this seller.'];
        }
        $totalCents = max(0, $totalCents - $discountCents);
    }

    if ($buyerUserId > 0) {
        try {
            $st = $dbh->prepare('SELECT name, username, email FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $buyerUserId]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($buyerName === null || $buyerName === '') {
                $buyerName = trim((string)($u['name'] ?? '')) ?: trim((string)($u['username'] ?? ''));
            }
            if ($buyerEmail === null || $buyerEmail === '') {
                $buyerEmail = trim((string)($u['email'] ?? ''));
            }
        } catch (Throwable $e) {
            // ignore
        }
        if ($deliveryAddress === '') {
            $deliveryAddress = buyer_shipping_default_text($dbh, $buyerUserId);
        }
        if (($buyerPhone === null || $buyerPhone === '') && $buyerUserId > 0) {
            $defPhone = buyer_shipping_default_phone($dbh, $buyerUserId);
            if ($defPhone !== '') {
                $buyerPhone = $defPhone;
            }
        }
    }

    $orderCode = org_shop_gen_order_code($orgId);

    $deliveryOption = strtolower(trim($deliveryOption));
    if (!in_array($deliveryOption, ['pickup', 'home_delivery', 'same_day'], true)) {
        $deliveryOption = 'home_delivery';
    }
    if ($deliveryOption === 'same_day') {
        return ['ok' => false, 'error' => 'Same-day delivery is not available for this product.'];
    }

    $receive = org_shop_product_receive_options($product);
    if ($deliveryOption === 'pickup') {
        if (!$receive['pickup_enabled']) {
            return ['ok' => false, 'error' => 'Pick up is not offered for this product.'];
        }
    } else {
        if (!$receive['delivery_enabled']) {
            return ['ok' => false, 'error' => 'Delivery is not offered for this product.'];
        }
        if (trim($deliveryAddress) === '') {
            return ['ok' => false, 'error' => 'Delivery address is required.'];
        }
    }

    $fulfillmentMethod = strtolower(trim((string)($fulfillmentMethod ?? ($product['fulfillment_method'] ?? 'fbm'))));
    if (!in_array($fulfillmentMethod, ['fba', 'fbm'], true)) {
        $fulfillmentMethod = 'fbm';
    }
    if ($deliveryOption === 'pickup') {
        $fulfillmentMethod = 'fbm';
        $pickupAddr = org_shop_seller_pickup_address_text($dbh, $orgId);
        $deliveryAddress = $pickupAddr !== ''
            ? ("Pick up at seller shop:\n" . $pickupAddr)
            : 'Pick up at seller shop';
    }

    $shippingFeeCents = ($deliveryOption === 'pickup') ? 0 : max(0, (int)($receive['shipping_fee_cents'] ?? 0));
    $merchandiseCents = $totalCents;
    $taxableCents = $merchandiseCents + $shippingFeeCents;
    $taxCents = org_shop_sales_tax_cents($taxableCents);
    $serviceFeeCents = org_shop_buyer_service_fee_cents();
    $totalCents = $taxableCents + $taxCents + $serviceFeeCents;

    try {
        $st = $dbh->prepare('
            INSERT INTO org_orders (
                org_id, order_code, buyer_user_id, buyer_name, buyer_phone, buyer_email,
                product_id, product_title, unit_price_cents, currency, quantity, total_cents,
                promo_code, discount_cents, shipping_fee_cents, tax_cents, service_fee_cents,
                status, order_type, fulfillment_method, delivery_option,
                buyer_notes, delivery_address, created_at, updated_at
            ) VALUES (
                :org, :code, :uid, :name, :phone, :email,
                :pid, :title, :unit, :cur, :qty, :total,
                :promo, :disc, :shipfee, :tax, :svcfee,
                \'pending\', \'purchase\', :fmethod, :dopt,
                :notes, :addr, NOW(), NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':code' => $orderCode,
            ':uid' => $buyerUserId > 0 ? $buyerUserId : null,
            ':name' => $buyerName !== '' ? $buyerName : null,
            ':phone' => $buyerPhone !== '' ? $buyerPhone : null,
            ':email' => $buyerEmail !== '' ? $buyerEmail : null,
            ':pid' => $productId,
            ':title' => (string)($product['title'] ?? 'Product'),
            ':unit' => $unitPrice,
            ':cur' => $currency,
            ':qty' => $quantity,
            ':total' => $totalCents,
            ':promo' => $promoCode !== '' ? $promoCode : null,
            ':disc' => $discountCents,
            ':shipfee' => $shippingFeeCents,
            ':tax' => $taxCents,
            ':svcfee' => $serviceFeeCents,
            ':fmethod' => $fulfillmentMethod,
            ':dopt' => $deliveryOption,
            ':notes' => $buyerNotes !== '' ? $buyerNotes : null,
            ':addr' => $deliveryAddress !== '' ? $deliveryAddress : null,
        ]);
        $orderId = (int)$dbh->lastInsertId();

        if ($stock !== null && $stock !== '' && (int)$stock > 0) {
            $dbh->prepare('UPDATE org_products SET stock_qty = GREATEST(0, stock_qty - :q), updated_at = NOW() WHERE id = :id LIMIT 1')
                ->execute([':q' => $quantity, ':id' => $productId]);
            org_shop_mark_sold_out_if_empty($dbh, $productId, $orgId);
        }

        org_shop_notify_seller_order_status(
            $dbh,
            $orgId,
            $buyerUserId,
            'pending',
            [$orderCode]
        );

        return [
            'ok' => true,
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'total_cents' => $totalCents,
            'discount_cents' => $discountCents,
            'shipping_fee_cents' => $shippingFeeCents,
            'tax_cents' => $taxCents,
            'service_fee_cents' => $serviceFeeCents,
            'promo_code' => $promoCode,
            'currency' => $currency,
            'org_id' => $orgId,
        ];
    } catch (Throwable $e) {
        // Fallback if promo / shipping / service fee columns missing on partially migrated DB.
        try {
            $st = $dbh->prepare('
                INSERT INTO org_orders (
                    org_id, order_code, buyer_user_id, buyer_name, buyer_phone, buyer_email,
                    product_id, product_title, unit_price_cents, currency, quantity, total_cents,
                    status, order_type, fulfillment_method, delivery_option,
                    buyer_notes, delivery_address, created_at, updated_at
                ) VALUES (
                    :org, :code, :uid, :name, :phone, :email,
                    :pid, :title, :unit, :cur, :qty, :total,
                    \'pending\', \'purchase\', :fmethod, :dopt,
                    :notes, :addr, NOW(), NOW()
                )
            ');
            $st->execute([
                ':org' => $orgId,
                ':code' => $orderCode,
                ':uid' => $buyerUserId > 0 ? $buyerUserId : null,
                ':name' => $buyerName !== '' ? $buyerName : null,
                ':phone' => $buyerPhone !== '' ? $buyerPhone : null,
                ':email' => $buyerEmail !== '' ? $buyerEmail : null,
                ':pid' => $productId,
                ':title' => (string)($product['title'] ?? 'Product'),
                ':unit' => $unitPrice,
                ':cur' => $currency,
                ':qty' => $quantity,
                ':total' => $totalCents,
                ':fmethod' => $fulfillmentMethod,
                ':dopt' => $deliveryOption,
                ':notes' => $buyerNotes !== '' ? $buyerNotes : null,
                ':addr' => $deliveryAddress !== '' ? $deliveryAddress : null,
            ]);
            $orderId = (int)$dbh->lastInsertId();
            if ($stock !== null && $stock !== '' && (int)$stock > 0) {
                try {
                    $dbh->prepare('UPDATE org_products SET stock_qty = GREATEST(0, stock_qty - :q), updated_at = NOW() WHERE id = :id LIMIT 1')
                        ->execute([':q' => $quantity, ':id' => $productId]);
                    org_shop_mark_sold_out_if_empty($dbh, $productId, $orgId);
                } catch (Throwable $eStock) {
                    // ignore stock sync failure
                }
            }
            org_shop_notify_seller_order_status(
                $dbh,
                $orgId,
                $buyerUserId,
                'pending',
                [$orderCode]
            );
            return [
                'ok' => true,
                'order_id' => $orderId,
                'order_code' => $orderCode,
                'total_cents' => $totalCents,
                'shipping_fee_cents' => $shippingFeeCents,
                'tax_cents' => $taxCents,
                'service_fee_cents' => $serviceFeeCents,
                'currency' => $currency,
                'org_id' => $orgId,
            ];
        } catch (Throwable $e2) {
            return ['ok' => false, 'error' => 'Could not place order.'];
        }
    }
}

/**
 * Apply an active seller promotion from shop_json.promotions.
 * Returns discount cents, or -1 if code invalid.
 */
function org_shop_promo_discount_cents(PDO $dbh, int $orgId, string $promoCode, int $subtotalCents): int
{
    $promoCode = strtoupper(trim($promoCode));
    if ($orgId <= 0 || $promoCode === '' || $subtotalCents <= 0) {
        return -1;
    }
    $promos = [];
    try {
        $st = $dbh->prepare('SELECT shop_json FROM org_settings WHERE org_id = :org LIMIT 1');
        $st->execute([':org' => $orgId]);
        $raw = $st->fetchColumn();
        if ($raw) {
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded) && isset($decoded['promotions']) && is_array($decoded['promotions'])) {
                $promos = $decoded['promotions'];
            }
        }
    } catch (Throwable $e) {
        return -1;
    }
    $today = date('Y-m-d');
    foreach ($promos as $promo) {
        if (!is_array($promo)) {
            continue;
        }
        if (strtoupper(trim((string)($promo['code'] ?? ''))) !== $promoCode) {
            continue;
        }
        if (strtolower((string)($promo['status'] ?? 'active')) !== 'active') {
            return -1;
        }
        $starts = trim((string)($promo['starts_at'] ?? ''));
        $ends = trim((string)($promo['ends_at'] ?? ''));
        if ($starts !== '' && $today < $starts) {
            return -1;
        }
        if ($ends !== '' && $today > $ends) {
            return -1;
        }
        $type = (string)($promo['type'] ?? 'percent');
        $value = (float)($promo['value'] ?? 0);
        if ($value <= 0) {
            return 0;
        }
        if ($type === 'fixed') {
            return min($subtotalCents, (int)round($value * 100));
        }
        $pct = min(100.0, max(0.0, $value));
        return (int)round($subtotalCents * ($pct / 100.0));
    }
    return -1;
}

/** @return list<array<string, mixed>> */
function org_shop_list_orders(PDO $dbh, int $orgId, string $statusFilter = 'all', int $limit = 100): array
{
    if ($orgId <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 200));
    $where = ['o.org_id = :org'];
    $params = [':org' => $orgId];
    $statusFilter = strtolower(trim($statusFilter));
    if ($statusFilter !== '' && $statusFilter !== 'all') {
        $where[] = 'o.status = :status';
        $params[':status'] = $statusFilter;
    } else {
        // Default list hides cancelled orders (customer cancel removes them from OMS inbox).
        $where[] = "o.status <> 'cancelled'";
    }
    $sql = "
        SELECT o.*, u.username AS buyer_username
        FROM org_orders o
        LEFT JOIN users u ON u.id = o.buyer_user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT {$limit}
    ";
    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Group seller inbox rows by customer (same brand/org).
 * One customer = one OMS row even if they bought bowl + burger across line items.
 * Product # = how many different products (bowl, cup, tomatoes).
 * Quantity # = total units (2 bowls + 3 cups + 6 tomatoes).
 *
 * @param list<array<string, mixed>> $orders
 * @return list<array<string, mixed>>
 */
function org_shop_group_seller_customer_orders(array $orders, bool $cancelledOnly = false): array
{
    $groups = [];
    foreach ($orders as $order) {
        $status = strtolower(trim((string)($order['status'] ?? '')));
        if ($cancelledOnly) {
            if ($status !== 'cancelled') {
                continue;
            }
        } elseif ($status === 'cancelled') {
            continue;
        }
        $buyerUserId = (int)($order['buyer_user_id'] ?? 0);
        $buyerEmail = strtolower(trim((string)($order['buyer_email'] ?? '')));
        $buyerNameNorm = mb_strtolower(trim((string)($order['buyer_name'] ?? '')));
        if ($buyerUserId > 0) {
            $groupKey = 'u:' . $buyerUserId;
        } elseif ($buyerEmail !== '') {
            $groupKey = 'e:' . $buyerEmail;
        } elseif ($buyerNameNorm !== '') {
            $groupKey = 'n:' . $buyerNameNorm;
        } else {
            $groupKey = 'o:' . (int)($order['id'] ?? 0);
        }

        $createdRaw = (string)($order['created_at'] ?? '');
        $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : false;

        if (!isset($groups[$groupKey])) {
            $buyerName = trim((string)($order['buyer_name'] ?? ''));
            if ($buyerName === '' && trim((string)($order['buyer_username'] ?? '')) !== '') {
                $buyerName = '@' . (string)$order['buyer_username'];
            }
            if ($buyerName === '') {
                $buyerName = $buyerEmail !== '' ? $buyerEmail : 'Guest';
            }
            $groups[$groupKey] = [
                'buyer_user_id' => $buyerUserId,
                'buyer_name' => $buyerName,
                'buyer_email' => trim((string)($order['buyer_email'] ?? '')),
                'buyer_phone' => trim((string)($order['buyer_phone'] ?? '')),
                'delivery_address' => trim((string)($order['delivery_address'] ?? '')),
                'currency' => (string)($order['currency'] ?? 'USD'),
                'date_raw' => $createdRaw,
                'date_sort' => $createdTs ?: 0,
                'date_min_ts' => $createdTs ?: 0,
                'date_max_ts' => $createdTs ?: 0,
                'total_cents' => 0,
                'statuses' => [],
                'order_ids' => [],
                'order_codes' => [],
                'products' => [],
                'lines' => [],
                'primary_order_id' => (int)($order['id'] ?? 0),
            ];
        }

        $g = &$groups[$groupKey];
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId > 0 && !in_array($orderId, $g['order_ids'], true)) {
            $g['order_ids'][] = $orderId;
        }
        // Prefer the newest line as the Details / fulfillment primary.
        if ($createdTs && $createdTs >= (int)$g['date_sort'] && $orderId > 0) {
            $g['primary_order_id'] = $orderId;
            $g['date_raw'] = $createdRaw;
            $g['date_sort'] = $createdTs;
        } elseif ($g['primary_order_id'] <= 0 && $orderId > 0) {
            $g['primary_order_id'] = $orderId;
        }
        if ($createdTs) {
            if ((int)$g['date_min_ts'] <= 0 || $createdTs < (int)$g['date_min_ts']) {
                $g['date_min_ts'] = $createdTs;
            }
            if ($createdTs > (int)$g['date_max_ts']) {
                $g['date_max_ts'] = $createdTs;
            }
        }
        $code = trim((string)($order['order_code'] ?? ''));
        if ($code !== '' && !in_array($code, $g['order_codes'], true)) {
            $g['order_codes'][] = $code;
        }
        $g['total_cents'] += (int)($order['total_cents'] ?? 0);
        if ($status !== '') {
            $g['statuses'][] = $status;
        }
        if ($g['delivery_address'] === '') {
            $g['delivery_address'] = trim((string)($order['delivery_address'] ?? ''));
        }
        if ($g['buyer_phone'] === '') {
            $g['buyer_phone'] = trim((string)($order['buyer_phone'] ?? ''));
        }
        if ($g['buyer_email'] === '') {
            $g['buyer_email'] = trim((string)($order['buyer_email'] ?? ''));
        }
        if ((int)$g['buyer_user_id'] <= 0 && $buyerUserId > 0) {
            $g['buyer_user_id'] = $buyerUserId;
        }

        $title = trim((string)($order['product_title'] ?? '')) ?: 'Product';
        $qty = max(1, (int)($order['quantity'] ?? 1));
        $lineCents = (int)($order['total_cents'] ?? 0);
        $titleKey = mb_strtolower($title);
        if (!isset($g['products'][$titleKey])) {
            $g['products'][$titleKey] = [
                'title' => $title,
                'qty' => $qty,
                'amount_cents' => $lineCents,
            ];
        } else {
            $g['products'][$titleKey]['qty'] += $qty;
            $g['products'][$titleKey]['amount_cents'] += $lineCents;
        }
        $g['lines'][] = $order;
        unset($g);
    }

    $out = [];
    foreach ($groups as $g) {
        $products = array_values($g['products']);
        $orderNum = count($products);
        $quantityNum = 0;
        $titles = [];
        foreach ($products as $p) {
            $quantityNum += max(1, (int)($p['qty'] ?? 1));
            $titles[] = (string)$p['title'] . ((int)$p['qty'] > 1 ? ' × ' . (int)$p['qty'] : '');
        }
        $statuses = array_values(array_unique($g['statuses']));
        if (count($statuses) === 1) {
            $status = $statuses[0];
        } elseif (in_array('pending', $statuses, true)) {
            $status = 'pending';
        } elseif ($statuses) {
            $status = 'multiple';
        } else {
            $status = 'pending';
        }
        $minTs = (int)$g['date_min_ts'];
        $maxTs = (int)$g['date_max_ts'];
        if ($minTs > 0 && $maxTs > 0) {
            $minLabel = date('M j, Y', $minTs);
            $maxLabel = date('M j, Y', $maxTs);
            $dateLabel = ($minLabel === $maxLabel) ? $maxLabel : ($minLabel . ' – ' . $maxLabel);
        } else {
            $dateLabel = (string)$g['date_raw'] !== '' ? (string)$g['date_raw'] : '—';
        }
        $out[] = [
            'buyer_user_id' => (int)$g['buyer_user_id'],
            'buyer_name' => (string)$g['buyer_name'],
            'buyer_email' => (string)$g['buyer_email'],
            'buyer_phone' => (string)$g['buyer_phone'],
            'delivery_address' => (string)$g['delivery_address'],
            'currency' => (string)$g['currency'],
            'date_raw' => (string)$g['date_raw'],
            'date_sort' => (int)$g['date_sort'],
            'date_label' => $dateLabel,
            'total_cents' => (int)$g['total_cents'],
            'total_label' => org_shop_format_price((int)$g['total_cents'], (string)$g['currency']),
            'status' => $status,
            'order_num' => $orderNum,
            'quantity_num' => $quantityNum,
            'product_titles' => $titles,
            'products' => $products,
            'order_ids' => $g['order_ids'],
            'order_codes' => $g['order_codes'],
            'primary_order_id' => (int)$g['primary_order_id'],
            'lines' => $g['lines'],
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return ((int)$b['date_sort']) <=> ((int)$a['date_sort']);
    });
    return $out;
}

/**
 * Sibling line items for the same customer + org (one brand customer purchase group).
 * @return list<array<string, mixed>>
 */
function org_shop_seller_order_batch(PDO $dbh, int $orgId, array $order): array
{
    if ($orgId <= 0 || !$order) {
        return $order ? [$order] : [];
    }
    $buyerUserId = (int)($order['buyer_user_id'] ?? 0);
    $buyerEmail = trim((string)($order['buyer_email'] ?? ''));
    $buyerName = trim((string)($order['buyer_name'] ?? ''));

    try {
        $where = ['o.org_id = :org', "o.status <> 'cancelled'"];
        $params = [':org' => $orgId];
        if ($buyerUserId > 0) {
            $where[] = 'o.buyer_user_id = :buyer';
            $params[':buyer'] = $buyerUserId;
        } elseif ($buyerEmail !== '') {
            $where[] = 'o.buyer_email = :email';
            $params[':email'] = $buyerEmail;
        } elseif ($buyerName !== '') {
            $where[] = 'o.buyer_name = :name';
            $params[':name'] = $buyerName;
        } else {
            return [$order];
        }
        $sql = '
            SELECT o.*, u.username AS buyer_username, p.sku
            FROM org_orders o
            LEFT JOIN users u ON u.id = o.buyer_user_id
            LEFT JOIN org_products p ON p.id = o.product_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY o.created_at ASC, o.id ASC
        ';
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows ?: [$order];
    } catch (Throwable $e) {
        return [$order];
    }
}

function org_shop_update_order_status(PDO $dbh, int $orgId, int $orderId, string $status, string $sellerNotes = ''): bool
{
    $allowed = ['pending', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'];
    if ($orgId <= 0 || $orderId <= 0 || !in_array($status, $allowed, true)) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            UPDATE org_orders
            SET status = :st,
                seller_notes = :notes,
                updated_at = NOW()
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ');
        $st->execute([
            ':st' => $status,
            ':notes' => $sellerNotes !== '' ? $sellerNotes : null,
            ':id' => $orderId,
            ':org' => $orgId,
        ]);
        if ($st->rowCount() <= 0) {
            return false;
        }
        if ($status === 'paid') {
            org_shop_issue_receipt($dbh, $orgId, $orderId);
        }
        if (in_array($status, ['shipped', 'delivered'], true)) {
            org_shop_notify_buyer_order_fulfillment($dbh, $orgId, $orderId, $status);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_issue_receipt(
    PDO $dbh,
    int $orgId,
    int $orderId,
    string $paymentMethod = '',
    string $paymentReference = ''
): int
{
    try {
        $st = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':id' => $orderId, ':org' => $orgId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return 0;
        }

        $stChk = $dbh->prepare('SELECT id FROM org_order_receipts WHERE order_id = :oid LIMIT 1');
        $stChk->execute([':oid' => $orderId]);
        $existing = (int)($stChk->fetchColumn() ?: 0);
        if ($existing > 0) {
            if ($paymentMethod !== '' || $paymentReference !== '') {
                try {
                    $dbh->prepare('
                        UPDATE org_order_receipts
                        SET payment_method = COALESCE(NULLIF(:pm, \'\'), payment_method),
                            payment_reference = COALESCE(NULLIF(:pr, \'\'), payment_reference)
                        WHERE id = :id LIMIT 1
                    ')->execute([
                        ':pm' => $paymentMethod,
                        ':pr' => $paymentReference,
                        ':id' => $existing,
                    ]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            return $existing;
        }

        $sellerName = '';
        $stOrg = $dbh->prepare('SELECT name FROM organizations WHERE id = :id LIMIT 1');
        $stOrg->execute([':id' => $orgId]);
        $sellerName = trim((string)($stOrg->fetchColumn() ?: ''));

        $receiptCode = org_shop_gen_receipt_code($orgId);
        $stripeRef = trim((string)($order['stripe_payment_intent_id'] ?? $order['stripe_checkout_session_id'] ?? ''));
        if ($paymentMethod === '' && $stripeRef !== '') {
            $paymentMethod = 'stripe';
        }
        if ($paymentReference === '' && $stripeRef !== '') {
            $paymentReference = $stripeRef;
        }

        $stIns = $dbh->prepare('
            INSERT INTO org_order_receipts (
                org_id, order_id, receipt_code, buyer_user_id, buyer_name, buyer_email, buyer_phone,
                seller_name, product_title, quantity, unit_price_cents, tax_cents, total_cents, currency,
                payment_method, payment_reference,
                status, issued_at, created_at
            ) VALUES (
                :org, :oid, :code, :uid, :name, :email, :phone,
                :seller, :title, :qty, :unit, :tax, :total, :cur,
                :pm, :pr,
                \'issued\', NOW(), NOW()
            )
        ');
        $stIns->execute([
            ':org' => $orgId,
            ':oid' => $orderId,
            ':code' => $receiptCode,
            ':uid' => $order['buyer_user_id'] ?? null,
            ':name' => $order['buyer_name'] ?? null,
            ':email' => $order['buyer_email'] ?? null,
            ':phone' => $order['buyer_phone'] ?? null,
            ':seller' => $sellerName !== '' ? $sellerName : null,
            ':title' => $order['product_title'] ?? '',
            ':qty' => (int)($order['quantity'] ?? 1),
            ':unit' => (int)($order['unit_price_cents'] ?? 0),
            ':tax' => (int)($order['tax_cents'] ?? 0),
            ':total' => (int)($order['total_cents'] ?? 0),
            ':cur' => $order['currency'] ?? 'USD',
            ':pm' => $paymentMethod !== '' ? substr($paymentMethod, 0, 40) : null,
            ':pr' => $paymentReference !== '' ? substr($paymentReference, 0, 120) : null,
        ]);
        return (int)$dbh->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Required fields before create/update (seller product form).
 *
 * @return array{ok:bool,error?:string}
 */
function org_shop_validate_product_required_fields(PDO $dbh, int $orgId, array $data, ?int $productId = null): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid organization.'];
    }

    // Address is required for create and update.
    if (function_exists('org_ecommerce_seller_has_required_address')) {
        require_once dirname(__DIR__, 2) . '/organization/includes/org_ecommerce.php';
    }
    if (function_exists('org_ecommerce_seller_has_required_address')
        && !org_ecommerce_seller_has_required_address($dbh, $orgId)
    ) {
        return ['ok' => false, 'error' => 'Add your Full Address before you can create or update a product. Address line 1, city, and state are required.'];
    }

    $sellingType = trim((string)($data['selling_type'] ?? ''));
    if ($sellingType === '' || $sellingType === '__add_name__') {
        return ['ok' => false, 'error' => 'Select what you are selling.'];
    }

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'Product title is required.'];
    }

    $priceRaw = trim((string)($data['price'] ?? ''));
    if ($priceRaw === '' || !is_numeric($priceRaw)) {
        return ['ok' => false, 'error' => 'Enter a product price.'];
    }
    if ((float)$priceRaw < 0) {
        return ['ok' => false, 'error' => 'Price cannot be negative.'];
    }

    $stockRaw = trim((string)($data['stock_qty'] ?? ''));
    if ($stockRaw === '' || !is_numeric($stockRaw) || (int)$stockRaw < 0) {
        return ['ok' => false, 'error' => 'Enter stock quantity (0 or more).'];
    }

    $category = trim((string)($data['category'] ?? ''));
    if ($category === '' || $category === '__add_name__') {
        return ['ok' => false, 'error' => 'Select a category.'];
    }

    $status = strtolower(trim((string)($data['status'] ?? '')));
    if (!in_array($status, ['draft', 'active', 'sold_out', 'archived'], true)) {
        return ['ok' => false, 'error' => 'Select a status.'];
    }

    $description = trim((string)($data['description'] ?? ''));
    if ($description === '') {
        return ['ok' => false, 'error' => 'Product description is required.'];
    }

    $deliveryEnabled = !empty($data['delivery_enabled']);
    $pickupEnabled = !empty($data['pickup_enabled']);
    if (!$deliveryEnabled && !$pickupEnabled) {
        return ['ok' => false, 'error' => 'Choose how buyers receive this product: Delivery and/or Pick up.'];
    }
    if ($deliveryEnabled) {
        $carriers = org_shop_normalize_delivery_carriers($data['delivery_carriers'] ?? []);
        if (!$carriers) {
            return ['ok' => false, 'error' => 'Select at least one delivery carrier / trip.'];
        }
    }

    // Product photos required: new upload and/or existing gallery/cover.
    $hasNewPhotos = false;
    if (!empty($_FILES['product_images']) && is_array($_FILES['product_images']['error'] ?? null)) {
        foreach ((array)$_FILES['product_images']['error'] as $errCode) {
            if ((int)$errCode === UPLOAD_ERR_OK) {
                $hasNewPhotos = true;
                break;
            }
        }
    } elseif (!empty($_FILES['product_images']['tmp_name']) && is_string($_FILES['product_images']['tmp_name'])) {
        $hasNewPhotos = (int)($_FILES['product_images']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    $hasExistingPhotos = false;
    $pid = $productId !== null ? (int)$productId : 0;
    if ($pid > 0) {
        $existing = org_shop_list_product_images($dbh, $pid, $orgId);
        $removeIds = [];
        if (isset($data['remove_product_image']) && is_array($data['remove_product_image'])) {
            foreach ($data['remove_product_image'] as $rid) {
                $removeIds[(int)$rid] = true;
            }
        } elseif (isset($_POST['remove_product_image']) && is_array($_POST['remove_product_image'])) {
            foreach ($_POST['remove_product_image'] as $rid) {
                $removeIds[(int)$rid] = true;
            }
        }
        foreach ($existing as $img) {
            $iid = (int)($img['id'] ?? 0);
            if ($iid > 0 && isset($removeIds[$iid])) {
                continue;
            }
            if (trim((string)($img['file_path'] ?? '')) !== '') {
                $hasExistingPhotos = true;
                break;
            }
        }
        if (!$hasExistingPhotos) {
            $prod = org_shop_get_product($dbh, $pid, $orgId);
            if ($prod && trim((string)($prod['cover_image_path'] ?? '')) !== '') {
                $hasExistingPhotos = true;
            }
        }
    }

    if (!$hasNewPhotos && !$hasExistingPhotos) {
        return ['ok' => false, 'error' => 'Upload at least one product photo.'];
    }

    return ['ok' => true];
}

function org_shop_save_product(PDO $dbh, int $orgId, array $data, ?int $productId = null, int $memberId = 0): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid organization.'];
    }

    $gate = org_shop_validate_product_required_fields($dbh, $orgId, $data, $productId);
    if (empty($gate['ok'])) {
        return $gate;
    }

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'Product title is required.'];
    }
    if (mb_strlen($title) > 200) {
        $title = mb_substr($title, 0, 200);
    }

    $priceCents = (int)round((float)($data['price'] ?? 0) * 100);
    if ($priceCents < 0) {
        $priceCents = 0;
    }

    $status = strtolower(trim((string)($data['status'] ?? '')));
    if (!in_array($status, ['draft', 'active', 'sold_out', 'archived'], true)) {
        return ['ok' => false, 'error' => 'Select a status.'];
    }

    $stockRaw = trim((string)($data['stock_qty'] ?? ''));
    $stockQty = $stockRaw === '' ? null : max(0, (int)$stockRaw);
    // 0 stock cannot stay active — sold out and removed from public shop.
    if ($stockQty !== null && $stockQty <= 0 && $status === 'active') {
        $status = 'sold_out';
    }
    $description = trim((string)($data['description'] ?? ''));
    $category = trim((string)($data['category'] ?? ''));
    if (mb_strlen($category) > 80) {
        $category = mb_substr($category, 0, 80);
    }
    $sellingType = trim((string)($data['selling_type'] ?? ''));
    if (mb_strlen($sellingType) > 80) {
        $sellingType = mb_substr($sellingType, 0, 80);
    }
    $attrRaw = $data['product_attr'] ?? [];
    if (!is_array($attrRaw)) {
        $attrRaw = [];
    }
    $productAttributes = org_product_type_normalize_attributes($attrRaw, $sellingType);
    $attributesJson = $productAttributes !== [] ? json_encode($productAttributes, JSON_UNESCAPED_UNICODE) : null;
    $sku = trim((string)($data['sku'] ?? ''));
    if (mb_strlen($sku) > 64) {
        $sku = mb_substr($sku, 0, 64);
    }
    $offerType = strtolower(trim((string)($data['offer_type'] ?? 'physical')));
    if (!in_array($offerType, ['physical', 'digital', 'service', 'subscription', 'license'], true)) {
        $offerType = 'physical';
    }
    $pricingModel = strtolower(trim((string)($data['pricing_model'] ?? 'one_time')));
    if (!in_array($pricingModel, ['one_time', 'recurring', 'quote', 'free', 'wholesale_tier'], true)) {
        $pricingModel = 'one_time';
    }
    $seoTitle = trim((string)($data['seo_title'] ?? ''));
    if (mb_strlen($seoTitle) > 200) {
        $seoTitle = mb_substr($seoTitle, 0, 200);
    }
    $seoDesc = trim((string)($data['seo_description'] ?? ''));
    if (mb_strlen($seoDesc) > 320) {
        $seoDesc = mb_substr($seoDesc, 0, 320);
    }
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'product', '-'));
    if ($slug === '') {
        $slug = 'product';
    }
    $bulletPoints = trim((string)($data['bullet_points'] ?? ''));
    $searchKeywords = trim((string)($data['search_keywords'] ?? ''));
    if (mb_strlen($searchKeywords) > 500) {
        $searchKeywords = mb_substr($searchKeywords, 0, 500);
    }
    $fulfillmentMethod = strtolower(trim((string)($data['fulfillment_method'] ?? 'fbm')));
    if (!in_array($fulfillmentMethod, ['fba', 'fbm'], true)) {
        $fulfillmentMethod = 'fbm';
    }
    $deliveryEnabled = !empty($data['delivery_enabled']) ? 1 : 0;
    $pickupEnabled = !empty($data['pickup_enabled']) ? 1 : 0;
    if ($deliveryEnabled === 0 && $pickupEnabled === 0) {
        return ['ok' => false, 'error' => 'Choose how buyers receive this product: Delivery and/or Pick up.'];
    }
    $carriers = org_shop_normalize_delivery_carriers($data['delivery_carriers'] ?? []);
    if ($deliveryEnabled === 1 && !$carriers) {
        return ['ok' => false, 'error' => 'Select at least one delivery carrier / trip.'];
    }
    if ($deliveryEnabled === 0) {
        $carriers = [];
    }
    $carriersCsv = $carriers ? implode(',', $carriers) : null;
    $shippingFeeCents = 0;
    if ($deliveryEnabled === 1) {
        if (isset($data['shipping_is_free']) && (string)$data['shipping_is_free'] === '1') {
            $shippingFeeCents = 0;
        } else {
            if (isset($data['shipping_fee_cents'])) {
                $shippingFeeCents = max(0, (int)$data['shipping_fee_cents']);
            } else {
                $shippingFeeCents = max(0, (int)round(((float)($data['shipping_fee'] ?? 0)) * 100));
            }
        }
    }

    if ($productId === null || $productId <= 0) {
        $max = org_shop_max_products($dbh, $orgId);
        if (org_shop_product_count($dbh, $orgId) >= $max) {
            return ['ok' => false, 'error' => 'Product limit reached for your rent plan (' . $max . ').'];
        }
    }

    try {
        org_shop_ensure_schema($dbh);
        if ($productId > 0) {
            $st = $dbh->prepare('
                UPDATE org_products
                SET title = :title, sku = :sku, description = :desc, seo_title = :seo_t, seo_description = :seo_d,
                    slug = :slug, bullet_points = :bullets, search_keywords = :keywords, fulfillment_method = :fmethod,
                    delivery_enabled = :deliv, pickup_enabled = :pickup, delivery_carriers = :carriers,
                    shipping_fee_cents = :shipfee,
                    offer_type = :otype, pricing_model = :pmodel,
                    price_cents = :price, stock_qty = :stock,
                    category = :cat, selling_type = :stype, attributes_json = :attrs, status = :status, updated_at = NOW()
                WHERE id = :id AND org_id = :org AND is_deleted = 0
                LIMIT 1
            ');
            $st->execute([
                ':title' => $title,
                ':sku' => $sku !== '' ? $sku : null,
                ':desc' => $description !== '' ? $description : null,
                ':seo_t' => $seoTitle !== '' ? $seoTitle : null,
                ':seo_d' => $seoDesc !== '' ? $seoDesc : null,
                ':slug' => $slug,
                ':bullets' => $bulletPoints !== '' ? $bulletPoints : null,
                ':keywords' => $searchKeywords !== '' ? $searchKeywords : null,
                ':fmethod' => $fulfillmentMethod,
                ':deliv' => $deliveryEnabled,
                ':pickup' => $pickupEnabled,
                ':carriers' => $carriersCsv,
                ':shipfee' => $shippingFeeCents,
                ':otype' => $offerType,
                ':pmodel' => $pricingModel,
                ':price' => $priceCents,
                ':stock' => $stockQty,
                ':cat' => $category !== '' ? $category : null,
                ':stype' => $sellingType !== '' ? $sellingType : null,
                ':attrs' => $attributesJson,
                ':status' => $status,
                ':id' => $productId,
                ':org' => $orgId,
            ]);
            org_shop_ensure_product_code($dbh, $orgId, $productId);
            return ['ok' => true, 'product_id' => $productId];
        }

        $productCode = org_shop_gen_product_code($dbh, $orgId);
        $st = $dbh->prepare('
            INSERT INTO org_products (
                org_id, sku, product_code, title, description, seo_title, seo_description, slug,
                bullet_points, search_keywords, fulfillment_method,
                delivery_enabled, pickup_enabled, delivery_carriers, shipping_fee_cents,
                offer_type, pricing_model,
                price_cents, currency, stock_qty, category, selling_type, attributes_json, status,
                created_by_member_id, created_at, updated_at, is_deleted
            ) VALUES (
                :org, :sku, :pcode, :title, :desc, :seo_t, :seo_d, :slug,
                :bullets, :keywords, :fmethod,
                :deliv, :pickup, :carriers, :shipfee,
                :otype, :pmodel,
                :price, \'USD\', :stock, :cat, :stype, :attrs, :status,
                :member, NOW(), NOW(), 0
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':sku' => $sku !== '' ? $sku : null,
            ':pcode' => $productCode,
            ':title' => $title,
            ':desc' => $description !== '' ? $description : null,
            ':seo_t' => $seoTitle !== '' ? $seoTitle : null,
            ':seo_d' => $seoDesc !== '' ? $seoDesc : null,
            ':slug' => $slug,
            ':bullets' => $bulletPoints !== '' ? $bulletPoints : null,
            ':keywords' => $searchKeywords !== '' ? $searchKeywords : null,
            ':fmethod' => $fulfillmentMethod,
            ':deliv' => $deliveryEnabled,
            ':pickup' => $pickupEnabled,
            ':carriers' => $carriersCsv,
            ':shipfee' => $shippingFeeCents,
            ':otype' => $offerType,
            ':pmodel' => $pricingModel,
            ':price' => $priceCents,
            ':stock' => $stockQty,
            ':cat' => $category !== '' ? $category : null,
            ':stype' => $sellingType !== '' ? $sellingType : null,
            ':attrs' => $attributesJson,
            ':status' => $status,
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        return ['ok' => true, 'product_id' => (int)$dbh->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save product.'];
    }
}

function org_shop_delete_product(PDO $dbh, int $orgId, int $productId): bool
{
    if ($orgId <= 0 || $productId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('UPDATE org_products SET is_deleted = 1, status = \'archived\', updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':id' => $productId, ':org' => $orgId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_handle_cover_upload(int $orgId, int $productId): ?string
{
    if ($orgId <= 0 || $productId <= 0 || empty($_FILES['cover_image']) || !is_array($_FILES['cover_image'])) {
        return null;
    }
    if ((int)($_FILES['cover_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = (string)($_FILES['cover_image']['tmp_name'] ?? '');
    return org_shop_store_uploaded_product_image($orgId, $productId, $tmp);
}

/**
 * Store one uploaded image under organization/uploads/shop/.
 */
function org_shop_store_uploaded_product_image(int $orgId, int $productId, string $tmpPath): ?string
{
    if ($orgId <= 0 || $productId <= 0 || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return null;
    }
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$fi->file($tmpPath);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($map[$mime])) {
        return null;
    }
    $ext = $map[$mime];
    $dir = dirname(__DIR__, 2) . '/organization/uploads/shop';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $fname = 'p' . $productId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $fname;
    if (!move_uploaded_file($tmpPath, $dest)) {
        return null;
    }
    return 'uploads/shop/' . $fname;
}

/** Max gallery photos per product (including cover). */
function org_shop_product_images_max(): int
{
    return 12;
}

/**
 * @return list<array{id:int,org_id:int,product_id:int,file_path:string,sort_order:int}>
 */
function org_shop_list_product_images(PDO $dbh, int $productId, int $orgId = 0): array
{
    if ($productId <= 0) {
        return [];
    }
    try {
        $sql = 'SELECT id, org_id, product_id, file_path, sort_order
                FROM org_product_images
                WHERE product_id = :pid';
        $params = [':pid' => $productId];
        if ($orgId > 0) {
            $sql .= ' AND org_id = :org';
            $params[':org'] = $orgId;
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $path = trim((string)($row['file_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'org_id' => (int)($row['org_id'] ?? 0),
                'product_id' => (int)($row['product_id'] ?? 0),
                'file_path' => $path,
                'sort_order' => (int)($row['sort_order'] ?? 0),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function org_shop_next_product_image_sort(PDO $dbh, int $productId): int
{
    if ($productId <= 0) {
        return 0;
    }
    try {
        $st = $dbh->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM org_product_images WHERE product_id = :pid');
        $st->execute([':pid' => $productId]);
        return ((int)$st->fetchColumn()) + 1;
    } catch (Throwable $e) {
        return 0;
    }
}

function org_shop_add_product_image_row(PDO $dbh, int $orgId, int $productId, string $relPath, int $sortOrder = 0): int
{
    $relPath = trim($relPath);
    if ($orgId <= 0 || $productId <= 0 || $relPath === '') {
        return 0;
    }
    try {
        $st = $dbh->prepare('
            INSERT INTO org_product_images (org_id, product_id, file_path, sort_order, created_at)
            VALUES (:org, :pid, :path, :sort, NOW())
        ');
        $st->execute([
            ':org' => $orgId,
            ':pid' => $productId,
            ':path' => $relPath,
            ':sort' => max(0, $sortOrder),
        ]);
        return (int)$dbh->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Handle multi-file input name="product_images[]".
 * Sets cover when product has none. Returns relative paths saved.
 *
 * @return list<string>
 */
function org_shop_handle_product_images_upload(PDO $dbh, int $orgId, int $productId): array
{
    if ($orgId <= 0 || $productId <= 0 || empty($_FILES['product_images']) || !is_array($_FILES['product_images'])) {
        return [];
    }
    $files = $_FILES['product_images'];
    if (!isset($files['name']) || !is_array($files['name'])) {
        // Single-file accidental submit shape
        if (!isset($files['tmp_name']) || !is_string($files['tmp_name'])) {
            return [];
        }
        $files = [
            'name' => [$files['name'] ?? ''],
            'type' => [$files['type'] ?? ''],
            'tmp_name' => [$files['tmp_name'] ?? ''],
            'error' => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
            'size' => [$files['size'] ?? 0],
        ];
    }

    $existing = org_shop_list_product_images($dbh, $productId, $orgId);
    $existingCount = count($existing);
    $product = org_shop_get_product($dbh, $productId, $orgId);
    $hasCover = $product && trim((string)($product['cover_image_path'] ?? '')) !== '';
    $max = org_shop_product_images_max();
    $sort = org_shop_next_product_image_sort($dbh, $productId);
    $saved = [];

    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        if ($existingCount + count($saved) >= $max) {
            break;
        }
        $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        $rel = org_shop_store_uploaded_product_image($orgId, $productId, $tmp);
        if ($rel === null) {
            continue;
        }
        if (org_shop_add_product_image_row($dbh, $orgId, $productId, $rel, $sort) <= 0) {
            continue;
        }
        $saved[] = $rel;
        $sort++;
        if (!$hasCover) {
            try {
                $dbh->prepare('UPDATE org_products SET cover_image_path = :p, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
                    ->execute([':p' => $rel, ':id' => $productId, ':org' => $orgId]);
                $hasCover = true;
            } catch (Throwable $e) {
                // keep going; image row is already saved
            }
        }
    }
    return $saved;
}

/**
 * @param list<int|string> $imageIds
 */
function org_shop_delete_product_images(PDO $dbh, int $orgId, int $productId, array $imageIds): int
{
    if ($orgId <= 0 || $productId <= 0 || !$imageIds) {
        return 0;
    }
    $ids = [];
    foreach ($imageIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return 0;
    }
    $deleted = 0;
    $root = dirname(__DIR__, 2) . '/organization/';
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_values($ids);
        $params[] = $productId;
        $params[] = $orgId;
        $st = $dbh->prepare("SELECT id, file_path FROM org_product_images WHERE id IN ($in) AND product_id = ? AND org_id = ?");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return 0;
        }
        $del = $dbh->prepare('DELETE FROM org_product_images WHERE id = :id AND product_id = :pid AND org_id = :org LIMIT 1');
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $rel = trim((string)($row['file_path'] ?? ''));
            $del->execute([':id' => $id, ':pid' => $productId, ':org' => $orgId]);
            if ($del->rowCount() > 0) {
                $deleted++;
                if ($rel !== '' && !preg_match('#^https?://#i', $rel)) {
                    $abs = $root . ltrim($rel, '/');
                    if (is_file($abs)) {
                        @unlink($abs);
                    }
                }
            }
        }
        // If cover pointed at a removed file, promote the next gallery image.
        $product = org_shop_get_product($dbh, $productId, $orgId);
        $cover = $product ? trim((string)($product['cover_image_path'] ?? '')) : '';
        $remaining = org_shop_list_product_images($dbh, $productId, $orgId);
        $remainingPaths = array_map(static fn(array $r): string => (string)$r['file_path'], $remaining);
        if ($cover !== '' && !in_array($cover, $remainingPaths, true)) {
            $newCover = $remainingPaths[0] ?? null;
            $dbh->prepare('UPDATE org_products SET cover_image_path = :p, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
                ->execute([':p' => $newCover, ':id' => $productId, ':org' => $orgId]);
        }
    } catch (Throwable $e) {
        return $deleted;
    }
    return $deleted;
}

/**
 * Relative gallery paths: cover first, then extras (no duplicates).
 *
 * @return list<string>
 */
function org_shop_product_gallery_paths(PDO $dbh, array $product): array
{
    $paths = [];
    $cover = trim((string)($product['cover_image_path'] ?? ''));
    if ($cover !== '') {
        $paths[$cover] = $cover;
    }
    $productId = (int)($product['id'] ?? 0);
    $orgId = (int)($product['org_id'] ?? 0);
    if ($productId > 0) {
        foreach (org_shop_list_product_images($dbh, $productId, $orgId) as $img) {
            $path = trim((string)($img['file_path'] ?? ''));
            if ($path !== '') {
                $paths[$path] = $path;
            }
        }
    }
    return array_values($paths);
}

/**
 * Public URLs for buyer gallery.
 *
 * @return list<string>
 */
function org_shop_product_gallery_urls(PDO $dbh, array $product): array
{
    $urls = [];
    foreach (org_shop_product_gallery_paths($dbh, $product) as $path) {
        $url = org_shop_cover_url($path);
        if ($url !== '') {
            $urls[] = $url;
        }
    }
    return $urls;
}

/**
 * After product save: apply removals + multi uploads + legacy single cover.
 */
function org_shop_save_product_images_from_request(PDO $dbh, int $orgId, int $productId): void
{
    if ($orgId <= 0 || $productId <= 0) {
        return;
    }
    $removeIds = $_POST['remove_product_image'] ?? [];
    if (is_array($removeIds) && $removeIds) {
        org_shop_delete_product_images($dbh, $orgId, $productId, $removeIds);
    }

    $coverPath = org_shop_handle_cover_upload($orgId, $productId);
    if ($coverPath !== null) {
        try {
            $dbh->prepare('UPDATE org_products SET cover_image_path = :p, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
                ->execute([':p' => $coverPath, ':id' => $productId, ':org' => $orgId]);
            // Keep cover in gallery too so multi-view stays complete.
            $already = false;
            foreach (org_shop_list_product_images($dbh, $productId, $orgId) as $img) {
                if ((string)$img['file_path'] === $coverPath) {
                    $already = true;
                    break;
                }
            }
            if (!$already && count(org_shop_list_product_images($dbh, $productId, $orgId)) < org_shop_product_images_max()) {
                org_shop_add_product_image_row($dbh, $orgId, $productId, $coverPath, 0);
            }
        } catch (Throwable $e) {
            // ignore cover write failure
        }
    }

    org_shop_handle_product_images_upload($dbh, $orgId, $productId);
}

/** @return list<array<string, mixed>> */
function org_shop_list_marketplace_products(PDO $dbh, int $limit = 120): array
{
    $limit = max(1, min($limit, 200));
    try {
        $st = $dbh->prepare("
            SELECT p.*,
                   o.name AS seller_name,
                   o.publisher_user_id,
                   o.commerce_brand_id,
                   o.publisher_category,
                   u.username AS publisher_username,
                   u.name AS publisher_name,
                   cb.slug AS commerce_brand_slug,
                   cb.name AS commerce_brand_name,
                   cb.tagline AS commerce_brand_tagline,
                   cb.accent_color AS commerce_brand_color,
                   cb.icon_letter AS commerce_brand_icon
            FROM org_products p
            INNER JOIN organizations o ON o.id = p.org_id AND o.status = 1
            INNER JOIN users u ON u.id = o.publisher_user_id
            LEFT JOIN commerce_brands cb ON cb.id = o.commerce_brand_id AND cb.is_active = 1
            WHERE p.is_deleted = 0
              AND p.status = 'active'
              AND (p.stock_qty IS NULL OR p.stock_qty > 0)
              AND o.publisher_user_id IS NOT NULL
              AND o.publisher_user_id > 0
              AND o.commerce_brand_id IS NOT NULL
              AND o.commerce_brand_id > 0
              AND LOWER(TRIM(COALESCE(o.publisher_category, ''))) IN ('', 'commerce')
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT {$limit}
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    $syncedOrgs = [];
    foreach ($rows as $row) {
        $orgId = (int)($row['org_id'] ?? 0);
        if ($orgId > 0 && !isset($syncedOrgs[$orgId])) {
            org_shop_sync_org_sold_out_stock($dbh, $orgId);
            $syncedOrgs[$orgId] = true;
        }
        if ($orgId <= 0 || !org_is_commerce_seller_row($row) || !platform_rent_shop_is_visible($dbh, $orgId)) {
            continue;
        }
        if ($row['stock_qty'] !== null && (int)$row['stock_qty'] <= 0) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function org_shop_publisher_user_id(PDO $dbh, int $orgId): int
{
    if ($orgId <= 0) {
        return 0;
    }
    try {
        $st = $dbh->prepare('SELECT publisher_user_id FROM organizations WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orgId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array<string, mixed>|null */
function org_shop_get_marketplace_product(PDO $dbh, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare("
            SELECT p.*,
                   o.name AS seller_name,
                   o.publisher_user_id,
                   o.commerce_brand_id,
                   o.publisher_category,
                   u.username AS publisher_username,
                   u.name AS publisher_name,
                   cb.slug AS commerce_brand_slug,
                   cb.name AS commerce_brand_name,
                   cb.tagline AS commerce_brand_tagline,
                   cb.accent_color AS commerce_brand_color,
                   cb.icon_letter AS commerce_brand_icon
            FROM org_products p
            INNER JOIN organizations o ON o.id = p.org_id AND o.status = 1
            INNER JOIN users u ON u.id = o.publisher_user_id
            LEFT JOIN commerce_brands cb ON cb.id = o.commerce_brand_id AND cb.is_active = 1
            WHERE p.id = :id
              AND p.is_deleted = 0
              AND p.status = 'active'
              AND (p.stock_qty IS NULL OR p.stock_qty > 0)
              AND o.publisher_user_id IS NOT NULL
              AND o.publisher_user_id > 0
              AND o.commerce_brand_id IS NOT NULL
              AND o.commerce_brand_id > 0
              AND LOWER(TRIM(COALESCE(o.publisher_category, ''))) IN ('', 'commerce')
            LIMIT 1
        ");
        $st->execute([':id' => $productId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $orgId = (int)($row['org_id'] ?? 0);
        if ($orgId > 0) {
            org_shop_sync_org_sold_out_stock($dbh, $orgId);
            // Re-check after sync in case this product just sold out.
            if (strtolower((string)($row['status'] ?? '')) === 'active'
                && $row['stock_qty'] !== null
                && (int)$row['stock_qty'] <= 0) {
                return null;
            }
            $fresh = org_shop_get_product($dbh, $productId, $orgId);
            if (!$fresh || strtolower((string)($fresh['status'] ?? '')) !== 'active') {
                return null;
            }
            if ($fresh['stock_qty'] !== null && (int)$fresh['stock_qty'] <= 0) {
                return null;
            }
            // Keep buyer-facing type details in sync with the product row.
            foreach (['selling_type', 'attributes_json', 'category', 'description', 'bullet_points', 'title', 'sku', 'product_code', 'price_cents', 'currency', 'stock_qty', 'cover_image_path'] as $field) {
                if (array_key_exists($field, $fresh)) {
                    $row[$field] = $fresh[$field];
                }
            }
            $row['product_code'] = org_shop_ensure_product_code(
                $dbh,
                $orgId,
                $productId,
                isset($row['product_code']) ? (string)$row['product_code'] : null
            );
        }
        if ($orgId <= 0 || !org_is_commerce_seller_row($row) || !platform_rent_shop_is_visible($dbh, $orgId)) {
            return null;
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/** @return list<array<string, mixed>> */
function org_shop_list_buyer_orders(PDO $dbh, int $buyerUserId, int $limit = 100): array
{
    if ($buyerUserId <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 200));
    try {
        $st = $dbh->prepare("
            SELECT o.*,
                   org.name AS seller_name,
                   org.publisher_user_id,
                   org.commerce_brand_id,
                   cb.slug AS commerce_brand_slug,
                   cb.name AS commerce_brand_name,
                   COALESCE(NULLIF(TRIM(o.product_title), ''), p.title, 'Product') AS product_title,
                   p.cover_image_path,
                   p.category,
                   r.receipt_code,
                   r.id AS receipt_id
            FROM org_orders o
            INNER JOIN organizations org ON org.id = o.org_id
              AND org.commerce_brand_id IS NOT NULL
              AND org.commerce_brand_id > 0
              AND LOWER(TRIM(COALESCE(org.publisher_category, ''))) IN ('', 'commerce')
            LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id AND cb.is_active = 1
            LEFT JOIN org_products p ON p.id = o.product_id AND p.is_deleted = 0
            LEFT JOIN org_order_receipts r ON r.order_id = o.id
            WHERE o.buyer_user_id = :uid
              AND o.buyer_hidden_at IS NULL
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT {$limit}
        ");
        $st->execute([':uid' => $buyerUserId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $st = $dbh->prepare("
                SELECT o.*,
                       org.name AS seller_name,
                       org.publisher_user_id,
                       org.commerce_brand_id,
                       cb.slug AS commerce_brand_slug,
                       cb.name AS commerce_brand_name,
                       COALESCE(NULLIF(TRIM(o.product_title), ''), p.title, 'Product') AS product_title,
                       p.cover_image_path,
                       p.category,
                       r.receipt_code,
                       r.id AS receipt_id
                FROM org_orders o
                INNER JOIN organizations org ON org.id = o.org_id
                  AND org.commerce_brand_id IS NOT NULL
                  AND org.commerce_brand_id > 0
                  AND LOWER(TRIM(COALESCE(org.publisher_category, ''))) IN ('', 'commerce')
                LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id AND cb.is_active = 1
                LEFT JOIN org_products p ON p.id = o.product_id AND p.is_deleted = 0
                LEFT JOIN org_order_receipts r ON r.order_id = o.id
                WHERE o.buyer_user_id = :uid
                ORDER BY o.created_at DESC, o.id DESC
                LIMIT {$limit}
            ");
            $st->execute([':uid' => $buyerUserId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }
}

/** Shop brand-group URL for a buyer order row (`shop.php?cbrand=…`), or empty if unknown. */
function org_shop_order_brand_shop_url(array $order): string
{
    $slug = trim((string)($order['commerce_brand_slug'] ?? ''));
    if ($slug === '') {
        return '';
    }
    if (!function_exists('org_commerce_brands_shop_url')) {
        require_once __DIR__ . '/org_commerce_brands.php';
    }
    return org_commerce_brands_shop_url($slug);
}

/**
 * Remove an order from the buyer's My Orders list (seller records unchanged).
 *
 * @return array{ok:bool,error?:string}
 */
function org_shop_hide_buyer_order(PDO $dbh, int $orderId, int $buyerUserId): array
{
    org_shop_ensure_schema($dbh);
    if ($orderId <= 0 || $buyerUserId <= 0) {
        return ['ok' => false, 'error' => 'Invalid order.'];
    }
    try {
        $st = $dbh->prepare('
            SELECT id, status, buyer_hidden_at
            FROM org_orders
            WHERE id = :id AND buyer_user_id = :uid
            LIMIT 1
        ');
        $st->execute([':id' => $orderId, ':uid' => $buyerUserId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        if (!empty($order['buyer_hidden_at'])) {
            return ['ok' => true];
        }
        $upd = $dbh->prepare('
            UPDATE org_orders
            SET buyer_hidden_at = NOW(), updated_at = NOW()
            WHERE id = :id AND buyer_user_id = :uid AND buyer_hidden_at IS NULL
            LIMIT 1
        ');
        $upd->execute([':id' => $orderId, ':uid' => $buyerUserId]);
        if ($upd->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'Could not remove this order.'];
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove this order.'];
    }
}

function org_shop_format_seller_address(?array $address): string
{
    if (!$address) {
        return '';
    }
    $lines = [];
    $line1 = trim((string)($address['line1'] ?? ''));
    $line2 = trim((string)($address['line2'] ?? ''));
    $city = trim((string)($address['city'] ?? ''));
    $state = trim((string)($address['state'] ?? ''));
    $postal = trim((string)($address['postal_code'] ?? ''));
    $country = trim((string)($address['country'] ?? ''));

    if ($line1 !== '') {
        $lines[] = $line1;
    }
    if ($line2 !== '') {
        $lines[] = $line2;
    }
    $cityLine = $city;
    if ($state !== '') {
        $cityLine = $cityLine !== '' ? $cityLine . ', ' . $state : $state;
    }
    if ($postal !== '') {
        $cityLine = trim($cityLine . ' ' . $postal);
    }
    if ($cityLine !== '') {
        $lines[] = $cityLine;
    }
    if ($country !== '') {
        $lines[] = $country;
    }
    return implode("\n", $lines);
}

/** @return list<string> */
function org_shop_delivery_carrier_keys(): array
{
    return ['ups', 'fedex', 'usps', 'own_trip', 'other'];
}

/** @return array<string, string> */
function org_shop_delivery_carrier_labels(): array
{
    return [
        'ups' => 'UPS',
        'fedex' => 'FedEx',
        'usps' => 'USPS',
        'own_trip' => "Seller's own trip",
        'other' => 'Other carrier',
    ];
}

/**
 * @param mixed $raw
 * @return list<string>
 */
function org_shop_normalize_delivery_carriers($raw): array
{
    $allowed = org_shop_delivery_carrier_keys();
    $parts = [];
    if (is_array($raw)) {
        $parts = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $parts = preg_split('/[\s,]+/', strtolower(trim($raw))) ?: [];
    }
    $out = [];
    foreach ($parts as $p) {
        $key = strtolower(trim((string)$p));
        if (in_array($key, $allowed, true) && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    return $out;
}

/**
 * @return array{delivery_enabled:bool,pickup_enabled:bool,carriers:list<string>,carrier_labels:list<string>,shipping_fee_cents:int}
 */
function org_shop_product_receive_options(array $product): array
{
    $hasDeliveryCol = array_key_exists('delivery_enabled', $product);
    $deliveryEnabled = $hasDeliveryCol ? ((int)($product['delivery_enabled'] ?? 0) === 1) : true;
    $pickupEnabled = !empty($product['pickup_enabled']);
    if (!$deliveryEnabled && !$pickupEnabled) {
        $deliveryEnabled = true;
    }
    $carriers = org_shop_normalize_delivery_carriers($product['delivery_carriers'] ?? '');
    $labels = org_shop_delivery_carrier_labels();
    $carrierLabels = [];
    foreach ($carriers as $key) {
        $carrierLabels[] = $labels[$key] ?? $key;
    }
    $shippingFeeCents = max(0, (int)($product['shipping_fee_cents'] ?? 0));
    return [
        'delivery_enabled' => $deliveryEnabled,
        'pickup_enabled' => $pickupEnabled,
        'carriers' => $carriers,
        'carrier_labels' => $carrierLabels,
        'shipping_fee_cents' => $shippingFeeCents,
    ];
}

function org_shop_seller_pickup_address_text(PDO $dbh, int $orgId): string
{
    if ($orgId <= 0) {
        return '';
    }
    try {
        $st = $dbh->prepare('SELECT shop_json FROM org_settings WHERE org_id = :org LIMIT 1');
        $st->execute([':org' => $orgId]);
        $raw = $st->fetchColumn();
        if (!$raw) {
            return '';
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        $addr = $decoded['address'] ?? null;
        return is_array($addr) ? org_shop_format_seller_address($addr) : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Buyer-facing seller contact/location for pickup door and product Seller tab.
 *
 * @return array{text:string,store_name:string,full_name:string,tagline:string,address:string,phone:string,email:string,has_address:bool}
 */
function org_shop_seller_pickup_display(PDO $dbh, int $orgId): array
{
    $out = [
        'text' => '',
        'store_name' => '',
        'full_name' => '',
        'tagline' => '',
        'address' => '',
        'phone' => '',
        'email' => '',
        'has_address' => false,
    ];
    if ($orgId <= 0) {
        return $out;
    }
    try {
        $storeName = '';
        $stOrg = $dbh->prepare('SELECT name FROM organizations WHERE id = :id LIMIT 1');
        $stOrg->execute([':id' => $orgId]);
        $storeName = trim((string)($stOrg->fetchColumn() ?: ''));

        $st = $dbh->prepare('SELECT shop_json FROM org_settings WHERE org_id = :org LIMIT 1');
        $st->execute([':org' => $orgId]);
        $raw = $st->fetchColumn();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $jsonStore = trim((string)($decoded['store_name'] ?? ''));
        $fullName = trim((string)($decoded['full_name'] ?? ''));
        $tagline = trim((string)($decoded['tagline'] ?? ''));
        $phone = trim((string)($decoded['contact_phone'] ?? ''));
        $email = trim((string)($decoded['contact_email'] ?? ''));
        $addr = is_array($decoded['address'] ?? null) ? $decoded['address'] : [];
        $address = org_shop_format_seller_address($addr);
        if ($jsonStore !== '') {
            $storeName = $jsonStore;
        }

        $lines = [];
        if ($storeName !== '') {
            $lines[] = $storeName;
        } elseif ($fullName !== '') {
            $lines[] = $fullName;
        }
        if ($address !== '') {
            $lines[] = $address;
            $out['has_address'] = true;
        }
        if ($phone !== '') {
            $lines[] = 'Phone: ' . $phone;
        }
        $out['store_name'] = $storeName !== '' ? $storeName : $fullName;
        $out['full_name'] = $fullName;
        $out['tagline'] = $tagline;
        $out['address'] = $address;
        $out['phone'] = $phone;
        $out['email'] = $email;
        $out['text'] = implode("\n", $lines);
        return $out;
    } catch (Throwable $e) {
        return $out;
    }
}

/** @return array<string, mixed>|null */
function org_shop_get_buyer_order(PDO $dbh, int $buyerUserId, int $orderId): ?array
{
    if ($buyerUserId <= 0 || $orderId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare("
            SELECT o.*,
                   org.name AS seller_name,
                   org.publisher_user_id,
                   p.cover_image_path,
                   p.category,
                   r.receipt_code,
                   r.id AS receipt_id,
                   r.tax_cents,
                   os.shop_json
            FROM org_orders o
            LEFT JOIN organizations org ON org.id = o.org_id
            LEFT JOIN org_settings os ON os.org_id = o.org_id
            LEFT JOIN org_products p ON p.id = o.product_id AND p.is_deleted = 0
            LEFT JOIN org_order_receipts r ON r.order_id = o.id
            WHERE o.buyer_user_id = :uid AND o.id = :oid
            LIMIT 1
        ");
        $st->execute([':uid' => $buyerUserId, ':oid' => $orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $sellerAddress = '';
        $sellerEmail = '';
        $sellerPhone = '';
        $storeName = '';
        $shopJson = trim((string)($row['shop_json'] ?? ''));
        $decoded = null;
        if ($shopJson !== '') {
            $decoded = json_decode($shopJson, true);
            if (is_array($decoded)) {
                if (is_array($decoded['address'] ?? null)) {
                    $sellerAddress = org_shop_format_seller_address($decoded['address']);
                }
                $sellerEmail = trim((string)($decoded['contact_email'] ?? ''));
                $sellerPhone = trim((string)($decoded['contact_phone'] ?? ''));
                $storeName = trim((string)($decoded['store_name'] ?? ''));
            }
        }
        $publisherUserId = (int)($row['publisher_user_id'] ?? 0);
        if (($sellerEmail === '' || $sellerPhone === '') && $publisherUserId > 0) {
            try {
                require_once __DIR__ . '/user_phone.php';
                $stU = $dbh->prepare('SELECT email, mobile FROM users WHERE id = :id LIMIT 1');
                $stU->execute([':id' => $publisherUserId]);
                $user = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($sellerEmail === '') {
                    $sellerEmail = trim((string)($user['email'] ?? ''));
                }
                if ($sellerPhone === '') {
                    $sellerPhone = function_exists('user_phone_from_user_row')
                        ? user_phone_from_user_row($user)
                        : trim((string)($user['mobile'] ?? ''));
                    if (strcasecmp($sellerPhone, 'N/A') === 0) {
                        $sellerPhone = '';
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        if ($storeName !== '') {
            $row['seller_name'] = $storeName;
        }
        $row['seller_address'] = $sellerAddress;
        $row['seller_email'] = $sellerEmail;
        $row['seller_phone'] = $sellerPhone;
        unset($row['shop_json']);
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function org_shop_attach_stripe_session(PDO $dbh, int $orderId, string $sessionId): bool
{
    if ($orderId <= 0 || trim($sessionId) === '') {
        return false;
    }
    try {
        $st = $dbh->prepare('
            UPDATE org_orders
            SET stripe_checkout_session_id = :sid, updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':sid' => $sessionId, ':id' => $orderId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_fulfill_stripe_payment(
    PDO $dbh,
    int $orderId,
    string $sessionId = '',
    string $paymentIntentId = ''
): bool {
    if ($orderId <= 0) {
        return false;
    }
    org_shop_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return false;
        }
        $orgId = (int)($order['org_id'] ?? 0);
        $status = (string)($order['status'] ?? '');
        if (in_array($status, ['paid', 'shipped', 'delivered'], true)) {
            return true;
        }

        $sql = '
            UPDATE org_orders
            SET status = \'paid\',
                paid_at = NOW(),
                updated_at = NOW()
        ';
        $params = [':id' => $orderId];
        if ($sessionId !== '') {
            $sql .= ', stripe_checkout_session_id = :sid';
            $params[':sid'] = $sessionId;
        }
        if ($paymentIntentId !== '') {
            $sql .= ', stripe_payment_intent_id = :pid';
            $params[':pid'] = $paymentIntentId;
        }
        $sql .= ' WHERE id = :id LIMIT 1';

        $dbh->prepare($sql)->execute($params);
        org_shop_apply_order_fees($dbh, $orderId);
        $payRef = $paymentIntentId !== '' ? $paymentIntentId : $sessionId;
        org_shop_issue_receipt($dbh, $orgId, $orderId, 'stripe', $payRef);

        $buyerUserId = (int)($order['buyer_user_id'] ?? 0);
        $orderCode = (string)($order['order_code'] ?? '');
        org_shop_notify_seller_order_status($dbh, $orgId, $buyerUserId, 'paid', [$orderCode]);

        $fbaShipped = org_shop_auto_fulfill_fba_order($dbh, $orderId);
        if ($fbaShipped) {
            org_shop_notify_seller_order_status(
                $dbh,
                $orgId,
                $buyerUserId,
                'shipped',
                [$orderCode],
                'Platform Fulfillment'
            );
            if (function_exists('org_shop_notify_buyer_order_fulfillment')) {
                org_shop_notify_buyer_order_fulfillment($dbh, $orgId, $orderId, 'shipped');
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_fulfill_stripe_session(PDO $dbh, array $session): bool
{
    $orderId = (int)($session['metadata']['order_id'] ?? 0);
    if ($orderId <= 0) {
        $code = trim((string)($session['client_reference_id'] ?? ''));
        if ($code !== '') {
            try {
                $st = $dbh->prepare('SELECT id FROM org_orders WHERE order_code = :c LIMIT 1');
                $st->execute([':c' => $code]);
                $orderId = (int)($st->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $orderId = 0;
            }
        }
    }
    if ($orderId <= 0) {
        return false;
    }

    $paymentStatus = (string)($session['payment_status'] ?? '');
    if ($paymentStatus !== 'paid') {
        return false;
    }

    $sessionId = trim((string)($session['id'] ?? ''));
    $paymentIntent = $session['payment_intent'] ?? '';
    if (is_array($paymentIntent)) {
        $paymentIntent = (string)($paymentIntent['id'] ?? '');
    }
    $paymentIntent = trim((string)$paymentIntent);

    return org_shop_fulfill_stripe_payment($dbh, $orderId, $sessionId, $paymentIntent);
}

function org_shop_copy_product_image_to_public_post(PDO $dbh, int $publicPostId, string $coverRelPath): bool
{
    $rel = ltrim(str_replace('\\', '/', trim($coverRelPath)), '/');
    if ($publicPostId <= 0 || $rel === '') {
        return false;
    }

    $orgRoot = dirname(__DIR__, 2) . '/organization';
    $srcAbs = $orgRoot . '/' . $rel;
    if (!is_file($srcAbs)) {
        return false;
    }

    $baseDir = dirname(__DIR__) . '/uploads/posts';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }
    $subDir = $baseDir . '/' . date('Ym');
    if (!is_dir($subDir)) {
        @mkdir($subDir, 0775, true);
    }

    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'jpg';
    }
    $fname = 'shop' . $publicPostId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destAbs = $subDir . '/' . $fname;
    if (!@copy($srcAbs, $destAbs)) {
        return false;
    }

    $webPath = 'uploads/posts/' . date('Ym') . '/' . $fname;
    try {
        $st = $dbh->prepare('
            INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, created_at)
            VALUES (:pid, \'image\', :fp, NULL, NOW())
        ');
        $st->execute([':pid' => $publicPostId, ':fp' => $webPath]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_publish_product_to_feed(PDO $dbh, int $orgId, int $productId): array
{
    require_once dirname(__DIR__, 2) . '/organization/includes/org_public_publish.php';

    $product = org_shop_get_product($dbh, $productId, $orgId);
    if (!$product || (string)($product['status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'Product must be active to publish.'];
    }

    $publisherUserId = org_shop_publisher_user_id($dbh, $orgId);
    if ($publisherUserId <= 0) {
        return ['ok' => false, 'error' => 'No publisher profile linked to this organization.'];
    }

    if (!platform_rent_shop_is_visible($dbh, $orgId)) {
        return ['ok' => false, 'error' => 'Shop is hidden until rent is active.'];
    }

    $title = trim((string)($product['title'] ?? ''));
    $priceLabel = org_shop_format_price((int)($product['price_cents'] ?? 0), (string)($product['currency'] ?? 'USD'));
    $shopUrl = 'profile.php?tab=shop&id=' . $publisherUserId;
    $bodyLines = [];
    if (trim((string)($product['description'] ?? '')) !== '') {
        $bodyLines[] = trim((string)$product['description']);
    }
    $bodyLines[] = 'Price: ' . $priceLabel;
    $bodyLines[] = 'Shop: ' . $shopUrl;
    $body = implode("\n\n", $bodyLines);

    $publicPostId = org_public_publish_from_org_post(
        $dbh,
        $publisherUserId,
        $orgId,
        0,
        $title,
        $body
    );

    if ($publicPostId <= 0) {
        return ['ok' => false, 'error' => 'Could not publish to feed.'];
    }

    $cover = trim((string)($product['cover_image_path'] ?? ''));
    if ($cover !== '') {
        org_shop_copy_product_image_to_public_post($dbh, $publicPostId, $cover);
    }

    try {
        $dbh->prepare('UPDATE org_products SET public_post_id = :pid, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
            ->execute([':pid' => $publicPostId, ':id' => $productId, ':org' => $orgId]);
    } catch (Throwable $e) {
        // non-fatal
    }

    return ['ok' => true, 'public_post_id' => $publicPostId];
}

function org_shop_get_seller_plan(PDO $dbh, int $orgId): string
{
    if ($orgId <= 0) {
        return 'individual';
    }
    org_shop_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT seller_plan FROM organizations WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orgId]);
        $plan = strtolower(trim((string)($st->fetchColumn() ?: 'individual')));
        return in_array($plan, ['individual', 'professional'], true) ? $plan : 'individual';
    } catch (Throwable $e) {
        return 'individual';
    }
}

function org_shop_save_seller_plan(PDO $dbh, int $orgId, string $plan): bool
{
    $plan = strtolower(trim($plan));
    if ($orgId <= 0 || !in_array($plan, ['individual', 'professional'], true)) {
        return false;
    }
    org_shop_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('UPDATE organizations SET seller_plan = :plan, updated_at = NOW() WHERE id = :id LIMIT 1');
        $st->execute([':plan' => $plan, ':id' => $orgId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Marketplace fees are the seller's responsibility (deducted from payout).
 * Customer total stays merchandise + shipping; seller receives base minus fees.
 *
 * @return array{
 *   referral_fee_cents:int,
 *   fulfillment_fee_cents:int,
 *   platform_fee_cents:int,
 *   fee_total_cents:int,
 *   seller_payout_cents:int,
 *   customer_total_cents:int
 * }
 */
function org_shop_calculate_order_fees(PDO $dbh, int $orgId, int $baseCents, string $fulfillmentMethod): array
{
    $baseCents = max(0, $baseCents);
    $fulfillmentMethod = strtolower(trim($fulfillmentMethod));
    $sellerPlan = org_shop_get_seller_plan($dbh, $orgId);

    $referralFee = (int)round($baseCents * 0.15);
    $fulfillmentFee = 0;
    if ($fulfillmentMethod === 'fba') {
        $fulfillmentFee = max(350, (int)round($baseCents * 0.05));
    }
    $platformFee = $sellerPlan === 'individual' ? 99 : 0;
    $feeTotal = $referralFee + $fulfillmentFee + $platformFee;
    $sellerPayout = max(0, $baseCents - $feeTotal);

    return [
        'referral_fee_cents' => $referralFee,
        'fulfillment_fee_cents' => $fulfillmentFee,
        'platform_fee_cents' => $platformFee,
        'fee_total_cents' => $feeTotal,
        'seller_payout_cents' => $sellerPayout,
        'customer_total_cents' => $baseCents,
    ];
}

function org_shop_apply_order_fees(PDO $dbh, int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }
    org_shop_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT org_id, total_cents, fulfillment_method,
                   COALESCE(tax_cents, 0) AS tax_cents,
                   COALESCE(service_fee_cents, 0) AS service_fee_cents,
                   COALESCE(shipping_fee_cents, 0) AS shipping_fee_cents,
                   COALESCE(unit_price_cents, 0) AS unit_price_cents,
                   COALESCE(quantity, 1) AS quantity,
                   COALESCE(discount_cents, 0) AS discount_cents
            FROM org_orders
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return false;
        }
        $taxCents = max(0, (int)($order['tax_cents'] ?? 0));
        $serviceFeeCents = max(0, (int)($order['service_fee_cents'] ?? 0));
        $totalCents = max(0, (int)($order['total_cents'] ?? 0));
        // Fees are seller-paid on merchandise + shipping (not on sales tax or buyer service fee).
        $feeBaseCents = max(0, $totalCents - $taxCents - $serviceFeeCents);
        if ($feeBaseCents <= 0) {
            $qty = max(1, (int)($order['quantity'] ?? 1));
            $feeBaseCents = max(
                0,
                ((int)($order['unit_price_cents'] ?? 0) * $qty) - (int)($order['discount_cents'] ?? 0)
            ) + max(0, (int)($order['shipping_fee_cents'] ?? 0));
        }
        $fees = org_shop_calculate_order_fees(
            $dbh,
            (int)($order['org_id'] ?? 0),
            $feeBaseCents,
            (string)($order['fulfillment_method'] ?? 'fbm')
        );
        $up = $dbh->prepare('
            UPDATE org_orders
            SET referral_fee_cents = :ref,
                fulfillment_fee_cents = :ful,
                platform_fee_cents = :plat,
                seller_payout_cents = :pay,
                payout_status = \'scheduled\',
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $up->execute([
            ':ref' => $fees['referral_fee_cents'],
            ':ful' => $fees['fulfillment_fee_cents'],
            ':plat' => $fees['platform_fee_cents'],
            ':pay' => $fees['seller_payout_cents'],
            ':id' => $orderId,
        ]);
        return true;
    } catch (Throwable $e) {
        // Older schemas may lack tax_cents — fall back to total-only base.
        try {
            $st = $dbh->prepare('SELECT org_id, total_cents, fulfillment_method FROM org_orders WHERE id = :id LIMIT 1');
            $st->execute([':id' => $orderId]);
            $order = $st->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                return false;
            }
            $fees = org_shop_calculate_order_fees(
                $dbh,
                (int)($order['org_id'] ?? 0),
                (int)($order['total_cents'] ?? 0),
                (string)($order['fulfillment_method'] ?? 'fbm')
            );
            $dbh->prepare('
                UPDATE org_orders
                SET referral_fee_cents = :ref,
                    fulfillment_fee_cents = :ful,
                    platform_fee_cents = :plat,
                    seller_payout_cents = :pay,
                    payout_status = \'scheduled\',
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ')->execute([
                ':ref' => $fees['referral_fee_cents'],
                ':ful' => $fees['fulfillment_fee_cents'],
                ':plat' => $fees['platform_fee_cents'],
                ':pay' => $fees['seller_payout_cents'],
                ':id' => $orderId,
            ]);
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function org_shop_auto_fulfill_fba_order(PDO $dbh, int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('SELECT order_code, fulfillment_method, status FROM org_orders WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order || (string)($order['fulfillment_method'] ?? '') !== 'fba') {
            return false;
        }
        if (!in_array((string)($order['status'] ?? ''), ['paid'], true)) {
            return false;
        }
        $track = 'PLATFORM-FBA-' . (string)($order['order_code'] ?? $orderId);
        $up = $dbh->prepare('
            UPDATE org_orders
            SET status = \'shipped\',
                carrier = \'Platform Fulfillment\',
                tracking_number = :track,
                shipped_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $up->execute([':track' => $track, ':id' => $orderId]);
        return $up->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array{rating:float,count:int} */
function org_shop_product_rating_stats(PDO $dbh, int $productId): array
{
    if ($productId <= 0) {
        return ['rating' => 0.0, 'count' => 0];
    }
    org_shop_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM org_product_reviews WHERE product_id = :pid');
        $st->execute([':pid' => $productId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $count = (int)($row['cnt'] ?? 0);
        if ($count <= 0) {
            return ['rating' => 0.0, 'count' => 0];
        }
        return ['rating' => round((float)($row['avg_rating'] ?? 0), 1), 'count' => $count];
    } catch (Throwable $e) {
        return ['rating' => 0.0, 'count' => 0];
    }
}

function org_shop_product_display_rating(PDO $dbh, int $productId): int
{
    $stats = org_shop_product_rating_stats($dbh, $productId);
    if ($stats['count'] > 0) {
        return max(1, min(5, (int)round($stats['rating'])));
    }
    return 4 + ($productId % 2);
}

function org_shop_submit_review(PDO $dbh, int $orderId, int $buyerUserId, int $rating, string $reviewText = ''): array
{
    org_shop_ensure_schema($dbh);
    $rating = max(1, min(5, $rating));
    if ($orderId <= 0 || $buyerUserId <= 0) {
        return ['ok' => false, 'error' => 'Invalid review request.'];
    }
    try {
        $st = $dbh->prepare('
            SELECT id, org_id, product_id, status, buyer_user_id
            FROM org_orders
            WHERE id = :id AND buyer_user_id = :uid
            LIMIT 1
        ');
        $st->execute([':id' => $orderId, ':uid' => $buyerUserId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        if ((string)($order['status'] ?? '') !== 'delivered') {
            return ['ok' => false, 'error' => 'You can review after delivery is confirmed.'];
        }
        $reviewText = trim($reviewText);
        $ins = $dbh->prepare('
            INSERT INTO org_product_reviews (org_id, product_id, order_id, buyer_user_id, rating, review_text, created_at)
            VALUES (:org, :pid, :oid, :uid, :rating, :txt, NOW())
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text), updated_at = NOW()
        ');
        $ins->execute([
            ':org' => (int)($order['org_id'] ?? 0),
            ':pid' => (int)($order['product_id'] ?? 0),
            ':oid' => $orderId,
            ':uid' => $buyerUserId,
            ':rating' => $rating,
            ':txt' => $reviewText !== '' ? $reviewText : null,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save review.'];
    }
}

function org_shop_request_return(PDO $dbh, int $orderId, int $buyerUserId, string $reason): array
{
    org_shop_ensure_schema($dbh);
    $reason = trim($reason);
    if ($orderId <= 0 || $buyerUserId <= 0 || $reason === '') {
        return ['ok' => false, 'error' => 'Return reason is required.'];
    }
    try {
        $st = $dbh->prepare('
            SELECT id, org_id, status, buyer_user_id
            FROM org_orders
            WHERE id = :id AND buyer_user_id = :uid
            LIMIT 1
        ');
        $st->execute([':id' => $orderId, ':uid' => $buyerUserId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        if (!in_array((string)($order['status'] ?? ''), ['paid', 'shipped', 'delivered'], true)) {
            return ['ok' => false, 'error' => 'This order cannot be returned yet.'];
        }
        $ins = $dbh->prepare('
            INSERT INTO org_order_returns (org_id, order_id, buyer_user_id, reason, status, created_at)
            VALUES (:org, :oid, :uid, :reason, \'requested\', NOW())
        ');
        $ins->execute([
            ':org' => (int)($order['org_id'] ?? 0),
            ':oid' => $orderId,
            ':uid' => $buyerUserId,
            ':reason' => mb_substr($reason, 0, 500),
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not submit return request.'];
    }
}

/**
 * Buyer cancels an order before shipment. Updates shared org_orders.status so the seller sees it immediately.
 * @return array{ok:bool,error?:string}
 */
function org_shop_buyer_cancel_order(PDO $dbh, int $orderId, int $buyerUserId, string $reason = ''): array
{
    org_shop_ensure_schema($dbh);
    $reason = trim($reason);
    if ($orderId <= 0 || $buyerUserId <= 0) {
        return ['ok' => false, 'error' => 'Invalid order.'];
    }
    if ($reason === '') {
        $reason = 'Changed mind';
    }
    $cancellable = ['pending', 'confirmed', 'paid'];
    try {
        $st = $dbh->prepare('
            SELECT id, org_id, product_id, quantity, status, buyer_notes, seller_notes, order_code
            FROM org_orders
            WHERE id = :id AND buyer_user_id = :uid
            LIMIT 1
        ');
        $st->execute([':id' => $orderId, ':uid' => $buyerUserId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        $status = strtolower(trim((string)($order['status'] ?? '')));
        if ($status === 'cancelled') {
            return ['ok' => true];
        }
        if (!in_array($status, $cancellable, true)) {
            if (in_array($status, ['shipped', 'delivered'], true)) {
                return ['ok' => false, 'error' => 'This order has already shipped. Request a return instead.'];
            }
            return ['ok' => false, 'error' => 'This order can no longer be cancelled.'];
        }

        $noteLine = 'Cancelled by customer: ' . mb_substr($reason, 0, 400);
        $existingNotes = trim((string)($order['buyer_notes'] ?? ''));
        $buyerNotes = $existingNotes !== '' ? ($existingNotes . "\n" . $noteLine) : $noteLine;
        $existingSellerNotes = trim((string)($order['seller_notes'] ?? ''));
        $sellerNotes = $existingSellerNotes !== '' ? ($existingSellerNotes . "\n" . $noteLine) : $noteLine;

        $upd = $dbh->prepare('
            UPDATE org_orders
            SET status = \'cancelled\',
                buyer_notes = :notes,
                seller_notes = :snotes,
                updated_at = NOW()
            WHERE id = :id AND buyer_user_id = :uid AND status IN (\'pending\',\'confirmed\',\'paid\')
            LIMIT 1
        ');
        $upd->execute([
            ':notes' => mb_substr($buyerNotes, 0, 2000),
            ':snotes' => mb_substr($sellerNotes, 0, 2000),
            ':id' => $orderId,
            ':uid' => $buyerUserId,
        ]);
        if ($upd->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'Could not cancel this order. It may have already changed status.'];
        }

        // Restore inventory for the cancelled purchase.
        $productId = (int)($order['product_id'] ?? 0);
        $qty = max(1, (int)($order['quantity'] ?? 1));
        if ($productId > 0) {
            try {
                $dbh->prepare('
                    UPDATE org_products
                    SET stock_qty = CASE WHEN stock_qty IS NULL THEN NULL ELSE stock_qty + :q END,
                        updated_at = NOW()
                    WHERE id = :id AND is_deleted = 0
                    LIMIT 1
                ')->execute([':q' => $qty, ':id' => $productId]);
            } catch (Throwable $e) {
                // ignore stock restore failure
            }
        }

        org_shop_notify_seller_order_cancelled(
            $dbh,
            (int)($order['org_id'] ?? 0),
            $buyerUserId,
            $reason,
            [(string)($order['order_code'] ?? '')]
        );

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not cancel the order.'];
    }
}

/**
 * Seller cancels an order (card issue, changed mind, etc.) before shipment.
 * Restores stock and notifies the buyer.
 *
 * @return array{ok:bool,error?:string,buyer_user_id?:int,order_code?:string}
 */
function org_shop_seller_cancel_order(PDO $dbh, int $orgId, int $orderId, string $reason = ''): array
{
    org_shop_ensure_schema($dbh);
    $reason = trim($reason);
    if ($orgId <= 0 || $orderId <= 0) {
        return ['ok' => false, 'error' => 'Invalid order.'];
    }
    if ($reason === '') {
        $reason = 'Seller cancelled';
    }
    $cancellable = ['pending', 'confirmed', 'paid'];
    try {
        $st = $dbh->prepare('
            SELECT id, org_id, product_id, quantity, status, buyer_user_id, buyer_notes, seller_notes, order_code
            FROM org_orders
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ');
        $st->execute([':id' => $orderId, ':org' => $orgId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        $status = strtolower(trim((string)($order['status'] ?? '')));
        if ($status === 'cancelled') {
            return [
                'ok' => true,
                'buyer_user_id' => (int)($order['buyer_user_id'] ?? 0),
                'order_code' => (string)($order['order_code'] ?? ''),
            ];
        }
        if (!in_array($status, $cancellable, true)) {
            return ['ok' => false, 'error' => 'This order can no longer be cancelled.'];
        }

        $noteLine = 'Cancelled by seller: ' . mb_substr($reason, 0, 400);
        $existingNotes = trim((string)($order['buyer_notes'] ?? ''));
        $buyerNotes = $existingNotes !== '' ? ($existingNotes . "\n" . $noteLine) : $noteLine;
        $existingSellerNotes = trim((string)($order['seller_notes'] ?? ''));
        $sellerNotes = $existingSellerNotes !== '' ? ($existingSellerNotes . "\n" . $noteLine) : $noteLine;

        $upd = $dbh->prepare('
            UPDATE org_orders
            SET status = \'cancelled\',
                buyer_notes = :notes,
                seller_notes = :snotes,
                updated_at = NOW()
            WHERE id = :id AND org_id = :org AND status IN (\'pending\',\'confirmed\',\'paid\')
            LIMIT 1
        ');
        $upd->execute([
            ':notes' => mb_substr($buyerNotes, 0, 2000),
            ':snotes' => mb_substr($sellerNotes, 0, 2000),
            ':id' => $orderId,
            ':org' => $orgId,
        ]);
        if ($upd->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'Could not cancel this order. It may have already changed status.'];
        }

        $productId = (int)($order['product_id'] ?? 0);
        $qty = max(1, (int)($order['quantity'] ?? 1));
        if ($productId > 0) {
            try {
                $dbh->prepare('
                    UPDATE org_products
                    SET stock_qty = CASE WHEN stock_qty IS NULL THEN NULL ELSE stock_qty + :q END,
                        updated_at = NOW()
                    WHERE id = :id AND is_deleted = 0
                    LIMIT 1
                ')->execute([':q' => $qty, ':id' => $productId]);
            } catch (Throwable $e) {
                // ignore stock restore failure
            }
        }

        return [
            'ok' => true,
            'buyer_user_id' => (int)($order['buyer_user_id'] ?? 0),
            'order_code' => trim((string)($order['order_code'] ?? '')),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not cancel the order.'];
    }
}

/**
 * Cancel every open line in the same customer purchase batch as $orderId.
 *
 * @return array{ok:bool,error?:string,cancelled:int,buyer_user_id?:int,codes?:list<string>}
 */
function org_shop_seller_cancel_customer_batch(PDO $dbh, int $orgId, int $orderId, string $reason = ''): array
{
    $primary = null;
    try {
        $st = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':id' => $orderId, ':org' => $orgId]);
        $primary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $primary = null;
    }
    if (!$primary) {
        return ['ok' => false, 'error' => 'Order not found.', 'cancelled' => 0];
    }

    $batch = org_shop_seller_order_batch($dbh, $orgId, $primary);
    if (!$batch) {
        $batch = [$primary];
    }
    $cancelled = 0;
    $codes = [];
    $buyerUserId = (int)($primary['buyer_user_id'] ?? 0);
    $errors = [];
    foreach ($batch as $line) {
        $lineId = (int)($line['id'] ?? 0);
        $lineStatus = strtolower(trim((string)($line['status'] ?? '')));
        if ($lineId <= 0 || !in_array($lineStatus, ['pending', 'confirmed', 'paid'], true)) {
            continue;
        }
        $res = org_shop_seller_cancel_order($dbh, $orgId, $lineId, $reason);
        if (!empty($res['ok'])) {
            $cancelled++;
            $code = trim((string)($res['order_code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
            if ($buyerUserId <= 0 && !empty($res['buyer_user_id'])) {
                $buyerUserId = (int)$res['buyer_user_id'];
            }
        } else {
            $errors[] = (string)($res['error'] ?? 'Cancel failed.');
        }
    }

    if ($cancelled <= 0) {
        return [
            'ok' => false,
            'error' => $errors[0] ?? 'No open lines could be cancelled.',
            'cancelled' => 0,
            'buyer_user_id' => $buyerUserId,
        ];
    }

    org_shop_notify_buyer_order_cancelled($dbh, $orgId, $buyerUserId, $reason, $codes);

    return [
        'ok' => true,
        'cancelled' => $cancelled,
        'buyer_user_id' => $buyerUserId,
        'codes' => $codes,
    ];
}

/**
 * Insert a commerce alert into the shared notification inbox.
 */
function org_shop_insert_commerce_notification(
    PDO $dbh,
    string $senderLabel,
    string $receiverUsername,
    string $message,
    string $route = 'shop'
): void {
    $senderLabel = trim($senderLabel);
    $receiverUsername = trim($receiverUsername);
    $message = trim($message);
    if ($senderLabel === '' || $receiverUsername === '' || $message === '') {
        return;
    }
    $route = preg_replace('/[^a-z]/i', '', $route) ?: 'shop';
    $type = mb_substr($message, 0, 470) . ' [r:' . $route . ']';
    try {
        $ins = $dbh->prepare('
            INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
            VALUES (:sender, :receiver, :type, 0)
        ');
        $ins->execute([
            ':sender' => mb_substr($senderLabel, 0, 120),
            ':receiver' => mb_substr($receiverUsername, 0, 120),
            ':type' => mb_substr($type, 0, 500),
        ]);
    } catch (Throwable $e) {
        // never block commerce flows
    }
}

/** @return array{org_name:string,publisher_username:string} */
function org_shop_org_notify_identities(PDO $dbh, int $orgId): array
{
    $out = ['org_name' => 'Seller', 'publisher_username' => ''];
    if ($orgId <= 0) {
        return $out;
    }
    try {
        $st = $dbh->prepare('
            SELECT o.name, u.username, u.fullname
            FROM organizations o
            LEFT JOIN users u ON u.id = o.publisher_user_id
            WHERE o.id = :org
            LIMIT 1
        ');
        $st->execute([':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $orgName = trim((string)($row['name'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        $fullname = trim((string)($row['fullname'] ?? ''));
        if ($orgName !== '') {
            $out['org_name'] = $orgName;
        } elseif ($fullname !== '') {
            $out['org_name'] = $fullname;
        } elseif ($username !== '') {
            $out['org_name'] = $username;
        }
        $out['publisher_username'] = $username;
    } catch (Throwable $e) {
        // keep defaults
    }
    return $out;
}

function org_shop_user_username(PDO $dbh, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    try {
        $st = $dbh->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        return trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Push an in-app notification so the customer knows the seller cancelled.
 */
function org_shop_notify_buyer_order_cancelled(
    PDO $dbh,
    int $orgId,
    int $buyerUserId,
    string $reason,
    array $orderCodes = []
): void {
    if ($buyerUserId <= 0 || $orgId <= 0) {
        return;
    }
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Seller cancelled';
    }
    $buyerUsername = org_shop_user_username($dbh, $buyerUserId);
    if ($buyerUsername === '') {
        return;
    }
    $idents = org_shop_org_notify_identities($dbh, $orgId);
    $codeBit = '';
    $codes = array_values(array_filter(array_map('strval', $orderCodes)));
    if ($codes) {
        $codeBit = ' (' . implode(', ', array_slice($codes, 0, 3)) . ')';
    }
    $message = 'Your order' . $codeBit . ' was cancelled by the seller. Reason: ' . mb_substr($reason, 0, 200)
        . '. Open Shopping Preferences → Notifications for details.';
    org_shop_insert_commerce_notification($dbh, $idents['org_name'], $buyerUsername, $message, 'shop');
}

/**
 * Push a seller inbox alert for order lifecycle changes (paid, cancel, ship, deliver, new order).
 *
 * @param list<string> $orderCodes
 */
function org_shop_notify_seller_order_status(
    PDO $dbh,
    int $orgId,
    int $buyerUserId,
    string $event,
    array $orderCodes = [],
    string $extra = ''
): void {
    if ($orgId <= 0) {
        return;
    }
    $event = strtolower(trim($event));
    $idents = org_shop_org_notify_identities($dbh, $orgId);
    $receiver = $idents['publisher_username'];
    if ($receiver === '') {
        return;
    }

    $buyerLabel = 'Customer';
    $buyerUsername = org_shop_user_username($dbh, $buyerUserId);
    if ($buyerUsername !== '') {
        $buyerLabel = $buyerUsername;
    } else {
        try {
            $st = $dbh->prepare('SELECT name, username FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $buyerUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $fn = trim((string)($row['name'] ?? '')) ?: trim((string)($row['username'] ?? ''));
            if ($fn !== '') {
                $buyerLabel = $fn;
            }
        } catch (Throwable $e) {
            // keep Customer
        }
    }

    $codeBit = '';
    $codes = array_values(array_filter(array_map('strval', $orderCodes)));
    if ($codes) {
        $codeBit = ' (' . implode(', ', array_slice($codes, 0, 3)) . ')';
    }
    $extra = trim($extra);

    switch ($event) {
        case 'new':
        case 'pending':
            $message = 'New order' . $codeBit . ' from ' . $buyerLabel
                . ' — status: pending. Confirm or wait for payment. Open Sales Management → Orders.';
            break;
        case 'paid':
            $message = 'Payment received' . $codeBit . ' from ' . $buyerLabel
                . ' — status: paid. Ship this order now. Open Sales Management → Orders / Delivery.';
            break;
        case 'cancelled':
        case 'cancellation':
            $reason = $extra !== '' ? $extra : 'Cancelled';
            $message = 'Order' . $codeBit . ' for ' . $buyerLabel
                . ' is cancelled (status: cancelled). Reason: '
                . mb_substr($reason, 0, 180)
                . '. Open Sales Management → Notification / Cancel orders table.';
            break;
        case 'shipped':
        case 'shipping':
            $track = $extra !== '' ? (' Tracking: ' . mb_substr($extra, 0, 120) . '.') : '';
            $message = 'Order' . $codeBit . ' for ' . $buyerLabel
                . ' is now shipping (status: shipped).' . $track
                . ' Customer was notified. Open Sales Management → Delivery / Shipping.';
            break;
        case 'delivered':
        case 'delivery':
            $message = 'Order' . $codeBit . ' for ' . $buyerLabel
                . ' is delivered (status: delivered). Customer was notified. Keep records in Orders.';
            break;
        default:
            $message = 'Order update' . $codeBit . ' for ' . $buyerLabel
                . ' — status: ' . $event . '. Open Sales Management → Orders.';
            break;
    }

    org_shop_insert_commerce_notification($dbh, $buyerLabel, $receiver, $message, 'orgsales');
}

/**
 * Tell the seller when a customer cancels (changed mind, card issue, etc.).
 */
function org_shop_notify_seller_order_cancelled(
    PDO $dbh,
    int $orgId,
    int $buyerUserId,
    string $reason,
    array $orderCodes = []
): void {
    org_shop_notify_seller_order_status(
        $dbh,
        $orgId,
        $buyerUserId,
        'cancelled',
        $orderCodes,
        $reason
    );
}

/**
 * Notify the buyer when an order ships or is delivered.
 */
function org_shop_notify_buyer_order_fulfillment(
    PDO $dbh,
    int $orgId,
    int $orderId,
    string $status,
    string $trackingNumber = '',
    string $carrier = ''
): void {
    $status = strtolower(trim($status));
    if (!in_array($status, ['shipped', 'delivered'], true) || $orgId <= 0 || $orderId <= 0) {
        return;
    }
    try {
        $st = $dbh->prepare('
            SELECT buyer_user_id, order_code, product_title, carrier, tracking_number
            FROM org_orders
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ');
        $st->execute([':id' => $orderId, ':org' => $orgId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return;
        }
        $buyerUserId = (int)($order['buyer_user_id'] ?? 0);
        $buyerUsername = org_shop_user_username($dbh, $buyerUserId);
        if ($buyerUsername === '') {
            return;
        }
        $idents = org_shop_org_notify_identities($dbh, $orgId);
        $code = trim((string)($order['order_code'] ?? ''));
        $title = trim((string)($order['product_title'] ?? 'your order'));
        $track = trim($trackingNumber !== '' ? $trackingNumber : (string)($order['tracking_number'] ?? ''));
        $carr = trim($carrier !== '' ? $carrier : (string)($order['carrier'] ?? ''));
        if ($status === 'shipped') {
            $message = 'Your order' . ($code !== '' ? ' (' . $code . ')' : '') . ' — ' . $title . ' — is on shipping'
                . ($carr !== '' ? ' via ' . $carr : '')
                . ($track !== '' ? '. Tracking: ' . $track : '')
                . '. Open Shopping Preferences → Notifications.';
        } else {
            $message = 'Your order' . ($code !== '' ? ' (' . $code . ')' : '') . ' — ' . $title
                . ' — was delivered. Open Shopping Preferences → Notifications.';
        }
        org_shop_insert_commerce_notification($dbh, $idents['org_name'], $buyerUsername, $message, 'shop');
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Buyer order-lifecycle counts (parallel to seller Notification hub).
 *
 * Cancel = seller cancelled the buyer's order.
 * Cancellation = buyer cancelled their own order.
 *
 * @return array{
 *   pending:int,paid:int,cancel:int,cancellation:int,cancelled:int,shipping:int,delivery:int
 * }
 */
function org_shop_buyer_order_lifecycle_counts(PDO $dbh, int $buyerUserId): array
{
    $out = [
        'pending' => 0,
        'paid' => 0,
        'cancel' => 0,
        'cancellation' => 0,
        'cancelled' => 0,
        'shipping' => 0,
        'delivery' => 0,
    ];
    if ($buyerUserId <= 0) {
        return $out;
    }
    try {
        $orders = org_shop_list_buyer_orders($dbh, $buyerUserId, 200);
        $cutoff = time() - (30 * 24 * 60 * 60);
        foreach ($orders as $order) {
            $status = strtolower(trim((string)($order['status'] ?? '')));
            if (in_array($status, ['pending', 'confirmed'], true)) {
                $out['pending']++;
            } elseif ($status === 'paid') {
                $out['paid']++;
            } elseif ($status === 'shipped') {
                $out['shipping']++;
            } elseif ($status === 'delivered') {
                $when = (string)($order['delivered_at'] ?? $order['updated_at'] ?? $order['created_at'] ?? '');
                $ts = $when !== '' ? (int)strtotime($when) : 0;
                if ($ts >= $cutoff || $ts === 0) {
                    $out['delivery']++;
                }
            } elseif ($status === 'cancelled') {
                $meta = org_shop_order_cancel_meta(
                    (string)($order['buyer_notes'] ?? ''),
                    (string)($order['seller_notes'] ?? '')
                );
                if ((string)($meta['by'] ?? 'Customer') === 'Seller') {
                    $out['cancel']++;
                } else {
                    $out['cancellation']++;
                }
                $out['cancelled']++;
            }
        }
    } catch (Throwable $e) {
        // keep zeros
    }
    return $out;
}

/**
 * Buyer action alerts for Shopping Preferences → Notifications.
 *
 * @return list<array{type:string,message:string,action:string,count:int}>
 */
function org_shop_buyer_commerce_alerts(PDO $dbh, int $buyerUserId): array
{
    $alerts = [];
    $life = org_shop_buyer_order_lifecycle_counts($dbh, $buyerUserId);
    if ((int)$life['pending'] > 0) {
        $alerts[] = [
            'type' => 'Pending',
            'message' => (int)$life['pending'] . ' order(s) awaiting your payment. Status stays pending until you pay — then it moves to Paid automatically.',
            'action' => 'my_orders.php',
            'count' => (int)$life['pending'],
        ];
    }
    if ((int)$life['paid'] > 0) {
        $alerts[] = [
            'type' => 'Paid',
            'message' => (int)$life['paid'] . ' paid order(s) — payment confirmed. The seller is preparing shipment. You will see Shipping when it leaves.',
            'action' => 'my_orders.php',
            'count' => (int)$life['paid'],
        ];
    }
    if ((int)$life['cancel'] > 0) {
        $alerts[] = [
            'type' => 'Cancel',
            'message' => (int)$life['cancel'] . ' order(s) the seller Cancelled (seller reason — card issue, stock, etc.). Check details and contact the seller if needed.',
            'action' => 'my_orders.php',
            'count' => (int)$life['cancel'],
        ];
    }
    if ((int)$life['cancellation'] > 0) {
        $alerts[] = [
            'type' => 'Cancellation',
            'message' => (int)$life['cancellation'] . ' Cancellation(s) you made — you cancelled your own order. Stock was restored when allowed.',
            'action' => 'my_orders.php',
            'count' => (int)$life['cancellation'],
        ];
    }
    if ((int)$life['shipping'] > 0) {
        $alerts[] = [
            'type' => 'Shipping',
            'message' => (int)$life['shipping'] . ' order(s) shipping (in transit). Track carrier details in Order history. Status becomes Delivery when you receive the package.',
            'action' => 'my_orders.php',
            'count' => (int)$life['shipping'],
        ];
    }
    if ((int)$life['delivery'] > 0) {
        $alerts[] = [
            'type' => 'Delivery',
            'message' => (int)$life['delivery'] . ' recently delivered order(s) — receipt confirmed. Leave a review if you like the product.',
            'action' => 'Your_Shopping_preferences.php#order-history',
            'count' => (int)$life['delivery'],
        ];
    }
    try {
        $st = $dbh->prepare("
            SELECT COUNT(*)
            FROM org_order_returns r
            INNER JOIN org_orders o ON o.id = r.order_id
            WHERE o.buyer_user_id = :uid AND r.status = 'requested'
        ");
        $st->execute([':uid' => $buyerUserId]);
        $openReturns = (int)($st->fetchColumn() ?: 0);
        if ($openReturns > 0) {
            $alerts[] = [
                'type' => 'Return / refund',
                'message' => $openReturns . ' return request(s) waiting for seller review.',
                'action' => 'Your_Shopping_preferences.php#returns-refunds',
                'count' => $openReturns,
            ];
        }
    } catch (Throwable $e) {
        // table may not exist
    }
    return $alerts;
}

/**
 * Buyer commerce notification feed: pending, paid, cancel, shipping, delivery, returns + inbox.
 *
 * @return list<array{type:string,title:string,message:string,when:string,sort:int,from:string,action?:string}>
 */
function org_shop_buyer_commerce_notification_feed(PDO $dbh, int $buyerUserId, int $limit = 50): array
{
    $feed = [];
    if ($buyerUserId <= 0) {
        return $feed;
    }
    $limit = max(1, min(100, $limit));

    try {
        $orders = org_shop_list_buyer_orders($dbh, $buyerUserId, 200);
        foreach ($orders as $order) {
            $status = strtolower(trim((string)($order['status'] ?? '')));
            if (!in_array($status, ['pending', 'confirmed', 'paid', 'cancelled', 'shipped', 'delivered'], true)) {
                continue;
            }
            $seller = trim((string)($order['seller_name'] ?? '')) ?: 'Seller';
            $brandLabel = trim((string)($order['commerce_brand_name'] ?? ''));
            if ($brandLabel === '') {
                $brandLabel = $seller;
            }
            $code = trim((string)($order['order_code'] ?? ''));
            $title = trim((string)($order['product_title'] ?? 'Product'));
            $whenRaw = (string)($order['updated_at'] ?? $order['created_at'] ?? '');
            $sort = $whenRaw !== '' ? (int)strtotime($whenRaw) : 0;
            $when = $whenRaw !== '' ? date('M j, Y g:i A', $sort ?: time()) : '';
            $meta = org_shop_order_cancel_meta(
                (string)($order['buyer_notes'] ?? ''),
                (string)($order['seller_notes'] ?? '')
            );
            $brandUrl = org_shop_order_brand_shop_url($order);
            $action = $brandUrl !== '' ? $brandUrl : 'my_orders.php';
            if ($status === 'cancelled') {
                $by = (string)$meta['by'];
                $reason = (string)$meta['reason'];
                $isSeller = $by === 'Seller';
                $feed[] = [
                    'type' => $isSeller ? 'Cancel' : 'Cancellation',
                    'title' => $isSeller
                        ? ('Seller Cancel · ' . $brandLabel)
                        : 'Your Cancellation',
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title . ' · Reason: ' . $reason,
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $action,
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                ];
            } elseif (in_array($status, ['pending', 'confirmed'], true)) {
                $feed[] = [
                    'type' => 'Pending',
                    'title' => 'Pending — awaiting payment · ' . $brandLabel,
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title,
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $action,
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                ];
            } elseif ($status === 'paid') {
                $feed[] = [
                    'type' => 'Paid',
                    'title' => 'Paid — seller preparing shipment · ' . $brandLabel,
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title,
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $action,
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                ];
            } elseif ($status === 'shipped') {
                $track = trim((string)($order['tracking_number'] ?? ''));
                $carr = trim((string)($order['carrier'] ?? ''));
                $feed[] = [
                    'type' => 'Shipping',
                    'title' => 'Shipping — in transit · ' . $brandLabel,
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title
                        . ($carr !== '' ? ' · ' . $carr : '')
                        . ($track !== '' ? ' · Tracking ' . $track : ''),
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $action,
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                ];
            } else {
                $feed[] = [
                    'type' => 'Delivery',
                    'title' => 'Delivery confirmed · ' . $brandLabel,
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title,
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $brandUrl !== '' ? $brandUrl : 'Your_Shopping_preferences.php#order-history',
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                ];
            }
        }
    } catch (Throwable $e) {
        // continue with inbox rows
    }

    try {
        $username = org_shop_user_username($dbh, $buyerUserId);
        if ($username !== '') {
            $st = $dbh->prepare('
                SELECT notiuser, notitype, created_at, is_read
                FROM notification
                WHERE notireceiver = :u
                ORDER BY id DESC
                LIMIT 40
            ');
            $st->execute([':u' => $username]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $text = trim((string)($row['notitype'] ?? ''));
                $text = (string)preg_replace('/\s\[(?:live|r|p|c):[^\]]+\]\s*$/', '', $text);
                $lower = strtolower($text);
                if (
                    strpos($lower, 'order') === false
                    && strpos($lower, 'cancel') === false
                    && strpos($lower, 'ship') === false
                    && strpos($lower, 'deliver') === false
                    && strpos($lower, 'return') === false
                    && strpos($lower, 'refund') === false
                    && strpos($lower, 'paid') === false
                    && strpos($lower, 'payment') === false
                ) {
                    continue;
                }
                $whenRaw = (string)($row['created_at'] ?? '');
                $sort = $whenRaw !== '' ? (int)strtotime($whenRaw) : 0;
                $type = 'Update';
                if (strpos($lower, 'cancelled by seller') !== false || strpos($lower, 'seller cancel') !== false) {
                    $type = 'Cancel';
                } elseif (strpos($lower, 'you cancelled') !== false || strpos($lower, 'cancellation') !== false) {
                    $type = 'Cancellation';
                } elseif (strpos($lower, 'cancel') !== false) {
                    $type = (strpos($lower, 'seller') !== false) ? 'Cancel' : 'Cancellation';
                } elseif (strpos($lower, 'return') !== false || strpos($lower, 'refund') !== false) {
                    $type = 'Return / refund';
                } elseif (strpos($lower, 'ship') !== false) {
                    $type = 'Shipping';
                } elseif (strpos($lower, 'deliver') !== false) {
                    $type = 'Delivery';
                } elseif (strpos($lower, 'paid') !== false || strpos($lower, 'payment') !== false) {
                    $type = 'Paid';
                } elseif (strpos($lower, 'pending') !== false || strpos($lower, 'new order') !== false) {
                    $type = 'Pending';
                }
                $titleMap = [
                    'Cancel' => 'Seller Cancel',
                    'Cancellation' => 'Your Cancellation',
                    'Shipping' => 'Shipping update',
                    'Delivery' => 'Delivery update',
                    'Paid' => 'Payment update',
                    'Pending' => 'Order pending',
                    'Return / refund' => 'Return / refund',
                ];
                $feed[] = [
                    'type' => $type,
                    'title' => $titleMap[$type] ?? $type,
                    'message' => $text,
                    'when' => $whenRaw !== '' ? date('M j, Y g:i A', $sort ?: time()) : '',
                    'sort' => $sort,
                    'from' => trim((string)($row['notiuser'] ?? '')) ?: 'Seller',
                    'action' => 'my_orders.php',
                ];
            }
        }
    } catch (Throwable $e) {
        // ignore inbox failures
    }

    usort($feed, static function (array $a, array $b): int {
        return ((int)$b['sort']) <=> ((int)$a['sort']);
    });

    // De-dupe near-identical messages.
    $seen = [];
    $out = [];
    foreach ($feed as $item) {
        $key = mb_strtolower(($item['type'] ?? '') . '|' . ($item['message'] ?? ''));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $item;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function org_shop_order_cancel_meta(string $buyerNotes, string $sellerNotes = ''): array
{
    $blob = trim($buyerNotes . "\n" . $sellerNotes);
    $by = 'Customer';
    $reason = 'Cancelled';
    if ($blob === '') {
        return ['reason' => 'Changed mind', 'by' => $by];
    }
    if (stripos($blob, 'Cancelled by seller') !== false) {
        $by = 'Seller';
    }
    if (preg_match('/Cancelled by (?:customer|seller):\s*(.+)$/mi', $blob, $m)) {
        $parsed = trim((string)$m[1]);
        $reason = $parsed !== '' ? $parsed : ($by === 'Seller' ? 'Seller cancelled' : 'Changed mind');
    } elseif (stripos($blob, 'Cancelled by seller') !== false) {
        $reason = 'Seller cancelled';
    } elseif (stripos($blob, 'Cancelled by customer') !== false) {
        $reason = 'Changed mind';
    }
    return ['reason' => $reason, 'by' => $by];
}

/** @return list<string> */
function org_shop_parse_bullet_points(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim(ltrim(trim($line), '•-'));
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** @return array<string, bool> */
function org_shop_seller_journey(PDO $dbh, int $orgId): array
{
    org_shop_ensure_schema($dbh);
    $hasProducts = org_shop_product_count($dbh, $orgId) > 0;
    $activeProducts = 0;
    try {
        $st = $dbh->prepare("SELECT COUNT(*) FROM org_products WHERE org_id = :org AND is_deleted = 0 AND status = 'active'");
        $st->execute([':org' => $orgId]);
        $activeProducts = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $activeProducts = 0;
    }
    $hasOrders = false;
    try {
        $st = $dbh->prepare('SELECT COUNT(*) FROM org_orders WHERE org_id = :org');
        $st->execute([':org' => $orgId]);
        $hasOrders = ((int)($st->fetchColumn() ?: 0)) > 0;
    } catch (Throwable $e) {
        $hasOrders = false;
    }
    $shopVisible = platform_rent_shop_is_visible($dbh, $orgId);
    return [
        'account_ready' => $orgId > 0,
        'catalog_listed' => $hasProducts,
        'products_live' => $activeProducts > 0 && $shopVisible,
        'inventory_ready' => $activeProducts > 0,
        'first_order' => $hasOrders,
        'storefront_live' => $shopVisible,
    ];
}
