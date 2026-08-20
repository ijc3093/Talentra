<?php
declare(strict_types=1);

/**
 * Buyer wishlist — separate from cart.
 */

require_once __DIR__ . '/org_shop.php';

function org_wishlist_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS org_wishlist_items (
              id BIGINT(20) NOT NULL AUTO_INCREMENT,
              user_id INT(11) NOT NULL,
              product_id INT(11) NOT NULL,
              org_id INT(11) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_wishlist_user_product (user_id, product_id),
              KEY idx_wishlist_user (user_id, created_at),
              KEY idx_wishlist_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // non-fatal
    }
}

function org_wishlist_has(PDO $dbh, int $userId, int $productId): bool
{
    if ($userId <= 0 || $productId <= 0) {
        return false;
    }
    org_wishlist_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT 1 FROM org_wishlist_items WHERE user_id = :u AND product_id = :p LIMIT 1');
        $st->execute([':u' => $userId, ':p' => $productId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{ok:bool,saved?:bool,message?:string,error?:string}
 */
function org_wishlist_add(PDO $dbh, int $userId, int $productId): array
{
    if ($userId <= 0 || $productId <= 0) {
        return ['ok' => false, 'error' => 'bad_ids'];
    }
    org_wishlist_ensure_schema($dbh);
    $product = org_shop_get_marketplace_product($dbh, $productId);
    if (!$product) {
        return ['ok' => false, 'error' => 'Product not found.'];
    }
    $orgId = (int)($product['org_id'] ?? 0);
    try {
        $dbh->prepare('
            INSERT INTO org_wishlist_items (user_id, product_id, org_id, created_at)
            VALUES (:u, :p, :o, NOW())
            ON DUPLICATE KEY UPDATE org_id = VALUES(org_id)
        ')->execute([':u' => $userId, ':p' => $productId, ':o' => $orgId]);
        return ['ok' => true, 'saved' => true, 'message' => 'Saved to wishlist.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save wishlist.'];
    }
}

/**
 * @return array{ok:bool,saved?:bool,message?:string,error?:string}
 */
function org_wishlist_remove(PDO $dbh, int $userId, int $productId): array
{
    if ($userId <= 0 || $productId <= 0) {
        return ['ok' => false, 'error' => 'bad_ids'];
    }
    org_wishlist_ensure_schema($dbh);
    try {
        $dbh->prepare('DELETE FROM org_wishlist_items WHERE user_id = :u AND product_id = :p LIMIT 1')
            ->execute([':u' => $userId, ':p' => $productId]);
        return ['ok' => true, 'saved' => false, 'message' => 'Removed from wishlist.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update wishlist.'];
    }
}

/**
 * @return array{ok:bool,saved:bool,message?:string,error?:string}
 */
function org_wishlist_toggle(PDO $dbh, int $userId, int $productId): array
{
    if (org_wishlist_has($dbh, $userId, $productId)) {
        $res = org_wishlist_remove($dbh, $userId, $productId);
        return [
            'ok' => !empty($res['ok']),
            'saved' => false,
            'message' => (string)($res['message'] ?? 'Removed from wishlist.'),
            'error' => (string)($res['error'] ?? ''),
        ];
    }
    $res = org_wishlist_add($dbh, $userId, $productId);
    return [
        'ok' => !empty($res['ok']),
        'saved' => !empty($res['ok']),
        'message' => (string)($res['message'] ?? 'Saved to wishlist.'),
        'error' => (string)($res['error'] ?? ''),
    ];
}

/** @return list<array<string, mixed>> */
function org_wishlist_list(PDO $dbh, int $userId, int $limit = 100): array
{
    if ($userId <= 0) {
        return [];
    }
    org_wishlist_ensure_schema($dbh);
    $limit = max(1, min(200, $limit));
    try {
        $st = $dbh->prepare("
            SELECT w.id AS wishlist_id, w.created_at AS wishlisted_at,
                   p.id AS product_id, p.title, p.price_cents, p.currency, p.stock_qty,
                   p.cover_image_path, p.status AS product_status,
                   o.id AS org_id, o.name AS seller_name, o.publisher_user_id,
                   u.username AS publisher_username, u.name AS publisher_name
            FROM org_wishlist_items w
            INNER JOIN org_products p ON p.id = w.product_id AND COALESCE(p.is_deleted,0) = 0
            INNER JOIN organizations o ON o.id = p.org_id AND o.status = 1
            LEFT JOIN users u ON u.id = o.publisher_user_id
            WHERE w.user_id = :uid
            ORDER BY w.created_at DESC, w.id DESC
            LIMIT {$limit}
        ");
        $st->execute([':uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_wishlist_count(PDO $dbh, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    org_wishlist_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT COUNT(*) FROM org_wishlist_items WHERE user_id = :uid');
        $st->execute([':uid' => $userId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}
