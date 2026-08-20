<?php
declare(strict_types=1);

/**
 * Stripe Connect Express helpers for organization sellers.
 */

require_once __DIR__ . '/org_shop.php';
require_once __DIR__ . '/stripe_shop.php';

function org_shop_connect_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    org_shop_ensure_schema($dbh);
    $cols = [
        'stripe_connect_account_id' => "VARCHAR(64) NULL DEFAULT NULL",
        'stripe_connect_charges_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
        'stripe_connect_payouts_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
        'stripe_connect_details_submitted' => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $name => $def) {
        if (!platform_rent_db_column_exists($dbh, 'organizations', $name)) {
            try {
                $dbh->exec("ALTER TABLE organizations ADD COLUMN `{$name}` {$def}");
            } catch (Throwable $e) {
            }
        }
    }
    if (!platform_rent_db_column_exists($dbh, 'org_orders', 'stripe_transfer_id')) {
        try {
            $dbh->exec("ALTER TABLE org_orders ADD COLUMN stripe_transfer_id VARCHAR(64) NULL DEFAULT NULL");
        } catch (Throwable $e) {
        }
    }
}

/** @return array{account_id:string,charges_enabled:bool,payouts_enabled:bool,details_submitted:bool} */
function org_shop_connect_status(PDO $dbh, int $orgId): array
{
    $out = [
        'account_id' => '',
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => false,
    ];
    if ($orgId <= 0) {
        return $out;
    }
    org_shop_connect_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT stripe_connect_account_id,
                   COALESCE(stripe_connect_charges_enabled,0) AS charges_enabled,
                   COALESCE(stripe_connect_payouts_enabled,0) AS payouts_enabled,
                   COALESCE(stripe_connect_details_submitted,0) AS details_submitted
            FROM organizations
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['account_id'] = trim((string)($row['stripe_connect_account_id'] ?? ''));
        $out['charges_enabled'] = (int)($row['charges_enabled'] ?? 0) === 1;
        $out['payouts_enabled'] = (int)($row['payouts_enabled'] ?? 0) === 1;
        $out['details_submitted'] = (int)($row['details_submitted'] ?? 0) === 1;
    } catch (Throwable $e) {
    }
    return $out;
}

function org_shop_connect_sync_account(PDO $dbh, int $orgId): array
{
    $status = org_shop_connect_status($dbh, $orgId);
    $accountId = $status['account_id'];
    if ($accountId === '') {
        return $status;
    }
    $account = stripe_shop_connect_retrieve_account($accountId);
    if (!$account) {
        return $status;
    }
    $charges = !empty($account['charges_enabled']);
    $payouts = !empty($account['payouts_enabled']);
    $details = !empty($account['details_submitted']);
    try {
        $dbh->prepare('
            UPDATE organizations
            SET stripe_connect_charges_enabled = :c,
                stripe_connect_payouts_enabled = :p,
                stripe_connect_details_submitted = :d,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ')->execute([
            ':c' => $charges ? 1 : 0,
            ':p' => $payouts ? 1 : 0,
            ':d' => $details ? 1 : 0,
            ':id' => $orgId,
        ]);
    } catch (Throwable $e) {
    }
    return [
        'account_id' => $accountId,
        'charges_enabled' => $charges,
        'payouts_enabled' => $payouts,
        'details_submitted' => $details,
    ];
}

/**
 * Ensure Connect account exists and return onboarding URL.
 *
 * @return array{ok:bool,url?:string,error?:string,status?:array}
 */
function org_shop_connect_start_onboarding(PDO $dbh, int $orgId): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Missing organization.'];
    }
    if (!stripe_shop_is_configured()) {
        return ['ok' => false, 'error' => 'Stripe is not configured on this server.'];
    }
    org_shop_connect_ensure_schema($dbh);
    $status = org_shop_connect_status($dbh, $orgId);
    $accountId = $status['account_id'];

    if ($accountId === '') {
        $email = '';
        if (function_exists('org_ecommerce_seller_info_seed')) {
            $seed = org_ecommerce_seller_info_seed($dbh, $orgId);
            $email = trim((string)($seed['contact_email'] ?? ''));
        }
        if ($email === '') {
            try {
                $st = $dbh->prepare('
                    SELECT u.email
                    FROM organizations o
                    LEFT JOIN users u ON u.id = o.publisher_user_id
                    WHERE o.id = :id
                    LIMIT 1
                ');
                $st->execute([':id' => $orgId]);
                $email = trim((string)($st->fetchColumn() ?: ''));
            } catch (Throwable $e) {
            }
        }
        $created = stripe_shop_connect_create_express_account($email, 'US');
        if (empty($created['ok'])) {
            return ['ok' => false, 'error' => (string)($created['error'] ?? 'Could not create Connect account.')];
        }
        $accountId = (string)($created['account_id'] ?? '');
        try {
            $dbh->prepare('
                UPDATE organizations
                SET stripe_connect_account_id = :aid, updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ')->execute([':aid' => $accountId, ':id' => $orgId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not save Connect account.'];
        }
    }

    $base = stripe_shop_organization_base_url();
    $link = stripe_shop_connect_account_link(
        $accountId,
        $base . '/payments.php?connect=refresh',
        $base . '/payments.php?connect=return'
    );
    if (empty($link['ok'])) {
        return ['ok' => false, 'error' => (string)($link['error'] ?? 'Could not start onboarding.')];
    }
    return [
        'ok' => true,
        'url' => (string)($link['url'] ?? ''),
        'status' => org_shop_connect_sync_account($dbh, $orgId),
    ];
}

