<?php
declare(strict_types=1);

/**
 * Customers panel: buyers from orders + customers who messaged the seller.
 *
 * Expected vars: PDO $dbh, int $orgId
 * Optional: int $sellerMsgPublisherId, array $sellerBuyerMsgContacts
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$customers = [];
$customerMap = [];

$mergeCustomer = static function (array &$map, string $key, array $row): void {
    if ($key === '') {
        return;
    }
    if (!isset($map[$key])) {
        $map[$key] = $row;
        return;
    }
    $cur = &$map[$key];
    foreach (['name', 'email', 'phone', 'currency'] as $field) {
        if (trim((string)($cur[$field] ?? '')) === '' && trim((string)($row[$field] ?? '')) !== '') {
            $cur[$field] = $row[$field];
        }
    }
    if ((int)($row['buyer_user_id'] ?? 0) > 0) {
        $cur['buyer_user_id'] = (int)$row['buyer_user_id'];
    }
    if ((int)($row['id'] ?? 0) > 0 && ((int)($cur['id'] ?? 0) <= 0 || (int)$row['id'] < (int)$cur['id'])) {
        $cur['id'] = (int)$row['id'];
    }
    $cur['orders_count'] = max((int)($cur['orders_count'] ?? 0), (int)($row['orders_count'] ?? 0));
    $cur['spent_cents'] = max((int)($cur['spent_cents'] ?? 0), (int)($row['spent_cents'] ?? 0));
    if ((string)($row['joined_at'] ?? '') !== '' && (
        (string)($cur['joined_at'] ?? '') === ''
        || strcmp((string)$row['joined_at'], (string)$cur['joined_at']) < 0
    )) {
        $cur['joined_at'] = $row['joined_at'];
    }
    if ((string)($row['last_order_at'] ?? '') !== '' && (
        (string)($cur['last_order_at'] ?? '') === ''
        || strcmp((string)$row['last_order_at'], (string)$cur['last_order_at']) > 0
    )) {
        $cur['last_order_at'] = $row['last_order_at'];
    }
    if ((string)($row['last_message'] ?? '') !== '') {
        $cur['last_message'] = (string)$row['last_message'];
    }
    if ((string)($row['last_message_at'] ?? '') !== '' && (
        (string)($cur['last_message_at'] ?? '') === ''
        || strcmp((string)$row['last_message_at'], (string)$cur['last_message_at']) > 0
    )) {
        $cur['last_message_at'] = $row['last_message_at'];
    }
    $cur['unread'] = max((int)($cur['unread'] ?? 0), (int)($row['unread'] ?? 0));
    $cur['has_message'] = !empty($cur['has_message']) || !empty($row['has_message']);
    unset($cur);
};

// 1) Buyers from org orders (do not require email — shop checkouts often omit it).
try {
    $st = $dbh->prepare("
        SELECT
            MIN(o.id) AS id,
            MAX(o.buyer_user_id) AS buyer_user_id,
            MAX(NULLIF(TRIM(o.buyer_name), '')) AS name,
            MAX(NULLIF(TRIM(o.buyer_email), '')) AS email,
            MAX(NULLIF(TRIM(o.buyer_phone), '')) AS phone,
            COUNT(*) AS orders_count,
            COALESCE(SUM(o.total_cents), 0) AS spent_cents,
            MIN(o.created_at) AS joined_at,
            MAX(o.created_at) AS last_order_at,
            MAX(o.currency) AS currency,
            CASE
                WHEN o.buyer_user_id IS NOT NULL AND o.buyer_user_id > 0 THEN CONCAT('u:', o.buyer_user_id)
                WHEN o.buyer_email IS NOT NULL AND TRIM(o.buyer_email) <> '' THEN CONCAT('e:', LOWER(TRIM(o.buyer_email)))
                WHEN o.buyer_name IS NOT NULL AND TRIM(o.buyer_name) <> '' THEN CONCAT('n:', LOWER(TRIM(o.buyer_name)))
                ELSE CONCAT('o:', o.id)
            END AS cust_key
        FROM org_orders o
        WHERE o.org_id = :org
          AND o.status <> 'cancelled'
        GROUP BY cust_key
        ORDER BY last_order_at DESC
        LIMIT 500
    ");
    $st->execute([':org' => (int)$orgId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $buyerId = (int)($row['buyer_user_id'] ?? 0);
        $email = strtolower(trim((string)($row['email'] ?? '')));
        $key = trim((string)($row['cust_key'] ?? ''));
        if ($key === '') {
            $key = $buyerId > 0 ? ('u:' . $buyerId) : ($email !== '' ? ('e:' . $email) : ('o:' . (int)($row['id'] ?? 0)));
        }
        $mergeCustomer($customerMap, $key, [
            'id' => (int)($row['id'] ?? 0),
            'buyer_user_id' => $buyerId,
            'name' => trim((string)($row['name'] ?? '')) ?: 'Customer',
            'email' => trim((string)($row['email'] ?? '')),
            'phone' => trim((string)($row['phone'] ?? '')),
            'orders_count' => (int)($row['orders_count'] ?? 0),
            'spent_cents' => (int)($row['spent_cents'] ?? 0),
            'joined_at' => (string)($row['joined_at'] ?? ''),
            'last_order_at' => (string)($row['last_order_at'] ?? ''),
            'currency' => (string)($row['currency'] ?? 'USD'),
            'last_message' => '',
            'last_message_at' => '',
            'unread' => 0,
            'has_message' => false,
        ]);
    }
} catch (Throwable $e) {
    // Fallback: fetch lines in PHP and group (works on older MySQL / ONLY_FULL_GROUP_BY).
    try {
        $st = $dbh->prepare("
            SELECT id, buyer_user_id, buyer_name, buyer_email, buyer_phone,
                   total_cents, created_at, currency, status
            FROM org_orders
            WHERE org_id = :org
              AND status <> 'cancelled'
            ORDER BY created_at DESC, id DESC
            LIMIT 2000
        ");
        $st->execute([':org' => (int)$orgId]);
        $tmp = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $buyerId = (int)($row['buyer_user_id'] ?? 0);
            $email = strtolower(trim((string)($row['buyer_email'] ?? '')));
            $name = trim((string)($row['buyer_name'] ?? ''));
            if ($buyerId > 0) {
                $key = 'u:' . $buyerId;
            } elseif ($email !== '') {
                $key = 'e:' . $email;
            } elseif ($name !== '') {
                $key = 'n:' . mb_strtolower($name);
            } else {
                $key = 'o:' . (int)($row['id'] ?? 0);
            }
            if (!isset($tmp[$key])) {
                $tmp[$key] = [
                    'id' => (int)($row['id'] ?? 0),
                    'buyer_user_id' => $buyerId,
                    'name' => $name !== '' ? $name : 'Customer',
                    'email' => trim((string)($row['buyer_email'] ?? '')),
                    'phone' => trim((string)($row['buyer_phone'] ?? '')),
                    'orders_count' => 0,
                    'spent_cents' => 0,
                    'joined_at' => (string)($row['created_at'] ?? ''),
                    'last_order_at' => (string)($row['created_at'] ?? ''),
                    'currency' => (string)($row['currency'] ?? 'USD'),
                    'last_message' => '',
                    'last_message_at' => '',
                    'unread' => 0,
                    'has_message' => false,
                ];
            }
            $tmp[$key]['orders_count']++;
            $tmp[$key]['spent_cents'] += (int)($row['total_cents'] ?? 0);
            if ($buyerId > 0) {
                $tmp[$key]['buyer_user_id'] = $buyerId;
            }
            $created = (string)($row['created_at'] ?? '');
            if ($created !== '' && ($tmp[$key]['joined_at'] === '' || strcmp($created, $tmp[$key]['joined_at']) < 0)) {
                $tmp[$key]['joined_at'] = $created;
            }
            if ($created !== '' && strcmp($created, $tmp[$key]['last_order_at']) > 0) {
                $tmp[$key]['last_order_at'] = $created;
            }
            if ((int)($row['id'] ?? 0) > 0 && ((int)$tmp[$key]['id'] <= 0 || (int)$row['id'] < (int)$tmp[$key]['id'])) {
                $tmp[$key]['id'] = (int)$row['id'];
            }
        }
        foreach ($tmp as $key => $row) {
            $mergeCustomer($customerMap, $key, $row);
        }
    } catch (Throwable $e2) {
        // keep empty
    }
}

// Enrich names/emails from users table when we have buyer_user_id.
$userIds = [];
foreach ($customerMap as $row) {
    $uid = (int)($row['buyer_user_id'] ?? 0);
    if ($uid > 0) {
        $userIds[$uid] = true;
    }
}
if ($userIds) {
    try {
        $idList = implode(',', array_map('intval', array_keys($userIds)));
        $st = $dbh->query("
            SELECT id, email, phone,
                   COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), friend_code) AS display_name
            FROM users
            WHERE id IN ({$idList})
        ");
        $byId = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            $byId[(int)$u['id']] = $u;
        }
        foreach ($customerMap as $key => &$row) {
            $uid = (int)($row['buyer_user_id'] ?? 0);
            if ($uid <= 0 || !isset($byId[$uid])) {
                continue;
            }
            $u = $byId[$uid];
            if (trim((string)($row['name'] ?? '')) === '' || strcasecmp((string)$row['name'], 'Customer') === 0) {
                $row['name'] = trim((string)($u['display_name'] ?? '')) ?: $row['name'];
            }
            if (trim((string)($row['email'] ?? '')) === '') {
                $row['email'] = trim((string)($u['email'] ?? ''));
            }
            if (trim((string)($row['phone'] ?? '')) === '') {
                $row['phone'] = trim((string)($u['phone'] ?? ''));
            }
        }
        unset($row);
    } catch (Throwable $e) {
        // ignore
    }
}

// 2) Customers who messaged the seller (Shopping Preferences / product Message seller).
$msgContacts = is_array($sellerBuyerMsgContacts ?? null) ? $sellerBuyerMsgContacts : [];
if (!$msgContacts) {
    $pubId = (int)($sellerMsgPublisherId ?? 0);
    if ($pubId <= 0) {
        try {
            require_once dirname(__DIR__, 2) . '/public_user/includes/staff_publisher_access.php';
            $pubId = staff_pub_org_publisher_user_id($dbh, (int)$orgId);
        } catch (Throwable $e) {
            $pubId = 0;
        }
    }
    if ($pubId > 0) {
        try {
            require_once dirname(__DIR__, 2) . '/public_user/includes/commerce_messaging.php';
            $msgContacts = commerce_list_seller_buyer_contacts($dbh, $pubId);
        } catch (Throwable $e) {
            $msgContacts = [];
        }
    }
}

foreach ($msgContacts as $mc) {
    $buyerId = (int)($mc['buyer_user_id'] ?? 0);
    if ($buyerId <= 0) {
        continue;
    }
    $key = 'u:' . $buyerId;
    $lastMsg = trim((string)($mc['last_message'] ?? ''));
    $lastAt = (string)($mc['last_at'] ?? '');
    $unread = (int)($mc['unread'] ?? 0);
    $mergeCustomer($customerMap, $key, [
        'id' => $buyerId,
        'buyer_user_id' => $buyerId,
        'name' => trim((string)($mc['buyer_name'] ?? '')) ?: 'Customer',
        'email' => '',
        'phone' => '',
        'orders_count' => 0,
        'spent_cents' => 0,
        'joined_at' => $lastAt,
        'last_order_at' => '',
        'currency' => 'USD',
        'last_message' => $lastMsg,
        'last_message_at' => $lastAt,
        'unread' => $unread,
        'has_message' => $lastMsg !== '' || $unread > 0 || $lastAt !== '',
    ]);
}

$customers = array_values($customerMap);
usort($customers, static function (array $a, array $b): int {
    $aAt = (string)(($a['last_message_at'] ?? '') !== '' ? $a['last_message_at'] : ($a['last_order_at'] ?? ''));
    $bAt = (string)(($b['last_message_at'] ?? '') !== '' ? $b['last_message_at'] : ($b['last_order_at'] ?? ''));
    return strcmp($bAt, $aAt);
});

$total = count($customers);
$new = 0;
$repeat = 0;
$spent = 0;
$msgCustomers = 0;
foreach ($customers as $c) {
    $joined = strtotime((string)($c['joined_at'] ?? '')) ?: 0;
    if ($joined >= time() - 7 * 86400) {
        $new++;
    }
    if ((int)($c['orders_count'] ?? 0) > 1) {
        $repeat++;
    }
    $spent += (int)($c['spent_cents'] ?? 0);
    if (!empty($c['has_message'])) {
        $msgCustomers++;
    }
}
$orders = array_sum(array_map(static fn($c) => (int)($c['orders_count'] ?? 0), $customers));
$avg = $orders ? (int)round($spent / $orders) : 0;

$msgUrl = static function (array $c): string {
    $buyerId = (int)($c['buyer_user_id'] ?? 0);
    if ($buyerId > 0 && function_exists('commerce_message_buyer_sales_url')) {
        return commerce_message_buyer_sales_url($buyerId);
    }
    return 'sales_management.php#message';
};
?>
<style>
.cus-action-menu{display:none;position:fixed;z-index:9999;width:205px;padding:6px;background:var(--ch-surface,#fff);border:1px solid #dce5f1;border-radius:10px;box-shadow:0 14px 34px rgba(15,23,42,.18)}
.cus-action-menu.open{display:block}
.cus-action-menu a,.cus-action-menu button{display:flex;align-items:center;gap:9px;width:100%;padding:8px 9px;border:0;border-radius:7px;background:transparent;color:#10204a;text-decoration:none;text-align:left;font-size:11px;font-weight:700;cursor:pointer}
.cus-action-menu a:hover,.cus-action-menu button:hover{background:#f5f8ff}
.cus-action-menu i{width:15px;text-align:center}
.cus-action-sep{height:1px;background:#e2e8f0;margin:5px -6px}
.cus-action-menu .danger{color:#dc2626}
.cus{--t:#10204a;--m:#657292;--b:#dce5f1;color:var(--t);height:calc(100vh - var(--org-header-h,48px) - 24px);display:flex;flex-direction:column;gap:10px;overflow:hidden}
.cus *{box-sizing:border-box}.cus a{text-decoration:none}
.cus-top{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:8px}
.cus-top .sm-hub-actions{display:inline-flex;align-items:center;gap:8px;margin-left:auto}
.cus-btn,.cus-filter select,.cus-filter button{height:34px;border:1px solid var(--b);border-radius:8px;background:var(--ch-surface,#fff);color:var(--t);padding:0 12px;font-size:11px;font-weight:800}
.cus-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.cus-kpi{height:98px;border:1px solid var(--b);border-radius:11px;background:var(--ch-surface,#fff);padding:12px;display:flex;align-items:center;gap:11px}
.cus-ico{width:39px;height:39px;flex:0 0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#4f46e5;color:#fff;font-size:18px}
.cus-ico.orange{background:#f59e0b}.cus-ico.green{background:#059669}.cus-ico.blue{background:#155eef}.cus-ico.red{background:#ef233c}
.cus-kpi small{display:block;color:var(--m);font-weight:700;font-size:10px}
.cus-kpi strong{display:block;font-size:20px}
.cus-trend{font-size:9px;color:#159c68}
.cus-filter{display:flex;gap:8px}
.cus-search{height:34px;border:1px solid var(--b);border-radius:8px;background:var(--ch-surface,#fff);display:flex;align-items:center;gap:8px;padding:0 11px;flex:1}
.cus-search input{border:0;outline:0;width:100%;font-size:11px}
.cus-card{flex:1;min-height:0;border:1px solid var(--b);border-radius:11px;background:var(--ch-surface,#fff);display:flex;flex-direction:column;overflow:hidden}
.cus-table-wrap{flex:1;min-height:0;overflow:auto}
.cus-table{width:100%;border-collapse:collapse}
.cus-table th,.cus-table td{padding:9px 11px;border-bottom:1px solid var(--b);text-align:left;font-size:10px;vertical-align:middle}
.cus-table th{color:var(--m);background:var(--ch-surface,#fbfcfe);font-size:9px;position:sticky;top:0;z-index:1}
.cus-id{color:#155eef;font-weight:900}
.cus-person{display:flex;align-items:center;gap:8px;font-weight:800}
.cus-avatar{width:29px;height:29px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-weight:900}
.cus-status{display:inline-flex;padding:4px 8px;border-radius:999px;background:#dcfce7;color:#15803d;font-size:9px;font-weight:900}
.cus-status.inactive{background:#f1f5f9;color:#64748b}
.cus-msg{max-width:220px;color:var(--m);line-height:1.35}
.cus-msg strong{display:block;color:var(--t);font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cus-msg em{font-style:normal;font-size:9px}
.cus-unread{display:inline-flex;min-width:18px;height:18px;padding:0 5px;margin-left:6px;align-items:center;justify-content:center;border-radius:999px;background:#dc3545;color:#fff;font-size:10px;font-weight:800}
.cus-actions{display:flex;gap:6px;align-items:center}
.cus-msg-btn{height:29px;padding:0 10px;border:1px solid #bfdbfe;border-radius:7px;background:#eff6ff;color:#1d4ed8;font-size:10px;font-weight:800;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.cus-msg-btn:hover{background:#dbeafe;text-decoration:none}
.cus-more{width:29px;height:29px;border:1px solid var(--b);background:var(--ch-surface,#fff);border-radius:7px;color:#10204a;display:inline-flex;align-items:center;justify-content:center}
.cus-foot{display:flex;justify-content:space-between;align-items:center;padding:9px 12px;font-size:10px;color:var(--m)}
.cus-pages{display:flex;gap:5px}
.cus-pages button{width:29px;height:29px;border:1px solid var(--b);border-radius:7px;background:var(--ch-surface,#fff)}
.cus-pages button.on{background:#155eef;color:#fff}
@media(min-width:901px){
  html[data-sales-active-view="customers"],
  html[data-sales-active-view="customers"] body.org-app,
  html[data-sales-active-view="customers"] body.org-app .sh-mainpanel,
  html[data-sales-active-view="customers"] body.org-app .sh-pagebody{overflow:hidden!important}
}
@media(max-width:900px){
  .cus{height:auto;overflow:visible}
  .cus-kpis{grid-template-columns:1fr 1fr}
  .cus-filter{flex-wrap:wrap}
  .cus-table-wrap{overflow:auto}
}
</style>
<div class="cus-action-menu" id="cusActionMenu" role="menu"></div>
<script>
document.addEventListener('click',function(e){
  var menu=document.getElementById('cusActionMenu'),btn=e.target.closest('.cus-more');
  if(!menu)return;
  if(btn){
    e.preventDefault();e.stopPropagation();
    var row=btn.closest('.cus-row');
    if(!row)return;
    var id=row.dataset.cid||'', name=row.dataset.name||'', email=row.dataset.email||'', msg=row.dataset.msg||'sales_management.php#message', q=encodeURIComponent(email||name);
    menu.dataset.id=id;
    menu.innerHTML=''
      +'<a href="'+msg+'"><i class="fa fa-commenting-o"></i> Message Customer</a>'
      +'<a href="crm_contacts.php?q='+q+'"><i class="fa fa-eye"></i> View Customer</a>'
      +'<a href="sales_management.php#orders"><i class="fa fa-shopping-bag"></i> View Orders</a>'
      +'<div class="cus-action-sep"></div>'
      +'<a href="crm_contacts.php?q='+q+'"><i class="fa fa-pencil"></i> Edit Customer</a>'
      +'<a href="crm_contacts.php?q='+q+'"><i class="fa fa-sticky-note-o"></i> Add Note</a>'
      +'<div class="cus-action-sep"></div>'
      +'<button data-cus-row-export><i class="fa fa-download"></i> Export Customer</button>';
    menu.classList.add('open');
    var b=btn.getBoundingClientRect(),left=Math.min(innerWidth-213,b.right-205),top=b.bottom+5;
    if(top+menu.offsetHeight>innerHeight-8)top=Math.max(8,b.top-menu.offsetHeight-5);
    menu.style.left=Math.max(8,left)+'px';menu.style.top=top+'px';
    return;
  }
  var exp=e.target.closest('[data-cus-row-export]');
  if(exp){
    e.preventDefault();
    var row=document.querySelector('.cus-row[data-cid="'+CSS.escape(menu.dataset.id||'')+'"]');
    if(row){
      var cells=[].slice.call(row.querySelectorAll('td'),0,9);
      var csv='Customer ID,Customer,Email,Phone,Orders,Spend,Average,Status,Message\n'+cells.map(function(c){return '"'+c.innerText.trim().replace(/"/g,'""')+'"'}).join(',');
      var blob=new Blob([csv],{type:'text/csv'}),a=document.createElement('a');
      a.href=URL.createObjectURL(blob);a.download=(menu.dataset.id||'customer')+'.csv';a.click();
      setTimeout(function(){URL.revokeObjectURL(a.href)},500);
    }
    menu.classList.remove('open');
    return;
  }
  if(!e.target.closest('#cusActionMenu'))menu.classList.remove('open');
});
</script>
<div class="cus" id="customersRoot">
  <div class="cus-top">
    <?php if (function_exists('org_sales_hub_intro')) { org_sales_hub_intro('customers'); } ?>
    <div class="sm-hub-actions"><button class="cus-btn" id="cusExport" type="button"><i class="fa fa-download"></i> Export</button></div>
  </div>
  <section class="cus-kpis">
    <div class="cus-kpi"><span class="cus-ico"><i class="fa fa-users"></i></span><div><small>Total Customers</small><strong><?= (int)$total ?></strong><span class="cus-trend">Buyers + messengers</span></div></div>
    <div class="cus-kpi"><span class="cus-ico orange"><i class="fa fa-user-plus"></i></span><div><small>New Customers</small><strong><?= (int)$new ?></strong><span class="cus-trend">Last 7 days</span></div></div>
    <div class="cus-kpi"><span class="cus-ico green"><i class="fa fa-shopping-bag"></i></span><div><small>Repeat Customers</small><strong><?= (int)$repeat ?></strong><span class="cus-trend">2+ orders</span></div></div>
    <div class="cus-kpi"><span class="cus-ico blue"><i class="fa fa-commenting-o"></i></span><div><small>Messaged Seller</small><strong><?= (int)$msgCustomers ?></strong><span class="cus-trend">Customer chats</span></div></div>
    <div class="cus-kpi"><span class="cus-ico red"><i class="fa fa-usd"></i></span><div><small>Total Spend</small><strong><?= h(org_sales_money($spent)) ?></strong><span class="cus-trend">Avg <?= h(org_sales_money($avg)) ?> · <?= (int)$orders ?> orders</span></div></div>
  </section>
  <div class="cus-filter">
    <label class="cus-search"><i class="fa fa-search"></i><input id="cusSearch" type="search" placeholder="Search by name, email, phone, message or customer ID..."></label>
    <select id="cusStatus"><option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
    <select id="cusGroup">
      <option value="">All Groups</option>
      <option value="repeat">Repeat Customers</option>
      <option value="new">New Customers</option>
      <option value="messaged">Messaged Seller</option>
      <option value="unread">Unread Messages</option>
    </select>
    <button id="cusReset" type="button"><i class="fa fa-refresh"></i> Reset</button>
  </div>
  <section class="cus-card">
    <div class="cus-table-wrap">
      <table class="cus-table">
        <thead>
          <tr>
            <th>Customer ID</th>
            <th>Customer</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Total Orders</th>
            <th>Total Spend</th>
            <th>Last Message</th>
            <th>Status</th>
            <th>Joined Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$customers): ?>
            <tr><td colspan="10" style="text-align:center;padding:25px;color:#657292">No customers yet. Buyers who order or message you will appear here.</td></tr>
          <?php endif; ?>
          <?php foreach ($customers as $c):
              $buyerId = (int)($c['buyer_user_id'] ?? 0);
              $anchorId = $buyerId > 0 ? $buyerId : (int)($c['id'] ?? 0);
              $cid = 'CUST-' . str_pad((string)max(1, $anchorId), 7, '0', STR_PAD_LEFT);
              $name = trim((string)($c['name'] ?? '')) ?: 'Customer';
              $initials = mb_strtoupper(mb_substr($name, 0, 1));
              $count = (int)($c['orders_count'] ?? 0);
              $joinedTs = strtotime((string)($c['joined_at'] ?? '')) ?: time();
              $lastAct = strtotime((string)(($c['last_message_at'] ?? '') !== '' ? $c['last_message_at'] : ($c['last_order_at'] ?? ''))) ?: 0;
              $active = $lastAct >= time() - 180 * 86400;
              $group = $count > 1 ? 'repeat' : ($joinedTs >= time() - 7 * 86400 ? 'new' : '');
              if (!empty($c['has_message'])) {
                  $group = trim($group . ' messaged');
              }
              if ((int)($c['unread'] ?? 0) > 0) {
                  $group = trim($group . ' unread');
              }
              $cur = (string)($c['currency'] ?? 'USD');
              $lastMsg = trim((string)($c['last_message'] ?? ''));
              $lastMsgAt = (string)($c['last_message_at'] ?? '');
              $unread = (int)($c['unread'] ?? 0);
              $chatHref = $msgUrl($c);
              $searchBlob = mb_strtolower($cid . ' ' . $name . ' ' . (string)($c['email'] ?? '') . ' ' . (string)($c['phone'] ?? '') . ' ' . $lastMsg);
          ?>
            <tr
              class="cus-row"
              data-cid="<?= h($cid) ?>"
              data-name="<?= h($name) ?>"
              data-email="<?= h((string)($c['email'] ?? '')) ?>"
              data-msg="<?= h($chatHref) ?>"
              data-status="<?= $active ? 'active' : 'inactive' ?>"
              data-group="<?= h($group) ?>"
              data-search="<?= h($searchBlob) ?>"
            >
              <td class="cus-id"><?= h($cid) ?></td>
              <td><div class="cus-person"><span class="cus-avatar"><?= h($initials) ?></span><?= h($name) ?><?php if ($unread > 0): ?><span class="cus-unread" title="Unread messages"><?= (int)min(99, $unread) ?></span><?php endif; ?></div></td>
              <td><?= h((string)(($c['email'] ?? '') !== '' ? $c['email'] : '—')) ?></td>
              <td><?= h((string)(($c['phone'] ?? '') !== '' ? $c['phone'] : '—')) ?></td>
              <td><strong><?= (int)$count ?></strong></td>
              <td><strong><?= h(org_sales_money((int)($c['spent_cents'] ?? 0), $cur)) ?></strong></td>
              <td>
                <?php if ($lastMsg !== '' || !empty($c['has_message'])): ?>
                  <div class="cus-msg">
                    <strong title="<?= h($lastMsg) ?>"><?= h($lastMsg !== '' ? $lastMsg : 'Opened chat') ?></strong>
                    <em><?= $lastMsgAt !== '' ? h(date('M j, g:i A', strtotime($lastMsgAt) ?: time())) : 'From customer' ?></em>
                  </div>
                <?php else: ?>
                  <span style="color:#94a3b8">—</span>
                <?php endif; ?>
              </td>
              <td><span class="cus-status <?= $active ? '' : 'inactive' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
              <td><?= h(date('M j, Y', $joinedTs)) ?></td>
              <td>
                <div class="cus-actions">
                  <?php if ($buyerId > 0): ?>
                    <a class="cus-msg-btn" href="<?= h($chatHref) ?>" title="Open customer message"><i class="fa fa-commenting-o"></i> Message</a>
                  <?php endif; ?>
                  <a class="cus-more" href="<?= h($chatHref) ?>" title="More actions">•••</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="cus-foot">
      <span id="cusCount"><?= (int)$total ?> customers</span>
      <div class="cus-pages" id="cusPages"></div>
      <span>10 / page</span>
    </footer>
  </section>
</div>
<script>
(function(){
  var root=document.getElementById('customersRoot');
  if(!root)return;
  var rows=[].slice.call(root.querySelectorAll('.cus-row'));
  var q=document.getElementById('cusSearch');
  var st=document.getElementById('cusStatus');
  var gr=document.getElementById('cusGroup');
  var page=1,size=10;
  function list(){
    var v=(q.value||'').toLowerCase();
    return rows.filter(function(r){
      var g=r.dataset.group||'';
      return (!v||(r.dataset.search||'').indexOf(v)>-1)
        && (!st.value||r.dataset.status===st.value)
        && (!gr.value||g.indexOf(gr.value)>-1);
    });
  }
  function draw(){
    var a=list(),pages=Math.max(1,Math.ceil(a.length/size));
    if(page>pages)page=pages;
    rows.forEach(function(r){r.hidden=true});
    a.slice((page-1)*size,page*size).forEach(function(r){r.hidden=false});
    document.getElementById('cusCount').textContent=a.length+' customers';
    var p=document.getElementById('cusPages');p.innerHTML='';
    for(var i=1;i<=pages;i++){
      var b=document.createElement('button');
      b.type='button';b.textContent=i;b.dataset.p=i;b.className=i===page?'on':'';
      b.onclick=function(){page=+this.dataset.p;draw()};
      p.appendChild(b);
    }
  }
  [q,st,gr].forEach(function(x){x.oninput=function(){page=1;draw()}});
  document.getElementById('cusReset').onclick=function(){q.value='';st.value='';gr.value='';page=1;draw()};
  document.getElementById('cusExport').onclick=function(){
    var lines=['Customer ID,Customer,Email,Phone,Orders,Spend,Message,Status,Joined'];
    list().forEach(function(r){
      lines.push([].slice.call(r.querySelectorAll('td'),0,9).map(function(c){return '"'+c.innerText.trim().replace(/"/g,'""')+'"'}).join(','));
    });
    var b=new Blob([lines.join('\n')],{type:'text/csv'}),a=document.createElement('a');
    a.href=URL.createObjectURL(b);a.download='customers.csv';a.click();
    setTimeout(function(){URL.revokeObjectURL(a.href)},500);
  };
  draw();
})();
</script>
