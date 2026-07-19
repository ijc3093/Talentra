<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/org_cart.php';
require_once __DIR__ . '/includes/buyer_shipping.php';
require_once __DIR__ . '/includes/buyer_seller_relationship.php';
require_once __DIR__ . '/includes/commerce_messaging.php';
require_once __DIR__ . '/includes/user_phone.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$GLOBALS['feedTopDbh'] = $dbh;
$GLOBALS['feedTopMeId'] = $meId;

$addrFlashOk = '';
$addrFlashErr = '';
$relFlashOk = '';
$relFlashErr = '';
if (!empty($_SESSION['addr_flash_ok'])) {
    $addrFlashOk = (string)$_SESSION['addr_flash_ok'];
    unset($_SESSION['addr_flash_ok']);
}
if (!empty($_SESSION['addr_flash_err'])) {
    $addrFlashErr = (string)$_SESSION['addr_flash_err'];
    unset($_SESSION['addr_flash_err']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buyer_addr_action'])) {
    $action = (string)$_POST['buyer_addr_action'];
    if ($action === 'save') {
        $res = buyer_shipping_save($dbh, $meId, [
            'label' => $_POST['label'] ?? 'Home',
            'full_name' => $_POST['full_name'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'line1' => $_POST['line1'] ?? '',
            'line2' => $_POST['line2'] ?? '',
            'city' => $_POST['city'] ?? '',
            'region' => $_POST['region'] ?? '',
            'postal_code' => $_POST['postal_code'] ?? '',
            'country' => $_POST['country'] ?? 'US',
            'is_default' => !empty($_POST['is_default']),
        ], (int)($_POST['address_id'] ?? 0));
        if (!empty($res['ok'])) {
            $phoneSync = trim((string)($_POST['phone'] ?? ''));
            if ($phoneSync !== '' && function_exists('user_phone_is_valid') && user_phone_is_valid($phoneSync)) {
                try {
                    $normalizedPhone = user_phone_normalize($phoneSync);
                    $stPhone = $dbh->prepare('UPDATE users SET mobile = :mobile WHERE id = :id LIMIT 1');
                    $stPhone->execute([':mobile' => mb_substr($normalizedPhone, 0, 40), ':id' => $meId]);
                } catch (Throwable $e) {
                    // ignore profile sync failure
                }
            }
            $_SESSION['addr_flash_ok'] = 'Address and contact details updated.';
        } else {
            $_SESSION['addr_flash_err'] = (string)($res['error'] ?? 'Could not save address.');
        }
        header('Location: Your_Shopping_preferences.php#addresses');
        exit;
    } elseif ($action === 'delete') {
        $_SESSION['addr_flash_ok'] = buyer_shipping_delete($dbh, $meId, (int)($_POST['address_id'] ?? 0))
            ? 'Address removed.'
            : '';
        if ($_SESSION['addr_flash_ok'] === '') {
            $_SESSION['addr_flash_err'] = 'Could not remove address.';
            unset($_SESSION['addr_flash_ok']);
        }
        header('Location: Your_Shopping_preferences.php#addresses');
        exit;
    } elseif ($action === 'default') {
        $_SESSION['addr_flash_ok'] = buyer_shipping_set_default($dbh, $meId, (int)($_POST['address_id'] ?? 0))
            ? 'Default address updated.'
            : '';
        if ($_SESSION['addr_flash_ok'] === '') {
            $_SESSION['addr_flash_err'] = 'Could not set default address.';
            unset($_SESSION['addr_flash_ok']);
        }
        header('Location: Your_Shopping_preferences.php#addresses');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buyer_seller_rel_action'])) {
    buyer_seller_rel_ensure_schema($dbh);
    $orgIdRel = (int)($_POST['org_id'] ?? 0);
    $res = buyer_seller_rel_save($dbh, $meId, $orgIdRel, [
        'relationship_type' => $_POST['relationship_type'] ?? 'shopper',
        'interests' => $_POST['interests'] ?? '',
        'preferred_contact' => $_POST['preferred_contact'] ?? 'message',
        'delivery_preference' => $_POST['delivery_preference'] ?? '',
        'budget_range' => $_POST['budget_range'] ?? '',
        'needs_note' => $_POST['needs_note'] ?? '',
        'share_with_seller' => !empty($_POST['share_with_seller']),
    ]);
    if (!empty($res['ok'])) {
        $relFlashOk = 'Preferences shared with this seller. They can use them to better meet your needs.';
    } else {
        $relFlashErr = (string)($res['error'] ?? 'Could not save seller preferences.');
    }
}

$buyerAddresses = buyer_shipping_list($dbh, $meId);
$buyerDefaultAddress = buyer_shipping_default_row($dbh, $meId);

buyer_seller_rel_ensure_schema($dbh);
$buyerSellerRels = buyer_seller_rel_list_for_buyer($dbh, $meId);
$buyerSellerRelEditOrg = (int)($_GET['seller_org'] ?? 0);
if ($buyerSellerRelEditOrg <= 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buyer_seller_rel_action'])) {
    $buyerSellerRelEditOrg = (int)($_POST['org_id'] ?? 0);
}
$buyerSellerRelEdit = null;
foreach ($buyerSellerRels as $relRow) {
    if ((int)($relRow['org_id'] ?? 0) === $buyerSellerRelEditOrg) {
        $buyerSellerRelEdit = $relRow;
        break;
    }
}
if ($buyerSellerRelEdit === null && $buyerSellerRels) {
    $buyerSellerRelEdit = $buyerSellerRels[0];
    $buyerSellerRelEditOrg = (int)($buyerSellerRelEdit['org_id'] ?? 0);
}

$sellerMsgContacts = commerce_list_buyer_seller_contacts($dbh, $meId);
$sellerMsgUnread = commerce_buyer_seller_unread_count($dbh, $meId);
$sellerMsgPeerId = (int)($_GET['seller_msg'] ?? $_GET['id'] ?? 0);
$sellerMsgAboutProduct = (int)($_GET['about_product'] ?? 0);
$sellerMsgAboutOrder = trim((string)($_GET['about_order'] ?? ''));
$sellerMsgDraft = commerce_messaging_compose_draft($dbh, $sellerMsgAboutProduct, $sellerMsgAboutOrder);
$sellerMsgActive = null;
if ($sellerMsgPeerId > 0 && commerce_can_dm_pair($dbh, $meId, $sellerMsgPeerId)) {
    foreach ($sellerMsgContacts as $c) {
        if ((int)($c['publisher_user_id'] ?? 0) === $sellerMsgPeerId) {
            $sellerMsgActive = $c;
            break;
        }
    }
    if ($sellerMsgActive === null) {
        try {
            $stPeer = $dbh->prepare("
                SELECT id, friend_code,
                       COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), friend_code) AS seller_name
                FROM users WHERE id = :id AND status = 1 LIMIT 1
            ");
            $stPeer->execute([':id' => $sellerMsgPeerId]);
            $peerRow = $stPeer->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($peerRow) {
                $sellerMsgActive = [
                    'publisher_user_id' => $sellerMsgPeerId,
                    'org_id' => 0,
                    'seller_name' => trim((string)($peerRow['seller_name'] ?? 'Seller')),
                    'friend_code' => strtoupper(trim((string)($peerRow['friend_code'] ?? ''))),
                    'last_message' => '',
                    'last_at' => '',
                    'unread' => 0,
                ];
                array_unshift($sellerMsgContacts, $sellerMsgActive);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
} elseif ($sellerMsgContacts) {
    $sellerMsgActive = $sellerMsgContacts[0];
    $sellerMsgPeerId = (int)($sellerMsgActive['publisher_user_id'] ?? 0);
}

$buyerOrders = org_shop_list_buyer_orders($dbh, $meId, 200);
$buyerOrderCount = 0;
$buyerSpentCents = 0;
$buyerOpenOrders = 0;
$buyerReceipts = 0;
$buyerReturnable = 0;
$buyerRecentOrderId = 0;
$buyerRecentOrderCode = '';
$buyerRecentStatus = '';
foreach ($buyerOrders as $buyerOrder) {
    $status = strtolower(trim((string)($buyerOrder['status'] ?? '')));
    if ($status === 'cancelled') {
        continue;
    }
    $buyerOrderCount++;
    $buyerSpentCents += (int)($buyerOrder['total_cents'] ?? 0);
    if (in_array($status, ['pending', 'confirmed', 'paid', 'shipped'], true)) $buyerOpenOrders++;
    if (!empty($buyerOrder['receipt_code'])) $buyerReceipts++;
    if (in_array($status, ['paid', 'shipped', 'delivered'], true)) $buyerReturnable++;
    if ($buyerRecentOrderId <= 0) {
        $buyerRecentOrderId = (int)($buyerOrder['id'] ?? 0);
        $buyerRecentOrderCode = (string)($buyerOrder['order_code'] ?? '');
        $buyerRecentStatus = $status;
    }
}
$buyerCartCount = org_cart_count($dbh, $meId);
$buyerCartItems = org_cart_list_items($dbh, $meId);
$buyerReturnRequests = 0;
$buyerReviews = 0;
$buyerReturnRows = [];
$buyerReviewRows = [];
try {
    $st = $dbh->prepare('
        SELECT r.*, o.order_code
        FROM org_order_returns r
        LEFT JOIN org_orders o ON o.id = r.order_id
        WHERE r.buyer_user_id = :uid
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT 20
    ');
    $st->execute([':uid' => $meId]);
    $buyerReturnRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $buyerReturnRequests = count($buyerReturnRows);
} catch (Throwable $e) {}
try {
    $st = $dbh->prepare('
        SELECT r.*, p.title AS product_title, o.order_code
        FROM org_product_reviews r
        LEFT JOIN org_products p ON p.id = r.product_id
        LEFT JOIN org_orders o ON o.id = r.order_id
        WHERE r.buyer_user_id = :uid
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT 20
    ');
    $st->execute([':uid' => $meId]);
    $buyerReviewRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $buyerReviews = count($buyerReviewRows);
} catch (Throwable $e) {}

$buyerProfile = ['name' => '', 'username' => '', 'email' => '', 'phone' => '', 'mobile' => ''];
try {
    $st = $dbh->prepare('SELECT name, username, email, mobile FROM users WHERE id = :id LIMIT 1');
    $st->execute([':id' => $meId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $buyerProfile['name'] = trim((string)($row['name'] ?? ''));
    $buyerProfile['username'] = trim((string)($row['username'] ?? ''));
    $buyerProfile['email'] = trim((string)($row['email'] ?? ''));
    $buyerProfile['mobile'] = trim((string)($row['mobile'] ?? ''));
    $buyerProfile['phone'] = function_exists('user_phone_from_user_row')
        ? user_phone_from_user_row($row)
        : (strcasecmp($buyerProfile['mobile'], 'N/A') === 0 ? '' : $buyerProfile['mobile']);
} catch (Throwable $e) {}
$buyerDisplayName = $buyerProfile['name'] !== ''
    ? $buyerProfile['name']
    : ($buyerProfile['username'] !== '' ? $buyerProfile['username'] : 'Customer');
// Prefer registered account phone over shipping-address-only display when loading contact card.
if ($buyerProfile['phone'] === '' && function_exists('buyer_shipping_default_phone')) {
    $buyerProfile['phone'] = buyer_shipping_default_phone($dbh, $meId);
}
$buyerInvoiceSubtotalCents = (int)$buyerSpentCents;
$buyerInvoiceDiscountCents = 0;
$buyerInvoiceTaxCents = 0;
$buyerInvoiceGrandCents = max(0, $buyerInvoiceSubtotalCents - $buyerInvoiceDiscountCents + $buyerInvoiceTaxCents);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('pref_date')) {
    function pref_date($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') return 'Not set';
        $ts = strtotime($raw);
        return $ts ? date('M j, Y', $ts) : $raw;
    }
}
if (!function_exists('pref_seller_contact')) {
    /** @return array{email:string,phone:string,address:string} */
    function pref_seller_contact(PDO $dbh, int $orgId, int $publisherUserId): array
    {
        $out = ['email' => '', 'phone' => '', 'address' => ''];
        if ($orgId > 0) {
            try {
                $st = $dbh->prepare('SELECT shop_json FROM org_settings WHERE org_id = :org LIMIT 1');
                $st->execute([':org' => $orgId]);
                $raw = (string)($st->fetchColumn() ?: '');
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $out['email'] = trim((string)($decoded['contact_email'] ?? ''));
                        $out['phone'] = trim((string)($decoded['contact_phone'] ?? ''));
                        if (is_array($decoded['address'] ?? null) && function_exists('org_shop_format_seller_address')) {
                            $out['address'] = org_shop_format_seller_address($decoded['address']);
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        if (($out['email'] === '' || $out['phone'] === '') && $publisherUserId > 0) {
            try {
                $st = $dbh->prepare('SELECT email, mobile FROM users WHERE id = :id LIMIT 1');
                $st->execute([':id' => $publisherUserId]);
                $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($out['email'] === '') {
                    $out['email'] = trim((string)($user['email'] ?? ''));
                }
                if ($out['phone'] === '') {
                    $out['phone'] = function_exists('user_phone_from_user_row')
                        ? user_phone_from_user_row($user)
                        : trim((string)($user['mobile'] ?? ''));
                    if (strcasecmp($out['phone'], 'N/A') === 0) {
                        $out['phone'] = '';
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        return $out;
    }
}
$buyerInvoiceSeller = trim((string)($buyerOrders[0]['seller_name'] ?? 'Seller'));
$buyerInvoiceDateRaw = (string)($buyerOrders[0]['created_at'] ?? '');
$buyerInvoiceDate = pref_date($buyerInvoiceDateRaw);
$buyerInvoiceDueDate = 'Not set';
if (trim($buyerInvoiceDateRaw) !== '') {
    $invoiceTs = strtotime($buyerInvoiceDateRaw);
    if ($invoiceTs) $buyerInvoiceDueDate = date('M j, Y', strtotime('+30 days', $invoiceTs));
}
$buyerPaymentStatus = (string)($buyerOrders[0]['status'] ?? 'pending');
$buyerPaymentTotalCents = (int)($buyerOrders[0]['total_cents'] ?? 0);
$buyerPaymentTotal = org_shop_format_price($buyerPaymentTotalCents, (string)($buyerOrders[0]['currency'] ?? 'USD'));

/** Group invoices by company + calendar day so one seller/day is one table row. */
$buyerPaymentGroups = [];
foreach ($buyerOrders as $order) {
    $status = strtolower(trim((string)($order['status'] ?? 'pending')));
    // Cancelled orders leave Order history / Invoices (same as org OMS inbox).
    if ($status === 'cancelled') {
        continue;
    }
    $orgId = (int)($order['org_id'] ?? 0);
    $company = trim((string)($order['seller_name'] ?? '')) ?: 'Seller';
    $createdRaw = (string)($order['created_at'] ?? '');
    $dayKey = '';
    $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : false;
    if ($createdTs) {
        $dayKey = date('Y-m-d', $createdTs);
    }
    $groupKey = $orgId . '|' . $dayKey . '|' . strtolower($company);
    if (!isset($buyerPaymentGroups[$groupKey])) {
        $buyerPaymentGroups[$groupKey] = [
            'org_id' => $orgId,
            'publisher_user_id' => (int)($order['publisher_user_id'] ?? 0),
            'company' => $company,
            'date_raw' => $createdRaw,
            'date' => pref_date($createdRaw),
            'date_sort' => $createdTs ?: 0,
            'currency' => (string)($order['currency'] ?? 'USD'),
            'total_cents' => 0,
            'statuses' => [],
            'receipts' => [],
            'order_codes' => [],
            'order_ids' => [],
            'cancellable_ids' => [],
            'products' => [],
            'item_count' => 0,
            'contact_email' => '',
            'contact_phone' => '',
            'contact_address' => '',
        ];
    }
    $g = &$buyerPaymentGroups[$groupKey];
    $g['total_cents'] += (int)($order['total_cents'] ?? 0);
    $orderIdRow = (int)($order['id'] ?? 0);
    if ($orderIdRow > 0 && !in_array($orderIdRow, $g['order_ids'], true)) {
        $g['order_ids'][] = $orderIdRow;
    }
    if ($orderIdRow > 0 && in_array($status, ['pending', 'confirmed', 'paid'], true) && !in_array($orderIdRow, $g['cancellable_ids'], true)) {
        $g['cancellable_ids'][] = $orderIdRow;
    }
    if ($status !== '') {
        $g['statuses'][] = $status;
    }
    $receipt = trim((string)($order['receipt_code'] ?? ''));
    if ($receipt !== '' && !in_array($receipt, $g['receipts'], true)) {
        $g['receipts'][] = $receipt;
    }
    $orderCode = trim((string)($order['order_code'] ?? ''));
    if ($orderCode === '') {
        $orderCode = '#' . (int)($order['id'] ?? 0);
    }
    if (!in_array($orderCode, $g['order_codes'], true)) {
        $g['order_codes'][] = $orderCode;
    }
    $qty = max(1, (int)($order['quantity'] ?? 1));
    $title = trim((string)($order['product_title'] ?? '')) ?: 'Product';
    $lineCents = (int)($order['total_cents'] ?? 0);
    $g['products'][] = [
        'title' => $title,
        'qty' => $qty,
        'amount' => org_shop_format_price($lineCents, (string)($order['currency'] ?? $g['currency'])),
        'amount_cents' => $lineCents,
    ];
    $g['item_count'] += $qty;
    unset($g);
}
uasort($buyerPaymentGroups, static function (array $a, array $b): int {
    return ((int)$b['date_sort']) <=> ((int)$a['date_sort']);
});
$buyerPaymentGroups = array_values($buyerPaymentGroups);
$sellerContactCache = [];

foreach ($buyerPaymentGroups as &$group) {
    $statuses = array_values(array_unique($group['statuses']));
    if (count($statuses) === 1) {
        $group['status'] = $statuses[0];
    } elseif (in_array('pending', $statuses, true)) {
        $group['status'] = 'pending';
    } elseif ($statuses) {
        $group['status'] = 'multiple';
    } else {
        $group['status'] = 'pending';
    }
    $group['total'] = org_shop_format_price((int)$group['total_cents'], (string)$group['currency']);
    if (count($group['receipts']) === 1) {
        $group['receipt_label'] = $group['receipts'][0];
    } elseif (count($group['receipts']) > 1) {
        $group['receipt_label'] = count($group['receipts']) . ' receipts';
    } else {
        $group['receipt_label'] = 'Pending';
    }
    $orderCount = count($group['order_codes']);
    if ($orderCount === 1) {
        $group['order_label'] = $group['order_codes'][0];
    } elseif ($orderCount > 1) {
        $group['order_label'] = $orderCount . ' orders';
    } else {
        $group['order_label'] = '—';
    }
    $group['invoice_label'] = $orderCount === 1
        ? $group['order_codes'][0]
        : ($group['company'] . ' · ' . $group['date']);
    $due = 'Not set';
    if (trim((string)$group['date_raw']) !== '') {
        $dueTs = strtotime((string)$group['date_raw']);
        if ($dueTs) {
            $due = date('M j, Y', strtotime('+30 days', $dueTs));
        }
    }
    $group['due'] = $due;

    $orgId = (int)($group['org_id'] ?? 0);
    $cacheKey = (string)$orgId;
    if (!isset($sellerContactCache[$cacheKey])) {
        $sellerContactCache[$cacheKey] = pref_seller_contact(
            $dbh,
            $orgId,
            (int)($group['publisher_user_id'] ?? 0)
        );
    }
    $contact = $sellerContactCache[$cacheKey];
    $group['contact_email'] = (string)($contact['email'] ?? '');
    $group['contact_phone'] = (string)($contact['phone'] ?? '');
    $group['contact_address'] = (string)($contact['address'] ?? '');
}
unset($group);

/**
 * Order history rows: same company + day, with:
 * Product # = how many different products (bowl, cup, tomatoes → 3)
 * Quantity # = total units (2 bowls + 3 cups + 6 tomatoes → 11)
 */
$buyerOrderHistoryRows = [];
foreach ($buyerPaymentGroups as $group) {
    $mergedProducts = [];
    foreach ($group['products'] as $p) {
        $title = trim((string)($p['title'] ?? '')) ?: 'Product';
        $key = mb_strtolower($title);
        $qty = max(1, (int)($p['qty'] ?? 1));
        $amountCents = (int)($p['amount_cents'] ?? 0);
        if (!isset($mergedProducts[$key])) {
            $mergedProducts[$key] = [
                'title' => $title,
                'qty' => $qty,
                'amount_cents' => $amountCents,
                'amount' => (string)($p['amount'] ?? org_shop_format_price($amountCents, (string)$group['currency'])),
            ];
        } else {
            $mergedProducts[$key]['qty'] += $qty;
            $mergedProducts[$key]['amount_cents'] += $amountCents;
            $mergedProducts[$key]['amount'] = org_shop_format_price(
                (int)$mergedProducts[$key]['amount_cents'],
                (string)$group['currency']
            );
        }
    }
    $productsList = array_values($mergedProducts);
    $productCount = count($productsList);
    $quantityTotal = 0;
    foreach ($productsList as $p) {
        $quantityTotal += max(1, (int)($p['qty'] ?? 1));
    }
    $cancellableIds = array_values(array_filter(array_map('intval', $group['cancellable_ids'] ?? [])));
    $buyerOrderHistoryRows[] = [
        'order_id' => (int)(($group['order_ids'][0] ?? 0)),
        'order_ids' => $group['order_ids'] ?? [],
        'cancellable_ids' => $cancellableIds,
        'order_num' => $productCount,
        'quantity_num' => $quantityTotal,
        'order_label' => (string)$group['order_label'],
        'invoice_label' => (string)$group['invoice_label'],
        'receipt_label' => (string)$group['receipt_label'],
        'company' => (string)$group['company'],
        'status' => (string)$group['status'],
        'total_cents' => (int)$group['total_cents'],
        'total' => (string)$group['total'],
        'currency' => (string)$group['currency'],
        'date' => (string)$group['date'],
        'due' => (string)$group['due'],
        'cancellable' => $cancellableIds !== [],
        'contact_email' => (string)($group['contact_email'] ?? ''),
        'contact_phone' => (string)($group['contact_phone'] ?? ''),
        'contact_address' => (string)($group['contact_address'] ?? ''),
        'products' => $productsList,
        'item_count' => $quantityTotal,
    ];
}

$buyerPaymentSelected = $buyerOrderHistoryRows[0] ?? null;
$buyerOrderHistorySelected = $buyerOrderHistoryRows[0] ?? null;
if ($buyerPaymentSelected) {
    $buyerPaymentStatus = (string)$buyerPaymentSelected['status'];
    $buyerPaymentTotal = (string)$buyerPaymentSelected['total'];
}
$buyerOrderHistoryCode = $buyerOrderHistorySelected
    ? (string)$buyerOrderHistorySelected['invoice_label']
    : ($buyerRecentOrderCode !== '' ? $buyerRecentOrderCode : 'Order');
$buyerOrderHistoryStatus = $buyerOrderHistorySelected
    ? (string)$buyerOrderHistorySelected['status']
    : ($buyerRecentStatus !== '' ? $buyerRecentStatus : 'Pending');
$buyerOrderHistoryTotal = $buyerOrderHistorySelected
    ? (string)$buyerOrderHistorySelected['total']
    : $buyerPaymentTotal;

/** Commerce notifications hub: lifecycle + alerts + recent updates (mirrors seller Notification). */
$buyerLife = org_shop_buyer_order_lifecycle_counts($dbh, $meId);
$buyerCommerceAlerts = org_shop_buyer_commerce_alerts($dbh, $meId);
$buyerCommerceNotifications = org_shop_buyer_commerce_notification_feed($dbh, $meId, 50);
$buyerNotifCount = (int)$buyerLife['pending']
    + (int)$buyerLife['paid']
    + (int)$buyerLife['cancel']
    + (int)$buyerLife['cancellation']
    + (int)$buyerLife['shipping'];
foreach ($buyerCommerceAlerts as $__ba) {
    if (strtolower((string)($__ba['type'] ?? '')) === 'return / refund') {
        $buyerNotifCount += (int)($__ba['count'] ?? 0);
    }
}
$buyerLifeTotal = $buyerNotifCount + (int)$buyerLife['delivery'];
$buyerAlertCount = count($buyerCommerceAlerts);
$buyerLifeStages = [
    [
        'key' => 'pending',
        'label' => 'Pending',
        'count' => (int)$buyerLife['pending'],
        'hint' => 'Waiting for your payment',
        'href' => 'my_orders.php',
    ],
    [
        'key' => 'paid',
        'label' => 'Paid',
        'count' => (int)$buyerLife['paid'],
        'hint' => 'Payment confirmed — seller ships',
        'href' => 'my_orders.php',
    ],
    [
        'key' => 'cancel',
        'label' => 'Cancel',
        'count' => (int)$buyerLife['cancel'],
        'hint' => 'Seller cancelled your order',
        'href' => 'my_orders.php',
    ],
    [
        'key' => 'cancellation',
        'label' => 'Cancellation',
        'count' => (int)$buyerLife['cancellation'],
        'hint' => 'You cancelled your order',
        'href' => 'my_orders.php',
    ],
    [
        'key' => 'shipping',
        'label' => 'Shipping',
        'count' => (int)$buyerLife['shipping'],
        'hint' => 'In transit to you',
        'href' => 'my_orders.php',
    ],
    [
        'key' => 'delivery',
        'label' => 'Delivery',
        'count' => (int)$buyerLife['delivery'],
        'hint' => 'Recently received',
        'href' => 'Your_Shopping_preferences.php#order-history',
    ],
];

$buyerInvoiceContactEmail = '';
$buyerInvoiceContactPhone = '';
$buyerInvoiceContactAddress = '';
if ($buyerOrderHistorySelected) {
    $buyerInvoiceSeller = (string)$buyerOrderHistorySelected['company'];
    $buyerInvoiceDate = (string)$buyerOrderHistorySelected['date'];
    $buyerInvoiceDueDate = (string)$buyerOrderHistorySelected['due'];
    $buyerInvoiceSubtotalCents = (int)$buyerOrderHistorySelected['total_cents'];
    $buyerInvoiceGrandCents = max(0, $buyerInvoiceSubtotalCents - $buyerInvoiceDiscountCents + $buyerInvoiceTaxCents);
    $buyerInvoiceContactEmail = (string)($buyerOrderHistorySelected['contact_email'] ?? '');
    $buyerInvoiceContactPhone = (string)($buyerOrderHistorySelected['contact_phone'] ?? '');
    $buyerInvoiceContactAddress = (string)($buyerOrderHistorySelected['contact_address'] ?? '');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Your Shopping Preferences</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <link rel="stylesheet" href="assets/ui_best.css">
  <link rel="stylesheet" href="assets/layout-fixed.css">
  <link rel="stylesheet" href="./css/shop-page.css?v=7">
  <style><?php include __DIR__ . '/includes/feed_rails.css.php'; ?></style>
  <style><?php include __DIR__ . '/includes/feed_header_chrome.css.php'; ?></style>
  <script defer src="assets/layout-fixed.js"></script>
  <style>
    .shop-customer-hub{display:grid;grid-template-columns:minmax(360px,1300px);gap:14px;margin:16px 0 18px;}
    .shop-customer-card{background:transparent;border:0;border-radius:0;color:var(--shop-text,var(--msb-palette-text,#111827));box-shadow:none;}
    .shop-customer-card{padding:16px;display:flex;flex-direction:column;gap:14px;}
    .shop-customer-kicker{margin:0 0 3px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-customer-name{margin:0;font-size:20px;font-weight:850;line-height:1.15;}
    .shop-customer-sub{margin:4px 0 0;font-size:13px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-customer-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;}
    .shop-customer-stat{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:5px;padding:28px 30px;min-height:128px;min-width:0;background:var(--shop-card-raised,var(--msb-palette-surface-2,rgba(15,23,42,.025)));}
    .shop-customer-stat strong{display:block;font-size:34px;line-height:1.05;}
    .shop-customer-stat span{display:block;margin-top:12px;font-size:15px;font-weight:700;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-customer-actions{display:flex;flex-wrap:wrap;gap:8px;}
    .shop-customer-action{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));border-radius:4px;padding:8px 10px;color:var(--shop-text,var(--msb-palette-text,#111827));text-decoration:none;font-size:12px;font-weight:800;background:var(--shop-btn-outline-bg,transparent);}
    .shop-customer-action:hover{text-decoration:none;background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));}
    #customer-dashboard > div:first-child{margin-top:-28px;margin-bottom:18px;}
    #customer-dashboard .shop-customer-actions{margin-top:54px;transform:translateY(34px);}
    .shop-pref-panel:not(#customer-dashboard) > div:first-child{margin-top:10px;}
    .shop-pref-panel{display:none;}
    .shop-pref-panel.is-active{display:flex;flex-direction:column;gap:14px;}
    #notifications.shop-pref-panel.is-active{
      gap:10px;
      max-height:calc(100vh - 170px);
      overflow:hidden;
      margin-top: -50px;
    }
    #notifications .shop-buyer-notif-section{margin:4px 0 8px;}
    #notifications .shop-buyer-notif-life{margin-top:0;}
    #notifications .shop-buyer-notif-sticky{
      flex:0 0 auto;
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    #notifications .shop-buyer-notif-scroll{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    #notifications .shop-buyer-notif-scroll .shop-buyer-notif-section{
      flex:0 0 auto;
    }
    #notifications .shop-buyer-notif-feed{
      flex:1 1 auto;
      min-height:0;
      max-height:min(360px,calc(100vh - 420px));
      overflow-y:auto;
      overflow-x:hidden;
      -webkit-overflow-scrolling:touch;
      scrollbar-width:thin;
      scrollbar-color:var(--shop-border-strong,var(--shop-border,#94a3b8)) transparent;
      overscroll-behavior:contain;
      padding-right:4px;
    }
    #notifications .shop-buyer-notif-feed::-webkit-scrollbar{width:6px;}
    #notifications .shop-buyer-notif-feed::-webkit-scrollbar-thumb{background:var(--shop-border-strong,var(--shop-border,#94a3b8));border-radius:999px;}
    .shop-pref-panel-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:0;padding:0;list-style:none;}
    .shop-pref-panel-item{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:5px;padding:12px;background:var(--shop-card-raised,var(--msb-palette-surface-2,rgba(15,23,42,.025)));}
    .shop-pref-panel-item strong{display:block;font-size:13px;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-pref-panel-item span{display:block;margin-top:5px;font-size:12px;line-height:1.4;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-pref-table-wrap{
      border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      border-radius:6px;
      max-width:100%;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      max-height:min(420px,calc(100vh - 260px));
      overflow:auto;
      -webkit-overflow-scrolling:touch;
      scrollbar-width:thin;
      scrollbar-color:var(--shop-border-strong,var(--shop-border,#94a3b8)) transparent;
      overscroll-behavior:contain;
    }
    .shop-pref-table-wrap::-webkit-scrollbar{width:6px;height:6px;}
    .shop-pref-table-wrap::-webkit-scrollbar-thumb{background:var(--shop-border-strong,var(--shop-border,#94a3b8));border-radius:999px;}
    .shop-pref-table{width:100%;border-collapse:separate;border-spacing:0;min-width:640px;table-layout:auto;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-pref-table th,.shop-pref-table td{padding:8px 10px;border-bottom:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));font-size:12px;text-align:left;vertical-align:middle;}
    .shop-pref-table th{
      position:sticky;
      top:0;
      z-index:3;
      padding:8px 22px 8px 10px;
      border-right:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      font-size:10px;
      line-height:1.2;
      text-transform:uppercase;
      letter-spacing:.02em;
      white-space:nowrap;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
      font-weight:700;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      box-shadow:inset 0 -1px 0 var(--shop-border,var(--msb-palette-border,#e5e7eb));
    }
    .shop-pref-table th:last-child{border-right:0;}
    .shop-pref-table th::before,.shop-pref-table th::after{content:"";position:absolute;right:8px;border-left:3px solid transparent;border-right:3px solid transparent;opacity:.28;}
    .shop-pref-table th::before{top:calc(50% - 5px);border-bottom:4px solid var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-pref-table th::after{top:calc(50% + 1px);border-top:4px solid var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-pref-table td{white-space:nowrap;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));}
    .shop-pref-table tr:last-child td{border-bottom:0;}
    .shop-pref-table tr[data-invoice-order],.shop-pref-table tr[data-payment-order]{cursor:pointer;}
    .shop-pref-table tr[data-invoice-order]:hover td,.shop-pref-table tr[data-invoice-order].is-selected td,.shop-pref-table tr[data-payment-order]:hover td,.shop-pref-table tr[data-payment-order].is-selected td{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));}
    .shop-pref-table-empty{color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));white-space:normal;}
    #invoices-payments .shop-pref-table{min-width:760px;}
    #invoices-payments .shop-pref-table th.shop-col-center,
    #invoices-payments .shop-pref-table td.shop-col-center{text-align:center;}
    #invoices-payments .shop-pref-table th.shop-col-center::before,
    #invoices-payments .shop-pref-table th.shop-col-center::after{display:none;}
    #invoices-payments .shop-pref-table th:nth-child(1),
    #invoices-payments .shop-pref-table th:nth-child(2),
    #invoices-payments .shop-pref-table th:nth-child(4),
    #invoices-payments .shop-pref-table th:nth-child(6),
    #invoices-payments .shop-pref-table th:nth-child(7){width:1%;white-space:nowrap;}
    #invoices-payments .shop-pref-table th:nth-child(3){width:18%;}
    .shop-payment-items .shop-invoice-items-head,
    .shop-payment-items .shop-invoice-product-line{margin:0;}
    .shop-payment-items .shop-invoice-product-line{font-size:13px;}
    .shop-payment-items-empty{padding:8px 0;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));font-size:13px;}
    .shop-invoice-layout .shop-pref-table-wrap,
    .shop-table-stack .shop-pref-table-wrap{max-height:min(520px,calc(100vh - 260px));}
    #addresses .shop-pref-table-wrap{max-height:min(260px,calc(100vh - 420px));}
    .shop-addr-view{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;max-width:860px;}
    .shop-addr-card{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:6px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:16px 18px;}
    .shop-addr-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;}
    .shop-addr-card-head h3{margin:0;font-size:15px;font-weight:850;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-addr-edit-btn{border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));border-radius:4px;background:var(--shop-btn-outline-bg,transparent);color:var(--shop-text,var(--msb-palette-text,#111827));font-size:12px;font-weight:800;padding:6px 12px;cursor:pointer;}
    .shop-addr-edit-btn:hover{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));}
    .shop-addr-line{margin:0 0 8px;font-size:13px;line-height:1.45;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-addr-line strong{display:inline-block;min-width:72px;font-weight:800;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-addr-muted{color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-addr-block{white-space:pre-line;margin:0;font-size:13px;line-height:1.5;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-addr-modal{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:20px;}
    .shop-addr-modal.is-open{display:flex;}
    .shop-addr-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
    .shop-addr-modal-dialog{position:relative;z-index:1;width:min(560px,100%);max-height:min(90vh,720px);overflow:auto;border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:8px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:18px 18px 16px;box-shadow:0 18px 48px rgba(0,0,0,.28);}
    .shop-addr-modal-dialog h3{margin:0 0 6px;font-size:18px;font-weight:850;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-addr-modal-dialog > p{margin:0 0 14px;font-size:13px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-addr-modal-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
    @media (max-width:700px){.shop-addr-view{grid-template-columns:1fr;}}
    .shop-table-stack{display:flex;flex-direction:column;gap:12px;min-width:0;}
    .shop-table-actions{display:flex;justify-content:flex-end;gap:10px;min-height:32px;margin-top: -7%;}
    .shop-table-action{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));border-radius:5px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));color:var(--shop-link,var(--msb-palette-action,#2563eb));font-size:17px;text-decoration:none;cursor:pointer;}
    .shop-table-action:hover{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));text-decoration:none;}
    .shop-order-cancel-btn{border:1px solid #fecaca;border-radius:4px;background:#fff;color:#b91c1c;font-size:11px;font-weight:800;padding:5px 10px;cursor:pointer;white-space:nowrap;}
    .shop-order-cancel-btn:hover{background:#fef2f2;}
    .shop-order-cancel-btn:disabled{opacity:.55;cursor:default;}
    .shop-pref-table th.shop-order-cancel-col::before,.shop-pref-table th.shop-order-cancel-col::after{display:none;}
    #order-history .shop-pref-table th.shop-col-center,
    #order-history .shop-pref-table td.shop-col-center{text-align:center;}
    #order-history .shop-pref-table th.shop-col-center::before,
    #order-history .shop-pref-table th.shop-col-center::after{display:none;}
    #order-history .shop-pref-table th:nth-child(1),
    #order-history .shop-pref-table th:nth-child(3){width:1%;white-space:nowrap;}
    #customer-dashboard{max-width:860px;}
    .shop-invoice-layout{display:grid;grid-template-columns:minmax(680px,1fr) 380px;gap:18px;align-items:start;}
    .shop-invoice-side{display:flex;flex-direction:column;gap:12px;}
    .shop-invoice-title{margin:0;font-size:20px;font-weight:500;line-height:1.2;color:var(--shop-text,var(--msb-palette-text,#111827)); margin-top:-30%;}
    .shop-invoice-summary{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:6px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:18px;color:var(--shop-text,var(--msb-palette-text,#111827));margin-right: 7%;max-height:520px;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:var(--shop-border-strong,var(--shop-border,#94a3b8)) transparent;}
    .shop-invoice-summary::-webkit-scrollbar{width:6px;}
    .shop-invoice-summary::-webkit-scrollbar-thumb{background:var(--shop-border-strong,var(--shop-border,#94a3b8));border-radius:999px;}
    .shop-invoice-meta{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;}
    .shop-invoice-number{font-size:13px;font-weight:850;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-invoice-status{display:inline-flex;margin-top:5px;border-radius:999px;padding:3px 9px;font-size:11px;font-weight:850;background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-invoice-dates{text-align:right;font-size:12px;line-height:1.6;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-invoice-dates strong{color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-invoice-addresses{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:14px;}
    .shop-invoice-address{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:5px;padding:9px 12px;background:var(--shop-card-raised,var(--msb-palette-surface-2,rgba(15,23,42,.025)));}
    .shop-invoice-address strong{display:block;margin-bottom:4px;font-size:13px;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-invoice-address span{display:block;font-size:12px;line-height:1.45;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-invoice-line{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:6px 0;font-size:14px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-invoice-line strong{color:var(--shop-text,var(--msb-palette-text,#111827));font-weight:850;}
    .shop-invoice-items{display:flex;flex-direction:column;gap:2px;margin:4px 0 10px;}
    .shop-invoice-items .shop-invoice-line{align-items:flex-start;}
    .shop-invoice-items .shop-invoice-line span:first-child{min-width:0;white-space:normal;line-height:1.35;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-invoice-items .shop-invoice-line span:last-child{flex:0 0 auto;font-weight:850;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-invoice-items-head{display:grid;grid-template-columns:minmax(0,1fr) 64px 88px;gap:10px;padding:4px 0 8px;border-bottom:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));margin-bottom:4px;font-size:11px;font-weight:850;letter-spacing:.02em;text-transform:uppercase;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-invoice-product-line{display:grid;grid-template-columns:minmax(0,1fr) 64px 88px;gap:10px;padding:8px 0;border-bottom:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));font-size:13px;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-invoice-product-line:last-child{border-bottom:0;}
    .shop-invoice-product-title{min-width:0;white-space:normal;line-height:1.35;font-weight:700;}
    .shop-invoice-product-qty,.shop-invoice-product-amount{text-align:right;font-weight:850;white-space:nowrap;}
    .shop-invoice-product-qty{color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-invoice-items-empty{padding:10px 0;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));font-size:13px;}
    .shop-invoice-note{margin-top:18px;padding-top:14px;border-top:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));}
    .shop-invoice-note h3{margin:0 0 8px;font-size:15px;font-weight:850;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-invoice-note p{margin:0;font-size:13px;line-height:1.5;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-invoice-download{display:inline-flex;align-items:center;justify-content:center;margin-top:16px;border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));border-radius:4px;padding:9px 14px;color:var(--shop-text,var(--msb-palette-text,#111827));font-size:13px;font-weight:850;text-decoration:none;background:var(--shop-btn-outline-bg,transparent);}
    .shop-invoice-download:hover{text-decoration:none;background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));}
    .shop-invoice-seller-contact{margin-top:16px;padding-top:14px;border-top:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));}
    .shop-invoice-seller-contact h3{margin:0 0 8px;font-size:15px;font-weight:850;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-invoice-seller-contact p{margin:0 0 4px;font-size:13px;line-height:1.45;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));white-space:pre-line;}
    .shop-invoice-seller-contact a{color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;}
    .shop-invoice-seller-contact a:hover{text-decoration:underline;}
    .shop-invoice-seller-contact [data-invoice-empty="1"]{display:none;}
    .shop-payment-summary{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:6px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:12px;color:var(--shop-text,var(--msb-palette-text,#111827));margin-right:7%;max-height:520px;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:var(--shop-border-strong,var(--shop-border,#94a3b8)) transparent;padding-right: 30px;}
    .shop-payment-summary::-webkit-scrollbar{width:6px;}
    .shop-payment-summary::-webkit-scrollbar-thumb{background:var(--shop-border-strong,var(--shop-border,#94a3b8));border-radius:999px;}
    .shop-payment-side{display:flex;flex-direction:column;gap:10px; margin-top: -120px;}
    .shop-payment-heading{margin:0 0 0 2px;}
    .shop-payment-heading h3{margin:0 0 4px;font-size:20px;font-weight:900;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-payment-section{padding-bottom:18px;margin-bottom:18px;border-bottom:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));}
    .shop-payment-section h3{margin:0 0 14px;font-size:20px;font-weight:900;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-payment-code{margin:-4px 0 14px;font-size:13px;font-weight:850;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-payment-address{font-size:15px;line-height:1.45;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-payment-status{display:grid;grid-template-columns:46px minmax(0,1fr) auto;gap:12px;align-items:center;margin-bottom:26px;}
    .shop-payment-icon{width:44px;height:34px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));border-radius:5px;font-size:19px;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-payment-state{font-size:18px;font-weight:900;text-transform:capitalize;}
    .shop-payment-amount{font-size:18px;font-weight:900;}
    .shop-payment-line{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:7px 0;font-size:15px;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-payment-line strong{font-weight:900;}
    .shop-payment-free{color:#16833a;font-weight:900;}
    .shop-payment-total{margin-top:14px;padding-top:14px;border-top:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));font-weight:900;}
    .shop-payment-tax-note{margin:18px 0 0;font-size:12px;line-height:1.45;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-payment-items{display:flex;flex-direction:column;gap:2px;margin-bottom:6px;}
    .shop-payment-items .shop-payment-line{align-items:flex-start;}
    .shop-payment-items .shop-payment-line span:first-child{min-width:0;white-space:normal;line-height:1.35;}
    .shop-payment-items .shop-payment-line span:last-child{flex:0 0 auto;font-weight:700;}
    .shop-payment-company{margin:0 0 10px;font-size:13px;font-weight:800;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-rel-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,360px);gap:18px;align-items:start;}
    .shop-rel-card{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:6px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:16px;}
    .shop-rel-card h3{margin:0 0 6px;font-size:16px;font-weight:850;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-rel-card p{margin:0 0 10px;font-size:13px;line-height:1.45;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-rel-form .form-group{margin-bottom:10px;}
    .shop-rel-form label{display:block;margin-bottom:4px;font-size:12px;font-weight:800;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-rel-form .form-control{font-size:13px;}
    .shop-rel-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
    .shop-rel-table a{color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;font-weight:800;}
    .shop-rel-table a:hover{text-decoration:underline;}
    @media (max-width:900px){.shop-rel-layout{grid-template-columns:1fr;}}
    .shop-pref-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:10px 0 0;}
    .shop-pref-head h1{font-size:22px;font-weight:850;margin:0;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-pref-head a{font-size:13px;font-weight:800;color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;}
    .shop-pref-head a:hover{text-decoration:underline;}
    .shop-pref-layout{display:grid;grid-template-columns:240px minmax(0,1fr);gap:18px;align-items:start;margin-top:16px;}
    .shop-pref-side{display:flex;flex-direction:column;gap:10px;position:sticky;top:12px;align-self:start;}
    .shop-pref-nav{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:6px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:10px;height:min(520px,calc(100vh - 280px));min-height:310px;display:flex;flex-direction:column;overflow:hidden;}
    .shop-pref-nav-title{flex:0 0 auto;margin:0 0 8px;padding:4px 6px;font-size:11px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-pref-nav-list{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:3px;margin:0;padding:0 4px 0 0;list-style:none;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:var(--shop-border-strong,var(--shop-border,#475569)) transparent;}
    .shop-pref-nav-list::-webkit-scrollbar{width:6px;}
    .shop-pref-nav-list::-webkit-scrollbar-thumb{background:var(--shop-border-strong,var(--shop-border,#475569));border-radius:999px;}
    .shop-pref-nav-link{display:flex;align-items:center;gap:9px;min-height:34px;padding:7px 8px;border-radius:5px;color:var(--shop-text,var(--msb-palette-text,#111827));font-size:13px;font-weight:800;text-decoration:none;}
    .shop-pref-nav-link:hover,.shop-pref-nav-link:focus,.shop-pref-nav-link.is-active{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));text-decoration:none;}
    .shop-pref-nav-link i{width:16px;text-align:center;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));font-size:15px;}
    .shop-pref-nav-badge{margin-left:auto;min-width:18px;height:18px;padding:0 5px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#dc3545;color:#fff;font-size:10px;font-weight:800;line-height:1;}
    .shop-pref-support-center{
      display:flex;align-items:center;gap:8px;padding:8px 10px;
      color:var(--shop-text,var(--msb-palette-text,#111827));font-size:14px;font-weight:850;
      text-decoration:none;line-height:1.2;
    }
    .shop-pref-support-center:hover,.shop-pref-support-center:focus,.shop-pref-support-center.is-active{
      color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;
    }
    .shop-pref-support-center i{font-size:16px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-pref-support-center.is-active i,.shop-pref-support-center:hover i{color:inherit;}
    .shop-buyer-notif-life{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:10px;padding:14px 16px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));}
    .shop-buyer-notif-life h3{margin:0 0 4px;font-size:14px;font-weight:700;}
    .shop-buyer-notif-life > p{margin:0 0 12px;font-size:12px;line-height:1.4;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));max-width:760px;}
    .shop-buyer-notif-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;}
    .shop-buyer-notif-stage{display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:10px;border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));background:var(--shop-card-raised,var(--msb-palette-surface-2,rgba(15,23,42,.025)));text-decoration:none !important;color:inherit !important;min-width:0;}
    .shop-buyer-notif-stage:hover{border-color:rgba(14,165,233,.45);background:rgba(14,165,233,.06);}
    .shop-buyer-notif-stage.is-hot{border-color:rgba(220,53,69,.35);background:rgba(220,53,69,.06);}
    .shop-buyer-notif-stage-top{display:flex;align-items:center;justify-content:space-between;gap:8px;}
    .shop-buyer-notif-stage-label{font-size:12px;font-weight:700;line-height:1.2;}
    .shop-buyer-notif-stage-count{font-size:16px;font-weight:800;line-height:1;}
    .shop-buyer-notif-stage-hint{font-size:11px;line-height:1.35;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));font-weight:400;}
    .shop-buyer-notif-section{margin:16px 0 10px;font-size:13px;font-weight:700;}
    .shop-buyer-notif-alerts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top: -12px;}
    .shop-buyer-notif-alert{display:flex;flex-direction:column;gap:6px;padding:14px 16px;border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:10px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));text-decoration:none !important;color:inherit !important;}
    .shop-buyer-notif-alert:hover{border-color:rgba(14,165,233,.4);}
    .shop-buyer-notif-alert-title{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700;}
    .shop-buyer-notif-alert-badge{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 7px;border-radius:999px;background:#dc3545;color:#fff;font-size:12px;font-weight:700;}
    .shop-buyer-notif-alert-copy{font-size:12px;line-height:1.45;font-weight:400;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-buyer-notif-feed{display:flex;flex-direction:column;gap:8px;}
    .shop-buyer-notif-feed-row{display:flex;flex-direction:column;gap:2px;padding:10px 12px;border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:10px;text-decoration:none !important;color:inherit !important;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));}
    .shop-buyer-notif-feed-row:hover{border-color:rgba(14,165,233,.4);}
    .shop-buyer-notif-feed-row strong{font-size:13px;font-weight:700;}
    .shop-buyer-notif-feed-row span{font-size:12px;line-height:1.4;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-buyer-notif-feed-when{font-size:11px !important;margin-top:2px;}
    @media (max-width:1100px){.shop-buyer-notif-grid{grid-template-columns:repeat(3,minmax(0,1fr));}}
    @media (max-width:900px){.shop-buyer-notif-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.shop-buyer-notif-alerts{grid-template-columns:1fr;}}
    @media (max-width:520px){.shop-buyer-notif-grid{grid-template-columns:1fr;}}
    body.shopping-preferences-page .sh-mainpanel{margin-left:var(--msb-leftbar-width,112px);}
    body.shopping-preferences-page .sh-pagebody{max-width:none;}
    body.shopping-preferences-page.shop-page.feed-insta-ui .shop-page-shell{padding-left:24px !important;padding-right:24px !important;}
    body.shopping-preferences-page .shop-pref-layout{max-width:none;width:100%;}
    .shop-seller-msg-layout{display:grid;grid-template-columns:minmax(180px,240px) minmax(0,1fr);gap:12px;min-height:420px;}
    .shop-seller-msg-list{border:1px solid var(--shop-border,rgba(148,163,184,.35));border-radius:8px;overflow:auto;max-height:520px;background:var(--shop-card-bg,transparent);}
    .shop-seller-msg-item{display:block;padding:10px 12px;border-bottom:1px solid var(--shop-border,rgba(148,163,184,.25));color:inherit;text-decoration:none;}
    .shop-seller-msg-item:hover,.shop-seller-msg-item.is-active{background:var(--shop-hover-bg,rgba(148,163,184,.12));}
    .shop-seller-msg-item strong{display:block;font-size:13px;}
    .shop-seller-msg-item span{display:block;font-size:11px;opacity:.75;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .shop-seller-msg-chat{border:1px solid var(--shop-border,rgba(148,163,184,.35));border-radius:8px;display:flex;flex-direction:column;min-height:420px;max-height:520px;background:var(--shop-card-bg,transparent);}
    .shop-seller-msg-head{padding:10px 12px;border-bottom:1px solid var(--shop-border,rgba(148,163,184,.25));font-weight:800;font-size:14px;}
    .shop-seller-msg-thread{flex:1 1 auto;overflow:auto;padding:12px;display:flex;flex-direction:column;gap:8px;}
    .shop-seller-msg-bubble{max-width:85%;padding:8px 10px;border-radius:10px;font-size:13px;line-height:1.4;white-space:pre-wrap;word-break:break-word;}
    .shop-seller-msg-bubble.me{align-self:flex-end;background:#2563eb;color:#fff;}
    .shop-seller-msg-bubble.them{align-self:flex-start;background:rgba(148,163,184,.18);}
    .shop-seller-msg-meta{font-size:10px;opacity:.7;margin-top:4px;}
    .shop-seller-msg-compose{display:flex;gap:8px;padding:10px;border-top:1px solid var(--shop-border,rgba(148,163,184,.25));}
    .shop-seller-msg-compose textarea{flex:1 1 auto;min-height:44px;max-height:120px;resize:none;}
    .shop-seller-msg-empty{padding:24px 12px;text-align:center;opacity:.8;font-size:13px;}
    @media (max-width:900px){.shop-seller-msg-layout{grid-template-columns:1fr;}}
    .shop-admin-support{display:grid;grid-template-columns:minmax(200px,260px) minmax(0,1fr);gap:12px;margin-top:14px;min-height:420px;}
    .shop-admin-support-guide{border:1px solid var(--shop-border,rgba(148,163,184,.35));border-radius:8px;padding:12px;background:var(--shop-card-bg,transparent);}
    .shop-admin-support-guide h3{margin:0 0 8px;font-size:13px;font-weight:850;}
    .shop-admin-support-guide ol{margin:0;padding-left:18px;font-size:12px;line-height:1.55;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-admin-support-guide li{margin:0 0 6px;}
    .shop-admin-support-guide p{margin:10px 0 0;font-size:12px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-admin-support-chat{border:1px solid var(--shop-border,rgba(148,163,184,.35));border-radius:8px;display:flex;flex-direction:column;min-height:420px;max-height:560px;background:var(--shop-card-bg,transparent);}
    .shop-admin-support-head{padding:10px 12px;border-bottom:1px solid var(--shop-border,rgba(148,163,184,.25));font-weight:800;font-size:14px;}
    .shop-admin-support-topics{display:flex;flex-wrap:wrap;gap:6px;padding:8px 10px;border-bottom:1px solid var(--shop-border,rgba(148,163,184,.25));}
    .shop-admin-topic{border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));border-radius:4px;background:var(--shop-btn-outline-bg,transparent);color:var(--shop-text,var(--msb-palette-text,#111827));font-size:11px;font-weight:800;padding:5px 9px;cursor:pointer;}
    .shop-admin-topic.is-active{border-color:var(--shop-link,var(--msb-palette-action,#2563eb));color:var(--shop-link,var(--msb-palette-action,#2563eb));background:rgba(37,99,235,.08);}
    .shop-admin-support-thread{flex:1 1 auto;overflow:auto;padding:12px;display:flex;flex-direction:column;gap:8px;}
    .shop-admin-support-compose{display:flex;flex-direction:column;gap:8px;padding:10px;border-top:1px solid var(--shop-border,rgba(148,163,184,.25));}
    .shop-admin-support-compose .shop-admin-support-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
    .shop-admin-support-compose textarea{min-height:64px;max-height:140px;resize:none;}
    .shop-admin-support-compose-row{display:flex;gap:8px;align-items:flex-end;}
    .shop-admin-support-compose-row textarea{flex:1 1 auto;}
    @media (max-width:900px){.shop-admin-support{grid-template-columns:1fr;}.shop-admin-support-compose .shop-admin-support-meta{grid-template-columns:1fr;}}

    @media (max-width:1024px){body.shopping-preferences-page.shop-page.feed-insta-ui .shop-page-shell{padding-left:calc(var(--feedRailW, 84px) + 12px) !important;}}
    @media (max-width:640px){body.shopping-preferences-page.shop-page.feed-insta-ui .shop-page-shell{padding-left:12px !important;padding-right:12px !important;}.shop-pref-nav-list{grid-template-columns:1fr;}.shop-customer-stats,.shop-pref-panel-list{grid-template-columns:repeat(2,minmax(0,1fr));}}
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui shopping-preferences-page">
<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>
<div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
      <?php include __DIR__ . '/includes/shop_header_search.php'; ?>
      <?php $feedTopShopActive = true; $feedTopShopOnly = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>
    <div class="shop-page-shell">
      <div class="shop-pref-head">
        <h1>Your Shopping Preferences</h1>
        <a href="shop.php">&larr; Back to shop</a>
      </div>
      <div class="shop-pref-layout">
        <div class="shop-pref-side">
          <aside class="shop-pref-nav" aria-label="Shopping preferences navigation">
            <p class="shop-pref-nav-title">Customer menu</p>
            <ul class="shop-pref-nav-list">
              <li><a class="shop-pref-nav-link is-active" href="#customer-dashboard" data-shop-pref-target="customer-dashboard"><i class="icon ion-speedometer"></i>Dashboard</a></li>
              <li><a class="shop-pref-nav-link" href="#order-history" data-shop-pref-target="order-history"><i class="icon ion-ios-list"></i>Order history</a></li>
              <li><a class="shop-pref-nav-link" href="#order-details" data-shop-pref-target="order-details"><i class="icon ion-document-text"></i>Order details</a></li>
              <!-- <li><a class="shop-pref-nav-link" href="#shopping-cart" data-shop-pref-target="shopping-cart"><i class="icon ion-ios-cart"></i>Shopping cart</a></li> -->
              <li><a class="shop-pref-nav-link" href="#wishlist" data-shop-pref-target="wishlist"><i class="icon ion-bookmark"></i>Wishlist</a></li>
              <li><a class="shop-pref-nav-link" href="#invoices-payments" data-shop-pref-target="invoices-payments"><i class="icon ion-card"></i>Invoices &amp; payments</a></li>
              <li><a class="shop-pref-nav-link" href="#returns-refunds" data-shop-pref-target="returns-refunds"><i class="icon ion-reply"></i>Returns &amp; refunds</a></li>
              <li><a class="shop-pref-nav-link" href="#seller-relationships" data-shop-pref-target="seller-relationships"><i class="icon ion-ios-people"></i>Seller relationships</a></li>
              <li>
                <a class="shop-pref-nav-link" href="#seller-messages" data-shop-pref-target="seller-messages">
                  <i class="icon ion-chatboxes"></i>Messages
                  <?php if ($sellerMsgUnread > 0): ?>
                    <span class="shop-pref-nav-badge"><?= $sellerMsgUnread > 99 ? '99+' : (int)$sellerMsgUnread ?></span>
                  <?php endif; ?>
                </a>
              </li>
              <li><a class="shop-pref-nav-link" href="#reviews-ratings" data-shop-pref-target="reviews-ratings"><i class="icon ion-star"></i>Reviews &amp; ratings</a></li>
              <li><a class="shop-pref-nav-link" href="#addresses" data-shop-pref-target="addresses"><i class="icon ion-location"></i>Addresses</a></li>
              <!-- <li><a class="shop-pref-nav-link" href="#contact-information" data-shop-pref-target="contact-information"><i class="icon ion-email"></i>Contact information</a></li> -->
              <!-- <li><a class="shop-pref-nav-link" href="#customer-groups" data-shop-pref-target="customer-groups"><i class="icon ion-ios-people"></i>Customer groups</a></li> -->
              <li>
                <a class="shop-pref-nav-link" href="#notifications" data-shop-pref-target="notifications">
                  <i class="icon ion-android-notifications"></i>Notifications
                  <?php if ($buyerNotifCount > 0): ?>
                    <span class="shop-pref-nav-badge"><?= $buyerNotifCount > 99 ? '99+' : (int)$buyerNotifCount ?></span>
                  <?php endif; ?>
                </a>
              </li>
              <li><a class="shop-pref-nav-link" href="#loyalty-program" data-shop-pref-target="loyalty-program"><i class="icon ion-ribbon-b"></i>Loyalty program</a></li>
              <li><a class="shop-pref-nav-link" href="#support-tickets" data-shop-pref-target="support-tickets"><i class="icon ion-help-buoy"></i>Support tickets</a></li>
              <li><a class="shop-pref-nav-link" href="#documents" data-shop-pref-target="documents"><i class="icon ion-folder"></i>Documents</a></li>
            </ul>
          </aside>
          <a class="shop-pref-support-center" href="#support-center" data-shop-pref-target="support-center">
            <i class="icon ion-ios-help"></i>Support Center
          </a>
        </div>
        <section class="shop-customer-hub" aria-label="Customer module">
          <div class="shop-customer-card">
            <div class="shop-pref-panel is-active" id="customer-dashboard" data-shop-pref-panel="customer-dashboard">
              <div>
                <p class="shop-customer-kicker">Customer dashboard</p>
                <h2 class="shop-customer-name"><?= h($buyerDisplayName) ?></h2>
                <p class="shop-customer-sub"><?= $buyerProfile['email'] !== '' ? h($buyerProfile['email']) : 'Buyer account' ?><?php if ($buyerProfile['phone'] !== ''): ?> · <?= h($buyerProfile['phone']) ?><?php endif; ?></p>
              </div>
              <div class="shop-customer-stats">
                <div class="shop-customer-stat"><strong><?= (int)$buyerOrderCount ?></strong><span>orders</span></div>
                <div class="shop-customer-stat"><strong><?= h(org_shop_format_price((int)$buyerSpentCents, 'USD')) ?></strong><span>spending</span></div>
                <div class="shop-customer-stat"><strong><?= (int)$buyerCartCount ?></strong><span>cart items</span></div>
                <div class="shop-customer-stat"><strong><?= (int)$buyerOpenOrders ?></strong><span>active orders</span></div>
              </div>
              <div class="shop-customer-actions">
                <a class="shop-customer-action" href="#order-history" data-shop-pref-target="order-history"><i class="icon ion-ios-list"></i> Orders</a>
                <a class="shop-customer-action" href="#seller-messages" data-shop-pref-target="seller-messages"><i class="icon ion-chatboxes"></i> Messages</a>
                <a class="shop-customer-action" href="#shopping-cart" data-shop-pref-target="shopping-cart"><i class="icon ion-ios-cart"></i> Cart</a>
                <a class="shop-customer-action" href="#support-tickets" data-shop-pref-target="support-tickets"><i class="icon ion-help-buoy"></i> Support</a>
              </div>
            </div>
            <div class="shop-pref-panel" id="order-history" data-shop-pref-panel="order-history">
              <div><p class="shop-customer-kicker">Order history</p><h2 class="shop-customer-name"><?= (int)count($buyerOrderHistoryRows) ?> order group<?= count($buyerOrderHistoryRows) === 1 ? '' : 's' ?></h2><p class="shop-customer-sub">Product # is how many products (bowl, cup, tomatoes). Quantity # is total units. Select a row to see the list on the right.</p></div>
              <div class="shop-invoice-layout">
                <div class="shop-table-stack">
                  <div class="shop-table-actions" aria-label="Order history actions">
                    <button type="button" class="shop-table-action" data-print-invoice aria-label="Print invoice"><i class="icon ion-printer"></i></button>
                    <a class="shop-table-action" href="my_orders.php" aria-label="Download invoice"><i class="icon ion-android-download"></i></a>
                  </div>
                  <div class="shop-pref-table-wrap"><table class="shop-pref-table">
                  <thead>
                    <tr>
                      <th class="shop-col-center">Order #</th>
                      <th>Seller</th>
                      <th class="shop-col-center">Quantity #</th>
                      <th>Status</th>
                      <th>Total</th>
                      <th>Date</th>
                      <th class="shop-order-cancel-col">Cancel order</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if ($buyerOrderHistoryRows): foreach ($buyerOrderHistoryRows as $index => $orderRow):
                    $productsPayload = array_map(static function (array $p): array {
                        return [
                            'title' => (string)$p['title'],
                            'qty' => (int)$p['qty'],
                            'amount' => (string)$p['amount'],
                        ];
                    }, $orderRow['products']);
                    $productsJson = htmlspecialchars(json_encode($productsPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    $cancellableCsv = implode(',', array_map('intval', $orderRow['cancellable_ids'] ?? []));
                  ?>
                    <tr
                      class="<?= $index === 0 ? 'is-selected' : '' ?>"
                      data-invoice-order
                      data-invoice-code="<?= h((string)$orderRow['invoice_label']) ?>"
                      data-invoice-status="<?= h((string)$orderRow['status']) ?>"
                      data-invoice-date="<?= h((string)$orderRow['date']) ?>"
                      data-invoice-due="<?= h((string)$orderRow['due']) ?>"
                      data-invoice-seller="<?= h((string)$orderRow['company']) ?>"
                      data-invoice-total="<?= h((string)$orderRow['total']) ?>"
                      data-invoice-contact-email="<?= h((string)$orderRow['contact_email']) ?>"
                      data-invoice-contact-phone="<?= h((string)$orderRow['contact_phone']) ?>"
                      data-invoice-contact-address="<?= h((string)$orderRow['contact_address']) ?>"
                      data-invoice-products="<?= $productsJson ?>"
                    >
                      <td class="shop-col-center"><?= (int)$orderRow['order_num'] ?></td>
                      <td><?= h((string)$orderRow['company']) ?></td>
                      <td class="shop-col-center"><?= (int)$orderRow['quantity_num'] ?></td>
                      <td><?= h((string)$orderRow['status']) ?></td>
                      <td><?= h((string)$orderRow['total']) ?></td>
                      <td><?= h((string)$orderRow['date']) ?></td>
                      <td>
                        <?php if (!empty($orderRow['cancellable']) && $cancellableCsv !== ''): ?>
                          <button type="button" class="shop-order-cancel-btn js-order-history-cancel" data-order-ids="<?= h($cancellableCsv) ?>">Cancel order</button>
                        <?php else: ?>
                          <span class="shop-addr-muted">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="7" class="shop-pref-table-empty">No orders yet.</td></tr>
                  <?php endif; ?>
                  </tbody>
                  </table></div>
                </div>
                <div class="shop-invoice-side">
                  <h2 class="shop-invoice-title">Order details</h2>
                  <aside class="shop-invoice-summary" aria-label="Order details">
                  <div class="shop-invoice-meta">
                    <div><div class="shop-invoice-number" data-invoice-field="code"><?= h($buyerOrderHistoryCode) ?></div><span class="shop-invoice-status" data-invoice-field="status"><?= h($buyerOrderHistoryStatus) ?></span></div>
                    <div class="shop-invoice-dates"><div><strong>Date</strong> <span data-invoice-field="date"><?= h($buyerInvoiceDate) ?></span></div><div><strong>Due Date</strong> <span data-invoice-field="due"><?= h($buyerInvoiceDueDate) ?></span></div></div>
                  </div>
                  <div class="shop-invoice-addresses">
                    <div class="shop-invoice-address"><strong>From:</strong><span data-invoice-field="seller"><?= h($buyerInvoiceSeller) ?></span><span>Seller organization</span></div>
                    <div class="shop-invoice-address"><strong>To:</strong><span><?= h($buyerDisplayName) ?></span><span><?= $buyerProfile['email'] !== '' ? h($buyerProfile['email']) : 'Customer account' ?></span><?php if ($buyerProfile['phone'] !== ''): ?><span><?= h($buyerProfile['phone']) ?></span><?php endif; ?></div>
                  </div>
                  <div class="shop-invoice-items" data-invoice-field="items-list">
                    <?php if ($buyerOrderHistorySelected && !empty($buyerOrderHistorySelected['products'])): ?>
                      <div class="shop-invoice-items-head"><span>Product</span><span style="text-align:right">Qty</span><span style="text-align:right">Amount</span></div>
                      <?php foreach ($buyerOrderHistorySelected['products'] as $productLine): ?>
                        <div class="shop-invoice-product-line">
                          <span class="shop-invoice-product-title"><?= h((string)$productLine['title']) ?></span>
                          <span class="shop-invoice-product-qty"><?= (int)$productLine['qty'] ?></span>
                          <span class="shop-invoice-product-amount"><?= h((string)$productLine['amount']) ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="shop-invoice-items-empty">No products in this order.</div>
                    <?php endif; ?>
                  </div>
                  <div class="shop-invoice-line"><span>Sub Total :</span><strong data-invoice-field="subtotal"><?= h($buyerOrderHistoryTotal) ?></strong></div>
                  <div class="shop-invoice-line"><span>Discount :</span><strong><?= h(org_shop_format_price($buyerInvoiceDiscountCents, 'USD')) ?></strong></div>
                  <div class="shop-invoice-line"><span>Taxes :</span><strong><?= h(org_shop_format_price($buyerInvoiceTaxCents, 'USD')) ?></strong></div>
                  <div class="shop-invoice-line"><strong>Grand Total :</strong><strong data-invoice-field="grand"><?= h($buyerOrderHistoryTotal) ?></strong></div>
                  <div class="shop-invoice-note">
                    <h3>Note</h3>
                    <p>Click an order in the table to review its products and quantities here.</p>
                  </div>
                  <a class="shop-invoice-download" href="my_orders.php">Download</a>
                  <div class="shop-invoice-seller-contact">
                    <h3>Seller contact</h3>
                    <p data-invoice-field="contact-email" data-invoice-empty="<?= $buyerInvoiceContactEmail === '' ? '1' : '0' ?>">
                      <?php if ($buyerInvoiceContactEmail !== ''): ?>
                        Email: <a href="mailto:<?= h($buyerInvoiceContactEmail) ?>"><?= h($buyerInvoiceContactEmail) ?></a>
                      <?php endif; ?>
                    </p>
                    <p data-invoice-field="contact-phone" data-invoice-empty="<?= $buyerInvoiceContactPhone === '' ? '1' : '0' ?>">
                      <?php if ($buyerInvoiceContactPhone !== ''): ?>
                        Phone: <a href="tel:<?= h(preg_replace('/\s+/', '', $buyerInvoiceContactPhone)) ?>"><?= h($buyerInvoiceContactPhone) ?></a>
                      <?php endif; ?>
                    </p>
                    <p data-invoice-field="contact-address" data-invoice-empty="<?= $buyerInvoiceContactAddress === '' ? '1' : '0' ?>"><?= $buyerInvoiceContactAddress !== '' ? h($buyerInvoiceContactAddress) : '' ?></p>
                    <p data-invoice-field="contact-fallback" data-invoice-empty="<?= ($buyerInvoiceContactEmail !== '' || $buyerInvoiceContactPhone !== '' || $buyerInvoiceContactAddress !== '') ? '1' : '0' ?>">Contact details not provided by this seller.</p>
                  </div>
                  </aside>
                </div>
              </div>
            </div>
            <div class="shop-pref-panel" id="order-details" data-shop-pref-panel="order-details">
              <div><p class="shop-customer-kicker">Order details</p><h2 class="shop-customer-name"><?= $buyerRecentOrderCode !== '' ? h($buyerRecentOrderCode) : 'No selected order' ?></h2><p class="shop-customer-sub"><?= $buyerRecentOrderId > 0 ? 'Status: ' . h($buyerRecentStatus) : 'Products, quantities, prices, and order status will show here.' ?></p></div>
              <ul class="shop-pref-panel-list">
                <li class="shop-pref-panel-item"><strong>Order ID</strong><span><?= $buyerRecentOrderId > 0 ? (int)$buyerRecentOrderId : 'No order yet' ?></span></li>
                <li class="shop-pref-panel-item"><strong>Return eligible</strong><span><?= (int)$buyerReturnable ?> order<?= (int)$buyerReturnable === 1 ? '' : 's' ?> currently eligible.</span></li>
              </ul>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table">
                <thead><tr><th>Field</th><th>Information</th></tr></thead>
                <tbody>
                  <tr><td>Order code</td><td><?= $buyerRecentOrderCode !== '' ? h($buyerRecentOrderCode) : 'No order selected' ?></td></tr>
                  <tr><td>Status</td><td><?= $buyerRecentStatus !== '' ? h($buyerRecentStatus) : 'Not available' ?></td></tr>
                  <tr><td>Receipt</td><td><?= !empty($buyerOrders[0]['receipt_code'] ?? '') ? h((string)$buyerOrders[0]['receipt_code']) : 'No receipt yet' ?></td></tr>
                  <tr><td>Seller</td><td><?= !empty($buyerOrders[0]['seller_name'] ?? '') ? h((string)$buyerOrders[0]['seller_name']) : 'Not available' ?></td></tr>
                </tbody>
              </table></div>
            </div>
            <div class="shop-pref-panel" id="shopping-cart" data-shop-pref-panel="shopping-cart">
              <div><p class="shop-customer-kicker">Shopping cart</p><h2 class="shop-customer-name"><?= (int)$buyerCartCount ?> cart item<?= (int)$buyerCartCount === 1 ? '' : 's' ?></h2><p class="shop-customer-sub">Products ready for checkout from organization sellers.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table">
                <thead><tr><th>Product</th><th>Seller</th><th>Qty</th><th>Price</th><th>Stock</th></tr></thead>
                <tbody>
                <?php if ($buyerCartItems): foreach (array_slice($buyerCartItems, 0, 8) as $item): ?>
                  <tr><td><?= h((string)($item['title'] ?? 'Product')) ?></td><td><?= h((string)($item['seller_name'] ?? 'Seller')) ?></td><td><?= (int)($item['quantity'] ?? 0) ?></td><td><?= h(org_shop_format_price((int)($item['price_cents'] ?? 0), (string)($item['currency'] ?? 'USD'))) ?></td><td><?= h((string)($item['stock_qty'] ?? '')) ?></td></tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="5" class="shop-pref-table-empty">Your cart is empty.</td></tr>
                <?php endif; ?>
                </tbody>
              </table></div>
            </div>
            <div class="shop-pref-panel" id="wishlist" data-shop-pref-panel="wishlist">
              <div><p class="shop-customer-kicker">Wishlist</p><h2 class="shop-customer-name">Saved products</h2><p class="shop-customer-sub">Products saved in the cart for later checkout show here.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Saved item</th><th>Seller</th><th>Status</th></tr></thead><tbody>
                <?php if ($buyerCartItems): foreach (array_slice($buyerCartItems, 0, 6) as $item): ?>
                  <tr><td><?= h((string)($item['title'] ?? 'Product')) ?></td><td><?= h((string)($item['seller_name'] ?? 'Seller')) ?></td><td>Saved in cart</td></tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="3" class="shop-pref-table-empty">No saved products yet.</td></tr>
                <?php endif; ?>
              </tbody></table></div>
            </div>
            <div class="shop-pref-panel" id="invoices-payments" data-shop-pref-panel="invoices-payments">
              <div><p class="shop-customer-kicker">Invoices &amp; payments</p><h2 class="shop-customer-name"><?= (int)count($buyerOrderHistoryRows) ?> compan<?= count($buyerOrderHistoryRows) === 1 ? 'y' : 'ies' ?> · <?= (int)$buyerReceipts ?> receipt<?= (int)$buyerReceipts === 1 ? '' : 's' ?></h2><p class="shop-customer-sub">Product # is how many products. Quantity # is total units. Select a row to see products on the right.</p></div>
              <div class="shop-invoice-layout">
                <div class="shop-pref-table-wrap"><table class="shop-pref-table">
                  <thead>
                    <tr>
                      <th>Receipt</th>
                      <th class="shop-col-center">Product #</th>
                      <th>Company name</th>
                      <th class="shop-col-center">Quantity #</th>
                      <th>Amount</th>
                      <th>Status</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if ($buyerOrderHistoryRows): foreach ($buyerOrderHistoryRows as $index => $payRow):
                    $productsPayload = array_map(static function (array $p): array {
                        return [
                            'title' => (string)$p['title'],
                            'qty' => (int)$p['qty'],
                            'amount' => (string)$p['amount'],
                        ];
                    }, $payRow['products']);
                    $productsJson = htmlspecialchars(json_encode($productsPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                  ?>
                    <tr
                      class="<?= $index === 0 ? 'is-selected' : '' ?>"
                      data-payment-order
                      data-payment-code="<?= h((string)$payRow['invoice_label']) ?>"
                      data-payment-status="<?= h((string)$payRow['status']) ?>"
                      data-payment-total="<?= h((string)$payRow['total']) ?>"
                      data-payment-company="<?= h((string)$payRow['company']) ?>"
                      data-payment-date="<?= h((string)$payRow['date']) ?>"
                      data-payment-products="<?= $productsJson ?>"
                    >
                      <td><?= h((string)$payRow['receipt_label']) ?></td>
                      <td class="shop-col-center"><?= (int)$payRow['order_num'] ?></td>
                      <td><?= h((string)$payRow['company']) ?></td>
                      <td class="shop-col-center"><?= (int)$payRow['quantity_num'] ?></td>
                      <td><?= h((string)$payRow['total']) ?></td>
                      <td><?= h((string)$payRow['status']) ?></td>
                      <td><?= h((string)$payRow['date']) ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="7" class="shop-pref-table-empty">No invoices or payments yet.</td></tr>
                  <?php endif; ?>
                </tbody></table></div>
                <div class="shop-payment-side">
                  <div class="shop-payment-heading">
                    <h3>Payment info</h3>
                    <div class="shop-payment-code">Invoice <span data-payment-field="code"><?= $buyerPaymentSelected ? h((string)$buyerPaymentSelected['invoice_label']) : 'Pending' ?></span></div>
                  </div>
                <aside class="shop-payment-summary" aria-label="Payment information">
                  <div class="shop-payment-section">
                    <div class="shop-payment-company" data-payment-field="company"><?= $buyerPaymentSelected ? h((string)$buyerPaymentSelected['company']) : 'Seller' ?></div>
                    <div class="shop-payment-status">
                      <span class="shop-payment-icon"><i class="icon ion-clock"></i></span>
                      <strong class="shop-payment-state" data-payment-field="status"><?= h($buyerPaymentStatus) ?></strong>
                      <strong class="shop-payment-amount" data-payment-field="amount"><?= h($buyerPaymentTotal) ?></strong>
                    </div>
                    <div class="shop-payment-items" data-payment-field="items-list">
                      <?php if ($buyerPaymentSelected && !empty($buyerPaymentSelected['products'])): ?>
                        <div class="shop-invoice-items-head"><span>Product</span><span style="text-align:right">Qty</span><span style="text-align:right">Amount</span></div>
                        <?php foreach ($buyerPaymentSelected['products'] as $productLine): ?>
                          <div class="shop-invoice-product-line">
                            <span class="shop-invoice-product-title"><?= h((string)$productLine['title']) ?></span>
                            <span class="shop-invoice-product-qty"><?= (int)$productLine['qty'] ?></span>
                            <span class="shop-invoice-product-amount"><?= h((string)$productLine['amount']) ?></span>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="shop-payment-items-empty">No products in this invoice.</div>
                      <?php endif; ?>
                    </div>
                    <div class="shop-payment-line"><span>Shipping</span><span class="shop-payment-free">Free</span></div>
                    <div class="shop-payment-line"><span>Tax*</span><span>$0.00</span></div>
                    <div class="shop-payment-line shop-payment-total"><strong>Order total</strong><strong data-payment-field="total"><?= h($buyerPaymentTotal) ?></strong></div>
                    <p class="shop-payment-tax-note">*We're required by law to collect sales tax and applicable fees for certain tax authorities.</p>
                  </div>
                  <div class="shop-payment-section">
                    <h3>Shipping address</h3>
                    <div class="shop-payment-address"><?= h($buyerDisplayName) ?></div>
                    <div class="shop-payment-address"><?= $buyerProfile['email'] !== '' ? h($buyerProfile['email']) : 'Customer account' ?></div>
                  </div>
                </aside>
                </div>
              </div>
            </div>
            <div class="shop-pref-panel" id="returns-refunds" data-shop-pref-panel="returns-refunds">
              <div><p class="shop-customer-kicker">Returns &amp; refunds</p><h2 class="shop-customer-name"><?= (int)$buyerReturnRequests ?> request<?= (int)$buyerReturnRequests === 1 ? '' : 's' ?></h2><p class="shop-customer-sub"><?= (int)$buyerReturnable ?> order<?= (int)$buyerReturnable === 1 ? '' : 's' ?> eligible for returns or refund review.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Order</th><th>Reason</th><th>Status</th><th>Date</th></tr></thead><tbody>
                <?php if ($buyerReturnRows): foreach ($buyerReturnRows as $row): ?>
                  <tr><td><?= h((string)($row['order_code'] ?? ('#' . (int)($row['order_id'] ?? 0)))) ?></td><td><?= h((string)($row['reason'] ?? 'Return request')) ?></td><td><?= h((string)($row['status'] ?? 'pending')) ?></td><td><?= h(pref_date($row['created_at'] ?? '')) ?></td></tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="4" class="shop-pref-table-empty">No return or refund requests yet.</td></tr>
                <?php endif; ?>
              </tbody></table></div>
            </div>
            <div class="shop-pref-panel" id="seller-relationships" data-shop-pref-panel="seller-relationships">
              <div>
                <p class="shop-customer-kicker">Seller relationships</p>
                <h2 class="shop-customer-name"><?= (int)count($buyerSellerRels) ?> seller<?= count($buyerSellerRels) === 1 ? '' : 's' ?></h2>
                <p class="shop-customer-sub">Tell sellers what you need — shopping style, delivery, budget, and notes — so they can serve you better.</p>
              </div>
              <?php if ($relFlashOk !== ''): ?><div class="alert alert-success"><?= h($relFlashOk) ?></div><?php endif; ?>
              <?php if ($relFlashErr !== ''): ?><div class="alert alert-danger"><?= h($relFlashErr) ?></div><?php endif; ?>
              <?php if (!$buyerSellerRels): ?>
                <div class="shop-pref-table-wrap"><table class="shop-pref-table"><tbody><tr><td class="shop-pref-table-empty">Order from a seller first, then share your preferences here.</td></tr></tbody></table></div>
              <?php else: ?>
                <div class="shop-rel-layout">
                  <div class="shop-pref-table-wrap shop-rel-table"><table class="shop-pref-table">
                    <thead><tr><th>Seller</th><th>Orders</th><th>Last order</th><th>Shared prefs</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($buyerSellerRels as $relRow):
                      $relOrgId = (int)($relRow['org_id'] ?? 0);
                      $relPubId = (int)($relRow['publisher_user_id'] ?? 0);
                      $hasRel = (int)($relRow['relationship_id'] ?? 0) > 0;
                      $shared = $hasRel && !empty($relRow['share_with_seller']);
                      $msgUrl = $relPubId > 0 ? commerce_message_seller_url($relPubId) : 'messages.php';
                    ?>
                      <tr class="<?= $relOrgId === $buyerSellerRelEditOrg ? 'is-selected' : '' ?>">
                        <td><?= h((string)($relRow['seller_name'] ?? 'Seller')) ?></td>
                        <td><?= (int)($relRow['order_count'] ?? 0) ?></td>
                        <td><?= h(pref_date($relRow['last_ordered_at'] ?? '')) ?></td>
                        <td><?= $shared ? 'Shared' : ($hasRel ? 'Private' : 'Not set') ?></td>
                        <td>
                          <a href="Your_Shopping_preferences.php?seller_org=<?= $relOrgId ?>#seller-relationships">Edit</a>
                          · <a href="<?= h($msgUrl) ?>">Message</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table></div>
                  <?php if ($buyerSellerRelEdit):
                    $editOrgId = (int)($buyerSellerRelEdit['org_id'] ?? 0);
                    $editPubId = (int)($buyerSellerRelEdit['publisher_user_id'] ?? 0);
                    $editMsgUrl = $editPubId > 0 ? commerce_message_seller_url($editPubId) : 'messages.php';
                    $editType = (string)($buyerSellerRelEdit['relationship_type'] ?? 'shopper');
                    if ($editType === '') $editType = 'shopper';
                    $editContact = (string)($buyerSellerRelEdit['preferred_contact'] ?? 'message');
                    if ($editContact === '') $editContact = 'message';
                  ?>
                    <div class="shop-rel-card">
                      <h3><?= h((string)($buyerSellerRelEdit['seller_name'] ?? 'Seller')) ?></h3>
                      <p>Share only what helps this seller meet your needs. You can stop sharing anytime.</p>
                      <form method="post" class="shop-rel-form" action="Your_Shopping_preferences.php?seller_org=<?= $editOrgId ?>#seller-relationships">
                        <input type="hidden" name="buyer_seller_rel_action" value="save">
                        <input type="hidden" name="org_id" value="<?= $editOrgId ?>">
                        <div class="form-group">
                          <label for="relationship_type">How you shop with them</label>
                          <select class="form-control" id="relationship_type" name="relationship_type">
                            <?php foreach (buyer_seller_rel_types() as $opt): ?>
                              <option value="<?= h($opt['value']) ?>" <?= $editType === $opt['value'] ? 'selected' : '' ?>><?= h($opt['label']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="form-group">
                          <label for="interests">Interests / what you’re looking for</label>
                          <input class="form-control" id="interests" name="interests" maxlength="500" value="<?= h((string)($buyerSellerRelEdit['interests'] ?? '')) ?>" placeholder="e.g. office supplies, kids gifts, eco-friendly">
                        </div>
                        <div class="form-group">
                          <label for="preferred_contact">Preferred contact</label>
                          <select class="form-control" id="preferred_contact" name="preferred_contact">
                            <?php foreach (buyer_seller_rel_contact_options() as $opt): ?>
                              <option value="<?= h($opt['value']) ?>" <?= $editContact === $opt['value'] ? 'selected' : '' ?>><?= h($opt['label']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="form-group">
                          <label for="delivery_preference">Delivery preference</label>
                          <input class="form-control" id="delivery_preference" name="delivery_preference" maxlength="80" value="<?= h((string)($buyerSellerRelEdit['delivery_preference'] ?? '')) ?>" placeholder="e.g. weekend delivery, pickup, leave at door">
                        </div>
                        <div class="form-group">
                          <label for="budget_range">Typical budget</label>
                          <input class="form-control" id="budget_range" name="budget_range" maxlength="40" value="<?= h((string)($buyerSellerRelEdit['budget_range'] ?? '')) ?>" placeholder="e.g. under $50, $100–$250">
                        </div>
                        <div class="form-group">
                          <label for="needs_note">Note for the seller</label>
                          <textarea class="form-control" id="needs_note" name="needs_note" rows="3" placeholder="Anything that helps them recommend or fulfill for you"><?= h((string)($buyerSellerRelEdit['needs_note'] ?? '')) ?></textarea>
                        </div>
                        <label class="tx-12"><input type="checkbox" name="share_with_seller" value="1" <?= ((int)($buyerSellerRelEdit['relationship_id'] ?? 0) === 0 || !empty($buyerSellerRelEdit['share_with_seller'])) ? 'checked' : '' ?>> Share these preferences with this seller</label>
                        <div class="shop-rel-actions">
                          <button type="submit" class="btn btn-primary btn-sm">Save preferences</button>
                          <a class="btn btn-outline-secondary btn-sm" href="<?= h($editMsgUrl) ?>">Message seller</a>
                        </div>
                      </form>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="shop-pref-panel" id="seller-messages" data-shop-pref-panel="seller-messages">
              <div>
                <p class="shop-customer-kicker">Messages</p>
                <h2 class="shop-customer-name">Chat with sellers</h2>
                <p class="shop-customer-sub">Ask about a product, order, pickup, or delivery — messages go directly to the seller.</p>
              </div>
              <?php if (!$sellerMsgContacts): ?>
                <div class="shop-seller-msg-empty">
                  No seller chats yet. Open a product and choose <strong>Message seller about this product</strong>, or place an order first.
                  <div class="mg-t-10"><a class="btn btn-sm btn-primary" href="shop.php">Browse shop</a></div>
                </div>
              <?php else: ?>
                <div class="shop-seller-msg-layout" id="shopSellerMsgRoot"
                  data-peer="<?= h((string)($sellerMsgActive['friend_code'] ?? '')) ?>"
                  data-peer-name="<?= h((string)($sellerMsgActive['seller_name'] ?? 'Seller')) ?>"
                  data-draft="<?= h($sellerMsgDraft) ?>"
                >
                  <div class="shop-seller-msg-list" aria-label="Sellers">
                    <?php foreach ($sellerMsgContacts as $c):
                      $cid = (int)($c['publisher_user_id'] ?? 0);
                      $isActive = $cid === (int)($sellerMsgActive['publisher_user_id'] ?? 0);
                      $href = commerce_message_seller_url($cid, $isActive ? $sellerMsgAboutProduct : 0, $isActive ? $sellerMsgAboutOrder : '');
                    ?>
                      <a class="shop-seller-msg-item<?= $isActive ? ' is-active' : '' ?>" href="<?= h($href) ?>" data-peer="<?= h((string)($c['friend_code'] ?? '')) ?>">
                        <strong><?= h((string)($c['seller_name'] ?? 'Seller')) ?><?php if ((int)($c['unread'] ?? 0) > 0): ?> <span class="shop-pref-nav-badge"><?= (int)$c['unread'] ?></span><?php endif; ?></strong>
                        <span><?= h((string)(($c['last_message'] !== '' ? $c['last_message'] : 'Start a conversation'))) ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                  <div class="shop-seller-msg-chat">
                    <div class="shop-seller-msg-head" id="shopSellerMsgHead"><?= h((string)($sellerMsgActive['seller_name'] ?? 'Seller')) ?></div>
                    <div class="shop-seller-msg-thread" id="shopSellerMsgThread" aria-live="polite"></div>
                    <div class="shop-seller-msg-compose">
                      <textarea id="shopSellerMsgInput" class="form-control" rows="2" placeholder="Write a message about the product or order…"></textarea>
                      <button type="button" class="btn btn-primary btn-sm" id="shopSellerMsgSend">Send</button>
                    </div>
                    <p class="tx-danger tx-12 mg-b-0" id="shopSellerMsgErr" style="padding:0 10px 8px;" hidden></p>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <div class="shop-pref-panel" id="reviews-ratings" data-shop-pref-panel="reviews-ratings">
              <div><p class="shop-customer-kicker">Reviews &amp; ratings</p><h2 class="shop-customer-name"><?= (int)$buyerReviews ?> submitted review<?= (int)$buyerReviews === 1 ? '' : 's' ?></h2><p class="shop-customer-sub">Product reviews submitted by this customer account.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Product</th><th>Rating</th><th>Review</th><th>Date</th></tr></thead><tbody>
                <?php if ($buyerReviewRows): foreach ($buyerReviewRows as $row): ?>
                  <tr><td><?= h((string)($row['product_title'] ?? 'Product')) ?></td><td><?= (int)($row['rating'] ?? 0) ?>/5</td><td><?= h((string)($row['review_text'] ?? '')) ?></td><td><?= h(pref_date($row['created_at'] ?? '')) ?></td></tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="4" class="shop-pref-table-empty">No reviews submitted yet.</td></tr>
                <?php endif; ?>
              </tbody></table></div>
            </div>
            <div class="shop-pref-panel" id="addresses" data-shop-pref-panel="addresses">
              <div>
                <p class="shop-customer-kicker">Addresses</p>
                <h2 class="shop-customer-name">Billing &amp; shipping</h2>
                <p class="shop-customer-sub">Your contact and shipping details for seller checkout. Edit to update, then save.</p>
              </div>
              <?php if ($addrFlashOk !== ''): ?><div class="alert alert-success"><?= h($addrFlashOk) ?></div><?php endif; ?>
              <?php if ($addrFlashErr !== ''): ?><div class="alert alert-danger"><?= h($addrFlashErr) ?></div><?php endif; ?>
              <?php
                $addrEdit = is_array($buyerDefaultAddress) ? $buyerDefaultAddress : [];
                $addrEditId = (int)($addrEdit['id'] ?? 0);
                $addrEditName = trim((string)($addrEdit['full_name'] ?? ''));
                if ($addrEditName === '') {
                    $addrEditName = $buyerDisplayName;
                }
                // Account registration phone/name first; shipping address overrides phone only when set.
                $addrEditPhone = (string)$buyerProfile['phone'];
                $addrShipPhone = trim((string)($addrEdit['phone'] ?? ''));
                if ($addrShipPhone !== '') {
                    $addrEditPhone = $addrShipPhone;
                }
                $addrEditLabel = trim((string)($addrEdit['label'] ?? '')) ?: 'Home';
                $addrDisplayPhone = $addrEditPhone !== '' ? $addrEditPhone : 'Not set';
                $addrHasShipping = $addrEditId > 0 && trim((string)($addrEdit['line1'] ?? '')) !== '';
              ?>
              <div class="shop-addr-view">
                <div class="shop-addr-card">
                  <div class="shop-addr-card-head">
                    <h3>Contact</h3>
                    <button type="button" class="shop-addr-edit-btn" data-open-addr-modal>Edit</button>
                  </div>
                  <p class="shop-addr-line"><strong>Name</strong> <?= h($buyerDisplayName) ?></p>
                  <p class="shop-addr-line"><strong>Email</strong> <?= $buyerProfile['email'] !== '' ? h($buyerProfile['email']) : '<span class="shop-addr-muted">Not set</span>' ?></p>
                  <p class="shop-addr-line"><strong>Phone</strong> <?= $buyerProfile['phone'] !== '' ? h($buyerProfile['phone']) : '<span class="shop-addr-muted">Not set</span>' ?></p>
                </div>
                <div class="shop-addr-card">
                  <div class="shop-addr-card-head">
                    <h3>Shipping address</h3>
                    <button type="button" class="shop-addr-edit-btn" data-open-addr-modal><?= $addrHasShipping ? 'Edit' : 'Add' ?></button>
                  </div>
                  <?php if ($addrHasShipping): ?>
                    <p class="shop-addr-line"><strong>Label</strong> <?= h($addrEditLabel) ?><?= !empty($addrEdit['is_default']) ? ' · Default' : '' ?></p>
                    <p class="shop-addr-block"><?= h(buyer_shipping_format_text($addrEdit)) ?></p>
                  <?php else: ?>
                    <p class="shop-addr-muted mg-b-0">No shipping address yet. Click Add to save one for checkout.</p>
                  <?php endif; ?>
                </div>
              </div>

              <div class="shop-addr-modal" id="addrEditModal" aria-hidden="true">
                <div class="shop-addr-modal-backdrop" data-close-addr-modal></div>
                <div class="shop-addr-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addrEditTitle">
                  <h3 id="addrEditTitle"><?= $addrHasShipping ? 'Edit address &amp; contact' : 'Add shipping address' ?></h3>
                  <p>Update your details, then save to refresh this page.</p>
                  <form method="post" action="Your_Shopping_preferences.php#addresses" class="shop-rel-form">
                    <input type="hidden" name="buyer_addr_action" value="save">
                    <input type="hidden" name="address_id" value="<?= $addrEditId ?>">
                    <div class="row">
                      <div class="col-md-4 form-group"><label for="addr_label">Label</label><input id="addr_label" name="label" class="form-control" value="<?= h($addrEditLabel) ?>"></div>
                      <div class="col-md-8 form-group"><label for="addr_full_name">Full name</label><input id="addr_full_name" name="full_name" class="form-control" value="<?= h($addrEditName) ?>" required></div>
                    </div>
                    <div class="form-group"><label for="addr_line1">Street address</label><input id="addr_line1" name="line1" class="form-control" value="<?= h((string)($addrEdit['line1'] ?? '')) ?>" required></div>
                    <div class="form-group"><label for="addr_line2">Apt / suite (optional)</label><input id="addr_line2" name="line2" class="form-control" value="<?= h((string)($addrEdit['line2'] ?? '')) ?>"></div>
                    <div class="row">
                      <div class="col-md-4 form-group"><label for="addr_city">City</label><input id="addr_city" name="city" class="form-control" value="<?= h((string)($addrEdit['city'] ?? '')) ?>"></div>
                      <div class="col-md-4 form-group"><label for="addr_region">State / region</label><input id="addr_region" name="region" class="form-control" value="<?= h((string)($addrEdit['region'] ?? '')) ?>"></div>
                      <div class="col-md-4 form-group"><label for="addr_postal">Postal code</label><input id="addr_postal" name="postal_code" class="form-control" value="<?= h((string)($addrEdit['postal_code'] ?? '')) ?>"></div>
                    </div>
                    <div class="row">
                      <div class="col-md-4 form-group"><label for="addr_country">Country</label><input id="addr_country" name="country" class="form-control" value="<?= h((string)($addrEdit['country'] ?? 'US')) ?>"></div>
                      <div class="col-md-8 form-group"><label for="addr_phone">Phone</label><input id="addr_phone" name="phone" class="form-control" value="<?= h($addrEditPhone) ?>"></div>
                    </div>
                    <label class="tx-12"><input type="checkbox" name="is_default" value="1" <?= ($addrEditId <= 0 || !empty($addrEdit['is_default'])) ? 'checked' : '' ?>> Use as default for checkout</label>
                    <div class="shop-addr-modal-actions">
                      <button type="submit" class="btn btn-primary btn-sm">Save</button>
                      <button type="button" class="btn btn-outline-secondary btn-sm" data-close-addr-modal>Cancel</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <div class="shop-pref-panel" id="contact-information" data-shop-pref-panel="contact-information">
              <div><p class="shop-customer-kicker">Contact information</p><h2 class="shop-customer-name"><?= h($buyerDisplayName) ?></h2><p class="shop-customer-sub">Email: <?= $buyerProfile['email'] !== '' ? h($buyerProfile['email']) : 'Not set' ?><?php if ($buyerProfile['phone'] !== ''): ?> · Phone: <?= h($buyerProfile['phone']) ?><?php endif; ?></p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody><tr><td>Name</td><td><?= h($buyerDisplayName) ?></td></tr><tr><td>Email</td><td><?= $buyerProfile['email'] !== '' ? h($buyerProfile['email']) : 'Not set' ?></td></tr><tr><td>Phone</td><td><?= $buyerProfile['phone'] !== '' ? h($buyerProfile['phone']) : 'Not set' ?></td></tr></tbody></table></div>
            </div>
            <div class="shop-pref-panel" id="customer-groups" data-shop-pref-panel="customer-groups">
              <div><p class="shop-customer-kicker">Customer groups</p><h2 class="shop-customer-name">Retail customer</h2><p class="shop-customer-sub">Retail, wholesale, and VIP status is managed by seller organizations.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Group</th><th>Managed by</th><th>Status</th></tr></thead><tbody><tr><td>Retail</td><td>Seller organizations</td><td>Default</td></tr><tr><td>Wholesale</td><td>Seller organizations</td><td>Not assigned</td></tr><tr><td>VIP</td><td>Seller organizations</td><td>Not assigned</td></tr></tbody></table></div>
            </div>
            <div class="shop-pref-panel" id="notifications" data-shop-pref-panel="notifications">
              <div class="shop-buyer-notif-sticky">
                <div>
                  <p class="shop-customer-kicker">Notifications</p>
                  <h2 class="shop-customer-name"><?= (int)$buyerAlertCount ?> alert<?= $buyerAlertCount === 1 ? '' : 's' ?></h2>
                  <p class="shop-customer-sub">Your order lifecycle hub — Pending → Paid → Shipping → Delivery. Cancel = seller stopped the order; Cancellation = you cancelled.</p>
                </div>

                <div class="shop-buyer-notif-life">
                  <h3>Order lifecycle</h3>
                  <p>
                    Counts update automatically: pay → Paid; seller Cancel vs your Cancellation stay separate; seller ships → Shipping; you receive → Delivery.
                    <?= (int)$buyerLifeTotal ?> order<?= $buyerLifeTotal === 1 ? '' : 's' ?> across stages.
                  </p>
                  <div class="shop-buyer-notif-grid" role="list">
                    <?php foreach ($buyerLifeStages as $stage): ?>
                      <?php $hot = (int)$stage['count'] > 0; ?>
                      <a
                        href="<?= h((string)$stage['href']) ?>"
                        class="shop-buyer-notif-stage<?= $hot ? ' is-hot' : '' ?>"
                        role="listitem"
                      >
                        <span class="shop-buyer-notif-stage-top">
                          <span class="shop-buyer-notif-stage-label"><?= h((string)$stage['label']) ?></span>
                          <span class="shop-buyer-notif-stage-count"><?= (int)$stage['count'] ?></span>
                        </span>
                        <span class="shop-buyer-notif-stage-hint"><?= h((string)$stage['hint']) ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>

                <?php if ($buyerCommerceAlerts): ?>
                  <div class="shop-buyer-notif-section">Action alerts</div>
                  <div class="shop-buyer-notif-alerts">
                    <?php foreach ($buyerCommerceAlerts as $alert): ?>
                      <?php
                        $badgeCount = max(0, (int)($alert['count'] ?? 0));
                        $badgeLabel = $badgeCount > 99 ? '99+' : (string)$badgeCount;
                      ?>
                      <a href="<?= h((string)$alert['action']) ?>" class="shop-buyer-notif-alert">
                        <span class="shop-buyer-notif-alert-title">
                          <?= h((string)$alert['type']) ?>
                          <?php if ($badgeCount > 0): ?>
                            <b class="shop-buyer-notif-alert-badge" aria-label="<?= h($badgeLabel . ' items') ?>"><?= h($badgeLabel) ?></b>
                          <?php endif; ?>
                        </span>
                        <span class="shop-buyer-notif-alert-copy"><?= h((string)$alert['message']) ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <p class="shop-customer-sub" style="margin:0;">No action alerts right now. Lifecycle counts above stay current as orders move.</p>
                <?php endif; ?>
              </div>

              <?php if ($buyerCommerceNotifications): ?>
                <div class="shop-buyer-notif-scroll">
                  <div class="shop-buyer-notif-section">Recent order updates</div>
                  <div class="shop-buyer-notif-feed">
                    <?php foreach (array_slice($buyerCommerceNotifications, 0, 12) as $n): ?>
                      <a href="<?= h((string)($n['action'] ?? 'my_orders.php')) ?>" class="shop-buyer-notif-feed-row">
                        <strong><?= h((string)($n['title'] ?? $n['type'] ?? 'Update')) ?></strong>
                        <span><?= h((string)($n['from'] ?? 'Seller')) ?> · <?= h((string)($n['message'] ?? '')) ?></span>
                        <?php if (trim((string)($n['when'] ?? '')) !== ''): ?>
                          <span class="shop-buyer-notif-feed-when"><?= h((string)$n['when']) ?></span>
                        <?php endif; ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <div class="shop-pref-panel" id="loyalty-program" data-shop-pref-panel="loyalty-program">
              <div><p class="shop-customer-kicker">Loyalty program</p><h2 class="shop-customer-name"><?= (int)floor($buyerSpentCents / 1000) ?> reward points</h2><p class="shop-customer-sub">Estimated reward points from purchases.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Source</th><th>Spending</th><th>Points</th></tr></thead><tbody><tr><td>Purchases</td><td><?= h(org_shop_format_price((int)$buyerSpentCents, 'USD')) ?></td><td><?= (int)floor($buyerSpentCents / 1000) ?></td></tr></tbody></table></div>
            </div>
            <div class="shop-pref-panel" id="support-tickets" data-shop-pref-panel="support-tickets">
              <div><p class="shop-customer-kicker">Support tickets</p><h2 class="shop-customer-name">Customer service</h2><p class="shop-customer-sub">Support requests and seller help details show here.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Ticket</th><th>Topic</th><th>Status</th></tr></thead><tbody><tr><td>None</td><td>No active customer service requests</td><td>Closed</td></tr></tbody></table></div>
            </div>
            <div class="shop-pref-panel" id="documents" data-shop-pref-panel="documents">
              <div><p class="shop-customer-kicker">Documents</p><h2 class="shop-customer-name">Buyer documents</h2><p class="shop-customer-sub">Tax ID or business files for B2B seller checks show here.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Document</th><th>Use</th><th>Status</th></tr></thead><tbody><tr><td>Tax ID</td><td>B2B seller checks</td><td>Not uploaded</td></tr><tr><td>Business license</td><td>Wholesale review</td><td>Not uploaded</td></tr></tbody></table></div>
            </div>
            <div class="shop-pref-panel" id="support-center" data-shop-pref-panel="support-center">
              <div>
                <p class="shop-customer-kicker">Support Center</p>
                <h2 class="shop-customer-name">Chat with Admin</h2>
                <p class="shop-customer-sub">Open a dispute against a seller, or ask Admin for help with an order, payment, or account issue.</p>
              </div>
              <div class="shop-admin-support" id="shopAdminSupportRoot" data-endpoint="ajax/admin_support_chat.php">
                <div class="shop-admin-support-guide">
                  <h3>How to get Admin help</h3>
                  <ol>
                    <li>Try <strong>Message sellers</strong> first for product, pickup, or delivery questions.</li>
                    <li>Choose <strong>Dispute with seller</strong> if the seller will not resolve an order problem.</li>
                    <li>Choose <strong>Need help</strong> for payments, account, or other platform questions.</li>
                    <li>Add the order code and seller name when you can, then send your message.</li>
                    <li>Admin replies appear in this same chat thread.</li>
                  </ol>
                  <p>Disputes and help requests go to Admin — not to the seller.</p>
                </div>
                <div class="shop-admin-support-chat">
                  <div class="shop-admin-support-head">Admin support chat</div>
                  <div class="shop-admin-support-topics" role="group" aria-label="Support topic">
                    <button type="button" class="shop-admin-topic is-active" data-topic="dispute">Dispute with seller</button>
                    <button type="button" class="shop-admin-topic" data-topic="help">Need help</button>
                  </div>
                  <div class="shop-admin-support-thread" id="shopAdminSupportThread" aria-live="polite"></div>
                  <div class="shop-admin-support-compose">
                    <div class="shop-admin-support-meta">
                      <input type="text" class="form-control form-control-sm" id="shopAdminSupportOrder" placeholder="Order code (optional)" maxlength="80">
                      <input type="text" class="form-control form-control-sm" id="shopAdminSupportSeller" placeholder="Seller name (optional)" maxlength="120">
                    </div>
                    <div class="shop-admin-support-compose-row">
                      <textarea id="shopAdminSupportInput" class="form-control" rows="2" placeholder="Describe the dispute or what you need help with…"></textarea>
                      <button type="button" class="btn btn-primary btn-sm" id="shopAdminSupportSend">Send</button>
                    </div>
                    <p class="tx-danger tx-12 mg-b-0" id="shopAdminSupportErr" hidden></p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
<script src="./lib/jquery/jquery.js"></script>
<script src="./js/shamcey.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var links = Array.prototype.slice.call(document.querySelectorAll('[data-shop-pref-target]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('[data-shop-pref-panel]'));

  function showPanel(id) {
    var found = false;
    panels.forEach(function (panel) {
      var active = panel.getAttribute('data-shop-pref-panel') === id;
      panel.classList.toggle('is-active', active);
      if (active) found = true;
    });
    links.forEach(function (link) {
      link.classList.toggle('is-active', link.getAttribute('data-shop-pref-target') === id);
    });
    return found;
  }

  links.forEach(function (link) {
    link.addEventListener('click', function (event) {
      var id = link.getAttribute('data-shop-pref-target');
      if (!id || !showPanel(id)) return;
      event.preventDefault();
      if (history.replaceState) history.replaceState(null, '', '#' + id);
    });
  });

  var initial = (window.location.hash || '').replace('#', '');
  if (initial === 'order-cancel-table') initial = 'notifications';
  if (initial) showPanel(initial);

  function fillProductLines(listEl, products, lineClassName) {
    if (!listEl) return;
    var lineClass = lineClassName || 'shop-payment-line';
    listEl.innerHTML = '';
    if (!products.length) {
      var empty = document.createElement('div');
      empty.className = lineClass;
      empty.innerHTML = '<span>No products</span><span>$0.00</span>';
      listEl.appendChild(empty);
      return;
    }
    products.forEach(function (product) {
      var line = document.createElement('div');
      line.className = lineClass;
      var title = String(product.title || 'Product');
      var qty = Number(product.qty || 1);
      var label = document.createElement('span');
      label.textContent = qty > 1 ? (title + ' × ' + qty) : title;
      var amount = document.createElement('span');
      amount.textContent = String(product.amount || '$0.00');
      line.appendChild(label);
      line.appendChild(amount);
      listEl.appendChild(line);
    });
  }

  function fillOrderHistoryProducts(listEl, products, emptyText) {
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!products.length) {
      var empty = document.createElement('div');
      empty.className = 'shop-invoice-items-empty shop-payment-items-empty';
      empty.textContent = emptyText || 'No products in this order.';
      listEl.appendChild(empty);
      return;
    }
    var head = document.createElement('div');
    head.className = 'shop-invoice-items-head';
    head.innerHTML = '<span>Product</span><span style="text-align:right">Qty</span><span style="text-align:right">Amount</span>';
    listEl.appendChild(head);
    products.forEach(function (product) {
      var line = document.createElement('div');
      line.className = 'shop-invoice-product-line';
      var title = document.createElement('span');
      title.className = 'shop-invoice-product-title';
      title.textContent = String(product.title || 'Product');
      var qty = document.createElement('span');
      qty.className = 'shop-invoice-product-qty';
      qty.textContent = String(Number(product.qty || 1));
      var amount = document.createElement('span');
      amount.className = 'shop-invoice-product-amount';
      amount.textContent = String(product.amount || '$0.00');
      line.appendChild(title);
      line.appendChild(qty);
      line.appendChild(amount);
      listEl.appendChild(line);
    });
  }

  Array.prototype.slice.call(document.querySelectorAll('#order-history [data-invoice-order]')).forEach(function (row) {
    row.addEventListener('click', function () {
      var panel = row.closest('[data-shop-pref-panel]');
      if (!panel) return;
      Array.prototype.slice.call(panel.querySelectorAll('[data-invoice-order]')).forEach(function (item) {
        item.classList.toggle('is-selected', item === row);
      });
      var values = {
        code: row.getAttribute('data-invoice-code') || 'Order',
        status: row.getAttribute('data-invoice-status') || 'Pending',
        date: row.getAttribute('data-invoice-date') || 'Not set',
        due: row.getAttribute('data-invoice-due') || 'Not set',
        seller: row.getAttribute('data-invoice-seller') || 'Seller',
        subtotal: row.getAttribute('data-invoice-total') || '$0.00',
        grand: row.getAttribute('data-invoice-total') || '$0.00'
      };
      Object.keys(values).forEach(function (key) {
        Array.prototype.slice.call(panel.querySelectorAll('[data-invoice-field="' + key + '"]')).forEach(function (field) {
          field.textContent = values[key];
        });
      });
      var products = [];
      try {
        products = JSON.parse(row.getAttribute('data-invoice-products') || '[]') || [];
      } catch (e) {
        products = [];
      }
      fillOrderHistoryProducts(panel.querySelector('[data-invoice-field="items-list"]'), products);

      var email = (row.getAttribute('data-invoice-contact-email') || '').trim();
      var phone = (row.getAttribute('data-invoice-contact-phone') || '').trim();
      var address = (row.getAttribute('data-invoice-contact-address') || '').trim();
      var emailEl = panel.querySelector('[data-invoice-field="contact-email"]');
      var phoneEl = panel.querySelector('[data-invoice-field="contact-phone"]');
      var addressEl = panel.querySelector('[data-invoice-field="contact-address"]');
      var fallbackEl = panel.querySelector('[data-invoice-field="contact-fallback"]');
      if (emailEl) {
        emailEl.innerHTML = '';
        emailEl.setAttribute('data-invoice-empty', email ? '0' : '1');
        if (email) {
          emailEl.appendChild(document.createTextNode('Email: '));
          var emailLink = document.createElement('a');
          emailLink.href = 'mailto:' + email;
          emailLink.textContent = email;
          emailEl.appendChild(emailLink);
        }
      }
      if (phoneEl) {
        phoneEl.innerHTML = '';
        phoneEl.setAttribute('data-invoice-empty', phone ? '0' : '1');
        if (phone) {
          phoneEl.appendChild(document.createTextNode('Phone: '));
          var phoneLink = document.createElement('a');
          phoneLink.href = 'tel:' + phone.replace(/\s+/g, '');
          phoneLink.textContent = phone;
          phoneEl.appendChild(phoneLink);
        }
      }
      if (addressEl) {
        addressEl.textContent = address;
        addressEl.setAttribute('data-invoice-empty', address ? '0' : '1');
      }
      if (fallbackEl) {
        fallbackEl.setAttribute('data-invoice-empty', (email || phone || address) ? '1' : '0');
      }
    });
  });

  Array.prototype.slice.call(document.querySelectorAll('#invoices-payments [data-payment-order]')).forEach(function (row) {
    row.addEventListener('click', function () {
      var panel = row.closest('[data-shop-pref-panel]');
      if (!panel) return;
      Array.prototype.slice.call(panel.querySelectorAll('[data-payment-order]')).forEach(function (item) {
        item.classList.toggle('is-selected', item === row);
      });
      var values = {
        code: row.getAttribute('data-payment-code') || 'Pending',
        status: row.getAttribute('data-payment-status') || 'pending',
        amount: row.getAttribute('data-payment-total') || '$0.00',
        total: row.getAttribute('data-payment-total') || '$0.00',
        company: row.getAttribute('data-payment-company') || 'Seller'
      };
      Object.keys(values).forEach(function (key) {
        Array.prototype.slice.call(panel.querySelectorAll('[data-payment-field="' + key + '"]')).forEach(function (field) {
          field.textContent = values[key];
        });
      });
      var products = [];
      try {
        products = JSON.parse(row.getAttribute('data-payment-products') || '[]') || [];
      } catch (e) {
        products = [];
      }
      fillOrderHistoryProducts(panel.querySelector('[data-payment-field="items-list"]'), products, 'No products in this invoice.');
    });
  });

  Array.prototype.slice.call(document.querySelectorAll('.js-order-history-cancel')).forEach(function (btn) {
    btn.addEventListener('click', async function (event) {
      event.preventDefault();
      event.stopPropagation();
      var idsRaw = String(btn.getAttribute('data-order-ids') || '');
      var ids = idsRaw.split(',').map(function (v) { return parseInt(v, 10); }).filter(function (n) { return n > 0; });
      if (!ids.length) return;
      if (!window.confirm('Cancel this order? It will be removed from your history and from the seller’s order list.')) return;
      var reason = window.prompt('Why are you cancelling? (optional)', 'Changed mind');
      if (reason === null) return;
      reason = String(reason).trim() || 'Changed mind';
      btn.disabled = true;
      var ok = true;
      var message = '';
      for (var i = 0; i < ids.length; i++) {
        var body = new URLSearchParams();
        body.set('order_id', String(ids[i]));
        body.set('reason', reason);
        try {
          var res = await fetch('ajax/order_cancel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
          });
          var data = await res.json();
          if (!data || !data.ok) {
            ok = false;
            message = (data && data.message) ? data.message : 'Could not cancel the order.';
            break;
          }
          message = data.message || 'Order cancelled.';
        } catch (e) {
          ok = false;
          message = 'Could not cancel the order.';
          break;
        }
      }
      if (!ok) {
        btn.disabled = false;
        window.alert(message);
        return;
      }
      var row = btn.closest('tr');
      if (row) row.remove();
      var tbody = document.querySelector('#order-history .shop-pref-table tbody');
      if (tbody && !tbody.querySelector('[data-invoice-order]')) {
        tbody.innerHTML = '<tr><td colspan="7" class="shop-pref-table-empty">No orders yet.</td></tr>';
      }
      window.location.hash = 'order-history';
      window.location.reload();
    });
  });

  Array.prototype.slice.call(document.querySelectorAll('[data-print-invoice]')).forEach(function (button) {
    button.addEventListener('click', function () {
      window.print();
    });
  });

  var addrModal = document.getElementById('addrEditModal');
  function openAddrModal() {
    if (!addrModal) return;
    addrModal.classList.add('is-open');
    addrModal.setAttribute('aria-hidden', 'false');
  }
  function closeAddrModal() {
    if (!addrModal) return;
    addrModal.classList.remove('is-open');
    addrModal.setAttribute('aria-hidden', 'true');
  }
  Array.prototype.slice.call(document.querySelectorAll('[data-open-addr-modal]')).forEach(function (btn) {
    btn.addEventListener('click', openAddrModal);
  });
  Array.prototype.slice.call(document.querySelectorAll('[data-close-addr-modal]')).forEach(function (btn) {
    btn.addEventListener('click', closeAddrModal);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeAddrModal();
  });
  <?php if ($addrFlashErr !== ''): ?>
  openAddrModal();
  <?php endif; ?>

  /* Seller product chat (Shopping Preferences — not messages.php) */
  (function () {
    var root = document.getElementById('shopSellerMsgRoot');
    if (!root) return;
    var thread = document.getElementById('shopSellerMsgThread');
    var input = document.getElementById('shopSellerMsgInput');
    var sendBtn = document.getElementById('shopSellerMsgSend');
    var errEl = document.getElementById('shopSellerMsgErr');
    var peer = String(root.getAttribute('data-peer') || '').trim().toUpperCase();
    var draft = String(root.getAttribute('data-draft') || '');
    var lastId = 0;
    var polling = false;

    function setErr(msg) {
      if (!errEl) return;
      if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
      errEl.hidden = false;
      errEl.textContent = msg;
    }
    function esc(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function appendItems(items, replace) {
      if (!thread) return;
      if (replace) thread.innerHTML = '';
      (items || []).forEach(function (item) {
        var id = parseInt(item.id || 0, 10);
        if (id > lastId) lastId = id;
        var div = document.createElement('div');
        div.className = 'shop-seller-msg-bubble ' + (item.is_me ? 'me' : 'them');
        div.innerHTML = esc(item.text || '') + '<div class="shop-seller-msg-meta">' + esc(item.time_label || '') + '</div>';
        thread.appendChild(div);
      });
      thread.scrollTop = thread.scrollHeight;
    }
    async function loadHistory() {
      if (!peer) return;
      try {
        var res = await fetch('ajax/user_chat_poll.php?peer=' + encodeURIComponent(peer) + '&after=0&wait=0&mark=1', { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok) {
          lastId = 0;
          appendItems(data.items || [], true);
          if (!(data.items || []).length) {
            thread.innerHTML = '<div class="shop-seller-msg-empty">No messages yet. Ask about the product, stock, pickup, or delivery.</div>';
          }
        }
      } catch (e) { /* ignore */ }
    }
    async function pollNew() {
      if (!peer || polling) return;
      polling = true;
      try {
        var res = await fetch('ajax/user_chat_poll.php?peer=' + encodeURIComponent(peer) + '&after=' + lastId + '&wait=0&mark=1', { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok && (data.items || []).length) {
          if (thread && thread.querySelector('.shop-seller-msg-empty')) thread.innerHTML = '';
          appendItems(data.items, false);
        }
      } catch (e) { /* ignore */ }
      polling = false;
    }
    async function sendMessage() {
      setErr('');
      if (!peer) { setErr('Select a seller first.'); return; }
      var text = input ? String(input.value || '').trim() : '';
      if (!text) { setErr('Type a message.'); return; }
      if (sendBtn) sendBtn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('to', peer);
        body.set('message', text);
        var res = await fetch('ajax/user_chat_send.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (!data || !data.ok) {
          setErr((data && (data.error || data.message)) || 'Could not send.');
          return;
        }
        if (input) input.value = '';
        await pollNew();
      } catch (e) {
        setErr('Could not send message.');
      } finally {
        if (sendBtn) sendBtn.disabled = false;
      }
    }

    if (input && draft && !String(input.value || '').trim()) {
      input.value = draft;
    }
    if (sendBtn) sendBtn.addEventListener('click', sendMessage);
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
        }
      });
    }
    loadHistory();
    setInterval(pollNew, 4000);
  })();

  /* Admin support chat (Support Center) */
  (function () {
    var root = document.getElementById('shopAdminSupportRoot');
    if (!root) return;
    var endpoint = String(root.getAttribute('data-endpoint') || 'ajax/admin_support_chat.php');
    var thread = document.getElementById('shopAdminSupportThread');
    var input = document.getElementById('shopAdminSupportInput');
    var sendBtn = document.getElementById('shopAdminSupportSend');
    var errEl = document.getElementById('shopAdminSupportErr');
    var orderEl = document.getElementById('shopAdminSupportOrder');
    var sellerEl = document.getElementById('shopAdminSupportSeller');
    var topicBtns = Array.prototype.slice.call(root.querySelectorAll('.shop-admin-topic'));
    var topic = 'dispute';
    var lastId = 0;
    var polling = false;

    function setErr(msg) {
      if (!errEl) return;
      if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
      errEl.hidden = false;
      errEl.textContent = msg;
    }
    function esc(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function appendItems(items, replace) {
      if (!thread) return;
      if (replace) thread.innerHTML = '';
      (items || []).forEach(function (item) {
        var id = parseInt(item.id || 0, 10);
        if (id > lastId) lastId = id;
        var div = document.createElement('div');
        div.className = 'shop-seller-msg-bubble ' + (item.is_me ? 'me' : 'them');
        div.innerHTML = esc(item.text || '') + '<div class="shop-seller-msg-meta">' + esc(item.from || '') + ' · ' + esc(item.time_label || '') + '</div>';
        thread.appendChild(div);
      });
      thread.scrollTop = thread.scrollHeight;
    }
    async function loadHistory() {
      try {
        var res = await fetch(endpoint + '?mode=history&after=0&mark=1', { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok) {
          lastId = 0;
          appendItems(data.items || [], true);
          if (!(data.items || []).length) {
            thread.innerHTML = '<div class="shop-seller-msg-empty">No Admin messages yet. Choose a topic and describe your dispute or help request.</div>';
          }
        }
      } catch (e) { /* ignore */ }
    }
    async function pollNew() {
      if (polling) return;
      polling = true;
      try {
        var res = await fetch(endpoint + '?mode=history&after=' + lastId + '&mark=1', { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok && (data.items || []).length) {
          if (thread && thread.querySelector('.shop-seller-msg-empty')) thread.innerHTML = '';
          appendItems(data.items, false);
        }
      } catch (e) { /* ignore */ }
      polling = false;
    }
    async function sendMessage() {
      setErr('');
      var text = input ? String(input.value || '').trim() : '';
      if (!text) { setErr('Type a message for Admin.'); return; }
      if (sendBtn) sendBtn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('mode', 'send');
        body.set('topic', topic);
        body.set('message', text);
        if (orderEl) body.set('order_code', String(orderEl.value || '').trim());
        if (sellerEl) body.set('seller_name', String(sellerEl.value || '').trim());
        var res = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (!data || !data.ok) {
          setErr((data && (data.error || data.message)) || 'Could not send.');
          return;
        }
        if (input) input.value = '';
        if (data.item) {
          if (thread && thread.querySelector('.shop-seller-msg-empty')) thread.innerHTML = '';
          appendItems([data.item], false);
        } else {
          await pollNew();
        }
      } catch (e) {
        setErr('Could not send message.');
      } finally {
        if (sendBtn) sendBtn.disabled = false;
      }
    }

    topicBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        topic = String(btn.getAttribute('data-topic') || 'help');
        topicBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
        if (input) {
          input.placeholder = topic === 'dispute'
            ? 'Describe the dispute with the seller…'
            : 'Describe what you need help with…';
        }
      });
    });
    if (sendBtn) sendBtn.addEventListener('click', sendMessage);
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
        }
      });
    }
    loadHistory();
    setInterval(pollNew, 5000);
  })();
});
</script>
</body>
</html>
