<?php
declare(strict_types=1);

/**
 * explore.php — Discover grid with search, category chips, captions + hashtags.
 */
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/missing_media.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/post_action_thin_icons.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/home_feed_tabs.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

function explore_media_src(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $path = (string)preg_replace('#^(/+)?public_user/#', '', $path);
    if (preg_match('~^(https?:)?//~i', $path)) {
        return $path;
    }
    if (isset($path[0]) && $path[0] === '/') {
        return $path;
    }
    return './' . ltrim($path, './');
}

function explore_path_is_video(string $path): bool
{
    return (bool)preg_match('/\.(mp4|webm|mov|m4v|ogg)(\?|#|$)/i', $path);
}

function explore_path_is_image(string $path): bool
{
    return (bool)preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)(\?|#|$)/i', $path);
}

/** @return list<array{key:string,label:string}> */
function explore_category_chips(PDO $dbh, int $userId): array
{
    $chips = [['key' => 'all', 'label' => 'All']];
    $catalog = function_exists('home_feed_program_catalog')
        ? home_feed_program_catalog($dbh)
        : [];
    $state = function_exists('home_feed_load_pins_state')
        ? home_feed_load_pins_state($dbh, $userId)
        : ['pins' => [], 'saved' => false];
    $pins = is_array($state['pins'] ?? null) ? $state['pins'] : [];
    $seen = ['all' => true];
    foreach ($pins as $rawSlug) {
        $slug = function_exists('home_feed_normalize_slug')
            ? home_feed_normalize_slug((string)$rawSlug)
            : strtolower(trim((string)$rawSlug));
        if ($slug === '' || isset($seen[$slug]) || !isset($catalog[$slug])) {
            continue;
        }
        $seen[$slug] = true;
        $chips[] = ['key' => $slug, 'label' => (string)$catalog[$slug]];
    }
    foreach ($catalog as $rawSlug => $label) {
        $slug = function_exists('home_feed_normalize_slug')
            ? home_feed_normalize_slug((string)$rawSlug)
            : strtolower(trim((string)$rawSlug));
        if ($slug === '' || isset($seen[$slug])) {
            continue;
        }
        $seen[$slug] = true;
        $chips[] = ['key' => $slug, 'label' => (string)$label];
    }
    return $chips;
}

function explore_caption_for_post(array $post): string
{
    $title = trim((string)($post['title'] ?? ''));
    $body = trim((string)($post['body'] ?? ''));
    $desc = trim((string)($post['description'] ?? ''));
    $caption = $title !== '' ? $title : ($body !== '' ? $body : $desc);
    $caption = preg_replace('/\s+/u', ' ', $caption) ?? $caption;
    if (preg_match('/^(?:#\w+\s*)+$/', $caption)) {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($caption) > 90) {
        $caption = rtrim(mb_substr($caption, 0, 87)) . '…';
    } elseif (strlen($caption) > 90) {
        $caption = rtrim(substr($caption, 0, 87)) . '…';
    }
    return trim($caption);
}

/** @return list<string> */
function explore_hashtags_for_post(array $post): array
{
    $raw = trim((string)($post['hashtags'] ?? ''));
    if ($raw === '') {
        $raw = trim((string)($post['title'] ?? '') . ' ' . (string)($post['body'] ?? '') . ' ' . (string)($post['description'] ?? ''));
    }
    if (function_exists('post_hashtags_parse')) {
        return array_slice(post_hashtags_parse($raw), 0, 4);
    }
    $out = [];
    if (preg_match_all('/#([A-Za-z][A-Za-z0-9_]{0,48})/', $raw, $m)) {
        foreach ($m[1] as $tag) {
            $key = strtolower((string)$tag);
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = (string)$tag;
            if (count($out) >= 4) {
                break;
            }
        }
    }
    return array_values($out);
}

