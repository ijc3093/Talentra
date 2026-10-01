<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';

// Mobile / API: Your_Shopping_preferences.php?format=json&panel=dashboard
if (strtolower(trim((string)($_GET['format'] ?? $_POST['format'] ?? ''))) === 'json') {
    require __DIR__ . '/ajax/shop_shopping_preferences.php';
    exit;
}

requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/org_cart.php';
require_once __DIR__ . '/includes/org_wishlist.php';
require_once __DIR__ . '/includes/buyer_shipping.php';
require_once __DIR__ . '/includes/buyer_membership.php';
require_once __DIR__ . '/includes/stripe_shop.php';
require_once __DIR__ . '/includes/buyer_seller_relationship.php';
require_once __DIR__ . '/includes/commerce_messaging.php';
require_once __DIR__ . '/includes/commerce_disputes.php';
require_once __DIR__ . '/includes/admin_support_chat.php';
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
$membershipFlashOk = '';
$membershipFlashErr = '';
$orderFlashOk = '';
$orderFlashErr = '';
if (!empty($_SESSION['addr_flash_ok'])) {
    $addrFlashOk = (string)$_SESSION['addr_flash_ok'];
    unset($_SESSION['addr_flash_ok']);
}
if (!empty($_SESSION['addr_flash_err'])) {
    $addrFlashErr = (string)$_SESSION['addr_flash_err'];
    unset($_SESSION['addr_flash_err']);
}
if (!empty($_SESSION['membership_flash_ok'])) {
    $membershipFlashOk = (string)$_SESSION['membership_flash_ok'];
    unset($_SESSION['membership_flash_ok']);
}
if (!empty($_SESSION['membership_flash_err'])) {
    $membershipFlashErr = (string)$_SESSION['membership_flash_err'];
    unset($_SESSION['membership_flash_err']);
}
if ((string)($_GET['membership'] ?? '') === '1') {
    $membershipFlashOk = $membershipFlashOk !== '' ? $membershipFlashOk : 'Membership payment confirmed. Enjoy $0 service fees while your plan is active.';
}
if ((string)($_GET['membership'] ?? '') === 'cancel') {
    $membershipFlashErr = $membershipFlashErr !== '' ? $membershipFlashErr : 'Membership checkout was cancelled.';
}

