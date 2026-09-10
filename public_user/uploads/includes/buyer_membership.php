<?php
declare(strict_types=1);

/**
 * Customer marketplace membership — optional $10/month subscription.
 * Active members get $0 platform service fee on shop orders.
 */

require_once __DIR__ . '/platform_rent.php';

function buyer_membership_price_cents(): int
{
    return 1000; // $10.00 / month
}

function buyer_membership_member_service_fee_cents(): int
{
    return 0; // members skip the $1.99 service fee
}

function buyer_membership_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        if (!platform_rent_table_exists($dbh, 'buyer_memberships')) {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS `buyer_memberships` (
                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                  `user_id` bigint(20) NOT NULL,
                  `status` enum('active','expired','cancelled') NOT NULL DEFAULT 'expired',
                  `paid_until` datetime DEFAULT NULL,
                  `plan_code` varchar(40) NOT NULL DEFAULT 'customer_plus',
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_buyer_memberships_user` (`user_id`),
                  KEY `idx_buyer_memberships_status` (`status`,`paid_until`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!platform_rent_table_exists($dbh, 'buyer_membership_payments')) {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS `buyer_membership_payments` (
                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                  `user_id` bigint(20) NOT NULL,
                  `amount_cents` int(11) NOT NULL DEFAULT 0,
                  `currency` varchar(3) NOT NULL DEFAULT 'USD',
                  `months_paid` int(11) NOT NULL DEFAULT 1,
                  `payment_method` varchar(40) DEFAULT NULL,
                  `payment_reference` varchar(120) DEFAULT NULL,
                  `status` enum('confirmed','pending','refunded') NOT NULL DEFAULT 'confirmed',
                  `notes` text,
                  `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_buyer_membership_payments_user` (`user_id`,`paid_at`),
                  KEY `idx_buyer_membership_payments_ref` (`payment_reference`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    } catch (Throwable $e) {
        // best-effort
    }

    $done = true;
}

function buyer_membership_sync_status(PDO $dbh, int $userId): string
{
    buyer_membership_ensure_schema($dbh);
    if ($userId <= 0) {
        return 'expired';
    }

    try {
        $st = $dbh->prepare('SELECT status, paid_until FROM buyer_memberships WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 'expired';
        }

        $status = strtolower(trim((string)($row['status'] ?? 'expired')));
        if ($status === 'cancelled') {
            return 'cancelled';
        }

        $paidUntil = trim((string)($row['paid_until'] ?? ''));
        $paidTs = $paidUntil !== '' ? strtotime($paidUntil) : false;
        if ($paidTs && $paidTs >= time()) {
            if ($status !== 'active') {
                $dbh->prepare('UPDATE buyer_memberships SET status = \'active\', updated_at = NOW() WHERE user_id = :uid LIMIT 1')
                    ->execute([':uid' => $userId]);
            }
            return 'active';
        }

        if ($status !== 'expired') {
            $dbh->prepare('UPDATE buyer_memberships SET status = \'expired\', updated_at = NOW() WHERE user_id = :uid LIMIT 1')
                ->execute([':uid' => $userId]);
        }
        return 'expired';
    } catch (Throwable $e) {
        return 'expired';
    }
}

function buyer_membership_is_active(PDO $dbh, int $userId): bool
{
    return buyer_membership_sync_status($dbh, $userId) === 'active';
}

/** @return array<string, mixed>|null */
function buyer_membership_snapshot(PDO $dbh, int $userId): ?array
{
    buyer_membership_ensure_schema($dbh);
    if ($userId <= 0) {
        return null;
    }

    $live = buyer_membership_sync_status($dbh, $userId);
    try {
        $st = $dbh->prepare('SELECT * FROM buyer_memberships WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $row = null;
    }

    if (!$row) {
        return [
            'user_id' => $userId,
            'status' => 'expired',
            'status_live' => 'expired',
            'paid_until' => null,
            'is_active' => false,
            'price_cents' => buyer_membership_price_cents(),
            'service_fee_cents' => org_shop_buyer_service_fee_cents(),
        ];
    }

    $row['status_live'] = $live;
    $row['is_active'] = ($live === 'active');
    $row['price_cents'] = buyer_membership_price_cents();
    $row['service_fee_cents'] = $row['is_active']
        ? buyer_membership_member_service_fee_cents()
        : (function_exists('org_shop_buyer_service_fee_cents') ? org_shop_buyer_service_fee_cents() : 199);
    return $row;
}

function buyer_membership_mark_paid(
    PDO $dbh,
    int $userId,
    int $monthsPaid = 1,
    string $paymentMethod = 'stripe',
    string $paymentReference = '',
    string $notes = ''
): bool {
    buyer_membership_ensure_schema($dbh);
    if ($userId <= 0) {
        return false;
    }

    $monthsPaid = max(1, min(12, $monthsPaid));
    $amountCents = buyer_membership_price_cents() * $monthsPaid;

    try {
        $dbh->beginTransaction();

        $stGet = $dbh->prepare('SELECT paid_until FROM buyer_memberships WHERE user_id = :uid LIMIT 1 FOR UPDATE');
        $stGet->execute([':uid' => $userId]);
        $existingUntil = trim((string)($stGet->fetchColumn() ?: ''));

        $baseTs = time();
        if ($existingUntil !== '') {
            $existingTs = strtotime($existingUntil);
            if ($existingTs && $existingTs > $baseTs) {
                $baseTs = $existingTs;
            }
        }
        $paidUntil = date('Y-m-d H:i:s', strtotime('+' . $monthsPaid . ' months', $baseTs));

        $stUp = $dbh->prepare('
            INSERT INTO buyer_memberships (user_id, status, paid_until, plan_code, created_at, updated_at)
            VALUES (:uid, \'active\', :until, \'customer_plus\', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              status = \'active\',
              paid_until = VALUES(paid_until),
              plan_code = \'customer_plus\',
              updated_at = NOW()
        ');
        $stUp->execute([':uid' => $userId, ':until' => $paidUntil]);

        $stPay = $dbh->prepare('
            INSERT INTO buyer_membership_payments (
                user_id, amount_cents, currency, months_paid,
                payment_method, payment_reference, status, notes, paid_at, created_at
            ) VALUES (
                :uid, :amt, \'USD\', :months,
                :method, :ref, \'confirmed\', :notes, NOW(), NOW()
            )
        ');
        $stPay->execute([
            ':uid' => $userId,
            ':amt' => $amountCents,
            ':months' => $monthsPaid,
            ':method' => $paymentMethod !== '' ? $paymentMethod : null,
            ':ref' => $paymentReference !== '' ? $paymentReference : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $dbh->commit();
        return true;
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        return false;
    }
}

/**
 * @param array<string, mixed> $session
 */
function buyer_membership_fulfill_stripe_session(PDO $dbh, array $session): bool
{
    $meta = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    if (strtolower(trim((string)($meta['kind'] ?? ''))) !== 'buyer_membership') {
        return false;
    }

    $paymentStatus = strtolower(trim((string)($session['payment_status'] ?? '')));
    $status = strtolower(trim((string)($session['status'] ?? '')));
    if ($paymentStatus !== 'paid' && $status !== 'complete') {
        return false;
    }

    $userId = (int)($meta['user_id'] ?? 0);
    $months = max(1, min(12, (int)($meta['months'] ?? 1)));
    $sessionId = trim((string)($session['id'] ?? ''));
    if ($userId <= 0) {
        return false;
    }

    if ($sessionId !== '' && platform_rent_table_exists($dbh, 'buyer_membership_payments')) {
        try {
            $st = $dbh->prepare('SELECT 1 FROM buyer_membership_payments WHERE payment_reference = :ref LIMIT 1');
            $st->execute([':ref' => $sessionId]);
            if ($st->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            // continue
        }
    }

    return buyer_membership_mark_paid(
        $dbh,
        $userId,
        $months,
        'stripe',
        $sessionId,
        'Customer Plus membership'
    );
}

/** @return list<array<string, mixed>> */
function buyer_membership_list_payments(PDO $dbh, int $userId, int $limit = 20): array
{
    buyer_membership_ensure_schema($dbh);
    if ($userId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    try {
        $st = $dbh->prepare("
            SELECT * FROM buyer_membership_payments
            WHERE user_id = :uid
            ORDER BY paid_at DESC, id DESC
            LIMIT {$limit}
        ");
        $st->execute([':uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Admin: confirmed membership revenue total. */
function buyer_membership_revenue_cents(PDO $dbh): int
{
    buyer_membership_ensure_schema($dbh);
    try {
        $st = $dbh->query("SELECT COALESCE(SUM(amount_cents), 0) FROM buyer_membership_payments WHERE status = 'confirmed'");
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return list<array<string, mixed>> */
function buyer_membership_admin_list(PDO $dbh, string $filter = 'all', string $search = '', int $limit = 200): array
{
    buyer_membership_ensure_schema($dbh);
    $limit = max(1, min(500, $limit));
    $where = ['1=1'];
    $params = [];

    if ($filter === 'active') {
        $where[] = "m.status = 'active' AND m.paid_until IS NOT NULL AND m.paid_until >= NOW()";
    } elseif ($filter === 'expired') {
        $where[] = "(m.status <> 'active' OR m.paid_until IS NULL OR m.paid_until < NOW())";
    }

    if ($search !== '') {
        $where[] = '(u.username LIKE :q OR u.email LIKE :q OR u.fullname LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    try {
        $sql = '
            SELECT m.*, u.username, u.email, u.fullname
            FROM buyer_memberships m
            JOIN users u ON u.id = m.user_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY m.paid_until DESC, m.id DESC
            LIMIT ' . $limit;
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $uid = (int)($row['user_id'] ?? 0);
            $row['status_live'] = buyer_membership_sync_status($dbh, $uid);
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}
