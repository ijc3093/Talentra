<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_organization_bridge.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/live_browse.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$dbh = (new Controller())->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$canStudio = live_studio_user_can_access($dbh, $meId)
    || (isset($_GET['can_studio']) && (string)$_GET['can_studio'] === '1');

$hubSurface = strtolower(trim((string)($_GET['hub_surface'] ?? 'public')));
if (!in_array($hubSurface, ['feed', 'public'], true)) {
    $hubSurface = 'public';
}
$hubBrowseTabLabel = $hubSurface === 'feed' ? 'Friend' : 'Public';

$hubDoorQuery = ['hub_surface' => $hubSurface];
if ($canStudio) {
    $hubDoorQuery['can_studio'] = '1';
}
$hubDoor = strtolower(trim((string)($_GET['hub_door'] ?? '')));
if (!in_array($hubDoor, ['left', 'right'], true)) {
    $hubDoor = '';
}
if ($hubDoor !== '') {
    $hubDoorQuery['hub_door'] = $hubDoor;
}
$hubDoorBaseUrl = 'live_door_hub.php?' . http_build_query($hubDoorQuery);

$hubPaintBg = '#171d24';
$hubPaintText = '#b1bcce';
$hubBgParam = trim((string)($_GET['hub_bg'] ?? ''));
if ($hubBgParam !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $hubBgParam)) {
    $hubPaintBg = $hubBgParam;
    $hubPaintText = '#f3f6fb';
}

$doorLists = live_browse_door_rows($dbh, $meId, 50);
$ownLiveId = (int)($doorLists['own_live_id'] ?? 0);
$chatLives = $doorLists['chat_rows'];
$friendLives = $doorLists['friend_rows'];
$publicLives = $doorLists['public_rows'];
$browseLives = $hubSurface === 'feed' ? $friendLives : $publicLives;

$featured = null;
if ($ownLiveId > 0) {
    foreach ($chatLives as $row) {
        if ((int)($row['id'] ?? 0) === $ownLiveId) {
            $featured = $row;
            break;
        }
    }
} elseif ($browseLives) {
    $featured = $browseLives[0];
}

if ($featured && $hubDoor !== '') {
    $featuredHostDoor = strtolower(trim((string)($featured['host_door'] ?? '')));
    $featuredStudioSource = strtolower(trim((string)($featured['studio_source'] ?? '')));
    $featuredOwnerDoor = ($featuredHostDoor === 'right' || $featuredStudioSource === 'software')
        ? 'right'
        : ($featuredHostDoor === 'left' ? 'left' : $featuredHostDoor);
    if ($featuredOwnerDoor !== '' && $featuredOwnerDoor !== $hubDoor) {
        $featured = null;
    }
}

if ($hubDoor !== '') {
    $chatLives = array_values(array_filter($chatLives, static function (array $row) use ($meId, $hubDoor): bool {
        $isOwner = (int)($row['user_id'] ?? 0) === $meId || !empty($row['is_owner']);
        if (!$isOwner) {
            return false;
        }
        $hostDoor = strtolower(trim((string)($row['host_door'] ?? '')));
        $studioSource = strtolower(trim((string)($row['studio_source'] ?? '')));
        $ownerDoor = ($hostDoor === 'right' || $studioSource === 'software')
            ? 'right'
            : ($hostDoor === 'left' ? 'left' : $hostDoor);
        if ($ownerDoor === 'right') {
            return $hubDoor === 'right';
        }
        if ($ownerDoor === 'left') {
            return $hubDoor === 'left';
        }
        return $hubDoor === 'left';
    }));
    $ownLiveId = 0;
    foreach ($chatLives as $row) {
        $ownLiveId = (int)($row['id'] ?? 0);
        if ($ownLiveId > 0) {
            break;
        }
    }
}

