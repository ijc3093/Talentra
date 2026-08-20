<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$orgId = (int)orgActiveOrgId();
$err = '';
$ok = '';
$detailId = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invd_stock'])) {
    $qty = max(0, (int)($_POST['stock_qty'] ?? 0));
    if ($detailId > 0 && org_shop_set_product_stock($dbh, $orgId, $detailId, $qty)) {
        $_SESSION['pt_flash_ok'] = 'Stock updated.';
        $_SESSION['pt_flash_err'] = '';
    } else {
        $_SESSION['pt_flash_ok'] = '';
        $_SESSION['pt_flash_err'] = 'Could not update stock.';
    }
    header('Location: product_table.php?id=' . $detailId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inv_action'])) {
    $invRun = org_shop_run_catalog_row_action(
        $dbh,
        $orgId,
        trim((string)($_POST['action'] ?? '')),
        (int)($_POST['product_id'] ?? 0)
    );
    if (!empty($invRun['ok'])) {
        $_SESSION['pt_flash_ok'] = (string)($invRun['message'] ?? 'Saved.');
        $_SESSION['pt_flash_err'] = '';
    } else {
        $_SESSION['pt_flash_ok'] = '';
        $_SESSION['pt_flash_err'] = (string)($invRun['error'] ?? 'Could not update inventory.');
    }
    $invActionName = trim((string)($_POST['action'] ?? ''));
    $invPid = (int)($_POST['product_id'] ?? 0);
    if ($invActionName === 'delete') {
        header('Location: product_table.php');
        exit;
    }
    if ($invPid > 0 && ((string)($_POST['from_view'] ?? '') === 'inventory-detail' || $detailId > 0)) {
        header('Location: product_table.php?id=' . $invPid);
        exit;
    }
    $invTabRet = strtolower(trim((string)($_POST['inv_tab'] ?? 'all')));
    if (!in_array($invTabRet, ['all', 'low', 'out'], true)) {
        $invTabRet = 'all';
    }
    $invQs = $invTabRet !== 'all' ? ('?inv=' . rawurlencode($invTabRet)) : '';
    header('Location: product_table.php' . $invQs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'delete') {
        $pid = (int)($_POST['product_id'] ?? 0);
        if (org_shop_delete_product($dbh, $orgId, $pid)) {
            $ok = 'Product removed.';
        } else {
            $err = 'Could not remove product.';
        }
    } elseif ($action === 'publish_feed') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $pub = org_shop_publish_product_to_feed($dbh, $orgId, $pid);
        if (!empty($pub['ok'])) {
            $ok = 'Published to public feed (post #' . (int)($pub['public_post_id'] ?? 0) . ').';
        } else {
            $err = (string)($pub['error'] ?? 'Could not publish to feed.');
        }
    }
}

if (!empty($_SESSION['pt_flash_ok']) || !empty($_SESSION['pt_flash_err'])) {
    $ok = (string)($_SESSION['pt_flash_ok'] ?? '');
    $err = (string)($_SESSION['pt_flash_err'] ?? '');
    unset($_SESSION['pt_flash_ok'], $_SESSION['pt_flash_err']);
}

$pageTitle = 'Inventory';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open(
    $pageTitle,
    '<link rel="stylesheet" href="css/commerce-hub.css?v=17">'
    . '<link rel="stylesheet" href="css/sales-azia.css?v=6">'
);
org_page_body_open('commerce-page');

if ($detailId > 0) {
    $product = org_shop_get_product($dbh, $detailId, $orgId);
    if ($product) {
        $productId = $detailId;
        $fromSales = false;
        $backHref = 'product_table.php';
        $invdFormAction = 'product_table.php?id=' . $detailId;
        require __DIR__ . '/includes/org_inventory_detail_panel.php';
        echo '</div>';
        org_page_shell_close();
        exit;
    }
}

$ptFormAction = '';
$ptShowStoreToolbar = false;
$ptDetailBase = 'product_table.php?id=';
$ptDetailSuffix = '';
$ptBaseUrl = 'product_table.php';
$ptHash = '';
$invTab = strtolower(trim((string)($_GET['inv'] ?? 'all')));
require __DIR__ . '/includes/org_product_table_panel.php';
echo '</div>';
org_page_shell_close();

