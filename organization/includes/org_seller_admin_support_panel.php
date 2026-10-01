<?php
declare(strict_types=1);

/**
 * Seller Admin support chat for sales_management.php#support-center.
 * UI matched to buyer Shopping Preferences → Support Center.
 * Keeps existing ajax/admin_support_chat.php behavior and element IDs.
 */
if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}
if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$sellerOpenDisputes = [];
if (isset($dbh) && $dbh instanceof PDO) {
    require_once __DIR__ . '/../../public_user/includes/commerce_disputes.php';
    require_once __DIR__ . '/../../public_user/includes/commerce_messaging.php';
    $pubIdForDisputes = (int)($sellerMsgPublisherId ?? $sellerMsgPublisherIdLocal ?? 0);
    if ($pubIdForDisputes <= 0 && isset($orgId)) {
        try {
            require_once __DIR__ . '/../../public_user/includes/staff_publisher_access.php';
            if (function_exists('staff_pub_org_publisher_user_id')) {
                $pubIdForDisputes = (int)staff_pub_org_publisher_user_id($dbh, (int)$orgId);
            }
        } catch (Throwable $e) {
            $pubIdForDisputes = 0;
        }
    }
    if ($pubIdForDisputes > 0) {
        $sellerOpenDisputes = commerce_dispute_list_for_seller($dbh, $pubIdForDisputes, 10);
    }
}
?>
<style>
  .sas-dispute-banner{
    border:1px solid #fdba74;background:#fff7ed;border-radius:12px;padding:10px 12px;margin:0;flex:0 0 auto;
  }
  .sas-dispute-banner h3{margin:0 0 6px;font-size:14px;font-weight:800;color:#9a3412;}
  .sas-dispute-banner p{margin:0 0 8px;font-size:12.5px;line-height:1.45;color:#9a3412;}
  .sas-dispute-item{
    display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid rgba(251,146,60,.35);
  }
  .sas-dispute-item:first-of-type{border-top:0;padding-top:0;}
  .sas-dispute-item img{width:42px;height:42px;border-radius:8px;object-fit:cover;background:#fed7aa;flex:0 0 auto;}
  .sas-dispute-item strong{display:block;font-size:13px;font-weight:800;color:#7c2d12;}
  .sas-dispute-item span{display:block;margin-top:2px;font-size:11px;font-weight:700;color:#c2410c;}
  .sas-dispute-item a{
    margin-left:auto;flex:0 0 auto;padding:7px 10px;border-radius:8px;background:#ea580c;color:#fff;
    font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap;
  }
  html.dark-auto .sas-dispute-banner,
  html[data-msb-appearance] .sas-dispute-banner{
    background:rgba(234,88,12,.16)!important;border-color:rgba(234,88,12,.4)!important;
  }
  .sas-wrap{
    --sas-bg:#fff;--sas-raised:#f8fafc;--sas-text:#0f172a;--sas-muted:#64748b;
    --sas-border:#e5e7eb;--sas-border-soft:#f1f5f9;--sas-input:#f8fafc;
    --sas-accent:var(--msb-palette-action,var(--org-accent,#2563eb));
    --sas-accent-soft:var(--msb-palette-action-soft,#eff6ff);
    --sas-btn:var(--msb-palette-btn-bg,var(--msb-palette-action,var(--org-accent,#2563eb)));
    --sas-btn-text:var(--msb-palette-btn-text,#fff);
    display:flex;flex-direction:column;gap:8px;min-height:0;height:100%;
    padding:0 0 4px;box-sizing:border-box;color:var(--sas-text);
  }
  html.dark-auto .sas-wrap,
  html[data-msb-appearance] .sas-wrap,
  html.msb-palette-active .sas-wrap{
    --sas-bg:var(--msb-palette-bg,#171d24);
    --sas-raised:var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733));
    --sas-text:var(--msb-palette-text,#e2e8f0);
    --sas-muted:var(--msb-palette-text-muted,#94a3b8);
    --sas-border:var(--msb-palette-border,rgba(148,163,184,.28));
    --sas-border-soft:var(--msb-palette-border,rgba(148,163,184,.18));
    --sas-input:var(--msb-palette-input-bg,var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733)));
    --sas-accent:var(--msb-palette-action,var(--org-accent,#2563eb));
    --sas-accent-soft:var(--msb-palette-action-soft,rgba(37,99,235,.18));
    --sas-btn:var(--msb-palette-btn-bg,var(--msb-palette-action,var(--org-accent,#2563eb)));
    --sas-btn-text:var(--msb-palette-btn-text,#fff);
  }
  html.dark-auto .sas-help-item,
  html[data-msb-appearance] .sas-help-item,
  html.msb-palette-active .sas-help-item{
    background:var(--sas-bg)!important;color:var(--sas-text)!important;
  }
  html.dark-auto .sas-help-item:hover,
  html[data-msb-appearance] .sas-help-item:hover,
  html.msb-palette-active .sas-help-item:hover{
    background:var(--sas-raised)!important;
  }
  html.dark-auto .sas-help-item.is-active,
  html[data-msb-appearance] .sas-help-item.is-active,
  html.msb-palette-active .sas-help-item.is-active{
    background:var(--sas-raised)!important;
    box-shadow:none;
  }
  html.dark-auto .sas-help-item strong,
  html[data-msb-appearance] .sas-help-item strong,
  html.msb-palette-active .sas-help-item strong{color:var(--sas-text)!important;}
  html.dark-auto .sas-help-item span,
  html[data-msb-appearance] .sas-help-item span,
  html.msb-palette-active .sas-help-item span{color:var(--sas-muted)!important;}
  html.dark-auto .sas-head-ava,
  html.dark-auto .sas-row-ava,
  html[data-msb-appearance] .sas-head-ava,
  html[data-msb-appearance] .sas-row-ava,
  html.msb-palette-active .sas-head-ava,
  html.msb-palette-active .sas-row-ava{
    background:var(--sas-raised)!important;color:var(--sas-accent)!important;
  }
  html.dark-auto .sas-topics .seller-admin-topic,
  html[data-msb-appearance] .sas-topics .seller-admin-topic,
  html.msb-palette-active .sas-topics .seller-admin-topic{
    background:var(--sas-input)!important;border-color:var(--sas-border)!important;color:var(--sas-text)!important;
  }
  html.dark-auto .sas-topics .seller-admin-topic.is-active,
  html[data-msb-appearance] .sas-topics .seller-admin-topic.is-active,
  html.msb-palette-active .sas-topics .seller-admin-topic.is-active{
    background:var(--sas-raised)!important;border-color:var(--sas-accent)!important;color:var(--sas-accent)!important;
  }
  .sas-intro{flex:0 0 auto;padding:0 2px 2px;max-width:min(100%,640px);}
  .sas-intro-kicker{margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sas-muted);}
  .sas-intro-title{margin:0 0 4px;font-size:20px;font-weight:800;letter-spacing:-.02em;line-height:1.15;color:var(--sas-text);}
  .sas-intro-sub{margin:0;font-size:12.5px;line-height:1.4;color:var(--sas-muted);max-width:68ch;}
  .sas-shell{
    display:grid;grid-template-columns:minmax(260px,320px) minmax(0,1fr);gap:12px;
    flex:1 1 auto;min-height:0;height:auto;margin:0;
  }
  .sas-rail,.sas-chat{
    display:flex;flex-direction:column;min-width:0;min-height:0;border:1px solid var(--sas-border);
    border-radius:12px;background:var(--sas-bg)!important;overflow:hidden;color:var(--sas-text);
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .sas-chat{height:100%;}
  .sas-toolbar,.sas-head,.sas-topics,.sas-compose{background:var(--sas-bg)!important;border-bottom:1px solid var(--sas-border-soft);flex:0 0 auto;}
  .sas-toolbar{display:flex;gap:8px;align-items:center;padding:12px 12px 10px;}
  .sas-search{position:relative;flex:1 1 auto;min-width:0;}
  .sas-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--sas-muted);font-size:13px;pointer-events:none;}
  .sas-search input,.sas-filter{
    border:1px solid var(--sas-border);border-radius:10px;font-size:13px;outline:none;
    background:var(--sas-input)!important;color:var(--sas-text)!important;
  }
  .sas-search input{width:100%;height:40px;padding:0 12px 0 34px;}
  .sas-search input:focus{border-color:var(--sas-accent);background:var(--sas-bg)!important;box-shadow:0 0 0 3px var(--sas-accent-soft);}
  .sas-filter{flex:0 0 auto;height:40px;padding:0 12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
  .sas-list,.sas-thread{flex:1 1 auto;min-height:0;overflow:auto;background:var(--sas-bg)!important;}
  .sas-help-item{
    display:block;width:100%;text-align:left;border:0;border-bottom:0;
    background:var(--sas-bg)!important;padding:14px 14px 12px;cursor:pointer;color:var(--sas-text);
  }
  .sas-help-item:hover{background:var(--sas-raised)!important;}
  .sas-help-item.is-active{background:var(--sas-accent-soft)!important;box-shadow:none;}
  .sas-help-item strong{display:block;font-size:13.5px;font-weight:800;color:var(--sas-text);}
  .sas-help-item span{display:block;margin-top:4px;font-size:12px;line-height:1.4;color:var(--sas-muted);}
  .sas-help-note{margin:0;padding:14px;font-size:12px;line-height:1.45;color:var(--sas-muted);border-top:1px solid var(--sas-border-soft);background:var(--sas-raised)!important;flex:0 0 auto;}
  .sas-head{
    display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:14px 16px;
  }
  .sas-head-ava,.sas-row-ava{border-radius:999px;background:var(--sas-accent-soft);color:var(--sas-accent);display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;}
  .sas-head-ava{width:42px;height:42px;font-size:16px;}
  .sas-head-meta{flex:0 1 auto;min-width:0;max-width:34%;}
  .sas-head-name{margin:0;font-size:15px;font-weight:800;color:var(--sas-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .sas-head-status{
    display:inline-flex;align-items:center;gap:6px;width:auto;max-width:100%;margin-top:2px;
    font-size:12px;font-weight:600;color:#16a34a;
    background:transparent!important;border:0!important;box-shadow:none!important;filter:none!important;
  }
  .sas-head-status i{font-size:8px;line-height:1;flex:0 0 auto;background:transparent!important;}
  .sas-head-more{width:36px;height:36px;border:0;border-radius:999px;background:transparent;color:var(--sas-muted);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;}
  .sas-head-more:hover{background:var(--sas-raised);color:var(--sas-text);}
  .sas-more{position:relative;flex:0 0 auto;margin-left:auto;}
  .sas-more.is-open .sas-head-more{background:var(--sas-raised);color:var(--sas-text);}
  .sas-more-menu{
    display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:50;min-width:260px;max-width:min(360px,90vw);
    padding:6px;border:1px solid var(--sas-border);border-radius:12px;background:var(--sas-bg)!important;
    box-shadow:0 10px 30px rgba(15,23,42,.12);
  }
  .sas-more.is-open .sas-more-menu{display:block;}
  .sas-history-head{
    padding:8px 12px 6px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--sas-muted);
  }
  .sas-history-list{max-height:280px;overflow:auto;padding:0 0 4px;}
  .sas-history-empty{padding:10px 12px;font-size:12px;color:var(--sas-muted);}
  .sas-history-item{
    display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:0;border-radius:8px;
    background:transparent;text-align:left;cursor:pointer;color:var(--sas-text);
  }
  .sas-history-item:hover,.sas-history-item.is-active{background:var(--sas-raised)!important;}
  .sas-history-item img{width:32px;height:32px;border-radius:6px;object-fit:cover;background:var(--sas-border-soft);flex:0 0 auto;}
  .sas-history-item-text{display:block;min-width:0;flex:1 1 auto;}
  .sas-history-item-label{display:block;font-size:13px;font-weight:800;color:var(--sas-text);line-height:1.25;}
  .sas-history-item-meta{display:block;margin-top:2px;font-size:11px;font-weight:600;color:var(--sas-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .sas-product{
    display:grid;grid-template-columns:48px minmax(0,1fr) auto;gap:10px;align-items:center;
    flex:1 1 180px;min-width:0;max-width:min(420px,100%);
    padding:8px 10px;border:1px solid var(--sas-border);border-radius:12px;background:var(--sas-raised)!important;
  }
  .sas-product[hidden]{display:none!important;}
  .sas-product img{width:48px;height:48px;border-radius:8px;object-fit:cover;background:var(--sas-border-soft);}
  .sas-product strong{display:block;font-size:13px;font-weight:800;color:var(--sas-text);line-height:1.3;}
  .sas-product-id,.sas-product-biz{display:block;margin-top:2px;font-size:11px;font-weight:700;color:var(--sas-muted);}
  .sas-product a{
    flex:0 0 auto;padding:7px 12px;border:1px solid var(--sas-border);border-radius:8px;background:var(--sas-bg)!important;
    color:var(--sas-text)!important;font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap;
  }
  .sas-product a:hover{background:var(--sas-raised)!important;}
  .sas-history-panel{display:none;flex-direction:column;flex:1 1 auto;min-height:0;background:var(--sas-bg)!important;}
  .sas-history-panel.is-open{display:flex;}
  .sas-history-bar{
    display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--sas-border-soft);flex:0 0 auto;
  }
  .sas-history-back{
    border:1px solid var(--sas-border);border-radius:8px;background:var(--sas-bg)!important;color:var(--sas-text);
    font-size:12px;font-weight:800;padding:6px 10px;cursor:pointer;flex:0 0 auto;
  }
  .sas-history-back:hover{background:var(--sas-raised)!important;}
  .sas-history-title{font-size:13px;font-weight:800;color:var(--sas-text);margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .sas-history-thread{
    flex:1 1 auto;overflow:auto;padding:18px 16px;display:flex;flex-direction:column;gap:14px;min-height:0;
  }
  .sas-chat.is-history-mode .sas-topics,
  .sas-chat.is-history-mode .sas-thread,
  .sas-chat.is-history-mode .sas-compose{display:none!important;}
  .sas-topics{display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px;}
  .sas-topics .seller-admin-topic{border:1px solid var(--sas-border);border-radius:999px;background:var(--sas-bg)!important;color:var(--sas-text);font-size:12px;font-weight:700;padding:7px 12px;cursor:pointer;line-height:1.2;}
  .sas-topics .seller-admin-topic.is-active{border-color:var(--sas-accent);color:var(--sas-accent);background:var(--sas-accent-soft)!important;}
  .sas-thread{padding:18px 16px;display:flex;flex-direction:column;gap:14px;}
  .sas-row{display:flex;gap:8px;align-items:flex-end;max-width:78%;}
  .sas-row.me{align-self:flex-end;flex-direction:row-reverse;}
  .sas-row.them{align-self:flex-start;}
  .sas-row-ava{width:28px;height:28px;font-size:11px;}
  .sas-row.me .sas-row-ava{display:none;}
  .sas-bubble-wrap{min-width:0;}
  .sas-bubble{max-width:100%;padding:10px 12px;border-radius:4px;font-size:13.5px;line-height:1.45;white-space:pre-wrap;word-break:break-word;}
  .sas-bubble.me{background:var(--sas-btn)!important;color:var(--sas-btn-text)!important;}
  .sas-bubble.them{background:var(--sas-raised)!important;color:var(--sas-text)!important;}
  .sas-meta{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--sas-muted);margin-top:4px;padding:0 2px;}
  .sas-row.me .sas-meta{justify-content:flex-end;}
  .sas-meta .fa-check-double{font-size:11px;color:var(--sas-accent);}
  .sas-empty{flex:1 1 auto;display:flex;align-items:center;justify-content:center;text-align:center;padding:40px 24px;color:var(--sas-muted);font-size:13.5px;line-height:1.5;}
  .sas-compose{padding:10px 12px 12px;border-top:1px solid var(--sas-border-soft);border-bottom:0;flex:0 0 auto;}
  .sas-compose-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;}
  .sas-compose-bar{display:flex;align-items:center;gap:4px;min-width:0;min-height:44px;padding:4px 10px;border:1px solid var(--sas-border);border-radius:12px;background:var(--sas-input)!important;}
  .sas-compose-attach,.sas-compose-tools button{width:34px;height:34px;border:0;border-radius:8px;background:transparent;color:var(--sas-muted);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;}
  .sas-compose-attach:hover,.sas-compose-tools button:hover{background:var(--sas-raised);color:var(--sas-text);}
  .sas-compose-bar textarea{flex:1 1 auto;min-height:34px;max-height:100px;resize:none;border:0;background:transparent;padding:7px 4px;font-size:13.5px;line-height:1.4;color:var(--sas-text)!important;outline:none;box-shadow:none;}
  .sas-compose-tools{display:flex;gap:0;flex:0 0 auto;}
  .sas-compose #sellerAdminSupportSend,
  body.org-app .commerce-page .sas-compose #sellerAdminSupportSend{
    height:44px;padding:0 18px;border:0!important;border-radius:10px;
    background:var(--sas-btn)!important;color:var(--sas-btn-text)!important;-webkit-text-fill-color:var(--sas-btn-text)!important;
    font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;
  }
  .sas-compose #sellerAdminSupportSend:hover{filter:brightness(.92);}
  .sas-compose #sellerAdminSupportSend:disabled{opacity:.55;cursor:not-allowed;}
  #sellerAdminSupportErr{color:#dc2626;font-size:12px;margin:8px 0 0;}
  @media (max-width:980px){
    .sas-shell{grid-template-columns:1fr;flex:1 1 auto;min-height:0;height:auto;}
    .sas-rail{max-height:220px;flex:0 0 auto;}
    .sas-chat{min-height:0;flex:1 1 auto;}
    .sas-intro-title{font-size:18px;}
  }
</style>

<div class="sas-wrap">
  <div class="sas-intro">
    <p class="sas-intro-kicker">Support Center</p>
    <h2 class="sas-intro-title">Live seller center</h2>
    <p class="sas-intro-sub">Ask Admin for seller help with orders, store settings, payouts, or account issues. Customer product questions stay in Customer chat.</p>
  </div>

  <?php if ($sellerOpenDisputes): ?>
    <div class="sas-dispute-banner" role="status">
      <h3>Admin dispute notice</h3>
      <p>A customer raised a product concern. Contact the customer, solve it quickly, then reply to Admin. You have 30 days after Admin opens the dispute.</p>
      <?php foreach ($sellerOpenDisputes as $dCase):
        $dCode = trim((string)($dCase['dispute_code'] ?? '')) ?: commerce_dispute_format_id((int)($dCase['id'] ?? 0));
        $dTitle = trim((string)($dCase['product_title'] ?? 'Product'));
        $dPid = (int)($dCase['product_id'] ?? 0);
        $dDue = trim((string)($dCase['seller_response_due_at'] ?? ''));
        $dDueLabel = $dDue !== '' && strtotime($dDue) ? ('Due ' . date('M j, Y', strtotime($dDue))) : 'Awaiting Admin notice';
        $dCover = '';
        if (function_exists('org_shop_cover_url')) {
            $dCover = org_shop_cover_url((string)($dCase['product_cover_path'] ?? ''));
        }
        $dChat = commerce_dispute_buyer_chat_url($dCase);
      ?>
        <div class="sas-dispute-item">
          <?php if ($dCover !== ''): ?>
            <img src="<?= h($dCover) ?>" alt="">
          <?php else: ?>
            <div class="sas-head-ava" style="width:42px;height:42px;border-radius:8px;flex:0 0 auto;" aria-hidden="true"><i class="fa fa-cube"></i></div>
          <?php endif; ?>
          <div>
            <strong><?= h($dTitle) ?></strong>
            <span><?= h($dCode) ?><?= $dPid > 0 ? (' · Product ID #' . $dPid) : '' ?> · <?= h($dDueLabel) ?></span>
          </div>
          <a href="<?= h($dChat) ?>">Message customer</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="sas-shell seller-admin-support" id="sellerAdminSupportRoot" data-endpoint="ajax/admin_support_chat.php">
    <div class="sas-rail" aria-label="Help topics">
      <div class="sas-toolbar">
        <div class="sas-search">
          <i class="fa fa-search" aria-hidden="true"></i>
          <input type="search" id="sellerAdminSupportSearch" placeholder="Search help topics..." autocomplete="off">
        </div>
        <button type="button" class="sas-filter" aria-label="Filter topics">
          All <i class="fa fa-chevron-down" aria-hidden="true"></i>
        </button>
      </div>
      <div class="sas-list" id="sellerAdminSupportTopicList">
        <button type="button" class="sas-help-item is-active" data-topic="seller_help" data-search="seller help admin account payouts">
          <strong>Seller help</strong>
          <span>General seller questions, store setup, and Admin guidance.</span>
        </button>
        <button type="button" class="sas-help-item" data-topic="orders" data-search="order dispute sale refund shipping">
          <strong>Order dispute</strong>
          <span>Issues with a specific sale, refund, pickup, or delivery.</span>
        </button>
        <button type="button" class="sas-help-item" data-topic="account" data-search="store account settings payouts">
          <strong>Store &amp; account</strong>
          <span>Account access, settings, payouts, and store profile help.</span>
        </button>
      </div>
      <p class="sas-help-note">Use Customer chat for buyer questions. Escalate here only when Admin must intervene.</p>
    </div>

    <div class="sas-chat" id="sellerAdminSupportChat">
      <?php
        $sasOpenCase = $sellerOpenDisputes[0] ?? null;
        $sasProdId = is_array($sasOpenCase) ? (int)($sasOpenCase['product_id'] ?? 0) : 0;
        $sasProdTitle = is_array($sasOpenCase) ? trim((string)($sasOpenCase['product_title'] ?? '')) : '';
        $sasProdCover = '';
        $sasProdHref = $sasProdId > 0 ? ('sales_management.php?inv_product=' . $sasProdId . '#inventory-detail') : '';
        $sasProdCode = '';
        $sasDisputeCode = '';
        if (is_array($sasOpenCase)) {
            $sasDisputeCode = trim((string)($sasOpenCase['dispute_code'] ?? ''));
            if ($sasDisputeCode === '') {
                $sasDisputeCode = commerce_dispute_format_id((int)($sasOpenCase['id'] ?? 0));
            }
            if ($sasProdTitle === '' && $sasProdId > 0) {
                $sasProdTitle = 'Product #' . $sasProdId;
            }
            if (function_exists('org_shop_cover_url')) {
                $sasProdCover = org_shop_cover_url((string)($sasOpenCase['product_cover_path'] ?? ''));
            }
            if ($sasProdId > 0 && function_exists('commerce_messaging_product_focus') && isset($dbh) && $dbh instanceof PDO) {
                $focus = commerce_messaging_product_focus($dbh, $sasProdId, (int)($orgId ?? 0));
                if (is_array($focus)) {
                    $sasProdTitle = trim((string)($focus['title'] ?? $sasProdTitle));
                    $sasProdCover = trim((string)($focus['cover'] ?? $sasProdCover));
                    $sasProdCode = trim((string)($focus['code'] ?? ''));
                    $sasProdHref = trim((string)($focus['seller_href'] ?? $sasProdHref));
                }
            }
        }
        $sasProdIdLabel = $sasProdId > 0 ? ('Product ID #' . $sasProdId) : '';
        if ($sasProdIdLabel !== '' && $sasProdCode !== '') {
            $sasProdIdLabel .= ' · ' . $sasProdCode;
        }
      ?>
      <div class="sas-head" id="sellerAdminSupportHead">
        <div class="sas-head-ava" aria-hidden="true"><i class="fa fa-headphones"></i></div>
        <div class="sas-head-meta">
          <p class="sas-head-name" id="sellerAdminSupportHeadName">Admin support</p>
          <div class="sas-head-status"><i class="fa fa-circle" aria-hidden="true"></i> <span id="sellerAdminSupportHeadStatus">Active now</span></div>
        </div>
        <?php if ($sasProdId > 0): ?>
          <div class="sas-product" id="sellerAdminSupportProduct">
            <?php if ($sasProdCover !== ''): ?>
              <img src="<?= h($sasProdCover) ?>" alt="">
            <?php else: ?>
              <img alt="" style="background:rgba(148,163,184,.25)">
            <?php endif; ?>
            <div>
              <strong><?= h($sasProdTitle) ?></strong>
              <?php if ($sasProdIdLabel !== ''): ?><span class="sas-product-id"><?= h($sasProdIdLabel) ?></span><?php endif; ?>
              <span class="sas-product-biz"><?= h($sasDisputeCode !== '' ? ('Customer concern · ' . $sasDisputeCode) : 'Customer concern') ?></span>
            </div>
            <?php if ($sasProdHref !== ''): ?><a href="<?= h($sasProdHref) ?>">Open product</a><?php endif; ?>
          </div>
        <?php else: ?>
          <div class="sas-product" id="sellerAdminSupportProduct" hidden></div>
        <?php endif; ?>
        <div class="sas-more" id="sellerAdminSupportMore">
          <button type="button" class="sas-head-more" id="sellerAdminSupportMoreBtn" aria-label="Product history" aria-haspopup="menu" aria-expanded="false">
            <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
          </button>
          <div class="sas-more-menu" id="sellerAdminSupportMoreMenu" role="menu">
            <div class="sas-history-head">Product history</div>
            <div class="sas-history-list" id="sellerAdminSupportHistoryList">
              <div class="sas-history-empty">Loading…</div>
            </div>
          </div>
        </div>
      </div>
      <div class="sas-history-panel" id="sellerAdminSupportHistoryPanel" aria-label="Product concern history">
        <div class="sas-history-bar">
          <button type="button" class="sas-history-back" id="sellerAdminSupportHistoryBack">← Back</button>
          <p class="sas-history-title" id="sellerAdminSupportHistoryTitle">Product history</p>
        </div>
        <div class="sas-history-thread" id="sellerAdminSupportHistoryThread"></div>
      </div>
      <div class="sas-topics" role="group" aria-label="Support topic">
        <button type="button" class="seller-admin-topic is-active" data-topic="seller_help">Seller help</button>
        <button type="button" class="seller-admin-topic" data-topic="orders">Order dispute</button>
        <button type="button" class="seller-admin-topic" data-topic="account">Store &amp; account</button>
      </div>
      <div class="sas-thread" id="sellerAdminSupportThread" aria-live="polite">
        <div class="sas-empty seller-admin-support-empty">No Admin messages yet. Choose a topic and ask for seller help.</div>
      </div>
      <div class="sas-compose">
        <div class="sas-compose-row">
          <div class="sas-compose-bar">
            <textarea id="sellerAdminSupportInput" rows="1" placeholder="Describe what you need Admin help with…"></textarea>
          </div>
          <button type="button" id="sellerAdminSupportSend">Send</button>
        </div>
        <p id="sellerAdminSupportErr" hidden></p>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var root = document.getElementById('sellerAdminSupportRoot');
  if (!root || root.getAttribute('data-sas-bound') === '1') return;
  root.setAttribute('data-sas-bound', '1');

  var endpoint = String(root.getAttribute('data-endpoint') || 'ajax/admin_support_chat.php');
  var chatEl = document.getElementById('sellerAdminSupportChat');
  var thread = document.getElementById('sellerAdminSupportThread');
  var input = document.getElementById('sellerAdminSupportInput');
  var sendBtn = document.getElementById('sellerAdminSupportSend');
  var errEl = document.getElementById('sellerAdminSupportErr');
  var headName = document.getElementById('sellerAdminSupportHeadName');
  var headStatus = document.getElementById('sellerAdminSupportHeadStatus');
  var searchEl = document.getElementById('sellerAdminSupportSearch');
  var productEl = document.getElementById('sellerAdminSupportProduct');
  var moreWrap = document.getElementById('sellerAdminSupportMore');
  var moreBtn = document.getElementById('sellerAdminSupportMoreBtn');
  var historyList = document.getElementById('sellerAdminSupportHistoryList');
  var historyPanel = document.getElementById('sellerAdminSupportHistoryPanel');
  var historyBack = document.getElementById('sellerAdminSupportHistoryBack');
  var historyTitle = document.getElementById('sellerAdminSupportHistoryTitle');
  var historyThread = document.getElementById('sellerAdminSupportHistoryThread');
  var topicPills = Array.prototype.slice.call(root.querySelectorAll('.sas-topics .seller-admin-topic'));
  var helpItems = Array.prototype.slice.call(root.querySelectorAll('.sas-help-item'));
  var topic = 'seller_help';
  var lastId = 0;
  var polling = false;
  var viewingHistory = false;
  var aboutProduct = 0;
  var topicLabels = {
    seller_help: { ph: 'Describe what you need Admin help with…' },
    orders: { ph: 'Describe the order dispute for Admin…' },
    account: { ph: 'Describe the store or account issue…' }
  };

  function setErr(msg) {
    if (!errEl) return;
    if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
    errEl.hidden = false;
    errEl.textContent = msg;
  }
  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function shortTime(label) {
    var s = String(label || '').trim();
    if (!s) return '';
    var m = s.match(/(\d{1,2}:\d{2}\s*[AP]M)/i);
    return m ? m[1] : s;
  }
  function emptyHtml() {
    return '<div class="sas-empty seller-admin-support-empty">No Admin messages yet. Choose a topic and ask for seller help.</div>';
  }
  function closeMoreMenu() {
    if (moreWrap) moreWrap.classList.remove('is-open');
    if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
  }
  function renderProductCard(el, product, forceHide) {
    if (!el) return;
    if (forceHide || !product || !(parseInt(product.id || 0, 10) > 0)) {
      el.hidden = true;
      el.innerHTML = '';
      return;
    }
    var id = parseInt(product.id || 0, 10) || 0;
    var title = String(product.title || ('Product #' + id));
    var cover = String(product.cover || '');
    var code = String(product.code || '');
    var href = String(product.seller_href || ('sales_management.php?inv_product=' + id + '#inventory-detail'));
    var disputeCode = String(product.dispute_code || '');
    var concern = String(product.concern_label || 'Customer concern');
    var idLabel = id > 0 ? ('Product ID #' + id) : '';
    if (idLabel && code) idLabel += ' · ' + code;
    var biz = disputeCode ? (concern + ' · ' + disputeCode) : concern;
    var imgSrc = cover || '';
    el.hidden = false;
    el.innerHTML =
      (imgSrc
        ? '<img src="' + esc(imgSrc) + '" alt="">'
        : '<img alt="" style="background:rgba(148,163,184,.25)">') +
      '<div>' +
        '<strong>' + esc(title) + '</strong>' +
        (idLabel ? '<span class="sas-product-id">' + esc(idLabel) + '</span>' : '') +
        '<span class="sas-product-biz">' + esc(biz) + '</span>' +
      '</div>' +
      (href ? '<a href="' + esc(href) + '">Open product</a>' : '');
  }
  function appendItems(items, replace, targetThread) {
    var box = targetThread || thread;
    if (!box) return;
    if (replace) box.innerHTML = '';
    var list = items || [];
    if (!list.length && replace && !targetThread) {
      box.innerHTML = emptyHtml();
      return;
    }
    list.forEach(function (item) {
      var id = parseInt(item.id || 0, 10);
      if (!targetThread && id > lastId) lastId = id;
      var isMe = !!item.is_me;
      var row = document.createElement('div');
      row.className = 'sas-row ' + (isMe ? 'me' : 'them');
      if (id > 0) row.setAttribute('data-id', String(id));
      var ava = isMe ? '' : '<div class="sas-row-ava" aria-hidden="true"><i class="fa fa-headphones"></i></div>';
      var checks = isMe ? ' <i class="fa fa-check-double" aria-hidden="true"></i>' : '';
      var from = String(item.from || (isMe ? 'You' : 'Admin'));
      row.innerHTML =
        ava +
        '<div class="sas-bubble-wrap">' +
          '<div class="sas-bubble ' + (isMe ? 'me' : 'them') + '">' + esc(item.text || '') + '</div>' +
          '<div class="sas-meta">' + esc(from) + (shortTime(item.time_label || '') ? (' · ' + esc(shortTime(item.time_label || ''))) : '') + checks + '</div>' +
        '</div>';
      box.appendChild(row);
    });
    box.scrollTop = box.scrollHeight;
  }
  function setTopic(next) {
    topic = String(next || 'seller_help');
    topicPills.forEach(function (b) {
      b.classList.toggle('is-active', String(b.getAttribute('data-topic') || '') === topic);
    });
    helpItems.forEach(function (row) {
      row.classList.toggle('is-active', String(row.getAttribute('data-topic') || '') === topic);
    });
    var meta = topicLabels[topic] || topicLabels.seller_help;
    if (input) input.placeholder = meta.ph;
    if (headName) headName.textContent = 'Admin support';
    if (headStatus && !viewingHistory) headStatus.textContent = 'Active now';
  }
  function exitHistoryView() {
    viewingHistory = false;
    if (chatEl) chatEl.classList.remove('is-history-mode');
    if (historyPanel) historyPanel.classList.remove('is-open');
    if (headStatus) headStatus.textContent = 'Active now';
    loadHistory();
  }
  async function loadCaseList() {
    if (!historyList) return;
    historyList.innerHTML = '<div class="sas-history-empty">Loading…</div>';
    try {
      var res = await fetch(endpoint + '?mode=cases', { credentials: 'same-origin', cache: 'no-store' });
      var data = await res.json();
      if (data && data.open_product && !viewingHistory) {
        aboutProduct = parseInt(data.open_product.id || 0, 10) || aboutProduct;
        renderProductCard(productEl, data.open_product, false);
      } else if (data && !data.seller_case_open && !viewingHistory) {
        renderProductCard(productEl, null, true);
      }
      var cases = (data && data.cases) || [];
      var list = cases.filter(function (c) { return !c.is_open; });
      if (!list.length) list = cases.slice();
      var byProduct = {};
      list.forEach(function (c) {
        var pid = parseInt(c.product_id || 0, 10) || 0;
        if (pid <= 0) return;
        if (!byProduct[pid]) byProduct[pid] = c;
      });
      var rows = Object.keys(byProduct).map(function (k) { return byProduct[k]; });
      rows.sort(function (a, b) {
        return (parseInt(b.product_id || 0, 10) || 0) - (parseInt(a.product_id || 0, 10) || 0);
      });
      if (!rows.length) {
        historyList.innerHTML = '<div class="sas-history-empty">No product history yet.</div>';
        return;
      }
      historyList.innerHTML = '';
      rows.forEach(function (c) {
        var pid = parseInt(c.product_id || 0, 10) || 0;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sas-history-item';
        btn.setAttribute('role', 'menuitem');
        btn.setAttribute('data-dispute-id', String(c.id || 0));
        btn.setAttribute('data-product-id', String(pid));
        var cover = String(c.product_cover || '');
        var prodTitle = String(c.product_title || ('Product #' + pid));
        var label = 'Product ID #' + pid + ' history';
        var img = cover || '';
        var meta = prodTitle;
        btn.innerHTML =
          (img
            ? '<img src="' + esc(img) + '" alt="">'
            : '<img alt="" style="background:rgba(148,163,184,.25)">') +
          '<span class="sas-history-item-text">' +
            '<span class="sas-history-item-label">' + esc(label) + '</span>' +
            (meta ? '<span class="sas-history-item-meta">' + esc(meta) + '</span>' : '') +
          '</span>';
        btn.addEventListener('click', function () {
          openCaseHistory(parseInt(c.id || 0, 10) || 0, pid);
        });
        historyList.appendChild(btn);
      });
    } catch (e) {
      historyList.innerHTML = '<div class="sas-history-empty">Could not load history.</div>';
    }
  }
  async function openCaseHistory(disputeId, productIdHint) {
    if (disputeId <= 0) return;
    closeMoreMenu();
    viewingHistory = true;
    if (chatEl) chatEl.classList.add('is-history-mode');
    if (historyPanel) historyPanel.classList.add('is-open');
    var pidHint = parseInt(productIdHint || 0, 10) || 0;
    if (historyTitle) {
      historyTitle.textContent = pidHint > 0 ? ('Product ID #' + pidHint + ' history') : 'Product history';
    }
    if (headStatus) headStatus.textContent = 'Viewing history';
    if (historyThread) historyThread.innerHTML = '<div class="sas-empty">Loading…</div>';
    try {
      var res = await fetch(endpoint + '?mode=case_history&dispute_id=' + encodeURIComponent(String(disputeId)), {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      var data = await res.json();
      if (!data || !data.ok) {
        if (historyThread) {
          historyThread.innerHTML = '<div class="sas-empty">' + esc((data && data.error) || 'Could not load case.') + '</div>';
        }
        return;
      }
      var pid = (data.product && parseInt(data.product.id || 0, 10)) || pidHint || 0;
      aboutProduct = pid || aboutProduct;
      if (historyTitle) {
        historyTitle.textContent = pid > 0 ? ('Product ID #' + pid + ' history') : 'Product history';
      }
      renderProductCard(productEl, data.product || null, false);
      if (historyThread) {
        historyThread.innerHTML = '';
        if ((data.items || []).length) {
          appendItems(data.items, true, historyThread);
        } else {
          historyThread.innerHTML = '<div class="sas-empty">No messages saved for Product ID #' + (pid || '?') + '.</div>';
        }
      }
    } catch (e) {
      if (historyThread) historyThread.innerHTML = '<div class="sas-empty">Could not load case.</div>';
    }
  }

  async function loadHistory() {
    if (viewingHistory) return;
    try {
      var res = await fetch(endpoint + '?mode=history&after=0&mark=1', { credentials: 'same-origin', cache: 'no-store' });
      var data = await res.json();
      if (data && data.open_product) {
        aboutProduct = parseInt(data.open_product.id || 0, 10) || aboutProduct;
        renderProductCard(productEl, data.open_product, false);
      } else if (data && !data.seller_case_open) {
        renderProductCard(productEl, null, true);
      }
      if (data && data.ok) {
        lastId = 0;
        appendItems(data.items || [], true);
      }
    } catch (e) { /* ignore */ }
  }
  async function pollNew() {
    if (polling || viewingHistory) return;
    polling = true;
    try {
      var res = await fetch(endpoint + '?mode=history&after=' + lastId + '&mark=1', { credentials: 'same-origin', cache: 'no-store' });
      var data = await res.json();
      if (data && data.open_product) {
        aboutProduct = parseInt(data.open_product.id || 0, 10) || aboutProduct;
        renderProductCard(productEl, data.open_product, false);
      }
      if (data && data.ok && (data.items || []).length) {
        if (thread && thread.querySelector('.seller-admin-support-empty')) thread.innerHTML = '';
        appendItems(data.items, false);
      }
    } catch (e) { /* ignore */ }
    polling = false;
  }
  async function sendMessage() {
    setErr('');
    var text = input ? String(input.value || '').trim() : '';
    if (!text) { setErr('Type a message for Admin.'); return; }
    if (sendBtn) sendBtn.disabled = true;
    try {
      var body = new URLSearchParams();
      body.set('mode', 'send');
      body.set('topic', topic);
      body.set('message', text);
      if (aboutProduct > 0) body.set('about_product', String(aboutProduct));
      var res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body.toString(),
        credentials: 'same-origin',
        cache: 'no-store'
      });
      var data = await res.json();
      if (!data || !data.ok) {
        setErr((data && (data.error || data.message)) || 'Could not send.');
        return;
      }
      if (input) input.value = '';
      if (data.item) {
        if (thread && thread.querySelector('.seller-admin-support-empty')) thread.innerHTML = '';
        appendItems([data.item], false);
      } else {
        await pollNew();
      }
    } catch (e) {
      setErr('Could not send message.');
    } finally {
      if (sendBtn) sendBtn.disabled = false;
    }
  }

  topicPills.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (viewingHistory) exitHistoryView();
      setTopic(btn.getAttribute('data-topic') || 'seller_help');
    });
  });
  helpItems.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (viewingHistory) exitHistoryView();
      setTopic(btn.getAttribute('data-topic') || 'seller_help');
    });
  });
  if (searchEl) {
    searchEl.addEventListener('input', function () {
      var q = String(searchEl.value || '').trim().toLowerCase();
      helpItems.forEach(function (row) {
        var blob = String(row.getAttribute('data-search') || '') + ' ' + String(row.textContent || '');
        row.style.display = (!q || blob.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }
  if (moreBtn && moreWrap) {
    moreBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = moreWrap.classList.toggle('is-open');
      moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) loadCaseList();
    });
    document.addEventListener('click', function (e) {
      if (!moreWrap.contains(e.target)) closeMoreMenu();
    });
  }
  if (historyBack) {
    historyBack.addEventListener('click', function () {
      exitHistoryView();
    });
  }
  if (sendBtn) sendBtn.addEventListener('click', sendMessage);
  if (input) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
  }
  setTopic('seller_help');
  loadHistory();
  loadCaseList();
  setInterval(pollNew, 5000);
})();
</script>
