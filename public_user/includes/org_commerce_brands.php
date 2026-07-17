<?php
declare(strict_types=1);

require_once __DIR__ . '/org_shop.php';
require_once __DIR__ . '/publisher_accounts.php';

function org_commerce_brands_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once __DIR__ . '/msb_migrations.php';
    $migration = dirname(__DIR__, 2) . '/Data/migrations/20260709_commerce_brands.sql';
    msb_run_sql_migration_file($dbh, $migration);
}

/** @return list<array<string, mixed>> */
function org_commerce_brands_list_active(PDO $dbh): array
{
    org_commerce_brands_ensure_schema($dbh);
    try {
        $st = $dbh->query('
            SELECT id, slug, name, tagline, accent_color, icon_letter, system_json, sort_order
            FROM commerce_brands
            WHERE is_active = 1
            ORDER BY sort_order ASC, name ASC
        ');
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string, mixed>|null */
function org_commerce_brands_get(PDO $dbh, int $brandId): ?array
{
    if ($brandId <= 0) {
        return null;
    }
    org_commerce_brands_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT id, slug, name, tagline, accent_color, icon_letter, system_json, sort_order, is_active
            FROM commerce_brands
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $brandId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array<string, mixed>|null */
function org_commerce_brands_get_by_slug(PDO $dbh, string $slug): ?array
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return null;
    }
    org_commerce_brands_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT id, slug, name, tagline, accent_color, icon_letter, system_json, sort_order, is_active
            FROM commerce_brands
            WHERE slug = :slug AND is_active = 1
            LIMIT 1
        ');
        $st->execute([':slug' => $slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Match a publisher/display name to a commerce brand (e.g. McDonald's). */
function org_commerce_brands_get_by_name(PDO $dbh, string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    org_commerce_brands_ensure_schema($dbh);
    foreach (org_commerce_brands_list_active($dbh) as $brand) {
        $brandName = trim((string)($brand['name'] ?? ''));
        if ($brandName !== '' && strcasecmp($brandName, $name) === 0) {
            return $brand;
        }
    }
    return null;
}

/** Resolve brand id from explicit selection or publisher name match. */
function org_commerce_brands_resolve_for_registration(PDO $dbh, int $brandId, string $publisherName): int
{
    if ($brandId > 0 && org_commerce_brands_get($dbh, $brandId)) {
        return $brandId;
    }
    $matched = org_commerce_brands_get_by_name($dbh, $publisherName);
    return $matched ? (int)($matched['id'] ?? 0) : 0;
}

function org_commerce_brands_org_id(PDO $dbh, int $orgId): int
{
    if ($orgId <= 0) {
        return 0;
    }
    org_commerce_brands_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT commerce_brand_id FROM organizations WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orgId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array<string, mixed>|null */
function org_commerce_brands_get_for_org(PDO $dbh, int $orgId): ?array
{
    $brandId = org_commerce_brands_org_id($dbh, $orgId);
    return $brandId > 0 ? org_commerce_brands_get($dbh, $brandId) : null;
}

function org_commerce_brands_assign_org(PDO $dbh, int $orgId, int $brandId): bool
{
    if ($orgId <= 0 || $brandId <= 0) {
        return false;
    }
    $brand = org_commerce_brands_get($dbh, $brandId);
    if (!$brand || empty($brand['is_active'])) {
        return false;
    }
    // Media publishers (CNN, news, sports, …) cannot join commerce brand systems.
    try {
        $stCat = $dbh->prepare('SELECT publisher_category FROM organizations WHERE id = :id LIMIT 1');
        $stCat->execute([':id' => $orgId]);
        $cat = strtolower(trim((string)($stCat->fetchColumn() ?: '')));
        if ($cat !== '' && $cat !== 'commerce') {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            UPDATE organizations
            SET commerce_brand_id = :bid,
                publisher_category = \'commerce\',
                updated_at = NOW()
            WHERE id = :oid
            LIMIT 1
        ');
        $st->execute([':bid' => $brandId, ':oid' => $orgId]);
        if ($st->rowCount() <= 0) {
            return false;
        }
        $stPub = $dbh->prepare('SELECT publisher_user_id FROM organizations WHERE id = :id LIMIT 1');
        $stPub->execute([':id' => $orgId]);
        $pubUserId = (int)($stPub->fetchColumn() ?: 0);
        if ($pubUserId > 0 && function_exists('publisher_db_column_exists') && publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
            $stUser = $dbh->prepare('UPDATE users SET publisher_category = \'commerce\' WHERE id = :id LIMIT 1');
            $stUser->execute([':id' => $pubUserId]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string, mixed> */
function org_commerce_brands_parse_system(?array $brand): array
{
    if (!$brand) {
        return [];
    }
    $raw = $brand['system_json'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    if (is_array($raw)) {
        return $raw;
    }
    return [];
}

/** @return array<string, int> slug => seller org count */
function org_commerce_brands_seller_counts(PDO $dbh): array
{
    org_commerce_brands_ensure_schema($dbh);
    if (!publisher_db_column_exists($dbh, 'organizations', 'commerce_brand_id')) {
        return [];
    }
    try {
        $st = $dbh->query('
            SELECT cb.slug, COUNT(DISTINCT o.id) AS seller_count
            FROM organizations o
            INNER JOIN commerce_brands cb ON cb.id = o.commerce_brand_id AND cb.is_active = 1
            WHERE o.status = 1
              AND o.commerce_brand_id IS NOT NULL
              AND o.commerce_brand_id > 0
              AND (
                  o.is_publisher_org = 1
                  OR (o.publisher_user_id IS NOT NULL AND o.publisher_user_id > 0)
              )
            GROUP BY cb.slug
        ');
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $slug = trim((string)($row['slug'] ?? ''));
        if ($slug !== '') {
            $out[$slug] = (int)($row['seller_count'] ?? 0);
        }
    }
    return $out;
}

/** Brands visible in shop nav (registered sellers and/or marketplace products). @return list<array<string, mixed>> */
function org_commerce_brands_list_for_shop(PDO $dbh): array
{
    org_commerce_brands_ensure_schema($dbh);
    $brands = org_commerce_brands_list_active($dbh);
    if (!$brands) {
        return [];
    }

    $productCounts = [];
    try {
        $products = org_shop_list_marketplace_products($dbh, 200);
        foreach ($products as $p) {
            $slug = trim((string)($p['commerce_brand_slug'] ?? ''));
            if ($slug !== '') {
                $productCounts[$slug] = ($productCounts[$slug] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) {
        $productCounts = [];
    }

    $sellerCounts = org_commerce_brands_seller_counts($dbh);

    $out = [];
    foreach ($brands as $brand) {
        $slug = (string)($brand['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $productCount = (int)($productCounts[$slug] ?? 0);
        $sellerCount = (int)($sellerCounts[$slug] ?? 0);
        if ($productCount <= 0 && $sellerCount <= 0) {
            continue;
        }
        $brand['product_count'] = $productCount;
        $brand['seller_count'] = $sellerCount;
        $out[] = $brand;
    }
    return $out;
}

function org_commerce_brands_shop_url(string $slug): string
{
    return 'shop.php?' . http_build_query(['cbrand' => $slug]);
}

function org_commerce_brands_slug_from_name(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'brand';
    }
    return mb_substr($slug, 0, 60);
}

function org_commerce_brands_unique_slug(PDO $dbh, string $baseSlug): string
{
    $slug = $baseSlug;
    $suffix = 2;
    while (org_commerce_brands_get_by_slug($dbh, $slug)) {
        $slug = mb_substr($baseSlug, 0, 56) . '-' . $suffix;
        $suffix++;
    }
    return $slug;
}

/** Create an active commerce brand from an approved name request. */
function org_commerce_brands_create(PDO $dbh, string $name, string $tagline = ''): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }

    org_commerce_brands_ensure_schema($dbh);

    $existing = org_commerce_brands_get_by_name($dbh, $name);
    if ($existing) {
        return (int)($existing['id'] ?? 0);
    }

    $slug = org_commerce_brands_unique_slug($dbh, org_commerce_brands_slug_from_name($name));
    $tagline = trim($tagline);
    if ($tagline === '') {
        $tagline = 'Brand stores and seller accounts on Talentra Shop.';
    }

    $iconLetter = mb_strtoupper(mb_substr($name, 0, 1));
    if ($iconLetter === '') {
        $iconLetter = 'C';
    }

    $system = [
        'model' => 'quick_service',
        'default_fulfillment' => 'fbm',
        'pickup_enabled' => true,
        'delivery_enabled' => true,
        'menu_categories' => ['Menu', 'Drinks', 'Sides', 'Specials'],
        'seller_label' => $name . ' seller',
        'order_hint' => 'Orders are prepared for pickup or delivery under the ' . $name . ' brand system.',
    ];

    try {
        $st = $dbh->prepare('
            INSERT INTO commerce_brands (slug, name, tagline, accent_color, icon_letter, system_json, sort_order, is_active, created_at, updated_at)
            VALUES (:slug, :name, :tagline, :accent_color, :icon_letter, :system_json, :sort_order, 1, NOW(), NOW())
        ');
        $st->execute([
            ':slug' => $slug,
            ':name' => $name,
            ':tagline' => mb_substr($tagline, 0, 255),
            ':accent_color' => '#2563eb',
            ':icon_letter' => mb_substr($iconLetter, 0, 1),
            ':system_json' => json_encode($system, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':sort_order' => 900,
        ]);
        return (int)$dbh->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Names to try when auto-matching an org to a commerce brand. */
function org_commerce_brands_name_candidates_from_org(array $orgRow): array
{
    $names = [
        trim((string)($orgRow['name'] ?? '')),
        trim((string)($orgRow['registered_publisher_name'] ?? '')),
        trim((string)($orgRow['pub_username'] ?? '')),
        trim((string)($orgRow['publisher_name'] ?? '')),
        trim((string)($orgRow['pub_name'] ?? '')),
    ];
    $out = [];
    foreach ($names as $name) {
        if ($name !== '' && !in_array($name, $out, true)) {
            $out[] = $name;
        }
    }
    return $out;
}

/** Suggest a commerce brand for an organization row (by name match). */
function org_commerce_brands_suggest_for_org(PDO $dbh, array $orgRow): ?array
{
    foreach (org_commerce_brands_name_candidates_from_org($orgRow) as $name) {
        $brand = org_commerce_brands_get_by_name($dbh, $name);
        if ($brand) {
            return $brand;
        }
    }
    return null;
}

/** @return list<array<string, mixed>> */
function org_commerce_brands_list_orgs_for_migration(PDO $dbh, string $filter = 'unassigned', string $search = ''): array
{
    org_commerce_brands_ensure_schema($dbh);
    if (!publisher_db_column_exists($dbh, 'organizations', 'commerce_brand_id')) {
        return [];
    }

    $where = ['1=1'];
    $params = [];

    if ($filter === 'unassigned') {
        $where[] = '(o.commerce_brand_id IS NULL OR o.commerce_brand_id = 0)';
    } elseif ($filter === 'assigned') {
        $where[] = 'o.commerce_brand_id IS NOT NULL AND o.commerce_brand_id > 0';
    } elseif ($filter === 'publisher') {
        $where[] = 'o.is_publisher_org = 1';
    } elseif ($filter === 'news_category') {
        $where[] = 'o.is_publisher_org = 1';
        $where[] = '(o.publisher_category IS NULL OR o.publisher_category = \'\' OR o.publisher_category = \'news\')';
    }

    $search = trim($search);
    if ($search !== '') {
        $where[] = '(o.name LIKE :q OR o.org_code LIKE :q OR m.username LIKE :q OR u.username LIKE :q OR u.name LIKE :q OR pno.name LIKE :q OR cb.name LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    try {
        $sql = '
            SELECT
                o.id, o.org_code, o.name, o.status, o.is_publisher_org, o.publisher_category,
                o.publisher_user_id, o.commerce_brand_id, o.created_at,
                m.username AS manager_username,
                u.id AS pub_user_id, u.username AS pub_username, u.name AS pub_name,
                u.publisher_category AS pub_publisher_category,
                pno.name AS registered_publisher_name,
                cb.name AS commerce_brand_name, cb.slug AS commerce_brand_slug
            FROM organizations o
            JOIN managers m ON m.id = o.owner_manager_id
            LEFT JOIN users u ON u.id = o.publisher_user_id
            LEFT JOIN publisher_name_options pno ON pno.org_id = o.id
            LEFT JOIN commerce_brands cb ON cb.id = o.commerce_brand_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY o.id DESC
            LIMIT 200
        ';
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    foreach ($rows as &$row) {
        $suggested = org_commerce_brands_suggest_for_org($dbh, $row);
        $row['suggested_brand_id'] = $suggested ? (int)($suggested['id'] ?? 0) : 0;
        $row['suggested_brand_name'] = $suggested ? (string)($suggested['name'] ?? '') : '';
    }
    unset($row);

    return $rows;
}

/**
 * Assign commerce brand to org and optionally sync publisher category to commerce.
 */
function org_commerce_brands_migrate_org(PDO $dbh, int $orgId, int $brandId, bool $setCommerceCategory = true): bool
{
    if ($orgId <= 0 || $brandId <= 0 || !org_commerce_brands_get($dbh, $brandId)) {
        return false;
    }

    // Never migrate media publishers (CNN, news, sports, …) into commerce brands.
    try {
        $stCat = $dbh->prepare('SELECT publisher_category FROM organizations WHERE id = :id LIMIT 1');
        $stCat->execute([':id' => $orgId]);
        $cat = strtolower(trim((string)($stCat->fetchColumn() ?: '')));
        if ($cat !== '' && $cat !== 'commerce') {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }

    if (!org_commerce_brands_assign_org($dbh, $orgId, $brandId)) {
        return false;
    }

    if (!$setCommerceCategory) {
        return true;
    }

    try {
        if (publisher_db_column_exists($dbh, 'organizations', 'publisher_category')) {
            $st = $dbh->prepare('UPDATE organizations SET publisher_category = :cat, updated_at = NOW() WHERE id = :id LIMIT 1');
            $st->execute([':cat' => 'commerce', ':id' => $orgId]);
        }

        $stPub = $dbh->prepare('SELECT publisher_user_id FROM organizations WHERE id = :id LIMIT 1');
        $stPub->execute([':id' => $orgId]);
        $pubUserId = (int)($stPub->fetchColumn() ?: 0);
        if ($pubUserId > 0 && publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
            $stUser = $dbh->prepare('UPDATE users SET publisher_category = :cat WHERE id = :id LIMIT 1');
            $stUser->execute([':cat' => 'commerce', ':id' => $pubUserId]);
        }
    } catch (Throwable $e) {
        return false;
    }

    return true;
}

/**
 * Auto-match orgs without a brand by organization/publisher name.
 * @return array{migrated:int, skipped:int, errors:int}
 */
function org_commerce_brands_auto_migrate_orgs(PDO $dbh, array $orgIds = []): array
{
    $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];
    $rows = org_commerce_brands_list_orgs_for_migration($dbh, 'unassigned', '');
    foreach ($rows as $row) {
        $orgId = (int)($row['id'] ?? 0);
        if ($orgId <= 0) {
            continue;
        }
        if ($orgIds && !in_array($orgId, $orgIds, true)) {
            continue;
        }
        $cat = strtolower(trim((string)($row['publisher_category'] ?? '')));
        if ($cat !== '' && $cat !== 'commerce') {
            $result['skipped']++;
            continue;
        }
        $brandId = (int)($row['suggested_brand_id'] ?? 0);
        if ($brandId <= 0) {
            $result['skipped']++;
            continue;
        }
        if (org_commerce_brands_migrate_org($dbh, $orgId, $brandId, true)) {
            $result['migrated']++;
        } else {
            $result['errors']++;
        }
    }
    return $result;
}
