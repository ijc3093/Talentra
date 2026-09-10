<?php
declare(strict_types=1);

/**
 * Buyer ↔ seller relationship preferences so sellers can meet customer needs.
 */

function buyer_seller_rel_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS buyer_seller_relationships (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                buyer_user_id BIGINT UNSIGNED NOT NULL,
                org_id BIGINT UNSIGNED NOT NULL,
                relationship_type VARCHAR(40) NOT NULL DEFAULT 'shopper',
                interests VARCHAR(500) NOT NULL DEFAULT '',
                preferred_contact VARCHAR(40) NOT NULL DEFAULT 'message',
                delivery_preference VARCHAR(80) NOT NULL DEFAULT '',
                budget_range VARCHAR(40) NOT NULL DEFAULT '',
                needs_note TEXT NULL,
                share_with_seller TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_buyer_seller_rel (buyer_user_id, org_id),
                KEY idx_buyer_seller_rel_org (org_id, updated_at),
                KEY idx_buyer_seller_rel_buyer (buyer_user_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }
}

/** @return list<array{value:string,label:string}> */
function buyer_seller_rel_types(): array
{
    return [
        ['value' => 'shopper', 'label' => 'Personal shopper'],
        ['value' => 'business', 'label' => 'Business / wholesale'],
        ['value' => 'gifts', 'label' => 'Gifts & occasions'],
        ['value' => 'subscriber', 'label' => 'Repeat / subscription'],
        ['value' => 'other', 'label' => 'Other'],
    ];
}

/** @return list<array{value:string,label:string}> */
function buyer_seller_rel_contact_options(): array
{
    return [
        ['value' => 'message', 'label' => 'In-app message'],
        ['value' => 'email', 'label' => 'Email'],
        ['value' => 'phone', 'label' => 'Phone'],
    ];
}

function buyer_seller_rel_type_label(string $type): string
{
    foreach (buyer_seller_rel_types() as $row) {
        if ($row['value'] === $type) {
            return $row['label'];
        }
    }
    return $type !== '' ? $type : 'Personal shopper';
}

function buyer_seller_rel_contact_label(string $preferred): string
{
    foreach (buyer_seller_rel_contact_options() as $row) {
        if ($row['value'] === $preferred) {
            return $row['label'];
        }
    }
    return $preferred !== '' ? $preferred : 'In-app message';
}

