<?php
declare(strict_types=1);
require_once __DIR__.'/includes/session_org.php';
require_once __DIR__.'/includes/org_context.php';
require_once __DIR__.'/includes/org_manager_guard.php';
require_once __DIR__.'/includes/org_sales.php';
org_require_manager(); org_require_commerce_seller(); org_ecommerce_ensure_schema($dbh);
$orgId=(int)orgActiveOrgId();$refunds=org_sales_return_requests($dbh,$orgId);
$counts=['total'=>count($refunds),'pending'=>0,'approved'=>0,'completed'=>0,'rejected'=>0];$totalCents=0;
foreach($refunds as $r){$s=strtolower((string)($r['status']??'requested'));$key=$s==='requested'?'pending':($s==='refunded'?'completed':$s);if(isset($counts[$key]))$counts[$key]++;$totalCents+=(int)($r['total_cents']??0);}
$refundsEmbedded=!empty($refundsEmbedded);
if(!$refundsEmbedded){$pageTitle='Refunds';require_once __DIR__.'/includes/org_page_shell.php';org_page_shell_open($pageTitle,'<link rel="stylesheet" href="css/commerce-hub.css?v=17"><link rel="stylesheet" href="css/sales-azia.css?v=6">');org_page_body_open('commerce-page');}
?>
<style>
.rfd{--t:#10204a;--m:#657292;--b:#dce5f1;color:var(--t);height:calc(100vh - var(--org-header-h,48px) - 24px);display:flex;flex-direction:column;gap:10px;overflow:hidden}.rfd *{box-sizing:border-box}.rfd a{text-decoration:none}.rfd-head{display:flex;justify-content:space-between;align-items:flex-start}.rfd-crumb{font-size:11px;font-weight:800;margin:0 0 4px}.rfd h1{font-size:25px;margin:0}.rfd-sub{font-size:11px;color:var(--m);margin:2px 0}.rfd-btn,.rfd-filter select,.rfd-filter button{height:34px;border:1px solid var(--b);border-radius:8px;background:var(--ch-surface,#fff);color:var(--t);padding:0 12px;font-size:11px;font-weight:800}.rfd-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.rfd-kpi{height:98px;border:1px solid var(--b);border-radius:11px;background:var(--ch-surface,#fff);padding:12px;display:flex;align-items:center;gap:11px}.rfd-ico{width:39px;height:39px;flex:0 0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#4f46e5;color:#fff;font-size:18px}.rfd-ico.orange{background:#f59e0b}.rfd-ico.green{background:#059669}.rfd-ico.blue{background:#155eef}.rfd-ico.red{background:#ef233c}.rfd-kpi small{display:block;color:var(--m);font-weight:700;font-size:10px}.rfd-kpi strong{display:block;font-size:20px}.rfd-trend{font-size:9px;color:#159c68}.rfd-filter{display:flex;gap:8px}.rfd-search{height:34px;border:1px solid var(--b);border-radius:8px;background:var(--ch-surface,#fff);display:flex;align-items:center;gap:8px;padding:0 11px;flex:1}.rfd-search input{border:0;outline:0;width:100%;font-size:11px}.rfd-card{flex:1;min-height:0;border:1px solid var(--b);border-radius:11px;background:var(--ch-surface,#fff);display:flex;flex-direction:column;overflow:hidden}.rfd-table-wrap{flex:1;min-height:0;overflow:hidden}.rfd-table{width:100%;border-collapse:collapse}.rfd-table th,.rfd-table td{padding:9px 12px;border-bottom:1px solid var(--b);text-align:left;font-size:10px}.rfd-table th{color:var(--m);background:var(--ch-surface,#fbfcfe);font-size:9px}.rfd-id{color:#155eef;font-weight:900}.rfd-amount{color:#ef233c;font-weight:900}.rfd-status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:9px;font-weight:900}.rfd-status.pending{background:#ffedd5;color:#c2410c}.rfd-status.approved,.rfd-status.completed{background:#dcfce7;color:#15803d}.rfd-status.rejected{background:#fee2e2;color:#dc2626}.rfd-more{width:29px;height:29px;border:1px solid var(--b);background:var(--ch-surface,#fff);border-radius:7px}.rfd-foot{display:flex;justify-content:space-between;align-items:center;padding:9px 12px;font-size:10px;color:var(--m)}.rfd-pages{display:flex;gap:5px}.rfd-pages button{width:29px;height:29px;border:1px solid var(--b);border-radius:7px;background:var(--ch-surface,#fff)}.rfd-pages button.on{background:#155eef;color:#fff}@media(min-width:901px){html,body.org-app,body.org-app .sh-mainpanel,body.org-app .sh-pagebody{overflow:hidden!important}}@media(max-width:900px){.rfd{height:auto;overflow:visible}.rfd-kpis{grid-template-columns:1fr 1fr}.rfd-filter{flex-wrap:wrap}.rfd-table-wrap{overflow:auto}}
</style>
<main class="rfd" id="refundsRoot"><header class="rfd-head"><div><p class="rfd-crumb"><a href="sales_management.php#inventory">Inventory</a> › Refunds</p><h1>Refunds</h1><p class="rfd-sub">Track and manage all refunds issued to buyers.</p></div><button class="rfd-btn" id="rfdExport"><i class="fa fa-download"></i> Export</button></header>
<section class="rfd-kpis"><div class="rfd-kpi"><span class="rfd-ico"><i class="fa fa-reply"></i></span><div><small>Total Refunds</small><strong><?=count($refunds)?></strong><span class="rfd-trend"><?=h(org_sales_money($totalCents))?> requested</span></div></div><div class="rfd-kpi"><span class="rfd-ico orange"><i class="fa fa-clock-o"></i></span><div><small>Pending</small><strong><?=$counts['pending']?></strong><span class="rfd-trend">Waiting for review</span></div></div><div class="rfd-kpi"><span class="rfd-ico green"><i class="fa fa-check"></i></span><div><small>Approved</small><strong><?=$counts['approved']?></strong><span class="rfd-trend">Ready to process</span></div></div><div class="rfd-kpi"><span class="rfd-ico blue"><i class="fa fa-file-text-o"></i></span><div><small>Completed</small><strong><?=$counts['completed']?></strong><span class="rfd-trend">Refunded</span></div></div><div class="rfd-kpi"><span class="rfd-ico red">×</span><div><small>Rejected</small><strong><?=$counts['rejected']?></strong><span class="rfd-trend">Declined requests</span></div></div></section>
<div class="rfd-filter"><label class="rfd-search"><i class="fa fa-search"></i><input id="rfdSearch" type="search" placeholder="Search by refund ID, buyer, or order ID..."></label><select id="rfdStatus"><option value="">All Status</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="completed">Completed</option><option value="rejected">Rejected</option></select><button id="rfdReset" type="button"><i class="fa fa-refresh"></i> Reset</button></div>
<section class="rfd-card"><div class="rfd-table-wrap"><table class="rfd-table"><thead><tr><th>Refund ID</th><th>Refund Date</th><th>Buyer</th><th>Order ID</th><th>Amount</th><th>Status</th><th>Refund Method</th><th>Payment Method</th><th>Processed By</th><th>Actions</th></tr></thead><tbody><?php if(!$refunds):?><tr><td colspan="10" style="text-align:center;color:#657292;padding:25px">No refund requests yet.</td></tr><?php endif;?><?php foreach($refunds as $r):$raw=strtolower((string)$r['status']);$status=$raw==='requested'?'pending':($raw==='refunded'?'completed':$raw);$rid='REFUND-'.str_pad((string)(int)$r['id'],6,'0',STR_PAD_LEFT);$ts=strtotime((string)$r['created_at'])?:time();?><tr class="rfd-row" data-status="<?=h($status)?>" data-search="<?=h(strtolower($rid.' '.($r['buyer_name']??'').' '.($r['buyer_email']??'').' '.($r['order_code']??'')))?>"><td class="rfd-id"><?=h($rid)?></td><td><strong><?=h(date('M j, Y',$ts))?></strong><br><?=h(date('g:i A',$ts))?></td><td><strong><?=h((string)(($r['buyer_name']??'')?:'Buyer'))?></strong><br><span style="color:#657292"><?=h((string)($r['buyer_email']??''))?></span></td><td><a class="rfd-id" href="order_details.php?id=<?=(int)$r['order_id']?>"><?=h((string)$r['order_code'])?></a></td><td class="rfd-amount">-<?=h(org_sales_money((int)$r['total_cents'],(string)$r['currency']))?></td><td><span class="rfd-status <?=h($status)?>"><?=h(ucfirst($status))?></span></td><td><?=stripos((string)($r['seller_notes']??''),'credit')!==false?'Store Credit':'Original Payment'?></td><td>—</td><td>System</td><td><a class="rfd-more" href="returns_refunds.php" style="display:inline-flex;align-items:center;justify-content:center">•••</a></td></tr><?php endforeach;?></tbody></table></div><footer class="rfd-foot"><span id="rfdCount"><?=count($refunds)?> refunds</span><div class="rfd-pages" id="rfdPages"></div><span>10 / page</span></footer></section></main><?php if(!$refundsEmbedded): ?></div><?php endif; ?>
<script>(function(){var root=document.getElementById('refundsRoot'),rows=[].slice.call(root.querySelectorAll('.rfd-row')),q=document.getElementById('rfdSearch'),s=document.getElementById('rfdStatus'),page=1,size=10;function filtered(){var v=q.value.toLowerCase();return rows.filter(function(r){return(!v||r.dataset.search.indexOf(v)>-1)&&(!s.value||r.dataset.status===s.value)})}function draw(){var f=filtered(),pages=Math.max(1,Math.ceil(f.length/size));if(page>pages)page=pages;rows.forEach(function(r){r.hidden=true});f.slice((page-1)*size,page*size).forEach(function(r){r.hidden=false});document.getElementById('rfdCount').textContent=f.length+' refunds';var p=document.getElementById('rfdPages');p.innerHTML='';for(var i=1;i<=pages;i++){var b=document.createElement('button');b.textContent=i;b.className=i===page?'on':'';b.dataset.page=i;b.onclick=function(){page=+this.dataset.page;draw()};p.appendChild(b)}}q.oninput=function(){page=1;draw()};s.onchange=function(){page=1;draw()};document.getElementById('rfdReset').onclick=function(){q.value='';s.value='';page=1;draw()};document.getElementById('rfdExport').onclick=function(){var lines=['Refund ID,Date,Buyer,Order,Amount,Status'];filtered().forEach(function(r){var c=r.cells;lines.push([c[0],c[1],c[2],c[3],c[4],c[5]].map(function(x){return '"'+x.innerText.trim().replace(/"/g,'""')+'"'}).join(','))});var b=new Blob([lines.join('\n')],{type:'text/csv'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='refunds.csv';a.click();setTimeout(function(){URL.revokeObjectURL(a.href)},500)};draw()})();</script>
<style>
.rfd-action-menu{display:none;position:fixed;z-index:9999;width:205px;padding:6px;background:var(--ch-surface,#fff);border:1px solid #dce5f1;border-radius:10px;box-shadow:0 14px 34px rgba(15,23,42,.18)}
.rfd-action-menu.open{display:block}.rfd-action-menu a,.rfd-action-menu button{display:flex;align-items:center;gap:9px;width:100%;padding:8px 9px;border:0;border-radius:7px;background:transparent;color:#10204a;text-decoration:none;text-align:left;font-size:11px;font-weight:700;cursor:pointer}.rfd-action-menu a:hover,.rfd-action-menu button:hover{background:#f5f8ff}.rfd-action-menu i{width:15px;text-align:center}.rfd-action-sep{height:1px;background:#e2e8f0;margin:5px -6px}.rfd-action-menu .danger{color:#dc2626}
</style>
<div class="rfd-action-menu" id="rfdActionMenu" role="menu"></div>
<script>
(function(){
 var menu=document.getElementById('rfdActionMenu'); if(!menu)return;
 function close(){menu.classList.remove('open')}
 function show(button,row){
  var cells=row.cells,idText=(cells[0].textContent||'').trim(),idMatch=idText.match(/(\d+)$/),refundId=idMatch?parseInt(idMatch[1],10):0;
  var orderLink=cells[3].querySelector('a'),orderHref=orderLink?orderLink.href:'#',buyer=(cells[2].textContent||'').trim().split(/\s+/).pop();
  var manage='returns_refunds.php';
  menu.innerHTML='<a href="refunds_detail.php?id='+refundId+'"><i class="fa fa-eye"></i> View Refund Details</a>'+
   '<a href="'+orderHref+'"><i class="fa fa-file-text-o"></i> View Order</a>'+
   '<a href="mailto:'+buyer+'"><i class="fa fa-user-o"></i> View Buyer</a>'+
   '<a href="'+orderHref+'"><i class="fa fa-credit-card"></i> View Payment</a>'+
   '<div class="rfd-action-sep"></div>'+
   '<a href="refunds_detail.php?id='+refundId+'#receipt"><i class="fa fa-download"></i> Download Receipt</a>'+
   '<a href="'+manage+'"><i class="fa fa-pencil"></i> Edit Refund</a>'+
   '<a href="'+manage+'"><i class="fa fa-check-circle-o"></i> Approve Refund</a>'+
   '<a href="'+manage+'"><i class="fa fa-times-circle-o"></i> Reject Refund</a>'+
   '<a href="'+manage+'"><i class="fa fa-ban"></i> Cancel Refund</a>'+
   '<div class="rfd-action-sep"></div>'+
   '<button type="button" class="danger" data-refund-delete><i class="fa fa-trash-o"></i> Delete Refund</button>';
  menu.classList.add('open');var box=button.getBoundingClientRect(),left=Math.min(window.innerWidth-213,box.right-205),top=box.bottom+5;if(top+menu.offsetHeight>window.innerHeight-8)top=Math.max(8,box.top-menu.offsetHeight-5);menu.style.left=Math.max(8,left)+'px';menu.style.top=top+'px';
 }
 document.addEventListener('click',function(e){
  var button=e.target.closest('.rfd-more');
  if(button){e.preventDefault();e.stopPropagation();var row=button.closest('.rfd-row');if(menu.classList.contains('open'))close();else if(row)show(button,row);return;}
  if(e.target.closest('[data-refund-delete]')){e.preventDefault();window.alert('Delete Refund is available from the refund management page after review.');return;}
  if(!e.target.closest('#rfdActionMenu'))close();
 });
 window.addEventListener('scroll',close,true);window.addEventListener('resize',close);
})();
</script>
<script>
document.addEventListener('click',function(e){
  var cell=e.target.closest('.rfd-row td:first-child');
  if(!cell)return;
  var match=(cell.textContent||'').trim().match(/(\d+)$/);
  if(match)window.location.href='refunds_detail.php?id='+parseInt(match[1],10);
});
</script>
<?php if(!$refundsEmbedded)org_page_shell_close(); ?>
