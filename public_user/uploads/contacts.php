<?php
// /Business_only3/public_user/contacts.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/user_identity.php';
require_once __DIR__ . '/includes/post_card_actions_menu.php';
require_once __DIR__ . '/includes/friend_system.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)userId();
$msg = '';
$error = '';

if ($meId <= 0) {
    clearUserSession();
    header("Location: index.php?session=reset");
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// In-page friend request accept/decline (stay on contacts.php)
if (isset($_POST['ajax']) && (string)$_POST['ajax'] === 'respond_request') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $reqId = (int)($_POST['id'] ?? $_POST['request_id'] ?? 0);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $accept = ($action === 'accept');
    $res = $accept
        ? fs_accept_friend_request($dbh, $reqId, $meId)
        : fs_decline_friend_request($dbh, $reqId, $meId);
    echo json_encode($res);
    exit;
}

function normalizeFriendCodeInput(string $value): string {
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? $value;
    if (strpos($value, 'URS-') === 0) {
        $value = 'USR-' . substr($value, 4);
    }
    return $value;
}

function findUserByFriendCode(PDO $dbh, string $value): ?array {
    $code = normalizeFriendCodeInput($value);
    if ($code === '') return null;

    $st = $dbh->prepare("SELECT id, name, username, email, friend_code, status FROM users WHERE UPPER(friend_code) = ? LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * ✅ Header-matching avatars
 * - 2-letter initials: first + last word (John Katy => JK)
 * - Stable color by INITIALS (JK/RV/EE always same everywhere)
 * - Same gradient style as header.php
 */

// ---------- Avatar helpers (match header.php behavior) ----------
if (!function_exists('normalize_avatar_key')) {
    /** Keep case; normalize ONLY by trimming and collapsing spaces. */
    function normalize_avatar_key(string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }
}

if (!function_exists('initials_from_name')) {
    function initials_from_name(string $name, string $fallback = 'ME'): string {
        $name = normalize_avatar_key($name);
        if ($name === '') return $fallback;

        $parts = preg_split('/\s+/', $name) ?: [];
        $parts = array_values(array_filter($parts, function ($x) {
            return trim((string)$x) !== '';
        }));
        if (!$parts) return $fallback;

        // ✅ first + last word
        $a = mb_substr($parts[0], 0, 1);
        $b = (count($parts) > 1) ? mb_substr($parts[count($parts)-1], 0, 1) : '';

        $ini = mb_strtoupper($a . $b);

        if ($ini === '') $ini = mb_strtoupper(mb_substr($name, 0, 2));
        return $ini ?: $fallback;
    }
}

if (!function_exists('color_from_string')) {
    /**
     * Stable color: SAME normalized key => SAME color (always)
     * Palette matches header.php.
     */
    function color_from_string(string $str): string {
        $colors = ['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f43f5e','#6366f1'];

        $str = normalize_avatar_key((string)$str);
        if ($str === '') $str = 'User';

        // ✅ header-like hashing (stable)
        $hash = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $hash = (($hash << 5) - $hash) + ord($str[$i]);
            $hash &= 0xFFFFFFFF;
        }

        // unsigned modulo palette size
        $idx = (int)(($hash < 0 ? $hash + 0x100000000 : $hash) % count($colors));
        return $colors[$idx] ?? $colors[0];
    }
}

if (!function_exists('avatar_gradient_style')) {
    function avatar_gradient_style(string $baseHex): string {
        $baseHex = trim($baseHex) ?: '#4f46e5';
        // ✅ stop at 40% (matches your header-style look)
        return "background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 40%), linear-gradient(135deg, {$baseHex}, #111827);";
    }
}

// Delete contact
if (isset($_GET['del'])) {
    $id = (int)($_GET['del'] ?? 0);
    if ($id > 0) {
        try {
            $st = $dbh->prepare("DELETE FROM user_contacts WHERE id = :id AND owner_user_id = :me");
            $st->execute([':id' => $id, ':me' => $meId]);
            $msg = "Friend removed.";
        } catch (Throwable $e) {
            $error = "Delete failed.";
        }
    }
}

// Load contacts
$st = $dbh->prepare("
  SELECT
    uc.id,
    uc.display_name,
    u.id AS friend_user_id,
    u.username AS friend_username,
    u.friend_code,
    u.email AS friend_email
  FROM user_contacts uc
  LEFT JOIN users u ON u.id = uc.friend_user_id
  WHERE uc.owner_user_id = :me
    AND NULLIF(TRIM(uc.display_name), '') IS NOT NULL
  ORDER BY uc.display_name ASC, uc.id DESC
");
$st->execute([':me' => $meId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$meDisplayName = trim((string)(function_exists('myUserName') ? myUserName() : ''));
if ($meDisplayName === '') {
    $meDisplayName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Friends'));
}
$meHandle = trim((string)(function_exists('userFriendCode') ? userFriendCode() : ($_SESSION['user_friend_code'] ?? '')));
if ($meHandle === '') {
    $meHandle = trim((string)($_SESSION['user_login'] ?? ''));
}
$friendCount = count($rows);
$friendCountLabel = $friendCount === 1 ? '1 friend' : ($friendCount . ' friends');

$pendingRequests = [];
try {
    $reqSt = $dbh->prepare("
      SELECT cr.id, cr.from_user_id, cr.created_at, u.name, u.username, u.email, u.friend_code
      FROM contact_requests cr
      JOIN users u ON u.id = cr.from_user_id
      WHERE cr.to_user_id = :me AND cr.status = 'pending'
      ORDER BY cr.created_at DESC
    ");
    $reqSt->execute([':me' => $meId]);
    $pendingRequests = $reqSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $pendingRequests = [];
}
$pendingRequestCount = count($pendingRequests);
$pendingRequestLabel = $pendingRequestCount === 1 ? '1 request' : ($pendingRequestCount . ' requests');

$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'friends')));
if ($activeTab !== 'friends' && $activeTab !== 'requests' && $activeTab !== 'add') {
    $activeTab = 'friends';
}
$prefillFriendCode = trim((string)($_GET['friend'] ?? ''));
if ($prefillFriendCode !== '' && function_exists('normalizeFriendCodeInput')) {
    $prefillFriendCode = normalizeFriendCodeInput($prefillFriendCode);
}
if ($prefillFriendCode !== '' && $activeTab === 'friends' && !isset($_GET['tab'])) {
    $activeTab = 'add';
}

// What’s happening — recent public posts from publishers
$happeningItems = [];
try {
    require_once __DIR__ . '/includes/publisher_accounts.php';
    $blockSql = function_exists('fs_block_exclude_author_sql')
        ? (' AND ' . fs_block_exclude_author_sql('p.user_id', ':happenBlockMe', ':happenBlockMe2'))
        : '';
    $happenSt = $dbh->prepare("
      SELECT
        p.id,
        COALESCE(NULLIF(TRIM(p.title), ''), '') AS title,
        COALESCE(NULLIF(TRIM(p.description), ''), '') AS description,
        COALESCE(NULLIF(TRIM(p.body), ''), '') AS body,
        COALESCE(u.name, u.username, CONCAT('Publisher ', u.id)) AS publisher_name,
        COALESCE(u.username, '') AS publisher_username,
        COALESCE(u.friend_code, '') AS publisher_friend_code,
        LOWER(TRIM(COALESCE(u.publisher_category, ''))) AS publisher_category
      FROM public_posts p
      INNER JOIN users u ON u.id = p.user_id
      WHERE p.is_deleted = 0
        AND COALESCE(p.is_archived, 0) = 0
        AND LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) = 'public'
        AND COALESCE(u.account_kind, 'personal') = 'publisher'
        AND u.status = 1
        {$blockSql}
      ORDER BY COALESCE(p.updated_at, p.created_at) DESC
      LIMIT 5
    ");
    $happenParams = [];
    if ($blockSql !== '') {
        $happenParams[':happenBlockMe'] = $meId;
        $happenParams[':happenBlockMe2'] = $meId;
    }
    $happenSt->execute($happenParams);
    $happenRows = $happenSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $catLabels = function_exists('publisher_categories_builtin') ? publisher_categories_builtin() : [];
    foreach ($happenRows as $hp) {
        $postId = (int)($hp['id'] ?? 0);
        if ($postId <= 0) {
            continue;
        }
        $title = trim((string)($hp['title'] ?? ''));
        if ($title === '') {
            $title = trim(preg_replace('/\s+/', ' ', strip_tags((string)($hp['description'] ?? ''))) ?? '');
        }
        if ($title === '') {
            $title = trim(preg_replace('/\s+/', ' ', strip_tags((string)($hp['body'] ?? ''))) ?? '');
        }
        if ($title === '') {
            $title = 'New post';
        }
        if (mb_strlen($title) > 72) {
            $title = rtrim(mb_substr($title, 0, 69)) . '…';
        }
        $publisherName = trim((string)($hp['publisher_name'] ?? 'Publisher'));
        $catKey = trim((string)($hp['publisher_category'] ?? ''));
        $catLabel = ($catKey !== '' && isset($catLabels[$catKey])) ? (string)$catLabels[$catKey] : ($catKey !== '' ? ucwords(str_replace('-', ' ', $catKey)) : 'Publisher');
        $happeningItems[] = [
            'id' => $postId,
            'title' => $title,
            'meta' => $catLabel . ' · ' . $publisherName,
            'href' => 'public.php?post=' . $postId,
        ];
    }
} catch (Throwable $e) {
    $happeningItems = [];
}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $items = [];
    foreach ($rows as $c) {
        $id = (int)($c['id'] ?? 0);
        $label = trim((string)($c['display_name'] ?? ''));
        $code = trim((string)($c['friend_code'] ?? ''));
        $email = trim((string)($c['friend_email'] ?? ''));
        $sub = $code !== '' ? $code : $email;
        $fallback = $sub !== '' ? mb_strtoupper(mb_substr($sub, 0, 2)) : 'CT';
        $initials = initials_from_name($label !== '' ? $label : $sub, $fallback);
        $uniqueKey = $code !== '' ? $code : ($email !== '' ? $email : ($label !== '' ? $label : $initials));

        $items[] = [
            'id' => $id,
            'display_name' => $label,
            'friend_user_id' => (int)($c['friend_user_id'] ?? 0),
            'friend_code' => $code,
            'friend_email' => $email,
            'subtitle' => $email,
            'initials' => $initials,
            'color' => color_from_string($uniqueKey),
            'avatar_url' => 'avatar.php?friend_code=' . urlencode($code) . '&email=' . urlencode($email) . '&name=' . urlencode($label !== '' ? $label : $sub),
            'profile_url' => ((int)($c['friend_user_id'] ?? 0) > 0)
                ? ('profile.php?id=' . (int)$c['friend_user_id'] . '&tab=gallery')
                : ($code !== '' ? ('profile.php?friend_code=' . urlencode($code) . '&tab=gallery') : ''),
            'message_url' => 'user_sendreply.php?to=' . urlencode($code !== '' ? $code : $email),
            'timeline_url' => ((int)($c['friend_user_id'] ?? 0) > 0) ? ('timeline.php?u=' . (int)$c['friend_user_id']) : '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'count' => count($items),
        'blocked_count' => 0,
        'items' => $items,
    ]);
    exit;
}

if (isset($_POST['ajax']) && (string)$_POST['ajax'] === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $id = (int)($_POST['contact_id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid contact.']);
        exit;
    }

    try {
        $del = $dbh->prepare("DELETE FROM user_contacts WHERE id = :id AND owner_user_id = :me");
        $del->execute([':id' => $id, ':me' => $meId]);
        echo json_encode(['ok' => $del->rowCount() > 0, 'message' => $del->rowCount() > 0 ? 'Friend removed.' : 'Contact not found.']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Delete failed.']);
    }
    exit;
}

if (isset($_POST['ajax']) && (string)$_POST['ajax'] === 'update') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $id = (int)($_POST['contact_id'] ?? $_POST['id'] ?? 0);
    $display = trim((string)($_POST['display_name'] ?? $_POST['full_name'] ?? ''));
    $friendCode = trim((string)($_POST['friend_code'] ?? ''));

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid contact.']);
        exit;
    }
    if ($display === '') {
        echo json_encode(['ok' => false, 'error' => 'Enter full name.']);
        exit;
    }
    if ($friendCode === '') {
        echo json_encode(['ok' => false, 'error' => 'Enter friend code.']);
        exit;
    }

    $friend = findUserByFriendCode($dbh, $friendCode);
    if (!$friend) {
        echo json_encode(['ok' => false, 'error' => 'User not found. Check friend code.']);
        exit;
    }
    if ((int)($friend['status'] ?? 0) !== 1) {
        echo json_encode(['ok' => false, 'error' => 'This user account is inactive.']);
        exit;
    }
    if ((int)($friend['id'] ?? 0) === $meId) {
        echo json_encode(['ok' => false, 'error' => 'You cannot save yourself as a contact.']);
        exit;
    }

    try {
        $existing = $dbh->prepare("SELECT id FROM user_contacts WHERE owner_user_id = :me AND friend_user_id = :friend_id AND id <> :id LIMIT 1");
        $existing->execute([
            ':me' => $meId,
            ':friend_id' => (int)$friend['id'],
            ':id' => $id,
        ]);
        if ((int)($existing->fetchColumn() ?: 0) > 0) {
            echo json_encode(['ok' => false, 'error' => 'This friend is already in your contacts.']);
            exit;
        }

        $up = $dbh->prepare("
            UPDATE user_contacts
            SET display_name = :display_name,
                friend_user_id = :friend_id
            WHERE id = :id AND owner_user_id = :me
            LIMIT 1
        ");
        $up->execute([
            ':display_name' => $display,
            ':friend_id' => (int)$friend['id'],
            ':id' => $id,
            ':me' => $meId,
        ]);

        echo json_encode([
            'ok' => $up->rowCount() >= 0,
            'message' => 'Contact updated.',
            'contact' => [
                'id' => $id,
                'display_name' => $display,
                'friend_user_id' => (int)$friend['id'],
                'friend_code' => trim((string)($friend['friend_code'] ?? '')),
                'friend_email' => trim((string)($friend['email'] ?? '')),
            ],
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Unable to update contact.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Friends</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, $meId);
  ?>

  <!-- vendor css -->
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">

  <!-- Shamcey CSS -->
  <link rel="stylesheet" href="./css/shamcey.css">

  <!-- script -->
  <script src="./lib/jquery/jquery.js"></script>
  <script src="./lib/popper.js/popper.js"></script>
  <script src="./lib/bootstrap/bootstrap.js"></script>
  <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
  <script src="./js/shamcey.js"></script>

  <style>
    /* Twitter-inspired Friends list — keep leftbar + fries behavior */
    :root{
      --x-text: var(--msb-palette-text, #0f1419);
      --x-muted: var(--msb-palette-text-muted, #536471);
      --x-border: var(--msb-palette-border, #eff3f4);
      --x-hover: var(--msb-palette-hover-bg, rgba(15,20,25,.03));
      --x-bg: var(--msb-palette-bg, #fff);
      --x-accent: var(--msb-palette-action, #1d9bf0);
      --x-btn: var(--msb-palette-text, #0f1419);
      --x-btn-text: var(--msb-palette-bg, #fff);
    }

    html, body.contacts-page {
      height: 100%;
      overflow: hidden;
      background: var(--x-bg) !important;
      color: var(--x-text);
    }

    .sh-mainpanel{
      height: 80vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      margin-left: 340px;
      margin-top: 20px;
      background: transparent !important;
    }
    .sh-pagetitle{ flex: 0 0 auto; }

    .sh-pagebody{
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      background: transparent !important;
      padding: 0 !important;
    }

    .contacts-layout{
      flex: 1 1 auto;
      min-height: 0;
      display: flex;
      align-items: stretch;
      gap: 16px;
      padding: 0 12px 8px 0;
      box-sizing: border-box;
      overflow: hidden;
    }

    .contacts-shell{
      flex: 1 1 auto;
      min-width: 0;
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      margin: 0;
      max-width: 860px;
      width: 100%;
      border: 0;
      border-radius: 0;
      background: var(--x-bg) !important;
      color: var(--x-text);
      box-sizing: border-box;
    }

    .contacts-fixed{
      flex: 0 0 auto;
      position: sticky;
      top: 0;
      z-index: 5;
      background: color-mix(in srgb, var(--x-bg) 88%, transparent);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border: 0;
    }

    /* Header (name + tabs): no top/left/right borders — only bottom divider */
    .contacts-head-chrome{
      border-top: 0;
      border-left: 0;
      border-right: 0;
      border-bottom: 1px solid var(--x-border);
    }

    .contacts-scroll{
      flex: 1 1 auto;
      min-height: 0;
      overflow: auto;
      -webkit-overflow-scrolling: touch;
      background: transparent;
      border-left: 1px solid var(--x-border);
      border-right: 1px solid var(--x-border);
      border-bottom: 1px solid var(--x-border);
    }

    .x-topbar{
      display:flex;
      align-items:center;
      gap:18px;
      padding:10px 16px;
      min-height:53px;
      box-sizing:border-box;
    }
    .x-back{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:34px;
      height:34px;
      border-radius:999px;
      color:var(--x-text);
      text-decoration:none;
      flex:0 0 auto;
      transition:background .15s ease;
    }
    .x-back:hover{background:var(--x-hover);color:var(--x-text);text-decoration:none;}
    .x-back i{font-size:18px;line-height:1;}
    .x-top-meta{min-width:0;flex:1 1 auto;}
    .x-top-name{
      margin:0;
      font-size:20px;
      font-weight:800;
      line-height:1.2;
      letter-spacing:-.02em;
      color:var(--x-text);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .x-top-sub{
      margin:2px 0 0;
      font-size:13px;
      line-height:1.2;
      color:var(--x-muted);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    .x-tabs{
      display:flex;
      width:100%;
      border-top:0;
    }
    .x-tab{
      flex:1 1 0;
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:53px;
      padding:12px 8px;
      border:0;
      background:transparent;
      color:var(--x-muted);
      font-size:15px;
      font-weight:600;
      text-decoration:none;
      position:relative;
      box-sizing:border-box;
      transition:background .15s ease,color .15s ease;
      cursor:pointer;
      font-family:inherit;
      appearance:none;
      -webkit-appearance:none;
    }
    .x-tab:hover{background:var(--x-hover);color:var(--x-text);text-decoration:none;}
    .x-tab.is-active{
      color:var(--x-text);
      font-weight:800;
    }
    .x-tab.is-active::after{
      content:'';
      position:absolute;
      left:50%;
      bottom:0;
      transform:translateX(-50%);
      width:56px;
      max-width:70%;
      height:4px;
      border-radius:999px;
      background:var(--x-accent);
    }

    .x-search-wrap{
      padding:10px 16px 12px;
      border-top:0;
      border-left:1px solid var(--x-border);
      border-right:1px solid var(--x-border);
    }
    .x-search{
      position:relative;
    }
    .x-search .search-ico{
      position:absolute;
      left:14px;
      top:50%;
      transform:translateY(-50%);
      color:var(--x-muted);
      opacity:.85;
      pointer-events:none;
    }
    .x-search .form-control,
    #contactSearch{
      width:100%;
      height:42px;
      border-radius:999px;
      padding:0 16px 0 40px;
      border:1px solid transparent;
      background:var(--msb-palette-input-bg, var(--msb-palette-surface-2, #eff3f4)) !important;
      color:var(--x-text) !important;
      font-size:15px;
      box-shadow:none !important;
    }
    #contactSearch:focus{
      border-color:var(--x-accent) !important;
      background:var(--x-bg) !important;
      outline:none;
    }

    .contacts-shell .alert{
      margin:12px 16px;
      border-radius:12px;
      background:var(--msb-palette-surface-2, #eef2f7) !important;
      border:1px solid var(--msb-palette-border, rgba(148,163,184,.45)) !important;
      color:var(--x-text) !important;
    }
    .contacts-fixed > .alert{
      margin-left:0;
      margin-right:0;
      border-radius:0;
      border-left:1px solid var(--x-border) !important;
      border-right:1px solid var(--x-border) !important;
    }

    .x-list{margin:0;padding:0;list-style:none;}
    .contact-row{
      display:flex;
      align-items:flex-start;
      gap:12px;
      width:100%;
      padding:12px 16px;
      border-bottom:1px solid var(--x-border);
      box-sizing:border-box;
      transition:background .12s ease;
    }
    .contact-row:hover{background:var(--x-hover);}
    .x-avatar-link{
      flex:0 0 auto;
      text-decoration:none;
    }
    .avatar-circle img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block;}
    .avatar-circle{
      width:48px;height:48px;border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      font-weight:800;letter-spacing:.02em;
      font-size:15px;flex:0 0 auto;
      color:#fff;
      border:0;
      box-shadow:none;
      position:relative;
      user-select:none;
      overflow:hidden;
    }
    .avatar-circle::after{display:none;}

    .x-row-main{
      flex:1 1 auto;
      min-width:0;
      display:flex;
      flex-direction:column;
      gap:2px;
    }
    .x-row-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
    }
    .x-id{
      min-width:0;
      flex:1 1 auto;
    }
    .x-name{
      margin:0;
      font-size:15px;
      font-weight:800;
      line-height:1.25;
      color:var(--x-text);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .x-name a{color:inherit;text-decoration:none;}
    .x-name a:hover{text-decoration:underline;}
    .x-handle{
      margin:1px 0 0;
      font-size:14px;
      line-height:1.25;
      color:var(--x-muted);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .x-bio{
      margin:4px 0 0;
      font-size:14px;
      line-height:1.35;
      color:var(--x-text);
      word-break:break-word;
    }
    .x-bio.mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size:13px;
      letter-spacing:.01em;
      color:var(--x-muted);
    }

    .x-row-actions{
      display:flex;
      align-items:center;
      gap:4px;
      flex:0 0 auto;
    }
    .x-msg-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:84px;
      height:32px;
      padding:0 16px;
      border-radius:999px;
      border:0;
      background:var(--x-btn);
      color:var(--x-btn-text);
      font-size:14px;
      font-weight:800;
      text-decoration:none;
      line-height:1;
      white-space:nowrap;
      transition:opacity .15s ease;
    }
    .x-msg-btn:hover{opacity:.88;color:var(--x-btn-text);text-decoration:none;}

    .x-req-actions{
      display:inline-flex;
      align-items:center;
      gap:8px;
      flex:0 0 auto;
    }
    .x-req-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:32px;
      padding:0 16px;
      border-radius:999px;
      border:0;
      font-size:14px;
      font-weight:800;
      line-height:1;
      cursor:pointer;
      white-space:nowrap;
    }
    .x-req-btn:disabled{opacity:.55;cursor:default;}
    .x-req-accept{
      background:var(--x-btn);
      color:var(--x-btn-text);
    }
    .x-req-decline{
      background:transparent;
      color:var(--x-text);
      border:1px solid var(--x-border);
    }
    .x-req-decline:hover{background:var(--x-hover);}
    .x-tab-panel[hidden]{display:none !important;}
    .x-search-wrap.is-hidden{display:none !important;}
    .x-add-wrap{
      padding:20px 16px 28px;
      box-sizing:border-box;
    }
    .x-add-card{
      border:0;
      border-radius:0;
      padding:18px 16px 16px;
      background:transparent;
      max-width:480px;
    }
    .x-add-card h3{
      margin:0 0 6px;
      font-size:20px;
      font-weight:900;
      letter-spacing:-.02em;
      color:var(--x-text);
    }
    .x-add-card p{
      margin:0 0 16px;
      font-size:14px;
      line-height:1.4;
      color:var(--x-muted);
    }
    .x-add-field{margin:0 0 14px;}
    .x-add-field label{
      display:block;
      margin:0 0 6px;
      font-size:13px;
      font-weight:800;
      color:var(--x-text);
    }
    .x-add-field .form-control{
      width:100%;
      height:44px;
      border-radius:12px;
      border:1px solid var(--x-border);
      background:var(--msb-palette-input-bg, var(--msb-palette-surface-2, #fff)) !important;
      color:var(--x-text) !important;
      padding:0 14px;
      font-size:15px;
      box-shadow:none !important;
    }
    .x-add-field .form-control:focus{
      border-color:var(--x-accent) !important;
      outline:none;
    }
    .x-add-hint{
      margin:6px 0 0;
      font-size:12px;
      color:var(--x-muted);
      line-height:1.35;
    }
    .x-add-submit{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:40px;
      padding:0 20px;
      border:0;
      border-radius:999px;
      background:var(--x-btn);
      color:var(--x-btn-text);
      font-size:15px;
      font-weight:800;
      cursor:pointer;
    }
    .x-add-submit:disabled{opacity:.55;cursor:default;}
    .x-add-alert{
      display:none;
      margin:0 0 14px;
      padding:10px 12px;
      border-radius:12px;
      font-size:14px;
      font-weight:600;
    }
    .x-add-alert.is-error{
      display:block;
      background:#fef2f2;
      color:#b91c1c;
      border:1px solid #fecaca;
    }
    .x-add-alert.is-ok{
      display:block;
      background:#ecfdf5;
      color:#047857;
      border:1px solid #a7f3d0;
    }

    .row-menu{
      position:relative;
      display:inline-flex;
      justify-content:flex-end;
    }
    .row-menu-toggle{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:34px;
      min-width:34px;
      height:34px;
      padding:0;
      border-radius:999px;
      border:0;
      background:transparent;
      color:var(--x-muted);
      font-size:16px;
      line-height:1;
      cursor:pointer;
      box-shadow:none;
    }
    .row-menu-toggle:hover,
    .row-menu-toggle:focus{
      background:rgba(29,155,240,.1);
      outline:none;
      color:var(--x-accent);
    }
    .row-menu-toggle::after{display:none;}
    .row-menu-toggle .pcm-fries-icon{
      display:inline-flex;
      flex-direction:column;
      justify-content:center;
      align-items:flex-start;
      gap:3px;
      width:14px;
      color:currentColor;
    }
    .row-menu-toggle .pcm-fries-bar{
      display:block;
      height:2px;
      border-radius:1px;
      background:currentColor;
      width:14px;
    }
    .row-menu-toggle .pcm-fries-bar--short{width:8px;}
    .row-menu .dropdown-menu{
      min-width:220px;
      margin-top:8px;
      border-radius:16px;
      border:1px solid var(--msb-palette-border, rgba(0,0,0,.08));
      box-shadow:0 0 15px rgba(101,119,134,.2), 0 0 3px 1px rgba(101,119,134,.15);
      padding:8px 0;
      background:var(--msb-palette-surface, var(--msb-palette-bg, #fff)) !important;
      color:var(--msb-palette-text, #1f2937);
    }
    .row-menu .dropdown-item{
      display:flex;
      align-items:center;
      gap:10px;
      padding:12px 16px;
      font-weight:700;
      color:var(--msb-palette-text, #1f2937);
    }
    .row-menu .dropdown-item i{
      width:18px;
      text-align:center;
      color:var(--msb-palette-text-muted, #64748b);
    }
    .row-menu .dropdown-item.text-danger,
    .row-menu .dropdown-item.text-danger i{
      color:#dc2626 !important;
    }
    .row-menu .dropdown-divider{
      margin:8px 0;
      border-top:1px solid var(--msb-palette-border, rgba(0,0,0,.08));
    }

    .x-empty{
      padding:48px 28px;
      text-align:center;
    }
    .x-empty h3{
      margin:0 0 8px;
      font-size:28px;
      font-weight:900;
      letter-spacing:-.03em;
      color:var(--x-text);
    }
    .x-empty p{
      margin:0 auto 18px;
      max-width:320px;
      font-size:15px;
      line-height:1.4;
      color:var(--x-muted);
    }
    .x-empty .btn{
      border-radius:999px;
      font-weight:800;
      padding:10px 20px;
    }

    /* Right sidebar — X / Twitter style */
    .x-right-rail{
      flex: 0 0 350px;
      width: 350px;
      max-width: 350px;
      min-height: 0;
      align-self: stretch;
      display: flex;
      flex-direction: column;
      overflow: auto;
      -webkit-overflow-scrolling: touch;
      padding: 4px 0 24px;
      box-sizing: border-box;
      scrollbar-width: thin;
    }
    .x-rail-search{
      position: sticky;
      top: 0;
      z-index: 2;
      padding: 10px 0 12px;
      background: color-mix(in srgb, var(--x-bg) 92%, transparent);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }
    .x-rail-search-field{
      position: relative;
    }
    .x-rail-search-field .fa-search{
      position: absolute;
      right: 14px;
      left: auto;
      top: 50%;
      transform: translateY(-50%);
      color: var(--x-muted);
      font-size: 14px;
      pointer-events: none;
    }
    .x-rail-search-input{
      width: 100%;
      height: 44px;
      border-radius: 999px;
      border: 1px solid var(--x-border);
      background: var(--msb-palette-input-bg, var(--msb-palette-surface-2, #eff3f4));
      color: var(--x-text);
      padding: 0 42px 0 16px;
      font-size: 15px;
      outline: none;
      box-sizing: border-box;
    }
    .x-rail-search-input::placeholder{color: var(--x-muted);}
    .x-rail-search-input:focus{
      background: var(--x-bg);
      border-color: var(--x-accent);
    }

    .x-rail-card{
      background: var(--x-bg);
      border: 1px solid var(--x-border);
      border-radius: 16px;
      margin-bottom: 16px;
      overflow: hidden;
      box-sizing: border-box;
    }
    .x-rail-card-pad{padding: 14px 16px 16px;}
    .x-rail-card-title{
      margin: 0 0 8px;
      font-size: 20px;
      font-weight: 900;
      letter-spacing: -.02em;
      color: var(--x-text);
      line-height: 1.2;
    }
    .x-rail-card-copy{
      margin: 0 0 12px;
      font-size: 14px;
      line-height: 1.4;
      color: var(--x-text);
    }

    /* Suggested for you (from public.php include) inside contacts right rail */
    body.contacts-page.feed-insta-ui .x-right-rail .feed-right-rail{
      display:block !important;
      position:static !important;
      left:auto !important;
      top:auto !important;
      right:auto !important;
      bottom:auto !important;
      width:100% !important;
      max-width:none !important;
      height:auto !important;
      max-height:none !important;
      margin:0 0 16px !important;
      padding:0 !important;
      overflow:visible !important;
      z-index:auto !important;
      background:transparent !important;
      border:0 !important;
      box-shadow:none !important;
    }
    body.contacts-page.feed-insta-ui .x-right-rail .sfy-panel{
      margin:0 !important;
      padding:12px 14px 8px !important;
      border:1px solid var(--x-border) !important;
      border-radius:16px !important;
      background:var(--x-bg) !important;
      height:auto !important;
      max-height:min(420px, 55vh) !important;
      min-height:0 !important;
      box-sizing:border-box !important;
    }
    body.contacts-page.feed-insta-ui .x-right-rail .sfy-panel-head{
      margin:0 0 8px !important;
    }
    body.contacts-page.feed-insta-ui .x-right-rail .sfy-title{
      font-size:20px !important;
      font-weight:900 !important;
      letter-spacing:-.02em !important;
      color:var(--x-text) !important;
    }
    body.contacts-page.feed-insta-ui .x-right-rail .sfy-panel-body{
      padding-right:4px !important;
      max-height:min(340px, 48vh) !important;
    }

    .x-trend{
      display: block;
      width: 100%;
      padding: 12px 16px;
      border: 0;
      background: transparent;
      text-align: left;
      text-decoration: none;
      color: inherit;
      box-sizing: border-box;
      position: relative;
      transition: background .12s ease;
    }
    .x-trend:hover{background: var(--x-hover); text-decoration: none; color: inherit;}
    .x-trend-meta{
      display: block;
      font-size: 13px;
      color: var(--x-muted);
      line-height: 1.2;
      margin-bottom: 2px;
    }
    .x-trend-title{
      display: block;
      font-size: 15px;
      font-weight: 800;
      color: var(--x-text);
      line-height: 1.25;
    }
    .x-trend-sub{
      display: block;
      margin-top: 2px;
      font-size: 13px;
      color: var(--x-muted);
      line-height: 1.25;
    }
    .x-trend-more{
      position: absolute;
      top: 10px;
      right: 12px;
      width: 30px;
      height: 30px;
      border: 0;
      border-radius: 999px;
      background: transparent;
      color: var(--x-muted);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      padding: 0;
    }
    .x-trend-more:hover{background: rgba(29,155,240,.1); color: var(--x-accent);}
    .x-trend-more i{font-size: 14px;}
    .x-rail-show-more{
      display: block;
      padding: 14px 16px;
      color: var(--x-accent);
      font-size: 15px;
      font-weight: 500;
      text-decoration: none;
    }
    .x-rail-show-more:hover{background: var(--x-hover); text-decoration: none; color: var(--x-accent);}

    .x-rail-main{
      /* Align with center column tabs / Add Friend — below sticky Search */
      margin-top: 56px;
      flex: 0 0 auto;
      width: 100%;
    }
    .x-rail-footer{
      margin-top: auto;
      padding: 16px 8px 0;
      font-size: 13px;
      line-height: 1.5;
      color: var(--x-muted);
      flex: 0 0 auto;
    }
    .x-rail-footer a{
      color: var(--x-muted);
      text-decoration: none;
    }
    .x-rail-footer a:hover{text-decoration: underline;}
    .x-rail-footer-sep{margin: 0 4px; opacity: .7;}

    html[data-msb-appearance] body.contacts-page,
    html[data-msb-appearance] body.contacts-page .sh-mainpanel,
    html[data-msb-appearance] body.contacts-page .sh-pagebody,
    html[data-msb-appearance] body.contacts-page .contacts-shell,
    html[data-msb-appearance] body.contacts-page .contacts-fixed,
    html[data-msb-appearance] body.contacts-page .contacts-scroll,
    html[data-msb-appearance] body.contacts-page .x-right-rail,
    html[data-msb-appearance] body.contacts-page .x-rail-card{
      background-color: var(--msb-palette-bg) !important;
      color: var(--msb-palette-text) !important;
    }

    @media (max-width: 1280px) {
      .x-right-rail{flex-basis: 300px; width: 300px; max-width: 300px;}
    }

    @media (max-width: 1100px) {
      .x-right-rail{display: none;}
      .contacts-layout{padding-right: 0;}
      .contacts-shell{max-width: none;}
    }

    @media (max-width: 991.98px) {
      html, body.contacts-page { height: auto !important; overflow: auto !important; }
      .sh-mainpanel{
        margin-left: 0 !important;
        margin-top: 0 !important;
        height: auto !important;
        min-height: 100vh;
      }
      .sh-pagebody{ overflow: visible !important; }
      .contacts-layout{
        display: block;
        overflow: visible;
        padding: 0;
      }
      .contacts-shell{
        margin: 0 !important;
        max-width: none;
        height: auto !important;
        border-left:0;
        border-right:0;
      }
      .contacts-scroll{ overflow: visible; }
      .x-tabs .x-tab{font-size:14px;padding:12px 4px;}
    }

    @media (max-width: 575.98px) {
      .x-top-name{font-size:18px;}
      .x-msg-btn{min-width:72px;padding:0 12px;font-size:13px;}
      .contact-row{padding:12px;}
    }
  </style>
</head>

<body class="contacts-page feed-insta-ui">

<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
  <!-- <div class="sh-pagetitle">
    <div class="input-group">
      <input type="search" class="form-control" placeholder="Search">
      <span class="input-group-btn">
        <button class="btn"><i class="fa fa-search"></i></button>
      </span>
    </div>
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-person-add"></i></div>
      <div class="sh-pagetitle-title">
        <span>Form Styles</span>
        <h2>Friends</h2>
      </div>
    </div>
  </div> -->

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="contacts-layout">
    <div class="contacts-shell">
      <div class="contacts-fixed">
        <div class="contacts-head-chrome">
        <div class="x-topbar">
          <a class="x-back" href="feed.php" aria-label="Back">
            <i class="fa fa-arrow-left" aria-hidden="true"></i>
          </a>
          <div class="x-top-meta">
            <h1 class="x-top-name"><?= h($meDisplayName) ?></h1>
            <p class="x-top-sub" id="contactsTabSub" data-friends-label="<?= h($friendCountLabel) ?>" data-requests-label="<?= h($pendingRequestLabel) ?>" data-add-label="Add a friend"><?php
              $tabSubNow = $friendCountLabel;
              if ($activeTab === 'requests') $tabSubNow = $pendingRequestLabel;
              elseif ($activeTab === 'add') $tabSubNow = 'Add a friend';
              echo h($tabSubNow);
            ?><?php if ($meHandle !== ''): ?> · <?= h($meHandle) ?><?php endif; ?></p>
          </div>
        </div>

        <nav class="x-tabs" aria-label="Friends sections">
          <button type="button" class="x-tab<?= $activeTab === 'friends' ? ' is-active' : '' ?>" data-contacts-tab="friends"<?= $activeTab === 'friends' ? ' aria-current="page"' : '' ?>>Friends</button>
          <button type="button" class="x-tab<?= $activeTab === 'requests' ? ' is-active' : '' ?>" data-contacts-tab="requests"<?= $activeTab === 'requests' ? ' aria-current="page"' : '' ?>>Friend Requests<?= $pendingRequestCount > 0 ? ' (' . (int)$pendingRequestCount . ')' : '' ?></button>
          <button type="button" class="x-tab<?= $activeTab === 'add' ? ' is-active' : '' ?>" data-contacts-tab="add"<?= $activeTab === 'add' ? ' aria-current="page"' : '' ?>>Add Friend</button>
        </nav>
        </div>

        <div class="x-search-wrap<?= $activeTab !== 'friends' ? ' is-hidden' : '' ?>" id="contactsSearchWrap">
          <div class="x-search">
            <i class="fa fa-search search-ico" aria-hidden="true"></i>
            <input type="search" id="contactSearch" class="form-control" placeholder="Search friends" autocomplete="off">
          </div>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
      </div>

      <div class="contacts-scroll">
        <div class="x-tab-panel" id="panelFriends" data-panel="friends"<?= $activeTab !== 'friends' ? ' hidden' : '' ?>>
        <?php if (empty($rows)): ?>
          <div class="x-empty">
            <h3>Looking for friends?</h3>
            <p>When people accept your friend requests, they’ll show up here.</p>
            <button type="button" class="btn btn-primary" data-contacts-tab="add">Add Friend</button>
          </div>
        <?php else: ?>
          <div class="x-list" id="contactsTbody" role="list">
            <?php foreach ($rows as $c): ?>
              <?php
                $id    = (int)($c['id'] ?? 0);
                $label = trim((string)($c['display_name'] ?? ''));
                $code  = trim((string)($c['friend_code'] ?? ''));
                $email = trim((string)($c['friend_email'] ?? ''));
                $uname = trim((string)($c['friend_username'] ?? ''));
                $sub   = $code !== '' ? $code : $email;
                $handle = $uname !== '' ? '@' . $uname : ($code !== '' ? $code : $email);
                $toParam = $code !== '' ? $code : $email;
                $profileUrl = '';
                if (!empty($c['friend_user_id'])) {
                  $profileUrl = 'profile.php?id=' . (int)$c['friend_user_id'] . '&tab=gallery';
                } elseif ($code !== '') {
                  $profileUrl = 'profile.php?friend_code=' . urlencode($code) . '&tab=gallery';
                }
                $messageUrl = 'user_sendreply.php?to=' . urlencode($toParam);

                $searchHay = strtolower($label . ' ' . $sub . ' ' . $email . ' ' . $uname);

                $fallback = $sub !== '' ? mb_strtoupper(mb_substr($sub, 0, 2)) : 'CT';
                $ini = initials_from_name($label !== '' ? $label : $sub, $fallback);
                $uniqueKey = $code !== '' ? $code : ($email !== '' ? $email : ($label !== '' ? $label : $ini));
                $peerKey   = normalize_avatar_key($uniqueKey);
                $peerColor = color_from_string($peerKey);
                $peerGrad  = avatar_gradient_style($peerColor);
              ?>
              <article class="contact-row"
                       role="listitem"
                       data-id="<?= $id ?>"
                       data-hay="<?= h($searchHay) ?>">
                <?php if ($profileUrl !== ''): ?>
                  <a class="x-avatar-link" href="<?= h($profileUrl) ?>" aria-label="View <?= h($label !== '' ? $label : 'friend') ?>">
                    <div class="avatar-circle" data-avatar-key="<?= h($peerKey) ?>" style="<?= h($peerGrad) ?>"><img src="avatar.php?friend_code=<?= urlencode($code) ?>&email=<?= urlencode($email) ?>&name=<?= urlencode($label !== "" ? $label : $sub) ?>" data-live-avatar="1" data-avatar-base="avatar.php?friend_code=<?= urlencode($code) ?>&email=<?= urlencode($email) ?>&name=<?= urlencode($label !== "" ? $label : $sub) ?>" alt=""></div>
                  </a>
                <?php else: ?>
                  <div class="avatar-circle" data-avatar-key="<?= h($peerKey) ?>" style="<?= h($peerGrad) ?>"><img src="avatar.php?friend_code=<?= urlencode($code) ?>&email=<?= urlencode($email) ?>&name=<?= urlencode($label !== "" ? $label : $sub) ?>" data-live-avatar="1" data-avatar-base="avatar.php?friend_code=<?= urlencode($code) ?>&email=<?= urlencode($email) ?>&name=<?= urlencode($label !== "" ? $label : $sub) ?>" alt=""></div>
                <?php endif; ?>

                <div class="x-row-main">
                  <div class="x-row-top">
                    <div class="x-id">
                      <p class="x-name" id="nameText-<?= $id ?>">
                        <?php if ($profileUrl !== ''): ?>
                          <a href="<?= h($profileUrl) ?>"><?= h($label) ?></a>
                        <?php else: ?>
                          <?= h($label) ?>
                        <?php endif; ?>
                      </p>
                      <p class="x-handle"><?= h($handle) ?></p>
                    </div>
                    <div class="x-row-actions">
                      <a class="x-msg-btn" href="<?= h($messageUrl) ?>">Message</a>
                      <div class="dropdown row-menu">
                        <button class="btn dropdown-toggle row-menu-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="More actions" aria-label="More actions">
                          <?= post_card_menu_fries_icon_html() ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right">
                          <?php if ($profileUrl !== ''): ?>
                            <a class="dropdown-item" href="<?= h($profileUrl) ?>">
                              <i class="fa fa-user"></i> View
                            </a>
                          <?php endif; ?>
                          <a class="dropdown-item" href="<?= h($messageUrl) ?>">
                            <i class="fa fa-comments"></i> Message
                          </a>
                          <?php if (!empty($c['friend_user_id'])): ?>
                            <a class="dropdown-item" href="timeline.php?u=<?= (int)$c['friend_user_id'] ?>">
                              <i class="icon ion-ios-locked"></i> Timeline
                            </a>
                          <?php endif; ?>
                          <button class="dropdown-item" type="button"
                                  data-undo-id="<?= $id ?>">
                            <i class="fa fa-undo"></i> Undo Rename
                          </button>
                          <button class="dropdown-item" type="button"
                                  data-rename-id="<?= $id ?>"
                                  data-rename-name="<?= h($label) ?>">
                            <i class="fa fa-pencil"></i> Rename
                          </button>
                          <div class="dropdown-divider"></div>
                          <?php if (!empty($c['friend_user_id'])): ?>
                            <button class="dropdown-item text-danger" type="button"
                                    data-block-peer="<?= (int)$c['friend_user_id'] ?>"
                                    data-contact-id="<?= $id ?>">
                              <i class="fa fa-ban"></i> Block
                            </button>
                            <button class="dropdown-item text-danger" type="button"
                                    data-unfriend-peer="<?= (int)$c['friend_user_id'] ?>"
                                    data-contact-id="<?= $id ?>">
                              <i class="fa fa-user-times"></i> Unfriend
                            </button>
                          <?php else: ?>
                            <a class="dropdown-item text-danger"
                               href="contacts.php?del=<?= $id ?>"
                               onclick="return confirm('Delete this contact?');">
                              <i class="fa fa-trash"></i> Delete
                            </a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php if ($email !== '' || $code !== ''): ?>
                    <p class="x-bio mono" id="codeText-<?= $id ?>"><?= h($sub) ?></p>
                  <?php else: ?>
                    <span id="codeText-<?= $id ?>" class="d-none"></span>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        </div>

        <div class="x-tab-panel" id="panelRequests" data-panel="requests"<?= $activeTab !== 'requests' ? ' hidden' : '' ?>>
          <?php if (empty($pendingRequests)): ?>
            <div class="x-empty">
              <h3>No friend requests</h3>
              <p>When someone sends you a friend request, it will show up here.</p>
            </div>
          <?php else: ?>
            <div class="x-list" id="requestsList" role="list">
              <?php foreach ($pendingRequests as $r): ?>
                <?php
                  $reqId = (int)($r['id'] ?? 0);
                  $fromId = (int)($r['from_user_id'] ?? 0);
                  $rName = trim((string)($r['name'] ?? ''));
                  $rUser = trim((string)($r['username'] ?? ''));
                  $rEmail = trim((string)($r['email'] ?? ''));
                  $rCode = trim((string)($r['friend_code'] ?? ''));
                  $rHandle = $rUser !== '' ? '@' . $rUser : ($rCode !== '' ? $rCode : $rEmail);
                  $rLabel = $rName !== '' ? $rName : ($rUser !== '' ? $rUser : 'User');
                  $rSub = $rCode !== '' ? $rCode : $rEmail;
                  $rProfile = $fromId > 0
                    ? ('profile.php?id=' . $fromId . '&tab=gallery')
                    : ($rCode !== '' ? ('profile.php?friend_code=' . urlencode($rCode) . '&tab=gallery') : '');
                  $rFallback = $rSub !== '' ? mb_strtoupper(mb_substr($rSub, 0, 2)) : 'RQ';
                  $rIni = initials_from_name($rLabel, $rFallback);
                  $rKey = normalize_avatar_key($rCode !== '' ? $rCode : ($rEmail !== '' ? $rEmail : $rLabel));
                  $rGrad = avatar_gradient_style(color_from_string($rKey));
                  $rTime = !empty($r['created_at']) ? date('M j', strtotime((string)$r['created_at'])) : '';
                ?>
                <article class="contact-row request-row"
                         role="listitem"
                         data-request-id="<?= $reqId ?>">
                  <?php if ($rProfile !== ''): ?>
                    <a class="x-avatar-link" href="<?= h($rProfile) ?>" aria-label="View <?= h($rLabel) ?>">
                      <div class="avatar-circle" data-avatar-key="<?= h($rKey) ?>" style="<?= h($rGrad) ?>"><img src="avatar.php?friend_code=<?= urlencode($rCode) ?>&email=<?= urlencode($rEmail) ?>&name=<?= urlencode($rLabel) ?>" data-live-avatar="1" data-avatar-base="avatar.php?friend_code=<?= urlencode($rCode) ?>&email=<?= urlencode($rEmail) ?>&name=<?= urlencode($rLabel) ?>" alt=""></div>
                    </a>
                  <?php else: ?>
                    <div class="avatar-circle" data-avatar-key="<?= h($rKey) ?>" style="<?= h($rGrad) ?>"><img src="avatar.php?friend_code=<?= urlencode($rCode) ?>&email=<?= urlencode($rEmail) ?>&name=<?= urlencode($rLabel) ?>" alt=""></div>
                  <?php endif; ?>
                  <div class="x-row-main">
                    <div class="x-row-top">
                      <div class="x-id">
                        <p class="x-name">
                          <?php if ($rProfile !== ''): ?>
                            <a href="<?= h($rProfile) ?>"><?= h($rLabel) ?></a>
                          <?php else: ?>
                            <?= h($rLabel) ?>
                          <?php endif; ?>
                        </p>
                        <p class="x-handle"><?= h($rHandle) ?><?php if ($rTime !== ''): ?> · <?= h($rTime) ?><?php endif; ?></p>
                      </div>
                      <div class="x-req-actions">
                        <button type="button" class="x-req-btn x-req-accept" data-request-action="accept" data-request-id="<?= $reqId ?>">Accept</button>
                        <button type="button" class="x-req-btn x-req-decline" data-request-action="decline" data-request-id="<?= $reqId ?>">Decline</button>
                      </div>
                    </div>
                    <?php if ($rEmail !== ''): ?>
                      <p class="x-bio"><?= h($rEmail) ?></p>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="x-tab-panel" id="panelAdd" data-panel="add"<?= $activeTab !== 'add' ? ' hidden' : '' ?>>
          <div class="x-add-wrap">
            <div class="x-add-card">
              <h3>Add Friend</h3>
              <p>Send a friend request using their friend code.</p>
              <div id="addFriendAlert" class="x-add-alert" role="status"></div>
              <form id="addFriendForm" autocomplete="off">
                <div class="x-add-field">
                  <label for="addFriendCode">Friend Code <span style="color:#dc2626;">*</span></label>
                  <input type="text" id="addFriendCode" name="friend" class="form-control" placeholder="e.g. USR-XXXX-YYYY" value="<?= h($prefillFriendCode) ?>" required>
                  <p class="x-add-hint">Use their friend code exactly as shown on their profile.</p>
                </div>
                <div class="x-add-field">
                  <label for="addFriendDisplay">Display Name (optional)</label>
                  <input type="text" id="addFriendDisplay" name="display_name" class="form-control" placeholder="e.g. John (Church friend)">
                  <p class="x-add-hint">Optional nickname for your own Friends list after they accept.</p>
                </div>
                <button type="submit" class="x-add-submit" id="addFriendSubmit">Send request</button>
              </form>
            </div>
          </div>
        </div>
      </div>

    </div>

    <aside class="x-right-rail" aria-label="Explore">
      <div class="x-rail-search">
        <form class="x-rail-search-field" action="public.php" method="get" role="search">
          <i class="fa fa-search" aria-hidden="true"></i>
          <input class="x-rail-search-input" type="search" name="q" placeholder="Search" autocomplete="off">
        </form>
      </div>

      <div class="x-rail-main">
      <?php
        require_once __DIR__ . '/includes/staff_publisher_access.php';
        $suggestedForYouStaffReadonly = staff_pub_is_readonly();
        // Contacts is personal: suggest people to add as friends (same as Discover).
        $suggestedForYouMaxFriends = 6;
        $suggestedForYouMaxFollow = 0;
        $suggestedForYouMaxAdvertise = 0;
        include __DIR__ . '/includes/suggested_for_you.php';
      ?>

      <div class="x-rail-card">
        <div class="x-rail-card-pad" style="padding-bottom:8px;">
          <h2 class="x-rail-card-title">What’s happening</h2>
        </div>
        <?php if ($happeningItems): ?>
          <?php foreach ($happeningItems as $item): ?>
            <a class="x-trend" href="<?= h((string)$item['href']) ?>">
              <span class="x-trend-meta"><?= h((string)$item['meta']) ?></span>
              <span class="x-trend-title"><?= h((string)$item['title']) ?></span>
              <span class="x-trend-more" aria-hidden="true"><i class="fa fa-ellipsis-h"></i></span>
            </a>
          <?php endforeach; ?>
          <a class="x-rail-show-more" href="public.php">Show more</a>
        <?php else: ?>
          <p class="x-trend" style="cursor:default;pointer-events:none;">
            <span class="x-trend-meta">Publishers</span>
            <span class="x-trend-title" style="font-weight:600;color:var(--x-muted);">No publisher posts yet.</span>
          </p>
          <a class="x-rail-show-more" href="public.php">Explore public</a>
        <?php endif; ?>
      </div>
      </div>

      <div class="x-rail-footer">
        <a href="#">Terms</a><span class="x-rail-footer-sep">·</span>
        <a href="#">Privacy</a><span class="x-rail-footer-sep">·</span>
        <a href="#">Cookies</a><span class="x-rail-footer-sep">·</span>
        <a href="#">Accessibility</a><span class="x-rail-footer-sep">·</span>
        <a href="#">Ads info</a><span class="x-rail-footer-sep">·</span>
        <a href="#">More...</a>
        <div style="margin-top:4px;">© <?= date('Y') ?> Talentra</div>
      </div>
    </aside>
    </div>
  </div>
</div>

<!-- Rename Modal -->
<div class="modal fade" id="renameModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document" style="max-width:520px;">
    <div class="modal-content" style="border-radius:14px;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-pencil"></i> Rename Friend</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="renameId" value="0">
        <label style="font-weight:800;">Display Name</label>
        <input id="renameInput" class="form-control" placeholder="Enter new name..." autocomplete="off">
        <small class="d-block mt-2" style="opacity:.75;">
          This only changes how it appears in your Friends list.
        </small>
        <div id="renameErr" class="alert alert-danger mt-3" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="renameSaveBtn"><i class="fa fa-save"></i> Save</button>
      </div>
    </div>
  </div>
</div>



<script>
(function(){
  // In-page Friends / Friend Requests / Add Friend tabs
  var tabBtns = Array.prototype.slice.call(document.querySelectorAll('[data-contacts-tab]'));
  var panelFriends = document.getElementById('panelFriends');
  var panelRequests = document.getElementById('panelRequests');
  var panelAdd = document.getElementById('panelAdd');
  var searchWrap = document.getElementById('contactsSearchWrap');
  var tabSub = document.getElementById('contactsTabSub');
  var meHandleSuffix = <?= json_encode($meHandle !== '' ? (' · ' . $meHandle) : '', JSON_UNESCAPED_UNICODE) ?>;

  function setContactsTab(tab, pushUrl){
    if (tab !== 'requests' && tab !== 'add') tab = 'friends';
    tabBtns.forEach(function(btn){
      var on = btn.getAttribute('data-contacts-tab') === tab;
      btn.classList.toggle('is-active', on);
      if (on) btn.setAttribute('aria-current', 'page');
      else btn.removeAttribute('aria-current');
    });
    if (panelFriends) panelFriends.hidden = (tab !== 'friends');
    if (panelRequests) panelRequests.hidden = (tab !== 'requests');
    if (panelAdd) panelAdd.hidden = (tab !== 'add');
    if (searchWrap) searchWrap.classList.toggle('is-hidden', tab !== 'friends');
    if (tabSub) {
      var label = tabSub.getAttribute('data-friends-label') || '';
      if (tab === 'requests') label = tabSub.getAttribute('data-requests-label') || '';
      else if (tab === 'add') label = tabSub.getAttribute('data-add-label') || 'Add a friend';
      tabSub.textContent = label + meHandleSuffix;
    }
    if (pushUrl !== false) {
      try {
        var url = new URL(window.location.href);
        if (tab === 'friends') url.searchParams.delete('tab');
        else url.searchParams.set('tab', tab);
        history.replaceState({}, '', url.pathname + url.search + url.hash);
      } catch (err) {}
    }
    if (tab === 'add') {
      var codeInput = document.getElementById('addFriendCode');
      if (codeInput) setTimeout(function(){ codeInput.focus(); }, 50);
    }
  }

  tabBtns.forEach(function(btn){
    btn.addEventListener('click', function(){
      setContactsTab(btn.getAttribute('data-contacts-tab') || 'friends', true);
    });
  });

  window.msbContactsSetTab = setContactsTab;

  // Add Friend form (uses add_contact.php ajax)
  var addForm = document.getElementById('addFriendForm');
  if (addForm) {
    addForm.addEventListener('submit', function(e){
      e.preventDefault();
      var codeEl = document.getElementById('addFriendCode');
      var nameEl = document.getElementById('addFriendDisplay');
      var alertEl = document.getElementById('addFriendAlert');
      var submitBtn = document.getElementById('addFriendSubmit');
      var friend = (codeEl && codeEl.value ? codeEl.value.trim() : '');
      var displayName = (nameEl && nameEl.value ? nameEl.value.trim() : '');
      if (alertEl) {
        alertEl.className = 'x-add-alert';
        alertEl.textContent = '';
      }
      if (!friend) {
        if (alertEl) {
          alertEl.className = 'x-add-alert is-error';
          alertEl.textContent = 'Enter a friend code.';
        }
        return;
      }
      if (submitBtn) submitBtn.disabled = true;
      fetch('add_contact.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({ ajax: 'add', friend: friend, display_name: displayName })
      }).then(function(r){ return r.json(); }).then(function(res){
        if (submitBtn) submitBtn.disabled = false;
        if (!res || !res.ok) {
          if (alertEl) {
            alertEl.className = 'x-add-alert is-error';
            alertEl.textContent = (res && (res.error || res.message)) ? (res.error || res.message) : 'Unable to send request.';
          }
          return;
        }
        if (alertEl) {
          alertEl.className = 'x-add-alert is-ok';
          alertEl.textContent = res.message || 'Friend request sent.';
        }
        if (codeEl) codeEl.value = '';
        if (nameEl) nameEl.value = '';
      }).catch(function(){
        if (submitBtn) submitBtn.disabled = false;
        if (alertEl) {
          alertEl.className = 'x-add-alert is-error';
          alertEl.textContent = 'Unable to send request.';
        }
      });
    });
  }

  // Search filter (friends list only)
  var searchEl = document.getElementById('contactSearch');
  var rows = Array.prototype.slice.call(document.querySelectorAll('#contactsTbody .contact-row'));

  function applySearch(){
    var q = (searchEl && searchEl.value ? searchEl.value.trim().toLowerCase() : '');
    rows.forEach(function(r){
      var hay = (r.getAttribute('data-hay') || '');
      r.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
    });
  }

  if (searchEl) searchEl.addEventListener('input', applySearch);

  // Accept / Decline friend requests (stay on contacts.php)
  document.addEventListener('click', function(e){
    var btn = e.target.closest ? e.target.closest('[data-request-action]') : null;
    if (!btn) return;
    var action = btn.getAttribute('data-request-action') || '';
    var reqId = parseInt(btn.getAttribute('data-request-id') || '0', 10) || 0;
    if (!reqId || (action !== 'accept' && action !== 'decline')) return;
    var row = btn.closest ? btn.closest('.request-row') : null;
    var actions = row ? row.querySelectorAll('[data-request-action]') : [btn];
    actions.forEach(function(b){ b.disabled = true; });
    fetch('contacts.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams({ ajax: 'respond_request', action: action, id: String(reqId) })
    }).then(function(r){ return r.json(); }).then(function(res){
      if (!res || !res.ok) {
        actions.forEach(function(b){ b.disabled = false; });
        alert((res && res.message) ? res.message : 'Unable to update request.');
        return;
      }
      if (row) row.remove();
      var left = document.querySelectorAll('#requestsList .request-row');
      var count = left.length;
      var label = count === 1 ? '1 request' : (String(count) + ' requests');
      if (tabSub) tabSub.setAttribute('data-requests-label', label);
      if (tabSub && !panelRequests.hidden) tabSub.textContent = label + meHandleSuffix;
      var reqTab = document.querySelector('[data-contacts-tab="requests"]');
      if (reqTab) reqTab.textContent = count > 0 ? ('Friend Requests (' + count + ')') : 'Friend Requests';
      if (!count) {
        var list = document.getElementById('requestsList');
        if (list) {
          list.outerHTML = '<div class="x-empty"><h3>No friend requests</h3><p>When someone sends you a friend request, it will show up here.</p></div>';
        }
      }
      if (action === 'accept') {
        // Refresh friends list so the new friend appears.
        window.location.href = 'contacts.php';
      }
    }).catch(function(){
      actions.forEach(function(b){ b.disabled = false; });
      alert('Unable to update request.');
    });
  });

  // Copy friend code/email
  document.addEventListener('click', async function(e){
    var b = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!b) return;
    var text = b.getAttribute('data-copy') || '';
    try{
      await navigator.clipboard.writeText(text);
      b.innerHTML = '<i class="fa fa-check"></i>';
      setTimeout(function(){ b.innerHTML = '<i class="fa fa-copy"></i>'; }, 900);
    }catch(err){}
  });

  // Block friend — they can no longer see your profile, posts, or messages
  document.addEventListener('click', function(e){
    var btn = e.target.closest ? e.target.closest('[data-block-peer]') : null;
    if (!btn) return;
    var peerId = parseInt(btn.getAttribute('data-block-peer') || '0', 10) || 0;
    var contactId = parseInt(btn.getAttribute('data-contact-id') || '0', 10) || 0;
    if (!peerId) return;
    if (!confirm('Block this person? They will no longer see your profile, posts, or messages, and you will no longer be friends.')) return;
    btn.disabled = true;
    fetch('ajax/friend_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams({ action: 'block', peer_id: String(peerId) })
    }).then(function(r){ return r.json(); }).then(function(res){
      if (!res || !res.ok) {
        btn.disabled = false;
        alert((res && res.message) ? res.message : 'Unable to block.');
        return;
      }
      var row = contactId
        ? document.querySelector('.contact-row[data-id="' + String(contactId) + '"]')
        : (btn.closest ? btn.closest('.contact-row') : null);
      if (row) row.remove();
      rows = Array.prototype.slice.call(document.querySelectorAll('#contactsTbody .contact-row'));
      if (!rows.length) {
        window.location.reload();
      }
    }).catch(function(){
      btn.disabled = false;
      alert('Unable to block.');
    });
  });

  // Unfriend (same as feed fries → Unfriend)
  document.addEventListener('click', function(e){
    var btn = e.target.closest ? e.target.closest('[data-unfriend-peer]') : null;
    if (!btn) return;
    var peerId = parseInt(btn.getAttribute('data-unfriend-peer') || '0', 10) || 0;
    var contactId = parseInt(btn.getAttribute('data-contact-id') || '0', 10) || 0;
    if (!peerId) return;
    if (!confirm('Unfriend this person?')) return;
    btn.disabled = true;
    fetch('ajax/friend_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams({ action: 'unfriend', peer_id: String(peerId) })
    }).then(function(r){ return r.json(); }).then(function(res){
      if (!res || !res.ok) {
        btn.disabled = false;
        alert((res && res.message) ? res.message : 'Unable to unfriend.');
        return;
      }
      var row = contactId
        ? document.querySelector('.contact-row[data-id="' + String(contactId) + '"]')
        : (btn.closest ? btn.closest('.contact-row') : null);
      if (row) row.remove();
      rows = Array.prototype.slice.call(document.querySelectorAll('#contactsTbody .contact-row'));
      if (!rows.length) {
        window.location.reload();
      }
    }).catch(function(){
      btn.disabled = false;
      alert('Unable to unfriend.');
    });
  });

  // Rename modal open
  document.addEventListener('click', function(e){
    var btn = e.target.closest ? e.target.closest('[data-rename-id]') : null;
    if (!btn) return;
    var id = parseInt(btn.getAttribute('data-rename-id') || '0', 10) || 0;
    var name = btn.getAttribute('data-rename-name') || '';
    if (!id) return;

    document.getElementById('renameId').value = String(id);
    document.getElementById('renameInput').value = name;
    document.getElementById('renameErr').style.display = 'none';

    if (window.jQuery && jQuery.fn && jQuery.fn.modal) {
      jQuery('#renameModal').modal('show');
      setTimeout(function(){ document.getElementById('renameInput').focus(); }, 250);
    }
  });

  // Rename save
  var saveBtn = document.getElementById('renameSaveBtn');
  if (saveBtn){
    saveBtn.addEventListener('click', async function(){
      var id = parseInt(document.getElementById('renameId').value || '0', 10) || 0;
      var newName = (document.getElementById('renameInput').value || '').trim();
      var errBox = document.getElementById('renameErr');

      errBox.style.display = 'none';
      errBox.textContent = '';

      if (!id || !newName){
        errBox.textContent = 'Name is required.';
        errBox.style.display = 'block';
        return;
      }

      try{
        var res = await fetch('ajax/contact_rename.php', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
          body: new URLSearchParams({ contact_id: String(id), display_name: newName })
        });
        var data = await res.json().catch(function(){ return {}; });

        if (!data.ok){
          throw new Error(data.error || 'Rename failed.');
        }

        var nameEl = document.getElementById('nameText-' + id);
        if (nameEl){
          var nameLink = nameEl.querySelector('a');
          if (nameLink) nameLink.textContent = newName;
          else nameEl.textContent = newName;
        }

        var row = document.querySelector('.contact-row[data-id="' + id + '"]');
        if (row){
          var code = (document.getElementById('codeText-' + id)?.textContent || '');
          var handle = (row.querySelector('.x-handle')?.textContent || '');
          row.setAttribute('data-hay', (newName + ' ' + code + ' ' + handle).toLowerCase());
        }

        var renameBtn = document.querySelector('[data-rename-id="' + id + '"]');
        if (renameBtn) renameBtn.setAttribute('data-rename-name', newName);

        if (window.jQuery && jQuery.fn && jQuery.fn.modal) {
          jQuery('#renameModal').modal('hide');
        }

      }catch(ex){
        errBox.textContent = ex && ex.message ? ex.message : 'Rename failed.';
        errBox.style.display = 'block';
      }
    });
  }

  // Undo rename
  document.addEventListener('click', async function(e){
    var btn = e.target.closest ? e.target.closest('[data-undo-id]') : null;
    if (!btn) return;

    var id = parseInt(btn.getAttribute('data-undo-id') || '0', 10) || 0;
    if (!id) return;

    try{
      var res = await fetch('ajax/contact_undo_rename.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: new URLSearchParams({ contact_id: String(id) })
      });
      var data = await res.json().catch(function(){ return {}; });

      if (!data.ok){
        throw new Error(data.error || 'Nothing to undo.');
      }

      var label = (data.display_name || '').toString();

      var nameEl = document.getElementById('nameText-' + id);
      if (nameEl){
        var nameLink = nameEl.querySelector('a');
        if (nameLink) nameLink.textContent = label;
        else nameEl.textContent = label;
      }

      var renameBtn = document.querySelector('[data-rename-id="' + id + '"]');
      if (renameBtn) renameBtn.setAttribute('data-rename-name', label);

      var row = document.querySelector('.contact-row[data-id="' + id + '"]');
      if (row){
        var code = (document.getElementById('codeText-' + id)?.textContent || '');
        var handle = (row.querySelector('.x-handle')?.textContent || '');
        row.setAttribute('data-hay', (label + ' ' + code + ' ' + handle).toLowerCase());
      }

    }catch(ex){}
  });

})();
</script>
<script>
(function($){
  if(!$ || !$.fn) return;

  function applyStatus(btn, status){
    status = String(status || 'none');
    btn.classList.remove('primary','is-friends','is-pending','is-accept');
    btn.disabled = false;
    if(status === 'friends'){
      btn.textContent = 'Friends';
      btn.classList.add('is-friends');
      btn.disabled = true;
      var row = btn.closest ? btn.closest('.sfy-row') : null;
      if(row) row.remove();
    } else if(status === 'incoming_pending'){
      btn.textContent = 'Accept';
      btn.classList.add('is-accept');
    } else if(status === 'outgoing_pending'){
      btn.textContent = 'Sent';
      btn.classList.add('is-pending');
      btn.disabled = true;
    } else {
      btn.textContent = '+';
      btn.classList.add('primary');
    }
    btn.setAttribute('data-status', status);
  }

  function applyStatusForPeer(peerId, status){
    peerId = Number(peerId || 0);
    if(!peerId) return;
    document.querySelectorAll('.friend-btn[data-peer-id="'+String(peerId)+'"]').forEach(function(btn){
      applyStatus(btn, status);
    });
  }

  $(document).on('click', '.publisher-follow-btn', function(){
    var btn = this;
    var id = btn.getAttribute('data-publisher-id') || '';
    if(!id) return;
    var fd = new FormData();
    fd.append('target_id', id);
    fetch('publisher_follow_toggle.php', { method:'POST', body: fd, cache:'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if(!res || !res.ok) return;
        var on = !!res.following;
        btn.classList.toggle('is-following', on);
        btn.classList.toggle('primary', !on);
        btn.textContent = on ? 'Following' : 'Follow';
        if(on){
          var row = btn.closest ? btn.closest('.sfy-row') : null;
          if(row) row.remove();
        }
      });
  });

  $(document).on('click', '.friend-btn', function(){
    var $btn = $(this), peerId = Number($btn.data('peer-id') || 0), status = String($btn.data('status') || '');
    if(!peerId) return;
    if(status === 'incoming_pending' || status === 'outgoing_pending'){
      if (typeof window.msbContactsSetTab === 'function') {
        window.msbContactsSetTab('requests', true);
      } else {
        window.location.href = 'contacts.php?tab=requests';
      }
      return;
    }
    $btn.prop('disabled', true);
    $.post('ajax/friend_action.php', { action:'send', peer_id: peerId }, function(res){
      if(res && res.status){ applyStatusForPeer(peerId, String(res.status)); }
      $btn.prop('disabled', false);
    }, 'json').fail(function(){
      $btn.prop('disabled', false);
    });
  });

  $('.friend-btn').each(function(){
    var btn = this, peerId = Number(btn.getAttribute('data-peer-id') || '0');
    if(!peerId) return;
    $.getJSON('ajax/friend_status.php', { peer_id: peerId }, function(res){
      if(res && res.status) applyStatus(btn, String(res.status));
    });
  });
})(window.jQuery);
</script>
<!-- <?php include __DIR__ . '/includes/footer.php'; ?> -->
</body>
</html>
