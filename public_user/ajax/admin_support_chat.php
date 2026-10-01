<?php
declare(strict_types=1);

/**
 * Customer ↔ Admin support chat (Support Center).
 * Product Report flow: about_product creates a commerce_disputes case with Dispute ID.
 */

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/admin_support_chat.php';
require_once __DIR__ . '/../includes/commerce_disputes.php';
require_once __DIR__ . '/../includes/msb_reports.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function asc_json(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0 && function_exists('myUserId')) {
    $meId = (int)myUserId();
}
$meEmail = admin_support_user_email($dbh, $meId);
if ($meEmail === '' && function_exists('myUserEmail')) {
    $meEmail = trim((string)myUserEmail());
}
if ($meEmail === '') {
    asc_json(['ok' => false, 'error' => 'Missing account email.']);
}

$mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'history')));
$caseOpen = function_exists('commerce_dispute_buyer_has_open_customer_case')
    ? commerce_dispute_buyer_has_open_customer_case($dbh, $meId)
    : false;

if ($mode === 'cases') {
    $cases = function_exists('commerce_dispute_list_for_buyer')
        ? commerce_dispute_list_for_buyer($dbh, $meId, 40)
        : [];
    $openCase = null;
    if (function_exists('commerce_dispute_buyer_latest_open_case')) {
        $openCase = commerce_dispute_buyer_latest_open_case($dbh, $meId);
    }
    $openProduct = null;
    if (is_array($openCase)) {
        $pid = (int)($openCase['product_id'] ?? 0);
        if ($pid > 0 && function_exists('commerce_messaging_product_focus')) {
            require_once __DIR__ . '/../includes/commerce_messaging.php';
            $openProduct = commerce_messaging_product_focus($dbh, $pid);
        }
        if (!is_array($openProduct) && $pid > 0) {
            $cover = '';
            if (function_exists('org_shop_cover_url')) {
                require_once __DIR__ . '/../includes/org_shop.php';
                $cover = org_shop_cover_url((string)($openCase['product_cover_path'] ?? ''));
            }
            $openProduct = [
                'id' => $pid,
                'title' => trim((string)($openCase['product_title'] ?? ('Product #' . $pid))),
                'code' => '',
                'cover' => $cover,
                'price' => '',
                'buyer_href' => 'product_detail.php?id=' . $pid,
                'seller_business' => trim((string)($openCase['seller_business_name'] ?? '')),
            ];
        } elseif (is_array($openProduct)) {
            $openProduct['seller_business'] = trim((string)($openCase['seller_business_name'] ?? ''));
        }
    }
    asc_json([
        'ok' => true,
        'customer_case_open' => $caseOpen,
        'cases' => $cases,
        'open_case' => is_array($openCase) ? [
            'id' => (int)($openCase['id'] ?? 0),
            'code' => trim((string)($openCase['dispute_code'] ?? '')),
            'product_id' => (int)($openCase['product_id'] ?? 0),
            'seller_business' => trim((string)($openCase['seller_business_name'] ?? '')),
        ] : null,
        'open_product' => $openProduct,
    ]);
}