/**
 * After an order is paid on the platform, push seller_payout_cents to Connect.
 *
 * @return array{ok:bool,skipped?:bool,transfer_id?:string,error?:string}
 */
function org_shop_connect_auto_payout_order(PDO $dbh, int $orderId): array
{
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'bad_order'];
    }
    org_shop_connect_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT id, org_id, currency, status,
                   COALESCE(seller_payout_cents, 0) AS seller_payout_cents,
                   COALESCE(NULLIF(TRIM(payout_status), \'\'), \'pending\') AS payout_status,
                   COALESCE(stripe_transfer_id, \'\') AS stripe_transfer_id,
                   COALESCE(order_code, \'\') AS order_code
            FROM org_orders
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!in_array((string)($order['status'] ?? ''), ['paid', 'shipped', 'delivered'], true)) {
            return ['ok' => false, 'skipped' => true, 'error' => 'order_not_paid'];
        }
        if (trim((string)($order['stripe_transfer_id'] ?? '')) !== '') {
            return ['ok' => true, 'skipped' => true, 'transfer_id' => (string)$order['stripe_transfer_id']];
        }
        if (strtolower((string)($order['payout_status'] ?? '')) === 'paid') {
            return ['ok' => true, 'skipped' => true];
        }

        $orgId = (int)($order['org_id'] ?? 0);
        $amount = (int)($order['seller_payout_cents'] ?? 0);
        if ($amount <= 0) {
            // Fees may not be applied yet.
            org_shop_apply_order_fees($dbh, $orderId);
            $st->execute([':id' => $orderId]);
            $order = $st->fetch(PDO::FETCH_ASSOC) ?: $order;
            $amount = (int)($order['seller_payout_cents'] ?? 0);
        }
        if ($amount <= 0 || $orgId <= 0) {
            return ['ok' => false, 'skipped' => true, 'error' => 'no_payout_amount'];
        }

        $status = org_shop_connect_sync_account($dbh, $orgId);
        if ($status['account_id'] === '' || !$status['payouts_enabled']) {
            // Ready for manual scheduling.
            try {
                $dbh->prepare("
                    UPDATE org_orders
                    SET payout_status = 'scheduled', updated_at = NOW()
                    WHERE id = :id AND COALESCE(NULLIF(TRIM(payout_status), ''), 'pending') = 'pending'
                    LIMIT 1
                ")->execute([':id' => $orderId]);
            } catch (Throwable $e) {
            }
            return ['ok' => false, 'skipped' => true, 'error' => 'connect_not_ready'];
        }

        $xfer = stripe_shop_connect_create_transfer(
            $amount,
            (string)($order['currency'] ?? 'USD'),
            $status['account_id'],
            'order-' . (string)($order['order_code'] ?? $orderId),
            [
                'order_id' => (string)$orderId,
                'org_id' => (string)$orgId,
                'order_code' => (string)($order['order_code'] ?? ''),
            ]
        );
        if (empty($xfer['ok'])) {
            try {
                $dbh->prepare("
                    UPDATE org_orders
                    SET payout_status = 'scheduled', updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ")->execute([':id' => $orderId]);
            } catch (Throwable $e) {
            }
            return ['ok' => false, 'error' => (string)($xfer['error'] ?? 'transfer_failed')];
        }

        $tid = (string)($xfer['transfer_id'] ?? '');
        $dbh->prepare("
            UPDATE org_orders
            SET payout_status = 'paid',
                stripe_transfer_id = :tid,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ")->execute([':tid' => $tid, ':id' => $orderId]);

        return ['ok' => true, 'transfer_id' => $tid];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'db'];
    }
}
