<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();
org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pd_action'])) {
    $pdRun = org_shop_run_catalog_row_action(
        $dbh,
        $orgId,
        trim((string)($_POST['action'] ?? '')),
        (int)($_POST['product_id'] ?? 0)
    );
    if (!empty($pdRun['ok'])) {
        $_SESSION['pd_flash_ok'] = (string)($pdRun['message'] ?? 'Saved.');
        $_SESSION['pd_flash_err'] = '';
    } else {
        $_SESSION['pd_flash_ok'] = '';
        $_SESSION['pd_flash_err'] = (string)($pdRun['error'] ?? 'Could not update product.');
    }
    $pdTabRet = strtolower(trim((string)($_POST['pd_tab'] ?? 'all')));
    if (!in_array($pdTabRet, ['all', 'active', 'out', 'low', 'draft'], true)) {
        $pdTabRet = 'all';
    }
    $pdQs = $pdTabRet !== 'all' ? ('?tab=' . rawurlencode($pdTabRet)) : '';
    header('Location: product_catalog.php' . $pdQs);
    exit;
}

if (!empty($_SESSION['pd_flash_ok']) || !empty($_SESSION['pd_flash_err'])) {
    $ok = (string)($_SESSION['pd_flash_ok'] ?? '');
    $err = (string)($_SESSION['pd_flash_err'] ?? '');
    unset($_SESSION['pd_flash_ok'], $_SESSION['pd_flash_err']);
}

$pdTab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
$pdBaseUrl = 'product_catalog.php';
$pdHash = '';
$pdAddHref = 'products.php';
$pdEditBase = 'products.php?edit=';
$pdDetailBase = 'products_detail.php?id=';

$pageTitle = 'Products';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open(
    $pageTitle,
    '<link rel="stylesheet" href="css/commerce-hub.css?v=17">'
    . '<link rel="stylesheet" href="css/sales-azia.css?v=6">'
);
org_page_body_open('commerce-page');
?>
<?php require __DIR__ . '/includes/org_products_dashboard_panel.php'; ?>
</div>
<?php org_page_shell_close(); ?>
