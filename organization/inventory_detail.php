<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

$adminOversight = function_exists('admin_linked_is_org_admin_oversight') && admin_linked_is_org_admin_oversight();
if (!$adminOversight) {
    org_require_commerce_seller();
}

org_ecommerce_ensure_schema($dbh);
org_shop_ensure_schema($dbh);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$orgId = (int)orgActiveOrgId();
$productId = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
$fromSales = ((string)($_GET['from'] ?? $_POST['from'] ?? '') === 'sales');
$err = '';
$ok = '';

$product = null;
if ($productId > 0) {
    $product = $adminOversight
        ? org_shop_get_product($dbh, $productId, 0)
        : org_shop_get_product($dbh, $productId, $orgId);
}

if ($product && $adminOversight) {
    $productOrgId = (int)($product['org_id'] ?? 0);
    if ($productOrgId > 0) {
        $_SESSION['org_active_org_id'] = $productOrgId;
        $orgId = $productOrgId;
    }
}

$backHref = $adminOversight
    ? '../admin/inventory.php'
    : ($fromSales ? 'sales_management.php#inventory' : 'product_table.php');

if (!$product) {
    header('Location: ' . $backHref);
    exit;
}

$redirQs = 'id=' . $productId . ($fromSales ? '&from=sales' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($adminOversight)) {
    if (isset($_POST['invd_stock'])) {
        $qty = max(0, (int)($_POST['stock_qty'] ?? 0));
        if (org_shop_set_product_stock($dbh, $orgId, $productId, $qty)) {
            $_SESSION['invd_flash_ok'] = 'Stock updated.';
        } else {
            $_SESSION['invd_flash_err'] = 'Could not update stock.';
        }
        header('Location: inventory_detail.php?' . $redirQs);
        exit;
    }
    if (isset($_POST['inv_action'])) {
        $run = org_shop_run_catalog_row_action(
            $dbh,
            $orgId,
            trim((string)($_POST['action'] ?? '')),
            $productId
        );
        if (!empty($run['ok'])) {
            $_SESSION['invd_flash_ok'] = (string)($run['message'] ?? 'Saved.');
        } else {
            $_SESSION['invd_flash_err'] = (string)($run['error'] ?? 'Could not update inventory.');
        }
        if (trim((string)($_POST['action'] ?? '')) === 'delete') {
            header('Location: ' . $backHref);
            exit;
        }
        header('Location: inventory_detail.php?' . $redirQs);
        exit;
    }
}

if (!empty($_SESSION['invd_flash_ok']) || !empty($_SESSION['invd_flash_err'])) {
    $ok = (string)($_SESSION['invd_flash_ok'] ?? '');
    $err = (string)($_SESSION['invd_flash_err'] ?? '');
    unset($_SESSION['invd_flash_ok'], $_SESSION['invd_flash_err']);
}

$product = $adminOversight
    ? org_shop_get_product($dbh, $productId, 0)
    : org_shop_get_product($dbh, $productId, $orgId);
if (!$product) {
    header('Location: ' . $backHref);
    exit;
}

$pageTitle = 'Inventory';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open(
    $pageTitle,
    '<link rel="stylesheet" href="css/commerce-hub.css?v=17">'
    . '<link rel="stylesheet" href="css/sales-azia.css?v=6">'
);
org_page_body_open('commerce-page');
require __DIR__ . '/includes/org_inventory_detail_panel.php';
echo '</div>';
org_page_shell_close();
