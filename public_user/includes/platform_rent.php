<?php
declare(strict_types=1);

/**
 * Platform rent — sellers pay monthly to keep their shop org live.
 */

function platform_rent_db_column_exists(PDO $dbh, string $table, string $column): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($table === '' || $column === '') {
        return false;
    }
    try {
        $st = $dbh->prepare('
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
            LIMIT 1
        ');
        $st->execute([':t' => $table, ':c' => $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function platform_rent_table_exists(PDO $dbh, string $table): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') {
        return false;
    }
    try {
        $st = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote($table));
        return (bool)($st && $st->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function platform_rent_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        if (!platform_rent_table_exists($dbh, 'platform_plans')) {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS `platform_plans` (
                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                  `code` varchar(40) NOT NULL,
                  `name` varchar(80) NOT NULL,
                  `price_cents` int(11) NOT NULL DEFAULT 0,
                  `currency` varchar(3) NOT NULL DEFAULT 'USD',
                  `billing_interval` enum('none','monthly','yearly') NOT NULL DEFAULT 'monthly',
                  `trial_days` int(11) NOT NULL DEFAULT 0,
                  `max_products` int(11) NOT NULL DEFAULT 10,
                  `is_active` tinyint(1) NOT NULL DEFAULT 1,
                  `sort_order` int(11) NOT NULL DEFAULT 0,
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_platform_plans_code` (`code`),
                  KEY `idx_platform_plans_active` (`is_active`,`sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $dbh->exec("
                INSERT INTO `platform_plans` (`id`, `code`, `name`, `price_cents`, `currency`, `billing_interval`, `trial_days`, `max_products`, `is_active`, `sort_order`, `created_at`) VALUES
                (1, 'shop_trial', 'Shop Trial', 0, 'USD', 'none', 30, 10, 1, 0, NOW()),
                (2, 'shop_basic', 'Shop Basic', 499, 'USD', 'monthly', 0, 50, 1, 10, NOW()),
                (3, 'shop_pro', 'Shop Pro', 999, 'USD', 'monthly', 0, 500, 1, 20, NOW())
            ");
        }

        if (!platform_rent_table_exists($dbh, 'platform_payments')) {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS `platform_payments` (
                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                  `org_id` bigint(20) NOT NULL,
                  `plan_id` bigint(20) NOT NULL,
                  `amount_cents` int(11) NOT NULL DEFAULT 0,
                  `currency` varchar(3) NOT NULL DEFAULT 'USD',
                  `months_paid` int(11) NOT NULL DEFAULT 1,
                  `payment_method` varchar(40) DEFAULT NULL,
                  `payment_reference` varchar(120) DEFAULT NULL,
                  `status` enum('confirmed','pending','refunded') NOT NULL DEFAULT 'confirmed',
                  `notes` text,
                  `recorded_by_admin_id` int(11) DEFAULT NULL,
                  `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_platform_payments_org` (`org_id`,`paid_at`),
                  KEY `idx_platform_payments_plan` (`plan_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!platform_rent_db_column_exists($dbh, 'organizations', 'rent_status')) {
            $dbh->exec("
                ALTER TABLE `organizations`
                  ADD COLUMN `platform_plan_id` bigint(20) DEFAULT NULL AFTER `business_model`,
                  ADD COLUMN `rent_status` enum('trial','active','overdue','suspended') NOT NULL DEFAULT 'trial' AFTER `platform_plan_id`,
                  ADD COLUMN `rent_paid_until` datetime DEFAULT NULL AFTER `rent_status`,
                  ADD COLUMN `rent_trial_ends_at` datetime DEFAULT NULL AFTER `rent_paid_until`
            ");
        }
    } catch (Throwable $e) {
        // best-effort
    }

    $done = true;
}

function platform_rent_format_money(int $cents, string $currency = 'USD'): string
{
    $currency = strtoupper(trim($currency) ?: 'USD');
    $amount = number_format($cents / 100, 2);
    if ($currency === 'USD') {
        return '$' . $amount;
    }
    return $amount . ' ' . $currency;
}

/** @return list<array<string, mixed>> */
function platform_rent_list_plans(PDO $dbh, bool $activeOnly = true): array
{
    platform_rent_ensure_schema($dbh);
    if (!platform_rent_table_exists($dbh, 'platform_plans')) {
        return [];
    }
    $sql = 'SELECT * FROM platform_plans';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    try {
        return $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function platform_rent_get_plan(PDO $dbh, int $planId): ?array
{
    platform_rent_ensure_schema($dbh);
    if ($planId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare('SELECT * FROM platform_plans WHERE id = :id LIMIT 1');
        $st->execute([':id' => $planId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function platform_rent_get_plan_by_code(PDO $dbh, string $code): ?array
{
    platform_rent_ensure_schema($dbh);
    $code = strtolower(trim($code));
    if ($code === '') {
        return null;
    }
    try {
        $st = $dbh->prepare('SELECT * FROM platform_plans WHERE code = :c LIMIT 1');
        $st->execute([':c' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function platform_rent_org_is_shop(array $org): bool
{
    if ((int)($org['is_publisher_org'] ?? 0) === 1) {
        return true;
    }
    return strtolower(trim((string)($org['org_kind'] ?? ''))) === 'shop';
}

function platform_rent_sync_org_status(PDO $dbh, int $orgId): string
{
    platform_rent_ensure_schema($dbh);
    if ($orgId <= 0 || !platform_rent_db_column_exists($dbh, 'organizations', 'rent_status')) {
        return 'trial';
    }

    try {
        $st = $dbh->prepare('
            SELECT rent_status, rent_paid_until, rent_trial_ends_at, status
            FROM organizations WHERE id = :id LIMIT 1
        ');
        $st->execute([':id' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 'trial';
        }

        if ((int)($row['status'] ?? 0) !== 1) {
            return 'suspended';
        }

        $current = strtolower(trim((string)($row['rent_status'] ?? 'trial')));
        if ($current === 'suspended') {
            return 'suspended';
        }

        $now = time();
        $paidUntil = trim((string)($row['rent_paid_until'] ?? ''));
        if ($paidUntil !== '') {
            $paidTs = strtotime($paidUntil);
            if ($paidTs && $paidTs >= $now) {
                if ($current !== 'active') {
                    $dbh->prepare('UPDATE organizations SET rent_status = \'active\', updated_at = NOW() WHERE id = :id LIMIT 1')
                        ->execute([':id' => $orgId]);
                }
                return 'active';
            }
            $dbh->prepare('UPDATE organizations SET rent_status = \'overdue\', updated_at = NOW() WHERE id = :id LIMIT 1')
                ->execute([':id' => $orgId]);
            return 'overdue';
        }

        $trialEnds = trim((string)($row['rent_trial_ends_at'] ?? ''));
        if ($trialEnds !== '') {
            $trialTs = strtotime($trialEnds);
            if ($trialTs && $trialTs >= $now) {
                if ($current !== 'trial') {
                    $dbh->prepare('UPDATE organizations SET rent_status = \'trial\', updated_at = NOW() WHERE id = :id LIMIT 1')
                        ->execute([':id' => $orgId]);
                }
                return 'trial';
            }
            $dbh->prepare('UPDATE organizations SET rent_status = \'overdue\', updated_at = NOW() WHERE id = :id LIMIT 1')
                ->execute([':id' => $orgId]);
            return 'overdue';
        }

        return $current !== '' ? $current : 'trial';
    } catch (Throwable $e) {
        return 'trial';
    }
}

function platform_rent_shop_is_visible(PDO $dbh, int $orgId): bool
{
    if ($orgId <= 0) {
        return false;
    }

    try {
        $st = $dbh->prepare('SELECT status, org_kind, is_publisher_org, rent_status FROM organizations WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['status'] ?? 0) !== 1) {
            return false;
        }
        if (!platform_rent_org_is_shop($row)) {
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }

    $status = platform_rent_sync_org_status($dbh, $orgId);
    return in_array($status, ['trial', 'active'], true);
}

function platform_rent_shop_visible_for_publisher(PDO $dbh, int $publisherUserId): bool
{
    if ($publisherUserId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            SELECT id FROM organizations
            WHERE publisher_user_id = :uid AND status = 1
            ORDER BY id ASC LIMIT 1
        ');
        $st->execute([':uid' => $publisherUserId]);
        $orgId = (int)($st->fetchColumn() ?: 0);
        if ($orgId <= 0) {
            return true;
        }
        return platform_rent_shop_is_visible($dbh, $orgId);
    } catch (Throwable $e) {
        return true;
    }
}

function platform_rent_org_snapshot(PDO $dbh, int $orgId): ?array
{
    platform_rent_ensure_schema($dbh);
    if ($orgId <= 0) {
        return null;
    }

    try {
        $st = $dbh->prepare('
            SELECT o.*, p.code AS plan_code, p.name AS plan_name, p.price_cents AS plan_price_cents,
                   p.currency AS plan_currency, p.max_products AS plan_max_products
            FROM organizations o
            LEFT JOIN platform_plans p ON p.id = o.platform_plan_id
            WHERE o.id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['rent_status_live'] = platform_rent_sync_org_status($dbh, $orgId);
        $row['shop_visible'] = platform_rent_shop_is_visible($dbh, $orgId);
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function platform_rent_start_trial_for_org(PDO $dbh, int $orgId, int $trialDays = 30): void
{
    platform_rent_ensure_schema($dbh);
    if ($orgId <= 0) {
        return;
    }

    $trialPlan = platform_rent_get_plan_by_code($dbh, 'shop_trial');
    $planId = (int)($trialPlan['id'] ?? 1);
    $trialDays = max(1, $trialDays > 0 ? $trialDays : (int)($trialPlan['trial_days'] ?? 30));

    try {
        $st = $dbh->prepare('
            UPDATE organizations
            SET platform_plan_id = :plan,
                rent_status = \'trial\',
                rent_paid_until = NULL,
                rent_trial_ends_at = DATE_ADD(NOW(), INTERVAL :days DAY),
                org_kind = IF(org_kind = \'community\', \'shop\', org_kind),
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $st->bindValue(':plan', $planId > 0 ? $planId : null, $planId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $st->bindValue(':days', $trialDays, PDO::PARAM_INT);
        $st->bindValue(':id', $orgId, PDO::PARAM_INT);
        $st->execute();
    } catch (Throwable $e) {
        // ignore
    }
}

function platform_rent_mark_paid(
    PDO $dbh,
    int $orgId,
    int $planId,
    int $monthsPaid,
    int $adminId = 0,
    string $paymentMethod = 'manual',
    string $paymentReference = '',
    string $notes = ''
): bool {
    platform_rent_ensure_schema($dbh);
    if ($orgId <= 0 || $planId <= 0) {
        return false;
    }

    $plan = platform_rent_get_plan($dbh, $planId);
    if (!$plan) {
        return false;
    }

    $monthsPaid = max(1, min($monthsPaid, 36));
    $amountCents = (int)($plan['price_cents'] ?? 0) * $monthsPaid;
    $currency = (string)($plan['currency'] ?? 'USD');

    try {
        $dbh->beginTransaction();

        $stGet = $dbh->prepare('SELECT rent_paid_until FROM organizations WHERE id = :id LIMIT 1 FOR UPDATE');
        $stGet->execute([':id' => $orgId]);
        $existingUntil = trim((string)($stGet->fetchColumn() ?: ''));

        $baseTs = time();
        if ($existingUntil !== '') {
            $existingTs = strtotime($existingUntil);
            if ($existingTs && $existingTs > $baseTs) {
                $baseTs = $existingTs;
            }
        }

        $paidUntil = date('Y-m-d H:i:s', strtotime('+' . $monthsPaid . ' months', $baseTs));

        $stU = $dbh->prepare('
            UPDATE organizations
            SET platform_plan_id = :plan,
                rent_status = \'active\',
                rent_paid_until = :until,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $stU->execute([
            ':plan' => $planId,
            ':until' => $paidUntil,
            ':id' => $orgId,
        ]);

        $stP = $dbh->prepare('
            INSERT INTO platform_payments (
                org_id, plan_id, amount_cents, currency, months_paid,
                payment_method, payment_reference, status, notes,
                recorded_by_admin_id, paid_at, created_at
            ) VALUES (
                :org, :plan, :amt, :cur, :months,
                :method, :ref, \'confirmed\', :notes,
                :admin, NOW(), NOW()
            )
        ');
        $stP->execute([
            ':org' => $orgId,
            ':plan' => $planId,
            ':amt' => $amountCents,
            ':cur' => $currency,
            ':months' => $monthsPaid,
            ':method' => $paymentMethod !== '' ? $paymentMethod : null,
            ':ref' => $paymentReference !== '' ? $paymentReference : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':admin' => $adminId > 0 ? $adminId : null,
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

function platform_rent_suspend(PDO $dbh, int $orgId): bool
{
    platform_rent_ensure_schema($dbh);
    if ($orgId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('UPDATE organizations SET rent_status = \'suspended\', updated_at = NOW() WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orgId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function platform_rent_list_payments(PDO $dbh, int $orgId, int $limit = 20): array
{
    platform_rent_ensure_schema($dbh);
    if ($orgId <= 0 || !platform_rent_table_exists($dbh, 'platform_payments')) {
        return [];
    }
    $limit = max(1, min($limit, 100));
    try {
        $st = $dbh->prepare("
            SELECT pp.*, pl.name AS plan_name, pl.code AS plan_code
            FROM platform_payments pp
            JOIN platform_plans pl ON pl.id = pp.plan_id
            WHERE pp.org_id = :org
            ORDER BY pp.paid_at DESC, pp.id DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function platform_rent_status_badge(string $status): string
{
    $status = strtolower(trim($status));
    switch ($status) {
        case 'active':
            return '<span class="pill ok">Rent active</span>';
        case 'trial':
            return '<span class="pill info">Trial</span>';
        case 'overdue':
            return '<span class="pill bad">Overdue</span>';
        case 'suspended':
            return '<span class="pill bad">Suspended</span>';
        default:
            return '<span class="pill">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