// Stripe checkout return (formerly my_orders.php)
$sessionId = trim((string)($_GET['session_id'] ?? ''));
if ($sessionId !== '') {
    org_shop_ensure_schema($dbh);
    $session = stripe_shop_retrieve_session($sessionId);
    if ($session && org_shop_fulfill_stripe_session($dbh, $session)) {
        $code = trim((string)($session['client_reference_id'] ?? ''));
        $orderFlashOk = $code !== ''
            ? 'Payment received. Order ' . $code . ' is confirmed.'
            : 'Payment received. Your order is confirmed.';
    } elseif ($session && (string)($session['payment_status'] ?? '') !== 'paid') {
        $orderFlashErr = 'Payment was not completed. You can retry from the shop.';
    } else {
        $orderFlashErr = 'Could not verify payment. Contact support if you were charged.';
    }
}
if ((string)($_GET['checkout'] ?? '') === 'cancel') {
    $orderFlashErr = $orderFlashErr !== '' ? $orderFlashErr : 'Checkout was cancelled.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['membership_action'])) {
    buyer_membership_ensure_schema($dbh);
    $months = max(1, min(12, (int)($_POST['months'] ?? 1)));
    if ((string)$_POST['membership_action'] === 'subscribe') {
        if (!stripe_shop_is_configured()) {
            $_SESSION['membership_flash_err'] = 'Online membership payment is not configured yet. Please try again later or contact Admin.';
        } else {
            $base = stripe_shop_public_base_url();
            $checkout = stripe_shop_create_membership_checkout_session(
                $meId,
                $months,
                $base . '/membership_success.php?session_id={CHECKOUT_SESSION_ID}',
                $base . '/Your_Shopping_preferences.php?membership=cancel#membership'
            );
            if (!empty($checkout['ok']) && !empty($checkout['checkout_url'])) {
                header('Location: ' . (string)$checkout['checkout_url']);
                exit;
            }
            $_SESSION['membership_flash_err'] = (string)($checkout['error'] ?? 'Could not start membership checkout.');
        }
        header('Location: Your_Shopping_preferences.php#membership');
        exit;
    }
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
$supportMsgEmail = function_exists('admin_support_user_email') ? admin_support_user_email($dbh, $meId) : '';
$supportMsgUnread = ($supportMsgEmail !== '' && function_exists('admin_support_unread_count'))
    ? admin_support_unread_count($dbh, $supportMsgEmail)
    : 0;
$sellerMsgPeerId = (int)($_GET['seller_msg'] ?? $_GET['id'] ?? 0);
$sellerMsgAboutProduct = (int)($_GET['about_product'] ?? 0);
$sellerMsgAboutOrder = trim((string)($_GET['about_order'] ?? ''));
$sellerMsgDraft = '';
$sellerMsgProduct = null;
$sellerMsgProductFocus = null;
if (!function_exists('pref_seller_msg_time')) {
    function pref_seller_msg_time($at): string
    {
        $at = trim((string)$at);
        if ($at === '') {
            return '';
        }
        $ts = strtotime($at);
        if ($ts === false) {
            return '';
        }
        if (date('Y-m-d') === date('Y-m-d', $ts)) {
            return date('g:i A', $ts);
        }
        if (date('Y') === date('Y', $ts)) {
            return date('M j', $ts);
        }
        return date('M j, Y', $ts);
    }
}
if (!function_exists('pref_seller_avatar_url')) {
    function pref_seller_avatar_url(int $userId, string $name, string $friendCode = ''): string
    {
        $q = ['u' => max(0, $userId), 'name' => $name !== '' ? $name : 'Seller'];
        if ($friendCode !== '') {
            $q['friend_code'] = $friendCode;
        }
        return 'avatar.php?' . http_build_query($q);
    }
}
$sellerMsgActive = null;
$sellerMsgPendingFirst = false; // opened from product "Message seller" before first send
$canOpenSellerChat = $sellerMsgPeerId > 0 && (
    commerce_messaging_publisher_has_shop($dbh, $sellerMsgPeerId)
    || (function_exists('org_is_commerce_seller_publisher') && org_is_commerce_seller_publisher($dbh, $sellerMsgPeerId))
);
if ($canOpenSellerChat) {
    foreach ($sellerMsgContacts as $c) {
        if ((int)($c['publisher_user_id'] ?? 0) === $sellerMsgPeerId) {
            $sellerMsgActive = $c;
            break;
        }
    }
    if ($sellerMsgActive === null) {
        // Deep-link from product_detail "Message seller about this product":
        // show this seller for the first message; they join the permanent list after send.
        try {
            $stPeer = $dbh->prepare("
                SELECT
                    u.id,
                    u.friend_code,
                    COALESCE(NULLIF(TRIM(org.name), ''), NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), u.friend_code) AS seller_name,
                    COALESCE(org.id, 0) AS org_id
                FROM users u
                LEFT JOIN organizations org ON org.publisher_user_id = u.id AND org.status = 1
                WHERE u.id = :id AND u.status = 1
                LIMIT 1
            ");
            $stPeer->execute([':id' => $sellerMsgPeerId]);
            $peerRow = $stPeer->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($peerRow) {
                $sellerMsgActive = [
                    'publisher_user_id' => $sellerMsgPeerId,
                    'org_id' => (int)($peerRow['org_id'] ?? 0),
                    'seller_name' => trim((string)($peerRow['seller_name'] ?? 'Seller')),
                    'friend_code' => strtoupper(trim((string)($peerRow['friend_code'] ?? ''))),
                    'last_message' => '',
                    'last_at' => '',
                    'unread' => 0,
                    'pending_first_message' => 1,
                    'about_product_id' => $sellerMsgAboutProduct,
                ];
                $sellerMsgPendingFirst = true;
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

if ($sellerMsgAboutProduct <= 0 && is_array($sellerMsgActive)) {
    $sellerMsgAboutProduct = (int)($sellerMsgActive['about_product_id'] ?? 0);
}
$sellerMsgDraft = commerce_messaging_compose_draft($dbh, $sellerMsgAboutProduct, $sellerMsgAboutOrder);
if ($sellerMsgAboutProduct > 0) {
    try {
        $sellerMsgProduct = org_shop_get_product($dbh, $sellerMsgAboutProduct);
    } catch (Throwable $e) {
        $sellerMsgProduct = null;
    }
    if (function_exists('commerce_messaging_product_focus')) {
        $sellerMsgProductFocus = commerce_messaging_product_focus($dbh, $sellerMsgAboutProduct);
    }
}

// Support Center deep-link from product Report (product image + ID + seller business).
$supportReportMode = (int)($_GET['support_report'] ?? 0) === 1;
$supportAboutProduct = (int)($_GET['about_product'] ?? 0);
$supportAboutSeller = (int)($_GET['about_seller'] ?? 0);
$supportTopic = strtolower(trim((string)($_GET['topic'] ?? ($supportReportMode ? 'dispute' : 'dispute'))));
if (!in_array($supportTopic, ['dispute', 'help'], true)) {
    $supportTopic = 'dispute';
}
$supportCaseOpen = function_exists('commerce_dispute_buyer_has_open_customer_case')
    ? commerce_dispute_buyer_has_open_customer_case($dbh, $meId)
    : false;
// Dispute chat unlocks with an open case, or a fresh product Report deep-link.
$supportDisputeUnlocked = $supportCaseOpen || ($supportReportMode && $supportAboutProduct > 0);
$supportProductFocus = null;
$supportSellerBusiness = '';
$supportDraft = '';
// When an Admin case is still open, keep the product card even without a Report deep-link.
if ($supportAboutProduct <= 0 && $supportCaseOpen && function_exists('commerce_dispute_buyer_latest_open_case')) {
    $openSupportCase = commerce_dispute_buyer_latest_open_case($dbh, $meId);
    if (is_array($openSupportCase)) {
        $supportAboutProduct = (int)($openSupportCase['product_id'] ?? 0);
        $supportSellerBusiness = trim((string)($openSupportCase['seller_business_name'] ?? ''));
        if ($supportAboutSeller <= 0) {
            $supportAboutSeller = (int)($openSupportCase['publisher_user_id'] ?? 0);
        }
    }
}
if ($supportAboutProduct > 0 && function_exists('commerce_messaging_product_focus')) {
    $supportProductFocus = commerce_messaging_product_focus($dbh, $supportAboutProduct);
    if ($supportAboutSeller <= 0 && is_array($supportProductFocus)) {
        try {
            $pRow = org_shop_get_product($dbh, $supportAboutProduct);
            $orgIdTmp = (int)($pRow['org_id'] ?? 0);
            if ($orgIdTmp > 0) {
                $stPub = $dbh->prepare('SELECT publisher_user_id, name FROM organizations WHERE id = :id LIMIT 1');
                $stPub->execute([':id' => $orgIdTmp]);
                $orgRow = $stPub->fetch(PDO::FETCH_ASSOC) ?: [];
                $supportAboutSeller = (int)($orgRow['publisher_user_id'] ?? 0);
                $supportSellerBusiness = trim((string)($orgRow['name'] ?? ''));
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}
if ($supportSellerBusiness === '' && $supportAboutSeller > 0) {
    try {
        $stBiz = $dbh->prepare("
            SELECT COALESCE(NULLIF(TRIM(org.name), ''), NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), 'Seller') AS biz
            FROM users u
            LEFT JOIN organizations org ON org.publisher_user_id = u.id AND org.status = 1
            WHERE u.id = :id
            LIMIT 1
        ");
        $stBiz->execute([':id' => $supportAboutSeller]);
        $supportSellerBusiness = trim((string)($stBiz->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $supportSellerBusiness = '';
    }
}
if ($supportAboutProduct > 0) {
    // Product + seller context stays on the sticky card / seller field — not the textarea.
    $supportDraft = '';
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
$buyerWishlistItems = org_wishlist_list($dbh, $meId, 100);
$buyerWishlistCount = count($buyerWishlistItems);
buyer_membership_ensure_schema($dbh);
$membershipSnap = buyer_membership_snapshot($dbh, $meId) ?: [];
$membershipActive = !empty($membershipSnap['is_active']);
$membershipPaidUntil = trim((string)($membershipSnap['paid_until'] ?? ''));
$membershipPayments = buyer_membership_list_payments($dbh, $meId, 8);
$membershipPriceLabel = org_shop_format_price(buyer_membership_price_cents(), 'USD');
$membershipServiceFeeLabel = org_shop_format_price(
    $membershipActive ? buyer_membership_member_service_fee_cents() : org_shop_buyer_service_fee_cents($dbh, $meId),
    'USD'
);
$stripeMembershipReady = stripe_shop_is_configured();
$buyerReturnRequests = 0;
$buyerReviews = 0;
$buyerReturnRows = [];
$buyerReturnHistoryRows = [];
$buyerReviewRows = [];
try {
    $st = $dbh->prepare("
        SELECT r.id, r.org_id, r.order_id, r.reason, r.status, r.seller_notes, r.created_at, r.updated_at,
               o.order_code, o.product_id, o.quantity, o.total_cents, o.unit_price_cents, o.currency,
               o.discount_cents, o.shipping_fee_cents, o.tax_cents, o.service_fee_cents, o.status AS order_status,
               COALESCE(NULLIF(TRIM(o.product_title), ''), p.title, 'Product') AS product_title,
               org.name AS seller_name,
               org.publisher_user_id,
               cb.name AS commerce_brand_name
        FROM org_order_returns r
        INNER JOIN org_orders o ON o.id = r.order_id
        LEFT JOIN organizations org ON org.id = o.org_id
        LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id AND cb.is_active = 1
        LEFT JOIN org_products p ON p.id = o.product_id AND p.is_deleted = 0
        WHERE o.buyer_user_id = :uid
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT 40
    ");
    $st->execute([':uid' => $meId]);
    $buyerReturnRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $buyerReturnRequests = count($buyerReturnRows);
} catch (Throwable $e) {
try {
    $st = $dbh->prepare('
            SELECT r.*, o.order_code, o.product_title, o.quantity, o.total_cents, o.currency, o.status AS order_status,
                   o.product_id, o.unit_price_cents, o.discount_cents, o.shipping_fee_cents, o.tax_cents, o.service_fee_cents
        FROM org_order_returns r
        LEFT JOIN org_orders o ON o.id = r.order_id
        WHERE r.buyer_user_id = :uid
        ORDER BY r.created_at DESC, r.id DESC
            LIMIT 40
    ');
    $st->execute([':uid' => $meId]);
    $buyerReturnRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $buyerReturnRequests = count($buyerReturnRows);
    } catch (Throwable $e2) {
        $buyerReturnRows = [];
        $buyerReturnRequests = 0;
    }
}
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
$buyerInvoiceShippingCents = 0;
$buyerInvoiceServiceFeeCents = 0;
$buyerInvoiceGrandCents = max(0, $buyerInvoiceSubtotalCents - $buyerInvoiceDiscountCents + $buyerInvoiceTaxCents + $buyerInvoiceShippingCents + $buyerInvoiceServiceFeeCents);
$buyerInvoiceSubtotalLabel = org_shop_format_price($buyerInvoiceSubtotalCents, 'USD');
$buyerInvoiceDiscountLabel = org_shop_format_price($buyerInvoiceDiscountCents, 'USD');
$buyerInvoiceTaxLabel = org_shop_format_price($buyerInvoiceTaxCents, 'USD');
$buyerInvoiceShippingLabel = 'Free';
$buyerInvoiceServiceFeeLabel = org_shop_format_price($buyerInvoiceServiceFeeCents, 'USD');
$buyerInvoiceGrandLabel = org_shop_format_price($buyerInvoiceGrandCents, 'USD');

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

$buyerReturnHistoryRows = [];
foreach ($buyerReturnRows as $idx => $retRow) {
    $retId = (int)($retRow['id'] ?? 0);
    $orderId = (int)($retRow['order_id'] ?? 0);
    $orgId = (int)($retRow['org_id'] ?? 0);
    $publisherId = (int)($retRow['publisher_user_id'] ?? 0);
    $productId = (int)($retRow['product_id'] ?? 0);
    $code = trim((string)($retRow['order_code'] ?? ''));
    $seller = trim((string)($retRow['commerce_brand_name'] ?? ''));
    if ($seller === '') {
        $seller = trim((string)($retRow['seller_name'] ?? '')) ?: 'Seller';
    }
    $title = trim((string)($retRow['product_title'] ?? 'Product')) ?: 'Product';
    $qty = max(1, (int)($retRow['quantity'] ?? 1));
    $currency = strtoupper(trim((string)($retRow['currency'] ?? 'USD'))) ?: 'USD';
    $totalCents = (int)($retRow['total_cents'] ?? 0);
    $unitCents = (int)($retRow['unit_price_cents'] ?? 0);
    if ($unitCents <= 0 && $qty > 0) {
        $unitCents = (int)floor($totalCents / $qty);
    }
    $discountCents = (int)($retRow['discount_cents'] ?? 0);
    $shippingCents = (int)($retRow['shipping_fee_cents'] ?? 0);
    $taxCents = (int)($retRow['tax_cents'] ?? 0);
    $serviceFeeCents = (int)($retRow['service_fee_cents'] ?? 0);
    $merchandiseCents = max(0, $totalCents - $shippingCents - $taxCents - $serviceFeeCents + $discountCents);
    $statusRaw = strtolower(trim((string)($retRow['status'] ?? 'requested')));
    $statusLabel = $statusRaw !== '' ? ucwords(str_replace('_', ' ', $statusRaw)) : 'Requested';
    $reason = trim((string)($retRow['reason'] ?? 'Return request')) ?: 'Return request';
    $sellerNotes = trim((string)($retRow['seller_notes'] ?? ''));
    $createdRaw = (string)($retRow['created_at'] ?? '');
    $dateLabel = pref_date($createdRaw);
    $updatedLabel = pref_date((string)($retRow['updated_at'] ?? $createdRaw));
    $contact = pref_seller_contact($dbh, $orgId, $publisherId);
    $askHref = ($publisherId > 0 && function_exists('commerce_message_seller_url'))
        ? commerce_message_seller_url($publisherId, $productId, $code)
        : 'Your_Shopping_preferences.php#seller-messages';
    $orderHref = $orderId > 0
        ? ('order_detail.php?order_id=' . $orderId . ($code !== '' ? ('&code=' . rawurlencode($code)) : ''))
        : 'Your_Shopping_preferences.php#order-history';
    $productHref = $productId > 0 ? ('product_detail.php?id=' . $productId) : '';
    $buyerReturnHistoryRows[] = [
        'return_id' => $retId,
        'return_num' => $idx + 1,
        'order_id' => $orderId,
        'order_code' => $code !== '' ? $code : ('#' . $orderId),
        'seller' => $seller,
        'qty' => $qty,
        'status' => $statusRaw,
        'status_label' => $statusLabel,
        'reason' => $reason,
        'seller_notes' => $sellerNotes,
        'date' => $dateLabel,
        'updated' => $updatedLabel,
        'total' => org_shop_format_price($totalCents, $currency),
        'subtotal_label' => org_shop_format_price($merchandiseCents, $currency),
        'discount_label' => org_shop_format_price($discountCents, $currency),
        'shipping_label' => $shippingCents > 0 ? org_shop_format_price($shippingCents, $currency) : 'Free',
        'tax_label' => org_shop_format_price($taxCents, $currency),
        'service_fee_label' => org_shop_format_price($serviceFeeCents, $currency),
        'product_id' => $productId,
        'publisher_user_id' => $publisherId,
        'order_href' => $orderHref,
        'product_href' => $productHref,
        'ask_href' => $askHref,
        'contact_email' => (string)($contact['email'] ?? ''),
        'contact_phone' => (string)($contact['phone'] ?? ''),
        'contact_address' => (string)($contact['address'] ?? ''),
        'products' => [[
            'title' => $title,
            'qty' => $qty,
            'amount' => org_shop_format_price($unitCents > 0 ? ($unitCents * $qty) : $totalCents, $currency),
        ]],
    ];
}
$buyerReturnSelectedIndex = 0;
$buyerReturnSelected = $buyerReturnHistoryRows[$buyerReturnSelectedIndex] ?? null;

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

/**
 * Group invoices by seller + checkout. Cart checkout inserts one org_orders row per
 * product within seconds, so lines from the same seller created within
 * $prefCheckoutGapSeconds of each other are one purchase; a later purchase
 * (even on the same day) becomes its own row.
 */
$prefCheckoutGapSeconds = 120;
$buyerCheckoutBatchByOrderId = [];
$buyerOrdersChrono = $buyerOrders;
usort($buyerOrdersChrono, static function (array $a, array $b): int {
    $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    return $ta <=> $tb ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
});
$buyerCheckoutLastByOrg = [];
foreach ($buyerOrdersChrono as $order) {
    $orderIdKey = (int)($order['id'] ?? 0);
    $orgKey = (int)($order['org_id'] ?? 0);
    $ts = strtotime((string)($order['created_at'] ?? '')) ?: 0;
    $last = $buyerCheckoutLastByOrg[$orgKey] ?? null;
    if ($last === null || $ts <= 0 || ($ts - (int)$last['ts']) > $prefCheckoutGapSeconds) {
        $last = ['batch' => $orgKey . '|' . ($ts > 0 ? $ts : ('o' . $orderIdKey)), 'ts' => $ts];
    } else {
        $last['ts'] = $ts;
    }
    $buyerCheckoutLastByOrg[$orgKey] = $last;
    $buyerCheckoutBatchByOrderId[$orderIdKey] = $last['batch'];
}

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
    $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : false;
    $groupKey = $buyerCheckoutBatchByOrderId[(int)($order['id'] ?? 0)]
        ?? ($orgId . '|o' . (int)($order['id'] ?? 0));
    if (!isset($buyerPaymentGroups[$groupKey])) {
        $buyerPaymentGroups[$groupKey] = [
            'org_id' => $orgId,
            'publisher_user_id' => (int)($order['publisher_user_id'] ?? 0),
            'company' => $company,
            'date_raw' => $createdRaw,
            'date' => pref_date($createdRaw),
            'time' => $createdTs ? date('g:i A', $createdTs) : '',
            'date_sort' => $createdTs ?: 0,
            'currency' => (string)($order['currency'] ?? 'USD'),
            'total_cents' => 0,
            'shipping_fee_cents' => 0,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'service_fee_cents' => 0,
            'merchandise_cents' => 0,
            'statuses' => [],
            'receipts' => [],
            'order_codes' => [],
            'order_ids' => [],
            'cancellable_ids' => [],
            'ship_detail_id' => 0,
            'ship_detail_code' => '',
            'product_ids' => [],
            'products' => [],
            'item_count' => 0,
            'contact_email' => '',
            'contact_phone' => '',
            'contact_address' => '',
        ];
    }
    $g = &$buyerPaymentGroups[$groupKey];
    $g['total_cents'] += (int)($order['total_cents'] ?? 0);
    $g['shipping_fee_cents'] += max(0, (int)($order['shipping_fee_cents'] ?? 0));
    $g['discount_cents'] += max(0, (int)($order['discount_cents'] ?? 0));
    $g['tax_cents'] += max(0, (int)($order['tax_cents'] ?? 0));
    $g['service_fee_cents'] += max(0, (int)($order['service_fee_cents'] ?? 0));
    $orderIdRow = (int)($order['id'] ?? 0);
    if ($orderIdRow > 0 && !in_array($orderIdRow, $g['order_ids'], true)) {
        $g['order_ids'][] = $orderIdRow;
    }
    if ($orderIdRow > 0 && org_shop_buyer_order_is_cancellable($order) && !in_array($orderIdRow, $g['cancellable_ids'], true)) {
        $g['cancellable_ids'][] = $orderIdRow;
    }
    $statusForGroup = $status;
    // Carrier+tracking / shipped_at without status upgrade still counts as shipped for the group.
    if (
        in_array($status, ['pending', 'confirmed', 'paid'], true)
        && !org_shop_buyer_order_is_cancellable($order)
    ) {
        $statusForGroup = 'shipped';
    }
    if ($statusForGroup !== '') {
        $g['statuses'][] = $statusForGroup;
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
    // Prefer delivered, then shipped, for Status → order_detail deep link (newest first in list).
    if ($statusForGroup === 'delivered' && $orderIdRow > 0) {
        $prevDetailStatus = (string)($g['ship_detail_status'] ?? '');
        if ((int)($g['ship_detail_id'] ?? 0) <= 0 || $prevDetailStatus !== 'delivered') {
            $g['ship_detail_id'] = $orderIdRow;
            $g['ship_detail_code'] = $orderCode;
            $g['ship_detail_status'] = 'delivered';
        }
    } elseif (
        $statusForGroup === 'shipped'
        && (int)($g['ship_detail_id'] ?? 0) <= 0
        && $orderIdRow > 0
    ) {
        $g['ship_detail_id'] = $orderIdRow;
        $g['ship_detail_code'] = $orderCode;
        $g['ship_detail_status'] = 'shipped';
    }
    $qty = max(1, (int)($order['quantity'] ?? 1));
    $title = trim((string)($order['product_title'] ?? '')) ?: 'Product';
    $unit = max(0, (int)($order['unit_price_cents'] ?? 0));
    $lineCents = max(0, $unit * $qty);
    if ($lineCents <= 0) {
    $shipCents = max(0, (int)($order['shipping_fee_cents'] ?? 0));
    $taxCents = max(0, (int)($order['tax_cents'] ?? 0));
    $svcCents = max(0, (int)($order['service_fee_cents'] ?? 0));
        $disc = max(0, (int)($order['discount_cents'] ?? 0));
        $lineTotal = (int)($order['total_cents'] ?? 0);
        $lineCents = max(0, $lineTotal - $shipCents - $taxCents - $svcCents + $disc);
    }
    $g['merchandise_cents'] += $lineCents;
    $productIdRow = (int)($order['product_id'] ?? 0);
    if ($productIdRow > 0 && !in_array($productIdRow, $g['product_ids'], true)) {
        $g['product_ids'][] = $productIdRow;
    }
    $g['products'][] = [
        'title' => $title,
        'qty' => $qty,
        'amount' => org_shop_format_price($lineCents, (string)($order['currency'] ?? $g['currency'])),
        'amount_cents' => $lineCents,
        'product_id' => $productIdRow,
        'category' => trim((string)($order['category'] ?? '')),
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
    // Once any line has shipped, the purchase group is no longer buyer-cancellable.
    $hasShipped = false;
    foreach ($statuses as $st) {
        if (in_array($st, ['shipped', 'delivered'], true)) {
            $hasShipped = true;
            break;
        }
    }
    if ($hasShipped) {
        $group['cancellable_ids'] = [];
    }
    if (count($statuses) === 1) {
        $group['status'] = $statuses[0];
    } elseif ($hasShipped) {
        // Prefer shipping label over "multiple" when fulfillment has started.
        $group['status'] = in_array('delivered', $statuses, true) ? 'delivered' : 'shipped';
    } elseif (in_array('pending', $statuses, true)) {
        $group['status'] = 'pending';
    } elseif ($statuses) {
        $group['status'] = 'multiple';
    } else {
        $group['status'] = 'pending';
    }
    $group['total'] = org_shop_format_price((int)$group['total_cents'], (string)$group['currency']);
    $shipCents = max(0, (int)($group['shipping_fee_cents'] ?? 0));
    $discCents = max(0, (int)($group['discount_cents'] ?? 0));
    $taxCents = max(0, (int)($group['tax_cents'] ?? 0));
    $svcCents = max(0, (int)($group['service_fee_cents'] ?? 0));
    $merchCents = max(0, (int)($group['merchandise_cents'] ?? 0));
    if ($merchCents <= 0) {
        $merchCents = max(0, (int)$group['total_cents'] - $shipCents - $taxCents - $svcCents + $discCents);
        $group['merchandise_cents'] = $merchCents;
    }
    $group['shipping_label'] = $shipCents > 0
        ? org_shop_format_price($shipCents, (string)$group['currency'])
        : 'Free';
    $group['shipping_is_free'] = $shipCents <= 0;
    $group['subtotal_label'] = org_shop_format_price($merchCents, (string)$group['currency']);
    $group['discount_label'] = org_shop_format_price($discCents, (string)$group['currency']);
    $group['discount_cents'] = $discCents;
    $group['tax_label'] = org_shop_format_price($taxCents, (string)$group['currency']);
    $group['service_fee_label'] = org_shop_format_price($svcCents, (string)$group['currency']);
    $group['service_fee_cents'] = $svcCents;
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
    $group['date_time'] = $group['date'] . ((string)($group['time'] ?? '') !== '' ? (' · ' . $group['time']) : '');
    $group['invoice_label'] = $orderCount === 1
        ? $group['order_codes'][0]
        : ($group['company'] . ' · ' . $group['date_time']);
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
 * Order history rows: one row per seller checkout, with:
 * Order # = purchase number for this buyer (oldest = 1, newest = highest)
 * Items = how many different products (bowl, cup, tomatoes → 3)
 * Quantity # = total units (2 bowls + 3 cups + 6 tomatoes → 11)
 */
$buyerOrderHistoryRows = [];
$buyerOrderGroupTotal = count($buyerPaymentGroups);
foreach ($buyerPaymentGroups as $groupIndex => $group) {
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
                'product_id' => (int)($p['product_id'] ?? 0),
                'category' => trim((string)($p['category'] ?? '')),
            ];
        } else {
            $mergedProducts[$key]['qty'] += $qty;
            $mergedProducts[$key]['amount_cents'] += $amountCents;
            $mergedProducts[$key]['amount'] = org_shop_format_price(
                (int)$mergedProducts[$key]['amount_cents'],
                (string)$group['currency']
            );
            if ((int)($mergedProducts[$key]['product_id'] ?? 0) <= 0 && (int)($p['product_id'] ?? 0) > 0) {
                $mergedProducts[$key]['product_id'] = (int)$p['product_id'];
            }
            if (trim((string)($mergedProducts[$key]['category'] ?? '')) === '' && trim((string)($p['category'] ?? '')) !== '') {
                $mergedProducts[$key]['category'] = trim((string)$p['category']);
            }
        }
    }
    $productsList = array_values($mergedProducts);
    $productCount = count($productsList);
    $quantityTotal = 0;
    foreach ($productsList as $p) {
        $quantityTotal += max(1, (int)($p['qty'] ?? 1));
    }
    $cancellableIds = array_values(array_filter(array_map('intval', $group['cancellable_ids'] ?? [])));
    $shipDetailId = (int)($group['ship_detail_id'] ?? 0);
    $shipDetailCode = trim((string)($group['ship_detail_code'] ?? ''));
    if ($shipDetailId <= 0) {
        $shipDetailId = (int)(($group['order_ids'][0] ?? 0));
    }
    if ($shipDetailCode === '' && !empty($group['order_codes'][0])) {
        $shipDetailCode = (string)$group['order_codes'][0];
    }
    $primaryProductId = (int)(($productsList[0]['product_id'] ?? 0));
    if ($primaryProductId <= 0 && !empty($group['product_ids'][0])) {
        $primaryProductId = (int)$group['product_ids'][0];
    }
    $primaryCategory = trim((string)($productsList[0]['category'] ?? ''));
    if ($primaryCategory === '') {
        $primaryCategory = trim((string)($productsList[0]['title'] ?? 'Item'));
    }
    $rowStatusKey = strtolower(trim((string)($group['status'] ?? '')));
    $buyerOrderHistoryRows[] = [
        'order_id' => (int)(($group['order_ids'][0] ?? 0)),
        'order_ids' => $group['order_ids'] ?? [],
        'cancellable_ids' => $cancellableIds,
        'ship_detail_id' => $shipDetailId,
        'ship_detail_code' => $shipDetailCode,
        'publisher_user_id' => (int)($group['publisher_user_id'] ?? 0),
        'primary_product_id' => $primaryProductId,
        'view_product_label' => $primaryProductId > 0
            ? ('View product · ' . $primaryCategory)
            : 'View product',
        'can_return' => in_array($rowStatusKey, ['paid', 'shipped', 'delivered'], true),
        'can_review' => $rowStatusKey === 'delivered',
        'order_num' => $buyerOrderGroupTotal - (int)$groupIndex,
        'product_num' => $productCount,
        'quantity_num' => $quantityTotal,
        'order_label' => (string)$group['order_label'],
        'invoice_label' => (string)$group['invoice_label'],
        'receipt_label' => (string)$group['receipt_label'],
        'company' => (string)$group['company'],
        'status' => (string)$group['status'],
        'status_label' => (static function (string $st): string {
            $st = strtolower(trim($st));
            if ($st === 'shipped') {
                return 'Shipp Tracking';
            }
            if ($st === 'delivered') {
                return 'Delivered';
            }
            return $st !== '' ? $st : 'pending';
        })((string)$group['status']),
        'total_cents' => (int)$group['total_cents'],
        'total' => (string)$group['total'],
        'merchandise_cents' => (int)($group['merchandise_cents'] ?? 0),
        'subtotal_label' => (string)($group['subtotal_label'] ?? '$0.00'),
        'shipping_fee_cents' => (int)($group['shipping_fee_cents'] ?? 0),
        'shipping_label' => (string)($group['shipping_label'] ?? 'Free'),
        'shipping_is_free' => !empty($group['shipping_is_free']),
        'discount_cents' => (int)($group['discount_cents'] ?? 0),
        'discount_label' => (string)($group['discount_label'] ?? '$0.00'),
        'tax_cents' => (int)($group['tax_cents'] ?? 0),
        'tax_label' => (string)($group['tax_label'] ?? '$0.00'),
        'service_fee_cents' => (int)($group['service_fee_cents'] ?? 0),
        'service_fee_label' => (string)($group['service_fee_label'] ?? '$0.00'),
        'currency' => (string)$group['currency'],
        'date' => (string)($group['date_time'] ?? $group['date']),
        'due' => (string)$group['due'],
        'cancellable' => $cancellableIds !== [],
        'contact_email' => (string)($group['contact_email'] ?? ''),
        'contact_phone' => (string)($group['contact_phone'] ?? ''),
        'contact_address' => (string)($group['contact_address'] ?? ''),
        'products' => $productsList,
        'product_ids' => array_values(array_filter(array_map('intval', $group['product_ids'] ?? []))),
        'item_count' => $quantityTotal,
    ];
}

$buyerOrderHistoryFocusProduct = (int)($_GET['about_product'] ?? 0);
$buyerOrderHistorySelectedIndex = 0;
if ($buyerOrderHistoryFocusProduct > 0) {
    foreach ($buyerOrderHistoryRows as $idx => $orderRow) {
        $pids = array_map('intval', $orderRow['product_ids'] ?? []);
        if (in_array($buyerOrderHistoryFocusProduct, $pids, true)) {
            $buyerOrderHistorySelectedIndex = (int)$idx;
            break;
        }
    }
}

$buyerPaymentSelected = $buyerOrderHistoryRows[$buyerOrderHistorySelectedIndex] ?? ($buyerOrderHistoryRows[0] ?? null);
$buyerOrderHistorySelected = $buyerPaymentSelected;
if ($buyerOrderHistorySelected) {
    $buyerInvoiceSeller = (string)($buyerOrderHistorySelected['company'] ?? $buyerInvoiceSeller);
    $buyerInvoiceDate = (string)($buyerOrderHistorySelected['date'] ?? $buyerInvoiceDate);
    $buyerInvoiceDueDate = (string)($buyerOrderHistorySelected['due'] ?? $buyerInvoiceDueDate);
}
$buyerPaymentStatus = 'pending';
$buyerPaymentTotal = '$0.00';
$buyerPaymentShipping = 'Free';
$buyerPaymentShippingFree = true;
$buyerPaymentDiscount = '$0.00';
$buyerPaymentTax = '$0.00';
$buyerPaymentServiceFee = '$0.00';
if ($buyerPaymentSelected) {
    $buyerPaymentStatus = (string)$buyerPaymentSelected['status'];
    $buyerPaymentTotal = (string)$buyerPaymentSelected['total'];
    $buyerPaymentShipping = (string)($buyerPaymentSelected['shipping_label'] ?? 'Free');
    $buyerPaymentShippingFree = !empty($buyerPaymentSelected['shipping_is_free']);
    $buyerPaymentDiscount = (string)($buyerPaymentSelected['discount_label'] ?? '$0.00');
    $buyerPaymentTax = (string)($buyerPaymentSelected['tax_label'] ?? '$0.00');
    $buyerPaymentServiceFee = (string)($buyerPaymentSelected['service_fee_label'] ?? '$0.00');
}
$buyerOrderHistoryCode = $buyerOrderHistorySelected
    ? (string)$buyerOrderHistorySelected['invoice_label']
    : ($buyerRecentOrderCode !== '' ? $buyerRecentOrderCode : 'Order');
$buyerOrderHistoryStatus = $buyerOrderHistorySelected
    ? (string)$buyerOrderHistorySelected['status']
    : ($buyerRecentStatus !== '' ? $buyerRecentStatus : 'Pending');
$buyerOrderHistoryStatusLabel = $buyerOrderHistorySelected
    ? (string)($buyerOrderHistorySelected['status_label'] ?? $buyerOrderHistorySelected['status'])
    : (strtolower(trim((string)$buyerOrderHistoryStatus)) === 'shipped'
        ? 'Shipp Tracking'
        : (strtolower(trim((string)$buyerOrderHistoryStatus)) === 'delivered'
            ? 'Delivered'
            : $buyerOrderHistoryStatus));
$buyerOrderHistoryShipId = $buyerOrderHistorySelected
    ? (int)($buyerOrderHistorySelected['ship_detail_id'] ?? $buyerOrderHistorySelected['order_id'] ?? 0)
    : 0;
$buyerOrderHistoryShipCode = $buyerOrderHistorySelected
    ? trim((string)($buyerOrderHistorySelected['ship_detail_code'] ?? ''))
    : '';
$buyerOrderHistoryShipHref = '';
$buyerOrderHistoryStatusKey = strtolower(trim((string)$buyerOrderHistoryStatus));
if (in_array($buyerOrderHistoryStatusKey, ['shipped', 'delivered'], true) && $buyerOrderHistoryShipId > 0) {
    $buyerOrderHistoryShipHref = 'order_detail.php?order_id=' . $buyerOrderHistoryShipId
        . ($buyerOrderHistoryShipCode !== '' ? ('&code=' . rawurlencode($buyerOrderHistoryShipCode)) : '');
}
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
$buyerReturnAlertCount = 0;
foreach ($buyerCommerceAlerts as $__baRet) {
    if (stripos((string)($__baRet['type'] ?? ''), 'return') !== false) {
        $buyerReturnAlertCount += (int)($__baRet['count'] ?? 0);
    }
}
$buyerNotifAttention = (int)$buyerNotifCount;
$buyerCancelledCount = (int)$buyerLife['cancel'] + (int)$buyerLife['cancellation'];
$buyerNotifTypeKey = static function (string $type): string {
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
$buyerNotifIcon = static function (string $key): string {
    $map = [
        'alerts' => 'ion-ios-bell',
        'pending' => 'ion-ios-time',
        'paid' => 'ion-card',
        'shipping' => 'ion-ios-box',
        'delivery' => 'ion-checkmark-circled',
        'cancelled' => 'ion-ios-close',
        'returns' => 'ion-ios-undo',
        'update' => 'ion-android-notifications',
    ];
    return $map[$key] ?? 'ion-android-notifications';
};
$buyerNotifTabs = [
    ['key' => 'all', 'label' => 'All', 'count' => 0],
    ['key' => 'alerts', 'label' => 'Alerts', 'count' => (int)$buyerAlertCount],
    ['key' => 'pending', 'label' => 'Pending', 'count' => (int)$buyerLife['pending']],
    ['key' => 'paid', 'label' => 'Paid', 'count' => (int)$buyerLife['paid']],
    ['key' => 'shipping', 'label' => 'Shipping', 'count' => (int)$buyerLife['shipping']],
    ['key' => 'delivery', 'label' => 'Delivery', 'count' => (int)$buyerLife['delivery']],
    ['key' => 'cancelled', 'label' => 'Cancelled', 'count' => (int)$buyerCancelledCount],
    ['key' => 'returns', 'label' => 'Returns', 'count' => (int)$buyerReturnAlertCount],
];
$buyerLifeStages = [
    [
        'key' => 'pending',
        'label' => 'Pending',
        'count' => (int)$buyerLife['pending'],
        'hint' => 'Waiting for your payment',
        'href' => 'Your_Shopping_preferences.php#order-history',
    ],
    [
        'key' => 'paid',
        'label' => 'Paid',
        'count' => (int)$buyerLife['paid'],
        'hint' => 'Payment confirmed — seller ships',
        'href' => 'Your_Shopping_preferences.php#order-history',
    ],
    [
        'key' => 'cancel',
        'label' => 'Cancel',
        'count' => (int)$buyerLife['cancel'],
        'hint' => 'Seller cancelled your order',
        'href' => 'Your_Shopping_preferences.php#order-history',
    ],
    [
        'key' => 'cancellation',
        'label' => 'Cancellation',
        'count' => (int)$buyerLife['cancellation'],
        'hint' => 'You cancelled your order',
        'href' => 'Your_Shopping_preferences.php#order-history',
    ],
    [
        'key' => 'shipping',
        'label' => 'Shipping',
        'count' => (int)$buyerLife['shipping'],
        'hint' => 'In transit to you',
        'href' => org_shop_buyer_latest_order_detail_href($dbh, $meId, ['shipped']),
    ],
    [
        'key' => 'delivery',
        'label' => 'Delivery',
        'count' => (int)$buyerLife['delivery'],
        'hint' => 'Your item is now delivered',
        'href' => org_shop_buyer_latest_order_detail_href($dbh, $meId, ['delivered']),
    ],
];

$buyerInvoiceContactEmail = '';
$buyerInvoiceContactPhone = '';
$buyerInvoiceContactAddress = '';
if ($buyerOrderHistorySelected) {
    $buyerInvoiceSeller = (string)$buyerOrderHistorySelected['company'];
    $buyerInvoiceDate = (string)$buyerOrderHistorySelected['date'];
    $buyerInvoiceDueDate = (string)$buyerOrderHistorySelected['due'];
    $buyerInvoiceSubtotalCents = (int)($buyerOrderHistorySelected['merchandise_cents'] ?? 0);
    $buyerInvoiceDiscountCents = (int)($buyerOrderHistorySelected['discount_cents'] ?? 0);
    $buyerInvoiceTaxCents = (int)($buyerOrderHistorySelected['tax_cents'] ?? 0);
    $buyerInvoiceShippingCents = (int)($buyerOrderHistorySelected['shipping_fee_cents'] ?? 0);
    $buyerInvoiceServiceFeeCents = (int)($buyerOrderHistorySelected['service_fee_cents'] ?? 0);
    $buyerInvoiceGrandCents = (int)($buyerOrderHistorySelected['total_cents'] ?? 0);
    $buyerInvoiceSubtotalLabel = (string)($buyerOrderHistorySelected['subtotal_label'] ?? org_shop_format_price($buyerInvoiceSubtotalCents, 'USD'));
    $buyerInvoiceDiscountLabel = (string)($buyerOrderHistorySelected['discount_label'] ?? org_shop_format_price($buyerInvoiceDiscountCents, 'USD'));
    $buyerInvoiceTaxLabel = (string)($buyerOrderHistorySelected['tax_label'] ?? org_shop_format_price($buyerInvoiceTaxCents, 'USD'));
    $buyerInvoiceShippingLabel = (string)($buyerOrderHistorySelected['shipping_label'] ?? ($buyerInvoiceShippingCents > 0 ? org_shop_format_price($buyerInvoiceShippingCents, 'USD') : 'Free'));
    $buyerInvoiceServiceFeeLabel = (string)($buyerOrderHistorySelected['service_fee_label'] ?? org_shop_format_price($buyerInvoiceServiceFeeCents, 'USD'));
    $buyerInvoiceGrandLabel = (string)($buyerOrderHistorySelected['total'] ?? org_shop_format_price($buyerInvoiceGrandCents, 'USD'));
    $buyerInvoiceContactEmail = (string)($buyerOrderHistorySelected['contact_email'] ?? '');
    $buyerInvoiceContactPhone = (string)($buyerOrderHistorySelected['contact_phone'] ?? '');
    $buyerInvoiceContactAddress = (string)($buyerOrderHistorySelected['contact_address'] ?? '');
}
?>
<!doctype html>
<html <?= app_html_lang_attrs() ?>>
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
    .shop-customer-hub{display:grid;grid-template-columns:minmax(360px,1300px);gap:14px;margin:4px 0 72px;}
    .shop-customer-card{background:transparent;border:0;border-radius:0;color:var(--shop-text,var(--msb-palette-text,#111827));box-shadow:none;}
    .shop-customer-card{padding:0 16px 16px;display:flex;flex-direction:column;gap:14px;}
    .shop-customer-kicker{margin:0 0 3px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-customer-name{margin:0;font-size:20px;font-weight:850;line-height:1.15;}
    .shop-customer-sub{margin:4px 0 0;font-size:13px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-customer-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
    .shop-customer-stats-3{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;}
    .shop-customer-stat{
      border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:8px;
      padding:10px 12px;min-height:0;min-width:0;
      background:var(--shop-card-raised,var(--msb-palette-surface-2,rgba(15,23,42,.025)));
    }
    .shop-customer-stat strong{display:block;font-size:16px;line-height:1.15;font-weight:800;color:var(--shop-text,var(--msb-palette-text,#0f172a));}
    .shop-customer-stat span{display:block;margin-top:4px;font-size:11px;font-weight:650;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    #membership .shop-customer-stats-3{max-width:520px;}
    #membership .shop-customer-stat{padding:8px 10px;}
    #membership .shop-customer-stat strong{font-size:14px;}
    #membership .shop-customer-stat span{font-size:10.5px;margin-top:3px;}
    #customer-dashboard .shop-customer-stat{padding:16px 18px;}
    #customer-dashboard .shop-customer-stat strong{font-size:28px;}
    #customer-dashboard .shop-customer-stat span{margin-top:8px;font-size:13px;}
    .shop-customer-actions{display:flex;flex-wrap:wrap;gap:8px;}
    .shop-customer-action{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));border-radius:4px;padding:8px 10px;color:var(--shop-text,var(--msb-palette-text,#111827));text-decoration:none;font-size:12px;font-weight:800;background:var(--shop-btn-outline-bg,transparent);}
    .shop-customer-action:hover{text-decoration:none;background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));}
    #customer-dashboard > div:first-child{margin-top:-28px;margin-bottom:18px;}
    #customer-dashboard .shop-customer-actions{margin-top:54px;transform:translateY(34px);}
    .shop-pref-panel:not(#customer-dashboard) > div:first-child{margin-top:0;}
    .shop-pref-panel.is-active > .shop-customer-kicker,
    .shop-pref-panel.is-active > div:first-child > .shop-customer-kicker,
    .shop-seller-msg-intro .shop-customer-kicker,
    .shop-support-msg-intro .shop-customer-kicker{margin-top:0;}
    .shop-pref-panel{display:none;}
    .shop-pref-panel.is-active{display:flex;flex-direction:column;gap:14px;}
    #notifications.shop-pref-panel.is-active{
      gap:0;
      height:100%;
      max-height:100%;
      min-height:0;
      overflow:hidden !important;
    }
    #notifications .shop-buyer-notif-head{
      flex:0 0 auto;
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      padding:2px 2px 10px;
    }
    #notifications .shop-buyer-notif-head-copy{min-width:0;}
    #notifications .shop-buyer-notif-head .shop-customer-kicker{margin:0 0 2px;}
    #notifications .shop-buyer-notif-head .shop-customer-name{margin:0;font-size:22px;}
    #notifications .shop-buyer-notif-head .shop-customer-sub{margin:4px 0 0;}
    #notifications .shop-buyer-notif-tabs{
      flex:0 0 auto;
      display:flex;
      align-items:center;
      gap:4px;
      overflow-x:auto;
      overflow-y:hidden;
      -webkit-overflow-scrolling:touch;
      scrollbar-width:none;
      border-bottom:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      margin:0 0 4px;
      padding:0 2px;
    }
    #notifications .shop-buyer-notif-tabs::-webkit-scrollbar{display:none;}
    #notifications .shop-buyer-notif-tab{
      flex:0 0 auto;
      appearance:none;
      -webkit-appearance:none;
      border:0 !important;
      outline:none !important;
      box-shadow:none !important;
      background:transparent;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
      font-size:13px;
      font-weight:700;
      line-height:1.2;
      padding:10px 12px 11px;
      margin:0;
      cursor:pointer;
      border-bottom:2px solid transparent !important;
      border-radius:0;
      white-space:nowrap;
    }
    #notifications .shop-buyer-notif-tab:hover,
    #notifications .shop-buyer-notif-tab:focus,
    #notifications .shop-buyer-notif-tab:focus-visible,
    #notifications .shop-buyer-notif-tab:active{
      outline:none !important;
      box-shadow:none !important;
      border-color:transparent !important;
      border-bottom-color:transparent !important;
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
    }
    #notifications .shop-buyer-notif-tab.is-active,
    #notifications .shop-buyer-notif-tab.is-active:hover,
    #notifications .shop-buyer-notif-tab.is-active:focus,
    #notifications .shop-buyer-notif-tab.is-active:focus-visible,
    #notifications .shop-buyer-notif-tab.is-active:active{
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
      outline:none !important;
      box-shadow:none !important;
      border-color:transparent !important;
      border-bottom-color:var(--shop-link,var(--msb-palette-action,#2563eb)) !important;
    }
    #notifications .shop-buyer-notif-tab-count{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:16px;
      height:16px;
      margin-left:6px;
      padding:0 5px;
      border-radius:999px;
      background:#dc3545;
      color:#fff;
      font-size:10px;
      font-weight:800;
      line-height:1;
      vertical-align:middle;
    }
    #notifications .shop-buyer-notif-tab:not(.is-active) .shop-buyer-notif-tab-count{
      background:rgba(220,53,69,.85);
    }
    #notifications .shop-buyer-notif-scroll{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      overflow:hidden;
      gap:0;
    }
    #notifications .shop-buyer-notif-feed{
      flex:1 1 auto;
      min-height:0;
      overflow-y:auto;
      overflow-x:hidden;
      -webkit-overflow-scrolling:touch;
      display:flex;
      flex-direction:column;
      gap:0;
      padding:0 2px 10px;
      scrollbar-width:thin;
      scrollbar-color:var(--shop-border-strong,var(--shop-border,#94a3b8)) transparent;
    }
    #notifications .shop-buyer-notif-feed::-webkit-scrollbar{width:6px;}
    #notifications .shop-buyer-notif-feed::-webkit-scrollbar-thumb{
      background:var(--shop-border-strong,var(--shop-border,#94a3b8));
      border-radius:999px;
    }
    #notifications .shop-buyer-notif-empty{
      padding:28px 12px;
      text-align:center;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
      font-size:13px;
      font-weight:600;
    }
    #notifications .shop-buyer-notif-row{
      display:flex;
      align-items:flex-start;
      gap:12px;
      padding:14px 8px;
      border-bottom:1px solid var(--shop-border,var(--msb-palette-border,#eef2f7));
      text-decoration:none !important;
      color:inherit !important;
      background:transparent;
    }
    #notifications .shop-buyer-notif-row:hover{
      background:var(--msb-palette-action-soft,rgba(37,99,235,.06));
    }
    #notifications .shop-buyer-notif-row[hidden]{display:none !important;}
    #notifications .shop-buyer-notif-ico{
      flex:0 0 auto;
      width:36px;
      height:36px;
      border-radius:50%;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:14px;
      background:rgba(37,99,235,.12);
      color:#2563eb;
    }
    #notifications .shop-buyer-notif-row.is-alert .shop-buyer-notif-ico{
      background:rgba(220,53,69,.12);
      color:#dc2626;
    }
    #notifications .shop-buyer-notif-row.is-delivery .shop-buyer-notif-ico{
      background:rgba(22,163,74,.12);
      color:#16a34a;
    }
    #notifications .shop-buyer-notif-row-body{min-width:0;flex:1 1 auto;}
    #notifications .shop-buyer-notif-row-top{
      display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 3px;
    }
    #notifications .shop-buyer-notif-row-top strong{
      font-size:13px;font-weight:800;line-height:1.25;color:var(--shop-text,var(--msb-palette-text,#0f172a));
    }
    #notifications .shop-buyer-notif-row-when{
      font-size:11px;font-weight:600;color:#94a3b8;
    }
    #notifications .shop-buyer-notif-row-copy{
      display:block;margin:0;font-size:12.5px;line-height:1.4;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
    }
    #notifications .shop-buyer-notif-alert-badge{
      display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;
      border-radius:999px;background:#dc3545;color:#fff;font-size:11px;font-weight:800;
    }
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
    #order-history .shop-pref-table-wrap{
      min-height:480px;
      max-height:min(640px,calc(100vh - 200px));
    }
    #order-history .shop-pref-table-wrap.is-more-open{
      overflow:visible;
      min-height:560px;
    }
    #returns-refunds .shop-pref-table-wrap{
      min-height:480px;
      max-height:min(640px,calc(100vh - 200px));
    }
    #returns-refunds .shop-pref-table-wrap.is-more-open{
      overflow:visible;
      min-height:560px;
    }
    #returns-refunds .shop-pref-table th.shop-col-center,
    #returns-refunds .shop-pref-table td.shop-col-center{text-align:center;}
    #returns-refunds .shop-pref-table th.shop-col-center::before,
    #returns-refunds .shop-pref-table th.shop-col-center::after{display:none;}
    #returns-refunds .shop-pref-table th:nth-child(1),
    #returns-refunds .shop-pref-table th:nth-child(4),
    #returns-refunds .shop-pref-table th:nth-child(5),
    #returns-refunds .shop-pref-table th:nth-child(6){width:1%;white-space:nowrap;}
    .shop-pref-table tr[data-return-row]{cursor:pointer;}
    .shop-pref-table tr[data-return-row]:hover td,
    .shop-pref-table tr[data-return-row].is-selected td{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));}
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
    .shop-table-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px;margin:0;padding:0 4px 0 0;position:relative;z-index:1;}
    .shop-table-action{width:32px;height:32px;flex:0 0 32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));border-radius:5px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));color:var(--shop-link,var(--msb-palette-action,#2563eb));font-size:17px;text-decoration:none;cursor:pointer;}
    .shop-table-action:hover{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));text-decoration:none;}
    .shop-order-cancel-btn{border:1px solid #fecaca;border-radius:4px;background:#fff;color:#b91c1c;font-size:11px;font-weight:800;padding:5px 10px;cursor:pointer;white-space:nowrap;}
    .shop-order-cancel-btn:hover{background:#fef2f2;}
    .shop-order-cancel-btn:disabled{opacity:.55;cursor:default;}
    .shop-pref-table th.shop-order-cancel-col::before,.shop-pref-table th.shop-order-cancel-col::after{display:none;}
    .shop-order-row-actions{display:inline-flex;align-items:center;justify-content:flex-end;gap:8px;width:100%;min-width:0;}
    .shop-order-more{position:relative;flex:0 0 auto;}
    .shop-order-more-btn{
      width:28px;height:28px;padding:0;border:0;border-radius:6px;background:transparent;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;line-height:1;
    }
    .shop-order-more-btn:hover,.shop-order-more.is-open .shop-order-more-btn{
      background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));
      color:var(--shop-text,var(--msb-palette-text,#111827));
    }
    .shop-order-more-menu{
      display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:40;min-width:220px;
      padding:8px;border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      border-radius:8px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      box-shadow:0 10px 28px rgba(15,23,42,.14);
    }
    .shop-order-more.is-open .shop-order-more-menu{
      display:block;position:fixed;z-index:10060;
      /* top/left set in JS so the menu escapes table overflow clipping */
    }
    .shop-order-more-menu a,.shop-order-more-menu button{
      display:flex;align-items:center;width:100%;min-height:56px;box-sizing:border-box;
      text-align:left;border:0;background:transparent;cursor:pointer;
      padding:16px 18px;border-radius:6px;font-size:15px;font-weight:650;line-height:1.35;
      color:var(--shop-text,var(--msb-palette-text,#111827));text-decoration:none;
    }
    .shop-order-more-menu a:hover,.shop-order-more-menu button:hover{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f3f4f6));}
    .shop-order-more-menu button.is-danger{color:#b91c1c;}
    html body dialog.shop-order-return-dialog{
      position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;
      width:min(360px,calc(100vw - 32px))!important;max-width:360px!important;height:max-content!important;min-height:0!important;
      max-height:calc(100dvh - 32px)!important;margin:auto!important;padding:20px 18px 16px!important;overflow:auto!important;
      transform:none!important;border:1px solid var(--msb-palette-border,rgba(148,163,184,.28))!important;border-radius:14px!important;
      background:var(--msb-palette-surface,var(--msb-palette-bg,#fff))!important;color:var(--msb-palette-text,#111827)!important;
      box-shadow:0 18px 48px rgba(0,0,0,.28)!important;text-align:center!important;box-sizing:border-box!important;z-index:2147483647!important;
    }
    .shop-order-return-dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);}
    html body dialog.shop-order-return-dialog:not([open]){display:none!important;}
    html body dialog.shop-order-return-dialog[open]{display:block!important;}
    html body .shop-order-return-close{
      position:absolute!important;top:10px!important;right:10px!important;width:28px!important;height:28px!important;
      margin:0!important;padding:0!important;border:0!important;border-radius:50%!important;background:transparent!important;
      color:var(--msb-palette-text-muted,var(--msb-palette-muted,#64748b))!important;font-size:18px!important;line-height:28px!important;
      cursor:pointer!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;
    }
    .shop-order-return-close:hover{background:var(--msb-palette-hover-bg,var(--msb-palette-surface-2,rgba(148,163,184,.14)));color:var(--msb-palette-text,#111827);}
    html body .shop-order-return-icon{
      position:static!important;display:grid!important;place-items:center!important;width:40px!important;height:40px!important;
      margin:0 auto 10px!important;border-radius:50%!important;background:rgba(239,68,68,.12)!important;color:#dc2626!important;font-size:16px!important;
    }
    html body .shop-order-return-dialog h2{
      position:static!important;display:block!important;margin:0 28px 6px!important;padding:0!important;color:inherit!important;
      font-size:15px!important;font-weight:700!important;line-height:1.3!important;
    }
    html body .shop-order-return-dialog > p{
      position:static!important;display:block!important;margin:0 0 12px!important;padding:0!important;
      color:var(--msb-palette-text-muted,var(--msb-palette-muted,#64748b))!important;font-size:13px!important;line-height:1.45!important;
    }
    html body .shop-order-return-reason{
      display:block;width:100%;box-sizing:border-box;min-height:72px;margin:0;padding:10px 12px;resize:vertical;
      border:1px solid var(--msb-palette-border,rgba(148,163,184,.38));border-radius:10px;
      background:var(--msb-palette-input-bg,var(--msb-palette-bg,#fff));color:var(--msb-palette-text,#111827);
      font-size:13px;font-weight:500;line-height:1.4;outline:none;text-align:left;
    }
    html body .shop-order-return-reason:focus{border-color:var(--msb-palette-border-strong,rgba(15,23,42,.28));}
    html body .shop-order-return-actions{
      position:static!important;display:flex!important;gap:8px!important;width:100%!important;margin:16px 0 0!important;padding:0!important;
    }
    .shop-order-return-actions button{flex:1 1 0;height:34px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer;}
    .shop-order-return-cancel{
      border:1px solid var(--msb-palette-border,rgba(148,163,184,.38));
      background:var(--msb-palette-hover-bg,var(--msb-palette-surface-2,transparent));
      color:var(--msb-palette-text,#111827);
    }
    .shop-order-return-confirm{border:1px solid #dc2626;background:#dc2626;color:#fff;}
    .shop-order-return-confirm:disabled{opacity:.65;cursor:default;}
    html body .shop-order-return-dialog.is-result .shop-order-return-icon{
      background:rgba(22,163,74,.12)!important;color:#16a34a!important;
    }
    html body .shop-order-return-dialog.is-result.is-error .shop-order-return-icon{
      background:rgba(239,68,68,.12)!important;color:#dc2626!important;
    }
    html body .shop-order-return-result-ok{
      flex:1 1 0;height:34px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer;
      border:1px solid #16a34a;background:#16a34a;color:#fff;
    }
    html body .shop-order-return-dialog.is-result.is-error .shop-order-return-result-ok{
      border-color:#dc2626;background:#dc2626;
    }
    html body .shop-order-review-dialog .shop-order-return-icon{
      background:rgba(245,158,11,.14)!important;color:#d97706!important;
    }
    html body .shop-order-review-dialog .shop-order-return-confirm{
      border:1px solid #2563eb;background:#2563eb;color:#fff;
    }
    html body .shop-order-review-stars{
      display:flex;justify-content:center;gap:6px;margin:0 0 12px;
    }
    html body .shop-order-review-star{
      width:36px;height:36px;border:0;border-radius:50%;padding:0;cursor:pointer;
      background:transparent;color:#cbd5e1;font-size:22px;line-height:1;
      display:inline-flex;align-items:center;justify-content:center;
    }
    html body .shop-order-review-star:hover,
    html body .shop-order-review-star.is-on{color:#f59e0b;}
    html body .shop-order-review-star:focus{outline:2px solid rgba(37,99,235,.35);outline-offset:2px;}
    html.dark-auto .shop-order-more-btn:hover,
    html.dark-auto .shop-order-more.is-open .shop-order-more-btn{background:rgba(148,163,184,.14);color:inherit;}
    html.dark-auto .shop-order-more-menu{
      background:var(--msb-palette-surface,#1a1f27);border-color:var(--msb-palette-border,rgba(148,163,184,.28));
      box-shadow:0 12px 32px rgba(0,0,0,.45);
    }
    html.dark-auto .shop-order-more-menu a:hover,
    html.dark-auto .shop-order-more-menu button:hover{background:rgba(148,163,184,.12);}
    #order-history .shop-pref-table th.shop-col-center,
    #order-history .shop-pref-table td.shop-col-center{text-align:center;}
    #order-history .shop-pref-table th.shop-col-center::before,
    #order-history .shop-pref-table th.shop-col-center::after{display:none;}
    #order-history .shop-pref-table th:nth-child(1),
    #order-history .shop-pref-table th:nth-child(3),
    #order-history .shop-pref-table th:nth-child(4){width:1%;white-space:nowrap;}
    .shop-invoice-status.is-ship-link{cursor:pointer;text-decoration:none;color:var(--shop-link,var(--msb-palette-action,#2563eb));}
    .shop-invoice-status.is-ship-link:hover{text-decoration:underline;}
    #order-history .shop-pref-table .shop-order-status-link,
    #returns-refunds .shop-pref-table .shop-order-status-link{
      color:var(--shop-link,var(--msb-palette-action,#2563eb));font-weight:800;text-decoration:none;
    }
    #order-history .shop-pref-table .shop-order-status-link:hover,
    #returns-refunds .shop-pref-table .shop-order-status-link:hover{text-decoration:underline;}
    #customer-dashboard{max-width:860px;}
    .shop-invoice-layout{display:grid;grid-template-columns:minmax(680px,1fr) 380px;gap:18px;align-items:start;}
    .shop-invoice-side{display:flex;flex-direction:column;gap:12px;}
    .shop-invoice-title{margin-top:-70px;font-size:20px;font-weight:500;line-height:1.2;color:var(--shop-text,var(--msb-palette-text,#111827));}
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
    .shop-pref-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 0;}
    .shop-pref-head h1{font-size:22px;font-weight:850;margin:10px;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-pref-head a{font-size:13px;font-weight:800;color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;}
    .shop-pref-head a:hover{text-decoration:underline;}
    .shop-pref-layout{display:grid;grid-template-columns:240px minmax(0,1fr);gap:18px;align-items:start;margin-top:0;}
    body.shopping-preferences-page{
      --shop-pref-frame-h:calc(100vh - 140px);
    }
    body.shopping-preferences-page .shop-customer-hub{margin:0 0 48px;}
    body.shopping-preferences-page .shop-customer-card{padding:0 !important;gap:0 !important;}
    .shop-pref-side{
      display:flex;flex-direction:column;gap:10px;
      position:sticky;top:72px;align-self:start;z-index:5;
      height:var(--shop-pref-frame-h);max-height:var(--shop-pref-frame-h);
      overflow:hidden;padding-bottom:0;box-sizing:border-box;
    }
    .shop-pref-support-card{
      border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:6px;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:10px;display:flex;flex-direction:column;gap:2px;
      flex:0 0 auto;margin-bottom:0;padding-bottom:10px;
    }
    .shop-pref-support-card .shop-pref-nav-title{margin-bottom:6px;}
    .shop-pref-nav{
      border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:6px;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:10px;
      flex:1 1 auto;min-height:0;height:auto;max-height:none;
      display:flex;flex-direction:column;overflow:hidden;
    }
    .shop-pref-nav-title{flex:0 0 auto;margin:0 0 8px;padding:4px 6px;font-size:11px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-pref-nav-list{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:3px;margin:0;padding:0 4px 0 0;list-style:none;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:var(--shop-border-strong,var(--shop-border,#475569)) transparent;}
    .shop-pref-nav-list::-webkit-scrollbar{width:6px;}
    .shop-pref-nav-list::-webkit-scrollbar-thumb{background:var(--shop-border-strong,var(--shop-border,#475569));border-radius:999px;}
    .shop-pref-help-list{
      flex:0 0 auto;min-height:0;overflow:visible;padding:0;margin:0;
    }
    .shop-pref-help-list > li{list-style:none;margin:0;padding:0;}
    .shop-pref-nav-link{
      display:flex;align-items:flex-start;gap:9px;min-height:34px;padding:7px 8px;border-radius:8px;
      color:var(--shop-text,var(--msb-palette-text,#111827));font-size:13px;font-weight:800;text-decoration:none;
      background:transparent;border:0;box-shadow:none;outline:none;
      transition:background .15s ease,color .15s ease;
    }
    .shop-pref-nav-link i{
      width:16px;text-align:center;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
      font-size:15px;margin-top:2px;flex:0 0 auto;transition:color .15s ease;
    }
    .shop-pref-nav-link .shop-pref-nav-copy{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1 1 auto;}
    .shop-pref-nav-link .shop-pref-nav-label-row{
      display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:nowrap;
    }
    .shop-pref-nav-link .shop-pref-nav-label{
      font-size:13px;font-weight:800;line-height:1.25;min-width:0;
      color:inherit;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
      transition:color .15s ease;
    }
    .shop-pref-nav-link .shop-pref-nav-sub{
      font-size:11px;font-weight:500;line-height:1.35;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
      transition:color .15s ease;
    }
    /* Hover — soft fill only; not the active switch */
    .shop-pref-nav-link:hover:not(.is-active),.shop-pref-nav-link:focus:not(.is-active){
      background:var(--msb-palette-action-soft,rgba(37,99,235,.12));
      color:var(--shop-text,var(--msb-palette-text,#111827));
      text-decoration:none;border:0;box-shadow:none;outline:none;
    }
    .shop-pref-nav-link:hover:not(.is-active) i,.shop-pref-nav-link:focus:not(.is-active) i{
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
    }
    /* Active = blue icon + label only (no filled box) */
    .shop-pref-nav-link.is-active,
    .shop-pref-nav-link.is-active:hover,
    .shop-pref-nav-link.is-active:focus{
      background:transparent;
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
      text-decoration:none;border:0;box-shadow:none;
    }
    .shop-pref-nav-link.is-active i,
    .shop-pref-nav-link.is-active .shop-pref-nav-label,
    .shop-pref-nav-link.is-active:hover i,
    .shop-pref-nav-link.is-active:focus i,
    .shop-pref-nav-link.is-active:hover .shop-pref-nav-label,
    .shop-pref-nav-link.is-active:focus .shop-pref-nav-label{
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
    }
    .shop-pref-nav-link.is-active .shop-pref-nav-sub,
    .shop-pref-nav-link.is-active:hover .shop-pref-nav-sub,
    .shop-pref-nav-link.is-active:focus .shop-pref-nav-sub{
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
    }
    .shop-pref-nav-badge{
      margin-left:0;flex:0 0 auto;min-width:18px;height:18px;padding:0 6px;border-radius:999px;
      display:inline-flex;align-items:center;justify-content:center;background:#dc3545;color:#fff;
      font-size:10px;font-weight:800;line-height:1;
    }
    .shop-pref-nav-link.is-active .shop-pref-nav-badge{
      background:#dc3545;color:#fff;
    }
    .shop-pref-live-label{margin:0 0 2px;padding:0 2px;font-size:11px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-pref-support-center{
      display:flex;align-items:flex-start;gap:8px;padding:10px;
      border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:6px;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      color:var(--shop-text,var(--msb-palette-text,#111827));font-size:14px;font-weight:850;
      text-decoration:none;line-height:1.25;
    }
    .shop-pref-support-center .shop-pref-nav-copy{flex:1 1 auto;}
    .shop-pref-support-center:hover,.shop-pref-support-center:focus,.shop-pref-support-center.is-active{
      color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;border-color:rgba(37,99,235,.35);
    }
    .shop-pref-support-center i{font-size:16px;margin-top:2px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-pref-support-center.is-active i,.shop-pref-support-center:hover i{color:inherit;}
    .shop-guidance-list{
      display:flex;flex-direction:column;gap:0;
      border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:10px;
      overflow:auto;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      margin:0 0 16px;max-height:min(62vh,560px);-webkit-overflow-scrolling:touch;
      scrollbar-width:thin;scrollbar-color:var(--shop-border-strong,var(--shop-border,#475569)) transparent;
    }
    .shop-guidance-list::-webkit-scrollbar{width:6px;}
    .shop-guidance-list::-webkit-scrollbar-thumb{background:var(--shop-border-strong,var(--shop-border,#475569));border-radius:999px;}
    .shop-guidance-item{padding:14px 14px;border-top:1px solid var(--shop-border,rgba(148,163,184,.28));flex:0 0 auto;}
    .shop-pref-panel.is-active{padding-bottom:28px;}
    .shop-guidance-item:first-child{border-top:0;}
    .shop-guidance-item strong{display:block;font-size:13.5px;font-weight:800;margin-bottom:4px;}
    .shop-guidance-item p{margin:0;font-size:12.5px;line-height:1.45;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-account-update-card{border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:10px;padding:14px 16px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));}
    .shop-account-update-ok{display:flex;align-items:center;gap:8px;margin:12px 0;padding:10px 12px;border-radius:10px;background:rgba(22,163,74,.12);color:#15803d;font-size:13px;font-weight:700;}
    .shop-account-update-fields{display:flex;flex-direction:column;gap:0;}
    .shop-account-update-fields div{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--shop-border,rgba(148,163,184,.28));font-size:13.5px;}
    .shop-account-update-fields div:first-child{border-top:0;}
    .shop-account-update-fields span{color:#15803d;font-weight:800;font-size:12px;}
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
    .shop-buyer-notif-alerts{display:grid;grid-template-columns:1fr;gap:8px;margin-top:0;}
    .shop-buyer-notif-alert{display:flex;flex-direction:column;gap:4px;padding:10px 12px;border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:10px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));text-decoration:none !important;color:inherit !important;}
    .shop-buyer-notif-alert:hover{border-color:rgba(14,165,233,.4);}
    .shop-buyer-notif-alert-title{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:800;line-height:1.2;color:#0f172a;}
    .shop-buyer-notif-alert-badge{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 7px;border-radius:999px;background:#dc3545;color:#fff;font-size:12px;font-weight:700;}
    .shop-buyer-notif-alert-copy{font-size:12px;line-height:1.4;font-weight:400;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-buyer-notif-feed{display:flex;flex-direction:column;gap:8px;}
    .shop-buyer-notif-feed-row{display:flex;flex-direction:column;gap:3px;padding:8px 12px;border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));border-radius:10px;text-decoration:none !important;color:inherit !important;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));}
    .shop-buyer-notif-feed-row:hover{border-color:rgba(37,99,235,.35);background:#f8fafc;}
    .shop-buyer-notif-feed-row strong{display:block;margin:0;padding:0;font-size:13px;font-weight:800;line-height:1.25;color:#0f172a;}
    .shop-buyer-notif-feed-row span{display:block;margin:0;font-size:12px;line-height:1.35;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .shop-buyer-notif-feed-when{font-size:11px !important;margin-top:1px !important;color:#94a3b8 !important;}
    @media (max-width:1100px){.shop-buyer-notif-grid{grid-template-columns:repeat(3,minmax(0,1fr));}}
    @media (max-width:900px){.shop-buyer-notif-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.shop-buyer-notif-alerts{grid-template-columns:1fr;}}
    @media (max-width:520px){.shop-buyer-notif-grid{grid-template-columns:1fr;}}
    body.shopping-preferences-page .sh-mainpanel{
      margin-left:var(--msb-leftbar-width,112px);
      overflow:visible !important;
    }
    body.shopping-preferences-page .sh-pagebody{
      max-width:none;
      overflow:visible !important;
    }
    body.shopping-preferences-page.shop-page.feed-insta-ui .shop-page-shell{
      padding-left:24px !important;
      padding-right:24px !important;
      padding-bottom:48px !important;
      display:block !important;
      overflow:visible !important;
      height:auto !important;
      min-height:0 !important;
    }
    body.shopping-preferences-page .shop-pref-layout{
      max-width:none;width:100%;
      align-items:start !important;
    }
    /* Keep left + right the same height for every row switch */
    body.shopping-preferences-page .shop-customer-hub{
      margin:0 0 32px !important;
      align-self:start !important;
      display:grid !important;
      grid-template-columns:minmax(0,1fr) !important;
      grid-template-rows:minmax(0,1fr) !important;
      height:var(--shop-pref-frame-h) !important;
      max-height:var(--shop-pref-frame-h) !important;
      min-height:0 !important;
      overflow:hidden !important;
    }
    body.shopping-preferences-page .shop-customer-card{
      padding:0 !important;gap:0 !important;
      height:100% !important;
      max-height:100% !important;
      min-height:0 !important;
      overflow:hidden !important;
      display:flex !important;
      flex-direction:column !important;
    }
    body.shopping-preferences-page .shop-pref-panel.is-active{
      flex:1 1 auto;
      min-height:0;
      height:100%;
      max-height:100%;
      overflow:auto;
      -webkit-overflow-scrolling:touch;
      box-sizing:border-box;
    }
    body.shopping-preferences-page #notifications.shop-pref-panel.is-active{
      overflow:hidden !important;
      display:flex !important;
      flex-direction:column !important;
    }
    body.shopping-preferences-page #order-history.shop-pref-panel.is-active{
      padding-right:14px;
      scrollbar-gutter:stable;
    }
    body.shopping-preferences-page #returns-refunds.shop-pref-panel.is-active{
      padding-right:14px;
      scrollbar-gutter:stable;
    }
    body.shopping-preferences-page.msgs-chat-active .shop-pref-layout{
      /* Keep same width as Guidance / other panels — do not widen */
      grid-template-columns:240px minmax(0,1fr);gap:18px;align-items:start;margin-bottom:32px;
    }
    body.shopping-preferences-page.msgs-chat-active .shop-pref-side{
      position:sticky;top:72px;align-self:start;z-index:5;
      height:var(--shop-pref-frame-h);max-height:var(--shop-pref-frame-h);
      padding-bottom:0;overflow:hidden;
    }
    body.shopping-preferences-page.msgs-chat-active .shop-customer-hub{margin:0 0 32px;align-self:start;}
    body.shopping-preferences-page.msgs-chat-active .shop-customer-card{
      padding:0;gap:0;height:100% !important;min-height:0 !important;background:transparent !important;border:0 !important;box-shadow:none !important;
    }
    body.shopping-preferences-page.msgs-chat-active .shop-pref-panel.is-active{
      overflow:hidden;
    }
    /* Guidance fills the same frame; FAQ list scrolls inside */
    #guidance-center.shop-pref-panel.is-active{
      display:flex !important;
      flex-direction:column;
      justify-content:flex-start !important;
      gap:0 !important;
      padding:12px 0 16px !important;
      margin:0 !important;
      height:100% !important;
      min-height:0 !important;
      overflow:hidden !important;
    }
    #guidance-center.shop-pref-panel.is-active > div:first-child{
      margin:0 0 28px !important;
      padding:0 !important;
      flex:0 0 auto !important;
      min-height:0 !important;
    }
    #guidance-center .shop-guidance-list{
      margin:0 0 10px !important;
      flex:1 1 auto !important;
      min-height:0 !important;
      max-height:none !important;
      overflow-y:auto !important;
      overflow-x:hidden !important;
      align-self:stretch;
    }
    #guidance-center.shop-pref-panel.is-active > .shop-customer-sub{
      margin:6px 0 0 !important;
    }
    body.shopping-preferences-page.msgs-seller-active #seller-messages.shop-pref-panel.is-active{
      height:100%;min-height:0;display:flex;flex-direction:column;padding:0;margin:0;gap:12px;
    }
    #seller-messages.shop-pref-panel.is-active{padding:0;margin:0;gap:12px;}
    .shop-seller-msg-intro{
      flex:0 0 auto;padding:0 2px 2px;border:0;background:transparent;
    }
    .shop-seller-msg-intro .shop-customer-kicker{
      margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;
    }
    .shop-seller-msg-intro .shop-customer-name{
      margin:0 0 6px;font-size:24px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.15;
    }
    .shop-seller-msg-intro .shop-customer-sub{
      margin:0;font-size:13px;line-height:1.45;color:#64748b;max-width:62ch;
    }
    #seller-messages .shop-seller-msg-shell{
      flex:1 1 auto;min-height:0;display:grid;grid-template-columns:minmax(280px,340px) minmax(0,1fr);
      gap:12px;border:0;border-radius:0;background:transparent;overflow:visible;box-shadow:none;
    }
    .shop-seller-msg-layout{display:contents;}
    .shop-seller-msg-rail{
      display:flex;flex-direction:column;min-width:0;min-height:0;
      border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
    }
    .shop-seller-msg-toolbar{
      display:flex;gap:8px;align-items:center;padding:12px 12px 10px;flex:0 0 auto;background:#fff;
      border-bottom:1px solid #f1f5f9;
    }
    .shop-seller-msg-search{position:relative;flex:1 1 auto;min-width:0;}
    .shop-seller-msg-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;pointer-events:none;}
    .shop-seller-msg-search input{
      width:100%;height:40px;border:1px solid #e2e8f0;border-radius:10px;padding:0 12px 0 34px;
      font-size:13px;background:#f8fafc;color:#0f172a;outline:none;
    }
    .shop-seller-msg-search input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
    .shop-seller-msg-filter{
      flex:0 0 auto;height:40px;padding:0 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;
      font-size:13px;font-weight:700;color:#334155;cursor:pointer;display:inline-flex;align-items:center;gap:6px;
    }
    .shop-seller-msg-list{flex:1 1 auto;min-height:0;overflow:auto;background:#fff;border:0;max-height:none;}
    .shop-seller-msg-item-row{display:block;border-bottom:0;position:relative;}
    .shop-seller-msg-item{
      display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:10px;align-items:start;
      padding:12px 14px;color:inherit;text-decoration:none;min-width:0;
    }
    .shop-seller-msg-item-row:hover{background:#f8fafc;}
    .shop-seller-msg-item-row.is-active{background:#eff6ff;}
    .shop-seller-msg-item-row.is-active .shop-seller-msg-item{box-shadow:none;}
    .shop-seller-msg-ava{width:42px;height:42px;border-radius:999px;object-fit:cover;background:#e2e8f0;display:block;}
    .shop-seller-msg-item-main{min-width:0;}
    .shop-seller-msg-item-top{display:flex;align-items:center;gap:6px;min-width:0;}
    .shop-seller-msg-item-top strong{font-size:13.5px;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .shop-seller-msg-verified{color:#2563eb;font-size:12px;flex:0 0 auto;line-height:1;}
    .shop-seller-msg-item-preview{display:block;margin-top:3px;font-size:12px;line-height:1.35;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .shop-seller-msg-item-time{font-size:11px;font-weight:600;color:#94a3b8;padding-top:2px;white-space:nowrap;}
    .shop-seller-msg-remove{display:none !important;}
    .shop-seller-msg-chat{
      display:flex;flex-direction:column;min-height:0;height:100%;
      border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
    }
    .shop-seller-msg-head{
      display:flex;align-items:center;gap:12px;padding:12px 16px;
      border-bottom:1px solid #eef2f7;background:#fff;flex:0 0 auto;
    }
    .shop-seller-msg-head-ava{width:42px;height:42px;border-radius:999px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
    .shop-seller-msg-head-meta{flex:0 1 auto;min-width:0;max-width:42%;}
    .shop-seller-msg-head-name{display:flex;align-items:center;gap:6px;font-size:15px;font-weight:800;color:#0f172a;margin:0;line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;}
    .shop-seller-msg-head-status{
      display:inline-flex;align-items:center;gap:6px;width:auto;max-width:100%;margin:1px 0 0;
      font-size:12px;font-weight:600;line-height:1.2;color:#16a34a;
      background:transparent!important;border:0!important;box-shadow:none!important;filter:none!important;
    }
    .shop-seller-msg-head-status i{font-size:8px;line-height:1;flex:0 0 auto;background:transparent!important;}
    .shop-seller-msg-head-info{
      display:inline-flex;align-items:center;justify-content:center;
      width:22px;height:22px;margin-left:2px;border-radius:999px;
      color:#64748b;text-decoration:none;flex:0 0 auto;line-height:1;
    }
    .shop-seller-msg-head-info:hover{color:#2563eb;background:#eff6ff;text-decoration:none;}
    .shop-seller-msg-head-info i{font-size:14px;line-height:1;background:transparent!important;}
    .shop-seller-msg-more{position:relative;flex:0 0 auto;}
    .shop-seller-msg-more-btn{
      width:36px;height:36px;border:0;border-radius:999px;background:transparent;color:#64748b;
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
    }
    .shop-seller-msg-more-btn:hover,.shop-seller-msg-more.is-open .shop-seller-msg-more-btn{background:#f1f5f9;color:#0f172a;}
    .shop-seller-msg-more-menu{
      display:none !important;position:absolute;right:0;top:calc(100% + 4px);z-index:40;min-width:160px;
      padding:6px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;
      box-shadow:0 10px 30px rgba(15,23,42,.12);
    }
    .shop-seller-msg-more.is-open .shop-seller-msg-more-menu,
    .shop-seller-msg-more-menu.is-open{display:block !important;}
    .shop-seller-msg-more-menu a,
    .shop-seller-msg-more-menu button{
      display:flex !important;align-items:center;gap:10px;width:100%;text-align:left;padding:10px 12px;
      border:0;border-radius:8px;background:transparent;font-size:14px;font-weight:600;line-height:1.3;
      color:#0f172a !important;text-decoration:none;cursor:pointer;
    }
    .shop-seller-msg-more-menu a:hover,.shop-seller-msg-more-menu button:hover{background:#f1f5f9;}
    .shop-seller-msg-more-menu i{width:16px;text-align:center;opacity:.85;flex:0 0 auto;}
    .shop-seller-msg-history-head{
      padding:8px 12px 6px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748b;
    }
    .shop-seller-msg-history-list{max-height:280px;overflow:auto;padding:0 0 4px;}
    .shop-seller-msg-history-empty{padding:10px 12px;font-size:12px;color:#64748b;}
    .shop-seller-msg-history-item{
      display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:0;border-radius:8px;
      background:transparent;text-align:left;cursor:pointer;
    }
    .shop-seller-msg-history-item:hover,.shop-seller-msg-history-item.is-active{background:#f1f5f9;}
    .shop-seller-msg-history-item img{width:32px;height:32px;border-radius:6px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
    .shop-seller-msg-history-item-label{display:block;font-size:13px;font-weight:800;color:#0f172a;line-height:1.25;}
    .shop-seller-msg-history-item-meta{display:block;margin-top:2px;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .shop-seller-msg-history-bar{
      display:none;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #eef2f7;background:#fff;flex:0 0 auto;
    }
    .shop-seller-msg-history-bar.is-open{display:flex;}
    .shop-seller-msg-history-back{
      border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#334155;
      font-size:12px;font-weight:800;padding:6px 10px;cursor:pointer;flex:0 0 auto;
    }
    .shop-seller-msg-history-back:hover{background:#f8fafc;}
    .shop-seller-msg-history-title{font-size:13px;font-weight:800;color:#0f172a;margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .shop-seller-msg-chat.is-history-mode .shop-seller-msg-compose-wrap{display:none!important;}
    .shop-seller-msg-row[hidden]{display:none!important;}
    /* Same header row — pushed next to the ⋯ menu */
    .shop-seller-msg-product{
      display:grid;grid-template-columns:36px minmax(0,1fr) auto;gap:8px;align-items:center;
      flex:0 1 auto;min-width:0;max-width:380px;width:auto;margin:0 12px 0 auto;padding:5px 8px;
      border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;
    }
    .shop-seller-msg-product[hidden]{display:none!important;margin-left:0;}
    .shop-seller-msg-head:has(.shop-seller-msg-product[hidden]) .shop-seller-msg-more,
    .shop-seller-msg-head:not(:has(.shop-seller-msg-product)) .shop-seller-msg-more{margin-left:auto;}
    .shop-seller-msg-product img{width:36px;height:36px;border-radius:6px;object-fit:cover;background:#e2e8f0;}
    .shop-seller-msg-product > div{min-width:0;}
    .shop-seller-msg-product strong{
      display:block;font-size:12px;font-weight:800;color:#0f172a;line-height:1.2;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .shop-seller-msg-product .shop-seller-msg-product-id{
      display:block;margin-top:1px;font-size:10px;font-weight:700;letter-spacing:.02em;color:#64748b;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .shop-seller-msg-product .shop-seller-msg-product-price{
      display:block;margin:1px 0 0;font-size:11px;font-weight:700;color:#0f172a;line-height:1.2;
    }
    .shop-seller-msg-product a{
      flex:0 0 auto;padding:4px 8px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;
      font-size:11px;font-weight:800;color:#334155;text-decoration:none;white-space:nowrap;
    }
    .shop-seller-msg-product a:hover{background:#f1f5f9;text-decoration:none;}
    .shop-seller-msg-bubble.is-product-ref{
      display:grid;grid-template-columns:44px minmax(0,1fr);gap:10px;align-items:center;
      background:#eef2ff;color:#0f172a;border:1px solid #c7d2fe;
    }
    .shop-seller-msg-bubble.is-product-ref img{width:44px;height:44px;border-radius:8px;object-fit:cover;background:#e2e8f0;}
    .shop-seller-msg-bubble.is-product-ref .shop-seller-msg-bubble-prod-title{display:block;font-weight:800;font-size:13px;line-height:1.3;}
    .shop-seller-msg-bubble.is-product-ref .shop-seller-msg-bubble-prod-id{display:block;margin-top:2px;font-size:11px;font-weight:700;opacity:.85;}
    .shop-seller-msg-row.me .shop-seller-msg-bubble.is-product-ref{background:#dbeafe;border-color:#93c5fd;color:#0f172a;}
    .shop-seller-msg-thread{
      flex:1 1 auto;overflow:auto;padding:18px 28px 18px 16px;display:flex;flex-direction:column;gap:14px;
      background:#fff;min-height:0;box-sizing:border-box;
    }
    .shop-seller-msg-row{display:flex;gap:8px;align-items:flex-end;max-width:78%;}
    .shop-seller-msg-row.me{align-self:flex-end;flex-direction:row-reverse;margin-right:12px;}
    .shop-seller-msg-row.them{align-self:flex-start;}
    .shop-seller-msg-row-ava{width:28px;height:28px;border-radius:999px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
    .shop-seller-msg-row.me .shop-seller-msg-row-ava{display:none;}
    .shop-seller-msg-bubble-wrap{min-width:0;display:flex;flex-direction:column;gap:4px;max-width:100%;}
    .shop-seller-msg-row.me .shop-seller-msg-bubble-wrap{align-items:flex-end;}
    .shop-seller-msg-row.them .shop-seller-msg-bubble-wrap{align-items:flex-start;}
    .shop-seller-msg-bubble{
      display:block;width:fit-content;max-width:100%;box-sizing:border-box;margin:0;
      padding:10px 12px;border-radius:4px;border:1px solid #e5e7eb;
      font-size:13.5px;font-weight:500;line-height:1.45;text-align:left;
      white-space:pre-wrap;word-break:break-word;background:#fff;color:#0f172a;
    }
    .shop-seller-msg-bubble.me{
      background:#2563eb!important;border-color:#2563eb!important;
      color:#fff!important;-webkit-text-fill-color:#fff!important;
    }
    .shop-seller-msg-bubble.me a{color:#dbeafe!important;-webkit-text-fill-color:#dbeafe!important;}
    .shop-seller-msg-bubble.them{
      background:#f1f5f9!important;border-color:#f1f5f9!important;
      color:#0f172a!important;-webkit-text-fill-color:#0f172a!important;
    }
    .shop-seller-msg-meta{
      display:flex;align-items:center;gap:5px;font-size:11px;font-weight:500;
      color:#94a3b8!important;-webkit-text-fill-color:#94a3b8!important;
      line-height:1.2;margin:0 2px;padding:0;
    }
    .shop-seller-msg-row.me .shop-seller-msg-meta{justify-content:flex-end;}
    .shop-seller-msg-row.them .shop-seller-msg-meta{justify-content:flex-start;}
    .shop-seller-msg-meta .fa-check-double{font-size:11px;color:#60a5fa;}
    .shop-seller-msg-compose-wrap{padding:12px 14px 14px;border-top:1px solid #eef2f7;background:#fff;flex:0 0 auto;}
    .shop-seller-msg-compose{
      display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;
      padding:0;border:0;background:transparent;
    }
    .shop-seller-msg-compose-bar{
      display:flex;align-items:center;gap:4px;min-width:0;min-height:44px;
      padding:4px 10px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;
    }
    .shop-seller-msg-compose-attach,.shop-seller-msg-compose-tools button{
      width:34px;height:34px;border:0;border-radius:8px;background:transparent;color:#64748b;
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;
    }
    .shop-seller-msg-compose-attach:hover,.shop-seller-msg-compose-tools button:hover{background:#e2e8f0;color:#0f172a;}
    .shop-seller-msg-compose textarea{
      flex:1 1 auto;min-height:34px;max-height:100px;resize:none;border:0;background:transparent;
      padding:7px 4px;font-size:13.5px;line-height:1.4;color:#0f172a;outline:none;box-shadow:none;
    }
    .shop-seller-msg-compose-tools{display:flex;gap:0;flex:0 0 auto;}
    .shop-seller-msg-compose #shopSellerMsgSend{
      height:44px;padding:0 18px;border:0;border-radius:10px;background:#2563eb;color:#fff;
      font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;
    }
    .shop-seller-msg-compose #shopSellerMsgSend:hover{background:#1d4ed8;}
    .shop-seller-msg-compose #shopSellerMsgSend:disabled{opacity:.55;cursor:not-allowed;}
    .shop-seller-msg-empty-card{
      flex:1 1 auto;min-height:0;border:1px solid #e5e7eb;border-radius:12px;background:#fff;
      display:flex;align-items:center;justify-content:center;text-align:center;
      padding:40px 24px;color:#64748b;font-size:13.5px;line-height:1.5;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
    }
    .shop-seller-msg-empty{
      flex:1 1 auto;display:flex;align-items:center;justify-content:center;text-align:center;
      padding:40px 24px;color:#64748b;font-size:13.5px;line-height:1.5;
    }
    .shop-seller-msg-empty .btn,.shop-seller-msg-empty-card .btn{margin-top:12px;}
    /* Dark auto / Progress color / Appearance palette — Chat with sellers */
    html.dark-auto #seller-messages,
    html[data-msb-appearance] #seller-messages,
    html.msb-palette-active #seller-messages{
      --sm-bg:var(--msb-palette-bg,#171d24);
      --sm-raised:var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733));
      --sm-text:var(--msb-palette-text,#e2e8f0);
      --sm-muted:var(--msb-palette-text-muted,#94a3b8);
      --sm-border:var(--msb-palette-border,rgba(148,163,184,.28));
      --sm-input:var(--msb-palette-input-bg,var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733)));
      --sm-accent:var(--msb-palette-action,#2563eb);
      --sm-accent-soft:var(--msb-palette-action-soft,rgba(37,99,235,.18));
      --sm-btn:var(--msb-palette-btn-bg,var(--msb-palette-action,#2563eb));
      --sm-btn-text:var(--msb-palette-btn-text,#fff);
    }
    html.dark-auto .shop-seller-msg-intro .shop-customer-name,
    html[data-msb-appearance] .shop-seller-msg-intro .shop-customer-name,
    html.msb-palette-active .shop-seller-msg-intro .shop-customer-name{color:var(--sm-text,var(--msb-palette-text,#e2e8f0))!important;}
    html.dark-auto .shop-seller-msg-intro .shop-customer-kicker,
    html.dark-auto .shop-seller-msg-intro .shop-customer-sub,
    html[data-msb-appearance] .shop-seller-msg-intro .shop-customer-kicker,
    html[data-msb-appearance] .shop-seller-msg-intro .shop-customer-sub,
    html.msb-palette-active .shop-seller-msg-intro .shop-customer-kicker,
    html.msb-palette-active .shop-seller-msg-intro .shop-customer-sub{color:var(--sm-muted,var(--msb-palette-text-muted,#94a3b8))!important;}
    html.dark-auto .shop-seller-msg-rail,
    html.dark-auto .shop-seller-msg-chat,
    html.dark-auto .shop-seller-msg-toolbar,
    html.dark-auto .shop-seller-msg-list,
    html.dark-auto .shop-seller-msg-head,
    html.dark-auto .shop-seller-msg-thread,
    html.dark-auto .shop-seller-msg-compose-wrap,
    html.dark-auto .shop-seller-msg-empty-card,
    html[data-msb-appearance] .shop-seller-msg-rail,
    html[data-msb-appearance] .shop-seller-msg-chat,
    html[data-msb-appearance] .shop-seller-msg-toolbar,
    html[data-msb-appearance] .shop-seller-msg-list,
    html[data-msb-appearance] .shop-seller-msg-head,
    html[data-msb-appearance] .shop-seller-msg-thread,
    html[data-msb-appearance] .shop-seller-msg-compose-wrap,
    html[data-msb-appearance] .shop-seller-msg-empty-card,
    html.msb-palette-active .shop-seller-msg-rail,
    html.msb-palette-active .shop-seller-msg-chat,
    html.msb-palette-active .shop-seller-msg-toolbar,
    html.msb-palette-active .shop-seller-msg-list,
    html.msb-palette-active .shop-seller-msg-head,
    html.msb-palette-active .shop-seller-msg-thread,
    html.msb-palette-active .shop-seller-msg-compose-wrap,
    html.msb-palette-active .shop-seller-msg-empty-card{
      background:var(--sm-bg,var(--msb-palette-bg,#171d24))!important;
      border-color:var(--sm-border,var(--msb-palette-border,rgba(148,163,184,.28)))!important;
      color:var(--sm-text,var(--msb-palette-text,#e2e8f0))!important;
    }
    html.dark-auto .shop-seller-msg-item-row,
    html[data-msb-appearance] .shop-seller-msg-item-row,
    html.msb-palette-active .shop-seller-msg-item-row{border-bottom:0!important;background:transparent!important;}
    html.dark-auto .shop-seller-msg-item-row:hover,
    html[data-msb-appearance] .shop-seller-msg-item-row:hover,
    html.msb-palette-active .shop-seller-msg-item-row:hover{background:var(--sm-raised,#1e2733)!important;}
    html.dark-auto .shop-seller-msg-item-row.is-active,
    html[data-msb-appearance] .shop-seller-msg-item-row.is-active,
    html.msb-palette-active .shop-seller-msg-item-row.is-active{background:var(--sm-raised,#1e2733)!important;}
    html.dark-auto .shop-seller-msg-item-row.is-active .shop-seller-msg-item,
    html[data-msb-appearance] .shop-seller-msg-item-row.is-active .shop-seller-msg-item,
    html.msb-palette-active .shop-seller-msg-item-row.is-active .shop-seller-msg-item{box-shadow:none!important;}
    html.dark-auto .shop-seller-msg-item-top strong,
    html.dark-auto .shop-seller-msg-head-name,
    html.dark-auto .shop-seller-msg-product strong,
    html.dark-auto .shop-seller-msg-product-price,
    html[data-msb-appearance] .shop-seller-msg-item-top strong,
    html[data-msb-appearance] .shop-seller-msg-head-name,
    html[data-msb-appearance] .shop-seller-msg-product strong,
    html[data-msb-appearance] .shop-seller-msg-product-price,
    html.msb-palette-active .shop-seller-msg-item-top strong,
    html.msb-palette-active .shop-seller-msg-head-name,
    html.msb-palette-active .shop-seller-msg-product strong,
    html.msb-palette-active .shop-seller-msg-product-price{color:var(--sm-text,#e2e8f0)!important;}
    html.dark-auto .shop-seller-msg-product-id,
    html[data-msb-appearance] .shop-seller-msg-product-id,
    html.msb-palette-active .shop-seller-msg-product-id{color:var(--sm-muted,#94a3b8)!important;}
    html.dark-auto .shop-seller-msg-item-preview,
    html.dark-auto .shop-seller-msg-item-time,
    html.dark-auto .shop-seller-msg-empty,
    html.dark-auto .shop-seller-msg-empty-card,
    html.dark-auto .shop-seller-msg-meta,
    html[data-msb-appearance] .shop-seller-msg-item-preview,
    html[data-msb-appearance] .shop-seller-msg-item-time,
    html[data-msb-appearance] .shop-seller-msg-empty,
    html[data-msb-appearance] .shop-seller-msg-empty-card,
    html[data-msb-appearance] .shop-seller-msg-meta,
    html.msb-palette-active .shop-seller-msg-item-preview,
    html.msb-palette-active .shop-seller-msg-item-time,
    html.msb-palette-active .shop-seller-msg-empty,
    html.msb-palette-active .shop-seller-msg-empty-card,
    html.msb-palette-active .shop-seller-msg-meta{color:var(--sm-muted,#94a3b8)!important;}
    html.dark-auto .shop-seller-msg-verified,
    html[data-msb-appearance] .shop-seller-msg-verified,
    html.msb-palette-active .shop-seller-msg-verified{color:var(--sm-accent,var(--msb-palette-action,#2563eb))!important;}
    html.dark-auto .shop-seller-msg-search input,
    html.dark-auto .shop-seller-msg-filter,
    html.dark-auto .shop-seller-msg-compose-bar,
    html.dark-auto .shop-seller-msg-head-info,
    html[data-msb-appearance] .shop-seller-msg-head-info,
    html.msb-palette-active .shop-seller-msg-head-info{color:var(--sm-muted,#94a3b8)!important;}
    html.dark-auto .shop-seller-msg-head-info:hover,
    html[data-msb-appearance] .shop-seller-msg-head-info:hover,
    html.msb-palette-active .shop-seller-msg-head-info:hover{
      color:var(--msb-palette-action,#60a5fa)!important;
      background:rgba(37,99,235,.14)!important;
    }
    html.dark-auto .shop-seller-msg-more-menu,
    html.dark-auto .shop-seller-msg-product,
    html[data-msb-appearance] .shop-seller-msg-search input,
    html[data-msb-appearance] .shop-seller-msg-filter,
    html[data-msb-appearance] .shop-seller-msg-compose-bar,
    html[data-msb-appearance] .shop-seller-msg-more-menu,
    html[data-msb-appearance] .shop-seller-msg-product,
    html.msb-palette-active .shop-seller-msg-search input,
    html.msb-palette-active .shop-seller-msg-filter,
    html.msb-palette-active .shop-seller-msg-compose-bar,
    html.msb-palette-active .shop-seller-msg-more-menu,
    html.msb-palette-active .shop-seller-msg-product{
      background:var(--sm-input,#1e2733)!important;border-color:var(--sm-border,rgba(148,163,184,.28))!important;color:var(--sm-text,#e2e8f0)!important;
    }
    html.dark-auto .shop-seller-msg-compose textarea,
    html[data-msb-appearance] .shop-seller-msg-compose textarea,
    html.msb-palette-active .shop-seller-msg-compose textarea{color:var(--sm-text,#e2e8f0)!important;}
    html.dark-auto .shop-seller-msg-more-btn:hover,
    html.dark-auto .shop-seller-msg-more.is-open .shop-seller-msg-more-btn,
    html.dark-auto .shop-seller-msg-more-menu a:hover,
    html.dark-auto .shop-seller-msg-more-menu button:hover,
    html.dark-auto .shop-seller-msg-product a:hover,
    html[data-msb-appearance] .shop-seller-msg-more-btn:hover,
    html[data-msb-appearance] .shop-seller-msg-more.is-open .shop-seller-msg-more-btn,
    html[data-msb-appearance] .shop-seller-msg-more-menu a:hover,
    html[data-msb-appearance] .shop-seller-msg-more-menu button:hover,
    html[data-msb-appearance] .shop-seller-msg-product a:hover,
    html.msb-palette-active .shop-seller-msg-more-btn:hover,
    html.msb-palette-active .shop-seller-msg-more.is-open .shop-seller-msg-more-btn,
    html.msb-palette-active .shop-seller-msg-more-menu a:hover,
    html.msb-palette-active .shop-seller-msg-more-menu button:hover,
    html.msb-palette-active .shop-seller-msg-product a:hover{background:var(--sm-raised,#1e2733)!important;color:var(--sm-text,#e2e8f0)!important;}
    html.dark-auto .shop-seller-msg-bubble.me,
    html[data-msb-appearance] .shop-seller-msg-bubble.me,
    html.msb-palette-active .shop-seller-msg-bubble.me,
    html.dark-auto .shop-admin-support-thread .shop-seller-msg-bubble.me,
    html[data-msb-appearance] .shop-admin-support-thread .shop-seller-msg-bubble.me,
    html.msb-palette-active .shop-admin-support-thread .shop-seller-msg-bubble.me,
    html.dark-auto .shop-seller-msg-compose #shopSellerMsgSend,
    html[data-msb-appearance] .shop-seller-msg-compose #shopSellerMsgSend,
    html.msb-palette-active .shop-seller-msg-compose #shopSellerMsgSend{
      background:#2563eb!important;
      border-color:#2563eb!important;
      color:#fff!important;
      -webkit-text-fill-color:#fff!important;
    }
    html.dark-auto .shop-seller-msg-bubble.them,
    html[data-msb-appearance] .shop-seller-msg-bubble.them,
    html.msb-palette-active .shop-seller-msg-bubble.them,
    html.dark-auto .shop-admin-support-thread .shop-seller-msg-bubble.them,
    html[data-msb-appearance] .shop-admin-support-thread .shop-seller-msg-bubble.them,
    html.msb-palette-active .shop-admin-support-thread .shop-seller-msg-bubble.them{
      background:var(--sm-raised,#1e2733)!important;color:var(--sm-text,#e2e8f0)!important;-webkit-text-fill-color:var(--sm-text,#e2e8f0)!important;border-color:var(--sm-raised,#1e2733)!important;
    }
    html.dark-auto .shop-seller-msg-meta,
    html[data-msb-appearance] .shop-seller-msg-meta,
    html.msb-palette-active .shop-seller-msg-meta,
    html.dark-auto .shop-admin-support-thread .shop-seller-msg-meta,
    html[data-msb-appearance] .shop-admin-support-thread .shop-seller-msg-meta,
    html.msb-palette-active .shop-admin-support-thread .shop-seller-msg-meta{
      color:#94a3b8!important;-webkit-text-fill-color:#94a3b8!important;
    }
    .shop-pref-nav-link.is-active[href="#seller-messages"],
    .shop-pref-nav-link.is-active[data-shop-pref-target="seller-messages"]{
      background:transparent;color:var(--shop-link,var(--msb-palette-action,#2563eb));box-shadow:none;
    }
    .shop-pref-nav-link.is-active[href="#seller-messages"] i,
    .shop-pref-nav-link.is-active[data-shop-pref-target="seller-messages"] i{color:var(--shop-link,var(--msb-palette-action,#2563eb));}
    .shop-pref-nav-link.is-active[href="#seller-messages"] .shop-pref-nav-sub,
    .shop-pref-nav-link.is-active[data-shop-pref-target="seller-messages"] .shop-pref-nav-sub{color:var(--shop-link,var(--msb-palette-action,#2563eb));}
    @media (max-width:980px){
      #seller-messages .shop-seller-msg-shell{grid-template-columns:1fr;}
      .shop-seller-msg-rail{max-height:300px;}
      .shop-seller-msg-chat{min-height:420px;}
      body.shopping-preferences-page.msgs-chat-active .shop-customer-card,
      body.shopping-preferences-page.msgs-seller-active #seller-messages.shop-pref-panel.is-active{height:auto;min-height:0;}
    }

    /* Support Center — same chat shell language as seller Messages */
    body.shopping-preferences-page.msgs-support-active #support-center.shop-pref-panel.is-active{
      height:100%;min-height:0;min-width:0;max-width:100%;width:100%;
      display:flex;flex-direction:column;padding:0;margin:0;gap:12px;
      box-sizing:border-box;overflow:hidden;
    }
    #support-center.shop-pref-panel.is-active{padding:0;margin:0;gap:12px;min-width:0;max-width:100%;}
    .shop-support-msg-intro{
      flex:0 0 auto;padding:0 2px 2px;border:0;background:transparent;
    }
    .shop-support-msg-intro .shop-customer-kicker{
      margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;
    }
    .shop-support-msg-intro .shop-customer-name{
      margin:0 0 6px;font-size:24px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.15;
    }
    .shop-support-msg-intro .shop-customer-sub{
      margin:0;font-size:13px;line-height:1.45;color:#64748b;max-width:62ch;
    }
    .shop-admin-support{
      flex:1 1 auto;min-height:0;min-width:0;width:100%;max-width:100%;
      display:grid;grid-template-columns:minmax(0,220px) minmax(0,1fr);
      gap:12px;margin:0;border:0;background:transparent;overflow:hidden;
    }
    .shop-admin-support-contacts{
      display:flex;flex-direction:column;min-width:0;min-height:0;width:100%;max-width:100%;
      border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden;
      box-shadow:0 1px 2px rgba(15,23,42,.04);max-height:none;height:100%;
    }
    .shop-admin-support-toolbar{
      display:flex;gap:8px;align-items:center;padding:12px 12px 10px;flex:0 0 auto;background:#fff;
      border-bottom:1px solid #f1f5f9;
    }
    .shop-admin-support-search{position:relative;flex:1 1 auto;min-width:0;}
    .shop-admin-support-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;pointer-events:none;}
    .shop-admin-support-search input{
      width:100%;height:40px;border:1px solid #e2e8f0;border-radius:10px;padding:0 12px 0 34px;
      font-size:13px;background:#f8fafc;color:#0f172a;outline:none;
    }
    .shop-admin-support-search input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
    .shop-admin-support-filter{
      flex:0 0 auto;height:40px;padding:0 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;
      font-size:13px;font-weight:700;color:#334155;cursor:pointer;display:inline-flex;align-items:center;gap:6px;
    }
    .shop-admin-support-contacts-head{display:none;}
    .shop-admin-support-contacts-list{flex:1 1 auto;min-height:0;overflow:auto;background:#fff;-webkit-overflow-scrolling:touch;}
    .shop-admin-support-contacts-empty{
      padding:40px 24px;font-size:13.5px;line-height:1.5;color:#64748b;text-align:center;
    }
    .shop-admin-support-contacts-empty .btn{margin-top:12px;}
    .shop-admin-support-help-item{
      display:block;width:100%;text-align:left;border:0;border-bottom:0;
      background:#fff;padding:14px 14px 12px;cursor:pointer;color:#0f172a;
    }
    .shop-admin-support-help-item:hover{background:#f8fafc;}
    .shop-admin-support-help-item.is-active{background:#eff6ff;box-shadow:none;}
    .shop-admin-support-help-item strong{display:block;font-size:13.5px;font-weight:800;color:#0f172a;}
    .shop-admin-support-help-item span{display:block;margin-top:4px;font-size:12px;line-height:1.4;color:#64748b;}
    .shop-admin-support-help-note{
      margin:0;padding:14px;font-size:12px;line-height:1.45;color:#64748b;
      border-top:1px solid #f1f5f9;background:#f8fafc;
    }
    html.dark-auto .shop-admin-support-help-item,
    html[data-msb-appearance] .shop-admin-support-help-item,
    html.msb-palette-active .shop-admin-support-help-item{
      background:var(--sc-bg,var(--msb-palette-bg,#171d24))!important;color:var(--sc-text,#e2e8f0)!important;
      border-bottom:0!important;
    }
    html.dark-auto .shop-admin-support-help-item:hover,
    html[data-msb-appearance] .shop-admin-support-help-item:hover,
    html.msb-palette-active .shop-admin-support-help-item:hover{background:var(--sc-raised,#1e2733)!important;}
    html.dark-auto .shop-admin-support-help-item.is-active,
    html[data-msb-appearance] .shop-admin-support-help-item.is-active,
    html.msb-palette-active .shop-admin-support-help-item.is-active{
      background:var(--sc-raised,#1e2733)!important;box-shadow:none!important;
    }
    html.dark-auto .shop-admin-support-help-item strong,
    html[data-msb-appearance] .shop-admin-support-help-item strong,
    html.msb-palette-active .shop-admin-support-help-item strong{color:var(--sc-text,#e2e8f0)!important;}
    html.dark-auto .shop-admin-support-help-item span,
    html.dark-auto .shop-admin-support-help-note,
    html[data-msb-appearance] .shop-admin-support-help-item span,
    html[data-msb-appearance] .shop-admin-support-help-note,
    html.msb-palette-active .shop-admin-support-help-item span,
    html.msb-palette-active .shop-admin-support-help-note{
      color:var(--sc-muted,#94a3b8)!important;
    }
    html.dark-auto .shop-admin-support-help-note,
    html[data-msb-appearance] .shop-admin-support-help-note,
    html.msb-palette-active .shop-admin-support-help-note{
      background:var(--sc-raised,#1e2733)!important;border-top-color:var(--sc-border,rgba(148,163,184,.18))!important;
    }
    .shop-admin-support-contact{
      display:grid;grid-template-columns:minmax(0,1fr) auto;gap:0;align-items:stretch;
      padding:0;border-bottom:0;position:relative;
    }
    .shop-admin-support-contact:hover{background:#f8fafc;}
    .shop-admin-support-contact.is-active{background:#eff6ff;}
    .shop-admin-support-contact.is-active .shop-admin-support-contact-select{box-shadow:none;}
    .shop-admin-support-contact-select{
      display:grid;grid-template-columns:42px minmax(0,1fr) auto;gap:10px;align-items:start;
      width:100%;text-align:left;border:0;background:transparent;padding:12px 14px;cursor:pointer;color:inherit;min-width:0;
    }
    .shop-admin-support-contact-ava{
      width:42px;height:42px;border-radius:999px;object-fit:cover;background:#e2e8f0;display:block;
    }
    .shop-admin-support-contact-main{min-width:0;}
    .shop-admin-support-contact-top{display:flex;align-items:center;gap:6px;min-width:0;}
    .shop-admin-support-contact-select strong{
      display:block;font-size:13.5px;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0;
    }
    .shop-admin-support-contact-select .shop-seller-msg-verified{color:#2563eb;font-size:12px;flex:0 0 auto;line-height:1;}
    .shop-admin-support-contact-select span.shop-admin-support-contact-preview{
      display:block;margin-top:3px;font-size:12px;line-height:1.35;color:#64748b;opacity:1;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .shop-admin-support-contact-time{font-size:11px;font-weight:600;color:#94a3b8;padding-top:2px;white-space:nowrap;}
    .shop-admin-support-contact-actions{
      display:flex;flex-direction:column;justify-content:center;gap:4px;padding:8px 10px 8px 0;flex:0 0 auto;
    }
    .shop-admin-support-contact-msg{
      flex:0 0 auto;font-size:11px;font-weight:800;color:#2563eb;text-decoration:none;padding:4px 8px;
      border-radius:6px;border:0;background:transparent;text-align:right;white-space:nowrap;
    }
    .shop-admin-support-contact-msg:hover{text-decoration:underline;background:transparent;}
    .shop-admin-support-contact-remove{
      flex:0 0 auto;font-size:11px;font-weight:700;color:#94a3b8;border:0;background:transparent;
      padding:4px 8px;border-radius:6px;cursor:pointer;text-align:right;white-space:nowrap;
    }
    .shop-admin-support-contact-remove:hover{color:#dc2626;background:#fef2f2;}
    .shop-admin-support-chat{
      display:flex;flex-direction:column;min-width:0;min-height:0;height:100%;width:100%;max-width:100%;
      border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden;
      box-shadow:0 1px 2px rgba(15,23,42,.04);max-height:none;
    }
    .shop-admin-support-head{
      display:flex !important;
      flex-direction:row !important;
      flex-wrap:nowrap !important;
      align-items:center !important;
      gap:12px;
      padding:12px 16px;
      border-bottom:1px solid #eef2f7;
      background:#fff;
      flex:0 0 auto;
      font-weight:inherit;
      font-size:inherit;
      min-width:0;
      width:100%;
      box-sizing:border-box;
    }
    .shop-admin-support-head-ava{
      width:42px;height:42px;border-radius:999px;object-fit:cover;background:#dbeafe;
      display:inline-flex;align-items:center;justify-content:center;color:#2563eb;font-size:18px;flex:0 0 auto;
    }
    .shop-admin-support-head-ava img{width:100%;height:100%;border-radius:999px;object-fit:cover;display:block;}
    .shop-admin-support-head-meta{flex:0 1 auto;min-width:0;max-width:28%;}
    .shop-admin-support-head-name{
      display:flex;align-items:center;gap:6px;font-size:15px;font-weight:800;color:#0f172a;margin:0;line-height:1.25;
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;
    }
    .shop-admin-support-head-status{
      display:inline-flex;align-items:center;gap:6px;width:auto;max-width:100%;margin:1px 0 0;
      font-size:12px;font-weight:600;line-height:1.2;color:#16a34a;
      background:transparent!important;border:0!important;box-shadow:none!important;filter:none!important;
    }
    .shop-admin-support-head-status i{font-size:8px;line-height:1;flex:0 0 auto;background:transparent!important;}
    .shop-admin-support-head-more{
      width:36px;height:36px;border:0;border-radius:999px;background:transparent;color:#64748b;
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;margin-left:0;
    }
    .shop-admin-support-head-more:hover{background:#f1f5f9;color:#0f172a;}
    .shop-admin-support-more{position:relative;flex:0 0 auto;}
    .shop-admin-support-more.is-open .shop-admin-support-head-more{background:#f1f5f9;color:#0f172a;}
    .shop-admin-support-more-menu{
      display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:50;min-width:260px;max-width:min(360px,90vw);
      padding:6px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;
      box-shadow:0 10px 30px rgba(15,23,42,.12);
    }
    .shop-admin-support-more.is-open .shop-admin-support-more-menu{display:block;}
    .shop-admin-support-history-head{
      padding:8px 12px 6px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;
      color:#64748b;
    }
    .shop-admin-support-history-list{max-height:280px;overflow:auto;padding:0 0 4px;}
    .shop-admin-support-history-list[hidden]{display:none!important;}
    .shop-admin-support-history-empty{padding:10px 12px;font-size:12px;color:#64748b;}
    .shop-admin-support-history-item{
      display:flex;align-items:center;gap:10px;
      width:100%;padding:10px 12px;border:0;border-radius:8px;background:transparent;text-align:left;cursor:pointer;
    }
    .shop-admin-support-history-item:hover{background:#f1f5f9;}
    .shop-admin-support-history-item img{width:32px;height:32px;border-radius:6px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
    .shop-admin-support-history-item-text{display:block;min-width:0;flex:1 1 auto;}
    .shop-admin-support-history-item-label{display:block;font-size:13px;font-weight:800;color:#0f172a;line-height:1.25;}
    .shop-admin-support-history-item-meta{display:block;margin-top:2px;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .shop-admin-support-history-panel{display:none;flex-direction:column;flex:1 1 auto;min-height:0;background:#fff;}
    .shop-admin-support-history-panel.is-open{display:flex;}
    .shop-admin-support-history-bar{
      display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #eef2f7;flex:0 0 auto;
    }
    .shop-admin-support-history-back{
      border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#334155;
      font-size:12px;font-weight:800;padding:6px 10px;cursor:pointer;flex:0 0 auto;
    }
    .shop-admin-support-history-back:hover{background:#f8fafc;}
    .shop-admin-support-history-title{font-size:13px;font-weight:800;color:#0f172a;margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .shop-admin-support-history-thread{
      flex:1 1 auto;overflow:auto;padding:18px 16px;display:flex;flex-direction:column;gap:10px;min-height:0;
    }
    .shop-admin-support-chat.is-history-mode .shop-admin-support-topics,
    .shop-admin-support-chat.is-history-mode .shop-admin-support-locked,
    .shop-admin-support-chat.is-history-mode .shop-admin-support-thread,
    .shop-admin-support-chat.is-history-mode .shop-admin-support-compose{display:none!important;}
    .shop-admin-support-head:has(.shop-admin-support-product[hidden]) .shop-admin-support-more,
    .shop-admin-support-head:not(:has(.shop-admin-support-product:not([hidden]))) .shop-admin-support-more{margin-left:auto;}
    /* Inline product card — same row as Admin support / Active now (matches seller Messages) */
    .shop-admin-support-head > .shop-admin-support-product,
    .shop-admin-support-product{
      display:grid !important;
      grid-template-columns:36px minmax(0,1fr) auto !important;
      gap:8px !important;
      align-items:center !important;
      flex:1 1 auto !important;
      min-width:0 !important;
      max-width:min(420px,58%) !important;
      width:auto !important;
      margin:0 0 0 auto !important;
      padding:5px 8px !important;
      border:1px solid #e2e8f0 !important;
      border-radius:10px !important;
      background:#f8fafc !important;
      float:none !important;
      clear:none !important;
      position:relative !important;
      box-sizing:border-box !important;
    }
    .shop-admin-support-head > .shop-admin-support-product[hidden],
    .shop-admin-support-product[hidden]{display:none!important;margin-left:0!important;}
    .shop-admin-support-product img{width:36px!important;height:36px!important;border-radius:6px;object-fit:cover;background:#e2e8f0;}
    .shop-admin-support-product > div{min-width:0;overflow:hidden;}
    .shop-admin-support-product strong{
      display:block;font-size:12px;font-weight:800;color:#0f172a;line-height:1.25;
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    }
    .shop-admin-support-product-id,.shop-admin-support-product-biz{
      display:block;margin:1px 0 0;font-size:10px;font-weight:700;letter-spacing:.02em;color:#64748b;
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    }
    .shop-admin-support-product a{
      flex:0 0 auto;padding:5px 10px;border:1px solid #bfdbfe;border-radius:8px;background:#fff;
      font-size:11px;font-weight:800;color:#2563eb;text-decoration:none;white-space:nowrap;
    }
    .shop-admin-support-product a:hover{background:#eff6ff;text-decoration:none;}
    html.dark-auto .shop-admin-support-product,
    html[data-msb-appearance] .shop-admin-support-product,
    html.msb-palette-active .shop-admin-support-product{
      background:var(--sc-raised,#1e2733)!important;border-color:var(--sc-border,rgba(148,163,184,.28))!important;
    }
    html.dark-auto .shop-admin-support-product strong,
    html[data-msb-appearance] .shop-admin-support-product strong,
    html.msb-palette-active .shop-admin-support-product strong{color:var(--sc-text,#e2e8f0)!important;}
    html.dark-auto .shop-admin-support-product-id,
    html.dark-auto .shop-admin-support-product-biz,
    html[data-msb-appearance] .shop-admin-support-product-id,
    html[data-msb-appearance] .shop-admin-support-product-biz,
    html.msb-palette-active .shop-admin-support-product-id,
    html.msb-palette-active .shop-admin-support-product-biz{color:var(--sc-muted,#94a3b8)!important;}
    html.dark-auto .shop-admin-support-product a,
    html[data-msb-appearance] .shop-admin-support-product a,
    html.msb-palette-active .shop-admin-support-product a{
      background:var(--sc-card,#0f172a)!important;border-color:var(--sc-border,rgba(148,163,184,.28))!important;
      color:var(--sc-accent,#60a5fa)!important;
    }
    html.dark-auto .shop-admin-support-product a:hover,
    html[data-msb-appearance] .shop-admin-support-product a:hover,
    html.msb-palette-active .shop-admin-support-product a:hover{
      background:var(--sc-raised,#1e2733)!important;
    }
    .shop-admin-support-topics{
      display:flex;flex-wrap:wrap;gap:8px;padding:10px 16px;border-bottom:1px solid #eef2f7;background:#fff;flex:0 0 auto;
    }
    .shop-admin-topic{
      border:1px solid #e2e8f0;border-radius:999px;background:#fff;color:#334155;
      font-size:12px;font-weight:800;padding:7px 12px;cursor:pointer;
    }
    .shop-admin-topic.is-active{
      border-color:#2563eb;color:#2563eb;background:#eff6ff;
    }
    .shop-admin-support-thread{
      flex:1 1 auto;overflow:auto;padding:18px 28px 18px 16px;display:flex;flex-direction:column;gap:10px;
      background:#fff;min-height:0;box-sizing:border-box;
    }
    .shop-admin-support-thread .shop-seller-msg-row{max-width:78%;}
    .shop-admin-support-thread .shop-seller-msg-empty{
      flex:1 1 auto;display:flex;align-items:center;justify-content:center;text-align:center;
      padding:40px 24px;color:#64748b;font-size:13.5px;line-height:1.5;
    }
    .shop-admin-support-compose{
      display:flex;flex-direction:column;gap:10px;padding:12px 14px 14px;border-top:1px solid #eef2f7;background:#fff;flex:0 0 auto;
    }
    .shop-admin-support-compose[hidden]{display:none!important;}
    .shop-admin-support-locked{
      margin:0 16px 12px;padding:12px 14px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb;
      color:#92400e;font-size:13px;line-height:1.45;font-weight:600;
    }
    .shop-admin-support-locked[hidden]{display:none!important;}
    .shop-admin-support-locked a{color:#b45309;font-weight:800;text-decoration:underline;}
    .shop-admin-support-compose .shop-admin-support-meta{
      display:grid;grid-template-columns:1fr 1fr;gap:8px;
    }
    .shop-admin-support-compose .shop-admin-support-meta input{
      height:36px;border:1px solid #e2e8f0;border-radius:10px;padding:0 12px;font-size:13px;
      background:#f8fafc;color:#0f172a;outline:none;width:100%;
    }
    .shop-admin-support-compose .shop-admin-support-meta input:focus{
      border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }
    .shop-admin-support-compose-row{
      display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;
    }
    .shop-admin-support-compose-bar{
      display:flex;align-items:center;gap:4px;min-width:0;min-height:44px;
      padding:4px 10px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;
    }
    .shop-admin-support-compose-attach,.shop-admin-support-compose-tools button{
      width:34px;height:34px;border:0;border-radius:8px;background:transparent;color:#64748b;
      display:inline-flex;align-items:center;justify-content:center;cursor:default;flex:0 0 auto;
    }
    .shop-admin-support-compose-bar textarea{
      flex:1 1 auto;min-height:34px;max-height:100px;resize:none;border:0;background:transparent;
      padding:7px 4px;font-size:13.5px;line-height:1.4;color:#0f172a;outline:none;box-shadow:none;width:100%;
    }
    .shop-admin-support-compose-tools{display:flex;gap:0;flex:0 0 auto;}
    .shop-admin-support-compose #shopAdminSupportSend{
      height:44px;padding:0 18px;border:0;border-radius:10px;background:#2563eb;color:#fff;
      font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;
    }
    .shop-admin-support-compose #shopAdminSupportSend:hover{background:#1d4ed8;}
    .shop-admin-support-compose #shopAdminSupportSend:disabled{opacity:.55;cursor:not-allowed;}
    .shop-pref-nav-link.is-active[href="#support-center"],
    .shop-pref-nav-link.is-active[data-shop-pref-target="support-center"],
    .shop-pref-pref-link.is-active[href="#support-center"],
    .shop-pref-pref-link.is-active[data-shop-pref-target="support-center"]{
      background:transparent;color:var(--shop-link,var(--msb-palette-action,#2563eb));box-shadow:none;
    }
    /* Dark auto / Progress color / Appearance palette */
    html.dark-auto #support-center,
    html[data-msb-appearance] #support-center,
    html.msb-palette-active #support-center{
      --sc-bg:var(--msb-palette-bg,#171d24);
      --sc-raised:var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733));
      --sc-text:var(--msb-palette-text,#e2e8f0);
      --sc-muted:var(--msb-palette-text-muted,#94a3b8);
      --sc-border:var(--msb-palette-border,rgba(148,163,184,.28));
      --sc-input:var(--msb-palette-input-bg,var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733)));
      --sc-accent:var(--msb-palette-action,#2563eb);
      --sc-accent-soft:var(--msb-palette-action-soft,rgba(37,99,235,.18));
      --sc-btn:var(--msb-palette-btn-bg,var(--msb-palette-action,#2563eb));
      --sc-btn-text:var(--msb-palette-btn-text,#fff);
    }
    html.dark-auto .shop-support-msg-intro .shop-customer-name,
    html[data-msb-appearance] .shop-support-msg-intro .shop-customer-name,
    html.msb-palette-active .shop-support-msg-intro .shop-customer-name{color:var(--sc-text,var(--msb-palette-text,#e2e8f0))!important;}
    html.dark-auto .shop-support-msg-intro .shop-customer-kicker,
    html.dark-auto .shop-support-msg-intro .shop-customer-sub,
    html[data-msb-appearance] .shop-support-msg-intro .shop-customer-kicker,
    html[data-msb-appearance] .shop-support-msg-intro .shop-customer-sub,
    html.msb-palette-active .shop-support-msg-intro .shop-customer-kicker,
    html.msb-palette-active .shop-support-msg-intro .shop-customer-sub{color:var(--sc-muted,var(--msb-palette-text-muted,#94a3b8))!important;}
    html.dark-auto .shop-admin-support-contacts,
    html.dark-auto .shop-admin-support-chat,
    html.dark-auto .shop-admin-support-toolbar,
    html.dark-auto .shop-admin-support-contacts-list,
    html.dark-auto .shop-admin-support-head,
    html.dark-auto .shop-admin-support-topics,
    html.dark-auto .shop-admin-support-thread,
    html.dark-auto .shop-admin-support-compose,
    html[data-msb-appearance] .shop-admin-support-contacts,
    html[data-msb-appearance] .shop-admin-support-chat,
    html[data-msb-appearance] .shop-admin-support-toolbar,
    html[data-msb-appearance] .shop-admin-support-contacts-list,
    html[data-msb-appearance] .shop-admin-support-head,
    html[data-msb-appearance] .shop-admin-support-topics,
    html[data-msb-appearance] .shop-admin-support-thread,
    html[data-msb-appearance] .shop-admin-support-compose,
    html.msb-palette-active .shop-admin-support-contacts,
    html.msb-palette-active .shop-admin-support-chat,
    html.msb-palette-active .shop-admin-support-toolbar,
    html.msb-palette-active .shop-admin-support-contacts-list,
    html.msb-palette-active .shop-admin-support-head,
    html.msb-palette-active .shop-admin-support-topics,
    html.msb-palette-active .shop-admin-support-thread,
    html.msb-palette-active .shop-admin-support-compose{
      background:var(--sc-bg,var(--msb-palette-bg,#171d24))!important;
      border-color:var(--sc-border,var(--msb-palette-border,rgba(148,163,184,.28)))!important;
      color:var(--sc-text,var(--msb-palette-text,#e2e8f0))!important;
    }
    html.dark-auto .shop-admin-support-contact,
    html[data-msb-appearance] .shop-admin-support-contact,
    html.msb-palette-active .shop-admin-support-contact{border-bottom:0!important;background:transparent!important;}
    html.dark-auto .shop-admin-support-contact:hover,
    html[data-msb-appearance] .shop-admin-support-contact:hover,
    html.msb-palette-active .shop-admin-support-contact:hover{background:var(--sc-raised,#1e2733)!important;}
    html.dark-auto .shop-admin-support-contact.is-active,
    html[data-msb-appearance] .shop-admin-support-contact.is-active,
    html.msb-palette-active .shop-admin-support-contact.is-active{background:var(--sc-raised,#1e2733)!important;}
    html.dark-auto .shop-admin-support-contact.is-active .shop-admin-support-contact-select,
    html[data-msb-appearance] .shop-admin-support-contact.is-active .shop-admin-support-contact-select,
    html.msb-palette-active .shop-admin-support-contact.is-active .shop-admin-support-contact-select{box-shadow:none!important;}
    html.dark-auto .shop-admin-support-contact-select strong,
    html.dark-auto .shop-admin-support-head-name,
    html[data-msb-appearance] .shop-admin-support-contact-select strong,
    html[data-msb-appearance] .shop-admin-support-head-name,
    html.msb-palette-active .shop-admin-support-contact-select strong,
    html.msb-palette-active .shop-admin-support-head-name{color:var(--sc-text,#e2e8f0)!important;}
    html.dark-auto .shop-admin-support-contact-preview,
    html.dark-auto .shop-admin-support-contact-time,
    html.dark-auto .shop-admin-support-contacts-empty,
    html.dark-auto .shop-admin-support-thread .shop-seller-msg-empty,
    html[data-msb-appearance] .shop-admin-support-contact-preview,
    html[data-msb-appearance] .shop-admin-support-contact-time,
    html[data-msb-appearance] .shop-admin-support-contacts-empty,
    html[data-msb-appearance] .shop-admin-support-thread .shop-seller-msg-empty,
    html.msb-palette-active .shop-admin-support-contact-preview,
    html.msb-palette-active .shop-admin-support-contact-time,
    html.msb-palette-active .shop-admin-support-contacts-empty,
    html.msb-palette-active .shop-admin-support-thread .shop-seller-msg-empty{color:var(--sc-muted,#94a3b8)!important;}
    html.dark-auto .shop-admin-support-head-ava,
    html[data-msb-appearance] .shop-admin-support-head-ava,
    html.msb-palette-active .shop-admin-support-head-ava{
      background:var(--sc-raised,#1e2733)!important;color:var(--sc-accent,var(--msb-palette-action,#2563eb))!important;
    }
    html.dark-auto .shop-admin-topic,
    html[data-msb-appearance] .shop-admin-topic,
    html.msb-palette-active .shop-admin-topic{
      background:var(--sc-input,#1e2733)!important;border-color:var(--sc-border,rgba(148,163,184,.28))!important;color:var(--sc-text,#e2e8f0)!important;
    }
    html.dark-auto .shop-admin-topic.is-active,
    html[data-msb-appearance] .shop-admin-topic.is-active,
    html.msb-palette-active .shop-admin-topic.is-active{
      background:var(--sc-raised,#1e2733)!important;border-color:var(--sc-accent,var(--msb-palette-action,#2563eb))!important;color:var(--sc-accent,var(--msb-palette-action,#2563eb))!important;
    }
    html.dark-auto .shop-admin-support-search input,
    html.dark-auto .shop-admin-support-filter,
    html.dark-auto .shop-admin-support-compose .shop-admin-support-meta input,
    html.dark-auto .shop-admin-support-compose-bar,
    html[data-msb-appearance] .shop-admin-support-search input,
    html[data-msb-appearance] .shop-admin-support-filter,
    html[data-msb-appearance] .shop-admin-support-compose .shop-admin-support-meta input,
    html[data-msb-appearance] .shop-admin-support-compose-bar,
    html.msb-palette-active .shop-admin-support-search input,
    html.msb-palette-active .shop-admin-support-filter,
    html.msb-palette-active .shop-admin-support-compose .shop-admin-support-meta input,
    html.msb-palette-active .shop-admin-support-compose-bar{
      background:var(--sc-input,#1e2733)!important;border-color:var(--sc-border,rgba(148,163,184,.28))!important;color:var(--sc-text,#e2e8f0)!important;
    }
    html.dark-auto .shop-admin-support-compose-bar textarea,
    html[data-msb-appearance] .shop-admin-support-compose-bar textarea,
    html.msb-palette-active .shop-admin-support-compose-bar textarea{color:var(--sc-text,#e2e8f0)!important;}
    html.dark-auto .shop-admin-support-compose #shopAdminSupportSend,
    html[data-msb-appearance] .shop-admin-support-compose #shopAdminSupportSend,
    html.msb-palette-active .shop-admin-support-compose #shopAdminSupportSend{
      background:var(--sc-btn,var(--msb-palette-btn-bg,var(--msb-palette-action,#2563eb)))!important;
      color:var(--sc-btn-text,#fff)!important;
    }
    html.dark-auto .shop-admin-support-contact-msg,
    html[data-msb-appearance] .shop-admin-support-contact-msg,
    html.msb-palette-active .shop-admin-support-contact-msg,
    html.dark-auto .shop-admin-support-contact-select .shop-seller-msg-verified,
    html[data-msb-appearance] .shop-admin-support-contact-select .shop-seller-msg-verified,
    html.msb-palette-active .shop-admin-support-contact-select .shop-seller-msg-verified{color:var(--sc-accent,var(--msb-palette-action,#2563eb))!important;}
    html.dark-auto .shop-pref-nav-link.is-active[href="#support-center"],
    html.dark-auto .shop-pref-nav-link.is-active[data-shop-pref-target="support-center"],
    html.dark-auto .shop-pref-pref-link.is-active[href="#support-center"],
    html.dark-auto .shop-pref-pref-link.is-active[data-shop-pref-target="support-center"],
    html[data-msb-appearance] .shop-pref-nav-link.is-active[href="#support-center"],
    html[data-msb-appearance] .shop-pref-nav-link.is-active[data-shop-pref-target="support-center"],
    html[data-msb-appearance] .shop-pref-pref-link.is-active[href="#support-center"],
    html[data-msb-appearance] .shop-pref-pref-link.is-active[data-shop-pref-target="support-center"],
    html.msb-palette-active .shop-pref-nav-link.is-active[href="#support-center"],
    html.msb-palette-active .shop-pref-nav-link.is-active[data-shop-pref-target="support-center"],
    html.msb-palette-active .shop-pref-pref-link.is-active[href="#support-center"],
    html.msb-palette-active .shop-pref-pref-link.is-active[data-shop-pref-target="support-center"]{
      background:transparent!important;
      color:var(--msb-palette-action,#60a5fa)!important;
      box-shadow:none!important;
    }
    @media (max-width:980px){
      .shop-admin-support{grid-template-columns:1fr;}
      .shop-admin-support-contacts{max-height:280px;}
      .shop-admin-support-chat{min-height:420px;}
      body.shopping-preferences-page.msgs-chat-active .shop-customer-card,
      body.shopping-preferences-page.msgs-support-active #support-center.shop-pref-panel.is-active{height:auto;min-height:0;}
    }
    @media (max-width:900px){
      .shop-admin-support-compose .shop-admin-support-meta{grid-template-columns:1fr;}
    }

    @media (max-width:1024px){body.shopping-preferences-page.shop-page.feed-insta-ui .shop-page-shell{padding-left:calc(var(--feedRailW, 84px) + 12px) !important;}}
    @media (max-width:640px){body.shopping-preferences-page.shop-page.feed-insta-ui .shop-page-shell{padding-left:12px !important;padding-right:12px !important;}.shop-pref-nav-list{grid-template-columns:1fr;}.shop-customer-stats,.shop-customer-stats-3,.shop-pref-panel-list{grid-template-columns:1fr;}}

    /* Contrast: texts/numbers readable on Dark auto / Progress / Appearance backgrounds */
    html.dark-auto body.shopping-preferences-page,
    html[data-msb-appearance] body.shopping-preferences-page,
    html.msb-palette-active body.shopping-preferences-page{
      --shop-text:var(--msb-palette-text,#f3f6fb);
      --shop-text-muted:var(--msb-palette-text-muted,#cbd5e1);
      --shop-text-soft:var(--msb-palette-text-muted,#cbd5e1);
      color:var(--shop-text)!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-customer-name,
    html.dark-auto body.shopping-preferences-page .shop-customer-stat strong,
    html.dark-auto body.shopping-preferences-page .shop-customer-action,
    html.dark-auto body.shopping-preferences-page .shop-pref-head h1,
    html.dark-auto body.shopping-preferences-page .shop-pref-pref-copy strong,
    html.dark-auto body.shopping-preferences-page .shop-pref-panel-item strong,
    html.dark-auto body.shopping-preferences-page .shop-pref-table,
    html.dark-auto body.shopping-preferences-page .shop-pref-table th,
    html.dark-auto body.shopping-preferences-page .shop-pref-table td,
    html.dark-auto body.shopping-preferences-page .shop-buyer-notif-alert-title,
    html.dark-auto body.shopping-preferences-page .shop-buyer-notif-feed-row strong,
    html.dark-auto body.shopping-preferences-page .shop-buyer-notif-row-top strong,
    html.dark-auto body.shopping-preferences-page .shop-guidance-item strong,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-name,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-stat strong,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-action,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-head h1,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-pref-copy strong,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-panel-item strong,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-table,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-table th,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-table td,
    html[data-msb-appearance] body.shopping-preferences-page .shop-buyer-notif-alert-title,
    html[data-msb-appearance] body.shopping-preferences-page .shop-buyer-notif-feed-row strong,
    html[data-msb-appearance] body.shopping-preferences-page .shop-buyer-notif-row-top strong,
    html[data-msb-appearance] body.shopping-preferences-page .shop-guidance-item strong,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-name,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-stat strong,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-action,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-head h1,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-pref-copy strong,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-panel-item strong,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-table,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-table th,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-table td,
    html.msb-palette-active body.shopping-preferences-page .shop-buyer-notif-alert-title,
    html.msb-palette-active body.shopping-preferences-page .shop-buyer-notif-feed-row strong,
    html.msb-palette-active body.shopping-preferences-page .shop-buyer-notif-row-top strong,
    html.msb-palette-active body.shopping-preferences-page .shop-guidance-item strong{
      color:var(--shop-text,var(--msb-palette-text,#f3f6fb))!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-customer-kicker,
    html.dark-auto body.shopping-preferences-page .shop-customer-sub,
    html.dark-auto body.shopping-preferences-page .shop-customer-stat span,
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-title,
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-sub,
    html.dark-auto body.shopping-preferences-page .shop-pref-pref-copy span,
    html.dark-auto body.shopping-preferences-page .shop-pref-panel-item span,
    html.dark-auto body.shopping-preferences-page .shop-pref-table-empty,
    html.dark-auto body.shopping-preferences-page .shop-pref-live-label,
    html.dark-auto body.shopping-preferences-page .shop-guidance-item p,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-kicker,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-sub,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-stat span,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-title,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-sub,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-pref-copy span,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-panel-item span,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-table-empty,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-live-label,
    html[data-msb-appearance] body.shopping-preferences-page .shop-guidance-item p,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-kicker,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-sub,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-stat span,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-title,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-sub,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-pref-copy span,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-panel-item span,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-table-empty,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-live-label,
    html.msb-palette-active body.shopping-preferences-page .shop-guidance-item p{
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#cbd5e1))!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-customer-stat,
    html.dark-auto body.shopping-preferences-page .shop-customer-action,
    html.dark-auto body.shopping-preferences-page .shop-pref-panel-item,
    html.dark-auto body.shopping-preferences-page .shop-pref-support-card,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-stat,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-action,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-panel-item,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-support-card,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-stat,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-action,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-panel-item,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-support-card{
      background:var(--shop-card-raised,var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733)))!important;
      border-color:var(--shop-border,var(--msb-palette-border,rgba(148,163,184,.28)))!important;
      color:var(--shop-text,var(--msb-palette-text,#f3f6fb))!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) i,
    html.dark-auto body.shopping-preferences-page .shop-pref-pref-icon,
    html.dark-auto body.shopping-preferences-page .shop-customer-action i,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) i,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-pref-icon,
    html[data-msb-appearance] body.shopping-preferences-page .shop-customer-action i,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) i,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-pref-icon,
    html.msb-palette-active body.shopping-preferences-page .shop-customer-action i{
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#cbd5e1))!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link:hover:not(.is-active),
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link:focus:not(.is-active),
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link:hover:not(.is-active),
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link:focus:not(.is-active),
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link:hover:not(.is-active),
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link:focus:not(.is-active){
      background:var(--msb-palette-action-soft,rgba(37,99,235,.14))!important;
      color:var(--shop-text,var(--msb-palette-text,#f3f6fb))!important;
      border:0!important;box-shadow:none!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link:hover:not(.is-active) i,
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link:focus:not(.is-active) i,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link:hover:not(.is-active) i,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link:focus:not(.is-active) i,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link:hover:not(.is-active) i,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link:focus:not(.is-active) i{
      color:var(--msb-palette-action,#60a5fa)!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link.is-active,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link.is-active,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link.is-active,
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link.is-active:hover,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link.is-active:hover,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link.is-active:hover{
      background:transparent!important;
      color:var(--msb-palette-action,#60a5fa)!important;
      border:0!important;box-shadow:none!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link.is-active i,
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link.is-active .shop-pref-nav-label,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link.is-active i,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link.is-active .shop-pref-nav-label,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link.is-active i,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link.is-active .shop-pref-nav-label{
      color:var(--msb-palette-action,#60a5fa)!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link.is-active .shop-pref-nav-sub,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link.is-active .shop-pref-nav-sub,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link.is-active .shop-pref-nav-sub{
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#cbd5e1))!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link:not(.is-active),
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) .shop-pref-nav-label,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link:not(.is-active),
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) .shop-pref-nav-label,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link:not(.is-active),
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) .shop-pref-nav-label{
      color:var(--shop-text,var(--msb-palette-text,#f3f6fb))!important;
      background:transparent!important;
    }
    html.dark-auto body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) i,
    html[data-msb-appearance] body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) i,
    html.msb-palette-active body.shopping-preferences-page .shop-pref-nav-link:not(.is-active) i{
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#cbd5e1))!important;
    }
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
          <div class="shop-pref-support-card" aria-label="Help">
            <p class="shop-pref-nav-title">Help</p>
            <ul class="shop-pref-nav-list shop-pref-help-list">
              <li>
                <a class="shop-pref-nav-link" href="#guidance-center" data-shop-pref-target="guidance-center">
                  <i class="icon ion-help-circled"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label">Guidance Center</span>
                    <span class="shop-pref-nav-sub">Find products, cart, checkout, track, returns.</span>
              </span>
            </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#support-center" data-shop-pref-target="support-center" id="shopPrefSupportNav">
                  <i class="icon ion-ios-telephone"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label-row">
                      <span class="shop-pref-nav-label">Support Center</span>
                      <?php if ($supportMsgUnread > 0): ?>
                        <span class="shop-pref-nav-badge" id="shopPrefSupportBadge"><?= $supportMsgUnread > 99 ? '99+' : (int)$supportMsgUnread ?></span>
                      <?php else: ?>
                        <span class="shop-pref-nav-badge" id="shopPrefSupportBadge" hidden>0</span>
                      <?php endif; ?>
                    </span>
                    <span class="shop-pref-nav-sub">Live help for orders, payments, or delivery.</span>
              </span>
            </a>
              </li>
            </ul>
          </div>

          <aside class="shop-pref-nav" aria-label="Your shopping">
            <p class="shop-pref-nav-title">Your shopping</p>
            <ul class="shop-pref-nav-list">
              <li>
                <a class="shop-pref-nav-link is-active" href="#customer-dashboard" data-shop-pref-target="customer-dashboard">
                  <i class="icon ion-speedometer"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label">Dashboard</span>
                    <span class="shop-pref-nav-sub">Overview of orders, spending, and cart.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#order-history" data-shop-pref-target="order-history">
                  <i class="icon ion-ios-list"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label-row">
                    <span class="shop-pref-nav-label">My orders</span>
                      <?php if ($buyerOpenOrders > 0): ?>
                        <span class="shop-pref-nav-badge" id="shopPrefOrdersBadge" title="Active orders"><?= $buyerOpenOrders > 99 ? '99+' : (int)$buyerOpenOrders ?></span>
                      <?php else: ?>
                        <span class="shop-pref-nav-badge" id="shopPrefOrdersBadge" hidden>0</span>
                      <?php endif; ?>
                    </span>
                    <span class="shop-pref-nav-sub">See purchases, status, and receipts.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#wishlist" data-shop-pref-target="wishlist">
                  <i class="icon ion-bookmark"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label">Wishlist</span>
                    <span class="shop-pref-nav-sub">Items you saved to buy later.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#returns-refunds" data-shop-pref-target="returns-refunds">
                  <i class="icon ion-reply"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label">Returns &amp; refunds</span>
                    <span class="shop-pref-nav-sub">Return an item or check a refund.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#seller-messages" data-shop-pref-target="seller-messages">
                  <i class="icon ion-chatboxes"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label-row">
                    <span class="shop-pref-nav-label">Messages</span>
                  <?php if ($sellerMsgUnread > 0): ?>
                    <span class="shop-pref-nav-badge"><?= $sellerMsgUnread > 99 ? '99+' : (int)$sellerMsgUnread ?></span>
                  <?php endif; ?>
                    </span>
                    <span class="shop-pref-nav-sub">Message sellers about products or orders.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#addresses" data-shop-pref-target="addresses">
                  <i class="icon ion-location"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label">Addresses</span>
                    <span class="shop-pref-nav-sub">Delivery addresses for checkout.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#notifications" data-shop-pref-target="notifications">
                  <i class="icon ion-android-notifications"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label-row">
                    <span class="shop-pref-nav-label">Notifications</span>
                  <?php if ($buyerNotifCount > 0): ?>
                    <span class="shop-pref-nav-badge"><?= $buyerNotifCount > 99 ? '99+' : (int)$buyerNotifCount ?></span>
                  <?php endif; ?>
                    </span>
                    <span class="shop-pref-nav-sub">Order alerts and shop updates.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#reviews-ratings" data-shop-pref-target="reviews-ratings">
                  <i class="icon ion-star"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label">Reviews &amp; ratings</span>
                    <span class="shop-pref-nav-sub">Reviews you left on products.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#membership" data-shop-pref-target="membership">
                  <i class="icon ion-ribbon-a"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label">Membership<?= $membershipActive ? ' · Active' : '' ?></span>
                    <span class="shop-pref-nav-sub">Your plan and service-fee benefits.</span>
                  </span>
                </a>
              </li>
              <li>
                <a class="shop-pref-nav-link" href="#loyalty-program" data-shop-pref-target="loyalty-program">
                  <i class="icon ion-ribbon-b"></i>
                  <span class="shop-pref-nav-copy">
                    <span class="shop-pref-nav-label">Loyalty program</span>
                    <span class="shop-pref-nav-sub">Reward points from shopping.</span>
                  </span>
                </a>
              </li>
            </ul>
          </aside>
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
                <a class="shop-customer-action" href="#support-center" data-shop-pref-target="support-center"><i class="icon ion-ios-telephone"></i> Support</a>
              </div>
            </div>
            <div class="shop-pref-panel" id="order-history" data-shop-pref-panel="order-history">
              <div><p class="shop-customer-kicker">My orders</p><h2 class="shop-customer-name"><?= (int)count($buyerOrderHistoryRows) ?> order<?= count($buyerOrderHistoryRows) === 1 ? '' : 's' ?></h2><p class="shop-customer-sub">Each row is one checkout: products you bought together share a row. Items is how many different products; Quantity # is total units. Select a row to see the list on the right.</p></div>
              <?php if ($orderFlashOk !== ''): ?><div class="alert alert-success"><?= h($orderFlashOk) ?></div><?php endif; ?>
              <?php if ($orderFlashErr !== ''): ?><div class="alert alert-danger"><?= h($orderFlashErr) ?></div><?php endif; ?>
              <div class="shop-invoice-layout">
                <div class="shop-table-stack">
                  <div class="shop-table-actions" aria-label="Order history actions">
                    <button type="button" class="shop-table-action" data-print-invoice aria-label="Print invoice"><i class="icon ion-printer"></i></button>
                    <a class="shop-table-action" href="#order-history" data-print-invoice aria-label="Download invoice"><i class="icon ion-android-download"></i></a>
                  </div>
                  <div class="shop-pref-table-wrap"><table class="shop-pref-table">
                  <thead>
                    <tr>
                      <th class="shop-col-center">Order #</th>
                      <th>Seller</th>
                      <th class="shop-col-center">Items</th>
                      <th class="shop-col-center">Quantity #</th>
                      <th>Status</th>
                      <th>Total</th>
                      <th>Date</th>
                      <th class="shop-order-cancel-col">Action</th>
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
                      class="<?= $index === $buyerOrderHistorySelectedIndex ? 'is-selected' : '' ?>"
                      data-invoice-order
                      data-invoice-code="<?= h((string)$orderRow['invoice_label']) ?>"
                      data-invoice-status="<?= h((string)$orderRow['status']) ?>"
                      data-invoice-status-label="<?= h((string)($orderRow['status_label'] ?? $orderRow['status'])) ?>"
                      data-invoice-date="<?= h((string)$orderRow['date']) ?>"
                      data-invoice-due="<?= h((string)$orderRow['due']) ?>"
                      data-invoice-seller="<?= h((string)$orderRow['company']) ?>"
                      data-invoice-total="<?= h((string)$orderRow['total']) ?>"
                      data-invoice-subtotal="<?= h((string)($orderRow['subtotal_label'] ?? '$0.00')) ?>"
                      data-invoice-discount="<?= h((string)($orderRow['discount_label'] ?? '$0.00')) ?>"
                      data-invoice-shipping="<?= h((string)($orderRow['shipping_label'] ?? 'Free')) ?>"
                      data-invoice-tax="<?= h((string)($orderRow['tax_label'] ?? '$0.00')) ?>"
                      data-invoice-service-fee="<?= h((string)($orderRow['service_fee_label'] ?? '$0.00')) ?>"
                      data-invoice-contact-email="<?= h((string)$orderRow['contact_email']) ?>"
                      data-invoice-contact-phone="<?= h((string)$orderRow['contact_phone']) ?>"
                      data-invoice-contact-address="<?= h((string)$orderRow['contact_address']) ?>"
                      data-invoice-products="<?= $productsJson ?>"
                      data-product-ids="<?= h(implode(',', array_map('intval', $orderRow['product_ids'] ?? []))) ?>"
                      data-ship-detail-id="<?= (int)($orderRow['ship_detail_id'] ?? $orderRow['order_id'] ?? 0) ?>"
                      data-ship-detail-code="<?= h((string)($orderRow['ship_detail_code'] ?? '')) ?>"
                    >
                      <td class="shop-col-center"><?= (int)$orderRow['order_num'] ?></td>
                      <td><?= h((string)$orderRow['company']) ?></td>
                      <td class="shop-col-center"><?= (int)$orderRow['product_num'] ?></td>
                      <td class="shop-col-center"><?= (int)$orderRow['quantity_num'] ?></td>
                      <td><?php
                        $rowStatusKey = strtolower(trim((string)($orderRow['status'] ?? '')));
                        $rowStatusLabel = (string)($orderRow['status_label'] ?? $orderRow['status']);
                        $rowDetailId = (int)($orderRow['ship_detail_id'] ?? $orderRow['order_id'] ?? 0);
                        $rowDetailCode = trim((string)($orderRow['ship_detail_code'] ?? ''));
                        if (in_array($rowStatusKey, ['shipped', 'delivered'], true) && $rowDetailId > 0):
                          $rowDetailHref = 'order_detail.php?order_id=' . $rowDetailId
                              . ($rowDetailCode !== '' ? ('&code=' . rawurlencode($rowDetailCode)) : '');
                      ?><a class="shop-order-status-link" href="<?= h($rowDetailHref) ?>" onclick="event.stopPropagation();"><?= h($rowStatusLabel) ?></a><?php
                        else:
                          echo h($rowStatusLabel);
                        endif;
                      ?></td>
                      <td><?= h((string)$orderRow['total']) ?></td>
                      <td><?= h((string)$orderRow['date']) ?></td>
                      <td>
                        <div class="shop-order-row-actions">
                        <?php if (!empty($orderRow['cancellable']) && $cancellableCsv !== ''): ?>
                          <button type="button" class="shop-order-cancel-btn js-order-history-cancel" data-order-ids="<?= h($cancellableCsv) ?>">Cancel order</button>
                        <?php else: ?>
                          <span class="shop-addr-muted">—</span>
                        <?php endif; ?>
                          <?php
                            $rowMoreOrderId = (int)($orderRow['ship_detail_id'] ?? $orderRow['order_id'] ?? 0);
                            $rowMoreProductId = (int)($orderRow['primary_product_id'] ?? 0);
                            $rowMorePublisherId = (int)($orderRow['publisher_user_id'] ?? 0);
                            $rowMoreProductHref = $rowMoreProductId > 0 ? ('product_detail.php?id=' . $rowMoreProductId) : '';
                            $rowAskHref = ($rowMorePublisherId > 0 && function_exists('commerce_message_seller_url'))
                                ? commerce_message_seller_url($rowMorePublisherId, $rowMoreProductId, trim((string)($orderRow['ship_detail_code'] ?? '')))
                                : 'Your_Shopping_preferences.php#seller-messages';
                            $rowViewProductLabel = trim((string)($orderRow['view_product_label'] ?? 'View product'));
                          ?>
                          <div class="shop-order-more">
                            <button type="button" class="shop-order-more-btn js-order-history-more" aria-label="More actions" aria-haspopup="menu" aria-expanded="false">
                              <i class="fa fa-ellipsis-v" aria-hidden="true"></i>
                            </button>
                            <div class="shop-order-more-menu" role="menu">
                              <?php if (!empty($orderRow['can_return']) && $rowMoreOrderId > 0): ?>
                                <button type="button" role="menuitem" class="js-order-history-return" data-order-id="<?= $rowMoreOrderId ?>" onclick="event.stopPropagation();">Request return</button>
                              <?php endif; ?>
                              <?php if ($rowMorePublisherId > 0): ?>
                                <a href="<?= h($rowAskHref) ?>" role="menuitem" onclick="event.stopPropagation();">Ask Product Question</a>
                              <?php endif; ?>
                              <?php if (!empty($orderRow['can_review']) && $rowMoreOrderId > 0): ?>
                                <button type="button" role="menuitem" class="js-order-history-review" data-order-id="<?= $rowMoreOrderId ?>" onclick="event.stopPropagation();">Leave review</button>
                              <?php endif; ?>
                              <?php if (!empty($orderRow['cancellable']) && $cancellableCsv !== ''): ?>
                                <button type="button" class="is-danger js-order-history-cancel" role="menuitem" data-order-ids="<?= h($cancellableCsv) ?>" onclick="event.stopPropagation();">Cancel</button>
                              <?php endif; ?>
                              <?php if ($rowMoreProductHref !== ''): ?>
                                <a href="<?= h($rowMoreProductHref) ?>" role="menuitem" onclick="event.stopPropagation();"><?= h($rowViewProductLabel) ?></a>
                                <a href="<?= h($rowMoreProductHref) ?>" role="menuitem" onclick="event.stopPropagation();">Buy again</a>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="8" class="shop-pref-table-empty">No orders yet.</td></tr>
                  <?php endif; ?>
                  </tbody>
                  </table></div>
                </div>
                <div class="shop-invoice-side">
                  <h2 class="shop-invoice-title">Order details</h2>
                  <aside class="shop-invoice-summary" aria-label="Order details">
                  <div class="shop-invoice-meta">
                    <div><div class="shop-invoice-number" data-invoice-field="code"><?= h($buyerOrderHistoryCode) ?></div><?php if ($buyerOrderHistoryShipHref !== ''): ?><a class="shop-invoice-status is-ship-link" data-invoice-field="status" href="<?= h($buyerOrderHistoryShipHref) ?>"><?= h($buyerOrderHistoryStatusLabel) ?></a><?php else: ?><span class="shop-invoice-status" data-invoice-field="status"><?= h($buyerOrderHistoryStatusLabel) ?></span><?php endif; ?></div>
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
                  <div class="shop-invoice-line"><span>Sub Total :</span><strong data-invoice-field="subtotal"><?= h($buyerInvoiceSubtotalLabel) ?></strong></div>
                  <div class="shop-invoice-line"><span>Discount :</span><strong data-invoice-field="discount"><?= h($buyerInvoiceDiscountLabel) ?></strong></div>
                  <div class="shop-invoice-line"><span>Shipping :</span><strong data-invoice-field="shipping"><?= h($buyerInvoiceShippingLabel) ?></strong></div>
                  <div class="shop-invoice-line"><span>Taxes :</span><strong data-invoice-field="tax"><?= h($buyerInvoiceTaxLabel) ?></strong></div>
                  <div class="shop-invoice-line"><span>Service fee :</span><strong data-invoice-field="service_fee"><?= h($buyerInvoiceServiceFeeLabel) ?></strong></div>
                  <div class="shop-invoice-line"><strong>Grand Total :</strong><strong data-invoice-field="grand"><?= h($buyerInvoiceGrandLabel) ?></strong></div>
                  <div class="shop-invoice-note">
                    <h3>Note</h3>
                    <p>Click an order in the table to review its products and quantities here.</p>
                  </div>
                  <a class="shop-invoice-download" href="#order-history" data-print-invoice>Download</a>
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

            <dialog class="shop-order-return-dialog" id="shopOrderReturnDialog" aria-labelledby="shopOrderReturnTitle">
              <button type="button" class="shop-order-return-close" data-close-order-return aria-label="Close">&times;</button>
              <div class="shop-order-return-icon" id="shopOrderReturnIcon" aria-hidden="true"><i class="fa fa-undo"></i></div>
              <h2 id="shopOrderReturnTitle">Request a return?</h2>
              <p id="shopOrderReturnBody">Tell us why you’re returning this item. The seller will review your request.</p>
              <div id="shopOrderReturnFormBlock">
                <textarea id="shopOrderReturnReason" class="shop-order-return-reason" rows="3" placeholder="Why are you returning this item?" aria-label="Return reason"></textarea>
                <div class="shop-order-return-actions">
                  <button type="button" class="shop-order-return-cancel" data-close-order-return>Cancel</button>
                  <button type="button" class="shop-order-return-confirm" id="shopOrderReturnConfirm">Request return</button>
                </div>
              </div>
              <div id="shopOrderReturnResultBlock" hidden>
                <div class="shop-order-return-actions">
                  <button type="button" class="shop-order-return-result-ok" id="shopOrderReturnResultOk" data-close-order-return>OK</button>
                </div>
              </div>
            </dialog>

            <dialog class="shop-order-return-dialog shop-order-review-dialog" id="shopOrderReviewDialog" aria-labelledby="shopOrderReviewTitle">
              <button type="button" class="shop-order-return-close" data-close-order-review aria-label="Close">&times;</button>
              <div class="shop-order-return-icon" id="shopOrderReviewIcon" aria-hidden="true"><i class="fa fa-star"></i></div>
              <h2 id="shopOrderReviewTitle">Leave a review?</h2>
              <p id="shopOrderReviewBody">Share your experience with this order. Your rating helps other buyers.</p>
              <div id="shopOrderReviewFormBlock">
                <div class="shop-order-review-stars" id="shopOrderReviewStars" role="radiogroup" aria-label="Rating">
                  <?php for ($star = 1; $star <= 5; $star++): ?>
                    <button type="button" class="shop-order-review-star<?= $star <= 5 ? ' is-on' : '' ?>" data-rating="<?= $star ?>" aria-label="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>" aria-checked="<?= $star === 5 ? 'true' : 'false' ?>" role="radio"><i class="fa fa-star" aria-hidden="true"></i></button>
                  <?php endfor; ?>
                </div>
                <textarea id="shopOrderReviewText" class="shop-order-return-reason" rows="3" placeholder="Write your review (optional)" aria-label="Review text"></textarea>
                <div class="shop-order-return-actions">
                  <button type="button" class="shop-order-return-cancel" data-close-order-review>Cancel</button>
                  <button type="button" class="shop-order-return-confirm" id="shopOrderReviewConfirm">Submit review</button>
                </div>
              </div>
              <div id="shopOrderReviewResultBlock" hidden>
                <div class="shop-order-return-actions">
                  <button type="button" class="shop-order-return-result-ok" id="shopOrderReviewResultOk" data-close-order-review>OK</button>
                </div>
              </div>
            </dialog>

            <dialog class="shop-order-return-dialog shop-order-cancel-dialog" id="shopOrderCancelDialog" aria-labelledby="shopOrderCancelTitle">
              <button type="button" class="shop-order-return-close" data-close-order-cancel aria-label="Close">&times;</button>
              <div class="shop-order-return-icon" id="shopOrderCancelIcon" aria-hidden="true"><i class="fa fa-ban"></i></div>
              <h2 id="shopOrderCancelTitle">Cancel this order?</h2>
              <p id="shopOrderCancelBody">It will be removed from your history and from the seller’s order list.</p>
              <div id="shopOrderCancelFormBlock">
                <textarea id="shopOrderCancelReason" class="shop-order-return-reason" rows="3" placeholder="Why are you cancelling? (optional)" aria-label="Cancel reason"></textarea>
                <div class="shop-order-return-actions">
                  <button type="button" class="shop-order-return-cancel" data-close-order-cancel>Keep order</button>
                  <button type="button" class="shop-order-return-confirm" id="shopOrderCancelConfirm">Cancel order</button>
                </div>
              </div>
              <div id="shopOrderCancelResultBlock" hidden>
                <div class="shop-order-return-actions">
                  <button type="button" class="shop-order-return-result-ok" id="shopOrderCancelResultOk" data-close-order-cancel>OK</button>
                </div>
              </div>
            </dialog>

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
              <div><p class="shop-customer-kicker">Wishlist</p><h2 class="shop-customer-name"><?= (int)$buyerWishlistCount ?> saved product<?= (int)$buyerWishlistCount === 1 ? '' : 's' ?></h2><p class="shop-customer-sub">Items you saved from product pages. Separate from your cart.</p></div>
              <div class="shop-pref-table-wrap"><table class="shop-pref-table"><thead><tr><th>Saved item</th><th>Seller</th><th>Price</th><th>Status</th><th></th></tr></thead><tbody>
                <?php if ($buyerWishlistItems): foreach ($buyerWishlistItems as $wItem):
                  $wPid = (int)($wItem['product_id'] ?? 0);
                  $wTitle = trim((string)($wItem['title'] ?? 'Product'));
                  $wSeller = trim((string)($wItem['seller_name'] ?? '')) ?: trim((string)($wItem['publisher_name'] ?? 'Seller'));
                  $wPrice = org_shop_format_price((int)($wItem['price_cents'] ?? 0), (string)($wItem['currency'] ?? 'USD'));
                  $wStock = $wItem['stock_qty'] ?? null;
                  $wOut = ($wStock !== null && $wStock !== '' && (int)$wStock <= 0) || strtolower((string)($wItem['product_status'] ?? '')) === 'sold_out';
                  $wUrl = 'product_detail.php?id=' . $wPid;
                ?>
                  <tr data-wishlist-row="<?= $wPid ?>">
                    <td><a href="<?= h($wUrl) ?>"><?= h($wTitle) ?></a></td>
                    <td><?= h($wSeller) ?></td>
                    <td><?= h($wPrice) ?></td>
                    <td><?= $wOut ? 'Out of stock' : 'Available' ?></td>
                    <td>
                      <?php if (!$wOut): ?>
                        <button type="button" class="btn btn-sm btn-primary js-wishlist-add-cart" data-product-id="<?= $wPid ?>">Add to cart</button>
                      <?php endif; ?>
                      <button type="button" class="btn btn-sm btn-outline-secondary js-wishlist-remove" data-product-id="<?= $wPid ?>">Remove</button>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= h($wUrl) ?>">View</a>
                    </td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="5" class="shop-pref-table-empty">No saved products yet. Tap “Add to Wishlist” on a product page.</td></tr>
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
                      data-payment-shipping="<?= h((string)($payRow['shipping_label'] ?? 'Free')) ?>"
                      data-payment-shipping-free="<?= !empty($payRow['shipping_is_free']) ? '1' : '0' ?>"
                      data-payment-discount="<?= h((string)($payRow['discount_label'] ?? '$0.00')) ?>"
                      data-payment-tax="<?= h((string)($payRow['tax_label'] ?? '$0.00')) ?>"
                      data-payment-service-fee="<?= h((string)($payRow['service_fee_label'] ?? '$0.00')) ?>"
                      data-payment-company="<?= h((string)$payRow['company']) ?>"
                      data-payment-date="<?= h((string)$payRow['date']) ?>"
                      data-payment-products="<?= $productsJson ?>"
                    >
                      <td><?= h((string)$payRow['receipt_label']) ?></td>
                      <td class="shop-col-center"><?= (int)$payRow['product_num'] ?></td>
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
                    <div class="shop-payment-line"><span>Shipping</span><span data-payment-field="shipping" class="<?= $buyerPaymentShippingFree ? 'shop-payment-free' : '' ?>"><?= h($buyerPaymentShipping) ?></span></div>
                    <div class="shop-payment-line"><span>Discount</span><span data-payment-field="discount"><?= h($buyerPaymentDiscount) ?></span></div>
                    <div class="shop-payment-line"><span>Tax*</span><span data-payment-field="tax"><?= h($buyerPaymentTax) ?></span></div>
                    <div class="shop-payment-line"><span>Service fee</span><span data-payment-field="service_fee"><?= h($buyerPaymentServiceFee) ?></span></div>
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
              <div><p class="shop-customer-kicker">Returns &amp; refunds</p><h2 class="shop-customer-name"><?= (int)$buyerReturnRequests ?> request<?= (int)$buyerReturnRequests === 1 ? '' : 's' ?></h2><p class="shop-customer-sub"><?= (int)$buyerReturnable ?> order<?= (int)$buyerReturnable === 1 ? '' : 's' ?> eligible for returns or refund review. Select a row to see details on the right.</p></div>
              <div class="shop-invoice-layout">
                <div class="shop-table-stack">
                  <div class="shop-pref-table-wrap"><table class="shop-pref-table">
                  <thead>
                    <tr>
                      <th class="shop-col-center">Return #</th>
                      <th>Order</th>
                      <th>Seller</th>
                      <th>Status</th>
                      <th>Total</th>
                      <th>Date</th>
                      <th class="shop-order-cancel-col">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if ($buyerReturnHistoryRows): foreach ($buyerReturnHistoryRows as $index => $retHist):
                    $productsPayload = array_map(static function (array $p): array {
                        return [
                            'title' => (string)$p['title'],
                            'qty' => (int)$p['qty'],
                            'amount' => (string)$p['amount'],
                        ];
                    }, $retHist['products']);
                    $productsJson = htmlspecialchars(json_encode($productsPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    $retAskHref = (string)($retHist['ask_href'] ?? 'Your_Shopping_preferences.php#seller-messages');
                    $retOrderHref = (string)($retHist['order_href'] ?? '#order-history');
                    $retProductHref = (string)($retHist['product_href'] ?? '');
                  ?>
                    <tr
                      class="<?= $index === $buyerReturnSelectedIndex ? 'is-selected' : '' ?>"
                      data-return-row
                      data-return-code="<?= h((string)$retHist['order_code']) ?>"
                      data-return-status="<?= h((string)$retHist['status']) ?>"
                      data-return-status-label="<?= h((string)$retHist['status_label']) ?>"
                      data-return-date="<?= h((string)$retHist['date']) ?>"
                      data-return-updated="<?= h((string)$retHist['updated']) ?>"
                      data-return-seller="<?= h((string)$retHist['seller']) ?>"
                      data-return-total="<?= h((string)$retHist['total']) ?>"
                      data-return-subtotal="<?= h((string)$retHist['subtotal_label']) ?>"
                      data-return-discount="<?= h((string)$retHist['discount_label']) ?>"
                      data-return-shipping="<?= h((string)$retHist['shipping_label']) ?>"
                      data-return-tax="<?= h((string)$retHist['tax_label']) ?>"
                      data-return-service-fee="<?= h((string)$retHist['service_fee_label']) ?>"
                      data-return-reason="<?= h((string)$retHist['reason']) ?>"
                      data-return-notes="<?= h((string)$retHist['seller_notes']) ?>"
                      data-return-order-href="<?= h($retOrderHref) ?>"
                      data-return-contact-email="<?= h((string)$retHist['contact_email']) ?>"
                      data-return-contact-phone="<?= h((string)$retHist['contact_phone']) ?>"
                      data-return-contact-address="<?= h((string)$retHist['contact_address']) ?>"
                      data-return-products="<?= $productsJson ?>"
                    >
                      <td class="shop-col-center"><?= (int)$retHist['return_num'] ?></td>
                      <td><a class="shop-order-status-link" href="<?= h($retOrderHref) ?>" onclick="event.stopPropagation();"><?= h((string)$retHist['order_code']) ?></a></td>
                      <td><?= h((string)$retHist['seller']) ?></td>
                      <td><?= h((string)$retHist['status_label']) ?></td>
                      <td><?= h((string)$retHist['total']) ?></td>
                      <td><?= h((string)$retHist['date']) ?></td>
                      <td>
                        <div class="shop-order-row-actions">
                          <span class="shop-addr-muted">—</span>
                          <div class="shop-order-more">
                            <button type="button" class="shop-order-more-btn js-return-history-more" aria-label="More actions" aria-haspopup="menu" aria-expanded="false">
                              <i class="fa fa-ellipsis-v" aria-hidden="true"></i>
                            </button>
                            <div class="shop-order-more-menu" role="menu">
                              <a href="<?= h($retOrderHref) ?>" role="menuitem" onclick="event.stopPropagation();">View order</a>
                              <?php if ((int)($retHist['publisher_user_id'] ?? 0) > 0): ?>
                                <a href="<?= h($retAskHref) ?>" role="menuitem" onclick="event.stopPropagation();">Message seller</a>
                              <?php endif; ?>
                              <?php if ($retProductHref !== ''): ?>
                                <a href="<?= h($retProductHref) ?>" role="menuitem" onclick="event.stopPropagation();">View product</a>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                      </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="shop-pref-table-empty">No return or refund requests yet.</td></tr>
                <?php endif; ?>
                  </tbody>
                  </table></div>
                </div>
                <div class="shop-invoice-side">
                  <h2 class="shop-invoice-title">Return details</h2>
                  <aside class="shop-invoice-summary" aria-label="Return details">
                  <div class="shop-invoice-meta">
                    <div>
                      <div class="shop-invoice-number" data-return-field="code"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['order_code']) : 'No return selected' ?></div>
                      <span class="shop-invoice-status" data-return-field="status"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['status_label']) : '—' ?></span>
                    </div>
                    <div class="shop-invoice-dates">
                      <div><strong>Requested</strong> <span data-return-field="date"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['date']) : 'Not set' ?></span></div>
                      <div><strong>Updated</strong> <span data-return-field="updated"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['updated']) : 'Not set' ?></span></div>
                    </div>
                  </div>
                  <div class="shop-invoice-addresses">
                    <div class="shop-invoice-address"><strong>From:</strong><span data-return-field="seller"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['seller']) : 'Seller' ?></span><span>Seller organization</span></div>
                    <div class="shop-invoice-address"><strong>To:</strong><span><?= h($buyerDisplayName) ?></span><span><?= $buyerProfile['email'] !== '' ? h($buyerProfile['email']) : 'Customer account' ?></span><?php if ($buyerProfile['phone'] !== ''): ?><span><?= h($buyerProfile['phone']) ?></span><?php endif; ?></div>
                  </div>
                  <div class="shop-invoice-items" data-return-field="items-list">
                    <?php if ($buyerReturnSelected && !empty($buyerReturnSelected['products'])): ?>
                      <div class="shop-invoice-items-head"><span>Product</span><span style="text-align:right">Qty</span><span style="text-align:right">Amount</span></div>
                      <?php foreach ($buyerReturnSelected['products'] as $productLine): ?>
                        <div class="shop-invoice-product-line">
                          <span class="shop-invoice-product-title"><?= h((string)$productLine['title']) ?></span>
                          <span class="shop-invoice-product-qty"><?= (int)$productLine['qty'] ?></span>
                          <span class="shop-invoice-product-amount"><?= h((string)$productLine['amount']) ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="shop-invoice-items-empty">Select a return to see products.</div>
                    <?php endif; ?>
                  </div>
                  <div class="shop-invoice-line"><span>Reason :</span><strong data-return-field="reason"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['reason']) : '—' ?></strong></div>
                  <div class="shop-invoice-line"><span>Seller notes :</span><strong data-return-field="notes"><?= $buyerReturnSelected && (string)$buyerReturnSelected['seller_notes'] !== '' ? h((string)$buyerReturnSelected['seller_notes']) : 'None yet' ?></strong></div>
                  <div class="shop-invoice-line"><span>Sub Total :</span><strong data-return-field="subtotal"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['subtotal_label']) : '$0.00' ?></strong></div>
                  <div class="shop-invoice-line"><span>Discount :</span><strong data-return-field="discount"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['discount_label']) : '$0.00' ?></strong></div>
                  <div class="shop-invoice-line"><span>Shipping :</span><strong data-return-field="shipping"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['shipping_label']) : 'Free' ?></strong></div>
                  <div class="shop-invoice-line"><span>Taxes :</span><strong data-return-field="tax"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['tax_label']) : '$0.00' ?></strong></div>
                  <div class="shop-invoice-line"><span>Service fee :</span><strong data-return-field="service_fee"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['service_fee_label']) : '$0.00' ?></strong></div>
                  <div class="shop-invoice-line"><strong>Order Total :</strong><strong data-return-field="grand"><?= $buyerReturnSelected ? h((string)$buyerReturnSelected['total']) : '$0.00' ?></strong></div>
                  <div class="shop-invoice-note">
                    <h3>Note</h3>
                    <p>Click a return in the table to review reason, status, and order details here.</p>
                  </div>
                  <a class="shop-invoice-download" href="<?= $buyerReturnSelected ? h((string)$buyerReturnSelected['order_href']) : '#order-history' ?>" data-return-field="order-link">View order</a>
                  <div class="shop-invoice-seller-contact">
                    <h3>Seller contact</h3>
                    <?php
                      $retEmail = $buyerReturnSelected ? (string)($buyerReturnSelected['contact_email'] ?? '') : '';
                      $retPhone = $buyerReturnSelected ? (string)($buyerReturnSelected['contact_phone'] ?? '') : '';
                      $retAddress = $buyerReturnSelected ? (string)($buyerReturnSelected['contact_address'] ?? '') : '';
                    ?>
                    <p data-return-field="contact-email" data-invoice-empty="<?= $retEmail === '' ? '1' : '0' ?>">
                      <?php if ($retEmail !== ''): ?>
                        Email: <a href="mailto:<?= h($retEmail) ?>"><?= h($retEmail) ?></a>
                      <?php endif; ?>
                    </p>
                    <p data-return-field="contact-phone" data-invoice-empty="<?= $retPhone === '' ? '1' : '0' ?>">
                      <?php if ($retPhone !== ''): ?>
                        Phone: <a href="tel:<?= h(preg_replace('/\s+/', '', $retPhone)) ?>"><?= h($retPhone) ?></a>
                      <?php endif; ?>
                    </p>
                    <p data-return-field="contact-address" data-invoice-empty="<?= $retAddress === '' ? '1' : '0' ?>"><?= $retAddress !== '' ? h($retAddress) : '' ?></p>
                    <p data-return-field="contact-fallback" data-invoice-empty="<?= ($retEmail !== '' || $retPhone !== '' || $retAddress !== '') ? '1' : '0' ?>">Contact details not provided by this seller.</p>
                  </div>
                  </aside>
                </div>
              </div>
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
              <div class="shop-seller-msg-intro">
                <p class="shop-customer-kicker">Messages</p>
                <h2 class="shop-customer-name">Chat with sellers</h2>
                <p class="shop-customer-sub">Seller contact only — ask about a product, order, pickup, or delivery. Friend chats stay in Messages.</p>
              </div>
              <?php if (!$sellerMsgContacts): ?>
                <div class="shop-seller-msg-empty-card">
                  <div>
                    No seller chats yet. Open a product and choose <strong>Message seller about this product</strong>, then send your first message — that seller will appear here.
                    <div><a class="btn btn-sm btn-primary" href="shop.php">Browse shop</a></div>
                </div>
                </div>
              <?php else:
                $activeName = trim((string)($sellerMsgActive['seller_name'] ?? 'Seller'));
                $activeFc = strtoupper(trim((string)($sellerMsgActive['friend_code'] ?? '')));
                $activePid = (int)($sellerMsgActive['publisher_user_id'] ?? 0);
                $activeAva = pref_seller_avatar_url($activePid, $activeName, $activeFc);
                $sellerInfoHref = function_exists('commerce_seller_info_url')
                  ? commerce_seller_info_url($activePid, 'seller-messages')
                  : ('seller_info.php?id=' . $activePid . '&from=seller-messages');
                $prodTitle = '';
                $prodPrice = '';
                $prodCover = '';
                $prodHref = '';
                $prodIdLabel = '';
                $prodIdNum = 0;
                $prodCode = '';
                if (is_array($sellerMsgProductFocus) && $sellerMsgProductFocus) {
                    $prodIdNum = (int)($sellerMsgProductFocus['id'] ?? $sellerMsgAboutProduct);
                    $prodTitle = trim((string)($sellerMsgProductFocus['title'] ?? 'Product'));
                    $prodPrice = trim((string)($sellerMsgProductFocus['price'] ?? ''));
                    $prodCover = trim((string)($sellerMsgProductFocus['cover'] ?? ''));
                    $prodHref = trim((string)($sellerMsgProductFocus['buyer_href'] ?? ''));
                    $prodCode = trim((string)($sellerMsgProductFocus['code'] ?? ''));
                    $prodIdLabel = 'Product ID #' . $prodIdNum;
                    if ($prodCode !== '') {
                        $prodIdLabel .= ' · ' . $prodCode;
                    }
                } elseif (is_array($sellerMsgProduct) && $sellerMsgProduct) {
                    $prodIdNum = (int)($sellerMsgProduct['id'] ?? $sellerMsgAboutProduct);
                    $prodTitle = trim((string)($sellerMsgProduct['title'] ?? 'Product'));
                    $prodPrice = function_exists('org_shop_format_price')
                      ? org_shop_format_price((int)($sellerMsgProduct['price_cents'] ?? 0), (string)($sellerMsgProduct['currency'] ?? 'USD'))
                      : '';
                    $prodCover = function_exists('org_shop_cover_url')
                      ? org_shop_cover_url((string)($sellerMsgProduct['cover_image_path'] ?? ''))
                      : '';
                    $prodHref = 'product_detail.php?id=' . $prodIdNum;
                    $prodCode = trim((string)($sellerMsgProduct['product_code'] ?? ''));
                    if ($prodCode === '' && function_exists('org_shop_product_code_from_id') && $prodIdNum > 0) {
                        $prodCode = org_shop_product_code_from_id($prodIdNum);
                    }
                    $prodIdLabel = $prodIdNum > 0 ? ('Product ID #' . $prodIdNum) : '';
                    if ($prodIdLabel !== '' && $prodCode !== '') {
                        $prodIdLabel .= ' · ' . $prodCode;
                    }
                }
              ?>
                <div class="shop-seller-msg-shell">
                <div class="shop-seller-msg-layout" id="shopSellerMsgRoot"
                    data-peer="<?= h($activeFc) ?>"
                    data-peer-name="<?= h($activeName) ?>"
                    data-peer-avatar="<?= h($activeAva) ?>"
                  data-draft="<?= h($sellerMsgDraft) ?>"
                    data-about-product="<?= (int)$prodIdNum ?>"
                    data-pending-first="<?= !empty($sellerMsgPendingFirst) || !empty($sellerMsgActive['pending_first_message']) ? '1' : '0' ?>"
                  >
                    <div class="shop-seller-msg-rail">
                      <div class="shop-seller-msg-toolbar">
                        <div class="shop-seller-msg-search">
                          <i class="fa fa-search" aria-hidden="true"></i>
                          <input type="search" id="shopSellerMsgSearch" placeholder="Search seller messages..." autocomplete="off">
                        </div>
                        <button type="button" class="shop-seller-msg-filter" id="shopSellerMsgFilter" aria-label="Filter conversations">
                          All <i class="fa fa-chevron-down" aria-hidden="true"></i>
                        </button>
                      </div>
                      <div class="shop-seller-msg-list" id="shopSellerMsgList" aria-label="Sellers you messaged">
                    <?php foreach ($sellerMsgContacts as $c):
                      $cid = (int)($c['publisher_user_id'] ?? 0);
                        $isActive = $cid === $activePid;
                        $href = commerce_message_seller_url(
                            $cid,
                            $isActive ? $sellerMsgAboutProduct : (int)($c['about_product_id'] ?? 0),
                            $isActive ? $sellerMsgAboutOrder : ''
                        );
                        $pending = !empty($c['pending_first_message']);
                        $cname = trim((string)($c['seller_name'] ?? 'Seller'));
                        $cfc = strtoupper(trim((string)($c['friend_code'] ?? '')));
                        $cava = pref_seller_avatar_url($cid, $cname, $cfc);
                        $preview = $pending ? 'Send a message to save this seller here' : (string)(($c['last_message'] !== '' ? $c['last_message'] : 'Seller conversation'));
                        $ctime = pref_seller_msg_time($c['last_at'] ?? '');
                        $searchBlob = strtolower($cname . ' ' . $preview);
                      ?>
                        <div class="shop-seller-msg-item-row<?= $isActive ? ' is-active' : '' ?>" data-publisher-id="<?= (int)$cid ?>" data-search="<?= h($searchBlob) ?>">
                          <a class="shop-seller-msg-item" href="<?= h($href) ?>" data-peer="<?= h($cfc) ?>">
                            <img class="shop-seller-msg-ava" src="<?= h($cava) ?>" alt="">
                            <span class="shop-seller-msg-item-main">
                              <span class="shop-seller-msg-item-top">
                                <strong><?= h($cname) ?></strong>
                                <i class="fa fa-check-circle shop-seller-msg-verified" aria-hidden="true" title="Verified seller"></i>
                                <?php if ((int)($c['unread'] ?? 0) > 0): ?><span class="shop-pref-nav-badge"><?= (int)$c['unread'] ?></span><?php endif; ?>
                              </span>
                              <span class="shop-seller-msg-item-preview"><?= h($preview) ?></span>
                            </span>
                            <?php if ($ctime !== ''): ?><span class="shop-seller-msg-item-time"><?= h($ctime) ?></span><?php endif; ?>
                          </a>
                          <?php if (!$pending): ?>
                            <button type="button" class="shop-seller-msg-remove" data-publisher-id="<?= (int)$cid ?>" title="Remove from list">Remove</button>
                          <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                      </div>
                  </div>
                  <div class="shop-seller-msg-chat">
                      <div class="shop-seller-msg-head" id="shopSellerMsgHead">
                        <img class="shop-seller-msg-head-ava" src="<?= h($activeAva) ?>" alt="">
                        <div class="shop-seller-msg-head-meta">
                          <p class="shop-seller-msg-head-name">
                            <?= h($activeName) ?>
                            <i class="fa fa-check-circle shop-seller-msg-verified" aria-hidden="true" title="Verified seller"></i>
                          </p>
                          <div class="shop-seller-msg-head-status">
                            <i class="fa fa-circle" aria-hidden="true"></i> Active now
                            <a class="shop-seller-msg-head-info" id="shopSellerMsgHeadInfo" href="<?= h($sellerInfoHref) ?>" title="Seller info" aria-label="Seller info">
                              <i class="fa fa-info-circle" aria-hidden="true"></i>
                            </a>
                          </div>
                        </div>
                      <?php if ($prodIdNum > 0 || $prodTitle !== ''): ?>
                        <div class="shop-seller-msg-product" id="shopSellerMsgProduct"
                          data-product-id="<?= (int)$prodIdNum ?>"
                          data-product-title="<?= h($prodTitle !== '' ? $prodTitle : ('Product #' . $prodIdNum)) ?>"
                          data-product-code="<?= h($prodCode) ?>"
                          data-product-price="<?= h($prodPrice) ?>"
                          data-product-cover="<?= h($prodCover) ?>"
                          data-product-href="<?= h($prodHref) ?>"
                        >
                          <?php if ($prodCover !== ''): ?>
                            <img src="<?= h($prodCover) ?>" alt="" id="shopSellerMsgProductImg">
                          <?php else: ?>
                            <img src="avatar.php?name=<?= rawurlencode($prodTitle !== '' ? $prodTitle : ('P' . $prodIdNum)) ?>" alt="" id="shopSellerMsgProductImg">
                          <?php endif; ?>
                          <div>
                            <strong id="shopSellerMsgProductTitle"><?= h($prodTitle !== '' ? $prodTitle : ('Product #' . $prodIdNum)) ?></strong>
                            <?php if ($prodIdLabel !== ''): ?><span class="shop-seller-msg-product-id" id="shopSellerMsgProductId"><?= h($prodIdLabel) ?></span><?php endif; ?>
                            <?php if ($prodPrice !== ''): ?><span class="shop-seller-msg-product-price" id="shopSellerMsgProductPrice"><?= h($prodPrice) ?></span><?php endif; ?>
                          </div>
                          <?php if ($prodHref !== ''): ?><a href="<?= h($prodHref) ?>" id="shopSellerMsgProductLink">View item</a><?php endif; ?>
                        </div>
                      <?php else: ?>
                        <div class="shop-seller-msg-product" id="shopSellerMsgProduct" hidden></div>
                      <?php endif; ?>
                        <div class="shop-seller-msg-more" id="shopSellerMsgMore">
                          <button type="button" class="shop-seller-msg-more-btn" id="shopSellerMsgMoreBtn" aria-label="Product history" aria-haspopup="menu" aria-expanded="false">
                            <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
                          </button>
                          <div class="shop-seller-msg-more-menu" id="shopSellerMsgMoreMenu" role="menu">
                            <div class="shop-seller-msg-history-head">Product history</div>
                            <div class="shop-seller-msg-history-list" id="shopSellerMsgHistoryList">
                              <div class="shop-seller-msg-history-empty">Loading…</div>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="shop-seller-msg-history-bar" id="shopSellerMsgHistoryBar">
                        <button type="button" class="shop-seller-msg-history-back" id="shopSellerMsgHistoryBack">← Back</button>
                        <p class="shop-seller-msg-history-title" id="shopSellerMsgHistoryTitle">Product history</p>
                      </div>
                    <div class="shop-seller-msg-thread" id="shopSellerMsgThread" aria-live="polite"></div>
                      <div class="shop-seller-msg-compose-wrap">
                    <div class="shop-seller-msg-compose">
                          <div class="shop-seller-msg-compose-bar">
                            <textarea id="shopSellerMsgInput" rows="1" placeholder="Type a message about the product or order..."></textarea>
                    </div>
                          <button type="button" id="shopSellerMsgSend">Send</button>
                        </div>
                        <p class="tx-danger tx-12 mg-b-0 mg-t-8" id="shopSellerMsgErr" hidden></p>
                      </div>
                    </div>
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
              <div class="shop-buyer-notif-head">
                <div class="shop-buyer-notif-head-copy">
                  <h2 class="shop-customer-name">Notifications</h2>
                  <p class="shop-customer-sub"><?= $buyerAlertCount > 0
                    ? ((int)$buyerAlertCount . ($buyerAlertCount === 1 ? ' needs attention' : ' need attention'))
                    : 'All caught up.' ?></p>
                </div>
              </div>
              <div class="shop-buyer-notif-tabs" role="tablist" aria-label="Order notification filters">
                <?php foreach ($buyerNotifTabs as $tab): ?>
                  <?php
                    $tabKey = (string)$tab['key'];
                    $tabCount = (int)$tab['count'];
                    $isAll = $tabKey === 'all';
                  ?>
                  <button
                    type="button"
                    class="shop-buyer-notif-tab<?= $isAll ? ' is-active' : '' ?>"
                    role="tab"
                    aria-selected="<?= $isAll ? 'true' : 'false' ?>"
                    data-buyer-notif-tab="<?= h($tabKey) ?>"
                  >
                    <?= h((string)$tab['label']) ?>
                    <?php if (!$isAll && $tabCount > 0): ?>
                      <span class="shop-buyer-notif-tab-count"><?= $tabCount > 99 ? '99+' : $tabCount ?></span>
                    <?php endif; ?>
                  </button>
                    <?php endforeach; ?>
                  </div>
              <div class="shop-buyer-notif-scroll">
                <div class="shop-buyer-notif-feed" id="shopBuyerNotifFeed" role="list">
                  <?php if (!$buyerCommerceAlerts && !$buyerCommerceNotifications): ?>
                    <div class="shop-buyer-notif-empty" data-buyer-notif-empty="all">No order notifications yet. Updates appear here as your orders move.</div>
                  <?php endif; ?>
                    <?php foreach ($buyerCommerceAlerts as $alert): ?>
                      <?php
                      $alertType = (string)($alert['type'] ?? 'Alert');
                      $alertKey = $buyerNotifTypeKey($alertType);
                        $badgeCount = max(0, (int)($alert['count'] ?? 0));
                        $badgeLabel = $badgeCount > 99 ? '99+' : (string)$badgeCount;
                      $ico = $buyerNotifIcon($alertKey === 'update' ? 'alerts' : $alertKey);
                      $alertHref = '#returns-refunds';
                      $alertPanel = 'returns-refunds';
                      if (
                          $alertKey === 'paid'
                          || $alertKey === 'shipping'
                          || $alertKey === 'delivery'
                          || $alertKey === 'pending'
                          || $alertKey === 'cancelled'
                      ) {
                          $detailTab = $alertKey === 'paid' ? '#payment' : '#order';
                          if ($alertKey === 'paid') {
                              $detailStatuses = ['paid'];
                          } elseif ($alertKey === 'shipping') {
                              $detailStatuses = ['shipped'];
                          } elseif ($alertKey === 'delivery') {
                              $detailStatuses = ['delivered'];
                          } elseif ($alertKey === 'cancelled') {
                              $detailStatuses = ['cancelled'];
                          } else {
                              $detailStatuses = ['pending', 'confirmed'];
                          }
                          $alertHref = (string)($alert['action'] ?? '');
                          if ($alertHref === '' || strpos($alertHref, 'order_detail.php') !== 0) {
                              $alertHref = org_shop_buyer_latest_order_detail_href($dbh, $meId, $detailStatuses);
                          }
                          if (strpos($alertHref, 'order_detail.php') === 0) {
                              $alertHref = preg_replace('/#.*$/', '', $alertHref) . $detailTab;
                          }
                          $alertPanel = '';
                      }
                    ?>
                    <a
                      href="<?= h($alertHref) ?>"
                      class="shop-buyer-notif-row is-alert<?= $alertKey === 'delivery' ? ' is-delivery' : '' ?>"
                      role="listitem"
                      data-buyer-notif-kind="alert"
                      data-buyer-notif-filter="<?= h($alertKey) ?>"
                      <?php if ($alertPanel !== ''): ?>data-shop-pref-target="<?= h($alertPanel) ?>"<?php endif; ?>
                    >
                      <span class="shop-buyer-notif-ico" aria-hidden="true"><i class="icon <?= h($ico) ?>"></i></span>
                      <span class="shop-buyer-notif-row-body">
                        <span class="shop-buyer-notif-row-top">
                          <strong><?= h($alertType) ?></strong>
                          <?php if ($badgeCount > 0): ?>
                            <b class="shop-buyer-notif-alert-badge"><?= h($badgeLabel) ?></b>
                          <?php endif; ?>
                        </span>
                        <span class="shop-buyer-notif-row-copy"><?= h((string)($alert['message'] ?? '')) ?></span>
                      </span>
                      </a>
                    <?php endforeach; ?>
                  <?php foreach (array_slice($buyerCommerceNotifications, 0, 40) as $n): ?>
                    <?php
                      $nType = (string)($n['type'] ?? 'Update');
                      $nKey = $buyerNotifTypeKey($nType);
                      $nIco = $buyerNotifIcon($nKey);
                      $nTitle = (string)($n['title'] ?? $nType);
                      $nFrom = trim((string)($n['from'] ?? 'Seller'));
                      $nMsg = trim((string)($n['message'] ?? ''));
                      $nWhen = trim((string)($n['when'] ?? ''));
                      $nCopy = $nFrom !== '' ? ($nFrom . ($nMsg !== '' ? ' · ' . $nMsg : '')) : $nMsg;
                      $nHref = '#returns-refunds';
                      $nPanel = 'returns-refunds';
                      if (
                          $nKey === 'paid'
                          || $nKey === 'shipping'
                          || $nKey === 'delivery'
                          || $nKey === 'pending'
                          || $nKey === 'cancelled'
                      ) {
                          $detailTab = $nKey === 'paid' ? '#payment' : '#order';
                          if ($nKey === 'paid') {
                              $detailStatuses = ['paid'];
                          } elseif ($nKey === 'shipping') {
                              $detailStatuses = ['shipped'];
                          } elseif ($nKey === 'delivery') {
                              $detailStatuses = ['delivered'];
                          } elseif ($nKey === 'cancelled') {
                              $detailStatuses = ['cancelled'];
                          } else {
                              $detailStatuses = ['pending', 'confirmed'];
                          }
                          $nHref = (string)($n['action'] ?? '');
                          $nOid = (int)($n['order_id'] ?? 0);
                          $nCode = trim((string)($n['order_code'] ?? ''));
                          if ($nOid > 0 && (strpos($nHref, 'order_detail.php') !== 0)) {
                              $nHref = 'order_detail.php?order_id=' . $nOid
                                  . ($nCode !== '' ? ('&code=' . rawurlencode($nCode)) : '')
                                  . $detailTab;
                          } elseif (strpos($nHref, 'order_detail.php') === 0) {
                              $nHref = preg_replace('/#.*$/', '', $nHref) . $detailTab;
                          } elseif ($nHref === '' || strpos($nHref, 'order_detail.php') !== 0) {
                              if (preg_match('/\b(ORD-[A-Z0-9-]+)\b/i', $nMsg . ' ' . $nTitle, $mOrd)) {
                                  $foundOrd = org_shop_find_buyer_order_by_code($dbh, $meId, (string)$mOrd[1]);
                                  if ($foundOrd) {
                                      $fid = (int)($foundOrd['id'] ?? 0);
                                      $fcode = trim((string)($foundOrd['order_code'] ?? $mOrd[1]));
                                      if ($fid > 0) {
                                          $nHref = 'order_detail.php?order_id=' . $fid
                                              . ($fcode !== '' ? ('&code=' . rawurlencode($fcode)) : '')
                                              . $detailTab;
                                      }
                                  }
                              }
                              if (strpos($nHref, 'order_detail.php') !== 0) {
                                  $nHref = org_shop_buyer_latest_order_detail_href($dbh, $meId, $detailStatuses);
                                  if (strpos($nHref, 'order_detail.php') === 0) {
                                      $nHref = preg_replace('/#.*$/', '', $nHref) . $detailTab;
                                  }
                              }
                          }
                          $nPanel = '';
                      }
                    ?>
                    <a
                      href="<?= h($nHref) ?>"
                      class="shop-buyer-notif-row<?= $nKey === 'delivery' ? ' is-delivery' : '' ?>"
                      role="listitem"
                      data-buyer-notif-kind="feed"
                      data-buyer-notif-filter="<?= h($nKey) ?>"
                      <?php if ($nPanel !== ''): ?>data-shop-pref-target="<?= h($nPanel) ?>"<?php endif; ?>
                    >
                      <span class="shop-buyer-notif-ico" aria-hidden="true"><i class="icon <?= h($nIco) ?>"></i></span>
                      <span class="shop-buyer-notif-row-body">
                        <span class="shop-buyer-notif-row-top">
                          <strong><?= h($nTitle) ?></strong>
                          <?php if ($nWhen !== ''): ?>
                            <span class="shop-buyer-notif-row-when"><?= h($nWhen) ?></span>
                <?php endif; ?>
                        </span>
                        <?php if ($nCopy !== ''): ?>
                          <span class="shop-buyer-notif-row-copy"><?= h($nCopy) ?></span>
                        <?php endif; ?>
                      </span>
                      </a>
                    <?php endforeach; ?>
                  <div class="shop-buyer-notif-empty" data-buyer-notif-empty="filter" hidden>Nothing in this filter right now.</div>
                  </div>
                </div>
            </div>
            <div class="shop-pref-panel" id="membership" data-shop-pref-panel="membership">
              <div>
                <p class="shop-customer-kicker">Customer Plus</p>
                <h2 class="shop-customer-name"><?= $membershipActive ? 'Member' : 'Optional membership' ?></h2>
                <p class="shop-customer-sub">
                  Pay <strong><?= h($membershipPriceLabel) ?>/month</strong> if you want membership benefits.
                  Members pay <strong>$0 service fee</strong> on shop orders (normally $1.99).
                </p>
              </div>
              <?php if ($membershipFlashOk !== ''): ?><div class="alert alert-success"><?= h($membershipFlashOk) ?></div><?php endif; ?>
              <?php if ($membershipFlashErr !== ''): ?><div class="alert alert-danger"><?= h($membershipFlashErr) ?></div><?php endif; ?>

              <div class="shop-customer-stats shop-customer-stats-3" style="margin-bottom:16px;">
                <div class="shop-customer-stat">
                  <strong><?= $membershipActive ? 'Active' : 'Not active' ?></strong>
                  <span>status</span>
                </div>
                <div class="shop-customer-stat">
                  <strong><?= $membershipPaidUntil !== '' ? h(date('M j, Y', strtotime($membershipPaidUntil) ?: time())) : '—' ?></strong>
                  <span>paid until</span>
                </div>
                <div class="shop-customer-stat">
                  <strong><?= h($membershipServiceFeeLabel) ?></strong>
                  <span>your service fee</span>
                </div>
              </div>

              <div class="shop-pref-table-wrap" style="margin-bottom:16px;">
                <table class="shop-pref-table">
                  <thead><tr><th>Benefit</th><th>With membership</th><th>Without</th></tr></thead>
                  <tbody>
                    <tr><td>Platform service fee per order</td><td>$0.00</td><td>$1.99</td></tr>
                    <tr><td>Monthly cost</td><td><?= h($membershipPriceLabel) ?></td><td>$0.00</td></tr>
                    <tr><td>Shop anytime + order tracking</td><td>Included</td><td>Included</td></tr>
                  </tbody>
                </table>
              </div>

              <form method="post" class="mg-b-20" style="max-width:360px;">
                <input type="hidden" name="membership_action" value="subscribe">
                <label class="tx-12" style="display:block;margin-bottom:6px;font-weight:700;">Pay for</label>
                <select name="months" class="form-control" style="margin-bottom:10px;">
                  <option value="1">1 month — <?= h($membershipPriceLabel) ?></option>
                  <option value="3">3 months — <?= h(org_shop_format_price(buyer_membership_price_cents() * 3, 'USD')) ?></option>
                  <option value="6">6 months — <?= h(org_shop_format_price(buyer_membership_price_cents() * 6, 'USD')) ?></option>
                  <option value="12">12 months — <?= h(org_shop_format_price(buyer_membership_price_cents() * 12, 'USD')) ?></option>
                </select>
                <button type="submit" class="btn btn-primary btn-block">
                  <?= $membershipActive ? 'Extend membership' : 'Subscribe — $10/month' ?>
                </button>
                <?php if (!$stripeMembershipReady): ?>
                  <p class="tx-12" style="margin-top:8px;opacity:.75;">Card checkout is not enabled yet. Contact Admin if you want to join.</p>
                <?php endif; ?>
              </form>

              <div class="shop-pref-table-wrap">
                <table class="shop-pref-table">
                  <thead><tr><th>When</th><th>Amount</th><th>Months</th><th>Method</th></tr></thead>
                  <tbody>
                    <?php if (!$membershipPayments): ?>
                      <tr><td colspan="4">No membership payments yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($membershipPayments as $mp): ?>
                        <tr>
                          <td><?= h(date('M j, Y', strtotime((string)($mp['paid_at'] ?? '')) ?: time())) ?></td>
                          <td><?= h(org_shop_format_price((int)($mp['amount_cents'] ?? 0), (string)($mp['currency'] ?? 'USD'))) ?></td>
                          <td><?= (int)($mp['months_paid'] ?? 1) ?></td>
                          <td><?= h((string)($mp['payment_method'] ?? '—')) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
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
            <div class="shop-pref-panel" id="guidance-center" data-shop-pref-panel="guidance-center">
              <div>
                <p class="shop-customer-kicker">Guidance Center</p>
                <h2 class="shop-customer-name">Shop as a customer</h2>
                <p class="shop-customer-sub">Self-serve how-tos for browsing, cart, checkout, tracking, returns, and Preferences. For a live agent, use Support Center.</p>
              </div>
              <div class="shop-guidance-list">
                <div class="shop-guidance-item"><strong>How do I find products in the shop?</strong><p>Open Shop, browse featured products or Shop by Category, and use search when you know a name or brand.</p></div>
                <div class="shop-guidance-item"><strong>How do I add items to my cart?</strong><p>On a product page, choose quantity if available, then tap Add to Cart. Open the cart to change quantities or checkout.</p></div>
                <div class="shop-guidance-item"><strong>How do I place an order?</strong><p>Confirm delivery address and phone, review the total, choose payment, and place the order. You’ll get an order code to track later.</p></div>
                <div class="shop-guidance-item"><strong>Where do I track my order?</strong><p>Open My orders in this menu and select a row for status, products, and receipts.</p></div>
                <div class="shop-guidance-item"><strong>How do payments work?</strong><p>Checkout shows price, shipping, tax, and any service fee before you confirm. Past charges are in each order’s details.</p></div>
                <div class="shop-guidance-item"><strong>How do I set my delivery address?</strong><p>Save addresses under Addresses so checkout is faster. Keep your phone current for delivery updates.</p></div>
                <div class="shop-guidance-item"><strong>What is the wishlist for?</strong><p>Save products without buying yet. Open Wishlist to revisit, compare, or add them to cart later.</p></div>
                <div class="shop-guidance-item"><strong>How do I message a seller?</strong><p>Use Messages from a product or order, or open Messages here for seller conversations.</p></div>
                <div class="shop-guidance-item"><strong>How do I return or get a refund?</strong><p>Open Returns &amp; refunds (or the order), follow return options when available, and track refund status here.</p></div>
                <div class="shop-guidance-item"><strong>What does membership do?</strong><p>Membership can reduce or remove service fees while active. Check Membership for status and benefits.</p></div>
                <div class="shop-guidance-item"><strong>What is Shopping Preferences?</strong><p>This page is your customer hub: dashboard, orders, wishlist, addresses, messages, and more. Guidance teaches the shop; Support Center is for live help.</p></div>
              </div>
              <p class="shop-customer-sub">Need a person? <a href="#support-center" data-shop-pref-target="support-center">Open Support Center</a>.</p>
            </div>
            <div class="shop-pref-panel" id="support-center" data-shop-pref-panel="support-center">
              <div class="shop-support-msg-intro">
                <p class="shop-customer-kicker">Support Center</p>
                <h2 class="shop-customer-name">Live customer center</h2>
                <p class="shop-customer-sub">Chat only with Admin about a seller dispute, payments, delivery, or account issues. To message a seller about a product, use Messages. For self-serve how-tos, use Guidance Center.</p>
              </div>
              <div class="shop-admin-support" id="shopAdminSupportRoot"
                data-endpoint="ajax/admin_support_chat.php"
                data-topic="<?= h($supportTopic) ?>"
                data-about-product="<?= (int)$supportAboutProduct ?>"
                data-about-seller="<?= (int)$supportAboutSeller ?>"
                data-seller-business="<?= h($supportSellerBusiness) ?>"
                data-draft="<?= h($supportDraft) ?>"
                data-support-report="<?= $supportReportMode ? '1' : '0' ?>"
                data-case-open="<?= $supportCaseOpen ? '1' : '0' ?>"
                data-dispute-unlocked="<?= $supportDisputeUnlocked ? '1' : '0' ?>"
              >
                <div class="shop-admin-support-contacts" aria-label="Admin help topics">
                  <div class="shop-admin-support-toolbar">
                    <div class="shop-admin-support-search">
                      <i class="fa fa-search" aria-hidden="true"></i>
                      <input type="search" id="shopAdminSupportSearch" placeholder="Search help topics..." autocomplete="off">
                  </div>
                    <button type="button" class="shop-admin-support-filter" aria-label="Filter topics">
                      All <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    </button>
                      </div>
                  <div class="shop-admin-support-contacts-list" id="shopAdminSupportList">
                    <button type="button" class="shop-admin-support-help-item<?= $supportTopic === 'dispute' ? ' is-active' : '' ?>" data-topic="dispute" data-search="dispute seller order refund business report product">
                      <strong>Dispute with seller</strong>
                      <span>Ask Admin to review a problem with a seller’s business, order, or refund.</span>
                          </button>
                    <button type="button" class="shop-admin-support-help-item<?= $supportTopic === 'help' ? ' is-active' : '' ?>" data-topic="help" data-search="help payment delivery account">
                      <strong>Need help</strong>
                      <span>Payments, delivery, account issues, or other Admin support.</span>
                    </button>
                    <p class="shop-admin-support-help-note">Seller product questions stay in Messages. Product Report opens a dispute case here so Admin can focus on the product and seller business (seller gets 30 days to respond).</p>
                        </div>
                </div>
                <div class="shop-admin-support-chat">
                  <?php
                    $supProdId = (int)($supportProductFocus['id'] ?? $supportAboutProduct);
                    $supProdTitle = trim((string)($supportProductFocus['title'] ?? ''));
                    $supProdCover = trim((string)($supportProductFocus['cover'] ?? ''));
                    $supProdHref = trim((string)($supportProductFocus['buyer_href'] ?? ''));
                    $supProdCode = trim((string)($supportProductFocus['code'] ?? ''));
                    $supProdIdLabel = $supProdId > 0 ? ('Product ID #' . $supProdId) : '';
                    if ($supProdIdLabel !== '' && $supProdCode !== '') {
                        $supProdIdLabel .= ' · ' . $supProdCode;
                    }
                    if ($supProdTitle === '' && $supProdId > 0) {
                        $supProdTitle = 'Product #' . $supProdId;
                    }
                    if ($supProdHref === '' && $supProdId > 0) {
                        $supProdHref = 'product_detail.php?id=' . $supProdId;
                    }
                  ?>
                  <div class="shop-admin-support-head shop-seller-msg-head" id="shopAdminSupportHead">
                    <div class="shop-admin-support-head-ava" aria-hidden="true"><i class="fa fa-headphones"></i></div>
                    <div class="shop-admin-support-head-meta shop-seller-msg-head-meta">
                      <p class="shop-admin-support-head-name shop-seller-msg-head-name" id="shopAdminSupportHeadName">Admin support</p>
                      <div class="shop-admin-support-head-status shop-seller-msg-head-status"><i class="fa fa-circle" aria-hidden="true"></i> <span id="shopAdminSupportHeadStatus">Active now</span></div>
                    </div>
                    <?php if ($supProdId > 0): ?>
                      <div class="shop-admin-support-product shop-seller-msg-product" id="shopAdminSupportProduct">
                        <?php if ($supProdCover !== ''): ?>
                          <img src="<?= h($supProdCover) ?>" alt="">
                        <?php else: ?>
                          <img src="avatar.php?name=<?= rawurlencode($supProdTitle !== '' ? $supProdTitle : ('P' . $supProdId)) ?>" alt="">
                    <?php endif; ?>
                        <div>
                          <strong><?= h($supProdTitle) ?></strong>
                          <?php if ($supProdIdLabel !== ''): ?><span class="shop-admin-support-product-id shop-seller-msg-product-id"><?= h($supProdIdLabel) ?></span><?php endif; ?>
                          <?php if ($supportSellerBusiness !== ''): ?><span class="shop-admin-support-product-biz"><?= h('Seller: ' . $supportSellerBusiness) ?></span><?php endif; ?>
                  </div>
                        <?php if ($supProdHref !== ''): ?><a href="<?= h($supProdHref) ?>">View item</a><?php endif; ?>
                </div>
                    <?php else: ?>
                      <div class="shop-admin-support-product shop-seller-msg-product" id="shopAdminSupportProduct" hidden></div>
                    <?php endif; ?>
                    <div class="shop-admin-support-more" id="shopAdminSupportMore">
                      <button type="button" class="shop-admin-support-head-more shop-seller-msg-more-btn" id="shopAdminSupportMoreBtn" aria-label="More options" aria-haspopup="menu" aria-expanded="false">
                        <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
                      </button>
                      <div class="shop-admin-support-more-menu" id="shopAdminSupportMoreMenu" role="menu">
                        <div class="shop-admin-support-history-head">Case history</div>
                        <div class="shop-admin-support-history-list" id="shopAdminSupportHistoryList">
                          <div class="shop-admin-support-history-empty">Loading history…</div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="shop-admin-support-history-panel" id="shopAdminSupportHistoryPanel" aria-label="Closed case history">
                    <div class="shop-admin-support-history-bar">
                      <button type="button" class="shop-admin-support-history-back" id="shopAdminSupportHistoryBack">← Back</button>
                      <p class="shop-admin-support-history-title" id="shopAdminSupportHistoryTitle">Case history</p>
                    </div>
                    <div class="shop-admin-support-product shop-seller-msg-product" id="shopAdminSupportHistoryProduct" hidden style="margin:10px 16px;max-width:none;width:auto;"></div>
                    <div class="shop-admin-support-history-thread" id="shopAdminSupportHistoryThread"></div>
                  </div>
                  <div class="shop-admin-support-topics" role="group" aria-label="Support topic">
                    <button type="button" class="shop-admin-topic<?= $supportTopic === 'dispute' ? ' is-active' : '' ?>" data-topic="dispute">Dispute with seller</button>
                    <button type="button" class="shop-admin-topic<?= $supportTopic === 'help' ? ' is-active' : '' ?>" data-topic="help">Need help</button>
                  </div>
                  <div class="shop-admin-support-locked" id="shopAdminSupportLocked"<?= ($supportTopic === 'dispute' && !$supportDisputeUnlocked) ? '' : ' hidden' ?>>
                    This case is closed by Admin. Dispute messages are unavailable until you open a product and tap <strong>Report</strong> to start a new case.
                    <div style="margin-top:8px;"><a href="shop.php">Browse products to report</a></div>
                  </div>
                  <div class="shop-admin-support-thread" id="shopAdminSupportThread" aria-live="polite"></div>
                  <div class="shop-admin-support-compose" id="shopAdminSupportCompose"<?= ($supportTopic === 'dispute' && !$supportDisputeUnlocked) ? ' hidden' : '' ?>>
                    <div class="shop-admin-support-meta">
                      <input type="text" id="shopAdminSupportOrder" placeholder="Order code (optional)" maxlength="80" autocomplete="off">
                      <input type="text" id="shopAdminSupportSeller" placeholder="Seller business name (optional)" maxlength="120" autocomplete="off" value="<?= h($supportSellerBusiness) ?>">
                    </div>
                    <div class="shop-admin-support-compose-row">
                      <div class="shop-admin-support-compose-bar">
                        <textarea id="shopAdminSupportInput" rows="1" placeholder="Describe the dispute or what you need help with…"></textarea>
                      </div>
                      <button type="button" id="shopAdminSupportSend">Send</button>
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

  (function initBuyerNotifTabs() {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-buyer-notif-tab]'));
    var rows = Array.prototype.slice.call(document.querySelectorAll('#shopBuyerNotifFeed [data-buyer-notif-filter]'));
    var emptyFilter = document.querySelector('#shopBuyerNotifFeed [data-buyer-notif-empty="filter"]');
    if (!tabs.length || !rows.length) return;
    function setTab(key) {
      var active = String(key || 'all');
      tabs.forEach(function (btn) {
        var on = btn.getAttribute('data-buyer-notif-tab') === active;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      var visible = 0;
      rows.forEach(function (row) {
        var kind = String(row.getAttribute('data-buyer-notif-kind') || '');
        var filter = String(row.getAttribute('data-buyer-notif-filter') || '');
        var show = active === 'all'
          || (active === 'alerts' && kind === 'alert')
          || (active !== 'alerts' && filter === active);
        row.hidden = !show;
        if (show) visible += 1;
      });
      if (emptyFilter) emptyFilter.hidden = visible > 0;
    }
    tabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        setTab(btn.getAttribute('data-buyer-notif-tab') || 'all');
        try { btn.blur(); } catch (e) { /* ignore */ }
      });
    });
    setTab('all');
  })();

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
    document.body.classList.toggle('msgs-seller-active', id === 'seller-messages');
    document.body.classList.toggle('msgs-support-active', id === 'support-center');
    document.body.classList.toggle('msgs-chat-active', id === 'seller-messages' || id === 'support-center');
    if (id === 'support-center') {
      try { document.dispatchEvent(new CustomEvent('shop-support-open')); } catch (e) { /* ignore */ }
    }
    if (id === 'notifications') markShopNotificationsRead();
    return found;
  }

  var shopNotifReadBusy = false;
  function markShopNotificationsRead() {
    if (shopNotifReadBusy || !window.fetch) return;
    shopNotifReadBusy = true;
    fetch('ajax/shop_notifications_read.php', { method: 'POST', credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.marked > 0) {
          if (typeof window.msbRefreshShopBadge === 'function') window.msbRefreshShopBadge();
          if (typeof window.msbShopSyncNow === 'function') window.msbShopSyncNow();
        }
      })
      .catch(function () { /* ignore */ })
      .then(function () { shopNotifReadBusy = false; });
  }

  links.forEach(function (link) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      var id = link.getAttribute('data-shop-pref-target');
      if (!id) return;
      var y = window.scrollY || window.pageYOffset || 0;
      if (!showPanel(id)) return;
      if (history.replaceState) history.replaceState(null, '', '#' + id);
      window.scrollTo(0, y);
      requestAnimationFrame(function () { window.scrollTo(0, y); });
    });
  });

  // Support Center: Admin topics only (no seller contacts)
  var supportSearch = document.getElementById('shopAdminSupportSearch');
  if (supportSearch) {
    supportSearch.addEventListener('input', function () {
      var q = String(supportSearch.value || '').trim().toLowerCase();
      Array.prototype.slice.call(document.querySelectorAll('.shop-admin-support-help-item')).forEach(function (row) {
        var blob = String(row.getAttribute('data-search') || '') + ' ' + String(row.textContent || '');
        row.style.display = (!q || blob.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }
  Array.prototype.slice.call(document.querySelectorAll('.shop-admin-support-help-item')).forEach(function (btn) {
    btn.addEventListener('click', function () {
      var topic = String(btn.getAttribute('data-topic') || 'dispute');
      Array.prototype.slice.call(document.querySelectorAll('.shop-admin-support-help-item')).forEach(function (row) {
        row.classList.toggle('is-active', row === btn);
      });
      var pill = document.querySelector('.shop-admin-topic[data-topic="' + topic + '"]');
      if (pill) pill.click();
      var headName = document.getElementById('shopAdminSupportHeadName');
      var headStatus = document.getElementById('shopAdminSupportHeadStatus');
      if (headName) headName.textContent = 'Admin support';
      if (headStatus) headStatus.textContent = 'Active now';
    });
  });

  var initial = (window.location.hash || '').replace('#', '');
  var viewParam = '';
  try {
    viewParam = String((new URLSearchParams(window.location.search || '')).get('view') || '').trim();
  } catch (e) {}
  if (!initial && viewParam) initial = viewParam;
  if (initial === 'order-cancel-table') initial = 'notifications';
  if (initial === 'order-details' || initial === 'invoices-payments' || initial === 'documents' || initial === 'my-orders' || initial === 'my_orders') initial = 'order-history';
  if (initial === 'support-tickets') initial = 'support-center';
  if (initial === 'seller-relationships') initial = 'seller-messages';
  if (initial === 'help-center' || initial === 'help' || initial === 'account-update') initial = 'guidance-center';
  if (initial === 'messages') initial = 'seller-messages';
  if (initial === 'contact-information' || initial === 'customer-groups') initial = 'addresses';
  if (initial === 'shopping-cart') { window.location.href = 'shop.php'; return; }
  if ((window.location.search || '').indexOf('session_id=') !== -1 || (window.location.search || '').indexOf('checkout=cancel') !== -1) {
    initial = 'order-history';
  }
  if (initial) showPanel(initial);

  // Deep-link: ?about_product=N#order-history selects that product's invoice row.
  (function focusOrderHistoryFromProduct() {
    if (initial !== 'order-history') return;
    var params = new URLSearchParams(window.location.search || '');
    var aboutPid = parseInt(params.get('about_product') || '0', 10) || 0;
    if (aboutPid <= 0) return;
    var rows = Array.prototype.slice.call(document.querySelectorAll('#order-history [data-invoice-order]'));
    var match = rows.find(function (row) {
      var ids = String(row.getAttribute('data-product-ids') || '')
        .split(',')
        .map(function (v) { return parseInt(v, 10) || 0; });
      return ids.indexOf(aboutPid) !== -1;
    });
    if (match) match.click();
  })();

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
        status: row.getAttribute('data-invoice-status-label') || row.getAttribute('data-invoice-status') || 'Pending',
        date: row.getAttribute('data-invoice-date') || 'Not set',
        due: row.getAttribute('data-invoice-due') || 'Not set',
        seller: row.getAttribute('data-invoice-seller') || 'Seller',
        subtotal: row.getAttribute('data-invoice-subtotal') || '$0.00',
        discount: row.getAttribute('data-invoice-discount') || '$0.00',
        shipping: row.getAttribute('data-invoice-shipping') || 'Free',
        tax: row.getAttribute('data-invoice-tax') || '$0.00',
        service_fee: row.getAttribute('data-invoice-service-fee') || '$0.00',
        grand: row.getAttribute('data-invoice-total') || '$0.00'
      };
      Object.keys(values).forEach(function (key) {
        Array.prototype.slice.call(panel.querySelectorAll('[data-invoice-field="' + key + '"]')).forEach(function (field) {
          field.textContent = values[key];
        });
      });
      var statusEl = panel.querySelector('[data-invoice-field="status"]');
      if (statusEl) {
        var st = String(row.getAttribute('data-invoice-status') || '').toLowerCase().trim();
        var shipId = parseInt(row.getAttribute('data-ship-detail-id') || '0', 10) || 0;
        var shipCode = String(row.getAttribute('data-ship-detail-code') || '').trim();
        var shipHref = ((st === 'shipped' || st === 'delivered') && shipId > 0)
          ? ('order_detail.php?order_id=' + shipId + (shipCode ? ('&code=' + encodeURIComponent(shipCode)) : ''))
          : '';
        if (shipHref) {
          if (statusEl.tagName !== 'A') {
            var link = document.createElement('a');
            link.className = statusEl.className + (statusEl.className.indexOf('is-ship-link') === -1 ? ' is-ship-link' : '');
            link.setAttribute('data-invoice-field', 'status');
            link.textContent = values.status;
            link.href = shipHref;
            statusEl.parentNode.replaceChild(link, statusEl);
          } else {
            statusEl.href = shipHref;
            statusEl.classList.add('is-ship-link');
          }
        } else if (statusEl.tagName === 'A') {
          var span = document.createElement('span');
          span.className = String(statusEl.className || '').replace(/\bis-ship-link\b/g, '').trim();
          span.setAttribute('data-invoice-field', 'status');
          span.textContent = values.status;
          statusEl.parentNode.replaceChild(span, statusEl);
        }
      }
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
        shipping: row.getAttribute('data-payment-shipping') || 'Free',
        discount: row.getAttribute('data-payment-discount') || '$0.00',
        tax: row.getAttribute('data-payment-tax') || '$0.00',
        service_fee: row.getAttribute('data-payment-service-fee') || '$0.00',
        company: row.getAttribute('data-payment-company') || 'Seller'
      };
      Object.keys(values).forEach(function (key) {
        Array.prototype.slice.call(panel.querySelectorAll('[data-payment-field="' + key + '"]')).forEach(function (field) {
          field.textContent = values[key];
          if (key === 'shipping') {
            field.classList.toggle('shop-payment-free', row.getAttribute('data-payment-shipping-free') === '1');
          }
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

  Array.prototype.slice.call(document.querySelectorAll('#returns-refunds [data-return-row]')).forEach(function (row) {
    row.addEventListener('click', function () {
      var panel = row.closest('[data-shop-pref-panel]');
      if (!panel) return;
      Array.prototype.slice.call(panel.querySelectorAll('[data-return-row]')).forEach(function (item) {
        item.classList.toggle('is-selected', item === row);
      });
      var values = {
        code: row.getAttribute('data-return-code') || 'Return',
        status: row.getAttribute('data-return-status-label') || row.getAttribute('data-return-status') || 'Requested',
        date: row.getAttribute('data-return-date') || 'Not set',
        updated: row.getAttribute('data-return-updated') || 'Not set',
        seller: row.getAttribute('data-return-seller') || 'Seller',
        reason: row.getAttribute('data-return-reason') || '—',
        notes: (row.getAttribute('data-return-notes') || '').trim() || 'None yet',
        subtotal: row.getAttribute('data-return-subtotal') || '$0.00',
        discount: row.getAttribute('data-return-discount') || '$0.00',
        shipping: row.getAttribute('data-return-shipping') || 'Free',
        tax: row.getAttribute('data-return-tax') || '$0.00',
        service_fee: row.getAttribute('data-return-service-fee') || '$0.00',
        grand: row.getAttribute('data-return-total') || '$0.00'
      };
      Object.keys(values).forEach(function (key) {
        Array.prototype.slice.call(panel.querySelectorAll('[data-return-field="' + key + '"]')).forEach(function (field) {
          field.textContent = values[key];
        });
      });
      var orderLink = panel.querySelector('[data-return-field="order-link"]');
      if (orderLink) {
        orderLink.href = row.getAttribute('data-return-order-href') || '#order-history';
      }
      var products = [];
      try {
        products = JSON.parse(row.getAttribute('data-return-products') || '[]') || [];
      } catch (e) {
        products = [];
      }
      fillOrderHistoryProducts(panel.querySelector('[data-return-field="items-list"]'), products, 'Select a return to see products.');

      var email = (row.getAttribute('data-return-contact-email') || '').trim();
      var phone = (row.getAttribute('data-return-contact-phone') || '').trim();
      var address = (row.getAttribute('data-return-contact-address') || '').trim();
      var emailEl = panel.querySelector('[data-return-field="contact-email"]');
      var phoneEl = panel.querySelector('[data-return-field="contact-phone"]');
      var addressEl = panel.querySelector('[data-return-field="contact-address"]');
      var fallbackEl = panel.querySelector('[data-return-field="contact-fallback"]');
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

  Array.prototype.slice.call(document.querySelectorAll('.js-order-history-more, .js-return-history-more')).forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      var wrap = btn.closest('.shop-order-more');
      if (!wrap) return;
      var open = wrap.classList.contains('is-open');
      closeOrderHistoryMoreMenus();
      if (!open) {
        wrap.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        var tableWrap = wrap.closest('.shop-pref-table-wrap');
        if (tableWrap) tableWrap.classList.add('is-more-open');
        positionOrderHistoryMoreMenu(wrap);
        window.requestAnimationFrame(function () {
          positionOrderHistoryMoreMenu(wrap);
        });
      }
    });
  });

  function positionOrderHistoryMoreMenu(wrap) {
    var btn = wrap.querySelector('.js-order-history-more, .js-return-history-more');
    var menu = wrap.querySelector('.shop-order-more-menu');
    if (!btn || !menu) return;
    menu.style.top = '';
    menu.style.right = '';
    menu.style.left = '';
    menu.style.bottom = '';
    var rect = btn.getBoundingClientRect();
    var menuWidth = Math.max(220, menu.offsetWidth || 220);
    var menuHeight = menu.offsetHeight || 0;
    var gap = 4;
    var left = Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8);
    left = Math.max(8, left);
    var top = rect.bottom + gap;
    if (menuHeight > 0 && top + menuHeight > window.innerHeight - 8) {
      top = Math.max(8, rect.top - menuHeight - gap);
    }
    menu.style.top = top + 'px';
    menu.style.left = left + 'px';
    menu.style.right = 'auto';
  }

  function closeOrderHistoryMoreMenus() {
    Array.prototype.slice.call(document.querySelectorAll('.shop-order-more.is-open')).forEach(function (el) {
      el.classList.remove('is-open');
      var b = el.querySelector('.js-order-history-more, .js-return-history-more');
      if (b) b.setAttribute('aria-expanded', 'false');
      var menu = el.querySelector('.shop-order-more-menu');
      if (menu) {
        menu.style.top = '';
        menu.style.left = '';
        menu.style.right = '';
        menu.style.bottom = '';
      }
    });
    Array.prototype.slice.call(document.querySelectorAll('#order-history .shop-pref-table-wrap.is-more-open, #returns-refunds .shop-pref-table-wrap.is-more-open')).forEach(function (el) {
      el.classList.remove('is-more-open');
    });
  }

  document.addEventListener('click', function () {
    closeOrderHistoryMoreMenus();
  });
  window.addEventListener('resize', closeOrderHistoryMoreMenus);
  var orderHistoryTableWrap = document.querySelector('#order-history .shop-pref-table-wrap');
  if (orderHistoryTableWrap) {
    orderHistoryTableWrap.addEventListener('scroll', closeOrderHistoryMoreMenus);
  }
  var returnsTableWrap = document.querySelector('#returns-refunds .shop-pref-table-wrap');
  if (returnsTableWrap) {
    returnsTableWrap.addEventListener('scroll', closeOrderHistoryMoreMenus);
  }

  var returnDialog = document.getElementById('shopOrderReturnDialog');
  var returnIcon = document.getElementById('shopOrderReturnIcon');
  var returnTitle = document.getElementById('shopOrderReturnTitle');
  var returnBody = document.getElementById('shopOrderReturnBody');
  var returnFormBlock = document.getElementById('shopOrderReturnFormBlock');
  var returnResultBlock = document.getElementById('shopOrderReturnResultBlock');
  var returnReasonInput = document.getElementById('shopOrderReturnReason');
  var returnConfirmBtn = document.getElementById('shopOrderReturnConfirm');
  var returnResultOk = document.getElementById('shopOrderReturnResultOk');
  var returnPendingOrderId = 0;

  function ensureReturnDialogOnBody() {
    if (returnDialog && returnDialog.parentElement !== document.body) {
      document.body.appendChild(returnDialog);
    }
  }

  function resetOrderReturnDialogForm() {
    returnPendingOrderId = 0;
    if (returnDialog) {
      returnDialog.classList.remove('is-result', 'is-error');
    }
    if (returnIcon) returnIcon.innerHTML = '<i class="fa fa-undo"></i>';
    if (returnTitle) returnTitle.textContent = 'Request a return?';
    if (returnBody) {
      returnBody.textContent = 'Tell us why you’re returning this item. The seller will review your request.';
    }
    if (returnFormBlock) returnFormBlock.hidden = false;
    if (returnResultBlock) returnResultBlock.hidden = true;
    if (returnReasonInput) {
      returnReasonInput.value = '';
      returnReasonInput.style.borderColor = '';
    }
    if (returnConfirmBtn) {
      returnConfirmBtn.disabled = false;
      returnConfirmBtn.textContent = 'Request return';
    }
  }

  function closeOrderReturnDialog() {
    resetOrderReturnDialogForm();
    if (returnDialog && typeof returnDialog.close === 'function') {
      try { returnDialog.close(); } catch (e) {}
    } else if (returnDialog) {
      returnDialog.removeAttribute('open');
    }
  }

  function showOrderReturnResult(ok, message) {
    var isOk = !!ok;
    var msg = String(message || '').trim();
    if (returnDialog) {
      returnDialog.classList.add('is-result');
      returnDialog.classList.toggle('is-error', !isOk);
    }
    if (returnIcon) {
      returnIcon.innerHTML = isOk ? '<i class="fa fa-check"></i>' : '<i class="fa fa-exclamation"></i>';
    }
    if (returnTitle) {
      returnTitle.textContent = isOk ? 'Return request submitted' : 'Return request failed';
    }
    if (returnBody) {
      returnBody.textContent = msg || (isOk
        ? 'The seller will review it.'
        : 'Something went wrong. Please try again.');
    }
    if (returnFormBlock) returnFormBlock.hidden = true;
    if (returnResultBlock) returnResultBlock.hidden = false;
    window.setTimeout(function () {
      if (returnResultOk) returnResultOk.focus();
    }, 30);
  }

  function openOrderReturnDialog(orderId) {
    ensureReturnDialogOnBody();
    resetOrderReturnDialogForm();
    returnPendingOrderId = orderId;
    if (returnDialog && typeof returnDialog.showModal === 'function') {
      try { returnDialog.showModal(); } catch (e) { returnDialog.setAttribute('open', ''); }
    } else if (returnDialog) {
      returnDialog.setAttribute('open', '');
    }
    window.setTimeout(function () {
      if (returnReasonInput) returnReasonInput.focus();
    }, 30);
  }

  Array.prototype.slice.call(document.querySelectorAll('[data-close-order-return]')).forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      closeOrderReturnDialog();
    });
  });
  if (returnDialog) {
    returnDialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      closeOrderReturnDialog();
    });
    returnDialog.addEventListener('click', function (event) {
      if (event.target === returnDialog) closeOrderReturnDialog();
    });
  }
  if (returnConfirmBtn) {
    returnConfirmBtn.addEventListener('click', async function () {
      var orderId = returnPendingOrderId;
      var reason = returnReasonInput ? String(returnReasonInput.value || '').trim() : '';
      if (!orderId) return;
      if (!reason) {
        if (returnReasonInput) {
          returnReasonInput.focus();
          returnReasonInput.style.borderColor = '#dc2626';
        }
        return;
      }
      if (returnReasonInput) returnReasonInput.style.borderColor = '';
      returnConfirmBtn.disabled = true;
      returnConfirmBtn.textContent = 'Submitting…';
      try {
        var body = new URLSearchParams();
        body.set('order_id', String(orderId));
        body.set('reason', reason);
        var res = await fetch('ajax/order_return.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        var ok = !!(data && data.ok);
        var message = '';
        if (ok) {
          message = 'The seller will review it.';
        } else {
          message = (data && data.message) || 'Return request failed.';
        }
        showOrderReturnResult(ok, message);
      } catch (e) {
        returnConfirmBtn.disabled = false;
        returnConfirmBtn.textContent = 'Request return';
        showOrderReturnResult(false, 'Return request failed.');
      }
    });
  }

  Array.prototype.slice.call(document.querySelectorAll('.js-order-history-return')).forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      closeOrderHistoryMoreMenus();
      var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10) || 0;
      if (!orderId) return;
      openOrderReturnDialog(orderId);
    });
  });

  var reviewDialog = document.getElementById('shopOrderReviewDialog');
  var reviewIcon = document.getElementById('shopOrderReviewIcon');
  var reviewTitle = document.getElementById('shopOrderReviewTitle');
  var reviewBody = document.getElementById('shopOrderReviewBody');
  var reviewFormBlock = document.getElementById('shopOrderReviewFormBlock');
  var reviewResultBlock = document.getElementById('shopOrderReviewResultBlock');
  var reviewTextInput = document.getElementById('shopOrderReviewText');
  var reviewConfirmBtn = document.getElementById('shopOrderReviewConfirm');
  var reviewResultOk = document.getElementById('shopOrderReviewResultOk');
  var reviewStars = Array.prototype.slice.call(document.querySelectorAll('#shopOrderReviewStars .shop-order-review-star'));
  var reviewPendingOrderId = 0;
  var reviewSelectedRating = 5;

  function ensureReviewDialogOnBody() {
    if (reviewDialog && reviewDialog.parentElement !== document.body) {
      document.body.appendChild(reviewDialog);
    }
  }

  function setReviewStars(rating) {
    reviewSelectedRating = rating;
    reviewStars.forEach(function (starBtn) {
      var value = parseInt(starBtn.getAttribute('data-rating') || '0', 10) || 0;
      var on = value <= rating;
      starBtn.classList.toggle('is-on', on);
      starBtn.setAttribute('aria-checked', value === rating ? 'true' : 'false');
    });
  }

  function resetOrderReviewDialogForm() {
    reviewPendingOrderId = 0;
    if (reviewDialog) {
      reviewDialog.classList.remove('is-result', 'is-error');
    }
    if (reviewIcon) reviewIcon.innerHTML = '<i class="fa fa-star"></i>';
    if (reviewTitle) reviewTitle.textContent = 'Leave a review?';
    if (reviewBody) {
      reviewBody.textContent = 'Share your experience with this order. Your rating helps other buyers.';
    }
    if (reviewFormBlock) reviewFormBlock.hidden = false;
    if (reviewResultBlock) reviewResultBlock.hidden = true;
    if (reviewTextInput) reviewTextInput.value = '';
    setReviewStars(5);
    if (reviewConfirmBtn) {
      reviewConfirmBtn.disabled = false;
      reviewConfirmBtn.textContent = 'Submit review';
    }
  }

  function closeOrderReviewDialog() {
    resetOrderReviewDialogForm();
    if (reviewDialog && typeof reviewDialog.close === 'function') {
      try { reviewDialog.close(); } catch (e) {}
    } else if (reviewDialog) {
      reviewDialog.removeAttribute('open');
    }
  }

  function showOrderReviewResult(ok, message) {
    var isOk = !!ok;
    var msg = String(message || '').trim();
    if (reviewDialog) {
      reviewDialog.classList.add('is-result');
      reviewDialog.classList.toggle('is-error', !isOk);
    }
    if (reviewIcon) {
      reviewIcon.innerHTML = isOk ? '<i class="fa fa-check"></i>' : '<i class="fa fa-exclamation"></i>';
    }
    if (reviewTitle) {
      reviewTitle.textContent = isOk ? 'Review submitted' : 'Could not save review';
    }
    if (reviewBody) {
      reviewBody.textContent = msg || (isOk
        ? 'Thank you for your review.'
        : 'Something went wrong. Please try again.');
    }
    if (reviewFormBlock) reviewFormBlock.hidden = true;
    if (reviewResultBlock) reviewResultBlock.hidden = false;
    window.setTimeout(function () {
      if (reviewResultOk) reviewResultOk.focus();
    }, 30);
  }

  function openOrderReviewDialog(orderId) {
    ensureReviewDialogOnBody();
    resetOrderReviewDialogForm();
    reviewPendingOrderId = orderId;
    if (reviewDialog && typeof reviewDialog.showModal === 'function') {
      try { reviewDialog.showModal(); } catch (e) { reviewDialog.setAttribute('open', ''); }
    } else if (reviewDialog) {
      reviewDialog.setAttribute('open', '');
    }
    window.setTimeout(function () {
      if (reviewTextInput) reviewTextInput.focus();
    }, 30);
  }

  reviewStars.forEach(function (starBtn) {
    starBtn.addEventListener('click', function (event) {
      event.preventDefault();
      var rating = parseInt(starBtn.getAttribute('data-rating') || '0', 10) || 0;
      if (rating >= 1 && rating <= 5) setReviewStars(rating);
    });
  });

  Array.prototype.slice.call(document.querySelectorAll('[data-close-order-review]')).forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      closeOrderReviewDialog();
    });
  });
  if (reviewDialog) {
    reviewDialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      closeOrderReviewDialog();
    });
    reviewDialog.addEventListener('click', function (event) {
      if (event.target === reviewDialog) closeOrderReviewDialog();
    });
  }
  if (reviewConfirmBtn) {
    reviewConfirmBtn.addEventListener('click', async function () {
      var orderId = reviewPendingOrderId;
      var rating = reviewSelectedRating;
      var reviewText = reviewTextInput ? String(reviewTextInput.value || '').trim() : '';
      if (!orderId) return;
      if (!(rating >= 1 && rating <= 5)) {
        setReviewStars(5);
        return;
      }
      reviewConfirmBtn.disabled = true;
      reviewConfirmBtn.textContent = 'Submitting…';
      try {
        var body = new URLSearchParams();
        body.set('order_id', String(orderId));
        body.set('rating', String(rating));
        body.set('review_text', reviewText);
        var res = await fetch('ajax/product_review.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        var ok = !!(data && data.ok);
        var message = ok
          ? ((data && data.message) || 'Thank you for your review.')
          : ((data && data.message) || 'Could not save review.');
        showOrderReviewResult(ok, message);
      } catch (e) {
        reviewConfirmBtn.disabled = false;
        reviewConfirmBtn.textContent = 'Submit review';
        showOrderReviewResult(false, 'Could not save review.');
      }
    });
  }

  Array.prototype.slice.call(document.querySelectorAll('.js-order-history-review')).forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      closeOrderHistoryMoreMenus();
      var orderId = parseInt(btn.getAttribute('data-order-id') || '0', 10) || 0;
      if (!orderId) return;
      openOrderReviewDialog(orderId);
    });
  });

  Array.prototype.slice.call(document.querySelectorAll('.js-order-history-cancel')).forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      closeOrderHistoryMoreMenus();
      var idsRaw = String(btn.getAttribute('data-order-ids') || '');
      var ids = idsRaw.split(',').map(function (v) { return parseInt(v, 10); }).filter(function (n) { return n > 0; });
      if (!ids.length) return;
      openOrderCancelDialog(ids, btn);
    });
  });

  var cancelDialog = document.getElementById('shopOrderCancelDialog');
  var cancelIcon = document.getElementById('shopOrderCancelIcon');
  var cancelTitle = document.getElementById('shopOrderCancelTitle');
  var cancelBody = document.getElementById('shopOrderCancelBody');
  var cancelFormBlock = document.getElementById('shopOrderCancelFormBlock');
  var cancelResultBlock = document.getElementById('shopOrderCancelResultBlock');
  var cancelReasonInput = document.getElementById('shopOrderCancelReason');
  var cancelConfirmBtn = document.getElementById('shopOrderCancelConfirm');
  var cancelResultOk = document.getElementById('shopOrderCancelResultOk');
  var cancelPendingIds = [];
  var cancelTriggerBtn = null;

  function ensureCancelDialogOnBody() {
    if (cancelDialog && cancelDialog.parentElement !== document.body) {
      document.body.appendChild(cancelDialog);
    }
  }

  function resetOrderCancelDialogForm() {
    cancelPendingIds = [];
    cancelTriggerBtn = null;
    if (cancelDialog) {
      cancelDialog.classList.remove('is-result', 'is-error');
    }
    if (cancelIcon) cancelIcon.innerHTML = '<i class="fa fa-ban"></i>';
    if (cancelTitle) cancelTitle.textContent = 'Cancel this order?';
    if (cancelBody) {
      cancelBody.textContent = 'It will be removed from your history and from the seller’s order list.';
    }
    if (cancelFormBlock) cancelFormBlock.hidden = false;
    if (cancelResultBlock) cancelResultBlock.hidden = true;
    if (cancelReasonInput) {
      cancelReasonInput.value = '';
      cancelReasonInput.style.borderColor = '';
    }
    if (cancelConfirmBtn) {
      cancelConfirmBtn.disabled = false;
      cancelConfirmBtn.textContent = 'Cancel order';
    }
  }

  function closeOrderCancelDialog() {
    var shouldReload = cancelDialog && cancelDialog.classList.contains('is-result') && !cancelDialog.classList.contains('is-error');
    resetOrderCancelDialogForm();
    if (cancelDialog && typeof cancelDialog.close === 'function') {
      try { cancelDialog.close(); } catch (e) {}
    } else if (cancelDialog) {
      cancelDialog.removeAttribute('open');
    }
    if (shouldReload) {
      window.location.hash = 'order-history';
      window.location.reload();
    }
  }

  function showOrderCancelResult(ok, message) {
    var isOk = !!ok;
    var msg = String(message || '').trim();
    if (cancelDialog) {
      cancelDialog.classList.add('is-result');
      cancelDialog.classList.toggle('is-error', !isOk);
    }
    if (cancelIcon) {
      cancelIcon.innerHTML = isOk ? '<i class="fa fa-check"></i>' : '<i class="fa fa-exclamation"></i>';
    }
    if (cancelTitle) {
      cancelTitle.textContent = isOk ? 'Order cancelled' : 'Could not cancel';
    }
    if (cancelBody) {
      cancelBody.textContent = msg || (isOk
        ? 'This order was removed from your history and the seller’s list.'
        : 'Something went wrong. Please try again.');
    }
    if (cancelFormBlock) cancelFormBlock.hidden = true;
    if (cancelResultBlock) cancelResultBlock.hidden = false;
    window.setTimeout(function () {
      if (cancelResultOk) cancelResultOk.focus();
    }, 30);
  }

  function openOrderCancelDialog(ids, triggerBtn) {
    ensureCancelDialogOnBody();
    resetOrderCancelDialogForm();
    cancelPendingIds = Array.isArray(ids) ? ids.slice() : [];
    cancelTriggerBtn = triggerBtn || null;
    if (!cancelDialog || !cancelPendingIds.length) return;
    if (typeof cancelDialog.showModal === 'function') {
      try { cancelDialog.showModal(); } catch (e) { cancelDialog.setAttribute('open', ''); }
    } else {
      cancelDialog.setAttribute('open', '');
    }
    window.setTimeout(function () {
      if (cancelReasonInput) cancelReasonInput.focus();
    }, 30);
  }

  async function submitOrderCancel() {
    if (!cancelPendingIds.length) return;
    var reason = cancelReasonInput ? String(cancelReasonInput.value || '').trim() : '';
    if (reason === '') reason = 'Changed mind';
    if (cancelConfirmBtn) {
      cancelConfirmBtn.disabled = true;
      cancelConfirmBtn.textContent = 'Cancelling…';
    }
    if (cancelTriggerBtn) cancelTriggerBtn.disabled = true;
      var ok = true;
      var message = '';
    for (var i = 0; i < cancelPendingIds.length; i++) {
        var body = new URLSearchParams();
      body.set('order_id', String(cancelPendingIds[i]));
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
      if (cancelTriggerBtn) cancelTriggerBtn.disabled = false;
      if (cancelConfirmBtn) {
        cancelConfirmBtn.disabled = false;
        cancelConfirmBtn.textContent = 'Cancel order';
      }
      showOrderCancelResult(false, message);
        return;
      }
    var row = cancelTriggerBtn ? cancelTriggerBtn.closest('tr') : null;
      if (row) row.remove();
      var tbody = document.querySelector('#order-history .shop-pref-table tbody');
      if (tbody && !tbody.querySelector('[data-invoice-order]')) {
      tbody.innerHTML = '<tr><td colspan="8" class="shop-pref-table-empty">No orders yet.</td></tr>';
    }
    showOrderCancelResult(true, message);
  }

  if (cancelConfirmBtn) {
    cancelConfirmBtn.addEventListener('click', function (event) {
      event.preventDefault();
      submitOrderCancel();
    });
  }
  Array.prototype.slice.call(document.querySelectorAll('[data-close-order-cancel]')).forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      closeOrderCancelDialog();
    });
  });
  if (cancelDialog) {
    cancelDialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      closeOrderCancelDialog();
    });
  }

  Array.prototype.slice.call(document.querySelectorAll('[data-print-invoice]')).forEach(function (button) {
    button.addEventListener('click', function (e) {
      if (e && typeof e.preventDefault === 'function') e.preventDefault();
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

  async function shopRemoveSellerContact(publisherId, rowEl) {
    publisherId = parseInt(publisherId || 0, 10);
    if (!publisherId) return;
    if (!window.confirm('Remove this seller from your contact list? You can message them again from a product later.')) return;
    try {
      var body = new URLSearchParams();
      body.set('action', 'remove');
      body.set('publisher_user_id', String(publisherId));
      var res = await fetch('ajax/shop_seller_contact_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      });
      var data = await res.json();
      if (!data || !data.ok) {
        window.alert((data && data.message) || 'Could not remove seller contact.');
        return;
      }
      if (rowEl && rowEl.parentNode) rowEl.parentNode.removeChild(rowEl);
    } catch (e) {
      window.alert('Could not remove seller contact.');
    }
  }
  Array.prototype.slice.call(document.querySelectorAll('.shop-seller-msg-remove')).forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      var row = btn.closest('[data-publisher-id]') || btn.closest('.shop-seller-msg-item-row');
      shopRemoveSellerContact(btn.getAttribute('data-publisher-id'), row);
    });
  });

  /* Seller product chat (Shopping Preferences — not messages.php) */
  (function () {
    var root = document.getElementById('shopSellerMsgRoot');
    if (!root) return;
    var chatEl = root.querySelector('.shop-seller-msg-chat');
    var thread = document.getElementById('shopSellerMsgThread');
    var input = document.getElementById('shopSellerMsgInput');
    var sendBtn = document.getElementById('shopSellerMsgSend');
    var errEl = document.getElementById('shopSellerMsgErr');
    var searchEl = document.getElementById('shopSellerMsgSearch');
    var composeWrap = root.querySelector('.shop-seller-msg-compose-wrap');
    var moreWrap = document.getElementById('shopSellerMsgMore');
    var moreBtn = document.getElementById('shopSellerMsgMoreBtn');
    var historyList = document.getElementById('shopSellerMsgHistoryList');
    var historyBar = document.getElementById('shopSellerMsgHistoryBar');
    var historyBack = document.getElementById('shopSellerMsgHistoryBack');
    var historyTitle = document.getElementById('shopSellerMsgHistoryTitle');
    var peer = String(root.getAttribute('data-peer') || '').trim().toUpperCase();
    var peerName = String(root.getAttribute('data-peer-name') || 'Seller');
    var peerAvatar = String(root.getAttribute('data-peer-avatar') || '');
    var draft = String(root.getAttribute('data-draft') || '');
    var aboutProduct = parseInt(root.getAttribute('data-about-product') || '0', 10) || 0;
    var lastId = 0;
    var polling = false;
    var viewingHistory = false;
    var viewingPid = 0;
    var allItems = [];
    var productCatalog = {};
    var productFocus = null;
    (function bootFocus() {
      var card = document.getElementById('shopSellerMsgProduct');
      if (!card || card.hasAttribute('hidden')) return;
      var id = parseInt(card.getAttribute('data-product-id') || '0', 10) || aboutProduct;
      if (id <= 0) return;
      productFocus = {
        id: id,
        title: String(card.getAttribute('data-product-title') || ('Product #' + id)),
        code: String(card.getAttribute('data-product-code') || ''),
        price: String(card.getAttribute('data-product-price') || ''),
        cover: String(card.getAttribute('data-product-cover') || ''),
        buyer_href: String(card.getAttribute('data-product-href') || ('product_detail.php?id=' + id))
      };
      productCatalog[id] = productFocus;
    })();

    function setErr(msg) {
      if (!errEl) return;
      if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
      errEl.hidden = false;
      errEl.textContent = msg;
    }
    function esc(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function shortTime(label) {
      var s = String(label || '').trim();
      if (!s) return '';
      var m = s.match(/(\d{1,2}:\d{2}\s*[AP]M)/i);
      return m ? m[1] : s;
    }
    function parseProductIdFromText(text) {
      var s = String(text || '');
      var m = s.match(/Product\s*ID\s*#\s*(\d+)/i) || s.match(/\bproduct\s*#\s*(\d+)/i) || s.match(/\(product\s*#\s*(\d+)\)/i);
      return m ? (parseInt(m[1], 10) || 0) : 0;
    }
    function isProductFocusLine(text) {
      var s = String(text || '').trim();
      if (!s) return false;
      if (/^Product\s*ID\s*#\s*\d+/i.test(s) && s.length < 220) return true;
      if (/^Regarding product/i.test(s) && s.length < 220) return true;
      return false;
    }
    function closeMoreMenu() {
      if (moreWrap) moreWrap.classList.remove('is-open');
      if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
    }
    function renderProductCard(focus) {
      var card = document.getElementById('shopSellerMsgProduct');
      if (!card) return;
      var id = focus && parseInt(focus.id || 0, 10) > 0 ? parseInt(focus.id, 10) : 0;
      if (!id) {
        card.hidden = true;
        card.innerHTML = '';
        if (!viewingHistory) {
          root.setAttribute('data-about-product', '0');
          aboutProduct = 0;
          productFocus = null;
        }
        return;
      }
      productFocus = focus;
      productCatalog[id] = Object.assign({}, productCatalog[id] || {}, focus);
      if (!viewingHistory) {
        aboutProduct = id;
        root.setAttribute('data-about-product', String(id));
      }
      var title = String(focus.title || ('Product #' + id));
      var code = String(focus.code || '');
      var idLabel = 'Product ID #' + id + (code ? (' · ' + code) : '');
      var price = String(focus.price || '');
      var cover = String(focus.cover || '');
      var href = String(focus.buyer_href || ('product_detail.php?id=' + id));
      card.hidden = false;
      card.setAttribute('data-product-id', String(id));
      card.setAttribute('data-product-title', title);
      card.setAttribute('data-product-code', code);
      card.setAttribute('data-product-price', price);
      card.setAttribute('data-product-cover', cover);
      card.setAttribute('data-product-href', href);
      card.innerHTML =
        (cover
          ? '<img src="' + esc(cover) + '" alt="" id="shopSellerMsgProductImg">'
          : '<img src="avatar.php?name=' + encodeURIComponent(title) + '" alt="" id="shopSellerMsgProductImg">') +
        '<div>' +
          '<strong id="shopSellerMsgProductTitle">' + esc(title) + '</strong>' +
          '<span class="shop-seller-msg-product-id" id="shopSellerMsgProductId">' + esc(idLabel) + '</span>' +
          (price ? '<span class="shop-seller-msg-product-price" id="shopSellerMsgProductPrice">' + esc(price) + '</span>' : '') +
        '</div>' +
        '<a href="' + esc(href) + '" id="shopSellerMsgProductLink">View item</a>';
    }
    function focusFromMarkerText(text) {
      var pid = parseProductIdFromText(text);
      if (pid <= 0) return null;
      var title = 'Product #' + pid;
      var code = '';
      var dash = String(text || '').split('—');
      if (dash.length > 1) title = dash.slice(1).join('—').trim() || title;
      var codeMatch = String(text || '').match(/\bPRD-[0-9A-Z]+\b/i);
      if (codeMatch) code = codeMatch[0];
      return { id: pid, title: title, code: code, price: '', cover: '', buyer_href: 'product_detail.php?id=' + pid };
    }
    function bubbleHtml(item) {
      var text = String(item.text || '');
      var isMe = !!item.is_me;
      if (isProductFocusLine(text)) {
        var focus = focusFromMarkerText(text);
        var pid = focus ? focus.id : 0;
        var title = focus ? focus.title : text;
        var code = focus ? focus.code : '';
        var known = productCatalog[pid] || ((productFocus && parseInt(productFocus.id || 0, 10) === pid) ? productFocus : null);
        if (known) {
          title = String(known.title || title);
          code = String(known.code || code);
        }
        var cover = known ? String(known.cover || '') : '';
        var idLabel = 'Product ID #' + pid + (code ? (' · ' + code) : '');
        return '<div class="shop-seller-msg-bubble ' + (isMe ? 'me' : 'them') + ' is-product-ref">' +
          (cover ? '<img src="' + esc(cover) + '" alt="">' : '<img alt="" style="background:rgba(148,163,184,.25)">') +
          '<div><span class="shop-seller-msg-bubble-prod-title">' + esc(title) + '</span>' +
          '<span class="shop-seller-msg-bubble-prod-id">' + esc(idLabel) + '</span></div></div>';
      }
      return '<div class="shop-seller-msg-bubble ' + (isMe ? 'me' : 'them') + '">' + esc(text) + '</div>';
    }
    function assignProductContexts(items) {
      var running = 0;
      return (items || []).map(function (item) {
        var copy = Object.assign({}, item);
        var pid = parseProductIdFromText(copy.text || '');
        if (pid > 0) {
          running = pid;
          var focus = focusFromMarkerText(copy.text || '');
          if (focus) {
            productCatalog[pid] = Object.assign({}, productCatalog[pid] || {}, focus);
          }
        }
        copy._product_id = running;
        return copy;
      });
    }
    function syncFocusFromItems(items) {
      if (viewingHistory) return;
      var list = items || [];
      for (var i = list.length - 1; i >= 0; i--) {
        if (!isProductFocusLine(list[i] && list[i].text)) continue;
        var focus = focusFromMarkerText(list[i].text);
        if (!focus || focus.id <= 0) continue;
        var known = productCatalog[focus.id] || {};
        renderProductCard(Object.assign({}, focus, known, { id: focus.id }));
        return;
      }
    }
    function paintThread(items) {
      if (!thread) return;
      thread.innerHTML = '';
      var list = items || [];
      if (!list.length) {
        thread.innerHTML = '<div class="shop-seller-msg-empty">' +
          (viewingHistory
            ? ('No messages saved for Product ID #' + (viewingPid || '?') + '.')
            : 'No messages yet. Ask about the product, stock, pickup, or delivery.') +
          '</div>';
        return;
      }
      list.forEach(function (item) {
        var id = parseInt(item.id || 0, 10);
        if (!viewingHistory && id > lastId) lastId = id;
        var isMe = !!item.is_me;
        var row = document.createElement('div');
        row.className = 'shop-seller-msg-row ' + (isMe ? 'me' : 'them');
        var pid = parseInt(item._product_id || 0, 10) || 0;
        if (pid > 0) row.setAttribute('data-product-id', String(pid));
        var avaHtml = isMe ? '' : ('<img class="shop-seller-msg-row-ava" src="' + esc(peerAvatar) + '" alt="">');
        var checks = isMe ? ' <i class="fa fa-check-double" aria-hidden="true"></i>' : '';
        row.innerHTML =
          avaHtml +
          '<div class="shop-seller-msg-bubble-wrap">' +
            bubbleHtml(item) +
            '<div class="shop-seller-msg-meta">' + esc(shortTime(item.time_label || '')) + checks + '</div>' +
          '</div>';
        thread.appendChild(row);
      });
      thread.scrollTop = thread.scrollHeight;
    }
    function appendItems(items, replace) {
      if (!thread) return;
      var incoming = assignProductContexts(items || []);
      if (replace) {
        allItems = incoming.slice();
        lastId = 0;
      } else {
        incoming.forEach(function (item) {
          var id = parseInt(item.id || 0, 10);
          if (id > 0 && allItems.some(function (x) { return parseInt(x.id || 0, 10) === id; })) return;
          // Carry forward last product context from allItems
          if (!(parseInt(item._product_id || 0, 10) > 0) && allItems.length) {
            var prev = allItems[allItems.length - 1];
            item._product_id = parseInt(prev._product_id || 0, 10) || 0;
          }
          allItems.push(item);
        });
      }
      allItems = assignProductContexts(allItems);
      syncFocusFromItems(allItems);
      if (viewingHistory && viewingPid > 0) {
        paintThread(allItems.filter(function (it) {
          return parseInt(it._product_id || 0, 10) === viewingPid;
        }));
      } else {
        paintThread(allItems);
      }
    }
    function buildHistoryRows() {
      var byPid = {};
      allItems.forEach(function (item) {
        var pid = parseInt(item._product_id || 0, 10) || 0;
        if (pid <= 0) return;
        if (!byPid[pid]) {
          var known = productCatalog[pid] || {};
          var fromText = isProductFocusLine(item.text) ? focusFromMarkerText(item.text) : null;
          byPid[pid] = Object.assign({
            id: pid,
            title: 'Product #' + pid,
            code: '',
            cover: '',
            price: '',
            buyer_href: 'product_detail.php?id=' + pid,
            time_label: item.time_label || ''
          }, fromText || {}, known, { id: pid });
        } else if (item.time_label) {
          byPid[pid].time_label = item.time_label;
        }
      });
      if (productFocus && parseInt(productFocus.id || 0, 10) > 0) {
        var fid = parseInt(productFocus.id, 10);
        byPid[fid] = Object.assign({}, byPid[fid] || { id: fid }, productFocus, { id: fid });
      }
      return Object.keys(byPid).map(function (k) { return byPid[k]; }).sort(function (a, b) {
        return (parseInt(b.id || 0, 10) || 0) - (parseInt(a.id || 0, 10) || 0);
      });
    }
    function renderHistoryList() {
      if (!historyList) return;
      var rows = buildHistoryRows();
      if (!rows.length) {
        historyList.innerHTML = '<div class="shop-seller-msg-history-empty">No product history yet.</div>';
        return;
      }
      historyList.innerHTML = '';
      rows.forEach(function (c) {
        var pid = parseInt(c.id || 0, 10) || 0;
        if (pid <= 0) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'shop-seller-msg-history-item' + (viewingPid === pid ? ' is-active' : '');
        btn.setAttribute('role', 'menuitem');
        var title = String(c.title || ('Product #' + pid));
        var cover = String(c.cover || '');
        var img = cover || ('avatar.php?name=' + encodeURIComponent(title));
        var meta = [title, c.time_label || ''].filter(Boolean).join(' · ');
        btn.innerHTML =
          '<img src="' + esc(img) + '" alt="">' +
          '<span>' +
            '<span class="shop-seller-msg-history-item-label">' + esc('Product ID #' + pid + ' history') + '</span>' +
            (meta ? '<span class="shop-seller-msg-history-item-meta">' + esc(meta) + '</span>' : '') +
          '</span>';
        btn.addEventListener('click', function () {
          openProductHistory(pid, c);
        });
        historyList.appendChild(btn);
      });
    }
    async function enrichProduct(pid) {
      pid = parseInt(pid || 0, 10) || 0;
      if (pid <= 0) return productCatalog[pid] || null;
      if (productCatalog[pid] && productCatalog[pid].cover) return productCatalog[pid];
      try {
        var res = await fetch('ajax/product_detail.php?id=' + encodeURIComponent(String(pid)), { credentials: 'same-origin' });
        var data = await res.json();
        if (!data || !data.ok) return productCatalog[pid] || null;
        var p = data.product || data;
        var cover = '';
        if (Array.isArray(p.gallery) && p.gallery.length) cover = String(p.gallery[0] || '');
        else cover = String(p.cover || p.cover_url || '');
        var price = '';
        if (p.price_label) price = String(p.price_label);
        else if (typeof p.price_cents !== 'undefined') {
          var cents = parseInt(p.price_cents || 0, 10) || 0;
          price = '$' + (cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        var focus = {
          id: pid,
          title: String(p.title || p.name || ('Product #' + pid)),
          code: String(p.product_code || p.code || ''),
          price: price,
          cover: cover,
          buyer_href: 'product_detail.php?id=' + pid
        };
        productCatalog[pid] = Object.assign({}, productCatalog[pid] || {}, focus);
        return productCatalog[pid];
      } catch (e) {
        return productCatalog[pid] || null;
      }
    }
    async function openProductHistory(pid, meta) {
      pid = parseInt(pid || 0, 10) || 0;
      if (pid <= 0) return;
      closeMoreMenu();
      viewingHistory = true;
      viewingPid = pid;
      if (chatEl) chatEl.classList.add('is-history-mode');
      if (historyBar) historyBar.classList.add('is-open');
      if (historyTitle) historyTitle.textContent = 'Product ID #' + pid + ' history';
      if (composeWrap) composeWrap.hidden = true;
      var focus = Object.assign({}, productCatalog[pid] || {}, meta || {}, { id: pid });
      renderProductCard(focus);
      paintThread(allItems.filter(function (it) {
        return parseInt(it._product_id || 0, 10) === pid;
      }));
      var enriched = await enrichProduct(pid);
      if (enriched && viewingPid === pid) {
        renderProductCard(Object.assign({}, focus, enriched, { id: pid }));
        renderHistoryList();
      }
    }
    function exitHistoryView() {
      viewingHistory = false;
      viewingPid = 0;
      if (chatEl) chatEl.classList.remove('is-history-mode');
      if (historyBar) historyBar.classList.remove('is-open');
      if (composeWrap) composeWrap.hidden = false;
      syncFocusFromItems(allItems);
      paintThread(allItems);
    }
    async function loadHistory() {
      if (!peer) return;
      try {
        var res = await fetch('ajax/user_chat_poll.php?peer=' + encodeURIComponent(peer) + '&after=0&wait=0&mark=1', { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok) {
          lastId = 0;
          appendItems(data.items || [], true);
          if (!(data.items || []).length && !viewingHistory) {
            thread.innerHTML = '<div class="shop-seller-msg-empty">No messages yet. Ask about the product, stock, pickup, or delivery.</div>';
          }
        }
      } catch (e) { /* ignore */ }
    }
    async function pollNew() {
      if (!peer || polling || viewingHistory) return;
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
      if (viewingHistory) return;
      if (!peer) { setErr('Select a seller first.'); return; }
      var text = input ? String(input.value || '').trim() : '';
      if (!text) { setErr('Type a message.'); return; }
      if (sendBtn) sendBtn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('to', peer);
        body.set('message', text);
        if (aboutProduct > 0) {
          body.set('about_product', String(aboutProduct));
          body.set('product_id', String(aboutProduct));
        }
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
        root.setAttribute('data-pending-first', '0');
        var activeRow = document.querySelector('.shop-seller-msg-item-row.is-active');
        if (activeRow) {
          var preview = activeRow.querySelector('.shop-seller-msg-item-preview');
          if (preview) preview.textContent = text.length > 80 ? (text.slice(0, 80) + '…') : text;
        }
        await pollNew();
      } catch (e) {
        setErr('Could not send message.');
      } finally {
        if (sendBtn) sendBtn.disabled = false;
      }
    }

    if (moreBtn && moreWrap) {
      moreBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = !moreWrap.classList.contains('is-open');
        moreWrap.classList.toggle('is-open', open);
        moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) renderHistoryList();
      });
    }
    if (historyBack) {
      historyBack.addEventListener('click', function () {
        exitHistoryView();
      });
    }
    document.addEventListener('click', function (e) {
      if (!moreWrap || !moreWrap.classList.contains('is-open')) return;
      if (moreWrap.contains(e.target)) return;
      closeMoreMenu();
    });

    if (searchEl) {
      searchEl.addEventListener('input', function () {
        var q = String(searchEl.value || '').trim().toLowerCase();
        Array.prototype.slice.call(document.querySelectorAll('#shopSellerMsgList .shop-seller-msg-item-row')).forEach(function (row) {
          var hay = String(row.getAttribute('data-search') || '');
          row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
        });
      });
    }

    if (input && draft && !String(input.value || '').trim()) {
      if (!/^Product\s*ID\s*#/i.test(String(draft).trim())) {
      input.value = draft;
      }
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
    var composeEl = document.getElementById('shopAdminSupportCompose');
    var lockedEl = document.getElementById('shopAdminSupportLocked');
    var chatEl = root.querySelector('.shop-admin-support-chat');
    var productEl = document.getElementById('shopAdminSupportProduct');
    var moreWrap = document.getElementById('shopAdminSupportMore');
    var moreBtn = document.getElementById('shopAdminSupportMoreBtn');
    var historyList = document.getElementById('shopAdminSupportHistoryList');
    var historyPanel = document.getElementById('shopAdminSupportHistoryPanel');
    var historyBack = document.getElementById('shopAdminSupportHistoryBack');
    var historyTitle = document.getElementById('shopAdminSupportHistoryTitle');
    var historyProduct = document.getElementById('shopAdminSupportHistoryProduct');
    var historyThread = document.getElementById('shopAdminSupportHistoryThread');
    var topicBtns = Array.prototype.slice.call(root.querySelectorAll('.shop-admin-topic'));
    var topic = String(root.getAttribute('data-topic') || 'dispute');
    if (topic !== 'help' && topic !== 'dispute') topic = 'dispute';
    var aboutProduct = parseInt(root.getAttribute('data-about-product') || '0', 10) || 0;
    var draft = String(root.getAttribute('data-draft') || '');
    var caseOpen = String(root.getAttribute('data-case-open') || '0') === '1';
    var disputeUnlocked = String(root.getAttribute('data-dispute-unlocked') || '0') === '1';
    var lastId = 0;
    var polling = false;
    var viewingHistory = false;

    function setErr(msg) {
      if (!errEl) return;
      if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
      errEl.hidden = false;
      errEl.textContent = msg;
    }
    function esc(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function isDisputeLocked() {
      return topic === 'dispute' && !disputeUnlocked && !caseOpen;
    }
    function supportPanelActive() {
      var panel = document.getElementById('support-center');
      return !!(panel && panel.classList.contains('is-active'));
    }
    function setSupportBadge(n) {
      var badge = document.getElementById('shopPrefSupportBadge');
      if (!badge) return;
      n = Math.max(0, parseInt(n || 0, 10) || 0);
      if (n > 0) {
        badge.hidden = false;
        badge.textContent = n > 99 ? '99+' : String(n);
      } else {
        badge.hidden = true;
        badge.textContent = '0';
      }
    }
    function closeMoreMenu() {
      if (moreWrap) moreWrap.classList.remove('is-open');
      if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
    }
    function renderProductCard(el, product, forceHide) {
      if (!el) return;
      if (forceHide || !product || !(parseInt(product.id || 0, 10) > 0)) {
        el.hidden = true;
        el.innerHTML = '';
        return;
      }
      var id = parseInt(product.id || 0, 10) || 0;
      var title = String(product.title || ('Product #' + id));
      var cover = String(product.cover || '');
      var code = String(product.code || '');
      var href = String(product.buyer_href || ('product_detail.php?id=' + id));
      var biz = String(product.seller_business || '');
      var idLabel = id > 0 ? ('Product ID #' + id) : '';
      if (idLabel && code) idLabel += ' · ' + code;
      var imgSrc = cover || ('avatar.php?name=' + encodeURIComponent(title));
      el.hidden = false;
      el.innerHTML =
        '<img src="' + esc(imgSrc) + '" alt="">' +
        '<div>' +
          '<strong>' + esc(title) + '</strong>' +
          (idLabel ? '<span class="shop-admin-support-product-id shop-seller-msg-product-id">' + esc(idLabel) + '</span>' : '') +
          (biz ? '<span class="shop-admin-support-product-biz">' + esc('Seller: ' + biz) + '</span>' : '') +
        '</div>' +
        (href ? '<a href="' + esc(href) + '">View item</a>' : '');
    }
    function syncOpenProduct(product) {
      if (caseOpen && product && parseInt(product.id || 0, 10) > 0) {
        aboutProduct = parseInt(product.id || 0, 10) || aboutProduct;
        root.setAttribute('data-about-product', String(aboutProduct));
        if (product.seller_business && sellerEl && !String(sellerEl.value || '').trim()) {
          sellerEl.value = String(product.seller_business);
        }
        renderProductCard(productEl, product, false);
      } else if (!caseOpen && topic === 'dispute') {
        renderProductCard(productEl, null, true);
      }
    }
    function applyLockUi() {
      var locked = isDisputeLocked();
      if (composeEl) composeEl.hidden = locked || viewingHistory;
      if (lockedEl) lockedEl.hidden = !locked || viewingHistory;
      if (locked && thread && !viewingHistory) {
        thread.innerHTML = '<div class="shop-seller-msg-empty">Case closed. Report a product to open a new Admin case. Past cases are in ⋯ → Case history.</div>';
        lastId = 0;
        renderProductCard(productEl, null, true);
      }
      var headStatus = document.getElementById('shopAdminSupportHeadStatus');
      if (headStatus) headStatus.textContent = locked ? 'Case closed' : 'Active now';
    }
    function exitHistoryView() {
      viewingHistory = false;
      if (chatEl) chatEl.classList.remove('is-history-mode');
      if (historyPanel) historyPanel.classList.remove('is-open');
      applyLockUi();
      if (caseOpen) {
        loadHistory();
      } else {
        renderProductCard(productEl, null, true);
      }
    }
    function appendItems(items, replace, targetThread) {
      var box = targetThread || thread;
      if (!box) return;
      if (replace) box.innerHTML = '';
      (items || []).forEach(function (item) {
        var id = parseInt(item.id || 0, 10);
        if (!targetThread && id > lastId) lastId = id;
        var isMe = !!item.is_me;
        var row = document.createElement('div');
        row.className = 'shop-seller-msg-row ' + (isMe ? 'me' : 'them');
        var who = esc(item.from || (isMe ? 'You' : 'Admin'));
        var when = esc(item.time_label || '');
        row.innerHTML =
          '<div class="shop-seller-msg-bubble-wrap">' +
            '<div class="shop-seller-msg-bubble ' + (isMe ? 'me' : 'them') + '">' + esc(item.text || '') + '</div>' +
            '<div class="shop-seller-msg-meta">' + who + (when ? (' · ' + when) : '') + '</div>' +
          '</div>';
        box.appendChild(row);
      });
      box.scrollTop = box.scrollHeight;
    }
    async function loadCaseList() {
      if (!historyList) return;
      historyList.innerHTML = '<div class="shop-admin-support-history-empty">Loading…</div>';
      try {
        var res = await fetch(endpoint + '?mode=cases', { credentials: 'same-origin' });
        var data = await res.json();
        if (data && typeof data.customer_case_open !== 'undefined') {
          caseOpen = !!data.customer_case_open;
          if (caseOpen) disputeUnlocked = true;
          root.setAttribute('data-case-open', caseOpen ? '1' : '0');
          if (!viewingHistory) {
            syncOpenProduct(data.open_product || null);
            applyLockUi();
          }
        }
        var cases = (data && data.cases) || [];
        // Prefer closed cases for history; fall back to all if none closed yet.
        var list = cases.filter(function (c) { return !c.is_open; });
        if (!list.length) list = cases.slice();
        // One row per product: "Product ID #8 history", "Product ID #9 history", …
        var byProduct = {};
        list.forEach(function (c) {
          var pid = parseInt(c.product_id || 0, 10) || 0;
          if (pid <= 0) return;
          if (!byProduct[pid]) byProduct[pid] = c;
        });
        var rows = Object.keys(byProduct).map(function (k) { return byProduct[k]; });
        rows.sort(function (a, b) {
          return (parseInt(b.product_id || 0, 10) || 0) - (parseInt(a.product_id || 0, 10) || 0);
        });
        if (!rows.length) {
          historyList.innerHTML = '<div class="shop-admin-support-history-empty">No product history yet.</div>';
          return;
        }
        historyList.innerHTML = '';
        rows.forEach(function (c) {
          var pid = parseInt(c.product_id || 0, 10) || 0;
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'shop-admin-support-history-item';
          btn.setAttribute('role', 'menuitem');
          btn.setAttribute('data-dispute-id', String(c.id || 0));
          btn.setAttribute('data-product-id', String(pid));
          var cover = String(c.product_cover || '');
          var prodTitle = String(c.product_title || ('Product #' + pid));
          var label = 'Product ID #' + pid + ' history';
          var img = cover || ('avatar.php?name=' + encodeURIComponent(prodTitle));
          var meta = [prodTitle, c.time_label || ''].filter(Boolean).join(' · ');
          btn.innerHTML =
            '<img src="' + esc(img) + '" alt="">' +
            '<span class="shop-admin-support-history-item-text">' +
              '<span class="shop-admin-support-history-item-label">' + esc(label) + '</span>' +
              (meta ? '<span class="shop-admin-support-history-item-meta">' + esc(meta) + '</span>' : '') +
            '</span>';
          btn.addEventListener('click', function () {
            openCaseHistory(parseInt(c.id || 0, 10) || 0, pid);
          });
          historyList.appendChild(btn);
        });
      } catch (e) {
        historyList.innerHTML = '<div class="shop-admin-support-history-empty">Could not load history.</div>';
      }
    }
    async function openCaseHistory(disputeId, productIdHint) {
      if (disputeId <= 0) return;
      closeMoreMenu();
      viewingHistory = true;
      if (chatEl) chatEl.classList.add('is-history-mode');
      if (historyPanel) historyPanel.classList.add('is-open');
      var pidHint = parseInt(productIdHint || 0, 10) || 0;
      if (historyTitle) {
        historyTitle.textContent = pidHint > 0 ? ('Product ID #' + pidHint + ' history') : 'Case history';
      }
      if (historyThread) historyThread.innerHTML = '<div class="shop-seller-msg-empty">Loading…</div>';
      renderProductCard(historyProduct, null, true);
      try {
        var res = await fetch(endpoint + '?mode=case_history&dispute_id=' + encodeURIComponent(String(disputeId)), { credentials: 'same-origin' });
        var data = await res.json();
        if (!data || !data.ok) {
          if (historyThread) historyThread.innerHTML = '<div class="shop-seller-msg-empty">' + esc((data && data.error) || 'Could not load case.') + '</div>';
          return;
        }
        var pid = (data.product && parseInt(data.product.id || 0, 10)) || pidHint || 0;
        if (historyTitle) {
          historyTitle.textContent = pid > 0 ? ('Product ID #' + pid + ' history') : 'Case history';
        }
        // Header product card + full message history in the conversation area.
        renderProductCard(productEl, data.product || null, false);
        renderProductCard(historyProduct, null, true);
        if (historyThread) {
          historyThread.innerHTML = '';
          if ((data.items || []).length) {
            appendItems(data.items, true, historyThread);
          } else {
            historyThread.innerHTML = '<div class="shop-seller-msg-empty">No messages saved for Product ID #' + (pid || '?') + '.</div>';
          }
        }
      } catch (e) {
        if (historyThread) historyThread.innerHTML = '<div class="shop-seller-msg-empty">Could not load case.</div>';
      }
    }
    async function loadHistory() {
      if (viewingHistory) return;
      var mark = supportPanelActive() ? '1' : '0';
      try {
        var res = await fetch(endpoint + '?mode=history&after=0&mark=' + mark + '&topic=' + encodeURIComponent(topic), { credentials: 'same-origin' });
        var data = await res.json();
        if (data && typeof data.unread_count !== 'undefined') {
          setSupportBadge(data.unread_count);
        } else if (data && data.ok && mark === '1') {
          setSupportBadge(0);
        }
        if (data && typeof data.customer_case_open !== 'undefined') {
          caseOpen = !!data.customer_case_open;
          if (caseOpen) disputeUnlocked = true;
          root.setAttribute('data-case-open', caseOpen ? '1' : '0');
          root.setAttribute('data-dispute-unlocked', disputeUnlocked ? '1' : '0');
        }
        syncOpenProduct(data && data.open_product ? data.open_product : null);
        if (isDisputeLocked() || (data && data.case_locked && topic === 'dispute')) {
          disputeUnlocked = false;
          applyLockUi();
          return;
        }
        if (data && data.ok) {
          lastId = 0;
          appendItems(data.items || [], true);
          if (!(data.items || []).length) {
            thread.innerHTML = '<div class="shop-seller-msg-empty">No Admin messages yet. Choose a topic and describe your dispute or help request.</div>';
          }
        }
        applyLockUi();
      } catch (e) { /* ignore */ }
    }
    async function pollNew() {
      if (polling || viewingHistory) return;
      polling = true;
      var mark = supportPanelActive() && !isDisputeLocked() ? '1' : '0';
      try {
        var res = await fetch(endpoint + '?mode=history&after=' + lastId + '&mark=' + mark + '&topic=' + encodeURIComponent(topic), { credentials: 'same-origin' });
        var data = await res.json();
        if (data && typeof data.unread_count !== 'undefined') {
          setSupportBadge(data.unread_count);
        }
        if (data && typeof data.customer_case_open !== 'undefined') {
          var wasOpen = caseOpen;
          caseOpen = !!data.customer_case_open;
          if (caseOpen) disputeUnlocked = true;
          root.setAttribute('data-case-open', caseOpen ? '1' : '0');
          syncOpenProduct(data.open_product || null);
          if (wasOpen && !caseOpen && topic === 'dispute') {
            disputeUnlocked = false;
            applyLockUi();
            polling = false;
            return;
          }
          if (!caseOpen && topic === 'dispute' && !disputeUnlocked) {
            applyLockUi();
            polling = false;
            return;
          }
        }
        if (data && data.ok && (data.items || []).length) {
          if (thread && thread.querySelector('.shop-seller-msg-empty')) thread.innerHTML = '';
          appendItems(data.items, false);
          if (mark === '1') setSupportBadge(0);
        }
      } catch (e) { /* ignore */ }
      polling = false;
    }
    async function sendMessage() {
      setErr('');
      if (isDisputeLocked()) {
        setErr('This case is closed. Report a product to open a new case with Admin.');
        return;
      }
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
        if (aboutProduct > 0) {
          body.set('about_product', String(aboutProduct));
          body.set('product_id', String(aboutProduct));
        }
        var aboutSeller = parseInt(root.getAttribute('data-about-seller') || '0', 10) || 0;
        if (aboutSeller > 0) body.set('about_seller', String(aboutSeller));
        var res = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (!data || !data.ok) {
          setErr((data && (data.error || data.message)) || 'Could not send.');
          if (data && data.case_locked) {
            caseOpen = false;
            disputeUnlocked = aboutProduct > 0 ? disputeUnlocked : false;
            applyLockUi();
          }
          return;
        }
        if (data.customer_case_open) {
          caseOpen = true;
          disputeUnlocked = true;
          root.setAttribute('data-case-open', '1');
          root.setAttribute('data-dispute-unlocked', '1');
          if (data.open_product) syncOpenProduct(data.open_product);
          else if (productEl && aboutProduct > 0) productEl.hidden = false;
          applyLockUi();
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

    if (moreBtn && moreWrap) {
      moreBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = !moreWrap.classList.contains('is-open');
        moreWrap.classList.toggle('is-open', open);
        moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) loadCaseList();
      });
    }
    if (historyBack) {
      historyBack.addEventListener('click', function () {
        exitHistoryView();
      });
    }
    document.addEventListener('click', function (e) {
      if (!moreWrap || !moreWrap.classList.contains('is-open')) return;
      if (moreWrap.contains(e.target)) return;
      closeMoreMenu();
    });

    topicBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        topic = String(btn.getAttribute('data-topic') || 'help');
        topicBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
        Array.prototype.slice.call(document.querySelectorAll('.shop-admin-support-help-item')).forEach(function (row) {
          row.classList.toggle('is-active', String(row.getAttribute('data-topic') || '') === topic);
        });
        if (input) {
          input.placeholder = topic === 'dispute'
            ? 'Describe the dispute with the seller’s business…'
            : 'Describe what you need Admin help with…';
        }
        var headName = document.getElementById('shopAdminSupportHeadName');
        if (headName) headName.textContent = 'Admin support';
        applyLockUi();
        loadHistory();
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
    topicBtns.forEach(function (b) {
      b.classList.toggle('is-active', String(b.getAttribute('data-topic') || '') === topic);
    });
    Array.prototype.slice.call(document.querySelectorAll('.shop-admin-support-help-item')).forEach(function (row) {
      row.classList.toggle('is-active', String(row.getAttribute('data-topic') || '') === topic);
      row.addEventListener('click', function () {
        var t = String(row.getAttribute('data-topic') || 'help');
        var match = topicBtns.filter(function (b) { return String(b.getAttribute('data-topic') || '') === t; })[0];
        if (match) match.click();
      });
    });
    if (input && draft && !String(input.value || '').trim() && !isDisputeLocked()) {
      input.value = draft;
    }
    if (input) {
      input.placeholder = topic === 'dispute'
        ? 'Describe the dispute with the seller’s business…'
        : 'Describe what you need Admin help with…';
    }
    applyLockUi();
    loadHistory();
    document.addEventListener('shop-support-open', function () {
      loadHistory();
    });
    setInterval(pollNew, 5000);
  })();

  document.querySelectorAll('.js-wishlist-remove').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var productId = parseInt(btn.getAttribute('data-product-id') || '0', 10);
      if (!productId) return;
      btn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('action', 'remove');
        body.set('product_id', String(productId));
        var res = await fetch('ajax/wishlist_action.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (!data || !data.ok) {
          window.alert((data && data.message) || 'Could not remove.');
          btn.disabled = false;
          return;
        }
        var row = btn.closest('tr[data-wishlist-row]');
        if (row && row.parentNode) row.parentNode.removeChild(row);
      } catch (e) {
        window.alert('Could not remove.');
        btn.disabled = false;
      }
    });
  });

  document.querySelectorAll('.js-wishlist-add-cart').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var productId = parseInt(btn.getAttribute('data-product-id') || '0', 10);
      if (!productId) return;
      btn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('action', 'add');
        body.set('product_id', String(productId));
        body.set('quantity', '1');
        var res = await fetch('ajax/cart_action.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (!data || !data.ok) {
          window.alert((data && data.message) || 'Could not add to cart.');
          btn.disabled = false;
          return;
        }
        btn.textContent = 'In cart';
        var badge = document.getElementById('feedTopCartBadge');
        if (badge && data.count != null) badge.textContent = String(data.count);
      } catch (e) {
        window.alert('Could not add to cart.');
        btn.disabled = false;
      }
    });
  });
});
</script>
</body>
</html>
