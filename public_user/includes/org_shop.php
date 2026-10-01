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
        '20260722_org_orders_product_unit_code.sql',
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
    if (!platform_rent_db_column_exists($dbh, 'org_products', 'product_code')) {
        try {
            $dbh->exec("ALTER TABLE org_products ADD COLUMN product_code VARCHAR(32) NULL DEFAULT NULL AFTER sku");
        } catch (Throwable $e) {
            // ignore
        }
    }
    org_shop_repair_unique_product_codes($dbh);
    try {
        $idx = $dbh->query("SHOW INDEX FROM org_products WHERE Key_name = 'uq_org_products_product_code'");
        $hasIdx = $idx && $idx->fetch(PDO::FETCH_ASSOC);
        if (!$hasIdx) {
            $dbh->exec('ALTER TABLE org_products ADD UNIQUE KEY uq_org_products_product_code (product_code)');
        }
    } catch (Throwable $e) {
        // ignore — duplicates may still be repairing, or engine limits
    }
    if (!platform_rent_db_column_exists($dbh, 'org_orders', 'product_unit_code')) {
        try {
            $dbh->exec("ALTER TABLE org_orders ADD COLUMN product_unit_code VARCHAR(40) NULL DEFAULT NULL AFTER product_title");
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (!platform_rent_db_column_exists($dbh, 'org_orders', 'amount_paid_cents')) {
        try {
            $dbh->exec('ALTER TABLE org_orders ADD COLUMN amount_paid_cents INT NOT NULL DEFAULT 0 AFTER total_cents');
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (!platform_rent_db_column_exists($dbh, 'org_orders', 'payment_method')) {
        try {
            $dbh->exec("ALTER TABLE org_orders ADD COLUMN payment_method VARCHAR(40) NULL DEFAULT NULL AFTER paid_at");
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (!platform_rent_db_column_exists($dbh, 'org_orders', 'payment_reference')) {
        try {
            $dbh->exec("ALTER TABLE org_orders ADD COLUMN payment_reference VARCHAR(120) NULL DEFAULT NULL AFTER payment_method");
        } catch (Throwable $e) {
            // ignore
        }
    }
    org_shop_repair_unique_product_unit_codes($dbh);
    try {
        $idx = $dbh->query("SHOW INDEX FROM org_orders WHERE Key_name = 'uq_org_orders_product_unit_code'");
        $hasIdx = $idx && $idx->fetch(PDO::FETCH_ASSOC);
        if (!$hasIdx) {
            $dbh->exec('ALTER TABLE org_orders ADD UNIQUE KEY uq_org_orders_product_unit_code (product_unit_code)');
        }
    } catch (Throwable $e) {
        // ignore
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
 * Active Customer Plus members ($10/mo) pay $0 service fee.
 */
function org_shop_buyer_service_fee_cents(?PDO $dbh = null, int $buyerUserId = 0): int
{
    $default = 199; // $1.99
    if ($buyerUserId > 0) {
        try {
            if (!function_exists('buyer_membership_is_active')) {
                require_once __DIR__ . '/buyer_membership.php';
            }
            $pdo = $dbh;
            if (!$pdo instanceof PDO && isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) {
                $pdo = $GLOBALS['dbh'];
            }
            if ($pdo instanceof PDO && buyer_membership_is_active($pdo, $buyerUserId)) {
                return buyer_membership_member_service_fee_cents();
            }
        } catch (Throwable $e) {
            // fall through to default
        }
    }
    return $default;
}

/**
 * Amount the buyer has actually remitted toward an order (cents).
 * Prefers amount_paid_cents; falls back to payment_reference / buyer_notes markers.
 */
function org_shop_order_amount_paid_cents(array $order): int
{
    if (array_key_exists('amount_paid_cents', $order)) {
        $col = (int)$order['amount_paid_cents'];
        if ($col > 0) {
            return $col;
        }
    }
    $ref = (string)($order['payment_reference'] ?? '');
    if (preg_match('/(?:^|[|;\s])paid[:=](\d+)/i', $ref, $m)) {
        return max(0, (int)$m[1]);
    }
    $notes = (string)($order['buyer_notes'] ?? '');
    if (preg_match('/Test payment Cost\s*\$:\s*\$?([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $notes, $m)) {
        $raw = str_replace(',', '', (string)$m[1]);
        if (is_numeric($raw)) {
            return max(0, (int)round(((float)$raw) * 100));
        }
    }
    if (preg_match('/paid\s+\$?([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s+of\s+/i', $notes, $m)) {
        $raw = str_replace(',', '', (string)$m[1]);
        if (is_numeric($raw)) {
            return max(0, (int)round(((float)$raw) * 100));
        }
    }
    $status = strtolower(trim((string)($order['status'] ?? '')));
    if (in_array($status, ['paid', 'shipped', 'delivered'], true)) {
        return max(0, (int)($order['total_cents'] ?? 0));
    }
    return 0;
}

/** @return array{due_cents:int,paid_cents:int,shortfall_cents:int,is_incomplete:bool,currency:string} */
function org_shop_order_payment_progress(array $order): array
{
    $due = max(0, (int)($order['total_cents'] ?? 0));
    $paid = org_shop_order_amount_paid_cents($order);
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $currency = strtoupper(trim((string)($order['currency'] ?? 'USD'))) ?: 'USD';
    $shortfall = max(0, $due - $paid);
    $isIncomplete = in_array($status, ['pending', 'confirmed'], true)
        || ($due > 0 && $paid > 0 && $paid < $due);
    return [
        'due_cents' => $due,
        'paid_cents' => $paid,
        'shortfall_cents' => $shortfall,
        'is_incomplete' => $isIncomplete,
        'currency' => $currency,
    ];
}

/** True when seller must not fulfill / ship until the customer pays in full. */
function org_shop_order_fulfillment_locked(array $order): bool
{
    $p = org_shop_order_payment_progress($order);
    return !empty($p['is_incomplete']);
}

/** Buyer-facing line about incomplete payment / shipping hold. */
function org_shop_order_incomplete_payment_buyer_message(array $order): string
{
    $p = org_shop_order_payment_progress($order);
    $cur = $p['currency'];
    if ($p['paid_cents'] > 0 && $p['shortfall_cents'] > 0) {
        return 'You paid '
            . org_shop_format_price($p['paid_cents'], $cur)
            . ' of '
            . org_shop_format_price($p['due_cents'], $cur)
            . ' — '
            . org_shop_format_price($p['shortfall_cents'], $cur)
            . ' still due. Shipping will not start until the full order total is paid.';
    }
    return 'Payment is incomplete (card declined, insufficient funds, or partial payment). '
        . 'The seller cannot start shipping until this order is fully paid.';
}

/** Seller-facing line about incomplete payment / do not ship. */
function org_shop_order_incomplete_payment_seller_message(array $order): string
{
    $p = org_shop_order_payment_progress($order);
    $cur = $p['currency'];
    if ($p['paid_cents'] > 0 && $p['shortfall_cents'] > 0) {
        return 'Customer paid '
            . org_shop_format_price($p['paid_cents'], $cur)
            . ' of '
            . org_shop_format_price($p['due_cents'], $cur)
            . ' (short '
            . org_shop_format_price($p['shortfall_cents'], $cur)
            . '). Do not ship until status is Paid.';
    }
    return 'Customer payment incomplete (credit/debit issue or partial pay). Do not ship until status is Paid.';
}

/**
 * Notify the buyer that payment is incomplete and shipping is on hold.
 */
function org_shop_notify_buyer_payment_incomplete(
    PDO $dbh,
    int $orgId,
    int $buyerUserId,
    string $orderCode = '',
    int $paidCents = 0,
    int $dueCents = 0,
    string $currency = 'USD'
): void {
    if ($orgId <= 0 || $buyerUserId <= 0) {
        return;
    }
    $buyerUsername = org_shop_user_username($dbh, $buyerUserId);
    if ($buyerUsername === '') {
        return;
    }
    $idents = org_shop_org_notify_identities($dbh, $orgId);
    $codeBit = $orderCode !== '' ? ' (' . $orderCode . ')' : '';
    $cur = strtoupper(trim($currency)) ?: 'USD';
    if ($paidCents > 0 && $dueCents > $paidCents) {
        $short = $dueCents - $paidCents;
        $message = 'Payment incomplete for your order' . $codeBit
            . ' — you paid ' . org_shop_format_price($paidCents, $cur)
            . ' of ' . org_shop_format_price($dueCents, $cur)
            . ' (still due ' . org_shop_format_price($short, $cur)
            . '). Shipping will not start until the full amount is paid. Open Notifications → Pending, then complete payment.';
    } else {
        $message = 'Payment incomplete for your order' . $codeBit
            . '. Shipping will not start until payment clears in full. Open Notifications → Pending to finish payment.';
    }
    org_shop_insert_commerce_notification($dbh, $idents['org_name'], $buyerUsername, $message, 'shop');
}

/**
 * Seller sends a payment-completion reminder to the buyer (Pending tab).
 *
 * @return array{ok:bool,error?:string,order_code?:string,buyer_user_id?:int}
 */
function org_shop_seller_request_payment_completion(
    PDO $dbh,
    int $orgId,
    int $orderId,
    string $customMessage = ''
): array {
    if ($orgId <= 0 || $orderId <= 0) {
        return ['ok' => false, 'error' => 'Invalid order.'];
    }
    org_shop_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':id' => $orderId, ':org' => $orgId]);
        $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        $status = strtolower(trim((string)($order['status'] ?? '')));
        if (!in_array($status, ['pending', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'This order is not waiting on payment.'];
        }
        $buyerUserId = (int)($order['buyer_user_id'] ?? 0);
        if ($buyerUserId <= 0) {
            return ['ok' => false, 'error' => 'No customer on this order.'];
        }
        $buyerUsername = org_shop_user_username($dbh, $buyerUserId);
        if ($buyerUsername === '') {
            return ['ok' => false, 'error' => 'Customer account not found.'];
        }

        $progress = org_shop_order_payment_progress($order);
        $code = trim((string)($order['order_code'] ?? ''));
        $cur = $progress['currency'];
        $defaultMsg = 'Your payment is incomplete. Please complete payment before shipping starts.';
        $sellerMsg = trim($customMessage) !== '' ? trim($customMessage) : $defaultMsg;
        $sellerMsg = mb_substr($sellerMsg, 0, 400);

        $detailBits = [];
        if ($progress['paid_cents'] > 0 || $progress['due_cents'] > 0) {
            $detailBits[] = 'Paid '
                . org_shop_format_price($progress['paid_cents'], $cur)
                . ' of '
                . org_shop_format_price($progress['due_cents'], $cur);
            if ($progress['shortfall_cents'] > 0) {
                $detailBits[] = 'still due '
                    . org_shop_format_price($progress['shortfall_cents'], $cur);
            }
        }
        $noteLine = 'Seller payment request: ' . $sellerMsg
            . ($detailBits !== [] ? ' (' . implode('; ', $detailBits) . ')' : '');
        $existingSellerNotes = trim((string)($order['seller_notes'] ?? ''));
        $sellerNotes = $existingSellerNotes !== ''
            ? ($existingSellerNotes . "\n" . $noteLine)
            : $noteLine;
        try {
            $dbh->prepare('
                UPDATE org_orders
                SET seller_notes = :notes, updated_at = NOW()
                WHERE id = :id AND org_id = :org
                LIMIT 1
            ')->execute([
                ':notes' => mb_substr($sellerNotes, 0, 2000),
                ':id' => $orderId,
                ':org' => $orgId,
            ]);
        } catch (Throwable $e) {
            // continue — still notify
        }

        $idents = org_shop_org_notify_identities($dbh, $orgId);
        $codeBit = $code !== '' ? ' (' . $code . ')' : '';
        $inboxMsg = 'Pending — payment incomplete' . $codeBit . '. '
            . $sellerMsg
            . ($detailBits !== [] ? ' ' . implode('; ', $detailBits) . '.' : '')
            . ' Shipping will not start until payment is complete. Open Notifications → Pending, then open the order to complete payment or cancel.';
        org_shop_insert_commerce_notification($dbh, $idents['org_name'], $buyerUsername, $inboxMsg, 'shop');

        return [
            'ok' => true,
            'order_code' => $code,
            'buyer_user_id' => $buyerUserId,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not notify the customer.'];
    }
}

/**
 * Apply an additional payment toward a pending/confirmed order (e.g. shortfall top-up).
 *
 * @return array{ok:bool,error?:string,status?:string,amount_paid_cents?:int,shortfall_cents?:int,total_cents?:int}
 */
function org_shop_buyer_complete_order_payment(
    PDO $dbh,
    int $buyerUserId,
    int $orderId,
    int $addCents
): array {
    if ($buyerUserId <= 0 || $orderId <= 0) {
        return ['ok' => false, 'error' => 'Invalid order.'];
    }
    $addCents = max(0, $addCents);
    if ($addCents <= 0) {
        return ['ok' => false, 'error' => 'Enter an amount greater than zero.'];
    }
    org_shop_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id AND buyer_user_id = :uid LIMIT 1');
        $st->execute([':id' => $orderId, ':uid' => $buyerUserId]);
        $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        $status = strtolower(trim((string)($order['status'] ?? '')));
        if (!in_array($status, ['pending', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'This order is not waiting on payment.'];
        }
        $due = max(0, (int)($order['total_cents'] ?? 0));
        $paid = org_shop_order_amount_paid_cents($order);
        $newPaid = $paid + $addCents;
        $currency = strtoupper(trim((string)($order['currency'] ?? 'USD'))) ?: 'USD';
        $code = trim((string)($order['order_code'] ?? ''));
        $orgId = (int)($order['org_id'] ?? 0);
        $complete = $due <= 0 || $newPaid >= $due;
        $shortfall = max(0, $due - $newPaid);
        $payRef = trim((string)($order['payment_reference'] ?? ''));
        if ($payRef === '') {
            $payRef = 'PAY-' . ($code !== '' ? $code : (string)$orderId);
        }
        $payRef = preg_replace('/\|paid:\d+/i', '', $payRef) ?? $payRef;
        $payRef = preg_replace('/\|due:\d+/i', '', $payRef) ?? $payRef;
        $payRef = rtrim($payRef, '|') . '|paid:' . $newPaid . '|due:' . $due;

        $noteLine = 'Additional payment: ' . org_shop_format_price($addCents, $currency)
            . ' (now paid ' . org_shop_format_price($newPaid, $currency)
            . ' of ' . org_shop_format_price($due, $currency) . ').';
        $notes = trim((string)($order['buyer_notes'] ?? ''));
        $notes = $notes !== '' ? ($notes . "\n" . $noteLine) : $noteLine;

        if ($complete) {
            try {
                $dbh->prepare("
                    UPDATE org_orders
                    SET status = 'paid',
                        paid_at = COALESCE(paid_at, NOW()),
                        amount_paid_cents = :paid,
                        payment_reference = :pref,
                        buyer_notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id AND buyer_user_id = :uid
                    LIMIT 1
                ")->execute([
                    ':paid' => $newPaid,
                    ':pref' => mb_substr($payRef, 0, 120),
                    ':notes' => mb_substr($notes, 0, 2000),
                    ':id' => $orderId,
                    ':uid' => $buyerUserId,
                ]);
            } catch (Throwable $e) {
                $dbh->prepare("
                    UPDATE org_orders
                    SET status = 'paid',
                        paid_at = COALESCE(paid_at, NOW()),
                        payment_reference = :pref,
                        buyer_notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id AND buyer_user_id = :uid
                    LIMIT 1
                ")->execute([
                    ':pref' => mb_substr($payRef, 0, 120),
                    ':notes' => mb_substr($notes, 0, 2000),
                    ':id' => $orderId,
                    ':uid' => $buyerUserId,
                ]);
            }
            if (function_exists('org_shop_apply_order_fees')) {
                org_shop_apply_order_fees($dbh, $orderId);
            }
            if ($orgId > 0 && function_exists('org_shop_issue_receipt')) {
                try {
                    org_shop_issue_receipt($dbh, $orgId, $orderId, 'topup', 'TOPUP-' . $code);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            if ($orgId > 0) {
                try {
                    org_shop_notify_seller_order_status($dbh, $orgId, $buyerUserId, 'paid', [$code]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            return [
                'ok' => true,
                'status' => 'paid',
                'amount_paid_cents' => $newPaid,
                'shortfall_cents' => 0,
                'total_cents' => $due,
            ];
        }

        try {
            $dbh->prepare("
                UPDATE org_orders
                SET amount_paid_cents = :paid,
                    payment_reference = :pref,
                    buyer_notes = :notes,
                    updated_at = NOW()
                WHERE id = :id AND buyer_user_id = :uid
                LIMIT 1
            ")->execute([
                ':paid' => $newPaid,
                ':pref' => mb_substr($payRef, 0, 120),
                ':notes' => mb_substr($notes, 0, 2000),
                ':id' => $orderId,
                ':uid' => $buyerUserId,
            ]);
        } catch (Throwable $e) {
            $dbh->prepare("
                UPDATE org_orders
                SET payment_reference = :pref,
                    buyer_notes = :notes,
                    updated_at = NOW()
                WHERE id = :id AND buyer_user_id = :uid
                LIMIT 1
            ")->execute([
                ':pref' => mb_substr($payRef, 0, 120),
                ':notes' => mb_substr($notes, 0, 2000),
                ':id' => $orderId,
                ':uid' => $buyerUserId,
            ]);
        }
        if ($orgId > 0) {
            try {
                $extra = 'Paid ' . org_shop_format_price($newPaid, $currency)
                    . ' of ' . org_shop_format_price($due, $currency)
                    . ' (short ' . org_shop_format_price($shortfall, $currency) . ')';
                org_shop_notify_seller_order_status($dbh, $orgId, $buyerUserId, 'pending', [$code], $extra);
            } catch (Throwable $e) {
                // ignore
            }
        }
        return [
            'ok' => true,
            'status' => 'pending',
            'amount_paid_cents' => $newPaid,
            'shortfall_cents' => $shortfall,
            'total_cents' => $due,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update payment.'];
    }
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

function org_shop_product_image_abs_path(string $path): string
{
    $rel = ltrim(str_replace('\\', '/', trim($path)), '/');
    if ($rel === '') {
        return '';
    }
    if (stripos($rel, '../organization/') === 0) {
        $rel = substr($rel, strlen('../organization/'));
    }
    if (stripos($rel, 'organization/') === 0) {
        $rel = substr($rel, strlen('organization/'));
    }
    return dirname(__DIR__, 2) . '/organization/' . $rel;
}

function org_shop_product_image_file_exists(string $path): bool
{
    $abs = org_shop_product_image_abs_path($path);
    return $abs !== '' && is_file($abs);
}

function org_shop_organization_web_prefix(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = stripos($script, '/organization/');
    if ($pos !== false) {
        return rtrim(substr($script, 0, $pos + strlen('/organization')), '/') . '/';
    }
    $pos = stripos($script, '/public_user/');
    if ($pos !== false) {
        return rtrim(substr($script, 0, $pos), '/') . '/organization/';
    }
    // Fallback for CLI / odd entry points — keep prior relative behavior.
    return '../organization/';
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
    $rel = ltrim(str_replace('\\', '/', $path), '/');
    if (stripos($rel, '../organization/') === 0) {
        $rel = substr($rel, strlen('../organization/'));
    }
    if (stripos($rel, 'organization/') === 0) {
        $rel = substr($rel, strlen('organization/'));
    }
    if (!org_shop_product_image_file_exists($rel)) {
        return '';
    }
    $url = org_shop_organization_web_prefix() . ltrim($rel, '/');
    $abs = org_shop_product_image_abs_path($rel);
    if ($abs !== '' && is_file($abs)) {
        $mtime = @filemtime($abs);
        if ($mtime) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'v=' . (int)$mtime;
        }
    }
    return $url;
}

function org_shop_cover_missing_html(): string
{
    $label = function_exists('app_t') ? app_t('Photo unavailable') : 'Photo unavailable';
    return '<span class="shop-cover-missing" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<rect x="3.25" y="5.25" width="17.5" height="13.5" rx="1.8" fill="none" stroke="currentColor" stroke-width="1.55"/>'
        . '<circle cx="8.15" cy="9.35" r="1.2" fill="currentColor"/>'
        . '<path d="M4.4 16.55l4.55-4.25 2.85 2.75 3.45-4.2 4.35 5.7" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>'
        . '</span>';
}

function org_shop_cover_img_html(string $url, string $alt = '', string $imgClass = ''): string
{
    $missing = org_shop_cover_missing_html();
    $url = trim($url);
    if ($url === '') {
        return $missing;
    }
    $cls = $imgClass !== '' ? ' class="' . htmlspecialchars($imgClass, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<img' . $cls . ' src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">'
        . $missing;
}

function org_shop_gen_order_code(int $orgId): string
{
    return 'ORD-' . $orgId . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/** Product listing code shown in Product table / Orders (e.g. PRD-9E82A). Always unique. */
function org_shop_product_code_from_id(int $productId): string
{
    $productId = max(1, $productId);
    // Deterministic from primary key — same car model never shares this ID.
    return 'PRD-' . strtoupper(base_convert((string)$productId, 10, 36));
}

function org_shop_product_code_is_taken(PDO $dbh, string $code, int $exceptProductId = 0): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    try {
        if ($exceptProductId > 0) {
            $st = $dbh->prepare('
                SELECT 1 FROM org_products
                WHERE product_code = :code AND id <> :id
                LIMIT 1
            ');
            $st->execute([':code' => $code, ':id' => $exceptProductId]);
        } else {
            $st = $dbh->prepare('SELECT 1 FROM org_products WHERE product_code = :code LIMIT 1');
            $st->execute([':code' => $code]);
        }
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_gen_product_code(PDO $dbh, int $orgId, int $productId = 0): string
{
    if ($productId > 0) {
        $code = org_shop_product_code_from_id($productId);
        if (!org_shop_product_code_is_taken($dbh, $code, $productId)) {
            return $code;
        }
        // Extremely rare collision with a legacy random code — add a suffix.
        for ($i = 0; $i < 8; $i++) {
            $alt = $code . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
            if (!org_shop_product_code_is_taken($dbh, $alt, $productId)) {
                return $alt;
            }
        }
        return $code . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    }

    // Pre-insert provisional code (global uniqueness — not per-org / not by model).
    for ($i = 0; $i < 16; $i++) {
        $code = 'PRD-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        if (!org_shop_product_code_is_taken($dbh, $code, 0)) {
            return $code;
        }
    }
    return 'PRD-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
}

/**
 * Ensure a product has a unique listing code; backfills older rows and fixes duplicates.
 */
function org_shop_ensure_product_code(PDO $dbh, int $orgId, int $productId, ?string $existing = null): string
{
    if ($orgId <= 0 || $productId <= 0) {
        return '';
    }
    $existing = trim((string)$existing);
    if ($existing !== '' && !org_shop_product_code_is_taken($dbh, $existing, $productId)) {
        return $existing;
    }

    $code = org_shop_gen_product_code($dbh, $orgId, $productId);
    try {
        $st = $dbh->prepare('
            UPDATE org_products
            SET product_code = :code, updated_at = NOW()
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ');
        $st->execute([':code' => $code, ':id' => $productId, ':org' => $orgId]);
        return $code;
    } catch (Throwable $e) {
        // Unique-index race: generate another and retry once.
        try {
            $code = org_shop_gen_product_code($dbh, $orgId, 0) . strtoupper(base_convert((string)$productId, 10, 36));
            $st = $dbh->prepare('
                UPDATE org_products
                SET product_code = :code, updated_at = NOW()
                WHERE id = :id AND org_id = :org
                LIMIT 1
            ');
            $st->execute([':code' => $code, ':id' => $productId, ':org' => $orgId]);
            return $code;
        } catch (Throwable $e2) {
            return $code;
        }
    }
}

/** Repair blank / duplicate product_code values (same model must still get unique IDs). */
function org_shop_repair_unique_product_codes(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        // Blank codes.
        $st = $dbh->query("
            SELECT id, org_id
            FROM org_products
            WHERE product_code IS NULL OR TRIM(product_code) = ''
            ORDER BY id ASC
        ");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            org_shop_ensure_product_code(
                $dbh,
                (int)($row['org_id'] ?? 0),
                (int)($row['id'] ?? 0),
                ''
            );
        }

        // Duplicate codes — keep the lowest id, reassign the rest.
        $dup = $dbh->query("
            SELECT product_code
            FROM org_products
            WHERE product_code IS NOT NULL AND TRIM(product_code) <> ''
            GROUP BY product_code
            HAVING COUNT(*) > 1
        ");
        $dupCodes = $dup ? ($dup->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        foreach ($dupCodes as $code) {
            $st2 = $dbh->prepare('
                SELECT id, org_id, product_code
                FROM org_products
                WHERE product_code = :code
                ORDER BY id ASC
            ');
            $st2->execute([':code' => (string)$code]);
            $hits = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $first = true;
            foreach ($hits as $hit) {
                if ($first) {
                    $first = false;
                    continue;
                }
                org_shop_ensure_product_code(
                    $dbh,
                    (int)($hit['org_id'] ?? 0),
                    (int)($hit['id'] ?? 0),
                    '' // force new unique code
                );
            }
        }
    } catch (Throwable $e) {
        // ignore — column/index may not exist yet
    }
}

function org_shop_gen_receipt_code(int $orgId): string
{
    return 'RCP-' . $orgId . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/**
 * Unique Product ID per sold unit / order line.
 * Same catalog car model can sell many times — each sale gets its own ID.
 */
function org_shop_product_unit_code_from_order_id(int $orderId): string
{
    $orderId = max(1, $orderId);
    // Unique per order line (same catalog Mustang sold twice => two IDs).
    return 'PRD-' . strtoupper(str_pad(base_convert((string)$orderId, 10, 36), 5, '0', STR_PAD_LEFT));
}

function org_shop_product_unit_code_is_taken(PDO $dbh, string $code, int $exceptOrderId = 0): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    try {
        if ($exceptOrderId > 0) {
            $st = $dbh->prepare('
                SELECT 1 FROM org_orders
                WHERE product_unit_code = :code AND id <> :id
                LIMIT 1
            ');
            $st->execute([':code' => $code, ':id' => $exceptOrderId]);
        } else {
            $st = $dbh->prepare('SELECT 1 FROM org_orders WHERE product_unit_code = :code LIMIT 1');
            $st->execute([':code' => $code]);
        }
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_ensure_product_unit_code(PDO $dbh, int $orderId, ?string $existing = null): string
{
    if ($orderId <= 0) {
        return '';
    }
    $existing = trim((string)$existing);
    if ($existing !== '' && !org_shop_product_unit_code_is_taken($dbh, $existing, $orderId)) {
        return $existing;
    }

    $code = org_shop_product_unit_code_from_order_id($orderId);
    if (org_shop_product_unit_code_is_taken($dbh, $code, $orderId)) {
        $code = 'PRD-' . strtoupper(base_convert((string)$orderId, 10, 36))
            . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    }

    try {
        $st = $dbh->prepare('
            UPDATE org_orders
            SET product_unit_code = :code, updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':code' => $code, ':id' => $orderId]);
        return $code;
    } catch (Throwable $e) {
        return $code;
    }
}

function org_shop_repair_unique_product_unit_codes(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $st = $dbh->query("
            SELECT id, product_unit_code
            FROM org_orders
            WHERE product_unit_code IS NULL OR TRIM(product_unit_code) = ''
            ORDER BY id ASC
        ");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            org_shop_ensure_product_unit_code($dbh, (int)($row['id'] ?? 0), '');
        }

        $dup = $dbh->query("
            SELECT product_unit_code
            FROM org_orders
            WHERE product_unit_code IS NOT NULL AND TRIM(product_unit_code) <> ''
            GROUP BY product_unit_code
            HAVING COUNT(*) > 1
        ");
        $dupCodes = $dup ? ($dup->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        foreach ($dupCodes as $code) {
            $st2 = $dbh->prepare('
                SELECT id, product_unit_code
                FROM org_orders
                WHERE product_unit_code = :code
                ORDER BY id ASC
            ');
            $st2->execute([':code' => (string)$code]);
            $hits = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $first = true;
            foreach ($hits as $hit) {
                if ($first) {
                    $first = false;
                    continue;
                }
                org_shop_ensure_product_unit_code($dbh, (int)($hit['id'] ?? 0), '');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
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
 * Keep sold_out / active in sync with tracked stock for an org.
 * Zero stock active → sold_out; restocked sold_out → active.
 */
function org_shop_sync_org_sold_out_stock(PDO $dbh, int $orgId): int
{
    if ($orgId <= 0) {
        return 0;
    }
    $changed = 0;
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
        $changed += (int)$st->rowCount();
    } catch (Throwable $e) {
        // continue
    }
    try {
        $st = $dbh->prepare("
            UPDATE org_products
            SET status = 'active', updated_at = NOW()
            WHERE org_id = :org
              AND is_deleted = 0
              AND stock_qty IS NOT NULL
              AND stock_qty > 0
              AND status = 'sold_out'
        ");
        $st->execute([':org' => $orgId]);
        $changed += (int)$st->rowCount();
    } catch (Throwable $e) {
        // keep partial
    }
    return $changed;
}

/**
 * After restocking a single product, clear sold_out when qty > 0.
 */
function org_shop_mark_active_if_restocked(PDO $dbh, int $productId, int $orgId = 0): bool
{
    if ($productId <= 0) {
        return false;
    }
    try {
        $sql = '
            UPDATE org_products
            SET status = \'active\', updated_at = NOW()
            WHERE id = :id
              AND is_deleted = 0
              AND stock_qty IS NOT NULL
              AND stock_qty > 0
              AND status = \'sold_out\'
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
              AND stock_qty IS NOT NULL AND stock_qty > 0 AND stock_qty < 2
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
    $serviceFeeCents = org_shop_buyer_service_fee_cents($dbh, $buyerUserId);
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
        if ($orderId > 0) {
            org_shop_ensure_product_unit_code($dbh, $orderId, '');
        }

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
            if ($orderId > 0) {
                org_shop_ensure_product_unit_code($dbh, $orderId, '');
            }
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

/** Percent value for an active seller promo code (0 when fixed / missing). */
function org_shop_promo_percent(PDO $dbh, int $orgId, string $promoCode): float
{
    $promoCode = strtoupper(trim($promoCode));
    if ($orgId <= 0 || $promoCode === '') {
        return 0.0;
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
        return 0.0;
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
            return 0.0;
        }
        $starts = trim((string)($promo['starts_at'] ?? ''));
        $ends = trim((string)($promo['ends_at'] ?? ''));
        if ($starts !== '' && $today < $starts) {
            return 0.0;
        }
        if ($ends !== '' && $today > $ends) {
            return 0.0;
        }
        $type = (string)($promo['type'] ?? 'percent');
        $value = (float)($promo['value'] ?? 0);
        if ($type === 'percent' && $value > 0) {
            return min(100.0, max(0.0, $value));
        }
        return 0.0;
    }
    return 0.0;
}

/** @return list<array<string, mixed>> */
function org_shop_list_orders(PDO $dbh, int $orgId, string $statusFilter = 'all', int $limit = 100): array
{
    if ($orgId <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 500));
    $where = ['o.org_id = :org'];
    $params = [':org' => $orgId];
    $statusFilter = strtolower(trim($statusFilter));
    if ($statusFilter === 'history') {
        // Completed orders archive (left the active OMS inbox).
        $where[] = "o.status IN ('shipped', 'delivered')";
    } elseif ($statusFilter === 'any' || $statusFilter === 'all_orders') {
        // Full ledger: every status, including cancelled / shipped / delivered.
    } elseif ($statusFilter === 'processing') {
        $where[] = "o.status IN ('pending', 'confirmed', 'paid')";
    } elseif ($statusFilter !== '' && $statusFilter !== 'all') {
        $where[] = 'o.status = :status';
        $params[':status'] = $statusFilter;
    } else {
        // Active Orders inbox: needs seller action. Shipped/delivered live in History Order.
        $where[] = "o.status IN ('pending', 'confirmed', 'paid')";
    }
    $sql = "
        SELECT o.*,
               u.username AS buyer_username,
               p.product_code AS product_code,
               p.cover_image_path AS product_cover
        FROM org_orders o
        LEFT JOIN users u ON u.id = o.buyer_user_id
        LEFT JOIN org_products p ON p.id = o.product_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT {$limit}
    ";
    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $oid = (int)($row['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $unit = trim((string)($row['product_unit_code'] ?? ''));
            $row['product_unit_code'] = org_shop_ensure_product_unit_code($dbh, $oid, $unit);
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        try {
            $sql2 = str_replace(',
               p.cover_image_path AS product_cover', ',
               NULL AS product_cover', $sql);
            $st = $dbh->prepare($sql2);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
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
        $productId = (int)($order['product_id'] ?? 0);
        $catalogCode = trim((string)($order['product_code'] ?? ''));
        if ($catalogCode === '' && $productId > 0) {
            $catalogCode = '#' . $productId;
        }
        // Per-sale Product ID (unique even when catalog/model is the same).
        $unitCode = trim((string)($order['product_unit_code'] ?? ''));
        if ($unitCode === '') {
            $oid = (int)($order['id'] ?? 0);
            $unitCode = $oid > 0 ? org_shop_product_unit_code_from_order_id($oid) : $catalogCode;
        }
        $productCode = $unitCode !== '' ? $unitCode : $catalogCode;
        $titleKey = $productId > 0 ? ('id:' . $productId) : mb_strtolower($title);
        if (!isset($g['products'][$titleKey])) {
            $g['products'][$titleKey] = [
                'title' => $title,
                'qty' => $qty,
                'amount_cents' => $lineCents,
                'product_id' => $productId,
                'product_code' => $productCode,
                'product_codes' => $productCode !== '' ? [$productCode] : [],
            ];
        } else {
            $g['products'][$titleKey]['qty'] += $qty;
            $g['products'][$titleKey]['amount_cents'] += $lineCents;
            if ($productId > 0 && (int)($g['products'][$titleKey]['product_id'] ?? 0) <= 0) {
                $g['products'][$titleKey]['product_id'] = $productId;
            }
            if ($productCode !== '') {
                $codes = $g['products'][$titleKey]['product_codes'] ?? [];
                if (!in_array($productCode, $codes, true)) {
                    $codes[] = $productCode;
                }
                $g['products'][$titleKey]['product_codes'] = $codes;
                // Keep a representative code for older callers.
                if (trim((string)($g['products'][$titleKey]['product_code'] ?? '')) === '') {
                    $g['products'][$titleKey]['product_code'] = $productCode;
                }
            }
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
        $productIds = [];
        foreach ($products as $p) {
            $quantityNum += max(1, (int)($p['qty'] ?? 1));
            $titles[] = (string)$p['title'] . ((int)$p['qty'] > 1 ? ' × ' . (int)$p['qty'] : '');
            $codes = $p['product_codes'] ?? [];
            if (!is_array($codes) || !$codes) {
                $code = trim((string)($p['product_code'] ?? ''));
                $codes = $code !== '' ? [$code] : [];
            }
            foreach ($codes as $code) {
                $code = trim((string)$code);
                if ($code !== '' && !in_array($code, $productIds, true)) {
                    $productIds[] = $code;
                }
            }
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
            'product_ids' => $productIds,
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
    $orderCode = trim((string)($order['order_code'] ?? ''));
    $orderId = (int)($order['id'] ?? 0);

    // One checkout / invoice = same order_code (never every historical order for the buyer).
    if ($orderCode !== '') {
        try {
            $st = $dbh->prepare('
                SELECT o.*, u.username AS buyer_username, p.sku, p.cover_image_path AS product_cover
                FROM org_orders o
                LEFT JOIN users u ON u.id = o.buyer_user_id
                LEFT JOIN org_products p ON p.id = o.product_id
                WHERE o.org_id = :org
                  AND UPPER(TRIM(o.order_code)) = UPPER(:code)
                ORDER BY o.created_at ASC, o.id ASC
            ');
            $st->execute([':org' => $orgId, ':code' => $orderCode]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $rows ?: [$order];
        } catch (Throwable $e) {
            return [$order];
        }
    }

    if ($orderId > 0) {
        return [$order];
    }

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
            SELECT o.*, u.username AS buyer_username, p.sku, p.cover_image_path AS product_cover
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
        $customerPaysShipping = isset($data['shipping_is_free']) && (string)$data['shipping_is_free'] === '0';
        if ($customerPaysShipping) {
            $feeCents = isset($data['shipping_fee_cents'])
                ? (int)$data['shipping_fee_cents']
                : (int)round(((float)($data['shipping_fee'] ?? 0)) * 100);
            if ($feeCents <= 0) {
                return ['ok' => false, 'error' => 'Enter the shipping fee the customer pays, or choose Free shipping (you pay the shipping).'];
            }
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
    // Keep listing status in sync with tracked stock:
    // 0 => sold_out (leaves shop); restocked sold_out => active again.
    if ($stockQty !== null) {
        if ($stockQty <= 0 && $status === 'active') {
            $status = 'sold_out';
        } elseif ($stockQty > 0 && $status === 'sold_out') {
            $status = 'active';
        }
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
            $code = '';
            try {
                $stCode = $dbh->prepare('SELECT product_code FROM org_products WHERE id = :id AND org_id = :org LIMIT 1');
                $stCode->execute([':id' => $productId, ':org' => $orgId]);
                $code = trim((string)($stCode->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $code = '';
            }
            return ['ok' => true, 'product_id' => $productId, 'product_code' => $code];
        }

        $productCode = org_shop_gen_product_code($dbh, $orgId, 0);
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
        $newId = (int)$dbh->lastInsertId();
        // Lock Product ID to this row so identical car models never share a code.
        if ($newId > 0) {
            $finalCode = org_shop_ensure_product_code($dbh, $orgId, $newId, '');
            return ['ok' => true, 'product_id' => $newId, 'product_code' => $finalCode];
        }
        return ['ok' => true, 'product_id' => $newId];
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

/**
 * Duplicate a listing (draft copy, new product code, shared photos).
 *
 * @return array{ok:bool,product_id?:int,error?:string}
 */
function org_shop_duplicate_product(PDO $dbh, int $orgId, int $productId, int $memberId = 0): array
{
    if ($orgId <= 0 || $productId <= 0) {
        return ['ok' => false, 'error' => 'Invalid product.'];
    }
    org_shop_ensure_schema($dbh);
    $src = org_shop_get_product($dbh, $productId, $orgId);
    if (!$src) {
        return ['ok' => false, 'error' => 'Product not found.'];
    }
    $max = org_shop_max_products($dbh, $orgId);
    if (org_shop_product_count($dbh, $orgId) >= $max) {
        return ['ok' => false, 'error' => 'Product limit reached for your rent plan (' . $max . ').'];
    }

    $title = trim((string)($src['title'] ?? 'Product'));
    if (!preg_match('/\(copy\)\s*$/i', $title)) {
        $title .= ' (Copy)';
    }
    if (mb_strlen($title) > 200) {
        $title = mb_substr($title, 0, 200);
    }
    $sku = trim((string)($src['sku'] ?? ''));
    if ($sku !== '') {
        $sku = mb_substr($sku . '-COPY', 0, 64);
    }
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'product', '-'));
    if ($slug === '') {
        $slug = 'product';
    }
    $productCode = org_shop_gen_product_code($dbh, $orgId, 0);

    try {
        $st = $dbh->prepare('
            INSERT INTO org_products (
                org_id, sku, product_code, title, description, seo_title, seo_description, slug,
                bullet_points, search_keywords, fulfillment_method,
                delivery_enabled, pickup_enabled, delivery_carriers, shipping_fee_cents,
                offer_type, pricing_model,
                price_cents, currency, stock_qty, category, selling_type, attributes_json, status,
                cover_image_path, created_by_member_id, created_at, updated_at, is_deleted
            ) VALUES (
                :org, :sku, :pcode, :title, :desc, :seo_t, :seo_d, :slug,
                :bullets, :keywords, :fmethod,
                :deliv, :pickup, :carriers, :shipfee,
                :otype, :pmodel,
                :price, :cur, :stock, :cat, :stype, :attrs, \'draft\',
                :cover, :member, NOW(), NOW(), 0
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':sku' => $sku !== '' ? $sku : null,
            ':pcode' => $productCode,
            ':title' => $title,
            ':desc' => ($src['description'] ?? null) !== null && trim((string)$src['description']) !== '' ? $src['description'] : null,
            ':seo_t' => ($src['seo_title'] ?? null) !== null && trim((string)$src['seo_title']) !== '' ? $src['seo_title'] : null,
            ':seo_d' => ($src['seo_description'] ?? null) !== null && trim((string)$src['seo_description']) !== '' ? $src['seo_description'] : null,
            ':slug' => $slug,
            ':bullets' => ($src['bullet_points'] ?? null) !== null && trim((string)$src['bullet_points']) !== '' ? $src['bullet_points'] : null,
            ':keywords' => ($src['search_keywords'] ?? null) !== null && trim((string)$src['search_keywords']) !== '' ? $src['search_keywords'] : null,
            ':fmethod' => in_array((string)($src['fulfillment_method'] ?? 'fbm'), ['fba', 'fbm'], true) ? (string)$src['fulfillment_method'] : 'fbm',
            ':deliv' => !empty($src['delivery_enabled']) ? 1 : 0,
            ':pickup' => !empty($src['pickup_enabled']) ? 1 : 0,
            ':carriers' => ($src['delivery_carriers'] ?? null) !== null && trim((string)$src['delivery_carriers']) !== '' ? $src['delivery_carriers'] : null,
            ':shipfee' => max(0, (int)($src['shipping_fee_cents'] ?? 0)),
            ':otype' => (string)($src['offer_type'] ?? 'physical'),
            ':pmodel' => (string)($src['pricing_model'] ?? 'one_time'),
            ':price' => max(0, (int)($src['price_cents'] ?? 0)),
            ':cur' => trim((string)($src['currency'] ?? 'USD')) !== '' ? (string)$src['currency'] : 'USD',
            ':stock' => $src['stock_qty'] === null || $src['stock_qty'] === '' ? null : max(0, (int)$src['stock_qty']),
            ':cat' => ($src['category'] ?? null) !== null && trim((string)$src['category']) !== '' ? $src['category'] : null,
            ':stype' => ($src['selling_type'] ?? null) !== null && trim((string)$src['selling_type']) !== '' ? $src['selling_type'] : null,
            ':attrs' => ($src['attributes_json'] ?? null) !== null && trim((string)$src['attributes_json']) !== '' ? $src['attributes_json'] : null,
            ':cover' => ($src['cover_image_path'] ?? null) !== null && trim((string)$src['cover_image_path']) !== '' ? $src['cover_image_path'] : null,
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        $newId = (int)$dbh->lastInsertId();
        if ($newId <= 0) {
            return ['ok' => false, 'error' => 'Could not duplicate product.'];
        }
        org_shop_ensure_product_code($dbh, $orgId, $newId, '');
        foreach (org_shop_list_product_images($dbh, $productId, $orgId) as $img) {
            $path = trim((string)($img['file_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            org_shop_add_product_image_row($dbh, $orgId, $newId, $path, (int)($img['sort_order'] ?? 0));
        }
        return ['ok' => true, 'product_id' => $newId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not duplicate product.'];
    }
}

function org_shop_set_product_listing_status(PDO $dbh, int $orgId, int $productId, string $status): bool
{
    $status = strtolower(trim($status));
    if ($orgId <= 0 || $productId <= 0 || !in_array($status, ['draft', 'active', 'sold_out', 'archived'], true)) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            UPDATE org_products
            SET status = :st, updated_at = NOW()
            WHERE id = :id AND org_id = :org AND is_deleted = 0
            LIMIT 1
        ');
        $st->execute([':st' => $status, ':id' => $productId, ':org' => $orgId]);
        if ($st->rowCount() <= 0) {
            return false;
        }
        if ($status === 'active') {
            org_shop_mark_sold_out_if_empty($dbh, $productId, $orgId);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_mark_product_out_of_stock(PDO $dbh, int $orgId, int $productId): bool
{
    if ($orgId <= 0 || $productId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            UPDATE org_products
            SET stock_qty = 0, status = \'sold_out\', updated_at = NOW()
            WHERE id = :id AND org_id = :org AND is_deleted = 0
            LIMIT 1
        ');
        $st->execute([':id' => $productId, ':org' => $orgId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_set_product_stock(PDO $dbh, int $orgId, int $productId, int $qty): bool
{
    if ($orgId <= 0 || $productId <= 0) {
        return false;
    }
    $qty = max(0, $qty);
    try {
        $statusSql = $qty <= 0
            ? ', status = \'sold_out\''
            : ', status = CASE WHEN status = \'sold_out\' THEN \'active\' ELSE status END';
        $st = $dbh->prepare("
            UPDATE org_products
            SET stock_qty = :q {$statusSql}, updated_at = NOW()
            WHERE id = :id AND org_id = :org AND is_deleted = 0
            LIMIT 1
        ");
        $st->execute([':q' => $qty, ':id' => $productId, ':org' => $orgId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Row actions from the Products catalog ⋯ menu.
 *
 * @return array{ok:bool,message?:string,error?:string}
 */
function org_shop_run_catalog_row_action(PDO $dbh, int $orgId, string $action, int $productId): array
{
    $action = strtolower(trim($action));
    if ($orgId <= 0 || $productId <= 0) {
        return ['ok' => false, 'error' => 'Invalid product.'];
    }
    if ($action === 'delete') {
        return org_shop_delete_product($dbh, $orgId, $productId)
            ? ['ok' => true, 'message' => 'Product removed.']
            : ['ok' => false, 'error' => 'Could not remove product.'];
    }
    if ($action === 'duplicate') {
        $dup = org_shop_duplicate_product($dbh, $orgId, $productId);
        return !empty($dup['ok'])
            ? ['ok' => true, 'message' => 'Product duplicated as a draft.']
            : ['ok' => false, 'error' => (string)($dup['error'] ?? 'Could not duplicate product.')];
    }
    if ($action === 'out_of_stock') {
        return org_shop_mark_product_out_of_stock($dbh, $orgId, $productId)
            ? ['ok' => true, 'message' => 'Product marked out of stock.']
            : ['ok' => false, 'error' => 'Could not update stock.'];
    }
    if ($action === 'deactivate') {
        return org_shop_set_product_listing_status($dbh, $orgId, $productId, 'draft')
            ? ['ok' => true, 'message' => 'Listing deactivated.']
            : ['ok' => false, 'error' => 'Could not deactivate listing.'];
    }
    if ($action === 'activate') {
        return org_shop_set_product_listing_status($dbh, $orgId, $productId, 'active')
            ? ['ok' => true, 'message' => 'Listing activated.']
            : ['ok' => false, 'error' => 'Could not activate listing.'];
    }
    return ['ok' => false, 'error' => 'Unknown action.'];
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
    org_shop_sync_product_cover_from_gallery($dbh, $orgId, $productId);
}

/**
 * Drop gallery/cover DB rows whose files are gone from disk.
 * Returns number of orphan gallery rows removed.
 */
function org_shop_prune_missing_product_images(PDO $dbh, int $orgId, int $productId = 0): int
{
    if ($orgId <= 0) {
        return 0;
    }
    $removed = 0;
    try {
        if ($productId > 0) {
            $st = $dbh->prepare('SELECT id, file_path FROM org_product_images WHERE org_id = :org AND product_id = :pid');
            $st->execute([':org' => $orgId, ':pid' => $productId]);
        } else {
            $st = $dbh->prepare('SELECT id, product_id, file_path FROM org_product_images WHERE org_id = :org');
            $st->execute([':org' => $orgId]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byProduct = [];
        foreach ($rows as $row) {
            $pid = $productId > 0 ? $productId : (int)($row['product_id'] ?? 0);
            $id = (int)($row['id'] ?? 0);
            $path = trim((string)($row['file_path'] ?? ''));
            if ($pid <= 0 || $id <= 0) {
                continue;
            }
            if ($path === '' || !org_shop_product_image_file_exists($path)) {
                $byProduct[$pid][] = $id;
            }
        }
        foreach ($byProduct as $pid => $ids) {
            $removed += org_shop_delete_product_images($dbh, $orgId, (int)$pid, $ids);
            org_shop_sync_product_cover_from_gallery($dbh, $orgId, (int)$pid);
        }

        // Clear covers that point at missing files even when gallery is empty.
        if ($productId > 0) {
            $products = [];
            $p = org_shop_get_product($dbh, $productId, $orgId);
            if ($p) {
                $products[] = $p;
            }
        } else {
            $products = org_shop_list_products($dbh, $orgId, false);
        }
        foreach ($products as $p) {
            $pid = (int)($p['id'] ?? 0);
            $cover = trim((string)($p['cover_image_path'] ?? ''));
            if ($pid <= 0 || $cover === '') {
                continue;
            }
            if (org_shop_product_image_file_exists($cover)) {
                continue;
            }
            $dbh->prepare('UPDATE org_products SET cover_image_path = NULL, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
                ->execute([':id' => $pid, ':org' => $orgId]);
            org_shop_sync_product_cover_from_gallery($dbh, $orgId, $pid);
        }
    } catch (Throwable $e) {
        return $removed;
    }
    return $removed;
}

/**
 * Keep org_products.cover_image_path aligned with the first gallery photo
 * so catalog / inventory / shop thumbnails always resolve.
 */
function org_shop_sync_product_cover_from_gallery(PDO $dbh, int $orgId, int $productId): void
{
    if ($orgId <= 0 || $productId <= 0) {
        return;
    }
    try {
        $gallery = org_shop_list_product_images($dbh, $productId, $orgId);
        $first = '';
        foreach ($gallery as $img) {
            $path = trim((string)($img['file_path'] ?? ''));
            if ($path !== '' && org_shop_product_image_file_exists($path)) {
                $first = $path;
                break;
            }
        }
        $product = org_shop_get_product($dbh, $productId, $orgId);
        $cover = $product ? trim((string)($product['cover_image_path'] ?? '')) : '';

        $coverOk = $cover !== '' && org_shop_product_image_file_exists($cover);
        if ($coverOk) {
            // Ensure cover is also represented in the gallery when possible.
            $inGallery = false;
            foreach ($gallery as $img) {
                if (trim((string)($img['file_path'] ?? '')) === $cover) {
                    $inGallery = true;
                    break;
                }
            }
            if (!$inGallery && count($gallery) < org_shop_product_images_max()) {
                org_shop_add_product_image_row($dbh, $orgId, $productId, $cover, 0);
            }
            return;
        }

        $nextCover = $first !== '' ? $first : null;
        if ($nextCover === null && $cover === '') {
            return;
        }
        $dbh->prepare('UPDATE org_products SET cover_image_path = :p, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
            ->execute([':p' => $nextCover, ':id' => $productId, ':org' => $orgId]);
    } catch (Throwable $e) {
        // ignore sync failure
    }
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
            LEFT JOIN users u ON u.id = o.publisher_user_id
            LEFT JOIN commerce_brands cb ON cb.id = o.commerce_brand_id AND cb.is_active = 1
            WHERE p.is_deleted = 0
              AND p.status = 'active'
              AND (p.stock_qty IS NULL OR p.stock_qty > 0)
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
            LEFT JOIN users u ON u.id = o.publisher_user_id
            LEFT JOIN commerce_brands cb ON cb.id = o.commerce_brand_id AND cb.is_active = 1
            WHERE p.id = :id
              AND p.is_deleted = 0
              AND p.status = 'active'
              AND (p.stock_qty IS NULL OR p.stock_qty > 0)
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
 * Buyer-facing shipping line for shop cards.
 * free   — Delivery on and the seller covers the trip (shipping_fee_cents = 0).
 * paid   — Delivery on and the customer pays shipping_fee_cents.
 * pickup — Pick up only; show the seller's business address.
 *
 * @return array{mode:string,free_shipping:bool,shipping_fee_cents:int,shipping_fee_label:string,pickup_enabled:bool,pickup_only:bool,pickup_address:string}
 */
function org_shop_product_shipping_badge(PDO $dbh, array $product): array
{
    static $addressByOrg = [];
    $receive = org_shop_product_receive_options($product);
    $currency = (string)($product['currency'] ?? 'USD');
    $fee = (int)$receive['shipping_fee_cents'];
    $out = [
        'mode' => 'paid',
        'free_shipping' => false,
        'shipping_fee_cents' => $fee,
        'shipping_fee_label' => $fee > 0 ? org_shop_format_price($fee, $currency) : '',
        'pickup_enabled' => (bool)$receive['pickup_enabled'],
        'pickup_only' => false,
        'pickup_address' => '',
    ];
    if ($receive['delivery_enabled']) {
        if ($fee <= 0) {
            $out['mode'] = 'free';
            $out['free_shipping'] = true;
        }
        return $out;
    }

    $out['mode'] = 'pickup';
    $out['pickup_only'] = true;
    $out['shipping_fee_cents'] = 0;
    $out['shipping_fee_label'] = '';
    $orgId = (int)($product['org_id'] ?? 0);
    if (!array_key_exists($orgId, $addressByOrg)) {
        $text = org_shop_seller_pickup_address_text($dbh, $orgId);
        $addressByOrg[$orgId] = trim((string)preg_replace('/\s*\n\s*/', ', ', $text));
    }
    $out['pickup_address'] = $addressByOrg[$orgId];
    return $out;
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
function org_shop_find_buyer_order_by_code(PDO $dbh, int $buyerUserId, string $orderCode): ?array
{
    if ($buyerUserId <= 0) {
        return null;
    }
    $orderCode = strtoupper(trim($orderCode));
    if ($orderCode === '') {
        return null;
    }
    try {
        $st = $dbh->prepare("
            SELECT o.id
            FROM org_orders o
            WHERE o.buyer_user_id = :uid
              AND o.buyer_hidden_at IS NULL
              AND UPPER(TRIM(o.order_code)) = :code
            ORDER BY o.id DESC
            LIMIT 1
        ");
        $st->execute([':uid' => $buyerUserId, ':code' => $orderCode]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id <= 0) {
            return null;
        }
        return org_shop_get_buyer_order($dbh, $buyerUserId, $id);
    } catch (Throwable $e) {
        return null;
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

        // Auto-push seller payout via Stripe Connect when the org is onboarded.
        try {
            $connectPath = __DIR__ . '/org_shop_connect.php';
            if (is_file($connectPath)) {
                require_once $connectPath;
            }
            if (function_exists('org_shop_connect_auto_payout_order')) {
                org_shop_connect_auto_payout_order($dbh, $orderId);
            }
        } catch (Throwable $eConnect) {
            // non-fatal — seller can mark payouts manually
        }

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

    $orderIds = [];
    $metaIds = trim((string)($session['metadata']['order_ids'] ?? ''));
    if ($metaIds !== '') {
        foreach (explode(',', $metaIds) as $piece) {
            $oid = (int)trim($piece);
            if ($oid > 0) {
                $orderIds[$oid] = $oid;
            }
        }
    }
    $singleId = (int)($session['metadata']['order_id'] ?? 0);
    if ($singleId > 0) {
        $orderIds[$singleId] = $singleId;
    }
    if (!$orderIds) {
        $code = trim((string)($session['client_reference_id'] ?? ''));
        if ($code !== '' && strpos($code, 'cart-') !== 0) {
            try {
                $st = $dbh->prepare('SELECT id FROM org_orders WHERE order_code = :c LIMIT 1');
                $st->execute([':c' => $code]);
                $oid = (int)($st->fetchColumn() ?: 0);
                if ($oid > 0) {
                    $orderIds[$oid] = $oid;
                }
            } catch (Throwable $e) {
            }
        }
    }
    if ($sessionId !== '' && !$orderIds) {
        try {
            $st = $dbh->prepare('SELECT id FROM org_orders WHERE stripe_checkout_session_id = :sid');
            $st->execute([':sid' => $sessionId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $oid = (int)($row['id'] ?? 0);
                if ($oid > 0) {
                    $orderIds[$oid] = $oid;
                }
            }
        } catch (Throwable $e) {
        }
    }

    if (!$orderIds) {
        return false;
    }

    $okAny = false;
    foreach ($orderIds as $orderId) {
        if (org_shop_fulfill_stripe_payment($dbh, $orderId, $sessionId, $paymentIntent)) {
            $okAny = true;
        }
    }
    return $okAny;
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
    $productUrl = 'product_detail.php?id=' . $productId;
    $bodyLines = [];
    if (trim((string)($product['description'] ?? '')) !== '') {
        $bodyLines[] = trim((string)$product['description']);
    }
    $bodyLines[] = 'Price: ' . $priceLabel;
    $bodyLines[] = 'Buy: ' . $productUrl;
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

    // Attach shoppable tag so feed/reel Buy chips open the existing buy door.
    try {
        $engagePath = __DIR__ . '/msb_feed_engagement.php';
        if (is_file($engagePath)) {
            require_once $engagePath;
        }
        if (function_exists('msb_save_post_products')) {
            msb_save_post_products($dbh, $publicPostId, $orgId, [$productId]);
        }
    } catch (Throwable $e) {
        // non-fatal — post still published
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
 * Buyer withdraws their own return request while the seller has not acted on it yet.
 * @return array{ok:bool,error?:string}
 */
function org_shop_buyer_cancel_return(PDO $dbh, int $returnId, int $buyerUserId): array
{
    org_shop_ensure_schema($dbh);
    if ($returnId <= 0 || $buyerUserId <= 0) {
        return ['ok' => false, 'error' => 'Return request not found.'];
    }
    try {
        $st = $dbh->prepare('SELECT id, status FROM org_order_returns WHERE id = :id AND buyer_user_id = :uid LIMIT 1');
        $st->execute([':id' => $returnId, ':uid' => $buyerUserId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Return request not found.'];
        }
        if (strtolower(trim((string)($row['status'] ?? ''))) !== 'requested') {
            return ['ok' => false, 'error' => 'The seller already reviewed this return, so it can no longer be cancelled.'];
        }
        $col = $dbh->query("SHOW COLUMNS FROM org_order_returns LIKE 'status'")->fetch(PDO::FETCH_ASSOC) ?: [];
        if (stripos((string)($col['Type'] ?? ''), 'enum(') === 0 && stripos((string)$col['Type'], "'cancelled'") === false) {
            $dbh->exec("
                ALTER TABLE org_order_returns
                MODIFY status ENUM('requested','approved','rejected','refunded','cancelled')
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested'
            ");
        }
        $upd = $dbh->prepare("
            UPDATE org_order_returns
            SET status = 'cancelled', updated_at = NOW()
            WHERE id = :id AND buyer_user_id = :uid AND status = 'requested'
            LIMIT 1
        ");
        $upd->execute([':id' => $returnId, ':uid' => $buyerUserId]);
        return $upd->rowCount() > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'Could not cancel the return request.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not cancel the return request.'];
    }
}

/**
 * Whether a buyer may still cancel this order line (before shipment).
 */
function org_shop_buyer_order_is_cancellable(array $order): bool
{
    $status = strtolower(trim((string)($order['status'] ?? '')));
    if (!in_array($status, ['pending', 'confirmed', 'paid'], true)) {
        return false;
    }
    if (trim((string)($order['shipped_at'] ?? '')) !== '') {
        return false;
    }
    // Carrier + tracking means the seller already shipped, even if status lagged.
    $track = trim((string)($order['tracking_number'] ?? ''));
    $carrier = trim((string)($order['carrier'] ?? ''));
    if ($track !== '' && $carrier !== '') {
        return false;
    }
    return true;
}

/**
 * Group buyer orders by seller checkout (same as Your_Shopping_preferences.php #order-history).
 * Returns invoice-style rows for My Orders list + detail panel (cents + labels).
 *
 * @return list<array<string,mixed>>
 */
function org_shop_buyer_order_history_groups(PDO $dbh, int $buyerUserId, int $limit = 200): array
{
    if ($buyerUserId <= 0 || !function_exists('org_shop_list_buyer_orders')) {
        return [];
    }
    $orders = org_shop_list_buyer_orders($dbh, $buyerUserId, max(1, min($limit, 200)));
    if ($orders === []) {
        return [];
    }

    $buyerName = 'Buyer';
    $buyerEmail = '';
    $buyerPhone = '';
    try {
        $st = $dbh->prepare('SELECT name, username, email, mobile FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $buyerUserId]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $buyerName = trim((string)($u['name'] ?? ''));
        if ($buyerName === '') {
            $buyerName = trim((string)($u['username'] ?? ''));
        }
        if ($buyerName === '') {
            $buyerName = 'Buyer';
        }
        $buyerEmail = trim((string)($u['email'] ?? ''));
        $buyerPhone = function_exists('user_phone_from_user_row')
            ? user_phone_from_user_row($u)
            : trim((string)($u['mobile'] ?? ''));
        if (strcasecmp($buyerPhone, 'N/A') === 0) {
            $buyerPhone = '';
        }
    } catch (Throwable $e) {
        // keep defaults
    }

    $sellerContact = static function (PDO $dbh, int $orgId, int $publisherUserId): array {
        $out = ['email' => '', 'phone' => '', 'address' => ''];
        if ($orgId > 0) {
            try {
                $st = $dbh->prepare('SELECT shop_json FROM org_settings WHERE org_id = :org LIMIT 1');
                $st->execute([':org' => $orgId]);
                $raw = (string)($st->fetchColumn() ?: '');
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $out['email'] = trim((string)($decoded['contact_email'] ?? ''));
                        $out['phone'] = trim((string)($decoded['contact_phone'] ?? ''));
                        if (is_array($decoded['address'] ?? null) && function_exists('org_shop_format_seller_address')) {
                            $out['address'] = org_shop_format_seller_address($decoded['address']);
                        }
                    }
                }
            } catch (Throwable $e) {
            }
        }
        if (($out['email'] === '' || $out['phone'] === '') && $publisherUserId > 0) {
            try {
                $st = $dbh->prepare('SELECT email, mobile FROM users WHERE id = :id LIMIT 1');
                $st->execute([':id' => $publisherUserId]);
                $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($out['email'] === '') {
                    $out['email'] = trim((string)($user['email'] ?? ''));
                }
                if ($out['phone'] === '') {
                    $out['phone'] = function_exists('user_phone_from_user_row')
                        ? user_phone_from_user_row($user)
                        : trim((string)($user['mobile'] ?? ''));
                    if (strcasecmp($out['phone'], 'N/A') === 0) {
                        $out['phone'] = '';
                    }
                }
            } catch (Throwable $e) {
            }
        }
        return $out;
    };

    $prefDate = static function ($value): string {
        $raw = trim((string)$value);
        if ($raw === '') {
            return 'Not set';
        }
        $ts = strtotime($raw);
        return $ts ? date('M j, Y', $ts) : $raw;
    };

    // Mirror Your_Shopping_preferences.php $buyerPaymentGroups: one group per seller checkout.
    // Cart checkout inserts one org_orders row per product within seconds; a later purchase
    // from the same seller (even on the same day) is its own group.
    $checkoutGapSeconds = 120;
    $batchByOrderId = [];
    $ordersChrono = $orders;
    usort($ordersChrono, static function (array $a, array $b): int {
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        return $ta <=> $tb ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });
    $lastByOrg = [];
    foreach ($ordersChrono as $order) {
        $orderIdKey = (int)($order['id'] ?? 0);
        $orgKey = (int)($order['org_id'] ?? 0);
        $ts = strtotime((string)($order['created_at'] ?? '')) ?: 0;
        $last = $lastByOrg[$orgKey] ?? null;
        if ($last === null || $ts <= 0 || ($ts - (int)$last['ts']) > $checkoutGapSeconds) {
            $last = ['batch' => $orgKey . '|' . ($ts > 0 ? $ts : ('o' . $orderIdKey)), 'ts' => $ts];
        } else {
            $last['ts'] = $ts;
        }
        $lastByOrg[$orgKey] = $last;
        $batchByOrderId[$orderIdKey] = $last['batch'];
    }

    $groups = [];
    foreach ($orders as $order) {
        $status = strtolower(trim((string)($order['status'] ?? 'pending')));
        if ($status === 'cancelled') {
            continue;
        }
        $orgId = (int)($order['org_id'] ?? 0);
        $company = trim((string)($order['seller_name'] ?? '')) ?: 'Seller';
        $createdRaw = (string)($order['created_at'] ?? '');
        $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : false;
        $groupKey = $batchByOrderId[(int)($order['id'] ?? 0)]
            ?? ($orgId . '|o' . (int)($order['id'] ?? 0));
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'org_id' => $orgId,
                'publisher_user_id' => (int)($order['publisher_user_id'] ?? 0),
                'company' => $company,
                'date_raw' => $createdRaw,
                'date' => $prefDate($createdRaw),
                'time' => $createdTs ? date('g:i A', $createdTs) : '',
                'date_sort' => $createdTs ?: 0,
                'currency' => (string)($order['currency'] ?? 'USD'),
                'total_cents' => 0,
                'shipping_fee_cents' => 0,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'service_fee_cents' => 0,
                'merchandise_cents' => 0,
                'statuses' => [],
                'receipts' => [],
                'order_codes' => [],
                'order_ids' => [],
                'cancellable_ids' => [],
                'ship_detail_id' => 0,
                'ship_detail_code' => '',
                'product_ids' => [],
                'products' => [],
                'item_count' => 0,
                'cover_image_path' => '',
            ];
        }
        $g = &$groups[$groupKey];
        $g['total_cents'] += (int)($order['total_cents'] ?? 0);
        $g['shipping_fee_cents'] += max(0, (int)($order['shipping_fee_cents'] ?? 0));
        $g['discount_cents'] += max(0, (int)($order['discount_cents'] ?? 0));
        $g['tax_cents'] += max(0, (int)($order['tax_cents'] ?? 0));
        $g['service_fee_cents'] += max(0, (int)($order['service_fee_cents'] ?? 0));
        $orderIdRow = (int)($order['id'] ?? 0);
        if ($orderIdRow > 0 && !in_array($orderIdRow, $g['order_ids'], true)) {
            $g['order_ids'][] = $orderIdRow;
        }
        if ($orderIdRow > 0 && function_exists('org_shop_buyer_order_is_cancellable')
            && org_shop_buyer_order_is_cancellable($order)
            && !in_array($orderIdRow, $g['cancellable_ids'], true)) {
            $g['cancellable_ids'][] = $orderIdRow;
        }
        $statusForGroup = $status;
        if (
            in_array($status, ['pending', 'confirmed', 'paid'], true)
            && function_exists('org_shop_buyer_order_is_cancellable')
            && !org_shop_buyer_order_is_cancellable($order)
        ) {
            $statusForGroup = 'shipped';
        }
        if ($statusForGroup !== '') {
            $g['statuses'][] = $statusForGroup;
        }
        $receipt = trim((string)($order['receipt_code'] ?? ''));
        if ($receipt !== '' && !in_array($receipt, $g['receipts'], true)) {
            $g['receipts'][] = $receipt;
        }
        $orderCode = trim((string)($order['order_code'] ?? ''));
        if ($orderCode === '') {
            $orderCode = '#' . (int)($order['id'] ?? 0);
        }
        if (!in_array($orderCode, $g['order_codes'], true)) {
            $g['order_codes'][] = $orderCode;
        }
        if ($statusForGroup === 'delivered' && $orderIdRow > 0) {
            $prevDetailStatus = (string)($g['ship_detail_status'] ?? '');
            if ((int)($g['ship_detail_id'] ?? 0) <= 0 || $prevDetailStatus !== 'delivered') {
                $g['ship_detail_id'] = $orderIdRow;
                $g['ship_detail_code'] = $orderCode;
                $g['ship_detail_status'] = 'delivered';
            }
        } elseif (
            $statusForGroup === 'shipped'
            && (int)($g['ship_detail_id'] ?? 0) <= 0
            && $orderIdRow > 0
        ) {
            $g['ship_detail_id'] = $orderIdRow;
            $g['ship_detail_code'] = $orderCode;
            $g['ship_detail_status'] = 'shipped';
        }
        if ($g['cover_image_path'] === '') {
            $g['cover_image_path'] = trim((string)($order['cover_image_path'] ?? ''));
        }
        $qty = max(1, (int)($order['quantity'] ?? 1));
        $title = trim((string)($order['product_title'] ?? '')) ?: 'Product';
        $unit = max(0, (int)($order['unit_price_cents'] ?? 0));
        $lineCents = max(0, $unit * $qty);
        if ($lineCents <= 0) {
            $shipCents = max(0, (int)($order['shipping_fee_cents'] ?? 0));
            $taxCents = max(0, (int)($order['tax_cents'] ?? 0));
            $svcCents = max(0, (int)($order['service_fee_cents'] ?? 0));
            $disc = max(0, (int)($order['discount_cents'] ?? 0));
            $lineTotal = (int)($order['total_cents'] ?? 0);
            $lineCents = max(0, $lineTotal - $shipCents - $taxCents - $svcCents + $disc);
        }
        $g['merchandise_cents'] += $lineCents;
        $productIdRow = (int)($order['product_id'] ?? 0);
        if ($productIdRow > 0 && !in_array($productIdRow, $g['product_ids'], true)) {
            $g['product_ids'][] = $productIdRow;
        }
        $g['products'][] = [
            'title' => $title,
            'qty' => $qty,
            'amount' => org_shop_format_price($lineCents, (string)($order['currency'] ?? $g['currency'])),
            'amount_cents' => $lineCents,
            'product_id' => $productIdRow,
            'category' => trim((string)($order['category'] ?? '')),
        ];
        $g['item_count'] += $qty;
        if ($createdTs && $createdTs > (int)$g['date_sort']) {
            $g['date_raw'] = $createdRaw;
            $g['date'] = $prefDate($createdRaw);
            $g['time'] = date('g:i A', $createdTs);
            $g['date_sort'] = $createdTs;
        }
        unset($g);
    }

    uasort($groups, static function (array $a, array $b): int {
        return ((int)$b['date_sort']) <=> ((int)$a['date_sort']);
    });
    $groups = array_values($groups);
    $sellerContactCache = [];

    foreach ($groups as &$group) {
        $statuses = array_values(array_unique($group['statuses']));
        $hasShipped = false;
        foreach ($statuses as $st) {
            if (in_array($st, ['shipped', 'delivered'], true)) {
                $hasShipped = true;
                break;
            }
        }
        if ($hasShipped) {
            $group['cancellable_ids'] = [];
        }
        if (count($statuses) === 1) {
            $group['status'] = $statuses[0];
        } elseif ($hasShipped) {
            $group['status'] = in_array('delivered', $statuses, true) ? 'delivered' : 'shipped';
        } elseif (in_array('pending', $statuses, true)) {
            $group['status'] = 'pending';
        } elseif ($statuses) {
            $group['status'] = 'multiple';
        } else {
            $group['status'] = 'pending';
        }
        $currency = (string)$group['currency'];
        $group['total'] = org_shop_format_price((int)$group['total_cents'], $currency);
        $shipCents = max(0, (int)($group['shipping_fee_cents'] ?? 0));
        $discCents = max(0, (int)($group['discount_cents'] ?? 0));
        $taxCents = max(0, (int)($group['tax_cents'] ?? 0));
        $svcCents = max(0, (int)($group['service_fee_cents'] ?? 0));
        $merchCents = max(0, (int)($group['merchandise_cents'] ?? 0));
        if ($merchCents <= 0) {
            $merchCents = max(0, (int)$group['total_cents'] - $shipCents - $taxCents - $svcCents + $discCents);
            $group['merchandise_cents'] = $merchCents;
        }
        $group['shipping_label'] = $shipCents > 0 ? org_shop_format_price($shipCents, $currency) : 'Free';
        $group['shipping_is_free'] = $shipCents <= 0;
        $group['subtotal_label'] = org_shop_format_price($merchCents, $currency);
        $group['discount_label'] = org_shop_format_price($discCents, $currency);
        $group['tax_label'] = org_shop_format_price($taxCents, $currency);
        $group['service_fee_label'] = org_shop_format_price($svcCents, $currency);
        if (count($group['receipts']) === 1) {
            $group['receipt_label'] = $group['receipts'][0];
        } elseif (count($group['receipts']) > 1) {
            $group['receipt_label'] = count($group['receipts']) . ' receipts';
        } else {
            $group['receipt_label'] = 'Pending';
        }
        $orderCount = count($group['order_codes']);
        if ($orderCount === 1) {
            $group['order_label'] = $group['order_codes'][0];
        } elseif ($orderCount > 1) {
            $group['order_label'] = $orderCount . ' orders';
        } else {
            $group['order_label'] = '—';
        }
        $group['date_time'] = $group['date'] . ((string)($group['time'] ?? '') !== '' ? (' · ' . $group['time']) : '');
        $group['invoice_label'] = $orderCount === 1
            ? $group['order_codes'][0]
            : ($group['company'] . ' · ' . $group['date_time']);
        $due = 'Not set';
        if (trim((string)$group['date_raw']) !== '') {
            $dueTs = strtotime((string)$group['date_raw']);
            if ($dueTs) {
                $due = date('M j, Y', strtotime('+30 days', $dueTs));
            }
        }
        $group['due'] = $due;
        $orgId = (int)($group['org_id'] ?? 0);
        $cacheKey = (string)$orgId;
        if (!isset($sellerContactCache[$cacheKey])) {
            $sellerContactCache[$cacheKey] = $sellerContact($dbh, $orgId, (int)($group['publisher_user_id'] ?? 0));
        }
        $contact = $sellerContactCache[$cacheKey];
        $group['contact_email'] = (string)($contact['email'] ?? '');
        $group['contact_phone'] = (string)($contact['phone'] ?? '');
        $group['contact_address'] = (string)($contact['address'] ?? '');
    }
    unset($group);

    // Mirror $buyerOrderHistoryRows
    $rows = [];
    $idx = 1;
    foreach ($groups as $group) {
        $mergedProducts = [];
        foreach ($group['products'] as $p) {
            $title = trim((string)($p['title'] ?? '')) ?: 'Product';
            $key = mb_strtolower($title);
            $qty = max(1, (int)($p['qty'] ?? 1));
            $amountCents = (int)($p['amount_cents'] ?? 0);
            if (!isset($mergedProducts[$key])) {
                $mergedProducts[$key] = [
                    'title' => $title,
                    'qty' => $qty,
                    'amount_cents' => $amountCents,
                    'amount' => (string)($p['amount'] ?? org_shop_format_price($amountCents, (string)$group['currency'])),
                    'product_id' => (int)($p['product_id'] ?? 0),
                ];
            } else {
                $mergedProducts[$key]['qty'] += $qty;
                $mergedProducts[$key]['amount_cents'] += $amountCents;
                $mergedProducts[$key]['amount'] = org_shop_format_price(
                    (int)$mergedProducts[$key]['amount_cents'],
                    (string)$group['currency']
                );
                if ((int)($mergedProducts[$key]['product_id'] ?? 0) <= 0 && (int)($p['product_id'] ?? 0) > 0) {
                    $mergedProducts[$key]['product_id'] = (int)$p['product_id'];
                }
            }
        }
        $productsList = array_values($mergedProducts);
        $productCount = count($productsList);
        $quantityTotal = 0;
        foreach ($productsList as $p) {
            $quantityTotal += max(1, (int)($p['qty'] ?? 1));
        }
        $cancellableIds = array_values(array_filter(array_map('intval', $group['cancellable_ids'] ?? [])));
        $shipDetailId = (int)($group['ship_detail_id'] ?? 0);
        $shipDetailCode = trim((string)($group['ship_detail_code'] ?? ''));
        if ($shipDetailId <= 0) {
            $shipDetailId = (int)(($group['order_ids'][0] ?? 0));
        }
        if ($shipDetailCode === '' && !empty($group['order_codes'][0])) {
            $shipDetailCode = (string)$group['order_codes'][0];
        }
        $status = (string)$group['status'];
        $statusKey = strtolower(trim($status));
        if ($statusKey === 'shipped') {
            $statusLabel = 'Shipp Tracking';
        } elseif ($statusKey === 'delivered') {
            $statusLabel = 'Delivered';
        } else {
            $statusLabel = $status !== '' ? ucfirst($status) : 'pending';
        }
        $canCancel = $cancellableIds !== [];
        $primaryId = (int)(($group['order_ids'][0] ?? 0));
        $coverUrl = function_exists('org_shop_cover_url')
            ? org_shop_cover_url((string)($group['cover_image_path'] ?? ''))
            : '';
        $primaryProductId = $productsList !== [] ? (int)($productsList[0]['product_id'] ?? 0) : 0;
        if ($primaryProductId <= 0 && !empty($group['product_ids'][0])) {
            $primaryProductId = (int)$group['product_ids'][0];
        }
        $primaryCategory = $productsList !== [] ? trim((string)($productsList[0]['category'] ?? '')) : '';
        if ($primaryCategory === '') {
            $primaryCategory = $productsList !== [] ? trim((string)($productsList[0]['title'] ?? 'Item')) : 'Item';
        }

        $rows[] = [
            'id' => $primaryId > 0 ? $primaryId : $idx,
            'group_index' => $idx,
            'is_order_group' => true,
            'order_id' => $primaryId,
            'order_ids' => $group['order_ids'] ?? [],
            'cancellable_ids' => $cancellableIds,
            'cancellable_order_ids' => $cancellableIds,
            'can_cancel' => $canCancel,
            'cancellable' => $canCancel,
            'can_return' => in_array($statusKey, ['paid', 'shipped', 'delivered'], true),
            'can_review' => $statusKey === 'delivered',
            'primary_product_id' => $primaryProductId,
            'view_product_label' => $primaryProductId > 0
                ? ('View product · ' . $primaryCategory)
                : 'View product',
            'ship_detail_id' => $shipDetailId,
            'ship_detail_code' => $shipDetailCode,
            'order_num' => $productCount,
            'product_count' => $productCount,
            'quantity_num' => $quantityTotal,
            'quantity' => $quantityTotal,
            'order_label' => (string)$group['order_label'],
            'order_code' => (string)$group['order_label'],
            'invoice_label' => (string)$group['invoice_label'],
            'receipt_label' => (string)$group['receipt_label'],
            'company' => (string)$group['company'],
            'seller' => (string)$group['company'],
            'seller_name' => (string)$group['company'],
            'org_id' => (int)$group['org_id'],
            'publisher_user_id' => (int)$group['publisher_user_id'],
            'status' => $status,
            'status_label' => $statusLabel,
            'total_cents' => (int)$group['total_cents'],
            'total' => (string)$group['total'],
            'total_label' => (string)$group['total'],
            'total_amount' => ((int)$group['total_cents']) / 100.0,
            'merchandise_cents' => (int)($group['merchandise_cents'] ?? 0),
            'subtotal_label' => (string)($group['subtotal_label'] ?? '$0.00'),
            'shipping_fee_cents' => (int)($group['shipping_fee_cents'] ?? 0),
            'shipping_label' => (string)($group['shipping_label'] ?? 'Free'),
            'shipping_is_free' => !empty($group['shipping_is_free']),
            'discount_cents' => (int)($group['discount_cents'] ?? 0),
            'discount_label' => (string)($group['discount_label'] ?? '$0.00'),
            'tax_cents' => (int)($group['tax_cents'] ?? 0),
            'tax_label' => (string)($group['tax_label'] ?? '$0.00'),
            'service_fee_cents' => (int)($group['service_fee_cents'] ?? 0),
            'service_fee_label' => (string)($group['service_fee_label'] ?? '$0.00'),
            'currency' => (string)$group['currency'],
            'date' => (string)($group['date_time'] ?? $group['date']),
            'date_label' => (string)($group['date_time'] ?? $group['date']),
            'time' => (string)($group['time'] ?? ''),
            'created_at' => (string)$group['date_raw'],
            'due' => (string)$group['due'],
            'due_date_label' => (string)$group['due'],
            'contact_email' => (string)($group['contact_email'] ?? ''),
            'contact_phone' => (string)($group['contact_phone'] ?? ''),
            'contact_address' => (string)($group['contact_address'] ?? ''),
            'seller_email' => (string)($group['contact_email'] ?? ''),
            'seller_phone' => (string)($group['contact_phone'] ?? ''),
            'seller_address' => (string)($group['contact_address'] ?? ''),
            'products' => $productsList,
            'line_items' => $productsList,
            'product_ids' => array_values(array_filter(array_map('intval', $group['product_ids'] ?? []))),
            'item_count' => $quantityTotal,
            'cover_image_path' => (string)($group['cover_image_path'] ?? ''),
            'cover_url' => $coverUrl,
            'product_title' => $productsList !== [] ? (string)$productsList[0]['title'] : 'Order group',
            'product_id' => $productsList !== [] ? (int)$productsList[0]['product_id'] : 0,
            'buyer_name' => $buyerName,
            'buyer_email' => $buyerEmail,
            'buyer_phone' => $buyerPhone,
            'money' => [
                'subtotal' => (string)($group['subtotal_label'] ?? '$0.00'),
                'discount' => (string)($group['discount_label'] ?? '$0.00'),
                'shipping' => (string)($group['shipping_label'] ?? 'Free'),
                'tax' => (string)($group['tax_label'] ?? '$0.00'),
                'service_fee' => (string)($group['service_fee_label'] ?? '$0.00'),
                'grand_total' => (string)$group['total'],
            ],
        ];
        $idx++;
    }

    return $rows;
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
    try {
        $st = $dbh->prepare('
            SELECT id, org_id, product_id, quantity, status, buyer_notes, seller_notes, order_code,
                   shipped_at, tracking_number, carrier
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
        if (!org_shop_buyer_order_is_cancellable($order)) {
            if (
                in_array($status, ['shipped', 'delivered'], true)
                || trim((string)($order['shipped_at'] ?? '')) !== ''
                || (trim((string)($order['tracking_number'] ?? '')) !== '' && trim((string)($order['carrier'] ?? '')) !== '')
            ) {
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
              AND shipped_at IS NULL
            LIMIT 1
        ');
        $upd->execute([
            ':notes' => mb_substr($buyerNotes, 0, 2000),
            ':snotes' => mb_substr($sellerNotes, 0, 2000),
            ':id' => $orderId,
            ':uid' => $buyerUserId,
        ]);
        if ($upd->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'Could not cancel this order. It may have already shipped or changed status.'];
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
    require_once __DIR__ . '/app_notification_api.php';
    $suffix = ' [r:' . $route . ']';
    $room = max(20, min(470, app_notification_type_capacity($dbh) - mb_strlen($suffix)));
    if (mb_strlen($message) > $room) {
        $message = rtrim(mb_substr($message, 0, $room - 1)) . '…';
    }
    $type = $message . $suffix;
    try {
        $ins = $dbh->prepare('
            INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
            VALUES (:sender, :receiver, :type, 0)
        ');
        $ins->execute([
            ':sender' => mb_substr($senderLabel, 0, 120),
            ':receiver' => mb_substr($receiverUsername, 0, 120),
            ':type' => $type,
        ]);
    } catch (Throwable $e) {
        // never block commerce flows
    }
}

/** Mark the buyer's shop commerce alerts read (Shop → Notifications opened). Social rows untouched. */
function org_shop_mark_commerce_inbox_read(PDO $dbh, int $buyerUserId): int
{
    if ($buyerUserId <= 0) {
        return 0;
    }
    require_once __DIR__ . '/app_notification_api.php';
    $receivers = app_notification_receivers($dbh, $buyerUserId);
    if ($receivers === []) {
        return 0;
    }
    try {
        $ph = implode(',', array_fill(0, count($receivers), '?'));
        $st = $dbh->prepare("
            UPDATE notification
            SET is_read = 1
            WHERE notireceiver IN ($ph)
              AND is_read = 0
              " . app_notification_shop_only_sql() . "
        ");
        $st->execute(array_merge($receivers, app_notification_shop_like_patterns()));
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
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
                . ' — payment incomplete. Do not ship until status is Paid.';
            if ($extra !== '') {
                $message .= ' ' . mb_substr($extra, 0, 160) . '.';
            } else {
                $message .= ' Credit/debit shortfall or card issue.';
            }
            $message .= ' Open Sales Management → Orders / Notification.';
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
                . '. Open Notifications, then open the order to track delivery.';
        } else {
            $message = 'Your item is now delivered'
                . ($code !== '' ? ' — order ' . $code : '')
                . ' — ' . $title
                . '. Open Notifications to view delivery from start to finish.';
        }
        org_shop_insert_commerce_notification($dbh, $idents['org_name'], $buyerUsername, $message, 'shop');
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Deep-link to the buyer's newest order matching one of the given statuses.
 */
function org_shop_buyer_latest_order_detail_href(PDO $dbh, int $buyerUserId, array $statuses): string
{
    if ($buyerUserId <= 0 || !$statuses) {
        return 'Your_Shopping_preferences.php#order-history';
    }
    $want = [];
    foreach ($statuses as $st) {
        $st = strtolower(trim((string)$st));
        if ($st !== '') {
            $want[$st] = true;
        }
    }
    if (!$want) {
        return 'Your_Shopping_preferences.php#order-history';
    }
    try {
        foreach (org_shop_list_buyer_orders($dbh, $buyerUserId, 200) as $order) {
            $status = strtolower(trim((string)($order['status'] ?? '')));
            if (!isset($want[$status])) {
                continue;
            }
            $orderId = (int)($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $code = trim((string)($order['order_code'] ?? ''));
            return 'order_detail.php?order_id=' . $orderId
                . ($code !== '' ? ('&code=' . rawurlencode($code)) : '');
        }
    } catch (Throwable $e) {
        // fall through
    }
    return 'Your_Shopping_preferences.php#order-history';
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
        $cancelBuckets = [];
        $cancellationBuckets = [];
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
                $when = (string)($order['updated_at'] ?? $order['created_at'] ?? '');
                $ts = $when !== '' ? (int)strtotime($when) : time();
                // One cancel action from Order history can close several same-seller lines at once.
                $bucket = ((int)($order['org_id'] ?? 0))
                    . '|' . ((string)($meta['by'] ?? 'Customer'))
                    . '|' . mb_strtolower(trim((string)($meta['reason'] ?? '')))
                    . '|' . (string)(int)floor($ts / 120);
                if ((string)($meta['by'] ?? 'Customer') === 'Seller') {
                    $cancelBuckets[$bucket] = true;
                } else {
                    $cancellationBuckets[$bucket] = true;
                }
            }
        }
        $out['cancel'] = count($cancelBuckets);
        $out['cancellation'] = count($cancellationBuckets);
        $out['cancelled'] = $out['cancel'] + $out['cancellation'];
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
    $shipHref = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['shipped']);
    if (strpos($shipHref, 'order_detail.php') === 0 && strpos($shipHref, '#') === false) {
        $shipHref .= '#order';
    }
    $deliverHref = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['delivered']);
    if (strpos($deliverHref, 'order_detail.php') === 0 && strpos($deliverHref, '#') === false) {
        $deliverHref .= '#order';
    }
    $paidHref = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['paid']);
    if (strpos($paidHref, 'order_detail.php') === 0 && strpos($paidHref, '#') === false) {
        $paidHref .= '#payment';
    }
    $pendingHref = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['pending', 'confirmed']);
    if (strpos($pendingHref, 'order_detail.php') === 0 && strpos($pendingHref, '#') === false) {
        $pendingHref .= '#order';
    }
    $cancelHref = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['cancelled']);
    if (strpos($cancelHref, 'order_detail.php') === 0 && strpos($cancelHref, '#') === false) {
        $cancelHref .= '#order';
    }
    if ((int)$life['pending'] > 0) {
        $alerts[] = [
            'type' => 'Pending',
            'message' => (int)$life['pending'] . ' order(s) cannot ship yet — payment is incomplete (partial pay, card issue, or insufficient funds). Complete the full order total so the seller can start shipping.',
            'action' => $pendingHref,
            'count' => (int)$life['pending'],
        ];
    }
    if ((int)$life['paid'] > 0) {
        $alerts[] = [
            'type' => 'Paid',
            'message' => (int)$life['paid'] . ' paid order(s) — payment confirmed. The seller is preparing shipment. You will see Shipping when it leaves.',
            'action' => $paidHref,
            'count' => (int)$life['paid'],
        ];
    }
    if ((int)$life['cancel'] > 0) {
        $alerts[] = [
            'type' => 'Cancel',
            'message' => (int)$life['cancel'] . ' order(s) the seller Cancelled (seller reason — card issue, stock, etc.). Check details and contact the seller if needed.',
            'action' => $cancelHref,
            'count' => (int)$life['cancel'],
        ];
    }
    if ((int)$life['cancellation'] > 0) {
        $alerts[] = [
            'type' => 'Cancellation',
            'message' => (int)$life['cancellation'] . ' Cancellation(s) you made — you cancelled your own order. Stock was restored when allowed.',
            'action' => $cancelHref,
            'count' => (int)$life['cancellation'],
        ];
    }
    if ((int)$life['shipping'] > 0) {
        $alerts[] = [
            'type' => 'Shipping',
            'message' => (int)$life['shipping'] . ' order(s) shipping (in transit). Tap to view tracking from Paid → Delivered.',
            'action' => $shipHref,
            'count' => (int)$life['shipping'],
        ];
    }
    if ((int)$life['delivery'] > 0) {
        $alerts[] = [
            'type' => 'Delivery',
            'message' => (int)$life['delivery'] . ' item(s) are now delivered. Tap to view delivery progress from start to finish.',
            'action' => $deliverHref,
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
        /** @var array<string, array<string, mixed>> $cancelFeedBuckets */
        $cancelFeedBuckets = [];
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
            $action = $brandUrl !== '' ? $brandUrl : 'Your_Shopping_preferences.php#order-history';
            if ($status === 'cancelled') {
                $by = (string)$meta['by'];
                $reason = (string)$meta['reason'];
                $isSeller = $by === 'Seller';
                $orderId = (int)($order['id'] ?? 0);
                $cancelAction = $orderId > 0
                    ? ('order_detail.php?order_id=' . $orderId
                        . ($code !== '' ? ('&code=' . rawurlencode($code)) : '')
                        . '#order')
                    : $action;
                // Order history cancel can close several same-seller lines in one action.
                $bucket = ((int)($order['org_id'] ?? 0))
                    . '|' . ($isSeller ? 'seller' : 'customer')
                    . '|' . mb_strtolower(trim($reason))
                    . '|' . (string)(int)floor(($sort ?: time()) / 120);
                if (!isset($cancelFeedBuckets[$bucket])) {
                    $cancelFeedBuckets[$bucket] = [
                        'type' => $isSeller ? 'Cancel' : 'Cancellation',
                        'title' => $isSeller
                            ? ('Seller Cancel · ' . $brandLabel)
                            : 'Your Cancellation',
                        'codes' => [],
                        'titles' => [],
                        'reason' => $reason,
                        'when' => $when,
                        'sort' => $sort,
                        'from' => $brandLabel,
                        'action' => $cancelAction,
                        'order_id' => $orderId,
                        'order_code' => $code,
                        'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                    ];
                }
                $bucketRow = &$cancelFeedBuckets[$bucket];
                if ($code !== '' && !in_array($code, $bucketRow['codes'], true)) {
                    $bucketRow['codes'][] = $code;
                }
                if ($title !== '' && !in_array($title, $bucketRow['titles'], true)) {
                    $bucketRow['titles'][] = $title;
                }
                if ($sort >= (int)$bucketRow['sort']) {
                    $bucketRow['sort'] = $sort;
                    $bucketRow['when'] = $when;
                    $bucketRow['action'] = $cancelAction;
                    $bucketRow['order_id'] = $orderId;
                    $bucketRow['order_code'] = $code;
                }
                unset($bucketRow);
            } elseif (in_array($status, ['pending', 'confirmed'], true)) {
                $orderId = (int)($order['id'] ?? 0);
                $pendingAction = $orderId > 0
                    ? ('order_detail.php?order_id=' . $orderId
                        . ($code !== '' ? ('&code=' . rawurlencode($code)) : '')
                        . '#order')
                    : $action;
                $payProgress = org_shop_order_payment_progress($order);
                $shortMsg = org_shop_order_incomplete_payment_buyer_message($order);
                $feed[] = [
                    'type' => 'Pending',
                    'title' => 'Pending — payment incomplete · ' . $brandLabel,
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title
                        . ' · ' . $shortMsg,
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $pendingAction,
                    'order_id' => $orderId,
                    'order_code' => $code,
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                    'amount_paid_cents' => (int)$payProgress['paid_cents'],
                    'shortfall_cents' => (int)$payProgress['shortfall_cents'],
                ];
            } elseif ($status === 'paid') {
                $orderId = (int)($order['id'] ?? 0);
                $paidAction = $orderId > 0
                    ? ('order_detail.php?order_id=' . $orderId
                        . ($code !== '' ? ('&code=' . rawurlencode($code)) : '')
                        . '#payment')
                    : $action;
                $feed[] = [
                    'type' => 'Paid',
                    'title' => 'Paid — seller preparing shipment · ' . $brandLabel,
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title,
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $paidAction,
                    'order_id' => $orderId,
                    'order_code' => $code,
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                ];
            } elseif ($status === 'shipped') {
                $track = trim((string)($order['tracking_number'] ?? ''));
                $carr = trim((string)($order['carrier'] ?? ''));
                $orderId = (int)($order['id'] ?? 0);
                $shipAction = $orderId > 0
                    ? ('order_detail.php?order_id=' . $orderId
                        . ($code !== '' ? ('&code=' . rawurlencode($code)) : '')
                        . '#order')
                    : $action;
                $feed[] = [
                    'type' => 'Shipping',
                    'title' => 'Shipping — in transit · ' . $brandLabel,
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title
                        . ($carr !== '' ? ' · ' . $carr : '')
                        . ($track !== '' ? ' · Tracking ' . $track : ''),
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $shipAction,
                    'order_id' => $orderId,
                    'order_code' => $code,
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                ];
            } else {
                $orderId = (int)($order['id'] ?? 0);
                $deliverAction = $orderId > 0
                    ? ('order_detail.php?order_id=' . $orderId
                        . ($code !== '' ? ('&code=' . rawurlencode($code)) : '')
                        . '#order')
                    : $action;
                $feed[] = [
                    'type' => 'Delivery',
                    'title' => 'Your item is now delivered · ' . $brandLabel,
                    'message' => ($code !== '' ? $code . ' · ' : '') . $title . ' · View delivery from Paid → Delivered',
                    'when' => $when,
                    'sort' => $sort,
                    'from' => $brandLabel,
                    'action' => $deliverAction,
                    'order_id' => $orderId,
                    'order_code' => $code,
                    'brand_slug' => trim((string)($order['commerce_brand_slug'] ?? '')),
                ];
            }
        }
        foreach ($cancelFeedBuckets as $bucketRow) {
            $codes = array_values(array_filter(array_map('strval', $bucketRow['codes'] ?? [])));
            $titles = array_values(array_filter(array_map('strval', $bucketRow['titles'] ?? [])));
            $reason = trim((string)($bucketRow['reason'] ?? 'Cancelled'));
            $codeBit = $codes !== [] ? implode(', ', array_slice($codes, 0, 3)) : '';
            if (count($codes) > 3) {
                $codeBit .= ' +' . (count($codes) - 3) . ' more';
            }
            $titleBit = $titles !== [] ? $titles[0] : 'Product';
            if (count($titles) > 1) {
                $titleBit .= ' +' . (count($titles) - 1) . ' more';
            }
            $feed[] = [
                'type' => (string)($bucketRow['type'] ?? 'Cancellation'),
                'title' => (string)($bucketRow['title'] ?? 'Your Cancellation'),
                'message' => ($codeBit !== '' ? $codeBit . ' · ' : '')
                    . $titleBit
                    . ' · Reason: ' . $reason,
                'when' => (string)($bucketRow['when'] ?? ''),
                'sort' => (int)($bucketRow['sort'] ?? 0),
                'from' => (string)($bucketRow['from'] ?? 'Seller'),
                'action' => (string)($bucketRow['action'] ?? 'Your_Shopping_preferences.php#order-history'),
                'order_id' => (int)($bucketRow['order_id'] ?? 0),
                'order_code' => (string)($bucketRow['order_code'] ?? ''),
                'brand_slug' => (string)($bucketRow['brand_slug'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // continue with returns / inbox rows
    }

    try {
        $stRet = $dbh->prepare("
            SELECT r.id, r.order_id, r.reason, r.status, r.created_at, r.updated_at,
                   o.order_code,
                   COALESCE(NULLIF(TRIM(o.product_title), ''), p.title, 'Product') AS product_title,
                   org.name AS seller_name,
                   cb.name AS commerce_brand_name
            FROM org_order_returns r
            INNER JOIN org_orders o ON o.id = r.order_id
            LEFT JOIN organizations org ON org.id = o.org_id
            LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id AND cb.is_active = 1
            LEFT JOIN org_products p ON p.id = o.product_id AND p.is_deleted = 0
            WHERE o.buyer_user_id = :uid
            ORDER BY COALESCE(r.updated_at, r.created_at) DESC, r.id DESC
            LIMIT 40
        ");
        $stRet->execute([':uid' => $buyerUserId]);
        foreach ($stRet->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ret) {
            $code = trim((string)($ret['order_code'] ?? ''));
            $title = trim((string)($ret['product_title'] ?? 'Product')) ?: 'Product';
            $reason = trim((string)($ret['reason'] ?? 'Return request')) ?: 'Return request';
            $status = strtolower(trim((string)($ret['status'] ?? 'requested')));
            $statusLabel = $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Requested';
            $brandLabel = trim((string)($ret['commerce_brand_name'] ?? ''));
            if ($brandLabel === '') {
                $brandLabel = trim((string)($ret['seller_name'] ?? '')) ?: 'Seller';
            }
            $whenRaw = (string)($ret['updated_at'] ?? $ret['created_at'] ?? '');
            if ($whenRaw === '') {
                $whenRaw = (string)($ret['created_at'] ?? '');
            }
            $sort = $whenRaw !== '' ? (int)strtotime($whenRaw) : 0;
            $when = $whenRaw !== '' ? date('M j, Y g:i A', $sort ?: time()) : '';
            $orderId = (int)($ret['order_id'] ?? 0);
            $feed[] = [
                'type' => 'Return / refund',
                'title' => 'Return / refund · ' . $brandLabel,
                'message' => ($code !== '' ? $code . ' · ' : '')
                    . $title
                    . ' · ' . $reason
                    . ' · Status: ' . $statusLabel,
                'when' => $when,
                'sort' => $sort > 0 ? $sort : time(),
                'from' => $brandLabel,
                'action' => 'Your_Shopping_preferences.php#returns-refunds',
                'order_id' => $orderId,
                'order_code' => $code,
            ];
        }
    } catch (Throwable $e) {
        try {
            $stRet = $dbh->prepare("
                SELECT r.id, r.order_id, r.reason, r.status, r.created_at,
                       o.order_code, o.product_title
                FROM org_order_returns r
                INNER JOIN org_orders o ON o.id = r.order_id
                WHERE o.buyer_user_id = :uid
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT 40
            ");
            $stRet->execute([':uid' => $buyerUserId]);
            foreach ($stRet->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ret) {
                $code = trim((string)($ret['order_code'] ?? ''));
                $title = trim((string)($ret['product_title'] ?? 'Product')) ?: 'Product';
                $reason = trim((string)($ret['reason'] ?? 'Return request')) ?: 'Return request';
                $status = strtolower(trim((string)($ret['status'] ?? 'requested')));
                $statusLabel = $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Requested';
                $whenRaw = (string)($ret['created_at'] ?? '');
                $sort = $whenRaw !== '' ? (int)strtotime($whenRaw) : 0;
                $orderId = (int)($ret['order_id'] ?? 0);
                $feed[] = [
                    'type' => 'Return / refund',
                    'title' => 'Return / refund',
                    'message' => ($code !== '' ? $code . ' · ' : '')
                        . $title
                        . ' · ' . $reason
                        . ' · Status: ' . $statusLabel,
                    'when' => $whenRaw !== '' ? date('M j, Y g:i A', $sort ?: time()) : '',
                    'sort' => $sort > 0 ? $sort : time(),
                    'from' => 'Seller',
                    'action' => 'Your_Shopping_preferences.php#returns-refunds',
                    'order_id' => $orderId,
                    'order_code' => $code,
                ];
            }
        } catch (Throwable $e2) {
            // returns table may not exist
        }
    }

    try {
        $username = org_shop_user_username($dbh, $buyerUserId);
        if ($username !== '') {
            require_once __DIR__ . '/app_notification_api.php';
            $st = $dbh->prepare('
                SELECT notiuser, notitype, created_at, is_read
                FROM notification
                WHERE notireceiver = ?
                ' . app_notification_shop_only_sql() . '
                ORDER BY id DESC
                LIMIT 40
            ');
            $st->execute(array_merge([$username], app_notification_shop_like_patterns()));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $text = trim((string)($row['notitype'] ?? ''));
                $text = (string)preg_replace('/\s\[(?:live|r|p|c):[^\]]+\]\s*$/', '', $text);
                $lower = strtolower($text);
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
                } elseif (strpos($lower, 'delivered') !== false || strpos($lower, 'now delivered') !== false) {
                    $type = 'Delivery';
                } elseif (strpos($lower, 'ship') !== false) {
                    $type = 'Shipping';
                } elseif (strpos($lower, 'deliver') !== false) {
                    $type = 'Delivery';
                } elseif (
                    strpos($lower, 'payment incomplete') !== false
                    || strpos($lower, 'complete payment') !== false
                    || strpos($lower, 'still due') !== false
                    || (strpos($lower, 'pending') !== false && strpos($lower, 'payment') !== false)
                ) {
                    $type = 'Pending';
                } elseif (strpos($lower, 'paid') !== false || (strpos($lower, 'payment') !== false && strpos($lower, 'incomplete') === false)) {
                    $type = 'Paid';
                } elseif (strpos($lower, 'pending') !== false || strpos($lower, 'new order') !== false) {
                    $type = 'Pending';
                }
                $titleMap = [
                    'Cancel' => 'Seller Cancel',
                    'Cancellation' => 'Your Cancellation',
                    'Shipping' => 'Shipping update',
                    'Delivery' => 'Your item is now delivered',
                    'Paid' => 'Payment update',
                    'Pending' => 'Pending — payment incomplete',
                    'Return / refund' => 'Return / refund',
                ];
                $inboxAction = 'Your_Shopping_preferences.php#order-history';
                if (preg_match('/\b(ORD-[A-Z0-9-]+)\b/i', $text, $mCode)) {
                    $found = org_shop_find_buyer_order_by_code($dbh, $buyerUserId, (string)$mCode[1]);
                    if ($found) {
                        $fid = (int)($found['id'] ?? 0);
                        $fcode = trim((string)($found['order_code'] ?? $mCode[1]));
                        if ($fid > 0) {
                            $hash = '';
                            if ($type === 'Paid') {
                                $hash = '#payment';
                            } elseif (
                                $type === 'Shipping'
                                || $type === 'Delivery'
                                || $type === 'Pending'
                                || $type === 'Cancel'
                                || $type === 'Cancellation'
                            ) {
                                $hash = '#order';
                            }
                            $inboxAction = 'order_detail.php?order_id=' . $fid
                                . ($fcode !== '' ? ('&code=' . rawurlencode($fcode)) : '')
                                . $hash;
                        }
                    }
                } elseif ($type === 'Paid') {
                    $inboxAction = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['paid']);
                    if (strpos($inboxAction, 'order_detail.php') === 0 && strpos($inboxAction, '#') === false) {
                        $inboxAction .= '#payment';
                    }
                } elseif ($type === 'Pending') {
                    $inboxAction = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['pending', 'confirmed']);
                    if (strpos($inboxAction, 'order_detail.php') === 0 && strpos($inboxAction, '#') === false) {
                        $inboxAction .= '#order';
                    }
                } elseif ($type === 'Cancel' || $type === 'Cancellation') {
                    $inboxAction = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['cancelled']);
                    if (strpos($inboxAction, 'order_detail.php') === 0 && strpos($inboxAction, '#') === false) {
                        $inboxAction .= '#order';
                    }
                } elseif ($type === 'Delivery') {
                    $inboxAction = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['delivered']);
                    if (strpos($inboxAction, 'order_detail.php') === 0 && strpos($inboxAction, '#') === false) {
                        $inboxAction .= '#order';
                    }
                } elseif ($type === 'Shipping') {
                    $inboxAction = org_shop_buyer_latest_order_detail_href($dbh, $buyerUserId, ['shipped']);
                    if (strpos($inboxAction, 'order_detail.php') === 0 && strpos($inboxAction, '#') === false) {
                        $inboxAction .= '#order';
                    }
                }
                $feed[] = [
                    'type' => $type,
                    'title' => $titleMap[$type] ?? $type,
                    'message' => $text,
                    'when' => $whenRaw !== '' ? date('M j, Y g:i A', $sort ?: time()) : '',
                    'sort' => $sort,
                    'from' => trim((string)($row['notiuser'] ?? '')) ?: 'Seller',
                    'action' => $inboxAction,
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
