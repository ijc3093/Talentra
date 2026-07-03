<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/device_profile.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$meId = (int)($_SESSION['user_id'] ?? 0);

function liveTableExists(PDO $dbh, string $table): bool
{
    try {
        $st = $dbh->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function liveFmt(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('M d, Y h:i A', $ts) : $raw;
}

function liveAvatarUrl(array $row, int $size = 88): string
{
    $params = [];
    $userId = (int)($row['user_id'] ?? 0);
    $email = trim((string)($row['email'] ?? ''));
    $friendCode = trim((string)($row['friend_code'] ?? ''));
    $username = trim((string)($row['username'] ?? ''));
    $name = trim((string)($row['display_name'] ?? $username ?: 'User'));
    if ($userId > 0) $params[] = 'u=' . rawurlencode((string)$userId);
    if ($email !== '') $params[] = 'email=' . rawurlencode($email);
    if ($friendCode !== '') $params[] = 'friend_code=' . rawurlencode($friendCode);
    if ($username !== '') $params[] = 'username=' . rawurlencode($username);
    if ($name !== '') $params[] = 'name=' . rawurlencode($name);
    $params[] = 's=' . rawurlencode((string)$size);
    return 'avatar.php?' . implode('&', $params);
}

function liveInitials(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'LV';
    }
    $parts = preg_split('/\s+/', $value) ?: [];
    $parts = array_values(array_filter($parts, static function ($part) {
        return trim((string)$part) !== '';
    }));
    if (!$parts) {
        return 'LV';
    }
    if (count($parts) === 1) {
        return strtoupper(substr((string)$parts[0], 0, 2));
    }
    return strtoupper(substr((string)$parts[0], 0, 1) . substr((string)$parts[1], 0, 1));
}

$controller = new Controller();
$dbh = $controller->pdo();
$q = trim((string)($_GET['q'] ?? ''));
$scope = strtolower(trim((string)($_GET['scope'] ?? 'public')));
if (!in_array($scope, ['friends', 'public'], true)) {
    $scope = 'public';
}
$rows = [];

if (liveTableExists($dbh, 'user_video_lives')) {
    $sql = "
        SELECT
            l.id,
            l.user_id,
            l.title,
            l.description,
            l.viewer_count,
            l.started_at,
            l.updated_at,
            COALESCE(l.device_label, '') AS device_label,
            COALESCE(l.device_viewport, '') AS device_viewport,
            u.name,
            u.username,
            u.email,
            u.friend_code
        FROM user_video_lives l
        JOIN users u ON u.id = l.user_id
        WHERE l.status = 'live'
    ";
    $params = [];
    if ($scope === 'friends') {
        $sql .= "
          AND l.visibility = 'friends'
          AND (
            l.user_id = :me_id
            OR EXISTS (
              SELECT 1
              FROM user_contacts uc
              WHERE uc.owner_user_id = :me_id_2
                AND uc.friend_user_id = l.user_id
            )
            OR EXISTS (
              SELECT 1
              FROM user_contacts uc2
              WHERE uc2.owner_user_id = l.user_id
                AND uc2.friend_user_id = :me_id_3
            )
          )
        ";
        $params[':me_id'] = $meId;
        $params[':me_id_2'] = $meId;
        $params[':me_id_3'] = $meId;
    } else {
        $sql .= "
          AND l.visibility = 'public'
        ";
    }
    if ($q !== '') {
        $sql .= "
          AND (
            COALESCE(l.title, '') LIKE :q
            OR COALESCE(l.description, '') LIKE :q
            OR COALESCE(u.name, '') LIKE :q
            OR COALESCE(u.username, '') LIKE :q
          )
        ";
        $params[':q'] = '%' . $q . '%';
    }
    $sql .= "
        ORDER BY COALESCE(l.started_at, l.updated_at) DESC, l.id DESC
        LIMIT 100
    ";

    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $hostId = (int)($row['user_id'] ?? 0);
            $hostName = trim((string)($row['name'] ?? $row['username'] ?? 'Host'));
            $friendStatus = $hostId > 0 && $hostId !== $meId
                ? fs_friend_status($dbh, $meId, $hostId)
                : ($hostId === $meId ? 'self' : 'none');
            $profileUrl = 'profile.php?id=' . $hostId . '&tab=gallery&from_live_public=1';
            if ($friendStatus === 'none') {
                $profileUrl .= '&restricted_live_view=1';
            }

            $row['friend_status'] = $friendStatus;
            $row['host_id'] = $hostId;
            $row['profile_url'] = $profileUrl;
            $row['avatar_fallback'] = liveInitials($hostName);
            $row['show_avatar_image'] = in_array($friendStatus, ['self', 'friends'], true) ? '1' : '0';
            $row['friend_action_label'] = '';
            $row['friend_action_kind'] = '';
            if ($friendStatus === 'none') {
                $row['friend_action_label'] = 'Add Friend';
                $row['friend_action_kind'] = 'send';
            } elseif ($friendStatus === 'outgoing_pending') {
                $row['friend_action_label'] = 'Request Sent';
                $row['friend_action_kind'] = 'pending';
            } elseif ($friendStatus === 'incoming_pending') {
                $row['friend_action_label'] = 'Accept Friend';
                $row['friend_action_kind'] = 'accept';
            }
            if ($row['friend_action_kind'] === 'send') {
                $row['friend_action_url'] = '#';
            } elseif ($row['friend_action_kind'] === 'accept') {
                $row['friend_action_url'] = 'contact_requests.php';
            } elseif ($row['friend_action_kind'] === 'pending') {
                $row['friend_action_url'] = '#';
            } else {
                $row['friend_action_url'] = '';
            }
        }
        unset($row);
    } catch (Throwable $e) {
        $rows = [];
    }
}

