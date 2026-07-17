<?php
declare(strict_types=1);

require_once __DIR__ . '/org_shop.php';

function org_cart_ensure_table(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_cart_items (
              id bigint(20) NOT NULL AUTO_INCREMENT,
              user_id bigint(20) NOT NULL,
              org_id bigint(20) NOT NULL,
              product_id bigint(20) NOT NULL,
              quantity int(11) NOT NULL DEFAULT 1,
              created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uniq_cart_user_product (user_id, product_id),
              KEY idx_cart_user (user_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // migration may be required on older DBs
    }
}

function org_cart_count(PDO $dbh, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    org_cart_ensure_table($dbh);
    try {
        $st = $dbh->prepare('SELECT COALESCE(SUM(quantity), 0) FROM org_cart_items WHERE user_id = :uid');
        $st->execute([':uid' => $userId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return list<array<string, mixed>> */
function org_cart_list_items(PDO $dbh, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    org_cart_ensure_table($dbh);
    try {
        $st = $dbh->prepare("
            SELECT c.*,
                   p.title, p.description, p.price_cents, p.currency, p.stock_qty,
                   p.cover_image_path, p.category, p.status AS product_status,
                   o.name AS seller_name,
                   o.publisher_user_id,
                   u.username AS publisher_username,
                   u.name AS publisher_name
            FROM org_cart_items c
            INNER JOIN org_products p ON p.id = c.product_id AND p.is_deleted = 0
            INNER JOIN organizations o ON o.id = c.org_id AND o.status = 1
            LEFT JOIN users u ON u.id = o.publisher_user_id
            WHERE c.user_id = :uid
            ORDER BY c.updated_at DESC, c.id DESC
        ");
        $st->execute([':uid' => $userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $orgId = (int)($row['org_id'] ?? 0);
        if ($orgId <= 0 || !org_is_commerce_seller($dbh, $orgId) || !platform_rent_shop_is_visible($dbh, $orgId)) {
            continue;
        }
        if ((string)($row['product_status'] ?? '') !== 'active') {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function org_cart_add_item(PDO $dbh, int $userId, int $productId, int $quantity = 1): array
{
    if ($userId <= 0 || $productId <= 0) {
        return ['ok' => false, 'error' => 'Invalid cart request.'];
    }
    org_cart_ensure_table($dbh);

    $product = org_shop_get_marketplace_product($dbh, $productId);
    if (!$product) {
        return ['ok' => false, 'error' => 'Product is not available.'];
    }

    $quantity = max(1, min(99, $quantity));
    $stock = $product['stock_qty'] ?? null;
    if ($stock !== null && $stock !== '' && (int)$stock >= 0) {
        $quantity = min($quantity, (int)$stock);
        if ($quantity <= 0) {
            return ['ok' => false, 'error' => 'Out of stock.'];
        }
    }

    $orgId = (int)($product['org_id'] ?? 0);
    try {
        $st = $dbh->prepare('
            INSERT INTO org_cart_items (user_id, org_id, product_id, quantity, created_at, updated_at)
            VALUES (:uid, :org, :pid, :qty, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                quantity = LEAST(99, quantity + VALUES(quantity)),
                updated_at = NOW()
        ');
        $st->execute([
            ':uid' => $userId,
            ':org' => $orgId,
            ':pid' => $productId,
            ':qty' => $quantity,
        ]);
        return ['ok' => true, 'count' => org_cart_count($dbh, $userId)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not add to cart.'];
    }
}

function org_cart_update_quantity(PDO $dbh, int $userId, int $productId, int $quantity): array
{
    if ($userId <= 0 || $productId <= 0) {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }
    org_cart_ensure_table($dbh);

    if ($quantity <= 0) {
        return org_cart_remove_item($dbh, $userId, $productId);
    }

    $quantity = max(1, min(99, $quantity));
    $product = org_shop_get_marketplace_product($dbh, $productId);
    if (!$product) {
        org_cart_remove_item($dbh, $userId, $productId);
        return ['ok' => false, 'error' => 'Product is no longer available.'];
    }

    $stock = $product['stock_qty'] ?? null;
    if ($stock !== null && $stock !== '' && (int)$stock >= 0) {
        $quantity = min($quantity, (int)$stock);
    }

    try {
        $st = $dbh->prepare('
            UPDATE org_cart_items
            SET quantity = :qty, updated_at = NOW()
            WHERE user_id = :uid AND product_id = :pid
            LIMIT 1
        ');
        $st->execute([':qty' => $quantity, ':uid' => $userId, ':pid' => $productId]);
        if ($st->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'Item not in cart.'];
        }
        return ['ok' => true, 'count' => org_cart_count($dbh, $userId)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update cart.'];
    }
}

function org_cart_remove_item(PDO $dbh, int $userId, int $productId): array
{
    if ($userId <= 0 || $productId <= 0) {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }
    org_cart_ensure_table($dbh);
    try {
        $st = $dbh->prepare('DELETE FROM org_cart_items WHERE user_id = :uid AND product_id = :pid LIMIT 1');
        $st->execute([':uid' => $userId, ':pid' => $productId]);
        return ['ok' => true, 'count' => org_cart_count($dbh, $userId)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove item.'];
    }
}

function org_cart_clear(PDO $dbh, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    org_cart_ensure_table($dbh);
    try {
        $dbh->prepare('DELETE FROM org_cart_items WHERE user_id = :uid')->execute([':uid' => $userId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function org_cart_subtotal_cents(array $items): int
{
    $total = 0;
    foreach ($items as $item) {
        $total += (int)($item['price_cents'] ?? 0) * (int)($item['quantity'] ?? 1);
    }
    return $total;
}

function org_cart_checkout(
    PDO $dbh,
    int $userId,
    string $deliveryAddress = '',
    string $buyerNotes = '',
    string $buyerPhone = '',
    ?array $productIds = null,
    string $promoCode = '',
    string $deliveryOption = 'home_delivery'
): array {
    $items = org_cart_list_items($dbh, $userId);
    if (!$items) {
        return ['ok' => false, 'error' => 'Your cart is empty.'];
    }

    if ($productIds !== null) {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        if (!$productIds) {
            return ['ok' => false, 'error' => 'Select at least one item to checkout.'];
        }
        $allowed = array_flip($productIds);
        $items = array_values(array_filter(
            $items,
            static fn(array $item): bool => isset($allowed[(int)($item['product_id'] ?? 0)])
        ));
        if (!$items) {
            return ['ok' => false, 'error' => 'Selected items are no longer in your cart.'];
        }
    }

    if ($deliveryAddress === '') {
        $deliveryAddress = buyer_shipping_default_text($dbh, $userId);
    }
    if ($buyerPhone === '') {
        $buyerPhone = buyer_shipping_default_phone($dbh, $userId);
    }

    $orders = [];
    $errors = [];
    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 1);
        // Promo codes are per-seller; apply only when cart line org matches.
        $linePromo = $promoCode;
        $result = org_shop_create_order(
            $dbh,
            $productId,
            $userId,
            $qty,
            $buyerNotes,
            $deliveryAddress,
            null,
            $buyerPhone,
            null,
            $deliveryOption,
            null,
            $linePromo
        );
        if (!empty($result['ok'])) {
            org_cart_remove_item($dbh, $userId, $productId);
            $orders[] = $result;
        } else {
            $errors[] = ((string)($item['title'] ?? 'Item')) . ': ' . ((string)($result['error'] ?? 'failed'));
        }
    }

    if (!$orders) {
        return ['ok' => false, 'error' => $errors ? implode(' ', $errors) : 'Checkout failed.'];
    }

    return [
        'ok' => true,
        'orders' => $orders,
        'errors' => $errors,
        'count' => org_cart_count($dbh, $userId),
    ];
}
