<?php
declare(strict_types=1);

/**
 * Seller ↔ Admin support chat (Sales Support Center).
 * Mirrors customer Support Center: cases + case_history for Product ID concern threads.
 */

require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../includes/org_manager_guard.php';
require_once __DIR__ . '/../../public_user/includes/staff_publisher_access.php';
require_once __DIR__ . '/../../public_user/includes/admin_support_chat.php';
require_once __DIR__ . '/../../public_user/includes/commerce_disputes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function sasc_json(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

org_require_manager();
org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
$publisherUserId = staff_pub_org_publisher_user_id($dbh, $orgId);
if ($publisherUserId <= 0) {
    $publisherUserId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
}
if ($publisherUserId <= 0) {
    sasc_json(['ok' => false, 'error' => 'No publisher account linked to this shop.']);
}

$meEmail = admin_support_user_email($dbh, $publisherUserId);
if ($meEmail === '') {
    sasc_json(['ok' => false, 'error' => 'Missing seller account email.']);
}

$mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'history')));

if ($mode === 'cases') {
    require_once __DIR__ . '/../../public_user/includes/commerce_messaging.php';
    $cases = commerce_dispute_list_history_for_seller($dbh, $publisherUserId, 40);
    $openCase = commerce_dispute_seller_latest_open_case($dbh, $publisherUserId);
    $openProduct = null;
    if (is_array($openCase)) {
        $pid = (int)($openCase['product_id'] ?? 0);
        if ($pid > 0) {
            $openProduct = commerce_messaging_product_focus($dbh, $pid, $orgId);
        }
        if (!is_array($openProduct) && $pid > 0) {
            $cover = '';
            if (function_exists('org_shop_cover_url')) {
                require_once __DIR__ . '/../../public_user/includes/org_shop.php';
                $cover = org_shop_cover_url((string)($openCase['product_cover_path'] ?? ''));
            }
            $openProduct = [
                'id' => $pid,
                'title' => trim((string)($openCase['product_title'] ?? ('Product #' . $pid))),
                'code' => '',
                'cover' => $cover,
                'price' => '',
                'seller_href' => 'sales_management.php?inv_product=' . $pid . '#inventory-detail',
            ];
        }
        if (is_array($openProduct)) {
            $code = trim((string)($openCase['dispute_code'] ?? ''));
            if ($code === '') {
                $code = commerce_dispute_format_id((int)($openCase['id'] ?? 0));
            }
            $openProduct['dispute_code'] = $code;
            $openProduct['concern_label'] = 'Customer concern';
        }
    }
    sasc_json([
        'ok' => true,
        'seller_case_open' => is_array($openCase),
        'cases' => $cases,
        'open_case' => is_array($openCase) ? [
            'id' => (int)($openCase['id'] ?? 0),
            'code' => trim((string)($openCase['dispute_code'] ?? '')) ?: commerce_dispute_format_id((int)($openCase['id'] ?? 0)),
            'product_id' => (int)($openCase['product_id'] ?? 0),
        ] : null,
        'open_product' => $openProduct,
    ]);
}

