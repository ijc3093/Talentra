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
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';

org_require_manager();

org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);
org_commerce_brands_ensure_schema($dbh);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$orgId = (int)orgActiveOrgId();
$commerceBrand = org_commerce_brands_get_for_org($dbh, $orgId);
if (!$commerceBrand) {
    header('Location: commerce_brand_select.php');
    exit;
}
$brandSystem = org_commerce_brands_parse_system($commerceBrand);
$memberId = (int)orgMemberId();
$err = '';
$ok = '';

$shopVisible = platform_rent_shop_is_visible($dbh, $orgId);
$maxProducts = org_shop_max_products($dbh, $orgId);
$productCount = org_shop_product_count($dbh, $orgId);
$shopSettings = org_ecommerce_get_shop_settings($dbh, $orgId);
$defaultFulfillment = (string)($brandSystem['default_fulfillment'] ?? $shopSettings['default_fulfillment_method'] ?? 'fbm');
$brandCategories = org_ecommerce_product_category_options(
    $dbh,
    $orgId,
    is_array($brandSystem['menu_categories'] ?? null) ? $brandSystem['menu_categories'] : []
);
$sellingTypeOptions = org_ecommerce_product_selling_type_options($dbh, $orgId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['product_id'] ?? 0);
    if (!org_ecommerce_seller_has_required_address($dbh, $orgId)) {
        $err = 'Add your Full Address before you can create or update a product. Address line 1, city, and state are required.';
    } else {
    $data = [
        'title' => (string)($_POST['title'] ?? ''),
        'description' => (string)($_POST['description'] ?? ''),
        'price' => (string)($_POST['price'] ?? '0'),
        'stock_qty' => (string)($_POST['stock_qty'] ?? ''),
        'category' => (string)($_POST['category'] ?? ''),
        'selling_type' => (string)($_POST['selling_type'] ?? ''),
        'status' => (string)($_POST['status'] ?? 'draft'),
        'sku' => (string)($_POST['sku'] ?? ''),
        'offer_type' => (string)($_POST['offer_type'] ?? 'physical'),
        'pricing_model' => (string)($_POST['pricing_model'] ?? 'one_time'),
        'seo_title' => (string)($_POST['seo_title'] ?? ''),
        'seo_description' => (string)($_POST['seo_description'] ?? ''),
        'bullet_points' => (string)($_POST['bullet_points'] ?? ''),
        'search_keywords' => (string)($_POST['search_keywords'] ?? ''),
        'fulfillment_method' => (string)($_POST['fulfillment_method'] ?? 'fbm'),
        'delivery_enabled' => !empty($_POST['delivery_enabled']) ? 1 : 0,
        'pickup_enabled' => !empty($_POST['pickup_enabled']) ? 1 : 0,
        'delivery_carriers' => isset($_POST['delivery_carriers']) && is_array($_POST['delivery_carriers'])
            ? $_POST['delivery_carriers']
            : [],
        'shipping_is_free' => (string)($_POST['shipping_is_free'] ?? '1'),
        'shipping_fee' => (string)($_POST['shipping_fee'] ?? '0'),
        'product_attr' => is_array($_POST['product_attr'] ?? null) ? $_POST['product_attr'] : [],
    ];
    $result = org_shop_save_product($dbh, $orgId, $data, $pid > 0 ? $pid : null, $memberId);
    if (!empty($result['ok'])) {
        $catName = trim((string)($data['category'] ?? ''));
        if ($catName !== '') {
            org_ecommerce_add_custom_category($dbh, $orgId, $catName);
        }
        $sellType = trim((string)($data['selling_type'] ?? ''));
        if ($sellType !== '') {
            org_ecommerce_add_custom_selling_type($dbh, $orgId, $sellType);
        }
        $savedId = (int)($result['product_id'] ?? 0);
        if ($savedId > 0) {
            org_shop_save_product_images_from_request($dbh, $orgId, $savedId);
        }
        $ok = $pid > 0 ? 'Product updated.' : 'Product created.';
        $productCount = org_shop_product_count($dbh, $orgId);
    } else {
        $err = (string)($result['error'] ?? 'Save failed.');
    }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editProduct = $editId > 0 ? org_shop_get_product($dbh, $editId, $orgId) : null;

$pimFormAction = '';
$pimTableHref = 'product_table.php';
$pimTableAttr = '';
$pimCancelHref = 'products.php';
$pimHubHref = 'commerce.php';
$pimHubLabel = 'Commerce hub';

$pageTitle = 'Products';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
<?php require __DIR__ . '/includes/org_products_catalog_panel.php'; ?>
</div>
<?php org_page_shell_close(); ?>