$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);
if (function_exists('device_profile_ensure_post_columns')) {
    device_profile_ensure_post_columns($dbh);
}
if (function_exists('sendNoCacheHeadersUser')) {
    sendNoCacheHeadersUser();
}
$meId = (int)($_SESSION['user_id'] ?? 0);
if (function_exists('app_i18n_boot')) {
    app_i18n_boot($dbh, $meId);
}
$q = trim((string)($_GET['q'] ?? ''));
$cat = strtolower(trim((string)($_GET['cat'] ?? 'all')));
$exploreChips = explore_category_chips($dbh, $meId);
// Keep the desktop category rail useful across its full width, then place the
// remaining program options in the More menu.
$visibleExploreChips = array_slice($exploreChips, 0, 11);
$moreCats = array_slice($exploreChips, 11);
$moreCatKeys = array_column($moreCats, 'key');
$validCats = array_column($exploreChips, 'key');
if (!in_array($cat, $validCats, true)) {
    $cat = 'all';
}
$openPostId = (int)($_GET['open_post'] ?? $_GET['post'] ?? 0);
if ($openPostId > 0) {
    header('Location: reel.php?post=' . $openPostId . '&from=explore', true, 302);
    exit;
}
$isPublisherWorkspaceViewer = publisher_workspace_viewer($dbh, $meId);

$where = 'COALESCE(p.is_deleted, 0) = 0 AND COALESCE(p.is_archived,0) = 0 AND ' . publisher_discover_list_where_sql($dbh, $meId);
$params = publisher_discover_list_where_params($dbh, $meId);
if ($meId > 0 && function_exists('fs_ensure_blocks_table') && fs_ensure_blocks_table($dbh)) {
    $where .= ' AND ' . fs_block_exclude_author_sql('p.user_id', ':fsBlockMe', ':fsBlockMe2');
    $params[':fsBlockMe'] = $meId;
    $params[':fsBlockMe2'] = $meId;
}
$where .= ' AND ' . publisher_public_surface_scope_sql($dbh, $meId, false);
$params = array_merge($params, publisher_public_surface_scope_params($dbh, $meId, false));
if (publisher_public_stranger_surface($dbh, $meId)) {
    $where .= " AND (
        COALESCE(u.account_kind, 'personal') <> 'publisher'
        OR p.user_id = :pubBrandOwn
        OR " . publisher_public_discoverable_publisher_sql($dbh, 'u') . '
    )';
    $params[':pubBrandOwn'] = $meId;
}
if ($isPublisherWorkspaceViewer) {
    $where .= ' AND ' . publisher_author_is_publisher_sql('u');
} else {
    $where .= ' AND ' . publisher_author_is_personal_sql('u');
}
$where .= " AND EXISTS (
    SELECT 1 FROM public_post_attachments a
    WHERE a.post_id = p.id
      AND LOWER(TRIM(COALESCE(a.type,''))) IN ('image','video','gif')
)";
if ($q !== '') {
    $where .= " AND (
      COALESCE(p.title,'') LIKE :qTitle
      OR COALESCE(p.body,'') LIKE :qBody
      OR COALESCE(p.hashtags,'') LIKE :qHash
      OR COALESCE(u.name,u.username,'') LIKE :qName
      OR COALESCE(u.username,'') LIKE :qUser
    )";
    $qLike = '%' . $q . '%';
    $params[':qTitle'] = $qLike;
    $params[':qBody'] = $qLike;
    $params[':qHash'] = $qLike;
    $params[':qName'] = $qLike;
    $params[':qUser'] = $qLike;
}
if ($cat !== 'all') {
    $where .= " AND (
      COALESCE(p.hashtags,'') LIKE :catHash
      OR COALESCE(p.title,'') LIKE :catTitle
      OR COALESCE(p.body,'') LIKE :catBody
      OR COALESCE(p.description,'') LIKE :catDesc
    )";
    $catLike = '%' . $cat . '%';
    $params[':catHash'] = $catLike;
    $params[':catTitle'] = $catLike;
    $params[':catBody'] = $catLike;
    $params[':catDesc'] = $catLike;
}

