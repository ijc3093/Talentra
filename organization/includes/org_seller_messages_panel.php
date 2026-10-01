<?php
declare(strict_types=1);

/**
 * Seller customer chat panel for sales_management.php#message.
 * UI matched to buyer Shopping Preferences → Chat with sellers.
 *
 * Expected vars:
 * - array $sellerBuyerMsgContacts
 * - array|null $sellerBuyerMsgActive
 * - string $sellerBuyerMsgDraft
 * - int $sellerBuyerMsgAboutProduct
 * - string $sellerBuyerMsgAboutOrder
 * - array|null $sellerBuyerMsgProductFocus
 * - int $sellerMsgPublisherId (optional)
 * - int $orgId (optional)
 */

if (!function_exists('h')) {
    function h(string $s): string
    {
        if (function_exists('org_ecommerce_h')) {
            return org_ecommerce_h($s);
        }
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('sbm_avatar_url')) {
    function sbm_avatar_url(int $userId, string $name, string $friendCode = ''): string
    {
        $label = trim($name !== '' ? $name : ($friendCode !== '' ? $friendCode : 'Customer'));
        $parts = preg_split('/\s+/', $label) ?: [];
        $a = strtoupper(substr((string)($parts[0] ?? '?'), 0, 1));
        $b = count($parts) >= 2
            ? strtoupper(substr((string)$parts[count($parts) - 1], 0, 1))
            : strtoupper(substr((string)($parts[0] ?? ''), 1, 1));
        $initials = htmlspecialchars(trim($a . $b) !== '' ? trim($a . $b) : '?', ENT_QUOTES, 'UTF-8');
        $key = $userId > 0 ? ('u' . $userId) : strtolower($label);
        $n = hexdec(substr(sha1($key), 0, 6));
        $h = $n % 360;
        $bg = "hsl({$h}, 62%, 46%)";
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="84" height="84" viewBox="0 0 84 84">'
            . '<circle cx="42" cy="42" r="42" fill="' . $bg . '"/>'
            . '<text x="50%" y="54%" text-anchor="middle" font-family="Inter,system-ui,sans-serif" font-size="32" font-weight="700" fill="#fff">'
            . $initials . '</text></svg>';
        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
    }
}

if (!function_exists('sbm_list_time')) {
    function sbm_list_time($at): string
    {
        $ts = $at ? strtotime((string)$at) : false;
        if (!$ts) {
            return '';
        }
        if (date('Y-m-d', $ts) === date('Y-m-d')) {
            return date('g:i A', $ts);
        }
        if (date('Y', $ts) === date('Y')) {
            return date('M j', $ts);
        }
        return date('M j, Y', $ts);
    }
}

if (!function_exists('sbm_short_time')) {
    function sbm_short_time(string $label): string
    {
        $s = trim($label);
        if ($s === '') {
            return '';
        }
        if (preg_match('/(\d{1,2}:\d{2}\s*[AP]M)/i', $s, $m)) {
            return $m[1];
        }
        return $s;
    }
}

$sellerBuyerMsgContacts = is_array($sellerBuyerMsgContacts ?? null) ? $sellerBuyerMsgContacts : [];
$sellerBuyerMsgActive = is_array($sellerBuyerMsgActive ?? null) ? $sellerBuyerMsgActive : null;
$sellerBuyerMsgDraft = (string)($sellerBuyerMsgDraft ?? '');
$sellerBuyerMsgAboutProduct = (int)($sellerBuyerMsgAboutProduct ?? 0);
$sellerBuyerMsgAboutOrder = (string)($sellerBuyerMsgAboutOrder ?? '');
$sellerBuyerMsgProductFocus = is_array($sellerBuyerMsgProductFocus ?? null) ? $sellerBuyerMsgProductFocus : null;
$activePeer = strtoupper(trim((string)($sellerBuyerMsgActive['friend_code'] ?? '')));
$activeName = trim((string)($sellerBuyerMsgActive['buyer_name'] ?? 'Customer'));
$activeBuyerId = (int)($sellerBuyerMsgActive['buyer_user_id'] ?? 0);
$activePreview = trim((string)($sellerBuyerMsgActive['last_message'] ?? ''));
$activeLastAt = trim((string)($sellerBuyerMsgActive['last_at'] ?? ''));
$activeAva = sbm_avatar_url($activeBuyerId, $activeName, $activePeer);

// Preload thread so customer messages appear even before JS poll.
$sellerBuyerMsgInitialItems = [];
$sellerMsgPublisherIdLocal = (int)($sellerMsgPublisherId ?? 0);
if ($sellerMsgPublisherIdLocal <= 0 && isset($dbh) && $dbh instanceof PDO && function_exists('staff_pub_org_publisher_user_id')) {
    try {
        $sellerMsgPublisherIdLocal = staff_pub_org_publisher_user_id($dbh, (int)($orgId ?? 0));
    } catch (Throwable $e) {
        $sellerMsgPublisherIdLocal = 0;
    }
}
if ($sellerMsgPublisherIdLocal <= 0 && isset($_SESSION['org_publisher_user_id'])) {
    $sellerMsgPublisherIdLocal = (int)$_SESSION['org_publisher_user_id'];
}
if ($sellerMsgPublisherIdLocal > 0 && $activeBuyerId > 0 && function_exists('commerce_messaging_thread_items') && isset($dbh) && $dbh instanceof PDO) {
    try {
        $sellerBuyerMsgInitialItems = commerce_messaging_thread_items(
            $dbh,
            $sellerMsgPublisherIdLocal,
            $activeBuyerId,
            0,
            2000,
            true
        );
    } catch (Throwable $e) {
        $sellerBuyerMsgInitialItems = [];
    }
}

if (!$sellerBuyerMsgInitialItems && $activePreview !== '' && !preg_match('/^Order\s/i', $activePreview)) {
    $seedTs = $activeLastAt !== '' ? strtotime($activeLastAt) : false;
    $sellerBuyerMsgInitialItems[] = [
        'id' => 0,
        'is_me' => false,
        'text' => $activePreview,
        'created_at' => $activeLastAt,
        'time_label' => $seedTs ? date('M d, Y h:i A', $seedTs) : '',
        'sender_name' => $activeName,
        'peer_name' => $activeName,
        'is_read' => 1,
    ];
}

// Infer product focus from contact / URL / message text so seller always sees image + ID + name.
if ($sellerBuyerMsgAboutProduct <= 0 && is_array($sellerBuyerMsgActive)) {
    $sellerBuyerMsgAboutProduct = (int)($sellerBuyerMsgActive['about_product_id'] ?? 0);
}
if ($sellerBuyerMsgAboutProduct <= 0 && function_exists('commerce_messaging_parse_product_id_from_text')) {
    foreach ($sellerBuyerMsgInitialItems as $item) {
        $pid = commerce_messaging_parse_product_id_from_text((string)($item['text'] ?? ''));
        if ($pid > 0) {
            $sellerBuyerMsgAboutProduct = $pid;
            break;
        }
    }
    if ($sellerBuyerMsgAboutProduct <= 0 && $activePreview !== '') {
        $sellerBuyerMsgAboutProduct = commerce_messaging_parse_product_id_from_text($activePreview);
    }
}
if ($sellerBuyerMsgProductFocus === null && $sellerBuyerMsgAboutProduct > 0 && function_exists('commerce_messaging_product_focus') && isset($dbh) && $dbh instanceof PDO) {
    $sellerBuyerMsgProductFocus = commerce_messaging_product_focus($dbh, $sellerBuyerMsgAboutProduct, (int)($orgId ?? 0));
}
// Persist inferred product onto buyer↔seller contact for next open.
if ($sellerBuyerMsgAboutProduct > 0 && $activeBuyerId > 0 && $sellerMsgPublisherIdLocal > 0
    && function_exists('commerce_buyer_seller_contact_remember') && isset($dbh) && $dbh instanceof PDO) {
    try {
        commerce_buyer_seller_contact_remember($dbh, $activeBuyerId, $sellerMsgPublisherIdLocal, $sellerBuyerMsgAboutProduct);
    } catch (Throwable $e) {
        // ignore
    }
}

$sbmProdId = (int)($sellerBuyerMsgProductFocus['id'] ?? $sellerBuyerMsgAboutProduct);
$sbmProdTitle = trim((string)($sellerBuyerMsgProductFocus['title'] ?? ''));
$sbmProdPrice = trim((string)($sellerBuyerMsgProductFocus['price'] ?? ''));
$sbmProdCover = trim((string)($sellerBuyerMsgProductFocus['cover'] ?? ''));
$sbmProdHref = trim((string)($sellerBuyerMsgProductFocus['seller_href'] ?? ''));
$sbmProdCode = trim((string)($sellerBuyerMsgProductFocus['code'] ?? ''));
$sbmProdIdLabel = '';
if ($sbmProdId > 0) {
    $sbmProdIdLabel = 'Product ID #' . $sbmProdId;
    if ($sbmProdCode !== '') {
        $sbmProdIdLabel .= ' · ' . $sbmProdCode;
    }
    if ($sbmProdTitle === '') {
        $sbmProdTitle = 'Product #' . $sbmProdId;
    }
    if ($sbmProdHref === '') {
        $sbmProdHref = 'sales_management.php?inv_product=' . $sbmProdId . '#inventory-detail';
    }
    // Carry buyer context so inventory detail can show that customer's order status.
    if ($activeBuyerId > 0 && $sbmProdHref !== '' && strpos($sbmProdHref, 'buyer_msg=') === false) {
        $sbmProdHref .= (strpos($sbmProdHref, '?') !== false ? '&' : '?') . 'buyer_msg=' . $activeBuyerId;
    }
}

$sellerBuyerMsgInitialJson = json_encode(
    $sellerBuyerMsgInitialItems,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
);
if (!is_string($sellerBuyerMsgInitialJson)) {
    $sellerBuyerMsgInitialJson = '[]';
}
$sellerBuyerMsgProductFocusJson = json_encode(
    $sellerBuyerMsgProductFocus ?: (
        $sbmProdId > 0
            ? [
                'id' => $sbmProdId,
                'code' => $sbmProdCode,
                'title' => $sbmProdTitle,
                'price' => $sbmProdPrice,
                'cover' => $sbmProdCover,
                'seller_href' => $sbmProdHref,
            ]
            : null
    ),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
);
if (!is_string($sellerBuyerMsgProductFocusJson)) {
    $sellerBuyerMsgProductFocusJson = 'null';
}
?>
<style>
  .sbm-wrap{display:flex;flex-direction:column;gap:12px;min-height:0;padding-top:28px;}
  .sbm-intro{flex:0 0 auto;padding:4px 2px 10px;border:0;background:transparent;max-width:min(100%,560px);}
  .sbm-intro-kicker{
    margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
    color:rgba(148,163,184,.95);
  }
  .sbm-intro-title{
    margin:0 0 8px;font-size:24px;font-weight:800;letter-spacing:-.02em;line-height:1.15;
    color:#f8fafc;
  }
  .sbm-intro-sub{
    margin:0 0 4px;font-size:13px;line-height:1.45;color:#94a3b8;max-width:62ch;
  }
  html:not(.dark-auto) .sbm-intro-title{color:#0f172a;}
  html:not(.dark-auto) .sbm-intro-sub{color:#64748b;}
  html:not(.dark-auto) .sbm-intro-kicker{color:#94a3b8;}
  .sbm-shell{
    display:grid;grid-template-columns:minmax(280px,340px) minmax(0,1fr);gap:12px;
    min-height:520px;height:min(58vh,640px);margin-top:8px;
  }
  .sbm-layout{display:contents;}
  .sbm-rail{
    display:flex;flex-direction:column;min-width:0;min-height:0;
    border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .sbm-toolbar{
    display:flex;gap:8px;align-items:center;padding:12px 12px 10px;flex:0 0 auto;background:#fff;
    border-bottom:1px solid #f1f5f9;
  }
  .sbm-search{position:relative;flex:1 1 auto;min-width:0;}
  .sbm-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;pointer-events:none;}
  .sbm-search input{
    width:100%;height:40px;border:1px solid #e2e8f0;border-radius:10px;padding:0 12px 0 34px;
    font-size:13px;background:#f8fafc;color:#0f172a;outline:none;
  }
  .sbm-search input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
  .sbm-filter{
    flex:0 0 auto;height:40px;padding:0 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;
    font-size:13px;font-weight:700;color:#334155;cursor:pointer;display:inline-flex;align-items:center;gap:6px;
  }
  .sbm-list{flex:1 1 auto;min-height:0;overflow:auto;background:#fff;}
  .sbm-item-row{display:block;border-bottom:0;position:relative;}
  .sbm-item{
    display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:10px;align-items:start;
    padding:12px 14px;color:inherit;text-decoration:none;min-width:0;cursor:pointer;
    box-shadow:none;
  }
  .sbm-item-row:hover{background:#f8fafc;}
  .sbm-item-row.is-active{background:#eff6ff;}
  .sbm-item-row.is-active .sbm-item{box-shadow:none;}
  .sbm-ava{width:42px;height:42px;border-radius:999px;object-fit:cover;background:#e2e8f0;display:block;}
  .sbm-item-main{min-width:0;}
  .sbm-item-top{display:flex;align-items:center;gap:6px;min-width:0;}
  .sbm-item-top strong{font-size:13.5px;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .sbm-badge{
    display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;
    border-radius:999px;background:#dc3545;color:#fff;font-size:10px;font-weight:800;flex:0 0 auto;
  }
  .sbm-item-preview{display:block;margin-top:3px;font-size:12px;line-height:1.35;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .sbm-item-time{font-size:11px;font-weight:600;color:#94a3b8;padding-top:2px;white-space:nowrap;}
  .sbm-chat{
    display:flex;flex-direction:column;min-height:0;height:100%;
    border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .sbm-head{
    display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #eef2f7;
    background:#fff;flex:0 0 auto;
  }
  .sbm-head-ava{width:42px;height:42px;border-radius:999px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
  .sbm-head-meta{flex:0 1 auto;min-width:0;max-width:42%;}
  .sbm-head-name{display:flex;align-items:center;gap:6px;font-size:15px;font-weight:800;color:#0f172a;margin:0;line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;}
  .sbm-head-status{
    display:inline-flex;align-items:center;gap:6px;width:auto;max-width:100%;margin:1px 0 0;
    font-size:12px;font-weight:600;line-height:1.2;color:#16a34a;
    background:transparent!important;border:0!important;box-shadow:none!important;filter:none!important;
  }
  .sbm-head-status i{font-size:8px;line-height:1;flex:0 0 auto;background:transparent!important;}
  .sbm-more{position:relative;flex:0 0 auto;}
  .sbm-more-btn{
    width:36px;height:36px;border:0;border-radius:999px;background:transparent;color:#64748b;
    display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
  }
  .sbm-more-btn:hover,.sbm-more.is-open .sbm-more-btn{background:#f1f5f9;color:#0f172a;}
  .sbm-more-menu{
    display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:50;min-width:260px;max-width:min(360px,90vw);
    padding:6px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;
    box-shadow:0 10px 30px rgba(15,23,42,.12);
  }
  .sbm-more.is-open .sbm-more-menu{display:block;}
  .sbm-history-head{
    padding:8px 12px 6px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748b;
  }
  .sbm-history-list{max-height:280px;overflow:auto;padding:0 0 4px;}
  .sbm-history-empty{padding:10px 12px;font-size:12px;color:#64748b;}
  .sbm-history-item{
    display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:0;border-radius:8px;
    background:transparent;text-align:left;cursor:pointer;
  }
  .sbm-history-item:hover,.sbm-history-item.is-active{background:#f1f5f9;}
  .sbm-history-item img{width:32px;height:32px;border-radius:6px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
  .sbm-history-item-label{display:block;font-size:13px;font-weight:800;color:#0f172a;line-height:1.25;}
  .sbm-history-item-meta{display:block;margin-top:2px;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .sbm-history-bar{
    display:none;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #eef2f7;background:#fff;flex:0 0 auto;
  }
  .sbm-history-bar.is-open{display:flex;}
  .sbm-history-back{
    border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#334155;
    font-size:12px;font-weight:800;padding:6px 10px;cursor:pointer;flex:0 0 auto;
  }
  .sbm-history-back:hover{background:#f8fafc;}
  .sbm-history-title{font-size:13px;font-weight:800;color:#0f172a;margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .sbm-chat.is-history-mode .sbm-compose-wrap{display:none!important;}
  .sbm-row[hidden]{display:none!important;}
  /* Same header row as buyer — card next to ⋯ */
  .sbm-product{
    display:grid;grid-template-columns:36px minmax(0,1fr) auto;gap:8px;align-items:center;
    flex:0 1 auto;min-width:0;max-width:380px;width:auto;margin:0 12px 0 auto;padding:5px 8px;
    border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;
  }
  .sbm-product[hidden]{display:none!important;margin-left:0;}
  .sbm-head:has(.sbm-product[hidden]) .sbm-more,
  .sbm-head:not(:has(.sbm-product)) .sbm-more{margin-left:auto;}
  .sbm-product img{width:36px;height:36px;border-radius:6px;object-fit:cover;background:#e2e8f0;}
  .sbm-product > div{min-width:0;}
  .sbm-product strong{
    display:block;font-size:12px;font-weight:800;color:#0f172a;line-height:1.2;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }
  .sbm-product-id{
    display:block;margin-top:1px;font-size:10px;font-weight:700;letter-spacing:.02em;color:#64748b;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }
  .sbm-product-price{display:block;margin:1px 0 0;font-size:11px;font-weight:700;color:#0f172a;line-height:1.2;}
  .sbm-product a{
    flex:0 0 auto;padding:4px 8px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;
    font-size:11px;font-weight:800;color:#334155;text-decoration:none;white-space:nowrap;
  }
  .sbm-product a:hover{background:#f1f5f9;text-decoration:none;}
  .sbm-bubble.is-product-ref{
    display:grid;grid-template-columns:48px minmax(0,1fr);gap:10px;align-items:center;
    max-width:100%;
  }
  .sbm-bubble.is-product-ref img{
    width:48px;height:48px;border-radius:8px;object-fit:cover;background:rgba(148,163,184,.25);
  }
  .sbm-bubble.is-product-ref .sbm-bubble-prod-title{display:block;font-weight:800;font-size:13px;line-height:1.3;}
  .sbm-bubble.is-product-ref .sbm-bubble-prod-id{display:block;margin-top:2px;font-size:11px;font-weight:700;opacity:.85;}
  .sbm-thread{
    flex:1 1 auto;overflow:auto;padding:18px 28px 18px 16px;display:flex;flex-direction:column;gap:14px;
    background:#fff;min-height:0;box-sizing:border-box;
  }
  .sbm-row{display:flex;gap:8px;align-items:flex-end;max-width:78%;}
  .sbm-row.me{align-self:flex-end;flex-direction:row-reverse;margin-right:12px;}
  .sbm-row.them{align-self:flex-start;}
  .sbm-row-ava{width:28px;height:28px;border-radius:999px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
  .sbm-row.me .sbm-row-ava{display:none;}
  .sbm-bubble-wrap{min-width:0;}
  .sbm-bubble{max-width:100%;padding:10px 12px;border-radius:4px;font-size:13.5px;line-height:1.45;white-space:pre-wrap;word-break:break-word;}
  .sbm-bubble.me{background:#2563eb;color:#fff;}
  .sbm-bubble.them{background:#f1f5f9;color:#0f172a;}
  .sbm-meta{display:flex;align-items:center;gap:5px;font-size:11px;color:#94a3b8;margin-top:4px;padding:0 2px;}
  .sbm-row.me .sbm-meta{justify-content:flex-end;}
  .sbm-meta .fa-check-double{font-size:11px;color:#60a5fa;}
  .sbm-compose-wrap{padding:12px 14px 14px;border-top:1px solid #eef2f7;background:#fff;flex:0 0 auto;}
  .sbm-compose{
    display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;
  }
  .sbm-compose-bar{
    display:flex;align-items:center;gap:4px;min-width:0;min-height:44px;
    padding:4px 10px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;
  }
  .sbm-compose-attach,.sbm-compose-tools button{
    width:34px;height:34px;border:0;border-radius:8px;background:transparent;color:#64748b;
    display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;
  }
  .sbm-compose-attach:hover,.sbm-compose-tools button:hover{background:#e2e8f0;color:#0f172a;}
  .sbm-compose textarea{
    flex:1 1 auto;min-height:34px;max-height:100px;resize:none;border:0;background:transparent;
    padding:7px 4px;font-size:13.5px;line-height:1.4;color:#0f172a;outline:none;box-shadow:none;
  }
  .sbm-compose-tools{display:flex;gap:0;flex:0 0 auto;}
  .sbm-compose #sellerBuyerMsgSend,
  body.org-app .commerce-page .sbm-compose #sellerBuyerMsgSend{
    height:44px;padding:0 18px;border:0 !important;border-radius:10px;
    background:#2563eb !important;color:#fff !important;-webkit-text-fill-color:#fff !important;
    font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;
  }
  .sbm-compose #sellerBuyerMsgSend:hover{background:#1d4ed8 !important;}
  .sbm-compose #sellerBuyerMsgSend:disabled{opacity:.55;cursor:not-allowed;}
  .sbm-empty{
    flex:1 1 auto;display:flex;align-items:center;justify-content:center;text-align:center;
    padding:40px 24px;color:#64748b;font-size:13.5px;line-height:1.5;
  }
  .sbm-empty-card{
    border:1px solid #e5e7eb;border-radius:12px;background:#fff;min-height:420px;
    display:flex;align-items:center;justify-content:center;text-align:center;
    padding:40px 24px;color:#64748b;font-size:13.5px;line-height:1.5;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  #sellerBuyerMsgErr{color:#dc2626;font-size:12px;margin:8px 0 0;}
  @media (max-width:980px){
    .sbm-shell{grid-template-columns:1fr;height:auto;min-height:0;}
    .sbm-rail{max-height:280px;}
    .sbm-chat{min-height:460px;}
    .sbm-intro-title{font-size:22px;}
  }
  /* Follow Dark auto / Progress color / Appearance palette */
  html.dark-auto .sbm-wrap,
  html[data-msb-appearance] .sbm-wrap,
  html.msb-palette-active .sbm-wrap{
    --sbm-bg:var(--msb-palette-bg,#171d24);
    --sbm-raised:var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733));
    --sbm-text:var(--msb-palette-text,#e2e8f0);
    --sbm-muted:var(--msb-palette-text-muted,#94a3b8);
    --sbm-border:var(--msb-palette-border,rgba(148,163,184,.28));
    --sbm-input:var(--msb-palette-input-bg,var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733)));
    --sbm-accent:var(--msb-palette-action,var(--org-accent,#2563eb));
    --sbm-accent-soft:var(--msb-palette-action-soft,rgba(37,99,235,.16));
    --sbm-btn:var(--msb-palette-btn-bg,var(--msb-palette-action,var(--org-accent,#2563eb)));
    --sbm-btn-text:var(--msb-palette-btn-text,#fff);
  }
  html.dark-auto .sbm-rail,
  html.dark-auto .sbm-chat,
  html.dark-auto .sbm-toolbar,
  html.dark-auto .sbm-list,
  html.dark-auto .sbm-head,
  html.dark-auto .sbm-thread,
  html.dark-auto .sbm-compose-wrap,
  html.dark-auto .sbm-empty-card,
  html[data-msb-appearance] .sbm-rail,
  html[data-msb-appearance] .sbm-chat,
  html[data-msb-appearance] .sbm-toolbar,
  html[data-msb-appearance] .sbm-list,
  html[data-msb-appearance] .sbm-head,
  html[data-msb-appearance] .sbm-thread,
  html[data-msb-appearance] .sbm-compose-wrap,
  html[data-msb-appearance] .sbm-empty-card,
  html.msb-palette-active .sbm-rail,
  html.msb-palette-active .sbm-chat,
  html.msb-palette-active .sbm-toolbar,
  html.msb-palette-active .sbm-list,
  html.msb-palette-active .sbm-head,
  html.msb-palette-active .sbm-thread,
  html.msb-palette-active .sbm-compose-wrap,
  html.msb-palette-active .sbm-empty-card{
    background:var(--sbm-bg,var(--msb-palette-bg,#171d24))!important;
    border-color:var(--sbm-border,var(--msb-palette-border,rgba(148,163,184,.28)))!important;
    color:var(--sbm-text,var(--msb-palette-text,#e2e8f0))!important;
  }
  html.dark-auto .sbm-intro-title,
  html[data-msb-appearance] .sbm-intro-title,
  html.msb-palette-active .sbm-intro-title,
  html.dark-auto .sbm-item-top strong,
  html[data-msb-appearance] .sbm-item-top strong,
  html.msb-palette-active .sbm-item-top strong,
  html.dark-auto .sbm-head-name,
  html[data-msb-appearance] .sbm-head-name,
  html.msb-palette-active .sbm-head-name{color:var(--sbm-text,var(--msb-palette-text,#e2e8f0))!important;}
  html.dark-auto .sbm-intro-sub,
  html.dark-auto .sbm-intro-kicker,
  html.dark-auto .sbm-item-preview,
  html.dark-auto .sbm-item-time,
  html.dark-auto .sbm-empty,
  html[data-msb-appearance] .sbm-intro-sub,
  html[data-msb-appearance] .sbm-intro-kicker,
  html[data-msb-appearance] .sbm-item-preview,
  html[data-msb-appearance] .sbm-item-time,
  html[data-msb-appearance] .sbm-empty,
  html.msb-palette-active .sbm-intro-sub,
  html.msb-palette-active .sbm-intro-kicker,
  html.msb-palette-active .sbm-item-preview,
  html.msb-palette-active .sbm-item-time,
  html.msb-palette-active .sbm-empty{color:var(--sbm-muted,var(--msb-palette-text-muted,#94a3b8))!important;}
  html.dark-auto .sbm-search input,
  html.dark-auto .sbm-filter,
  html.dark-auto .sbm-compose-bar,
  html[data-msb-appearance] .sbm-search input,
  html[data-msb-appearance] .sbm-filter,
  html[data-msb-appearance] .sbm-compose-bar,
  html.msb-palette-active .sbm-search input,
  html.msb-palette-active .sbm-filter,
  html.msb-palette-active .sbm-compose-bar{
    background:var(--sbm-input,var(--msb-palette-input-bg,#1e2733))!important;
    border-color:var(--sbm-border,rgba(148,163,184,.28))!important;
    color:var(--sbm-text,#e2e8f0)!important;
  }
  html.dark-auto .sbm-compose textarea,
  html[data-msb-appearance] .sbm-compose textarea,
  html.msb-palette-active .sbm-compose textarea{color:var(--sbm-text,#e2e8f0)!important;}
  html.dark-auto .sbm-item-row,
  html[data-msb-appearance] .sbm-item-row,
  html.msb-palette-active .sbm-item-row{border-bottom:0!important;background:transparent!important;}
  html.dark-auto .sbm-item-row:hover,
  html[data-msb-appearance] .sbm-item-row:hover,
  html.msb-palette-active .sbm-item-row:hover{background:var(--sbm-raised,#1e2733)!important;}
  html.dark-auto .sbm-item-row.is-active,
  html[data-msb-appearance] .sbm-item-row.is-active,
  html.msb-palette-active .sbm-item-row.is-active{background:var(--sbm-accent-soft,rgba(37,99,235,.16))!important;}
  html.dark-auto .sbm-item-row.is-active .sbm-item,
  html[data-msb-appearance] .sbm-item-row.is-active .sbm-item,
  html.msb-palette-active .sbm-item-row.is-active .sbm-item,
  html.dark-auto .sbm-item,
  html[data-msb-appearance] .sbm-item,
  html.msb-palette-active .sbm-item{box-shadow:none!important;}
  html.dark-auto .sbm-product strong,
  html.dark-auto .sbm-product-price,
  html[data-msb-appearance] .sbm-product strong,
  html[data-msb-appearance] .sbm-product-price,
  html.msb-palette-active .sbm-product strong,
  html.msb-palette-active .sbm-product-price{color:var(--sbm-text,#e2e8f0)!important;}
  html.dark-auto .sbm-product-id,
  html[data-msb-appearance] .sbm-product-id,
  html.msb-palette-active .sbm-product-id{color:var(--sbm-muted,#94a3b8)!important;}
  html.dark-auto .sbm-product,
  html[data-msb-appearance] .sbm-product,
  html.msb-palette-active .sbm-product{
    background:var(--sbm-raised,#1e2733)!important;
    border-color:var(--sbm-border,rgba(148,163,184,.28))!important;
  }
  html.dark-auto .sbm-product a,
  html[data-msb-appearance] .sbm-product a,
  html.msb-palette-active .sbm-product a{
    background:var(--sbm-bg,#171d24)!important;color:var(--sbm-text,#e2e8f0)!important;
    border-color:var(--sbm-border,rgba(148,163,184,.28))!important;
  }
  html.dark-auto .sbm-bubble.me,
  html[data-msb-appearance] .sbm-bubble.me,
  html.msb-palette-active .sbm-bubble.me,
  html.dark-auto .sbm-compose #sellerBuyerMsgSend,
  html[data-msb-appearance] .sbm-compose #sellerBuyerMsgSend,
  html.msb-palette-active .sbm-compose #sellerBuyerMsgSend,
  body.org-app .commerce-page html.dark-auto .sbm-compose #sellerBuyerMsgSend{
    background:var(--sbm-btn,var(--msb-palette-btn-bg,var(--msb-palette-action,#2563eb)))!important;
    color:var(--sbm-btn-text,#fff)!important;-webkit-text-fill-color:var(--sbm-btn-text,#fff)!important;
  }
  html.dark-auto .sbm-bubble.them,
  html[data-msb-appearance] .sbm-bubble.them,
  html.msb-palette-active .sbm-bubble.them{
    background:var(--sbm-raised,#1e2733)!important;color:var(--sbm-text,#e2e8f0)!important;
  }
</style>

<?php if (!$sellerBuyerMsgContacts): ?>
  <div class="sbm-wrap">
    <div class="sbm-intro">
      <p class="sbm-intro-kicker">Messages</p>
      <h2 class="sbm-intro-title">Customer chat</h2>
      <p class="sbm-intro-sub">Receive and reply to customer questions about products, orders, pickup, and delivery.</p>
    </div>
    <div class="sbm-empty-card">
      No customer chats yet. When a buyer messages you from the shop, their thread appears here.
    </div>
  </div>
<?php else: ?>
  <div class="sbm-wrap">
    <div class="sbm-intro">
      <p class="sbm-intro-kicker">Messages</p>
      <h2 class="sbm-intro-title">Customer chat</h2>
      <p class="sbm-intro-sub">Receive and reply to customer questions about products, orders, pickup, and delivery.</p>
    </div>
    <div class="sbm-shell">
    <div class="sbm-layout" id="sellerBuyerMsgRoot"
      data-peer="<?= h($activePeer) ?>"
      data-buyer-id="<?= (int)$activeBuyerId ?>"
      data-publisher-id="<?= (int)$sellerMsgPublisherIdLocal ?>"
      data-peer-name="<?= h($activeName) ?>"
      data-peer-avatar="<?= h($activeAva) ?>"
      data-draft="<?= h($sellerBuyerMsgDraft) ?>"
      data-about-product="<?= (int)$sbmProdId ?>"
      data-thread-count="<?= (int)count($sellerBuyerMsgInitialItems) ?>"
      data-sbm-v="9"
    >
      <div class="sbm-rail">
        <div class="sbm-toolbar">
          <div class="sbm-search">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input type="search" id="sellerBuyerMsgSearch" placeholder="Search customer messages..." autocomplete="off">
          </div>
          <button type="button" class="sbm-filter" id="sellerBuyerMsgFilter" aria-label="Filter conversations">
            All <i class="fa fa-chevron-down" aria-hidden="true"></i>
          </button>
        </div>
        <div class="sbm-list" id="sellerBuyerMsgList" aria-label="Customers">
          <?php foreach ($sellerBuyerMsgContacts as $c):
            $cid = (int)($c['buyer_user_id'] ?? 0);
            $isActive = $cid === (int)($sellerBuyerMsgActive['buyer_user_id'] ?? 0);
            $href = function_exists('commerce_message_buyer_sales_url')
              ? commerce_message_buyer_sales_url(
                  $cid,
                  $isActive ? $sellerBuyerMsgAboutProduct : (int)($c['about_product_id'] ?? 0),
                  $isActive ? $sellerBuyerMsgAboutOrder : ''
              )
              : ('sales_management.php?buyer_msg=' . $cid . '#message');
            $preview = (string)($c['last_message'] ?? '');
            if ($preview === '' && trim((string)($c['order_code'] ?? '')) !== '') {
                $preview = 'Order ' . (string)$c['order_code'];
            }
            if ($preview === '') {
                $preview = 'Start a conversation';
            }
            $cname = (string)($c['buyer_name'] ?? 'Customer');
            $cpeer = strtoupper(trim((string)($c['friend_code'] ?? '')));
            $cava = sbm_avatar_url($cid, $cname, $cpeer);
            $ctime = sbm_list_time($c['last_at'] ?? '');
            $searchBlob = strtolower($cname . ' ' . $preview);
          ?>
            <div class="sbm-item-row<?= $isActive ? ' is-active' : '' ?>" data-search="<?= h($searchBlob) ?>">
              <a class="sbm-item seller-buyer-msg-item<?= $isActive ? ' is-active' : '' ?>"
                 href="<?= h($href) ?>"
                 data-peer="<?= h($cpeer) ?>"
                 data-buyer-id="<?= (int)$cid ?>"
                 data-peer-name="<?= h($cname) ?>"
                 data-peer-avatar="<?= h($cava) ?>"
                 data-preview="<?= h($preview) ?>">
                <img class="sbm-ava" src="<?= h($cava) ?>" alt="">
                <span class="sbm-item-main">
                  <span class="sbm-item-top">
                    <strong><?= h($cname) ?></strong>
                    <?php
                      $threadUnread = (int)($c['unread'] ?? 0);
                      $threadBadge = $threadUnread > 0
                          ? $threadUnread
                          : (!empty($c['needs_reply']) ? 1 : 0);
                    ?>
                    <?php if ($threadBadge > 0): ?>
                      <span class="sbm-badge"><?= (int)min(99, $threadBadge) ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="sbm-item-preview"><?= h($preview) ?></span>
                </span>
                <?php if ($ctime !== ''): ?><span class="sbm-item-time"><?= h($ctime) ?></span><?php endif; ?>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="sbm-chat">
        <div class="sbm-head" id="sellerBuyerMsgHead">
          <img class="sbm-head-ava" id="sellerBuyerMsgHeadAva" src="<?= h($activeAva) ?>" alt="">
          <div class="sbm-head-meta">
            <p class="sbm-head-name" id="sellerBuyerMsgHeadName"><?= h($activeName) ?></p>
            <div class="sbm-head-status"><i class="fa fa-circle" aria-hidden="true"></i> Active now</div>
          </div>
          <?php if ($sbmProdId > 0): ?>
            <div class="sbm-product" id="sellerBuyerMsgProduct">
              <?php if ($sbmProdCover !== ''): ?>
                <img src="<?= h($sbmProdCover) ?>" alt="" id="sellerBuyerMsgProductImg">
              <?php else: ?>
                <img src="<?= h(sbm_avatar_url(0, $sbmProdTitle !== '' ? $sbmProdTitle : ('P' . $sbmProdId))) ?>" alt="" id="sellerBuyerMsgProductImg">
              <?php endif; ?>
              <div>
                <strong id="sellerBuyerMsgProductTitle"><?= h($sbmProdTitle) ?></strong>
                <?php if ($sbmProdIdLabel !== ''): ?><span class="sbm-product-id" id="sellerBuyerMsgProductId"><?= h($sbmProdIdLabel) ?></span><?php endif; ?>
                <?php if ($sbmProdPrice !== ''): ?><span class="sbm-product-price" id="sellerBuyerMsgProductPrice"><?= h($sbmProdPrice) ?></span><?php endif; ?>
              </div>
              <?php if ($sbmProdHref !== ''): ?><a href="<?= h($sbmProdHref) ?>" id="sellerBuyerMsgProductLink">Open product</a><?php endif; ?>
            </div>
          <?php else: ?>
            <div class="sbm-product" id="sellerBuyerMsgProduct" hidden></div>
          <?php endif; ?>
          <div class="sbm-more" id="sellerBuyerMsgMore">
            <button type="button" class="sbm-more-btn" id="sellerBuyerMsgMoreBtn" aria-label="Product history" aria-haspopup="menu" aria-expanded="false">
              <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
            </button>
            <div class="sbm-more-menu" id="sellerBuyerMsgMoreMenu" role="menu">
              <div class="sbm-history-head">Product history</div>
              <div class="sbm-history-list" id="sellerBuyerMsgHistoryList">
                <div class="sbm-history-empty">Loading…</div>
              </div>
            </div>
          </div>
        </div>
        <div class="sbm-history-bar" id="sellerBuyerMsgHistoryBar">
          <button type="button" class="sbm-history-back" id="sellerBuyerMsgHistoryBack">← Back</button>
          <p class="sbm-history-title" id="sellerBuyerMsgHistoryTitle">Product history</p>
        </div>
        <div class="sbm-thread" id="sellerBuyerMsgThread" aria-live="polite">
          <?php if ($sellerBuyerMsgInitialItems): ?>
            <?php
              $sbmRunningPid = $sbmProdId > 0 ? $sbmProdId : 0;
              foreach ($sellerBuyerMsgInitialItems as $item):
              $isMe = !empty($item['is_me']);
              $short = sbm_short_time((string)($item['time_label'] ?? ''));
              $itemText = (string)($item['text'] ?? '');
              $itemPid = function_exists('commerce_messaging_parse_product_id_from_text')
                ? commerce_messaging_parse_product_id_from_text($itemText)
                : 0;
              if ($itemPid > 0) {
                  $sbmRunningPid = $itemPid;
              }
              $rowPid = $sbmRunningPid;
              $isProdLine = !$isMe && $itemPid > 0 && (
                preg_match('/^Product\s*ID\s*#\s*\d+/i', trim($itemText))
                || preg_match('/^Regarding product/i', trim($itemText))
                || ($sbmProdId > 0 && $itemPid === $sbmProdId && mb_strlen(trim($itemText)) < 220)
              );
            ?>
              <div class="sbm-row <?= $isMe ? 'me' : 'them' ?>" data-id="<?= (int)($item['id'] ?? 0) ?>"<?= $rowPid > 0 ? ' data-product-id="' . (int)$rowPid . '"' : '' ?>>
                <?php if (!$isMe): ?>
                  <img class="sbm-row-ava" src="<?= h($activeAva) ?>" alt="">
                <?php endif; ?>
                <div class="sbm-bubble-wrap">
                  <?php if ($isProdLine): ?>
                    <?php
                      $bubbleTitle = $sbmProdId === $itemPid && $sbmProdTitle !== ''
                        ? $sbmProdTitle
                        : $itemText;
                      if (preg_match('/^Product\s*ID\s*#\s*\d+/i', trim($itemText)) && str_contains($itemText, '—')) {
                          $parts = explode('—', $itemText, 2);
                          $bubbleTitle = trim((string)($parts[1] ?? $bubbleTitle));
                      }
                      $bubbleCover = ($sbmProdId === $itemPid) ? $sbmProdCover : '';
                      $bubbleIdLabel = 'Product ID #' . $itemPid;
                      if ($sbmProdId === $itemPid && $sbmProdCode !== '') {
                          $bubbleIdLabel .= ' · ' . $sbmProdCode;
                      }
                    ?>
                    <div class="sbm-bubble them is-product-ref">
                      <?php if ($bubbleCover !== ''): ?>
                        <img src="<?= h($bubbleCover) ?>" alt="">
                      <?php else: ?>
                        <img alt="" style="background:rgba(148,163,184,.25)">
                      <?php endif; ?>
                      <div>
                        <span class="sbm-bubble-prod-title"><?= h($bubbleTitle) ?></span>
                        <span class="sbm-bubble-prod-id"><?= h($bubbleIdLabel) ?></span>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="sbm-bubble <?= $isMe ? 'me' : 'them' ?>"><?= h($itemText) ?></div>
                  <?php endif; ?>
                  <div class="sbm-meta">
                    <?= h($short) ?>
                    <?php if ($isMe): ?><i class="fa fa-check-double" aria-hidden="true"></i><?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="sbm-empty" id="sellerBuyerMsgEmpty">Loading conversation…</div>
          <?php endif; ?>
        </div>
        <div class="sbm-compose-wrap">
          <div class="sbm-compose">
            <div class="sbm-compose-bar">
              <textarea id="sellerBuyerMsgInput" rows="1" placeholder="Reply about the product or order..."></textarea>
            </div>
            <button type="button" id="sellerBuyerMsgSend">Send</button>
          </div>
          <p id="sellerBuyerMsgErr" hidden></p>
        </div>
      </div>
    </div>
  </div>
  </div>
  <script>
  (function () {
    var root = document.getElementById('sellerBuyerMsgRoot');
    if (!root || root.getAttribute('data-sbm-bound') === '1') return;
    root.setAttribute('data-sbm-bound', '1');

    var thread = document.getElementById('sellerBuyerMsgThread');
    var headName = document.getElementById('sellerBuyerMsgHeadName');
    var headAva = document.getElementById('sellerBuyerMsgHeadAva');
    var list = document.getElementById('sellerBuyerMsgList');
    var searchEl = document.getElementById('sellerBuyerMsgSearch');
    var input = document.getElementById('sellerBuyerMsgInput');
    var sendBtn = document.getElementById('sellerBuyerMsgSend');
    var errEl = document.getElementById('sellerBuyerMsgErr');
    var chatEl = root.querySelector('.sbm-chat');
    var composeWrap = root.querySelector('.sbm-compose-wrap');
    var moreWrap = document.getElementById('sellerBuyerMsgMore');
    var moreBtn = document.getElementById('sellerBuyerMsgMoreBtn');
    var historyList = document.getElementById('sellerBuyerMsgHistoryList');
    var historyBar = document.getElementById('sellerBuyerMsgHistoryBar');
    var historyBack = document.getElementById('sellerBuyerMsgHistoryBack');
    var historyTitle = document.getElementById('sellerBuyerMsgHistoryTitle');
    var peer = String(root.getAttribute('data-peer') || '').trim().toUpperCase();
    var buyerId = parseInt(root.getAttribute('data-buyer-id') || '0', 10) || 0;
    var peerAvatar = String(root.getAttribute('data-peer-avatar') || '');
    var draft = String(root.getAttribute('data-draft') || '');
    var lastId = 0;
    var polling = false;
    var loading = false;
    var viewingHistory = false;
    var viewingPid = 0;
    var allItems = [];
    var productCatalog = {};
    var endpoint = (function () {
      try {
        return new URL('ajax/seller_buyer_chat.php', window.location.href).toString();
      } catch (e) {
        return 'ajax/seller_buyer_chat.php';
      }
    })();
    var bootItems = <?= $sellerBuyerMsgInitialJson ?>;
    var bootProduct = <?= $sellerBuyerMsgProductFocusJson ?>;
    var productFocus = bootProduct && typeof bootProduct === 'object' ? bootProduct : null;
    if (productFocus && parseInt(productFocus.id || 0, 10) > 0) {
      productCatalog[parseInt(productFocus.id, 10)] = productFocus;
    }

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
    function parseProductIdFromText(text) {
      var s = String(text || '');
      var m = s.match(/Product\s*ID\s*#\s*(\d+)/i) || s.match(/\bproduct\s*#\s*(\d+)/i) || s.match(/\(product\s*#\s*(\d+)\)/i);
      return m ? (parseInt(m[1], 10) || 0) : 0;
    }
    function isProductFocusLine(text) {
      var s = String(text || '').trim();
      if (!s) return false;
      if (/^Product\s*ID\s*#\s*\d+/i.test(s) && s.length < 220) return true;
      if (/^Regarding product/i.test(s) && s.length < 220) return true;
      return false;
    }
    function closeMoreMenu() {
      if (moreWrap) moreWrap.classList.remove('is-open');
      if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
    }
    function focusFromMarkerText(text) {
      var pid = parseProductIdFromText(text);
      if (pid <= 0) return null;
      var title = 'Product #' + pid;
      var code = '';
      var dash = String(text || '').split('\u2014');
      if (dash.length > 1) title = dash.slice(1).join('\u2014').trim() || title;
      var codeMatch = String(text || '').match(/\bPRD-[0-9A-Z]+\b/i);
      if (codeMatch) code = codeMatch[0];
      return {
        id: pid,
        title: title,
        code: code,
        price: '',
        cover: '',
        seller_href: 'sales_management.php?inv_product=' + pid + '#inventory-detail'
      };
    }
    function renderProductCard(focus) {
      var card = document.getElementById('sellerBuyerMsgProduct');
      if (!card) return;
      var id = focus && parseInt(focus.id || 0, 10) > 0 ? parseInt(focus.id, 10) : 0;
      if (!id) {
        card.hidden = true;
        card.innerHTML = '';
        if (!viewingHistory) {
          root.setAttribute('data-about-product', '0');
          productFocus = null;
        }
        return;
      }
      productFocus = focus;
      productCatalog[id] = Object.assign({}, productCatalog[id] || {}, focus);
      if (!viewingHistory) root.setAttribute('data-about-product', String(id));
      var title = String(focus.title || ('Product #' + id));
      var code = String(focus.code || '');
      var idLabel = 'Product ID #' + id + (code ? (' \u00b7 ' + code) : '');
      var price = String(focus.price || '');
      var cover = String(focus.cover || '');
      var href = String(focus.seller_href || ('sales_management.php?inv_product=' + id + '#inventory-detail'));
      if (buyerId > 0 && href.indexOf('buyer_msg=') === -1) {
        href += (href.indexOf('?') >= 0 ? '&' : '?') + 'buyer_msg=' + buyerId;
      }
      card.hidden = false;
      card.innerHTML =
        (cover
          ? '<img src="' + esc(cover) + '" alt="" id="sellerBuyerMsgProductImg">'
          : '<img src="" alt="" id="sellerBuyerMsgProductImg" style="background:#334155">') +
        '<div>' +
          '<strong id="sellerBuyerMsgProductTitle">' + esc(title) + '</strong>' +
          '<span class="sbm-product-id" id="sellerBuyerMsgProductId">' + esc(idLabel) + '</span>' +
          (price ? '<span class="sbm-product-price" id="sellerBuyerMsgProductPrice">' + esc(price) + '</span>' : '') +
        '</div>' +
        '<a href="' + esc(href) + '" id="sellerBuyerMsgProductLink">Open product</a>';
    }
    function assignProductContexts(items) {
      var running = 0;
      return (items || []).map(function (item) {
        var copy = Object.assign({}, item);
        var pid = parseProductIdFromText(copy.text || '');
        if (pid > 0) {
          running = pid;
          var focus = focusFromMarkerText(copy.text || '');
          if (focus) productCatalog[pid] = Object.assign({}, productCatalog[pid] || {}, focus);
        }
        copy._product_id = running;
        return copy;
      });
    }
    async function ensureProductFromMessages(items) {
      if (viewingHistory) return;
      var found = 0;
      var listItems = items || [];
      for (var i = listItems.length - 1; i >= 0; i--) {
        var t = listItems[i] && listItems[i].text;
        if (!isProductFocusLine(t)) continue;
        found = parseProductIdFromText(t);
        if (found > 0) break;
      }
      if (!found) {
        if (productFocus && parseInt(productFocus.id || 0, 10) > 0) renderProductCard(productFocus);
        return;
      }
      if (productFocus && parseInt(productFocus.id || 0, 10) === found && productCatalog[found]) {
        renderProductCard(Object.assign({}, productCatalog[found], productFocus));
        return;
      }
      try {
        var res = await fetch(endpoint + '?mode=product&product_id=' + encodeURIComponent(String(found)), { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok && data.product) renderProductCard(data.product);
        else renderProductCard(Object.assign({}, productCatalog[found] || {}, {
          id: found, title: 'Product #' + found, code: '', price: '', cover: '',
          seller_href: 'sales_management.php?inv_product=' + found + '#inventory-detail'
        }));
      } catch (e) {
        renderProductCard({
          id: found, title: 'Product #' + found, code: '', price: '', cover: '',
          seller_href: 'sales_management.php?inv_product=' + found + '#inventory-detail'
        });
      }
    }
    function bubbleHtml(item) {
      var text = String(item.text || '');
      var isMe = !!item.is_me;
      var pid = parseProductIdFromText(text);
      var known = pid > 0 ? (productCatalog[pid] || ((productFocus && parseInt(productFocus.id || 0, 10) === pid) ? productFocus : null)) : null;
      if (!isMe && pid > 0 && (isProductFocusLine(text) || known)) {
        var title = known ? String(known.title || ('Product #' + pid)) : text;
        if (isProductFocusLine(text) && !known) {
          var dash = text.split('\u2014');
          title = dash.length > 1 ? dash.slice(1).join('\u2014').trim() : ('Product #' + pid);
        }
        var cover = known ? String(known.cover || '') : '';
        var code = known ? String(known.code || '') : '';
        var idLabel = 'Product ID #' + pid + (code ? (' \u00b7 ' + code) : '');
        return '<div class="sbm-bubble them is-product-ref">' +
          (cover ? '<img src="' + esc(cover) + '" alt="">' : '<img alt="" style="background:rgba(148,163,184,.25)">') +
          '<div><span class="sbm-bubble-prod-title">' + esc(title) + '</span>' +
          '<span class="sbm-bubble-prod-id">' + esc(idLabel) + '</span></div></div>';
      }
      return '<div class="sbm-bubble ' + (isMe ? 'me' : 'them') + '">' + esc(text) + '</div>';
    }
    function peerQuery() {
      var q = [];
      if (buyerId > 0) q.push('buyer_id=' + encodeURIComponent(String(buyerId)));
      if (peer) q.push('peer=' + encodeURIComponent(peer));
      return q.join('&');
    }
    function emptyHtml(msg) {
      return '<div class="sbm-empty">' + esc(msg || 'No messages yet. Reply when the customer writes, or send the first note about their order.') + '</div>';
    }
    function activePreviewText() {
      if (!list) return '';
      var active = list.querySelector('.sbm-item-row.is-active .seller-buyer-msg-item') || list.querySelector('.seller-buyer-msg-item.is-active');
      if (!active) return '';
      var preview = String(active.getAttribute('data-preview') || '').trim();
      if (!preview || preview === 'Start a conversation' || /^Order\s/i.test(preview)) return '';
      return preview;
    }
    function paintThread(items) {
      if (!thread) return;
      thread.innerHTML = '';
      var listItems = items || [];
      if (!listItems.length) {
        thread.innerHTML = emptyHtml(viewingHistory
          ? ('No messages saved for Product ID #' + (viewingPid || '?') + '.')
          : '');
        return;
      }
      listItems.forEach(function (item) {
        var id = parseInt(item.id || 0, 10);
        if (!viewingHistory && id > lastId) lastId = id;
        var isMe = !!item.is_me;
        var row = document.createElement('div');
        row.className = 'sbm-row ' + (isMe ? 'me' : 'them');
        row.setAttribute('data-id', String(id > 0 ? id : 0));
        var pid = parseInt(item._product_id || 0, 10) || 0;
        if (pid > 0) row.setAttribute('data-product-id', String(pid));
        var avaHtml = isMe ? '' : ('<img class="sbm-row-ava" src="' + esc(peerAvatar) + '" alt="">');
        var checks = isMe ? ' <i class="fa fa-check-double" aria-hidden="true"></i>' : '';
        row.innerHTML =
          avaHtml +
          '<div class="sbm-bubble-wrap">' +
            bubbleHtml(item) +
            '<div class="sbm-meta">' + esc(shortTime(item.time_label || '')) + checks + '</div>' +
          '</div>';
        thread.appendChild(row);
      });
      thread.scrollTop = thread.scrollHeight;
    }
    function seedFromPreview() {
      var preview = activePreviewText();
      if (!preview || !thread) return false;
      if (thread.querySelector('.sbm-row[data-id]:not([data-id="0"])')) return true;
      if (thread.querySelector('.sbm-row')) return true;
      appendItems([{ id: 0, is_me: false, text: preview, time_label: '' }], true);
      return true;
    }
    function appendItems(items, replace) {
      if (!thread) return;
      var incoming = assignProductContexts(items || []);
      if (replace) {
        allItems = incoming.slice();
        lastId = 0;
      } else {
        incoming.forEach(function (item) {
          var id = parseInt(item.id || 0, 10);
          if (id > 0 && allItems.some(function (x) { return parseInt(x.id || 0, 10) === id; })) return;
          if (!(parseInt(item._product_id || 0, 10) > 0) && allItems.length) {
            item._product_id = parseInt(allItems[allItems.length - 1]._product_id || 0, 10) || 0;
          }
          allItems.push(item);
        });
      }
      allItems = assignProductContexts(allItems);
      if (!allItems.length && replace) {
        if (!seedFromPreview()) thread.innerHTML = emptyHtml();
        return;
      }
      if (!viewingHistory) {
        ensureProductFromMessages(allItems);
        paintThread(allItems);
      } else if (viewingPid > 0) {
        paintThread(allItems.filter(function (it) {
          return parseInt(it._product_id || 0, 10) === viewingPid;
        }));
      } else {
        paintThread(allItems);
      }
    }
    function buildHistoryRows() {
      var byPid = {};
      allItems.forEach(function (item) {
        var pid = parseInt(item._product_id || 0, 10) || 0;
        if (pid <= 0) return;
        if (!byPid[pid]) {
          var known = productCatalog[pid] || {};
          var fromText = isProductFocusLine(item.text) ? focusFromMarkerText(item.text) : null;
          byPid[pid] = Object.assign({
            id: pid, title: 'Product #' + pid, code: '', cover: '', price: '',
            seller_href: 'sales_management.php?inv_product=' + pid + '#inventory-detail',
            time_label: item.time_label || ''
          }, fromText || {}, known, { id: pid });
        } else if (item.time_label) {
          byPid[pid].time_label = item.time_label;
        }
      });
      if (productFocus && parseInt(productFocus.id || 0, 10) > 0) {
        var fid = parseInt(productFocus.id, 10);
        byPid[fid] = Object.assign({}, byPid[fid] || { id: fid }, productFocus, { id: fid });
      }
      return Object.keys(byPid).map(function (k) { return byPid[k]; }).sort(function (a, b) {
        return (parseInt(b.id || 0, 10) || 0) - (parseInt(a.id || 0, 10) || 0);
      });
    }
    function renderHistoryList() {
      if (!historyList) return;
      var rows = buildHistoryRows();
      if (!rows.length) {
        historyList.innerHTML = '<div class="sbm-history-empty">No product history yet.</div>';
        return;
      }
      historyList.innerHTML = '';
      rows.forEach(function (c) {
        var pid = parseInt(c.id || 0, 10) || 0;
        if (pid <= 0) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sbm-history-item' + (viewingPid === pid ? ' is-active' : '');
        btn.setAttribute('role', 'menuitem');
        var title = String(c.title || ('Product #' + pid));
        var cover = String(c.cover || '');
        var meta = title;
        btn.innerHTML =
          (cover ? '<img src="' + esc(cover) + '" alt="">' : '<img alt="" style="background:#e2e8f0">') +
          '<span>' +
            '<span class="sbm-history-item-label">' + esc('Product ID #' + pid + ' history') + '</span>' +
            (meta ? '<span class="sbm-history-item-meta">' + esc(meta) + '</span>' : '') +
          '</span>';
        btn.addEventListener('click', function () { openProductHistory(pid, c); });
        historyList.appendChild(btn);
      });
    }
    async function enrichProduct(pid) {
      pid = parseInt(pid || 0, 10) || 0;
      if (pid <= 0) return productCatalog[pid] || null;
      if (productCatalog[pid] && productCatalog[pid].cover) return productCatalog[pid];
      try {
        var res = await fetch(endpoint + '?mode=product&product_id=' + encodeURIComponent(String(pid)), { credentials: 'same-origin' });
        var data = await res.json();
        if (data && data.ok && data.product) {
          productCatalog[pid] = Object.assign({}, productCatalog[pid] || {}, data.product);
          return productCatalog[pid];
        }
      } catch (e) {}
      return productCatalog[pid] || null;
    }
    async function openProductHistory(pid, meta) {
      pid = parseInt(pid || 0, 10) || 0;
      if (pid <= 0) return;
      closeMoreMenu();
      viewingHistory = true;
      viewingPid = pid;
      if (chatEl) chatEl.classList.add('is-history-mode');
      if (historyBar) historyBar.classList.add('is-open');
      if (historyTitle) historyTitle.textContent = 'Product ID #' + pid + ' history';
      if (composeWrap) composeWrap.hidden = true;
      var focus = Object.assign({}, productCatalog[pid] || {}, meta || {}, { id: pid });
      renderProductCard(focus);
      paintThread(allItems.filter(function (it) {
        return parseInt(it._product_id || 0, 10) === pid;
      }));
      var enriched = await enrichProduct(pid);
      if (enriched && viewingPid === pid) {
        renderProductCard(Object.assign({}, focus, enriched, { id: pid }));
        renderHistoryList();
      }
    }
    function exitHistoryView() {
      viewingHistory = false;
      viewingPid = 0;
      if (chatEl) chatEl.classList.remove('is-history-mode');
      if (historyBar) historyBar.classList.remove('is-open');
      if (composeWrap) composeWrap.hidden = false;
      ensureProductFromMessages(allItems);
      paintThread(allItems);
    }
    if (Array.isArray(bootItems) && bootItems.length) {
      allItems = assignProductContexts(bootItems);
      bootItems.forEach(function (item) {
        var id = parseInt(item.id || 0, 10);
        if (id > lastId) lastId = id;
      });
    }
    if (productFocus && parseInt(productFocus.id || 0, 10) > 0) {
      renderProductCard(productFocus);
    } else if (allItems.length) {
      ensureProductFromMessages(allItems);
    }

    function setHead(name, ava) {
      if (headName) headName.textContent = name || 'Customer';
      if (ava) {
        peerAvatar = ava;
        root.setAttribute('data-peer-avatar', ava);
        if (headAva) headAva.src = ava;
      }
    }

    async function loadHistory(force) {
      if ((!peer && buyerId <= 0) || loading) return;
      loading = true;
      setErr('');
      var hadReal = !!(thread && thread.querySelector('.sbm-row[data-id]:not([data-id="0"])'));
      if (force && thread && !hadReal && !thread.querySelector('.sbm-row') && !viewingHistory) {
        thread.innerHTML = emptyHtml('Loading conversation\u2026');
      }
      try {
        var res = await fetch(endpoint + '?mode=history&' + peerQuery() + '&after=0&mark=1&_=' + Date.now(), {
          credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
        });
        var data = await res.json();
        if (data && data.ok) {
          var items = data.items || [];
          if (items.length) appendItems(items, true);
          else if (!thread.querySelector('.sbm-row')) {
            if (!seedFromPreview()) thread.innerHTML = emptyHtml();
          }
          if (data.peer_code) peer = String(data.peer_code).toUpperCase();
          if (data.buyer_id) buyerId = parseInt(data.buyer_id, 10) || buyerId;
          if (data.peer_name) setHead(data.peer_name, peerAvatar);
          root.setAttribute('data-peer', peer);
          root.setAttribute('data-buyer-id', String(buyerId));
          root.setAttribute('data-thread-count', String(items.length));
        } else if (data && data.error) {
          setErr(data.error);
          if (!thread.querySelector('.sbm-row')) {
            if (!seedFromPreview()) thread.innerHTML = emptyHtml(data.error);
          }
        }
      } catch (e) {
        setErr('Could not load customer messages.');
        if (!thread.querySelector('.sbm-row')) {
          if (!seedFromPreview()) thread.innerHTML = emptyHtml('Could not load customer messages.');
        }
      } finally {
        loading = false;
      }
    }

    async function pollNew() {
      if ((!peer && buyerId <= 0) || polling || loading || viewingHistory) return;
      polling = true;
      try {
        var res = await fetch(endpoint + '?mode=poll&' + peerQuery() + '&after=' + lastId + '&mark=1&_=' + Date.now(), {
          credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
        });
        var data = await res.json();
        if (data && data.ok && (data.items || []).length) {
          if (thread && thread.querySelector('.sbm-empty')) thread.innerHTML = '';
          appendItems(data.items, false);
        }
      } catch (e) {}
      polling = false;
    }

    async function sendMessage() {
      setErr('');
      if (viewingHistory) return;
      if (!peer && buyerId <= 0) { setErr('Select a customer first.'); return; }
      var text = input ? String(input.value || '').trim() : '';
      if (!text) { setErr('Type a message.'); return; }
      if (sendBtn) sendBtn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('mode', 'send');
        if (peer) body.set('peer', peer);
        if (buyerId > 0) body.set('buyer_id', String(buyerId));
        var aboutProd = parseInt(root.getAttribute('data-about-product') || '0', 10) || 0;
        if (aboutProd > 0) body.set('about_product', String(aboutProd));
        body.set('message', text);
        var res = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
          body: body.toString(), credentials: 'same-origin', cache: 'no-store'
        });
        var data = await res.json();
        if (!data || !data.ok) {
          setErr((data && data.error) || 'Could not send.');
          return;
        }
        if (input) input.value = '';
        if (data.item) {
          if (thread && thread.querySelector('.sbm-empty')) thread.innerHTML = '';
          appendItems([data.item], false);
        } else {
          await pollNew();
        }
        var active = list && list.querySelector('.sbm-item-row.is-active .seller-buyer-msg-item');
        if (active) {
          active.setAttribute('data-preview', text);
          var preview = active.querySelector('.sbm-item-preview');
          if (preview) preview.textContent = text.length > 80 ? (text.slice(0, 80) + '\u2026') : text;
        }
      } catch (e) {
        setErr('Could not send message.');
      } finally {
        if (sendBtn) sendBtn.disabled = false;
      }
    }

    function selectContact(el, pushUrl) {
      if (!el) return;
      var nextBuyer = parseInt(el.getAttribute('data-buyer-id') || '0', 10) || 0;
      var nextPeer = String(el.getAttribute('data-peer') || '').trim().toUpperCase();
      var nextName = String(el.getAttribute('data-peer-name') || 'Customer');
      var nextAva = String(el.getAttribute('data-peer-avatar') || '');
      if (nextBuyer <= 0 && !nextPeer) return;

      exitHistoryView();
      buyerId = nextBuyer;
      peer = nextPeer;
      lastId = 0;
      allItems = [];
      productCatalog = {};
      productFocus = null;
      root.setAttribute('data-buyer-id', String(buyerId));
      root.setAttribute('data-peer', peer);
      root.setAttribute('data-peer-name', nextName);
      root.setAttribute('data-about-product', '0');
      setHead(nextName, nextAva);
      renderProductCard(null);
      if (list) {
        list.querySelectorAll('.sbm-item-row').forEach(function (row) {
          row.classList.toggle('is-active', row.contains(el));
        });
        list.querySelectorAll('.seller-buyer-msg-item').forEach(function (a) {
          a.classList.toggle('is-active', a === el);
        });
      }
      if (thread) {
        var preview = String(el.getAttribute('data-preview') || '').trim();
        if (preview && preview !== 'Start a conversation' && !/^Order\s/i.test(preview)) {
          appendItems([{ id: 0, is_me: false, text: preview, time_label: '' }], true);
        } else {
          thread.innerHTML = emptyHtml('Loading conversation\u2026');
        }
      }
      if (pushUrl) {
        try {
          var url = new URL(el.getAttribute('href') || window.location.href, window.location.href);
          history.replaceState(history.state || {}, '', url.pathname + url.search + '#message');
        } catch (e2) {}
      }
      loadHistory(true);
    }

    if (moreBtn && moreWrap) {
      moreBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = !moreWrap.classList.contains('is-open');
        moreWrap.classList.toggle('is-open', open);
        moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) renderHistoryList();
      });
    }
    if (historyBack) historyBack.addEventListener('click', exitHistoryView);
    document.addEventListener('click', function (e) {
      if (!moreWrap || !moreWrap.classList.contains('is-open')) return;
      if (moreWrap.contains(e.target)) return;
      closeMoreMenu();
    });

    if (list) {
      list.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('.seller-buyer-msg-item') : null;
        if (!a || !list.contains(a)) return;
        e.preventDefault();
        selectContact(a, true);
      });
    }

    if (searchEl && list) {
      searchEl.addEventListener('input', function () {
        var q = String(searchEl.value || '').trim().toLowerCase();
        list.querySelectorAll('.sbm-item-row').forEach(function (row) {
          var blob = String(row.getAttribute('data-search') || '');
          row.style.display = (!q || blob.indexOf(q) !== -1) ? '' : 'none';
        });
      });
    }

    if (input && draft && !String(input.value || '').trim()) {
      if (!/^Product\s*ID\s*#/i.test(String(draft).trim())) input.value = draft;
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

    seedFromPreview();
    loadHistory(true);
    setInterval(pollNew, 4000);

    document.addEventListener('org-nav-complete', function () {
      loadHistory(true);
    });
    window.addEventListener('hashchange', function () {
      if (String(window.location.hash || '').replace(/^#/, '') === 'message') {
        loadHistory(true);
      }
    });
  })();
  </script>
<?php endif; ?>
