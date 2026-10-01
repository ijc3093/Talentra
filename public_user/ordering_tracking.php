<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
org_shop_ensure_schema($dbh);
$GLOBALS['feedTopDbh'] = $dbh;
$GLOBALS['feedTopMeId'] = $meId;
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

require_once __DIR__ . '/includes/shop_filter_context.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$topics = [
    'how-to-track' => [
        'label' => 'How to track my order',
        'icon' => 'clock',
        'title' => 'How to track my order',
        'lead' => 'Follow these steps to track your order.',
        'layout' => 'steps',
        'steps' => [
            ['title' => 'Go to Orders', 'text' => "Tap the profile icon and select 'My Orders'."],
            ['title' => 'Find your order', 'text' => 'Select the order you want to track.'],
            ['title' => 'View tracking details', 'text' => "You'll see the current status, tracking number, and estimated delivery date."],
        ],
        'note' => ['tone' => 'help', 'icon' => 'truck', 'title' => 'Need more help?', 'text' => 'Contact our support team.'],
    ],
    'status-meanings' => [
        'label' => 'Order status meanings',
        'icon' => 'clock',
        'title' => 'Order status meanings',
        'lead' => 'Here are the common order statuses and what they mean.',
        'layout' => 'status',
        'statuses' => [
            ['tone' => 'pending', 'label' => 'Pending', 'text' => 'Your order has been received and is being processed.'],
            ['tone' => 'processing', 'label' => 'Processing', 'text' => "We're preparing your items for shipment."],
            ['tone' => 'shipped', 'label' => 'Shipped', 'text' => 'Your order is on the way.'],
            ['tone' => 'out', 'label' => 'Out for delivery', 'text' => 'Your order is out for delivery today.'],
            ['tone' => 'delivered', 'label' => 'Delivered', 'text' => 'Your order has been delivered.'],
            ['tone' => 'canceled', 'label' => 'Canceled', 'text' => 'Your order has been canceled.'],
            ['tone' => 'returned', 'label' => 'Returned', 'text' => 'Your order has been returned to us.'],
        ],
    ],
    'delivery-times' => [
        'label' => 'Estimated delivery times',
        'icon' => 'clock',
        'title' => 'Estimated delivery times',
        'lead' => 'Delivery times depend on the item, shipping method, and your location.',
        'layout' => 'kv',
        'rows' => [
            ['label' => 'Standard Shipping', 'value' => '3–5 business days'],
            ['label' => 'Expedited Shipping', 'value' => '1–3 business days'],
            ['label' => 'Same-Day Delivery', 'value' => 'Same day (select areas)'],
            ['label' => 'International Shipping', 'value' => '7–21 business days'],
        ],
        'note' => ['tone' => 'info', 'icon' => 'info', 'title' => 'Please note', 'text' => 'These are estimates and can vary due to weather, carrier delays, and customs.'],
    ],
    'international' => [
        'label' => 'International shipping',
        'icon' => 'globe',
        'title' => 'International shipping',
        'lead' => 'We ship to many countries around the world.',
        'layout' => 'kv',
        'rows' => [
            ['label' => 'Available countries', 'value' => 'Over 100 countries'],
            ['label' => 'Delivery time', 'value' => '7–21 business days'],
            ['label' => 'Shipping cost', 'value' => 'Calculated at checkout'],
            ['label' => 'Customs & duties', 'value' => 'May apply (determined by your country)'],
            ['label' => 'Tracking', 'value' => 'Available for most orders'],
        ],
        'note' => ['tone' => 'help', 'icon' => 'globe', 'title' => 'Have questions?', 'text' => 'Contact our support team.'],
    ],
    'cancel-change' => [
        'label' => 'Changing or canceling an order',
        'icon' => 'refresh',
        'title' => 'Changing or canceling an order',
        'lead' => "You can change or cancel your order if it hasn't shipped yet.",
        'layout' => 'steps',
        'steps' => [
            ['title' => 'Go to My Orders', 'text' => 'Find the order you want to change or cancel.'],
            ['title' => 'Select the option', 'text' => "Tap 'Cancel Order' or 'Edit Order' (if available)."],
            ['title' => 'Confirmation', 'text' => "You'll receive a confirmation email once the change is made."],
        ],
        'note' => ['tone' => 'warn', 'icon' => 'warn', 'title' => 'Please note', 'text' => 'Once an order has shipped, it can no longer be changed or canceled.'],
    ],
    'missing-items' => [
        'label' => 'Items missing from my order',
        'icon' => 'box',
        'title' => 'Items missing from my order',
        'lead' => "If an item is missing, we'll help you resolve it.",
        'layout' => 'steps',
        'steps' => [
            ['title' => 'Check your order', 'text' => 'Review the items in your order confirmation email.'],
            ['title' => 'Check the package', 'text' => 'Sometimes items ship separately and may arrive in a different package.'],
            ['title' => 'Contact us', 'text' => 'If the item is still missing, reach out to our support team with your order number and photos of the package.'],
        ],
        'note' => ['tone' => 'help', 'icon' => 'headset', 'title' => 'Contact support', 'text' => "We're here to help."],
    ],
];

