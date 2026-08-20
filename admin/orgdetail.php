<?php
declare(strict_types=1);

/**
 * admin/orgdetail.php
 * Organization detail — profile-style UI matching user_form / publisher_request_detail.
 * Preserves status, publisher link, commerce brand, Connect, rent, and member actions.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';

$listFilter = strtolower(trim((string)($_GET['filter'] ?? $_POST['list_filter'] ?? 'all')));
if (!in_array($listFilter, ['all', 'active', 'disabled', 'publisher', 'regular'], true)) {
    $listFilter = 'all';
}
$listQ = trim((string)($_GET['q'] ?? $_POST['list_q'] ?? ''));

$orgId = (int)($_GET['id'] ?? $_POST['org_id'] ?? 0);
if ($orgId <= 0) {
    header('Location: orglist.php');
    exit;
}

if (isset($_POST['set_org_status'])) {
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if (org_admin_set_status($dbh, 'organizations', $orgId, $status)) {
        $msg = $status === 1 ? 'Organization activated.' : 'Organization disabled.';
    } else {
        $error = 'Could not update organization status.';
    }
}

if (isset($_POST['set_manager_status'])) {
    $managerId = (int)($_POST['manager_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($managerId <= 0) {
        $error = 'Invalid manager id.';
    } elseif (org_admin_set_status($dbh, 'managers', $managerId, $status)) {
        $msg = $status === 1 ? 'Manager activated.' : 'Manager disabled.';
    } else {
        $error = 'Could not update manager status.';
    }
}

if (isset($_POST['set_staff_status'])) {
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($staffId <= 0) {
        $error = 'Invalid staff id.';
    } elseif (org_admin_set_status($dbh, 'staff_accounts', $staffId, $status)) {
        $msg = $status === 1 ? 'Staff account activated.' : 'Staff account disabled.';
    } else {
        $error = 'Could not update staff status.';
    }
}

$org = org_admin_get_organization($dbh, $orgId);
if (!$org) {
    header('Location: orglist.php');
    exit;
}

require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';
require_once __DIR__ . '/../public_user/includes/org_shop_connect.php';
require_once __DIR__ . '/../public_user/includes/stripe_shop.php';
platform_rent_ensure_schema($dbh);
org_commerce_brands_ensure_schema($dbh);
org_shop_connect_ensure_schema($dbh);
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['idadmin'] ?? 0);
$rentPlans = platform_rent_list_plans($dbh, false);
$paidRentPlans = array_values(array_filter(
    $rentPlans,
    static fn(array $p): bool => strtolower(trim((string)($p['code'] ?? ''))) !== 'shop_trial'
));
$rentSnapshot = platform_rent_org_snapshot($dbh, $orgId);
$rentPayments = platform_rent_list_payments($dbh, $orgId, 10);
$isShopOrg = $rentSnapshot ? platform_rent_org_is_shop($rentSnapshot) : false;

if ($isShopOrg && isset($_POST['mark_rent_paid'])) {
    $planId = (int)($_POST['plan_id'] ?? 0);
    $months = (int)($_POST['months_paid'] ?? 1);
    $method = trim((string)($_POST['payment_method'] ?? 'manual'));
    $reference = trim((string)($_POST['payment_reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($planId <= 0) {
        $error = 'Choose a rent plan.';
    } elseif (platform_rent_mark_paid($dbh, $orgId, $planId, $months, $adminId, $method, $reference, $notes)) {
        $msg = 'Rent payment recorded.';
        $rentSnapshot = platform_rent_org_snapshot($dbh, $orgId);
        $rentPayments = platform_rent_list_payments($dbh, $orgId, 10);
    } else {
        $error = 'Could not record rent payment.';
    }
}

if ($isShopOrg && isset($_POST['suspend_rent'])) {
    if (platform_rent_suspend($dbh, $orgId)) {
        $msg = 'Shop rent suspended.';
        $rentSnapshot = platform_rent_org_snapshot($dbh, $orgId);
    } else {
        $error = 'Could not suspend rent.';
    }
}

if (isset($_POST['migrate_commerce_brand'])) {
    $brandId = (int)($_POST['commerce_brand_id'] ?? 0);
    if ($brandId <= 0) {
        $error = 'Choose a commerce brand.';
    } elseif (org_commerce_brands_migrate_org($dbh, $orgId, $brandId, true)) {
        $brand = org_commerce_brands_get($dbh, $brandId);
        $msg = 'Commerce brand set to ' . (string)($brand['name'] ?? 'brand') . '.';
        $org = org_admin_get_organization($dbh, $orgId);
        $orgCommerceBrand = org_commerce_brands_get_for_org($dbh, $orgId);
        $suggestedCommerceBrand = org_commerce_brands_suggest_for_org($dbh, $org ?: []);
    } else {
        $error = 'Could not assign commerce brand.';
    }
}

if (isset($_POST['link_publisher_user'])) {
    $lookup = trim((string)($_POST['publisher_lookup'] ?? ''));
    $found = org_admin_find_publisher_user($dbh, $lookup);
    if (!$found) {
        $error = 'No publisher user matched that id, username, email, or friend code.';
    } else {
        $res = org_admin_set_org_publisher_link($dbh, $orgId, (int)$found['id']);
        if (!empty($res['ok'])) {
            $msg = 'Linked publisher user @' . (string)($found['username'] ?? $found['id']) . '.';
            $org = org_admin_get_organization($dbh, $orgId);
        } else {
            $error = (string)($res['error'] ?? 'Could not link publisher.');
        }
    }
}

if (isset($_POST['unlink_publisher_user'])) {
    $res = org_admin_clear_org_publisher_link($dbh, $orgId);
    if (!empty($res['ok'])) {
        $msg = 'Publisher user unlinked from this organization.';
        $org = org_admin_get_organization($dbh, $orgId);
    } else {
        $error = (string)($res['error'] ?? 'Could not unlink publisher.');
    }
}

if (isset($_POST['sync_connect'])) {
    if (!stripe_shop_is_configured()) {
        $error = 'Stripe is not configured on this server.';
    } else {
        $connectStatus = org_shop_connect_sync_account($dbh, $orgId);
        $msg = $connectStatus['account_id'] !== ''
            ? 'Stripe Connect status refreshed from Stripe.'
            : 'No Connect account linked yet for this org.';
    }
}

if (isset($_POST['clear_connect'])) {
    $res = org_admin_clear_org_connect($dbh, $orgId);
    if (!empty($res['ok'])) {
        $msg = 'Local Connect link cleared (Stripe Express account still exists in Stripe Dashboard).';
    } else {
        $error = (string)($res['error'] ?? 'Could not clear Connect link.');
    }
}

$commerceBrands = org_commerce_brands_list_active($dbh);
$orgCommerceBrand = org_commerce_brands_get_for_org($dbh, $orgId);
$suggestedCommerceBrand = org_commerce_brands_suggest_for_org($dbh, $org ?: []);
$connectStatus = org_shop_connect_status($dbh, $orgId);
$stripeReady = stripe_shop_is_configured();

$members = org_admin_list_org_members($dbh, $orgId);
$orgStatus = (int)($org['status'] ?? 0);
$managerStatus = (int)($org['manager_status'] ?? 0);
$isPubOrg = (int)($org['is_publisher_org'] ?? 0) === 1;

$navRows = org_admin_list_organizations($dbh, $listFilter, $listQ);
$navIds = [];
foreach ($navRows as $nr) {
    $nid = (int)($nr['id'] ?? 0);
    if ($nid > 0) {
        $navIds[] = $nid;
    }
}
$navPos = array_search($orgId, $navIds, true);
$navTotal = count($navIds);
$prevId = 0;
$nextId = 0;
if ($navPos !== false) {
    $navPos = (int)$navPos + 1;
    $idx = $navPos - 1;
    if ($idx > 0) {
        $prevId = (int)$navIds[$idx - 1];
    }
    if ($idx < $navTotal - 1) {
        $nextId = (int)$navIds[$idx + 1];
    }
} else {
    $navPos = 0;
}

function od_initials(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') {
        return '??';
    }
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), static fn($p) => trim($p) !== ''));
    if (!$parts) {
        return '??';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}

function od_avatar_color(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
    return $palette[$hash % count($palette)];
}

$orgName = (string)($org['name'] ?? 'Organization');
$orgCode = (string)($org['org_code'] ?? '');
$ini = od_initials($orgName !== '' ? $orgName : $orgCode);
$avBg = od_avatar_color($orgCode !== '' ? $orgCode : $orgName);
$memberCount = count($members);
$mgrCount = 0;
$staffCount = 0;
foreach ($members as $m) {
    if (($m['member_type'] ?? '') === 'staff') {
        $staffCount++;
    } else {
        $mgrCount++;
    }
}

$listHref = 'orglist.php?filter=' . rawurlencode($listFilter) . ($listQ !== '' ? '&q=' . rawurlencode($listQ) : '');
$detailQs = 'filter=' . rawurlencode($listFilter) . ($listQ !== '' ? '&q=' . rawurlencode($listQ) : '');
$rentLive = $isShopOrg && $rentSnapshot
    ? (string)($rentSnapshot['rent_status_live'] ?? $rentSnapshot['rent_status'] ?? 'trial')
    : '';
$shopVisible = $isShopOrg && $rentSnapshot && !empty($rentSnapshot['shop_visible']);
$connectLinked = ($connectStatus['account_id'] ?? '') !== '';

org_admin_render_head('Organization · ' . $orgName);
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open('Organization');
?>

<style>
  /* Viewport lock — match user_form.php screen: no page scroll; only card bodies scroll */
  html,body{height:100% !important;overflow:hidden !important;max-height:100dvh !important;}
  body.azia-admin{overflow:hidden !important;}
  .sh-mainpanel{
    height:100vh !important;max-height:100dvh !important;
    display:flex !important;flex-direction:column !important;overflow:hidden !important;
  }
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:4px !important;padding-bottom:4px !important;padding-left:8px !important;padding-right:8px !important;
    margin-left:0 !important;margin-right:0 !important;flex:1 1 auto !important;background:#f4f6fb !important;
  }
  .uf-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:5px;overflow:hidden !important;padding:0 2px;box-sizing:border-box;
  }
  .uf-btn{
    height:24px;padding:0 8px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;
    font-size:10px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:4px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .uf-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .uf-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .uf-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .uf-btn.warn{border-color:#fed7aa;color:#c2410c;}
  .uf-btn.warn:hover{background:#fff7ed;}
  .uf-btn.sm{height:20px;padding:0 6px;font-size:9px;}
  .uf-btn.is-disabled{opacity:.45;pointer-events:none;}
  .uf-btn:disabled{opacity:.45;pointer-events:none;}

  .uf-hero{
    flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:6px 10px;
    display:flex;align-items:flex-start;justify-content:space-between;gap:8px;min-width:0;
  }
  .uf-hero-left{display:flex;gap:8px;min-width:0;align-items:flex-start;flex:1 1 auto;}
  .uf-av{width:40px;height:40px;border-radius:999px;color:#fff;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;flex:0 0 40px;}
  .uf-hero h1{margin:0;font-size:14px;font-weight:800;color:#0f172a;line-height:1.15;display:inline-flex;align-items:center;gap:5px;}
  .uf-hero .name{font-size:11px;color:#64748b;font-weight:600;margin-top:1px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
  .uf-meta{margin-top:3px;display:grid;grid-template-columns:1fr 1fr;gap:1px 12px;}
  .uf-meta-row{display:flex;align-items:center;gap:5px;font-size:10px;color:#475569;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .uf-meta-row i{width:12px;color:#94a3b8;text-align:center;font-size:10px;flex:0 0 auto;}
  .uf-hero-actions{display:flex;gap:5px;flex-wrap:wrap;align-items:center;justify-content:flex-end;}

  .uf-badge{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:999px;font-size:9px;font-weight:800;}
  .uf-badge.ok,.uf-badge.green{background:#dcfce7;color:#15803d;}
  .uf-badge.bad{background:#fee2e2;color:#b91c1c;}
  .uf-badge.warn{background:#ffedd5;color:#c2410c;}
  .uf-badge.blue{background:#dbeafe;color:#1d4ed8;}
  .uf-badge.gray{background:#f1f5f9;color:#475569;}
  .uf-badge.orange{background:#ffedd5;color:#c2410c;}
  .pill{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;}
  .pill.ok{background:#dcfce7;color:#15803d;}
  .pill.bad{background:#fee2e2;color:#b91c1c;}
  .pill.info{background:#dbeafe;color:#1d4ed8;}
  .pill.warn{background:#ffedd5;color:#c2410c;}

  .uf-metrics{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:5px;min-width:0;}
  .uf-metric{background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:5px 8px;min-width:0;overflow:hidden;}
  .uf-metric-top{display:flex;align-items:center;justify-content:space-between;gap:4px;margin-bottom:1px;}
  .uf-metric .lab{font-size:9px;font-weight:700;color:#64748b;}
  .uf-metric .val{font-size:14px;font-weight:800;color:#0f172a;line-height:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .uf-mico{width:16px;height:16px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:8px;flex:0 0 auto;}
  .uf-mico.purple{background:#f5f3ff;color:#7c3aed;}
  .uf-mico.blue{background:#dbeafe;color:#2563eb;}
  .uf-mico.green{background:#dcfce7;color:#16a34a;}
  .uf-mico.orange{background:#ffedd5;color:#ea580c;}
  .uf-mico.yellow{background:#fef9c3;color:#ca8a04;}
  .uf-mico.red{background:#fee2e2;color:#dc2626;}

  .uf-summary{
    flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:5px 10px;
    display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;min-width:0;
  }
  .uf-sum-item{min-width:0;overflow:hidden;}
  .uf-sum-item .k{font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;}
  .uf-sum-item .v{font-size:10px;font-weight:700;color:#0f172a;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

  .uf-tabs{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:8px;
    padding:0 4px;overflow:hidden;min-width:0;
  }
  .uf-tabs a{
    flex:0 0 auto;padding:5px 8px;font-size:10px;font-weight:700;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .uf-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .uf-tabs a:hover{color:#0f172a;text-decoration:none;}

  .uf-board{
    flex:1 1 auto;min-height:0;min-width:0;
    display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr) minmax(0,1fr);
    gap:5px;overflow:hidden !important;
  }
  .uf-col{min-height:0;min-width:0;display:flex;flex-direction:column;gap:5px;overflow:hidden !important;}
  .uf-card{
    background:#fff;border:1px solid #eef2f7;border-radius:8px;overflow:hidden;min-width:0;min-height:0;
    display:flex;flex-direction:column;
  }
  .uf-card.flex{flex:1 1 auto;min-height:0;}
  .uf-card.shrink{flex:0 0 auto;}
  .uf-card-hd{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:6px;
    padding:4px 8px;border-bottom:1px solid #f1f5f9;
  }
  .uf-card-hd h2{margin:0;font-size:11px;font-weight:800;color:#0f172a;}
  .uf-card-bd{flex:1 1 auto;min-height:0;padding:6px 8px;overflow:hidden;}
  .uf-card-bd.scroll{overflow:auto !important;overscroll-behavior:contain;}
  .uf-sec-title{font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;margin:8px 0 4px;letter-spacing:.04em;}
  .uf-sec-title:first-child{margin-top:0;}

  .uf-kv{display:flex;justify-content:space-between;gap:8px;padding:3px 0;border-bottom:1px solid #f8fafc;font-size:10px;}
  .uf-kv:last-child{border-bottom:0;}
  .uf-kv .k{color:#64748b;font-weight:700;}
  .uf-kv .v{color:#0f172a;font-weight:800;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:62%;}

  .uf-note{
    background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:5px 7px;margin-bottom:5px;
    font-size:10px;color:#78350f;line-height:1.3;
  }
  .uf-note.blue{background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a;}
  .uf-note.muted{background:#f8fafc;border-color:#e2e8f0;color:#475569;}
  .uf-note:last-child{margin-bottom:0;}
  .uf-note .meta{font-size:8px;font-weight:700;margin-bottom:2px;opacity:.85;}

  .uf-form{display:flex;flex-direction:column;gap:6px;min-height:0;}
  .uf-field label{display:block;font-size:9px;font-weight:800;color:#64748b;margin:0 0 2px;}
  .uf-field input,.uf-field select,.uf-field textarea{
    width:100%;height:28px;border:1px solid #e2e8f0;border-radius:6px;padding:0 7px;
    font-size:11px;color:#0f172a;box-sizing:border-box;background:#fff;
  }
  .uf-field textarea{height:auto;min-height:48px;padding:6px 7px;resize:vertical;}
  .uf-actions{display:flex;justify-content:flex-end;gap:5px;flex-wrap:wrap;}
  .uf-row{display:flex;gap:5px;flex-wrap:wrap;align-items:flex-end;}
  .uf-row .uf-field{flex:1 1 80px;min-width:70px;}

  .uf-table{width:100%;border-collapse:collapse;font-size:10px;}
  .uf-table th{
    text-align:left;font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;
    color:#94a3b8;padding:4px 3px;border-bottom:1px solid #eef2f7;position:sticky;top:0;background:#fff;z-index:1;
  }
  .uf-table td{padding:5px 3px;border-bottom:1px solid #f8fafc;vertical-align:middle;color:#0f172a;}
  .uf-table tr:hover td{background:#f8fafc;}

  .uf-quick{display:grid;grid-template-columns:1fr 1fr;gap:4px;}
  .uf-qbtn{
    border:1px solid #e2e8f0;border-radius:6px;padding:7px 4px;background:#fff;text-align:center;
    font-size:9px;font-weight:800;color:#334155;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:2px;
    cursor:pointer;
  }
  .uf-qbtn i{font-size:11px;}
  .uf-qbtn:hover{text-decoration:none;background:#f8fafc;}
  .uf-qbtn.green{border-color:#bbf7d0;background:#f0fdf4;color:#166534;}
  .uf-qbtn.orange{border-color:#fed7aa;background:#fff7ed;color:#c2410c;}
  .uf-qbtn.blue{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8;}
  .uf-qbtn.is-disabled{opacity:.45;pointer-events:none;}

  .uf-alert{flex:0 0 auto;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;}
  .uf-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .uf-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .uf-drop{position:relative;}
  .uf-drop-menu{
    display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:30;min-width:160px;
    background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 20px rgba(15,23,42,.12);padding:4px;
  }
  .uf-drop.open .uf-drop-menu{display:block;}
  .uf-drop-menu a,.uf-drop-menu button{
    display:block;width:100%;text-align:left;padding:6px 8px;border-radius:6px;font-size:11px;font-weight:700;
    color:#334155;text-decoration:none;border:0;background:transparent;cursor:pointer;
  }
  .uf-drop-menu a:hover,.uf-drop-menu button:hover{background:#f8fafc;}
  .uf-empty{padding:8px 4px;text-align:center;color:#64748b;font-size:10px;}
  .uf-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:9px;}

  @media (max-width:900px){
    .uf-metrics,.uf-summary,.uf-meta,.uf-quick{grid-template-columns:1fr 1fr;}
    .uf-board{grid-template-columns:1fr;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="uf-wrap">

      <?php if ($error !== ''): ?><div class="uf-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="uf-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>

      <section class="uf-hero">
        <div class="uf-hero-left">
          <div class="uf-av" style="background:<?= org_admin_h($avBg) ?>;"><?= org_admin_h($ini) ?></div>
          <div style="min-width:0;">
            <h1>
              <?= org_admin_h($orgName) ?>
              <i class="fa fa-<?= $isPubOrg ? 'bullhorn' : 'briefcase' ?>" style="color:#2563eb;font-size:12px;"></i>
            </h1>
            <div class="name">
              <?= org_admin_h($orgCode) ?>
              <span class="uf-badge <?= $orgStatus === 1 ? 'ok' : 'bad' ?>"><?= $orgStatus === 1 ? 'Active' : 'Disabled' ?></span>
              <span class="uf-badge <?= $isPubOrg ? 'blue' : 'gray' ?>"><?= $isPubOrg ? 'Publisher' : 'Regular' ?></span>
              <?php if ($navPos > 0): ?><span class="uf-badge gray"><?= (int)$navPos ?> / <?= (int)$navTotal ?></span><?php endif; ?>
            </div>
            <div class="uf-meta">
              <div class="uf-meta-row"><i class="fa fa-calendar"></i> Created <?= org_admin_h(org_admin_fmt_dt($org['created_at'] ?? '')) ?></div>
              <div class="uf-meta-row"><i class="fa fa-user"></i> <?= org_admin_h((string)($org['manager_username'] ?? '—')) ?></div>
              <div class="uf-meta-row"><i class="fa fa-users"></i> <?= (int)$memberCount ?> member<?= $memberCount === 1 ? '' : 's' ?></div>
              <div class="uf-meta-row"><i class="fa fa-cc-stripe"></i> Connect <?= $connectLinked ? 'linked' : 'not linked' ?></div>
            </div>
          </div>
        </div>
        <div class="uf-hero-actions">
          <div class="uf-drop" id="ufActionsDrop">
            <button type="button" class="uf-btn" onclick="document.getElementById('ufActionsDrop').classList.toggle('open')"><i class="fa fa-ellipsis-v"></i> Actions</button>
            <div class="uf-drop-menu">
              <a href="org_stripe_connect.php">Stripe Connect orgs</a>
              <a href="org_commerce_brands.php">Commerce brands</a>
              <a href="org_rent.php">Shop rent</a>
              <form method="post" onsubmit="return confirm('Change organization status?');" style="margin:0;">
                <input type="hidden" name="status_value" value="<?= $orgStatus === 1 ? 0 : 1 ?>">
                <button type="submit" name="set_org_status"><?= $orgStatus === 1 ? 'Disable org' : 'Activate org' ?></button>
              </form>
            </div>
          </div>
          <a class="uf-btn<?= $prevId <= 0 ? ' is-disabled' : '' ?>" href="<?= $prevId > 0 ? 'orgdetail.php?id=' . $prevId . '&amp;' . org_admin_h($detailQs) : '#' ?>"><i class="fa fa-chevron-left"></i> Previous</a>
          <a class="uf-btn<?= $nextId <= 0 ? ' is-disabled' : '' ?>" href="<?= $nextId > 0 ? 'orgdetail.php?id=' . $nextId . '&amp;' . org_admin_h($detailQs) : '#' ?>">Next <i class="fa fa-chevron-right"></i></a>
          <a class="uf-btn primary" href="<?= org_admin_h($listHref) ?>"><i class="fa fa-angle-left"></i> Back to list</a>
        </div>
      </section>

      <div class="uf-metrics">
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Status</span><span class="uf-mico <?= $orgStatus === 1 ? 'green' : 'red' ?>"><i class="fa fa-flag"></i></span></div><div class="val"><?= $orgStatus === 1 ? 'Active' : 'Disabled' ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Type</span><span class="uf-mico blue"><i class="fa fa-<?= $isPubOrg ? 'bullhorn' : 'briefcase' ?>"></i></span></div><div class="val"><?= $isPubOrg ? 'Publisher' : 'Regular' ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Members</span><span class="uf-mico purple"><i class="fa fa-users"></i></span></div><div class="val"><?= number_format($memberCount) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Connect</span><span class="uf-mico <?= $connectLinked ? 'green' : 'orange' ?>"><i class="fa fa-cc-stripe"></i></span></div><div class="val"><?= $connectLinked ? 'Linked' : 'None' ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Brand</span><span class="uf-mico yellow"><i class="fa fa-tag"></i></span></div><div class="val"><?= $orgCommerceBrand ? org_admin_h((string)($orgCommerceBrand['name'] ?? 'Set')) : '—' ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Rent</span><span class="uf-mico orange"><i class="fa fa-home"></i></span></div><div class="val"><?= $isShopOrg ? org_admin_h($rentLive !== '' ? $rentLive : 'shop') : 'n/a' ?></div></div>
      </div>

      <div class="uf-summary">
        <div class="uf-sum-item"><div class="k">Org ID</div><div class="v">#<?= (int)$orgId ?></div></div>
        <div class="uf-sum-item"><div class="k">Code</div><div class="v"><?= org_admin_h($orgCode !== '' ? $orgCode : '—') ?></div></div>
        <div class="uf-sum-item"><div class="k">Owner</div><div class="v"><?= org_admin_h((string)($org['manager_username'] ?? '—')) ?></div></div>
        <div class="uf-sum-item"><div class="k">Publisher</div><div class="v"><?= !empty($org['pub_user_id']) ? org_admin_h((string)($org['pub_username'] ?? '')) : '—' ?></div></div>
        <div class="uf-sum-item"><div class="k">Managers</div><div class="v"><?= (int)$mgrCount ?></div></div>
        <div class="uf-sum-item"><div class="k">Staff</div><div class="v"><?= (int)$staffCount ?></div></div>
        <div class="uf-sum-item"><div class="k">Created</div><div class="v"><?= org_admin_h(org_admin_fmt_dt($org['created_at'] ?? '')) ?></div></div>
      </div>

      <nav class="uf-tabs">
        <a href="#ufOverview" class="is-active">Overview</a>
        <a href="#ufMembers">Members (<?= (int)$memberCount ?>)</a>
        <a href="#ufConnect">Connect</a>
        <?php if ($isShopOrg): ?><a href="#ufRent">Shop rent</a><?php endif; ?>
        <a href="#ufBrand">Commerce</a>
        <a href="<?= org_admin_h($listHref) ?>">List</a>
      </nav>

      <div class="uf-board" id="ufOverview">
        <!-- LEFT: org + owner -->
        <div class="uf-col">
          <section class="uf-card flex">
            <div class="uf-card-hd">
              <h2>Organization</h2>
              <form method="post" onsubmit="return confirm('Change organization status?');" style="margin:0;">
                <input type="hidden" name="status_value" value="<?= $orgStatus === 1 ? 0 : 1 ?>">
                <button type="submit" name="set_org_status" class="uf-btn sm <?= $orgStatus === 1 ? 'warn' : 'primary' ?>">
                  <?= $orgStatus === 1 ? 'Disable' : 'Activate' ?>
                </button>
              </form>
            </div>
            <div class="uf-card-bd scroll">
              <div class="uf-sec-title">Workspace</div>
              <div class="uf-kv"><span class="k">Name</span><span class="v" title="<?= org_admin_h($orgName) ?>"><?= org_admin_h($orgName) ?></span></div>
              <div class="uf-kv"><span class="k">Code</span><span class="v"><?= org_admin_h($orgCode) ?></span></div>
              <div class="uf-kv"><span class="k">Status</span><span class="v"><?= org_admin_status_badge($orgStatus) ?></span></div>
              <div class="uf-kv"><span class="k">Type</span><span class="v"><?= $isPubOrg ? 'Publisher' : 'Regular' ?></span></div>
              <div class="uf-kv"><span class="k">Category</span><span class="v"><?= org_admin_h((string)($org['publisher_category'] ?? '') !== '' ? (string)$org['publisher_category'] : '—') ?></span></div>
              <div class="uf-kv"><span class="k">Registry</span><span class="v"><?php
                if (!empty($org['registered_publisher_name'])) {
                    if (!empty($org['pub_user_id'])) {
                        echo '<a href="' . org_admin_h(org_admin_user_activity_link((int)$org['pub_user_id'])) . '">' . org_admin_h((string)$org['registered_publisher_name']) . '</a>';
                    } else {
                        echo org_admin_h((string)$org['registered_publisher_name']);
                    }
                } else {
                    echo '—';
                }
              ?></span></div>

              <div class="uf-sec-title">Owner manager</div>
              <div class="uf-kv"><span class="k">Username</span><span class="v"><?= org_admin_h((string)($org['manager_username'] ?? '')) ?></span></div>
              <?php if (!empty($org['manager_fullname'])): ?>
                <div class="uf-kv"><span class="k">Name</span><span class="v"><?= org_admin_h((string)$org['manager_fullname']) ?></span></div>
              <?php endif; ?>
              <div class="uf-kv"><span class="k">Email</span><span class="v" title="<?= org_admin_h((string)($org['manager_email'] ?? '')) ?>"><?= org_admin_h((string)($org['manager_email'] ?? '—')) ?></span></div>
              <div class="uf-kv"><span class="k">Code</span><span class="v"><?= org_admin_h((string)($org['manager_code'] ?? '—')) ?></span></div>
              <div class="uf-kv"><span class="k">Status</span><span class="v"><?= org_admin_status_badge($managerStatus) ?></span></div>
              <form method="post" class="uf-actions" style="margin-top:6px;" onsubmit="return confirm('Change manager status?');">
                <input type="hidden" name="manager_id" value="<?= (int)($org['manager_id'] ?? 0) ?>">
                <input type="hidden" name="status_value" value="<?= $managerStatus === 1 ? 0 : 1 ?>">
                <button type="submit" name="set_manager_status" class="uf-btn sm <?= $managerStatus === 1 ? 'warn' : 'primary' ?>">
                  <?= $managerStatus === 1 ? 'Disable manager' : 'Activate manager' ?>
                </button>
              </form>

              <div class="uf-sec-title" id="ufBrand">Commerce brand</div>
              <div class="uf-kv">
                <span class="k">Current</span>
                <span class="v">
                  <?php if ($orgCommerceBrand): ?>
                    <span class="uf-badge ok"><?= org_admin_h((string)($orgCommerceBrand['name'] ?? '')) ?></span>
                  <?php else: ?>
                    <span class="uf-badge bad">Not linked</span>
                  <?php endif; ?>
                </span>
              </div>
              <?php if ($commerceBrands): ?>
              <form method="post" class="uf-form" style="margin-top:4px;">
                <div class="uf-field">
                  <select name="commerce_brand_id" required>
                    <option value="">Choose brand…</option>
                    <?php foreach ($commerceBrands as $brand):
                      $bid = (int)($brand['id'] ?? 0);
                      $selected = $orgCommerceBrand && (int)($orgCommerceBrand['id'] ?? 0) === $bid;
                      if (!$selected && !$orgCommerceBrand && $suggestedCommerceBrand && (int)($suggestedCommerceBrand['id'] ?? 0) === $bid) {
                          $selected = true;
                      }
                    ?>
                      <option value="<?= $bid ?>"<?= $selected ? ' selected' : '' ?>><?= org_admin_h((string)($brand['name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="uf-actions">
                  <button type="submit" name="migrate_commerce_brand" class="uf-btn sm primary"><?= $orgCommerceBrand ? 'Change' : 'Assign' ?></button>
                </div>
              </form>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <!-- MIDDLE: members -->
        <div class="uf-col">
          <section class="uf-card flex" id="ufMembers">
            <div class="uf-card-hd">
              <h2>Members</h2>
              <span class="uf-badge gray"><?= (int)$memberCount ?></span>
            </div>
            <div class="uf-card-bd scroll">
              <?php if (!$members): ?>
                <div class="uf-empty">No members found.</div>
              <?php else: ?>
                <table class="uf-table">
                  <thead>
                    <tr>
                      <th>Type</th>
                      <th>Account</th>
                      <th>Role</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($members as $m):
                    $memberType = (string)($m['member_type'] ?? '');
                    $accountStatus = (int)($m['account_status'] ?? 0);
                    $memberId = (int)($m['member_id'] ?? 0);
                  ?>
                    <tr>
                      <td><span class="uf-badge <?= $memberType === 'staff' ? 'warn' : 'blue' ?>"><?= org_admin_h(ucfirst($memberType)) ?></span></td>
                      <td>
                        <div style="font-weight:800;"><?= org_admin_h((string)($m['member_username'] ?? '')) ?></div>
                        <div style="font-size:8px;color:#94a3b8;"><?= org_admin_h((string)($m['member_code'] ?? '')) ?></div>
                      </td>
                      <td><?= org_admin_h((string)($m['role_name'] ?? '')) ?></td>
                      <td><?= org_admin_status_badge($accountStatus) ?></td>
                      <td>
                        <?php if ($memberType === 'staff' && $memberId > 0): ?>
                          <form method="post" style="display:inline;" onsubmit="return confirm('Change staff status?');">
                            <input type="hidden" name="staff_id" value="<?= $memberId ?>">
                            <input type="hidden" name="status_value" value="<?= $accountStatus === 1 ? 0 : 1 ?>">
                            <button type="submit" name="set_staff_status" class="uf-btn sm <?= $accountStatus === 1 ? 'warn' : 'primary' ?>">
                              <?= $accountStatus === 1 ? 'Off' : 'On' ?>
                            </button>
                          </form>
                        <?php elseif ($memberType === 'manager' && $memberId > 0 && $memberId !== (int)($org['manager_id'] ?? 0)): ?>
                          <span style="font-size:9px;color:#94a3b8;">—</span>
                        <?php else: ?>
                          <span style="font-size:9px;color:#94a3b8;">Owner</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <!-- RIGHT: publisher + connect + rent + actions -->
        <div class="uf-col">
          <section class="uf-card flex">
            <div class="uf-card-hd"><h2>Links &amp; payouts</h2></div>
            <div class="uf-card-bd scroll">
              <div class="uf-sec-title">Publisher user</div>
              <?php if (!empty($org['pub_user_id'])): ?>
                <div class="uf-kv"><span class="k">User</span><span class="v"><?= org_admin_render_public_user_link((int)$org['pub_user_id'], (string)($org['pub_username'] ?? ''), (string)($org['pub_username'] ?? ''), (string)($org['pub_code'] ?? '')) ?></span></div>
                <div class="uf-kv"><span class="k">Code</span><span class="v"><?= org_admin_h((string)($org['pub_code'] ?? '—')) ?></span></div>
                <div class="uf-kv"><span class="k">Status</span><span class="v"><?= org_admin_status_badge((int)($org['pub_user_status'] ?? 0)) ?></span></div>
              <?php else: ?>
                <div class="uf-empty" style="padding:4px 0;">No public_user link</div>
              <?php endif; ?>
              <form method="post" class="uf-form" style="margin-top:4px;">
                <div class="uf-field">
                  <input type="text" name="publisher_lookup" placeholder="Id, username, email, or code" required>
                </div>
                <div class="uf-actions">
                  <button type="submit" name="link_publisher_user" class="uf-btn sm primary" onclick="return confirm('Link / replace publisher user?');">
                    <?= !empty($org['pub_user_id']) ? 'Replace' : 'Link' ?>
                  </button>
                </div>
              </form>
              <?php if (!empty($org['pub_user_id'])): ?>
              <form method="post" class="uf-actions" style="margin-top:2px;" onsubmit="return confirm('Unlink publisher user?');">
                <button type="submit" name="unlink_publisher_user" class="uf-btn sm warn">Unlink</button>
              </form>
              <?php endif; ?>

              <div class="uf-sec-title" id="ufConnect">Stripe Connect</div>
              <div class="uf-kv">
                <span class="k">Account</span>
                <span class="v uf-mono" title="<?= org_admin_h((string)($connectStatus['account_id'] ?? '')) ?>">
                  <?= $connectLinked ? org_admin_h((string)$connectStatus['account_id']) : 'Not linked' ?>
                </span>
              </div>
              <div style="display:flex;gap:3px;flex-wrap:wrap;margin:4px 0;">
                <span class="uf-badge <?= !empty($connectStatus['details_submitted']) ? 'ok' : 'bad' ?>">Details</span>
                <span class="uf-badge <?= !empty($connectStatus['charges_enabled']) ? 'ok' : 'bad' ?>">Charges</span>
                <span class="uf-badge <?= !empty($connectStatus['payouts_enabled']) ? 'ok' : 'bad' ?>">Payouts</span>
              </div>
              <div class="uf-actions">
                <form method="post" style="margin:0;">
                  <button type="submit" name="sync_connect" class="uf-btn sm primary"<?= $stripeReady ? '' : ' disabled' ?>>Sync</button>
                </form>
                <?php if ($connectLinked): ?>
                  <form method="post" style="margin:0;" onsubmit="return confirm('Clear local Connect link?');">
                    <button type="submit" name="clear_connect" class="uf-btn sm warn">Clear</button>
                  </form>
                <?php endif; ?>
                <a class="uf-btn sm" href="org_stripe_connect.php">All</a>
              </div>

              <?php if ($isShopOrg && $rentSnapshot): ?>
              <div class="uf-sec-title" id="ufRent">Shop rent</div>
              <div class="uf-kv"><span class="k">Status</span><span class="v"><?= platform_rent_status_badge((string)($rentSnapshot['rent_status_live'] ?? $rentSnapshot['rent_status'] ?? 'trial')) ?></span></div>
              <div class="uf-kv"><span class="k">Shop</span><span class="v"><?= $shopVisible ? '<span class="uf-badge ok">Visible</span>' : '<span class="uf-badge bad">Hidden</span>' ?></span></div>
              <div class="uf-kv"><span class="k">Plan</span><span class="v"><?= org_admin_h((string)($rentSnapshot['plan_name'] ?? 'Shop Trial')) ?></span></div>
              <div class="uf-kv"><span class="k">Until</span><span class="v"><?php
                $until = trim((string)($rentSnapshot['rent_paid_until'] ?? ''));
                if ($until === '') {
                    $until = trim((string)($rentSnapshot['rent_trial_ends_at'] ?? ''));
                }
                echo $until !== '' ? org_admin_h(org_admin_fmt_dt($until)) : '—';
              ?></span></div>
              <form method="post" class="uf-form" style="margin-top:4px;">
                <div class="uf-row">
                  <div class="uf-field">
                    <select name="plan_id" required>
                      <?php foreach ($paidRentPlans as $plan): ?>
                        <option value="<?= (int)$plan['id'] ?>"><?= org_admin_h((string)($plan['name'] ?? '')) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="uf-field" style="max-width:70px;">
                    <select name="months_paid">
                      <option value="1">1 mo</option>
                      <option value="3">3 mo</option>
                      <option value="6">6 mo</option>
                      <option value="12">12 mo</option>
                    </select>
                  </div>
                </div>
                <input type="hidden" name="payment_method" value="manual">
                <div class="uf-field"><input type="text" name="payment_reference" placeholder="Reference #"></div>
                <div class="uf-field"><input type="text" name="notes" placeholder="Notes"></div>
                <div class="uf-actions">
                  <button type="submit" name="suspend_rent" class="uf-btn sm warn" onclick="return confirm('Suspend shop rent?');">Suspend</button>
                  <button type="submit" name="mark_rent_paid" class="uf-btn sm primary">Mark paid</button>
                </div>
              </form>
              <?php endif; ?>
            </div>
          </section>

          <section class="uf-card shrink">
            <div class="uf-card-hd"><h2>Quick Actions</h2></div>
            <div class="uf-card-bd" style="overflow:hidden;">
              <div class="uf-quick">
                <a class="uf-qbtn blue" href="<?= org_admin_h($listHref) ?>"><i class="fa fa-list"></i> Back</a>
                <a class="uf-qbtn<?= $prevId <= 0 ? ' is-disabled' : '' ?>" href="<?= $prevId > 0 ? 'orgdetail.php?id=' . $prevId . '&amp;' . org_admin_h($detailQs) : '#' ?>"><i class="fa fa-chevron-left"></i> Prev</a>
                <a class="uf-qbtn<?= $nextId <= 0 ? ' is-disabled' : '' ?>" href="<?= $nextId > 0 ? 'orgdetail.php?id=' . $nextId . '&amp;' . org_admin_h($detailQs) : '#' ?>"><i class="fa fa-chevron-right"></i> Next</a>
                <a class="uf-qbtn green" href="org_stripe_connect.php"><i class="fa fa-cc-stripe"></i> Connect</a>
                <a class="uf-qbtn orange" href="org_commerce_brands.php"><i class="fa fa-tags"></i> Brands</a>
                <a class="uf-qbtn" href="orglist.php?filter=publisher"><i class="fa fa-bullhorn"></i> Pubs</a>
              </div>
            </div>
          </section>
        </div>
      </div>

    </div>
  </div>
</div>
<script>
(function(){
  document.addEventListener('click', function(e){
    var drop = document.getElementById('ufActionsDrop');
    if (!drop) return;
    if (!drop.contains(e.target)) drop.classList.remove('open');
  });
})();
</script>
<?php org_admin_render_foot(); ?>
