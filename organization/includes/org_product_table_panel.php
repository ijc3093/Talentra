<?php
declare(strict_types=1);

/**
 * Shared product table UI for product_table.php and sales_management.php#product-table.
 *
 * Expected vars:
 * - PDO $dbh
 * - int $orgId
 * - string $err
 * - string $ok
 * - string $ptBackHref (optional) e.g. commerce.php
 * - string $ptBackLabel (optional)
 * - string $ptFormAction (optional) e.g. sales_management.php#product-table
 * - bool $ptShowBack (optional, default true)
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

if (!function_exists('product_table_cover_url')) {
    function product_table_cover_url(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return ltrim($path, '/');
    }
}

if (!function_exists('product_table_status_class')) {
    function product_table_status_class(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'active') {
            return 'is-active';
        }
        if ($status === 'draft') {
            return 'is-draft';
        }
        if ($status === 'sold_out') {
            return 'is-sold-out';
        }
        return 'is-other';
    }
}

require_once dirname(__DIR__, 2) . '/public_user/includes/platform_rent.php';

$shopVisible = platform_rent_shop_is_visible($dbh, $orgId);
$maxProducts = org_shop_max_products($dbh, $orgId);
org_shop_sync_org_sold_out_stock($dbh, $orgId);
$productCount = org_shop_product_count($dbh, $orgId);
$products = org_shop_list_products($dbh, $orgId, false);

$ptBackHref = (string)($ptBackHref ?? 'commerce.php');
$ptBackLabel = (string)($ptBackLabel ?? 'Commerce hub');
$ptFormAction = (string)($ptFormAction ?? '');
$ptShowBack = !isset($ptShowBack) || (bool)$ptShowBack;
$ptAddHref = (string)($ptAddHref ?? 'products.php');
$ptAddAttr = (string)($ptAddAttr ?? '');
$ptEditBase = (string)($ptEditBase ?? 'products.php?edit=');
$ptEditHash = (string)($ptEditHash ?? '');
$ptDetailBase = (string)($ptDetailBase ?? 'products_detail.php?id=');
$ptDetailSuffix = (string)($ptDetailSuffix ?? '');
$err = (string)($err ?? '');
$ok = (string)($ok ?? '');
?>
  <div class="pt-head">
    <div>
      <?php if ($ptShowBack): ?>
        <a href="<?= h($ptBackHref) ?>" class="pt-back">&larr; <?= h($ptBackLabel) ?></a>
      <?php endif; ?>
      <h1>Product table</h1>
      <p><?= (int)$productCount ?> / <?= (int)$maxProducts ?> on your plan · manage listings, stock, and feed posts</p>
    </div>
    <div class="pt-head-actions">
      <a href="<?= h((string)($ptAddHref ?? 'products.php')) ?>" class="pt-btn pt-btn-primary"<?= (string)($ptAddAttr ?? '') ?>>+ Add product</a>
      <?php if ($shopVisible): ?>
        <span class="pt-pill is-live">Shop live</span>
      <?php else: ?>
        <span class="pt-pill is-warn">Shop hidden</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <div class="pt-card">
    <div class="pt-table-wrap">
      <table class="pt-table">
        <thead>
          <tr>
            <th class="pt-col-id">ID</th>
            <th class="pt-col-product">Title / SKU</th>
            <th>Type</th>
            <th>Price</th>
            <th>Stock</th>
            <th>Status</th>
            <th class="pt-col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$products): ?>
            <tr>
              <td colspan="7" class="pt-empty">No products yet. <a href="products.php">Add your first product</a>.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($products as $p): ?>
              <?php
                $cover = product_table_cover_url((string)($p['cover_image_path'] ?? ''));
                $price = org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD'));
                $status = (string)($p['status'] ?? 'draft');
                $category = trim((string)($p['category'] ?? ''));
                $sku = trim((string)($p['sku'] ?? ''));
                $subLabel = $category !== '' ? $category : ($sku !== '' ? 'SKU ' . $sku : '');
                $productCode = trim((string)($p['product_code'] ?? ''));
                if ($productCode === '' && function_exists('org_shop_ensure_product_code')) {
                    $productCode = org_shop_ensure_product_code($dbh, (int)$orgId, (int)($p['id'] ?? 0), '');
                }
                $detailUrl = $ptDetailBase . (int)($p['id'] ?? 0) . $ptDetailSuffix;
              ?>
              <tr class="pt-row-link" data-href="<?= h($detailUrl) ?>" tabindex="0" role="link" aria-label="Open product <?= h($productCode !== '' ? $productCode : (string)(int)($p['id'] ?? 0)) ?>">
                <td class="pt-col-id">
                  <a href="<?= h($detailUrl) ?>" class="pt-id-link" onclick="event.stopPropagation();">
                    <code class="pt-id-code"><?= h($productCode !== '' ? $productCode : ('#' . (int)($p['id'] ?? 0))) ?></code>
                  </a>
                </td>
                <td class="pt-col-product">
                  <a href="<?= h($detailUrl) ?>" class="pt-product-link" onclick="event.stopPropagation();">
                    <div class="pt-product-cell">
                      <div class="pt-thumb">
                        <?php if ($cover !== ''): ?>
                          <img src="<?= h($cover) ?>" alt="">
                        <?php else: ?>
                          <i class="icon ion-ios-box"></i>
                        <?php endif; ?>
                      </div>
                      <div class="pt-product-text">
                        <strong><?= h((string)$p['title']) ?></strong>
                        <?php if ($subLabel !== ''): ?>
                          <span><?= h($subLabel) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </a>
                </td>
                <td class="pt-muted"><?= h((string)($p['offer_type'] ?? 'physical')) ?></td>
                <td><?= h($price) ?></td>
                <td class="pt-muted">
                  <?php if ($p['stock_qty'] === null): ?>
                    —
                  <?php else: ?>
                    <?php $stockQty = (int)$p['stock_qty']; ?>
                    <?php if ($stockQty < 5): ?>
                      <span
                        class="pt-stock-low-badge"
                        title="Low inventory (less than 5)"
                        style="display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 8px;border-radius:999px;background:#dc3545!important;color:#fff!important;font-size:15px!important;font-weight:800!important;line-height:1;"
                      ><?= $stockQty ?></span>
                    <?php else: ?>
                      <span style="font-size:15px;font-weight:700;"><?= $stockQty ?></span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                    $statusLabel = $status === 'sold_out' ? 'sold out' : $status;
                  ?>
                  <span class="pt-status <?= product_table_status_class($status) ?>"><?= h($statusLabel) ?></span>
                </td>
                <td class="pt-col-actions" onclick="event.stopPropagation();">
                  <div class="pt-actions">
                    <a href="<?= h($detailUrl) ?>" class="pt-action">View</a>
                    <a href="<?= h($ptEditBase . (int)$p['id'] . $ptEditHash) ?>" class="pt-action">Edit</a>
                    <?php if ($status === 'active'): ?>
                      <form method="post"<?= $ptFormAction !== '' ? ' action="' . h($ptFormAction) . '"' : '' ?> class="pt-inline-form">
                        <input type="hidden" name="pt_action" value="1">
                        <input type="hidden" name="action" value="publish_feed">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="pt-action">Publish to Feed</button>
                      </form>
                    <?php endif; ?>
                    <form method="post"<?= $ptFormAction !== '' ? ' action="' . h($ptFormAction) . '"' : '' ?> class="pt-inline-form" onsubmit="return confirm('Remove this product?');">
                      <input type="hidden" name="pt_action" value="1">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="pt-action pt-action-danger">Delete</button>
                    </form>
                  </div>
                  <?php if (!empty($p['public_post_id'])): ?>
                    <div class="pt-feed-note">Feed post #<?= (int)$p['public_post_id'] ?></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <script>
  (function(){
    document.querySelectorAll('tr.pt-row-link[data-href]').forEach(function(row){
      row.addEventListener('click', function(e){
        if (e.target.closest('a, button, input, form, label')) return;
        var href = row.getAttribute('data-href');
        if (href) window.location.href = href;
      });
      row.addEventListener('keydown', function(e){
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (e.target !== row) return;
        e.preventDefault();
        var href = row.getAttribute('data-href');
        if (href) window.location.href = href;
      });
    });
  })();
  </script>