$sql = "
SELECT
  p.id, p.user_id,
  COALESCE(p.title,'') AS title,
  COALESCE(p.description,'') AS description,
  COALESCE(p.body,'') AS body,
  COALESCE(p.hashtags,'') AS hashtags,
  COALESCE(u.name, u.username, CONCAT('User ', u.id)) AS display_name,
  COALESCE(u.username,'') AS username
FROM public_posts p
JOIN users u ON u.id = p.user_id
WHERE {$where}
ORDER BY COALESCE(p.updated_at,p.created_at) DESC, p.id DESC
LIMIT 240";
try {
    $st = $dbh->prepare($sql);
    $st->execute($params);
    $posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $eSql) {
    // Older DBs without hashtags column: retry without it.
    $sql = str_replace("COALESCE(p.hashtags,'') AS hashtags,", "'' AS hashtags,", $sql);
    $sql = str_replace('OR COALESCE(p.hashtags,\'\') LIKE :qHash', '', $sql);
    $sql = str_replace('COALESCE(p.hashtags,\'\') LIKE :catHash OR', '', $sql);
    unset($params[':qHash'], $params[':catHash']);
    $st = $dbh->prepare($sql);
    $st->execute($params);
    $posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$kept = [];

foreach ($posts as $post) {
    $pid = (int)($post['id'] ?? 0);
    try {
        $stA = $dbh->prepare('SELECT type, file_path, thumb_path FROM public_post_attachments WHERE post_id = :pid ORDER BY id ASC');
        $stA->execute([':pid' => $pid]);
        $attachments = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $eAtt) {
        $attachments = [];
    }
    $media = [];
    foreach ($attachments as $a) {
        $type = strtolower(trim((string)($a['type'] ?? '')));
        if (!in_array($type, ['image', 'video', 'gif'], true)) {
            continue;
        }
        $rawFile = trim((string)($a['file_path'] ?? ''));
        $rawThumb = trim((string)($a['thumb_path'] ?? ''));
        $usableFile = function_exists('msb_public_media_usable')
            ? msb_public_media_usable($rawFile)
            : $rawFile;
        if ($usableFile === '') {
            continue;
        }
        $usableThumb = '';
        if ($rawThumb !== '') {
            $usableThumb = function_exists('msb_public_media_usable')
                ? msb_public_media_usable($rawThumb)
                : $rawThumb;
        }
        $a['type'] = $type;
        $a['file_path'] = explore_media_src($usableFile);
        $a['thumb_path'] = $usableThumb !== '' ? explore_media_src($usableThumb) : '';
        $media[] = $a;
    }
    if ($media === [] || count($media) > 1) {
        continue;
    }
    $post['attachments'] = $media;
    $post['caption'] = explore_caption_for_post($post);
    $post['tag_list'] = explore_hashtags_for_post($post);
    $kept[] = $post;
    if (count($kept) >= 100) {
        break;
    }
}
$posts = $kept;
$pageTitle = function_exists('app_t') ? app_t('Explore') : 'Explore';
$exploreSubtitle = function_exists('app_t') ? app_t('Discover amazing content, people and communities') : 'Discover amazing content, people and communities';
$exploreSearchPh = function_exists('app_t') ? app_t('Search posts, people, or hashtags...') : 'Search posts, people, or hashtags...';
?>
<!DOCTYPE html>
<html <?= function_exists('app_html_lang_attrs') ? app_html_lang_attrs() : 'lang="en"' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= h($pageTitle) ?></title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <script src="./lib/jquery/jquery.js"></script>
  <style>
    html,body{
      margin:0;height:100%;
      background:var(--msb-palette-bg, #f7f8fa);
      color:var(--msb-palette-text, #0f172a);
    }
    body.explore-page{overflow:hidden;}
    .explore-app{
      position:fixed;
      left:var(--feedRailW, 84px);
      top:0;
      right:0;
      bottom:0;
      display:flex;
      flex-direction:column;
      min-width:0;
      background:var(--msb-palette-bg, #f7f8fa);
    }
    .explore-top{
      flex:0 0 auto;
      padding:18px 20px 8px;
      background:var(--msb-palette-bg, #f7f8fa);
    }
    .explore-search{
      display:flex;
      justify-content:center;
      margin:0 auto 18px;
      max-width:min(920px, 100%);
    }
    .explore-search-form{width:100%;}
    .explore-search-field{position:relative;}
    .explore-search-icon{
      position:absolute;left:14px;top:50%;transform:translateY(-50%);
      width:28px;height:28px;border:0;background:transparent;color:#94a3b8;padding:0;cursor:pointer;
      font-size:15px;
    }
    .explore-search-input{
      width:100%;height:46px;border:0;border-radius:999px;
      background:var(--msb-palette-input-bg, #eef1f5);
      color:var(--msb-palette-text, #0f172a);
      padding:0 18px 0 48px;font-size:15px;outline:none;box-sizing:border-box;
      box-shadow:inset 0 0 0 1px rgba(15,23,42,.04);
    }
    .explore-search-input::placeholder{ color:#94a3b8; }
    .explore-hero{
      max-width:none;
      width:100%;
      margin:0 0 14px;
      padding:0;
    }
    .explore-hero h1{
      margin:0;
      font-size:34px;
      line-height:1.15;
      font-weight:800;
      letter-spacing:-.03em;
      color:var(--msb-palette-text, #0f172a);
    }
    .explore-hero p{
      margin:8px 0 0;
      font-size:15px;
      line-height:1.4;
      color:var(--msb-palette-text-muted, #64748b);
      font-weight:500;
    }
    .explore-filters{
      max-width:none;
      width:100%;
      margin:0;
      padding:2px 32px 6px 0;
      box-sizing:border-box;
      display:flex;
      flex-wrap:nowrap;
      justify-content:space-between;
      gap:clamp(5px, .7vw, 14px);
      align-items:center;
    }
    .explore-chip{
      display:inline-flex;
      align-items:center;
      gap:6px;
      height:36px;
      padding:0 16px;
      border-radius:999px;
      border:0;
      background:var(--msb-palette-surface-2, var(--msb-palette-bg, #eef1f5));
      color:var(--msb-palette-text, #0f172a);
      font-size:13.5px;
      font-weight:700;
      text-decoration:none !important;
      white-space:nowrap;
      flex:0 0 auto;
      cursor:pointer;
      transition:background .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .explore-chip:hover{
      background:var(--msb-palette-hover-bg, #e2e8f0);
      color:var(--msb-palette-text-on-hover, var(--msb-palette-text, #0f172a));
    }
    .explore-chip.is-active{
      background:var(--msb-palette-action, #2563eb);
      color:var(--msb-palette-btn-text, #fff);
      box-shadow:0 8px 18px rgba(37,99,235,.22);
    }
    .explore-chip.is-more i{ font-size:11px; opacity:.8; }
    .explore-more-wrap{ position:relative; }
    .explore-more-menu{
      position:absolute;
      top:calc(100% + 8px);
      right:0;
      left:auto;
      min-width:160px;
      background:var(--msb-palette-surface, var(--msb-palette-bg, #fff));
      border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));
      border-radius:14px;
      box-shadow:0 16px 40px rgba(15,23,42,.14);
      padding:8px;
      max-height:min(60vh, 620px);
      overflow-x:hidden;
      overflow-y:auto;
      overscroll-behavior:contain;
      scrollbar-width:thin;
      scrollbar-color:var(--msb-palette-border, #64748b) transparent;
      z-index:20;
      display:none;
    }
    .explore-more-menu::-webkit-scrollbar{width:5px;}
    .explore-more-menu::-webkit-scrollbar-track{background:transparent;}
    .explore-more-menu::-webkit-scrollbar-thumb{
      background:var(--msb-palette-border, #64748b);
      border-radius:999px;
    }
    .explore-more-menu.is-open{ display:block; }
    .explore-more-menu a{
      display:block;
      padding:10px 12px;
      border-radius:10px;
      color:var(--msb-palette-text, #0f172a);
      text-decoration:none !important;
      font-size:13px;
      font-weight:700;
    }
    .explore-more-menu a:hover{
      background:var(--msb-palette-hover-bg, #f1f5f9);
      color:var(--msb-palette-text-on-hover, var(--msb-palette-text, #0f172a));
    }
    .explore-grid-wrap{
      flex:1 1 auto;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch;
      padding:8px 20px 96px;
    }
    .explore-grid{
      max-width:none;
      width:100%;
      margin:0;
      display:grid;
      grid-template-columns:repeat(4, minmax(0, 1fr));
      gap:16px;
      box-sizing:border-box;
    }
    .explore-tile{
      position:relative;
      display:flex;
      flex-direction:column;
      overflow:hidden;
      background:transparent;
      text-decoration:none !important;
      color:inherit;
      border-radius:0;
      min-width:0;
    }
    .explore-tile-media{
      position:relative;
      width:100%;
      aspect-ratio:4 / 3;
      overflow:hidden;
      border-radius:0;
      background:#dbe3ee;
    }
    .explore-tile img,
    .explore-tile video{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
      pointer-events:none;
      background:#dbe3ee;
    }
    .explore-badge{
      position:absolute;top:10px;right:10px;width:18px;height:18px;color:#fff;
      filter:drop-shadow(0 1px 2px rgba(0,0,0,.45));pointer-events:none;
    }
    .explore-badge svg{width:18px;height:18px;display:block;}
    .explore-tile-meta{
      padding:10px 2px 0;
      display:flex;
      flex-direction:column;
      gap:6px;
      min-width:0;
    }
    .explore-tile-caption{
      margin:0;
      font-size:14px;
      line-height:1.35;
      font-weight:700;
      color:var(--msb-palette-text, #0f172a);
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }
    .explore-tile-tags{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
    }
    .explore-tile-tag{
      display:inline-flex;
      align-items:center;
      min-height:24px;
      padding:3px 10px;
      border-radius:999px;
      background:var(--msb-palette-action-soft, #e8f1ff);
      color:var(--msb-palette-link, var(--msb-palette-action, #3b82f6));
      font-size:12.5px;
      font-weight:700;
      line-height:1.2;
    }
    .explore-empty{
      padding:64px 16px;text-align:center;
      color:var(--msb-palette-muted, #667085);
      font-weight:600;
    }
    @media (max-width:1099px){
      .explore-grid{grid-template-columns:repeat(3, minmax(0, 1fr));gap:14px;}
      .explore-top{padding:14px 14px 6px;}
      .explore-grid-wrap{padding:6px 14px 96px;}
      .explore-hero h1{font-size:28px;}
      .explore-filters{
        justify-content:flex-start;
        padding-right:12px;
        overflow-x:auto;
        overflow-y:visible;
        scrollbar-width:thin;
        -webkit-overflow-scrolling:touch;
      }
    }
    @media (max-width:991.98px){
      .explore-app{left:0;bottom:66px;}
    }
    @media (max-width:700px){
      .explore-grid{grid-template-columns:repeat(2, minmax(0, 1fr));gap:12px;}
      .explore-filters{gap:8px;}
      .explore-chip{height:34px;padding:0 13px;font-size:12.5px;}
    }
  </style>
</head>
<body class="explore-page feed-insta-ui public-page">
<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <div class="explore-app">
    <div class="explore-top">
      <div class="explore-search">
        <form class="explore-search-form" method="get" action="explore.php">
          <?php if ($cat !== 'all'): ?>
            <input type="hidden" name="cat" value="<?= h($cat) ?>">
          <?php endif; ?>
          <div class="explore-search-field">
            <button type="submit" class="explore-search-icon" aria-label="<?= h(function_exists('app_t_attr') ? app_t_attr('Search') : 'Search') ?>">
              <i class="fa fa-search" aria-hidden="true"></i>
            </button>
            <input
              type="search"
              name="q"
              class="explore-search-input"
              value="<?= h($q) ?>"
              placeholder="<?= h($exploreSearchPh) ?>"
              autocomplete="off"
              enterkeyhint="search"
            >
          </div>
        </form>
      </div>
      <div class="explore-hero">
        <h1><?= h($pageTitle) ?></h1>
        <p><?= h($exploreSubtitle) ?></p>
      </div>
      <div class="explore-filters" role="tablist" aria-label="<?= h(function_exists('app_t_attr') ? app_t_attr('Explore categories') : 'Explore categories') ?>">
        <?php
          $shown = 0;
          foreach ($visibleExploreChips as $chip):
            $key = (string)$chip['key'];
            $label = (string)$chip['label'];
            $href = 'explore.php?' . http_build_query(array_filter([
              'cat' => $key === 'all' ? null : $key,
              'q' => $q !== '' ? $q : null,
            ]));
            $isActive = ($cat === $key);
            $shown++;
        ?>
          <a class="explore-chip<?= $isActive ? ' is-active' : '' ?>" href="<?= h($href) ?>" role="tab" aria-selected="<?= $isActive ? 'true' : 'false' ?>"><?= h(function_exists('app_t') ? app_t($label) : $label) ?></a>
        <?php endforeach; ?>
        <?php if ($moreCats): ?>
        <div class="explore-more-wrap">
          <button type="button" class="explore-chip is-more<?= in_array($cat, $moreCatKeys, true) ? ' is-active' : '' ?>" id="exploreMoreBtn" aria-haspopup="true" aria-expanded="false">
            <?= h(function_exists('app_t') ? app_t('More') : 'More') ?>
            <i class="fa fa-chevron-down" aria-hidden="true"></i>
          </button>
          <div class="explore-more-menu" id="exploreMoreMenu" hidden>
            <?php
              foreach ($moreCats as $more):
                $mhref = 'explore.php?' . http_build_query(array_filter([
                  'cat' => $more['key'],
                  'q' => $q !== '' ? $q : null,
                ]));
            ?>
              <a href="<?= h($mhref) ?>"><?= h(function_exists('app_t') ? app_t($more['label']) : $more['label']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="explore-grid-wrap" id="exploreGridWrap">
      <?php if (!$posts): ?>
        <div class="explore-empty" role="status"><?= h(function_exists('app_t') ? app_t('No People Posts Available') : 'No People Posts Available') ?></div>
      <?php else: ?>
        <div class="explore-grid" id="exploreGrid">
          <?php foreach ($posts as $post): ?>
            <?php
              $exploreId = (int)($post['id'] ?? 0);
              $exploreAtt = is_array($post['attachments'] ?? null) ? $post['attachments'] : [];
              $exploreFirst = $exploreAtt[0] ?? null;
              if (!is_array($exploreFirst)) {
                  continue;
              }
              $exploreType = strtolower(trim((string)($exploreFirst['type'] ?? '')));
              $exploreFile = trim((string)($exploreFirst['file_path'] ?? ''));
              $exploreThumb = trim((string)($exploreFirst['thumb_path'] ?? ''));
              $exploreIsVideo = ($exploreType === 'video') || explore_path_is_video($exploreFile);
              $explorePoster = ($exploreThumb !== '' && explore_path_is_image($exploreThumb)) ? $exploreThumb : '';
              $exploreImg = '';
              if (!$exploreIsVideo) {
                  if ($exploreThumb !== '' && explore_path_is_image($exploreThumb)) {
                      $exploreImg = $exploreThumb;
                  } elseif (explore_path_is_image($exploreFile)) {
                      $exploreImg = $exploreFile;
                  }
              }
              if ($exploreIsVideo && $exploreFile === '') {
                  continue;
              }
              if (!$exploreIsVideo && $exploreImg === '') {
                  continue;
              }
              $exploreIsMulti = count($exploreAtt) > 1;
              $exploreAuthor = trim((string)($post['display_name'] ?? '')) !== ''
                ? trim((string)$post['display_name'])
                : trim((string)($post['username'] ?? 'Post'));
              $exploreCaption = trim((string)($post['caption'] ?? ''));
              $exploreTags = is_array($post['tag_list'] ?? null) ? $post['tag_list'] : [];
              $exploreTags = array_slice($exploreTags, 0, 4);
            ?>
            <a
              class="explore-tile"
              href="reel.php?post=<?= $exploreId ?>&from=explore"
              data-post-id="<?= $exploreId ?>"
              aria-label="<?= h($exploreAuthor) ?>"
            >
              <span class="explore-tile-media">
                <?php if ($exploreIsVideo): ?>
                  <video
                    src="<?= h($exploreFile) ?>"
                    <?= $explorePoster !== '' ? 'poster="' . h($explorePoster) . '"' : '' ?>
                    muted
                    loop
                    playsinline
                    autoplay
                    preload="metadata"
                  ></video>
                <?php else: ?>
                  <img src="<?= h($exploreImg) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <?php if ($exploreIsVideo): ?>
                  <span class="explore-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><rect x="3.5" y="6" width="11" height="12" rx="2" stroke="#fff" stroke-width="1.7"/><path d="M14.5 9.2 20 6.8v10.4l-5.5-2.4V9.2z" fill="#fff"/></svg>
                  </span>
                <?php elseif ($exploreIsMulti): ?>
                  <span class="explore-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><rect x="7" y="4" width="13" height="13" rx="2" stroke="#fff" stroke-width="1.7"/><rect x="4" y="7" width="13" height="13" rx="2" fill="#fff"/></svg>
                  </span>
                <?php endif; ?>
              </span>
              <?php if ($exploreCaption !== '' || $exploreTags): ?>
                <span class="explore-tile-meta">
                  <?php if ($exploreCaption !== ''): ?>
                    <span class="explore-tile-caption"><?= h($exploreCaption) ?></span>
                  <?php endif; ?>
                  <?php if ($exploreTags): ?>
                    <span class="explore-tile-tags">
                      <?php foreach ($exploreTags as $tg): ?>
                        <span class="explore-tile-tag">#<?= h($tg) ?></span>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                </span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<script>
(function(){
  var emptyCopy = <?= json_encode(function_exists('app_t') ? app_t('No People Posts Available') : 'No People Posts Available') ?>;
  var RESUME_KEY = 'msbExploreResume';
  var restoreDone = false;

  function scrollWrap(){
    return document.getElementById('exploreGridWrap');
  }
  function currentQ(){
    try{ return String((new URL(window.location.href)).searchParams.get('q') || ''); }catch(e){ return ''; }
  }
  function currentCat(){
    try{ return String((new URL(window.location.href)).searchParams.get('cat') || 'all'); }catch(e){ return 'all'; }
  }
  function saveExplorePlace(postId){
    var wrap = scrollWrap();
    var payload = {
      scrollTop: wrap ? Number(wrap.scrollTop || 0) : 0,
      postId: Number(postId || 0),
      q: currentQ(),
      cat: currentCat(),
      ts: Date.now()
    };
    try{ sessionStorage.setItem(RESUME_KEY, JSON.stringify(payload)); }catch(e){}
  }
  function readExplorePlace(){
    try{
      var raw = sessionStorage.getItem(RESUME_KEY);
      if(!raw) return null;
      var data = JSON.parse(raw);
      if(!data || typeof data !== 'object') return null;
      return data;
    }catch(e){ return null; }
  }
  function restoreExplorePlace(){
    if(restoreDone) return;
    var data = readExplorePlace();
    if(!data) return;
    if(String(data.q || '') !== currentQ()) return;
    if(String(data.cat || 'all') !== currentCat()) return;
    var wrap = scrollWrap();
    if(!wrap) return;
    restoreDone = true;
    var top = Math.max(0, Number(data.scrollTop || 0));
    var postId = Number(data.postId || 0);
    function apply(){
      wrap.scrollTop = top;
      if(postId > 0){
        var tile = document.querySelector('a.explore-tile[data-post-id="'+String(postId)+'"]');
        if(tile){
          var wrapRect = wrap.getBoundingClientRect();
          var tileRect = tile.getBoundingClientRect();
          var above = tileRect.top < wrapRect.top + 8;
          var below = tileRect.bottom > wrapRect.bottom - 8;
          if(above || below){
            var nextTop = wrap.scrollTop + (tileRect.top - wrapRect.top) - Math.max(24, (wrap.clientHeight - tile.clientHeight) / 2);
            wrap.scrollTop = Math.max(0, nextTop);
          }
        }
      }
    }
    apply();
    window.requestAnimationFrame(function(){
      apply();
      window.setTimeout(apply, 50);
    });
  }

  function deletedIdMap(){
    if(typeof window.MSBDeletedPostIdMap === 'function') return window.MSBDeletedPostIdMap() || {};
    var ids = {};
    function merge(storage){
      if(!storage) return;
      try{
        var map = JSON.parse(storage.getItem('msbFeedDeletedPostIds') || '{}') || {};
        Object.keys(map).forEach(function(k){ if(Number(k || 0) > 0) ids[String(k)] = 1; });
      }catch(e){}
    }
    try{ merge(window.sessionStorage); }catch(eS){}
    try{ merge(window.localStorage); }catch(eL){}
    return ids;
  }
  function showExploreEmpty(){
    var wrap = document.getElementById('exploreGridWrap');
    if(wrap) wrap.innerHTML = '<div class="explore-empty" role="status">' + emptyCopy + '</div>';
  }
  function dropBrokenTile(el){
    var tile = el && el.closest ? el.closest('a.explore-tile') : null;
    if(!tile && el && el.classList && el.classList.contains('explore-tile')) tile = el;
    if(!tile) return;
    tile.remove();
    var grid = document.getElementById('exploreGrid');
    if(grid && !grid.querySelector('.explore-tile')) showExploreEmpty();
  }
  function stripPlaceholderTiles(){
    document.querySelectorAll('.explore-grid .msb-no-image, .explore-tile .msb-no-image').forEach(function(ph){
      dropBrokenTile(ph);
    });
  }
  function stripDeletedTiles(){
    var ids = deletedIdMap();
    document.querySelectorAll('a.explore-tile[data-post-id]').forEach(function(tile){
      var id = String(tile.getAttribute('data-post-id') || '');
      if(ids[id]) tile.remove();
    });
    var grid = document.getElementById('exploreGrid');
    if(grid && !grid.querySelector('.explore-tile')) showExploreEmpty();
  }
  stripDeletedTiles();
  stripPlaceholderTiles();
  restoreExplorePlace();

  document.addEventListener('click', function(e){
    var tile = e.target && e.target.closest ? e.target.closest('a.explore-tile') : null;
    if(!tile) return;
    saveExplorePlace(tile.getAttribute('data-post-id'));
  }, true);

  var moreBtn = document.getElementById('exploreMoreBtn');
  var moreMenu = document.getElementById('exploreMoreMenu');
  if (moreBtn && moreMenu) {
    moreBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      var open = moreMenu.classList.toggle('is-open');
      moreMenu.hidden = !open;
      moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function(ev){
      if (moreMenu.contains(ev.target) || moreBtn.contains(ev.target)) return;
      moreMenu.classList.remove('is-open');
      moreMenu.hidden = true;
      moreBtn.setAttribute('aria-expanded', 'false');
    });
  }

  document.querySelectorAll('.explore-tile img, .explore-tile video').forEach(function(el){
    el.addEventListener('error', function(){ dropBrokenTile(el); });
  });
  document.addEventListener('error', function(e){
    var t = e && e.target;
    if(!t || !t.closest) return;
    if(!t.closest('.explore-tile')) return;
    if(t.tagName !== 'IMG' && t.tagName !== 'VIDEO') return;
    dropBrokenTile(t);
  }, true);
  window.addEventListener('pageshow', function(){
    stripDeletedTiles();
    stripPlaceholderTiles();
    restoreDone = false;
    restoreExplorePlace();
  });
})();
</script>
</body>
</html>
