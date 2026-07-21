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

$ptBackHref = 'commerce.php';
$ptBackLabel = 'Commerce hub';
$ptFormAction = '';
$ptShowBack = true;
$ptDetailBase = 'products_detail.php?id=';
$ptDetailSuffix = '';

$pageTitle = 'Inventory';
$ptTitle = 'Inventory';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/product-table.css?v=9">');
?>
<?php org_page_body_open('product-table-page'); ?>
<?php require __DIR__ . '/includes/org_product_table_panel.php'; ?>
</div>
<?php org_page_shell_close(); ?>
