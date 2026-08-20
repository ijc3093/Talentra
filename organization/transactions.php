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
$txnInSalesHub = false;
$txnShowPageHead = true;
$txnInventoryHref = 'product_table.php';
$txnInventoryAttr = '';
$txnProductBase = 'product_table.php?id=';
$txnProductSuffix = '';
$txnNotiCount = 0;
$txnMsgCount = 0;

$pageTitle = 'Transactions';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open(
    $pageTitle,
    '<link rel="stylesheet" href="css/commerce-hub.css?v=17">'
    . '<link rel="stylesheet" href="css/sales-azia.css?v=6">'
);
org_page_body_open('commerce-page');
$panelFile = __DIR__ . '/includes/org_inventory_transactions_panel.php';
if (is_file($panelFile)) {
    require $panelFile;
} else {
    echo '<p class="tx-color-03">Transactions panel is missing on the server. Upload organization/includes/org_inventory_transactions_panel.php.</p>';
}
?>
</div>
<?php org_page_shell_close(); ?>