if ($mode === 'case_history') {
    $disputeId = (int)($_GET['dispute_id'] ?? $_POST['dispute_id'] ?? 0);
    $case = function_exists('commerce_dispute_get_for_buyer')
        ? commerce_dispute_get_for_buyer($dbh, $meId, $disputeId)
        : null;
    if (!$case) {
        asc_json(['ok' => false, 'error' => 'Case not found.']);
    }
    $code = trim((string)($case['dispute_code'] ?? ''));
    if ($code === '') {
        $code = commerce_dispute_format_id((int)($case['id'] ?? 0));
    }
    $pid = (int)($case['product_id'] ?? 0);
    $product = null;
    if ($pid > 0 && function_exists('commerce_messaging_product_focus')) {
        require_once __DIR__ . '/../includes/commerce_messaging.php';
        $product = commerce_messaging_product_focus($dbh, $pid);
    }
    if (!is_array($product) && $pid > 0) {
        require_once __DIR__ . '/../includes/org_shop.php';
        $cover = function_exists('org_shop_cover_url')
            ? org_shop_cover_url((string)($case['product_cover_path'] ?? ''))
            : '';
        $product = [
            'id' => $pid,
            'title' => trim((string)($case['product_title'] ?? ('Product #' . $pid))),
            'code' => '',
            'cover' => $cover,
            'price' => '',
            'buyer_href' => 'product_detail.php?id=' . $pid,
        ];
    }
    if (is_array($product)) {
        $product['seller_business'] = trim((string)($case['seller_business_name'] ?? ''));
    }

    $poll = admin_support_poll($dbh, $meEmail, 0, false);
    $items = [];
    // Include the full Admin↔customer thread for this product across dispute + Help channels.
    // Customer lines mention Product ID / dispute code; Admin replies usually do not,
    // so keep following messages until a different Product ID appears.
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
    // Fallback: if code/product never matched but we have a known product, keep product-only hits.
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

    $custClosed = (int)($case['customer_case_closed'] ?? 0) === 1;
    $status = strtolower(trim((string)($case['status'] ?? '')));
    $isOpen = !$custClosed && in_array($status, ['open', 'seller_notified'], true);

    asc_json([
        'ok' => true,
        'case' => [
            'id' => (int)($case['id'] ?? 0),
            'code' => $code,
            'is_open' => $isOpen,
            'status' => $status,
            'seller_business' => trim((string)($case['seller_business_name'] ?? '')),
            'time_label' => (($ts = strtotime((string)($case['updated_at'] ?? ''))) ? date('M j, Y', $ts) : ''),
        ],
        'product' => $product,
        'items' => $items,
        'customer_case_open' => $caseOpen,
    ]);
}

if ($mode === 'send') {
    $topic = strtolower(trim((string)($_POST['topic'] ?? 'help')));
    $text = (string)($_POST['message'] ?? '');
    $orderCode = trim((string)($_POST['order_code'] ?? ''));
    $sellerName = trim((string)($_POST['seller_name'] ?? ''));
    $aboutProduct = (int)($_POST['about_product'] ?? $_POST['product_id'] ?? 0);
    $aboutSeller = (int)($_POST['about_seller'] ?? 0);

    $extra = [];
    $disputeCode = '';
    $disputeId = 0;

    // Dispute chat stays locked after Admin closes the customer case until a new product Report.
    if ($topic === 'dispute' && !$caseOpen && $aboutProduct <= 0) {
        asc_json([
            'ok' => false,
            'error' => 'This case is closed. Open a product and tap Report to start a new case with Admin.',
            'customer_case_open' => false,
            'case_locked' => true,
        ]);
    }

    if ($topic === 'dispute' && $aboutProduct > 0) {
        $created = commerce_dispute_create_from_product($dbh, $meId, $aboutProduct, $text, 'product_report');
        if (!empty($created['ok'])) {
            $disputeId = (int)($created['id'] ?? 0);
            $disputeCode = trim((string)($created['code'] ?? ''));
            $caseOpen = true;
            if ($disputeCode !== '') {
                $extra[] = 'Dispute ID: ' . $disputeCode;
            }
            $case = $disputeId > 0 ? commerce_dispute_get($dbh, $disputeId) : null;
            if (is_array($case)) {
                $extra[] = commerce_dispute_context_lines($case);
            } else {
                $extra[] = 'Product ID #' . $aboutProduct;
                if ($sellerName !== '') {
                    $extra[] = 'Seller business: ' . $sellerName;
                }
            }
            // Also log a product report for Admin review queue.
            if (function_exists('msb_reports_create')) {
                $label = trim((string)($_SESSION['user_email'] ?? $meEmail));
                msb_reports_create($dbh, $meId, 'user', 'product', $aboutProduct, 'other', $text, 0, $label);
            }
        } elseif (!$caseOpen) {
            asc_json([
                'ok' => false,
                'error' => (string)($created['error'] ?? 'Could not open a new dispute case.'),
                'customer_case_open' => false,
                'case_locked' => true,
            ]);
        }
    }

    if ($orderCode !== '') {
        $extra[] = 'Order: ' . $orderCode;
    }
    if ($sellerName !== '' && $disputeCode === '') {
        $extra[] = 'Seller: ' . $sellerName;
    }
    if ($aboutSeller > 0 && $disputeCode === '') {
        $extra[] = 'Seller user ID: ' . $aboutSeller;
    }

    // Deduplicate extra lines while preserving order.
    $seen = [];
    $extraUnique = [];
    foreach ($extra as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $key = strtolower($line);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $extraUnique[] = $line;
    }

    $result = admin_support_send(
        $dbh,
        $meEmail,
        $text,
        $topic,
        'customer',
        $extraUnique ? implode("\n", $extraUnique) : null
    );
    if (!empty($result['ok']) && $disputeCode !== '') {
        $result['dispute_id'] = $disputeId;
        $result['dispute_code'] = $disputeCode;
    }
    $result['customer_case_open'] = $caseOpen;
    $result['case_locked'] = ($topic === 'dispute' && !$caseOpen);
    if ($caseOpen && $aboutProduct > 0) {
        if (function_exists('commerce_messaging_product_focus')) {
            require_once __DIR__ . '/../includes/commerce_messaging.php';
            $op = commerce_messaging_product_focus($dbh, $aboutProduct);
            if (is_array($op)) {
                $op['seller_business'] = $sellerName;
                $result['open_product'] = $op;
            }
        }
    }
    asc_json($result);
}