if ($mode === 'case_history') {
    require_once __DIR__ . '/../../public_user/includes/commerce_messaging.php';
    $disputeId = (int)($_GET['dispute_id'] ?? $_POST['dispute_id'] ?? 0);
    $case = commerce_dispute_get_for_seller($dbh, $publisherUserId, $disputeId);
    if (!$case) {
        sasc_json(['ok' => false, 'error' => 'Case not found.']);
    }
    $code = trim((string)($case['dispute_code'] ?? ''));
    if ($code === '') {
        $code = commerce_dispute_format_id((int)($case['id'] ?? 0));
    }
    $pid = (int)($case['product_id'] ?? 0);
    $product = null;
    if ($pid > 0) {
        $product = commerce_messaging_product_focus($dbh, $pid, $orgId);
    }
    if (!is_array($product) && $pid > 0) {
        require_once __DIR__ . '/../../public_user/includes/org_shop.php';
        $cover = function_exists('org_shop_cover_url')
            ? org_shop_cover_url((string)($case['product_cover_path'] ?? ''))
            : '';
        $product = [
            'id' => $pid,
            'title' => trim((string)($case['product_title'] ?? ('Product #' . $pid))),
            'code' => '',
            'cover' => $cover,
            'price' => '',
            'seller_href' => 'sales_management.php?inv_product=' . $pid . '#inventory-detail',
        ];
    }
    if (is_array($product)) {
        $product['dispute_code'] = $code;
        $product['concern_label'] = 'Customer concern';
    }

    $poll = admin_support_poll($dbh, $meEmail, 0, false);
    $items = [];
    // Full Admin↔seller thread for this product concern (dispute + help channels).
    $inThread = false;
    $pidPattern = $pid > 0
        ? ('/Product\s*ID\s*#\s*' . preg_quote((string)$pid, '/') . '\b/i')
        : '';
    foreach ((array)($poll['items'] ?? []) as $item) {
        $ch = strtolower(trim((string)($item['channel'] ?? '')));
        if ($ch !== 'dispute' && $ch !== 'user_admin') {
            continue;
        }
        $text = (string)($item['text'] ?? '');
        $itemPid = (int)($item['product_id'] ?? 0);
        $matchCode = $code !== '' && stripos($text, $code) !== false;
        $matchProduct = ($pid > 0 && $itemPid === $pid)
            || ($pidPattern !== '' && preg_match($pidPattern, $text));
        $isOtherProduct = $itemPid > 0 && $pid > 0 && $itemPid !== $pid;

        if ($matchProduct || $matchCode) {
            $inThread = true;
            $items[] = $item;
            continue;
        }
        if ($isOtherProduct) {
            $inThread = false;
            continue;
        }
        if ($inThread) {
            $items[] = $item;
        }
    }
    if (!$items && $pid > 0) {
        foreach ((array)($poll['items'] ?? []) as $item) {
            $ch = strtolower(trim((string)($item['channel'] ?? '')));
            if ($ch !== 'dispute' && $ch !== 'user_admin') {
                continue;
            }
            $itemPid = (int)($item['product_id'] ?? 0);
            $text = (string)($item['text'] ?? '');
            if ($itemPid === $pid || ($pidPattern !== '' && preg_match($pidPattern, $text))) {
                $items[] = $item;
            }
        }
    }

    $sellerClosed = (int)($case['seller_case_closed'] ?? 0) === 1;
    $status = strtolower(trim((string)($case['status'] ?? '')));
    $isOpen = !$sellerClosed && in_array($status, ['open', 'seller_notified'], true);

    sasc_json([
        'ok' => true,
        'case' => [
            'id' => (int)($case['id'] ?? 0),
            'code' => $code,
            'is_open' => $isOpen,
            'status' => $status,
            'time_label' => (($ts = strtotime((string)($case['updated_at'] ?? ''))) ? date('M j, Y', $ts) : ''),
        ],
        'product' => $product,
        'items' => $items,
        'seller_case_open' => is_array(commerce_dispute_seller_latest_open_case($dbh, $publisherUserId)),
    ]);
}

if ($mode === 'send') {
    $topic = strtolower(trim((string)($_POST['topic'] ?? 'seller_help')));
    $text = (string)($_POST['message'] ?? '');
    // Keep message body clean — org/product context lives in the header card, not the bubble.
    $result = admin_support_send(
        $dbh,
        $meEmail,
        $text,
        $topic,
        'seller',
        null
    );
    // Mark commerce dispute seller response when reply cites Dispute ID.
    if (!empty($result['ok']) && preg_match('/DSP-[0-9A-Z]+/i', $text, $m)) {
        $case = commerce_dispute_get_by_code($dbh, $m[0]);
        if ($case && (int)($case['publisher_user_id'] ?? 0) === $publisherUserId) {
            commerce_dispute_mark_seller_responded($dbh, (int)$case['id']);
        }
    }
    sasc_json($result);
}

$after = (int)($_GET['after'] ?? $_POST['after'] ?? 0);
$mark = !isset($_GET['mark']) || (string)$_GET['mark'] !== '0';
$result = admin_support_poll($dbh, $meEmail, $after, $mark);

// Attach open concern product for live conversation header (customer concern).
if (!empty($result['ok'])) {
    require_once __DIR__ . '/../../public_user/includes/commerce_messaging.php';
    $openCase = commerce_dispute_seller_latest_open_case($dbh, $publisherUserId);
    $openProduct = null;
    if (is_array($openCase)) {
        $pid = (int)($openCase['product_id'] ?? 0);
        if ($pid > 0) {
            $openProduct = commerce_messaging_product_focus($dbh, $pid, $orgId);
        }
        if (!is_array($openProduct) && $pid > 0) {
            require_once __DIR__ . '/../../public_user/includes/org_shop.php';
            $cover = function_exists('org_shop_cover_url')
                ? org_shop_cover_url((string)($openCase['product_cover_path'] ?? ''))
                : '';
            $openProduct = [
                'id' => $pid,
                'title' => trim((string)($openCase['product_title'] ?? ('Product #' . $pid))),
                'code' => '',
                'cover' => $cover,
                'price' => '',
                'seller_href' => 'sales_management.php?inv_product=' . $pid . '#inventory-detail',
            ];
        }
        if (is_array($openProduct)) {
            $code = trim((string)($openCase['dispute_code'] ?? ''));
            if ($code === '') {
                $code = commerce_dispute_format_id((int)($openCase['id'] ?? 0));
            }
            $openProduct['dispute_code'] = $code;
            $openProduct['concern_label'] = 'Customer concern';
        }
    }
    $result['seller_case_open'] = is_array($openCase);
    $result['open_product'] = $openProduct;
}

sasc_json($result);