$featuredTitle = trim((string)($featured['title'] ?? ''));
if ($featuredTitle === '') {
    $featuredTitle = 'Live now on Talentra';
}
$featuredHost = trim((string)($featured['name'] ?? $featured['username'] ?? 'Host'));
$featuredDescription = $featured ? trim((string)($featured['description'] ?? '')) : '';
$featuredViews = (int)($featured['viewer_count'] ?? 0);
if ($featured) {
    $featuredTitle = trim((string)($featured['title'] ?? ''));
    if ($featuredTitle === '') {
        $featuredTitle = 'Live now on Talentra';
    }
    $featuredHost = trim((string)($featured['name'] ?? $featured['username'] ?? 'Host'));
    $featuredDescription = trim((string)($featured['description'] ?? ''));
    $featuredViews = (int)($featured['viewer_count'] ?? 0);
} else {
    $featured = null;
    $featuredTitle = 'Live now on Talentra';
    $featuredHost = 'Talentra Live';
    $featuredDescription = '';
    $featuredViews = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Live</title>
  <style id="msb-live-hub-critical">
    html,body{
      background:var(--msb-palette-bg, <?= h($hubPaintBg) ?>) !important;
      background-image:none !important;
      color:var(--msb-palette-text, <?= h($hubPaintText) ?>) !important;
    }
  </style>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <style>
    :root{
      --hub-bg:var(--msb-palette-bg, #171d24);
      --hub-text:var(--msb-palette-text, #101828);
      --hub-muted:var(--msb-palette-text-muted, #667085);
      --hub-border:var(--msb-palette-border, rgba(15,23,42,.08));
      --hub-control-bg:var(--msb-palette-nav-hover, rgba(15,23,42,.06));
      --hub-link:var(--msb-palette-action, var(--msb-palette-link, #2563eb));
      --hub-pick-bg:var(--msb-palette-hover-bg, rgba(15,23,42,.04));
      --hub-pick-hover:var(--msb-palette-nav-hover, rgba(15,23,42,.08));
      --hub-pick-active-bg:var(--msb-palette-nav-active-bg, var(--msb-palette-action-soft, rgba(15,23,42,.08)));
      --hub-pick-active-text:var(--msb-palette-nav-active-text, var(--msb-palette-text));
      --hub-avatar-bg:var(--msb-palette-surface, #e2e8f0);
      --hub-input-bg:var(--msb-palette-input-bg, rgba(15,23,42,.06));
      --hub-placeholder:var(--msb-palette-placeholder, #98a2b3);
      --hub-overlay:rgba(15,23,42,.45);
      --hub-confirm-card-bg:var(--msb-palette-bg, #ffffff);
      --hub-confirm-card-border:var(--msb-palette-border, rgba(15,23,42,.1));
      --hub-confirm-muted:var(--msb-palette-text-muted, #667085);
      --hub-confirm-control-bg:var(--msb-palette-nav-hover, rgba(15,23,42,.06));
      --hub-confirm-control-border:var(--msb-palette-border, rgba(15,23,42,.12));
      --hub-media-bg:var(--msb-palette-surface, #0b0d12);
      --hub-stage-border:rgba(15,23,42,.14);
      --hub-stage-shadow:rgba(15,23,42,.14);
      --hub-stage-placeholder-text:var(--msb-palette-text, #101828);
      --hub-stage-placeholder-muted:var(--msb-palette-text-muted, #667085);
      --hub-system-text:var(--msb-palette-text, #101828);
      --hub-toast-bg:var(--msb-palette-bg, #ffffff);
      --hub-toast-text:var(--msb-palette-text, #101828);
      --hub-toast-shadow:rgba(15,23,42,.16);
      --hub-live-surface:var(--msb-palette-surface-2, var(--msb-palette-bg, #f5f7fb));
      --hub-live-border:var(--msb-palette-border, rgba(15,23,42,.08));
      --hub-live-text:var(--msb-palette-text, #101828);
      --hub-live-muted:var(--msb-palette-text-muted, #667085);
      --hub-live-subtle:var(--msb-palette-placeholder, #98a2b3);
      --hub-live-compose-bg:var(--msb-palette-bg, #ffffff);
      --hub-live-compose-input:var(--msb-palette-input-bg, var(--msb-palette-hover-bg, #f2f4f7));
      --hub-live-compose-input-border:var(--msb-palette-border-strong, rgba(15,23,42,.1));
      --hub-live-compose-text:var(--msb-palette-text, #101828);
      --hub-live-compose-placeholder:var(--msb-palette-placeholder, #98a2b3);
      --hub-live-accent:var(--msb-palette-link, #6f85c9);
      --hub-live-send-bg:var(--msb-palette-action, #dc2626);
      --hub-live-send-text:var(--msb-palette-text-on-action, #ffffff);
    }
    html.dark-auto:not([data-msb-appearance]),
    html[data-theme="dark"]:not([data-msb-appearance]){
      --hub-stage-border:rgba(255,255,255,.1);
      --hub-stage-shadow:rgba(0,0,0,.42);
      --hub-stage-placeholder-text:var(--msb-palette-text, #f8fafc);
      --hub-stage-placeholder-muted:var(--msb-palette-text-muted, rgba(255,255,255,.62));
      --hub-overlay:rgba(0,0,0,.62);
      --hub-confirm-card-bg:linear-gradient(180deg,#171b26 0%,#11151d 100%);
      --hub-confirm-card-border:rgba(255,255,255,.1);
      --hub-confirm-muted:rgba(255,255,255,.62);
      --hub-confirm-control-bg:rgba(255,255,255,.06);
      --hub-confirm-control-border:rgba(255,255,255,.12);
      --hub-toast-bg:#1f2937;
      --hub-toast-text:#f8fafc;
      --hub-toast-shadow:rgba(0,0,0,.35);
    }
    *{ box-sizing:border-box; }
    html,body{
      margin:0;
      height:100%;
      background:var(--hub-bg);
      color:var(--hub-text);
      font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      font-size:14px;
      line-height:1.35;
      transition:background-color .22s ease, color .22s ease, border-color .22s ease;
    }
    .hub-shell{ min-height:100%; height:100%; display:flex; flex-direction:column; background:var(--hub-bg); --hub-stage-inset-x:10px; }
    .hub-top{
      display:flex; align-items:center; justify-content:space-between; gap:8px;
      padding:8px 10px 6px; background:var(--hub-bg);
    }
    .hub-brand{ display:flex; align-items:center; gap:8px; min-width:0; }
    .hub-logo{
      width:28px; height:28px; border-radius:999px;
      background:linear-gradient(135deg, var(--msb-palette-action, #2563eb), var(--msb-palette-accent, #7c3aed));
      display:grid; place-items:center; font-weight:900; font-size:11px; flex:0 0 auto; color:#fff;
    }
    .hub-brand-text{ min-width:0; }
    .hub-brand-text strong{ display:block; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--hub-text); }
    .hub-brand-text span{ display:block; font-size:11px; color:var(--hub-muted); margin-top:1px; }
    .hub-top-actions{ display:flex; align-items:center; gap:6px; flex:0 0 auto; }
    .hub-icon-btn{
      width:28px; height:28px; border:0; border-radius:999px; background:var(--hub-control-bg);
      color:var(--hub-text); display:grid; place-items:center; cursor:pointer; font-size:13px;
    }
    .hub-end-btn{
      border:0; border-radius:999px; height:28px; padding:0 10px;
      background:rgba(239,68,68,.18); color:#fca5a5; font-size:11px; font-weight:800;
      cursor:pointer; flex:0 0 auto;
    }
    .hub-end-btn:disabled{ opacity:.55; cursor:wait; }
    .hub-studio-top-btn{
      border:0; border-radius:999px; height:28px; padding:0 10px;
      background:var(--hub-control-bg); color:var(--hub-text); font-size:11px; font-weight:800;
      cursor:pointer; flex:0 0 auto; white-space:nowrap;
    }
    .hub-studio-top-btn.is-active{
      background:var(--hub-pick-active-bg);
      color:var(--hub-pick-active-text);
    }
    .hub-stage-wrap{
      position:relative;
      flex:0 0 auto;
      padding:8px var(--hub-stage-inset-x) 6px;
      background:var(--hub-bg);
    }
    .hub-stage{
      --hub-stage-ratio:16/10;
      position:relative;
      width:100%;
      aspect-ratio:var(--hub-stage-ratio);
      min-height:120px;
      max-height:min(30vh, 200px);
      background:var(--hub-media-bg);
      border:1px solid var(--hub-stage-border);
      border-radius:12px;
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.04),
        0 10px 28px var(--hub-stage-shadow);
      overflow:hidden;
    }
    .hub-stage.is-watching{
      background:var(--hub-media-bg);
    }
    .hub-stage .hub-watch-frame,
    .hub-stage .hub-watch-snapshot,
    .hub-stage .hub-host-video,
    .hub-stage .hub-watch-loading{
      position:absolute;
      inset:0;
      width:100%;
      height:100%;
      border:0;
      border-radius:inherit;
      display:block;
      background:var(--hub-media-bg);
    }
    .hub-stage .hub-watch-frame{
      z-index:1;
      pointer-events:none;
      transition:opacity .18s ease;
    }
    .hub-stage .hub-watch-snapshot{
      object-fit:cover;
      z-index:2;
      opacity:0;
      pointer-events:none;
      transition:opacity .18s ease;
    }
    .hub-stage.is-watching.use-snapshot-stage .hub-watch-snapshot{
      opacity:1;
    }
    .hub-stage.is-watching.use-snapshot-stage .hub-watch-frame{
      opacity:0;
    }
    .hub-watch-loading{
      z-index:3;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:12px;
      color:rgba(255,255,255,.78);
      font-size:12px;
      font-weight:700;
      text-align:center;
    }
    .hub-stage .hub-host-video{
      object-fit:cover;
      z-index:1;
    }
    .hub-stage .hub-host-cam-retry{
      position:absolute;
      inset:0;
      z-index:3;
      display:none;
      align-items:center;
      justify-content:center;
      padding:12px;
      border:0;
      background:rgba(0,0,0,.72);
      color:#fff;
      font:inherit;
      font-size:12px;
      font-weight:700;
      cursor:pointer;
      text-align:center;
    }
    .hub-stage .hub-host-cam-retry.is-visible{
      display:flex;
    }
    .hub-stage-meta.is-hidden{
      display:none;
    }
    .hub-stage img{ width:100%; height:100%; object-fit:cover; display:block; }
    .hub-stage-placeholder{
      position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center;
      gap:6px; color:var(--hub-stage-placeholder-text); text-align:center; padding:12px;
      font-size:12px; font-weight:700;
    }
    .hub-stage-placeholder i{ font-size:22px; opacity:.72; color:var(--hub-stage-placeholder-muted); }
    .hub-stage-placeholder .hub-live-pick{
      max-width:min(100%, 220px);
      justify-content:center;
    }
    .hub-live-pick-gap{ margin-left:6px; }
    .hub-stage-placeholder .hub-stage-placeholder-sub{
      font-size:11px;
      color:var(--hub-stage-placeholder-muted);
    }
    .hub-stage-meta{
      position:absolute; left:8px; right:8px; bottom:8px; z-index:4;
      display:flex; align-items:center; justify-content:space-between; gap:10px;
      pointer-events:none;
    }
    .hub-stage.is-watching .hub-stage-meta,
    .hub-stage:has(.hub-stage-placeholder) .hub-stage-meta{
      display:none;
    }
    .hub-live-tag{
      display:inline-flex; align-items:center; gap:4px; padding:4px 8px; border-radius:999px;
      background:rgba(0,0,0,.55); border:1px solid rgba(255,255,255,.16); font-size:9px; font-weight:800; letter-spacing:.05em;
    }
    .hub-live-tag .dot{ width:6px; height:6px; border-radius:999px; background:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.25); }
    .hub-views{ font-size:10px; font-weight:700; color:rgba(255,255,255,.88); }
    .hub-tabs{
      display:flex; gap:10px; overflow:auto; padding:0 10px; border-bottom:1px solid var(--hub-border);
      background:var(--hub-bg);
      scrollbar-width:none;
    }
    .hub-tabs::-webkit-scrollbar{ display:none; }
    .hub-tabs button{
      border:0; background:transparent; color:var(--hub-muted); font-size:11px; font-weight:700;
      padding:8px 0 6px; cursor:pointer; white-space:nowrap; position:relative;
    }
    .hub-tabs button.is-active{ color:var(--hub-pick-active-text, var(--hub-text)); }
    .hub-tabs button.is-active::after{
      content:''; position:absolute; left:0; right:0; bottom:0; height:2px;
      background:var(--msb-palette-action, var(--hub-link)); border-radius:999px;
    }
    .hub-chat{
      flex:1 1 auto; min-height:0; overflow:auto; padding:10px;
      display:flex; flex-direction:column; gap:10px;
    }
    .hub-body{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
    }
    .hub-panel{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      padding:10px;
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    .hub-panel.is-hidden{ display:none; }
    .hub-shell.is-studio-tab .hub-stage-wrap{
      display:none;
    }
    .hub-shell.is-studio-tab .hub-body{
      flex:1 1 auto;
      min-height:280px;
    }
    .hub-panel-studio{
      padding:0;
      overflow:hidden;
      gap:0;
      display:flex;
      flex-direction:column;
      flex:1 1 auto;
      min-height:280px;
    }
    .hub-studio-loading{
      flex:1 1 auto;
      min-height:200px;
      display:grid;
      place-items:center;
      color:var(--hub-muted);
      font-size:12px;
      font-weight:700;
    }
    .hub-panel-studio.is-frame-ready .hub-studio-loading{
      display:none;
    }
    .hub-public-browse{
      display:flex;
      flex-direction:column;
      gap:8px;
      flex:1 1 auto;
      min-height:0;
    }
    .hub-studio-frame{
      width:100%;
      flex:1 1 auto;
      min-height:280px;
      height:100%;
      border:0;
      display:block;
      background:var(--hub-bg);
    }
    .hub-body.is-switching .hub-panel{
      pointer-events:none;
    }
    .hub-empty-link{
      border:0;
      background:transparent;
      padding:0;
      color:var(--hub-link);
      font:inherit;
      font-size:12px;
      cursor:pointer;
      text-align:left;
    }
    .hub-msg{ display:flex; gap:7px; align-items:flex-start; }
    .hub-msg-avatar{
      width:24px; height:24px; border-radius:999px; background:var(--hub-avatar-bg); color:var(--hub-text); display:grid; place-items:center;
      font-size:9px; font-weight:800; flex:0 0 auto;
    }
    .hub-msg-body{ min-width:0; }
    .hub-msg-user{ font-size:11px; color:var(--hub-muted); margin-bottom:1px; }
    .hub-msg-text{ font-size:13px; font-weight:700; line-height:1.35; color:var(--hub-text); }
    .hub-msg.system .hub-msg-text{ font-weight:600; color:var(--hub-system-text); }
    .hub-live-pick{
      display:flex; align-items:center; gap:8px; width:100%; text-align:left; border:0; cursor:pointer;
      padding:8px 6px; border-radius:10px; background:var(--hub-pick-bg); color:var(--hub-text);
      font-size:12px;
    }
    .hub-live-pick:hover{ background:var(--hub-pick-hover); }
    .hub-live-pick.is-active,
    .hub-live-pick[aria-current="true"]{
      background:var(--hub-pick-active-bg);
      color:var(--hub-pick-active-text);
    }
    .hub-live-pick + .hub-live-pick{ margin-top:6px; }
    .hub-empty{ padding:12px 6px; color:var(--hub-muted); font-size:12px; line-height:1.45; }
    .hub-empty a{ color:var(--hub-link); }
    .hub-tab-count{
      display:inline;
      margin-left:2px;
      font-size:10px;
      font-weight:800;
      color:var(--hub-muted);
    }
    .hub-tabs button.is-active .hub-tab-count{ color:var(--hub-pick-active-text, var(--hub-text)); }
    .hub-tabs button.hub-tab-device{
      display:inline-flex;
      align-items:center;
      gap:4px;
    }
    .hub-tabs button.hub-tab-device i{
      position:relative;
      font-size:11px;
    }
    .hub-tabs button.hub-tab-device i.has-off-slash::after{
      content:'';
      position:absolute;
      top:-2px;
      left:50%;
      width:2px;
      height:12px;
      border-radius:999px;
      background:#ef4444;
      transform:translateX(-50%) rotate(42deg);
    }
    .hub-tabs button:disabled{
      opacity:.42;
      cursor:default;
    }
    .hub-compose-live{
      flex:0 0 auto;
      display:none;
    }
    #hubChat.is-live-session{
      padding:0;
      gap:0;
      margin:0 var(--hub-stage-inset-x);
      width:auto;
      align-self:stretch;
      border-radius:12px;
      overflow:hidden;
      border:1px solid var(--hub-live-border);
      background:var(--hub-live-surface);
      color:var(--hub-live-text);
      display:flex;
      flex-direction:column;
      flex:1 1 auto;
      min-height:0;
    }
    .hub-chat-live{
      display:none;
      flex:1 1 auto;
      min-height:0;
      flex-direction:column;
    }
    .hub-chat-live.is-visible{
      display:flex;
      flex:1 1 auto;
      min-height:0;
    }
    .hub-chat-live.is-hidden{
      display:none !important;
    }
    .hub-live-side-stats{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      padding:8px 10px;
      border-bottom:1px solid var(--hub-live-border);
      color:var(--hub-live-muted);
      font-size:11px;
      font-weight:700;
    }
    .hub-live-side-stats strong{
      color:var(--hub-live-text);
      font-size:12px;
    }
    .hub-live-chat-panel{
      display:flex;
      flex-direction:column;
      flex:1 1 auto;
      min-height:0;
    }
    .hub-live-comments-box{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      color:var(--hub-live-muted);
      font-size:12px;
      line-height:1.45;
      background:var(--hub-live-surface);
    }
    .hub-live-comments-box.has-comments{
      color:var(--hub-live-text);
      overflow:hidden;
    }
    .hub-live-comments-list{
      overflow-y:auto;
      padding:8px 10px 12px;
      display:flex;
      flex-direction:column;
      gap:8px;
      flex:1 1 auto;
      min-height:0;
      align-content:flex-start;
      color:var(--hub-live-muted);
      background:var(--hub-live-surface);
    }
    .hub-live-comment-card{
      display:grid;
      grid-template-columns:24px minmax(0,1fr);
      gap:7px;
      align-items:start;
      flex:0 0 auto;
    }
    .hub-live-comment-avatar{
      width:24px;
      height:24px;
      border-radius:50%;
      color:#fff;
      font-size:9px;
      font-weight:900;
      display:grid;
      place-items:center;
      box-shadow:none;
    }
    .hub-live-comment-main{ min-width:0; }
    .hub-live-comment-author{
      font-size:11px;
      font-weight:800;
      margin-bottom:2px;
      color:var(--hub-live-muted);
      display:flex;
      align-items:center;
      gap:6px;
      flex-wrap:wrap;
    }
    .hub-live-comment-team{
      color:var(--hub-live-accent);
      font-size:9px;
      font-weight:700;
    }
    .hub-live-comment-body{
      font-size:14px;
      color:var(--hub-live-text);
      line-height:1.4;
      letter-spacing:0;
      word-break:break-word;
    }
    .hub-live-comment-meta{
      display:flex;
      align-items:center;
      gap:10px;
      margin-top:6px;
      color:var(--hub-live-subtle);
      font-size:11px;
      font-weight:700;
    }
    .hub-live-comment-reply{
      border:0;
      background:transparent;
      color:var(--hub-live-muted);
      padding:0;
      font:inherit;
      font-weight:800;
      cursor:pointer;
    }
    .hub-live-comment-like{
      margin-left:auto;
      display:inline-flex;
      align-items:center;
      gap:8px;
      border:0;
      background:transparent;
      padding:0;
      font:inherit;
      color:var(--hub-live-muted);
      cursor:pointer;
    }
    .hub-live-comment-like i{ font-size:14px; }
    .hub-live-comment-like-count{
      font-size:11px;
      font-weight:800;
      line-height:1;
    }
    .hub-live-comment-like.is-liked{ color:#ec4899; }
    .hub-live-comment-like.is-liked i::before{ content:"\f004"; }
    .hub-live-comment-likes{
      margin-top:4px;
      color:var(--hub-live-subtle);
      font-size:11px;
      font-weight:700;
    }
    .hub-live-compose{
      padding:10px 12px 10px;
      border-top:0;
      background:var(--hub-live-compose-bg);
    }
    .hub-live-compose-row{
      display:flex;
      align-items:center;
      gap:8px;
    }
    .hub-live-compose-inputwrap{
      flex:1 1 auto;
      min-width:0;
      display:flex;
      align-items:center;
      gap:8px;
      min-height:40px;
      padding:0 10px 0 12px;
      border-radius:999px;
      background:var(--hub-live-compose-input);
      border:1px solid var(--hub-live-compose-input-border);
    }
    .hub-live-compose-inputwrap textarea{
      width:100%;
      min-height:24px;
      max-height:72px;
      border:0;
      background:transparent;
      color:var(--hub-live-compose-text);
      padding:2px 0 0;
      resize:none;
      font:inherit;
      outline:none;
      font-size:14px;
      line-height:1.35;
    }
    .hub-live-compose-inputwrap textarea::placeholder{
      color:var(--hub-live-compose-placeholder);
    }
    .hub-live-compose-tool{
      border:0;
      background:transparent;
      color:var(--hub-live-muted);
      width:25px;
      height:25px;
      display:grid;
      place-items:center;
      cursor:pointer;
      font-size:14px;
      flex:0 0 auto;
    }
    .hub-live-send-btn{
      width:32px;
      height:32px;
      border-radius:999px;
      border:0;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      font-size:16px;
      flex:0 0 auto;
      background:var(--hub-live-send-bg);
      color:var(--hub-live-send-text);
      box-shadow:none;
    }
    .hub-live-compose-feedback{
      min-height:14px;
      margin-top:6px;
      font-size:11px;
      font-weight:700;
      color:var(--hub-live-muted);
    }
    .hub-live-compose-feedback.is-error{ color:#f87171; }
    .hub-live-compose-dock{
      display:none;
      flex:0 0 auto;
      flex-direction:column;
      background:var(--hub-bg);
      max-height:34vh;
      padding:6px var(--hub-stage-inset-x) env(safe-area-inset-bottom);
    }
    .hub-live-compose-dock.is-visible{
      display:flex;
    }
    .hub-live-compose-dock.is-visible .hub-live-compose{
      border-radius:12px;
      border:1px solid var(--hub-live-border);
    }
    .hub-live-compose-dock.is-hidden{
      display:none !important;
    }
    .hub-shell.is-studio-tab .hub-live-compose-dock{
      display:none !important;
    }
    #hubPublic.is-public-watch{
      padding:0;
      gap:0;
      margin:0 var(--hub-stage-inset-x);
      width:auto;
      align-self:stretch;
      border-radius:12px;
      overflow:hidden;
      border:1px solid var(--hub-live-border);
      background:var(--hub-live-surface);
      color:var(--hub-live-text);
      display:flex;
      flex-direction:column;
      flex:1 1 auto;
      min-height:0;
    }
    .hub-public-watch-chat{
      display:none;
      flex:1 1 auto;
      min-height:0;
      flex-direction:column;
    }
    .hub-public-watch-chat.is-visible{
      display:flex;
    }
    .hub-public-watch-chat.is-hidden{
      display:none !important;
    }
    .hub-public-browse.is-hidden{
      display:none !important;
    }
    .hub-description-panel,
    .hub-settings-panel{
      display:flex;
      flex-direction:column;
      gap:10px;
      min-height:0;
    }
    .hub-description-title{
      font-size:14px;
      font-weight:800;
      line-height:1.3;
      color:var(--hub-text);
    }
    .hub-description-host{
      font-size:11px;
      color:var(--hub-muted);
      font-weight:700;
    }
    .hub-description-body{
      font-size:13px;
      line-height:1.45;
      color:var(--hub-text);
      white-space:pre-wrap;
    }
    .hub-description-body.is-hidden{
      display:none;
    }
    .hub-description-empty{
      color:var(--hub-muted);
      font-size:12px;
      line-height:1.45;
      padding:6px 0;
    }
    .hub-description-empty.is-hidden,
    #hubChatIdle.is-hidden{
      display:none !important;
    }
    .hub-comment-list{
      display:grid;
      gap:8px;
      min-height:0;
      overflow:auto;
      flex:1 1 auto;
    }
    .hub-comment-card{
      display:grid;
      grid-template-columns:24px minmax(0,1fr);
      gap:7px;
      align-items:flex-start;
    }
    .hub-comment-author{
      font-size:11px;
      font-weight:800;
      color:var(--hub-text);
    }
    .hub-comment-body{
      font-size:13px;
      line-height:1.4;
      color:var(--hub-text);
      margin-top:1px;
    }
    .hub-comment-meta{
      font-size:10px;
      color:var(--hub-muted);
      margin-top:3px;
      font-weight:700;
    }
    .hub-settings-item{
      display:grid;
      gap:4px;
      padding:8px 10px;
      border-radius:10px;
      background:var(--hub-pick-bg);
    }
    .hub-settings-toggle{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      font-size:12px;
      font-weight:700;
      color:var(--hub-text);
    }
    .hub-settings-note{
      font-size:11px;
      line-height:1.4;
      color:var(--hub-muted);
    }
    .hub-confirm{
      position:fixed;
      inset:0;
      z-index:40;
      display:none;
      align-items:center;
      justify-content:center;
      padding:16px;
      background:var(--hub-overlay);
      backdrop-filter:blur(4px);
    }
    .hub-confirm.is-open{ display:flex; }
    .hub-confirm-card{
      width:min(300px, 100%);
      border-radius:16px;
      background:var(--hub-confirm-card-bg);
      border:1px solid var(--hub-confirm-card-border);
      box-shadow:0 16px 40px var(--hub-toast-shadow);
      padding:18px 16px 16px;
      text-align:center;
    }
    .hub-confirm-icon{
      width:40px;
      height:40px;
      margin:0 auto 10px;
      border-radius:999px;
      display:grid;
      place-items:center;
      background:rgba(239,68,68,.14);
      color:#f87171;
      font-size:16px;
    }
    .hub-confirm-card h3{
      margin:0;
      font-size:16px;
      line-height:1.2;
      font-weight:800;
      color:var(--hub-text);
    }
    .hub-confirm-card p{
      margin:8px 0 0;
      font-size:12px;
      line-height:1.45;
      color:var(--hub-confirm-muted);
    }
    .hub-confirm-actions{
      margin-top:16px;
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:8px;
    }
    .hub-confirm-btn{
      min-height:38px;
      border-radius:10px;
      border:1px solid var(--hub-confirm-control-border);
      background:var(--hub-confirm-control-bg);
      color:var(--hub-text);
      font-size:13px;
      font-weight:800;
      cursor:pointer;
    }
    .hub-confirm-btn.is-danger{
      border-color:#ef4444;
      background:#ef4444;
      color:#fff;
    }
    .hub-confirm-btn:disabled{
      opacity:.55;
      cursor:wait;
    }
    .hub-toast{
      position:fixed;
      left:50%;
      bottom:16px;
      transform:translateX(-50%);
      z-index:50;
      padding:8px 12px;
      border-radius:10px;
      background:var(--hub-toast-bg);
      color:var(--hub-toast-text);
      font-size:12px;
      font-weight:700;
      box-shadow:0 8px 24px var(--hub-toast-shadow);
      max-width:calc(100% - 24px);
      text-align:center;
      border:1px solid var(--hub-border);
    }
  </style>
</head>
<body>
  <div class="hub-shell">
    <div class="hub-top">
      <div class="hub-brand">
        <div class="hub-logo">T</div>
        <div class="hub-brand-text">
          <strong><?= h($featured ? $featuredHost : 'Talentra Live') ?></strong>
          <span><?= $featured ? h($featuredTitle) : 'Discover live sessions' ?></span>
        </div>
      </div>
      <div class="hub-top-actions">
        <?php if ($canStudio): ?>
          <button type="button" class="hub-end-btn" id="hubEndBtn" aria-label="End live"<?= $ownLiveId > 0 ? '' : ' hidden' ?>>End</button>
          <button type="button" class="hub-studio-top-btn" id="hubTopStudioBtn" data-hub-tab="studio">Create Live</button>
        <?php endif; ?>
        <button type="button" class="hub-icon-btn" id="hubCloseBtn" aria-label="Close"><i class="fa fa-times"></i></button>
      </div>
    </div>

    <div class="hub-stage-wrap">
      <div class="hub-stage" id="hubStage">
        <?php if ($featured): ?>
          <?php
            $featuredSnapshotVersion = '';
            $featuredSnapshotPath = __DIR__ . '/storage/live_snapshots/' . (int)$featured['id'] . '.jpg';
            if (is_file($featuredSnapshotPath)) {
                $featuredSnapshotVersion = (string)(@md5_file($featuredSnapshotPath) ?: '');
            }
            $featuredVisibility = strtolower(trim((string)($featured['visibility'] ?? ($hubSurface === 'feed' ? 'friends' : 'public'))));
            $featuredHostDoor = strtolower(trim((string)($featured['host_door'] ?? '')));
            $featuredStudioSource = strtolower(trim((string)($featured['studio_source'] ?? '')));
          ?>
          <div class="hub-stage-placeholder">
            <i class="fa fa-video-camera"></i>
            <div><?= h($featuredTitle) ?></div>
            <button type="button" class="hub-live-pick" data-live-id="<?= (int)$featured['id'] ?>" data-owner-id="<?= (int)($featured['user_id'] ?? 0) ?>" data-is-owner="<?= ((int)($featured['user_id'] ?? 0) === $meId) ? '1' : '0' ?>" data-host="<?= h($featuredHost) ?>" data-title="<?= h($featuredTitle) ?>" data-snapshot-version="<?= h($featuredSnapshotVersion) ?>" data-visibility="<?= h($featuredVisibility) ?>" data-watch-panel="public" data-host-door="<?= h($featuredHostDoor) ?>" data-studio-source="<?= h($featuredStudioSource) ?>">
              <span class="hub-live-tag"><span class="dot"></span> LIVE</span>
              <span class="hub-live-pick-gap">Tap to watch</span>
            </button>
          </div>
        <?php else: ?>
          <div class="hub-stage-placeholder">
            <i class="fa fa-video-camera"></i>
            <div>No one is live right now</div>
            <?php if ($canStudio): ?>
              <div class="hub-stage-placeholder-sub">Open Live Studio to start broadcasting.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="hub-stage-meta">
          <span class="hub-live-tag"><span class="dot"></span> LIVE</span>
          <?php if ($featuredViews > 0): ?>
            <span class="hub-views"><?= h(number_format($featuredViews)) ?> watching</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="hub-tabs" role="tablist" aria-label="Live sections">
      <button type="button" class="is-active" data-hub-tab="chat">Host</button>
      <button type="button" data-hub-tab="description">Description</button>
      <button type="button" data-hub-tab="public"><?= h($hubBrowseTabLabel) ?></button>
      <?php if ($canStudio): ?>
      <button type="button" data-hub-tab="software">Streaming software</button>
      <?php endif; ?>
      <button type="button" data-hub-tab="settings">Settings</button>
      <button type="button" class="hub-tab-device" data-hub-tab="microphone" id="hubTabMic" aria-label="Microphone">
        <i class="fa fa-microphone" aria-hidden="true"></i> Microphone
      </button>
      <button type="button" class="hub-tab-device" data-hub-tab="camera" id="hubTabCamera" aria-label="Camera">
        <i class="fa fa-video-camera" aria-hidden="true"></i> Camera
      </button>
    </div>

    <div class="hub-body">
      <div class="hub-panel" id="hubChat" data-hub-panel="chat">
        <div id="hubChatIdle">
        <?php if ($chatLives): ?>
          <?php foreach ($chatLives as $row): ?>
            <?php
              $lid = (int)($row['id'] ?? 0);
              $title = trim((string)($row['title'] ?? 'Live session'));
              $host = trim((string)($row['name'] ?? $row['username'] ?? 'Host'));
              $views = (int)($row['viewer_count'] ?? 0);
              $visibility = strtolower(trim((string)($row['visibility'] ?? 'friends')));
            ?>
            <button type="button" class="hub-live-pick" data-live-id="<?= $lid ?>" data-owner-id="<?= (int)($row['user_id'] ?? 0) ?>" data-is-owner="<?= ((int)($row['user_id'] ?? 0) === $meId) ? '1' : '0' ?>" data-host="<?= h($host) ?>" data-title="<?= h($title) ?>" data-visibility="<?= h($visibility) ?>" data-watch-panel="chat">
              <span class="hub-msg-avatar"><?= h(strtoupper(substr($host, 0, 1))) ?></span>
              <span class="hub-msg-body">
                <span class="hub-msg-user"><?= h($host) ?><?= $views > 0 ? ' · ' . h((string)$views) . ' watching' : '' ?></span>
                <span class="hub-msg-text"><?= h($title) ?></span>
              </span>
            </button>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="hub-msg system">
            <div class="hub-msg-avatar">LV</div>
            <div class="hub-msg-body">
              <div class="hub-msg-user">Talentra Live</div>
              <div class="hub-msg-text"><?= $canStudio ? 'Start your live in Live Studio. Your broadcast appears here in Chat.' : 'Your live sessions appear here in Chat when you go live.' ?></div>
            </div>
          </div>
          <?php if ($canStudio): ?>
            <div class="hub-empty"><button type="button" class="hub-empty-link" data-hub-tab-switch="studio">Open Live Studio</button> to start your broadcast.</div>
          <?php endif; ?>
          <?php if (!$canStudio || $ownLiveId <= 0): ?>
          <div class="hub-empty"><?= $hubSurface === 'feed'
            ? 'Friends-only lives from your friends appear in the ' . h($hubBrowseTabLabel) . ' tab.'
            : 'Public rooms appear in the ' . h($hubBrowseTabLabel) . ' tab and on Public Live.' ?></div>
          <?php endif; ?>
        <?php endif; ?>
        </div>
        <div class="hub-chat-live is-hidden" id="hubChatLive">
          <div class="hub-live-side-stats">
            <div><strong id="hubChatReactionStat">0</strong> Reactions</div>
            <div><strong id="hubChatCommentStat">0</strong> Comments</div>
            <div><i class="fa fa-eye" aria-hidden="true"></i> <strong id="hubChatViewStat">0</strong> Watching</div>
          </div>
          <div class="hub-live-chat-panel">
            <div class="hub-live-comments-box" id="hubCommentsBox">
              <div class="hub-live-comments-list" id="hubCommentList">Comments will appear here when viewers join your live session.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="hub-panel is-hidden" id="hubPublic" data-hub-panel="public">
        <div class="hub-public-browse" id="hubPublicBrowse">
        <?php if ($browseLives): ?>
          <?php foreach ($browseLives as $row): ?>
            <?php
              $lid = (int)($row['id'] ?? 0);
              $title = trim((string)($row['title'] ?? 'Live session'));
              $host = trim((string)($row['name'] ?? $row['username'] ?? 'Host'));
              $views = (int)($row['viewer_count'] ?? 0);
              $visibility = strtolower(trim((string)($row['visibility'] ?? ($hubSurface === 'feed' ? 'friends' : 'public'))));
              $visibilityLabel = $visibility === 'friends' ? 'Friends' : ($visibility === 'public' ? 'Public' : '');
              $snapshotVersion = '';
              $snapshotPath = __DIR__ . '/storage/live_snapshots/' . $lid . '.jpg';
              if (is_file($snapshotPath)) {
                  $snapshotVersion = (string)(@md5_file($snapshotPath) ?: '');
              }
              $hostDoor = strtolower(trim((string)($row['host_door'] ?? '')));
              $studioSource = strtolower(trim((string)($row['studio_source'] ?? '')));
            ?>
            <button type="button" class="hub-live-pick" data-live-id="<?= $lid ?>" data-owner-id="<?= (int)($row['user_id'] ?? 0) ?>" data-is-owner="0" data-host="<?= h($host) ?>" data-title="<?= h($title) ?>" data-visibility="<?= h($visibility) ?>" data-watch-panel="public" data-snapshot-version="<?= h($snapshotVersion) ?>" data-host-door="<?= h($hostDoor) ?>" data-studio-source="<?= h($studioSource) ?>">
              <span class="hub-msg-avatar"><?= h(strtoupper(substr($host, 0, 1))) ?></span>
              <span class="hub-msg-body">
                <span class="hub-msg-user"><?= h($host) ?><?= $views > 0 ? ' · ' . h((string)$views) . ' watching' : '' ?><?= $visibilityLabel !== '' ? ' · ' . h($visibilityLabel) : '' ?></span>
                <span class="hub-msg-text"><?= h($title) ?></span>
              </span>
            </button>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="hub-msg system">
            <div class="hub-msg-avatar"><?= $hubSurface === 'feed' ? 'FR' : 'PL' ?></div>
            <div class="hub-msg-body">
              <div class="hub-msg-user"><?= h($hubBrowseTabLabel) ?> Live</div>
              <div class="hub-msg-text"><?= $hubSurface === 'feed'
                ? 'Friends-only rooms from your accepted friends appear here.'
                : 'Public rooms appear here and on Public Live.' ?></div>
            </div>
          </div>
          <div class="hub-empty"><?= $hubSurface === 'feed'
            ? 'No friends-only live rooms right now.'
            : 'No public live rooms right now.' ?></div>
        <?php endif; ?>
        </div>
        <div class="hub-public-watch-chat is-hidden" id="hubPublicWatchChat">
          <div class="hub-live-comments-box" id="hubPublicCommentsBox">
            <div class="hub-live-comments-list" id="hubPublicCommentList">Comments will appear here when viewers join the live session.</div>
          </div>
        </div>
      </div>

      <?php if ($canStudio): ?>
      <div class="hub-panel hub-panel-studio is-hidden" id="hubStudio" data-hub-panel="studio">
        <div class="hub-studio-loading" id="hubStudioLoading">Opening Live Studio...</div>
        <iframe
          id="hubStudioFrame"
          class="hub-studio-frame"
          title="Live Studio"
          allow="autoplay; fullscreen; picture-in-picture; camera; microphone"
          src="about:blank"
        ></iframe>
      </div>
      <?php endif; ?>

      <div class="hub-panel hub-description-panel is-hidden" id="hubDescription" data-hub-panel="description">
        <div class="hub-description-title" id="hubDescriptionTitle"><?= h($featuredTitle) ?></div>
        <div class="hub-description-host" id="hubDescriptionHost"><?= h($featuredHost) ?></div>
        <div class="hub-description-body" id="hubDescriptionBody"><?= $featuredDescription !== '' ? nl2br(h($featuredDescription)) : '' ?></div>
        <div class="hub-description-empty<?= $featuredDescription !== '' ? ' is-hidden' : '' ?>" id="hubDescriptionEmpty">No description yet for this live session.</div>
      </div>

      <div class="hub-panel hub-settings-panel is-hidden" id="hubSettings" data-hub-panel="settings">
        <div class="hub-settings-item">
          <label class="hub-settings-toggle" for="hubSettingsMicToggle">
            <span>Microphone on</span>
            <input type="checkbox" id="hubSettingsMicToggle" checked>
          </label>
          <div class="hub-settings-note">Mute or unmute the host microphone while live.</div>
        </div>
        <div class="hub-settings-item">
          <label class="hub-settings-toggle" for="hubSettingsCameraToggle">
            <span>Camera on</span>
            <input type="checkbox" id="hubSettingsCameraToggle" checked>
          </label>
          <div class="hub-settings-note">Hide or show the host camera without leaving the live.</div>
        </div>
        <div class="hub-settings-item">
          <div class="hub-settings-note">Open Live Studio for full device and quality controls.</div>
        </div>
      </div>

      <div class="hub-panel hub-settings-panel is-hidden" id="hubMicrophone" data-hub-panel="microphone">
        <div class="hub-settings-item">
          <label class="hub-settings-toggle" for="hubMicPanelToggle">
            <span>Microphone on</span>
            <input type="checkbox" id="hubMicPanelToggle" checked>
          </label>
          <div class="hub-settings-note">Turn your microphone on or off while you are live.</div>
        </div>
      </div>

      <div class="hub-panel hub-settings-panel is-hidden" id="hubCamera" data-hub-panel="camera">
        <div class="hub-settings-item">
          <label class="hub-settings-toggle" for="hubCameraPanelToggle">
            <span>Camera on</span>
            <input type="checkbox" id="hubCameraPanelToggle" checked>
          </label>
          <div class="hub-settings-note">Turn your camera on or off while you are live.</div>
        </div>
      </div>
    </div>

    <div class="hub-live-compose-dock is-hidden" id="hubLiveComposeDock">
      <div class="hub-live-compose" id="hubComposeLive">
        <div class="hub-live-compose-row">
          <div class="hub-live-compose-inputwrap">
            <textarea id="hubCommentInput" placeholder="Add comment..." rows="1"></textarea>
            <button type="button" class="hub-live-compose-tool" id="hubCommentMention" aria-label="Mention">@</button>
            <button type="button" class="hub-live-compose-tool" aria-label="Emoji"><i class="fa fa-smile-o" aria-hidden="true"></i></button>
          </div>
          <button type="button" class="hub-live-send-btn" id="hubCommentSend" aria-label="Send message">
            <i class="fa fa-arrow-up" aria-hidden="true"></i>
          </button>
        </div>
        <div class="hub-live-compose-feedback" id="hubComposeFeedback" aria-live="polite"></div>
      </div>
    </div>
  </div>

  <div class="hub-confirm" id="hubEndConfirm" aria-hidden="true">
    <div class="hub-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="hubEndConfirmTitle" aria-describedby="hubEndConfirmText">
      <div class="hub-confirm-icon" aria-hidden="true"><i class="fa fa-video-camera"></i></div>
      <h3 id="hubEndConfirmTitle">End live?</h3>
      <p id="hubEndConfirmText">Your broadcast will stop and viewers will be disconnected.</p>
      <div class="hub-confirm-actions">
        <button type="button" class="hub-confirm-btn" id="hubEndConfirmCancel">Cancel</button>
        <button type="button" class="hub-confirm-btn is-danger" id="hubEndConfirmOk">End live</button>
      </div>
    </div>
  </div>

  <script>
  (function(){
    var hubSnapshotTimer = null;
    var hubViewerSnapshotTimer = null;
    var hubViewerRoomTimer = null;
    var hubViewerSnapshotVersion = '';
    var hubSnapshotLiveActive = false;
    var hubSnapshotLoadToken = 0;
    var hubStageSyncTimer = null;
    var hubEmbedVideoCurrentTime = 0;
    var hubEmbedVideoLastAdvanceAt = 0;
    var hubViewerRoomFailCount = 0;
    var hubSnapshotBusy = false;
    var hubHostVideo = null;
    var hubWatchingLiveId = 0;
    var hubHostStreamPromise = null;
    var ownLiveId = <?= (int)$ownLiveId ?>;
    var hubDoorSide = <?= json_encode($hubDoor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var hubEndBusy = false;
    var hubLiveStartedAt = 0;
    var hubPublicPollTimer = null;
    var hubDoorPollTimer = null;
    var hubBrowseFingerprint = '';
    var hubPublicFingerprint = '';
    var hubFriendFingerprint = '';
    var hubChatFingerprint = '';
    var hubBrowseCache = [];
    var hubPublicCache = [];
    var hubFriendCache = [];
    var hubChatCache = [];
    var hubWatchPanelKey = 'chat';
    var hubBrowseRefreshSeq = 0;
    var hubHostLiveActive = false;
    var hubRoomReactionCounts = { love:0, like:0, fire:0, wow:0, clap:0 };
    var hubMicOn = true;
    var hubCameraOn = true;
    var hubBrowseTabLabel = <?= json_encode($hubBrowseTabLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var hubSurface = <?= json_encode($hubSurface, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var hubDoorBaseUrl = <?= json_encode($hubDoorBaseUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var hubOwnLiveMeta = {
      title: <?= json_encode($ownLiveId > 0 ? $featuredTitle : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      visibility: <?= json_encode(($ownLiveId > 0 && $featured) ? strtolower(trim((string)($featured['visibility'] ?? 'friends'))) : 'friends', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      host_door: <?= json_encode(($ownLiveId > 0 && $featured) ? strtolower(trim((string)($featured['host_door'] ?? ''))) : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      studio_source: <?= json_encode(($ownLiveId > 0 && $featured) ? strtolower(trim((string)($featured['studio_source'] ?? ''))) : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };

    function readHubBrandTitle(){
      var brandSpan = document.querySelector('.hub-brand-text span');
      return brandSpan ? String(brandSpan.textContent || '').trim() : '';
    }

    function parseHubJsonResponse(response){
      return response.text().then(function(raw){
        var data = null;
        try {
          data = raw ? JSON.parse(raw) : null;
        } catch (error) {
          var trimmed = String(raw || '').trim().toLowerCase();
          if (trimmed.indexOf('<!doctype') === 0 || trimmed.indexOf('<html') === 0 || trimmed.indexOf('<h') === 0) {
            throw new Error('Server returned HTML instead of JSON. Refresh and try again.');
          }
          throw new Error('Server returned an invalid response. Refresh and try again.');
        }
        return data;
      });
    }

    function isHubSoftwareHost(meta){
      meta = meta || {};
      return hubStudioSource === 'software'
        || String(meta.studio_source || '').toLowerCase() === 'software';
    }

    function buildOwnLiveBrowseRow(){
      if (ownLiveId <= 0) return null;
      var title = String(hubOwnLiveMeta.title || readHubBrandTitle() || 'Live session').trim() || 'Live session';
      return {
        id: ownLiveId,
        title: title,
        host: 'You',
        user_id: hubMeId,
        viewer_count: 0,
        visibility: String(hubOwnLiveMeta.visibility || 'friends').toLowerCase(),
        is_owner: true
      };
    }

    function filterOutOwnLiveFromBrowseRows(rows){
      rows = Array.isArray(rows) ? rows.slice() : [];
      return rows.filter(function(row){
        var liveId = parseInt((row && row.id) || '0', 10) || 0;
        var ownerId = parseInt((row && row.user_id) || '0', 10) || 0;
        if (ownLiveId > 0 && liveId === ownLiveId) return false;
        if (ownerId === hubMeId || !!row.is_owner) return false;
        return true;
      });
    }

    function filterHubChatRows(rows){
      rows = Array.isArray(rows) ? rows.slice() : [];
      return rows.filter(function(row){
        var isOwner = !!row.is_owner || parseInt((row && row.user_id) || '0', 10) === hubMeId;
        if (!isOwner) return false;
        return canHostChatInThisDoor(row);
      });
    }

    function filterHubFriendRows(rows){
      rows = Array.isArray(rows) ? rows.slice() : [];
      return rows.filter(function(row){
        var isOwner = !!row.is_owner || parseInt((row && row.user_id) || '0', 10) === hubMeId;
        if (isOwner) return false;
        return String(row.visibility || 'friends').toLowerCase() === 'friends';
      });
    }

    function filterHubPublicRows(rows){
      rows = Array.isArray(rows) ? rows.slice() : [];
      return rows.filter(function(row){
        var isOwner = !!row.is_owner || parseInt((row && row.user_id) || '0', 10) === hubMeId;
        if (isOwner) return false;
        return String(row.visibility || 'public').toLowerCase() === 'public';
      });
    }

    function mergeOwnLiveIntoBrowseRows(rows){
      return filterOutOwnLiveFromBrowseRows(rows);
    }

    function collectBrowseRowsFromDom(){
      var panel = document.getElementById('hubPublicBrowse');
      if (!panel) return [];
      var rows = [];
      panel.querySelectorAll('.hub-live-pick[data-live-id]').forEach(function(btn){
        var liveId = parseInt(btn.getAttribute('data-live-id') || '0', 10) || 0;
        if (liveId <= 0) return;
        rows.push({
          id: liveId,
          title: String(btn.getAttribute('data-title') || 'Live session'),
          host: String(btn.getAttribute('data-host') || 'Host'),
          user_id: parseInt(btn.getAttribute('data-owner-id') || '0', 10) || 0,
          viewer_count: 0,
          visibility: String(btn.getAttribute('data-visibility') || (hubSurface === 'feed' ? 'friends' : 'public')).toLowerCase(),
          is_owner: btn.getAttribute('data-is-owner') === '1'
        });
      });
      return rows;
    }

    function mergeWatchingLiveIntoBrowseRows(rows){
      rows = Array.isArray(rows) ? rows.slice() : [];
      var watchingId = parseInt(hubWatchingLiveId || '0', 10) || 0;
      if (watchingId <= 0 || watchingId === ownLiveId || hubWatchPanelKey !== 'public') return rows;
      var hasWatching = rows.some(function(row){
        return parseInt((row && row.id) || '0', 10) === watchingId;
      });
      if (hasWatching) return rows;
      var cached = null;
      var caches = hubSurface === 'feed' ? hubFriendCache : hubPublicCache;
      caches.some(function(row){
        if (parseInt((row && row.id) || '0', 10) === watchingId) {
          cached = row;
          return true;
        }
        return false;
      });
      if (!cached) {
        var panel = document.getElementById('hubPublicBrowse');
        if (panel) {
          var btn = panel.querySelector('.hub-live-pick[data-live-id="' + watchingId + '"]');
          if (btn) {
            cached = {
              id: watchingId,
              title: String(btn.getAttribute('data-title') || 'Live session'),
              host: String(btn.getAttribute('data-host') || 'Host'),
              user_id: parseInt(btn.getAttribute('data-owner-id') || '0', 10) || 0,
              viewer_count: 0,
              visibility: String(btn.getAttribute('data-visibility') || (hubSurface === 'feed' ? 'friends' : 'public')).toLowerCase(),
              is_owner: false,
              snapshot_version: String(btn.getAttribute('data-snapshot-version') || '')
            };
          }
        }
      }
      if (cached) {
        rows.unshift(cached);
      }
      return rows;
    }

    function stabilizeHubFriendRows(rows){
      rows = filterHubFriendRows(Array.isArray(rows) ? rows : []);
      rows = mergeWatchingLiveIntoBrowseRows(rows);
      if (rows.length) {
        hubFriendCache = rows.slice();
        return rows;
      }
      if (hubFriendCache.length) {
        return filterHubFriendRows(hubFriendCache.slice());
      }
      return filterHubFriendRows(collectBrowseRowsFromDom());
    }

    function stabilizeHubPublicRows(rows){
      rows = filterHubPublicRows(Array.isArray(rows) ? rows : []);
      rows = mergeWatchingLiveIntoBrowseRows(rows);
      if (rows.length) {
        hubPublicCache = rows.slice();
        return rows;
      }
      if (hubPublicCache.length) {
        return filterHubPublicRows(hubPublicCache.slice());
      }
      return filterHubPublicRows(collectBrowseRowsFromDom());
    }

    function stabilizeHubChatRows(rows){
      rows = filterHubChatRows(Array.isArray(rows) ? rows : []);
      if (rows.length) {
        hubChatCache = rows.slice();
        return rows;
      }
      if (hubChatCache.length) {
        return filterHubChatRows(hubChatCache.slice());
      }
      return [];
    }

    function stabilizeHubBrowseRows(rows){
      return hubSurface === 'feed' ? stabilizeHubFriendRows(rows) : stabilizeHubPublicRows(rows);
    }

    function syncHubEndButton(){
      var btn = document.getElementById('hubEndBtn');
      if (!btn) return;
      btn.hidden = ownLiveId <= 0 || !canHostChatInThisDoor();
    }

    function setHubEndConfirmOpen(isOpen){
      var modal = document.getElementById('hubEndConfirm');
      if (!modal) return;
      modal.classList.toggle('is-open', !!isOpen);
      modal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }

    function openHubEndConfirm(){
      if (hubEndBusy) return;
      var liveId = hubWatchingLiveId > 0 ? hubWatchingLiveId : ownLiveId;
      if (liveId <= 0) return;
      setHubEndConfirmOpen(true);
    }

    async function performEndHubLive(){
      if (hubEndBusy) return;
      var liveId = hubWatchingLiveId > 0 ? hubWatchingLiveId : ownLiveId;
      if (liveId <= 0) return;

      hubEndBusy = true;
      var btn = document.getElementById('hubEndBtn');
      var okBtn = document.getElementById('hubEndConfirmOk');
      var cancelBtn = document.getElementById('hubEndConfirmCancel');
      if (btn) btn.disabled = true;
      if (okBtn) okBtn.disabled = true;
      if (cancelBtn) cancelBtn.disabled = true;

      try {
        var formData = new FormData();
        formData.append('action', 'end_live');
        if (ownLiveId > 0) {
          formData.append('live_id', String(ownLiveId));
        }
        var response = await fetch('ajax/live_studio_host_action.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
        var data = await parseHubJsonResponse(response);
        if (!response.ok) {
          throw new Error(data && data.error ? data.error : 'Unable to end live');
        }
        if (!data || !data.ok) {
          throw new Error(data && data.error ? data.error : 'Unable to end live');
        }
        setHubEndConfirmOpen(false);
        stopHubHostSnapshotLoop();
        stopHubHostStream();
        hubWatchingLiveId = 0;
        ownLiveId = 0;
        hubHostLiveActive = false;
        setSessionHostLiveDoor('');
        clearSessionHostLiveDoors();
        window.location.href = hubDoorBaseUrl;
      } catch (error) {
        setHubEndConfirmOpen(false);
        hubEndBusy = false;
        if (btn) btn.disabled = false;
        if (okBtn) okBtn.disabled = false;
        if (cancelBtn) cancelBtn.disabled = false;
        showHubToast(error && error.message ? error.message : 'Unable to end live');
      }
    }

    function showHubToast(message){
      var toast = document.getElementById('hubToast');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'hubToast';
        toast.className = 'hub-toast';
        document.body.appendChild(toast);
      }
      toast.textContent = String(message || '');
      toast.hidden = false;
      window.clearTimeout(showHubToast._timer);
      showHubToast._timer = window.setTimeout(function(){
        toast.hidden = true;
      }, 3200);
    }

    async function endHubLive(){
      openHubEndConfirm();
    }

    function isHubInRightDoor(){
      try {
        return !!(window.frameElement && window.frameElement.id === 'ttLiveRightDoorFrame');
      } catch (e) {
        return false;
      }
    }

    function isHubInLeftDoor(){
      try {
        return !!(window.frameElement && window.frameElement.id === 'ttLiveDoorFrame');
      } catch (e) {
        return false;
      }
    }

    function getSessionHostStageDoor(){
      try {
        return String(sessionStorage.getItem('msbHostLiveStageDoor') || sessionStorage.getItem('msbHostLiveDoor') || '').toLowerCase();
      } catch (e) {
        return '';
      }
    }

    function getSessionHostChatDoor(){
      try {
        return String(sessionStorage.getItem('msbHostLiveChatDoor') || '').toLowerCase();
      } catch (e) {
        return '';
      }
    }

    function setSessionHostStageDoor(door){
      try {
        door = String(door || '').toLowerCase();
        if (!door) {
          sessionStorage.removeItem('msbHostLiveStageDoor');
          sessionStorage.removeItem('msbHostLiveDoor');
          return;
        }
        sessionStorage.setItem('msbHostLiveStageDoor', door);
        sessionStorage.setItem('msbHostLiveDoor', door);
      } catch (e) {}
    }

    function setSessionHostChatDoor(door){
      try {
        door = String(door || '').toLowerCase();
        if (!door) sessionStorage.removeItem('msbHostLiveChatDoor');
        else sessionStorage.setItem('msbHostLiveChatDoor', door);
      } catch (e) {}
    }

    function clearSessionHostLiveDoors(){
      try {
        sessionStorage.removeItem('msbHostLiveStageDoor');
        sessionStorage.removeItem('msbHostLiveChatDoor');
        sessionStorage.removeItem('msbHostLiveDoor');
      } catch (e) {}
    }

    function getSessionHostLiveDoor(){
      return getSessionHostStageDoor();
    }

    function setSessionHostLiveDoor(door){
      door = String(door || '').toLowerCase();
      setSessionHostStageDoor(door);
      if (door) setSessionHostChatDoor(door);
      else setSessionHostChatDoor('');
    }

    var hubMeId = <?= (int)$meId ?>;

    function getHubDoorSide(){
      if (isHubInRightDoor()) return 'right';
      if (isHubInLeftDoor()) return 'left';
      return String(hubDoorSide || '').toLowerCase();
    }

    function resolveOwnerDoor(meta){
      meta = meta || hubOwnLiveMeta || {};
      var hostDoor = String(meta.host_door || meta.hostDoor || '').toLowerCase();
      var studioSource = String(meta.studio_source || meta.studioSource || '').toLowerCase();
      if (hostDoor === 'right' || (studioSource === 'software' && hostDoor !== 'left')) return 'right';
      if (hostDoor === 'left') return 'left';
      return hostDoor || 'left';
    }

    function resolveEventOwnerDoor(payload){
      payload = payload || {};
      var hostDoor = String(payload.hostLiveDoor || '').toLowerCase();
      var studioSource = String(payload.studioSource || payload.studio_source || '').toLowerCase();
      if (hostDoor === 'right' || studioSource === 'software') return 'right';
      if (hostDoor === 'left') return 'left';
      return '';
    }

    function canHostChatInThisDoor(meta){
      var myDoor = getHubDoorSide();
      if (!myDoor) return true;
      return resolveOwnerDoor(meta) === myDoor;
    }

    function refreshHubHostLiveActive(){
      hubHostLiveActive = ownLiveId > 0 && canHostChatInThisDoor(hubOwnLiveMeta);
    }

    function isHostLiveOwnedByOtherDoor(){
      if (ownLiveId <= 0 && !hubHostLiveActive) return false;
      return !canHostChatInThisDoor();
    }

    function shouldSkipHostLiveStageInThisDoor(payload){
      payload = payload || {};
      var eventDoor = resolveEventOwnerDoor(payload);
      var myDoor = getHubDoorSide();
      if (myDoor && eventDoor && eventDoor !== myDoor) return true;
      if (myDoor && !eventDoor) {
        var studioSource = String(payload.studioSource || payload.studio_source || '').toLowerCase();
        if (studioSource === 'software' && myDoor === 'left') return true;
        if (myDoor === 'right' && studioSource !== 'software' && String(payload.hostLiveDoor || '').toLowerCase() === 'left') return true;
      }
      return false;
    }

    function shouldSkipHostLiveInThisDoor(payload){
      return shouldSkipHostLiveStageInThisDoor(payload) && !canHostChatInThisDoor();
    }

    function syncHostLiveDoorsFromMeta(meta){
      meta = meta || {};
      if (ownLiveId <= 0 || !canHostChatInThisDoor(meta)) return;
      hubOwnLiveMeta.host_door = String(meta.host_door || meta.hostDoor || hubOwnLiveMeta.host_door || '').toLowerCase();
      hubOwnLiveMeta.studio_source = String(meta.studio_source || meta.studioSource || hubOwnLiveMeta.studio_source || '').toLowerCase();
      refreshHubHostLiveActive();
    }

    function syncHubChatIdleDoorVisibility(){
      if (canHostChatInThisDoor()) return;
      var chatIdle = document.getElementById('hubChatIdle');
      if (!chatIdle) return;
      chatIdle.querySelectorAll('[data-live-id][data-is-owner="1"]').forEach(function(pick){
        pick.remove();
      });
      ensureHubChatIdlePlaceholder();
    }

    function ensureHubChatIdlePlaceholder(){
      var chatIdle = document.getElementById('hubChatIdle');
      if (!chatIdle) return;
      if (chatIdle.querySelector('[data-live-id], .hub-msg.system, .hub-empty')) return;
      var ownerDoor = resolveOwnerDoor(hubOwnLiveMeta);
      var liveOnOtherDoor = ownLiveId > 0 && ((ownerDoor === 'right' && isHubInLeftDoor()) || (ownerDoor === 'left' && isHubInRightDoor()));
      var hint = liveOnOtherDoor
        ? (isHubInLeftDoor()
          ? 'Your streaming software live is on the right sidebar. Start a separate live here in Live Studio when you are ready.'
          : 'Your streaming software live is on the left sidebar. Chat for that session stays on the left.')
        : (hubCanStudio
          ? 'Start your live in Live Studio. Your broadcast appears here in Chat.'
          : 'Your live sessions appear here in Chat when you go live.');
      chatIdle.innerHTML = ''
        + '<div class="hub-msg system">'
        + '<div class="hub-msg-avatar">LV</div>'
        + '<div class="hub-msg-body">'
        + '<div class="hub-msg-user">Talentra Live</div>'
        + '<div class="hub-msg-text">' + escapeHubText(hint) + '</div>'
        + '</div></div>'
        + (hubCanStudio
          ? '<div class="hub-empty"><button type="button" class="hub-empty-link" data-hub-tab-switch="studio">Open Live Studio</button> to start your broadcast.</div>'
          : '');
    }

    function openLiveStudioInHub(){
      if (!canUseHubStudioTabs()) return;
      hubStudioSource = '';
      var studioPanel = document.getElementById('hubStudio');
      var studioLoading = document.getElementById('hubStudioLoading');
      if (studioPanel) studioPanel.classList.remove('is-frame-ready');
      if (studioLoading) studioLoading.textContent = 'Opening Live Studio...';
      switchHubTab('studio');
    }

    function openFriendBrowseForDoor(door){
      door = String(door || 'left').toLowerCase();
      if (door === 'right') {
        if (isHubInRightDoor()) {
          switchHubTab('public');
          refreshHubBrowseLives(true);
          return;
        }
        try {
          var rightUrl = new URL('live_door_hub.php', window.location.href);
          rightUrl.searchParams.set('hub_door', 'right');
          rightUrl.searchParams.set('hub_tab', 'public');
          rightUrl.searchParams.set('hub_surface', hubSurface);
          if (hubCanStudio) rightUrl.searchParams.set('can_studio', '1');
          (window.top || window.parent).postMessage({ type: 'msb-live-right-door-open', url: rightUrl.href }, '*');
        } catch (error) {}
        return;
      }
      if (isHubInLeftDoor()) {
        switchHubTab('public');
        refreshHubBrowseLives(true);
        return;
      }
      try {
        var leftUrl = new URL('live_door_hub.php', window.location.href);
        leftUrl.searchParams.set('hub_door', 'left');
        leftUrl.searchParams.set('hub_tab', 'public');
        leftUrl.searchParams.set('hub_surface', hubSurface);
        if (hubCanStudio) leftUrl.searchParams.set('can_studio', '1');
        (window.top || window.parent).postMessage({ type: 'msb-live-door-open', url: leftUrl.href }, '*');
      } catch (error2) {}
    }

    function openLiveStudioBrowseEntry(){
      if (!isHubHostLive()) {
        openFriendBrowseForDoor('left');
        return;
      }
      openLiveStudioInHub();
    }

    function openLiveSoftwareBrowseEntry(){
      if (!isHubHostLive()) {
        openFriendBrowseForDoor('right');
        return;
      }
      if (isHubInLeftDoor()) {
        openLiveRightDoorForSoftware();
        return;
      }
      if (!canUseHubStudioTabs()) return;
      hubStudioSource = 'software';
      switchHubTab('software');
    }

    function markHubStudioFrameReady(){
      var frame = document.getElementById('hubStudioFrame');
      var studioPanel = document.getElementById('hubStudio');
      var studioLoading = document.getElementById('hubStudioLoading');
      if (frame) frame.classList.add('is-ready');
      if (studioPanel) studioPanel.classList.add('is-frame-ready');
      if (studioLoading) studioLoading.textContent = '';
    }

    function buildHubStudioFrameSrc(){
      var nextSrc = 'live_studio.php?door_panel=1&hub_embed=1';
      if (hubStudioSource) {
        nextSrc += '&source=' + encodeURIComponent(hubStudioSource);
      }
      try {
        if (window.frameElement && window.frameElement.id === 'ttLiveRightDoorFrame') {
          nextSrc += '&right_door=1';
        } else if (isHubInLeftDoor()) {
          nextSrc += '&left_door=1';
        }
      } catch (error) {}
      return nextSrc;
    }

    function ensureStudioFrame(forceReload){
      var frame = document.getElementById('hubStudioFrame');
      var studioPanel = document.getElementById('hubStudio');
      var studioLoading = document.getElementById('hubStudioLoading');
      if (!frame) return;
      var nextSrc = buildHubStudioFrameSrc();
      var currentSrc = String(frame.getAttribute('src') || '').trim();
      var normalizedCurrent = currentSrc.replace(/([&?])_ts=\d+/g, '$1').replace(/[?&]$/, '');
      var wantsSoftware = hubStudioSource === 'software';
      var currentHasSoftware = /[?&]source=software(?:&|$)/.test(normalizedCurrent);
      var sourceMatches = wantsSoftware ? currentHasSoftware : !currentHasSoftware;
      var doorMatches = (isHubInLeftDoor() && /[?&]left_door=1(?:&|$)/.test(normalizedCurrent))
        || (isHubInRightDoor() && /[?&]right_door=1(?:&|$)/.test(normalizedCurrent))
        || (!isHubInLeftDoor() && !isHubInRightDoor());
      if (!forceReload && currentSrc && currentSrc !== 'about:blank' && normalizedCurrent.indexOf('live_studio.php') !== -1 && sourceMatches && doorMatches) {
        markHubStudioFrameReady();
        return;
      }
      if (studioPanel) studioPanel.classList.remove('is-frame-ready');
      if (studioLoading) studioLoading.textContent = 'Opening Live Studio...';
      frame.classList.remove('is-ready');
      var loadUrl = nextSrc + (nextSrc.indexOf('?') >= 0 ? '&' : '?') + '_ts=' + String(Date.now());
      var loadTimer = window.setTimeout(function(){
        markHubStudioFrameReady();
      }, 3500);
      frame.addEventListener('load', function onStudioFrameLoad(){
        frame.removeEventListener('load', onStudioFrameLoad);
        window.clearTimeout(loadTimer);
        markHubStudioFrameReady();
      });
      frame.setAttribute('src', loadUrl);
    }

    syncHostLiveDoorsFromMeta(hubOwnLiveMeta);
    refreshHubHostLiveActive();
    syncHubChatIdleDoorVisibility();

    function readHubLivePickMeta(btn){
      btn = btn || null;
      return {
        host: btn ? (btn.getAttribute('data-host') || '') : '',
        title: btn ? (btn.getAttribute('data-title') || '') : '',
        snapshot_version: btn ? (btn.getAttribute('data-snapshot-version') || '') : '',
        visibility: btn ? String(btn.getAttribute('data-visibility') || '').toLowerCase() : '',
        host_door: btn ? String(btn.getAttribute('data-host-door') || '').toLowerCase() : '',
        studio_source: btn ? String(btn.getAttribute('data-studio-source') || '').toLowerCase() : ''
      };
    }

    function isSoftwareRightDoorLive(meta){
      return resolveFriendWatchDoor(meta) === 'right';
    }

    function resolveFriendWatchDoor(meta){
      meta = meta || {};
      var hostDoor = String(meta.host_door || meta.hostLiveDoor || '').toLowerCase();
      var studioSource = String(meta.studio_source || meta.studioSource || '').toLowerCase();
      if (hostDoor === 'right' || (studioSource === 'software' && hostDoor !== 'left')) {
        return 'right';
      }
      if (hostDoor === 'left') {
        return 'left';
      }
      return hostDoor !== '' ? hostDoor : 'left';
    }

    function buildLiveLeftDoorWatchUrl(liveId, meta){
      liveId = parseInt(liveId || '0', 10) || 0;
      meta = meta || {};
      var url;
      try {
        url = new URL('live_door_hub.php', window.location.href);
      } catch (e) {
        url = new URL('live_door_hub.php', window.location.href);
      }
      url.searchParams.set('hub_surface', hubSurface);
      if (hubCanStudio) {
        url.searchParams.set('can_studio', '1');
      }
      url.searchParams.set('hub_door', 'left');
      url.searchParams.set('hub_tab', 'public');
      if (liveId > 0) {
        url.searchParams.set('watch_live', String(liveId));
      }
      if (meta.visibility) {
        url.searchParams.set('watch_visibility', String(meta.visibility));
      }
      if (meta.host) {
        url.searchParams.set('watch_host', String(meta.host));
      }
      if (meta.title) {
        url.searchParams.set('watch_title', String(meta.title));
      }
      if (meta.snapshot_version) {
        url.searchParams.set('watch_snapshot', String(meta.snapshot_version));
      }
      if (meta.host_door) {
        url.searchParams.set('watch_host_door', String(meta.host_door));
      }
      if (meta.studio_source) {
        url.searchParams.set('watch_studio_source', String(meta.studio_source));
      }
      return url.href;
    }

    function buildLiveRightDoorWatchUrl(liveId, meta){
      liveId = parseInt(liveId || '0', 10) || 0;
      meta = meta || {};
      var url;
      try {
        url = new URL('live_door_hub.php', window.location.href);
      } catch (e) {
        url = new URL('live_door_hub.php', window.location.href);
      }
      url.searchParams.set('hub_surface', hubSurface);
      url.searchParams.set('can_studio', '1');
      url.searchParams.set('hub_door', 'right');
      url.searchParams.set('hub_tab', 'public');
      if (liveId > 0) {
        url.searchParams.set('watch_live', String(liveId));
      }
      if (meta.visibility) {
        url.searchParams.set('watch_visibility', String(meta.visibility));
      }
      if (meta.host) {
        url.searchParams.set('watch_host', String(meta.host));
      }
      if (meta.title) {
        url.searchParams.set('watch_title', String(meta.title));
      }
      if (meta.snapshot_version) {
        url.searchParams.set('watch_snapshot', String(meta.snapshot_version));
      }
      if (meta.host_door) {
        url.searchParams.set('watch_host_door', String(meta.host_door));
      }
      if (meta.studio_source) {
        url.searchParams.set('watch_studio_source', String(meta.studio_source));
      }
      return url.href;
    }

    function openLiveRightDoorWithUrl(rightUrl){
      rightUrl = String(rightUrl || '').trim();
      if (!rightUrl) return false;
      var hosts = [];
      try {
        if (window.top && window.top !== window) hosts.push(window.top);
      } catch (e) {}
      try {
        if (window.parent && window.parent !== window) hosts.push(window.parent);
      } catch (e2) {}
      for (var i = 0; i < hosts.length; i += 1) {
        try {
          var hostWin = hosts[i];
          if (hostWin && hostWin.TTLiveRight && typeof hostWin.TTLiveRight.open === 'function') {
            hostWin.TTLiveRight.open(rightUrl);
            return true;
          }
        } catch (e3) {}
      }
      try {
        (window.top || window.parent).postMessage({ type: 'msb-live-right-door-open', url: rightUrl }, '*');
        return true;
      } catch (e4) {}
      return false;
    }

    function openLiveRightDoorForWatch(liveId, meta){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0) return;
      meta = meta || {};
      if (isHubInRightDoor()) {
        embedLiveInHub(liveId, meta, 'public');
        return;
      }
      openLiveRightDoorWithUrl(buildLiveRightDoorWatchUrl(liveId, meta));
    }

    function openLiveLeftDoorWithUrl(leftUrl){
      leftUrl = String(leftUrl || '').trim();
      if (!leftUrl) return false;
      var hosts = [];
      try {
        if (window.top && window.top !== window) hosts.push(window.top);
      } catch (e) {}
      try {
        if (window.parent && window.parent !== window) hosts.push(window.parent);
      } catch (e2) {}
      for (var i = 0; i < hosts.length; i += 1) {
        try {
          var hostWin = hosts[i];
          if (hostWin && hostWin.TTLive && typeof hostWin.TTLive.open === 'function') {
            hostWin.TTLive.open(leftUrl);
            return true;
          }
        } catch (e3) {}
      }
      try {
        (window.top || window.parent).postMessage({ type: 'msb-live-door-open', url: leftUrl }, '*');
        return true;
      } catch (e4) {}
      return false;
    }

    function openLiveLeftDoorForWatch(liveId, meta){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0) return;
      meta = meta || {};
      if (isHubInLeftDoor()) {
        embedLiveInHub(liveId, meta, 'public');
        return;
      }
      openLiveLeftDoorWithUrl(buildLiveLeftDoorWatchUrl(liveId, meta));
    }

    function routeFriendWatch(liveId, meta, panelKey){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0) return;
      meta = meta || {};
      panelKey = panelKey || 'public';
      if (resolveFriendWatchDoor(meta) === 'right') {
        openLiveRightDoorForWatch(liveId, meta);
        return;
      }
      openLiveLeftDoorForWatch(liveId, meta);
    }

    function buildLiveRightDoorSoftwareUrl(){
      var url;
      try {
        url = new URL('live_door_hub.php', window.location.href);
      } catch (e) {
        url = new URL('live_door_hub.php', window.location.href);
      }
      url.searchParams.set('hub_surface', hubSurface);
      url.searchParams.set('can_studio', '1');
      url.searchParams.set('hub_door', 'right');
      url.searchParams.set('hub_tab', 'software');
      return url.href;
    }

    function openLiveRightDoorForSoftware(){
      if (isHubInLeftDoor()) {
        activateHubTab('chat');
        setHubPanel('chat');
        setHubShellMode('chat');
      }
      var opened = openLiveRightDoorWithUrl(buildLiveRightDoorSoftwareUrl());
      if (isHubInLeftDoor() && opened) {
        showHubToast('Streaming software opened in the right sidebar.');
      }
    }

    function getLiveDoorHost(kind){
      var hosts = [];
      try {
        if (window.top) hosts.push(window.top);
      } catch (e) {}
      try {
        if (window.parent && window.parent !== window) hosts.push(window.parent);
      } catch (e) {}
      for (var i = 0; i < hosts.length; i += 1) {
        try {
          var hostWin = hosts[i];
          if (kind === 'right' && hostWin.TTLiveRight && typeof hostWin.TTLiveRight.close === 'function') {
            return hostWin;
          }
          if (kind === 'left' && hostWin.TTLive && typeof hostWin.TTLive.close === 'function') {
            return hostWin;
          }
        } catch (e) {}
      }
      return null;
    }

    function closeDoor(){
      var inRight = isHubInRightDoor();
      var inLeft = isHubInLeftDoor();
      var topWin = null;
      try { topWin = window.top; } catch (e) {}

      if (inRight) {
        try {
          var rightHost = getLiveDoorHost('right');
          if (rightHost) rightHost.TTLiveRight.close();
        } catch (e) {}
        try {
          (topWin || window.parent).postMessage({ type: 'msb-live-right-door-close' }, '*');
        } catch (e2) {}
        return;
      }

      if (inLeft) {
        try {
          var leftHost = getLiveDoorHost('left');
          if (leftHost) leftHost.TTLive.close();
        } catch (e) {}
        try {
          (topWin || window.parent).postMessage({ type: 'msb-live-door-close' }, '*');
        } catch (e2) {}
        return;
      }

      try {
        var rightHostFallback = getLiveDoorHost('right');
        if (rightHostFallback) {
          rightHostFallback.TTLiveRight.close();
          return;
        }
      } catch (e) {}
      try {
        var leftHostFallback = getLiveDoorHost('left');
        if (leftHostFallback) {
          leftHostFallback.TTLive.close();
          return;
        }
      } catch (e) {}
      try { (topWin || window.parent).postMessage({ type: 'msb-live-right-door-close' }, '*'); } catch (e3) {}
      try { (topWin || window.parent).postMessage({ type: 'msb-live-door-close' }, '*'); } catch (e4) {}
    }

    function openDoorUrl(url){
      url = String(url || '').trim();
      if (!url) return;
      try {
        if (window.parent && window.parent.TTLive && typeof window.parent.TTLive.open === 'function') {
          window.parent.TTLive.open(url);
          return;
        }
      } catch(e) {}
      try { window.parent.postMessage({ type:'msb-live-door-open', url:url }, '*'); } catch(e2) {}
      window.location.href = url;
    }

    function activateHubTab(key){
      document.querySelectorAll('[data-hub-tab]').forEach(function(tab){
        tab.classList.toggle('is-active', tab.getAttribute('data-hub-tab') === key);
      });
    }

    function setHubPanel(key){
      key = String(key || 'chat');
      document.querySelectorAll('[data-hub-panel]').forEach(function(panel){
        panel.classList.toggle('is-hidden', panel.getAttribute('data-hub-panel') !== key);
      });
    }

    function setHubShellMode(key){
      var shell = document.querySelector('.hub-shell');
      if (!shell) return;
      shell.classList.toggle('is-studio-tab', key === 'studio' || key === 'software');
    }

    var hubStudioSource = '';
    var hubCanStudio = <?= $canStudio ? 'true' : 'false' ?>;

    function canUseHubStudioTabs(){
      return !!hubCanStudio;
    }

    function readHubUrlParams(){
      var params = new URLSearchParams(window.location.search);
      return {
        tab: String(params.get('hub_tab') || '').trim(),
        studioSource: String(params.get('studio_source') || '').trim(),
        watchLive: parseInt(params.get('watch_live') || '0', 10) || 0,
        watchVisibility: String(params.get('watch_visibility') || '').trim().toLowerCase(),
        watchHost: String(params.get('watch_host') || '').trim(),
        watchTitle: String(params.get('watch_title') || '').trim(),
        watchSnapshot: String(params.get('watch_snapshot') || '').trim(),
        watchHostDoor: String(params.get('watch_host_door') || '').trim().toLowerCase(),
        watchStudioSource: String(params.get('watch_studio_source') || '').trim().toLowerCase()
      };
    }

    function setHubStudioSource(source){
      source = String(source || '').trim();
      if (!source) return;
      hubStudioSource = source;
      if (source === 'software') {
        if (!isHubHostLive()) {
          openFriendBrowseForDoor('right');
          return;
        }
        if (isHubInLeftDoor()) {
          openLiveRightDoorForSoftware();
          return;
        }
        if (!canUseHubStudioTabs()) return;
        switchHubTab('software');
        return;
      }
      if (!canUseHubStudioTabs()) return;
      switchHubTab('studio');
      ensureStudioFrame(true);
    }

    function stopHubDoorPoll(){
      if (hubDoorPollTimer) {
        window.clearInterval(hubDoorPollTimer);
        hubDoorPollTimer = null;
      }
    }

    function startHubDoorPoll(){
      stopHubDoorPoll();
      hubDoorPollTimer = window.setInterval(function(){
        refreshHubBrowseLives();
      }, 4000);
    }

    function stopHubPublicPoll(){
      if (hubPublicPollTimer) {
        window.clearInterval(hubPublicPollTimer);
        hubPublicPollTimer = null;
      }
    }

    function startHubPublicPoll(){
      stopHubPublicPoll();
      hubPublicPollTimer = window.setInterval(function(){
        refreshHubBrowseLives();
      }, 4000);
    }

    function renderHubChatLives(rows){
      var chatIdle = document.getElementById('hubChatIdle');
      if (!chatIdle) return;
      rows = filterHubChatRows(Array.isArray(rows) ? rows : []);
      if (!rows.length) return;
      chatIdle.innerHTML = '';
      rows.forEach(function(row){
        var liveId = parseInt(row.id || '0', 10) || 0;
        if (liveId <= 0) return;
        var title = String(row.title || 'Live session');
        var host = String(row.host || 'Host');
        var views = parseInt(row.viewer_count || '0', 10) || 0;
        var isOwner = !!row.is_owner;
        var visibility = String(row.visibility || (isOwner ? hubOwnLiveMeta.visibility : 'friends')).toLowerCase();
        var pick = document.createElement('button');
        pick.type = 'button';
        pick.className = 'hub-live-pick';
        pick.setAttribute('data-live-id', String(liveId));
        pick.setAttribute('data-owner-id', String(parseInt(row.user_id || '0', 10) || 0));
        pick.setAttribute('data-is-owner', isOwner ? '1' : '0');
        pick.setAttribute('data-host', host);
        pick.setAttribute('data-title', title);
        pick.setAttribute('data-visibility', visibility);
        pick.setAttribute('data-watch-panel', 'chat');
        pick.innerHTML = '<span class="hub-msg-avatar">' + escapeHubText(host.charAt(0).toUpperCase()) + '</span>'
          + '<span class="hub-msg-body">'
          + '<span class="hub-msg-user">' + escapeHubText(isOwner ? 'You' : host)
          + (views > 0 ? ' · ' + escapeHubText(String(views)) + ' watching' : '')
          + '</span>'
          + '<span class="hub-msg-text">' + escapeHubText(title) + '</span>'
          + '</span>';
        chatIdle.appendChild(pick);
        bindHubLivePick(pick);
      });
    }

    function shouldShowHubLiveChat(){
      if (isHubHostLive()) return true;
      return hubWatchingLiveId > 0;
    }

    function shouldShowHubComposeDock(){
      if (hubWatchingLiveId <= 0) return false;
      var tab = getActiveHubTabKey();
      if (isHubHostLive()) return tab === 'chat';
      return tab === hubWatchPanelKey;
    }

    function syncHubComposeDock(){
      var dock = document.getElementById('hubLiveComposeDock');
      var show = shouldShowHubComposeDock();
      if (dock) {
        dock.classList.toggle('is-visible', show);
        dock.classList.toggle('is-hidden', !show);
      }
    }

    function syncHubPublicWatchUi(){
      var tab = getActiveHubTabKey();
      var publicPanel = document.getElementById('hubPublic');
      var browse = document.getElementById('hubPublicBrowse');
      var watchChat = document.getElementById('hubPublicWatchChat');
      var watchingFriend = hubWatchingLiveId > 0 && !isHubHostLive();
      var showPublicWatch = watchingFriend && tab === 'public' && hubWatchPanelKey === 'public';
      if (publicPanel) publicPanel.classList.toggle('is-public-watch', showPublicWatch);
      if (browse) browse.classList.toggle('is-hidden', showPublicWatch);
      if (watchChat) {
        watchChat.classList.toggle('is-visible', showPublicWatch);
        watchChat.classList.toggle('is-hidden', !showPublicWatch);
      }
    }

    function requestHubStudioRoomData(){
      var frame = document.getElementById('hubStudioFrame');
      if (!frame || !frame.contentWindow) return;
      try { frame.contentWindow.postMessage({ type: 'msb-hub-request-room-data' }, '*'); } catch (e) {}
    }

    function applyHubHostStudioRoomData(rawPayload){
      var payload = normalizeHubStudioPayload(rawPayload || {});
      if (!payload.ok) return;
      var live = payload.live || {};
      var liveId = parseInt(live.id || '0', 10) || 0;
      var status = String(live.status || '').toLowerCase();
      if (liveId > 0 && status === 'live') {
        ownLiveId = liveId;
        syncHostLiveDoorsFromMeta(live);
        if (!canHostChatInThisDoor(live)) {
          syncHubEndButton();
          return;
        }
        hubHostLiveActive = true;
        hubOwnLiveMeta.title = String(live.title || hubOwnLiveMeta.title || '');
        if (hubWatchingLiveId <= 0 || hubWatchingLiveId === ownLiveId) {
          hubWatchingLiveId = liveId;
        }
        syncHubEndButton();
        syncHubLiveSessionUi(true);
        renderHubRoomPayload(payload);
        if (!hubViewerRoomTimer) {
          startHubViewerWatchLoop(liveId, null);
        }
        return;
      }
      if (status && status !== 'live') {
        hubHostLiveActive = false;
        if (hubWatchingLiveId === ownLiveId) {
          syncHubLiveSessionUi(false);
        }
      }
    }

    function getActiveHubTabKey(){
      var active = document.querySelector('[data-hub-tab].is-active');
      return active ? String(active.getAttribute('data-hub-tab') || '').trim() : '';
    }

    function switchHubTab(key){
      key = String(key || 'chat');
      if (key === 'react' || key === 'watching') key = 'chat';
      if (key === 'software') {
        if (isHubInLeftDoor()) {
          if (!isHubHostLive()) {
            openFriendBrowseForDoor('right');
            return;
          }
          openLiveRightDoorForSoftware();
          return;
        }
        if (!isHubHostLive()) {
          openFriendBrowseForDoor('right');
          return;
        }
        if (!canUseHubStudioTabs()) return;
      }
      if (key === 'studio' && !canUseHubStudioTabs()) return;
      var body = document.querySelector('.hub-body');
      if (body) body.classList.add('is-switching');
      activateHubTab(key);
      setHubShellMode(key);
      if (key === 'studio') {
        hubStudioSource = '';
        ensureStudioFrame(true);
        setHubPanel('studio');
      } else if (key === 'software') {
        hubStudioSource = 'software';
        ensureStudioFrame(true);
        setHubPanel('studio');
        stopHubPublicPoll();
      } else if (key === 'chat' || key === 'public' || key === 'description' || key === 'settings' || key === 'microphone' || key === 'camera') {
        setHubPanel(key === 'public' ? 'public' : key);
        if (key === 'public') {
          startHubPublicPoll();
          refreshHubBrowseLives(true).then(function(){
            if (!isHubHostLive()) {
              maybeAutoStartHubWatch();
            }
          });
        } else {
          stopHubPublicPoll();
          if (hubWatchingLiveId > 0 && !isHubHostLive() && key !== hubWatchPanelKey) {
            stopFriendWatchInHub(false);
          }
        }
      }
      if (key === 'chat' && hubHostLiveActive && ownLiveId > 0) {
        if (hubWatchingLiveId <= 0) hubWatchingLiveId = ownLiveId;
        requestHubStudioRoomData();
        fetchHubViewerRoom(ownLiveId, null).catch(function(){});
      }
      syncHubLiveSessionUi(shouldShowHubLiveChat());
      syncHubComposeDock();
      syncHubPublicWatchUi();
      window.requestAnimationFrame(function(){
        if (body) body.classList.remove('is-switching');
      });
    }

    function postHubStudioMessage(payload){
      var frame = document.getElementById('hubStudioFrame');
      if (!frame || !frame.contentWindow) return;
      try { frame.contentWindow.postMessage(payload, '*'); } catch (e) {}
    }

    function syncHubLiveSessionUi(active){
      var tab = getActiveHubTabKey();
      var chatPanel = document.getElementById('hubChat');
      var chatIdle = document.getElementById('hubChatIdle');
      var chatLive = document.getElementById('hubChatLive');
      var settingsMic = document.getElementById('hubSettingsMicToggle');
      var settingsCamera = document.getElementById('hubSettingsCameraToggle');
      var micPanelToggle = document.getElementById('hubMicPanelToggle');
      var cameraPanelToggle = document.getElementById('hubCameraPanelToggle');
      var tabMic = document.getElementById('hubTabMic');
      var tabCamera = document.getElementById('hubTabCamera');
      var showChatLive = !!active && tab === 'chat' && isHubHostLive();
      if (chatPanel) chatPanel.classList.toggle('is-live-session', showChatLive);
      if (chatIdle) chatIdle.classList.toggle('is-hidden', showChatLive);
      if (chatLive) {
        chatLive.classList.toggle('is-visible', showChatLive);
        chatLive.classList.toggle('is-hidden', !showChatLive);
      }
      var hostLive = isHubHostLive();
      if (tabMic) tabMic.disabled = !hostLive;
      if (tabCamera) tabCamera.disabled = !hostLive;
      if (settingsMic) settingsMic.disabled = !hostLive;
      if (settingsCamera) settingsCamera.disabled = !hostLive;
      if (micPanelToggle) micPanelToggle.disabled = !hostLive;
      if (cameraPanelToggle) cameraPanelToggle.disabled = !hostLive;
      if (showChatLive) syncHubTabControls();
      syncHubComposeDock();
      syncHubPublicWatchUi();
    }

    function setHubComposeFeedback(message, tone){
      var node = document.getElementById('hubComposeFeedback');
      if (!node) return;
      node.textContent = String(message || '');
      node.classList.toggle('is-error', tone === 'error');
    }

    function normalizeHubStudioPayload(data){
      data = data || {};
      var live = data.live || {};
      return {
        ok: !!data.ok,
        can_view: true,
        live: {
          id: parseInt(live.id || ownLiveId || '0', 10) || ownLiveId,
          owner_id: hubMeId,
          title: String(live.title || hubOwnLiveMeta.title || ''),
          description: String(live.description || ''),
          status: String(live.status || 'live'),
          visibility: String(live.visibility || hubOwnLiveMeta.visibility || 'friends'),
          viewer_count: parseInt(live.viewer_count || '0', 10) || 0,
          owner_name: 'You',
          snapshot_version: String(live.snapshot_version || '')
        },
        comments: Array.isArray(data.comments) ? data.comments : [],
        comment_total: parseInt(data.comment_total || '0', 10) || 0,
        reaction_counts: data.reaction_counts || { love:0, like:0, fire:0, wow:0, clap:0 },
        reaction_users: Array.isArray(data.reaction_users) ? data.reaction_users : [],
        my_reaction: String(data.my_reaction || '')
      };
    }

    function hubCommentTone(name){
      var raw = String(name || '');
      var total = 0;
      for (var i = 0; i < raw.length; i += 1) total += raw.charCodeAt(i);
      return total % 360;
    }

    function hubInitials(name){
      var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
      if (!parts.length) return 'U';
      var first = parts[0].charAt(0) || '';
      var last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
      return (first + last).toUpperCase() || 'U';
    }

    function hubReactionTotal(counts){
      counts = counts || hubRoomReactionCounts || {};
      var total = 0;
      ['love','like','fire','wow','clap'].forEach(function(key){
        total += parseInt(counts[key] || '0', 10) || 0;
      });
      return total;
    }

    function renderHubComments(comments){
      var box = document.getElementById('hubCommentsBox');
      var list = document.getElementById('hubCommentList');
      var publicBox = document.getElementById('hubPublicCommentsBox');
      var publicList = document.getElementById('hubPublicCommentList');
      comments = Array.isArray(comments) ? comments : [];
      var emptyHost = 'Comments will appear here when viewers join your live session.';
      var emptyViewer = 'Comments will appear here when viewers join the live session.';
      var html = '';
      if (!comments.length) {
        if (box) box.classList.remove('has-comments');
        if (publicBox) publicBox.classList.remove('has-comments');
        if (list) list.textContent = isHubHostLive() ? emptyHost : emptyViewer;
        if (publicList) publicList.textContent = emptyViewer;
        return;
      }
      if (box) box.classList.add('has-comments');
      if (publicBox) publicBox.classList.add('has-comments');
      html = comments.map(function(item){
        var author = escapeHubText(item.author || 'User');
        var body = escapeHubText(item.body || '');
        var isSelf = parseInt(item.user_id || '0', 10) === hubMeId;
        var initials = escapeHubText(hubInitials(item.author || 'User'));
        var tone = hubCommentTone(item.author || 'User');
        var meta = escapeHubText(String(item.created_at_label || item.created_at || '').trim() || 'Now');
        var likeCount = parseInt(item.like_count || '0', 10) || 0;
        var likedByLabel = escapeHubText(item.liked_by_label || '');
        return '<div class="hub-live-comment-card' + (isSelf ? ' is-self' : '') + '" data-comment-id="' + (parseInt(item.id || '0', 10) || 0) + '" data-comment-author="' + author + '">'
          + '<div class="hub-live-comment-avatar" style="background:linear-gradient(135deg, hsl(' + tone + ' 80% 62%), hsl(' + ((tone + 38) % 360) + ' 78% 54%));">' + initials + '</div>'
          + '<div class="hub-live-comment-main">'
          + '<div class="hub-live-comment-author">' + author + (isSelf ? '<span class="hub-live-comment-team">Team</span>' : '') + '</div>'
          + '<div class="hub-live-comment-body">' + body + '</div>'
          + '<div class="hub-live-comment-meta"><span>' + meta + '</span>'
          + '<button type="button" class="hub-live-comment-reply">Reply</button>'
          + '<button type="button" class="hub-live-comment-like' + (item.liked_by_me ? ' is-liked' : '') + '" aria-label="Like comment" title="' + likedByLabel + '">'
          + '<i class="fa fa-heart-o" aria-hidden="true"></i>'
          + (likeCount > 0 ? '<span class="hub-live-comment-like-count">' + likeCount + '</span>' : '')
          + '</button></div>'
          + (likedByLabel ? '<div class="hub-live-comment-likes">' + likedByLabel + '</div>' : '')
          + '</div></div>';
      }).join('');
      if (list) {
        var stickChat = (list.scrollHeight - list.scrollTop - list.clientHeight) <= 48;
        list.innerHTML = html;
        if (stickChat) list.scrollTop = list.scrollHeight;
      }
      if (publicList) {
        var stickPublic = (publicList.scrollHeight - publicList.scrollTop - publicList.clientHeight) <= 48;
        publicList.innerHTML = html;
        if (stickPublic) publicList.scrollTop = publicList.scrollHeight;
      }
    }

    function syncHubDescriptionPanel(live){
      live = live || {};
      var title = document.getElementById('hubDescriptionTitle');
      var host = document.getElementById('hubDescriptionHost');
      var body = document.getElementById('hubDescriptionBody');
      var empty = document.getElementById('hubDescriptionEmpty');
      var description = String(live.description || '').trim();
      if (title) title.textContent = String(live.title || readHubBrandTitle() || 'Live session');
      if (host) host.textContent = String(live.owner_name || 'Host');
      if (body) body.textContent = description;
      if (empty) empty.classList.toggle('is-hidden', description !== '');
      if (body) body.classList.toggle('is-hidden', description === '');
    }

    function renderHubRoomPayload(data){
      if (!data || !data.ok) return;
      var live = data.live || {};
      var comments = Array.isArray(data.comments) ? data.comments : [];
      hubRoomReactionCounts = data.reaction_counts || hubRoomReactionCounts;
      var reactionTotal = hubReactionTotal(hubRoomReactionCounts);
      var commentCount = parseInt(data.comment_total || comments.length || '0', 10) || comments.length;
      var viewCount = parseInt(live.viewer_count || '0', 10) || 0;
      var chatTabCount = document.getElementById('hubChatTabCount');
      if (chatTabCount) chatTabCount.textContent = String(commentCount);
      var chatReactionStat = document.getElementById('hubChatReactionStat');
      var chatCommentStat = document.getElementById('hubChatCommentStat');
      var chatViewStat = document.getElementById('hubChatViewStat');
      if (chatReactionStat) chatReactionStat.textContent = String(reactionTotal);
      if (chatCommentStat) chatCommentStat.textContent = String(commentCount);
      if (chatViewStat) chatViewStat.textContent = String(viewCount);
      var stageViews = document.querySelector('.hub-stage-meta .hub-views');
      if (stageViews) {
        stageViews.textContent = viewCount > 0 ? (String(viewCount) + ' watching') : '';
        stageViews.style.display = viewCount > 0 ? '' : 'none';
      }
      renderHubComments(comments);
      syncHubDescriptionPanel(live);
    }

    function syncHubTabControls(){
      var stream = window.__msbHubHostStream;
      var hasAudio = !!(stream && stream.getAudioTracks && stream.getAudioTracks().some(function(track){
        return track.readyState === 'live';
      }));
      var hasVideo = !!(stream && stream.getVideoTracks && stream.getVideoTracks().some(function(track){
        return track.readyState === 'live';
      }));
      if (stream && hasAudio) {
        var audioTrack = stream.getAudioTracks().find(function(track){ return track.readyState === 'live'; });
        if (audioTrack) hubMicOn = !!audioTrack.enabled;
      }
      if (stream && hasVideo) {
        var videoTrack = stream.getVideoTracks().find(function(track){ return track.readyState === 'live'; });
        if (videoTrack) hubCameraOn = !!videoTrack.enabled;
      }
      var tabMic = document.getElementById('hubTabMic');
      var tabCamera = document.getElementById('hubTabCamera');
      var settingsMic = document.getElementById('hubSettingsMicToggle');
      var settingsCamera = document.getElementById('hubSettingsCameraToggle');
      var micPanelToggle = document.getElementById('hubMicPanelToggle');
      var cameraPanelToggle = document.getElementById('hubCameraPanelToggle');
      var micActive = isHubHostLive() && hubMicOn && hasAudio;
      var cameraActive = isHubHostLive() && hubCameraOn && hasVideo;
      if (tabMic) {
        var micIcon = tabMic.querySelector('i');
        tabMic.setAttribute('aria-label', micActive ? 'Microphone on' : 'Microphone off');
        if (micIcon) micIcon.className = micActive ? 'fa fa-microphone' : 'fa fa-microphone has-off-slash';
      }
      if (tabCamera) {
        var cameraIcon = tabCamera.querySelector('i');
        tabCamera.setAttribute('aria-label', cameraActive ? 'Camera on' : 'Camera off');
        if (cameraIcon) cameraIcon.className = cameraActive ? 'fa fa-video-camera' : 'fa fa-video-camera has-off-slash';
      }
      if (settingsMic) settingsMic.checked = hubMicOn;
      if (settingsCamera) settingsCamera.checked = hubCameraOn;
      if (micPanelToggle) micPanelToggle.checked = hubMicOn;
      if (cameraPanelToggle) cameraPanelToggle.checked = hubCameraOn;
      var hostVideo = document.querySelector('#hubStage .hub-host-video');
      if (hostVideo) hostVideo.style.opacity = cameraActive ? '1' : '0.12';
    }

    function setHubMicEnabled(enabled){
      if (!isHubHostLive()) return;
      hubMicOn = !!enabled;
      var stream = window.__msbHubHostStream;
      if (stream && stream.getAudioTracks) {
        stream.getAudioTracks().forEach(function(track){
          if (track.readyState === 'live') track.enabled = hubMicOn;
        });
      }
      postHubStudioMessage({ type: 'msb-hub-device-control', device: 'mic', enabled: hubMicOn });
      syncHubTabControls();
    }

    function persistHubHostCameraState(enabled){
      if (!isHubHostLive() || ownLiveId <= 0) {
        return Promise.resolve(null);
      }
      var formData = new FormData();
      formData.append('action', 'set_camera_enabled');
      formData.append('enabled', enabled ? '1' : '0');
      return fetch('ajax/live_studio_room_action.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      }).then(function(response){
        return parseHubJsonResponse(response);
      }).catch(function(){
        return null;
      });
    }

    function setHubCameraEnabled(enabled){
      if (!isHubHostLive()) return;
      hubCameraOn = !!enabled;
      var stream = window.__msbHubHostStream;
      if (stream && stream.getVideoTracks) {
        stream.getVideoTracks().forEach(function(track){
          if (track.readyState === 'live') track.enabled = hubCameraOn;
        });
      }
      postHubStudioMessage({ type: 'msb-hub-device-control', device: 'camera', enabled: hubCameraOn });
      persistHubHostCameraState(hubCameraOn);
      syncHubTabControls();
    }

    function isHubHostLive(){
      return ownLiveId > 0 && hubHostLiveActive && canHostChatInThisDoor();
    }

    function sendHubComment(){
      if (hubWatchingLiveId <= 0) return;
      var input = document.getElementById('hubCommentInput');
      if (!input) return;
      var body = String(input.value || '').trim();
      if (!body) {
        setHubComposeFeedback('Type a comment before sending.', 'error');
        return;
      }
      var formData = new FormData();
      formData.append('action', 'send_comment');
      formData.append('comment_body', body);
      var url = 'ajax/live_watch_room.php';
      if (isHubHostLive()) {
        url = 'ajax/live_studio_room_action.php';
      } else {
        formData.append('live_id', String(hubWatchingLiveId));
      }
      fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      }).then(function(response){
        return parseHubJsonResponse(response).then(function(data){
          if (!response.ok) {
            throw new Error((data && data.error) ? data.error : 'Unable to send comment');
          }
          return data;
        });
      }).then(function(data){
        if (!data || !data.ok) {
          throw new Error((data && data.error) ? data.error : 'Unable to send comment');
        }
        input.value = '';
        setHubComposeFeedback('');
        renderHubRoomPayload(isHubHostLive() ? normalizeHubStudioPayload(data) : data);
      }).catch(function(error){
        setHubComposeFeedback((error && error.message) ? error.message : 'Unable to send comment', 'error');
      });
    }

    function toggleHubCommentLike(commentId){
      commentId = parseInt(commentId || '0', 10) || 0;
      if (commentId <= 0 || hubWatchingLiveId <= 0) return;
      var formData = new FormData();
      formData.append('action', 'toggle_comment_like');
      formData.append('comment_id', String(commentId));
      var url = 'ajax/live_watch_room.php';
      if (isHubHostLive()) {
        url = 'ajax/live_studio_room_action.php';
      } else {
        formData.append('live_id', String(hubWatchingLiveId));
      }
      fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      }).then(function(response){
        return parseHubJsonResponse(response);
      }).then(function(data){
        if (!data || !data.ok) return;
        renderHubRoomPayload(isHubHostLive() ? normalizeHubStudioPayload(data) : data);
      }).catch(function(){});
    }

    function escapeHubText(value){
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function resolveHubPanelKey(el){
      var panel = el && el.closest ? el.closest('[data-hub-panel]') : null;
      var key = panel ? String(panel.getAttribute('data-hub-panel') || '').trim() : '';
      return key === 'public' || key === 'chat' || key === 'studio' ? key : 'chat';
    }

    function focusHubPanel(key){
      key = String(key || 'chat');
      if (key === 'studio') {
        openLiveStudioInHub();
        return;
      }
      activateHubTab(key);
      setHubPanel(key);
      setHubShellMode(key);
    }

    function bindHubLivePick(btn){
      if (!btn || btn.getAttribute('data-hub-pick-bound') === '1') return;
      btn.setAttribute('data-hub-pick-bound', '1');
      btn.addEventListener('click', function(){
        var isOwner = btn.getAttribute('data-is-owner') === '1';
        var visibility = String(btn.getAttribute('data-visibility') || '').toLowerCase();
        var watchPanel = String(btn.getAttribute('data-watch-panel') || '').toLowerCase();
        if (!watchPanel) {
          watchPanel = isOwner ? 'chat' : 'public';
        }
        openLive(btn.getAttribute('data-live-id'), readHubLivePickMeta(btn), isOwner, watchPanel);
      });
    }

    function stopFriendWatchInHub(leaveRoom){
      var liveId = hubWatchingLiveId;
      stopHubViewerWatch(leaveRoom !== false);
      hubWatchingLiveId = 0;
      hubWatchPanelKey = 'chat';
      hubViewerSnapshotVersion = '';
      var stage = document.getElementById('hubStage');
      var stageWrap = document.querySelector('.hub-stage-wrap');
      var stageMeta = document.querySelector('.hub-stage-meta');
      if (stage) {
        stage.classList.remove('is-watching', 'has-webrtc-video', 'use-snapshot-stage', 'has-snapshot-stage', 'hub-friend-watch');
        stage.innerHTML = '';
      }
      if (stageWrap) stageWrap.classList.remove('is-watching');
      if (stageMeta) stageMeta.classList.remove('is-hidden');
      syncHubEndButton();
      syncHubLiveSessionUi(false);
      renderHubRoomPayload({ ok:true, live:{}, comments:[], comment_total:0, reaction_counts:{ love:0, like:0, fire:0, wow:0, clap:0 }, reaction_users:[], my_reaction:'' });
      if (leaveRoom !== false && liveId > 0) {
        refreshHubBrowseLives(true);
      }
    }

    function maybeAutoStartHubWatch(){
      if (hubWatchingLiveId > 0) return;
      if (ownLiveId > 0 || isHubHostLive()) return;
      var stage = document.getElementById('hubStage');
      if (stage && stage.classList.contains('is-watching')) return;
      if (getActiveHubTabKey() !== 'public') return;
      var panel = document.getElementById('hubPublicBrowse');
      if (!panel) return;
      var picks = panel.querySelectorAll('.hub-live-pick[data-live-id]');
      if (picks.length !== 1) return;
      var btn = picks[0];
      if (btn.getAttribute('data-is-owner') === '1') return;
      var meta = readHubLivePickMeta(btn);
      openLive(btn.getAttribute('data-live-id'), meta, false, 'public');
    }

    function ensureOwnLiveInPublicList(liveId, title, visibility){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0) return;
      ownLiveId = liveId;
      hubOwnLiveMeta.title = String(title || hubOwnLiveMeta.title || '').trim();
      if (visibility) {
        hubOwnLiveMeta.visibility = String(visibility).toLowerCase();
      }
      ensureOwnLiveInChatList(liveId, title);
      refreshHubBrowseLives(true);
    }

    function ensureOwnLiveInBrowseList(liveId, title, visibility){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0) return;
      var vis = String(visibility || hubOwnLiveMeta.visibility || '').toLowerCase();
      if (vis !== 'public' && !(vis === 'friends' && hubSurface === 'feed')) return;
      var browsePanel = document.getElementById('hubPublicBrowse');
      if (!browsePanel) return;
      if (browsePanel.querySelector('[data-live-id="' + liveId + '"]')) return;

      hubOwnLiveMeta.title = String(title || hubOwnLiveMeta.title || 'Live session').trim() || 'Live session';
      if (visibility) {
        hubOwnLiveMeta.visibility = String(visibility).toLowerCase();
      }

      var pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'hub-live-pick';
      pick.setAttribute('data-live-id', String(liveId));
      pick.setAttribute('data-owner-id', String(hubMeId));
      pick.setAttribute('data-is-owner', '1');
      pick.setAttribute('data-host', 'You');
      pick.setAttribute('data-title', hubOwnLiveMeta.title);
      pick.innerHTML = '<span class="hub-msg-avatar">Y</span><span class="hub-msg-body">'
        + '<span class="hub-msg-user">You · Private room</span>'
        + '<span class="hub-msg-text">' + escapeHubText(hubOwnLiveMeta.title) + '</span></span>';

      var visibilityLabel = hubOwnLiveMeta.visibility === 'public'
        ? 'Public'
        : (hubOwnLiveMeta.visibility === 'friends' ? 'Friends' : 'Private');
      pick.querySelector('.hub-msg-user').textContent = 'You · ' + visibilityLabel;

      if (browsePanel.querySelector('.hub-msg.system') || browsePanel.querySelector('.hub-empty')) {
        browsePanel.innerHTML = '';
      }
      browsePanel.insertBefore(pick, browsePanel.firstChild);
      bindHubLivePick(pick);
    }

    function renderHubBrowseEmpty(){
      var browsePanel = document.getElementById('hubPublicBrowse');
      if (!browsePanel) return;
      if (browsePanel.querySelector('.hub-live-pick')) {
        return;
      }
      browsePanel.innerHTML = ''
        + '<div class="hub-msg system">'
        + '<div class="hub-msg-avatar">' + (hubSurface === 'feed' ? 'FR' : 'PL') + '</div>'
        + '<div class="hub-msg-body">'
        + '<div class="hub-msg-user">' + escapeHubText(hubBrowseTabLabel) + ' Live</div>'
        + '<div class="hub-msg-text">' + escapeHubText(hubSurface === 'feed'
          ? 'Friends-only rooms from your accepted friends appear here.'
          : 'Public rooms appear here and on Public Live.') + '</div>'
        + '</div></div>'
        + '<div class="hub-empty">' + escapeHubText(hubSurface === 'feed'
          ? 'No friends-only live rooms right now.'
          : 'No public live rooms right now.') + '</div>';
    }

    function renderHubBrowseLives(rows){
      var browsePanel = document.getElementById('hubPublicBrowse');
      if (!browsePanel) return;
      rows = stabilizeHubBrowseRows(rows);
      if (!rows.length) {
        if (browsePanel.querySelector('.hub-live-pick')) {
          return;
        }
        renderHubBrowseEmpty();
        return;
      }
      browsePanel.innerHTML = '';
      rows.forEach(function(row){
        var liveId = parseInt(row.id || '0', 10) || 0;
        if (liveId <= 0) return;
        var isOwner = !!row.is_owner || parseInt(row.user_id || '0', 10) === hubMeId;
        if (isOwner) return;
        var title = String(row.title || 'Live session');
        var host = String(row.host || 'Host');
        var views = parseInt(row.viewer_count || '0', 10) || 0;
        var visibility = String(row.visibility || (hubSurface === 'feed' ? 'friends' : 'public')).toLowerCase();
        if (hubSurface === 'feed') {
          if (visibility !== 'friends') return;
        } else if (visibility !== 'public') {
          return;
        }
        var myDoor = getHubDoorSide();
        if (myDoor && resolveFriendWatchDoor(row) !== myDoor) {
          return;
        }
        var visibilityLabel = hubSurface === 'feed' ? 'Friends' : 'Public';
        var pick = document.createElement('button');
        pick.type = 'button';
        pick.className = 'hub-live-pick';
        pick.setAttribute('data-live-id', String(liveId));
        pick.setAttribute('data-owner-id', String(parseInt(row.user_id || '0', 10) || 0));
        pick.setAttribute('data-is-owner', '0');
        pick.setAttribute('data-host', host);
        pick.setAttribute('data-title', title);
        pick.setAttribute('data-visibility', visibility);
        pick.setAttribute('data-watch-panel', 'public');
        pick.setAttribute('data-snapshot-version', String(row.snapshot_version || ''));
        pick.setAttribute('data-host-door', String(row.host_door || ''));
        pick.setAttribute('data-studio-source', String(row.studio_source || ''));
        pick.innerHTML = '<span class="hub-msg-avatar">' + escapeHubText(host.charAt(0).toUpperCase()) + '</span>'
          + '<span class="hub-msg-body">'
          + '<span class="hub-msg-user">' + escapeHubText(host)
          + (views > 0 ? ' · ' + escapeHubText(String(views)) + ' watching' : '')
          + (visibilityLabel ? ' · ' + escapeHubText(visibilityLabel) : '')
          + '</span>'
          + '<span class="hub-msg-text">' + escapeHubText(title) + '</span>'
          + '</span>';
        browsePanel.appendChild(pick);
        bindHubLivePick(pick);
      });
      if (!isHubHostLive()) {
        maybeAutoStartHubWatch();
      }
    }

    async function refreshHubBrowseLives(force){
      var seq = ++hubBrowseRefreshSeq;
      try {
        var browseUrl = new URL('ajax/live_door_browse.php', window.location.href);
        browseUrl.searchParams.set('hub_surface', hubSurface);
        if (getHubDoorSide()) {
          browseUrl.searchParams.set('hub_door', getHubDoorSide());
        }
        browseUrl.searchParams.set('t', String(Date.now()));
        var response = await fetch(browseUrl.toString(), {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store'
        });
        if (seq !== hubBrowseRefreshSeq) return;
        if (!response.ok) return;
        var data = await parseHubJsonResponse(response);
        if (seq !== hubBrowseRefreshSeq) return;
        if (!data || !data.ok) return;
        if (data.own_live_id) {
          ownLiveId = parseInt(data.own_live_id || '0', 10) || ownLiveId;
          syncHubEndButton();
        }
        var browseLives = stabilizeHubBrowseRows(data.browse_lives || (hubSurface === 'feed' ? (data.friend_lives || []) : (data.public_lives || [])));
        var chatLives = stabilizeHubChatRows(data.chat_lives || []);
        var nextFingerprint = String(data.fingerprint || '');
        var nextBrowseFingerprint = String(data.browse_fingerprint || browseLives.map(function(row){ return row.id; }).join(','));
        var nextChatFingerprint = String(data.chat_fingerprint || chatLives.map(function(row){ return row.id; }).join(','));
        var browseChanged = force || nextBrowseFingerprint !== hubBrowseFingerprint;
        var chatChanged = force || nextChatFingerprint !== hubChatFingerprint;
        hubBrowseFingerprint = nextBrowseFingerprint;
        if (browseChanged) {
          renderHubBrowseLives(browseLives);
        }
        if (chatChanged) {
          hubChatFingerprint = nextChatFingerprint;
          renderHubChatLives(chatLives);
        }
        if (getActiveHubTabKey() === 'public' && !isHubHostLive()) {
          maybeAutoStartHubWatch();
        }
        var featured = data.featured || browseLives[0] || chatLives[0] || null;
        var ownRow = null;
        chatLives.some(function(row){
          if (row && row.is_owner && canHostChatInThisDoor(row)) {
            ownRow = row;
            ownLiveId = parseInt(row.id || '0', 10) || ownLiveId;
            hubOwnLiveMeta.title = String(row.title || hubOwnLiveMeta.title || '');
            hubOwnLiveMeta.visibility = String(row.visibility || hubOwnLiveMeta.visibility || 'friends');
            hubOwnLiveMeta.host_door = String(row.host_door || '').toLowerCase();
            hubOwnLiveMeta.studio_source = String(row.studio_source || '').toLowerCase();
            return true;
          }
          return false;
        });
        var stageLive = null;
        if (ownLiveId > 0 && ownRow) {
          stageLive = ownRow;
        } else if (featured && parseInt(featured.user_id || '0', 10) !== hubMeId) {
          stageLive = featured;
        }
        if (ownLiveId > 0 && ownRow && String(ownRow.status || 'live').toLowerCase() === 'live') {
          syncHostLiveDoorsFromMeta(ownRow);
          refreshHubHostLiveActive();
          syncHubChatIdleDoorVisibility();
          if (getActiveHubTabKey() === 'chat' && isHubHostLive()) {
            syncHubLiveSessionUi(true);
            requestHubStudioRoomData();
            fetchHubViewerRoom(ownLiveId, null).catch(function(){});
          }
        }
        if (stageLive && stageLive.id && hubWatchingLiveId <= 0) {
          var stage = document.getElementById('hubStage');
          var hasStagePick = stage && stage.querySelector('.hub-live-pick');
          var isWatching = stage && stage.classList.contains('is-watching');
          if (stage && !isWatching && !hasStagePick) {
            renderHubStageIdlePick(parseInt(stageLive.id, 10), {
              title: stageLive.title || 'Live now on Talentra',
              host: stageLive.is_owner ? 'You' : (stageLive.host || 'Host'),
              ownerId: parseInt(stageLive.user_id || hubMeId || '0', 10) || hubMeId,
              isOwner: !!stageLive.is_owner
            });
            updateHubBrand({
              host: stageLive.is_owner ? 'You' : (stageLive.host || 'Host'),
              title: stageLive.title || 'Live now on Talentra'
            });
          }
        }
      } catch (error) {}
    }

    function renderHubStageIdlePick(liveId, meta){
      meta = meta || {};
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0) return;

      var stage = document.getElementById('hubStage');
      var stageWrap = document.querySelector('.hub-stage-wrap');
      if (!stage) return;

      hubWatchingLiveId = 0;
      hubHostVideo = null;
      stage.classList.remove('is-watching');
      if (stageWrap) stageWrap.classList.remove('is-watching');

      var title = String(meta.title || 'Live now on Talentra').trim() || 'Live now on Talentra';
      var host = String(meta.host || 'You').trim() || 'You';
      var ownerId = parseInt(meta.ownerId || hubMeId || '0', 10) || hubMeId || 0;
      var isOwner = meta.isOwner !== false;

      stage.innerHTML = '';

      var placeholder = document.createElement('div');
      placeholder.className = 'hub-stage-placeholder';
      placeholder.innerHTML = '<i class="fa fa-video-camera"></i><div>' + escapeHubText(title) + '</div>';

      var pickBtn = document.createElement('button');
      pickBtn.type = 'button';
      pickBtn.className = 'hub-live-pick';
      pickBtn.style.maxWidth = '280px';
      pickBtn.setAttribute('data-live-id', String(liveId));
      pickBtn.setAttribute('data-owner-id', String(ownerId));
      pickBtn.setAttribute('data-is-owner', isOwner ? '1' : '0');
      pickBtn.setAttribute('data-host', host);
      pickBtn.setAttribute('data-title', title);
      pickBtn.innerHTML = '<span class="hub-live-tag"><span class="dot"></span> LIVE</span><span class="hub-live-pick-gap">Tap to watch</span>';
      placeholder.appendChild(pickBtn);
      stage.appendChild(placeholder);

      var stageMeta = document.createElement('div');
      stageMeta.className = 'hub-stage-meta';
      stageMeta.innerHTML = '<span class="hub-live-tag"><span class="dot"></span> LIVE</span>';
      stage.appendChild(stageMeta);

      bindHubLivePick(pickBtn);
    }

    function ensureOwnLiveInChatList(liveId, title){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0 || !canHostChatInThisDoor()) return;
      var chatIdle = document.getElementById('hubChatIdle');
      if (!chatIdle) return;
      if (chatIdle.querySelector('[data-live-id="' + liveId + '"]')) return;

      var pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'hub-live-pick';
      pick.setAttribute('data-live-id', String(liveId));
      pick.setAttribute('data-owner-id', String(hubMeId));
      pick.setAttribute('data-is-owner', '1');
      pick.setAttribute('data-host', 'You');
      pick.setAttribute('data-title', String(title || 'Live session'));
      pick.innerHTML = '<span class="hub-msg-avatar">Y</span><span class="hub-msg-body">'
        + '<span class="hub-msg-user">You</span>'
        + '<span class="hub-msg-text">' + escapeHubText(title || 'Live session') + '</span></span>';

      if (chatIdle.querySelector('.hub-msg.system')) {
        chatIdle.innerHTML = '';
      }
      chatIdle.insertBefore(pick, chatIdle.firstChild);
      bindHubLivePick(pick);
    }

    function updateHubBrand(meta){
      meta = meta || {};
      var brandStrong = document.querySelector('.hub-brand-text strong');
      var brandSpan = document.querySelector('.hub-brand-text span');
      if (meta.host && brandStrong) brandStrong.textContent = meta.host;
      if (meta.title && brandSpan) brandSpan.textContent = meta.title;
    }

    function clearHubWatch(){
      if (hubWatchingLiveId > 0 && hubWatchingLiveId !== ownLiveId) {
        stopFriendWatchInHub(true);
        return;
      }
      stopHubHostSnapshotLoop();
      stopHubHostStream();
      stopHubViewerWatch(true);
      hubWatchingLiveId = 0;
      hubHostStreamPromise = null;
      hubHostVideo = null;
      window.location.href = hubDoorBaseUrl;
    }

    function stopHubHostSnapshotLoop(){
      if (hubSnapshotTimer) {
        clearInterval(hubSnapshotTimer);
        hubSnapshotTimer = null;
      }
      hubSnapshotBusy = false;
    }

    function stopHubViewerSnapshotLoop(){
      if (hubViewerSnapshotTimer) {
        clearInterval(hubViewerSnapshotTimer);
        hubViewerSnapshotTimer = null;
      }
    }

    function stopHubViewerRoomLoop(){
      if (hubViewerRoomTimer) {
        clearInterval(hubViewerRoomTimer);
        hubViewerRoomTimer = null;
      }
      hubSnapshotLiveActive = false;
      hubViewerRoomFailCount = 0;
    }

    function stopHubStageSync(){
      if (hubStageSyncTimer) {
        clearInterval(hubStageSyncTimer);
        hubStageSyncTimer = null;
      }
      hubEmbedVideoCurrentTime = 0;
      hubEmbedVideoLastAdvanceAt = 0;
      var stage = document.getElementById('hubStage');
      if (stage) {
        stage.classList.remove('use-snapshot-stage');
      }
    }

    function readHubEmbedStageState(){
      var frame = document.querySelector('#hubStage .hub-watch-frame');
      if (!frame) return null;
      try {
        var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
        if (!doc) return null;
        var embedStage = doc.querySelector('.stage-screen');
        if (!embedStage) return null;
        var video = doc.getElementById('watchStageVideo');
        return {
          hasWebRtc: embedStage.classList.contains('has-webrtc'),
          hasSnapshot: embedStage.classList.contains('has-snapshot'),
          currentTime: video ? Number(video.currentTime || 0) : 0,
          paused: !!(video && video.paused),
          ended: !!(video && video.ended),
          readyState: video ? Number(video.readyState || 0) : 0
        };
      } catch (error) {
        return null;
      }
    }

    function syncHubStageSurface(){
      var stage = document.getElementById('hubStage');
      if (!stage) return;
      var snap = stage.querySelector('.hub-watch-snapshot');
      var hasParentSnapshot = stage.classList.contains('has-snapshot-stage')
        && !!(snap && snap.getAttribute('src'));
      var embedState = readHubEmbedStageState();
      var embedHealthy = false;

      if (embedState) {
        if (embedState.hasWebRtc) {
          if (embedState.currentTime > (hubEmbedVideoCurrentTime + 0.01)) {
            hubEmbedVideoCurrentTime = embedState.currentTime;
            hubEmbedVideoLastAdvanceAt = Date.now();
          } else if (!hubEmbedVideoLastAdvanceAt && embedState.readyState >= 2) {
            hubEmbedVideoLastAdvanceAt = Date.now();
          }
          var stalledFor = Date.now() - Number(hubEmbedVideoLastAdvanceAt || 0);
          embedHealthy = !embedState.paused && !embedState.ended && (stalledFor <= 6500 || embedState.readyState < 2);
        } else if (embedState.hasSnapshot) {
          hubEmbedVideoLastAdvanceAt = Date.now();
          embedHealthy = true;
        }
      }

      stage.classList.toggle('use-snapshot-stage', !!(hasParentSnapshot && !embedHealthy));
      if (embedHealthy || hasParentSnapshot) {
        hideHubWatchLoading();
      }
    }

    function setHubSnapshotVisible(isVisible){
      var stage = document.getElementById('hubStage');
      if (!stage) return;
      var snap = stage.querySelector('.hub-watch-snapshot');
      stage.classList.toggle('has-snapshot-stage', !!isVisible);
      if (!isVisible && snap) {
        snap.removeAttribute('src');
        delete snap.dataset.loadToken;
      }
      syncHubStageSurface();
    }

    function setHubSnapshotSource(url, onError){
      var stage = document.getElementById('hubStage');
      var snap = stage && stage.querySelector('.hub-watch-snapshot');
      if (!snap || !url || hubWatchingLiveId <= 0) return;
      hubSnapshotLoadToken += 1;
      var token = 'hub-stage-' + String(hubSnapshotLoadToken);
      snap.dataset.loadToken = token;
      var loader = new Image();
      loader.onload = function(){
        if (snap.dataset.loadToken !== token || hubWatchingLiveId <= 0) return;
        snap.src = url;
        setHubSnapshotVisible(true);
        hideHubWatchLoading();
      };
      loader.onerror = function(){
        if (snap.dataset.loadToken !== token) return;
        if (typeof onError === 'function') {
          onError();
        }
      };
      loader.src = url;
    }

    function pullHubSnapshotFrame(forceVersioned){
      if (!hubWatchingLiveId || !hubSnapshotLiveActive || !hubViewerSnapshotVersion) {
        setHubSnapshotVisible(false);
        return;
      }
      var url = 'ajax/live_snapshot.php?live=' + encodeURIComponent(String(hubWatchingLiveId))
        + '&t=' + encodeURIComponent(String(forceVersioned ? hubViewerSnapshotVersion : Date.now()))
        + '&v=' + encodeURIComponent(String(hubViewerSnapshotVersion));
      setHubSnapshotSource(url, function(){
        if (forceVersioned) {
          setHubSnapshotVisible(false);
        }
      });
    }

    function restartHubStageSync(){
      stopHubStageSync();
      if (!hubWatchingLiveId) {
        syncHubStageSurface();
        return;
      }
      syncHubStageSurface();
      hubStageSyncTimer = window.setInterval(syncHubStageSurface, 700);
    }

    function restartHubSnapshotLoop(){
      stopHubViewerSnapshotLoop();
      if (!hubWatchingLiveId || !hubSnapshotLiveActive || !hubViewerSnapshotVersion) {
        setHubSnapshotVisible(false);
        return;
      }
      pullHubSnapshotFrame(false);
      restartHubStageSync();
      hubViewerSnapshotTimer = window.setInterval(function(){
        if (hubWatchingLiveId <= 0) return;
        pullHubSnapshotFrame(false);
        syncHubStageSurface();
      }, 900);
    }

    function stopHubViewerWatch(leaveRoom){
      var liveId = hubWatchingLiveId;
      stopHubViewerSnapshotLoop();
      stopHubViewerRoomLoop();
      stopHubStageSync();
      hubViewerSnapshotVersion = '';
      setHubSnapshotVisible(false);
      if (leaveRoom !== false && liveId > 0) {
        fetch('ajax/live_watch_room.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json'
          },
          body: 'action=leave_view&live_id=' + encodeURIComponent(String(liveId))
        }).catch(function(){});
      }
    }

    function setHubWatchLoadingMessage(loading, message, show){
      if (!loading) return;
      loading.textContent = String(message || 'Connecting to live...');
      loading.style.display = show === false ? 'none' : 'flex';
    }

    function applyHubViewerRoomPayload(liveId, data, loading){
      if (!data || !data.ok || !data.can_view) {
        throw new Error((data && data.error) ? data.error : 'You do not have access to this live room.');
      }
      renderHubRoomPayload(data);
      var live = data.live || {};
      var nextVersion = String(live.snapshot_version || '');
      var nextSnapshotLiveActive = String(live.status || '').toLowerCase() === 'live' && nextVersion !== '';

      hubSnapshotLiveActive = nextSnapshotLiveActive;
      if (!nextSnapshotLiveActive) {
        hubViewerSnapshotVersion = '';
        restartHubSnapshotLoop();
        if (loading) {
          var isLive = String(live.status || '').toLowerCase() === 'live';
          setHubWatchLoadingMessage(loading, isLive ? 'Waiting for host camera...' : 'Live ended', true);
        }
        return;
      }
      if (hubViewerSnapshotVersion !== nextVersion) {
        hubViewerSnapshotVersion = nextVersion;
        pullHubSnapshotFrame(true);
        restartHubSnapshotLoop();
        return;
      }
      restartHubSnapshotLoop();
    }

    function fetchHubViewerRoom(liveId, loading){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0 || hubWatchingLiveId !== liveId) {
        return Promise.resolve();
      }
      var url = isHubHostLive()
        ? ('ajax/live_studio_room_action.php?t=' + encodeURIComponent(String(Date.now())))
        : ('ajax/live_watch_room.php?live=' + encodeURIComponent(String(liveId)) + '&t=' + encodeURIComponent(String(Date.now())));
      return fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      }).then(function(response){
        return parseHubJsonResponse(response).then(function(data){
          if (!response.ok) {
            throw new Error((data && data.error) ? data.error : 'Unable to join live room.');
          }
          var payload = isHubHostLive() ? normalizeHubStudioPayload(data) : data;
          applyHubViewerRoomPayload(liveId, payload, loading);
          hubViewerRoomFailCount = 0;
        });
      });
    }

    function startHubViewerWatchLoop(liveId, loading){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (liveId <= 0) return;
      stopHubViewerSnapshotLoop();
      stopHubViewerRoomLoop();
      stopHubStageSync();
      hubViewerRoomFailCount = 0;
      setHubWatchLoadingMessage(loading, 'Connecting to live...', true);
      fetchHubViewerRoom(liveId, loading).catch(function(error){
        hubViewerRoomFailCount += 1;
        if (loading && hubViewerRoomFailCount >= 2) {
          setHubWatchLoadingMessage(loading, error && error.message ? error.message : 'Unable to watch this live.', true);
        }
      });
      hubViewerRoomTimer = window.setInterval(function(){
        if (hubWatchingLiveId !== liveId) return;
        fetchHubViewerRoom(liveId, loading).catch(function(error){
          hubViewerRoomFailCount += 1;
          if (loading && hubViewerRoomFailCount >= 3) {
            setHubWatchLoadingMessage(loading, error && error.message ? error.message : 'Unable to watch this live.', true);
          }
        });
      }, 4000);
    }

    function uploadHubHostSnapshot(liveId, video){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (hubSnapshotBusy || liveId <= 0 || !video) {
        return Promise.resolve();
      }
      if (video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
        return Promise.resolve();
      }
      hubSnapshotBusy = true;
      var maxWidth = 960;
      var scale = video.videoWidth > maxWidth ? (maxWidth / video.videoWidth) : 1;
      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
      canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
      var ctx = canvas.getContext('2d');
      if (!ctx) {
        hubSnapshotBusy = false;
        return Promise.resolve();
      }
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      return new Promise(function(resolve){
        canvas.toBlob(function(blob){
          if (!blob) {
            hubSnapshotBusy = false;
            resolve();
            return;
          }
          var formData = new FormData();
          formData.append('live_id', String(liveId));
          formData.append('frame', blob, 'frame.jpg');
          fetch('ajax/live_snapshot.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          }).catch(function() {}).finally(function(){
            hubSnapshotBusy = false;
            resolve();
          });
        }, 'image/jpeg', 0.86);
      });
    }

    function startHubHostSnapshotLoop(liveId, video){
      stopHubHostSnapshotLoop();
      uploadHubHostSnapshot(liveId, video);
      hubSnapshotTimer = window.setInterval(function(){
        uploadHubHostSnapshot(liveId, video);
      }, 1000);
    }

    function showHubHostCamRetry(retryBtn, visible){
      if (!retryBtn) return;
      retryBtn.classList.toggle('is-visible', !!visible);
      retryBtn.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function attachHubHostStream(video, stream, liveId, retryBtn){
      if (!video || !stream) {
        showHubHostCamRetry(retryBtn, true);
        return Promise.resolve(false);
      }
      video.srcObject = stream;
      return video.play().then(function(){
        showHubHostCamRetry(retryBtn, false);
        if (window.__msbHubHostStreamOwnedByHub) {
          startHubHostSnapshotLoop(liveId, video);
          var hasEnabledVideo = !!(stream && stream.getVideoTracks && stream.getVideoTracks().some(function(track){
            return track.readyState === 'live' && track.enabled;
          }));
          if (hasEnabledVideo) {
            persistHubHostCameraState(true);
          }
        }
        syncHubTabControls();
        return true;
      }).catch(function(){
        showHubHostCamRetry(retryBtn, true);
        return false;
      });
    }

    function embedHostLiveInHub(id, meta, streamPromise, panelKey){
      id = parseInt(id || '0', 10) || 0;
      if (id <= 0) return;
      meta = meta || {};
      var softwareHost = isHubSoftwareHost(meta);
      stopFriendWatchInHub(true);
      hubWatchingLiveId = id;
      switchHubTab('chat');
      updateHubBrand(meta || {});
      var stage = document.getElementById('hubStage');
      var stageMeta = document.querySelector('.hub-stage-meta');
      var stageWrap = document.querySelector('.hub-stage-wrap');
      if (!stage) return;
      stage.classList.remove('has-webrtc-video', 'use-snapshot-stage', 'has-snapshot-stage', 'hub-friend-watch');
      stage.classList.add('is-watching');
      if (stageWrap) stageWrap.classList.add('is-watching');
      stage.innerHTML = '';

      var video = document.createElement('video');
      video.className = 'hub-host-video';
      video.autoplay = true;
      video.playsInline = true;
      video.muted = true;
      video.setAttribute('autoplay', 'autoplay');
      video.setAttribute('playsinline', 'playsinline');
      video.setAttribute('muted', 'muted');

      var retryBtn = document.createElement('button');
      retryBtn.type = 'button';
      retryBtn.className = 'hub-host-cam-retry';
      retryBtn.textContent = softwareHost ? 'Waiting for software feed...' : 'Tap to enable camera';
      retryBtn.setAttribute('aria-label', softwareHost ? 'Waiting for software feed' : 'Enable camera');

      stage.appendChild(video);
      var snap = null;
      if (softwareHost) {
        snap = document.createElement('img');
        snap.className = 'hub-watch-snapshot';
        snap.alt = 'Live stream';
        stage.appendChild(snap);
        hubSnapshotLiveActive = true;
        restartHubSnapshotLoop();
        pullHubSnapshotFrame(true);
      }
      stage.appendChild(retryBtn);
      hubHostVideo = video;

      var resolveStream = streamPromise || ensureHubHostStream(id, meta);
      resolveStream.then(function(stream){
        if (stream) {
          return attachHubHostStream(video, stream, id, retryBtn);
        }
        showHubHostCamRetry(retryBtn, softwareHost);
        return false;
      }).catch(function(){
        showHubHostCamRetry(retryBtn, true);
      });

      retryBtn.addEventListener('click', function(){
        if (softwareHost) {
          requestHubStudioRoomData();
          postHubStudioMessage({ type: 'msb-hub-request-room-data' });
          pullHubSnapshotFrame(true);
          return;
        }
        showHubHostCamRetry(retryBtn, false);
        ensureHubHostStream(id, meta).then(function(stream){
          return attachHubHostStream(video, stream, id, retryBtn);
        }).catch(function(){
          showHubHostCamRetry(retryBtn, true);
        });
      });

      if (stageMeta) stageMeta.classList.add('is-hidden');
      syncHubLiveSessionUi(true);
      startHubViewerWatchLoop(id, null);
      syncHubTabControls();
    }

    function stopHubHostStream(){
      if (window.__msbHubHostStreamOwnedByHub && window.__msbHubHostStream && typeof window.__msbHubHostStream.getTracks === 'function') {
        window.__msbHubHostStream.getTracks().forEach(function(track){
          try { track.stop(); } catch (e) {}
        });
      }
      window.__msbHubHostStream = null;
      window.__msbHubHostStreamLiveId = 0;
      window.__msbHubHostStreamOwnedByHub = false;
      hubHostVideo = null;
    }

    function readBorrowedHubHostStream(liveId){
      liveId = parseInt(liveId || '0', 10) || 0;
      if (
        liveId > 0
        && window.__msbHubHostStream
        && Number(window.__msbHubHostStreamLiveId || 0) === liveId
        && typeof window.__msbHubHostStream.getVideoTracks === 'function'
      ) {
        var tracks = window.__msbHubHostStream.getVideoTracks();
        if (tracks.length && tracks[0].readyState === 'live') {
          return window.__msbHubHostStream;
        }
      }
      return null;
    }

    function ensureHubHostStream(liveId, meta){
      liveId = parseInt(liveId || '0', 10) || 0;
      meta = meta || {};
      if (liveId <= 0) {
        return Promise.resolve(null);
      }
      var softwareHost = isHubSoftwareHost(meta);
      var borrowed = readBorrowedHubHostStream(liveId);
      if (borrowed) {
        return Promise.resolve(borrowed);
      }
      return new Promise(function(resolve){
        var attempts = 0;
        var maxAttempts = softwareHost ? 80 : 20;
        function pollBorrowed(){
          var stream = readBorrowedHubHostStream(liveId);
          if (stream) {
            resolve(stream);
            return;
          }
          attempts += 1;
          if (attempts < maxAttempts) {
            window.setTimeout(pollBorrowed, 100);
            return;
          }
          if (softwareHost) {
            resolve(null);
            return;
          }
          stopHubHostStream();
          if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            resolve(null);
            return;
          }
          navigator.mediaDevices.getUserMedia({
            video: {
              facingMode: 'user',
              width: { ideal: 1280 },
              height: { ideal: 720 },
              frameRate: { ideal: 24, max: 30 }
            },
            audio: {
              echoCancellation: true,
              noiseSuppression: true,
              autoGainControl: true
            }
          }).then(function(stream){
            window.__msbHubHostStream = stream;
            window.__msbHubHostStreamLiveId = liveId;
            window.__msbHubHostStreamOwnedByHub = true;
            resolve(stream);
          }).catch(function(){
            resolve(null);
          });
        }
        pollBorrowed();
      });
    }

    function hideHubWatchLoading(){
      var loading = document.querySelector('#hubStage .hub-watch-loading');
      if (loading && loading.parentNode) {
        loading.parentNode.removeChild(loading);
      }
    }

    function embedLiveInHub(id, meta, panelKey){
      id = parseInt(id || '0', 10) || 0;
      if (id <= 0) return;
      meta = meta || {};
      panelKey = 'public';
      var prevId = hubWatchingLiveId;
      stopFriendWatchInHub(prevId > 0 && prevId !== id);
      hubWatchPanelKey = panelKey;
      hubWatchingLiveId = id;
      hubViewerSnapshotVersion = String(meta.snapshot_version || '').trim();
      hubSnapshotLiveActive = !!hubViewerSnapshotVersion;
      hubEmbedVideoCurrentTime = 0;
      hubEmbedVideoLastAdvanceAt = 0;
      switchHubTab(panelKey);
      updateHubBrand(meta || {});
      var stage = document.getElementById('hubStage');
      var stageWrap = document.querySelector('.hub-stage-wrap');
      var stageMeta = document.querySelector('.hub-stage-meta');
      if (!stage) return;
      if (stageMeta) stageMeta.classList.add('is-hidden');
      stage.classList.remove('has-webrtc-video', 'use-snapshot-stage', 'has-snapshot-stage', 'hub-friend-watch');
      stage.classList.add('is-watching');
      if (stageWrap) stageWrap.classList.add('is-watching');
      stage.innerHTML = '';

      var frame = document.createElement('iframe');
      frame.className = 'hub-watch-frame';
      frame.title = String(meta.title || 'Live video');
      frame.setAttribute('loading', 'eager');
      frame.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; camera; microphone');
      frame.setAttribute('referrerpolicy', 'same-origin');
      frame.src = 'live_watch.php?live=' + encodeURIComponent(String(id)) + '&embed=1';

      var snap = document.createElement('img');
      snap.className = 'hub-watch-snapshot';
      snap.alt = 'Live stream';

      var loading = document.createElement('div');
      loading.className = 'hub-watch-loading';
      loading.textContent = 'Connecting to live...';

      stage.appendChild(frame);
      stage.appendChild(snap);
      stage.appendChild(loading);

      frame.addEventListener('load', function onHubWatchFrameLoad(){
        frame.removeEventListener('load', onHubWatchFrameLoad);
        window.setTimeout(syncHubStageSurface, 80);
        window.setTimeout(syncHubStageSurface, 350);
      });

      startHubViewerWatchLoop(id, loading);
      restartHubStageSync();
      if (hubViewerSnapshotVersion) {
        pullHubSnapshotFrame(true);
      }
      restartHubSnapshotLoop();
      syncHubEndButton();
      syncHubLiveSessionUi(true);
    }

    function openLive(id, meta, isOwner, panelKey){
      id = parseInt(id || '0', 10) || 0;
      if (id <= 0) return;
      stopHubHostSnapshotLoop();
      hubHostVideo = null;
      if (isOwner) {
        if (!canHostChatInThisDoor(meta)) return;
        hubWatchPanelKey = 'chat';
        if (ownLiveId <= 0) ownLiveId = id;
        var hostMeta = Object.assign({}, meta || {}, {
          studio_source: String((meta && meta.studio_source) || hubStudioSource || '').toLowerCase()
        });
        hubHostStreamPromise = ensureHubHostStream(id, hostMeta);
        embedHostLiveInHub(id, hostMeta, hubHostStreamPromise, 'chat');
        syncHubEndButton();
        return;
      }
      stopHubHostStream();
      hubHostStreamPromise = null;
      var visibility = String((meta && meta.visibility) || '').toLowerCase();
      panelKey = isOwner ? 'chat' : 'public';
      if (!isOwner && visibility) {
        if (visibility === 'public' && hubSurface !== 'public') return;
        if (visibility === 'friends' && hubSurface !== 'feed') return;
      }
      routeFriendWatch(id, meta || {}, panelKey);
      syncHubEndButton();
    }

    function openStudio(){
      openLiveStudioInHub();
    }

    function handleHubLiveStarted(payload){
      payload = payload || {};
      var hostUserId = parseInt(payload.hostUserId || payload.userId || '0', 10) || 0;
      var nextId = parseInt(payload.liveId || '0', 10) || 0;
      if (hostUserId > 0 && hostUserId === hubMeId) {
        var eventDoor = resolveEventOwnerDoor(payload);
        if (!eventDoor) {
          eventDoor = isHubInRightDoor() ? 'right' : 'left';
        }
        var myDoor = getHubDoorSide();
        if (myDoor && eventDoor !== myDoor) {
          refreshHubBrowseLives(true);
          return;
        }
        if (shouldSkipHostLiveStageInThisDoor(payload)) {
          if (nextId > 0) ownLiveId = nextId;
          refreshHubHostLiveActive();
          syncHubEndButton();
          syncHubLiveSessionUi(false);
          syncHubChatIdleDoorVisibility();
          refreshHubBrowseLives(true);
          return;
        }
      }
      if (hostUserId > 0 && hostUserId !== hubMeId) {
        var hostVisibility = String(payload.visibility || 'friends').toLowerCase();
        var hostLiveDoor = String(payload.hostLiveDoor || '').toLowerCase();
        var studioSource = String(payload.studioSource || payload.studio_source || '').toLowerCase();
        var watchMeta = {
          title: String(payload.title || 'Live session'),
          host: String(payload.host || payload.hostName || 'Host'),
          visibility: hostVisibility,
          host_door: hostLiveDoor,
          studio_source: studioSource
        };
        var matchesSurface = (hostVisibility === 'public' && hubSurface === 'public')
          || (hostVisibility === 'friends' && hubSurface === 'feed');
        refreshHubBrowseLives(true).then(function(){
          if (!matchesSurface) return;
          var liveId = parseInt(payload.liveId || '0', 10) || 0;
          if (liveId <= 0) return;
          if (hubWatchingLiveId > 0) return;
          switchHubTab('public');
          var watchDoor = resolveFriendWatchDoor(watchMeta);
          if (watchDoor === 'right') {
            if (isHubInRightDoor()) {
              maybeAutoStartHubWatch();
            } else {
              openLiveRightDoorForWatch(liveId, watchMeta);
            }
            return;
          }
          if (isHubInLeftDoor()) {
            maybeAutoStartHubWatch();
          } else {
            openLiveLeftDoorForWatch(liveId, watchMeta);
          }
        });
        return;
      }
      var now = Date.now();
      if (nextId > 0 && nextId === ownLiveId && hubLiveStartedAt && (now - hubLiveStartedAt) < 2000) {
        hubHostLiveActive = canHostChatInThisDoor(payload);
        syncHubLiveSessionUi(true);
        requestHubStudioRoomData();
        refreshHubBrowseLives(true);
        if (!canHostChatInThisDoor(payload)) return;
        var stage = document.getElementById('hubStage');
        if (stage && !stage.classList.contains('is-watching')) {
          openLive(nextId, {
            title: String(payload.title || hubOwnLiveMeta.title || 'Live session'),
            host: 'You',
            ownerId: hubMeId,
            studio_source: String(payload.studioSource || payload.studio_source || hubStudioSource || 'software').toLowerCase()
          }, true, 'chat');
        }
        return;
      }
      if (nextId > 0) {
        hubLiveStartedAt = now;
      }
      ownLiveId = nextId;
      var title = String(payload.title || 'Live now on Talentra').trim() || 'Live now on Talentra';
      var visibility = String(payload.visibility || 'friends').toLowerCase();
      hubOwnLiveMeta = { title: title, visibility: visibility };
      syncHubEndButton();
      hubHostLiveActive = true;
      refreshHubHostLiveActive();
      updateHubBrand({ host: 'You', title: title });
      ensureOwnLiveInChatList(ownLiveId, title);
      if ((visibility === 'public' && hubSurface === 'public')
        || (visibility === 'friends' && hubSurface === 'feed')) {
        ensureOwnLiveInBrowseList(ownLiveId, title, visibility);
      }
      refreshHubBrowseLives(true);
      switchHubTab('chat');
      if (ownLiveId > 0 && canHostChatInThisDoor(payload)) {
        openLive(ownLiveId, {
          title: title,
          host: 'You',
          ownerId: hubMeId,
          studio_source: String(payload.studioSource || payload.studio_source || hubStudioSource || '').toLowerCase()
        }, true, 'chat');
        requestHubStudioRoomData();
      } else {
        refreshHubHostLiveActive();
        syncHubLiveSessionUi(false);
        syncHubChatIdleDoorVisibility();
      }
    }

    document.getElementById('hubCloseBtn')?.addEventListener('click', function(event){
      event.preventDefault();
      event.stopPropagation();
      closeDoor();
    });
    document.getElementById('hubEndBtn')?.addEventListener('click', endHubLive);
    document.getElementById('hubEndConfirmCancel')?.addEventListener('click', function(){
      if (hubEndBusy) return;
      setHubEndConfirmOpen(false);
    });
    document.getElementById('hubEndConfirmOk')?.addEventListener('click', performEndHubLive);
    document.getElementById('hubEndConfirm')?.addEventListener('click', function(event){
      if (hubEndBusy) return;
      if (event.target && event.target.id === 'hubEndConfirm') {
        setHubEndConfirmOpen(false);
      }
    });
    document.addEventListener('keydown', function(event){
      if (event.key === 'Escape') {
        var modal = document.getElementById('hubEndConfirm');
        if (modal && modal.classList.contains('is-open')) {
          setHubEndConfirmOpen(false);
        }
      }
    });
    syncHubEndButton();
    var hubUrlParams = readHubUrlParams();
    if (hubUrlParams.studioSource && hubUrlParams.tab === 'studio' && canUseHubStudioTabs()) {
      setHubStudioSource(hubUrlParams.studioSource);
    } else if (hubUrlParams.tab === 'software') {
      openLiveSoftwareBrowseEntry();
    } else if (hubUrlParams.tab) {
      switchHubTab(hubUrlParams.tab);
    } else if (ownLiveId <= 0 && !canUseHubStudioTabs() && document.querySelector('#hubPublicBrowse .hub-live-pick[data-is-owner="0"]')) {
      switchHubTab('public');
    } else {
      activateHubTab('chat');
      setHubPanel('chat');
      setHubShellMode('chat');
    }

    document.querySelectorAll('[data-live-id]').forEach(function(btn){
      bindHubLivePick(btn);
    });

    if (ownLiveId > 0 && <?= $canStudio ? 'true' : 'false' ?>) {
      window.setTimeout(function(){
        if (!canHostChatInThisDoor()) {
          syncHubLiveSessionUi(false);
          syncHubChatIdleDoorVisibility();
          return;
        }
        if (hubWatchingLiveId > 0 && hubWatchingLiveId !== ownLiveId) return;
        var stage = document.getElementById('hubStage');
        if (stage && stage.classList.contains('is-watching') && hubWatchingLiveId !== ownLiveId) return;
        openLive(ownLiveId, {
          title: hubOwnLiveMeta.title || readHubBrandTitle() || 'Live session',
          host: 'You',
          ownerId: hubMeId,
          studio_source: hubStudioSource || hubOwnLiveMeta.studio_source || ''
        }, true, 'chat');
      }, 500);
    } else if (hubUrlParams.watchLive > 0) {
      window.setTimeout(function(){
        if (hubWatchingLiveId > 0) return;
        var stage = document.getElementById('hubStage');
        if (stage && stage.classList.contains('is-watching')) return;
        openLive(hubUrlParams.watchLive, {
          host: hubUrlParams.watchHost || '',
          title: hubUrlParams.watchTitle || 'Live session',
          snapshot_version: hubUrlParams.watchSnapshot || '',
          visibility: hubUrlParams.watchVisibility || (hubSurface === 'feed' ? 'friends' : 'public'),
          host_door: hubUrlParams.watchHostDoor || '',
          studio_source: hubUrlParams.watchStudioSource || ''
        }, false, 'public');
      }, 450);
    }

    window.addEventListener('message', function(event){
      if (!event || !event.data || typeof event.data !== 'object') return;
      if (event.data.type === 'msb-hub-open-friend-browse') {
        openFriendBrowseForDoor(String(event.data.door || 'left'));
        return;
      }
      if (event.data.type === 'msb-hub-watch-close') {
        clearHubWatch();
        return;
      }
      if (event.data.type === 'msb-hub-watch-video-ready') {
        var readyLiveId = parseInt(event.data.liveId || '0', 10) || 0;
        if (readyLiveId > 0 && readyLiveId === hubWatchingLiveId) {
          hideHubWatchLoading();
        }
        return;
      }
      if (event.data.type === 'msb-hub-watch-video-lost') {
        var lostLiveId = parseInt(event.data.liveId || '0', 10) || 0;
        if (lostLiveId > 0 && lostLiveId === hubWatchingLiveId) {
          hubEmbedVideoCurrentTime = 0;
          hubEmbedVideoLastAdvanceAt = 0;
          pullHubSnapshotFrame(false);
          syncHubStageSurface();
        }
        return;
      }
      if (event.data.type === 'msb-hub-room-data') {
        applyHubHostStudioRoomData(event.data.payload || {});
        return;
      }
      if (event.data.type === 'msb-hub-tab-switch') {
        switchHubTab(String(event.data.tab || 'chat'));
        return;
      }
      if (event.data.type === 'msb-hub-live-started') {
        handleHubLiveStarted(event.data);
        return;
      }
      if (event.data.type === 'msb-hub-live-refresh') {
        refreshHubBrowseLives(true);
        return;
      }
      if (event.data.type === 'msb-hub-set-studio-source') {
        if (hubWatchingLiveId > 0 && !isHubHostLive()) return;
        setHubStudioSource(String(event.data.source || ''));
        return;
      }
      if (event.data.type === 'msb-live-door-open') {
        var leftUrl = String(event.data.url || '').trim();
        if (!leftUrl) return;
        var insideLeftDoor = false;
        try {
          insideLeftDoor = !!(window.frameElement && window.frameElement.id === 'ttLiveDoorFrame');
        } catch (error) {}
        if (insideLeftDoor) {
          var leftTab = '';
          try {
            leftTab = String(new URL(leftUrl, window.location.href).searchParams.get('hub_tab') || '').trim().toLowerCase();
          } catch (parseErr) {}
          if (leftTab === 'software' && canUseHubStudioTabs()) {
            setHubStudioSource('software');
          } else if (leftTab) {
            switchHubTab(leftTab);
          }
          return;
        }
        try {
          if (window.parent && window.parent.TTLive && typeof window.parent.TTLive.open === 'function') {
            window.parent.TTLive.open(leftUrl);
            return;
          }
        } catch (error) {}
        try {
          (window.top || window.parent).postMessage({ type: 'msb-live-door-open', url: leftUrl }, '*');
        } catch (error2) {}
        return;
      }
      if (event.data.type === 'msb-live-right-door-open') {
        var rightUrl = String(event.data.url || '').trim();
        if (!rightUrl) return;
        var insideRightDoor = false;
        try {
          insideRightDoor = !!(window.frameElement && window.frameElement.id === 'ttLiveRightDoorFrame');
        } catch (error) {}
        if (insideRightDoor) {
          var rightTab = '';
          try {
            rightTab = String(new URL(rightUrl, window.location.href).searchParams.get('hub_tab') || '').trim().toLowerCase();
          } catch (parseErr) {}
          if (rightTab === 'software' && canUseHubStudioTabs()) {
            setHubStudioSource('software');
          } else if (rightTab) {
            switchHubTab(rightTab);
          }
          return;
        }
        try {
          if (window.parent && window.parent.TTLiveRight && typeof window.parent.TTLiveRight.open === 'function') {
            window.parent.TTLiveRight.open(rightUrl);
            return;
          }
        } catch (error) {}
        try {
          (window.top || window.parent).postMessage({ type: 'msb-live-right-door-open', url: rightUrl }, '*');
        } catch (error2) {}
        return;
      }
      if (event.data.type === 'msb-live-right-door-close') {
        try {
          if (window.top && window.top.TTLiveRight && typeof window.top.TTLiveRight.close === 'function') {
            window.top.TTLiveRight.close();
            return;
          }
        } catch (error) {}
        try {
          (window.top || window.parent).postMessage({ type: 'msb-live-right-door-close' }, '*');
        } catch (error2) {}
      }
    });

    document.getElementById('hubTopStudioBtn')?.addEventListener('click', function(event){
      event.preventDefault();
      event.stopPropagation();
      openLiveStudioInHub();
    });

    document.querySelectorAll('[data-hub-tab]').forEach(function(tab){
      tab.addEventListener('click', function(event){
        var tabKey = tab.getAttribute('data-hub-tab') || '';
        if (tabKey === 'studio') {
          event.preventDefault();
          openLiveStudioInHub();
          return;
        }
        if (tabKey === 'software') {
          event.preventDefault();
          openLiveSoftwareBrowseEntry();
          return;
        }
        switchHubTab(tabKey);
      });
    });

    document.querySelectorAll('[data-hub-tab-switch]').forEach(function(btn){
      btn.addEventListener('click', function(event){
        event.preventDefault();
        var tabKey = btn.getAttribute('data-hub-tab-switch') || 'chat';
        if (tabKey === 'studio') {
          openLiveStudioInHub();
          return;
        }
        switchHubTab(tabKey);
      });
    });

    window.addEventListener('beforeunload', function(){
      stopHubHostSnapshotLoop();
      stopHubViewerWatch(true);
      stopHubHostStream();
      stopHubStageSync();
      stopHubDoorPoll();
      stopHubPublicPoll();
    });

    startHubDoorPoll();
    refreshHubBrowseLives(true);

    if (hubCanStudio && (isHubInLeftDoor() || hubDoorSide === 'left')) {
      window.setTimeout(function(){
        if (getActiveHubTabKey() === 'studio') return;
        ensureStudioFrame(false);
      }, 250);
    }

    var input = document.getElementById('hubCommentInput');
    input?.addEventListener('keydown', function(e){
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendHubComment();
      }
    });
    document.getElementById('hubCommentSend')?.addEventListener('click', function(){
      sendHubComment();
    });
    document.getElementById('hubCommentMention')?.addEventListener('click', function(){
      if (!input) return;
      input.value = String(input.value || '') + '@';
      input.focus();
    });
    document.getElementById('hubCommentList')?.addEventListener('click', function(event){
      handleHubCommentListClick(event);
    });
    document.getElementById('hubPublicCommentList')?.addEventListener('click', function(event){
      handleHubCommentListClick(event);
    });

    function handleHubCommentListClick(event){
      var replyButton = event.target.closest('.hub-live-comment-reply');
      if (replyButton) {
        var comment = replyButton.closest('[data-comment-author]');
        var author = comment ? String(comment.getAttribute('data-comment-author') || '').trim() : '';
        if (input) {
          input.value = author ? ('@' + author + ' ') : '';
          input.focus();
        }
        return;
      }
      var likeButton = event.target.closest('.hub-live-comment-like');
      if (likeButton) {
        var card = likeButton.closest('[data-comment-id]');
        var commentId = card ? parseInt(card.getAttribute('data-comment-id') || '0', 10) : 0;
        if (commentId > 0) toggleHubCommentLike(commentId);
      }
    }

    document.getElementById('hubSettingsMicToggle')?.addEventListener('change', function(e){
      setHubMicEnabled(!!e.target.checked);
    });
    document.getElementById('hubSettingsCameraToggle')?.addEventListener('change', function(e){
      setHubCameraEnabled(!!e.target.checked);
    });
    document.getElementById('hubMicPanelToggle')?.addEventListener('change', function(e){
      setHubMicEnabled(!!e.target.checked);
    });
    document.getElementById('hubCameraPanelToggle')?.addEventListener('change', function(e){
      setHubCameraEnabled(!!e.target.checked);
    });

    syncHubLiveSessionUi(shouldShowHubLiveChat());
    syncHubComposeDock();
    syncHubPublicWatchUi();
    syncHubTabControls();

    function notifyLiveDoorPainted(){
      try {
        window.parent.postMessage({ type: 'msb-live-hub-painted' }, '*');
      } catch (error) {}
    }
    function scheduleLiveDoorPaintedNotify(){
      window.requestAnimationFrame(function(){
        window.requestAnimationFrame(notifyLiveDoorPainted);
      });
    }
    if (!window.__msbLiveHubPaintListenerBound) {
      window.__msbLiveHubPaintListenerBound = true;
      window.addEventListener('message', function(paintEvent){
        if (!paintEvent || !paintEvent.data || typeof paintEvent.data !== 'object') return;
        if (paintEvent.data.type !== 'msb-live-hub-request-paint') return;
        scheduleLiveDoorPaintedNotify();
      });
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', scheduleLiveDoorPaintedNotify);
    } else {
      scheduleLiveDoorPaintedNotify();
    }
  })();
  </script>
</body>
</html>