$after = (int)($_GET['after'] ?? $_POST['after'] ?? 0);
$mark = !isset($_GET['mark']) || (string)$_GET['mark'] !== '0';
$topicFilter = strtolower(trim((string)($_GET['topic'] ?? $_POST['topic'] ?? '')));
$result = admin_support_poll($dbh, $meEmail, $after, $mark);

// Closed customer case: dispute messages stay unavailable until a new Report opens a case.
if (is_array($result) && !empty($result['ok']) && !$caseOpen) {
    if ($topicFilter === 'dispute') {
        $result['items'] = [];
    } else {
        $kept = [];
        foreach ((array)($result['items'] ?? []) as $item) {
            if (strtolower(trim((string)($item['channel'] ?? ''))) === 'dispute') {
                continue;
            }
            $kept[] = $item;
        }
        $result['items'] = $kept;
    }
}
$result['customer_case_open'] = $caseOpen;
$result['case_locked'] = !$caseOpen;
$result['unread_count'] = function_exists('admin_support_unread_count')
    ? admin_support_unread_count($dbh, $meEmail)
    : 0;
$openProduct = null;
$openCasePayload = null;
if ($caseOpen && function_exists('commerce_dispute_buyer_latest_open_case')) {
    $openCase = commerce_dispute_buyer_latest_open_case($dbh, $meId);
    if (is_array($openCase)) {
        $pid = (int)($openCase['product_id'] ?? 0);
        $openCasePayload = [
            'id' => (int)($openCase['id'] ?? 0),
            'code' => trim((string)($openCase['dispute_code'] ?? '')),
            'product_id' => $pid,
            'seller_business' => trim((string)($openCase['seller_business_name'] ?? '')),
        ];
        if ($pid > 0) {
            if (function_exists('commerce_messaging_product_focus')) {
                require_once __DIR__ . '/../includes/commerce_messaging.php';
                $openProduct = commerce_messaging_product_focus($dbh, $pid);
            }
            if (!is_array($openProduct)) {
                require_once __DIR__ . '/../includes/org_shop.php';
                $openProduct = [
                    'id' => $pid,
                    'title' => trim((string)($openCase['product_title'] ?? ('Product #' . $pid))),
                    'code' => '',
                    'cover' => function_exists('org_shop_cover_url')
                        ? org_shop_cover_url((string)($openCase['product_cover_path'] ?? ''))
                        : '',
                    'price' => '',
                    'buyer_href' => 'product_detail.php?id=' . $pid,
                ];
            }
            if (is_array($openProduct)) {
                $openProduct['seller_business'] = trim((string)($openCase['seller_business_name'] ?? ''));
            }
        }
    }
}
$result['open_case'] = $openCasePayload;
$result['open_product'] = $openProduct;
asc_json($result);
