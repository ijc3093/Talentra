<?php
declare(strict_types=1);

$payoutRows = org_sales_payout_rows($dbh, (int)$orgId, 200);
$payoutTotals = org_sales_payout_totals($dbh, (int)$orgId);
$paidCents = (int)$payoutTotals['paid_cents'];
$pendingCents = (int)$payoutTotals['pending_cents'] + (int)$payoutTotals['scheduled_cents'];
$totalCents = $paidCents + $pendingCents;
$totalCount = count($payoutRows);
$averageCents = $totalCount ? (int)round($totalCents / $totalCount) : 0;
?>
<style>
/* Payments hub: fill viewport, raise cards to the bottom edge. */
.sales-management-view[data-sales-view="payments"].is-active,
html[data-sales-initial-view="payments"] .sales-management-view[data-sales-view="payments"],
html[data-sales-active-view="payments"] .sales-management-view[data-sales-view="payments"]{
  display:flex !important;
  flex-direction:column;
  flex:1 1 auto;
  min-height:0;
  height:auto !important;
  max-height:none !important;
  overflow:hidden !important;
  padding:0 4px 8px 0 !important;
  margin:0 !important;
  box-sizing:border-box;
}
html[data-sales-initial-view="payments"] body.org-app,
html[data-sales-active-view="payments"] body.org-app,
html[data-sales-initial-view="payments"] body.org-app .sh-mainpanel,
html[data-sales-active-view="payments"] body.org-app .sh-mainpanel,
html[data-sales-initial-view="payments"] body.org-app .sh-pagebody,
html[data-sales-active-view="payments"] body.org-app .sh-pagebody{
  height:100vh !important;
  max-height:100vh !important;
  overflow:hidden !important;
  box-sizing:border-box !important;
}
html[data-sales-initial-view="payments"] body.org-app .sh-pagebody,
html[data-sales-active-view="payments"] body.org-app .sh-pagebody{
  display:flex !important;
  flex-direction:column !important;
  min-height:0 !important;
}
.pyo{
  --ink:#10204a;--muted:#667392;--line:#dce5f1;color:var(--ink);
  position:relative;box-sizing:border-box;
  display:flex;flex-direction:column;
  min-height:0;
  height:calc(100vh - var(--org-header-h, 48px) - 20px);
  max-height:calc(100vh - var(--org-header-h, 48px) - 20px);
  overflow:hidden;
}
.pyo *{box-sizing:border-box}
.pyo-top{position:relative;right:auto;top:auto;z-index:1;display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:0 0 10px;flex:0 0 auto}
.pyo-top .sm-hub-actions{display:inline-flex;align-items:center;gap:8px;margin-left:auto}
.pyo-btn,.pyo-filter select,.pyo-filter button{height:38px;border:1px solid var(--line);border-radius:9px;background:var(--ch-surface,#fff);color:var(--ink);padding:0 13px;font-weight:700}
.pyo-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:0 0 12px;flex:0 0 auto}
.pyo-kpi{border:1px solid var(--line);background:var(--ch-surface,#fff);border-radius:12px;padding:14px;display:flex;gap:13px;align-items:center}
.pyo-icon{width:40px;height:40px;flex:0 0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;background:#4f46e5}
.pyo-icon.green{background:#059669}.pyo-icon.orange{background:#f59e0b}.pyo-icon.red{background:#ef233c}.pyo-icon.blue{background:#155eef}
.pyo-kpi small{display:block;color:var(--muted);font-weight:700}
.pyo-kpi strong{display:block;font-size:20px;margin:3px 0}
.pyo-trend{font-size:11px;color:#159c68}
.pyo-filter{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:12px;flex:0 0 auto}
.pyo-search{height:38px;display:flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:9px;background:var(--ch-surface,#fff);padding:0 12px;flex:1 1 250px}
.pyo-search input{border:0;outline:0;width:100%;background:transparent}
.pyo-layout{
  display:grid;
  grid-template-columns:minmax(600px,2.2fr) minmax(290px,.8fr);
  gap:16px;
  flex:1 1 auto;
  min-height:0;
  overflow:hidden;
}
.pyo-card{
  background:var(--ch-surface,#fff);
  border:1px solid var(--line);
  border-radius:11px;
  overflow:hidden;
  min-height:0;
  height:100%;
  display:flex;
  flex-direction:column;
}
.pyo-table-wrap{flex:1 1 auto;min-height:0;overflow:auto}
.pyo-table{width:100%;border-collapse:collapse;min-width:800px}
.pyo-table th,.pyo-table td{padding:12px 13px;border-bottom:1px solid var(--line);text-align:left;font-size:12px}
.pyo-table th{color:var(--muted);background:var(--ch-surface,#fbfcfe);font-size:11px;position:sticky;top:0;z-index:1}
.pyo-table tr{cursor:pointer}
.pyo-table tr.is-selected{background:#f3f7ff}
.pyo-id{color:#155eef;font-weight:800}
.pyo-status{display:inline-flex;gap:5px;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800}
.pyo-status.paid{background:#dcfce7;color:#15803d}
.pyo-status.pending,.pyo-status.scheduled{background:#ffedd5;color:#c2410c}
.pyo-more{width:31px;height:31px;border:1px solid #dbeafe;background:var(--ch-surface,#fff);border-radius:7px;color:#17346d}
.pyo-foot{
  flex:0 0 auto;
  display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;
  padding:12px 14px;color:var(--muted);font-size:12px;
  border-top:1px solid var(--line);background:var(--ch-surface,#fff);
  margin-top:auto;
}
.pyo-pages{display:flex;gap:4px;align-items:center;flex-wrap:wrap}
.pyo-pages button{min-width:30px;height:30px;border:1px solid #e2e8f0;background:#fff;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;color:#0f172a}
.pyo-pages button:hover{background:#f8fafc}
.pyo-pages button.is-on{background:#2563eb;border-color:#2563eb;color:#fff}
.pyo-pages button:disabled{opacity:.45;cursor:default}
.pyo-pages .pyo-page-gap{padding:0 4px;font-weight:700;color:var(--muted)}
.pyo-foot select{height:30px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;padding:0 8px;font-size:12px;font-weight:700;color:#0f172a}
.pyo-detail{padding:0;overflow:hidden}
.pyo-detail-scroll{flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;padding:18px;-webkit-overflow-scrolling:touch}
.pyo-detail-head{border-bottom:1px solid var(--line);margin:-18px -18px 15px;padding:18px}
.pyo-detail h3{margin:0 0 10px;font-size:13px}
.pyo-amount{font-size:24px;font-weight:800;margin:14px 0 4px}
.pyo-muted{color:var(--muted);font-size:12px}
.pyo-def{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;font-size:12px;margin-bottom:20px}
.pyo-def span:nth-child(odd){color:var(--muted)}
.pyo-green{color:#159c68}
.pyo-timeline{border-left:2px solid #d9eee5;margin-left:11px;padding-left:20px}
.pyo-event{position:relative;margin:0 0 18px;font-size:12px}
.pyo-event:before{content:'✓';position:absolute;left:-34px;top:-3px;width:24px;height:24px;border-radius:50%;background:#dcfce7;color:#159c68;display:flex;align-items:center;justify-content:center}
.pyo-empty{padding:30px;text-align:center;color:var(--muted)}
@media(max-width:1150px){
  .pyo{height:auto;max-height:none;overflow:visible}
  .pyo-kpis{grid-template-columns:repeat(2,1fr)}
  .pyo-layout{grid-template-columns:1fr;overflow:visible}
  .pyo-card{height:auto}
  .pyo-detail-scroll{max-height:420px}
}
@media(max-width:650px){.pyo-top{position:static;margin-bottom:10px}.pyo-kpis{grid-template-columns:1fr}}
</style>
<div class="pyo" id="payoutPanel">
  <div class="pyo-top"><?php if (function_exists('org_sales_hub_intro')) { org_sales_hub_intro('payments'); } ?><div class="sm-hub-actions"><button class="pyo-btn" id="pyoExport" type="button"><i class="fa fa-download"></i> Export</button></div></div>
  <div class="pyo-kpis">
    <div class="pyo-kpi"><span class="pyo-icon"><i class="fa fa-dollar"></i></span><div><small>Total Payouts</small><strong><?= org_ecommerce_h(org_sales_money($totalCents)) ?></strong><span class="pyo-trend">All eligible orders</span></div></div>
    <div class="pyo-kpi"><span class="pyo-icon green"><i class="fa fa-check"></i></span><div><small>Successful Payouts</small><strong><?= org_ecommerce_h(org_sales_money($paidCents)) ?></strong><span class="pyo-trend"><?= (int)$payoutTotals['paid_count'] ?> paid</span></div></div>
    <div class="pyo-kpi"><span class="pyo-icon orange"><i class="fa fa-clock-o"></i></span><div><small>Pending Payouts</small><strong><?= org_ecommerce_h(org_sales_money($pendingCents)) ?></strong><span class="pyo-trend"><?= (int)$payoutTotals['pending_count'] + (int)$payoutTotals['scheduled_count'] ?> pending</span></div></div>
    <div class="pyo-kpi"><span class="pyo-icon red">!</span><div><small>Failed Payouts</small><strong>$0.00</strong><span class="pyo-trend">0 failed</span></div></div>
    <div class="pyo-kpi"><span class="pyo-icon blue"><i class="fa fa-credit-card"></i></span><div><small>Average Payout</small><strong><?= org_ecommerce_h(org_sales_money($averageCents)) ?></strong><span class="pyo-trend">Per order</span></div></div>
  </div>
  <div class="pyo-filter">
    <label class="pyo-search"><i class="fa fa-search"></i><input id="pyoSearch" type="search" placeholder="Search by payout ID, reference, or name..."></label>
    <select id="pyoStatus"><option value="">All Payout Status</option><option value="paid">Paid</option><option value="pending">Pending</option><option value="scheduled">Scheduled</option></select>
    <button type="button" id="pyoReset"><i class="fa fa-refresh"></i> Reset</button>
  </div>
  <div class="pyo-layout">
    <div class="pyo-card">
      <div class="pyo-table-wrap">
        <table class="pyo-table">
          <thead><tr><th>Payout ID</th><th>Payout Date</th><th>Status</th><th>Amount</th><th>Method</th><th>Account</th><th>Reference</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$payoutRows): ?><tr><td colspan="8" class="pyo-empty">No eligible payouts yet.</td></tr><?php endif; ?>
          <?php foreach ($payoutRows as $i => $row):
            $status = strtolower((string)($row['payout_status'] ?? 'pending'));
            if (!in_array($status, ['paid', 'pending', 'scheduled'], true)) $status = 'pending';
            $date = (string)($row['paid_at'] ?: $row['created_at']);
            $ts = strtotime($date) ?: time();
            $id = 'PAYOUT-' . str_pad((string)(int)$row['id'], 6, '0', STR_PAD_LEFT);
          ?>
            <tr class="pyo-row<?= $i === 0 ? ' is-selected' : '' ?>"
              data-oid="<?= (int)$row['id'] ?>"
              data-search="<?= org_ecommerce_h(strtolower($id . ' ' . ($row['order_code'] ?? '') . ' ' . ($row['buyer_name'] ?? ''))) ?>"
              data-status="<?= org_ecommerce_h($status) ?>"
              data-id="<?= org_ecommerce_h($id) ?>"
              data-amount="<?= org_ecommerce_h(org_sales_money((int)$row['seller_payout_cents'], (string)$row['currency'])) ?>"
              data-date="<?= org_ecommerce_h(date('M j, Y g:i A', $ts)) ?>"
              data-ref="<?= org_ecommerce_h((string)$row['order_code']) ?>"
              data-orders="1"
              data-total="<?= org_ecommerce_h(org_sales_money((int)$row['total_cents'], (string)$row['currency'])) ?>"
              data-fees="<?= org_ecommerce_h(org_sales_money(max(0, (int)$row['total_cents'] - (int)$row['seller_payout_cents']), (string)$row['currency'])) ?>">
              <td class="pyo-id"><?= org_ecommerce_h($id) ?></td>
              <td><?= org_ecommerce_h(date('M j, Y', $ts)) ?><br><small><?= org_ecommerce_h(date('g:i A', $ts)) ?></small></td>
              <td><span class="pyo-status <?= org_ecommerce_h($status) ?>"><?= $status === 'paid' ? '✓ ' : '' ?><?= org_ecommerce_h(ucfirst($status)) ?></span></td>
              <td><strong><?= org_ecommerce_h(org_sales_money((int)$row['seller_payout_cents'], (string)$row['currency'])) ?></strong></td>
              <td><i class="fa fa-bank"></i> Bank Transfer</td>
              <td>•••• 4242</td>
              <td><a href="order_details.php?id=<?= (int)$row['id'] ?>"><?= org_ecommerce_h((string)$row['order_code']) ?></a></td>
              <td><button class="pyo-more" type="button">•••</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="pyo-foot" id="pyoFoot"<?= !$payoutRows ? ' hidden' : '' ?>>
        <div id="pyoFootLabel">Showing 0 of 0 payouts</div>
        <div class="pyo-pages" id="pyoPages" aria-label="Pagination"></div>
        <label>
          <select id="pyoPageSize" aria-label="Rows per page">
            <option value="5" selected>5 / page</option>
            <option value="10">10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
          </select>
        </label>
      </div>
    </div>
    <aside class="pyo-card pyo-detail" id="pyoDetail">
      <div class="pyo-detail-scroll">
        <div class="pyo-detail-head">
          <div><span class="pyo-id" id="pdId">Select a payout</span> <span class="pyo-status paid" id="pdStatus"></span></div>
          <div class="pyo-amount" id="pdAmount">—</div>
          <div class="pyo-muted" id="pdPaid"></div>
        </div>
        <h3>Overview</h3>
        <div class="pyo-def">
          <span>Payout ID</span><strong id="pdId2">—</strong>
          <span>Status</span><strong id="pdStatus2">—</strong>
          <span>Payout Date</span><strong id="pdDate">—</strong>
          <span>Reference</span><strong id="pdRef">—</strong>
          <span>Orders</span><strong id="pdOrders">—</strong>
          <span>Total Sales</span><strong id="pdTotal">—</strong>
          <span>Fees &amp; Deductions</span><strong id="pdFees">—</strong>
          <span>Payout Amount</span><strong class="pyo-green" id="pdAmount2">—</strong>
        </div>
        <h3>Payout Method</h3>
        <div class="pyo-def">
          <span>Method</span><strong>Bank Transfer</strong>
          <span>Account</span><strong>•••• 4242</strong>
          <span>Bank Name</span><strong>Seller bank account</strong>
        </div>
        <h3>Timeline</h3>
        <div class="pyo-timeline">
          <div class="pyo-event"><strong>Payout initiated</strong><div class="pyo-muted" id="pdTime1"></div></div>
          <div class="pyo-event"><strong>Processing</strong></div>
          <div class="pyo-event"><strong>Payout status updated</strong></div>
        </div>
        <a class="pyo-btn" id="pdViewDetails" href="#" style="display:flex;justify-content:center;text-decoration:none;margin-top:8px">View Payout Details <i class="fa fa-external-link"></i></a>
      </div>
    </aside>
  </div>
</div>
<script>
(function () {
  var root = document.getElementById('payoutPanel');
  if (!root) return;
  var rows = [].slice.call(root.querySelectorAll('.pyo-row'));
  var q = document.getElementById('pyoSearch');
  var status = document.getElementById('pyoStatus');
  var pageSizeEl = document.getElementById('pyoPageSize');
  var foot = document.getElementById('pyoFoot');
  var footLabel = document.getElementById('pyoFootLabel');
  var pagesEl = document.getElementById('pyoPages');
  var page = 1;

  function set(id, v) {
    var e = document.getElementById(id);
    if (e) e.textContent = v;
  }
  function select(r) {
    rows.forEach(function (x) { x.classList.toggle('is-selected', x === r); });
    var d = r.dataset;
    var s = d.status.charAt(0).toUpperCase() + d.status.slice(1);
    var link = document.getElementById('pdViewDetails');
    set('pdId', d.id);
    set('pdId2', d.id);
    set('pdStatus', s);
    set('pdStatus2', s);
    set('pdAmount', d.amount);
    set('pdAmount2', d.amount);
    set('pdPaid', (d.status === 'paid' ? 'Paid on ' : 'Updated ') + d.date);
    set('pdDate', d.date);
    set('pdRef', d.ref);
    set('pdOrders', d.orders);
    set('pdTotal', d.total);
    set('pdFees', '-' + d.fees);
    set('pdTime1', d.date);
    if (link) link.href = 'payout_detail.php?id=' + encodeURIComponent(d.oid);
  }
  function visibleRows() {
    var text = (q && q.value || '').toLowerCase();
    var st = status && status.value || '';
    return rows.filter(function (r) {
      return (!text || r.dataset.search.indexOf(text) > -1) && (!st || r.dataset.status === st);
    });
  }
  function render() {
    var vis = visibleRows();
    var size = Math.max(1, parseInt(pageSizeEl && pageSizeEl.value, 10) || 5);
    var total = vis.length;
    var pages = Math.max(1, Math.ceil(total / size) || 1);
    if (page > pages) page = pages;
    if (page < 1) page = 1;
    var start = (page - 1) * size;
    var end = Math.min(total, start + size);
    rows.forEach(function (r) { r.hidden = true; });
    vis.forEach(function (r, i) {
      r.hidden = !(i >= start && i < end);
    });
    if (foot) foot.hidden = total === 0;
    if (footLabel) {
      footLabel.textContent = total === 0
        ? 'No matching payouts'
        : ('Showing ' + (total ? (start + 1) : 0) + '–' + end + ' of ' + total + ' payouts');
    }
    if (pagesEl) {
      pagesEl.innerHTML = '';
      function addBtn(label, to, on, disabled) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        if (on) b.className = 'is-on';
        if (disabled) b.disabled = true;
        b.addEventListener('click', function () {
          if (disabled || to === page) return;
          page = to;
          render();
        });
        pagesEl.appendChild(b);
      }
      function addGap() {
        var s = document.createElement('span');
        s.className = 'pyo-page-gap';
        s.textContent = '…';
        pagesEl.appendChild(s);
      }
      addBtn('‹', Math.max(1, page - 1), false, page <= 1);
      var nums = [];
      for (var i = 1; i <= pages; i++) {
        if (i === 1 || i === pages || (i >= page - 2 && i <= page + 2)) nums.push(i);
      }
      var prev = 0;
      nums.forEach(function (n) {
        if (prev && n - prev > 1) addGap();
        addBtn(String(n), n, n === page, false);
        prev = n;
      });
      addBtn('›', Math.min(pages, page + 1), false, page >= pages);
    }
    var firstVisible = vis.slice(start, end)[0];
    if (firstVisible && !root.querySelector('.pyo-row.is-selected:not([hidden])')) {
      select(firstVisible);
    }
  }

  rows.forEach(function (r) {
    r.addEventListener('click', function () { select(r); });
  });
  [q, status, pageSizeEl].forEach(function (el) {
    if (!el) return;
    el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', function () {
      page = 1;
      render();
    });
  });
  document.getElementById('pyoReset').addEventListener('click', function () {
    if (q) q.value = '';
    if (status) status.value = '';
    page = 1;
    render();
  });
  document.getElementById('pyoExport').addEventListener('click', function () {
    var lines = ['Payout ID,Date,Status,Amount,Reference'];
    visibleRows().forEach(function (r) {
      lines.push([r.dataset.id, r.dataset.date, r.dataset.status, r.dataset.amount, r.dataset.ref]
        .map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; })
        .join(','));
    });
    var b = new Blob([lines.join('\n')], { type: 'text/csv' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(b);
    a.download = 'payouts.csv';
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 500);
  });
  if (rows[0]) select(rows[0]);
  render();
})();
</script>
