<?php
// /Business_only3/organization/feed.php
declare(strict_types=1);

/**
 * ✅ FIXES IN THIS FILE
 * 1) Feed page loads (fixed broken HTML/PHP that stopped browser from rendering).
 * 2) Replies modal history loads reliably (AJAX JSON not broken by stray output).
 * 3) HY093 Invalid parameter number fixed (no reused named placeholders).
 * 4) Reply button works (ONLY ONE form + unique IDs).
 * 5) Sending reply updates modal + updates reply count on post card.
 * 6) PHP 7.4 safe (no str_starts_with).
 *
 * ✅ UI UPDATES (RIGHT SIDEBAR)
 * - Clean, modern sidebar UI (sticky header + separate scroll list)
 * - Beautiful spacing, chips, NEW badge, pin button
 * - Fixed broken sidebar markup structure
 */

// ✅ AJAX guard BEFORE includes (prevents stray output breaking JSON)
$isAjaxReplies = (isset($_GET['ajax']) && $_GET['ajax'] === 'replies');
$isAjaxInlineComments = (isset($_GET['ajax']) && $_GET['ajax'] === 'inline_comments');
$isAjaxMark    = (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_read');
$isAjaxPost    = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');

if ($isAjaxReplies || $isAjaxInlineComments || $isAjaxPost || $isAjaxMark) {
    error_reporting(0);
    ini_set('display_errors', '0');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    ob_start(); // buffer anything printed by includes
}

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

// ✅ DB connection (org_context.php usually sets $dbh; keep safe fallback)
if (!isset($dbh) || !($dbh instanceof PDO)) {
    require_once __DIR__ . '/../admin/controller.php';
    $controller = new Controller();
    $dbh = $controller->pdo();
}

// ✅ Discard anything echoed by includes for AJAX
if ($isAjaxReplies || $isAjaxInlineComments || $isAjaxPost || $isAjaxMark) {
    @ob_end_clean();
}

// only show PHP errors on normal page load
if (!$isAjaxReplies && !$isAjaxPost && !$isAjaxMark) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function clamp_int($v, int $min, int $max, int $default): int {
    if (!is_numeric($v)) return $default;
    $n = (int)$v;
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

function is_managerish(string $role): bool { return in_array($role, ['admin','manager'], true); }

// ✅ PHP 7.4 compatible starts_with (replaces str_starts_with)
function starts_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

/**
 * Build a PUBLIC HTTPS URL for a stored attachment path.
 * Office Online viewer requires a publicly reachable HTTPS URL (NOT localhost).
 *
 * Priority:
 *  1) If $path is already http(s)://, use it.
 *  2) If $publicFileBaseUrl is set (from org_settings.theme_json), join it with $path.
 *  3) If current request is https and host is not localhost, build from current host.
 */
function build_public_file_url(string $path, string $publicFileBaseUrl = ''): string {
    $raw = trim($path);
    if ($raw === '') return '';
    $rawNoQ = strtok($raw, '?');
    if (starts_with($rawNoQ, 'https://')) return $rawNoQ;
    if (starts_with($rawNoQ, 'http://')) return ''; // Office viewer requires HTTPS

    $rawNoQ = ltrim($rawNoQ, '/');

    if ($publicFileBaseUrl !== '') {
        if (!starts_with($publicFileBaseUrl, 'https://')) return '';
        return rtrim($publicFileBaseUrl, '/') . '/' . $rawNoQ;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host  = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!$https || $host === '') return '';
    $lh = strtolower($host);
    if ($lh === 'localhost' || starts_with($lh, 'localhost:') || starts_with($lh, '127.0.0.1')) return '';

    return 'https://' . $host . '/' . $rawNoQ;
}

function office_embed_url(string $publicHttpsFileUrl): string {
    return 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($publicHttpsFileUrl);
}

function post_label(string $type): string {
    switch ($type) {
        case 'announcement': return 'Announcement';
        case 'direction':    return 'Direction';
        case 'update':       return 'Update';
        case 'weekly_update': return 'Weekly Update';
        case 'recognition':  return 'Recognition';
        default:             return ucfirst($type);
    }
}

// ✅ Helper: normalize subject for display
function post_subject_row(array $p): string {
    $ptype   = (string)($p['post_type'] ?? 'update');
    $title   = trim((string)($p['title'] ?? ''));
    $created = (string)($p['created_at'] ?? '');

    $dt = $created !== '' ? strtotime($created) : time();
    $when = date('M j, Y g:ia', $dt);

    if ($title === '') $title = post_label($ptype);

    // ✅ make it unique + clear
    $id = (int)($p['id'] ?? 0);
    return $title . ' · ' . $when . ($id > 0 ? " · #{$id}" : '');
}

function post_card_title(array $p): string {
    $title = trim((string)($p['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    return post_label((string)($p['post_type'] ?? 'update'));
}

function post_raw_title(array $p): string {
    return trim((string)($p['title'] ?? ''));
}

function post_body_display_text(string $body, int $max = 0): string {
    $body = trim($body);
    if ($body === '') return '';

    $body = preg_replace('/<img[^>]*>/i', '', $body);
    $body = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', '', $body);

    $text = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/\r\n?/", "\n", $text);
    $lines = array_map(static function (string $line): string {
        return trim(preg_replace('/[ \t]+/', ' ', $line));
    }, explode("\n", $text));
    $text = trim(implode("\n", array_filter($lines, static function (string $line): bool {
        return $line !== '';
    })));

    if ($max > 0 && mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max - 1) . '…';
    }
    return $text;
}

function post_has_primary_media(array $primary): bool {
    $type = (string)($primary['type'] ?? 'none');
    $src = trim((string)($primary['src'] ?? ''));
    return $type !== 'none' && $src !== '';
}

function feed_post_card_layout(array $p, array $primary): array {
    $hasMedia = post_has_primary_media($primary);
    $rawTitle = post_raw_title($p);
    $description = post_body_display_text((string)($p['body'] ?? ''));

    return [
        'has_media' => $hasMedia,
        'raw_title' => $rawTitle,
        'description' => $description,
        'text_in_media' => !$hasMedia && ($rawTitle !== '' || $description !== ''),
        'detail_title' => $hasMedia ? post_card_title($p) : '',
    ];
}

function render_feed_description_block(string $description, string $modalTitle, string $modalDate): string {
    $description = trim($description);
    if ($description === '') {
        return '';
    }

    ob_start();
    ?>
    <div class="feed-desc-wrapper">
      <div class="feed-desc clamp-7"><?= h($description) ?></div>
      <button type="button" class="read-more-btn is-hidden" data-description="<?= h($description) ?>" data-title="<?= h($modalTitle) ?>" data-date="<?= h($modalDate) ?>" onclick="openReadMoreModal(this)">
        Read more
      </button>
    </div>
    <?php
    return (string)ob_get_clean();
}

function render_feed_text_media_panel(string $title, string $description): string {
    if ($title === '' && $description === '') {
        return '';
    }

    ob_start();
    ?>
    <div class="feed-media-text">
      <?php if ($title !== ''): ?>
        <div class="feed-media-text-title"><?= nl2br(h($title)) ?></div>
      <?php endif; ?>
      <?php if ($description !== ''): ?>
        <div class="feed-media-text-desc<?= $title === '' ? ' feed-media-text-desc--solo' : '' ?>"><?= nl2br(h($description)) ?></div>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function feed_post_media_classes(array $layout): string {
    $classes = ['feed-post-media'];
    if (!empty($layout['text_in_media'])) {
        $classes[] = 'is-text-only';
    }
    return implode(' ', $classes);
}

function render_feed_head_pin_button(int $pid, string $tab, array $sessionPins): string {
    if ($pid <= 0) {
        return '';
    }

    $pins = array_values(array_unique(array_map('intval', $sessionPins)));
    $isPinned = in_array($pid, $pins, true);
    $action = $isPinned ? 'unpin' : 'pin';

    ob_start();
    ?>
    <a class="btn btn-sm btn-outline-secondary pin-btn<?= $isPinned ? ' pinned' : '' ?>"
       href="feed.php?action=<?= h($action) ?>&pid=<?= (int)$pid ?>&id=<?= (int)$pid ?>&tab=<?= h($tab) ?>"
       title="<?= $isPinned ? 'Unpin' : 'Pin' ?>">
      <i class="fa fa-thumb-tack"></i>
    </a>
    <?php
    return (string)ob_get_clean();
}

function render_sidebar_fries_button(
    int $pid,
    string $tab,
    array $sessionPins,
    int $redirectId = 0,
    string $orgName = 'Organization'
): string {
    if ($pid <= 0) {
        return '';
    }

    $pins = array_values(array_unique(array_map('intval', $sessionPins)));
    $isPinned = in_array($pid, $pins, true);
    $redirectId = $redirectId > 0 ? $redirectId : $pid;
    $postUrl = 'feed.php?id=' . $pid . '&tab=' . rawurlencode($tab);
    $pinUrl = 'feed.php?action=pin&pid=' . $pid . '&id=' . $redirectId . '&tab=' . rawurlencode($tab);
    $unpinUrl = 'feed.php?action=unpin&pid=' . $pid . '&id=' . $redirectId . '&tab=' . rawurlencode($tab);

    ob_start();
    ?>
    <div class="sidebar-tools sidebar-fries-wrap"
         data-pid="<?= (int)$pid ?>"
         data-tab="<?= h($tab) ?>"
         data-pinned="<?= $isPinned ? '1' : '0' ?>"
         data-post-url="<?= h($postUrl) ?>"
         data-pin-url="<?= h($pinUrl) ?>"
         data-unpin-url="<?= h($unpinUrl) ?>"
         data-org-name="<?= h($orgName) ?>">
      <button type="button"
              class="sidebar-fries-btn"
              aria-label="More options"
              title="More options"
              aria-haspopup="true"
              aria-expanded="false">
        <span class="sidebar-fries-icon" aria-hidden="true">
          <span class="sidebar-fries-bar sidebar-fries-bar--short"></span>
          <span class="sidebar-fries-bar"></span>
          <span class="sidebar-fries-bar sidebar-fries-bar--short"></span>
        </span>
      </button>
      <div class="sidebar-fries-menu" role="menu" hidden>
        <button type="button" class="sidebar-fries-item is-danger" data-action="report" role="menuitem">Report</button>
        <button type="button" class="sidebar-fries-item is-danger" data-action="unpin" role="menuitem"<?= $isPinned ? '' : ' disabled' ?>>Unfollow</button>
        <button type="button" class="sidebar-fries-item" data-action="about" role="menuitem">About this account</button>
        <button type="button" class="sidebar-fries-item" data-action="goto" role="menuitem">Go to post</button>
        <button type="button" class="sidebar-fries-item" data-action="share" role="menuitem">Share to...</button>
        <button type="button" class="sidebar-fries-item" data-action="copy" role="menuitem">Copy link</button>
        <button type="button" class="sidebar-fries-item" data-action="embed" role="menuitem">Embed</button>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

/**
 * ✅ Extract first image URL from a post body (supports HTML <img> or Markdown ![]()).
 * Returns empty string if not found.
 */
function extract_first_image_src(string $body): string {
    $body = trim($body);
    if ($body === '') return '';

    // HTML <img ... src="...">
    if (preg_match('/<img[^>]+src\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $body, $m)) {
        return (string)$m[1];
    }

    // Markdown image: ![alt](url)
    if (preg_match('/!\[[^\]]*\]\(([^\)\s]+)(?:\s+"[^"]*")?\)/', $body, $m2)) {
        return (string)$m2[1];
    }

    return '';
}

/**
 * ✅ Extract a readable description from body (strip HTML, keep plain text).
 */
function extract_description(string $body, int $max = 850): string {
    $body = trim($body);
    if ($body === '') return '';

    // Remove images first
    $body = preg_replace('/<img[^>]*>/i', ' ', $body);
    $body = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', ' ', $body);

    // Strip tags + decode entities
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    if ($max > 0 && mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max - 1) . '…';
    }
    return $text;
}


/**
 * ✅ Fetch attachments for a post (org_post_attachments).
 * Returns list rows: file_path, original_name, mime_type/mime, ext, file_size.
 */
function fetch_post_attachments(PDO $dbh, int $orgId, int $postId): array {
    try {
        $st = $dbh->prepare("
            SELECT
              id,
              file_path,
              COALESCE(original_name, file_name) AS original_name,
              COALESCE(mime_type, mime, '') AS mime_type,
              COALESCE(ext, '') AS ext,
              COALESCE(file_size, 0) AS file_size
            FROM org_post_attachments
            WHERE org_id = :org AND post_id = :pid
            ORDER BY id ASC
        ");
        $st->execute([':org'=>$orgId, ':pid'=>$postId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function attachment_kind(string $mime, string $ext): string {
    $e = strtolower(ltrim($ext, '.'));
    $m = strtolower($mime);

    // Images (including GIF)
    if (strpos($m, 'image/') === 0 || in_array($e, ['jpg','jpeg','png','gif','webp','bmp','svg'], true)) return 'image';

    // Video
    if (strpos($m, 'video/') === 0 || in_array($e, ['mp4','webm','ogg','mov','m4v'], true)) return 'video';

    // PDF
    if ($m === 'application/pdf' || $e === 'pdf') return 'pdf';

    // Office / docs
    if (in_array($e, ['ppt','pptx'], true) || strpos($m, 'presentation') !== false) return 'ppt';
    if (in_array($e, ['doc','docx'], true) || strpos($m, 'word') !== false) return 'doc';
    if (in_array($e, ['xls','xlsx','csv'], true) || strpos($m, 'spreadsheet') !== false || strpos($m, 'excel') !== false) return 'sheet';

    return 'file';
}

function normalize_media_url(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    // absolute URL or absolute path
    if (preg_match('~^(https?:)?//~i', $path) || $path[0] === '/') return $path;
    // relative to /organization/ is fine (uploads/feed/...)
    return $path;
}

function find_local_ppt_pdf_webpath(string $pptWebPath): string {
    $u = $pptWebPath;
    $qPos = strpos($u, '?');
    if ($qPos !== false) {
        $u = substr($u, 0, $qPos);
    }
    $hPos = strpos($u, '#');
    if ($hPos !== false) {
        $u = substr($u, 0, $hPos);
    }

    if (!preg_match('/\.(pptx|ppt)$/i', $u)) {
        return '';
    }

    $pdfWeb = preg_replace('/\.(pptx|ppt)$/i', '.pdf', $u);
    $rel = $pdfWeb;

    if (strpos($rel, '/organization/') !== false) {
        $rel = substr($rel, strpos($rel, '/organization/') + strlen('/organization/'));
    }
    $rel = ltrim($rel, '/');

    $fs = __DIR__ . '/' . $rel;
    return is_file($fs) ? $pdfWeb : '';
}

/**
 * ✅ If a PPTX/DOCX/XLSX has been converted to PDF on your server (common for local preview),
 * try to find that PDF and return a WEB path we can iframe.
 *
 * Supported patterns:
 * - file.pptx.pdf   (your old naming)
 * - file.pptx + ".pdf"  => file.pptx.pdf
 * - file.pptx -> file.pdf
 */
function find_local_converted_pdf_webpath(string $src): string {
    $src = trim($src);
    if ($src === '') return '';

    // candidate web paths
    $candidates = [];
    $candidates[] = $src . '.pdf'; // file.pptx.pdf style
    $candidates[] = preg_replace('~\.(pptx|ppt|docx|doc|xlsx|xls)$~i', '.pdf', $src);
    // also try stripping double extensions like .pptx.pdf already
    $candidates[] = preg_replace('~\.(pptx|ppt|docx|doc|xlsx|xls)\.pdf$~i', '.pdf', $src);

    // de-dup
    $uniq = [];
    foreach ($candidates as $c) {
        if (!is_string($c) || $c === '') continue;
        $uniq[$c] = true;
    }

    // try resolve to filesystem and check existence
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    foreach (array_keys($uniq) as $webPath) {
        $webPathTrim = $webPath;
        $fsTry = [];

        if ($webPathTrim !== '' && $webPathTrim[0] === '/' && $docRoot !== '') {
            $fsTry[] = rtrim($docRoot, '/') . $webPathTrim;
        }

        // relative tries (uploads/...) can be under /organization, project root, or parent dirs.
        $fsTry[] = __DIR__ . '/' . ltrim($webPathTrim, '/');
        $fsTry[] = __DIR__ . '/../' . ltrim($webPathTrim, '/');
        $fsTry[] = __DIR__ . '/../../' . ltrim($webPathTrim, '/');

        foreach ($fsTry as $fs) {
            if (is_string($fs) && $fs !== '' && file_exists($fs)) {
                return $webPathTrim;
            }
        }
    }

    return '';
}

/**
 * ✅ Decide what to show in the big left media area.
 * Priority: attachment image -> attachment video -> extracted <img> in body -> pdf/ppt/file fallback.
 */
function pick_primary_media(array $attachments, string $bodyFallbackImg = ''): array {
    // 1) first image attachment
    foreach ($attachments as $a) {
        $kind = attachment_kind((string)($a['mime_type'] ?? ''), (string)($a['ext'] ?? ''));
        if ($kind === 'image') {
            return [
                'type' => 'image',
                'src'  => normalize_media_url((string)($a['file_path'] ?? '')),
                'name' => (string)($a['original_name'] ?? 'image'),
                'mime' => (string)($a['mime_type'] ?? ''),
            ];
        }
    }
    // 2) first video attachment
    foreach ($attachments as $a) {
        $kind = attachment_kind((string)($a['mime_type'] ?? ''), (string)($a['ext'] ?? ''));
        if ($kind === 'video') {
            return [
                'type' => 'video',
                'src'  => normalize_media_url((string)($a['file_path'] ?? '')),
                'name' => (string)($a['original_name'] ?? 'video'),
                'mime' => (string)($a['mime_type'] ?? 'video/mp4'),
            ];
        }
    }
    // 3) first inline img in body
    $bodyFallbackImg = trim($bodyFallbackImg);
    if ($bodyFallbackImg !== '') {
        return [
            'type' => 'image',
            'src'  => normalize_media_url($bodyFallbackImg),
            'name' => 'image',
            'mime' => 'image/*',
        ];
    }
    // 4) first other attachment (pdf/ppt/file)
    if ($attachments) {
        $a = $attachments[0];
        $kind = attachment_kind((string)($a['mime_type'] ?? ''), (string)($a['ext'] ?? ''));
        return [
            'type' => $kind,
            'src'  => normalize_media_url((string)($a['file_path'] ?? '')),
            'name' => (string)($a['original_name'] ?? 'file'),
            'mime' => (string)($a['mime_type'] ?? ''),
        ];
    }
    return ['type'=>'none','src'=>'','name'=>'','mime'=>''];
}



/**
 * ✅ Build a media gallery list (supports multiple attachments).
 * Each item: type(image|video|pdf|ppt|file), src, name, mime
 * Includes the first inline image from body as a fallback item (if any).
 */
function build_media_gallery(array $attachments, string $bodyFallbackImg = ''): array {
    $items = [];

    // Attachments first (preserve order)
    foreach ($attachments as $a) {
        $kind = attachment_kind((string)($a['mime_type'] ?? ''), (string)($a['ext'] ?? ''));
        $src  = normalize_media_url((string)($a['file_path'] ?? ''));
        if ($src === '') continue;

        $items[] = [
            'type' => $kind,
            'src'  => $src,
            'name' => (string)($a['original_name'] ?? 'attachment'),
            'mime' => (string)($a['mime_type'] ?? ''),
        ];
    }

    // Body inline image (only if not already present)
    $bodyFallbackImg = trim($bodyFallbackImg);
    if ($bodyFallbackImg !== '') {
        $dup = false;
        foreach ($items as $it) {
            if (($it['type'] ?? '') === 'image' && (string)($it['src'] ?? '') === $bodyFallbackImg) { $dup = true; break; }
        }
        if (!$dup) {
            $items[] = [
                'type' => 'image',
                'src'  => $bodyFallbackImg,
                'name' => 'inline-image',
                'mime' => 'image/*',
            ];
        }
    }

    // Ensure at least one placeholder
    if (!$items) $items[] = ['type'=>'none','src'=>'','name'=>'','mime'=>''];

    return $items;
}




// -------------------- Org --------------------
$orgId = (int)($ORG['id'] ?? 0);
if ($orgId <= 0 && function_exists('orgActiveOrgId')) $orgId = (int)orgActiveOrgId();
if ($orgId <= 0) die('Invalid organization context.');

// -------------------- Resolve session account -> org_members row --------------------
$accountType = function_exists('orgAccountType') ? (string)orgAccountType() : '';
$accountId   = function_exists('orgAccountId')   ? (int)orgAccountId()     : 0;

if ($accountId <= 0) $accountId = (int)($_SESSION['org_account_id'] ?? 0);
if ($accountType !== 'manager' && $accountType !== 'staff') {
    if (function_exists('isOrgManager') && isOrgManager()) $accountType = 'manager';
    else $accountType = 'staff';
}
if ($accountId <= 0) die('Invalid org session.');

// -------------------- Resolve session membership (trusted) --------------------
$meMemberId = function_exists('orgMemberId') ? (int)orgMemberId() : 0;
$myRoleId   = function_exists('orgRoleId')   ? (int)orgRoleId()   : 0;
$myJoinedAt = '';

if ($meMemberId <= 0) {
    $st = $dbh->prepare("
        SELECT id, role_id, joined_at
        FROM org_members
        WHERE org_id = :org
          AND member_type = :mt
          AND member_id = :mid
        LIMIT 1
    ");
    $st->execute([':org'=>$orgId, ':mt'=>$accountType, ':mid'=>$accountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $meMemberId = (int)($row['id'] ?? 0);
    $myRoleId   = (int)($row['role_id'] ?? 0);
    $myJoinedAt = (string)($row['joined_at'] ?? '');
}

if ($meMemberId <= 0) {
    http_response_code(403);
    echo 'Not a member of this organization. <a href="select_org.php">Select Organization</a>';
    exit;
}

// Resolve role name via org_roles
$meRole = ($accountType === 'manager') ? 'manager' : 'staff';
$canManagePosts = ($accountType === 'manager'); // ✅ only manager can edit/delete posts

try {
    if ($myRoleId > 0) {
        $stR = $dbh->prepare("SELECT name FROM org_roles WHERE id = :id AND org_id = :org LIMIT 1");
        $stR->execute([':id'=>$myRoleId, ':org'=>$orgId]);
        $roleName = (string)($stR->fetchColumn() ?: '');
        $rl = strtolower($roleName);
        if ($rl === 'manager') $meRole = 'manager';
        elseif ($rl === 'staff') $meRole = 'staff';
        elseif ($rl === 'admin') $meRole = 'admin';
    }
} catch (Throwable $e) { /* keep fallback */ }

// -------------------- Self-heal organization_users --------------------
try {
    $stChk = $dbh->prepare("SELECT role FROM organization_users WHERE org_id=:o AND user_id=:u LIMIT 1");
    $stChk->execute([':o'=>$orgId, ':u'=>$meMemberId]);
    $have = (string)($stChk->fetchColumn() ?: '');
    if ($have === '') {
        $ins = $dbh->prepare("
            INSERT INTO organization_users (org_id, user_id, role, joined_at)
            VALUES (:o, :u, :r, NOW())
            ON DUPLICATE KEY UPDATE role = VALUES(role)
        ");
        $ins->execute([':o'=>$orgId, ':u'=>$meMemberId, ':r'=>$meRole]);
    } else {
        $meRole = $have;
    }
} catch (Throwable $e) { /* ignore */ }

// -------------------- Resolve my fullname --------------------
$myFullname = 'Member';
try {
    $stN = $dbh->prepare("
        SELECT COALESCE(m.fullname, s.fullname, 'Member') AS fullname
        FROM org_members om
        LEFT JOIN managers m
          ON om.member_type = 'manager' AND m.id = om.member_id
        LEFT JOIN staff_accounts s
          ON om.member_type = 'staff' AND s.id = om.member_id
        WHERE om.org_id = :org AND om.id = :omid
        LIMIT 1
    ");
    $stN->execute([':org'=>$orgId, ':omid'=>$meMemberId]);
    $myFullname = (string)($stN->fetchColumn() ?: 'Member');
} catch (Throwable $e) { /* keep fallback */ }

// -------------------- CSRF --------------------
if (empty($_SESSION['csrf_org_dash'])) {
    $_SESSION['csrf_org_dash'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_org_dash'];

function csrf_ok(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf_org_dash'])
        && is_string($_POST['csrf'])
        && hash_equals((string)$_SESSION['csrf_org_dash'], (string)$_POST['csrf']);
}

// -------------------- Detect reply support --------------------
$hasReplyColumn = false;
try {
    $stCol = $dbh->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'org_post_comments'
          AND COLUMN_NAME = 'parent_comment_id'
    ");
    $stCol->execute();
    $hasReplyColumn = ((int)($stCol->fetchColumn() ?: 0) > 0);
} catch (Throwable $e) { $hasReplyColumn = false; }

// -------------------- Render comment helper --------------------
function split_comment_sentences(string $text): array {
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $text = preg_replace("/\r\n?/", ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $parts = preg_split('/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static function (string $part): bool {
        return $part !== '';
    }));

    return $parts;
}

function comment_body_is_long(string $body, int $sentenceLimit = 3): bool {
    return count(split_comment_sentences($body)) > $sentenceLimit;
}

function comment_body_preview(string $body, int $sentenceLimit = 3): string {
    $sentences = split_comment_sentences($body);
    if (count($sentences) <= $sentenceLimit) {
        return $body;
    }
    return implode(' ', array_slice($sentences, 0, $sentenceLimit));
}

function render_comment_item(array $c, bool $isReply = false, int $idx = 0, bool $truncateSentences = false): string {
    $cid  = (int)($c['id'] ?? 0);
    $name = (string)($c['user_name'] ?? ('User #' . (int)($c['user_id'] ?? 0)));
    $role = (string)($c['role'] ?? 'member');
    $dt   = (string)($c['created_at'] ?? '');
    $body = (string)($c['body'] ?? '');

    $indent = $isReply ? 'margin-left:18px;border-left:3px solid #e5e7eb;padding-left:12px;' : '';

    $bodyHtml = '';
    if ($truncateSentences && comment_body_is_long($body)) {
        $preview = comment_body_preview($body, 3);
        $modalTitle = $name . ' (' . $role . ')';
        $bodyHtml = '
        <div class="comment-body">
          <div class="comment-body-preview">'.h($preview).'…</div>
          <button type="button" class="read-more-btn comment-read-more-btn"
            data-description="'.h($body).'"
            data-title="'.h($modalTitle).'"
            data-date="'.h($dt).'"
            onclick="openReadMoreModal(this)">Read more</button>
        </div>';
    } else {
        $bodyHtml = '<div class="comment-body">'.h($body).'</div>';
    }

    return '
      <div class="comment-item" data-idx="'.$idx.'" style="'.$indent.'">
        <div class="comment-meta">
          <div>
            '.h($name).' ('.h($role).') · '.h($dt).'
          </div>
          <button type="button" class="btn btn-sm btn-link replyBtn" data-cid="'.$cid.'" data-cname="'.h($name).'" style="padding:0;">
            Reply
          </button>
        </div>
        '.$bodyHtml.'
      </div>
    ';
}

function fetch_post_comments_for_feed(PDO $dbh, int $orgId, int $postId, bool $hasReplyColumn): array {
    try {
        $extraSelect = $hasReplyColumn ? ', c.parent_comment_id' : '';
        $stc = $dbh->prepare("
            SELECT
              c.id, c.user_id, c.body, c.created_at
              $extraSelect,
              COALESCE(ou.role,'member') AS role,
              COALESCE(
                m.fullname,
                s.fullname,
                CONCAT('Member #', om.member_id)
              ) AS user_name
            FROM org_post_comments c
            LEFT JOIN organization_users ou
              ON ou.org_id = :org1 AND ou.user_id = c.user_id
            LEFT JOIN org_members om
              ON om.org_id = :org2 AND om.id = c.user_id
            LEFT JOIN managers m
              ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s
              ON om.member_type = 'staff' AND s.id = om.member_id
            WHERE c.post_id = :pid
            ORDER BY c.created_at ASC
            LIMIT 500
        ");
        $stc->execute([
            ':org1' => $orgId,
            ':org2' => $orgId,
            ':pid'  => $postId,
        ]);
        return $stc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function render_feed_inline_comments_html(PDO $dbh, int $orgId, int $postId, bool $hasReplyColumn): string {
    $comments = fetch_post_comments_for_feed($dbh, $orgId, $postId, $hasReplyColumn);
    $thread = [];
    $children = [];
    foreach ($comments as $c) {
        if ($hasReplyColumn) {
            $parent = (int)($c['parent_comment_id'] ?? 0);
            if ($parent > 0) {
                $children[$parent][] = $c;
            } else {
                $thread[] = $c;
            }
        } else {
            $thread[] = $c;
        }
    }

    ob_start();
    if (!$comments) {
        echo '<div class="mini-muted">No comments yet.</div>';
    } else {
        $i = 0;
        foreach ($thread as $c) {
            echo render_comment_item($c, false, $i++, true);
            if ($hasReplyColumn) {
                $cid = (int)($c['id'] ?? 0);
                foreach ($children[$cid] ?? [] as $r) {
                    echo render_comment_item($r, true, $i++, true);
                }
            }
        }
    }
    return (string)ob_get_clean();
}

function render_feed_compose_form(int $pid, string $tab, string $csrf, bool $locked, string $formAction, string $backTo): string {
    $disabled = $locked ? ' disabled' : '';
    $placeholder = $locked ? 'Comments are closed' : 'Write a comment…';

    return '
      <div class="feed-compose">
        <form class="feed-compose-form jsFeedComposeForm" method="post" action="'.h($formAction).'" data-pid="'.(int)$pid.'">
          <input type="hidden" name="csrf" value="'.h($csrf).'">
          <input type="hidden" name="action" value="comment">
          <input type="hidden" name="post_id" value="'.(int)$pid.'">
          <input type="hidden" name="reply_to" value="0">
          <input type="hidden" name="back_to" value="'.h($backTo).'">
          <textarea
            name="comment_body"
            class="feed-compose-input"
            rows="1"
            maxlength="500"
            placeholder="'.h($placeholder).'"
            aria-label="Write a comment"'.$disabled.'
          ></textarea>
          <button type="submit" class="feed-compose-send" title="Send" aria-label="Send comment"'.$disabled.'>
            <i class="fa fa-paper-plane"></i>
          </button>
        </form>
      </div>
    ';
}

// -------------------- AJAX: replies modal loader --------------------
if ($isAjaxMark) {
    header('Content-Type: application/json; charset=utf-8');

    $pid = (int)($_GET['pid'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['ok'=>false,'err'=>'Invalid post id']);
        exit;
    }

    // ✅ Make sure we have session keys available (org/tab/member)
    $orgId      = (int)orgActiveOrgId();
    $meMemberId = (int)orgMemberId();
    $tab        = (string)($_GET['tab'] ?? 'all');
    if ($tab !== 'all' && $tab !== 'culture' && $tab !== 'work') $tab = 'work';

    $pinKey  = 'feed_pins_' . $orgId . '_' . $tab . '_' . $meMemberId;
    $seenKey = 'feed_seen_' . $orgId . '_' . $tab . '_' . $meMemberId;
    $readAtKey = 'feed_last_read_at_' . $orgId . '_' . $tab . '_' . $meMemberId;

    if (!isset($_SESSION[$pinKey]) || !is_array($_SESSION[$pinKey]))  { $_SESSION[$pinKey]  = []; }
    if (!isset($_SESSION[$seenKey]) || !is_array($_SESSION[$seenKey])) { $_SESSION[$seenKey] = []; }
    if (!isset($_SESSION[$readAtKey])) { $_SESSION[$readAtKey] = 0; }

    // ✅ Fetch latest counts + created_at to update "last read" timestamp safely
    try {
        $st = $dbh->prepare("
            SELECT p.created_at,
                   (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count
            FROM org_posts p
            WHERE p.org_id = :org AND p.id = :pid
            LIMIT 1
        ");
        $st->execute([':org'=>$orgId, ':pid'=>$pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $cc  = (int)($row['comment_count'] ?? 0);
        $_SESSION[$seenKey][$pid] = $cc;

        $dt = (string)($row['created_at'] ?? '');
        $ts = $dt !== '' ? (int)strtotime($dt) : 0;
        if ($ts > (int)($_SESSION[$readAtKey] ?? 0)) {
            $_SESSION[$readAtKey] = $ts;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'err'=>'DB error']);
        exit;
    }

    // return updated unread count
    $unread = 0;
    try {
        $tsReal = (int)($_SESSION[$readAtKey] ?? 0);
        $tsEff  = ($tsReal > 0) ? $tsReal : (time() - ($firstVisitNewWindowDays * 86400));
        $stU = $dbh->prepare("SELECT COUNT(*) FROM org_posts p WHERE p.org_id = :org AND p.created_at > FROM_UNIXTIME(:ts)");
        $stU->execute([':org'=>$orgId, ':ts'=>$tsEff]);
        $unread = (int)$stU->fetchColumn();
    } catch (Throwable $e) { $unread = 0; }

echo json_encode(['ok'=>true,'pid'=>$pid,'unread'=>$unread]);
    exit;
}

if ($isAjaxReplies) {
    header('Content-Type: application/json; charset=utf-8');

    $pid = (int)($_GET['pid'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['ok'=>false,'err'=>'Invalid post.']);
        exit;
    }

    try {
        // post summary
        $st = $dbh->prepare("
            SELECT id, post_type, title, created_at, comments_locked
            FROM org_posts
            WHERE id = :pid AND org_id = :org
            LIMIT 1
        ");
        $st->execute([':pid'=>$pid, ':org'=>$orgId]);
        $post = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$post) {
            echo json_encode(['ok'=>false,'err'=>'Post not found.']);
            exit;
        }

        $locked = ((int)($post['comments_locked'] ?? 0) === 1);

        // comments (✅ FIX HY093: do NOT reuse same placeholder multiple times)
        $extraSelect = $hasReplyColumn ? ", c.parent_comment_id" : "";
        $stc = $dbh->prepare("
            SELECT
              c.id, c.user_id, c.body, c.created_at
              $extraSelect,
              COALESCE(ou.role,'member') AS role,
              COALESCE(
                m.fullname,
                s.fullname,
                CONCAT('Member #', om.member_id)
              ) AS user_name
            FROM org_post_comments c
            LEFT JOIN organization_users ou
              ON ou.org_id = :org1 AND ou.user_id = c.user_id
            LEFT JOIN org_members om
              ON om.org_id = :org2 AND om.id = c.user_id
            LEFT JOIN managers m
              ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s
              ON om.member_type = 'staff' AND s.id = om.member_id
            WHERE c.post_id = :pid
            ORDER BY c.created_at ASC
              LIMIT 500
        ");
        $stc->execute([
            ':org1' => $orgId,
            ':org2' => $orgId,
            ':pid'  => $pid
        ]);
        $comments = $stc->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // thread build
        $thread = [];
        $children = [];
        foreach ($comments as $c) {
            if ($hasReplyColumn) {
                $parent = (int)($c['parent_comment_id'] ?? 0);
                if ($parent > 0) $children[$parent][] = $c;
                else $thread[] = $c;
            } else {
                $thread[] = $c;
            }
        }

        // count
        $stCnt = $dbh->prepare("SELECT COUNT(*) FROM org_post_comments WHERE post_id = :pid");
        $stCnt->execute([':pid'=>$pid]);
        $count = (int)($stCnt->fetchColumn() ?: 0);

        // meta
        $meta = post_label((string)($post['post_type'] ?? 'update')) . ' · ' . (string)($post['created_at'] ?? '');

        // render modal HTML body
        ob_start();
        ?>
        <div class="rm-collapser">
          <div class="rm-collapser-left">
            <span class="rm-hint"><i class="fa fa-info-circle"></i> Comments are for clarification and alignment.</span>
          </div>
        </div>

        <div id="rmCommentsWrap">

        <?php if (!$comments): ?>
          <div class="mini-muted">No replies yet.</div>
        <?php else: ?>
          <?php $i = 0; ?>
          <?php foreach ($thread as $c): ?>
            <?= render_comment_item($c, false, $i++) ?>
            <?php if ($hasReplyColumn): ?>
              <?php $cid = (int)($c['id'] ?? 0); $kids = $children[$cid] ?? []; ?>
              <?php foreach ($kids as $r): ?>
                <?= render_comment_item($r, true, $i++) ?>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>

        </div>

        <?php if (!$locked): ?>
          <div class="reply-box">
            <div class="mini-muted" style="margin-bottom:6px;">
              <strong id="replyTargetLabel">Reply to post</strong>
            </div>

            <!-- ✅ ONE FORM ONLY (no duplicate IDs) -->
            <form method="post" id="rmReplyForm">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="comment">
              <input type="hidden" name="post_id" value="<?= (int)$pid ?>">
              <input type="hidden" name="reply_to" id="reply_to" value="0">

              <div class="form-group" style="margin-bottom:8px;">
                <textarea
                  name="comment_body"
                  id="rm_comment_body"
                  maxlength="500"
                  class="form-control"
                  rows="3"
                  placeholder="Write your reply…"
                  required></textarea>
              </div>

              <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <button class="btn btn-sm btn-primary" type="submit">
                  <i class="fa fa-paper-plane mg-r-5"></i> Send
                </button>
                <button class="btn btn-sm btn-outline-secondary" type="button" id="clearReplyTarget">
                  Clear target
                </button>
                <?php if (!$hasReplyColumn): ?>
                  <span class="mini-muted">(DB missing <code>parent_comment_id</code>, reply stored as text.)</span>
                <?php endif; ?>
              </div>
            </form>
          </div>
        <?php else: ?>
          <div class="mini-muted org-comments-door-locked" style="margin-top:0;">Comments are closed for this update.</div>
        <?php endif; ?>
        <?php
        $html = (string)ob_get_clean();

        echo json_encode([
            'ok'    => true,
            'pid'   => $pid,
            'meta'  => $meta,
            'html'  => $html,
            'count' => $count,
            'locked'=> $locked ? 1 : 0
        ]);
        exit;

    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'err'=>'DB error: '.$e->getMessage()]);
        exit;
    }
}


if ($isAjaxInlineComments) {
    header('Content-Type: application/json; charset=utf-8');

    $pid = (int)($_GET['pid'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['ok'=>false,'err'=>'Invalid post.']);
        exit;
    }

    try {
        $st = $dbh->prepare('SELECT id FROM org_posts WHERE id = :pid AND org_id = :org LIMIT 1');
        $st->execute([':pid'=>$pid, ':org'=>$orgId]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(['ok'=>false,'err'=>'Post not found.']);
            exit;
        }

        $html = render_feed_inline_comments_html($dbh, $orgId, $pid, $hasReplyColumn);
        $stCnt = $dbh->prepare('SELECT COUNT(*) FROM org_post_comments WHERE post_id = :pid');
        $stCnt->execute([':pid'=>$pid]);
        $count = (int)($stCnt->fetchColumn() ?: 0);

        echo json_encode(['ok'=>true,'pid'=>$pid,'html'=>$html,'count'=>$count]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'err'=>'DB error: '.$e->getMessage()]);
        exit;
    }
}


// -------------------- Route: AJAX mark read (after media loads) --------------------
// ✅ Accept either variable name (prevents "not work yet" if you renamed it elsewhere)
$isAjaxMarkRead = ($isAjaxMarkRead ?? false) || ($isAjaxMark ?? false);

if ($isAjaxMarkRead) {
    // Prevent PHP notices/warnings from breaking JSON
    error_reporting(0);
    ini_set('display_errors', '0');

    header('Content-Type: application/json; charset=utf-8');

    try {
        $pid = (int)($_GET['pid'] ?? 0);
        $tabReq = (string)($_GET['tab'] ?? 'work');
        if (!in_array($tabReq, ['work','culture','all'], true)) {
            $tabReq = 'work';
        }
        if ($pid <= 0) {
            throw new RuntimeException('Invalid post.');
        }

        // Ensure table exists (best-effort)
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS org_feed_reads (
                  org_id BIGINT NOT NULL,
                  member_id BIGINT NOT NULL,
                  tab VARCHAR(20) NOT NULL,
                  last_read_at DATETIME NOT NULL,
                  PRIMARY KEY (org_id, member_id, tab),
                  KEY idx_member (member_id),
                  KEY idx_org (org_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // ignore create failure; continue (update may fail)
        }

        // Fetch post created_at
        $stP = $dbh->prepare("
            SELECT created_at
            FROM org_posts
            WHERE id = :pid AND org_id = :org
            LIMIT 1
        ");
        $stP->execute([':pid' => $pid, ':org' => $orgId]);
        $createdAt = (string)($stP->fetchColumn() ?: '');
        if ($createdAt === '') {
            throw new RuntimeException('Post not found.');
        }

        // Mark read up to this post timestamp (monotonic)
        $stU = $dbh->prepare("
            INSERT INTO org_feed_reads (org_id, member_id, tab, last_read_at)
            VALUES (:org, :mid, :tab, :dt)
            ON DUPLICATE KEY UPDATE last_read_at = GREATEST(last_read_at, VALUES(last_read_at))
        ");
        $stU->execute([
            ':org' => $orgId,
            ':mid' => $meMemberId,
            ':tab' => $tabReq,
            ':dt'  => $createdAt
        ]);

        // Compute unread count after this timestamp
        $wt = '';
        if ($tabReq === 'culture') {
            $wt = " AND p.post_type = 'recognition' ";
        } elseif ($tabReq === 'work') {
            $wt = " AND p.post_type IN ('announcement','direction','update') ";
        } // 'all' => no filter

        $stC = $dbh->prepare("
            SELECT COUNT(*)
            FROM org_posts p
            WHERE p.org_id = :org
              $wt
              AND p.created_at > :since
        ");
        $stC->execute([':org' => $orgId, ':since' => $createdAt]);
        $u = (int)($stC->fetchColumn() ?: 0);

        echo json_encode(['ok' => true, 'pid' => $pid, 'unread' => $u], JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        exit;
    }
}


// -------------------- Flash (PRG) --------------------
$flashOk  = (string)($_SESSION['feed_flash_ok'] ?? '');
$flashErr = (string)($_SESSION['feed_flash_err'] ?? '');
unset($_SESSION['feed_flash_ok'], $_SESSION['feed_flash_err']);

if (!empty($_GET['posted'])) {
    $flashOk = 'Announcement posted to your organization feed.';
    if (!empty($_GET['public'])) {
        $flashOk = 'Posted to organization feed and shared to your public feed.';
    }
}

// -------------------- Route: Home list OR single post view --------------------
$postId = (int)($_GET['id'] ?? 0);
$isView = ($postId > 0);

// -------------------- Actions (ack/comment/reply/close only) --------------------
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if ($isAjaxPost) {
            header('Content-Type: application/json; charset=utf-8');
        }

        if (!csrf_ok()) throw new RuntimeException('Security check failed. Please refresh and try again.');

        $action = (string)($_POST['action'] ?? '');
        $pid    = (int)($_POST['post_id'] ?? 0);

        if ($pid <= 0) throw new RuntimeException('Invalid post.');

        // ensure belongs to org + read locked state
        $stChk = $dbh->prepare("SELECT comments_locked FROM org_posts WHERE id=:pid AND org_id=:org LIMIT 1");
        $stChk->execute([':pid'=>$pid, ':org'=>$orgId]);
        $lockedVal = $stChk->fetchColumn();
        if ($lockedVal === false) throw new RuntimeException('Post not found.');
        $isLocked = ((int)$lockedVal === 1);

        
// ✅ Manager-only: edit/delete post
if ($action === 'delete_post' || $action === 'edit_post') {
    if (!$canManagePosts) {
        throw new RuntimeException('Only the manager can edit or delete posts.');
    }

    // ensure post belongs to this org (and get current fields for edit)
    $stP = $dbh->prepare("SELECT id, title, body FROM org_posts WHERE id = :pid AND org_id = :org LIMIT 1");
    $stP->execute([':pid' => $pid, ':org' => $orgId]);
    $pRow = $stP->fetch(PDO::FETCH_ASSOC);
    if (!$pRow) {
        throw new RuntimeException('Post not found.');
    }

    if ($action === 'delete_post') {
        // Best-effort cleanup (safe even if some tables don't exist)
        try { $dbh->prepare("DELETE FROM org_post_attachments WHERE post_id = :pid")->execute([':pid'=>$pid]); } catch (Throwable $e) {}
        try { $dbh->prepare("DELETE FROM org_post_comments WHERE post_id = :pid")->execute([':pid'=>$pid]); } catch (Throwable $e) {}
        try { $dbh->prepare("DELETE FROM org_post_acknowledgements WHERE post_id = :pid")->execute([':pid'=>$pid]); } catch (Throwable $e) {}
        try { $dbh->prepare("DELETE FROM org_post_likes WHERE post_id = :pid")->execute([':pid'=>$pid]); } catch (Throwable $e) {}
        try { $dbh->prepare("DELETE FROM org_post_views WHERE post_id = :pid")->execute([':pid'=>$pid]); } catch (Throwable $e) {}

        $dbh->prepare("DELETE FROM org_posts WHERE id = :pid AND org_id = :org LIMIT 1")
            ->execute([':pid'=>$pid, ':org'=>$orgId]);

        if ($isAjaxPost) {
            echo json_encode(['ok'=>true,'deleted'=>true,'post_id'=>$pid]);
            exit;
        }

        $flashOk = 'Post deleted.';
        $backTo = (string)($_POST['back_to'] ?? ('feed.php?tab='.$tab));
        header('Location: ' . $backTo);
        exit;
    }

    // edit_post
    $newTitle = trim((string)($_POST['edit_title'] ?? ''));
    $newBody  = (string)($_POST['edit_body'] ?? '');

    if ($newTitle === '') $newTitle = (string)($pRow['title'] ?? '');
    if (mb_strlen($newTitle) > 255) {
        throw new RuntimeException('Title is too long.');
    }
    if (trim(strip_tags($newBody)) === '') {
        throw new RuntimeException('Body cannot be empty.');
    }

    $dbh->prepare("UPDATE org_posts SET title = :t, body = :b WHERE id = :pid AND org_id = :org LIMIT 1")
        ->execute([':t'=>$newTitle, ':b'=>$newBody, ':pid'=>$pid, ':org'=>$orgId]);

    if ($isAjaxPost) {
        echo json_encode(['ok'=>true,'edited'=>true,'post_id'=>$pid]);
        exit;
    }

    $flashOk = 'Post updated.';
    $backTo = (string)($_POST['back_to'] ?? ('feed.php?id='.$pid.'&tab='.$tab));
    header('Location: ' . $backTo);
    exit;
}

if ($action === 'ack') {
            $st = $dbh->prepare("
                INSERT INTO org_post_acknowledgements (post_id, user_id, acknowledged_at)
                VALUES (:pid, :uid, NOW())
                ON DUPLICATE KEY UPDATE acknowledged_at = VALUES(acknowledged_at)
            ");
            $st->execute([':pid' => $pid, ':uid' => $meMemberId]);
            $flashOk = ''; // no flash for Understand

        }
        elseif ($action === 'comment') {
            if ($isLocked) throw new RuntimeException('Comments are closed for this update.');

            $body = trim((string)($_POST['comment_body'] ?? ''));
            $replyTo = (int)($_POST['reply_to'] ?? 0);

            if ($body === '') throw new RuntimeException('Comment cannot be empty.');
            if (mb_strlen($body) > 500) $body = mb_substr($body, 0, 500);

            // Fallback reply prefix if no DB parent column
            if (!$hasReplyColumn && $replyTo > 0) {
                $body = "↳ Reply to #{$replyTo}: " . $body;
                if (mb_strlen($body) > 500) $body = mb_substr($body, 0, 500);
                $replyTo = 0;
            }

            if ($hasReplyColumn) {
                $st = $dbh->prepare("
                    INSERT INTO org_post_comments (post_id, user_id, parent_comment_id, body, created_at)
                    SELECT :pid1, :uid, :parent_id, :body, NOW()
                    FROM org_posts p
                    WHERE p.id = :pid2
                      AND p.org_id = :org
                      AND p.comments_locked = 0
                    LIMIT 1
                ");
                $st->execute([
                    ':pid1'      => $pid,
                    ':pid2'      => $pid,
                    ':uid'       => $meMemberId,
                    ':parent_id' => ($replyTo > 0 ? $replyTo : null),
                    ':body'      => $body,
                    ':org'       => $orgId
                ]);
            } else {
                $st = $dbh->prepare("
                    INSERT INTO org_post_comments (post_id, user_id, body, created_at)
                    SELECT :pid1, :uid, :body, NOW()
                    FROM org_posts p
                    WHERE p.id = :pid2
                      AND p.org_id = :org
                      AND p.comments_locked = 0
                    LIMIT 1
                ");
                $st->execute([
                    ':pid1' => $pid,
                    ':pid2' => $pid,
                    ':uid'  => $meMemberId,
                    ':body' => $body,
                    ':org'  => $orgId
                ]);
            }

            if ($st->rowCount() < 1) throw new RuntimeException('Comments are closed for this update.');
            $flashOk = 'Reply added.';
        }
        elseif ($action === 'close_comments') {
            if (!is_managerish($meRole)) throw new RuntimeException('Only Manager/Admin can close comments.');

            $st = $dbh->prepare("
                UPDATE org_posts
                SET comments_locked = 1, locked_at = NOW(), updated_at = NOW()
                WHERE id = :pid AND org_id = :org
                LIMIT 1
            ");
            $st->execute([':pid'=>$pid, ':org'=>$orgId]);

            if ($st->rowCount() < 1) throw new RuntimeException('Post not found for this organization.');
            $flashOk = 'Comments closed.';
        } else {
            throw new RuntimeException('Invalid action.');
        }

        // ✅ If AJAX (modal submit), return JSON
        if ($isAjaxPost) {
            $resp = ['ok' => true];
            if ($action === 'comment') {
                $stCnt = $dbh->prepare("SELECT COUNT(*) FROM org_post_comments WHERE post_id = :pid");
                $stCnt->execute([':pid' => $pid]);
                $resp['count'] = (int)$stCnt->fetchColumn();
                $resp['inline_html'] = render_feed_inline_comments_html($dbh, $orgId, $pid, $hasReplyColumn);
            }
            echo json_encode($resp);
            exit;
        }

        // ✅ PRG redirect back where user came from
        $_SESSION['feed_flash_ok']   = $flashOk;
        $_SESSION['feed_flash_err'] = '';
        $backTo = (string)($_POST['back_to'] ?? '');
        if ($backTo !== '' && starts_with($backTo, 'feed.php')) {
            header('Location: ' . $backTo);
        } else {
            header('Location: feed.php?id=' . (int)$pid);
        }
        exit;
    }
} catch (Throwable $e) {
    if ($isAjaxPost) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
        exit;
    }
    $flashErr = $e->getMessage();
}

// -------------------- Feed tab --------------------
$tab = (string)($_GET['tab'] ?? 'work'); // work|culture|all
if ($tab !== 'work' && $tab !== 'culture' && $tab !== 'all') $tab = 'work';

$whereType = '';
if ($tab === 'culture') {
    $whereType = " AND p.post_type = 'recognition' ";
} elseif ($tab === 'work') {
    $whereType = " AND p.post_type IN ('announcement','direction','update','weekly_update') ";
}

// -------------------- Feed NEW baseline (first visit) + public file base --------------------
$firstVisitNewWindowDays = 180; // default
$publicFileBaseUrl = '';       // optional: https://yourdomain.com (or https://yourdomain.com/subdir)
$themeMode = 'auto'; // light|dark|auto (from org_settings.theme_json)
try {
    $stS = $dbh->prepare("SELECT theme_json FROM org_settings WHERE org_id = :oid LIMIT 1");
    $stS->execute([':oid'=>$orgId]);
    $tjson = (string)($stS->fetchColumn() ?: '');
    if ($tjson !== '') {
        $theme = json_decode($tjson, true);
        if (is_array($theme)) {
            if (isset($theme['feed_first_visit_new_window_days'])) {
                $v = (int)$theme['feed_first_visit_new_window_days'];
                if ($v >= 0 && $v <= 3650) $firstVisitNewWindowDays = $v;
            }
            // Theme mode (optional): {"mode":"dark"} or {"mode":"light"} or {"mode":"auto"}
            if (isset($theme['mode'])) {
                $m = strtolower(trim((string)$theme['mode']));
                if (in_array($m, ['light','dark','auto'], true)) $themeMode = $m;
            } elseif (isset($theme['theme_mode'])) {
                $m = strtolower(trim((string)$theme['theme_mode']));
                if (in_array($m, ['light','dark','auto'], true)) $themeMode = $m;
            }
            // For Office Online preview (docx/pptx/xlsx) we need a PUBLIC HTTPS URL to the file.
            // Set this per org in org_settings.theme_json, for example:
            // {"public_file_base_url":"https://example.com"}
            // or {"public_base_url":"https://example.com"}
            $p = '';
            if (isset($theme['public_file_base_url'])) $p = (string)$theme['public_file_base_url'];
            elseif (isset($theme['public_base_url'])) $p = (string)$theme['public_base_url'];
            $p = trim($p);
            if ($p !== '') $publicFileBaseUrl = rtrim($p, '/');
        }
    }
} catch (Throwable $e) { /* ignore */ }

// Ensure org_feed_reads exists (post-level read pointer per tab)
try {
    $dbh->exec("
        CREATE TABLE IF NOT EXISTS org_feed_reads (
          org_id BIGINT NOT NULL,
          member_id BIGINT NOT NULL,
          tab VARCHAR(20) NOT NULL,
          last_read_at DATETIME NOT NULL,
          PRIMARY KEY (org_id, member_id, tab),
          KEY idx_member (member_id),
          KEY idx_org (org_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) { /* ignore */ }

// Get last_read_at for this org+member+tab
$lastReadAt = '';
try {
    $stR = $dbh->prepare("SELECT last_read_at FROM org_feed_reads WHERE org_id=:org AND member_id=:mid AND tab=:tab LIMIT 1");
    $stR->execute([':org'=>$orgId, ':mid'=>$meMemberId, ':tab'=>$tab]);
    $lastReadAt = (string)($stR->fetchColumn() ?: '');
} catch (Throwable $e) { $lastReadAt = ''; }

// Effective baseline (used for NEW badges + auto-open)
$effectiveReadAt = $lastReadAt;
if ($effectiveReadAt === '') {
    $effectiveReadAt = date('Y-m-d H:i:s', time() - ($firstVisitNewWindowDays * 86400));
}
$effectiveReadTs = strtotime($effectiveReadAt) ?: (time() - ($firstVisitNewWindowDays * 86400));

// -------------------- Unread counter (post-level) --------------------
$unreadCount = 0;
try {
    $stU = $dbh->prepare("
        SELECT COUNT(*)
        FROM org_posts p
        WHERE p.org_id = :org
          $whereType
          AND p.created_at > :since
    ");
    $stU->execute([':org'=>$orgId, ':since'=>$effectiveReadAt]);
    $unreadCount = (int)($stU->fetchColumn() ?: 0);
} catch (Throwable $e) { /* ignore */ }

// -------------------- HOME LIST: fetch posts --------------------

// -------------------- Post-level unread counter (per tab) --------------------
$unreadPostsCount = 0;
try {
    // Use effective baseline (real last read, or first-visit window baseline)
    $stUP = $dbh->prepare("
        SELECT COUNT(*)
        FROM org_posts p
        WHERE p.org_id = :org
          $whereType
          AND p.created_at > FROM_UNIXTIME(:ts)
    ");
    $stUP->execute([':org'=>$orgId, ':ts'=>$effectiveReadAt]);
    $unreadPostsCount = (int)($stUP->fetchColumn() ?: 0);
} catch (Throwable $e) { $unreadPostsCount = 0; }

// ✅ Right Sidebar session history (unique per org+tab+member)
$histKeyLatest = 'feed_latest_' . $orgId . '_' . $tab;
$histKeyList   = 'feed_hist_'   . $orgId . '_' . $tab . '_' . $meMemberId;
if (!isset($_SESSION[$histKeyList]) || !is_array($_SESSION[$histKeyList])) {
    $_SESSION[$histKeyList] = [];
}

// ✅ Pinned posts + Seen reply counts (per org+tab+member)
$pinKey  = 'feed_pins_' . $orgId . '_' . $tab . '_' . $meMemberId;
$seenKey = 'feed_seen_' . $orgId . '_' . $tab . '_' . $meMemberId;

if (!isset($_SESSION[$pinKey]) || !is_array($_SESSION[$pinKey]))  { $_SESSION[$pinKey]  = []; }
if (!isset($_SESSION[$seenKey]) || !is_array($_SESSION[$seenKey])) { $_SESSION[$seenKey] = []; }

// ✅ Post-level unread tracking (session-only, per org+tab+member)
$readAtKey = 'feed_last_read_at_' . $orgId . '_' . $tab . '_' . $meMemberId; // unix timestamp
if (!isset($_SESSION[$readAtKey])) { $_SESSION[$readAtKey] = 0; }
$lastReadAt = (int)($_SESSION[$readAtKey] ?? 0);

// ✅ FIRST VISIT "NEW" WINDOW (org_settings override)
// If you have never read any post yet, treat posts from the last N days as NEW.
// You can configure per-org in org_settings with key: feed_first_visit_new_window_days
// Example values: 1 (24h), 7 (1 week), 180 (default)
// ----------------------------------------------------
// FIRST VISIT NEW WINDOW (from theme_json)
// ----------------------------------------------------
$firstVisitNewWindowDays = 180; // default fallback

try {
    $st = $dbh->prepare("
        SELECT theme_json
        FROM org_settings
        WHERE org_id = :oid
        LIMIT 1
    ");
    $st->execute([':oid' => $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['theme_json'])) {
        $theme = json_decode($row['theme_json'], true);
        if (isset($theme['feed_first_visit_new_window_days'])) {
            $val = (int)$theme['feed_first_visit_new_window_days'];
            if ($val >= 0 && $val <= 3650) {
                $firstVisitNewWindowDays = $val;
            }
        }
    }
} catch (Throwable $e) {
    // fallback to default
}


$firstVisitBaselineTs    = time() - ($firstVisitNewWindowDays * 86400);
$effectiveReadAt         = ($lastReadAt > 0) ? $lastReadAt : $firstVisitBaselineTs;
// ✅ Pin/Unpin action (session-based, fast, no schema change)
$action = (string)($_GET['action'] ?? '');
$pid    = (int)($_GET['pid'] ?? 0);
if ($pid > 0 && ($action === 'pin' || $action === 'unpin')) {
    $pins = array_values(array_unique(array_map('intval', $_SESSION[$pinKey])));
    if ($action === 'pin') {
        array_unshift($pins, $pid);
    } else {
        $pins = array_values(array_filter($pins, function($x) use ($pid){ return (int)$x !== $pid; }));
    }
    // keep unique + cap
    $pins = array_values(array_unique(array_map('intval', $pins)));
    if (count($pins) > 50) $pins = array_slice($pins, 0, 50);
    $_SESSION[$pinKey] = $pins;

    // redirect back (prevents duplicate action on refresh)
    $redirId  = (int)($_GET['id'] ?? 0);
    $redirTab = (string)($_GET['tab'] ?? $tab);
    $qs = 'tab=' . urlencode($redirTab);
    if ($redirId > 0) $qs .= '&id=' . $redirId;
    header('Location: feed.php?' . $qs);
    exit;
}

$currentPost    = [];
$sidebarPosts   = [];
$currentPostId  = 0;

// ✅ If no explicit id, show the newest post in the main view area (Facebook-style),
// and put older posts in the right sidebar.
if (!$isView) {
    try {
        // ✅ Auto-open newest UNREAD post (post-level), falling back to newest overall
        // Unread = created_at > last read timestamp (updated AFTER media loads via AJAX mark_read)
        $currentPost = [];
        $currentPostId = 0;

        if ($effectiveReadAt > 0) {
            try {
                $sqlUnreadTop = "
                    SELECT
                      p.id, p.org_id, p.author_id, p.author_role, p.post_type,
                      p.title, p.body, p.visibility, p.comments_locked, p.locked_at,
                      p.created_at,

                      COALESCE(ou.role, '') AS author_org_role,

                      COALESCE(
                        m.fullname,
                        s.fullname,
                        CONCAT(UPPER(LEFT(om.member_type,1)), SUBSTRING(om.member_type,2), ' #', om.member_id)
                      ) AS author_name,

                      (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
                      (SELECT COUNT(*) FROM org_post_acknowledgements a WHERE a.post_id = p.id) AS ack_count,
                      EXISTS(
                        SELECT 1 FROM org_post_acknowledgements a2
                        WHERE a2.post_id = p.id AND a2.user_id = :me_id
                      ) AS i_acknowledged

                    FROM org_posts p
                    LEFT JOIN organization_users ou
                      ON ou.org_id = p.org_id AND ou.user_id = p.author_id
                    LEFT JOIN org_members om
                      ON om.org_id = p.org_id AND om.id = p.author_id
                    LEFT JOIN managers m
                      ON om.member_type = 'manager' AND m.id = om.member_id
                    LEFT JOIN staff_accounts s
                      ON om.member_type = 'staff' AND s.id = om.member_id

                    WHERE p.org_id = :org_id
                      $whereType
                      AND p.created_at > FROM_UNIXTIME(:ts)
                    ORDER BY p.created_at DESC
                    LIMIT 1
                ";
                $stUnread = $dbh->prepare($sqlUnreadTop);
                $stUnread->execute([':org_id'=>$orgId, ':me_id'=>$meMemberId, ':ts'=>$effectiveReadAt]);
                $currentPost = $stUnread->fetch(PDO::FETCH_ASSOC) ?: [];
                $currentPostId = (int)($currentPost['id'] ?? 0);
            } catch (Throwable $e) { /* ignore */ }
        }

        // Newest post (main view)
        $sqlTop = "
            SELECT
              p.id, p.org_id, p.author_id, p.author_role, p.post_type,
              p.title, p.body, p.visibility, p.comments_locked, p.locked_at,
              p.created_at,

              COALESCE(ou.role, '') AS author_org_role,

              COALESCE(
                m.fullname,
                s.fullname,
                CONCAT(UPPER(LEFT(om.member_type,1)), SUBSTRING(om.member_type,2), ' #', om.member_id)
              ) AS author_name,

              (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
              (SELECT COUNT(*) FROM org_post_acknowledgements a WHERE a.post_id = p.id) AS ack_count,
              EXISTS(
                SELECT 1 FROM org_post_acknowledgements a2
                WHERE a2.post_id = p.id AND a2.user_id = :me_id
              ) AS i_acknowledged

            FROM org_posts p
            LEFT JOIN organization_users ou
              ON ou.org_id = p.org_id AND ou.user_id = p.author_id
            LEFT JOIN org_members om
              ON om.org_id = p.org_id AND om.id = p.author_id
            LEFT JOIN managers m
              ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s
              ON om.member_type = 'staff' AND s.id = om.member_id

            WHERE p.org_id = :org_id
            $whereType
            ORDER BY p.created_at DESC
            LIMIT 1
        ";
        if ($currentPostId <= 0) {
            $stTop = $dbh->prepare(str_replace("WHERE p.org_id = :org_id", "WHERE p.org_id = :org_id
              AND p.created_at > :since", $sqlTop));
        $stTop->execute([':org_id'=>$orgId, ':me_id'=>$meMemberId, ':since'=>$effectiveReadAt]);
        $currentPost = $stTop->fetch(PDO::FETCH_ASSOC) ?: [];

        // fallback: newest overall
        if (!$currentPost) {
            $stTop = $dbh->prepare($sqlTop);
            $stTop->execute([':org_id'=>$orgId, ':me_id'=>$meMemberId]);
            $currentPost = $stTop->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        $currentPostId = (int)($currentPost['id'] ?? 0);
        }
        // ⏳ NOTE: We mark read AFTER media loads (via AJAX) so NEW badges stay accurate.
        // ✅ If a NEW post arrives, push the previous "latest" into the session history sidebar list.
        $prevLatest = (int)($_SESSION[$histKeyLatest] ?? 0);
        if ($currentPostId > 0 && $prevLatest > 0 && $prevLatest !== $currentPostId) {
            array_unshift($_SESSION[$histKeyList], $prevLatest);
        }
        if ($currentPostId > 0) {
            $_SESSION[$histKeyLatest] = $currentPostId;
        }

        // keep unique + cap
        $_SESSION[$histKeyList] = array_values(array_unique(array_map('intval', $_SESSION[$histKeyList])));
        if (count($_SESSION[$histKeyList]) > 50) {
            $_SESSION[$histKeyList] = array_slice($_SESSION[$histKeyList], 0, 50);
        }

        // Sidebar: 1) session history (most recent), 2) most recent DB posts excluding current
        $histIds = array_values(array_filter(array_map('intval', $_SESSION[$histKeyList]), function($x) use ($currentPostId) {
            return $x > 0 && $x !== $currentPostId;
        }));

        // fetch history rows
        if ($histIds) {
            $in = implode(',', array_fill(0, count($histIds), '?'));
            $sqlHist = "
                SELECT
                  p.id, p.post_type, p.title, p.created_at,
                  (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
                  (SELECT COUNT(*) FROM org_post_attachments a WHERE a.post_id = p.id AND a.org_id = p.org_id) AS media_count,
                  EXISTS(
                    SELECT 1 FROM org_post_attachments a2
                    WHERE a2.post_id = p.id AND a2.org_id = p.org_id
                      AND COALESCE(a2.mime_type, a2.mime, '') LIKE 'video/%'
                  ) AS has_video,
                  (SELECT COUNT(*) FROM org_post_attachments a WHERE a.post_id = p.id AND a.org_id = p.org_id) AS media_count,
                  EXISTS(
                    SELECT 1 FROM org_post_attachments a2
                    WHERE a2.post_id = p.id AND a2.org_id = p.org_id
                      AND COALESCE(a2.mime_type, a2.mime, '') LIKE 'video/%'
                  ) AS has_video,
                  (SELECT COUNT(*) FROM org_post_attachments a WHERE a.post_id = p.id AND a.org_id = p.org_id) AS media_count,
                  EXISTS(
                    SELECT 1 FROM org_post_attachments a2
                    WHERE a2.post_id = p.id AND a2.org_id = p.org_id
                      AND COALESCE(a2.mime_type, a2.mime, '') LIKE 'video/%'
                  ) AS has_video
                FROM org_posts p
                WHERE p.org_id = ? AND p.id IN ($in)
                ORDER BY FIELD(p.id, $in)
                LIMIT 20
            ";
            // bind: org_id + ids + ids again for FIELD ordering
            $params = array_merge([$orgId], $histIds, $histIds);
            $stH = $dbh->prepare($sqlHist);
            $stH->execute($params);
            $sidebarPosts = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // if sidebar is still short, fill with latest DB posts excluding current
        $want = 20;
        if (count($sidebarPosts) < $want) {
            $need = $want - count($sidebarPosts);
            $need = (int)$need;

            // avoid duplicates
            $seen = [];
            foreach ($sidebarPosts as $r) $seen[(int)($r['id'] ?? 0)] = true;
            $seen[(int)$postId] = true;

            // build NOT IN list
            $ph = [];
            $params = [':org_id'=>$orgId];
            $i = 0;
            foreach (array_keys($seen) as $sid) {
                if ($sid <= 0) continue;
                $k = ':x'.$i++;
                $ph[] = $k;
                $params[$k] = (int)$sid;
            }
            $notIn = $ph ? " AND p.id NOT IN (" . implode(',', $ph) . ") " : "";

            $sqlMore = "
                SELECT
                  p.id, p.post_type, p.title, p.created_at,
                  (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
                  (SELECT COUNT(*) FROM org_post_attachments a WHERE a.post_id = p.id AND a.org_id = p.org_id) AS media_count,
                  EXISTS(
                    SELECT 1 FROM org_post_attachments a2
                    WHERE a2.post_id = p.id AND a2.org_id = p.org_id
                      AND COALESCE(a2.mime_type, a2.mime, '') LIKE 'video/%'
                  ) AS has_video,
                  (SELECT COUNT(*) FROM org_post_attachments a WHERE a.post_id = p.id AND a.org_id = p.org_id) AS media_count,
                  EXISTS(
                    SELECT 1 FROM org_post_attachments a2
                    WHERE a2.post_id = p.id AND a2.org_id = p.org_id
                      AND COALESCE(a2.mime_type, a2.mime, '') LIKE 'video/%'
                  ) AS has_video,
                  (SELECT COUNT(*) FROM org_post_attachments a WHERE a.post_id = p.id AND a.org_id = p.org_id) AS media_count,
                  EXISTS(
                    SELECT 1 FROM org_post_attachments a2
                    WHERE a2.post_id = p.id AND a2.org_id = p.org_id
                      AND COALESCE(a2.mime_type, a2.mime, '') LIKE 'video/%'
                  ) AS has_video
                FROM org_posts p
                WHERE p.org_id = :org_id
                  $whereType
                  $notIn
                ORDER BY p.created_at DESC
                LIMIT $need
            ";
            $stMore = $dbh->prepare($sqlMore);
            $stMore->execute($params);
            $more = $stMore->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($more as $r) $sidebarPosts[] = $r;
        }

    } catch (Throwable $e) {
        $flashErr = $flashErr ?: ('DB error: ' . $e->getMessage());
    }
} else {
    // ✅ Viewing a specific post: put it into session history (so it appears in right sidebar)
    if ($postId > 0) {
        array_unshift($_SESSION[$histKeyList], $postId);
        $_SESSION[$histKeyList] = array_values(array_unique(array_map('intval', $_SESSION[$histKeyList])));
        if (count($_SESSION[$histKeyList]) > 50) {
            $_SESSION[$histKeyList] = array_slice($_SESSION[$histKeyList], 0, 50);
        }
    }

    // Sidebar: recent posts excluding the one being viewed
    try {
        $sqlSide = "
            SELECT
              p.id, p.post_type, p.title, p.created_at,
              (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
              (SELECT COUNT(*) FROM org_post_attachments a WHERE a.post_id = p.id AND a.org_id = p.org_id) AS media_count,
              EXISTS(
                SELECT 1 FROM org_post_attachments a2
                WHERE a2.post_id = p.id AND a2.org_id = p.org_id
                  AND COALESCE(a2.mime_type, a2.mime, '') LIKE 'video/%'
              ) AS has_video
            FROM org_posts p
            WHERE p.org_id = :org_id
              $whereType
              AND p.id <> :cur
            ORDER BY p.created_at DESC
            LIMIT 20
        ";
        $stS = $dbh->prepare($sqlSide);
        $stS->execute([':org_id'=>$orgId, ':cur'=>$postId]);
        $sidebarPosts = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* ignore */ }
}

// ✅ Reorder sidebar: pinned items first (session-based), then the rest
try {
    $pins = array_values(array_unique(array_map('intval', $_SESSION[$pinKey] ?? [])));
    if ($pins && $sidebarPosts) {
        $pinSet = [];
        foreach ($pins as $p) $pinSet[(int)$p] = true;

        $pinned = [];
        $rest   = [];
        foreach ($sidebarPosts as $r) {
            $rid = (int)($r['id'] ?? 0);
            if ($rid > 0 && isset($pinSet[$rid])) $pinned[] = $r;
            else $rest[] = $r;
        }
        $sidebarPosts = array_merge($pinned, $rest);
    }
} catch (Throwable $e) { /* ignore */ }

$posts = []; // legacy variable used below (keep to avoid notices)
if ($currentPostId > 0) $posts = [$currentPost];

// -------------------- VIEW: fetch ONE post + comments (if id exists) --------------------
$post = [];
$comments = [];
$children = [];
$thread = [];

if ($isView) {
    try {
        $sql = "
            SELECT
              p.id, p.org_id, p.author_id, p.author_role, p.post_type,
              p.title, p.body, p.visibility, p.comments_locked, p.locked_at,
              p.created_at,

              COALESCE(ou.role, '') AS author_org_role,

              COALESCE(
                m.fullname,
                s.fullname,
                CONCAT(UPPER(LEFT(om.member_type,1)), SUBSTRING(om.member_type,2), ' #', om.member_id)
              ) AS author_name,

              (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
              (SELECT COUNT(*) FROM org_post_acknowledgements a WHERE a.post_id = p.id) AS ack_count,
              EXISTS(
                SELECT 1 FROM org_post_acknowledgements a2
                WHERE a2.post_id = p.id AND a2.user_id = :me_id
              ) AS i_acknowledged

            FROM org_posts p
            LEFT JOIN organization_users ou
              ON ou.org_id = p.org_id AND ou.user_id = p.author_id
            LEFT JOIN org_members om
              ON om.org_id = p.org_id AND om.id = p.author_id
            LEFT JOIN managers m
              ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s
              ON om.member_type = 'staff' AND s.id = om.member_id

            WHERE p.org_id = :org_id
              AND p.id = :pid
            LIMIT 1
        ";
        $st = $dbh->prepare($sql);
        $st->execute([':org_id'=>$orgId, ':pid'=>$postId, ':me_id'=>$meMemberId]);
        $post = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        // ✅ Mark this post as "seen" for new-replies badge logic
        try {
            $stCnt = $dbh->prepare("SELECT COUNT(*) FROM org_post_comments WHERE post_id = :pid");
            $stCnt->execute([':pid'=>$postId]);
            $_SESSION[$seenKey][$postId] = (int)$stCnt->fetchColumn();
        } catch (Throwable $e) { /* ignore */ }

    } catch (Throwable $e) {
        $flashErr = $flashErr ?: ('DB error: ' . $e->getMessage());
    }

    if (!$post) {
        $isView = false;
    } else {
        try {
            $extraSelect = $hasReplyColumn ? ", c.parent_comment_id" : "";
            $sqlC = "
              SELECT
                c.id, c.post_id, c.user_id, c.body, c.created_at
                $extraSelect,
                COALESCE(ou.role,'member') AS role,
                COALESCE(
                  m.fullname,
                  s.fullname,
                  CONCAT('Member #', om.member_id)
                ) AS user_name
              FROM org_post_comments c
              LEFT JOIN organization_users ou
                ON ou.org_id = :org1 AND ou.user_id = c.user_id
              LEFT JOIN org_members om
                ON om.org_id = :org2 AND om.id = c.user_id
              LEFT JOIN managers m
                ON om.member_type = 'manager' AND m.id = om.member_id
              LEFT JOIN staff_accounts s
                ON om.member_type = 'staff' AND s.id = om.member_id
              WHERE c.post_id = :pid
              ORDER BY c.created_at ASC
              LIMIT 500
            ";
            $stc = $dbh->prepare($sqlC);
            $stc->execute([
                ':org1' => $orgId,
                ':org2' => $orgId,
                ':pid'  => (int)$post['id']
            ]);
            $comments = $stc->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { /* ignore */ }

        foreach ($comments as $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) continue;

            if ($hasReplyColumn) {
                $parent = (int)($c['parent_comment_id'] ?? 0);
                if ($parent > 0) $children[$parent][] = $c;
                else $thread[] = $c;
            } else {
                $thread[] = $c;
            }
        }

        // ✅ When viewing a post (feed.php?id=...), show THAT post in the main view area
        $currentPost   = $post;
        $currentPostId = (int)($post['id'] ?? $postId);
    }
}

// ✅ Active post ID for sidebar highlighting / pin redirect
$activePostId = 0;
if ($isView) {
    $activePostId = (int)$postId;
} else {
    $activePostId = (int)$currentPostId;
}



?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h((string)($ORG['name'] ?? 'Organization')) ?> - Home</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  
  <style>
    /* ==============================
       THEME VARIABLES — follow html.dark-auto from per-publisher Gear prefs
    ============================== */
    :root{
      --bg-main:#171d24;
      --bg-card:#171d24;
      --bg-sidebar:#171d24;
      --text-primary:#111827;
      --text-secondary:#6b7280;
      --border-color:#e5e7eb;
      --accent:var(--msb-palette-action, #2563eb);
      --shadow: 0 10px 18px rgba(0,0,0,.04);
      --shadow-strong: 0 14px 24px rgba(0,0,0,.10);
      --feed-chrome-offset: 160px;
      --feed-info-h: 260px;
      --feed-media-h: calc(100vh - var(--feed-chrome-offset, 160px) - var(--feed-info-h, 260px));
    }
    html.dark-auto{
      --bg-main:#171d24;
      --bg-card:#171d24;
      --bg-sidebar:#171d24;
      --text-primary:#b1bcce;
      --text-secondary:#b1bcce;
      --text-muted:#b1bcce;
      --border-color:#22324a;
      --accent:var(--msb-palette-action, #6e7b90);
      --shadow: 0 10px 18px rgba(0,0,0,.35);
      --shadow-strong: 0 14px 24px rgba(0,0,0,.45);
    }

    /* ===============================
   BASE LIGHT THEME
  =================================*/
  :root {
    --bg-main: #171d24;
    --bg-card: #171d24;

    --text-primary: #25282f;     /* Very dark */
    --text-secondary: #475569;   /* Medium */
    --text-muted: #64748b;       /* Subtle */

    --border-color: rgba(15,23,42,0.08);
  }


  /* ===============================
    DARK THEME (High Contrast)
  =================================*/
  html.dark-auto {
    --bg-main: #171d24;
    --bg-card: #171d24;

    --text-primary: #b1bcce;
    --text-secondary: #b1bcce;
    --text-muted: #b1bcce;

    --border-color: rgba(255,255,255,0.08);
  }


  /* ===============================
    APPLY VARIABLES
  =================================*/

  body {
    background: var(--bg-main);
    color: var(--text-primary);
  }
  .feed-title {
    color: var(--text-primary);
  }

  .feed-desc {
    color: var(--text-secondary);
  }

  .feed-date,
  .feed-meta {
    color: var(--text-muted);
  }

  /* Base page colors */
  body{
    background: var(--bg-main) !important;
    color: var(--text-primary);
    transition: background .25s ease, color .25s ease;
  }
  .sh-mainpanel, .sh-pagebody{
    background: var(--bg-main);
  }
  .dashboard-card{
    background: transparent;
  }
 
    
  html,body{ height:100%; overflow:hidden; }
  .sh-mainpanel{ height:100vh; display:flex; flex-direction:column; overflow:hidden; }
  .sh-pagetitle{ flex:0 0 auto; }
  .sh-pagebody{ flex:1 1 auto; overflow:hidden; display:flex; flex-direction:column; min-height:0; padding-bottom:0!important; }
  .dashboard-card{ flex:1 1 auto; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
  .card-body-fixed{ flex:1 1 auto; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
  .feed-toolbar{
    flex:0 0 auto;
    padding:6px 8px 4px;
    border-bottom:1px solid var(--border-color, #e5e7eb);
    background:var(--bg-card, #fff);
    z-index:2;
  }
  .rows-scroll{
    flex:1 1 auto;
    min-height:0;
    overflow:hidden;
    padding: 6px 8px;
    display:flex;
    flex-direction:column;
  }

  .dash-toprow{
    display:flex; align-items:center; justify-content:space-between;
    gap:10px; flex-wrap:wrap; margin: 10px 0 6px;
  }
  .feed-toolbar-split{
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:nowrap;
    margin:6px 0 4px;
    padding:0 5px;
  }
  .feed-toolbar-main{
    flex:1 1 auto;
    min-width:0;
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
  }
  .feed-toolbar-side{
    flex:0 0 340px;
    width:340px;
    display:flex;
    justify-content:flex-end;
    align-items:center;
  }
  .feed-toolbar-stats{
    display:flex;
    gap:4px;
    flex-wrap:nowrap;
    justify-content:flex-end;
    align-items:center;
    max-width:100%;
  }
  .feed-toolbar-stats .stat-pill{
    padding:4px 6px;
    gap:4px;
    font-size:11px;
  }
  .feed-toolbar-stats .stat-pill .icon{
    width:18px;
    height:18px;
    font-size:10px;
  }
  .feed-toolbar-stats .stat-pill .sub{
    margin-left:2px;
  }
  .dash-toprow .left-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .dash-toprow .right-stats{ margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }

  .stat-pill{
    display:inline-flex; align-items:center; gap:8px;
    padding:6px 10px; border-radius:999px;
    border:1px solid var(--border-color); background: var(--bg-card);
    font-size:12px; line-height:1; white-space:nowrap;
    box-shadow:0 1px 0 rgba(0,0,0,.02);
  }
  .stat-pill .icon{
    width:22px; height:22px; border-radius:999px;
    display:inline-flex; align-items:center; justify-content:center;
    background:var(--org-accent-soft, var(--msb-palette-action-soft, #eef2ff));
    color:var(--org-accent, var(--msb-palette-action, #2563eb));
    font-size:12px;
  }
  .stat-pill .num{ font-weight:900; color:var(--text-primary, #111827); }
  .stat-pill .lbl{ color:var(--text-secondary, #4b5563); font-weight:800; }
  .stat-pill .sub{ color:var(--text-muted, #9ca3af); font-weight:700; margin-left:6px; }

    
/* ✅ Feed card: wide media on top, details below */
.feed-card{
  overflow:hidden;
  min-height: 420px;
  background:var(--bg-card);
  box-shadow: var(--shadow);
  border: 1px solid var(--border-color, #e5e7eb);
  border-radius: 12px;
  display:flex;
  flex-direction:column;
}
.feed-post{
  display:flex;
  flex-direction:column;
  align-items:stretch;
  height: 100%;
  min-height: 0;
  flex: 1 1 auto;
}


.feed-post-media {
  flex: 1 1 auto;
  width: 100%;
  min-width: 0;
  height: calc(100vh - var(--feed-chrome-offset, 160px) - var(--feed-info-h, 260px));
  min-height: 300px;
  max-height: none;
  background: #000;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-bottom: 1px solid rgba(0,0,0,.08);
}
.feed-post-media img{
  width:100%;
  height:100%;
  object-fit:contain;
  display:block;
  background:#000;
}

.feed-post-media video{
  width:100%;
  height:100%;
  object-fit:contain;
  display:block;
  background:#000;
}
.feed-post-media .media-fallback{
  width:100%;
  height:100%;
  display:flex;
  align-items:center;
  justify-content:center;
  color:#e2e8f0;
  font-weight:900;
  text-align:center;
  padding:24px;
  background: linear-gradient(135deg, #171d24, #111827);
}
.feed-post-media.is-text-only{
  background:var(--bg-card, #fff);
  align-items:stretch;
  justify-content:flex-start;
  border-bottom:1px solid var(--border-color, #e5e7eb);
  display:flex;
  flex-direction:column;
}
.feed-post-media.is-text-only .feed-media-text{
  width:100%;
  height:100%;
  min-height:0;
  display:flex;
  flex-direction:column;
  overflow:hidden;
  text-align:left;
  color:var(--text-primary, #111827);
}
.feed-post-media.is-text-only .feed-media-text-title{
  flex:0 0 auto;
  padding:24px 24px 12px;
  font-weight:900;
  font-size:clamp(20px, 2.4vw, 26px);
  line-height:1.25;
  margin:0;
  color:var(--text-primary, #111827);
  background:var(--bg-card, #fff);
  border-bottom:1px solid var(--border-color, #e5e7eb);
}
.feed-post-media.is-text-only .feed-media-text-desc{
  flex:1 1 auto;
  min-height:0;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  padding:14px 24px 20px;
  font-size:15px;
  line-height:1.6;
  color:var(--text-secondary, #374151);
  white-space:pre-wrap;
}
.feed-post-media.is-text-only .feed-media-text-desc--solo{
  flex:1 1 auto;
  min-height:0;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  padding:24px 24px 20px;
  font-size:clamp(18px, 2.1vw, 22px);
  font-weight:800;
  line-height:1.45;
  color:var(--text-primary, #111827);
}

/* ✅ Media carousel strip */
.media-strip{
  display:flex;
  gap:8px;
  padding:10px 0 0;
  overflow-x:auto;
  -webkit-overflow-scrolling: touch;
}
.media-tile{
  flex:0 0 auto;
  width:56px;
  height:56px;
  border-radius:12px;
  border:2px solid rgba(0,0,0,.12);
  background: rgba(0,0,0,.03);
  overflow:hidden;
  padding:0;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
}
.media-tile img{ width:100%; height:100%; object-fit:cover; display:block; }
.media-tile .tile-icon{ font-size:18px; color:#334155; }
.media-tile .tile-video{ width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:#171d24; color:#fff; font-size:16px; }
.media-tile.active{ border-color:#6366f1; box-shadow: 0 0 0 4px rgba(99,102,241,.12); }


.feed-post-detail{
  flex: 0 0 auto;
  min-width: 0;
  width: 100%;
  padding: 10px 14px 12px;
  display:flex;
  flex-direction:column;
  overflow: visible;
  background: var(--bg-card);
  border-top: 1px solid var(--border-color, #e5e7eb);
}
.feed-detail-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:6px;
  flex-shrink: 0;
}
.feed-head-tools{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  justify-content:flex-end;
  flex:0 0 auto;
  margin-left:auto;
}
.feed-head-tools .btn{
  padding: 4px 8px;
  line-height: 1;
}
.feed-head-tools .pin-btn.pinned{
  border-color:#f59e0b;
  color:#b45309;
}
.feed-author{
  display:flex;
  align-items:center;
  gap:10px;
  min-width:0;
}
.feed-avatar{
  width:36px;height:36px;border-radius:999px;
  display:flex;align-items:center;justify-content:center;
  background:#eef2ff;color:#3730a3;
  font-weight:900;
  flex:0 0 auto;
}
.feed-author-lines{ min-width:0; }
.feed-author-name{
  font-weight:900;
  color:#85888b;
  line-height:1.15;
  font-size:13px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width: 100%;
}
.feed-author-meta{ font-size:12px; color:#6b7280; font-weight:700; display:flex; gap:8px; flex-wrap:wrap; }

.feed-title{
  font-weight:900;
  margin: 2px 0 6px;
  color:#111827;
  font-size: 14px;
  line-height:1.25;
  flex-shrink: 0;
}

.feed-post-detail .comment-item{
  padding:8px 10px;
  border:1px solid var(--border-color, #e5e7eb);
  border-radius:10px;
  margin:8px 0;
  background:var(--bg-main, #171d24);
  font-size:13px;
  line-height:1.45;
}
.feed-post-detail .comment-meta{
  font-size:12px;
  color:var(--text-secondary, #6b7280);
  margin-bottom:4px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.feed-inline-comments{
  flex:1 1 auto;
  min-height:72px;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  margin:8px 0 6px;
  padding:4px 0;
  border-top:1px solid var(--border-color, #e5e7eb);
  border-bottom:1px solid var(--border-color, #e5e7eb);
}
.feed-inline-comments .comment-item--new{
  border-color:rgba(59,130,246,.45);
  background:rgba(59,130,246,.08);
}
.feed-inline-comments .comment-body-preview{
  white-space:pre-wrap;
  word-break:break-word;
}
.feed-inline-comments .comment-read-more-btn{
  margin-top:4px;
}

.feed-desc{
  color:#1f2937;
  font-size: 13px;
  line-height:1.45;
  white-space:pre-wrap;
  margin: 0 0 12px;
}

/* --- Description clamp + Read more --- */
.feed-desc-wrapper{
  position:relative;
  margin: 0 0 6px;
  /* flex: 1 1 auto; */
  min-height: 0;
  overflow: hidden;
}
.feed-desc.clamp-7{
  display:-webkit-box;
  -webkit-box-orient:vertical;
  -webkit-line-clamp:7;
  line-clamp:7;
  overflow:hidden;
  white-space:pre-line;
  word-break:break-word;
  margin:0;
}
.read-more-btn.is-hidden{
  display:none !important;
}
.read-more-btn{
  background:none;
  border:none;
  padding:0;
  margin-top:6px;
  color: var(--accent);
  font-weight:900;
  font-size:12px;
  cursor:pointer;
}
.read-more-btn:hover{ text-decoration:underline; }

.read-more-modal{
  display:none;
  position:fixed;
  inset:0;
  background: rgba(0,0,0,.55);
  z-index: 99999;
  align-items:center;
  justify-content:center;
}
.read-more-modal .rm-card{
  width: min(720px, 92vw);
  max-height: 82vh;
  overflow:auto;
  background:#fff;
  border-radius: 14px;
  padding: 18px 18px 16px;
  box-shadow: 0 18px 60px rgba(0,0,0,.25);
  position: relative;
}
.read-more-modal .rm-close{
  position:absolute;
  top:10px;
  right:12px;
  width: 36px;
  height: 36px;
  border-radius: 999px;
  border: 1px solid #e5e7eb;
  background: #f8fafc;
  font-size: 18px;
  font-weight: 900;
  cursor:pointer;
}
.read-more-modal .rm-head{ margin: 0 40px 12px 0; padding-bottom: 10px; border-bottom: 1px solid rgba(15,23,42,.08); }
.read-more-modal .rm-title{ font-weight: 900; font-size: 18px; color:#111827; margin: 0 0 6px 0; }
.read-more-modal .rm-date{ font-weight: 700; font-size: 12px; color:#64748b; }
.read-more-modal .rm-body{ white-space:pre-wrap; line-height:1.55; color:#1f2937; font-size: 13px; }

.feed-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:center;
  margin-top: 8px;
  flex-shrink: 0;
}
.feed-actions-liked{
  white-space: nowrap;
}
.feed-actions-right{
  margin-left: auto;
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  justify-content:flex-end;
}

.feed-compose{
  margin-top: 4px;
  flex-shrink: 0;
}
.feed-compose-form{
  display:flex;
  align-items:flex-end;
  gap:8px;
}
.feed-compose-input{
  flex:1 1 auto;
  min-width:0;
  min-height:36px;
  max-height:72px;
  resize:none;
  border:1px solid var(--border-color, #e5e7eb);
  border-radius:10px;
  padding:8px 10px;
  font-size:13px;
  line-height:1.35;
  background:var(--bg-main, #171d24);
  color:var(--text-primary, #111827);
}
.feed-compose-input:focus{
  outline:none;
  border-color:#6366f1;
  box-shadow:0 0 0 3px rgba(99,102,241,.12);
}
.feed-compose-input:disabled{
  opacity:.6;
  cursor:not-allowed;
}
.feed-compose-send{
  flex:0 0 auto;
  width:36px;
  height:36px;
  border:0;
  border-radius:10px;
  background:var(--org-accent, var(--msb-palette-action, #2563eb));
  color:#fff;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  font-size:14px;
}
.feed-compose-send:hover:not(:disabled){
  background:var(--org-accent-strong, var(--msb-palette-action-strong, #1d4ed8));
}
.feed-compose-send:disabled{
  opacity:.5;
  cursor:not-allowed;
}

/* Comments preview */
.feed-comments{
  margin-top: 6px;
  padding-top: 8px;
  border-top: 1px solid #eef0f4;
  min-height: 0;
  overflow:hidden;
  flex-shrink: 0;
}
.feed-comment{
  display:flex;
  gap:8px;
  margin: 8px 0;
  font-size: 12.5px;
  line-height:1.35;
}
.feed-comment .who{
  font-weight:900;
  color:#697386;
  white-space:nowrap;
}
.feed-comment .txt{
  color:#6d86af;
  overflow:hidden;
  text-overflow:ellipsis;
  display:-webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.feed-comment .time{
  color:#9ca3af;
  font-size:11px;
  font-weight:700;
  margin-left:auto;
  white-space:nowrap;
}
.feed-viewall{
  display:inline-block;
  margin-top: 6px;
  font-size:12px;
  font-weight:900;
  color:var(--org-link, var(--msb-palette-link, var(--accent, #2563eb)));
}

@media (max-width: 992px){
  .feed-post-media{
    min-height: 220px;
    height: calc(100vh - 300px);
  }
  .feed-post-detail{ height: 170px; max-height: 170px; overflow: hidden; }
}
    .feed-meta{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; font-size:12px; color:#6b7280; margin-bottom:8px; }
    .feed-badge{ font-size:12px; padding:3px 8px; border-radius:999px; background:#f2f4f7; color:#374151; font-weight:800; }
    .feed-title{ font-weight:800; margin:6px 0 6px; color:#111827; }
    .feed-title a{ color:inherit; text-decoration:none; }
    .feed-title a:hover{ text-decoration:underline; }
    .feed-body{ white-space:pre-wrap; color:#1f2937; margin: 0 0 10px; }
    .feed-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:8px; }
    .mini-muted{ color:#6b7280; font-size:12px; }

    .comment-item{ padding:8px 10px; border:1px solid #eef0f4; border-radius:10px; margin:8px 0; background:#fafbfc; }
    .comment-meta{ font-size:12px; color:#6b7280; margin-bottom:4px; display:flex; align-items:center; justify-content:space-between; gap:10px; }

    .modal-clean .modal-dialog{ max-width: 980px; }
    .modal-clean .modal-content{ border:2px solid #6b7280; border-radius:18px; box-shadow:none; }
    .modal-clean .modal-header{
      border:0; padding:16px 18px 8px 18px;
      display:flex; align-items:center; justify-content:space-between;
    }
    .modal-clean .modal-body{
      padding: 8px 18px 18px 18px;
      max-height: 70vh; overflow:auto;
      background-color:#0d2946;
    }
    #editPostModal.show{
      display: block !important;
      z-index: 1050;
    }
    .modal-badge{
      display:inline-flex; align-items:center;
      padding:6px 10px; border-radius:999px;
      background:#eef2f7; color:#374151;
      font-weight:900; font-size:12px;
    }
    .btn-close-x{
      width:40px; height:40px; border-radius:999px;
      border:2px solid #e5e7eb; background:#fff;
      display:flex; align-items:center; justify-content:center;
      cursor:pointer;
    }
    .btn-close-x:hover{ background:#f3f4f6; }

    .reply-box{
      border:1px dashed #d1d5db;
      border-radius:12px;
      padding:10px;
      margin-top:12px;
      background:#fff;
    }


    /* ✅ Replies modal: collapsed-by-default (clarity-first) */
    .rm-collapser{
      display:flex; align-items:center; justify-content:space-between;
      gap:10px; flex-wrap:wrap;
      padding:10px 0 8px;
      border-bottom:1px solid #eef0f4;
      margin-bottom:10px;
    }
    .rm-hint{ font-size:12px; color:#6b7280; font-weight:800; }
    .rm-hint i{ margin-right:6px; }
    

    /* ✅ Feed layout with right sidebar */
    .feed-layout{
      display:flex;
      gap:14px;
      align-items:stretch;
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      padding:5px;
    }
    .feed-layout--tall{
      height: 100%;
      max-height: 100%;
    }
    .feed-layout--tall .feed-card {
      flex: 1 1 auto;
      min-height: 0;
      height: 100%;
      max-height: 100%;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
    }
    .feed-layout--tall .feed-post{
      height: 100%;
      min-height: 0;
      flex: 1 1 auto;
      display: flex;
      flex-direction: column;
    }
    .feed-layout--tall .feed-post-media{
      flex: 0 0 auto;
      height: var(--feed-media-h, calc(100vh - 420px));
      min-height: 280px;
      max-height: var(--feed-media-h, calc(100vh - 420px));
      border-top-left-radius: 12px;
      border-top-right-radius: 12px;
    }
    .feed-layout--tall .feed-post-detail{
      flex: 1 1 auto;
      min-height: 0;
      height: auto;
      max-height: none;
      overflow: hidden;
      padding: 10px 14px 14px;
      border-bottom-left-radius: 12px;
      border-bottom-right-radius: 12px;
      display: flex;
      flex-direction: column;
    }
    .feed-layout--tall .feed-desc-wrapper{
      flex-shrink: 0;
      margin-bottom: 4px;
    }
    .feed-layout--tall .feed-inline-comments{
      flex:1 1 auto;
      min-height:0;
      overflow-y:auto;
    }
    .feed-layout--tall .feed-actions{
      flex-shrink: 0;
      margin-top: auto;
    }
    .feed-layout--tall .feed-compose{
      flex-shrink: 0;
    }
    .feed-layout--tall .feed-main{
      flex:1 1 auto;
      min-width:0;
      min-height:0;
      height: 100%;
      max-height: 100%;
      overflow: hidden;
      box-shadow: none;
      background: transparent;
      display: flex;
      flex-direction: column;
      padding: 0;
      box-sizing: border-box;
    }
    .feed-layout--tall .feed-scroll{
      flex:1 1 auto;
      min-height:0;
      height: 100%;
      overflow: hidden;
      padding: 0;
      box-shadow: none;
      display: flex;
      flex-direction: column;
    }

    /* ✅ Center column fills available viewport height */
    .feed-main{
      flex:1 1 auto;
      min-width:0;
      min-height:0;
      height: 100%;
      max-height: 100%;
      overflow:hidden;
      box-shadow: var(--shadow);
      display: flex;
      flex-direction:column;
    }

    /* ✅ Feed content fits panel — no inner vertical scroll */
    .feed-scroll{
      flex:1 1 auto;
      min-height:0;
      height: 100%;
      overflow:hidden;
      padding-right:6px;
      box-shadow: var(--shadow);
    }

    /* ✅ Media preview frame: tall wide band at top of card */
    .post-media,
    .feed-post-media{
      border: 0;
      border-bottom: 1px solid rgba(0,0,0,.08);
      background: #000;
      overflow: hidden;
      width: 100%;
      flex: 1 1 auto;
      height: calc(100vh - var(--feed-chrome-offset, 160px) - var(--feed-info-h, 260px));
      min-height: 300px;
      max-height: none;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .post-media img,
    .feed-post-media img,
    .post-media video,
    .feed-post-media video,
    .post-media iframe,
    .feed-post-media iframe,
    .post-media embed,
    .feed-post-media embed,
    .post-media object,
    .feed-post-media object{
      width:100%;
      height:100%;
      max-height:100%;
      display:block;
      object-fit:contain;
    }
    .post-media video,
    .feed-post-media video{ background:#000; }
/* ✅ Right sidebar: clean card UI — full height beside main post */
    .feed-sidebar{
      flex:0 0 340px;
      width:340px;

      position: relative;
      top: auto;
      align-self:stretch;

      height: 100%;
      max-height: none;
      min-height: 0;
      overflow: hidden;

      border-radius: 2px;
      border:1px solid var(--border-color);
      box-shadow: 0 10px 18px rgba(0,0,0,.04);

      display: flex;
      flex-direction: column;
    }

    .sidebar-head{
      flex: 0 0 auto;
      position: sticky;
      top: 0;
      z-index: 5;

      padding: 12px 12px 10px;
      border-bottom: 1px solid #eef0f4;
      background: rgba(255,255,255,.95);
      backdrop-filter: blur(8px);
    }

    .sidebar-titlebar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin-bottom: 10px;
    }
    .sidebar-title{
      display:flex;
      align-items:center;
      gap:8px;
      font-weight: 900;
      color:#111827;
      font-size: 12px;
      letter-spacing: .2px;
    }
    .sidebar-subtitle{
      font-size: 11px;
      color:#6b7280;
      font-weight: 700;
    }
    .sidebar-head-actions{
      display:flex;
      align-items:center;
      gap:8px;
      flex:0 0 auto;
      margin-left:auto;
    }
    .sidebar-refresh-btn{
      padding:2px 8px !important;
      font-size:11px !important;
      line-height:1.2 !important;
      border-radius:999px !important;
      white-space:nowrap;
    }

    /* Search */
    .sidebar-search{
      width:100%;
      border:1px solid var(--border-color);
      border-radius: 12px;
      padding: 9px 10px;
      font-size: 12px;
      outline:none;
      background: var(--bg-sidebar);
    }
    .sidebar-search:focus{
      border-color:#c7d2fe;
      box-shadow: 0 0 0 4px rgba(99,102,241,.12);
    }

    /* Sections + list */
    .sidebar-section{
      padding: 10px 12px 8px;
      font-size: 11px;
      color:#6b7280;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .5px;
    }
    .sidebar-list{
      flex: 1 1 auto;     /* take remaining height */
      min-height: 0;      /* ✅ REQUIRED so it can actually shrink and scroll */
      overflow-y: auto;   /* ✅ scroll rows */
      overflow-x: hidden;
      -webkit-overflow-scrolling: touch;
      padding-bottom: 8px;
      scroll-behavior: smooth;
    }
    

    /* Items */
    .sidebar-item-row{
      display:flex;
      align-items:flex-start;
      gap:0;
      border-bottom:1px solid #f1f5f9;
    }
    .sidebar-item-row.is-active{
      background:linear-gradient(90deg, rgba(99,102,241,.14), rgba(99,102,241,0));
      border-left:4px solid #6366f1;
    }
    html.dark-auto .sidebar-item-row.is-active,
    body.dark-auto .sidebar-item-row.is-active{
      background:linear-gradient(90deg, rgba(177,188,206,.14), rgba(177,188,206,0));
      border-left-color:#b1bcce;
    }
    .sidebar-item-row .sidebar-fries-wrap{
      flex:0 0 auto;
      padding:10px 10px 10px 0;
    }

    .sidebar-item{
      display:block;
      flex:1 1 auto;
      min-width:0;
      padding:10px 0 10px 12px;
      text-decoration:none;
      border-bottom:0;
      transition:background .15s ease;
    }
    .sidebar-item-row:not(.is-active) .sidebar-item:hover{ background:var(--bg-sidebar); }

    .sidebar-item.is-active,
    .sidebar-item-row.is-active .sidebar-item{
      padding-left:8px;
    }

    .sidebar-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }

    .sidebar-text{ min-width:0; }
    .sidebar-subject{
      font-weight: 900;
      color:#111827;
      font-size: 12px;
      line-height: 1.25;
      display:-webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow:hidden;
    }

    .sidebar-meta{
      margin-top: 6px;
      display:flex;
      gap:6px;
      flex-wrap:wrap;
      font-size: 11px;
      color:#6b7280;
      align-items:center;
    }

    .sidebar-chip{
      font-size:10px;
      padding:2px 8px;
      border-radius:999px;
      background: var(--bg-sidebar);
      color:#374151;
      font-weight: 900;
      border:1px solid var(--border-color);
    }

    .sidebar-badge-new{
      font-size:10px;
      padding:2px 8px;
      border-radius:999px;
      background: var(--bg-sidebar);
      color:#166534;
      font-weight: 900;
      border:1px solid var(--border-color);
    }

    .sidebar-tools{
      display:flex;
      align-items:center;
      gap:8px;
      flex:0 0 auto;
    }

    .sidebar-fries-wrap{
      position:relative;
      z-index:2;
      pointer-events:auto;
    }

    .sidebar-fries-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:30px;
      height:30px;
      padding:0;
      border:0;
      border-radius:0;
      background:transparent;
      color:#334155;
      cursor:pointer;
      flex:0 0 auto;
      line-height:1;
      pointer-events:auto;
      position:relative;
      z-index:3;
    }
    .sidebar-fries-btn:hover,
    .sidebar-fries-btn:focus{
      color:#111827;
      outline:none;
    }
    .sidebar-fries-icon{
      display:inline-flex;
      flex-direction:column;
      justify-content:center;
      align-items:flex-start;
      gap:3px;
      width:14px;
      color:currentColor;
    }
    .sidebar-fries-bar{
      display:block;
      height:2px;
      border-radius:1px;
      background:currentColor;
      width:14px;
    }
    .sidebar-fries-bar--short{ width:8px; }

    .sidebar-fries-menu{
      min-width:240px;
      background:#fff;
      border-radius:16px;
      box-shadow:0 4px 24px rgba(0,0,0,.15), 0 0 1px rgba(0,0,0,.1);
      padding:8px 0;
      display:none;
    }
    .sidebar-fries-menu.is-open{ display:block; }
    .sidebar-fries-menu.sidebar-fries-portal{
      position:fixed;
      z-index:100000;
      display:block;
    }
    .sidebar-fries-item{
      display:block;
      width:100%;
      padding:12px 16px;
      border:0;
      background:transparent;
      text-align:left;
      font-size:14px;
      font-weight:400;
      color:#262626;
      cursor:pointer;
      line-height:1.3;
    }
    .sidebar-fries-item:hover,
    .sidebar-fries-item:focus{
      background:#fafafa;
      outline:none;
    }
    .sidebar-fries-item.is-danger{ color:#ed4956; }
    .sidebar-fries-item:disabled{
      opacity:.45;
      cursor:default;
    }
    .sidebar-fries-item:disabled:hover,
    .sidebar-fries-item:disabled:focus{
      background:transparent;
    }
    .sidebar-fries-btn.is-open{
      opacity:.72;
    }

    .sidebar-video-pill{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:30px;
      height:30px;
      border-radius:999px;
      border:1px solid var(--border-color);
      background: var(--bg-sidebar);
      color:#111827;
      font-size:12px;
    }

    /* Pin button */
    .pin-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:30px;
      height:30px;
      border-radius:999px;
      border:1px solid var(--border-color);
      background: var(--bg-sidebar);
      color:#334155;
      text-decoration:none;
      transition: transform .12s ease, background .12s ease;
    }
    .pin-btn:hover{ background: var(--bg-sidebar); transform: translateY(-1px); }
    .pin-btn.pinned{
      border-color:#f59e0b;
      color:#b45309;
      background: var(--bg-sidebar);
    }

    /* Dark mode — History sidebar */
    .dark-auto .feed-sidebar{
      background:#171d24 !important;
      border-color:#334155;
      box-shadow: var(--shadow);
      height: 440px;
    }
    .dark-auto .sidebar-head{
      background:#171d24 !important;
      border-bottom-color:#334155;
    }

    .dark-auto .sidebar-title,
    .dark-auto .sidebar-title i,
    .dark-auto .sidebar-subtitle,
    .dark-auto .sidebar-section,
    .dark-auto .sidebar-meta,
    .dark-auto .sidebar-meta > span,
    .dark-auto .sidebar-subject{ color:#b1bcce !important; }

    .dark-auto .sidebar-refresh-btn,
    .dark-auto .sidebar-refresh-btn i{
      color:#e8edf5 !important;
      border-color:rgba(177,188,206,.48) !important;
      background:#252f3d !important;
    }
    .dark-auto .sidebar-refresh-btn:hover,
    .dark-auto .sidebar-refresh-btn:focus{
      color:#f3f6fb !important;
      background:#2f3a4a !important;
      border-color:rgba(177,188,206,.68) !important;
    }

    .dark-auto .sidebar-search{
      background:#171d24 !important;
      border-color:#334155;
      color:#b1bcce !important;
    }
    .dark-auto .sidebar-search::placeholder{
      color:#b1bcce !important;
      opacity:.72;
    }
    .dark-auto .sidebar-search:focus{
      border-color:rgba(177,188,206,.55);
      box-shadow:0 0 0 4px rgba(177,188,206,.12);
    }

    .dark-auto .sidebar-item{ border-bottom-color:#1f2937; }
    .dark-auto .sidebar-item-row{ border-bottom-color:#1f2937; }
    .dark-auto .sidebar-item-row:not(.is-active) .sidebar-item:hover{ background:#171d24; }

    .dark-auto .sidebar-chip{
      background:#171d24 !important;
      border-color:#334155;
      color:#b1bcce !important;
    }
    .dark-auto .sidebar-badge-new{
      background:#171d24 !important;
      border-color:rgba(177,188,206,.35);
      color:#b1bcce !important;
    }
    .dark-auto .pin-btn{
      background:#171d24;
      border-color:#334155;
      color:#b1bcce;
    }
    .dark-auto .sidebar-fries-btn,
    .dark-auto .sidebar-fries-btn:hover,
    .dark-auto .sidebar-fries-btn:focus{ color:#b1bcce !important; }
    .dark-auto .sidebar-fries-menu{
      background:#171d24;
      border:1px solid #334155;
      box-shadow:0 4px 24px rgba(0,0,0,.45), 0 0 1px rgba(255,255,255,.08);
    }
    .dark-auto .sidebar-fries-item{ color:#b1bcce !important; }
    .dark-auto .sidebar-fries-item:hover,
    .dark-auto .sidebar-fries-item:focus{ background:rgba(177,188,206,.08); color:#b1bcce !important; }
    .dark-auto .sidebar-fries-item.is-danger{ color:#ff6b7a !important; }
    

    @media (max-width: 992px){
      .feed-layout{ flex-direction:column; }
      .feed-sidebar{
        width:100%;
        flex:0 0 auto;
        position:relative;
        top:auto;
        max-height:none;
      }
      .feed-toolbar-side{
        width:100%;
        flex:0 0 auto;
        justify-content:flex-start;
      }
      .sidebar-list{ max-height:none; }
    }

    .feed-tabs{ display:flex; gap:8px; margin:6px 0 2px; flex-wrap:wrap; align-items:center; }
    .feed-tabs a{
      padding:5px 10px; border-radius:8px; text-decoration:none;
      border:1px solid var(--border-color); color:#6b7280; font-weight:700; background: var(--bg-sidebar);
      font-size:11px;
    }
    .feed-tabs a.active{ background: var(--bg-sidebar); color:#1a69b1; border-color:#0b5ed7; }
  
    /* ✅ Media strip (multi-attachment carousel) */
    .media-strip{
      display:flex; gap:8px; padding:8px; border-top:1px solid var(--border-color);
      overflow-x:auto; background:rgba(255,255,255,.02);
    }
    .media-tile{
      width:54px; height:54px; border-radius:12px; border:1px solid var(--border-color);
      background:var(--bg-sidebar); display:flex; align-items:center; justify-content:center;
      flex:0 0 auto; cursor:pointer; padding:0; position:relative;
      transition: transform .12s ease, border-color .12s ease;
    }
    .media-tile:hover{ transform: translateY(-1px); }
    .media-tile.active{ border-color:#0b5ed7; box-shadow: 0 0 0 2px rgba(11,94,215,.20); }
    .media-tile img{ width:100%; height:100%; object-fit:cover; border-radius:12px; display:block; }
    .media-tile .tile-icon{ font-size:18px; opacity:.9; }

  

    /* ==========================================================
       ✅ HIGH-CONTRAST OVERRIDES (LIGHT/DARK)
       Put at end of <style> so it wins over older hard-coded colors.
    ========================================================== */
    :root{
      --text-muted: #64748b;
    }
    html.dark-auto{
      --text-muted: #9ca3af;
    }

    /* Force base surfaces */
    body, .sh-mainpanel, .sh-pagebody, .dashboard-card{
      background: var(--bg-main) !important;
      color: var(--text-primary) !important;
    }

    /* Cards / panels */
    .feed-card,
    .stat-pill,
    .right-sidebar,
    .sidebar-card,
    .rm-card,
    .rm-card *{
      background: var(--bg-card) !important;
      border-color: var(--border-color) !important;
      color: var(--text-primary) !important;
    }

    /* Sidebar surface (if you use different class names, this still helps) */
    .sidebar-shell,
    .sidebar-wrap,
    .sidebar-panel{
      background: var(--bg-sidebar) !important;
      color: var(--text-primary) !important;
    }

    /* Titles / headings */
    .feed-title,
    .post-title,
    .rm-title,
    .rm-head h1,
    .rm-head h2,
    .rm-head h3{
      color: var(--text-primary) !important;
    }

    /* Body text */
    .feed-desc,
    .post-desc,
    .rm-body{
      color: var(--text-secondary) !important;
    }

    /* Muted text (dates, meta, helper text) */
    .feed-date,
    .feed-meta,
    .meta,
    .rm-date,
    .text-muted,
    .muted,
    .stat-pill .sub{
      color: var(--text-muted) !important;
    }

    /* Stat pill internal hardcoded colors */
    .stat-pill .num{ color: var(--text-primary) !important; }
    .stat-pill .lbl{ color: var(--text-secondary) !important; }

    /* Buttons / links */
    .link, .read-more-btn{
      color: var(--accent) !important;
    }

    /* Inputs */
    input, textarea, select{
      background: var(--bg-card) !important;
      color: var(--text-primary) !important;
      border-color: var(--border-color) !important;
    }
    input::placeholder, textarea::placeholder{
      color: var(--text-muted) !important;
      opacity: 1;
    }

    /* Modal overlay stays dark for both themes */
    .read-more-modal{
      background: rgba(0,0,0,0.65) !important;
    }

</style>
</head>

<body class="org-app org-page-feed" data-theme="<?= h($themeMode ?? 'auto') ?>">

<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <div class="sh-pagebody" style="padding:1px;">
    <div class="card bd-0 dashboard-card">
      <div class="card-body card-body-fixed">
        <div class="feed-toolbar">

          <?php if ($flashOk): ?>
            <div class="alert alert-success" style="margin-top:4px;margin-bottom:4px;"><?= h($flashOk) ?></div>
          <?php endif; ?>
          <?php if ($flashErr): ?>
            <div class="alert alert-danger" style="margin-top:4px;margin-bottom:4px;"><?= h($flashErr) ?></div>
          <?php endif; ?>

          <div class="dash-toprow feed-toolbar-split">
            <div class="feed-toolbar-main left-actions">
              <a href="feed.php" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-home mg-r-5"></i> Home
              </a>
              <a href="feed.php?tab=<?= h($tab) ?>" class="btn btn-sm btn-outline-secondary" title="Refresh unread count">
                <i class="fa fa-refresh mg-r-5"></i> Refresh
              </a>

              <?php if (is_managerish($meRole)): ?>
                <a href="compose_post.php" class="btn btn-sm btn-primary">
                  <i class="fa fa-paper-plane mg-r-5"></i> New announcement
                </a>
                <a href="create_staff.php" class="btn btn-sm btn-outline-secondary">
                  <i class="ion-person-add mg-r-5"></i> Create Staff
                </a>
                <a href="settings.php" class="btn btn-sm btn-outline-secondary">
                  <i class="ion-ios-gear mg-r-5"></i> Settings
                </a>
              <?php else: ?>
                <a href="members.php" class="btn btn-sm btn-outline-secondary">
                  <i class="ion-ios-people mg-r-5"></i> Members
                </a>
              <?php endif; ?>
              <?php
                require_once __DIR__ . '/includes/org_header_feed_tabs.php';
                org_render_feed_toolbar_tabs($tab, (int)$unreadPostsCount);
              ?>
            </div>
            <div class="feed-toolbar-side">
              <?php
                require_once __DIR__ . '/includes/org_header_feed_stats.php';
                org_render_feed_toolbar_stats($dbh, $orgId);
              ?>
            </div>
          </div>
        </div><!-- /.feed-toolbar -->

        <div class="rows-scroll">
          <div class="feed-layout<?= ($isView || !empty($currentPost)) ? ' feed-layout--tall' : '' ?>">
            <!-- ✅ Main post view area -->
            <div class="feed-main">
              <div class="feed-scroll">

                <?php if ($isView && $post): ?>
                <?php
                  $pid = (int)($post['id'] ?? 0);
                  $ptype = (string)($post['post_type'] ?? 'update');
                  $created = (string)($post['created_at'] ?? '');
                  $authorName = (string)($post['author_name'] ?? 'Unknown');
                  $authorOrgRole = (string)($post['author_org_role'] ?? ($post['author_role'] ?? 'member'));
                  $locked = ((int)($post['comments_locked'] ?? 0) === 1);
                  $iAck = ((int)($post['i_acknowledged'] ?? 0) === 1);
                  $ackCount = (int)($post['ack_count'] ?? 0);
                  $commentCount = (int)($post['comment_count'] ?? 0);

                  $title = post_subject_row($post);
                  $body  = (string)($post['body'] ?? '');
                ?>

                
                <div class="feed-card" id="post-<?= $pid ?>">
                  <?php
                    $imgSrc       = extract_first_image_src($body);
                    $attachments = fetch_post_attachments($dbh, $orgId, $pid);
                    $primary     = pick_primary_media($attachments, $imgSrc);

                    $desc    = extract_description($body);
                    $layout  = feed_post_card_layout($post, $primary);
                    $initial = strtoupper(mb_substr(trim($authorName), 0, 1));
                    if ($initial === '') $initial = 'M';
                  ?>
                  <div class="feed-post">
                    <div class="<?= h(feed_post_media_classes($layout)) ?>">
                      <?php if ($layout['text_in_media']): ?>

                        <?= render_feed_text_media_panel($layout['raw_title'], $layout['description']) ?>

                      <?php elseif (($primary['type'] ?? '') === 'image' && ($primary['src'] ?? '') !== ''): ?>

                        <img src="<?= h((string)$primary['src']) ?>" alt="<?= h((string)($primary['name'] ?? 'Image')) ?>">

                      <?php elseif (($primary['type'] ?? '') === 'video' && ($primary['src'] ?? '') !== ''): ?>

                        <video controls playsinline preload="metadata">
                          <source src="<?= h((string)$primary['src']) ?>" type="<?= h((string)($primary['mime'] ?? 'video/mp4')) ?>">
                          Your browser does not support the video tag.
                        </video>

                      <?php elseif (($primary['type'] ?? '') === 'pdf' && ($primary['src'] ?? '') !== ''): ?>

                      <?php
                        $pdfSrc = (string)$primary['src'];
                        $pdfEmbed = $pdfSrc;
                        // add viewer params if no hash exists
                        if (strpos($pdfEmbed, '#') === false) {
                          $pdfEmbed .= '#toolbar=0&navpanes=0&scrollbar=1&view=FitH';
                        }
                      ?>
                      <iframe
                        class="feed-doc-iframe"
                        src="<?= h($pdfEmbed) ?>"
                        title="<?= h((string)($primary['name'] ?? 'PDF')) ?>"
                        loading="lazy"
                        style="width:100%; height:100%; border:0; border-radius:1px; background:#171d24;">
                      </iframe>

                      <?php elseif (($primary['type'] ?? '') === 'ppt' && ($primary['src'] ?? '') !== ''): ?>

                    <?php
                      $raw = (string)$primary['src'];

                      // 1) Office viewer works only for public HTTPS URLs
                      $isHttps = (stripos($raw, 'https://') === 0);
                      $officeEmbed = $isHttps ? ('https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($raw)) : '';

                      // 2) If not HTTPS, try local converted PDF preview (same filename .pdf)
                      $localPdfWeb = (!$isHttps) ? find_local_ppt_pdf_webpath($raw) : '';
                      $pdfEmbed2 = $localPdfWeb;
                      if ($pdfEmbed2 !== '' && strpos($pdfEmbed2, '#') === false) {
                          $pdfEmbed2 .= '#toolbar=0&navpanes=0&scrollbar=1&view=FitH';
                      }
                    ?>

                    <?php if ($officeEmbed !== ''): ?>
                      <iframe class="feed-doc-iframe"
                              src="<?= h($officeEmbed) ?>"
                              title="<?= h((string)($primary['name'] ?? 'PowerPoint')) ?>"
                              loading="lazy"
                              style="width:100%; height:100%; border:0; border-radius:14px; background:#171d24;"></iframe>

                    <?php elseif ($pdfEmbed2 !== ''): ?>
                      <iframe class="feed-doc-iframe"
                              src="<?= h($pdfEmbed2) ?>"
                              title="<?= h((string)($primary['name'] ?? 'PowerPoint (PDF Preview)')) ?>"
                              loading="lazy"
                              style="width:100%; height:100%; border:0; border-radius:14px; background:#171d24;"></iframe>

                    <?php else: ?>
                      <div class="media-fallback">
                        <div>
                          <div style="font-size:44px; line-height:1; margin-bottom:10px;"><i class="fa fa-file-powerpoint-o"></i></div>
                          <div style="font-size:13px; opacity:.9; margin-bottom:8px;">
                            PowerPoint preview needs a public HTTPS link (or a local PDF conversion)
                          </div>
                          <a href="<?= h($raw) ?>" target="_blank" rel="noopener"
                            style="color:#fff; text-decoration:underline; font-weight:900;">
                            <?= h((string)($primary['name'] ?? 'Open PPTX')) ?>
                          </a>
                        </div>
                      </div>
                    <?php endif; ?>


                    <?php elseif (($primary['type'] ?? '') !== 'none' && ($primary['src'] ?? '') !== ''): ?>

                      <div class="media-fallback">
                        <div>
                          <div style="font-size:44px; line-height:1; margin-bottom:10px;"><i class="fa fa-paperclip"></i></div>
                          <div style="font-size:13px; opacity:.9; margin-bottom:8px;">Attachment</div>
                          <a href="<?= h((string)$primary['src']) ?>" target="_blank" rel="noopener"
                            style="color:#fff; text-decoration:underline; font-weight:900;">
                            <?= h((string)($primary['name'] ?? 'Open file')) ?>
                          </a>
                        </div>
                      </div>

                    <?php else: ?>

                      <div class="media-fallback">
                        <div>
                          <div style="font-size:44px; line-height:1; margin-bottom:10px;"><i class="fa fa-image"></i></div>
                          <div style="font-size:13px; opacity:.9;">No media attached</div>
                        </div>
                      </div>

                    <?php endif; ?>
                  </div>


                  <div class="feed-post-detail">
                    <div class="feed-detail-head">
                      <div class="feed-author">
                        <div class="feed-avatar"><?= h($initial) ?></div>
                        <div class="feed-author-lines">
                          <div class="feed-author-name">
                            <?= h($authorName) ?>
                            <span class="feed-badge" style="margin-left:8px;"><?= h(post_label($ptype)) ?></span>
                          </div>
                          <div class="feed-author-meta">
                            <span>Posted <?= h($created) ?></span>
                            <span>•</span>
                            <span><?= h($authorOrgRole) ?></span>
                          </div>
                        </div>
                      </div>
                      <div class="feed-head-tools">
                        <?php if ($locked): ?>
                          <span class="feed-badge" style="background: var(--bg-sidebar);">Comments Closed</span>
                        <?php endif; ?>
                        <?= render_feed_head_pin_button($pid, $tab, $_SESSION[$pinKey] ?? []) ?>
                        <a class="btn btn-sm btn-outline-secondary" href="feed.php?id=<?= (int)$pid ?>&tab=<?= h($tab) ?>" title="Full View">
                          <i class="fa fa-folder-open"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-secondary js-open-comments-door" data-pid="<?= (int)$pid ?>" title="Comments">
                          <i class="fa fa-comments"></i>
                        </button>
                      </div>
                    </div>

                    <?php if ($layout['text_in_media']): ?>
                    <div class="feed-inline-comments" id="feedInlineComments-<?= (int)$pid ?>">
                      <?= render_feed_inline_comments_html($dbh, $orgId, $pid, $hasReplyColumn) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($layout['has_media']): ?>
                    <div class="feed-title"><?= h($layout['detail_title']) ?></div>
                    <?php if ($layout['description'] !== ''): ?>
                    <?= render_feed_description_block($layout['description'], $layout['detail_title'], $created) ?>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div class="feed-actions">
                      <form method="post" action="feed.php?id=<?= (int)$pid ?>&tab=<?= h($tab) ?>" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="ack">
                        <input type="hidden" name="post_id" value="<?= $pid ?>">
                        <input type="hidden" name="back_to" value="<?= h('feed.php?id='.$pid.'&tab='.$tab) ?>">
                        <button type="submit" class="btn btn-sm <?= $iAck ? 'btn-success' : 'btn-outline-success' ?>">
                          <i class="fa fa-lightbulb-o mg-r-5"></i> <?= $iAck ? 'Liked' : 'Like' ?>
                        </button>
                      </form>

                      <button type="button" class="mini-muted js-open-comments-door" data-pid="<?= (int)$pid ?>" style="border:0;background:transparent;padding:0;font:inherit;font-weight:900;">
                        <i class="fa fa-comments"></i>
                        <span class="jsReplyCount" data-pid="<?= (int)$pid ?>"><?= (int)$commentCount ?></span> comments
                      </button>

                      <div class="feed-actions-right">
                        <button type="button" class="feed-viewall js-open-comments-door" data-pid="<?= (int)$pid ?>" style="border:0;background:transparent;padding:0;font:inherit;">
                          View all <?= (int)$commentCount ?> comments
                        </button>
                        <span class="mini-muted feed-actions-liked"><i class="fa fa-lightbulb-o"></i> <?= $ackCount ?> liked</span>
                      </div>
                    </div>

                    <?= render_feed_compose_form(
                      $pid,
                      $tab,
                      $csrf,
                      $locked,
                      'feed.php?id=' . (int)$pid . '&tab=' . rawurlencode($tab),
                      'feed.php?id=' . (int)$pid . '&tab=' . rawurlencode($tab)
                    ) ?>

                  </div>
                </div>
              </div>

              <?php elseif (!empty($currentPost)): ?>
                <?php
                  $pid = (int)($currentPost['id'] ?? 0);
                  $ptype = (string)($currentPost['post_type'] ?? 'update');
                  $created = (string)($currentPost['created_at'] ?? '');
                  $authorName = (string)($currentPost['author_name'] ?? 'Unknown');
                  $authorOrgRole = (string)($currentPost['author_org_role'] ?? ($currentPost['author_role'] ?? 'member'));
                  $locked = ((int)($currentPost['comments_locked'] ?? 0) === 1);
                  $iAck = ((int)($currentPost['i_acknowledged'] ?? 0) === 1);
                  $ackCount = (int)($currentPost['ack_count'] ?? 0);
                  $commentCount = (int)($currentPost['comment_count'] ?? 0);

                  $title = post_subject_row($currentPost);
                  $body  = (string)($currentPost['body'] ?? '');
                ?>

                
                <div class="feed-card" id="post-<?= $pid ?>">
                  <?php
                    $imgSrc       = extract_first_image_src($body);
                    $attachments = fetch_post_attachments($dbh, $orgId, $pid);
                    $primary     = pick_primary_media($attachments, $imgSrc);

                    $desc    = extract_description($body);
                    $layout  = feed_post_card_layout($currentPost, $primary);
                    $initial = strtoupper(mb_substr(trim($authorName), 0, 1));
                    if ($initial === '') $initial = 'M';
                  ?>
                  <div class="feed-post">
                    <div class="<?= h(feed_post_media_classes($layout)) ?>">
                      <?php if ($layout['text_in_media']): ?>

                        <?= render_feed_text_media_panel($layout['raw_title'], $layout['description']) ?>

                      <?php elseif (($primary['type'] ?? '') === 'image' && ($primary['src'] ?? '') !== ''): ?>
                        <img src="<?= h((string)$primary['src']) ?>" alt="<?= h((string)($primary['name'] ?? 'Image')) ?>">
                      <?php elseif (($primary['type'] ?? '') === 'video' && ($primary['src'] ?? '') !== ''): ?>
                        <video controls playsinline preload="metadata">
                          <source src="<?= h((string)$primary['src']) ?>" type="<?= h((string)($primary['mime'] ?? 'video/mp4')) ?>">
                          Your browser does not support the video tag.
                        </video>
                      <?php elseif (($primary['type'] ?? '') !== 'none' && ($primary['src'] ?? '') !== ''): ?>
                        <div class="media-fallback">
                          <div>
                            <div style="font-size:44px; line-height:1; margin-bottom:10px;"><i class="fa fa-paperclip"></i></div>
                            <div style="font-size:13px; opacity:.9; margin-bottom:8px;">Attachment</div>
                            <a href="<?= h((string)$primary['src']) ?>" target="_blank" rel="noopener" style="color:#fff; text-decoration:underline; font-weight:900;">
                              <?= h((string)($primary['name'] ?? 'Open file')) ?>
                            </a>
                          </div>
                        </div>
                      <?php else: ?>
                        <div class="media-fallback">
                          <div>
                            <div style="font-size:44px; line-height:1; margin-bottom:10px;"><i class="fa fa-image"></i></div>
                            <div style="font-size:13px; opacity:.9;">No media attached</div>
                          </div>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="feed-post-detail">
                      <div class="feed-detail-head">
                        <div class="feed-author">
                          <div class="feed-avatar"><?= h($initial) ?></div>
                          <div class="feed-author-lines">
                            <div class="feed-author-name">
                              <?= h($authorName) ?>
                              <span class="feed-badge" style="margin-left:8px;"><?= h(post_label($ptype)) ?></span>
                            </div>
                            <div class="feed-author-meta">
                              <span>Posted <?= h($created) ?></span>
                              <span>•</span>
                              <span><?= h($authorOrgRole) ?></span>
                            </div>
                          </div>
                        </div>
                        <div class="feed-head-tools">
                          <?php if ($locked): ?>
                            <span class="feed-badge" style="background: var(--bg-sidebar);">Comments Closed</span>
                          <?php endif; ?>
                          <?= render_feed_head_pin_button($pid, $tab, $_SESSION[$pinKey] ?? []) ?>
                          <a class="btn btn-sm btn-outline-secondary" href="feed.php?id=<?= (int)$pid ?>&tab=<?= h($tab) ?>" title="Full View">
                            <i class="fa fa-folder-open"></i>
                          </a>
                          <button type="button" class="btn btn-sm btn-outline-secondary js-open-comments-door" data-pid="<?= (int)$pid ?>" title="Comments">
                            <i class="fa fa-comments"></i>
                          </button>
                        </div>
                      </div>

                      <?php if ($layout['text_in_media']): ?>
                      <div class="feed-inline-comments" id="feedInlineComments-<?= (int)$pid ?>">
                        <?= render_feed_inline_comments_html($dbh, $orgId, $pid, $hasReplyColumn) ?>
                      </div>
                      <?php endif; ?>

                      <?php if ($layout['has_media']): ?>
                      <div class="feed-title"><?= h($layout['detail_title']) ?></div>
                      <?php if ($layout['description'] !== ''): ?>
                      <?= render_feed_description_block($layout['description'], $layout['detail_title'], $created) ?>
                      <?php endif; ?>
                      <?php endif; ?>

                      <div class="feed-actions">
                        <form method="post" action="feed.php?tab=<?= h($tab) ?>" style="margin:0;">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="ack">
                          <input type="hidden" name="post_id" value="<?= $pid ?>">
                          <input type="hidden" name="back_to" value="<?= h('feed.php?tab='.$tab) ?>">
                          <button type="submit" class="btn btn-sm <?= $iAck ? 'btn-success' : 'btn-outline-success' ?>">
                            <i class="fa fa-lightbulb-o mg-r-5"></i> <?= $iAck ? 'Liked' : 'Like' ?>
                          </button>
                        </form>

                        <button type="button" class="mini-muted js-open-comments-door" data-pid="<?= (int)$pid ?>" style="border:0;background:transparent;padding:0;font:inherit;font-weight:900;">
                          <i class="fa fa-comments"></i>
                          <span class="jsReplyCount" data-pid="<?= (int)$pid ?>"><?= (int)$commentCount ?></span> comments
                        </button>

                        <div class="feed-actions-right">
                          <button type="button" class="feed-viewall js-open-comments-door" data-pid="<?= (int)$pid ?>" style="border:0;background:transparent;padding:0;font:inherit;">
                            View all <?= (int)$commentCount ?> comments
                          </button>
                          <span class="mini-muted feed-actions-liked"><i class="fa fa-lightbulb-o"></i> <?= $ackCount ?> liked</span>
                        </div>
                      </div>

                      <?= render_feed_compose_form(
                        $pid,
                        $tab,
                        $csrf,
                        $locked,
                        'feed.php?tab=' . rawurlencode($tab),
                        'feed.php?tab=' . rawurlencode($tab)
                      ) ?>

                    </div>
                  </div>
                </div>
                <?php else: ?>
                <div class="feed-card"><div class="mini-muted">No posts yet.</div></div>
                  <?php endif; ?>
                  <!-- <div class="feed-tabs">
                    <a class="<?= $tab==='work'?'active':'' ?>" href="feed.php?tab=work">Work</a>
                    <a class="<?= $tab==='culture'?'active':'' ?>" href="feed.php?tab=culture">Culture</a>
                    <a class="<?= $tab==='all'?'active':'' ?>" href="feed.php?tab=all">All</a>
                  </div> -->
                </div>
            </div>

            <!-- ✅ Right Sidebar: clean + beautiful -->
            <aside class="feed-sidebar" aria-label="Post history">

              <?php
                $activeRow    = $isView ? ($post ?? []) : ($currentPost ?? []);
                $activeTitle  = $activeRow ? post_subject_row($activeRow) : '';
                $activeType   = (string)($activeRow['post_type'] ?? 'update');
                $activeReplies = $isView
                  ? (int)($_SESSION[$seenKey][$activePostId] ?? 0)
                  : (int)($activeRow['comment_count'] ?? 0);
              ?>

              <div class="sidebar-head">
                <div class="sidebar-titlebar">
                  <div class="sidebar-title">
                    <i class="fa fa-clock-o"></i> History
                  </div>
                  <div class="sidebar-head-actions">
                    <a href="feed.php?tab=<?= h($tab) ?>&refresh=1<?= $isView && $postId>0 ? '&id='.(int)$postId : '' ?>"
                       class="btn btn-sm btn-outline-secondary sidebar-refresh-btn"
                       title="Refresh feed">
                      <i class="fa fa-refresh mg-r-5"></i> Refresh
                    </a>
                    <div class="sidebar-subtitle">Old subjects</div>
                  </div>
                </div>

                <input id="sidebarSearch" class="sidebar-search" type="text" placeholder="Search history…" autocomplete="off">
              </div>

              <div class="sidebar-list">

                <?php if ($activePostId > 0 && $activeTitle !== ''): ?>
                  <div class="sidebar-section">Currently viewing</div>

                  <div class="sidebar-item-row is-active">
                    <a class="sidebar-item is-active"
                       href="feed.php?id=<?= (int)$activePostId ?>&tab=<?= h($tab) ?>"
                       data-subject="<?= h(strtolower((string)$activeTitle)) ?>">

                      <div class="sidebar-top">
                        <div class="sidebar-text">
                          <div class="sidebar-subject"><?= h($activeTitle) ?></div>
                          <div class="sidebar-meta">
                            <span class="sidebar-chip"><?= h(post_label($activeType)) ?></span>
                            <span class="sidebar-chip"><?= (int)$activeReplies ?> replies</span>
                          </div>
                        </div>
                      </div>
                    </a>
                    <?= render_sidebar_fries_button(
                      (int)$activePostId,
                      $tab,
                      $_SESSION[$pinKey] ?? [],
                      (int)$activePostId,
                      (string)($ORG['name'] ?? 'Organization')
                    ) ?>
                  </div>
                <?php endif; ?>

                <div class="sidebar-section">Recent history</div>

                <?php if (!$sidebarPosts): ?>
                  <div class="mini-muted" style="padding:10px 12px;">No previous posts yet.</div>
                <?php else: ?>
                  <?php foreach ($sidebarPosts as $sp): ?>
                    <?php
                      $sid    = (int)($sp['id'] ?? 0);
                      $stype  = (string)($sp['post_type'] ?? 'update');
                      $stitle = post_subject_row($sp);
                      $sdt    = (string)($sp['created_at'] ?? '');
                      $scnt   = (int)($sp['comment_count'] ?? 0);
                      $sdts   = $sdt !== '' ? strtotime($sdt) : time();

                      $seen = $_SESSION[$seenKey][$sid] ?? null; // int|null
                      $isNew = ($seen !== null && (int)$scnt > (int)$seen);
                    ?>

                    <div class="sidebar-item-row<?= ($sid > 0 && $sid === (int)$activePostId) ? ' is-active' : '' ?>">
                      <a class="sidebar-item<?= ($sid > 0 && $sid === (int)$activePostId) ? ' is-active' : '' ?>"
                         href="feed.php?id=<?= $sid ?>&tab=<?= h($tab) ?>"
                         data-subject="<?= h(strtolower((string)$stitle)) ?>">

                        <div class="sidebar-top">
                          <div class="sidebar-text">
                            <div class="sidebar-subject"><?= h($stitle) ?></div>
                            <div class="sidebar-meta">
                              <span><?= h(date('M j, Y g:ia', $sdts)) ?></span>
                              <span class="sidebar-chip"><?= h(post_label($stype)) ?></span>
                              <span class="sidebar-chip"><?= (int)$scnt ?> replies</span>
                              <?php if ($isNew): ?>
                                <span class="sidebar-badge-new">NEW</span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                      </a>
                      <?= render_sidebar_fries_button(
                        $sid,
                        $tab,
                        $_SESSION[$pinKey] ?? [],
                        (int)$activePostId,
                        (string)($ORG['name'] ?? 'Organization')
                      ) ?>
                    </div>

                  <?php endforeach; ?>
                <?php endif; ?>

              </div>
            </aside>
          </div>
<!-- end -->
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>

</div>


<!-- ✅ Manager-only: Edit post modal -->
<div class="modal fade modal-clean" id="editPostModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">

      <div class="modal-header">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <span class="modal-badge">Edit Post</span>
          <span class="mini-muted" id="epMeta"></span>
        </div>

        <button type="button" class="btn-close-x" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true" style="font-size:22px; font-weight:900;">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <form method="post" id="epForm">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="edit_post">
          <input type="hidden" name="post_id" id="epPostId" value="0">
          <input type="hidden" name="back_to" value="<?= h('feed.php?id='.$activePostId.'&tab='.$tab) ?>">

          <div class="form-group">
            <label style="font-weight:900; font-size:12px;">Title</label>
            <input type="text" class="form-control" name="edit_title" id="epTitle" maxlength="255" placeholder="Post title">
          </div>

          <div class="form-group" style="margin-top:10px;">
            <label style="font-weight:900; font-size:12px;">Body</label>
            <textarea class="form-control" name="edit_body" id="epBody" rows="10" style="min-height:220px;"></textarea>
            <div class="mini-muted" style="margin-top:6px;">
              Tip: this field supports HTML if you paste it (your original post body is stored as HTML).
            </div>
          </div>

          <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
            <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa fa-save mg-r-5"></i> Save Changes
            </button>
          </div>
        </form>
      </div>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/org_comments_door.php'; ?>

<?php org_layout_footer_assets(); ?>

<script>
  
// ✅ Manager-only: open Edit modal (no page reload)
function openEditPostModal(postId, title, bodyHtml){
  try {
    document.getElementById('epPostId').value = String(postId || 0);
    document.getElementById('epTitle').value  = title || '';
    document.getElementById('epBody').value   = bodyHtml || '';
    document.getElementById('epMeta').textContent = 'Post #' + String(postId || 0);
    $('#editPostModal').modal('show');
  } catch(e) {
    console.log('Edit modal error', e);
  }
}

  // ✅ Inline compose under post card
  function scrollFeedInlineCommentsToBottom(box, highlightLast){
    if (!box) return;
    box.scrollTop = box.scrollHeight;
    if (!highlightLast) return;
    var items = box.querySelectorAll('.comment-item');
    if (!items.length) return;
    var last = items[items.length - 1];
    last.classList.add('comment-item--new');
    window.setTimeout(function(){
      last.classList.remove('comment-item--new');
    }, 2400);
  }

  function refreshFeedInlineComments(pid, highlightLast){
    pid = parseInt(pid || '0', 10);
    if (pid <= 0) return Promise.resolve();
    var box = document.getElementById('feedInlineComments-' + pid);
    if (!box) return Promise.resolve();
    return fetch('feed.php?ajax=inline_comments&pid=' + encodeURIComponent(pid), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function(r){ return r.text(); })
      .then(function(txt){
        var resp = null;
        try { resp = JSON.parse(txt); } catch (err) {}
        if (!resp || !resp.ok) return;
        box.innerHTML = resp.html || '<div class="mini-muted">No comments yet.</div>';
        scrollFeedInlineCommentsToBottom(box, !!highlightLast);
      });
  }

  function updateFeedInlineComments(pid, html, highlightLast){
    var box = document.getElementById('feedInlineComments-' + pid);
    if (!box) return;
    box.innerHTML = html || '<div class="mini-muted">No comments yet.</div>';
    scrollFeedInlineCommentsToBottom(box, !!highlightLast);
  }

  document.addEventListener('submit', function(e){
    var form = e.target.closest('.jsFeedComposeForm');
    if (!form) return;
    e.preventDefault();

    var ta = form.querySelector('.feed-compose-input');
    var body = ta ? String(ta.value || '').trim() : '';
    if (!body) {
      if (ta) ta.focus();
      return;
    }

    var pid = parseInt(form.getAttribute('data-pid') || '0', 10);
    var fd = new FormData(form);
    fd.append('ajax', '1');

    var btn = form.querySelector('.feed-compose-send');
    if (btn) btn.disabled = true;

    fetch(form.getAttribute('action') || 'feed.php', { method: 'POST', body: fd })
      .then(function(r){ return r.text(); })
      .then(function(txt){
        var resp = null;
        try { resp = JSON.parse(txt); } catch (err) {}

        if (!resp || !resp.ok) {
          alert((resp && resp.err) ? resp.err : ('Failed to post comment.\n\nServer says:\n' + txt));
          return;
        }

        if (ta) ta.value = '';

        if (pid > 0) {
          if (resp.count !== undefined) {
            document.querySelectorAll('.jsReplyCount[data-pid="'+pid+'"]').forEach(function(span){
              span.textContent = resp.count;
            });
            document.querySelectorAll('.feed-viewall[data-pid="'+pid+'"]').forEach(function(el){
              el.textContent = 'View all ' + resp.count + ' comments';
            });
          }
          if (window.OrgCommentsDoor && typeof window.OrgCommentsDoor.refreshIfOpen === 'function') {
            window.OrgCommentsDoor.refreshIfOpen(pid);
          }
          if (resp.inline_html !== undefined) {
            updateFeedInlineComments(pid, resp.inline_html, true);
          } else {
            refreshFeedInlineComments(pid, true);
          }
        }
      })
      .catch(function(){
        alert('Failed to post comment. Please try again.');
      })
      .finally(function(){
        if (btn) btn.disabled = false;
        if (ta && !ta.disabled) ta.focus();
      });
  });
</script>


<script>
(function(){
  var card = document.querySelector('.feed-card[data-post-id]');
  if (!card) return;

  var pid = card.getAttribute('data-post-id');
  var tab = card.getAttribute('data-tab') || 'work';

  var main = document.getElementById('mainMedia');
  if (!main) return;

  var unreadNum = document.getElementById('unreadPostsNum');
  var marked = false;

  function keyFor(src){ return 'org_feed_vidpos:' + pid + ':' + src; }

  function saveCurrentVideo(){
    var v = main.querySelector('video');
    if (v && !isNaN(v.currentTime)) {
      try { localStorage.setItem(keyFor(v.currentSrc || ''), String(v.currentTime)); } catch(e){}
      try { v.pause(); } catch(e){}
    }
  }

  function restoreVideo(v){
    if (!v) return;
    var src = v.currentSrc || '';
    try {
      var t = parseFloat(localStorage.getItem(keyFor(src)) || '0');
      if (!isNaN(t) && t > 0) {
        // wait a tick so metadata is ready
        setTimeout(function(){
          try { v.currentTime = t; } catch(e){}
        }, 50);
      }
    } catch(e){}
  }

  function markRead(){
    if (marked) return;
    marked = true;
    fetch('feed.php?ajax=mark_read&pid=' + encodeURIComponent(pid) + '&tab=' + encodeURIComponent(tab), { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok && unreadNum && typeof j.unread !== 'undefined') {
          unreadNum.textContent = String(j.unread);
        }
      })
      .catch(function(){ /* ignore */ });
  }

  function attachLoadHandlers(){
    // mark read AFTER media actually loads
    var img = main.querySelector('img');
    var vid = main.querySelector('video');
    var ifr = main.querySelector('iframe');

    if (img) {
      if (img.complete) { markRead(); }
      else img.addEventListener('load', markRead, { once:true });
      img.addEventListener('error', function(){ /* don't mark on error */ }, { once:true });
    } else if (vid) {
      vid.addEventListener('loadeddata', function(){
        restoreVideo(vid);
        markRead();
      }, { once:true });

      // save position occasionally
      var lastSave = 0;
      vid.addEventListener('timeupdate', function(){
        var now = Date.now();
        if (now - lastSave > 1500) {
          lastSave = now;
          try { localStorage.setItem(keyFor(vid.currentSrc || ''), String(vid.currentTime)); } catch(e){}
        }
      });
      vid.addEventListener('pause', function(){
        try { localStorage.setItem(keyFor(vid.currentSrc || ''), String(vid.currentTime)); } catch(e){}
      });
    } else if (ifr) {
      ifr.addEventListener('load', markRead, { once:true });
    } else {
      // fallback (no media)
      markRead();
    }
  }

  // initial attach
  attachLoadHandlers();

  // carousel switching
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.media-tile');
    if (!btn) return;

    e.preventDefault();

    // pause/save any playing video before switching
    saveCurrentVideo();

    // active state
    document.querySelectorAll('.media-tile[data-post="' + pid + '"]').forEach(function(b){
      b.classList.remove('active');
    });
    btn.classList.add('active');

    var type = btn.getAttribute('data-type') || 'file';
    var src  = btn.getAttribute('data-src') || '';
    var mime = btn.getAttribute('data-mime') || '';

    marked = false; // switching media should re-trigger markRead only once (already read anyway, harmless)

    if (!src) return;

    // render into main
    if (type === 'image') {
      main.innerHTML = '<img src="' + src.replace(/"/g,'&quot;') + '" alt="">';
    } else if (type === 'video') {
      var t = mime || 'video/mp4';
      main.innerHTML = '<video controls playsinline preload="metadata"><source src="' + src.replace(/"/g,'&quot;') + '" type="' + t.replace(/"/g,'&quot;') + '">Your browser does not support the video tag.</video>';
    } else if (type === 'pdf') {
      var emb = src;
      if (emb.indexOf('#') === -1) emb += '#toolbar=0&navpanes=0&scrollbar=0&view=FitH';
      main.innerHTML = '<iframe class="feed-doc-iframe" src="' + emb.replace(/"/g,'&quot;') + '" title="PDF" loading="lazy"></iframe>';
    } else {
      main.innerHTML = '<div class="doc-preview"><div class="doc-inner"><div class="doc-icon"><i class="fa fa-paperclip"></i></div><div class="doc-name">Attachment</div><div class="doc-actions"><a class="doc-btn" href="' + src.replace(/"/g,'&quot;') + '" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Open</a></div></div></div>';
    }

    // re-attach handlers (mark read after load, restore video pos)
    attachLoadHandlers();
  });

  // save video position on navigation
  window.addEventListener('beforeunload', function(){
    saveCurrentVideo();
  });
})();
</script>

<script>
(function(){
  var input = document.getElementById('sidebarSearch');
  if (!input) return;

  // ✅ only filter rows that have data-subject
  var rows = Array.prototype.slice.call(document.querySelectorAll('.feed-sidebar [data-subject]'));
  function norm(s){ return (s||'').toString().toLowerCase().trim(); }

  input.addEventListener('input', function(){
    var q = norm(input.value);
    rows.forEach(function(a){
      var subj = norm(a.getAttribute('data-subject'));
      var row = a.closest ? a.closest('.sidebar-item-row') : null;
      var target = row || a;
      target.style.display = (!q || subj.indexOf(q) !== -1) ? '' : 'none';
    });
  });

})();

</script>

<script>
(function(){
  if (window.__orgSidebarFriesInit) return;
  window.__orgSidebarFriesInit = true;

  var activePortal = null;
  var activeBtn = null;
  var activeWrap = null;
  var ignoreCloseUntil = 0;

  function matchesSel(el, sel){
    if (!el || el.nodeType !== 1) return false;
    if (el.matches) return el.matches(sel);
    if (el.msMatchesSelector) return el.msMatchesSelector(sel);
    return false;
  }

  function closestEl(el, sel){
    while (el && el.nodeType === 1){
      if (matchesSel(el, sel)) return el;
      el = el.parentElement;
    }
    return null;
  }

  function absPostUrl(wrap){
    var rel = (wrap && wrap.getAttribute('data-post-url')) || '';
    try { return new URL(rel, window.location.href).href; }
    catch(e){ return window.location.href; }
  }

  function closeSidebarFriesMenu(){
    if (activePortal && activePortal.parentNode){
      try { activePortal.parentNode.removeChild(activePortal); } catch(e){}
    }
    if (activeBtn){
      activeBtn.classList.remove('is-open');
      activeBtn.setAttribute('aria-expanded', 'false');
    }
    activePortal = null;
    activeBtn = null;
    activeWrap = null;
  }

  function positionSidebarFriesMenu(btn, menu){
    var rect = btn.getBoundingClientRect();
    var mw = menu.offsetWidth || 240;
    var mh = menu.offsetHeight || 80;
    var gap = 6;
    var top = rect.bottom + gap;
    var left = rect.right - mw;
    var vw = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
    var vh = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);

    if (left < 10) left = 10;
    if (left + mw > vw - 10) left = Math.max(10, vw - mw - 10);
    if (top + mh > vh - 10) top = Math.max(10, rect.top - gap - mh);

    menu.style.top = top + 'px';
    menu.style.left = left + 'px';
  }

  function flashSidebarToast(msg){
    var el = document.getElementById('sidebarFriesToast');
    if (!el){
      el = document.createElement('div');
      el.id = 'sidebarFriesToast';
      el.setAttribute('role', 'status');
      el.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:#262626;color:#fff;padding:10px 16px;border-radius:999px;font-size:13px;font-weight:600;z-index:100001;opacity:0;transition:opacity .2s ease;pointer-events:none;';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.style.opacity = '1';
    clearTimeout(el._hideTimer);
    el._hideTimer = setTimeout(function(){ el.style.opacity = '0'; }, 1800);
  }

  function copyText(text){
    if (navigator.clipboard && navigator.clipboard.writeText){
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function(resolve, reject){
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        resolve();
      } catch(err){ reject(err); }
    });
  }

  function handleSidebarFriesAction(action, wrap){
    if (!wrap) return;
    var pid = wrap.getAttribute('data-pid') || '';
    var postUrl = wrap.getAttribute('data-post-url') || '';
    var unpinUrl = wrap.getAttribute('data-unpin-url') || '';
    var orgName = wrap.getAttribute('data-org-name') || 'Organization';
    var fullUrl = absPostUrl(wrap);

    if (action === 'report'){
      flashSidebarToast('Thanks for your report.');
      return;
    }
    if (action === 'unpin' && unpinUrl){
      window.location.href = unpinUrl;
      return;
    }
    if (action === 'about'){
      window.alert('About this account\n\n' + orgName + '\nPost #' + pid);
      return;
    }
    if (action === 'goto' && postUrl){
      window.location.href = postUrl;
      return;
    }
    if (action === 'share'){
      if (navigator.share){
        navigator.share({ title: orgName, url: fullUrl }).catch(function(){});
      } else {
        copyText(fullUrl).then(function(){
          flashSidebarToast('Link copied to clipboard.');
        });
      }
      return;
    }
    if (action === 'copy'){
      copyText(fullUrl).then(function(){
        flashSidebarToast('Link copied to clipboard.');
      });
      return;
    }
    if (action === 'embed'){
      var code = '<iframe src="' + fullUrl + '" width="400" height="480" frameborder="0" allowfullscreen></iframe>';
      copyText(code).then(function(){
        flashSidebarToast('Embed code copied.');
      });
    }
  }

  function bindSidebarFriesPortal(menu, wrap){
    menu.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      var item = closestEl(e.target, '[data-action]');
      if (!item || item.disabled) return;
      handleSidebarFriesAction(item.getAttribute('data-action'), wrap);
      closeSidebarFriesMenu();
    });
  }

  function openSidebarFriesMenu(wrap, btn){
    var sourceMenu = wrap ? wrap.querySelector('.sidebar-fries-menu') : null;
    if (!sourceMenu || !btn) return;

    if (activeWrap === wrap){
      closeSidebarFriesMenu();
      return;
    }

    closeSidebarFriesMenu();

    var portal = sourceMenu.cloneNode(true);
    portal.classList.add('sidebar-fries-portal', 'is-open');
    portal.removeAttribute('hidden');
    portal.style.display = 'block';
    portal.style.visibility = 'hidden';
    document.body.appendChild(portal);
    positionSidebarFriesMenu(btn, portal);
    portal.style.visibility = 'visible';

    bindSidebarFriesPortal(portal, wrap);

    activePortal = portal;
    activeBtn = btn;
    activeWrap = wrap;
    btn.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    ignoreCloseUntil = Date.now() + 250;
  }

  document.addEventListener('click', function(e){
    var btn = closestEl(e.target, '.sidebar-fries-btn');
    if (btn){
      e.preventDefault();
      e.stopPropagation();
      openSidebarFriesMenu(closestEl(btn, '.sidebar-fries-wrap'), btn);
      return;
    }
    if (Date.now() < ignoreCloseUntil) return;
    if (closestEl(e.target, '.sidebar-fries-wrap') || closestEl(e.target, '.sidebar-fries-portal')) return;
    closeSidebarFriesMenu();
  }, true);

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeSidebarFriesMenu();
  });
  window.addEventListener('resize', closeSidebarFriesMenu);
  var sidebarListEl = document.querySelector('.sidebar-list');
  if (sidebarListEl) {
    sidebarListEl.addEventListener('scroll', closeSidebarFriesMenu, { passive: true });
  }
})();

</script>

<script>
// -------------------- Feed UX: mark read after media loads + video memory + strip switching --------------------
(function(){
  var playback = {}; // {pid: seconds}
  var currentVideo = null;
  var marked = {}; // pid -> true once

  function pauseCurrentVideo(){
    if (currentVideo && !currentVideo.paused){
      try { currentVideo.pause(); } catch(e){}
    }
  }

  function bindVideo(pid, v){
    if (!v) return;
    // restore position
    if (typeof playback[pid] === 'number' && playback[pid] > 0){
      try { v.currentTime = playback[pid]; } catch(e){}
    }
    v.addEventListener('timeupdate', function(){
      playback[pid] = v.currentTime || 0;
    });
    v.addEventListener('play', function(){
      if (currentVideo && currentVideo !== v){
        try { currentVideo.pause(); } catch(e){}
      }
      currentVideo = v;
    });
  }

  function markRead(pid, tab){
    if (!pid || marked[pid]) return;
    // don't mark until media is actually ready
    $.getJSON('feed.php', { ajax:'mark_read', pid: pid, tab: tab || 'work' })
      .done(function(resp){
        if (resp && resp.ok){
          marked[pid] = true;
          if (typeof resp.unread !== 'undefined'){
            $('#unreadNum').text(resp.unread);
          }
          // since we advance pointer to this post, NEW badges should clear (best-effort)
          $('.sidebar-badge-new').remove();
        }
      });
  }

  function whenMediaReady(pid){
    var $wrap = $('.feed-post-media[data-post-id="'+pid+'"]');
    if (!$wrap.length) return;

    var tab = $wrap.data('tab') || 'work';
    var $main = $wrap.find('.media-main').first();
    if (!$main.length) return;

      // Find the first media element inside main
      var el = $main.find('img,video,iframe').first().get(0);
      if (!el){
        // no media - mark read immediately
        markRead(pid, tab);
        return;
      }

      if (el.tagName === 'IMG'){
        if (el.complete){
          markRead(pid, tab);
        } else {
          el.addEventListener('load', function(){ markRead(pid, tab); }, { once:true });
          el.addEventListener('error', function(){ /* don't mark */ }, { once:true });
        }
      } else if (el.tagName === 'VIDEO'){
      bindVideo(pid, el);
      // mark read once metadata is ready (enough to confirm load)
      el.addEventListener('loadedmetadata', function(){ markRead(pid, tab); }, { once:true });
      el.addEventListener('error', function(){ /* don't mark */ }, { once:true });
      } else if (el.tagName === 'IFRAME'){
        el.addEventListener('load', function(){ markRead(pid, tab); }, { once:true });
        // If iframe never fires (some browsers), mark after short delay
        setTimeout(function(){ markRead(pid, tab); }, 900);
      }
    }

    function renderMain(kind, src, mime, name){
  src = src || '';
  kind = kind || 'file';
  mime = mime || '';
  name = name || 'Attachment';

  if (!src) {
    return '<div class="media-fallback"><div><div style="font-size:44px; line-height:1; margin-bottom:10px;"><i class="fa fa-image"></i></div><div style="font-size:13px; opacity:.9;">No media attached</div></div></div>';
  }

  // escape helpers
  var escAttr = function(v){ return String(v || '').replace(/"/g,'&quot;'); };
  var escHtml = function(v){ return $('<div/>').text(String(v || '')).html(); };

  if (kind === 'image'){
    return '<img src="'+escAttr(src)+'" alt="'+escAttr(name || 'Image')+'">';
  }

  if (kind === 'video'){
    var t = mime || 'video/mp4';
    return '<video controls playsinline preload="metadata"><source src="'+escAttr(src)+'" type="'+escAttr(t)+'">Your browser does not support the video tag.</video>';
  }

  // ✅ PDF inline preview (more compatible)
  if (kind === 'pdf'){
    // Avoid breaking URLs that already have a fragment
    var embed = src;
    if (embed.indexOf('#') === -1){
      embed += '#toolbar=0&navpanes=0&scrollbar=0&view=FitH';
    }
    return ''
      + '<iframe class="feed-doc-iframe"'
      + ' src="'+escAttr(embed)+'"'
      + ' title="'+escAttr(name || 'PDF')+'"'
      + ' loading="lazy"'
      + ' referrerpolicy="no-referrer"></iframe>';
  }

  // ✅ PPT/PPTX inline preview ONLY if src is public HTTPS (Office viewer)
  if (kind === 'ppt'){
    var isHttps = /^https:\/\//i.test(src);
    if (isHttps){
      var office = 'https://view.officeapps.live.com/op/embed.aspx?src=' + encodeURIComponent(src);
      return ''
        + '<iframe class="feed-doc-iframe"'
        + ' src="'+escAttr(office)+'"'
        + ' title="'+escAttr(name || 'PPTX')+'"'
        + ' loading="lazy"'
        + ' referrerpolicy="no-referrer"></iframe>';
    }
    // If not HTTPS/public, fall back (because it cannot preview reliably)
    return '<div class="doc-preview"><div class="doc-inner">'
      + '<div class="doc-icon"><i class="fa fa-file-powerpoint-o"></i></div>'
      + '<div class="doc-name">'+escHtml(name)+'</div>'
      + '<div class="doc-meta">'
      + '<span class="doc-pill"><i class="fa fa-paperclip"></i> Attachment</span> '
      + '<span class="doc-pill">PPTX</span> '
      + '<span class="doc-pill" title="PPTX preview needs a public HTTPS URL">'
      + '<i class="fa fa-info-circle"></i> Preview needs HTTPS</span>'
      + '</div>'
      + '<div class="doc-actions">'
      + '<a class="doc-btn" href="'+escAttr(src)+'" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Open</a>'
      + '<a class="doc-btn" href="'+escAttr(src)+'" download><i class="fa fa-download"></i> Download</a>'
      + '</div></div></div>';
  }

  // other files fallback
  var icon = 'fa-file-o';
  var label = (kind || 'FILE').toString().toUpperCase();
  if (kind === 'doc'){ icon = 'fa-file-word-o'; label = 'DOCX'; }
  else if (kind === 'sheet'){ icon = 'fa-file-excel-o'; label = 'XLSX'; }

  return '<div class="doc-preview"><div class="doc-inner">'
    + '<div class="doc-icon"><i class="fa '+icon+'"></i></div>'
    + '<div class="doc-name">'+escHtml(name)+'</div>'
    + '<div class="doc-meta"><span class="doc-pill"><i class="fa fa-paperclip"></i> Attachment</span> <span class="doc-pill">'+escHtml(label)+'</span></div>'
    + '<div class="doc-actions">'
    + '<a class="doc-btn" href="'+escAttr(src)+'" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Open</a>'
    + '<a class="doc-btn" href="'+escAttr(src)+'" download><i class="fa fa-download"></i> Download</a>'
    + '</div></div></div>';
}

})();

</script>

<!-- Read more modal (shared for all cards) -->
<div id="readMoreModal" class="read-more-modal" aria-hidden="true">
  <div class="rm-card" role="dialog" aria-modal="true" aria-labelledby="readMoreTitle">
    <button type="button" class="rm-close" onclick="closeReadMoreModal()" aria-label="Close">×</button>
    <div class="rm-head">
      <div id="readMoreTitle" class="rm-title"></div>
      <div id="readMoreDate" class="rm-date"></div>
    </div>
    <div id="readMoreDescription" class="rm-body"></div>
  </div>
</div>

<script>
  function initFeedReadMoreButtons(){
    document.querySelectorAll('.feed-post-detail .feed-desc-wrapper').forEach(function(wrap){
      var desc = wrap.querySelector('.feed-desc.clamp-7');
      var btn = wrap.querySelector('.read-more-btn');
      if (!desc || !btn) return;
      var needsMore = desc.scrollHeight > desc.clientHeight + 1;
      btn.classList.toggle('is-hidden', !needsMore);
    });
  }
  function openReadMoreModal(btn){
    try{
      var modal = document.getElementById('readMoreModal');
      var title = document.getElementById('readMoreTitle');
      var date  = document.getElementById('readMoreDate');
      var body  = document.getElementById('readMoreDescription');

      var t = (btn && btn.getAttribute('data-title')) ? btn.getAttribute('data-title') : 'Details';
      var d = (btn && (btn.getAttribute('data-description') || btn.getAttribute('data-full'))) ? (btn.getAttribute('data-description') || btn.getAttribute('data-full')) : '';
      var dt = (btn && btn.getAttribute('data-date')) ? btn.getAttribute('data-date') : '';

      if (title) title.textContent = t;
      if (date)  date.textContent  = dt;
      if (body)  body.textContent  = d;

      if (modal){
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden','false');
      }
      document.body.style.overflow = 'hidden';
    } catch(e){}
  }
  function closeReadMoreModal(){
    var modal = document.getElementById('readMoreModal');
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
  }
  // Close when clicking the dark backdrop
  document.addEventListener('click', function(e){
    var modal = document.getElementById('readMoreModal');
    if (!modal || modal.style.display !== 'flex') return;
    if (e.target === modal) closeReadMoreModal();
  });
  // ESC to close
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeReadMoreModal();
  });
  initFeedReadMoreButtons();
  document.addEventListener('org-nav-complete', initFeedReadMoreButtons);
  window.addEventListener('resize', initFeedReadMoreButtons);
</script>

<script>
window.addEventListener('pagehide', function () {
  document.documentElement.setAttribute('data-org-feed', '0');
  document.querySelectorAll('video, audio, .feed-post-media, .feed-post, .feed-layout').forEach(function (el) {
    try { if (el.pause) el.pause(); } catch (e) {}
    try {
      el.removeAttribute('src');
      if (el.load) el.load();
    } catch (e2) {}
    if (el.parentNode) el.parentNode.removeChild(el);
  });
});
</script>

</body>
</html>