$topicKey = strtolower(trim((string)($_GET['topic'] ?? '')));
if ($topicKey !== '' && !isset($topics[$topicKey])) {
    $topicKey = '';
}

$trackError = '';
$trackCode = trim((string)($_GET['code'] ?? $_POST['code'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['track']) && $trackCode !== '')) {
    $found = org_shop_find_buyer_order_by_code($dbh, $meId, $trackCode);
    if ($found) {
        header('Location: order_detail.php?order_id=' . (int)($found['id'] ?? 0));
        exit;
    }
    $trackError = $trackCode === ''
        ? 'Enter your order number to continue.'
        : 'No order found for that number. Check the code on your confirmation email or My Orders.';
}

$supportHref = 'Your_Shopping_preferences.php#support-tickets';
$ordersHref = 'Your_Shopping_preferences.php#order-history';
$pageTitle = 'Ordering & Tracking';

function ot_icon_svg(string $name): string
{
    $icons = [
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'list' => '<path d="M8 7h11M8 12h11M8 17h11"/><circle cx="4.5" cy="7" r="1.2"/><circle cx="4.5" cy="12" r="1.2"/><circle cx="4.5" cy="17" r="1.2"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M8 3.5v3M16 3.5v3M3.5 9.5h17"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.8 3 4.2 6 4.2 9S14.8 18 12 21c-2.8-3-4.2-6-4.2-9S9.2 6 12 3z"/>',
        'refresh' => '<path d="M4.5 12a7.5 7.5 0 0 1 12.8-5.3L19 4.5V9h-4.5"/><path d="M19.5 12a7.5 7.5 0 0 1-12.8 5.3L5 19.5V15h4.5"/>',
        'box' => '<path d="M4 8.5L12 4l8 4.5v7L12 20l-8-4.5v-7z"/><path d="M12 12v8M4 8.5l8 3.5 8-3.5"/>',
        'doc' => '<path d="M7 3.5h7l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-9.5A1.5 1.5 0 0 1 5.5 20V5A1.5 1.5 0 0 1 7 3.5z"/><path d="M14 3.5V8h4.5M9 12h6M9 16h6"/>',
        'truck' => '<path d="M3 7.5h11v9H3zM14 10.5h4.2L21 14v2.5h-2"/><circle cx="7" cy="17.5" r="1.6"/><circle cx="17" cy="17.5" r="1.6"/>',
        'headset' => '<path d="M4.5 12a7.5 7.5 0 0 1 15 0"/><path d="M4.5 12v4.5a2 2 0 0 0 2 2H8v-7H6.5A2 2 0 0 0 4.5 12zm15 0v4.5a2 2 0 0 1-2 2H16v-7h1.5a2 2 0 0 1 2 1.5z"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 10.5V17M12 7.2h.01"/>',
        'warn' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v6M12 16.5h.01"/>',
        'chevron' => '<path d="M9 6l6 6-6 6"/>',
        'back' => '<path d="M15 6l-6 6 6 6"/>',
    ];
    $path = $icons[$name] ?? $icons['doc'];
    return '<svg class="ot-ico" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

function ot_render_topic_body(array $topic, string $supportHref): void
{
    $note = is_array($topic['note'] ?? null) ? $topic['note'] : null;
    echo '<div class="ot-hero">';
    echo '<div class="ot-hero-ico">' . ot_icon_svg((string)$topic['icon']) . '</div>';
    echo '<div>';
    echo '<h2>' . h((string)$topic['title']) . '</h2>';
    echo '<p>' . h((string)$topic['lead']) . '</p>';
    echo '</div></div>';

    if (($topic['layout'] ?? '') === 'steps') {
        echo '<ol class="ot-steps">';
        foreach ((array)($topic['steps'] ?? []) as $i => $step) {
            echo '<li class="ot-step"><span class="ot-step-num">' . ((int)$i + 1) . '</span><div>';
            echo '<strong>' . h((string)($step['title'] ?? '')) . '</strong>';
            echo '<p>' . h((string)($step['text'] ?? '')) . '</p>';
            echo '</div></li>';
        }
        echo '</ol>';
    } elseif (($topic['layout'] ?? '') === 'status') {
        echo '<ul class="ot-status">';
        foreach ((array)($topic['statuses'] ?? []) as $row) {
            $tone = (string)($row['tone'] ?? 'pending');
            echo '<li>';
            echo '<span class="ot-pill is-' . h($tone) . '">' . h((string)($row['label'] ?? '')) . '</span>';
            echo '<p>' . h((string)($row['text'] ?? '')) . '</p>';
            echo '</li>';
        }
        echo '</ul>';
    } else {
        echo '<ul class="ot-kv">';
        foreach ((array)($topic['rows'] ?? []) as $row) {
            echo '<li><strong>' . h((string)($row['label'] ?? '')) . '</strong>';
            echo '<span>' . h((string)($row['value'] ?? '')) . '</span></li>';
        }
        echo '</ul>';
    }

    if ($note) {
        $tone = (string)($note['tone'] ?? 'help');
        $noteClass = 'ot-note' . ($tone === 'warn' ? ' is-warn' : '');
        $noteHref = ($tone === 'help') ? $supportHref : '';
        if ($noteHref !== '') {
            echo '<a class="' . h($noteClass) . '" href="' . h($noteHref) . '">';
        } else {
            echo '<div class="' . h($noteClass) . '">';
        }
        echo ot_icon_svg((string)($note['icon'] ?? 'info'));
        echo '<span><strong>' . h((string)($note['title'] ?? '')) . '</strong>';
        echo '<span>' . h((string)($note['text'] ?? '')) . '</span></span>';
        echo $noteHref !== '' ? '</a>' : '</div>';
    }
}
?>
<!doctype html>
<html <?= app_html_lang_attrs() ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <link rel="stylesheet" href="assets/ui_best.css">
  <link rel="stylesheet" href="assets/layout-fixed.css">
  <link rel="stylesheet" href="./css/shop-page.css?v=10">
  <style><?php include __DIR__ . '/includes/feed_rails.css.php'; ?></style>
  <style><?php include __DIR__ . '/includes/feed_header_chrome.css.php'; ?></style>
  <script defer src="assets/layout-fixed.js"></script>
  <style>
    .ot-shell{
      max-width:560px;margin:0 auto;padding:12px 0 88px;
      color:var(--shop-text,var(--msb-palette-text,#0f172a));
    }
    .ot-page-title{
      margin:0 0 16px;font-size:28px;font-weight:800;letter-spacing:-.03em;line-height:1.15;
      color:var(--shop-text,var(--msb-palette-text,#0f172a));
    }
    .ot-top{
      display:none;align-items:center;justify-content:space-between;gap:12px;
      padding:0 0 14px;position:sticky;top:0;z-index:5;
      background:var(--shop-page-bg,var(--msb-palette-bg,#f3f4f6));
    }
    .ot-shell.is-topic .ot-top{display:flex;}
    .ot-shell.is-topic .ot-page-title{display:none;}
    .ot-top-btn{
      width:40px;height:40px;border:0;border-radius:12px;background:transparent;color:inherit;
      display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer;
    }
    .ot-top-btn:hover{background:rgba(37,99,235,.08);}
    .ot-top-title{margin:0;font-size:18px;font-weight:800;letter-spacing:-.02em;text-align:center;flex:1;min-width:0;}
    .ot-panel[hidden]{display:none !important;}
    .ot-section{margin:0 0 18px;}
    .ot-box{
      background:var(--shop-card-bg,#fff);
      border:1px solid var(--shop-border,rgba(15,23,42,.1));
      border-radius:14px;
      padding:16px;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
    }
    .ot-track-head{
      display:flex;align-items:center;gap:10px;margin:0 0 14px;
      font-size:16px;font-weight:800;color:var(--shop-text,#0f172a);
    }
    .ot-track-head .ot-ico{width:22px;height:22px;color:var(--shop-link,#2563eb);flex-shrink:0;}
    .ot-track-form{display:grid;gap:12px;}
    .ot-input{
      width:100%;box-sizing:border-box;min-height:46px;padding:12px 14px;border-radius:10px;
      border:1px solid var(--shop-border,rgba(15,23,42,.1));
      background:var(--shop-card-raised,#f3f4f6);
      color:inherit;font:inherit;font-size:14px;
    }
    .ot-input::placeholder{color:#9ca3af;}
    .ot-input:focus{
      outline:0;border-color:var(--shop-link,#2563eb);
      background:var(--shop-input-bg,#fff);
      box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }
    .ot-btn{
      display:inline-flex;align-items:center;justify-content:center;min-height:46px;border:0;border-radius:10px;
      padding:12px 18px;font:inherit;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;
      background:var(--shop-link,#2563eb);color:#fff;
    }
    .ot-btn:hover{filter:brightness(1.05);}
    .ot-error{margin:0;font-size:13px;color:#b91c1c;}
    .ot-h3{margin:0 0 10px;font-size:18px;font-weight:800;color:var(--shop-text,#0f172a);}
    .ot-topics{list-style:none;margin:0;padding:0;}
    .ot-topics li + li{border-top:1px solid var(--shop-border,rgba(15,23,42,.08));}
    .ot-topic{
      display:flex;align-items:center;gap:12px;width:100%;padding:14px 2px;text-decoration:none;
      color:var(--shop-text,#0f172a);font-size:15px;font-weight:500;
      background:transparent;border:0;cursor:pointer;text-align:left;font:inherit;
    }
    .ot-topic:hover{color:var(--shop-link,#2563eb);}
    .ot-topic > span:not(.ot-chev){flex:1;min-width:0;}
    .ot-topic .ot-chev{width:18px;height:18px;display:inline-flex;opacity:.45;color:#94a3b8;flex-shrink:0;}
    .ot-topic .ot-chev .ot-ico{width:18px;height:18px;}
    .ot-help-card{
      display:flex;align-items:flex-start;gap:12px;
      background:var(--shop-card-bg,#fff);
      border:1px solid var(--shop-border,rgba(15,23,42,.1));
      border-radius:14px;padding:16px;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
    }
    .ot-help-card > .ot-ico{width:24px;height:24px;color:var(--shop-link,#2563eb);flex-shrink:0;margin-top:1px;}
    .ot-help-copy{min-width:0;}
    .ot-help-copy strong{display:block;font-size:15px;font-weight:800;margin-bottom:2px;}
    .ot-help-copy p{margin:0 0 10px;font-size:13px;color:var(--shop-text-muted,#64748b);}
    .ot-help-copy a{font-size:14px;font-weight:700;color:var(--shop-link,#2563eb);text-decoration:none;}
    .ot-help-copy a:hover{text-decoration:underline;}
    .ot-hero{display:flex;align-items:flex-start;gap:12px;margin:0 0 18px;}
    .ot-hero-ico{
      width:42px;height:42px;border-radius:12px;flex-shrink:0;
      display:inline-flex;align-items:center;justify-content:center;
      color:var(--shop-link,var(--msb-palette-link,#2563eb));
      background:rgba(37,99,235,.08);
    }
    .ot-hero h2{margin:0 0 4px;font-size:20px;font-weight:800;letter-spacing:-.02em;line-height:1.25;}
    .ot-hero p{margin:0;font-size:14px;line-height:1.45;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .ot-steps{list-style:none;margin:0;padding:0;display:grid;gap:16px;}
    .ot-step{display:grid;grid-template-columns:36px 1fr;gap:12px;align-items:start;}
    .ot-step-num{
      width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
      background:var(--shop-link,var(--msb-palette-link,#2563eb));color:#fff;font-weight:800;font-size:15px;
    }
    .ot-step strong{display:block;font-size:15px;font-weight:800;margin:2px 0 4px;}
    .ot-step p{margin:0;font-size:14px;line-height:1.45;color:var(--shop-text-muted,#64748b);}
    .ot-status{list-style:none;margin:0;padding:0;display:grid;gap:14px;}
    .ot-status li{display:grid;grid-template-columns:minmax(118px,auto) 1fr;gap:12px;align-items:start;}
    .ot-pill{
      display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:4px 12px;
      border-radius:999px;font-size:12px;font-weight:800;white-space:nowrap;color:#fff;
    }
    .ot-pill.is-pending{background:#94a3b8;}
    .ot-pill.is-processing{background:#2563eb;}
    .ot-pill.is-shipped{background:#7c3aed;}
    .ot-pill.is-out{background:#34d399;color:#064e3b;}
    .ot-pill.is-delivered{background:#16a34a;}
    .ot-pill.is-canceled{background:#ef4444;}
    .ot-pill.is-returned{background:#eab308;color:#713f12;}
    .ot-status p{margin:4px 0 0;font-size:14px;line-height:1.4;color:var(--shop-text-muted,#64748b);}
    .ot-kv{list-style:none;margin:0;padding:0;border-top:1px solid var(--shop-border,rgba(15,23,42,.08));}
    .ot-kv li{
      display:flex;justify-content:space-between;gap:16px;padding:14px 0;
      border-bottom:1px solid var(--shop-border,rgba(15,23,42,.08));font-size:14px;
    }
    .ot-kv strong{font-weight:800;}
    .ot-kv span{color:var(--shop-text-muted,#64748b);text-align:right;}
    .ot-note{
      display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:16px;margin-top:22px;
      background:rgba(37,99,235,.08);color:inherit;text-decoration:none;
    }
    .ot-note.is-warn{background:#fee2e2;color:#991b1b;}
    .ot-note.is-warn .ot-ico{color:#dc2626;}
    .ot-note .ot-ico{width:22px;height:22px;flex-shrink:0;margin-top:1px;color:var(--shop-link,#2563eb);}
    .ot-note strong{display:block;font-size:14px;font-weight:800;margin-bottom:2px;}
    .ot-note > span{font-size:13px;line-height:1.4;}
    .ot-note > span > span{display:block;color:var(--shop-text-muted,#64748b);}
    .ot-note.is-warn > span > span{color:#991b1b;}
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
    @media (max-width:640px){
      .ot-shell{padding-left:2px;padding-right:2px;}
      .ot-page-title{font-size:24px;}
      .ot-status li{grid-template-columns:1fr;}
    }
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui ordering-tracking-page">

<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>
<?php
  $feedLeftRailActive = 'shop.php';
  $feedLeftRailCanFollow = $canFollowPublishers;
  $feedLeftRailShopOnly = true;
  $feedLeftRailShopFilters = true;
  $feedLeftRailPageHeadTitle = 'Shop';
  $feedLeftRailPageHeadSub = 'Ordering, tracking, and delivery help.';
  include __DIR__ . '/includes/feed_left_rail.php';
?>

<div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
      <?php include __DIR__ . '/includes/shop_header_search.php'; ?>
      <?php $feedTopShopActive = true; $feedTopShopOnly = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>

    <div class="shop-page-shell">
      <div class="ot-shell" id="otApp" data-initial-topic="<?= h($topicKey) ?>">
        <h1 class="ot-page-title"><?= h($pageTitle) ?></h1>
        <header class="ot-top">
          <button type="button" class="ot-top-btn" id="otBackBtn" aria-label="Back"><?= ot_icon_svg('back') ?></button>
          <h2 class="ot-top-title"><?= h($pageTitle) ?></h2>
          <span class="ot-top-btn" aria-hidden="true"></span>
        </header>

        <div class="ot-panel" data-ot-panel="hub" id="otPanelHub">
          <section class="ot-section">
            <div class="ot-box">
              <div class="ot-track-head">
                <?= ot_icon_svg('box') ?>
                <span>Track Your Order</span>
              </div>
              <form class="ot-track-form" method="get" action="ordering_tracking.php" autocomplete="off">
                <input type="hidden" name="track" value="1">
                <label class="sr-only" for="otOrderCode">Order number</label>
                <input class="ot-input" id="otOrderCode" name="code" type="text" value="<?= h($trackCode) ?>" placeholder="Order number (e.g. ORD-0001234)" autocapitalize="characters" spellcheck="false">
                <?php if ($trackError !== ''): ?><p class="ot-error"><?= h($trackError) ?></p><?php endif; ?>
                <button type="submit" class="ot-btn">Track Order</button>
              </form>
            </div>
          </section>

          <section class="ot-section">
            <h3 class="ot-h3">Popular Topics</h3>
            <div class="ot-box" style="padding:4px 14px;">
              <ul class="ot-topics">
                <?php foreach ($topics as $key => $topic): ?>
                  <li>
                    <button type="button" class="ot-topic" data-ot-open="<?= h($key) ?>">
                      <span><?= h((string)$topic['label']) ?></span>
                      <span class="ot-chev" aria-hidden="true"><?= ot_icon_svg('chevron') ?></span>
                    </button>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </section>

          <section class="ot-section">
            <div class="ot-help-card">
              <?= ot_icon_svg('headset') ?>
              <div class="ot-help-copy">
                <strong>Still need help?</strong>
                <p>Contact our support team.</p>
                <a href="<?= h($supportHref) ?>">Contact Support</a>
              </div>
            </div>
          </section>
        </div>

        <?php foreach ($topics as $key => $topic): ?>
          <div class="ot-panel" data-ot-panel="<?= h($key) ?>" id="otPanel-<?= h($key) ?>" hidden>
            <section class="ot-section">
              <?php ot_render_topic_body($topic, $supportHref); ?>
            </section>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var app = document.getElementById('otApp');
  if (!app) return;
  var hub = document.getElementById('otPanelHub');
  var backBtn = document.getElementById('otBackBtn');
  var panels = Array.prototype.slice.call(app.querySelectorAll('[data-ot-panel]'));
  var topicKeys = {};
  panels.forEach(function(p){
    var key = p.getAttribute('data-ot-panel') || '';
    if (key && key !== 'hub') topicKeys[key] = true;
  });

  function showPanel(key, opts){
    opts = opts || {};
    var next = (key && topicKeys[key]) ? key : 'hub';
    panels.forEach(function(p){
      var id = p.getAttribute('data-ot-panel') || '';
      p.hidden = id !== next;
    });
    app.classList.toggle('is-topic', next !== 'hub');
    if (!opts.skipHistory && window.history) {
      if (next === 'hub') {
        if (window.history.replaceState) {
          window.history.replaceState({ otTopic: 'hub' }, '', 'ordering_tracking.php');
        }
      } else if (opts.replace && window.history.replaceState) {
        window.history.replaceState({ otTopic: next }, '', 'ordering_tracking.php?topic=' + encodeURIComponent(next));
      } else if (window.history.pushState) {
        window.history.pushState({ otTopic: next }, '', 'ordering_tracking.php?topic=' + encodeURIComponent(next));
      }
    }
    try { window.scrollTo(0, 0); } catch (e) {}
  }

  app.addEventListener('click', function(e){
    var openBtn = e.target && e.target.closest ? e.target.closest('[data-ot-open]') : null;
    if (openBtn) {
      e.preventDefault();
      showPanel(openBtn.getAttribute('data-ot-open') || '');
      return;
    }
  });

  if (backBtn) {
    backBtn.addEventListener('click', function(){
      var visible = panels.find(function(p){ return !p.hidden && p.getAttribute('data-ot-panel') !== 'hub'; });
      if (visible) {
        showPanel('hub');
        return;
      }
      window.location.href = 'shop.php';
    });
  }

  window.addEventListener('popstate', function(){
    var params = new URLSearchParams(window.location.search || '');
    showPanel(params.get('topic') || '', { skipHistory: true });
  });

  showPanel(app.getAttribute('data-initial-topic') || '', { replace: true });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
