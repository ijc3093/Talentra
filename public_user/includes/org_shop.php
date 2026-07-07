<?php
declare(strict_types=1);

require_once __DIR__ . '/platform_rent.php';

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
    $sql = '
        SELECT * FROM org_products
        WHERE org_id = :org AND is_deleted = 0
    ';
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= ' ORDER BY sort_order ASC, id DESC';
    try {
        $st = $dbh->prepare($sql);
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function org_shop_products_for_publisher(PDO $dbh, int $publisherUserId, bool $activeOnly = true): array
{
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
    ?string $buyerEmail = null
): array {
    $product = org_shop_get_product($dbh, $productId);
    if (!$product || (string)($product['status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'Product is not available.'];
    }

    $orgId = (int)($product['org_id'] ?? 0);
    if ($orgId <= 0 || !platform_rent_shop_is_visible($dbh, $orgId)) {
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
    }

    $orderCode = org_shop_gen_order_code($orgId);

    try {
        $st = $dbh->prepare('
            INSERT INTO org_orders (
                org_id, order_code, buyer_user_id, buyer_name, buyer_phone, buyer_email,
                product_id, product_title, unit_price_cents, currency, quantity, total_cents,
                status, order_type, buyer_notes, delivery_address, created_at, updated_at
            ) VALUES (
                :org, :code, :uid, :name, :phone, :email,
                :pid, :title, :unit, :cur, :qty, :total,
                \'pending\', \'purchase\', :notes, :addr, NOW(), NOW()
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
            ':notes' => $buyerNotes !== '' ? $buyerNotes : null,
            ':addr' => $deliveryAddress !== '' ? $deliveryAddress : null,
        ]);
        $orderId = (int)$dbh->lastInsertId();

        if ($stock !== null && $stock !== '' && (int)$stock > 0) {
            $dbh->prepare('UPDATE org_products SET stock_qty = GREATEST(0, stock_qty - :q), updated_at = NOW() WHERE id = :id LIMIT 1')
                ->execute([':q' => $quantity, ':id' => $productId]);
        }

        return [
            'ok' => true,
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'total_cents' => $totalCents,
            'currency' => $currency,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not place order.'];
    }
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
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function org_shop_issue_receipt(PDO $dbh, int $orgId, int $orderId): int
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
            return $existing;
        }

        $sellerName = '';
        $stOrg = $dbh->prepare('SELECT name FROM organizations WHERE id = :id LIMIT 1');
        $stOrg->execute([':id' => $orgId]);
        $sellerName = trim((string)($stOrg->fetchColumn() ?: ''));

        $receiptCode = org_shop_gen_receipt_code($orgId);
        $stIns = $dbh->prepare('
            INSERT INTO org_order_receipts (
                org_id, order_id, receipt_code, buyer_user_id, buyer_name, buyer_email, buyer_phone,
                seller_name, product_title, quantity, unit_price_cents, tax_cents, total_cents, currency,
                status, issued_at, created_at
            ) VALUES (
                :org, :oid, :code, :uid, :name, :email, :phone,
                :seller, :title, :qty, :unit, 0, :total, :cur,
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
            ':total' => (int)($order['total_cents'] ?? 0),
            ':cur' => $order['currency'] ?? 'USD',
        ]);
        return (int)$dbh->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function org_shop_save_product(PDO $dbh, int $orgId, array $data, ?int $productId = null, int $memberId = 0): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid organization.'];
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

    $status = strtolower(trim((string)($data['status'] ?? 'draft')));
    if (!in_array($status, ['draft', 'active', 'sold_out', 'archived'], true)) {
        $status = 'draft';
    }

    $stockRaw = trim((string)($data['stock_qty'] ?? ''));
    $stockQty = $stockRaw === '' ? null : max(0, (int)$stockRaw);
    $description = trim((string)($data['description'] ?? ''));
    $category = trim((string)($data['category'] ?? ''));
    if (mb_strlen($category) > 80) {
        $category = mb_substr($category, 0, 80);
    }
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

    if ($productId === null || $productId <= 0) {
        $max = org_shop_max_products($dbh, $orgId);
        if (org_shop_product_count($dbh, $orgId) >= $max) {
            return ['ok' => false, 'error' => 'Product limit reached for your rent plan (' . $max . ').'];
        }
    }

    try {
        if ($productId > 0) {
            $st = $dbh->prepare('
                UPDATE org_products
                SET title = :title, sku = :sku, description = :desc, seo_title = :seo_t, seo_description = :seo_d,
                    slug = :slug, offer_type = :otype, pricing_model = :pmodel,
                    price_cents = :price, stock_qty = :stock,
                    category = :cat, status = :status, updated_at = NOW()
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
                ':otype' => $offerType,
                ':pmodel' => $pricingModel,
                ':price' => $priceCents,
                ':stock' => $stockQty,
                ':cat' => $category !== '' ? $category : null,
                ':status' => $status,
                ':id' => $productId,
                ':org' => $orgId,
            ]);
            return ['ok' => true, 'product_id' => $productId];
        }

        $st = $dbh->prepare('
            INSERT INTO org_products (
                org_id, sku, title, description, seo_title, seo_description, slug,
                offer_type, pricing_model,
                price_cents, currency, stock_qty, category, status,
                created_by_member_id, created_at, updated_at, is_deleted
            ) VALUES (
                :org, :sku, :title, :desc, :seo_t, :seo_d, :slug,
                :otype, :pmodel,
                :price, \'USD\', :stock, :cat, :status,
                :member, NOW(), NOW(), 0
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':sku' => $sku !== '' ? $sku : null,
            ':title' => $title,
            ':desc' => $description !== '' ? $description : null,
            ':seo_t' => $seoTitle !== '' ? $seoTitle : null,
            ':seo_d' => $seoDesc !== '' ? $seoDesc : null,
            ':slug' => $slug,
            ':otype' => $offerType,
            ':pmodel' => $pricingModel,
            ':price' => $priceCents,
            ':stock' => $stockQty,
            ':cat' => $category !== '' ? $category : null,
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
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$fi->file($tmp);
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
    if (!move_uploaded_file($tmp, $dest)) {
        return null;
    }
    return 'uploads/shop/' . $fname;
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
                   u.username AS publisher_username,
                   u.name AS publisher_name
            FROM org_products p
            INNER JOIN organizations o ON o.id = p.org_id AND o.status = 1
            INNER JOIN users u ON u.id = o.publisher_user_id
            WHERE p.is_deleted = 0
              AND p.status = 'active'
              AND o.publisher_user_id IS NOT NULL
              AND o.publisher_user_id > 0
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT {$limit}
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $orgId = (int)($row['org_id'] ?? 0);
        if ($orgId <= 0 || !platform_rent_shop_is_visible($dbh, $orgId)) {
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
                   u.username AS publisher_username,
                   u.name AS publisher_name
            FROM org_products p
            INNER JOIN organizations o ON o.id = p.org_id AND o.status = 1
            INNER JOIN users u ON u.id = o.publisher_user_id
            WHERE p.id = :id
              AND p.is_deleted = 0
              AND p.status = 'active'
              AND o.publisher_user_id IS NOT NULL
              AND o.publisher_user_id > 0
            LIMIT 1
        ");
        $st->execute([':id' => $productId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $orgId = (int)($row['org_id'] ?? 0);
        if ($orgId <= 0 || !platform_rent_shop_is_visible($dbh, $orgId)) {
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
                   r.receipt_code,
                   r.id AS receipt_id
            FROM org_orders o
            LEFT JOIN organizations org ON org.id = o.org_id
            LEFT JOIN org_order_receipts r ON r.order_id = o.id
            WHERE o.buyer_user_id = :uid
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT {$limit}
        ");
        $st->execute([':uid' => $buyerUserId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
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
    try {
        $st = $dbh->prepare('SELECT org_id, status FROM org_orders WHERE id = :id LIMIT 1');
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
        org_shop_issue_receipt($dbh, $orgId, $orderId);
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
