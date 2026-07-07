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
org_ecommerce_ensure_schema($dbh);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
$err = '';
$ok = '';

$shopVisible = platform_rent_shop_is_visible($dbh, $orgId);
$maxProducts = org_shop_max_products($dbh, $orgId);
$productCount = org_shop_product_count($dbh, $orgId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'delete') {
        $pid = (int)($_POST['product_id'] ?? 0);
        if (org_shop_delete_product($dbh, $orgId, $pid)) {
            $ok = 'Product removed.';
            $productCount = org_shop_product_count($dbh, $orgId);
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
    } else {
        $pid = (int)($_POST['product_id'] ?? 0);
        $data = [
            'title' => (string)($_POST['title'] ?? ''),
            'description' => (string)($_POST['description'] ?? ''),
            'price' => (string)($_POST['price'] ?? '0'),
            'stock_qty' => (string)($_POST['stock_qty'] ?? ''),
            'category' => (string)($_POST['category'] ?? ''),
            'status' => (string)($_POST['status'] ?? 'draft'),
            'sku' => (string)($_POST['sku'] ?? ''),
            'offer_type' => (string)($_POST['offer_type'] ?? 'physical'),
            'pricing_model' => (string)($_POST['pricing_model'] ?? 'one_time'),
            'seo_title' => (string)($_POST['seo_title'] ?? ''),
            'seo_description' => (string)($_POST['seo_description'] ?? ''),
        ];
        $result = org_shop_save_product($dbh, $orgId, $data, $pid > 0 ? $pid : null, $memberId);
        if (!empty($result['ok'])) {
            $savedId = (int)($result['product_id'] ?? 0);
            $coverPath = org_shop_handle_cover_upload($orgId, $savedId);
            if ($coverPath !== null && $savedId > 0) {
                $dbh->prepare('UPDATE org_products SET cover_image_path = :p, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
                    ->execute([':p' => $coverPath, ':id' => $savedId, ':org' => $orgId]);
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
$products = org_shop_list_products($dbh, $orgId, false);

$pageTitle = 'Products';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<div class="sh-pagebody">
  <div class="card bd-0 shadow-base">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
      <div>
        <h6 class="card-title tx-uppercase tx-14 mg-b-0">Product catalog (PIM)</h6>
        <p class="mg-b-0 tx-12 tx-color-03"><?= (int)$productCount ?> / <?= (int)$maxProducts ?> on your plan</p>
      </div>
      <div class="d-flex" style="gap:8px;">
        <a href="commerce.php" class="btn btn-sm btn-outline-secondary">Commerce hub</a>
        <?php if (!$shopVisible): ?>
          <span class="badge badge-warning align-self-center">Shop hidden — rent overdue or trial ended</span>
        <?php else: ?>
          <span class="badge badge-success align-self-center">Shop live</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
      <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="mg-b-25">
        <input type="hidden" name="product_id" value="<?= (int)($editProduct['id'] ?? 0) ?>">
        <div class="row">
          <div class="col-md-5">
            <div class="form-group">
              <label>Title</label>
              <input type="text" name="title" class="form-control" maxlength="200" required value="<?= h((string)($editProduct['title'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label>SKU</label>
              <input type="text" name="sku" class="form-control" maxlength="64" value="<?= h((string)($editProduct['sku'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label>Price (USD)</label>
              <input type="number" name="price" class="form-control" min="0" step="0.01" value="<?= h(number_format(((int)($editProduct['price_cents'] ?? 0)) / 100, 2, '.', '')) ?>">
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label>Stock</label>
              <input type="number" name="stock_qty" class="form-control" min="0" placeholder="∞" value="<?= isset($editProduct['stock_qty']) && $editProduct['stock_qty'] !== null ? (int)$editProduct['stock_qty'] : '' ?>">
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-3">
            <div class="form-group">
              <label>Offer type</label>
              <select name="offer_type" class="form-control">
                <?php foreach (['physical','digital','service','subscription','license'] as $ot): ?>
                  <option value="<?= h($ot) ?>" <?= (($editProduct['offer_type'] ?? 'physical') === $ot) ? 'selected' : '' ?>><?= h(ucfirst($ot)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label>Pricing model</label>
              <select name="pricing_model" class="form-control">
                <?php foreach (['one_time','recurring','quote','free','wholesale_tier'] as $pm): ?>
                  <option value="<?= h($pm) ?>" <?= (($editProduct['pricing_model'] ?? 'one_time') === $pm) ? 'selected' : '' ?>><?= h(str_replace('_', ' ', ucfirst($pm))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label>Category</label>
              <input type="text" name="category" class="form-control" maxlength="80" value="<?= h((string)($editProduct['category'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label>Status</label>
              <select name="status" class="form-control">
                <?php foreach (['draft', 'active', 'sold_out', 'archived'] as $st): ?>
                  <option value="<?= h($st) ?>" <?= (($editProduct['status'] ?? 'draft') === $st) ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $st))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>SEO title</label>
              <input type="text" name="seo_title" class="form-control" maxlength="200" value="<?= h((string)($editProduct['seo_title'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label>Cover image</label>
              <input type="file" name="cover_image" class="form-control" accept="image/*">
            </div>
          </div>
          <div class="col-md-12">
            <div class="form-group">
              <label>SEO description</label>
              <input type="text" name="seo_description" class="form-control" maxlength="320" value="<?= h((string)($editProduct['seo_description'] ?? '')) ?>">
            </div>
          </div>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" class="form-control" rows="3"><?= h((string)($editProduct['description'] ?? '')) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?= $editProduct ? 'Update product' : 'Add product' ?></button>
        <?php if ($editProduct): ?>
          <a href="products.php" class="btn btn-outline-secondary mg-l-5">Cancel edit</a>
        <?php endif; ?>
      </form>

      <div class="table-responsive">
        <table class="table table-hover mg-b-0">
          <thead>
            <tr>
              <th></th>
              <th>Title / SKU</th>
              <th>Type</th>
              <th>Price</th>
              <th>Stock</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$products): ?>
              <tr><td colspan="7" class="text-center tx-color-03">No products yet.</td></tr>
            <?php else: foreach ($products as $p): ?>
              <?php
                $coverRaw = trim((string)($p['cover_image_path'] ?? ''));
                $cover = ($coverRaw !== '' && !preg_match('#^https?://#i', $coverRaw)) ? ltrim($coverRaw, '/') : $coverRaw;
                $price = org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD'));
              ?>
              <tr>
                <td style="width:56px;">
                  <?php if ($cover !== ''): ?>
                    <img src="<?= h($cover) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                  <?php endif; ?>
                </td>
                <td>
                  <strong><?= h((string)$p['title']) ?></strong>
                  <?php if (trim((string)($p['sku'] ?? '')) !== ''): ?>
                    <div class="tx-12 tx-color-03">SKU <?= h((string)$p['sku']) ?></div>
                  <?php endif; ?>
                  <?php if (trim((string)($p['category'] ?? '')) !== ''): ?>
                    <div class="tx-12 tx-color-03"><?= h((string)$p['category']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="tx-12"><?= h((string)($p['offer_type'] ?? 'physical')) ?></td>
                <td><?= h($price) ?></td>
                <td><?= $p['stock_qty'] === null ? '—' : (int)$p['stock_qty'] ?></td>
                <td><span class="badge badge-light"><?= h((string)$p['status']) ?></span></td>
                <td class="text-right">
                  <a href="products.php?edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                  <?php if ((string)($p['status'] ?? '') === 'active'): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="publish_feed">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-success">Publish to Feed</button>
                  </form>
                  <?php endif; ?>
                  <?php if (!empty($p['public_post_id'])): ?>
                    <span class="tx-11 tx-color-03 d-block mg-t-5">Feed post #<?= (int)$p['public_post_id'] ?></span>
                  <?php endif; ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Remove this product?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