$friendLiveUrl = 'public_live.php?' . http_build_query(array_filter([
    'scope' => 'friends',
    'q' => $q !== '' ? $q : null,
]));
$publicLiveUrl = 'public_live.php?' . http_build_query(array_filter([
    'scope' => 'public',
    'q' => $q !== '' ? $q : null,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Public Live</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, $meId);
  ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <script src="./lib/jquery/jquery.js"></script>
  <script src="./lib/popper.js/popper.js"></script>
  <script src="./lib/bootstrap/bootstrap.js"></script>
  <style>
    :root {
      --bg: #120d11;
      --panel: #262b31;
      --line: rgba(255,255,255,.08);
      --text: #f5f7fb;
      --muted: rgba(238,243,255,.72);
      --blue: #57a5ff;
      --blue-dark: #9cc9ff;
      --soft: rgba(255,255,255,.08);
      --live: #ff2f7d;
      --shadow: 0 28px 64px rgba(0, 0, 0, 0.28);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: var(--msb-palette-bg, #171d24);
      color: var(--text);
      font-family: "Trebuchet MS", "Segoe UI", sans-serif;
    }
    html.dark-auto:not([data-msb-appearance]) body,
    html[data-theme="dark"]:not([data-msb-appearance]) body {
      background: var(--msb-palette-bg, #171d24) !important;
      background-image: none !important;
    }
    a { color: inherit; text-decoration: none; }
    .live-main {
      margin-left: var(--feedRailW, 84px);
      min-height: 100vh;
      padding: 100px 18px 18px;
    }
    .live-wrap {
      width: 100%;
      max-width: none;
      margin: 0;
    }
    .hero {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:18px;
      min-height:72px;
      padding:16px 18px 14px;
      position:fixed;
      top:0;
      left:var(--feedRailW, 84px);
      right:0;
      z-index:120;
      margin-bottom:0;
      font-size:.875rem;
      line-height:1.5;
    }
    .hero,
    .hero .yt-brand,
    .hero .search-input,
    .hero .search-btn,
    .hero .yt-mic-btn,
    .hero .yt-chat-tab{
      font-family:"Roboto","Helvetica Neue",Arial,sans-serif;
    }
    .yt-topbar-left,
    .yt-topbar-right{
      display:flex;
      align-items:center;
      gap:14px;
      flex:0 0 auto;
    }
    .yt-topbar-center{
      flex:1 1 auto;
      display:flex;
      align-items:center;
      justify-content:center;
      min-width:0;
    }
    .yt-icon-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:44px;
      height:44px;
      border-radius:50%;
      color:#fff;
      font-size:24px;
      background:transparent;
      border:0;
      cursor:pointer;
    }
    .yt-brand{
      display:inline-flex;
      align-items:center;
      gap:10px;
      color:#fff;
      font-size:24px;
      font-weight:900;
      letter-spacing:-.03em;
    }
    .yt-brand-badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:40px;
      height:28px;
      border-radius:10px;
      background:#ff0033;
      color:#fff;
      font-size:18px;
    }
    .search-card {
      width:min(100%, 840px);
      border:0;
      border-radius:0;
      background:transparent;
      padding:0;
      margin:0;
    }
    .search-row {
      display:flex;
      align-items:center;
      gap:10px;
      width:100%;
    }
    .yt-search-shell{
      display:flex;
      align-items:center;
      width:min(100%, 840px);
      min-width:0;
    }
    .search-input {
      flex: 1;
      height: 52px;
      border: 1px solid #3a3a3a;
      border-right:0;
      border-radius: 999px 0 0 999px;
      padding: 0 22px;
      font-size: 15px;
      outline: none;
      background: #121212;
      color: #fff;
    }
    .search-btn {
      flex:0 0 auto;
      width:88px;
      height: 52px;
      border: 1px solid #3a3a3a;
      border-radius: 0 999px 999px 0;
      padding: 0;
      font-weight: 800;
      color: #fff;
      background: #222;
      cursor: pointer;
      white-space: nowrap;
      font-size:24px;
    }
    .yt-mic-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:48px;
      height:48px;
      border-radius:50%;
      border:0;
      background:#222;
      color:#fff;
      font-size:24px;
      cursor:pointer;
    }
    .yt-chat-switch{
      display:inline-flex;
      align-items:center;
      gap:6px;
      min-height:48px;
      padding:4px;
      border-radius:999px;
      border:1px solid #4a4a4a;
      background:linear-gradient(180deg, #f7f8fd 0%, #e7ebf7 100%);
      box-shadow:inset 0 1px 0 rgba(255,255,255,.75);
    }
    .yt-chat-tab{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:136px;
      min-height:40px;
      padding:0 16px;
      border:0;
      border-radius:999px;
      background:transparent;
      color:#6e7785;
      font-size:14px;
      font-weight:900;
      letter-spacing:-.02em;
    }
    .yt-chat-tab.is-active{
      background:#3d4958;
      color:#fff;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.08);
    }
    .summary-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin: 0 0 12px;
      color: var(--muted);
      font-size: 14px;
    }
    .summary-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: var(--soft);
      color: #fff;
      font-weight: 800;
    }
    .summary-pill::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--live);
    }
    .live-shell{
      display:grid;
      grid-template-columns:minmax(0, 1.45fr) minmax(320px, 420px);
      gap:18px;
      align-items:start;
    }
    .featured-live{
      position:sticky;
      top:18px;
      display:block;
      position: sticky;
      background:transparent;
      border:0;
      border-radius:0;
      box-shadow:none;
      overflow:visible;
    }
    .featured-live-frame{
      display:block;
      background:#0f0f0f;
      border:1px solid rgba(255,255,255,.06);
      overflow:hidden;
      box-shadow: 0 18px 50px rgba(0,0,0,.22);
      cursor:pointer;
    }
    .featured-live-frame.use-snapshot-stage .featured-player-snapshot{
      opacity:1;
    }
    .featured-live-frame.use-snapshot-stage .featured-player-embed{
      opacity:0;
    }
    .featured-player{
      position:relative;
      aspect-ratio:16 / 9;
      background:#000;
      overflow:hidden;
    }
    .featured-player.phone-shot{
      width:min(100%, 300px);
      max-height:460px;
      margin-inline:auto;
      border-radius:28px;
      aspect-ratio:var(--device-ar-w, 375) / var(--device-ar-h, 667);
    }
    .featured-player-embed{
      position:absolute;
      inset:0;
      width:100%;
      height:100%;
      border:0;
      background:#000;
      pointer-events:none;
      z-index:0;
      transition:opacity .18s ease;
    }
    .featured-player-snapshot{
      position:absolute;
      inset:0;
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
      background:#000;
      opacity:0;
      transition:opacity .18s ease;
      pointer-events:none;
      z-index:1;
    }
    .featured-player::after{
      content:"";
      position:absolute;
      inset:0;
      z-index:2;
      background:
        linear-gradient(180deg, rgba(0,0,0,.08) 0%, rgba(0,0,0,0) 16%, rgba(0,0,0,0) 58%, rgba(0,0,0,.42) 100%),
        radial-gradient(circle at center, rgba(255,255,255,.02), transparent 60%);
    }
    .featured-player-scene{
      position:absolute;
      inset:0;
      display:block;
    }
    .scene-audience{
      position:absolute;
      left:0;
      right:0;
      top:0;
      height:24%;
      background:
        linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,0) 42%),
        radial-gradient(circle at 22% 62%, rgba(227,238,255,.28) 0 1.4%, transparent 1.7%),
        radial-gradient(circle at 50% 52%, rgba(227,238,255,.22) 0 1.2%, transparent 1.5%),
        radial-gradient(circle at 76% 58%, rgba(227,238,255,.24) 0 1.4%, transparent 1.7%);
      opacity:.9;
    }
    .scene-rig{
      position:absolute;
      inset:0;
      background:
        linear-gradient(90deg, transparent 13%, rgba(204,224,255,.12) 13.3%, transparent 13.7%, transparent 32%, rgba(204,224,255,.11) 32.3%, transparent 32.7%, transparent 68%, rgba(204,224,255,.11) 68.3%, transparent 68.7%, transparent 87%, rgba(204,224,255,.12) 87.3%, transparent 87.7%),
        linear-gradient(180deg, transparent 24%, rgba(204,224,255,.12) 24.3%, transparent 24.7%, transparent 56%, rgba(204,224,255,.12) 56.3%, transparent 56.7%);
      opacity:.45;
    }
    .scene-ring{
      position:absolute;
      left:9%;
      right:9%;
      bottom:13%;
      height:26%;
      border-bottom:10px solid rgba(238,243,255,.7);
      border-left:6px solid rgba(238,243,255,.26);
      border-right:6px solid rgba(238,243,255,.26);
      background:
        linear-gradient(180deg, rgba(20,30,43,.04) 0%, rgba(8,12,18,.42) 100%);
      clip-path:polygon(4% 18%, 96% 18%, 100% 100%, 0 100%);
      opacity:.88;
    }
    .scene-ring::before,
    .scene-ring::after{
      content:"";
      position:absolute;
      left:0;
      right:0;
      height:3px;
      background:rgba(238,243,255,.58);
      box-shadow:0 12px 0 rgba(238,243,255,.44), 0 24px 0 rgba(238,243,255,.32);
    }
    .scene-ring::before{ top:14%; }
    .scene-ring::after{ display:none; }
    .scene-host,
    .scene-opponent{
      position:absolute;
      bottom:16%;
      border-radius:40px 40px 18px 18px;
      box-shadow:0 20px 40px rgba(0,0,0,.28);
    }
    .scene-host{
      left:48%;
      width:16%;
      height:42%;
      background:
        radial-gradient(circle at 50% 12%, #ddb7a2 0 12%, transparent 12.5%),
        radial-gradient(circle at 37% 40%, #3f6991 0 9%, transparent 10%),
        radial-gradient(circle at 62% 42%, #3f6991 0 9%, transparent 10%),
        linear-gradient(180deg, rgba(193,152,134,.98) 0 32%, rgba(161,118,96,.96) 32% 61%, rgba(53,71,95,.98) 61% 100%);
      filter:brightness(1.02);
    }
    .scene-opponent{
      left:-3%;
      width:23%;
      height:62%;
      background:
        radial-gradient(circle at 62% 10%, #6b4d3f 0 8%, transparent 8.3%),
        linear-gradient(180deg, rgba(136,99,84,.98) 0 36%, rgba(96,68,59,.98) 36% 100%);
      transform:scaleX(-1);
      filter:blur(.3px) brightness(.78);
      opacity:.92;
    }
    .scene-host::before,
    .scene-opponent::before{
      content:"";
      position:absolute;
      inset:0;
      background:
        radial-gradient(circle at 35% 16%, rgba(255,255,255,.16), transparent 28%),
        repeating-linear-gradient(115deg, transparent 0 18px, rgba(0,0,0,.08) 18px 24px, transparent 24px 38px);
      mix-blend-mode:multiply;
      border-radius:inherit;
      opacity:.75;
    }
    .featured-live-stats{
      position:absolute;
      top:auto;
      left:20px;
      bottom:20px;
      z-index:2;
      display:flex;
      align-items:center;
      gap:10px;
    }
    .featured-player-host{
      position:absolute;
      top:16px;
      left:8px;
      z-index:2;
      display:flex;
      align-items:center;
      gap:12px;
      max-width:min(48%, 460px);
      padding:8px 14px 8px 8px;
      border-radius:999px;
      /* background:rgba(15,15,15,.48); */
      /* backdrop-filter: blur(12px); */
      /* box-shadow:0 12px 26px rgba(0,0,0,.24); */
    }
    .featured-player-cta{
      position:absolute;
      top:16px;
      right:18px;
      z-index:2;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:42px;
      padding:0 18px;
      border-radius:999px;
      background:rgba(15,15,15,.78);
      border:1px solid rgba(255,255,255,.1);
      color:#fff;
      font-size:15px;
      font-weight:800;
      letter-spacing:-.01em;
      box-shadow:0 10px 24px rgba(0,0,0,.25);
      backdrop-filter: blur(12px);
    }
    .featured-player-brand{
      position:absolute;
      top:18px;
      left:184px;
      z-index:2;
      color:rgba(255,255,255,.68);
      font-size:18px;
      font-weight:900;
      letter-spacing:-.02em;
      text-shadow:0 10px 24px rgba(0,0,0,.28);
    }
    .featured-player-badge{
      position:static;
      z-index:2;
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:9px 14px;
      border-radius:10px;
      background:rgba(255,47,125,.94);
      color:#fff;
      font-size:14px;
      font-weight:900;
      text-transform:uppercase;
      letter-spacing:.03em;
    }
    .featured-player-viewers{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:9px 14px;
      border-radius:10px;
      background:rgba(45,33,25,.72);
      color:#fff;
      font-size:14px;
      font-weight:800;
      backdrop-filter: blur(8px);
    }
    .featured-live-comments{
      position:absolute;
      left:20px;
      bottom:26px;
      z-index:2;
      display:flex;
      flex-direction:column;
      gap:12px;
      width:min(44%, 430px);
      pointer-events:none;
    }
    .featured-live-comment{
      display:flex;
      align-items:flex-start;
      gap:12px;
      color:#fff;
      animation:liveCommentFloat 5s ease-out forwards;
      opacity:0;
    }
    .featured-live-comment-avatar{
      width:46px;
      height:46px;
      border-radius:50%;
      overflow:hidden;
      display:flex;
      align-items:center;
      justify-content:center;
      background:linear-gradient(135deg,#111827,#3b82f6);
      color:#fff;
      font-weight:900;
      flex:0 0 auto;
      box-shadow:0 10px 24px rgba(0,0,0,.25);
    }
    .featured-live-comment-avatar img{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
    }
    .featured-live-comment-bubble{
      padding:14px 16px;
      border-radius:22px;
      background:rgba(50,54,61,.62);
      backdrop-filter: blur(12px);
      box-shadow:0 10px 24px rgba(0,0,0,.2);
      line-height:1.28;
    }
    .featured-live-comment-name{
      font-size:16px;
      font-weight:900;
      display:flex;
      align-items:center;
      gap:8px;
      margin-bottom:4px;
    }
    .featured-live-comment-name .fa{
      font-size:14px;
      color:#38bdf8;
    }
    .featured-live-comment-text{
      font-size:15px;
      color:rgba(255,255,255,.94);
    }
    .featured-live-bottom{
      position:relative;
      left:auto;
      right:auto;
      bottom:auto;
      z-index:0;
      display:block;
      width:100%;
      max-width:min(100%, 760px);
      margin:0;
    }
    .featured-live-share{
      border:0;
      background:rgba(255,255,255,.18);
      color:#fff;
      min-height:54px;
      padding:0 22px;
      border-radius:24px;
      font-size:18px;
      font-weight:900;
      letter-spacing:.01em;
      backdrop-filter: blur(12px);
    }
    .featured-live-input{
      flex:1 1 300px;
      min-height:54px;
      border-radius:24px;
      background:rgba(255,255,255,.16);
      color:rgba(255,255,255,.84);
      padding:14px 22px;
      display:block;
      font-size:25px;
      font-weight:700;
      backdrop-filter: blur(12px);
      border:0;
      outline:none;
      resize:none;
      font-family:inherit;
      line-height:1.25;
    }
    .featured-live-send{
      justify-self:center;
      align-self:end;
      min-width:58px;
      height:58px;
      border-radius:20px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      background:rgba(13,16,23,.84);
      color:#fff;
      font-size:24px;
      box-shadow:0 12px 28px rgba(0,0,0,.24);
      border:0;
    }
    .featured-live-reactions{
      display:flex;
      align-items:center;
      gap:14px;
      margin-left:0;
      padding-left:16px;
      border-left:2px solid rgba(255,255,255,.22);
    }
    .featured-meta-head{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:18px;
      margin-bottom:20px;
    }
    .featured-live-reactions-overlay{
      position:static;
      z-index:2;
      display:flex;
      align-items:center;
      justify-content:flex-end;
      gap:5px;
      flex:0 0 auto;
      transform:none;
      pointer-events:auto;
    }
    .featured-live-reaction{
      border:0;
      width:50px;
      height:50px;
      border-radius:50%;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:40px;
      box-shadow:0 12px 28px rgba(0,0,0,.24);
      background:#fff;
    }
    .featured-live-reaction.is-active{
      transform:translateY(-2px) scale(1.04);
      box-shadow:0 16px 32px rgba(0,0,0,.3);
    }
    .featured-live-reaction.is-locked{
      cursor:not-allowed;
      opacity:.72;
    }
    .featured-live-reaction.like{ color:#3b82f6; }
    .featured-live-reaction.love{ background:#ff4f7b; color:#fff; }
    .featured-live-reaction.haha{ background:#facc15; }
    .featured-live-reaction.wow{ background:#fcd34d; }
    .featured-live-reaction.sad{ background:#fde68a; }
    .featured-live-reaction.angry{ background:#fb923c; }
    .featured-live-burst{
      position:absolute;
      right:28px;
      top:22%;
      bottom:108px;
      z-index:2;
      pointer-events:none;
    }
    .featured-live-burst span{
      position:absolute;
      right:0;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:44px;
      height:44px;
      border-radius:50%;
      background:rgba(255,255,255,.08);
      font-size:28px;
      filter:drop-shadow(0 10px 20px rgba(0,0,0,.2));
      animation:liveReactionFloat 5s ease-out forwards;
      opacity:0;
    }
    .featured-live-burst span:nth-child(1){ top:8%; font-size:26px; }
    .featured-live-burst span:nth-child(2){ top:32%; font-size:30px; }
    .featured-live-burst span:nth-child(3){ top:56%; font-size:38px; }
    .featured-live-burst span:nth-child(4){ top:76%; font-size:32px; }
    .featured-live-burst span:nth-child(5){ top:90%; right:54px; font-size:28px; }
    @keyframes liveCommentFloat{
      0%{ opacity:0; transform:translate3d(0,18px,0) scale(.96); filter:blur(4px); }
      12%{ opacity:1; transform:translate3d(0,0,0) scale(1); filter:blur(0); }
      72%{ opacity:1; transform:translate3d(10px,-30px,0) scale(1); filter:blur(0); }
      100%{ opacity:0; transform:translate3d(24px,-56px,0) scale(.98); filter:blur(6px); }
    }
    @keyframes liveReactionFloat{
      0%{ opacity:0; transform:translate3d(0,18px,0) scale(.8); filter:blur(3px) drop-shadow(0 10px 20px rgba(0,0,0,.2)); }
      14%{ opacity:1; transform:translate3d(0,0,0) scale(1); filter:blur(0) drop-shadow(0 10px 20px rgba(0,0,0,.2)); }
      76%{ opacity:1; transform:translate3d(-18px,-82px,0) scale(1.05); filter:blur(0) drop-shadow(0 14px 22px rgba(0,0,0,.22)); }
      100%{ opacity:0; transform:translate3d(-30px,-132px,0) scale(.9); filter:blur(6px) drop-shadow(0 14px 22px rgba(0,0,0,.22)); }
    }
    .featured-meta{
      display:block;
      padding:14px 0 0;
      background:transparent;
    }
    .featured-title{
      margin:0;
      font-size:clamp(24px, 2.2vw, 34px);
      line-height:1.18;
      /* font-weight:900; */
      color:#fff;
      letter-spacing:-.02em;
    }
    .featured-toolbar{
      display:grid;
      grid-template-columns:minmax(0, 1fr) 72px auto;
      align-items:end;
      gap:18px;
      width:100%;
    }
    .featured-channel{
      display:flex;
      align-items:center;
      gap:14px;
      min-width:0;
      flex:1 1 360px;
      margin-top:0;
    }
    .featured-avatar{
      width:56px;
      height:56px;
      border-radius:50%;
      overflow:hidden;
      background:linear-gradient(135deg, #4f46e5, #0ea5e9);
      display:flex;
      align-items:center;
      justify-content:center;
      color:#fff;
      font-size:20px;
      font-weight:900;
      flex:0 0 auto;
    }
    .featured-avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .featured-channel-meta{
      min-width:0;
      display:flex;
      flex-direction:column;
      justify-content:center;
    }
    .featured-channel-name{
      font-size:20px;
      font-weight:900;
      color:#fff;
      line-height:1.1;
    }
    .featured-channel-sub{
      margin-top:2px;
      color:var(--muted);
      font-size:16px;
      line-height:1.35;
    }
    .featured-subscribe{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:136px;
      height:46px;
      margin-left:14px;
      padding:0 20px;
      border-radius:999px;
      background:#fff;
      color:#0f0f0f;
      font-size:18px;
      font-weight:900;
      flex:0 0 auto;
    }
    .featured-actions{
      display:flex;
      align-items:center;
      gap:12px;
      flex:0 1 auto;
      flex-wrap:wrap;
      justify-content:flex-start;
      align-self:end;
      margin-top:0;
    }
    .featured-action-pill,
    .featured-more{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      min-height:52px;
      padding:0 20px;
      border-radius:999px;
      /* background:#272727; */
      color:#fff;
      font-size:18px;
      font-weight:800;
    }
    .featured-action-pill i,
    .featured-more i{
      font-size:22px;
    }
    .featured-more{
      width:48px;
      min-width:48px;
      padding:0;
    }
    .live-rail{
      position:sticky;
      top:14px;
      background:rgba(255,255,255,.03);
      border:1px solid var(--line);
      /* border-radius:28px; */
      box-shadow: 0 18px 50px rgba(0,0,0,.18);
      overflow:hidden;
      height:calc(85vh - 28px);
      max-height:calc(85vh - 28px);
      min-height:0;
      display:flex;
      flex-direction:column;
    }
    .live-rail-head{
      padding:18px 18px 14px;
      border-bottom:1px solid var(--line);
      background:rgba(255,255,255,.03);
    }
    .live-rail-ad{
      margin:10px;
      border:1px solid rgba(255,255,255,.1);
      border-radius:22px;
      overflow:hidden;
      background:#171717;
    }
    .live-rail-ad-top{
      min-height:110px;
      padding:20px 18px;
      background:linear-gradient(90deg, #101010 0%, #181818 100%);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
      color:#fff;
    }
    .live-rail-ad-logo{
      display:inline-flex;
      align-items:center;
      gap:12px;
      font-size:20px;
      font-weight:900;
    }
    .live-rail-ad-logo span{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:48px;
      height:34px;
      border-radius:12px;
      background:#ff0033;
      font-size:22px;
    }
    .live-rail-ad-copy{
      font-size:16px;
      line-height:1.2;
      font-weight:900;
      text-align:right;
      max-width:200px;
    }
    .live-rail-ad-body{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
      padding:18px;
    }
    .live-rail-ad-brand{
      display:flex;
      align-items:center;
      gap:12px;
      min-width:0;
    }
    .live-rail-ad-avatar{
      width:48px;
      height:48px;
      border-radius:50%;
      background:#fff;
      color:#ff0033;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:26px;
      flex:0 0 auto;
    }
    .live-rail-ad-meta strong{
      display:block;
      font-size:18px;
      color:#fff;
      line-height:1.1;
    }
    .live-rail-ad-meta span{
      display:block;
      margin-top:4px;
      color:var(--muted);
      font-size:13px;
    }
    .live-rail-ad-cta{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:110px;
      height:46px;
      padding:0 18px;
      border-radius:999px;
      background:#2b2b2b;
      color:#fff;
      font-size:15px;
      font-weight:900;
      flex:0 0 auto;
    }
    .live-rail-title{
      margin:0;
      font-size:18px;
      font-weight:900;
      color:#fff;
    }
    .live-rail-sub{
      margin:6px 0 0;
      font-size:13px;
      color:var(--muted);
    }
    .live-rail-list{
      flex:1;
      min-height:0;
      overflow-y:auto;
      padding:10px;
      display:grid;
      gap:10px;
    }
    .list {
      display: grid;
      gap: 18px;
    }
    .row-card {
      display: grid;
      grid-template-columns: minmax(250px, 320px) minmax(0, 1fr);
      gap: 0;
      align-items: stretch;
      min-height: 520px;
      background: linear-gradient(180deg, rgba(43, 28, 30, 0.94) 0%, rgba(20, 18, 22, 0.98) 100%);
      border: 1px solid var(--line);
      border-radius: 28px;
      box-shadow: 0 18px 50px rgba(0,0,0,.22);
      overflow: hidden;
      transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
    }
    .row-card:hover {
      transform: translateY(-1px);
      border-color: rgba(255,255,255,.16);
      box-shadow: 0 22px 56px rgba(0,0,0,.28);
    }
    .rail-card{
      display:grid;
      grid-template-columns:148px minmax(0,1fr);
      gap:12px;
      align-items:start;
      padding:10px;
      /* border-radius:20px; */
      background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.06);
      transition:background .16s ease, border-color .16s ease, transform .16s ease;
    }
    .rail-card:hover{
      background:rgba(255,255,255,.06);
      border-color:rgba(255,255,255,.12);
      transform:translateY(-1px);
    }
    .rail-card.is-active{
      background:rgba(255,255,255,.08);
      border-color:rgba(255,255,255,.18);
      box-shadow:inset 0 0 0 1px rgba(255,255,255,.06);
    }
    .rail-preview{
      position:relative;
      min-height:104px;
      /* border-radius:16px; */
      overflow:hidden;
      background:
        radial-gradient(circle at top center, rgba(255,255,255,.22) 0%, rgba(255,255,255,.05) 22%, transparent 50%),
        linear-gradient(180deg, rgba(245,231,191,.92) 0%, rgba(186,180,164,.76) 48%, rgba(50,45,47,.92) 100%);
    }
    .rail-preview::after{
      content:"";
      position:absolute;
      inset:0;
      background:linear-gradient(180deg, rgba(0,0,0,.02), rgba(0,0,0,.22));
    }
    .rail-live-badge{
      position:absolute;
      top:8px;
      left:8px;
      z-index:2;
      display:inline-flex;
      align-items:center;
      gap:6px;
      min-height:24px;
      padding:0 9px;
      border-radius:999px;
      background:rgba(255,47,125,.92);
      color:#fff;
      font-size:11px;
      font-weight:900;
      letter-spacing:.04em;
      text-transform:uppercase;
    }
    .rail-preview-kicker{
      position:absolute;
      inset:auto 10px 10px 10px;
      z-index:2;
      color:#fff;
      font-size:11px;
      font-weight:800;
      letter-spacing:.04em;
      text-shadow:0 1px 2px rgba(0,0,0,.45);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .rail-main{
      min-width:0;
      padding-top:2px;
    }
    .rail-title{
      margin:0 0 6px;
      font-size:16px;
      line-height:1.25;
      font-weight:900;
      color:#fff;
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }
    .rail-host{
      font-size:13px;
      font-weight:800;
      color:#d7e6ff;
    }
    .rail-meta{
      margin-top:6px;
      font-size:12px;
      color:var(--muted);
      line-height:1.45;
    }
    .row-main {
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      padding: 34px 28px 26px;
      background: linear-gradient(180deg, rgba(255,255,255,.05) 0%, rgba(255,255,255,.02) 100%);
      min-width: 0;
    }
    .live-card-stage{
      position: relative;
      min-width: 0;
      background:
        radial-gradient(circle at top center, rgba(255,255,255,.22) 0%, rgba(255,255,255,.05) 22%, transparent 50%),
        linear-gradient(180deg, rgba(245,231,191,.92) 0%, rgba(186,180,164,.76) 48%, rgba(50,45,47,.92) 100%);
      overflow: hidden;
      display:flex;
      align-items:stretch;
      justify-content:stretch;
    }
    .live-stage-frame{
      position:absolute;
      inset:0;
      background:
        linear-gradient(180deg, rgba(0,0,0,.02), rgba(0,0,0,.08)),
        radial-gradient(circle at bottom center, rgba(0,0,0,.26), transparent 40%);
    }
    .live-stage-banner{
      position:absolute;
      top:18px;
      left:50%;
      transform:translateX(-50%);
      z-index:3;
      font-size:clamp(24px, 3vw, 56px);
      font-weight:900;
      letter-spacing:.02em;
      color:#ffd221;
      text-shadow:0 3px 0 rgba(0,0,0,.55);
      text-transform:uppercase;
      white-space:nowrap;
    }
    .live-stage-topbar{
      position:absolute;
      top:18px;
      left:18px;
      right:18px;
      z-index:3;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      pointer-events:none;
    }
    .live-stage-pill-row{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }
    .live-stage-pill,
    .live-stage-status{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:34px;
      padding:0 12px;
      border-radius:999px;
      background:rgba(17,17,17,.74);
      color:#fff;
      font-size:12px;
      font-weight:800;
      backdrop-filter:blur(8px);
    }
    .live-stage-status{
      min-width:110px;
      justify-content:flex-start;
      gap:8px;
    }
    .live-stage-status::before{
      content:"";
      width:8px;
      height:8px;
      border-radius:50%;
      background:#3cff88;
      box-shadow:0 0 0 4px rgba(60,255,136,.18);
    }
    .live-stage-overlay{
      position:absolute;
      inset:0;
      display:flex;
      align-items:flex-end;
      justify-content:center;
      z-index:2;
      padding:24px 26px 28px;
      background:linear-gradient(180deg, rgba(0,0,0,.02) 0%, rgba(0,0,0,.08) 46%, rgba(0,0,0,.38) 100%);
    }
    .watch-pill{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:14px;
      min-height:74px;
      padding:0 36px;
      border-radius:999px;
      color:#fff;
      border:1px solid rgba(255,255,255,.34);
      background:rgba(255,255,255,.10);
      box-shadow:0 12px 32px rgba(0,0,0,.18);
      font-size:18px;
      font-weight:900;
      backdrop-filter:blur(8px);
    }
    .watch-pill i{font-size:22px;}
    .watch-pill kbd{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:34px;
      height:34px;
      padding:0 10px;
      border-radius:10px;
      background:#fff;
      color:#111;
      font:inherit;
      font-size:18px;
      font-weight:900;
      line-height:1;
      box-shadow:inset 0 -2px 0 rgba(0,0,0,.14);
    }
    .live-stage-character{
      position:absolute;
      right:24px;
      bottom:34px;
      z-index:2;
      width:min(20vw, 240px);
      max-width:240px;
      aspect-ratio:3 / 4;
      border-radius:24px 24px 18px 18px;
      background:
        radial-gradient(circle at 50% 18%, #f7f0bd 0%, #e9cb70 15%, transparent 16%),
        linear-gradient(180deg, rgba(255,255,255,.75) 0%, rgba(255,255,255,.58) 12%, transparent 13%),
        linear-gradient(180deg, #fafafa 0%, #cfcfd6 38%, #21262c 39%, #101317 100%);
      box-shadow: 0 20px 40px rgba(0,0,0,.24);
      opacity:.96;
      pointer-events:none;
    }
    .live-stage-character::before{
      content:"";
      position:absolute;
      inset:12% 16% 22%;
      border-radius:40% 40% 30% 30%;
      background:
        radial-gradient(circle at 35% 48%, #f34b53 0%, #f34b53 9%, #1d0e10 10%, transparent 13%),
        radial-gradient(circle at 65% 48%, #f34b53 0%, #f34b53 9%, #1d0e10 10%, transparent 13%),
        radial-gradient(circle at 50% 68%, #d26e65 0%, #d26e65 7%, transparent 8%),
        linear-gradient(180deg, #f7f7fb 0%, #d7d8df 28%, #f5d7c9 29%, #f1d0c3 78%, #ddb7a4 100%);
      box-shadow: inset 0 -18px 26px rgba(0,0,0,.08);
    }
    .live-stage-controls{
      position:absolute;
      left:22px;
      bottom:18px;
      z-index:3;
      display:flex;
      gap:14px;
      align-items:center;
    }
    .live-stage-control,
    .live-stage-volume{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:52px;
      height:52px;
      border-radius:50%;
      background:rgba(0,0,0,.42);
      color:#fff;
      font-size:26px;
      backdrop-filter:blur(10px);
    }
    .live-stage-volume{
      position:absolute;
      right:22px;
      bottom:18px;
      z-index:3;
    }
    .topline {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 18px;
    }
    .live-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      background: rgba(255, 47, 125, 0.18);
      color: #ffd6e6;
      padding: 6px 10px;
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    .live-chip::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--live);
    }
    .host-name {
      font-size: 22px;
      font-weight: 800;
      color: var(--text);
    }
    .session-title {
      margin: 0 0 6px;
      font-size: clamp(36px, 4vw, 58px);
      line-height: 1.02;
      letter-spacing: -0.02em;
    }
    .session-desc {
      margin: 0;
      color: var(--muted);
      line-height: 1.45;
      font-size: 15px;
      max-width: 260px;
    }
    .meta-row {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 20px;
    }
    .meta-pill {
      border-radius: 999px;
      background: rgba(255,255,255,.08);
      color: #fff;
      padding: 6px 10px;
      font-size: 12px;
      font-weight: 700;
    }
    .row-actions {
      margin-top: 26px;
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .host-block{
      display:flex;
      align-items:center;
      gap:14px;
      margin-top:18px;
    }
    .watch-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 170px;
      height: 48px;
      border-radius: 14px;
      color: #1b1013;
      font-size: 15px;
      font-weight: 800;
      background: linear-gradient(180deg, #ffd54a 0%, #ffbd2e 100%);
      box-shadow: 0 12px 24px rgba(255, 189, 46, 0.24);
    }
    .watch-note {
      color: var(--muted);
      font-size: 12px;
      text-align: left;
    }
    .mini-avatar{
      width:58px;
      height:58px;
      border-radius:50%;
      overflow:hidden;
      background:linear-gradient(135deg, #4f46e5, #0ea5e9);
      display:flex;
      align-items:center;
      justify-content:center;
      color:#fff;
      font-weight:900;
      font-size:20px;
      flex:0 0 auto;
      box-shadow:0 10px 22px rgba(0,0,0,.22);
    }
    .mini-avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .host-meta strong{
      display:block;
      font-size:22px;
      line-height:1.1;
    }
    .host-meta span{
      display:block;
      margin-top:3px;
      color:var(--muted);
      font-size:16px;
    }
    .empty {
      padding: 48px 28px;
      text-align: center;
      background: linear-gradient(180deg, rgba(36, 41, 48, 0.98) 0%, rgba(23, 26, 32, 0.98) 100%);
      border: 1px solid var(--line);
      border-radius: 28px;
      box-shadow: var(--shadow);
    }
    .empty h2 {
      margin: 0 0 10px;
      font-size: 28px;
      letter-spacing: -0.03em;
    }
    .empty p {
      margin: 0;
      color: var(--muted);
      font-size: 15px;
      line-height: 1.5;
    }
    @media (max-width: 1024px) {
      .hero{
        left:0;
        flex-wrap:wrap;
        padding:12px;
      }
      .yt-topbar-center{ order:3; width:100%; }
      .search-card,
      .yt-search-shell{ width:100%; }
    }
    @media (max-width: 991.98px) {
      .live-main {
        margin-left: 0;
        padding: 100px 12px 18px;
      }
      .live-shell{
        grid-template-columns:1fr;
      }
      .featured-live,
      .live-rail{
        position:static;
        height:auto;
        max-height:none;
        min-height:auto;
      }
      .featured-toolbar{
        display:flex;
        flex-direction:column;
        align-items:stretch;
      }
      .featured-live-comments{
        width:min(60%, 430px);
      }
      .featured-player-host{
        max-width:min(54%, 420px);
      }
      .featured-live-bottom{
        display:flex;
        flex-wrap:wrap;
        gap:12px;
        max-width:100%;
      }
      .featured-meta-head{
        align-items:flex-start;
        flex-wrap:wrap;
      }
      .featured-live-reactions-overlay{
        gap:10px;
        flex-wrap:wrap;
      }
      .featured-live-reactions{
        margin-left:0;
        border-left:0;
        padding-left:0;
      }
      .featured-channel{
        flex-wrap:wrap;
      }
      .featured-subscribe{
        margin-left:0;
      }
      .featured-actions{
        justify-content:flex-start;
      }
      .row-card {
        grid-template-columns: 1fr;
        min-height:auto;
      }
      .row-main {
        padding: 24px 20px 22px;
      }
      .live-card-stage{
        min-height:360px;
      }
      .live-stage-character{
        width:min(34vw, 180px);
        right:14px;
        bottom:22px;
      }
      .watch-pill{
        min-height:62px;
        padding:0 24px;
        font-size:16px;
      }
    }
    @media (max-width: 640px) {
      .yt-brand{ font-size:20px; }
      .yt-topbar-right .yt-icon-btn:nth-child(1){ display:none; }
      .featured-title{ font-size:24px; }
      .featured-player{ aspect-ratio: 16 / 10; }
      .featured-player-brand{
        left:142px;
        font-size:16px;
      }
      .featured-live-stats{
        top:auto;
        left:14px;
        bottom:14px;
        gap:8px;
      }
      .featured-player-host{
        top:60px;
        left:14px;
        right:14px;
        max-width:none;
        padding:8px 12px 8px 8px;
        border-radius:18px;
      }
      .featured-player-badge,
      .featured-player-viewers{
        padding:8px 10px;
        font-size:12px;
      }
      .featured-player-cta{
        top:12px;
        right:12px;
        min-height:38px;
        padding:0 14px;
        font-size:13px;
      }
      .featured-live-comments{
        left:14px;
        right:14px;
        bottom:110px;
        width:auto;
        gap:10px;
      }
      .featured-live-comment-avatar{
        width:40px;
        height:40px;
      }
      .featured-live-comment-bubble{
        padding:12px 14px;
      }
      .featured-live-bottom{
        gap:10px;
        max-width:100%;
      }
      .featured-meta-head{
        gap:12px;
        margin-bottom:14px;
      }
      .featured-live-reactions-overlay{
        width:100%;
        justify-content:flex-start;
        gap:10px;
        flex-wrap:wrap;
      }
      .featured-live-share{
        min-height:46px;
        padding:0 18px;
        font-size:16px;
      }
      .featured-live-input{
        min-height:46px;
        font-size:16px;
      }
      .featured-live-chip{
        width:48px;
        height:48px;
        font-size:24px;
      }
      .featured-live-reactions{
        gap:10px;
      }
      .featured-live-reaction{
        width:50px;
        height:50px;
        font-size:30px;
      }
      .featured-live-burst{
        right:14px;
        top:26%;
        bottom:124px;
      }
      .featured-live-burst span{
        width:36px;
        height:36px;
        font-size:22px !important;
      }
      .live-rail-list{ max-height:none; }
      .rail-card{ grid-template-columns:116px minmax(0,1fr); }
      .featured-channel{
        flex-direction:row;
        align-items:center;
      }
      .featured-action-pill{
        padding:0 14px;
        font-size:15px;
      }
      .yt-chat-switch{ padding:4px; gap:4px; }
      .yt-chat-tab{
        min-width:112px;
        min-height:34px;
        padding:0 14px;
        font-size:13px;
      }
    }
  </style>
  <style>
    body{
      --pl-text:#132033;
      --pl-muted:#5e6c7c;
      --pl-border:rgba(15,23,42,.12);
      --pl-border-strong:rgba(15,23,42,.16);
      --pl-shell:rgba(255,255,255,.74);
      --pl-panel:#ffffff;
      --pl-control-bg:#ffffff;
      --pl-control-soft:#eef3fb;
      --pl-control-border:rgba(15,23,42,.14);
      --pl-control-placeholder:#667085;
      --pl-tab-shell:#eef3fb;
      --pl-tab-active:#17324d;
      --pl-tab-active-text:#ffffff;
      --pl-action-bg:#eef3fb;
      --pl-action-text:#112134;
      --pl-action-hover:#e3ebf5;
      --pl-subscribe-bg:#112134;
      --pl-subscribe-text:#ffffff;
      --pl-summary-bg:rgba(15,23,42,.06);
      --pl-row-bg:linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(242,246,251,.98) 100%);
      --pl-row-main-bg:linear-gradient(180deg, rgba(255,255,255,.92) 0%, rgba(245,248,252,.98) 100%);
      --pl-card-bg:#ffffff;
      --pl-card-hover:#f6f9fc;
      --pl-card-active:#eef3fb;
      --pl-rail-bg:rgba(255,255,255,.78);
      --pl-rail-head:rgba(255,255,255,.88);
      --pl-empty-bg:linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(243,247,252,.98) 100%);
      --pl-shadow:0 18px 44px rgba(15,23,42,.10);
      --pl-host:#28517b;
      background:
        radial-gradient(circle at top left, rgba(37,99,235,.08), transparent 24%),
        radial-gradient(circle at right center, rgba(14,165,233,.10), transparent 30%),
        linear-gradient(180deg, #f7fbff 0%, #edf3f9 40%, #e7edf5 100%);
      color:var(--pl-text);
    }
    html[data-theme="dark"] body{
      --pl-text:#f5f7fb;
      --pl-muted:rgba(238,243,255,.72);
      --pl-border:rgba(255,255,255,.08);
      --pl-border-strong:rgba(255,255,255,.14);
      --pl-shell:rgba(255,255,255,.02);
      --pl-panel:#262b31;
      --pl-control-bg:#121212;
      --pl-control-soft:#222;
      --pl-control-border:#3a3a3a;
      --pl-control-placeholder:#8a94a6;
      --pl-tab-shell:#242d39;
      --pl-tab-active:#3d4958;
      --pl-tab-active-text:#fff;
      --pl-action-bg:rgba(255,255,255,.08);
      --pl-action-text:#fff;
      --pl-action-hover:rgba(255,255,255,.12);
      --pl-subscribe-bg:#fff;
      --pl-subscribe-text:#0f0f0f;
      --pl-summary-bg:rgba(255,255,255,.08);
      --pl-row-bg:linear-gradient(180deg, rgba(43, 28, 30, 0.94) 0%, rgba(20, 18, 22, 0.98) 100%);
      --pl-row-main-bg:linear-gradient(180deg, rgba(255,255,255,.05) 0%, rgba(255,255,255,.02) 100%);
      --pl-card-bg:rgba(255,255,255,.03);
      --pl-card-hover:rgba(255,255,255,.06);
      --pl-card-active:rgba(255,255,255,.08);
      --pl-rail-bg:rgba(255,255,255,.03);
      --pl-rail-head:rgba(255,255,255,.03);
      --pl-empty-bg:linear-gradient(180deg, rgba(36,41,48,.98) 0%, rgba(23,26,32,.98) 100%);
      --pl-shadow:0 28px 64px rgba(0,0,0,.28);
      --pl-host:#d7e6ff;
      background:#171d24 !important;
      background-image:none !important;
    }
    .live-main,
    .live-wrap,
    .hero,
    .row-card,
    .row-main,
    .live-rail,
    .live-rail-head,
    .live-rail-ad,
    .live-rail-ad-body,
    .empty{
      color:var(--pl-text);
    }
    .hero{
      background:var(--pl-shell);
      border:0;
      border-bottom:1px solid var(--pl-border);
      border-radius:0;
      box-shadow:0 10px 28px rgba(15,23,42,.08);
      backdrop-filter:blur(14px);
    }
    .yt-icon-btn,
    .yt-brand{
      color:var(--pl-text);
    }
    .search-input{
      background:var(--pl-control-bg);
      color:var(--pl-text);
      border-color:var(--pl-control-border);
    }
    .search-input::placeholder{
      color:var(--pl-control-placeholder);
    }
    .search-btn,
    .yt-mic-btn{
      background:var(--pl-control-soft);
      color:var(--pl-text);
      border-color:var(--pl-control-border);
    }
    .yt-chat-switch{
      background:var(--pl-tab-shell);
      border-color:var(--pl-control-border);
      box-shadow:none;
    }
    .yt-chat-tab{
      color:var(--pl-muted);
    }
    .yt-chat-tab.is-active{
      background:var(--pl-tab-active);
      color:var(--pl-tab-active-text);
      box-shadow:none;
    }
    .summary-bar{
      color:var(--pl-muted);
    }
    .summary-pill{
      background:var(--pl-summary-bg);
      color:var(--pl-text);
    }
    .featured-title,
    .featured-channel-name,
    .live-rail-title,
    .rail-title,
    .host-name,
    .session-title,
    .host-meta strong,
    .empty h2{
      color:var(--pl-text);
    }
    .featured-channel-sub,
    .rail-meta,
    .live-rail-sub,
    .watch-note,
    .host-meta span,
    .empty p,
    .session-desc{
      color:var(--pl-muted);
    }
    .featured-subscribe{
      background:var(--pl-subscribe-bg);
      color:var(--pl-subscribe-text);
    }
    .featured-action-pill,
    .featured-more,
    .featured-live-share{
      background:var(--pl-action-bg);
      color:var(--pl-action-text);
    }
    .featured-action-pill:hover,
    .featured-more:hover,
    .featured-live-share:hover{
      background:var(--pl-action-hover);
    }
    .featured-live-input{
      background:var(--pl-control-bg);
      color:var(--pl-text);
      border:1px solid var(--pl-control-border);
      backdrop-filter:none;
    }
    .featured-live-input::placeholder{
      color:var(--pl-control-placeholder);
    }
    .featured-live-send{
      background:#0d61bc;
      color:#fff;
      box-shadow:none;
    }
    .live-rail{
      background:var(--pl-rail-bg);
      border-color:var(--pl-border);
      box-shadow:var(--pl-shadow);
    }
    .live-rail-head{
      background:var(--pl-rail-head);
      border-bottom-color:var(--pl-border);
    }
    .live-rail-ad{
      background:var(--pl-panel);
      border-color:var(--pl-border);
    }
    .live-rail-ad-body{
      background:var(--pl-panel);
    }
    .live-rail-ad-body .live-rail-ad-meta strong{
      color:var(--pl-text);
    }
    .live-rail-ad-body .live-rail-ad-meta span{
      color:var(--pl-muted);
    }
    .live-rail-ad-avatar{
      background:var(--pl-control-soft);
      color:#ff0033;
    }
    .live-rail-ad-cta{
      background:var(--pl-action-bg);
      color:var(--pl-action-text);
    }
    .row-card{
      background:var(--pl-row-bg);
      border-color:var(--pl-border);
      box-shadow:var(--pl-shadow);
    }
    .row-card:hover{
      border-color:var(--pl-border-strong);
      box-shadow:0 22px 56px rgba(15,23,42,.14);
    }
    html[data-theme="dark"] .row-card:hover{
      box-shadow:0 22px 56px rgba(0,0,0,.28);
    }
    .row-main{
      background:var(--pl-row-main-bg);
      color:var(--pl-text);
    }
    .meta-pill{
      background:var(--pl-summary-bg);
      color:var(--pl-text);
    }
    .rail-card{
      background:var(--pl-card-bg);
      border-color:var(--pl-border);
    }
    .rail-card:hover{
      background:var(--pl-card-hover);
      border-color:var(--pl-border-strong);
    }
    .rail-card.is-active{
      background:var(--pl-card-active);
      border-color:rgba(13,97,188,.24);
      box-shadow:inset 0 0 0 1px rgba(13,97,188,.12);
    }
    html[data-theme="dark"] .rail-card.is-active{
      border-color:rgba(255,255,255,.18);
      box-shadow:inset 0 0 0 1px rgba(255,255,255,.06);
    }
    .rail-host{
      color:var(--pl-host);
    }
    .empty{
      background:var(--pl-empty-bg);
      border-color:var(--pl-border);
      box-shadow:var(--pl-shadow);
    }
  </style>
</head>
<body>
  <?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
  <main class="live-main">
    <div class="live-wrap">
      <section class="hero">
        <div class="yt-topbar-left">
          <button class="yt-icon-btn" type="button" aria-label="Menu"><i class="fa fa-bars"></i></button>
          <div class="yt-brand"> Talentra</div>
        </div>
        <div class="yt-topbar-center">
          <form class="search-card" id="feedTopbarSearchForm" method="get" action="public_live.php">
            <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
            <div class="search-row">
              <div class="yt-search-shell">
                <input id="feedTopbarSearch" class="search-input" type="search" name="q" value="<?php echo h($q); ?>" placeholder="Search">
                <button class="search-btn" type="submit" aria-label="Search"><i class="fa fa-search"></i></button>
              </div>
              <button class="yt-mic-btn" type="button" aria-label="Voice search"><i class="fa fa-microphone"></i></button>
            </div>
          </form>
        </div>
        <div class="yt-topbar-right">
          <div class="yt-chat-switch" aria-label="Chat mode">
            <a class="yt-chat-tab<?php echo $scope === 'friends' ? ' is-active' : ''; ?>" href="<?php echo h($friendLiveUrl); ?>" aria-pressed="<?php echo $scope === 'friends' ? 'true' : 'false'; ?>">Friend Live</a>
            <a class="yt-chat-tab<?php echo $scope === 'public' ? ' is-active' : ''; ?>" href="<?php echo h($publicLiveUrl); ?>" aria-pressed="<?php echo $scope === 'public' ? 'true' : 'false'; ?>">Public Live</a>
          </div>
          <button class="yt-icon-btn" type="button" aria-label="More"><i class="fa fa-ellipsis-v"></i></button>
        </div>
      </section>

      <?php if (!$rows): ?>
        <section class="empty">
          <h2><?php echo $scope === 'friends' ? 'No friend lives right now' : 'No public lives right now'; ?></h2>
          <p>
            <?php if ($scope === 'friends'): ?>
              When a host starts a friends-only room, it appears in Friend Live for accepted friends only.
            <?php else: ?>
              When a host starts a public room, it appears in Public Live and anyone can open it.
            <?php endif; ?>
          </p>
        </section>
      <?php else: ?>
        <?php
          $featuredRow = $rows[0];
          $featuredDisplayName = trim((string)($featuredRow['name'] ?? $featuredRow['username'] ?? 'Host'));
          $featuredTitle = trim((string)($featuredRow['title'] ?? ($scope === 'friends' ? 'Friends live session' : 'Public live session')));
          $featuredDescription = trim((string)($featuredRow['description'] ?? ''));
          $featuredWatchUrl = 'live_watch.php?live=' . (int)$featuredRow['id'];
          $featuredAvatarUrl = liveAvatarUrl([
              'user_id' => (int)($featuredRow['user_id'] ?? 0),
              'email' => (string)($featuredRow['email'] ?? ''),
              'friend_code' => (string)($featuredRow['friend_code'] ?? ''),
              'username' => (string)($featuredRow['username'] ?? ''),
              'display_name' => $featuredDisplayName,
          ]);
          $featuredAvatarFallback = (string)($featuredRow['avatar_fallback'] ?? liveInitials($featuredDisplayName));
          $featuredShowAvatarImage = (string)($featuredRow['show_avatar_image'] ?? '1') === '1';
          $featuredFriendActionLabel = trim((string)($featuredRow['friend_action_label'] ?? ''));
          $featuredFriendActionUrl = trim((string)($featuredRow['friend_action_url'] ?? ''));
          $featuredDeviceMeta = device_profile_card_meta(
              (string)($featuredRow['device_label'] ?? ''),
              (string)($featuredRow['device_viewport'] ?? '')
          );
          $featuredPlayerClass = 'featured-player' . (!empty($featuredDeviceMeta['phone_shot']) ? ' phone-shot' : '');
          $featuredPlayerStyle = trim((string)($featuredDeviceMeta['style'] ?? ''));
          $featuredStartedText = (liveFmt((string)($featuredRow['started_at'] ?? '')) ?: 'recently') . device_profile_meta_suffix((string)($featuredRow['device_label'] ?? ''));
        ?>
        <section class="live-shell">
          <section class="featured-live">
            <div id="featuredLiveLink" class="featured-live-frame" role="button" tabindex="0" data-live-watch-modal="1" data-live-watch-url="<?php echo h($featuredWatchUrl); ?>" aria-label="Open live modal">
              <div class="<?php echo h($featuredPlayerClass); ?>"<?= $featuredPlayerStyle !== '' ? ' style="' . h($featuredPlayerStyle) . '"' : '' ?>>
                <iframe
                  id="featuredLiveEmbed"
                  class="featured-player-embed"
                  src="<?php echo h($featuredWatchUrl . '&embed=1'); ?>"
                  title="<?php echo h($featuredTitle); ?>"
                  loading="eager"
                  allow="autoplay; fullscreen; picture-in-picture"
                  referrerpolicy="same-origin"
                ></iframe>
                <img id="featuredLiveSnapshot" class="featured-player-snapshot" alt="Live preview" aria-hidden="true">
                
                <div class="featured-player-host" data-live-overlay-ignore="1">
                  <div id="featuredAvatarBox" class="featured-avatar">
                    <?php if ($featuredShowAvatarImage): ?>
                      <img id="featuredAvatarImg" src="<?php echo h($featuredAvatarUrl); ?>" alt="<?php echo h($featuredDisplayName); ?>" onerror="this.style.display='none';this.parentNode.textContent='<?php echo h($featuredAvatarFallback); ?>';">
                    <?php else: ?>
                      <?php echo h($featuredAvatarFallback); ?>
                    <?php endif; ?>
                  </div>
                  <div class="featured-channel-meta">
                    <div id="featuredChannelName" class="featured-channel-name"><?php echo h($featuredDisplayName); ?></div>
                    <div class="featured-channel-sub">Started <?php echo h($featuredStartedText); ?></div>
                  </div>
                </div>

                <div class="featured-live-stats" data-live-overlay-ignore="1">
                  <div id="featuredPlayerBadge" class="featured-player-badge">Live</div>
                  <div id="featuredViewerBadge" class="featured-player-viewers"><i class="fa fa-eye"></i> <?php echo (int)($featuredRow['viewer_count'] ?? 0); ?></div>
                </div>
                
                <a
                  id="featuredFriendAction"
                  class="featured-player-cta"
                  data-live-overlay-ignore="1"
                  href="<?php echo h($featuredFriendActionUrl !== '' ? $featuredFriendActionUrl : '#'); ?>"
                  data-friend-action-kind="<?php echo h((string)($featuredRow['friend_action_kind'] ?? '')); ?>"
                  data-peer-id="<?php echo (int)($featuredRow['host_id'] ?? 0); ?>"
                  <?php echo ($featuredFriendActionLabel !== '' && $featuredFriendActionUrl !== '') ? '' : 'style="display:none"'; ?>
                ><?php echo h($featuredFriendActionLabel !== '' ? $featuredFriendActionLabel : 'Add Friend'); ?></a>
                <div id="featuredLiveComments" class="featured-live-comments" data-live-overlay-ignore="1"></div>
                <div id="featuredLiveBurst" class="featured-live-burst" aria-hidden="true"></div>
                
              </div>
            </div>
            <div class="featured-meta">
              <div class="featured-meta-head">
                <h2 id="featuredTitle" class="featured-title"><?php echo h($featuredTitle); ?></h2>
                <div class="featured-live-reactions-overlay" data-live-overlay-ignore="1">
                  <a class="featured-live-reaction like" type="button" data-live-reaction="like">👍</a>
                  <a class="featured-live-reaction love" type="button" data-live-reaction="love">❤</a>
                  <a class="featured-live-reaction haha" type="button" data-live-reaction="clap">🥰</a>
                  <a class="featured-live-reaction wow" type="button" data-live-reaction="wow">😮</a>
                  <a class="featured-live-reaction angry" type="button" data-live-reaction="fire">😡</a>
                </div>
              </div>
              <div class="featured-toolbar">
                <div class="featured-live-bottom" data-live-overlay-ignore="1">
                  <textarea id="featuredCommentInput" class="featured-live-input" rows="1" placeholder="Write a comment..."></textarea>
                </div>
                <a id="featuredCommentSend" class="featured-live-send" type="button" aria-label="Send comment"><i class="fa fa-paper-plane"></i></a>
                <div class="featured-actions">
                  <span id="featuredLikes" class="featured-action-pill"><i class="fa fa-thumbs-up"></i> <?php echo (int)($featuredRow['viewer_count'] ?? 0); ?></span>
                  <span class="featured-action-pill"><i class="fa fa-share"></i> Share</span>
                  <span id="featuredCommentSummary" class="featured-action-pill"><i class="fa fa-comments-o"></i> 0 comments</span>
                  <span class="featured-more"><i class="fa fa-ellipsis-h"></i></span>
                </div>
              </div>
            </div>
          </section>

          <aside class="live-rail" aria-label="Other live rooms">
            

            <div class="live-rail-head">
              <div class="summary-bar">
                <!-- <div class="summary-pill"><?php echo (int)count($rows); ?> active public live rooms</div> -->
                <div>
                  <?php
                    if ($q !== '') {
                        echo 'Filtered by "' . h($q) . '"';
                    } else {
                        echo $scope === 'friends'
                            ? 'Showing the latest active friend live sessions'
                            : 'Showing the latest active public live sessions';
                    }
                  ?>
                </div>
              </div>
              <h2 class="live-rail-title">Up next</h2>
            </div>
            <div class="live-rail-list">
              <?php foreach ($rows as $row): ?>
                <?php
                  $displayName = trim((string)($row['name'] ?? $row['username'] ?? 'Host'));
                  $title = trim((string)($row['title'] ?? ($scope === 'friends' ? 'Friends live session' : 'Public live session')));
                  $watchUrl = 'live_watch.php?live=' . (int)$row['id'];
                ?>
                <?php
                  $railStarted = (liveFmt((string)($row['started_at'] ?? '')) ?: 'recently') . device_profile_meta_suffix((string)($row['device_label'] ?? ''));
                  $railAvatarUrl = liveAvatarUrl([
                      'user_id' => (int)($row['user_id'] ?? 0),
                      'email' => (string)($row['email'] ?? ''),
                      'friend_code' => (string)($row['friend_code'] ?? ''),
                      'username' => (string)($row['username'] ?? ''),
                      'display_name' => $displayName,
                  ]);
                  $railDescription = trim((string)($row['description'] ?? ''));
                ?>
                <a
                  class="rail-card<?= ((int)$row['id'] === (int)$featuredRow['id']) ? ' is-active' : '' ?>"
                  href="<?php echo h($watchUrl); ?>"
                  data-live-select="1"
                  data-live-id="<?php echo (int)$row['id']; ?>"
                  data-live-watch-url="<?php echo h($watchUrl); ?>"
                  data-live-title="<?php echo h($title); ?>"
                  data-live-display-name="<?php echo h($displayName); ?>"
                  data-live-username="<?php echo h((string)($row['username'] ?? 'host')); ?>"
                  data-live-viewers="<?php echo (int)($row['viewer_count'] ?? 0); ?>"
                  data-live-started="<?php echo h($railStarted); ?>"
                  data-live-avatar-url="<?php echo h($railAvatarUrl); ?>"
                  data-live-avatar-fallback="<?php echo h((string)($row['avatar_fallback'] ?? liveInitials($displayName))); ?>"
                  data-live-show-avatar-image="<?php echo h((string)($row['show_avatar_image'] ?? '1')); ?>"
                  data-live-friend-action-label="<?php echo h((string)($row['friend_action_label'] ?? '')); ?>"
                  data-live-friend-action-kind="<?php echo h((string)($row['friend_action_kind'] ?? '')); ?>"
                  data-live-friend-action-url="<?php echo h((string)($row['friend_action_url'] ?? '')); ?>"
                  data-live-peer-id="<?php echo (int)($row['host_id'] ?? 0); ?>"
                  data-live-description="<?php echo h($railDescription !== '' ? $railDescription : 'Public live session is running now. Open this room to watch the host live.'); ?>"
                >
                  <div class="rail-preview">
                    <span class="rail-live-badge">Live</span>
                    <div class="rail-preview-kicker"><?php echo h('@' . ((string)($row['username'] ?? 'host'))); ?></div>
                  </div>
                  <div class="rail-main">
                    <h3 class="rail-title"><?php echo h($title); ?></h3>
                    <div class="rail-host"><?php echo h($displayName); ?></div>
                    <div class="rail-meta"><?php echo (int)($row['viewer_count'] ?? 0); ?> watching · <?php echo h($railStarted); ?></div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </aside>
        </section>
      <?php endif; ?>
    </div>
  </main>

  <script>
    (function () {
      document.addEventListener('click', function (event) {
        const rail = event.target.closest('[data-live-select="1"]');
        if (rail) {
          event.preventDefault();

          document.querySelectorAll('.rail-card.is-active').forEach(function(card){
            card.classList.remove('is-active');
          });
          rail.classList.add('is-active');

          const featuredLink = document.getElementById('featuredLiveLink');
          const featuredTitle = document.getElementById('featuredTitle');
          const featuredName = document.getElementById('featuredChannelName');
          const featuredSub = document.getElementById('featuredChannelSub');
          const featuredLikes = document.getElementById('featuredLikes');
          const featuredViewerBadge = document.getElementById('featuredViewerBadge');
          const featuredEmbed = document.getElementById('featuredLiveEmbed');
          const featuredAvatarImg = document.getElementById('featuredAvatarImg');
          const featuredAvatarBox = document.getElementById('featuredAvatarBox');
          const featuredBadge = document.getElementById('featuredPlayerBadge');
          const featuredFriendAction = document.getElementById('featuredFriendAction');
          if (window.setFeaturedLiveId) {
            window.setFeaturedLiveId(Number(rail.getAttribute('data-live-id') || 0));
          }

          const watchUrl = String(rail.getAttribute('data-live-watch-url') || '').trim();
          const title = String(rail.getAttribute('data-live-title') || '').trim();
          const displayName = String(rail.getAttribute('data-live-display-name') || '').trim();
          const username = String(rail.getAttribute('data-live-username') || 'host').trim();
          const viewers = String(rail.getAttribute('data-live-viewers') || '0').trim();
          const started = String(rail.getAttribute('data-live-started') || 'recently').trim();
          const avatarUrl = String(rail.getAttribute('data-live-avatar-url') || '').trim();
          const avatarFallback = String(rail.getAttribute('data-live-avatar-fallback') || 'LV').trim();
          const showAvatarImage = String(rail.getAttribute('data-live-show-avatar-image') || '1') === '1';
          const friendActionLabel = String(rail.getAttribute('data-live-friend-action-label') || '').trim();
          const friendActionKind = String(rail.getAttribute('data-live-friend-action-kind') || '').trim();
          const friendActionUrl = String(rail.getAttribute('data-live-friend-action-url') || '').trim();
          const peerId = Number(rail.getAttribute('data-live-peer-id') || 0);

          if (featuredLink && watchUrl) {
            featuredLink.setAttribute('href', watchUrl);
            featuredLink.setAttribute('data-live-watch-url', watchUrl);
          }
          if (featuredEmbed && watchUrl) {
            featuredEmbed.setAttribute('src', watchUrl + '&embed=1');
            featuredEmbed.setAttribute('title', title || 'Live stream');
          }
          if (featuredTitle) featuredTitle.textContent = title;
          if (featuredName) featuredName.textContent = displayName;
          if (featuredSub) featuredSub.textContent = viewers + ' watching · Started ' + started;
          if (featuredLikes) featuredLikes.innerHTML = '<i class="fa fa-thumbs-up"></i> ' + viewers;
          if (featuredViewerBadge) featuredViewerBadge.innerHTML = '<i class="fa fa-eye"></i> ' + viewers;
          if (featuredBadge) featuredBadge.textContent = 'Live';
          if (featuredAvatarBox) {
            featuredAvatarBox.textContent = '';
            if (showAvatarImage) {
              const img = document.createElement('img');
              img.id = 'featuredAvatarImg';
              img.alt = displayName;
              img.src = avatarUrl;
              img.onerror = function(){
                this.style.display = 'none';
                if (featuredAvatarBox) featuredAvatarBox.textContent = avatarFallback;
              };
              featuredAvatarBox.appendChild(img);
            } else {
              featuredAvatarBox.textContent = avatarFallback;
            }
          }
          if (featuredFriendAction) {
            if (friendActionLabel && friendActionUrl) {
              featuredFriendAction.textContent = friendActionLabel;
              featuredFriendAction.setAttribute('href', friendActionUrl);
              featuredFriendAction.setAttribute('data-friend-action-kind', friendActionKind);
              featuredFriendAction.setAttribute('data-peer-id', String(peerId));
              featuredFriendAction.style.display = '';
            } else {
              featuredFriendAction.style.display = 'none';
            }
          }

          return;
        }

        const overlayIgnore = event.target.closest('[data-live-overlay-ignore="1"]');
        if (overlayIgnore) {
          const actionLink = event.target.closest('a[href]');
          if (actionLink && overlayIgnore.contains(actionLink)) {
            return;
          }
          event.preventDefault();
          return;
        }

        const link = event.target.closest('[data-live-watch-modal="1"]');
      if (!link) return;
        const url = String(link.getAttribute('data-live-watch-url') || '').trim();
        if (!url) return;
        if (typeof window.openHeaderLiveModal !== 'function') return;
        event.preventDefault();
      window.openHeaderLiveModal(url);
      });

      const featuredLiveTrigger = document.getElementById('featuredLiveLink');
      if (featuredLiveTrigger) {
        featuredLiveTrigger.addEventListener('keydown', function(event) {
          if (event.key !== 'Enter' && event.key !== ' ') return;
          if (event.target && event.target.closest && event.target.closest('[data-live-overlay-ignore="1"]')) {
            return;
          }
          const url = String(featuredLiveTrigger.getAttribute('data-live-watch-url') || '').trim();
          if (!url || typeof window.openHeaderLiveModal !== 'function') return;
          event.preventDefault();
          window.openHeaderLiveModal(url);
        });
      }

      const featuredComments = document.getElementById('featuredLiveComments');
      const featuredBurst = document.getElementById('featuredLiveBurst');
      const featuredCommentInput = document.getElementById('featuredCommentInput');
      const featuredCommentSend = document.getElementById('featuredCommentSend');
      const featuredCommentSummary = document.getElementById('featuredCommentSummary');
      const featuredFriendAction = document.getElementById('featuredFriendAction');
      const featuredReactionButtons = Array.from(document.querySelectorAll('[data-live-reaction]'));
      const featuredLiveLink = document.getElementById('featuredLiveLink');
      const featuredLiveEmbed = document.getElementById('featuredLiveEmbed');
      const featuredSnapshotImage = document.getElementById('featuredLiveSnapshot');
      const reactionEmojiMap = { love: '❤️', like: '👍', fire: '😡', wow: '😮', clap: '🥰' };
      let featuredLiveId = Number((featuredLiveLink && featuredLiveLink.getAttribute('data-live-watch-url') || '').match(/live=(\d+)/)?.[1] || 0);
      let featuredRoomPoll = null;
      let featuredSnapshotTimer = null;
      let featuredStageSyncTimer = null;
      let featuredSnapshotVersion = '';
      let featuredSnapshotLiveActive = false;
      let featuredSnapshotLoadToken = 0;
      let featuredSeenCommentIds = new Set();
      let featuredPrevReactionCounts = null;
      let featuredSnapshotBooted = false;
      let featuredEmbedVideoCurrentTime = 0;
      let featuredEmbedVideoLastAdvanceAt = 0;

      function releaseFeaturedViewer(liveId) {
        const targetId = Number(liveId || 0);
        if (targetId <= 0) return;
        if (navigator.sendBeacon) {
          const formData = new FormData();
          formData.append('action', 'leave_view');
          formData.append('live_id', String(targetId));
          navigator.sendBeacon('ajax/live_watch_room.php', formData);
          return;
        }
        fetch('ajax/live_watch_room.php', {
          method: 'POST',
          credentials: 'same-origin',
          keepalive: true,
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json'
          },
          body: 'action=leave_view&live_id=' + encodeURIComponent(String(targetId))
        }).catch(function(){});
      }

      async function sendFeaturedFriendRequest(peerId) {
        const body = new URLSearchParams();
        body.set('action', 'send');
        body.set('peer_id', String(peerId));
        const response = await fetch('ajax/friend_action.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json'
          },
          body: body.toString()
        });
        const data = await response.json();
        if (!response.ok || !data || data.ok === false) {
          throw new Error((data && data.message) || 'Unable to send friend request.');
        }
        return data;
      }

      function featuredEsc(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function featuredInitials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return 'U';
        return ((parts[0][0] || '') + (parts.length > 1 ? (parts[parts.length - 1][0] || '') : '')).toUpperCase() || 'U';
      }

      function readFeaturedEmbedStageState() {
        if (!featuredLiveEmbed) return null;
        try {
          const doc = featuredLiveEmbed.contentDocument || (featuredLiveEmbed.contentWindow && featuredLiveEmbed.contentWindow.document);
          if (!doc) return null;
          const stage = doc.querySelector('.stage-screen');
          if (!stage) return null;
          const video = doc.getElementById('watchStageVideo');
          return {
            hasWebRtc: stage.classList.contains('has-webrtc'),
            hasSnapshot: stage.classList.contains('has-snapshot'),
            currentTime: video ? Number(video.currentTime || 0) : 0,
            paused: !!(video && video.paused),
            ended: !!(video && video.ended),
            readyState: video ? Number(video.readyState || 0) : 0
          };
        } catch (error) {
          return null;
        }
      }

      function syncFeaturedStageSurface() {
        if (!featuredLiveLink) return;
        const hasParentSnapshot = featuredLiveLink.classList.contains('has-snapshot-stage')
          && !!(featuredSnapshotImage && featuredSnapshotImage.getAttribute('src'));
        const embedState = readFeaturedEmbedStageState();
        let embedHealthy = false;

        if (embedState) {
          if (embedState.hasWebRtc) {
            if (embedState.currentTime > (featuredEmbedVideoCurrentTime + 0.01)) {
              featuredEmbedVideoCurrentTime = embedState.currentTime;
              featuredEmbedVideoLastAdvanceAt = Date.now();
            } else if (!featuredEmbedVideoLastAdvanceAt && embedState.readyState >= 2) {
              featuredEmbedVideoLastAdvanceAt = Date.now();
            }
            const stalledFor = Date.now() - Number(featuredEmbedVideoLastAdvanceAt || 0);
            embedHealthy = !embedState.paused && !embedState.ended && (stalledFor <= 6500 || embedState.readyState < 2);
          } else if (embedState.hasSnapshot) {
            featuredEmbedVideoLastAdvanceAt = Date.now();
            embedHealthy = true;
          }
        }

        featuredLiveLink.classList.toggle('use-snapshot-stage', !!(hasParentSnapshot && !embedHealthy));
      }

      function stopFeaturedStageSync() {
        if (featuredStageSyncTimer) {
          clearInterval(featuredStageSyncTimer);
          featuredStageSyncTimer = null;
        }
        featuredEmbedVideoCurrentTime = 0;
        featuredEmbedVideoLastAdvanceAt = 0;
        if (featuredLiveLink) {
          featuredLiveLink.classList.remove('use-snapshot-stage');
        }
      }

      function restartFeaturedStageSync() {
        stopFeaturedStageSync();
        if (!featuredLiveId || !featuredLiveEmbed) {
          syncFeaturedStageSurface();
          return;
        }
        syncFeaturedStageSurface();
        featuredStageSyncTimer = window.setInterval(syncFeaturedStageSurface, 700);
      }

      function setFeaturedSnapshotVisible(isVisible) {
        if (!featuredLiveLink || !featuredSnapshotImage) return;
        featuredLiveLink.classList.toggle('has-snapshot-stage', !!isVisible);
        if (!isVisible) {
          featuredSnapshotImage.removeAttribute('src');
          delete featuredSnapshotImage.dataset.loadToken;
        }
        syncFeaturedStageSurface();
      }

      function setFeaturedSnapshotSource(url, onError) {
        if (!featuredSnapshotImage || !url) return;
        featuredSnapshotLoadToken += 1;
        const token = 'featured-stage-' + String(featuredSnapshotLoadToken);
        featuredSnapshotImage.dataset.loadToken = token;
        const loader = new Image();
        loader.onload = function() {
          if (featuredSnapshotImage.dataset.loadToken !== token) return;
          featuredSnapshotImage.src = url;
          setFeaturedSnapshotVisible(true);
        };
        loader.onerror = function() {
          if (featuredSnapshotImage.dataset.loadToken !== token) return;
          if (typeof onError === 'function') {
            onError();
          }
        };
        loader.src = url;
      }

      function pullFeaturedSnapshotFrame(forceVersioned) {
        if (!featuredLiveId || !featuredSnapshotLiveActive || !featuredSnapshotVersion) {
          setFeaturedSnapshotVisible(false);
          return;
        }
        const url = 'ajax/live_snapshot.php?live=' + encodeURIComponent(String(featuredLiveId))
          + '&t=' + encodeURIComponent(String(forceVersioned ? featuredSnapshotVersion : Date.now()))
          + '&v=' + encodeURIComponent(String(featuredSnapshotVersion));
        setFeaturedSnapshotSource(url, function() {
          if (forceVersioned) {
            setFeaturedSnapshotVisible(false);
          }
        });
      }

      function restartFeaturedSnapshotLoop() {
        if (featuredSnapshotTimer) {
          clearInterval(featuredSnapshotTimer);
          featuredSnapshotTimer = null;
        }
        if (!featuredLiveId || !featuredSnapshotLiveActive || !featuredSnapshotVersion) {
          setFeaturedSnapshotVisible(false);
          return;
        }
        pullFeaturedSnapshotFrame(false);
        restartFeaturedStageSync();
        featuredSnapshotTimer = window.setInterval(function() {
          pullFeaturedSnapshotFrame(false);
          syncFeaturedStageSurface();
        }, 900);
      }

      function spawnFeaturedComment(item) {
        if (!featuredComments || !item) return;
        const author = String(item.author || item.author_name || 'Viewer');
        const body = String(item.body || '').trim();
        if (!body) return;
        const node = document.createElement('div');
        node.className = 'featured-live-comment';
        node.innerHTML = '<div class="featured-live-comment-avatar">' + featuredEsc(featuredInitials(author)) + '</div>'
          + '<div class="featured-live-comment-bubble">'
          + '<div class="featured-live-comment-name">' + featuredEsc(author) + '</div>'
          + '<div class="featured-live-comment-text">' + featuredEsc(body) + '</div>'
          + '</div>';
        featuredComments.appendChild(node);
        window.setTimeout(function() {
          if (node.parentNode) node.parentNode.removeChild(node);
        }, 5200);
      }

      function spawnFeaturedReaction(kind, index) {
        if (!featuredBurst || !reactionEmojiMap[kind]) return;
        const node = document.createElement('span');
        const horizontal = [0, 26, -18, 40, -32][Number(index || 0) % 5];
        const vertical = [78, 62, 48, 34, 18][Number(index || 0) % 5];
        node.textContent = reactionEmojiMap[kind];
        node.style.bottom = vertical + 'px';
        node.style.right = Math.max(0, 8 + horizontal) + 'px';
        node.style.left = horizontal < 0 ? 'auto' : '';
        node.style.animationDelay = '0s';
        featuredBurst.appendChild(node);
        window.setTimeout(function() {
          if (node.parentNode) node.parentNode.removeChild(node);
        }, 5200);
      }

      function setFeaturedReactionUi(myReaction) {
        featuredReactionButtons.forEach(function(button) {
          const current = String(button.getAttribute('data-live-reaction') || '');
          const hasReaction = String(myReaction || '') !== '';
          button.classList.toggle('is-active', current === String(myReaction || ''));
          button.classList.toggle('is-locked', hasReaction);
          button.disabled = hasReaction;
        });
      }

      function applyFeaturedSnapshot(data) {
        if (!data || !data.live) return;
        const live = data.live || {};
        const comments = Array.isArray(data.comments) ? data.comments : [];
        const counts = data.reaction_counts || {};
        const totalReactions = Object.keys(counts).reduce(function(sum, key){ return sum + Number(counts[key] || 0); }, 0);
        const nextSnapshotVersion = String(live.snapshot_version || '');
        const nextSnapshotLiveActive = String(live.status || '').toLowerCase() === 'live' && nextSnapshotVersion !== '';

        featuredSnapshotLiveActive = nextSnapshotLiveActive;
        if (!nextSnapshotLiveActive) {
          featuredSnapshotVersion = '';
          restartFeaturedSnapshotLoop();
        } else if (featuredSnapshotVersion !== nextSnapshotVersion) {
          featuredSnapshotVersion = nextSnapshotVersion;
          pullFeaturedSnapshotFrame(true);
          restartFeaturedSnapshotLoop();
        } else {
          restartFeaturedSnapshotLoop();
        }

        if (!featuredSnapshotBooted) {
          comments.forEach(function(item) {
            const id = Number(item && item.id || 0);
            if (id > 0) featuredSeenCommentIds.add(id);
          });
        } else {
          comments.forEach(function(item) {
            const id = Number(item && item.id || 0);
            if (id > 0 && !featuredSeenCommentIds.has(id)) {
              featuredSeenCommentIds.add(id);
              spawnFeaturedComment(item);
            }
          });
        }

        if (!featuredSnapshotBooted || !featuredPrevReactionCounts) {
          featuredPrevReactionCounts = Object.assign({}, counts);
        } else {
          Object.keys(reactionEmojiMap).forEach(function(key) {
            const prev = Number(featuredPrevReactionCounts[key] || 0);
            const next = Number(counts[key] || 0);
            const delta = Math.max(0, next - prev);
            const capped = Math.min(delta, 4);
            for (let i = 0; i < capped; i += 1) {
              spawnFeaturedReaction(key, i);
            }
          });
          featuredPrevReactionCounts = Object.assign({}, counts);
        }

        setFeaturedReactionUi(data.my_reaction || '');
        const featuredLikesNode = document.getElementById('featuredLikes');
        if (featuredLikesNode) {
          featuredLikesNode.innerHTML = '<i class="fa fa-thumbs-up"></i> ' + String(totalReactions);
        }
        if (featuredCommentSummary) {
          featuredCommentSummary.innerHTML = '<i class="fa fa-comments-o"></i> ' + String(comments.length) + ' comments';
        }
        const featuredViewerNode = document.getElementById('featuredViewerBadge');
        if (featuredViewerNode) {
          featuredViewerNode.innerHTML = '<i class="fa fa-eye"></i> ' + String(Number(live.viewer_count || 0));
        }
        const featuredSubNode = document.getElementById('featuredChannelSub');
        if (featuredSubNode) {
          const startedText = featuredSubNode.textContent.replace(/^[^·]*·\s*/, '');
          featuredSubNode.textContent = String(Number(live.viewer_count || 0)) + ' watching · ' + startedText;
        }
        const activeRail = document.querySelector('.rail-card.is-active');
        if (activeRail) {
          activeRail.setAttribute('data-live-viewers', String(Number(live.viewer_count || 0)));
          const railMeta = activeRail.querySelector('.rail-meta');
          if (railMeta) {
            const currentText = railMeta.textContent || '';
            const startedText = currentText.includes('·') ? currentText.split('·').slice(1).join('·').trim() : 'recently';
            railMeta.textContent = String(Number(live.viewer_count || 0)) + ' watching · ' + startedText;
          }
        }
        featuredSnapshotBooted = true;
      }

      async function fetchFeaturedSnapshot() {
        if (!featuredLiveId) return;
        const response = await fetch('ajax/live_watch_room.php?live=' + encodeURIComponent(String(featuredLiveId)) + '&t=' + encodeURIComponent(String(Date.now())), {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        if (!response.ok || !data || data.ok === false) {
          throw new Error((data && data.error) || 'Unable to load live room.');
        }
        applyFeaturedSnapshot(data);
      }

      function restartFeaturedPoll() {
        if (featuredRoomPoll) {
          clearInterval(featuredRoomPoll);
          featuredRoomPoll = null;
        }
        if (!featuredLiveId) return;
        fetchFeaturedSnapshot().catch(function(){});
        featuredRoomPoll = window.setInterval(function() {
          fetchFeaturedSnapshot().catch(function(){});
        }, 4000);
      }

      async function postFeaturedRoom(action, extra) {
        if (!featuredLiveId) return;
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('live_id', String(featuredLiveId));
        Object.keys(extra || {}).forEach(function(key) {
          body.set(key, String(extra[key] == null ? '' : extra[key]));
        });
        const response = await fetch('ajax/live_watch_room.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json'
          },
          body: body.toString()
        });
        const data = await response.json();
        if (!response.ok || !data || data.ok === false) {
          throw new Error((data && data.error) || 'Unable to update live room.');
        }
        applyFeaturedSnapshot(data);
      }

      if (featuredCommentSend && featuredCommentInput) {
        featuredCommentSend.addEventListener('click', async function() {
          const value = String(featuredCommentInput.value || '').trim();
          if (!value) return;
          try {
            await postFeaturedRoom('send_comment', { comment_body: value });
            featuredCommentInput.value = '';
          } catch (error) {}
        });
        featuredCommentInput.addEventListener('keydown', function(event) {
          if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            featuredCommentSend.click();
          }
        });
      }

      featuredReactionButtons.forEach(function(button) {
        button.addEventListener('click', async function() {
          if (button.disabled) return;
          const reaction = String(button.getAttribute('data-live-reaction') || '').trim();
          if (!reaction) return;
          try {
            await postFeaturedRoom('react_live', { reaction: reaction });
          } catch (error) {}
        });
      });


      if (featuredFriendAction) {
        featuredFriendAction.addEventListener('click', async function(event) {
          const actionKind = String(featuredFriendAction.getAttribute('data-friend-action-kind') || '').trim();
          if (actionKind !== 'send') {
            return;
          }
          event.preventDefault();
          const peerId = Number(featuredFriendAction.getAttribute('data-peer-id') || 0);
          if (peerId <= 0) return;
          try {
            featuredFriendAction.style.pointerEvents = 'none';
            await sendFeaturedFriendRequest(peerId);
            featuredFriendAction.textContent = 'Request Sent';
            featuredFriendAction.setAttribute('data-friend-action-kind', 'pending');
            featuredFriendAction.setAttribute('href', '#');
          } catch (error) {
            window.alert(error && error.message ? error.message : 'Unable to send friend request.');
          } finally {
            featuredFriendAction.style.pointerEvents = '';
          }
        });
      }

      window.setFeaturedLiveId = function(nextId) {
        const previousLiveId = Number(featuredLiveId || 0);
        const upcomingLiveId = Number(nextId || 0);
        if (previousLiveId > 0 && previousLiveId !== upcomingLiveId) {
          releaseFeaturedViewer(previousLiveId);
        }
        featuredLiveId = upcomingLiveId;
        featuredSnapshotVersion = '';
        featuredSnapshotLiveActive = false;
        featuredSeenCommentIds = new Set();
        featuredPrevReactionCounts = null;
        featuredSnapshotBooted = false;
        if (featuredComments) featuredComments.innerHTML = '';
        if (featuredBurst) featuredBurst.innerHTML = '';
        restartFeaturedSnapshotLoop();
        restartFeaturedStageSync();
        restartFeaturedPoll();
      };

      if (featuredLiveEmbed) {
        featuredLiveEmbed.addEventListener('load', function() {
          window.setTimeout(syncFeaturedStageSurface, 80);
          window.setTimeout(syncFeaturedStageSurface, 350);
        });
      }

      restartFeaturedStageSync();
      restartFeaturedPoll();
      window.addEventListener('beforeunload', function() {
        releaseFeaturedViewer(featuredLiveId);
      });
      window.addEventListener('pagehide', function() {
        releaseFeaturedViewer(featuredLiveId);
      });
    })();
  </script>
</body>
</html>
