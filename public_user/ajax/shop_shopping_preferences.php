<?php
declare(strict_types=1);

/**
 * JSON Shopping Preferences hub — mirrors Your_Shopping_preferences.php for mobile.
 * GET panel=dashboard|order-history|order-details|wishlist|invoices-payments|
 *     returns-refunds|seller-relationships|messages|reviews-ratings|addresses|
 *     notifications|membership|loyalty-program|support-tickets|documents|support-center|
 *     guidance-center|account-update
 */

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/org_cart.php';
require_once __DIR__ . '/../includes/org_wishlist.php';
require_once __DIR__ . '/../includes/buyer_shipping.php';
require_once __DIR__ . '/../includes/buyer_membership.php';
require_once __DIR__ . '/../includes/stripe_shop.php';
require_once __DIR__ . '/../includes/buyer_seller_relationship.php';
require_once __DIR__ . '/../includes/commerce_messaging.php';
require_once __DIR__ . '/../includes/admin_support_chat.php';
require_once __DIR__ . '/../includes/user_phone.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $controller = new Controller();
    $dbh = $controller->pdo();
    $meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
    // Local MAMP / Simulator: allow the signed-in mobile user id on loopback only.
    if ($meId <= 0) {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $isLoopback = in_array($remote, ['127.0.0.1', '::1'], true)
            || str_starts_with($remote, '127.');
        if ($isLoopback) {
            $meId = (int)($_SERVER['HTTP_X_SHOP_USER_ID'] ?? $_GET['user_id'] ?? 0);
            if ($meId > 0) {
                $_SESSION['user_id'] = $meId;
            }
        }
    }
    if ($meId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
        exit;
    }

    $panel = strtolower(trim((string)($_GET['panel'] ?? $_POST['panel'] ?? 'dashboard')));
    if ($panel === '' || $panel === 'customer-dashboard') {
        $panel = 'dashboard';
    }

    $fmtMoney = static function (int $cents, string $currency = 'USD'): string {
        return org_shop_format_price($cents, $currency);
    };
    $fmtDate = static function ($value): string {
        if ($value === null || $value === '') {
            return '—';
        }
        $t = strtotime((string)$value);
        return $t ? date('M j, Y', $t) : '—';
    };

    // Profile
    $buyerProfile = ['name' => '', 'username' => '', 'email' => '', 'phone' => ''];
    try {
        $st = $dbh->prepare('SELECT name, username, email, mobile FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $meId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $buyerProfile['name'] = trim((string)($row['name'] ?? ''));
        $buyerProfile['username'] = trim((string)($row['username'] ?? ''));
        $buyerProfile['email'] = trim((string)($row['email'] ?? ''));
        $buyerProfile['phone'] = function_exists('user_phone_from_user_row')
            ? user_phone_from_user_row($row)
            : trim((string)($row['mobile'] ?? ''));
        if (strcasecmp($buyerProfile['phone'], 'N/A') === 0) {
            $buyerProfile['phone'] = '';
        }
    } catch (Throwable $e) {
    }
    $displayName = $buyerProfile['name'] !== ''
        ? $buyerProfile['name']
        : ($buyerProfile['username'] !== '' ? $buyerProfile['username'] : 'Customer');

    // Orders / spend (line stats) + seller+day groups for My Orders (web #order-history parity)
    $buyerOrders = org_shop_list_buyer_orders($dbh, $meId, 200);
    $buyerOrderCount = 0;
    $buyerSpentCents = 0;
    $buyerOpenOrders = 0;
    $orderRowsFlat = [];
    foreach ($buyerOrders as $buyerOrder) {
        $status = strtolower(trim((string)($buyerOrder['status'] ?? '')));
        if ($status === 'cancelled') {
            continue;
        }
        $buyerOrderCount++;
        $totalCents = (int)($buyerOrder['total_cents'] ?? 0);
        $buyerSpentCents += $totalCents;
        if (in_array($status, ['pending', 'confirmed', 'paid', 'shipped'], true)) {
            $buyerOpenOrders++;
        }
        $currency = strtoupper((string)($buyerOrder['currency'] ?? 'USD'));
        $qty = max(1, (int)($buyerOrder['quantity'] ?? 1));
        $orderRowsFlat[] = [
            'id' => (int)($buyerOrder['id'] ?? 0),
            'order_code' => (string)($buyerOrder['order_code'] ?? ''),
            'status' => $status !== '' ? $status : 'pending',
            'seller' => trim((string)($buyerOrder['seller_name'] ?? 'Shop')),
            'product_title' => (string)($buyerOrder['product_title'] ?? 'Product'),
            'product_id' => (int)($buyerOrder['product_id'] ?? 0),
            'quantity' => $qty,
            'total_cents' => $totalCents,
            'total_label' => $fmtMoney($totalCents, $currency),
            'date' => $fmtDate($buyerOrder['created_at'] ?? ''),
            'created_at' => (string)($buyerOrder['created_at'] ?? ''),
            'org_id' => (int)($buyerOrder['org_id'] ?? 0),
            'cover_url' => org_shop_cover_url((string)($buyerOrder['cover_image_path'] ?? '')),
            'receipt_code' => trim((string)($buyerOrder['receipt_code'] ?? '')),
            'can_cancel' => function_exists('org_shop_buyer_order_is_cancellable')
                ? org_shop_buyer_order_is_cancellable($buyerOrder)
                : false,
            'is_order_group' => false,
        ];
    }
    $orderGroupRows = [];
    if (function_exists('org_shop_buyer_order_history_groups')) {
        $orderGroupRows = org_shop_buyer_order_history_groups($dbh, $meId, 200);
    }
    // Default list rows: grouped invoice rows (web parity). Fall back to flat lines.
    $orderRows = $orderGroupRows !== [] ? $orderGroupRows : $orderRowsFlat;
    $buyerOrderGroupCount = count($orderGroupRows) > 0 ? count($orderGroupRows) : count($orderRowsFlat);

    $buyerCartCount = org_cart_count($dbh, $meId);
    $buyerWishlistItems = org_wishlist_list($dbh, $meId, 100);
    $buyerWishlistCount = count($buyerWishlistItems);
    $buyerAddresses = buyer_shipping_list($dbh, $meId);

    buyer_membership_ensure_schema($dbh);
    $membershipSnap = buyer_membership_snapshot($dbh, $meId) ?: [];
    $membershipActive = !empty($membershipSnap['is_active']);

    $notifCount = 0;
    $notifRows = [];
    if (function_exists('org_shop_buyer_commerce_notification_feed')) {
        $notifRows = org_shop_buyer_commerce_notification_feed($dbh, $meId, 40) ?: [];
        $notifCount = count($notifRows);
    }

    // Web #notifications: lifecycle counts + "need attention" alert cards + tab counts.
    $notifLife = function_exists('org_shop_buyer_order_lifecycle_counts')
        ? org_shop_buyer_order_lifecycle_counts($dbh, $meId)
        : [];
    foreach (['pending', 'paid', 'cancel', 'cancellation', 'shipping', 'delivery'] as $lifeKey) {
        $notifLife[$lifeKey] = (int)($notifLife[$lifeKey] ?? 0);
    }
    $notifAlerts = function_exists('org_shop_buyer_commerce_alerts')
        ? (org_shop_buyer_commerce_alerts($dbh, $meId) ?: [])
        : [];
    $notifBadgeCount = $notifLife['pending'] + $notifLife['paid'] + $notifLife['cancel']
        + $notifLife['cancellation'] + $notifLife['shipping'];
    $notifReturnAlertCount = 0;
    foreach ($notifAlerts as $na) {
        $naType = strtolower((string)($na['type'] ?? ''));
        if ($naType === 'return / refund') {
            $notifBadgeCount += (int)($na['count'] ?? 0);
        }
        if (strpos($naType, 'return') !== false) {
            $notifReturnAlertCount += (int)($na['count'] ?? 0);
        }
    }
    $notifTypeKey = static function (string $type): string {
        $t = strtolower(trim($type));
        if ($t === '' || strpos($t, 'return') !== false || strpos($t, 'refund') !== false) {
            return 'returns';
        }
        if ($t === 'cancel' || $t === 'cancellation') {
            return 'cancelled';
        }
        if (in_array($t, ['pending', 'paid', 'shipping', 'delivery'], true)) {
            return $t;
        }
        return 'update';
    };
    $notifOrderRef = static function (string $action): array {
        $oid = 0;
        $code = '';
        if ($action !== '' && preg_match('/order_id=(\d+)/', $action, $m)) {
            $oid = (int)$m[1];
        }
        if ($action !== '' && preg_match('/[?&]code=([^&#]+)/', $action, $m)) {
            $code = rawurldecode((string)$m[1]);
        }
        return [$oid, $code];
    };

    $sellerMsgUnread = 0;
    $sellerMsgContacts = [];
    if (function_exists('commerce_list_buyer_seller_contacts')) {
        try {
            $sellerMsgContacts = commerce_list_buyer_seller_contacts($dbh, $meId) ?: [];
            foreach ($sellerMsgContacts as $c) {
                $sellerMsgUnread += max(0, (int)($c['unread'] ?? 0));
            }
        } catch (Throwable $e) {
        }
    }

    $supportMsgUnread = 0;
    if (function_exists('admin_support_user_email') && function_exists('admin_support_unread_count')) {
        try {
            $supportEmail = admin_support_user_email($dbh, $meId);
            if ($supportEmail !== '') {
                $supportMsgUnread = admin_support_unread_count($dbh, $supportEmail);
            }
        } catch (Throwable $e) {
            $supportMsgUnread = 0;
        }
    }

    $returnRows = [];
    try {
        $st = $dbh->prepare("
            SELECT r.*, o.order_code, o.product_id, o.quantity, o.total_cents, o.currency,
                   COALESCE(NULLIF(TRIM(o.product_title), ''), p.title, 'Product') AS product_title,
                   p.cover_image_path,
                   org.name AS seller_name
            FROM org_order_returns r
            LEFT JOIN org_orders o ON o.id = r.order_id
            LEFT JOIN org_products p ON p.id = o.product_id
            LEFT JOIN organizations org ON org.id = COALESCE(o.org_id, r.org_id)
            WHERE r.buyer_user_id = :uid
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 30
        ");
        $st->execute([':uid' => $meId]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $returnRows[] = [
                'id' => (int)($r['id'] ?? 0),
                'order_id' => (int)($r['order_id'] ?? 0),
                'order_code' => (string)($r['order_code'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'reason' => (string)($r['reason'] ?? ''),
                'date' => $fmtDate($r['created_at'] ?? ''),
                'product_id' => (int)($r['product_id'] ?? 0),
                'product_title' => (string)($r['product_title'] ?? 'Product'),
                'quantity' => max(1, (int)($r['quantity'] ?? 1)),
                'total_label' => $fmtMoney((int)($r['total_cents'] ?? 0), strtoupper((string)($r['currency'] ?? 'USD'))),
                'cover_url' => org_shop_cover_url((string)($r['cover_image_path'] ?? '')),
                'seller' => trim((string)($r['seller_name'] ?? '')) ?: 'Shop',
            ];
        }
    } catch (Throwable $e) {
    }

    $reviewRows = [];
    try {
        $st = $dbh->prepare('
            SELECT r.*, p.title AS product_title, p.cover_image_path,
                   o.order_code, o.status AS order_status, o.quantity AS order_quantity,
                   o.created_at AS order_created_at, org.name AS seller_name
            FROM org_product_reviews r
            LEFT JOIN org_products p ON p.id = r.product_id
            LEFT JOIN org_orders o ON o.id = r.order_id
            LEFT JOIN organizations org ON org.id = r.org_id
            WHERE r.buyer_user_id = :uid
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 30
        ');
        $st->execute([':uid' => $meId]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $reviewRows[] = [
                'id' => (int)($r['id'] ?? 0),
                'product_id' => (int)($r['product_id'] ?? 0),
                'order_id' => (int)($r['order_id'] ?? 0),
                'product_title' => (string)($r['product_title'] ?? 'Product'),
                'cover_image_path' => (string)($r['cover_image_path'] ?? ''),
                'order_code' => (string)($r['order_code'] ?? ''),
                'order_status' => strtolower(trim((string)($r['order_status'] ?? ''))),
                'order_quantity' => max(1, (int)($r['order_quantity'] ?? 1)),
                'order_date' => $fmtDate($r['order_created_at'] ?? ''),
                'seller' => trim((string)($r['seller_name'] ?? '')),
                'rating' => (int)($r['rating'] ?? 0),
                'body' => (string)($r['review_text'] ?? $r['body'] ?? $r['comment'] ?? ''),
                'date' => $fmtDate($r['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
    }

    $relationships = [];
    if (function_exists('buyer_seller_relationship_list')) {
        try {
            $relationships = buyer_seller_relationship_list($dbh, $meId) ?: [];
        } catch (Throwable $e) {
        }
    }

    $menuSubtitles = [
        'dashboard' => 'Overview of orders, spending, and cart.',
        'order-history' => 'See purchases, status, and receipts.',
        'wishlist' => 'Items you saved to buy later.',
        'returns-refunds' => 'Return an item or check a refund.',
        'messages' => 'Message sellers about products or orders.',
        'addresses' => 'Delivery addresses for checkout.',
        'notifications' => 'Order alerts and shop updates.',
        'reviews-ratings' => 'Reviews you left on products.',
        'membership' => 'Your plan and service-fee benefits.',
        'loyalty-program' => 'Reward points from shopping.',
        'support-center' => 'Talk to a live agent about an order, payment, or delivery issue.',
        'guidance-center' => 'Step-by-step: find products, cart, checkout, track, and returns.',
    ];

    // Customer journey order (duplicates like invoices/order-details stay out of the menu).
    $menu = [
        ['id' => 'dashboard', 'title' => 'Dashboard', 'icon' => 'gauge', 'badge' => 0, 'subtitle' => $menuSubtitles['dashboard']],
        ['id' => 'order-history', 'title' => 'My orders', 'icon' => 'list.bullet.rectangle', 'badge' => $buyerOpenOrders, 'subtitle' => $menuSubtitles['order-history']],
        ['id' => 'wishlist', 'title' => 'Wishlist', 'icon' => 'bookmark', 'badge' => $buyerWishlistCount, 'subtitle' => $menuSubtitles['wishlist']],
        ['id' => 'returns-refunds', 'title' => 'Returns & refunds', 'icon' => 'arrow.uturn.left', 'badge' => count(array_filter($returnRows, static fn($r) => strtolower((string)$r['status']) !== 'cancelled')), 'subtitle' => $menuSubtitles['returns-refunds']],
        ['id' => 'messages', 'title' => 'Messages', 'icon' => 'bubble.left.and.bubble.right', 'badge' => $sellerMsgUnread, 'subtitle' => $menuSubtitles['messages']],
        ['id' => 'addresses', 'title' => 'Addresses', 'icon' => 'mappin.and.ellipse', 'badge' => count($buyerAddresses), 'subtitle' => $menuSubtitles['addresses']],
        ['id' => 'notifications', 'title' => 'Notifications', 'icon' => 'bell', 'badge' => $notifBadgeCount, 'subtitle' => $menuSubtitles['notifications']],
        ['id' => 'reviews-ratings', 'title' => 'Reviews & ratings', 'icon' => 'star', 'badge' => count($reviewRows), 'subtitle' => $menuSubtitles['reviews-ratings']],
        ['id' => 'membership', 'title' => 'Membership' . ($membershipActive ? ' · Active' : ''), 'icon' => 'rosette', 'badge' => 0, 'subtitle' => $menuSubtitles['membership']],
        ['id' => 'loyalty-program', 'title' => 'Loyalty program', 'icon' => 'gift', 'badge' => 0, 'subtitle' => $menuSubtitles['loyalty-program']],
        ['id' => 'support-center', 'title' => 'Support Center', 'icon' => 'headphones', 'badge' => $supportMsgUnread, 'subtitle' => $menuSubtitles['support-center']],
    ];

    $preferenceLinks = [
        [
            'id' => 'guidance-center',
            'title' => 'Guidance Center',
            'subtitle' => $menuSubtitles['guidance-center'],
            'icon' => 'questionmark',
        ],
        [
            'id' => 'support-center',
            'title' => 'Support Center',
            'subtitle' => $menuSubtitles['support-center'],
            'icon' => 'headphones',
            'badge' => $supportMsgUnread,
        ],
    ];

    $recentOrders = [];
    foreach (array_slice($orderRowsFlat, 0, 5) as $ro) {
        $recentOrders[] = [
            'id' => (int)($ro['id'] ?? 0),
            'order_id' => (int)($ro['id'] ?? 0),
            'order_code' => (string)($ro['order_code'] ?? ''),
            'product_title' => (string)($ro['product_title'] ?? 'Product'),
            'product_id' => (int)($ro['product_id'] ?? 0),
            'cover_url' => (string)($ro['cover_url'] ?? ''),
            'total_label' => (string)($ro['total_label'] ?? '$0.00'),
            'seller' => (string)($ro['seller'] ?? ''),
            'status' => (string)($ro['status'] ?? ''),
            'date' => (string)($ro['date'] ?? ''),
            'free_shipping' => true,
        ];
    }

    $dashboard = [
        'name' => $displayName,
        'email' => $buyerProfile['email'],
        'phone' => $buyerProfile['phone'],
        'orders' => $buyerOrderCount,
        'spending_cents' => $buyerSpentCents,
        'spending_label' => $fmtMoney($buyerSpentCents),
        'cart_items' => $buyerCartCount,
        'active_orders' => $buyerOpenOrders,
        'wishlist_count' => $buyerWishlistCount,
        'recent_orders' => $recentOrders,
        'actions' => [
            ['id' => 'order-history', 'title' => 'Orders', 'icon' => 'list.bullet.rectangle'],
            ['id' => 'messages', 'title' => 'Messages', 'icon' => 'bubble.left.and.bubble.right'],
            ['id' => 'cart', 'title' => 'Cart', 'icon' => 'cart'],
            ['id' => 'support-center', 'title' => 'Support', 'icon' => 'headphones'],
        ],
    ];

    $panelPayload = ['id' => $panel, 'title' => '', 'subtitle' => '', 'rows' => []];

    switch ($panel) {
        case 'dashboard':
            $panelPayload['title'] = 'Customer dashboard';
            $panelPayload['subtitle'] = $displayName;
            break;

        case 'order-history':
        case 'order-details':
        case 'invoices-payments':
            $panelPayload['title'] = 'My orders';
            $gc = (int)$buyerOrderGroupCount;
            $panelPayload['subtitle'] = $gc . ' order' . ($gc === 1 ? '' : 's')
                . ' · Each row is one checkout · Quantity # is units · tap a row for invoice details';
            $panelPayload['group_count'] = $gc;
            $panelPayload['is_order_groups'] = $orderGroupRows !== [];
            foreach ($orderRows as $o) {
                $isGroup = !empty($o['is_order_group']);
                $statusRaw = (string)($o['status'] ?? 'pending');
                $statusLabel = (string)($o['status_label'] ?? '');
                if ($statusLabel === '') {
                    $st = strtolower(trim($statusRaw));
                    if ($st === 'shipped') {
                        $statusLabel = 'Shipp Tracking';
                    } elseif ($st === 'delivered') {
                        $statusLabel = 'Delivered';
                    } else {
                        $statusLabel = $statusRaw !== '' ? ucfirst($statusRaw) : 'pending';
                    }
                }
                $seller = (string)($o['seller'] ?? $o['company'] ?? $o['seller_name'] ?? 'Shop');
                $date = (string)($o['date'] ?? $o['date_label'] ?? '');
                $totalLabel = (string)($o['total_label'] ?? $o['total'] ?? '');
                $orderCode = (string)($o['order_code'] ?? $o['order_label'] ?? '');
                $productTitle = (string)($o['product_title'] ?? '');
                $qtyNum = (int)($o['quantity_num'] ?? $o['quantity'] ?? 0);
                $prodNum = (int)($o['order_num'] ?? $o['product_count'] ?? 0);
                $canCancel = !empty($o['can_cancel']) || !empty($o['cancellable']);
                $cancelLabel = $canCancel ? 'Cancel order' : '—';
                $stKey = strtolower(trim($statusRaw));
                $primaryProductId = (int)($o['primary_product_id'] ?? $o['product_id'] ?? 0);
                $viewProductLabel = trim((string)($o['view_product_label'] ?? ''));
                if ($viewProductLabel === '' && $primaryProductId > 0) {
                    $cat = trim((string)($o['category'] ?? $o['product_title'] ?? 'Item'));
                    $viewProductLabel = 'View product · ' . ($cat !== '' ? $cat : 'Item');
                }
                if ($viewProductLabel === '') {
                    $viewProductLabel = 'View product';
                }
                $canReturn = array_key_exists('can_return', $o)
                    ? !empty($o['can_return'])
                    : in_array($stKey, ['paid', 'shipped', 'delivered'], true);
                $canReview = array_key_exists('can_review', $o)
                    ? !empty($o['can_review'])
                    : ($stKey === 'delivered');
                $title = $isGroup
                    ? ('#' . (int)($o['group_index'] ?? $o['id'] ?? 0))
                    : ($orderCode !== '' ? $orderCode : ('Order #' . (int)($o['id'] ?? 0)));
                $subtitle = $isGroup
                    ? ($seller . ' · Qty ' . $qtyNum . ' · ' . $prodNum . ' product' . ($prodNum === 1 ? '' : 's'))
                    : ($seller . ' · ' . $productTitle);
                $rowId = $isGroup
                    ? ('group-' . (string)($o['group_index'] ?? $o['id'] ?? 0))
                    : (string)($o['id'] ?? 0);
                $panelPayload['rows'][] = array_merge($o, [
                    'id' => $rowId,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'meta' => $statusLabel . ' · ' . $date,
                    'value' => $totalLabel,
                    'order_id' => (int)($o['order_id'] ?? $o['id'] ?? 0),
                    'order_code' => $orderCode,
                    'cover_url' => (string)($o['cover_url'] ?? ''),
                    'product_title' => $productTitle,
                    'product_id' => $primaryProductId > 0 ? $primaryProductId : (int)($o['product_id'] ?? 0),
                    'primary_product_id' => $primaryProductId,
                    'view_product_label' => $viewProductLabel,
                    'can_return' => $canReturn,
                    'can_review' => $canReview,
                    'publisher_user_id' => (int)($o['publisher_user_id'] ?? 0),
                    'seller' => $seller,
                    'status' => $statusRaw,
                    'status_label' => $statusLabel,
                    'date' => $date,
                    'total_label' => $totalLabel,
                    'can_cancel' => $canCancel,
                    'cancellable' => $canCancel,
                    'cancel_label' => $cancelLabel,
                    'is_order_group' => $isGroup,
                    'quantity_num' => $qtyNum,
                    'order_num' => $prodNum,
                ]);
            }
            break;

        case 'wishlist':
            $panelPayload['title'] = 'Wishlist';
            $panelPayload['subtitle'] = $buyerWishlistCount . ' saved item' . ($buyerWishlistCount === 1 ? '' : 's');
            foreach ($buyerWishlistItems as $w) {
                $cents = (int)($w['price_cents'] ?? 0);
                $cur = strtoupper((string)($w['currency'] ?? 'USD'));
                $panelPayload['rows'][] = [
                    'id' => (string)((int)($w['product_id'] ?? $w['id'] ?? 0)),
                    'title' => (string)($w['title'] ?? 'Product'),
                    'subtitle' => (string)($w['seller'] ?? $w['seller_name'] ?? ''),
                    'meta' => '',
                    'value' => $fmtMoney($cents, $cur),
                    'cover_url' => org_shop_cover_url((string)($w['cover_image_path'] ?? $w['cover_url'] ?? '')),
                    'product_id' => (int)($w['product_id'] ?? $w['id'] ?? 0),
                ];
            }
            break;

        case 'returns-refunds':
            $panelPayload['title'] = 'Returns & refunds';
            $panelPayload['subtitle'] = count($returnRows) . ' order' . (count($returnRows) === 1 ? '' : 's');
            $returnStatusLabels = [
                'requested' => 'Pending',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'refunded' => 'Refunded',
                'cancelled' => 'Canceled',
            ];
            foreach ($returnRows as $r) {
                $retStatus = strtolower(trim((string)$r['status']));
                $panelPayload['rows'][] = [
                    'id' => (string)$r['id'],
                    'return_id' => (int)$r['id'],
                    'title' => $r['order_code'] !== '' ? $r['order_code'] : ('Return #' . $r['id']),
                    'subtitle' => $r['reason'] !== '' ? $r['reason'] : 'Return request',
                    'meta' => strtoupper($r['status']) . ' · ' . $r['date'],
                    'value' => $r['total_label'],
                    'total_label' => $r['total_label'],
                    'order_id' => $r['order_id'],
                    'order_code' => $r['order_code'],
                    'status' => $retStatus,
                    'status_label' => $returnStatusLabels[$retStatus] ?? ucfirst($retStatus),
                    'reason' => $r['reason'],
                    'requested_label' => 'Requested on ' . $r['date'],
                    'product_id' => $r['product_id'],
                    'product_title' => $r['product_title'],
                    'quantity_num' => $r['quantity'],
                    'cover_url' => $r['cover_url'],
                    'seller' => $r['seller'],
                    'can_cancel_return' => $retStatus === 'requested',
                ];
            }
            break;

        case 'seller-relationships':
            $panelPayload['title'] = 'Seller relationships';
            $panelPayload['subtitle'] = count($relationships) . ' seller' . (count($relationships) === 1 ? '' : 's');
            foreach ($relationships as $rel) {
                $panelPayload['rows'][] = [
                    'id' => (string)((int)($rel['org_id'] ?? $rel['id'] ?? 0)),
                    'title' => (string)($rel['store_name'] ?? $rel['seller_name'] ?? 'Seller'),
                    'subtitle' => (string)($rel['contact_email'] ?? ''),
                    'meta' => (string)($rel['notes'] ?? ''),
                    'value' => '',
                ];
            }
            break;

        case 'messages':
            $panelPayload['title'] = 'Messages';
            $panelPayload['subtitle'] = $sellerMsgUnread > 0 ? ($sellerMsgUnread . ' unread') : 'Seller conversations';
            $sellerCatSt = null;
            try {
                $sellerCatSt = $dbh->prepare("
                    SELECT TRIM(COALESCE(category, '')) AS category, COUNT(*) AS n
                    FROM org_products
                    WHERE org_id = :org AND is_deleted = 0 AND status = 'active'
                    GROUP BY TRIM(COALESCE(category, ''))
                    ORDER BY n DESC
                ");
            } catch (Throwable $e) {
                $sellerCatSt = null;
            }
            foreach ($sellerMsgContacts as $c) {
                if (!empty($c['pending_first_message'])) {
                    continue;
                }
                $sellerCategory = '';
                $sellerItemCount = 0;
                $sellerOrgId = (int)($c['org_id'] ?? 0);
                if ($sellerCatSt && $sellerOrgId > 0) {
                    try {
                        $sellerCatSt->execute([':org' => $sellerOrgId]);
                        foreach ($sellerCatSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $catRow) {
                            $sellerItemCount += (int)($catRow['n'] ?? 0);
                            $catName = trim((string)($catRow['category'] ?? ''));
                            if ($sellerCategory === '' && $catName !== '') {
                                $sellerCategory = ucwords(strtolower($catName));
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
                $panelPayload['rows'][] = [
                    'category' => $sellerCategory,
                    'item_count' => $sellerItemCount,
                    'verified' => 1,
                    'id' => (string)((int)($c['publisher_user_id'] ?? 0)),
                    'title' => (string)($c['seller_name'] ?? 'Seller'),
                    'subtitle' => (string)($c['last_message'] ?? ''),
                    'meta' => (string)($c['last_at'] ?? ''),
                    'value' => ((int)($c['unread'] ?? 0)) > 0 ? ((int)$c['unread'] . ' new') : '',
                    'publisher_id' => (int)($c['publisher_user_id'] ?? 0),
                    'friend_code' => (string)($c['friend_code'] ?? ''),
                    'unread' => (int)($c['unread'] ?? 0),
                ];
            }
            break;

        case 'reviews-ratings':
            $panelPayload['title'] = 'Reviews & ratings';
            $panelPayload['subtitle'] = count($reviewRows) . ' review' . (count($reviewRows) === 1 ? '' : 's');
            $productReviewSt = null;
            try {
                $productReviewSt = $dbh->prepare("
                    SELECT r.id, r.rating, r.review_text, r.created_at, r.buyer_user_id,
                           COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), 'Buyer') AS buyer_name
                    FROM org_product_reviews r
                    LEFT JOIN users u ON u.id = r.buyer_user_id
                    WHERE r.product_id = :pid
                    ORDER BY r.created_at DESC, r.id DESC
                    LIMIT 30
                ");
            } catch (Throwable $e) {
                $productReviewSt = null;
            }
            $shortName = static function (string $name): string {
                $parts = preg_split('/\s+/', trim($name)) ?: [];
                $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
                if ($parts === []) {
                    return 'Buyer';
                }
                $first = ucfirst($parts[0]);
                return count($parts) > 1 ? ($first . ' ' . strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) . '.') : $first;
            };
            $reviewStatusLabels = [
                'delivered' => 'Completed', 'completed' => 'Completed', 'shipped' => 'Shipped',
                'paid' => 'Paid', 'pending' => 'Pending', 'confirmed' => 'Confirmed',
                'cancelled' => 'Canceled', 'returned' => 'Returned',
            ];
            foreach ($reviewRows as $r) {
                $productReviews = [];
                if ($productReviewSt && $r['product_id'] > 0) {
                    try {
                        $productReviewSt->execute([':pid' => $r['product_id']]);
                        foreach ($productReviewSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pr) {
                            $prName = $shortName((string)($pr['buyer_name'] ?? 'Buyer'));
                            $productReviews[] = [
                                'id' => (int)($pr['id'] ?? 0),
                                'name' => $prName,
                                'initial' => strtoupper(mb_substr($prName, 0, 1)),
                                'rating' => max(0, min(5, (int)($pr['rating'] ?? 0))),
                                'body' => trim((string)($pr['review_text'] ?? '')),
                                'date' => $fmtDate($pr['created_at'] ?? ''),
                                'sort_ts' => (int)(strtotime((string)($pr['created_at'] ?? '')) ?: 0),
                                'is_mine' => (int)($pr['buyer_user_id'] ?? 0) === $meId,
                            ];
                        }
                    } catch (Throwable $e) {
                    }
                }
                $orderStatus = (string)$r['order_status'];
                $panelPayload['rows'][] = [
                    'id' => (string)$r['id'],
                    'title' => $r['product_title'],
                    'subtitle' => $r['body'],
                    'meta' => ($r['rating'] > 0 ? (str_repeat('★', min(5, $r['rating'])) . ' · ') : '') . $r['date'],
                    'value' => $r['order_code'],
                    'review_id' => $r['id'],
                    'order_id' => $r['order_id'],
                    'product_id' => $r['product_id'],
                    'product_title' => $r['product_title'],
                    'cover_url' => function_exists('org_shop_cover_url') ? org_shop_cover_url($r['cover_image_path']) : '',
                    'order_code' => $r['order_code'],
                    'order_date' => $r['order_date'],
                    'order_status' => $orderStatus,
                    'order_status_label' => $reviewStatusLabels[$orderStatus] ?? ($orderStatus !== '' ? ucfirst($orderStatus) : ''),
                    'seller' => $r['seller'],
                    'quantity' => $r['order_quantity'],
                    'rating' => $r['rating'],
                    'body' => $r['body'],
                    'date' => $r['date'],
                    'product_reviews' => $productReviews,
                ];
            }
            break;

        case 'addresses':
            $panelPayload['title'] = 'Addresses';
            $addrCount = count($buyerAddresses);
            $panelPayload['subtitle'] = $addrCount . ' address' . ($addrCount === 1 ? '' : 'es');

            // Web #addresses: Contact card + default Shipping address card + one edit form.
            $addrEdit = buyer_shipping_default_row($dbh, $meId) ?? [];
            $addrEditId = (int)($addrEdit['id'] ?? 0);
            $addrEditName = trim((string)($addrEdit['full_name'] ?? ''));
            if ($addrEditName === '') {
                $addrEditName = $displayName;
            }
            $addrEditPhone = $buyerProfile['phone'];
            $addrShipPhone = trim((string)($addrEdit['phone'] ?? ''));
            if ($addrShipPhone !== '') {
                $addrEditPhone = $addrShipPhone;
            }
            $addrHasShipping = $addrEditId > 0 && trim((string)($addrEdit['line1'] ?? '')) !== '';
            $panelPayload['kicker'] = 'Addresses';
            $panelPayload['heading'] = 'Billing & shipping';
            $panelPayload['intro'] = 'Your contact and shipping details for seller checkout. Edit to update, then save.';
            $panelPayload['contact'] = [
                'name' => $displayName,
                'email' => $buyerProfile['email'],
                'phone' => $buyerProfile['phone'],
            ];
            $panelPayload['shipping'] = [
                'has_address' => $addrHasShipping,
                'address_id' => $addrEditId,
                'label' => trim((string)($addrEdit['label'] ?? '')) ?: 'Home',
                'is_default' => !empty($addrEdit['is_default']),
                'text' => $addrHasShipping ? buyer_shipping_format_text($addrEdit) : '',
                'form' => [
                    'address_id' => $addrEditId,
                    'label' => trim((string)($addrEdit['label'] ?? '')) ?: 'Home',
                    'full_name' => $addrEditName,
                    'line1' => trim((string)($addrEdit['line1'] ?? '')),
                    'line2' => trim((string)($addrEdit['line2'] ?? '')),
                    'city' => trim((string)($addrEdit['city'] ?? '')),
                    'region' => trim((string)($addrEdit['region'] ?? '')),
                    'postal_code' => trim((string)($addrEdit['postal_code'] ?? '')),
                    'country' => trim((string)($addrEdit['country'] ?? '')) ?: 'US',
                    'phone' => $addrEditPhone,
                    'is_default' => $addrEditId <= 0 || !empty($addrEdit['is_default']),
                ],
            ];
            $addrNorm = static function (string $s): string {
                return (string)preg_replace('/[^a-z0-9]+/', '', strtolower($s));
            };
            foreach ($buyerAddresses as $a) {
                $label = trim((string)($a['label'] ?? 'Address'));
                $line = function_exists('buyer_shipping_format_door_text')
                    ? buyer_shipping_format_door_text($a)
                    : trim(implode(', ', array_filter([
                        (string)($a['line1'] ?? $a['street'] ?? ''),
                        (string)($a['city'] ?? ''),
                        (string)($a['state'] ?? ''),
                        (string)($a['postal'] ?? ''),
                    ])));
                $line1 = trim((string)($a['line1'] ?? ''));
                $line2 = trim((string)($a['line2'] ?? ''));
                $cityLine = trim(implode(', ', array_filter([
                    trim((string)($a['city'] ?? '')),
                    trim(trim((string)($a['region'] ?? '')) . ' ' . trim((string)($a['postal_code'] ?? ''))),
                ], static fn(string $s): bool => $s !== '')));
                $isDefault = !empty($a['is_default']);

                // Orders keep the ship-to address as text; match on the normalized street line.
                $needle = $addrNorm($line1);
                $shippedOrders = [];
                if ($needle !== '') {
                    foreach ($buyerOrders as $o) {
                        $hay = $addrNorm((string)($o['delivery_address'] ?? ''));
                        if ($hay !== '' && strpos($hay, $needle) !== false) {
                            $shippedOrders[] = $o;
                        }
                    }
                }
                $last = $shippedOrders[0] ?? null;
                $lastStatus = $last ? strtolower(trim((string)($last['status'] ?? ''))) : '';

                $panelPayload['rows'][] = [
                    'id' => (string)((int)($a['id'] ?? 0)),
                    'title' => $label !== '' ? $label : 'Address',
                    'subtitle' => $line,
                    'meta' => trim((string)($a['phone'] ?? '')),
                    'value' => $isDefault ? 'Default' : '',
                    'address_id' => (int)($a['id'] ?? 0),
                    'label' => $label !== '' ? $label : 'Home',
                    'full_name' => trim((string)($a['full_name'] ?? '')),
                    'phone' => trim((string)($a['phone'] ?? '')),
                    'line1' => $line1,
                    'line2' => $line2,
                    'city' => trim((string)($a['city'] ?? '')),
                    'region' => trim((string)($a['region'] ?? '')),
                    'postal_code' => trim((string)($a['postal_code'] ?? '')),
                    'country' => trim((string)($a['country'] ?? 'US')),
                    'city_line' => $cityLine,
                    'is_default' => $isDefault,
                    'shipped_order_count' => count($shippedOrders),
                    'last_order_id' => $last ? (int)($last['id'] ?? 0) : 0,
                    'last_order_code' => $last ? trim((string)($last['order_code'] ?? '')) : '',
                    'last_order_title' => $last ? trim((string)($last['product_title'] ?? '')) : '',
                    'last_order_seller' => $last ? trim((string)($last['seller_name'] ?? '')) : '',
                    'last_order_qty' => $last ? max(1, (int)($last['quantity'] ?? 1)) : 0,
                    'last_order_status' => $lastStatus,
                    'last_order_status_label' => $lastStatus !== '' ? ucfirst($lastStatus) : '',
                    'last_order_date' => $last ? $fmtDate($last['created_at'] ?? '') : '',
                    'last_order_cover_url' => $last && function_exists('org_shop_cover_url')
                        ? org_shop_cover_url((string)($last['cover_image_path'] ?? ''))
                        : '',
                ];
            }
            break;

        case 'notifications':
            org_shop_mark_commerce_inbox_read($dbh, $meId);
            $panelPayload['title'] = 'Notifications';
            $alertCount = count($notifAlerts);
            $panelPayload['subtitle'] = $alertCount > 0
                ? ($alertCount . ($alertCount === 1 ? ' needs attention' : ' need attention'))
                : 'All caught up.';
            $panelPayload['attention_count'] = $alertCount;
            $panelPayload['badge_count'] = $notifBadgeCount;
            $panelPayload['tabs'] = [
                ['key' => 'all', 'label' => 'All', 'count' => 0],
                ['key' => 'alerts', 'label' => 'Alerts', 'count' => $alertCount],
                ['key' => 'pending', 'label' => 'Pending', 'count' => $notifLife['pending']],
                ['key' => 'paid', 'label' => 'Paid', 'count' => $notifLife['paid']],
                ['key' => 'shipping', 'label' => 'Shipping', 'count' => $notifLife['shipping']],
                ['key' => 'delivery', 'label' => 'Delivery', 'count' => $notifLife['delivery']],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'count' => $notifLife['cancel'] + $notifLife['cancellation']],
                ['key' => 'returns', 'label' => 'Returns', 'count' => $notifReturnAlertCount],
            ];
            $panelPayload['alerts'] = [];
            foreach ($notifAlerts as $aIdx => $a) {
                $aType = (string)($a['type'] ?? 'Alert');
                $aKey = $notifTypeKey($aType);
                $aAction = (string)($a['action'] ?? '');
                [$aOid, $aCode] = $notifOrderRef($aAction);
                $panelPayload['alerts'][] = [
                    'id' => 'alert-' . $aKey . '-' . $aIdx,
                    'kind' => 'alert',
                    'key' => $aKey,
                    'type' => $aType,
                    'title' => $aType,
                    'message' => (string)($a['message'] ?? ''),
                    'count' => max(0, (int)($a['count'] ?? 0)),
                    'action' => $aAction,
                    'panel' => $aKey === 'returns' || $aKey === 'update' ? 'returns-refunds' : '',
                    'order_id' => $aOid,
                    'order_code' => $aCode,
                ];
            }
            foreach ($notifRows as $idx => $n) {
                $action = (string)($n['action'] ?? '');
                $orderId = (int)($n['order_id'] ?? 0);
                $orderCode = trim((string)($n['order_code'] ?? ''));
                [$refOid, $refCode] = $notifOrderRef($action);
                if ($orderId <= 0) {
                    $orderId = $refOid;
                }
                if ($orderCode === '') {
                    $orderCode = $refCode;
                }
                $type = trim((string)($n['type'] ?? ''));
                $key = $notifTypeKey($type);
                $rowId = (string)((int)($n['id'] ?? 0));
                if ($rowId === '0' || $rowId === '') {
                    $rowId = 'notif-' . (string)$idx . '-' . max(0, $orderId);
                }
                $from = trim((string)($n['from'] ?? 'Seller'));
                $msg = trim((string)($n['message'] ?? $n['body'] ?? ''));
                $copy = $from !== '' ? ($from . ($msg !== '' ? ' · ' . $msg : '')) : $msg;
                $panelPayload['rows'][] = [
                    'id' => $rowId,
                    'kind' => 'feed',
                    'key' => $key,
                    'title' => (string)($n['title'] ?? $n['headline'] ?? 'Update'),
                    'subtitle' => $copy,
                    'message' => $msg,
                    'from' => $from,
                    'meta' => trim((string)($n['when'] ?? '')) !== ''
                        ? (string)$n['when']
                        : $fmtDate($n['created_at'] ?? ''),
                    'value' => '',
                    'type' => $type,
                    'action' => $action,
                    'panel' => ($key === 'returns' || $key === 'update') && strpos($action, 'order_detail.php') !== 0
                        ? 'returns-refunds'
                        : '',
                    'order_id' => $orderId,
                    'order_code' => $orderCode,
                ];
            }
            break;

        case 'membership':
            $panelPayload['title'] = 'Membership';
            $panelPayload['subtitle'] = $membershipActive ? 'Active' : 'Not active';
            $price = $fmtMoney(buyer_membership_price_cents());
            $fee = $fmtMoney(buyer_membership_member_service_fee_cents());
            $standardFee = $fmtMoney(org_shop_buyer_service_fee_cents(null, 0));
            $checkoutReady = stripe_shop_is_configured();
            $paidUntil = $membershipActive ? $fmtDate($membershipSnap['paid_until'] ?? '') : '';
            $panelPayload['rows'][] = [
                'id' => 'status',
                'title' => $membershipActive ? 'Membership active' : 'Join membership',
                'subtitle' => $membershipActive
                    ? ('Paid until ' . $paidUntil)
                    : ('Plan ' . $price . '/mo · Service fee while member: ' . $fee),
                'meta' => $checkoutReady ? 'Stripe checkout available' : 'Checkout not configured',
                'value' => $price,
                'is_active' => $membershipActive,
                'paid_until' => $paidUntil,
                'price_label' => $price,
                'member_fee_label' => $fee,
                'standard_fee_label' => $standardFee,
                'checkout_available' => $checkoutReady,
            ];
            $panelPayload['benefits'] = [
                ['key' => 'discounts', 'title' => 'Exclusive discounts', 'subtitle' => 'Get special member-only prices'],
                ['key' => 'early', 'title' => 'Early access', 'subtitle' => 'Be the first to see new products'],
                ['key' => 'shipping', 'title' => 'Free shipping deals', 'subtitle' => 'Access member-only shipping offers'],
                ['key' => 'content', 'title' => 'Member-only content', 'subtitle' => 'See exclusive posts and updates'],
                ['key' => 'support', 'title' => 'Priority support', 'subtitle' => 'Get faster help from the seller'],
            ];
            break;

        case 'loyalty-program':
            $panelPayload['title'] = 'Loyalty program';
            $panelPayload['subtitle'] = 'Earn rewards on eligible purchases';
            $panelPayload['rows'][] = [
                'id' => 'loyalty',
                'title' => 'Loyalty points',
                'subtitle' => 'Points and rewards will appear here as you shop.',
                'meta' => '',
                'value' => '0 pts',
            ];
            break;

        case 'guidance-center':
            $panelPayload['title'] = 'Guidance Center';
            $panelPayload['subtitle'] = 'Self-serve guides for shopping as a customer';
            foreach ([
                ['How do I find products in the shop?', 'Browse featured products, categories, or search by name.'],
                ['How do I add items to my cart?', 'Open a product, tap Add to Cart, then review quantities in Cart.'],
                ['How do I place an order?', 'Confirm address and payment, then place the order for a tracking code.'],
                ['Where do I track my order?', 'Open My orders and tap a row for status and receipts.'],
                ['How do I return or get a refund?', 'Use Returns & refunds or the order details when returns are available.'],
                ['What is Shopping Preferences?', 'Your customer hub for orders, wishlist, addresses, messages, and more.'],
            ] as $i => $faq) {
                $panelPayload['rows'][] = [
                    'id' => 'guidance-' . $i,
                    'title' => $faq[0],
                    'subtitle' => $faq[1],
                    'meta' => '',
                    'value' => '',
                    'action' => 'help-center',
                ];
            }
            break;


        case 'support-tickets':
        case 'support-center':
            $panelPayload['title'] = 'Support Center';
            $panelPayload['subtitle'] = 'Talk live with the customer center for your shopping need.';
            $panelPayload['rows'][] = [
                'id' => 'live-support',
                'title' => 'Contact live support',
                'subtitle' => 'Disputes, payments, delivery, and account help from Admin.',
                'meta' => '',
                'value' => '',
                'action' => 'support-center',
            ];
            $panelPayload['rows'][] = [
                'id' => 'guidance',
                'title' => 'Open Guidance Center',
                'subtitle' => 'Self-serve how-tos for browsing, cart, checkout, and tracking.',
                'meta' => '',
                'value' => '',
                'action' => 'help-center',
            ];
            break;

        case 'documents':
            $panelPayload['title'] = 'Documents';
            $panelPayload['subtitle'] = 'Buyer documents';
            $panelPayload['rows'][] = [
                'id' => 'docs',
                'title' => 'No documents yet',
                'subtitle' => 'Tax ID or business files for B2B seller checks show here.',
                'meta' => '',
                'value' => '',
            ];
            break;

        default:
            $panelPayload['title'] = 'Shopping Preferences';
            $panelPayload['subtitle'] = 'Choose a section from the customer menu.';
            break;
    }

    echo json_encode([
        'ok' => true,
        'panel' => $panel,
        'buyer' => [
            'id' => $meId,
            'name' => $displayName,
            'email' => $buyerProfile['email'],
            'phone' => $buyerProfile['phone'],
        ],
        'menu' => $menu,
        'preference_links' => $preferenceLinks,
        'dashboard' => $dashboard,
        'content' => $panelPayload,
        'notification_count' => $notifBadgeCount,
        'message_unread' => $sellerMsgUnread,
        'support_unread' => $supportMsgUnread,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to load shopping preferences.']);
}