/** @return array<string, mixed>|null */
function buyer_seller_rel_get(PDO $dbh, int $buyerUserId, int $orgId): ?array
{
    buyer_seller_rel_ensure_schema($dbh);
    if ($buyerUserId <= 0 || $orgId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare('
            SELECT * FROM buyer_seller_relationships
            WHERE buyer_user_id = :buyer AND org_id = :org
            LIMIT 1
        ');
        $st->execute([':buyer' => $buyerUserId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Shared prefs for seller view (respects share_with_seller).
 * @return array<string, mixed>|null
 */
function buyer_seller_rel_for_seller(PDO $dbh, int $orgId, int $buyerUserId): ?array
{
    $row = buyer_seller_rel_get($dbh, $buyerUserId, $orgId);
    if (!$row || empty($row['share_with_seller'])) {
        return null;
    }
    return $row;
}

/**
 * Sellers the buyer has ordered from, with relationship row if any.
 * @return list<array<string, mixed>>
 */
function buyer_seller_rel_list_for_buyer(PDO $dbh, int $buyerUserId): array
{
    buyer_seller_rel_ensure_schema($dbh);
    if ($buyerUserId <= 0) {
        return [];
    }
    try {
        $st = $dbh->prepare("
            SELECT
                o.org_id,
                org.name AS seller_name,
                org.publisher_user_id,
                COUNT(*) AS order_count,
                MAX(o.created_at) AS last_ordered_at,
                SUM(o.total_cents) AS total_spent_cents,
                MAX(o.currency) AS currency,
                r.id AS relationship_id,
                r.relationship_type,
                r.interests,
                r.preferred_contact,
                r.delivery_preference,
                r.budget_range,
                r.needs_note,
                r.share_with_seller,
                r.updated_at AS relationship_updated_at
            FROM org_orders o
            INNER JOIN organizations org ON org.id = o.org_id AND org.status = 1
            LEFT JOIN buyer_seller_relationships r
              ON r.buyer_user_id = o.buyer_user_id AND r.org_id = o.org_id
            WHERE o.buyer_user_id = :uid
              AND org.commerce_brand_id IS NOT NULL
              AND org.commerce_brand_id > 0
              AND LOWER(TRIM(COALESCE(org.publisher_category, ''))) IN ('', 'commerce')
            GROUP BY o.org_id, org.name, org.publisher_user_id,
                     r.id, r.relationship_type, r.interests, r.preferred_contact,
                     r.delivery_preference, r.budget_range, r.needs_note,
                     r.share_with_seller, r.updated_at
            ORDER BY last_ordered_at DESC, seller_name ASC
        ");
        $st->execute([':uid' => $buyerUserId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,error?:string,id?:int}
 */
function buyer_seller_rel_save(PDO $dbh, int $buyerUserId, int $orgId, array $data): array
{
    buyer_seller_rel_ensure_schema($dbh);
    if ($buyerUserId <= 0 || $orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid seller or buyer.'];
    }

    // Buyer may only save for sellers they have ordered from.
    try {
        $chk = $dbh->prepare('SELECT 1 FROM org_orders WHERE buyer_user_id = :b AND org_id = :o LIMIT 1');
        $chk->execute([':b' => $buyerUserId, ':o' => $orgId]);
        if (!$chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'You can only share preferences with sellers you have ordered from.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not verify seller relationship.'];
    }

    $allowedTypes = array_column(buyer_seller_rel_types(), 'value');
    $type = strtolower(trim((string)($data['relationship_type'] ?? 'shopper')));
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'shopper';
    }
    $allowedContact = array_column(buyer_seller_rel_contact_options(), 'value');
    $contact = strtolower(trim((string)($data['preferred_contact'] ?? 'message')));
    if (!in_array($contact, $allowedContact, true)) {
        $contact = 'message';
    }

    $fields = [
        ':buyer' => $buyerUserId,
        ':org' => $orgId,
        ':type' => $type,
        ':interests' => mb_substr(trim((string)($data['interests'] ?? '')), 0, 500),
        ':contact' => $contact,
        ':delivery' => mb_substr(trim((string)($data['delivery_preference'] ?? '')), 0, 80),
        ':budget' => mb_substr(trim((string)($data['budget_range'] ?? '')), 0, 40),
        ':note' => trim((string)($data['needs_note'] ?? '')),
        ':share' => !empty($data['share_with_seller']) ? 1 : 0,
    ];

    try {
        $st = $dbh->prepare('
            INSERT INTO buyer_seller_relationships (
                buyer_user_id, org_id, relationship_type, interests, preferred_contact,
                delivery_preference, budget_range, needs_note, share_with_seller,
                created_at, updated_at
            ) VALUES (
                :buyer, :org, :type, :interests, :contact,
                :delivery, :budget, :note, :share,
                NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                relationship_type = VALUES(relationship_type),
                interests = VALUES(interests),
                preferred_contact = VALUES(preferred_contact),
                delivery_preference = VALUES(delivery_preference),
                budget_range = VALUES(budget_range),
                needs_note = VALUES(needs_note),
                share_with_seller = VALUES(share_with_seller),
                updated_at = NOW()
        ');
        $st->execute($fields);
        $row = buyer_seller_rel_get($dbh, $buyerUserId, $orgId);
        return ['ok' => true, 'id' => (int)($row['id'] ?? 0)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save seller preferences.'];
    }
}
