<?php
declare(strict_types=1);

require_once __DIR__ . '/org_ecommerce.php';
require_once __DIR__ . '/org_crm_lifecycle.php';

function org_sales_money(int $cents, string $currency = 'USD'): string
{
    return org_shop_format_price($cents, $currency);
}

function org_sales_status_badge(string $status): string
{
    $status = strtolower(trim($status));
    $map = [
        'pending' => 'badge-warning',
        'confirmed' => 'badge-info',
        'paid' => 'badge-primary',
        'shipped' => 'badge-info',
        'delivered' => 'badge-success',
        'cancelled' => 'badge-secondary',
        'draft' => 'badge-light',
        'sent' => 'badge-info',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'expired' => 'badge-secondary',
        'overdue' => 'badge-danger',
        'scheduled' => 'badge-info',
    ];
    return $map[$status] ?? 'badge-light';
}

/** @return array<string, mixed>|null */
function org_sales_order(PDO $dbh, int $orgId, int $orderId): ?array
{
    if ($orgId <= 0 || $orderId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare('
            SELECT o.*, p.sku, p.status AS product_status, p.stock_qty, p.fulfillment_method AS product_fulfillment
            FROM org_orders o
            LEFT JOIN org_products p ON p.id = o.product_id
            WHERE o.id = :id AND o.org_id = :org
            LIMIT 1
        ');
        $st->execute([':id' => $orderId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** @return list<array<string, mixed>> */
function org_sales_recent_payments(PDO $dbh, int $orgId, int $limit = 100): array
{
    $limit = max(1, min($limit, 200));
    try {
        $st = $dbh->prepare("
            SELECT 'order' AS source, id, order_code AS code, buyer_name AS customer,
                   total_cents AS amount_cents, currency, status, paid_at, created_at
            FROM org_orders
            WHERE org_id = :org AND status IN ('paid','shipped','delivered')
            ORDER BY COALESCE(paid_at, updated_at, created_at) DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string, int> */
function org_sales_payment_totals(PDO $dbh, int $orgId): array
{
    $out = ['paid_cents' => 0, 'outstanding_cents' => 0, 'payments_count' => 0, 'open_invoices' => 0];
    try {
        $st = $dbh->prepare("SELECT COALESCE(SUM(total_cents),0), COUNT(*) FROM org_orders WHERE org_id = :org AND status IN ('paid','shipped','delivered')");
        $st->execute([':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $out['paid_cents'] = (int)$row[0];
        $out['payments_count'] = (int)$row[1];

        org_crm_lifecycle_ensure_schema($dbh);
        $st = $dbh->prepare("SELECT COALESCE(SUM(amount_cents),0), COUNT(*) FROM org_crm_invoices WHERE org_id = :org AND status IN ('sent','overdue')");
        $st->execute([':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $out['outstanding_cents'] = (int)$row[0];
        $out['open_invoices'] = (int)$row[1];
    } catch (Throwable $e) {
        // keep defaults
    }
    return $out;
}

/** @return list<array<string, mixed>> */
function org_sales_delivery_orders(PDO $dbh, int $orgId): array
{
    return org_shop_list_orders($dbh, $orgId, 'all', 200);
}

/** @return list<array<string, mixed>> */
function org_sales_returns_candidates(PDO $dbh, int $orgId): array
{
    try {
        $st = $dbh->prepare("
            SELECT *
            FROM org_orders
            WHERE org_id = :org
              AND status IN ('delivered','cancelled')
            ORDER BY updated_at DESC, created_at DESC
            LIMIT 200
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function org_sales_return_requests(PDO $dbh, int $orgId, string $statusFilter = 'all'): array
{
    if ($orgId <= 0) {
        return [];
    }
    org_ecommerce_ensure_schema($dbh);
    $where = ['r.org_id = :org'];
    $params = [':org' => $orgId];
    $statusFilter = strtolower(trim($statusFilter));
    if ($statusFilter !== '' && $statusFilter !== 'all') {
        $where[] = 'r.status = :st';
        $params[':st'] = $statusFilter;
    }
    try {
        $st = $dbh->prepare('
            SELECT r.*,
                   o.order_code, o.product_title, o.total_cents, o.currency, o.status AS order_status,
                   o.buyer_name, o.buyer_email, o.buyer_phone
            FROM org_order_returns r
            INNER JOIN org_orders o ON o.id = r.order_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
              FIELD(r.status, \'requested\', \'approved\', \'refunded\', \'rejected\'),
              r.created_at DESC, r.id DESC
            LIMIT 200
        ');
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_sales_update_return_request(
    PDO $dbh,
    int $orgId,
    int $returnId,
    string $status,
    string $sellerNotes = ''
): bool {
    $allowed = ['requested', 'approved', 'rejected', 'refunded'];
    if ($orgId <= 0 || $returnId <= 0 || !in_array($status, $allowed, true)) {
        return false;
    }
    org_ecommerce_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT r.*, o.id AS order_pk, o.status AS order_status
            FROM org_order_returns r
            INNER JOIN org_orders o ON o.id = r.order_id AND o.org_id = r.org_id
            WHERE r.id = :id AND r.org_id = :org
            LIMIT 1
        ');
        $st->execute([':id' => $returnId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $upd = $dbh->prepare('
            UPDATE org_order_returns
            SET status = :st,
                seller_notes = :notes,
                updated_at = NOW()
            WHERE id = :id AND org_id = :org
            LIMIT 1
        ');
        $upd->execute([
            ':st' => $status,
            ':notes' => $sellerNotes !== '' ? $sellerNotes : ($row['seller_notes'] ?? null),
            ':id' => $returnId,
            ':org' => $orgId,
        ]);

        // Keep order + return in sync for approval / refund / reject.
        $orderId = (int)($row['order_id'] ?? 0);
        $note = trim($sellerNotes);
        if ($note === '') {
            $note = 'Return ' . $status;
        }
        if (in_array($status, ['approved', 'refunded'], true) && $orderId > 0) {
            org_ecommerce_update_fulfillment($dbh, $orgId, $orderId, 'cancelled', $note, '', '');
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function org_sales_promotions(PDO $dbh, int $orgId): array
{
    $settings = org_ecommerce_get_shop_settings($dbh, $orgId);
    $promos = $settings['promotions'] ?? [];
    return is_array($promos) ? array_values(array_filter($promos, 'is_array')) : [];
}

/** @param list<array<string, mixed>> $promotions */
function org_sales_save_promotions(PDO $dbh, int $orgId, array $promotions): bool
{
    return org_ecommerce_save_shop_settings($dbh, $orgId, ['promotions' => array_values($promotions)]);
}

/** @return list<array<string, mixed>> */
function org_sales_salesperson_performance(PDO $dbh, int $orgId): array
{
    try {
        $st = $dbh->prepare("
            SELECT
              om.id AS member_row_id,
              om.member_type,
              om.member_id,
              COALESCE(m.fullname, s.fullname, m.username, s.username, 'Team member') AS name,
              COALESCE(m.email, s.email, '') AS email,
              COUNT(o.id) AS assigned_orders,
              COALESCE(SUM(CASE WHEN o.status IN ('paid','shipped','delivered') THEN o.total_cents ELSE 0 END),0) AS revenue_cents,
              COALESCE(SUM(CASE WHEN o.status IN ('pending','confirmed') THEN 1 ELSE 0 END),0) AS open_orders
            FROM org_members om
            LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
            LEFT JOIN org_orders o ON o.org_id = om.org_id AND o.assigned_member_id = om.id
            WHERE om.org_id = :org AND om.status = 1
            GROUP BY om.id, om.member_type, om.member_id, name, email
            ORDER BY revenue_cents DESC, assigned_orders DESC, name ASC
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array{type:string,message:string,action:string,count:int}> */
/**
 * Live order-lifecycle counts for seller Notification (customer-order groups).
 *
 * Cancel = seller cancelled the customer's order.
 * Cancellation = customer cancelled their own order.
 *
 * @return array{
 *   pending:int,paid:int,cancel:int,cancellation:int,cancelled:int,shipping:int,delivery:int
 * }
 */
function org_sales_order_lifecycle_counts(PDO $dbh, int $orgId): array
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
    if ($orgId <= 0) {
        return $out;
    }
    try {
        if (!function_exists('org_shop_list_orders')) {
            require_once dirname(__DIR__, 2) . '/public_user/includes/org_shop.php';
        }
        $pendingLines = array_merge(
            org_shop_list_orders($dbh, $orgId, 'pending', 500),
            org_shop_list_orders($dbh, $orgId, 'confirmed', 500)
        );
        $out['pending'] = count(org_shop_group_seller_customer_orders($pendingLines));
        $out['paid'] = count(org_shop_group_seller_customer_orders(
            org_shop_list_orders($dbh, $orgId, 'paid', 500)
        ));

        $cancelGroups = org_shop_group_seller_customer_orders(
            org_shop_list_orders($dbh, $orgId, 'cancelled', 500),
            true
        );
        $sellerCancel = 0;
        $customerCancel = 0;
        foreach ($cancelGroups as $g) {
            $notesBlob = '';
            foreach ($g['lines'] as $line) {
                $notesBlob .= "\n" . (string)($line['buyer_notes'] ?? '') . "\n" . (string)($line['seller_notes'] ?? '');
            }
            $meta = org_shop_order_cancel_meta($notesBlob, '');
            if ((string)($meta['by'] ?? 'Customer') === 'Seller') {
                $sellerCancel++;
            } else {
                $customerCancel++;
            }
        }
        $out['cancel'] = $sellerCancel;
        $out['cancellation'] = $customerCancel;
        $out['cancelled'] = $sellerCancel + $customerCancel;

        $out['shipping'] = count(org_shop_group_seller_customer_orders(
            org_shop_list_orders($dbh, $orgId, 'shipped', 500)
        ));
        // Delivery = recently received (so the seller reviews confirmations, not all history).
        $deliveredLines = org_shop_list_orders($dbh, $orgId, 'delivered', 200);
        $recent = [];
        $cutoff = time() - (30 * 24 * 60 * 60);
        foreach ($deliveredLines as $line) {
            $when = (string)($line['delivered_at'] ?? $line['updated_at'] ?? $line['created_at'] ?? '');
            $ts = $when !== '' ? (int)strtotime($when) : 0;
            if ($ts >= $cutoff || $ts === 0) {
                $recent[] = $line;
            }
        }
        $out['delivery'] = count(org_shop_group_seller_customer_orders($recent));
    } catch (Throwable $e) {
        // keep zeros
    }
    return $out;
}

function org_sales_notifications(PDO $dbh, int $orgId): array
{
    $alerts = [];
    $stats = org_ecommerce_dashboard_stats($dbh, $orgId);
    $life = org_sales_order_lifecycle_counts($dbh, $orgId);

    try {
        if ((int)$life['pending'] > 0) {
            $alerts[] = [
                'type' => 'Pending',
                'message' => (int)$life['pending'] . ' order(s) awaiting payment or confirmation. Status stays pending until the customer pays — then it moves to Paid automatically.',
                'action' => 'sales_management.php#orders',
                'count' => (int)$life['pending'],
                'lifecycle' => true,
            ];
        }
        if ((int)$life['paid'] > 0) {
            $alerts[] = [
                'type' => 'Paid',
                'message' => (int)$life['paid'] . ' paid order(s) — payment confirmed. Ship now: add Carrier + Tracking (status becomes Shipping automatically). Your responsibility until packages leave.',
                'action' => 'sales_management.php#orders',
                'count' => (int)$life['paid'],
                'lifecycle' => true,
            ];
        }
        if ((int)$life['cancel'] > 0) {
            $alerts[] = [
                'type' => 'Cancel',
                'message' => (int)$life['cancel'] . ' order(s) you cancelled (seller Cancel) — card issue, stock, or other seller reason. Review Cancel orders table and keep the customer informed.',
                'action' => 'sales_management.php#table_cancel_orders',
                'count' => (int)$life['cancel'],
                'lifecycle' => true,
            ];
        }
        if ((int)$life['cancellation'] > 0) {
            $alerts[] = [
                'type' => 'Cancellation',
                'message' => (int)$life['cancellation'] . ' customer Cancellation(s) — the buyer cancelled their own order. Review reasons in Cancel orders table so nothing is missed.',
                'action' => 'sales_management.php#table_cancel_orders',
                'count' => (int)$life['cancellation'],
                'lifecycle' => true,
            ];
        }
        if ((int)$life['shipping'] > 0) {
            $alerts[] = [
                'type' => 'Shipping',
                'message' => (int)$life['shipping'] . ' order(s) shipping (in transit). Customers were notified. Mark Delivery when the customer receives the package.',
                'action' => 'sales_management.php#delivery-shipping',
                'count' => (int)$life['shipping'],
                'lifecycle' => true,
            ];
        }
        if ((int)$life['delivery'] > 0) {
            $alerts[] = [
                'type' => 'Delivery',
                'message' => (int)$life['delivery'] . ' recently delivered order(s) — receipt confirmed. Keep records; customers were notified.',
                'action' => 'sales_management.php#orders',
                'count' => (int)$life['delivery'],
                'lifecycle' => true,
            ];
        }
    } catch (Throwable $e) {
        $ordersOpen = (int)$stats['orders_open'];
        if ($ordersOpen > 0) {
            $alerts[] = [
                'type' => 'Orders',
                'message' => $ordersOpen . ' order(s) need confirmation, payment, or fulfillment.',
                'action' => 'sales_management.php#orders',
                'count' => $ordersOpen,
            ];
        }
    }

    $lowStock = (int)$stats['products_low_stock'];
    $inv = ['low' => $lowStock, 'sold_out' => 0, 'draft' => 0, 'total' => $lowStock];
    try {
        if (!function_exists('org_shop_inventory_status_counts')) {
            require_once dirname(__DIR__, 2) . '/public_user/includes/org_shop.php';
        }
        $inv = org_shop_inventory_status_counts($dbh, $orgId);
        $lowStock = (int)$inv['low'];
    } catch (Throwable $e) {
        // keep stats-based low stock
    }
    if ((int)$inv['total'] > 0) {
        $meta = [];
        if ((int)$inv['low'] > 0) {
            $meta[] = [
                'label' => (int)$inv['low'] . ' Product' . ((int)$inv['low'] === 1 ? '' : 's') . ' low',
                'count' => (int)$inv['low'],
            ];
        }
        if ((int)$inv['sold_out'] > 0) {
            $meta[] = [
                'label' => (int)$inv['sold_out'] === 1
                    ? 'Sold Out'
                    : ((int)$inv['sold_out'] . ' Sold Out'),
                'count' => (int)$inv['sold_out'],
            ];
        }
        if ((int)$inv['draft'] > 0) {
            $meta[] = [
                'label' => (int)$inv['draft'] === 1
                    ? 'Draft'
                    : ((int)$inv['draft'] . ' Draft'),
                'count' => (int)$inv['draft'],
            ];
        }
        $parts = array_map(static function (array $row): string {
            return (string)$row['label'];
        }, $meta);
        $alerts[] = [
            'type' => 'Inventory',
            'message' => (int)$inv['low'] > 0
                ? ('Products are now low in stock — ' . (int)$inv['low']
                    . ' product(s) have less than 5 units. Sold-out items leave the public shop automatically. Restock soon.')
                : ('Inventory needs attention — sold-out items leave the public shop; drafts stay unpublished until activated.'),
            'action' => 'sales_management.php#product-table',
            'count' => max(1, (int)$inv['total']),
            'meta' => $meta,
            'detail' => implode(' · ', $parts),
        ];
    }
    try {
        $st = $dbh->prepare("SELECT COUNT(*) FROM org_order_returns WHERE org_id = :org AND status = 'requested'");
        $st->execute([':org' => $orgId]);
        $openReturns = (int)($st->fetchColumn() ?: 0);
        if ($openReturns > 0) {
            $alerts[] = [
                'type' => 'Return / refund',
                'message' => $openReturns . ' buyer return request(s) waiting for review.',
                'action' => 'sales_management.php#returns-refunds',
                'count' => $openReturns,
            ];
        }
    } catch (Throwable $e) {
        // table may not exist yet
    }
    $payments = org_sales_payment_totals($dbh, $orgId);
    $openInvoices = (int)$payments['open_invoices'];
    if ($openInvoices > 0) {
        $alerts[] = [
            'type' => 'Payments',
            'message' => $openInvoices . ' invoice(s) are still outstanding.',
            'action' => 'invoices.php',
            'count' => $openInvoices,
        ];
    }
    $quotes = org_crm_lifecycle_stats($dbh, $orgId);
    $quotesOpen = (int)$quotes['quotes_open'];
    if ($quotesOpen > 0) {
        $alerts[] = [
            'type' => 'Quotations',
            'message' => $quotesOpen . ' quote(s) are open.',
            'action' => 'quotations.php',
            'count' => $quotesOpen,
        ];
    }
    return $alerts;
}

/**
 * Chronological commerce event feed for seller Notification panel.
 *
 * @return list<array{type:string,title:string,message:string,when:string,action:string,sort:int}>
 */
function org_sales_commerce_event_feed(PDO $dbh, int $orgId, int $limit = 40): array
{
    $feed = [];
    if ($orgId <= 0) {
        return $feed;
    }
    $limit = max(1, min(100, $limit));
    if (!function_exists('org_shop_list_orders')) {
        require_once dirname(__DIR__, 2) . '/public_user/includes/org_shop.php';
    }

    try {
        $cancelGroups = org_shop_group_seller_customer_orders(
            org_shop_list_orders($dbh, $orgId, 'cancelled', 200),
            true
        );
        foreach ($cancelGroups as $g) {
            $notesBlob = '';
            foreach ($g['lines'] as $line) {
                $notesBlob .= "\n" . (string)($line['buyer_notes'] ?? '') . "\n" . (string)($line['seller_notes'] ?? '');
            }
            $meta = org_shop_order_cancel_meta($notesBlob, '');
            $whenRaw = (string)($g['updated_at'] ?? $g['created_at'] ?? $g['date_raw'] ?? '');
            if ($whenRaw === '' && !empty($g['lines'][0]['updated_at'])) {
                $whenRaw = (string)$g['lines'][0]['updated_at'];
            }
            if ($whenRaw === '' && !empty($g['lines'][0]['created_at'])) {
                $whenRaw = (string)$g['lines'][0]['created_at'];
            }
            $sort = $whenRaw !== '' ? (int)strtotime($whenRaw) : (int)($g['date_sort'] ?? 0);
            $products = implode(', ', $g['product_titles'] ?? []);
            $feed[] = [
                'type' => ((string)$meta['by'] === 'Seller') ? 'Cancel' : 'Cancellation',
                'title' => ((string)$meta['by'] === 'Seller' ? 'Seller Cancel' : 'Customer Cancellation') . ' · ' . (string)($g['buyer_name'] ?? 'Customer'),
                'message' => ($products !== '' ? $products . ' · ' : '') . 'Reason: ' . (string)$meta['reason']
                    . ' · ' . (string)($g['total_label'] ?? ''),
                'when' => $whenRaw !== '' ? date('M j, Y g:i A', $sort ?: time()) : (string)($g['date_label'] ?? ''),
                'action' => 'sales_management.php#table_cancel_orders',
                'sort' => $sort,
            ];
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        foreach ([
            'pending' => 'Pending',
            'confirmed' => 'Pending',
            'paid' => 'Paid',
            'shipped' => 'Shipping',
            'delivered' => 'Delivery',
        ] as $status => $type) {
            $lines = org_shop_list_orders($dbh, $orgId, $status, 80);
            $groups = org_shop_group_seller_customer_orders($lines);
            foreach ($groups as $g) {
                $whenRaw = '';
                if (!empty($g['lines'][0]['updated_at'])) {
                    $whenRaw = (string)$g['lines'][0]['updated_at'];
                } elseif (!empty($g['lines'][0]['created_at'])) {
                    $whenRaw = (string)$g['lines'][0]['created_at'];
                }
                $sort = $whenRaw !== '' ? (int)strtotime($whenRaw) : (int)($g['date_sort'] ?? 0);
                $products = implode(', ', $g['product_titles'] ?? []);
                $titleMap = [
                    'pending' => 'Pending — awaiting payment',
                    'confirmed' => 'Pending — awaiting payment',
                    'paid' => 'Paid — ready to ship',
                    'shipped' => 'Shipping — in transit',
                    'delivered' => 'Delivery confirmed',
                ];
                $action = in_array($status, ['shipped', 'delivered'], true)
                    ? 'sales_management.php#delivery-shipping'
                    : 'sales_management.php#orders';
                $feed[] = [
                    'type' => $type,
                    'title' => ($titleMap[$status] ?? ucfirst($status)) . ' · ' . (string)($g['buyer_name'] ?? 'Customer'),
                    'message' => ($products !== '' ? $products . ' · ' : '') . (string)($g['total_label'] ?? ''),
                    'when' => $whenRaw !== '' ? date('M j, Y g:i A', $sort ?: time()) : (string)($g['date_label'] ?? ''),
                    'action' => $action,
                    'sort' => $sort,
                ];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $st = $dbh->prepare("
            SELECT id, order_id, reason, status, created_at, updated_at
            FROM org_order_returns
            WHERE org_id = :org
            ORDER BY COALESCE(updated_at, created_at) DESC
            LIMIT 40
        ");
        $st->execute([':org' => $orgId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ret) {
            $whenRaw = (string)($ret['updated_at'] ?? $ret['created_at'] ?? '');
            $sort = $whenRaw !== '' ? (int)strtotime($whenRaw) : 0;
            $feed[] = [
                'type' => 'Return / refund',
                'title' => 'Return ' . (string)($ret['status'] ?? 'requested'),
                'message' => trim((string)($ret['reason'] ?? '')) !== ''
                    ? (string)$ret['reason']
                    : ('Order #' . (int)($ret['order_id'] ?? 0)),
                'when' => $whenRaw !== '' ? date('M j, Y g:i A', $sort ?: time()) : '',
                'action' => 'sales_management.php#returns-refunds',
                'sort' => $sort,
            ];
        }
    } catch (Throwable $e) {
        // table may not exist
    }

    usort($feed, static function (array $a, array $b): int {
        return ((int)$b['sort']) <=> ((int)$a['sort']);
    });
    return array_slice($feed, 0, $limit);
}

/**
 * Actionable seller attention counts for header + sales workflow badges.
 *
 * @return array{
 *   total:int,
 *   orders:int,
 *   delivery:int,
 *   products:int,
 *   customers:int,
 *   returns:int,
 *   notification:int
 * }
 */
function org_sales_attention_counts(PDO $dbh, int $orgId): array
{
    $out = [
        'total' => 0,
        'orders' => 0,
        'delivery' => 0,
        'products' => 0,
        'customers' => 0,
        'returns' => 0,
        'notification' => 0,
    ];
    if ($orgId <= 0) {
        return $out;
    }

    try {
        org_ecommerce_ensure_schema($dbh);
    } catch (Throwable $e) {
        // continue with best-effort queries
    }

    try {
        // OMS badge = customer rows needing attention (same grouping as orders.php).
        // One buyer with bowl+burger = 1, two separate customer purchases = 2.
        if (!function_exists('org_shop_list_orders')) {
            require_once dirname(__DIR__, 2) . '/public_user/includes/org_shop.php';
        }
        $attentionLines = array_merge(
            org_shop_list_orders($dbh, $orgId, 'pending', 500),
            org_shop_list_orders($dbh, $orgId, 'confirmed', 500)
        );
        $out['orders'] = count(org_shop_group_seller_customer_orders($attentionLines));
    } catch (Throwable $e) {
        $out['orders'] = 0;
    }

    try {
        // Delivery badge = customer rows with paid orders ready to ship.
        if (!function_exists('org_shop_list_orders')) {
            require_once dirname(__DIR__, 2) . '/public_user/includes/org_shop.php';
        }
        $paidLines = org_shop_list_orders($dbh, $orgId, 'paid', 500);
        $out['delivery'] = count(org_shop_group_seller_customer_orders($paidLines));
    } catch (Throwable $e) {
        $out['delivery'] = 0;
    }

    try {
        // Product table risks: low stock (< 5 units) + newly created catalog items (7 days).
        $st = $dbh->prepare("
            SELECT COUNT(*)
            FROM org_products
            WHERE org_id = :org
              AND is_deleted = 0
              AND status = 'active'
              AND (
                    (stock_qty IS NOT NULL AND stock_qty < 5)
                 OR created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              )
        ");
        $st->execute([':org' => $orgId]);
        $out['products'] = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        try {
            $st = $dbh->prepare("
                SELECT COUNT(*)
                FROM org_products
                WHERE org_id = :org
                  AND is_deleted = 0
                  AND status = 'active'
                  AND stock_qty IS NOT NULL
                  AND stock_qty < 5
            ");
            $st->execute([':org' => $orgId]);
            $out['products'] = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e2) {
            $out['products'] = 0;
        }
    }

    try {
        org_crm_ensure_schema($dbh);
        // New CRM customers in the last 7 days.
        $st = $dbh->prepare("
            SELECT COUNT(*)
            FROM org_crm_contacts
            WHERE org_id = :org
              AND is_deleted = 0
              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $st->execute([':org' => $orgId]);
        $newCrm = (int)($st->fetchColumn() ?: 0);

        // Buyers with recent purchases who are not yet in CRM.
        $st = $dbh->prepare("
            SELECT COUNT(DISTINCT o.buyer_email)
            FROM org_orders o
            LEFT JOIN org_crm_contacts c
              ON c.org_id = o.org_id
             AND c.is_deleted = 0
             AND LOWER(TRIM(c.email)) = LOWER(TRIM(o.buyer_email))
            WHERE o.org_id = :org
              AND o.status <> 'cancelled'
              AND o.buyer_email IS NOT NULL
              AND TRIM(o.buyer_email) <> ''
              AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND c.id IS NULL
        ");
        $st->execute([':org' => $orgId]);
        $unsyncedBuyers = (int)($st->fetchColumn() ?: 0);
        $out['customers'] = $newCrm + $unsyncedBuyers;
    } catch (Throwable $e) {
        $out['customers'] = 0;
    }

    try {
        $st = $dbh->prepare("SELECT COUNT(*) FROM org_order_returns WHERE org_id = :org AND status = 'requested'");
        $st->execute([':org' => $orgId]);
        $out['returns'] = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $out['returns'] = 0;
    }

    try {
        // Notification badge = full order-lifecycle workload + open returns.
        $life = org_sales_order_lifecycle_counts($dbh, $orgId);
        $out['notification'] = (int)$life['pending']
            + (int)$life['paid']
            + (int)$life['cancel']
            + (int)$life['cancellation']
            + (int)$life['shipping']
            + (int)$out['returns'];
    } catch (Throwable $e) {
        $out['notification'] = (int)$out['returns'] + (int)$out['delivery'];
    }

    $out['total'] = (int)$out['orders']
        + (int)$out['products']
        + (int)$out['customers']
        + (int)$out['notification'];

    return $out;
}
