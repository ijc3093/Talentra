<?php
declare(strict_types=1);

/**
 * Shared inventory / product table UI for product_table.php and sales_management.php#inventory.
 *
 * Expected vars:
 * - PDO $dbh
 * - int $orgId
 * - string $err
 * - string $ok
 * - string $ptBackHref (optional) e.g. commerce.php
 * - string $ptBackLabel (optional)
 * - string $ptFormAction (optional) e.g. sales_management.php#inventory
 * - string $ptTitle (optional, default Inventory)
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
$orderedQtyMap = function_exists('org_shop_product_ordered_qty_map')
    ? org_shop_product_ordered_qty_map($dbh, (int)$orgId)
    : [];

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
$ptTitle = trim((string)($ptTitle ?? 'Inventory'));
if ($ptTitle === '') {
    $ptTitle = 'Inventory';
}
$err = (string)($err ?? '');
$ok = (string)($ok ?? '');

// Group inventory by category for the right-hand panel.
$ptCategoryGroups = [];
foreach ($products as $__p) {
    $catLabel = trim((string)($__p['category'] ?? ''));
    if ($catLabel === '') {
        $catLabel = 'Uncategorized';
    }
    $catKey = mb_strtolower($catLabel);
    if (!isset($ptCategoryGroups[$catKey])) {
        $ptCategoryGroups[$catKey] = [
            'name' => $catLabel,
            'count' => 0,
            'stock' => 0,
            'ordered' => 0,
            'product_total' => 0,
            'stock_tracked' => false,
        ];
    }
    $pid = (int)($__p['id'] ?? 0);
    $ord = (int)($orderedQtyMap[$pid] ?? 0);
    $tracked = $__p['stock_qty'] !== null && $__p['stock_qty'] !== '';
    $stk = $tracked ? max(0, (int)$__p['stock_qty']) : 0;
    $ptCategoryGroups[$catKey]['count']++;
    $ptCategoryGroups[$catKey]['ordered'] += $ord;
    if ($tracked) {
        $ptCategoryGroups[$catKey]['stock_tracked'] = true;
        $ptCategoryGroups[$catKey]['stock'] += $stk;
        $ptCategoryGroups[$catKey]['product_total'] += $stk + $ord;
    } else {
        $ptCategoryGroups[$catKey]['product_total'] += $ord;
    }
}
uasort($ptCategoryGroups, static function (array $a, array $b): int {
    $cmp = strcasecmp((string)$a['name'], (string)$b['name']);
    if ($cmp !== 0) {
        return $cmp;
    }
    return ((int)$b['count']) <=> ((int)$a['count']);
});
$ptCategoryGroups = array_values($ptCategoryGroups);
?>
  <div class="pt-head">
    <div>
      <?php if ($ptShowBack): ?>
        <a href="<?= h($ptBackHref) ?>" class="pt-back">&larr; <?= h($ptBackLabel) ?></a>
      <?php endif; ?>
      <h1><?= h($ptTitle) ?></h1>
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

  <div class="pt-layout">
  <div class="pt-card pt-card-main">
    <div class="pt-table-wrap">
      <table class="pt-table" id="ptInventoryTable">
        <thead>
          <tr>
            <th class="pt-col-id">ID</th>
            <th class="pt-col-product">Title / SKU</th>
            <th>Type</th>
            <th>Price</th>
            <th title="Total units accounted for (Stock + Ordered)">Product #</th>
            <th>Stock</th>
            <th>Ordered #</th>
            <th>Status</th>
            <th class="pt-col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$products): ?>
            <tr>
              <td colspan="9" class="pt-empty">No products yet. <a href="products.php">Add your first product</a>.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($products as $p): ?>
              <?php
                $cover = product_table_cover_url((string)($p['cover_image_path'] ?? ''));
                $price = org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD'));
                $status = (string)($p['status'] ?? 'draft');
                $category = trim((string)($p['category'] ?? ''));
                $categoryKey = mb_strtolower($category !== '' ? $category : 'Uncategorized');
                $sku = trim((string)($p['sku'] ?? ''));
                $subLabel = $category !== '' ? $category : ($sku !== '' ? 'SKU ' . $sku : '');
                $productCode = trim((string)($p['product_code'] ?? ''));
                if ($productCode === '' && function_exists('org_shop_ensure_product_code')) {
                    $productCode = org_shop_ensure_product_code($dbh, (int)$orgId, (int)($p['id'] ?? 0), '');
                }
                $orderedQty = (int)($orderedQtyMap[(int)($p['id'] ?? 0)] ?? 0);
                $stockTracked = $p['stock_qty'] !== null && $p['stock_qty'] !== '';
                $stockQty = $stockTracked ? (int)$p['stock_qty'] : null;
                // Product # = remaining stock + units already ordered (so 8 + 2 = 10).
                // Helps the seller confirm nothing is missing from inventory.
                $productTotalQty = $stockTracked ? max(0, $stockQty) + max(0, $orderedQty) : max(0, $orderedQty);
                $detailUrl = $ptDetailBase . (int)($p['id'] ?? 0) . $ptDetailSuffix;
              ?>
              <tr class="pt-row-link" data-href="<?= h($detailUrl) ?>" data-category="<?= h($categoryKey) ?>" tabindex="0" role="link" aria-label="Open product <?= h($productCode !== '' ? $productCode : (string)(int)($p['id'] ?? 0)) ?>">
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
                <td class="pt-product-number" title="Stock + Ordered = total units accounted for">
                  <span class="pt-num" style="font-weight:800;"><?= (int)$productTotalQty ?></span>
                </td>
                <td class="pt-muted">
                  <?php if (!$stockTracked): ?>
                    —
                  <?php else: ?>
                    <?php if ($stockQty < 5): ?>
                      <span class="pt-stock-low-badge" title="Low inventory (less than 5)"><?= (int)$stockQty ?></span>
                    <?php else: ?>
                      <span class="pt-num"><?= (int)$stockQty ?></span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="pt-muted">
                  <span class="pt-num"><?= (int)$orderedQty ?></span>
                </td>
                <td>
                  <?php
                    $statusLabel = $status === 'sold_out' ? 'sold out' : $status;
                  ?>
                  <span class="pt-status <?= product_table_status_class($status) ?>"><?= h($statusLabel) ?></span>
                </td>
                <td class="pt-col-actions">
                  <div class="pt-fries-wrap">
                    <button type="button" class="pt-fries-btn" aria-label="Product actions" aria-haspopup="true" aria-expanded="false" title="More">
                      <span class="pt-fries-icon" aria-hidden="true">
                        <span class="pt-fries-bar"></span>
                        <span class="pt-fries-bar pt-fries-bar--short"></span>
                        <span class="pt-fries-bar"></span>
                        <span class="pt-fries-bar pt-fries-bar--short"></span>
                      </span>
                    </button>
                    <div class="pt-fries-menu" role="menu" hidden>
                      <a href="<?= h($detailUrl) ?>" class="pt-fries-item" role="menuitem">View</a>
                      <a href="<?= h($ptEditBase . (int)$p['id'] . $ptEditHash) ?>" class="pt-fries-item" role="menuitem">Edit</a>
                      <?php if ($status === 'active'): ?>
                        <form method="post"<?= $ptFormAction !== '' ? ' action="' . h($ptFormAction) . '"' : '' ?> class="pt-fries-form">
                          <input type="hidden" name="pt_action" value="1">
                          <input type="hidden" name="action" value="publish_feed">
                          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                          <button type="submit" class="pt-fries-item" role="menuitem">Publish to Feed</button>
                        </form>
                      <?php endif; ?>
                      <form method="post"<?= $ptFormAction !== '' ? ' action="' . h($ptFormAction) . '"' : '' ?> class="pt-fries-form" onsubmit="return confirm('Remove this product?');">
                        <input type="hidden" name="pt_action" value="1">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="pt-fries-item is-danger" role="menuitem">Delete</button>
                      </form>
                      <?php if (!empty($p['public_post_id'])): ?>
                        <a href="feed.php?id=<?= (int)$p['public_post_id'] ?>" class="pt-fries-item pt-fries-item-meta" role="menuitem">Feed post #<?= (int)$p['public_post_id'] ?></a>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <aside class="pt-card pt-categories" aria-label="Product categories">
    <div class="pt-categories-head">
      <h2>Categories</h2>
      <button type="button" class="pt-cat-all is-active" data-pt-cat="" title="Show all products">All</button>
    </div>
    <?php if (!$ptCategoryGroups): ?>
      <p class="pt-categories-empty">No categories yet. Add a category when you create a product.</p>
    <?php else: ?>
      <ul class="pt-cat-list">
        <?php foreach ($ptCategoryGroups as $g):
          $gName = (string)($g['name'] ?? 'Category');
          $gKey = mb_strtolower($gName);
          $gCount = (int)($g['count'] ?? 0);
          $gStock = (int)($g['stock'] ?? 0);
          $gOrdered = (int)($g['ordered'] ?? 0);
          $gTotal = (int)($g['product_total'] ?? 0);
          $gTracked = !empty($g['stock_tracked']);
        ?>
          <li>
            <button type="button" class="pt-cat-item" data-pt-cat="<?= h($gKey) ?>">
              <span class="pt-cat-name"><?= h($gName) ?></span>
              <span class="pt-cat-count"><?= $gCount ?> listing<?= $gCount === 1 ? '' : 's' ?></span>
              <span class="pt-cat-meta">
                <?php if ($gTracked): ?>
                  Product # <?= $gTotal ?> · Stock <?= $gStock ?> · Ordered <?= $gOrdered ?>
                <?php else: ?>
                  Ordered <?= $gOrdered ?>
                <?php endif; ?>
              </span>
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>
  </div>
  <script>
  (function(){
    document.querySelectorAll('tr.pt-row-link[data-href]').forEach(function(row){
      row.addEventListener('click', function(e){
        if (e.target.closest('a, button, input, form, label, .pt-fries-wrap')) return;
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

    function closeAllPtFries(except) {
      document.querySelectorAll('.pt-fries-wrap.is-open').forEach(function(wrap){
        if (except && wrap === except) return;
        wrap.classList.remove('is-open');
        var btn = wrap.querySelector('.pt-fries-btn');
        var menu = wrap.querySelector('.pt-fries-menu');
        if (btn) btn.setAttribute('aria-expanded', 'false');
        if (menu) {
          menu.hidden = true;
          menu.style.top = '';
          menu.style.right = '';
          menu.style.left = '';
          menu.style.position = '';
          menu.style.zIndex = '';
        }
      });
    }

    function openPtFries(wrap, btn) {
      var menu = wrap.querySelector('.pt-fries-menu');
      if (!menu) return;
      closeAllPtFries(wrap);
      wrap.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      menu.hidden = false;
      // Fixed position so overflow on the card/table does not clip the menu.
      var rect = btn.getBoundingClientRect();
      menu.style.position = 'fixed';
      menu.style.top = Math.round(rect.bottom + 4) + 'px';
      menu.style.right = Math.round(window.innerWidth - rect.right) + 'px';
      menu.style.left = 'auto';
      menu.style.zIndex = '9999';
    }

    document.querySelectorAll('.pt-fries-btn').forEach(function(btn){
      btn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        var wrap = btn.closest('.pt-fries-wrap');
        if (!wrap) return;
        if (wrap.classList.contains('is-open')) {
          closeAllPtFries(null);
          return;
        }
        openPtFries(wrap, btn);
      });
    });

    document.addEventListener('click', function(e){
      if (e.target.closest('.pt-fries-wrap') || e.target.closest('.pt-fries-menu')) return;
      closeAllPtFries(null);
    });

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') closeAllPtFries(null);
    });

    window.addEventListener('scroll', function(){ closeAllPtFries(null); }, true);
    window.addEventListener('resize', function(){ closeAllPtFries(null); });

    // Category filter (right panel).
    var catButtons = Array.prototype.slice.call(document.querySelectorAll('[data-pt-cat]'));
    var rows = Array.prototype.slice.call(document.querySelectorAll('#ptInventoryTable tbody tr.pt-row-link'));
    function setPtCategoryFilter(cat) {
      cat = String(cat || '').toLowerCase();
      catButtons.forEach(function(btn){
        var isAll = btn.classList.contains('pt-cat-all');
        var key = String(btn.getAttribute('data-pt-cat') || '').toLowerCase();
        var on = cat === '' ? isAll : (!isAll && key === cat);
        btn.classList.toggle('is-active', on);
      });
      rows.forEach(function(row){
        var rowCat = String(row.getAttribute('data-category') || '').toLowerCase();
        row.hidden = cat !== '' && rowCat !== cat;
      });
    }
    catButtons.forEach(function(btn){
      btn.addEventListener('click', function(){
        var key = String(btn.getAttribute('data-pt-cat') || '');
        if (btn.classList.contains('is-active') && key !== '') {
          setPtCategoryFilter('');
          return;
        }
        setPtCategoryFilter(key);
      });
    });
  })();
  </script>
