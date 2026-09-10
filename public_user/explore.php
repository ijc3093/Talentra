<?php
declare(strict_types=1);

/**
 * explore.php — Instagram-style media grid.
 * Discover (home.php?tab=discover) stays on public.php cards.
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

$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);
if (function_exists('sendNoCacheHeadersUser')) {
    sendNoCacheHeadersUser();
}
$meId = (int)($_SESSION['user_id'] ?? 0);
if (function_exists('app_i18n_boot')) {
    app_i18n_boot($dbh, $meId);
}
$q = trim((string)($_GET['q'] ?? ''));
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
    $where .= " AND (COALESCE(p.title,'') LIKE :qTitle OR COALESCE(p.body,'') LIKE :qBody OR COALESCE(u.name,u.username,'') LIKE :qName OR COALESCE(u.username,'') LIKE :qUser)";
    $qLike = '%' . $q . '%';
    $params[':qTitle'] = $qLike;
    $params[':qBody'] = $qLike;
    $params[':qName'] = $qLike;
    $params[':qUser'] = $qLike;
}

$sql = "
SELECT
  p.id, p.user_id, COALESCE(p.title,'') AS title, COALESCE(p.description,'') AS description, COALESCE(p.body,'') AS body,
  COALESCE(u.name, u.username, CONCAT('User ', u.id)) AS display_name,
  COALESCE(u.username,'') AS username
FROM public_posts p
JOIN users u ON u.id = p.user_id
WHERE {$where}
ORDER BY COALESCE(p.updated_at,p.created_at) DESC, p.id DESC
LIMIT 240";
$st = $dbh->prepare($sql);
$st->execute($params);
$posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
    $kept[] = $post;
    if (count($kept) >= 100) {
        break;
    }
}
$posts = $kept;

$pageTitle = function_exists('app_t') ? app_t('Explore') : 'Explore';
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
    html,body{margin:0;height:100%;background:var(--msb-palette-bg, #fff);color:var(--msb-palette-text, #111);}
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
      background:var(--msb-palette-bg, #fff);
    }
    .explore-search{
      flex:0 0 auto;
      display:flex;
      justify-content:center;
      padding:12px 16px 10px;
    }
    .explore-search-form{width:min(100%, 460px);}
    .explore-search-field{position:relative;}
    .explore-search-icon{
      position:absolute;left:10px;top:50%;transform:translateY(-50%);
      width:28px;height:28px;border:0;background:transparent;color:#8e8e8e;padding:0;cursor:pointer;
    }
    .explore-search-input{
      width:100%;height:40px;border:0;border-radius:999px;
      background:var(--msb-palette-input-bg, #efefef);
      color:var(--msb-palette-text, #262626);
      padding:0 16px 0 42px;font-size:16px;outline:none;box-sizing:border-box;
    }
    .explore-grid-wrap{
      flex:1 1 auto;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch;
    }
    .explore-grid{
      display:grid;
      grid-template-columns:repeat(4, minmax(0, 1fr));
      gap:3px;
      padding:0 3px 88px;
      box-sizing:border-box;
    }
    .explore-tile{
      position:relative;display:block;aspect-ratio:1/1;overflow:hidden;
      background:#1a1a1a;text-decoration:none !important;color:inherit;
    }
    .explore-tile img,
    .explore-tile video{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
      pointer-events:none;
      background:#111;
    }
    .explore-badge{
      position:absolute;top:8px;right:8px;width:18px;height:18px;color:#fff;
      filter:drop-shadow(0 1px 2px rgba(0,0,0,.45));pointer-events:none;
    }
    .explore-badge svg{width:18px;height:18px;display:block;}
    .explore-empty{padding:48px 16px;text-align:center;color:var(--msb-palette-muted, #667085);}
    @media (max-width:1099px){
      .explore-grid{grid-template-columns:repeat(3, minmax(0, 1fr));gap:2px;padding:0 2px 88px;}
    }
    @media (max-width:991.98px){
      .explore-app{left:0;bottom:66px;}
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
    <div class="explore-search">
      <form class="explore-search-form" method="get" action="explore.php">
        <div class="explore-search-field">
          <button type="submit" class="explore-search-icon" aria-label="<?= h(function_exists('app_t_attr') ? app_t_attr('Search') : 'Search') ?>">
            <i class="fa fa-search" aria-hidden="true"></i>
          </button>
          <input
            type="search"
            name="q"
            class="explore-search-input"
            value="<?= h($q) ?>"
            placeholder="<?= h(function_exists('app_t') ? app_t('Search') : 'Search') ?>"
            autocomplete="off"
            enterkeyhint="search"
          >
        </div>
      </form>
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
            ?>
            <a
              class="explore-tile"
              href="reel.php?post=<?= $exploreId ?>&from=explore"
              data-post-id="<?= $exploreId ?>"
              aria-label="<?= h($exploreAuthor) ?>"
            >
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
  function saveExplorePlace(postId){
    var wrap = scrollWrap();
    var payload = {
      scrollTop: wrap ? Number(wrap.scrollTop || 0) : 0,
      postId: Number(postId || 0),
      q: currentQ(),
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
    // Only restore for the same Explore search context.
    if(String(data.q || '') !== currentQ()) return;
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
