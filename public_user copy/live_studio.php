<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/theme_prefs.php';

if (!function_exists('h')) {
    function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('studioTableExists')) {
    function studioTableExists(PDO $dbh, string $table): bool
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
}

if (!function_exists('studioFmt')) {
    function studioFmt(?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        return $ts ? date('M d, Y h:i A', $ts) : $raw;
    }
}

if (!function_exists('studioInputDt')) {
    function studioInputDt(?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d\TH:i', $ts) : '';
    }
}

if (!function_exists('studioVisibilityLabel')) {
    function studioVisibilityLabel(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'public') {
            return 'Public room';
        }
        if ($value === 'friends') {
            return 'Friends only';
        }
        return 'Private room';
    }
}

if (!function_exists('studioEnsureUsageTable')) {
    function studioEnsureUsageTable(PDO $dbh): bool
    {
        if (studioTableExists($dbh, 'user_video_live_usage')) {
            return true;
        }

        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS user_video_live_usage (
                    user_id INT NOT NULL PRIMARY KEY,
                    total_sessions INT NOT NULL DEFAULT 0,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('studioEnsureLiveShareCountColumn')) {
    function studioEnsureLiveShareCountColumn(PDO $dbh): void
    {
        if (!studioTableExists($dbh, 'user_video_lives')) {
            return;
        }
        try {
            $st = $dbh->prepare("
                SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'user_video_lives'
                  AND COLUMN_NAME = 'share_count'
                LIMIT 1
            ");
            $st->execute();
            if (!$st->fetchColumn()) {
                $dbh->exec("ALTER TABLE user_video_lives ADD COLUMN share_count INT NOT NULL DEFAULT 0 AFTER viewer_count");
            }
        } catch (Throwable $e) {
            // keep page load resilient
        }
    }
}

if (!function_exists('studioFetchLatestFinishedSummary')) {
    function studioFetchLatestFinishedSummary(PDO $dbh, int $meId): ?array
    {
        if ($meId <= 0 || !studioTableExists($dbh, 'user_video_lives')) {
            return null;
        }

        try {
            $st = $dbh->prepare("
                SELECT id, title, visibility, viewer_count, share_count, ended_at, started_at, created_at
                FROM user_video_lives
                WHERE user_id = :uid
                  AND status NOT IN ('draft','scheduled','live')
                ORDER BY COALESCE(ended_at, started_at, created_at) DESC, id DESC
            ");
            $st->execute([':uid' => $meId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) {
                return null;
            }

            $latestRow = $rows[0];
            $liveIds = array_values(array_filter(array_map(static function ($row) {
                return (int)($row['id'] ?? 0);
            }, $rows)));
            $views = 0;
            $shares = 0;
            foreach ($rows as $row) {
                $views += (int)($row['viewer_count'] ?? 0);
                $shares += (int)($row['share_count'] ?? 0);
            }

            $comments = 0;
            $reactionCounts = ['love' => 0, 'like' => 0, 'fire' => 0, 'wow' => 0, 'clap' => 0];

            if ($liveIds && studioTableExists($dbh, 'user_video_live_comments')) {
                try {
                    $placeholders = implode(',', array_fill(0, count($liveIds), '?'));
                    $stComments = $dbh->prepare("
                        SELECT COUNT(*)
                        FROM user_video_live_comments
                        WHERE live_id IN ($placeholders)
                    ");
                    $stComments->execute($liveIds);
                    $comments = (int)($stComments->fetchColumn() ?: 0);
                } catch (Throwable $e) {
                    $comments = 0;
                }
            }

            if ($liveIds && studioTableExists($dbh, 'user_video_live_reactions')) {
                try {
                    $placeholders = implode(',', array_fill(0, count($liveIds), '?'));
                    $stReactions = $dbh->prepare("
                        SELECT reaction, COUNT(*) AS total
                        FROM user_video_live_reactions
                        WHERE live_id IN ($placeholders)
                        GROUP BY reaction
                    ");
                    $stReactions->execute($liveIds);
                    foreach (($stReactions->fetchAll(PDO::FETCH_ASSOC) ?: []) as $reactionRow) {
                        $reaction = (string)($reactionRow['reaction'] ?? '');
                        if (array_key_exists($reaction, $reactionCounts)) {
                            $reactionCounts[$reaction] = (int)($reactionRow['total'] ?? 0);
                        }
                    }
                } catch (Throwable $e) {
                    $reactionCounts = ['love' => 0, 'like' => 0, 'fire' => 0, 'wow' => 0, 'clap' => 0];
                }
            }

            return [
                'id' => (int)($latestRow['id'] ?? 0),
                'title' => 'All finished live totals',
                'visibility' => (string)($latestRow['visibility'] ?? 'private'),
                'views' => $views,
                'comments' => $comments,
                'shares' => $shares,
                'love' => (int)($reactionCounts['love'] ?? 0),
                'like' => (int)($reactionCounts['like'] ?? 0),
                'smile' => (int)($reactionCounts['wow'] ?? 0),
                'care' => (int)($reactionCounts['clap'] ?? 0),
                'angry' => (int)($reactionCounts['fire'] ?? 0),
                'ended_at_label' => 'Saved across ' . count($rows) . ' finished live session' . (count($rows) === 1 ? '' : 's') . '. Latest: ' . studioFmt((string)($latestRow['ended_at'] ?? $latestRow['started_at'] ?? $latestRow['created_at'] ?? '')),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

$meId = theme_prefs_viewer_user_id();
$meName = trim((string)($_SESSION['name'] ?? $_SESSION['user_name'] ?? 'My Story Book'));
$meEmail = trim((string)($_SESSION['email'] ?? $_SESSION['user_email'] ?? ''));
$meCode = trim((string)($_SESSION['friend_code'] ?? $_SESSION['user_friend_code'] ?? ''));
$avatarUrl = 'avatar.php?u=' . $meId . '&email=' . rawurlencode($meEmail) . '&name=' . rawurlencode($meName);

$parts = preg_split('/\s+/', $meName) ?: [];
$parts = array_values(array_filter($parts, static function ($part) {
    return trim((string)$part) !== '';
}));
$initials = 'MS';
if ($parts) {
    $first = strtoupper(substr((string)$parts[0], 0, 1));
    $last = strtoupper(substr((string)$parts[count($parts) - 1], 0, 1));
    $initials = $first . ($last !== '' ? $last : '');
}

$controller = new Controller();
$dbh = $controller->pdo();
studioEnsureLiveShareCountColumn($dbh);
$currentLive = null;
$historyCount = 0;
$historySummary = null;

if (studioTableExists($dbh, 'user_video_lives')) {
    try {
        $stCurrent = $dbh->prepare("
            SELECT id, title, description, stream_key, status, visibility, viewer_count, share_count, started_at, scheduled_for, updated_at, created_at
            FROM user_video_lives
            WHERE user_id = :uid
              AND status IN ('draft','scheduled','live')
            ORDER BY FIELD(status, 'live', 'scheduled', 'draft'), COALESCE(started_at, scheduled_for, updated_at, created_at) DESC, id DESC
            LIMIT 1
        ");
        $stCurrent->execute([':uid' => $meId]);
        $currentLive = $stCurrent->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $currentLive = null;
    }

    try {
        $stHistory = $dbh->prepare("
            SELECT COUNT(*)
            FROM user_video_lives
            WHERE user_id = :uid
              AND status NOT IN ('draft','scheduled','live')
        ");
        $stHistory->execute([':uid' => $meId]);
        $historyCount = (int)($stHistory->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $historyCount = 0;
    }

    if (studioEnsureUsageTable($dbh)) {
        try {
            $stUsage = $dbh->prepare("
                SELECT total_sessions
                FROM user_video_live_usage
                WHERE user_id = :uid
                LIMIT 1
            ");
            $stUsage->execute([':uid' => $meId]);
            $historyCount = max($historyCount, (int)($stUsage->fetchColumn() ?: 0));
        } catch (Throwable $e) {
            $historyCount += 0;
        }
    }

    $historySummary = studioFetchLatestFinishedSummary($dbh, $meId);
}

$prefillTitle = trim((string)($currentLive['title'] ?? 'Please stop by saying HI'));
if ($prefillTitle === '') {
    $prefillTitle = 'Please stop by saying HI';
}
$prefillDescription = trim((string)($currentLive['description'] ?? 'Share update, answer questions, and go live with your audience'));
$prefillVisibility = strtolower(trim((string)($currentLive['visibility'] ?? 'private')));
if (!in_array($prefillVisibility, ['private', 'friends', 'public'], true)) {
    $prefillVisibility = 'private';
}
$prefillSchedule = studioInputDt((string)($currentLive['scheduled_for'] ?? ''));
$currentStatus = strtolower(trim((string)($currentLive['status'] ?? 'draft')));
$currentStatusLabel = $currentStatus === 'live' ? 'LIVE' : ($currentStatus === 'scheduled' ? 'SCHEDULED' : 'DRAFT');
$currentSessionHeading = $currentLive ? ((string)($currentLive['title'] ?? 'Current live')) : 'No active entry';
$currentSessionMeta = $currentLive
    ? ($currentStatus === 'live'
        ? ('Started ' . studioFmt((string)($currentLive['started_at'] ?? '')))
        : ($currentStatus === 'scheduled'
            ? ('Scheduled ' . studioFmt((string)($currentLive['scheduled_for'] ?? '')))
    : 'Draft saved and ready for setup'))
    : 'Start one now or schedule a session from the launch form above';
$autoOpenLiveWatchId = (int)($_GET['open_live_watch'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Live Studio</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="stylesheet" href="./css/dark-auto.css">
  <link rel="stylesheet" href="./css/shamcey.css">
  <script src="./js/device_profile.js"></script>
  <script src="./js/dark-auto.js?v=4" defer></script>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <script src="./lib/jquery/jquery.js"></script>
  <script src="./lib/popper.js/popper.js"></script>
  <script src="./lib/bootstrap/bootstrap.js"></script>
  <style>
    :root {
      --bg: #f4f6fb;
      --panel: #ffffff;
      --line: #d8e0ef;
      --text: #111827;
      --muted: #6b7280;
      --blue: #2b89f0;
      --stage-fill: #5c88dc;
      --stage-fill-dark: #4e77ca;
      --stage-divider: rgba(27, 52, 105, 0.45);
      --shadow: 0 18px 48px rgba(79, 102, 150, 0.14);
      --radius-lg: 15px;
      --radius-md: 12px;
      --radius-sm: 8px;
      --rail-width: 112px;
      --feedRailW: 84px;
      --font: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    * { box-sizing: border-box; }

    html,
    body {
      height: 100%;
    }

    body {
      margin: 0;
      font-family: var(--font);
      background:
        radial-gradient(circle at top right, rgba(90, 135, 255, 0.10), transparent 28%),
        radial-gradient(circle at bottom left, rgba(37, 99, 235, 0.08), transparent 30%),
        var(--bg);
      color: var(--text);
    }

    html.dark-auto {
      --bg: #0f141c;
      --panel: #182130;
      --line: rgba(255,255,255,.12);
      --text: #f3f6fb;
      --muted: #a9b6c8;
      --blue: #5aa4ff;
      --stage-fill: #18253d;
      --stage-fill-dark: #121b2d;
      --stage-divider: rgba(255,255,255,.14);
      --shadow: 0 22px 56px rgba(0,0,0,.34);
    }

    html.dark-auto body {
      background:
        radial-gradient(circle at top right, rgba(90, 135, 255, 0.16), transparent 28%),
        radial-gradient(circle at bottom left, rgba(37, 99, 235, 0.10), transparent 30%),
        var(--bg);
      color: var(--text);
    }

    html.dark-auto .studio-rail,
    html.dark-auto .studio-panel,
    html.dark-auto .studio-card,
    html.dark-auto .session-card,
    html.dark-auto .history-card,
    html.dark-auto .studio-live-modal-dialog,
    html.dark-auto .studio-live-confirm-card {
      background: var(--panel) !important;
      color: var(--text) !important;
      border-color: var(--line) !important;
      box-shadow: var(--shadow);
    }

    html.dark-auto .studio-rail,
    html.dark-auto .feed-ig-rail {
      background: linear-gradient(180deg, #131b27 0%, #182130 100%) !important;
      border-right-color: var(--line) !important;
      box-shadow: inset -1px 0 0 rgba(255,255,255,.04) !important;
    }

    html.dark-auto .feed-ig-logo-label {
      color: #f8fbff !important;
    }

    html.dark-auto .feed-ig-btn,
    html.dark-auto .feed-ig-link,
    html.dark-auto .ig-link {
      color: #e7edf5 !important;
      background: transparent !important;
    }

    html.dark-auto .feed-ig-btn:hover,
    html.dark-auto .feed-ig-link:hover,
    html.dark-auto .feed-ig-btn:focus,
    html.dark-auto .feed-ig-link:focus,
    html.dark-auto .feed-ig-btn.active,
    html.dark-auto .feed-ig-link.active,
    html.dark-auto .ig-link:hover,
    html.dark-auto .ig-link:focus,
    html.dark-auto .ig-link.active {
      background: rgba(255,255,255,.08) !important;
      color: #fff !important;
    }

    html.dark-auto .studio-shell,
    html.dark-auto .studio-main,
    html.dark-auto .studio-stage,
    html.dark-auto .preview-panel {
      color: var(--text);
    }

    html.dark-auto .brand-copy,
    html.dark-auto .panel-eyebrow,
    html.dark-auto .panel-copy,
    html.dark-auto .helper-copy,
    html.dark-auto .detail-copy,
    html.dark-auto .timeline-note,
    html.dark-auto .studio-live-request-help,
    html.dark-auto .studio-live-request-sub,
    html.dark-auto .studio-live-compose-feedback,
    html.dark-auto .studio-live-comment-author,
    html.dark-auto .studio-live-comment-meta,
    html.dark-auto .room-stat,
    html.dark-auto .watch-status,
    html.dark-auto .stat-label,
    html.dark-auto .eyebrow,
    html.dark-auto .live-dot-label,
    html.dark-auto .progress-step-label,
    html.dark-auto .subtle-label {
      color: var(--muted) !important;
    }

    html.dark-auto input,
    html.dark-auto textarea,
    html.dark-auto select,
    html.dark-auto .source-card,
    html.dark-auto .summary-pill,
    html.dark-auto .studio-live-control,
    html.dark-auto .studio-live-end,
    html.dark-auto .studio-live-request-card,
    html.dark-auto .studio-live-request-item,
    html.dark-auto .studio-live-approved-pill {
      background: #111827 !important;
      color: var(--text) !important;
      border-color: var(--line) !important;
    }

    html.dark-auto input::placeholder,
    html.dark-auto textarea::placeholder {
      color: #8ea0b8 !important;
    }

    html.dark-auto .source-card.is-selected,
    html.dark-auto .studio-live-control.is-active,
    html.dark-auto .camera-toggle.is-on {
      background: #1b2d4a !important;
      color: #dbeafe !important;
      border-color: rgba(96,165,250,.42) !important;
    }

    html.dark-auto .page-kicker,
    html.dark-auto .section-title,
    html.dark-auto .source-name,
    html.dark-auto .software-panel-title,
    html.dark-auto .software-mode-card strong,
    html.dark-auto .preview-label,
    html.dark-auto .history-stat,
    html.dark-auto .field label,
    html.dark-auto .toggle-state,
    html.dark-auto .studio-feedback,
    html.dark-auto .middle-top,
    html.dark-auto .label-blue,
    html.dark-auto .status-chip,
    html.dark-auto .studio-note,
    html.dark-auto .step h4,
    html.dark-auto .info-card h4,
    html.dark-auto .history-card h4,
    html.dark-auto .chat-card h4,
    html.dark-auto .comment-author,
    html.dark-auto .comment-body,
    html.dark-auto .room-stat,
    html.dark-auto .reaction-btn,
    html.dark-auto .secondary-btn,
    html.dark-auto .history-row strong,
    html.dark-auto .toggle-card strong,
    html.dark-auto .preview-message,
    html.dark-auto .preview-empty-title,
    html.dark-auto .studio-section-title {
      color: var(--text) !important;
    }

    html.dark-auto .page-title,
    html.dark-auto .label-blue,
    html.dark-auto .secondary-btn,
    html.dark-auto .status-chip,
    html.dark-auto .source-card.is-selected .source-glyph,
    html.dark-auto .source-card.is-selected .source-name {
      color: #8fc2ff !important;
    }

    html.dark-auto .head-actions,
    html.dark-auto .progress-copy,
    html.dark-auto .middle-top code,
    html.dark-auto .toggle-help,
    html.dark-auto .studio-note,
    html.dark-auto .software-panel-copy,
    html.dark-auto .software-mode-card span,
    html.dark-auto .software-inline-value,
    html.dark-auto .step p,
    html.dark-auto .intro,
    html.dark-auto .muted,
    html.dark-auto .info-card p,
    html.dark-auto .history-card p,
    html.dark-auto .chat-card p,
    html.dark-auto .comment-meta,
    html.dark-auto .field-hint,
    html.dark-auto .preview-empty-copy,
    html.dark-auto .empty-copy,
    html.dark-auto .helper-inline {
      color: var(--muted) !important;
    }

    html.dark-auto .panel,
    html.dark-auto .card,
    html.dark-auto .setup-card,
    html.dark-auto .history-row,
    html.dark-auto .history-metric,
    html.dark-auto .chat-card,
    html.dark-auto .software-panel,
    html.dark-auto .software-mode-card,
    html.dark-auto .toggle-card,
    html.dark-auto .comment-item,
    html.dark-auto .source-card,
    html.dark-auto .camera-preview,
    html.dark-auto .preview-warning,
    html.dark-auto .studio-note {
      background: #182130 !important;
      color: var(--text) !important;
      border-color: rgba(255,255,255,.12) !important;
      box-shadow: none;
    }

    html.dark-auto .camera-preview {
      background:
        linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px),
        #090d14 !important;
    }

    html.dark-auto .camera-preview.is-live {
      background: #05070c !important;
    }

    html.dark-auto .preview-message h3,
    html.dark-auto .preview-warning strong {
      color: #dbeafe !important;
    }

    html.dark-auto .preview-message p,
    html.dark-auto .preview-warning,
    html.dark-auto .progress-copy {
      color: #b8c6d9 !important;
    }

    html.dark-auto .history-stat span {
      background: #1b2d4a !important;
      color: #dbeafe !important;
    }

    html.dark-auto .history-metric-label {
      color: #9db0c8 !important;
    }

    html.dark-auto .progress-track {
      background: #233044 !important;
    }

    html.dark-auto .step,
    html.dark-auto .toggle-card,
    html.dark-auto .history-row,
    html.dark-auto .comment-item {
      background: #121927 !important;
      border-color: rgba(255,255,255,.12) !important;
    }

    html.dark-auto .step-badge,
    html.dark-auto .source-glyph {
      background: #0f1723 !important;
      border-color: rgba(255,255,255,.12) !important;
      color: #c7d2fe !important;
    }

    html.dark-auto .step.is-active .step-badge {
      background: #2563eb !important;
      border-color: rgba(147,197,253,.55) !important;
      color: #eff6ff !important;
    }

    html.dark-auto .step.is-active,
    html.dark-auto .source-card.is-selected,
    html.dark-auto .software-mode-card.is-selected {
      background: #18263b !important;
      border-color: rgba(96,165,250,.38) !important;
      box-shadow: 0 0 0 1px rgba(96,165,250,.10) inset;
    }

    html.dark-auto .control,
    html.dark-auto .textarea,
    html.dark-auto .select,
    html.dark-auto .datetime,
    html.dark-auto .comment-input {
      background: #0f1723 !important;
      color: #f8fbff !important;
      border-color: rgba(255,255,255,.14) !important;
      box-shadow: inset 0 1px 2px rgba(0,0,0,.26);
    }

    html.dark-auto .control::placeholder,
    html.dark-auto .textarea::placeholder,
    html.dark-auto .datetime::placeholder,
    html.dark-auto .comment-input::placeholder {
      color: #8ea0b8 !important;
    }

    html.dark-auto .select-wrap svg,
    html.dark-auto .date-wrap svg {
      color: #b7c5d9 !important;
    }

    html.dark-auto .primary-btn,
    html.dark-auto .accent-btn,
    html.dark-auto .cam-btn {
      color: #fff !important;
    }

    html.dark-auto .secondary-btn {
      background: #101b2d !important;
      border-color: rgba(96,165,250,.34) !important;
    }

    html.dark-auto .toggle-state {
      color: #ffb4ad !important;
    }

    html.dark-auto .alert-line {
      background: #221518 !important;
      color: #ffb4ad !important;
      border-color: rgba(255,127,127,.28) !important;
    }

    html.dark-auto .switch {
      border-color: rgba(255,255,255,.18) !important;
    }

    html.dark-auto .switch::after {
      background: #f8fbff !important;
    }

    html.dark-auto .live-dot {
      box-shadow: 0 0 0 6px rgba(255,107,107,.14) !important;
    }

    html.dark-auto .live-dot::after {
      background: rgba(255,107,107,.28) !important;
    }

    html.dark-auto .status-chip.live {
      background: rgba(255,107,107,.16) !important;
      color: #ffb4ad !important;
    }

    html.dark-auto .status-chip.scheduled {
      background: rgba(245,158,11,.16) !important;
      color: #fcd34d !important;
    }

    html.dark-auto .status-chip.draft {
      background: rgba(148,163,184,.16) !important;
      color: #cbd5e1 !important;
    }

    .feed-ig-rail .dropdown-bestprofile-menu{
      width:320px !important;
      min-width:320px !important;
      max-width:320px !important;
      font-family:var(--msb-font-ui, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif) !important;
    }

    .feed-ig-rail .bestprofile-top{
      padding:14px !important;
      gap:12px !important;
    }

    .feed-ig-rail .bestprofile-avatar.big{
      width:64px !important;
      height:64px !important;
      margin-right:12px !important;
      font-size:18px !important;
    }

    .feed-ig-rail .bestprofile-name{
      font-size:14px !important;
      line-height:1.2 !important;
    }

    .feed-ig-rail .bestprofile-email,
    .feed-ig-rail .bestprofile-code{
      font-size:12px !important;
    }

    .feed-ig-rail .bestprofile-nav li a{
      padding:10px 14px !important;
      gap:10px !important;
      font-size:13px !important;
      line-height:1.2 !important;
    }

    a { color: inherit; text-decoration: none; }
    button, input, textarea, select { font: inherit; }

    .studio-shell {
      min-height: 100vh;
      display: block;
      padding-left: calc(var(--feedRailW) + 8px);
      overflow: hidden;
    }

    .studio-rail {
      background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
      border-right: 1px solid #e3e8f3;
      padding: 18px 10px 14px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      display: none;
    }

    .brand-mark {
      width: 70px;
      height: 70px;
      border-radius: 18px;
      display: grid;
      place-items: center;
      color: #fff;
      font-size: 24px;
      font-weight: 800;
      background: linear-gradient(135deg, #503ff3 0%, #149fed 100%);
      box-shadow: 0 14px 28px rgba(54, 111, 246, 0.22);
    }

    .brand-copy {
      margin-top: -2px;
      font-size: 14px;
      font-weight: 800;
      line-height: 1.02;
      text-align: center;
      color: #303847;
    }

    .rail-avatar {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      overflow: hidden;
      border: 3px solid #fff;
      background: #d4d91a;
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.10);
      display: grid;
      place-items: center;
      color: #fff;
      font-weight: 800;
      font-size: 14px;
      position: relative;
    }

    .rail-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .rail-nav {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 4px;
      flex: 1;
      width: 100%;
      align-items: center;
    }

    .rail-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      color: #171f2e;
      transition: background 0.18s ease, transform 0.18s ease;
    }

    .rail-icon:hover,
    .rail-icon.is-active {
      background: #edf3ff;
      transform: translateY(-1px);
    }

    .studio-main {
      padding: 8px;
      /* padding-bottom: 88px; */
      overflow: visible;
    }

    .studio-frame {
      height: calc(100vh - 15px);
      background: var(--panel);
      border: 1px solid #d8deea;
      box-shadow: var(--shadow);
      display: grid;
      grid-template-columns: 390px minmax(560px, 1fr) minmax(440px, 1fr);
      overflow: hidden;
    }

    .panel {
      padding: 10px 14px;
      border-right: 1px solid #dfe5f1;
      display: flex;
      flex-direction: column;
      gap: 8px;
      min-width: 0;
      overflow: hidden;
    }

    .panel:last-child { border-right: 0; }

    .left-head,
    .title-lockup,
    .progress-wrap,
    .preview-toolbar,
    .toggle-top,
    .action-row {
      display: flex;
      align-items: center;
    }

    .left-head {
      align-items: flex-start;
      justify-content: space-between;
      gap: 8px;
    }

    .title-lockup { gap: 10px; align-items: flex-start; }

    .live-dot {
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: #ffe2dc;
      display: grid;
      place-items: center;
      flex: 0 0 auto;
    }

    .live-dot::after {
      content: "";
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #ff8f78;
      box-shadow: 0 0 0 5px rgba(255, 160, 141, 0.12);
    }

    .page-kicker {
      font-size: 15px;
      font-weight: 800;
      margin: 0 0 3px;
    }

    .page-title {
      margin: 0;
      font-size: 17px;
      line-height: 1.1;
      color: var(--blue);
      font-weight: 800;
    }

    .head-actions {
      display: flex;
      gap: 6px;
      color: #707070;
    }

    .icon-chip {
      width: 28px;
      height: 28px;
      border-radius: 8px;
      display: grid;
      place-items: center;
    }

    .progress-wrap { gap: 8px; }

    .progress-track {
      flex: 1;
      height: 6px;
      border-radius: 999px;
      background: #ddd;
      overflow: hidden;
    }

    .progress-bar {
      width: 0%;
      height: 100%;
      background: linear-gradient(90deg, #86b7ff 0%, #377ef7 100%);
      transition: width 0.25s ease;
    }

    .progress-copy {
      color: #7098ff;
      font-weight: 700;
      font-size: 12px;
      white-space: nowrap;
    }

    .card {
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 10px;
      background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
      box-shadow: 0 6px 16px rgba(56, 78, 120, 0.04);
    }

    .setup-card {
      background: linear-gradient(180deg, #f8fbff 0%, #f4f7fd 100%);
    }

    .eyebrow {
      font-size: 10px;
      letter-spacing: 0.13em;
      text-transform: uppercase;
      color: #5f88d0;
      font-weight: 800;
      margin-bottom: 2px;
    }

    .section-title {
      margin: 0;
      font-size: 14px;
      font-weight: 800;
      letter-spacing: -0.01em;
    }

    .steps {
      margin-top: 6px;
      display: grid;
      gap: 6px;
    }

    .step {
      display: grid;
      grid-template-columns: 34px 1fr;
      gap: 10px;
      align-items: start;
      padding: 8px;
      border-radius: 12px;
      border: 1px solid #d7e2f3;
      background: #fff;
    }

    .step.is-active {
      background: #ebf3ff;
      border-color: #73a7ff;
    }

    .step-badge {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      background: #eef3fa;
      border: 1px solid #d1dbea;
      color: #5a6b82;
      display: grid;
      place-items: center;
      font-weight: 800;
      font-size: 13px;
    }

    .step.is-active .step-badge {
      background: #356fdc;
      border-color: #356fdc;
      color: #fff;
    }

    .step h4,
    .info-card h4,
    .history-card h4,
    .chat-card h4 {
      margin: 0 0 3px;
      font-size: 13px;
      font-weight: 800;
    }

    .step p,
    .intro,
    .muted,
    .info-card p,
    .history-card p,
    .chat-card p {
      margin: 0;
      color: var(--muted);
      line-height: 1.18;
      font-size: 11px;
    }

    .setup-helper {
      margin-top: 10px;
      padding: 12px 14px;
      border: 1px solid #3b4658;
      border-radius: 14px;
      background: rgba(18, 25, 39, 0.68);
      display: grid;
      gap: 8px;
    }

    .subcards,
    .history-panel {
      margin-top: auto;
      display: grid;
      gap: 8px;
    }

    .label-blue {
      color: var(--blue);
      font-weight: 800;
      font-size: 14px;
      margin: 0 0 3px;
    }

    .middle-top {
      font-size: 13px;
      line-height: 1.3;
      color: #555f73;
      padding-right: 8px;
    }

    .middle-top code {
      font-family: inherit;
      color: #e3726b;
      background: transparent;
      font-weight: 700;
    }

    .source-grid,
    .split {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .source-card {
      min-height: 74px;
      border-radius: 8px;
      border: 2px solid #989898;
      background: #fff;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 10px;
      cursor: pointer;
      transition: border-color 0.18s ease, box-shadow 0.18s ease;
      text-align: center;
    }

    .source-card.is-selected {
      border-color: #5ea1ff;
      box-shadow: 0 0 0 3px rgba(94, 161, 255, 0.12) inset;
    }

    .source-glyph {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: #f1f4fb;
      color: #6a7191;
    }

    .source-card.is-selected .source-glyph {
      background: #e7f0ff;
      color: #2c70c8;
    }

    .source-name {
      font-size: 14px;
      font-weight: 800;
    }

    .software-panel {
      margin-top: 12px;
      border: 1px solid #d6deef;
      border-radius: 12px;
      background: #f9fbff;
      padding: 12px;
      display: grid;
      gap: 10px;
    }

    .software-panel-title {
      font-size: 13px;
      font-weight: 800;
      color: #1a2234;
      margin: 0;
    }

    .software-panel-copy {
      margin: 0;
      font-size: 12px;
      line-height: 1.45;
      color: #61708a;
    }

    .software-mode-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .software-mode-card {
      border: 1px solid #d6deef;
      border-radius: 10px;
      background: #fff;
      padding: 11px 12px;
      display: grid;
      gap: 4px;
      text-align: left;
      cursor: pointer;
      transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .software-mode-card strong {
      font-size: 13px;
      font-weight: 800;
      color: #1a2234;
    }

    .software-mode-card span {
      font-size: 11px;
      line-height: 1.45;
      color: #61708a;
    }

    .software-mode-card.is-selected {
      border-color: #5ea1ff;
      box-shadow: 0 0 0 2px rgba(94, 161, 255, 0.14) inset;
      background: #eef5ff;
    }

    .software-mode-body {
      display: grid;
      gap: 10px;
    }

    .software-pane {
      display: none;
      gap: 10px;
    }

    .software-pane.is-active {
      display: grid;
    }

    .software-inline {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .software-inline-value {
      font-size: 12px;
      line-height: 1.4;
      color: #5b6474;
      overflow-wrap: anywhere;
    }

    .software-url-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto auto;
      gap: 10px;
      align-items: center;
    }

    .software-link-note {
      font-size: 11px;
      line-height: 1.45;
      color: #61708a;
    }

    @media (max-width: 860px) {
      .software-mode-grid,
      .software-url-row {
        grid-template-columns: 1fr;
      }
    }

    .preview-label {
      font-size: 14px;
      font-weight: 800;
      margin-top: -2px;
      letter-spacing: -0.01em;
    }

    .preview-label.is-hidden-source-ui {
      display: none;
    }

    .camera-preview {
      position: relative;
      min-height: 300px;
      border-radius: 12px;
      overflow: hidden;
      color: #fff;
      background:
        linear-gradient(rgba(255,255,255,0.06) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.06) 1px, transparent 1px),
        #0b0b0b;
      background-size: 24px 24px, 24px 24px, auto;
      border: 1px solid #262626;
      padding: 16px 18px;
    }

    .camera-preview.is-hidden-source-ui {
      position: fixed;
      left: -10000px;
      top: 0;
      width: 960px;
      height: 540px;
      min-height: 540px;
      padding: 0;
      border: 0;
      overflow: hidden;
      opacity: 0;
      pointer-events: none;
    }

    .preview-video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: none;
      background: #000;
    }

    .preview-video.is-host-streaming {
      display: block;
    }

    .camera-preview.is-live {
      background: #050505;
    }

    .camera-preview.is-live .preview-video {
      display: block;
    }

    .camera-preview.is-live .preview-camera,
    .camera-preview.is-live .preview-message,
    .camera-preview.is-live .preview-warning {
      position: relative;
      z-index: 2;
    }

    .camera-preview.is-live .preview-camera,
    .camera-preview.is-live .preview-message {
      display: none;
    }

    .camera-preview.is-software-source .preview-camera {
      display: none;
    }

    .camera-preview.is-live .preview-toolbar {
      position: relative;
      z-index: 2;
    }

    .preview-toolbar {
      gap: 8px;
      align-items: center;
      justify-content: flex-start;
      flex-wrap: wrap;
    }

    .pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 12px;
      border-radius: 999px;
      background: rgba(255,255,255,0.12);
      border: 1px solid rgba(255,255,255,0.10);
      font-weight: 700;
      font-size: 12px;
      backdrop-filter: blur(8px);
    }

    .pill .tiny-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #ff7f67;
      box-shadow: 0 0 0 5px rgba(255, 127, 103, 0.14);
    }

    .pill.action {
      background: #a44f43;
    }

    .preview-camera {
      width: 54px;
      height: 54px;
      border-radius: 16px;
      margin: 24px auto 12px;
      background: rgba(255,255,255,0.10);
      display: grid;
      place-items: center;
      backdrop-filter: blur(8px);
    }

    .preview-message {
      text-align: center;
      max-width: 560px;
      margin: 0 auto;
    }

    .preview-message h3 {
      margin: 0 0 6px;
      font-size: 16px;
      color: #2991ff;
    }

    .preview-message p {
      margin: 0;
      color: rgba(255,255,255,0.72);
      font-size: 13px;
    }

    .preview-warning {
      position: absolute;
      left: 18px;
      right: 18px;
      bottom: 18px;
      top: auto;
      border-radius: 12px;
      background: #101a2c;
      border: 1px solid rgba(70, 108, 169, 0.35);
      padding: 12px 14px;
      color: rgba(255,255,255,0.86);
      font-size: 12px;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
      z-index: 3;
    }

    .camera-preview.is-live .preview-warning {
      position: absolute !important;
      left: 18px !important;
      right: 18px !important;
      top: auto !important;
      bottom: 18px !important;
      transform: none !important;
      margin: 0 !important;
    }

    .preview-warning strong {
      display: block;
      margin-bottom: 2px;
      color: #fff;
      font-size: 12px;
    }

    .history-row {
      border: 1px solid #d6deef;
      border-radius: 12px;
      padding: 10px;
      background: #f9fbff;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 12px;
      flex-wrap: wrap;
    }

    .history-stat {
      font-size: 13px;
      font-weight: 800;
      color: #262626;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin-right: auto;
    }

    .history-stat span {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 42px;
      height: 42px;
      padding: 0 12px;
      border-radius: 999px;
      background: #e8f0ff;
      color: #1f4e95;
      font-size: 20px;
      font-weight: 900;
      line-height: 1;
    }

    .history-summary {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid #d6deef;
      display: grid;
      gap: 8px;
    }

    .history-summary-title {
      font-size: 13px;
      font-weight: 800;
      color: #1a2234;
    }

    .history-summary-meta {
      font-size: 11px;
      color: #6b7280;
    }

    .history-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }

    @media (max-width: 1200px) {
      .history-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    .history-metric {
      display: grid;
      gap: 6px;
      padding: 12px;
      border: 1px solid #dbe3f0;
      border-radius: 12px;
      background: #fff;
      min-height: 76px;
      align-content: start;
    }

    .history-metric-label {
      font-size: 11px;
      font-weight: 800;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .history-metric span {
      display: block;
      font-size: 22px;
      font-weight: 900;
      line-height: 1;
      color: #162033;
    }

    .secondary-btn {
      border: 1px solid #78a7f3;
      background: #e8f0ff;
      color: #254d89;
      font-weight: 700;
      border-radius: 8px;
      padding: 9px 12px;
      cursor: pointer;
    }

    .form-grid {
      display: grid;
      gap: 6px;
    }

    .field label {
      display: block;
      margin-bottom: 4px;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      color: #2d2d2d;
    }

    .field {
      display: flex;
      flex-direction: column;
    }

    .control,
    .textarea,
    .select,
    .datetime {
      width: 100%;
      border: 1px solid #cbd4e1;
      border-radius: 7px;
      background: #fff;
      padding: 9px 11px;
      color: #2c3444;
      outline: none;
      font-size: 13px;
      box-shadow: inset 0 1px 2px rgba(17, 24, 39, 0.03);
    }

    .textarea {
      min-height: 52px;
      resize: vertical;
    }

    .select-wrap,
    .date-wrap {
      position: relative;
    }

    .select-wrap svg,
    .date-wrap svg {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #242424;
      pointer-events: none;
    }

    .toggle-card {
      border: 1px solid #cbd4e1;
      border-radius: 8px;
      padding: 8px;
      background: #fff;
      margin-top: 2px;
    }

    .toggle-top {
      gap: 8px;
      flex-wrap: wrap;
    }

    .toggle-state {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      font-weight: 800;
      color: #ff625c;
    }

    .cam-btn {
      border: 0;
      background: #c3505f;
      color: #fff;
      border-radius: 4px;
      font-weight: 800;
      padding: 9px 12px;
      cursor: pointer;
      min-width: 128px;
      font-size: 12px;
      box-shadow: 0 8px 18px rgba(195, 80, 95, 0.22);
    }

    .switch {
      width: 52px;
      height: 28px;
      border-radius: 999px;
      background: #df9aa2;
      position: relative;
      border: 2px solid rgba(255,255,255,0.5);
      cursor: pointer;
      flex: 0 0 auto;
    }

    .switch::after {
      content: "";
      position: absolute;
      top: 2px;
      left: 2px;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: #fff;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.14);
      transition: transform 0.2s ease;
    }

    .switch.is-on {
      background: #54b67a;
    }

    .switch.is-on::after {
      transform: translateX(24px);
    }

    .alert-line {
      margin-top: 8px;
      border: 2px solid #ff9791;
      background: #fff;
      color: #e5605a;
      border-radius: 4px;
      padding: 8px 9px 7px;
      font-size: 11px;
      font-weight: 700;
    }

    .toggle-help {
      margin-top: 6px;
      color: #4f5564;
      line-height: 1.24;
      font-size: 12px;
    }

    .action-row {
      margin-top: 6px;
      justify-content: flex-end;
      gap: 8px;
      align-items: center;
    }

    .primary-btn,
    .accent-btn {
      border: 0;
      color: #fff;
      font-weight: 800;
      border-radius: 4px;
      padding: 10px 14px;
      cursor: pointer;
      min-width: 132px;
      font-size: 12px;
      line-height: 1.1;
      text-align: center;
    }

    .primary-btn {
      background: linear-gradient(180deg, #ff6d61 0%, #fb6257 100%);
      box-shadow: 0 10px 18px rgba(251, 98, 87, 0.24);
    }

    .accent-btn {
      background: linear-gradient(180deg, #5072bc 0%, #4568b2 100%);
      box-shadow: 0 10px 18px rgba(69, 104, 178, 0.22);
    }

    .chat-card {
      border: 1px solid #8ab1f5;
      border-radius: 12px;
      /* padding: 10px 12px; */
      background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
    }

    .chat-card h4 {
      font-size: 15px;
      letter-spacing: -0.01em;
    }

    .room-stats {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 8px;
    }

    .room-stat {
      border-radius: 999px;
      background: #eef4ff;
      color: #355a96;
      padding: 5px 9px;
      font-size: 11px;
      font-weight: 700;
    }

    .reaction-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 10px;
    }

    .reaction-btn {
      border: 1px solid #cdd8eb;
      background: #fff;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: 11px;
      font-weight: 700;
      color: #30435f;
      cursor: pointer;
    }
    .reaction-btn .reaction-love-heart {
      color:#ec4899;
      margin-right:4px;
      line-height:1;
    }

    .reaction-btn.active {
      background: #254d89;
      border-color: #254d89;
      color: #fff;
    }
    .reaction-btn.active .reaction-love-heart {
      color:#ff8fd0;
    }

    .analytics-cache {
      display: none;
    }

    .stats-chart {
      /* margin-top: 14px; */
      /* padding: 14px 12px 10px; */
      border: 1px solid #d7e1f2;
      border-radius: 14px;
      background: #ffffff;
      overflow: hidden;
    }

    .stats-chart-title {
      /* margin: 0 0 14px; */
      text-align: center;
      /* font-size: 22px; */
      line-height: 1.15;
      font-weight: 400;
      color: #5a5f68;
      letter-spacing: -0.02em;
    }

    .stats-chart-body {
      display: grid;
      grid-template-columns: 34px 46px minmax(0, 1fr);
      gap: 1px;
      align-items: stretch;
    }

    .stats-y-label {
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      color: #5a5f68;
      line-height: 1;
      writing-mode: vertical-rl;
      transform: rotate(180deg);
      text-align: center;
      padding-bottom: 34px;
    }

    .stats-y-axis {
      display: grid;
      grid-template-rows: repeat(6, 1fr);
      align-items: end;
      padding-bottom: 34px;
    }

    .stats-y-tick {
      font-size: 11px;
      color: #5a5f68;
      line-height: 1;
      text-align: right;
      padding-right: 6px;
    }

    .stats-plot {
      position: relative;
      height: 240px;
      border-left: 1px solid #cfd5de;
      border-bottom: 1px solid #cfd5de;
      background:
        repeating-linear-gradient(
          to top,
          transparent 0,
          transparent 39px,
          #d8dbe2 39px,
          #d8dbe2 40px
        );
      padding: 10px 10px 0 14px;
    }

    .stats-bars {
      position: absolute;
      left: 14px;
      right: 10px;
      bottom: 25px;
      /* top: 10px; */
      display: grid;
      grid-template-columns: repeat(8, minmax(0, 1fr));
      gap: 8px;
      align-items: end;
    }

    .stats-bar-col {
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: end;
      align-items: center;
      gap: 1px;
      min-width: 0;
    }

    .stats-bar-value {
      font-size: 11px;
      font-weight: 700;
      color: #5a5f68;
      line-height: 1;
    }

    .stats-bar {
      width: min(100%, 32px);
      min-height: 6px;
      height: 6px;
      background: #5b86bd;
      border-radius: 0;
      transition: height .2s ease;
    }

    .stats-x-axis {
      position: absolute;
      left: 14px;
      right: 10px;
      bottom: 0;
      height: 34px;
      display: grid;
      grid-template-columns: repeat(8, minmax(0, 1fr));
      gap: 8px;
      align-items: center;
    }

    .stats-x-label {
      text-align: center;
      font-size: 11px;
      color: #5a5f68;
      line-height: 1.1;
      white-space: nowrap;
    }

    .comment-list {
      margin-top: 10px;
      display: grid;
      gap: 8px;
      max-height: 168px;
      overflow-y: auto;
      padding-right: 4px;
    }

    .comment-item {
      border: 1px solid #d7e1f2;
      border-radius: 10px;
      padding: 8px 9px;
      background: #fff;
    }

    .comment-author {
      font-size: 11px;
      font-weight: 800;
      color: #233a62;
      margin-bottom: 4px;
    }

    .comment-body {
      font-size: 12px;
      color: #39465b;
      line-height: 1.3;
    }

    .comment-form {
      margin-top: 10px;
      display: grid;
      gap: 8px;
    }

    .comment-input {
      width: 100%;
      min-height: 52px;
      border: 1px solid #cbd4e1;
      border-radius: 8px;
      padding: 9px 11px;
      resize: vertical;
      font-size: 12px;
    }

    .comment-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
    }

    .status-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 9px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      background: #edf3ff;
      color: #2b5dad;
      margin-top: 6px;
    }

    .status-chip.live {
      background: #ffe7e4;
      color: #c44b42;
    }

    .status-chip.scheduled {
      background: #fff2d8;
      color: #9a6900;
    }

    .status-chip.draft {
      background: #eef2f7;
      color: #5b6474;
    }

    .studio-note {
      border: 1px solid #d8e0ef;
      border-radius: 10px;
      background: #f8fbff;
      padding: 8px 10px;
      font-size: 11px;
      color: #506079;
      line-height: 1.3;
    }

    .studio-feedback {
      min-height: 16px;
      font-size: 11px;
      color: #506079;
    }

    .studio-feedback.error {
      color: #c14643;
    }

    .studio-feedback.success {
      color: #2f7a4f;
    }

    .studio-live-modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: stretch;
      justify-content: center;
      padding: 0;
      background: rgba(6, 10, 18, 0.92);
      z-index: 2500;
    }

    .studio-live-modal.is-open {
      display: flex;
    }

    .studio-live-modal-dialog {
      position: relative;
      width: 100vw;
      height: 100vh;
      background: #11131a;
      overflow: hidden;
      box-shadow: 0 30px 110px rgba(0, 0, 0, .48);
      display: grid;
      grid-template-rows: 60px minmax(0, 1fr) 66px;
      row-gap: 0;
    }

    .studio-live-modal-top {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: center;
      gap: 18px;
      padding: 0 12px;
      background: #171822;
      border-bottom: 1px solid rgba(255,255,255,.06);
      color: #fff;
    }

    .studio-live-modal-top-left {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
    }

    .studio-live-modal-branding {
      min-width: 0;
      display: grid;
      gap: 2px;
    }

    .studio-live-modal-title-row {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }

    .studio-live-modal-title {
      font-size: 27px;
      font-weight: 800;
      letter-spacing: -.03em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .studio-live-modal-live-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      height: 22px;
      padding: 0 10px;
      border-radius: 999px;
      background: #ff5c3d;
      color: #fff;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: .04em;
      text-transform: uppercase;
      flex: 0 0 auto;
    }

    .studio-live-modal-subtitle {
      font-size: 13px;
      color: rgba(255,255,255,.7);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .studio-live-modal-top-right {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
    }

    .studio-live-modal-top-center {
      font-size: 12px;
      color: rgba(255,255,255,.72);
      font-weight: 700;
      white-space: nowrap;
    }

    .studio-live-speaker-btn {
      display: inline-grid;
      place-items: center;
      width: 42px;
      height: 42px;
      padding: 0;
      border: 0;
      border-radius: 10px;
      background: rgba(255,255,255,.07);
      color: #fff;
      font-size: 16px;
      cursor: pointer;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.05);
    }

    .studio-live-top-end {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 0 18px;
      border: 0;
      border-radius: 12px;
      background: #6f3c2f;
      color: #fff;
      font-size: 14px;
      font-weight: 800;
      cursor: pointer;
    }

    .studio-live-request-dock {
      position: absolute;
      top: 20px;
      right: 20px;
      width: min(420px, calc(100% - 40px));
      z-index: 7;
      pointer-events: none;
      display: none;
    }

    .studio-live-request-dock.is-open {
      display: block;
    }

    .studio-live-request-dock .studio-live-request-card {
      pointer-events: auto;
      margin-bottom: 0;
    }

    .studio-live-modal-stage {
      position: relative;
      padding: 0;
      background: linear-gradient(180deg, var(--stage-fill) 0%, var(--stage-fill-dark) 100%);
      width: 100%;
      height: 100%;
      min-height: 0;
      overflow: hidden;
    }
    .studio-live-modal-stage::after {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      opacity: 0;
      transition: opacity .18s ease;
    }
    .studio-live-modal-camera-off {
      position: absolute;
      inset: 0;
      z-index: 3;
      display: none;
      align-items: center;
      justify-content: center;
      pointer-events: none;
      background: #000;
      overflow: hidden;
    }
    .studio-live-modal-stage.is-camera-off .studio-live-modal-camera-off {
      display: flex;
    }
    .studio-live-modal-stage.is-camera-off .studio-live-modal-frame,
    .studio-live-modal-stage.is-camera-off .studio-live-modal-local-video,
    .studio-live-modal-stage.is-camera-off .studio-live-host-chip,
    .studio-live-modal-stage.is-camera-off .studio-live-stage-reactions {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .studio-live-modal-camera-off::before {
      content: '';
      position: absolute;
      left: 50%;
      top: 50%;
      width: min(78%, 240px);
      height: 3px;
      border-radius: 999px;
      background: #ff4d3b;
      transform: translate(-50%, -50%) rotate(-28deg);
      box-shadow: 0 0 0 1px rgba(255, 77, 59, 0.12);
    }
    .studio-live-modal-camera-off-icon {
      position: relative;
      width: 68px;
      height: 52px;
      color: #f2f5fb;
      display: grid;
      place-items: center;
      filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.42));
    }
    .studio-live-modal-camera-off-icon .fa {
      font-size: 40px;
      line-height: 1;
    }

    .studio-live-modal-stage.is-camera-off .studio-live-modal-camera-off,
    .studio-live-modal-stage.is-camera-off .studio-live-modal-local-video {
      border-radius: 0;
      border: 1px solid rgba(255,255,255,.14);
      box-shadow: none;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-three-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-four-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-five-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-six-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-seven-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-eight-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-nine-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-ten-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-eleven-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twelve-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-fourteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twenty-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-gallery-stage .studio-live-modal-camera-off {
      inset: 18px auto 86px 18px;
      width: calc(50% - 27px);
      height: calc(100% - 104px);
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-three-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-four-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-five-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-six-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-seven-stage .studio-live-modal-camera-off {
      inset: 0 auto 0 0;
      width: calc(50% - 1px);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-nine-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-ten-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-eleven-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twelve-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-fourteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twenty-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-modal-camera-off,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-modal-camera-off {
      inset: 0 auto 0 0;
      width: var(--studio-stage-main-width);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }
    .studio-live-stage-reactions {
      position: absolute;
      inset: 0;
      z-index: 6;
      pointer-events: none;
      overflow: hidden;
    }
    .studio-live-stage-reaction {
      position: absolute;
      right: 34px;
      bottom: 80px;
      width: 68px;
      height: 68px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 42px;
      line-height: 1;
      background: radial-gradient(circle at 35% 30%, rgba(255,255,255,.96), rgba(255,255,255,.72));
      box-shadow: 0 14px 28px rgba(0,0,0,.18);
      animation: studioLiveReactionFloat 5s ease-out forwards;
      filter: drop-shadow(0 12px 18px rgba(255,255,255,.15));
      opacity: 0;
    }
    .studio-live-stage-reaction.is-love {
      color: #ec4899;
    }
    @keyframes studioLiveReactionFloat {
      0% { opacity: 0; transform: translate3d(0, 18px, 0) scale(.78); filter: blur(5px); }
      12% { opacity: 1; transform: translate3d(0, 0, 0) scale(1); filter: blur(0); }
      82% { opacity: 1; transform: translate3d(-10px, -126px, 0) scale(1.04); filter: blur(0); }
      100% { opacity: 0; transform: translate3d(-18px, -168px, 0) scale(1.08); filter: blur(8px); }
    }

    .studio-live-host-chip {
      position: absolute;
      top: 16px;
      left: 18px;
      z-index: 5;
      display: none;
      align-items: center;
      gap: 8px;
      color: #fff;
      font-size: 13px;
      font-weight: 800;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      pointer-events: none;
    }

    .studio-live-host-chip-avatar {
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: rgba(17, 24, 39, .74);
      color: #fff;
      display: grid;
      place-items: center;
      font-size: 11px;
      font-weight: 900;
    }

    .studio-live-modal-local-video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      display: none;
      background: var(--stage-fill);
      object-fit: cover;
      z-index: 2;
      transition: opacity .18s ease;
    }

    .studio-live-modal-stage.is-owner-live .studio-live-modal-local-video {
      display: block;
    }

    .studio-live-guest-layer {
      position: absolute;
      right: 24px;
      bottom: 90px;
      z-index: 4;
      display: grid;
      gap: 12px;
      justify-items: end;
      pointer-events: none;
    }

    .studio-live-guest-tile {
      width: 188px;
      border-radius: 18px;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,.18);
      background: rgba(10, 18, 34, .92);
      box-shadow: 0 18px 34px rgba(0,0,0,.28);
    }

    .studio-live-guest-video {
      width: 100%;
      height: 132px;
      display: block;
      object-fit: cover;
      background: #000;
    }

    .studio-live-guest-image {
      width: 100%;
      height: 132px;
      display: none;
      object-fit: cover;
      background: #000;
    }

    .studio-live-guest-placeholder {
      width: 100%;
      height: 132px;
      display: none;
      align-items: center;
      justify-content: center;
      background:
        radial-gradient(160px 110px at 50% 18%, rgba(255,255,255,.16), transparent 62%),
        linear-gradient(180deg, #232a37 0%, #121720 100%);
      color: #fff;
    }

    .studio-live-guest-placeholder-badge {
      width: 58px;
      height: 58px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      font-weight: 900;
      letter-spacing: .04em;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.16);
      box-shadow: 0 10px 24px rgba(0,0,0,.22);
    }

    .studio-live-guest-camera-off {
      position: absolute;
      inset: 0 0 auto 0;
      height: 132px;
      z-index: 2;
      display: none;
      align-items: center;
      justify-content: center;
      pointer-events: none;
      background: #000;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-three-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-four-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-five-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-six-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-fourteen-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-twenty-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-guest-camera-off,
    .studio-live-modal-stage.has-gallery-stage .studio-live-guest-camera-off {
      inset: 0;
      height: 100%;
    }

    .studio-live-guest-camera-off::before {
      content: '';
      position: absolute;
      left: 50%;
      top: 50%;
      width: min(78%, 240px);
      height: 3px;
      border-radius: 999px;
      background: #ff4d3b;
      transform: translate(-50%, -50%) rotate(-28deg);
      box-shadow: 0 0 0 1px rgba(255, 77, 59, 0.12);
    }

    .studio-live-guest-camera-off-icon {
      position: relative;
      width: 68px;
      height: 52px;
      color: #f2f5fb;
      display: grid;
      place-items: center;
      filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.42));
    }

    .studio-live-guest-camera-off-icon .fa {
      font-size: 40px;
      line-height: 1;
    }

    .studio-live-guest-tile.has-snapshot .studio-live-guest-video {
      display: none;
    }

    .studio-live-guest-tile.has-snapshot .studio-live-guest-image {
      display: block;
    }

    .studio-live-guest-tile.has-snapshot:not(.snapshot-ready) .studio-live-guest-image {
      display: none;
    }

    .studio-live-guest-tile.has-snapshot:not(.snapshot-ready) .studio-live-guest-placeholder {
      display: flex;
    }

    .studio-live-guest-tile.is-camera-off .studio-live-guest-video,
    .studio-live-guest-tile.is-camera-off .studio-live-guest-image,
    .studio-live-guest-tile.is-camera-off .studio-live-guest-placeholder {
      display: none !important;
    }

    .studio-live-guest-tile.is-camera-off .studio-live-guest-camera-off {
      display: flex;
    }

    .studio-live-guest-meta {
      padding: 10px 12px;
      color: #fff;
    }

    .studio-live-guest-name {
      font-size: 13px;
      font-weight: 900;
    }

    .studio-live-guest-state {
      margin-top: 4px;
      font-size: 11px;
      color: rgba(255,255,255,.68);
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-three-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-four-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-five-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-six-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-seven-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-eight-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-nine-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-ten-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-eleven-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twelve-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-gallery-stage .studio-live-modal-local-video {
      inset: 18px auto 86px 18px;
      width: calc(50% - 27px);
      height: calc(100% - 104px);
      border-radius: 0;
      border: 1px solid rgba(255,255,255,.14);
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-three-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-four-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-five-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-six-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-gallery-stage .studio-live-guest-layer {
      top: 18px;
      right: 18px;
      bottom: 86px;
      width: calc(50% - 27px);
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-video {
      height: 100%;
      min-height: 240px;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-image {
      height: 100%;
      min-height: 240px;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: calc(50% - 1px);
      height: 100%;
      border: 0;
      border-right: 0;
    }
    .studio-live-modal-stage.has-dual-stage::after {
      left: calc(50% - 1px);
      right: auto;
      width: 2px;
      background: var(--stage-divider);
      opacity: 1;
      z-index: 4;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: calc(50% - 1px);
      gap: 0;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-tile {
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-video {
      min-height: 100%;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-meta {
      display: none;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-guest-state {
      display: none;
    }

    .studio-live-modal-stage.has-dual-stage .studio-live-host-chip {
      display: none;
    }

    .studio-live-modal-stage.has-three-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: calc(50% - 1px);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-three-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: calc(50% - 1px);
      grid-template-columns: 1fr;
      grid-template-rows: repeat(2, minmax(0, 1fr));
      gap: 0;
    }

    .studio-live-modal-stage.has-three-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .studio-live-modal-stage.has-three-stage .studio-live-guest-video {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-three-stage .studio-live-guest-image {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-three-stage .studio-live-guest-tile:first-child {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-three-stage .studio-live-guest-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .studio-live-modal-stage.has-three-stage .studio-live-guest-state {
      display: none;
    }

    .studio-live-modal-stage.has-three-stage .studio-live-host-chip {
      display: inline-flex;
    }

    .studio-live-modal-stage.has-four-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(2, minmax(0, 1fr));
      gap: 0;
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-tile:nth-child(1) {
      grid-column: 1 / span 2;
      grid-row: 1;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 2;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 2;
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-video {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-image {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .studio-live-modal-stage.has-four-stage .studio-live-guest-state {
      display: none;
    }

    .studio-live-modal-stage.has-four-stage .studio-live-host-chip {
      display: inline-flex;
    }

    .studio-live-modal-stage.has-five-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-five-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(2, minmax(0, 1fr));
      gap: 0;
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-five-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .studio-live-modal-stage.has-five-stage .studio-live-guest-tile:nth-child(-n+2) {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-five-stage .studio-live-guest-tile:nth-child(odd) {
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-five-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-five-stage .studio-live-guest-image {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-five-stage .studio-live-guest-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .studio-live-modal-stage.has-five-stage .studio-live-guest-state {
      display: none;
    }

    .studio-live-modal-stage.has-five-stage .studio-live-host-chip {
      display: inline-flex;
    }

    .studio-live-modal-stage.has-six-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(6, minmax(0, 1fr));
      gap: 0;
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-tile:nth-child(1) {
      grid-column: 1;
      grid-row: 1 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-tile:nth-child(2) {
      grid-column: 1;
      grid-row: 4 / span 3;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-tile:nth-child(3) {
      grid-column: 2;
      grid-row: 1 / span 2;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-tile:nth-child(4) {
      grid-column: 2;
      grid-row: 3 / span 2;
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-tile:nth-child(5) {
      grid-column: 2;
      grid-row: 5 / span 2;
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-six-stage .studio-live-guest-image {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .studio-live-modal-stage.has-six-stage .studio-live-guest-state {
      display: none;
    }

    .studio-live-modal-stage.has-six-stage .studio-live-host-chip {
      display: inline-flex;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
      gap: 0;
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-video {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-image {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-image {
      object-fit: contain;
      object-position: center top;
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-meta {
      position: absolute;
      top: 16px;
      left: 18px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-state {
      display: none;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-host-chip {
      display: inline-flex;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(-n+4) {
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(odd) {
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-eight-stage,
    .studio-live-modal-stage.has-nine-stage,
    .studio-live-modal-stage.has-ten-stage,
    .studio-live-modal-stage.has-eleven-stage,
    .studio-live-modal-stage.has-twelve-stage,
    .studio-live-modal-stage.has-thirteen-stage {
      --studio-stage-main-width: 40%;
      --studio-stage-guest-width: 60%;
      --studio-stage-grid-columns: 4;
      --studio-stage-grid-rows: 2;
    }

    .studio-live-modal-stage.has-eight-stage {
      --studio-stage-main-width: 50%;
      --studio-stage-guest-width: 50%;
      --studio-stage-grid-columns: 2;
      --studio-stage-grid-rows: 12;
    }

    .studio-live-modal-stage.has-nine-stage {
      --studio-stage-main-width: 50%;
      --studio-stage-guest-width: 50%;
      --studio-stage-grid-columns: 2;
      --studio-stage-grid-rows: 4;
    }

    .studio-live-modal-stage.has-ten-stage {
      --studio-stage-main-width: 50%;
      --studio-stage-guest-width: 50%;
      --studio-stage-grid-columns: 6;
      --studio-stage-grid-rows: 12;
    }

    .studio-live-modal-stage.has-eleven-stage,
    .studio-live-modal-stage.has-twelve-stage,
    .studio-live-modal-stage.has-thirteen-stage {
      --studio-stage-main-width: 36%;
      --studio-stage-guest-width: 64%;
      --studio-stage-grid-columns: 4;
      --studio-stage-grid-rows: 3;
    }

    .studio-live-modal-stage.has-eleven-stage {
      --studio-stage-main-width: 50%;
      --studio-stage-guest-width: 50%;
      --studio-stage-grid-columns: 2;
      --studio-stage-grid-rows: 5;
    }

    .studio-live-modal-stage.has-twelve-stage {
      --studio-stage-main-width: 50%;
      --studio-stage-guest-width: 50%;
      --studio-stage-grid-columns: 6;
      --studio-stage-grid-rows: 15;
    }

    .studio-live-modal-stage.has-thirteen-stage {
      --studio-stage-main-width: 50%;
      --studio-stage-guest-width: 50%;
      --studio-stage-grid-columns: 2;
      --studio-stage-grid-rows: 6;
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-nine-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-ten-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-eleven-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twelve-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: var(--studio-stage-main-width);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: var(--studio-stage-guest-width);
      display: grid;
      grid-template-columns: repeat(var(--studio-stage-grid-columns), minmax(0, 1fr));
      grid-template-rows: repeat(var(--studio-stage-grid-rows), minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      border: 0;
      box-shadow: none;
      background: #000;
      position: relative;
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-image {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-meta {
      position: absolute;
      top: 12px;
      left: 14px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-fourteen-stage,
    .studio-live-modal-stage.has-fifteen-stage,
    .studio-live-modal-stage.has-sixteen-stage,
    .studio-live-modal-stage.has-seventeen-stage,
    .studio-live-modal-stage.has-eighteen-stage,
    .studio-live-modal-stage.has-nineteen-stage,
    .studio-live-modal-stage.has-twenty-stage,
    .studio-live-modal-stage.has-twentyone-stage,
    .studio-live-modal-stage.has-twentytwo-stage,
    .studio-live-modal-stage.has-twentythree-stage,
    .studio-live-modal-stage.has-twentyfour-stage,
    .studio-live-modal-stage.has-twentyfive-stage {
      --studio-stage-main-width: 50%;
      --studio-stage-guest-width: 50%;
      --studio-stage-grid-columns: 4;
      --studio-stage-grid-rows: 6;
    }

    .studio-live-modal-stage.has-fourteen-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twenty-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: var(--studio-stage-main-width);
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-fourteen-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twenty-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      width: var(--studio-stage-guest-width);
      display: grid;
      grid-template-columns: repeat(var(--studio-stage-grid-columns), minmax(0, 1fr));
      grid-template-rows: repeat(var(--studio-stage-grid-rows), minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-fourteen-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twenty-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .studio-live-modal-stage.has-fourteen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-fourteen-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twenty-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twenty-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-guest-image {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-fourteen-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-fifteen-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-sixteen-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-seventeen-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-eighteen-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-nineteen-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-twenty-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-twentyone-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-twentytwo-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-twentythree-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-twentyfour-stage .studio-live-guest-meta,
    .studio-live-modal-stage.has-twentyfive-stage .studio-live-guest-meta {
      position: absolute;
      top: 12px;
      left: 14px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .studio-live-modal-stage.has-twentyplus-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 20%;
      height: 16.666667%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-layer {
      inset: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      grid-template-rows: repeat(6, minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile {
      width: 100%;
      height: 100%;
      min-height: 0;
      border-radius: 0;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      border: 0;
      box-shadow: none;
      background: var(--stage-fill);
      position: relative;
    }

    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-image {
      height: 100%;
      min-height: 100%;
    }

    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-meta {
      position: absolute;
      top: 12px;
      left: 14px;
      right: auto;
      bottom: auto;
      padding: 0;
      background: transparent;
      text-shadow: 0 1px 3px rgba(0,0,0,.48);
      z-index: 2;
    }

    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(1) { grid-column: 2; grid-row: 1; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(2) { grid-column: 3; grid-row: 1; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(3) { grid-column: 4; grid-row: 1; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(4) { grid-column: 5; grid-row: 1; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(5) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(6) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(7) { grid-column: 3; grid-row: 2; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(8) { grid-column: 4; grid-row: 2; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(9) { grid-column: 5; grid-row: 2; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(10) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(11) { grid-column: 2; grid-row: 3; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(12) { grid-column: 3; grid-row: 3; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(13) { grid-column: 4; grid-row: 3; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(14) { grid-column: 5; grid-row: 3; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(15) { grid-column: 1; grid-row: 4; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(16) { grid-column: 2; grid-row: 4; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(17) { grid-column: 3; grid-row: 4; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(18) { grid-column: 4; grid-row: 4; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(19) { grid-column: 5; grid-row: 4; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(20) { grid-column: 1; grid-row: 5; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(21) { grid-column: 2; grid-row: 5; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(22) { grid-column: 3; grid-row: 5; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(23) { grid-column: 4; grid-row: 5; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(24) { grid-column: 5; grid-row: 5; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(25) { grid-column: 1; grid-row: 6; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(26) { grid-column: 2; grid-row: 6; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(27) { grid-column: 3; grid-row: 6; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(28) { grid-column: 4; grid-row: 6; }
    .studio-live-modal-stage.has-twentyplus-stage .studio-live-guest-tile:nth-child(29) { grid-column: 5; grid-row: 6; }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-layer {
      inset: 0;
      width: 100%;
      gap: 2px;
      background: #0b1018;
      justify-items: stretch;
      align-items: stretch;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile {
      border: 0;
      box-shadow: none;
      background: #0f1622;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-image,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-image {
      object-fit: contain;
      object-position: center top;
      background: #0f1622;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-eight-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 25%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      background: #0f1622;
    }

    .studio-live-modal-stage.has-nine-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 33.333333%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      background: #0f1622;
    }

    .studio-live-modal-stage.has-ten-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 20%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      background: #0f1622;
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-modal-local-video,
    .studio-live-modal-stage.has-twelve-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 25%;
      height: 33.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .9);
      border-bottom: 2px solid rgba(8, 12, 20, .9);
      background: #0f1622;
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-layer {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(2, minmax(0, 1fr));
    }

    .studio-live-modal-stage.has-nine-stage .studio-live-guest-layer {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
    }

    .studio-live-modal-stage.has-ten-stage .studio-live-guest-layer {
      grid-template-columns: repeat(5, minmax(0, 1fr));
      grid-template-rows: repeat(2, minmax(0, 1fr));
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-layer,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-layer {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(1),
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(1),
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(1),
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(1),
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(1),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(1) { grid-column: 2; grid-row: 1; }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(2),
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(2) { grid-column: 3; grid-row: 1; }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(3),
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(3) { grid-column: 4; grid-row: 1; }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(4),
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(4) { grid-column: 1; grid-row: 2; }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(5),
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(5) { grid-column: 2; grid-row: 2; }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(6),
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(6) { grid-column: 3; grid-row: 2; }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(7) { grid-column: 4; grid-row: 2; }

    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(2),
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(2),
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(2),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(2) { grid-column: 3; grid-row: 1; }

    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(5) { grid-column: 3; grid-row: 2; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(6) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(7) { grid-column: 2; grid-row: 3; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(8) { grid-column: 3; grid-row: 3; }

    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(3) { grid-column: 4; grid-row: 1; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(4) { grid-column: 5; grid-row: 1; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(5) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(6) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(7) { grid-column: 3; grid-row: 2; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(8) { grid-column: 4; grid-row: 2; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(9) { grid-column: 5; grid-row: 2; }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(3),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(3) { grid-column: 4; grid-row: 1; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(4),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(4) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(5),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(5) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(6),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(6) { grid-column: 3; grid-row: 2; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(7),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(7) { grid-column: 4; grid-row: 2; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(8),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(8) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(9),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(9) { grid-column: 2; grid-row: 3; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(10),
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(10) { grid-column: 3; grid-row: 3; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(11) { grid-column: 4; grid-row: 3; }

    .studio-live-modal-stage.has-seven-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: auto;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-image {
      object-fit: cover;
      object-position: center center;
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(1) { grid-column: 1; grid-row: 1; }
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(2) { grid-column: 2; grid-row: 1; }
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(5) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-seven-stage .studio-live-guest-tile:nth-child(6) { grid-column: 2; grid-row: 3; }

    .studio-live-modal-stage.has-eight-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 57.142857%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-layer {
      inset: 0 0 0 50%;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(3, minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-image {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-image {
      object-fit: cover;
      object-position: center center;
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(1) {
      position: absolute;
      top: 57.142857%;
      left: -100%;
      width: 100%;
      height: 42.857143%;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(2) { grid-column: 1; grid-row: 1; }
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(3) { grid-column: 2; grid-row: 1; }
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(4) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(5) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(6) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-eight-stage .studio-live-guest-tile:nth-child(7) { grid-column: 2; grid-row: 3; }

    .studio-live-modal-stage.has-nine-stage .studio-live-modal-local-video {
      inset: 0 auto 0 0;
      width: 50%;
      height: 100%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-nine-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: auto;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(4, minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-image {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-nine-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-image {
      object-fit: cover;
      object-position: center center;
    }

    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(1) { grid-column: 1; grid-row: 1; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(2) { grid-column: 2; grid-row: 1; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(3) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(4) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(5) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(6) { grid-column: 2; grid-row: 3; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(7) { grid-column: 1; grid-row: 4; }
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-tile:nth-child(8) { grid-column: 2; grid-row: 4; }

    .studio-live-modal-stage.has-ten-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 53.333333%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-ten-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: auto;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(4, minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-image {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-ten-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-image {
      object-fit: cover;
      object-position: center center;
    }

    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(1) {
      position: absolute;
      top: 53.333333%;
      left: -100%;
      width: 100%;
      height: 46.666667%;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
    }

    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(2) { grid-column: 1; grid-row: 1; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(3) { grid-column: 2; grid-row: 1; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(4) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(5) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(6) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(7) { grid-column: 2; grid-row: 3; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(8) { grid-column: 1; grid-row: 4; }
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-tile:nth-child(9) { grid-column: 2; grid-row: 4; }

    .studio-live-modal-stage.has-eleven-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 60%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-layer {
      top: 0;
      right: 0;
      bottom: 0;
      left: auto;
      width: 50%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: repeat(4, minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-image {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-image {
      object-fit: cover;
      object-position: center center;
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(1) {
      position: absolute;
      top: 60%;
      left: -100%;
      width: 50%;
      height: 40%;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(2) {
      position: absolute;
      top: 60%;
      left: -50%;
      width: 50%;
      height: 40%;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 0;
    }

    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(3) { grid-column: 1; grid-row: 1; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(4) { grid-column: 2; grid-row: 1; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(5) { grid-column: 1; grid-row: 2; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(6) { grid-column: 2; grid-row: 2; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(7) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(8) { grid-column: 2; grid-row: 3; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(9) { grid-column: 1; grid-row: 4; }
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-tile:nth-child(10) { grid-column: 2; grid-row: 4; }

    .studio-live-modal-stage.has-twelve-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
      z-index: 2;
    }

    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-layer {
      inset: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(4, minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-image {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-image {
      object-fit: cover;
      object-position: center center;
    }

    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(1) { grid-column: 3; grid-row: 1; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(3) { grid-column: 3; grid-row: 2; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(4) { grid-column: 4; grid-row: 2; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(5) { grid-column: 1; grid-row: 3 / span 2; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(6) { grid-column: 2; grid-row: 3; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(7) { grid-column: 3; grid-row: 3; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(8) { grid-column: 4; grid-row: 3; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(9) { grid-column: 2; grid-row: 4; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(10) { grid-column: 3; grid-row: 4; }
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-tile:nth-child(11) { grid-column: 4; grid-row: 4; }

    .studio-live-modal-stage.has-thirteen-stage .studio-live-modal-local-video {
      inset: 0 auto auto 0;
      width: 50%;
      height: 50%;
      border: 0;
      border-right: 2px solid rgba(8, 12, 20, .8);
      border-bottom: 2px solid rgba(8, 12, 20, .8);
      background: var(--stage-fill);
      z-index: 2;
    }

    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-layer {
      inset: 0;
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      grid-template-rows: repeat(4, minmax(0, 1fr));
      gap: 2px;
      background: rgba(8, 12, 20, .8);
    }

    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-image {
      background: var(--stage-fill);
    }

    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-video,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-image {
      object-fit: cover;
      object-position: center center;
    }

    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(1) { grid-column: 3; grid-row: 1; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(2) { grid-column: 4; grid-row: 1; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(3) { grid-column: 3; grid-row: 2; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(4) { grid-column: 4; grid-row: 2; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(5) { grid-column: 1; grid-row: 3; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(6) { grid-column: 2; grid-row: 3; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(7) { grid-column: 3; grid-row: 3; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(8) { grid-column: 4; grid-row: 3; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(9) { grid-column: 1; grid-row: 4; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(10) { grid-column: 2; grid-row: 4; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(11) { grid-column: 3; grid-row: 4; }
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-tile:nth-child(12) { grid-column: 4; grid-row: 4; }

    .studio-live-modal-stage.has-eight-stage .studio-live-guest-state,
    .studio-live-modal-stage.has-nine-stage .studio-live-guest-state,
    .studio-live-modal-stage.has-ten-stage .studio-live-guest-state,
    .studio-live-modal-stage.has-eleven-stage .studio-live-guest-state,
    .studio-live-modal-stage.has-twelve-stage .studio-live-guest-state,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-guest-state {
      display: none;
    }

    .studio-live-modal-stage.has-eight-stage .studio-live-host-chip,
    .studio-live-modal-stage.has-nine-stage .studio-live-host-chip,
    .studio-live-modal-stage.has-ten-stage .studio-live-host-chip,
    .studio-live-modal-stage.has-eleven-stage .studio-live-host-chip,
    .studio-live-modal-stage.has-twelve-stage .studio-live-host-chip,
    .studio-live-modal-stage.has-thirteen-stage .studio-live-host-chip {
      display: inline-flex;
    }

    .studio-live-modal-stage.has-gallery-stage .studio-live-guest-layer {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-auto-rows: minmax(148px, 1fr);
      gap: 12px;
      overflow: auto;
      align-content: start;
    }

    .studio-live-modal-stage.has-gallery-stage .studio-live-guest-tile {
      width: 100%;
      height: auto;
      min-height: 0;
      border-radius: 14px;
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
    }

    .studio-live-modal-stage.has-gallery-stage .studio-live-guest-video {
      height: 100%;
      min-height: 148px;
    }

    .studio-live-modal-stage.has-gallery-stage .studio-live-guest-image {
      height: 100%;
      min-height: 148px;
    }


    .studio-live-modal-body {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 0;
      min-height: 0;
      background: #0e1118;
      transition: grid-template-columns .22s ease;
      position: relative;
    }

    .studio-live-modal-dialog.has-chat .studio-live-modal-body {
      grid-template-columns: minmax(0, 1fr) 360px;
    }

    .studio-live-modal-frame {
      width: 100%;
      height: 100%;
      display: block;
      background: #000;
      transition: opacity .18s ease;
    }
    .studio-live-modal-stage.is-direct-stage .studio-live-modal-frame {
      opacity: 0;
      pointer-events: none;
    }

    .studio-live-modal-stage.is-owner-live .studio-live-modal-frame {
      opacity: 0;
      pointer-events: none;
    }

    .studio-live-sidebar {
      min-width: 0;
      background: #050505;
      border-left: 1px solid rgba(255,255,255,.14);
      color: #f5f5f5;
      display: none;
      grid-template-rows: auto minmax(0, 1fr) auto;
      height: 100%;
      overflow: hidden;
    }

    .studio-live-modal-dialog.has-chat .studio-live-sidebar {
      display: grid;
    }

    .studio-live-modal-dialog.sidebar-mode-reactions #studioLiveChatPanel,
    .studio-live-modal-dialog.sidebar-mode-reactions #studioLiveCompose,
    .studio-live-modal-dialog.sidebar-mode-reactions #studioLiveDescriptionPanel {
      display: none;
    }

    .studio-live-modal-dialog.sidebar-mode-reactions #studioLiveReactionPanel {
      display: flex;
    }

    .studio-live-modal-dialog.sidebar-mode-chat #studioLiveReactionPanel {
      display: none;
    }

    .studio-live-modal-dialog.sidebar-mode-chat #studioLiveDescriptionPanel,
    .studio-live-modal-dialog.sidebar-mode-description #studioLiveChatPanel,
    .studio-live-modal-dialog.sidebar-mode-description #studioLiveReactionPanel,
    .studio-live-modal-dialog.sidebar-mode-description #studioLiveSettingsPanel,
    .studio-live-modal-dialog.sidebar-mode-description #studioLiveCompose {
      display: none;
    }

    .studio-live-modal-dialog.sidebar-mode-description #studioLiveDescriptionPanel {
      display: flex;
    }

    .studio-live-modal-dialog.sidebar-mode-settings #studioLiveChatPanel,
    .studio-live-modal-dialog.sidebar-mode-settings #studioLiveReactionPanel,
    .studio-live-modal-dialog.sidebar-mode-settings #studioLiveDescriptionPanel,
    .studio-live-modal-dialog.sidebar-mode-settings #studioLiveCompose {
      display: none;
    }

    .studio-live-modal-dialog.sidebar-mode-settings #studioLiveSettingsPanel {
      display: block;
    }

    .studio-live-modal-dialog.sidebar-mode-description .studio-live-side-stats {
      display: none;
    }

    .studio-live-modal-dialog.sidebar-mode-settings .studio-live-side-stats {
      display: none;
    }

    .studio-live-modal-dialog.sidebar-mode-description .studio-live-side-scroll {
      padding: 0;
      background: #252a30;
    }

    .studio-live-modal-dialog.sidebar-mode-settings .studio-live-side-scroll {
      padding: 0;
      background: #252a30;
      overflow-y: auto;
    }

    .studio-live-modal-dialog.sidebar-mode-description .studio-live-side-head {
      position: relative;
      min-height: 0;
      padding: 0;
      border-bottom: 0;
      background: transparent;
    }

    .studio-live-modal-dialog.sidebar-mode-settings .studio-live-side-head {
      position: relative;
      min-height: 0;
      padding: 20px 24px 14px;
      border-bottom: 0;
      background: #252a30;
    }

    .studio-live-modal-dialog.sidebar-mode-description .studio-live-side-close {
      position: absolute;
      top: 36px;
      right: 24px;
      z-index: 3;
    }

    .studio-live-modal-dialog.sidebar-mode-description .studio-live-side-title {
      display: none;
    }

    .studio-live-modal-dialog.sidebar-mode-settings .studio-live-side-title {
      display: flex;
    }

    .studio-live-side-head {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: start;
      gap: 14px;
      padding: 26px 28px 18px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      background: #050505;
    }

    .studio-live-side-title {
      min-width: 0;
      display:flex;
      align-items:flex-end;
      gap:10px;
      font-size: 19px;
      font-weight: 900;
      line-height: 1.1;
    }

    .studio-live-side-title strong {
      display: block;
      color: #f8fafc;
      font-size:22px;
      font-weight:900;
      letter-spacing:-.02em;
    }

    .studio-live-side-title span {
      color: rgba(255,255,255,.38);
      font-size:18px;
      font-weight:800;
    }

    .studio-live-side-badge {
      display:none;
    }

    .studio-live-side-close {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      border: 0;
      background: rgba(255,255,255,.12);
      color: #f8fafc;
      font-size: 30px;
      line-height: 1;
      cursor: pointer;
    }

    .studio-live-side-stats {
      display: none;
    }

    .studio-live-side-stat {
      padding: 16px 8px 14px;
      text-align: center;
      font-weight: 800;
      color: rgba(218, 224, 235, .8);
      font-size: 13px;
    }

    .studio-live-side-stat strong {
      color: rgba(255,255,255,.92);
      font-size: 15px;
      margin-right: 4px;
    }

    .studio-live-side-scroll {
      flex: 1 1 auto;
      min-height: 0;
      padding: 14px 18px 0 28px;
      background: #050505;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .studio-live-request-card {
      margin: 0 0 20px;
      background: #f6f8fd;
      border: 1px solid #d9e2f1;
      border-radius: 22px;
      padding: 18px 18px 18px;
      color: #1a2130;
      box-shadow: 0 12px 28px rgba(0,0,0,.12);
    }

    .studio-live-request-card h4 {
      margin: 0 0 8px;
      font-size: 18px;
      font-weight: 900;
    }

    .studio-live-request-help {
      margin: 0;
      color: #67758f;
      font-size: 13px;
      line-height: 1.75;
    }

    .studio-live-request-help .accent {
      color: #dc5c53;
    }

    .studio-live-request-empty {
      margin-top: 18px;
      border: 1px dashed #c8d5ef;
      border-radius: 20px;
      padding: 16px 18px;
      color: #6f7d95;
      font-size: 14px;
      background: #fff;
    }

    .studio-live-request-list {
      margin-top: 10px;
      display: grid;
      gap: 12px;
    }

    .studio-live-request-item {
      border: 1px solid #d8e1f0;
      border-radius: 18px;
      padding: 14px 14px 12px;
      background: #fff;
    }

    .studio-live-request-name {
      font-size: 14px;
      font-weight: 900;
      color: #14203a;
    }

    .studio-live-request-sub {
      margin-top: 5px;
      font-size: 12px;
      color: #667892;
    }

    .studio-live-request-actions {
      margin-top: 12px;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .studio-live-request-btn {
      min-width: 102px;
      height: 38px;
      border-radius: 12px;
      border: 1px solid #cfd9ea;
      background: #fff;
      color: #30435f;
      font-size: 13px;
      font-weight: 900;
      cursor: pointer;
    }

    .studio-live-request-btn.confirm {
      border-color: #2e7be7;
      background: #2e7be7;
      color: #fff;
    }

    .studio-live-approved-list {
      margin: 0 0 12px;
      display: grid;
      gap: 10px;
    }

    .studio-live-approved-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 8px 12px;
      background: #eef6ff;
      color: #24539a;
      font-size: 12px;
      font-weight: 800;
    }

    .studio-live-comments-box {
      margin: 0;
      border: 0;
      border-radius: 0;
      background: transparent;
      min-height: 96px;
      padding: 10px 0 16px;
      color: #858b99;
      font-size: 14px;
      line-height: 1.5;
      margin-bottom: 0;
      flex: 1 1 auto;
      min-height: 0;
      display: flex;
      flex-direction: column;
    }

    .studio-live-comments-box.has-comments {
      color: #111827;
      padding: 0;
      background: transparent;
      border-style: solid;
      overflow: hidden;
    }

    .studio-live-comments-list {
      overflow-y: auto;
      padding: 2px 10px 22px 0;
      display: grid;
      gap: 30px;
      /* flex: 1 1 auto; */
      min-height: 0;
    }

    .studio-live-chat-panel {
      display: flex;
      flex-direction: column;
      min-height: 0;
      flex: 1 1 auto;
    }

    .studio-live-reaction-panel {
      display: none;
      flex-direction: column;
      min-height: 0;
      flex: 1 1 auto;
      padding: 4px 10px 18px 0;
    }

    .studio-live-description-panel {
      display: none;
      flex-direction: column;
      min-height: 0;
      flex: 1 1 auto;
      padding: 0;
      background: #252a30;
    }

    .studio-live-description-card {
      min-height: 0;
      height: 100%;
      display: grid;
      grid-template-rows: auto auto minmax(0, 1fr);
      background: #252a30;
    }

    .studio-live-description-head {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      align-items: center;
      gap: 16px;
      padding: 12px 10px 18px 10px;
    }

    .studio-live-description-avatar {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 22px;
      font-weight: 900;
      color: #f8fafc;
      background: radial-gradient(circle at 30% 30%, #44d1c3 0%, #1e8b98 45%, #103848 100%);
    }

    .studio-live-description-meta {
      min-width: 0;
    }

    .studio-live-description-title {
      margin: 0;
      font-size: 18px;
      line-height: 1.08;
      font-weight: 900;
      color: #f8fafc;
      letter-spacing: -.03em;
      word-break: break-word;
    }

    .studio-live-description-sub {
      margin-top: 6px;
      font-size: 12px;
      line-height: 1.35;
      color: rgba(255,255,255,.38);
      word-break: break-word;
    }

    .studio-live-description-divider {
      height: 1px;
      background: rgba(255,255,255,.06);
    }

    .studio-live-description-scroll {
      min-height: 0;
      overflow-y: auto;
      padding: 18px 10px 28px 10px;
    }

    .studio-live-description-body {
      margin: 0;
      font-size: 10px;
      line-height: 1.62;
      color: rgba(255,255,255,.92);
      white-space: pre-wrap;
      word-break: break-word;
    }

    .studio-live-reaction-tabs {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      padding: 4px 0 16px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      margin-bottom: 16px;
    }

    .studio-live-reaction-tab {
      border: 0;
      background: transparent;
      color: rgba(255,255,255,.7);
      font-size: 14px;
      font-weight: 800;
      cursor: pointer;
      padding: 0 0 8px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-bottom: 3px solid transparent;
    }

    .studio-live-reaction-tab.is-active {
      color: #3b82f6;
      border-bottom-color: #3b82f6;
    }

    .studio-live-reaction-list {
      min-height: 0;
      overflow-y: auto;
      display: grid;
      gap: 16px;
      padding-right: 4px;
    }

    .studio-live-reaction-item {
      display: grid;
      grid-template-columns: 56px minmax(0,1fr) auto;
      gap: 14px;
      align-items: center;
    }

    .studio-live-reaction-avatar {
      width: 56px;
      height: 56px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 20px;
      font-weight: 900;
      position: relative;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }

    .studio-live-reaction-badge {
      position: absolute;
      right: -2px;
      bottom: -2px;
      width: 24px;
      height: 24px;
      border-radius: 999px;
      background: #11131a;
      display: grid;
      place-items: center;
      font-size: 16px;
    }

    .studio-live-reaction-main {
      min-width: 0;
    }

    .studio-live-reaction-name {
      color: #f8fafc;
      font-size: 17px;
      font-weight: 800;
      line-height: 1.2;
      word-break: break-word;
    }

    .studio-live-reaction-time {
      margin-top: 4px;
      color: rgba(255,255,255,.46);
      font-size: 13px;
      font-weight: 700;
    }

    .studio-live-reaction-time .studio-live-reaction-type {
      color: rgba(255,255,255,.74);
      margin-right: 8px;
    }

    .studio-live-reaction-action {
      border: 0;
      border-radius: 14px;
      min-width: 144px;
      height: 54px;
      padding: 0 18px;
      background: rgba(255,255,255,.14);
      color: #f3f4f6;
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
    }

    .studio-live-reaction-action[disabled] {
      opacity: .72;
      cursor: default;
    }

    .studio-live-reaction-action.is-static {
      background: rgba(255,255,255,.08);
      color: rgba(255,255,255,.76);
    }

    .studio-live-reaction-empty {
      color: rgba(255,255,255,.56);
      font-size: 14px;
      font-weight: 700;
      padding: 8px 0;
    }

    .studio-live-comment-card {
      width: 100%;
      justify-self: stretch;
      background: transparent;
      border: 0;
      border-radius: 0;
      padding: 0;
      display: grid;
      grid-template-columns: 52px minmax(0, 1fr);
      gap: 14px;
      align-items: start;
    }

    .studio-live-comment-card.is-self {
      justify-self: stretch;
    }

    .studio-live-comment-avatar {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: linear-gradient(135deg, #ec4899 0%, #f97316 100%);
      color: #fff;
      font-size: 18px;
      font-weight: 900;
      display: grid;
      place-items: center;
      overflow: hidden;
      flex: 0 0 auto;
      box-shadow: 0 10px 30px rgba(0,0,0,.35);
    }

    .studio-live-comment-main {
      min-width: 0;
    }

    .studio-live-comment-author {
      font-size: 15px;
      font-weight: 800;
      margin-bottom: 8px;
      color: rgba(255,255,255,.62);
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .studio-live-comment-team {
      color: #6f85c9;
      font-size: 11px;
      font-weight: 700;
    }

    .studio-live-comment-body {
      font-size: 21px;
      color: #f8fafc;
      line-height: 1.42;
      letter-spacing: -.02em;
      word-break: break-word;
    }
    .studio-live-comment-meta {
      display:flex;
      align-items:center;
      gap:18px;
      margin-top:12px;
      color: rgba(255,255,255,.46);
      font-size:14px;
      font-weight:700;
    }
    .studio-live-comment-reply {
      border:0;
      background:transparent;
      color: rgba(255,255,255,.64);
      padding:0;
      font: inherit;
      font-weight:800;
      cursor:pointer;
    }
    .studio-live-comment-like {
      margin-left:auto;
      display:inline-flex;
      align-items:center;
      gap:8px;
      border:0;
      background:transparent;
      padding:0;
      font:inherit;
      appearance:none;
      color: rgba(255,255,255,.64);
      cursor:pointer;
    }
    .studio-live-comment-like i {
      font-size:18px;
    }
    .studio-live-comment-like-count {
      font-size:13px;
      font-weight:800;
      line-height:1;
    }
    .studio-live-comment-like.is-liked {
      color:#ec4899;
    }
    .studio-live-comment-like.is-liked i::before {
      content:"\f004";
    }
    .studio-live-comment-likes {
      margin-top:8px;
      color: rgba(255,255,255,.46);
      font-size:13px;
      font-weight:700;
      line-height:1.4;
    }

    .studio-live-chat-tabs {
      display: none;
    }

    .studio-live-compose {
      padding: 12px 20px 22px;
      border-top: 1px solid rgba(255,255,255,.08);
      background: #050505;
      position: relative;
      z-index: 2;
    }

    .studio-live-compose h4 {
      display: none;
    }

    .studio-live-compose textarea {
      width: 100%;
      min-height: 32px;
      max-height: 96px;
      border-radius: 0;
      border: 0;
      background: transparent;
      color: #f8fafc;
      padding: 4px 0 0;
      resize: none;
      font: inherit;
      outline: none;
      box-shadow: none;
      font-size: 18px;
      line-height: 24px;
      align-self: center;
      margin: 0;
    }

    .studio-live-compose textarea::placeholder {
      color: rgba(255,255,255,.34);
    }

    .studio-live-compose-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 52px;
      align-items: center;
      gap: 12px;
      margin-top: 0;
    }

    .studio-live-send-btn {
      width:52px;
      height:52px;
      border-radius:999px;
      border:0;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      font-size:28px;
      box-shadow: 0 10px 28px rgba(0,0,0,.32);
    }

    .studio-live-send-btn {
      background: linear-gradient(180deg, #7f1d1d 0%, #991b1b 100%);
      color:#fda4af;
      min-width:52px;
    }

    .studio-live-compose-inputwrap {
      display:flex;
      align-items:center;
      gap:12px;
      min-height:52px;
      padding: 0 14px 0 18px;
      border-radius:999px;
      background:#202020;
      border:1px solid rgba(255,255,255,.06);
    }

    .studio-live-compose-tool {
      border:0;
      background:transparent;
      color: rgba(255,255,255,.88);
      width:32px;
      height:32px;
      display:grid;
      place-items:center;
      font-size:20px;
      cursor:pointer;
      padding:0;
    }

    .studio-live-compose-feedback {
      min-height: 0;
      color: rgba(255,255,255,.54);
      font-size: 12px;
      margin-top: 0;
    }

    .studio-live-compose-feedback.error {
      color: #ff8d83;
    }

    .studio-live-compose-feedback.success {
      color: #90deac;
    }

    .studio-live-modal-bottom {
      position: relative;
      z-index: 6;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      min-height: 66px;
      background: #171822;
      color: #fff;
      border-top: 1px solid rgba(255,255,255,.08);
      box-shadow: 0 -10px 24px rgba(0, 0, 0, .18);
      overflow: hidden;
    }

    .studio-live-controls,
    .studio-live-controls-right {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .studio-live-controls-right {
      margin-left: auto;
      justify-content: flex-end;
      flex-wrap: nowrap;
      min-width: max-content;
    }

    .studio-live-control {
      border: 0;
      background: rgba(255,255,255,.04);
      color: #d9deea;
      display: grid;
      justify-items: center;
      gap: 3px;
      font-weight: 700;
      cursor: pointer;
      min-width: 62px;
      height: 48px;
      padding: 6px 10px;
      border-radius: 10px;
    }

    .studio-live-control i {
      position: relative;
      display: inline-block;
      font-size: 16px;
    }

    .studio-live-control i.has-off-slash::after {
      content: '';
      position: absolute;
      top: -2px;
      left: 50%;
      width: 3px;
      height: 22px;
      border-radius: 999px;
      background: #ff5c5c;
      transform: translateX(-50%) rotate(42deg);
      box-shadow: 0 0 0 1px rgba(12,16,24,.2);
    }

    .studio-live-control-label {
      font-size: 11px;
      font-weight: 700;
      line-height: 1.1;
      text-align: center;
    }

    .studio-live-control-count {
      display: inline;
      margin-top: 0;
      font-size: 11px;
      color: rgba(255,255,255,.82);
    }

    .studio-live-control.is-active {
      background: rgba(255,255,255,.1);
      color: #fff;
    }

    .studio-live-end {
      border: 0;
      background: #6f3c2f;
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-weight: 800;
      cursor: pointer;
      min-width: 132px;
      height: 44px;
      padding: 0 18px;
      border-radius: 12px;
    }

    .studio-live-end i {
      font-size: 16px;
    }

    .studio-live-end .studio-live-control-label {
      color: #fff;
    }

    .studio-live-settings-panel {
      display: none;
      min-height: 100%;
      background: #252a30;
    }

    .studio-live-settings-body {
      display: grid;
      gap: 14px;
      padding: 16px 18px 18px;
    }

    .studio-live-settings-item {
      display: grid;
      gap: 6px;
    }

    .studio-live-settings-item label,
    .studio-live-settings-toggle {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      color: #f8fafc;
      font-size: 14px;
      font-weight: 700;
    }

    .studio-live-settings-item select {
      width: 100%;
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 12px;
      background: rgba(255,255,255,.06);
      color: #fff;
      padding: 10px 12px;
      font-size: 14px;
      font-weight: 700;
      outline: none;
    }

    .studio-live-settings-item input[type="checkbox"] {
      width: 18px;
      height: 18px;
      accent-color: #4c95ff;
      flex: 0 0 auto;
    }

    .studio-live-settings-note {
      color: rgba(217,222,234,.78);
      font-size: 12px;
      line-height: 1.45;
    }

    .is-mirrored-preview {
      transform: scaleX(-1);
    }

    .studio-live-confirm {
      position: absolute;
      inset: 56px 0 92px;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(12, 16, 28, 0.52);
      z-index: 8;
    }

    .studio-live-confirm.is-open {
      display: flex;
    }

    .studio-live-confirm-card {
      width: min(500px, calc(100vw - 48px));
      border-radius: 24px;
      background: #fff;
      padding: 30px 34px 24px;
      text-align: center;
      box-shadow: 0 22px 50px rgba(0,0,0,.28);
    }

    .studio-live-confirm-card h3 {
      margin: 0;
      font-size: 28px;
      line-height: 1.15;
      color: #0e1b36;
      font-weight: 900;
    }

    .studio-live-confirm-card p {
      margin: 18px 0 0;
      font-size: 16px;
      line-height: 1.5;
      color: #596882;
    }

    .studio-live-confirm-actions {
      margin-top: 26px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 14px;
    }

    .studio-live-confirm-btn {
      min-width: 146px;
      height: 56px;
      border-radius: 16px;
      border: 1px solid #d6dde9;
      background: #fff;
      color: #33445f;
      font-size: 16px;
      font-weight: 900;
      cursor: pointer;
    }

    .studio-live-confirm-btn.confirm {
      border-color: #f44343;
      background: #f44343;
      color: #fff;
    }


    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    @media (max-width: 1560px) {
      .studio-frame {
        grid-template-columns: 350px minmax(500px, 1fr) minmax(390px, 1fr);
      }
    }

    @media (max-width: 1320px) {
      .studio-shell {
        height: auto;
        padding-left: calc(var(--feedRailW) + 8px);
        overflow: visible;
      }

      .studio-frame {
        height: auto;
        grid-template-columns: 1fr;
      }

      .panel {
        border-right: 0;
        border-bottom: 1px solid #dfe5f1;
      }

      .panel:last-child { border-bottom: 0; }

      .studio-live-modal-dialog {
        width: 100vw;
        height: 100vh;
        border-radius: 0;
        border: 0;
      }

      .studio-live-modal-dialog.has-chat .studio-live-modal-body {
        grid-template-columns: minmax(0, 1fr) 390px;
      }
    }

    @media (max-width: 860px) {
      .studio-shell {
        padding-left: 0;
        height: auto;
      }

      .studio-frame {
        height: auto;
      }

      .source-grid,
      .split {
        grid-template-columns: 1fr;
      }

      .action-row {
        justify-content: stretch;
        flex-direction: column;
      }

      .primary-btn,
      .accent-btn,
      .cam-btn {
        width: 100%;
      }

      .studio-live-modal-dialog {
        grid-template-rows: 60px minmax(0, 1fr) auto;
      }

      .studio-live-modal-stage {
        padding: 0;
      }

      .studio-live-modal-bottom {
        padding: 18px 20px 20px;
        flex-direction: column;
        align-items: stretch;
        gap: 18px;
      }

      .studio-live-controls,
      .studio-live-controls-right {
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
      }

      .studio-live-control,
      .studio-live-end {
        min-width: 72px;
      }

      .studio-live-confirm {
        inset: 56px 0 0;
      }

      .studio-live-modal-body,
      .studio-live-modal-dialog.has-chat .studio-live-modal-body {
        grid-template-columns: 1fr;
      }

      .studio-live-sidebar {
        border-left: 0;
        border-top: 1px solid rgba(255,255,255,.14);
      }
      .studio-live-comments-list { gap:22px; }
      .studio-live-comment-card { grid-template-columns:42px minmax(0,1fr); gap:12px; }
      .studio-live-comment-avatar { width:40px; height:40px; font-size:12px; }
      .studio-live-comment-body { font-size:12px; }
      .studio-live-compose { padding:10px 12px 16px; }
      .studio-live-compose-row { grid-template-columns:minmax(0,1fr) 46px; gap:10px; }
      .studio-live-send-btn { width:40px; height:40px; font-size:18px; min-width:40px; }
      .studio-live-compose-inputwrap { min-height:40px; }
      .studio-live-compose textarea { min-height:30px; font-size:15px; line-height:22px; }

    }

    @media (max-width: 575.98px) {
      .studio-shell {
        padding-left: 0;
      }
    }
  </style>
</head>
<body>
  <?php $skipHeaderThemeBootstrap = true; include __DIR__.'/includes/header.php'; ?>
  <div class="studio-shell">
    <aside class="studio-rail" aria-label="Studio navigation">
      <a class="brand-mark" href="feed.php" aria-label="Talentra">t</a>
      <div class="brand-copy">Talentra</div>

      <a class="rail-avatar" href="profile.php" title="<?php echo h($meName); ?>">
        <img src="<?php echo h($avatarUrl); ?>" alt="<?php echo h($meName); ?>" onerror="this.style.display='none';this.parentNode.textContent='<?php echo h($initials); ?>';">
      </a>

      <nav class="rail-nav">
        <a class="rail-icon" href="messages.php" title="Messages"><?php echo iconChat(); ?></a>
        <a class="rail-icon" href="dashboard.php" title="Notifications"><?php echo iconBell(); ?></a>
        <a class="rail-icon" href="compose.php" title="Create"><?php echo iconPlus(); ?></a>
        <a class="rail-icon" href="public.php" title="Public"><?php echo iconGlobe(); ?></a>
        <a class="rail-icon is-active" href="live_studio.php" title="Live Studio"><?php echo iconVideo(); ?></a>
        <a class="rail-icon" href="profile.php" title="Profile"><?php echo iconUserPlus(); ?></a>
      </nav>

      <a class="rail-icon" href="logout.php" title="Sign out"><?php echo iconPower(); ?></a>
    </aside>

    <main class="studio-main">
      <div class="studio-frame">
        <section class="panel">
          <div class="left-head">
            <div class="title-lockup">
              <div class="live-dot" aria-hidden="true"></div>
              <div>
                <div class="page-kicker">Live Studio</div>
                <h1 class="page-title">Create live video</h1>
              </div>
            </div>

            <div class="head-actions" aria-hidden="true">
              <div class="icon-chip"><?php echo iconHelp(); ?></div>
              <div class="icon-chip"><?php echo iconCard(); ?></div>
            </div>
          </div>

          <div class="progress-wrap">
            <div class="progress-track"><div class="progress-bar" id="progressBar"></div></div>
            <div class="progress-copy" id="progressCopy">0 / 3</div>
          </div>

          <div class="card setup-card">
            <div class="eyebrow">Setup Flow</div>
            <h2 class="section-title">Get your room ready</h2>

            <div class="steps">
              <div class="step is-active" id="stepCamera">
                <div class="step-badge">1</div>
                <div>
                  <h4>Turn on camera</h4>
                  <p>Start with your webcam so the preview is ready before you go live.</p>
                </div>
              </div>

              <div class="step" id="stepDetails">
                <div class="step-badge">2</div>
                <div>
                  <h4>Complete details</h4>
                  <p>Check title, visibility, and the posting plan for this session.</p>
                </div>
              </div>

              <div class="step" id="stepGoLive">
                <div class="step-badge">3</div>
                <div>
                  <h4>Go live</h4>
                  <p>Launch the session when your setup checks are complete.</p>
                </div>
              </div>
            </div>

            <div class="setup-helper">
              <p class="intro">Create, schedule. or manage your own live room.</p>
              <div class="studio-note" id="stepHint">Step 1: turn on the host camera preview. Step 2: complete the room details. Step 3: start live or schedule the session.</div>
            </div>
          </div>

          <div class="subcards">
            <div class="card info-card">
              <div class="label-blue">Current Session</div>
              <p>The latest open live entry for your account</p>
              <h4 id="currentSessionTitle"><?php echo h($currentSessionHeading); ?></h4>
              <p id="currentSessionMeta"><?php echo h($currentSessionMeta); ?></p>
              <div class="status-chip <?php echo h($currentStatus ?: 'draft'); ?>" id="currentSessionStatus"><?php echo h($currentStatusLabel); ?></div>
            </div>
          </div>
        </section>

        <section class="panel">
          <!-- <p class="middle-top">
            This page now supports live comments and reactions for the selection. Visibility rules are enforced so
            <code>public</code> sessions accept everyone and <code>friend</code> sessions stay friend-to-friend.
          </p> -->

          <div>
            <h2 class="section-title" style="font-size:18px; margin-bottom: 14px;">Select a video source</h2>
            <div class="source-grid" id="sourceGrid">
              <button class="source-card is-selected" type="button" data-source="webcam">
                <span class="source-glyph"><?php echo iconWebcam(); ?></span>
                <span class="source-name">Webcam</span>
              </button>
              <button class="source-card" type="button" data-source="software">
                <span class="source-glyph"><?php echo iconWand(); ?></span>
                <span class="source-name">Streaming software</span>
              </button>
            </div>

            <div class="software-panel" id="softwarePanel" hidden>
              <div>
                <p class="software-panel-title">Streaming software inputs</p>
                <p class="software-panel-copy">Choose how you want to feed the live stage from software instead of the webcam.</p>
              </div>

              <div class="software-mode-grid">
                <button class="software-mode-card is-selected" type="button" data-software-mode="file">
                  <strong>Video file</strong>
                  <span>Play a local video file and use it as the live source inside the preview.</span>
                </button>
                <button class="software-mode-card" type="button" data-software-mode="page">
                  <strong>Browser/web page</strong>
                  <span>Open a web page in a browser tab, then share that tab into the live room.</span>
                </button>
                <button class="software-mode-card" type="button" data-software-mode="external">
                  <strong>External live feed</strong>
                  <span>Share the app window that shows an OBS, RTMP, NDI, VLC, or similar feed.</span>
                </button>
              </div>

              <div class="software-mode-body">
                <div class="software-pane is-active" data-software-panel="file">
                  <div class="software-inline">
                    <button class="secondary-btn" type="button" id="softwareFileButton">Choose Video File</button>
                    <span class="software-inline-value" id="softwareFileName">No file selected yet.</span>
                  </div>
                  <div class="studio-note">Local video files loop in the preview so you can keep them running while the live room is active.</div>
                  <input id="softwareVideoFileInput" type="file" accept="video/*" hidden>
                </div>

                <div class="software-pane" data-software-panel="page">
                  <div class="software-url-row">
                    <input class="control" id="softwarePageUrl" type="url" placeholder="https://example.com or /page">
                    <button class="secondary-btn" type="button" id="softwareOpenPageButton">Open Page</button>
                    <button class="secondary-btn" type="button" id="softwarePageShareButton">Open + Share Tab</button>
                  </div>
                  <div class="software-link-note">This page opens the site in a browser tab, then captures that tab. Many sites block direct embedding, so tab sharing is the reliable browser-safe path.</div>
                </div>

                <div class="software-pane" data-software-panel="external">
                  <div class="software-inline">
                    <button class="secondary-btn" type="button" id="softwareExternalWindowButton">Share Feed Window</button>
                    <span class="software-inline-value">Use the window that shows your encoder, NDI monitor, player, or another feed viewer.</span>
                  </div>
                  <div class="studio-note">Direct RTMP or NDI ingest is not handled by this browser page. The working in-browser path is to share the window that is already showing that feed.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="preview-label" id="previewLabel">Video</div>

          <div class="camera-preview" id="cameraPreview">
            <video class="preview-video" id="previewVideo" autoplay playsinline muted></video>
            <div class="preview-toolbar">
              <div class="pill" id="previewStatusPill"><span class="tiny-dot"></span><?php echo h($currentStatusLabel); ?></div>
              <div class="pill">Live session</div>
              <div class="pill action" id="previewActionPill"><?php echo iconPowerMini(); ?>Turn Camera On</div>
            </div>

            <div class="preview-camera"><?php echo iconCamera(); ?></div>

            <div class="preview-message">
              <h3 id="previewTitle">Allow access to camera</h3>
              <p id="previewText">Your browser is not allowing camera access for Live</p>
            </div>

            <div class="preview-warning" id="previewWarning">
              <strong>CAMERA OFF</strong>
              Preview is off. Click Turn Camera On when you want to show video again.
            </div>
          </div>

          <div class="history-panel">
            <div class="card history-card">
              <div class="label-blue">Recent Sessions</div>
              <div class="history-row" style="margin-top: 12px;">
                <div class="history-stat"><span id="historyCount"><?php echo (int)$historyCount; ?></span> finished sessions in history</div>
                <button class="secondary-btn" type="button" id="saveDraftButton">Save Draft</button>
              </div>
              <div class="history-summary">
                <div class="history-summary-title" id="historySummaryTitle"><?php echo h((string)($historySummary['title'] ?? 'No finished live yet')); ?></div>
                <div class="history-summary-meta" id="historySummaryMeta"><?php echo h((string)($historySummary['ended_at_label'] ?? 'Finish a live session to keep its counts here.')); ?></div>
                <!-- <div class="history-metrics">
                  <div class="history-metric"><div class="history-metric-label">Views</div><span id="historyViewsCount"><?php echo (int)($historySummary['views'] ?? 0); ?></span></div>
                  <div class="history-metric"><div class="history-metric-label">Comments</div><span id="historyCommentsCount"><?php echo (int)($historySummary['comments'] ?? 0); ?></span></div>
                  <div class="history-metric"><div class="history-metric-label">Shares</div><span id="historySharesCount"><?php echo (int)($historySummary['shares'] ?? 0); ?></span></div>
                  <div class="history-metric"><div class="history-metric-label">Love</div><span id="historyLoveCount"><?php echo (int)($historySummary['love'] ?? 0); ?></span></div>
                  <div class="history-metric"><div class="history-metric-label">Likes</div><span id="historyLikeCount"><?php echo (int)($historySummary['like'] ?? 0); ?></span></div>
                  <div class="history-metric"><div class="history-metric-label">Smile</div><span id="historySmileCount"><?php echo (int)($historySummary['smile'] ?? 0); ?></span></div>
                  <div class="history-metric"><div class="history-metric-label">Care</div><span id="historyCareCount"><?php echo (int)($historySummary['care'] ?? 0); ?></span></div>
                  <div class="history-metric"><div class="history-metric-label">Angry</div><span id="historyAngryCount"><?php echo (int)($historySummary['angry'] ?? 0); ?></span></div>
                </div> -->
              </div>
            </div>
          </div>

        </section>

        <section class="panel">
          <form class="form-grid" action="#" method="post" onsubmit="return false;">
            <div class="field">
              <label for="sessionTitle">Session Title</label>
              <input class="control" id="sessionTitle" type="text" value="<?php echo h($prefillTitle); ?>">
            </div>

            <div class="field">
              <label for="sessionDescription">Description</label>
              <textarea class="textarea" id="sessionDescription"><?php echo h($prefillDescription); ?></textarea>
            </div>

            <div class="split">
              <div class="field">
                <label for="sessionVisibility">Visibility</label>
                <div class="select-wrap">
                  <select class="select" id="sessionVisibility">
                    <option value="private"<?php echo $prefillVisibility === 'private' ? ' selected' : ''; ?>>Private room</option>
                    <option value="friends"<?php echo $prefillVisibility === 'friends' ? ' selected' : ''; ?>>Friends only</option>
                    <option value="public"<?php echo $prefillVisibility === 'public' ? ' selected' : ''; ?>>Public room</option>
                  </select>
                  <?php echo iconChevron(); ?>
                </div>
              </div>

              <div class="field">
                <label for="scheduleFor">Schedule For</label>
                <div class="date-wrap">
                  <input class="datetime" id="scheduleFor" type="datetime-local" value="<?php echo h($prefillSchedule); ?>">
                  <?php echo iconCalendar(); ?>
                </div>
              </div>
            </div>

            <div class="toggle-card">
              <div class="toggle-top">
                <div class="toggle-state">
                  <?php echo iconVideoSolid(); ?>
                  <span id="toggleStateDot" style="width:24px;height:24px;border-radius:50%;background:#ffb5a8;display:inline-block;box-shadow: inset 0 0 0 7px #ff8c72;"></span>
                  <span id="toggleStateText">OFF</span>
                </div>

                <button class="cam-btn" id="cameraButton" type="button">Turn Camera On</button>
                <button class="switch" id="cameraSwitch" type="button" aria-pressed="false"><span class="sr-only">Toggle camera</span></button>
              </div>

              <div class="alert-line" id="toggleAlertLine">
                CAMERA OFF<br>
                Preview is off. Click Turn Camera On when you want to show video again.
              </div>

              <div class="studio-feedback" id="studioFeedback"></div>

              <div class="action-row">
                <button class="secondary-btn" type="button" id="endLiveButton"<?php echo $currentStatus === 'live' ? '' : ' style="display:none"'; ?>>End Live</button>
                <button class="primary-btn" type="button" id="startLiveButton">Start Live Now</button>
                <button class="accent-btn" type="button" id="scheduleLiveButton">Schedule Live</button>
              </div>
            </div>
          </form>

          <div class="chat-card">
            <!-- <h4 style="font-size: 17px;">Live Chat</h4> -->
            <!-- <p>Host comments and reactions for the current room appear here.</p> -->
            <!-- <p style="margin-top: 12px;">Current room code: <span id="streamKeyLabel"><?php echo h((string)($currentLive['stream_key'] ?? 'Not created yet')); ?></span></p> -->
            <div class="analytics-cache" aria-hidden="true">
              <div class="room-stats">
              <div class="room-stat"><i class="fa fa-eye" aria-hidden="true"></i> Watching <span id="viewerCount"><?php echo (int)($currentLive['viewer_count'] ?? 0); ?></span></div>
              <div class="room-stat">Comments <span id="commentCount">0</span></div>
              <div class="room-stat">Reactions <span id="reactionTotal">0</span></div>
              <div class="room-stat">Shares <span id="shareCount"><?php echo (int)($currentLive['share_count'] ?? 0); ?></span></div>
              </div>
              <div class="reaction-row">
              <button class="reaction-btn" type="button" data-reaction="love"><span class="reaction-love-heart" aria-hidden="true">&#10084;</span><span class="reaction-label">Love</span> <span data-reaction-count="love">0</span></button>
              <button class="reaction-btn" type="button" data-reaction="like">Like <span data-reaction-count="like">0</span></button>
              <button class="reaction-btn" type="button" data-reaction="fire">Fire <span data-reaction-count="fire">0</span></button>
              <button class="reaction-btn" type="button" data-reaction="wow">Wow <span data-reaction-count="wow">0</span></button>
              <button class="reaction-btn" type="button" data-reaction="clap">Clap <span data-reaction-count="clap">0</span></button>
              </div>
            </div>
            <div class="stats-chart" aria-label="Statistics chart">
              <h5 class="stats-chart-title" id="statsChartTitle">Live Room Statistics</h5>
              <div class="stats-chart-body">
                <div class="stats-y-label">Count of Reactions</div>
                <div class="stats-y-axis" aria-hidden="true">
                  <div class="stats-y-tick" id="statsTick5">1</div>
                  <div class="stats-y-tick" id="statsTick4">2</div>
                  <div class="stats-y-tick" id="statsTick3">3</div>
                  <div class="stats-y-tick" id="statsTick2">4</div>
                  <div class="stats-y-tick" id="statsTick1">5</div>
                  <div class="stats-y-tick" id="statsTick0">0</div>
                </div>
                <div class="stats-plot">
                  <div class="stats-bars">
                    <div class="stats-bar-col">
                      <div class="stats-bar-value" id="chartViewsValue"><?php echo (int)($currentLive['viewer_count'] ?? 0); ?></div>
                      <div class="stats-bar" id="chartViewsBar"></div>
                    </div>
                    <div class="stats-bar-col">
                      <div class="stats-bar-value" id="chartCommentsValue">0</div>
                      <div class="stats-bar" id="chartCommentsBar"></div>
                    </div>
                    <div class="stats-bar-col">
                      <div class="stats-bar-value" id="chartSharesValue"><?php echo (int)($currentLive['share_count'] ?? 0); ?></div>
                      <div class="stats-bar" id="chartSharesBar"></div>
                    </div>
                    <div class="stats-bar-col">
                      <div class="stats-bar-value" id="chartLoveValue">0</div>
                      <div class="stats-bar" id="chartLoveBar"></div>
                    </div>
                    <div class="stats-bar-col">
                      <div class="stats-bar-value" id="chartLikeValue">0</div>
                      <div class="stats-bar" id="chartLikeBar"></div>
                    </div>
                    <div class="stats-bar-col">
                      <div class="stats-bar-value" id="chartSmileValue">0</div>
                      <div class="stats-bar" id="chartSmileBar"></div>
                    </div>
                    <div class="stats-bar-col">
                      <div class="stats-bar-value" id="chartCareValue">0</div>
                      <div class="stats-bar" id="chartCareBar"></div>
                    </div>
                    <div class="stats-bar-col">
                      <div class="stats-bar-value" id="chartAngryValue">0</div>
                      <div class="stats-bar" id="chartAngryBar"></div>
                    </div>
                  </div>
                  <div class="stats-x-axis" aria-hidden="true">
                    <div class="stats-x-label">Views</div>
                    <div class="stats-x-label">Comm</div>
                    <div class="stats-x-label">Shares</div>
                    <div class="stats-x-label">Love</div>
                    <div class="stats-x-label">Likes</div>
                    <div class="stats-x-label">Smile</div>
                    <div class="stats-x-label">Care</div>
                    <div class="stats-x-label">Angry</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
     
    </main>
  </div>

  <div class="studio-live-modal" id="studioLiveModal" aria-hidden="true">
    <div class="studio-live-modal-dialog" role="dialog" aria-modal="true" aria-label="Live session">
      <div class="studio-live-modal-top">
        <div class="studio-live-modal-top-left">
          <div class="studio-live-modal-branding">
            <div class="studio-live-modal-title-row">
              <div class="studio-live-modal-title" id="studioModalTopTitle"><?php echo h($prefillTitle); ?></div>
              <div class="studio-live-modal-live-badge">Live</div>
            </div>
          </div>
        </div>
        <div class="studio-live-modal-top-right">
          <div class="studio-live-modal-top-center">Started moments ago</div>
          <button type="button" class="studio-live-speaker-btn" aria-label="Speaker view">
            <i class="fa fa-th-large" aria-hidden="true"></i>
          </button>
          <!-- <button type="button" class="studio-live-top-end" id="studioLiveTopClose" aria-label="End event">End event</button> -->
        </div>
      </div>
      <div class="studio-live-modal-body">
        <div class="studio-live-request-dock" id="studioLiveRequestDock" aria-label="Guest requests">
          <div class="studio-live-request-card">
            <h4>Guest requests</h4>
            <p class="studio-live-request-help">When a friend clicks <span class="accent">Request</span>, their name appears here. Click <span class="accent">Confirm</span> or <span class="accent">Deny</span>.</p>
            <div class="studio-live-request-list" id="studioLiveRequestList"></div>
            <div class="studio-live-request-empty" id="studioLiveRequestEmpty">No guest requests yet.</div>
          </div>
        </div>
        <div class="studio-live-modal-stage">
          <iframe class="studio-live-modal-frame" id="studioLiveModalFrame" title="Live session viewer" src="about:blank" allow="autoplay; fullscreen; picture-in-picture; camera; microphone"></iframe>
          <div class="studio-live-host-chip">
            <div class="studio-live-host-chip-avatar"><?php echo h($initials); ?></div>
            <div><?php echo h($meName); ?></div>
          </div>
          <div class="studio-live-modal-camera-off" aria-hidden="true">
            <div class="studio-live-modal-camera-off-icon">
              <i class="fa fa-video-camera" aria-hidden="true"></i>
            </div>
          </div>
          <video class="studio-live-modal-local-video" id="studioLiveModalLocalVideo" autoplay playsinline muted></video>
          <div class="studio-live-guest-layer" id="studioLiveGuestLayer"></div>
          <div class="studio-live-stage-reactions" id="studioLiveStageReactions" aria-hidden="true"></div>
        </div>
        <aside class="studio-live-sidebar" id="studioLiveSidebar" aria-label="Live chat sidebar">
          <div class="studio-live-side-head">
            <div class="studio-live-side-title">
              <strong id="studioSidebarTitleText">Comments</strong>
              <span id="studioSidebarTitleCount">0</span>
            </div>
            <div class="studio-live-side-badge" id="studioModalVisibilityBadge" title="Room visibility">
              <i class="fa fa-bell" aria-hidden="true"></i>
            </div>
            <!-- <button type="button" class="studio-live-side-close" id="studioLiveSidebarClose" aria-label="Close chat">&times;</button> -->
          </div>
          <div class="studio-live-side-stats">
            <div class="studio-live-side-stat"><strong id="studioModalSidebarReactionCount">0</strong> Reactions</div>
            <div class="studio-live-side-stat"><strong id="studioModalSidebarCommentCount">0</strong> Comments</div>
            <div class="studio-live-side-stat"><i class="fa fa-eye" aria-hidden="true"></i> <strong id="studioModalSidebarViewCount">0</strong> Watching</div>
          </div>
          <div class="studio-live-side-scroll">
            <div class="studio-live-chat-panel" id="studioLiveChatPanel">
              <div class="studio-live-approved-list" id="studioLiveApprovedList"></div>
              <div class="studio-live-comments-box" id="studioModalCommentsBox">
                <div class="studio-live-comments-list" id="studioModalCommentList">Comments will appear here when viewers join your live session.</div>
              </div>
            </div>
            <div class="studio-live-reaction-panel" id="studioLiveReactionPanel">
              <div class="studio-live-reaction-tabs" id="studioLiveReactionTabs"></div>
              <div class="studio-live-reaction-list" id="studioLiveReactionList"></div>
            </div>
            <div class="studio-live-description-panel" id="studioLiveDescriptionPanel">
              <div class="studio-live-description-card">
                <div class="studio-live-description-head">
                  <!-- <div class="studio-live-description-avatar" id="studioLiveDescriptionAvatar"><?php echo h($initials); ?></div> -->
                  <div class="studio-live-description-meta">
                    <h3 class="studio-live-description-title" id="studioLiveDescriptionTitle"><?php echo h($prefillTitle); ?></h3>
                    <div class="studio-live-description-sub" id="studioLiveDescriptionSub"><?php echo h($meName); ?> • <?php echo h(date('Y-m-d H:i')); ?></div>
                  </div>
                </div>
                <div class="studio-live-description-divider"></div>
                <div class="studio-live-description-scroll">
                  <p class="studio-live-description-body" id="studioLiveDescriptionBody"><?php echo h($prefillDescription); ?></p>
                </div>
              </div>
            </div>
            <div class="studio-live-settings-panel" id="studioLiveSettingsPanel" aria-hidden="true">
              <div class="studio-live-settings-body">
                <div class="studio-live-settings-item">
                  <label for="studioLiveCameraDeviceSelect">Camera device</label>
                  <select id="studioLiveCameraDeviceSelect">
                    <option value="">System default camera</option>
                  </select>
                  <div class="studio-live-settings-note">Used for webcam mode.</div>
                </div>
                <div class="studio-live-settings-item">
                  <label for="studioLiveMicDeviceSelect">Microphone device</label>
                  <select id="studioLiveMicDeviceSelect">
                    <option value="">System default microphone</option>
                  </select>
                  <div class="studio-live-settings-note">Used for webcam, screen share, and software sources.</div>
                </div>
                <div class="studio-live-settings-item">
                  <label for="studioLiveSpeakerDeviceSelect">Speaker / output</label>
                  <select id="studioLiveSpeakerDeviceSelect">
                    <option value="">System default output</option>
                  </select>
                  <div class="studio-live-settings-note">Controls sound output for your local preview when supported by the browser.</div>
                </div>
                <div class="studio-live-settings-item">
                  <label class="studio-live-settings-toggle" for="studioLiveMicMuteToggle">
                    <span>Microphone on</span>
                    <input type="checkbox" id="studioLiveMicMuteToggle">
                  </label>
                  <div class="studio-live-settings-note">Mute or unmute the host microphone without leaving the live.</div>
                </div>
                <div class="studio-live-settings-item">
                  <label class="studio-live-settings-toggle" for="studioLiveCameraEnabledToggle">
                    <span>Camera on</span>
                    <input type="checkbox" id="studioLiveCameraEnabledToggle">
                  </label>
                  <div class="studio-live-settings-note">Hide or show the host camera without closing the modal.</div>
                </div>
                <div class="studio-live-settings-item">
                  <label for="studioLiveQualitySelect">Video quality</label>
                  <select id="studioLiveQualitySelect">
                    <option value="auto">Auto</option>
                    <option value="720p">720p</option>
                    <option value="1080p">1080p</option>
                  </select>
                  <div class="studio-live-settings-note">Changes the host camera and relay profile while you are live.</div>
                </div>
                <div class="studio-live-settings-item">
                  <label for="studioLiveFrameRateSelect">Frame rate</label>
                  <select id="studioLiveFrameRateSelect">
                    <option value="24">24 fps</option>
                    <option value="30">30 fps</option>
                  </select>
                  <div class="studio-live-settings-note">Use 24 fps for stability or 30 fps for smoother motion.</div>
                </div>
                <div class="studio-live-settings-item">
                  <label class="studio-live-settings-toggle" for="studioLiveMirrorToggle">
                    <span>Mirror camera</span>
                    <input type="checkbox" id="studioLiveMirrorToggle">
                  </label>
                  <div class="studio-live-settings-note">Only affects how your local preview looks in studio.</div>
                </div>
                <div class="studio-live-settings-item">
                  <label>Background blur</label>
                  <div class="studio-live-settings-note">Background blur can be added later after the core device controls are stable.</div>
                </div>
              </div>
            </div>
          </div>
          <div class="studio-live-compose" id="studioLiveCompose">
            <div class="studio-live-compose-row">
              <div class="studio-live-compose-inputwrap">
                <textarea id="studioModalCommentInput" placeholder="Add comment..."></textarea>
                <button type="button" class="studio-live-compose-tool" aria-label="Mention">@</button>
                <button type="button" class="studio-live-compose-tool" aria-label="Emoji"><i class="fa fa-smile-o" aria-hidden="true"></i></button>
              </div>
              <button type="button" class="studio-live-send-btn" id="studioModalSendButton" aria-label="Send message">
                <i class="fa fa-arrow-up" aria-hidden="true"></i>
              </button>
            </div>
            <div class="studio-live-compose-feedback" id="studioModalComposeFeedback"></div>
          </div>
        </aside>
      </div>
      <div class="studio-live-modal-bottom">
        <div class="studio-live-controls">
          <button type="button" class="studio-live-control" id="studioLiveSettingsToggle" aria-label="Settings">
            <i class="fa fa-cog" aria-hidden="true"></i>
            <span class="studio-live-control-label">Settings</span>
          </button>
          <a type="button" class="studio-live-control" id="studioLiveMicToggle" aria-label="Turn microphone off">
            <i class="fa fa-microphone has-off-slash" aria-hidden="true"></i>
            <span class="studio-live-control-label">Microphone</span>
          </a>
          <a type="button" class="studio-live-control" id="studioLiveCameraToggle" aria-label="Turn camera off">
            <i class="fa fa-video-camera" aria-hidden="true"></i>
            <span class="studio-live-control-label">Camera</span>
          </a>
          <!-- <button type="button" class="studio-live-control" aria-label="Share screen">
            <i class="fa fa-desktop" aria-hidden="true"></i>
            <span class="studio-live-control-label">Share</span>
          </button> -->
          
          <a type="button" class="studio-live-control" id="studioLiveReactionToggle" aria-label="React">
            <i class="fa fa-smile-o" aria-hidden="true"></i>
            <span class="studio-live-control-label">React</span>
          </a>
          <!-- <a type="button" class="studio-live-control" aria-label="Fullscreen">
            <i class="fa fa-arrows-alt" aria-hidden="true"></i>
            <span class="studio-live-control-label">Fullscreen</span>
          </a> -->
          <a type="button" class="studio-live-control" aria-label="People">
            <i class="fa fa-eye" aria-hidden="true"></i>
            <span class="studio-live-control-label">Watching <span class="studio-live-control-count" id="studioModalSidebarViewCountDock">0</span></span>
          </a>
          
          <a type="button" class="studio-live-control" id="studioLiveChatToggle" aria-label="Chat">
            <i class="fa fa-comment" aria-hidden="true"></i>
            <span class="studio-live-control-label">Chat <span class="studio-live-control-count" id="studioModalChatCount">0</span></span>
          </a>

          <a type="button" class="studio-live-control" id="studioLiveDescriptionToggle" aria-label="Description">
            <i class="fa fa-book" aria-hidden="true"></i>
            <span class="studio-live-control-label">Description <span class="studio-live-control-count"></span></span>
          </a>
        </div>
        
        <div class="studio-live-controls-right">
          <a type="button" class="studio-live-end" id="studioLiveModalClose" aria-label="End meeting">
            <i class="fa fa-phone" aria-hidden="true"></i>
            <span class="studio-live-control-label">End event</span>
          </a>
        </div>
      </div>
      <div class="studio-live-confirm" id="studioLiveConfirm" aria-hidden="true">
        <div class="studio-live-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="studioLiveConfirmTitle" aria-describedby="studioLiveConfirmText">
          <h3 id="studioLiveConfirmTitle">End Live</h3>
          <p id="studioLiveConfirmText">Are you sure you want to end this live video?</p>
          <div class="studio-live-confirm-actions">
            <button type="button" class="studio-live-confirm-btn" id="studioLiveConfirmCancel">Cancel</button>
            <button type="button" class="studio-live-confirm-btn confirm" id="studioLiveConfirmOk">OK</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    const titleInput = document.getElementById('sessionTitle');
    const descriptionInput = document.getElementById('sessionDescription');
    const visibilityInput = document.getElementById('sessionVisibility');
    const scheduleInput = document.getElementById('scheduleFor');
    const sourceCards = Array.from(document.querySelectorAll('.source-card'));
    const softwarePanel = document.getElementById('softwarePanel');
    const softwareModeCards = Array.from(document.querySelectorAll('[data-software-mode]'));
    const softwarePanels = Array.from(document.querySelectorAll('[data-software-panel]'));
    const softwareFileButton = document.getElementById('softwareFileButton');
    const softwareVideoFileInput = document.getElementById('softwareVideoFileInput');
    const softwareFileName = document.getElementById('softwareFileName');
    const softwarePageUrl = document.getElementById('softwarePageUrl');
    const softwareOpenPageButton = document.getElementById('softwareOpenPageButton');
    const softwarePageShareButton = document.getElementById('softwarePageShareButton');
    const softwareExternalWindowButton = document.getElementById('softwareExternalWindowButton');
    const progressBar = document.getElementById('progressBar');
    const progressCopy = document.getElementById('progressCopy');
    const cameraButton = document.getElementById('cameraButton');
    const cameraSwitch = document.getElementById('cameraSwitch');
    const toggleStateText = document.getElementById('toggleStateText');
    const toggleAlertLine = document.getElementById('toggleAlertLine');
    const previewTitle = document.getElementById('previewTitle');
    const previewText = document.getElementById('previewText');
    const previewWarning = document.getElementById('previewWarning');
    const previewActionPill = document.getElementById('previewActionPill');
    const previewStatusPill = document.getElementById('previewStatusPill');
    const previewVideo = document.getElementById('previewVideo');
    const previewLabelNode = document.getElementById('previewLabel');
    const previewCamera = document.querySelector('.preview-camera');
    const cameraPreview = document.getElementById('cameraPreview');
    const stepCamera = document.getElementById('stepCamera');
    const stepCameraTitle = stepCamera ? stepCamera.querySelector('h4') : null;
    const stepCameraCopy = stepCamera ? stepCamera.querySelector('p') : null;
    const stepDetails = document.getElementById('stepDetails');
    const stepGoLive = document.getElementById('stepGoLive');
    const feedback = document.getElementById('studioFeedback');
    const stepHint = document.getElementById('stepHint');
    const currentSessionTitle = document.getElementById('currentSessionTitle');
    const currentSessionMeta = document.getElementById('currentSessionMeta');
    const currentSessionStatus = document.getElementById('currentSessionStatus');
    const historyCount = document.getElementById('historyCount');
    const historySummaryTitle = document.getElementById('historySummaryTitle');
    const historySummaryMeta = document.getElementById('historySummaryMeta');
    const historyViewsCount = document.getElementById('historyViewsCount');
    const historyCommentsCount = document.getElementById('historyCommentsCount');
    const historySharesCount = document.getElementById('historySharesCount');
    const historyLoveCount = document.getElementById('historyLoveCount');
    const historyLikeCount = document.getElementById('historyLikeCount');
    const historySmileCount = document.getElementById('historySmileCount');
    const historyCareCount = document.getElementById('historyCareCount');
    const historyAngryCount = document.getElementById('historyAngryCount');
    const streamKeyLabel = document.getElementById('streamKeyLabel');
    const startLiveButton = document.getElementById('startLiveButton');
    const scheduleLiveButton = document.getElementById('scheduleLiveButton');
    const saveDraftButton = document.getElementById('saveDraftButton');
    const endLiveButton = document.getElementById('endLiveButton');
    const viewerCount = document.getElementById('viewerCount');
    const commentCount = document.getElementById('commentCount');
    const reactionTotal = document.getElementById('reactionTotal');
    const shareCount = document.getElementById('shareCount');
    const commentList = document.getElementById('commentList');
    const commentInput = document.getElementById('commentInput');
    const sendCommentButton = document.getElementById('sendCommentButton');
    const statsTick5 = document.getElementById('statsTick5');
    const statsTick4 = document.getElementById('statsTick4');
    const statsTick3 = document.getElementById('statsTick3');
    const statsTick2 = document.getElementById('statsTick2');
    const statsTick1 = document.getElementById('statsTick1');
    const statsTick0 = document.getElementById('statsTick0');
    const chartViewsValue = document.getElementById('chartViewsValue');
    const chartViewsBar = document.getElementById('chartViewsBar');
    const chartCommentsValue = document.getElementById('chartCommentsValue');
    const chartCommentsBar = document.getElementById('chartCommentsBar');
    const chartSharesValue = document.getElementById('chartSharesValue');
    const chartSharesBar = document.getElementById('chartSharesBar');
    const chartLoveValue = document.getElementById('chartLoveValue');
    const chartLoveBar = document.getElementById('chartLoveBar');
    const chartLikeValue = document.getElementById('chartLikeValue');
    const chartLikeBar = document.getElementById('chartLikeBar');
    const chartSmileValue = document.getElementById('chartSmileValue');
    const chartSmileBar = document.getElementById('chartSmileBar');
    const chartCareValue = document.getElementById('chartCareValue');
    const chartCareBar = document.getElementById('chartCareBar');
    const chartAngryValue = document.getElementById('chartAngryValue');
    const chartAngryBar = document.getElementById('chartAngryBar');
    const statsChartTitle = document.getElementById('statsChartTitle');
    const reactionButtons = Array.from(document.querySelectorAll('[data-reaction]'));
    const autoOpenLiveWatchId = <?php echo (int)$autoOpenLiveWatchId; ?>;
    const studioLiveModal = document.getElementById('studioLiveModal');
    const studioLiveModalDialog = studioLiveModal ? studioLiveModal.querySelector('.studio-live-modal-dialog') : null;
    const studioLiveModalStage = studioLiveModal ? studioLiveModal.querySelector('.studio-live-modal-stage') : null;
    const studioLiveModalFrame = document.getElementById('studioLiveModalFrame');
    const studioLiveModalCameraOff = studioLiveModal ? studioLiveModal.querySelector('.studio-live-modal-camera-off') : null;
    const studioLiveModalLocalVideo = document.getElementById('studioLiveModalLocalVideo');
    const studioLiveModalClose = document.getElementById('studioLiveModalClose');
    const studioLiveTopClose = document.getElementById('studioLiveTopClose');
    const studioModalTopTitle = document.getElementById('studioModalTopTitle');
    const studioModalParticipantCount = document.getElementById('studioModalParticipantCount');
    const studioModalChatCount = document.getElementById('studioModalChatCount');
    const studioModalReactionCount = document.getElementById('studioModalReactionCount');
    const studioLiveSidebar = document.getElementById('studioLiveSidebar');
    const studioLiveSettingsToggle = document.getElementById('studioLiveSettingsToggle');
    const studioLiveSettingsPanel = document.getElementById('studioLiveSettingsPanel');
    const studioLiveCameraDeviceSelect = document.getElementById('studioLiveCameraDeviceSelect');
    const studioLiveMicDeviceSelect = document.getElementById('studioLiveMicDeviceSelect');
    const studioLiveSpeakerDeviceSelect = document.getElementById('studioLiveSpeakerDeviceSelect');
    const studioLiveMicMuteToggle = document.getElementById('studioLiveMicMuteToggle');
    const studioLiveCameraEnabledToggle = document.getElementById('studioLiveCameraEnabledToggle');
    const studioLiveQualitySelect = document.getElementById('studioLiveQualitySelect');
    const studioLiveFrameRateSelect = document.getElementById('studioLiveFrameRateSelect');
    const studioLiveMirrorToggle = document.getElementById('studioLiveMirrorToggle');
    const studioLiveMicToggle = document.getElementById('studioLiveMicToggle');
    const studioLiveCameraToggle = document.getElementById('studioLiveCameraToggle');
    const studioLiveChatToggle = document.getElementById('studioLiveChatToggle');
    const studioLiveReactionToggle = document.getElementById('studioLiveReactionToggle');
    const studioLiveDescriptionToggle = document.getElementById('studioLiveDescriptionToggle');
    const studioLiveSidebarClose = document.getElementById('studioLiveSidebarClose');
    const studioModalVisibilityBadge = document.getElementById('studioModalVisibilityBadge');
    const studioModalSidebarReactionCount = document.getElementById('studioModalSidebarReactionCount');
    const studioModalSidebarCommentCount = document.getElementById('studioModalSidebarCommentCount');
    const studioModalSidebarViewCount = document.getElementById('studioModalSidebarViewCount');
    const studioModalSidebarViewCountDock = document.getElementById('studioModalSidebarViewCountDock');
    const studioModalCommentsBox = document.getElementById('studioModalCommentsBox');
    const studioModalCommentList = document.getElementById('studioModalCommentList');
    const studioModalCommentHeaderCount = document.getElementById('studioModalCommentHeaderCount');
    const studioSidebarTitleText = document.getElementById('studioSidebarTitleText');
    const studioSidebarTitleCount = document.getElementById('studioSidebarTitleCount');
    const studioLiveReactionTabs = document.getElementById('studioLiveReactionTabs');
    const studioLiveReactionList = document.getElementById('studioLiveReactionList');
    const studioLiveDescriptionAvatar = document.getElementById('studioLiveDescriptionAvatar');
    const studioLiveDescriptionTitle = document.getElementById('studioLiveDescriptionTitle');
    const studioLiveDescriptionSub = document.getElementById('studioLiveDescriptionSub');
    const studioLiveDescriptionBody = document.getElementById('studioLiveDescriptionBody');
    const studioModalCommentInput = document.getElementById('studioModalCommentInput');
    const studioModalComposeFeedback = document.getElementById('studioModalComposeFeedback');
    const studioModalSendButton = document.getElementById('studioModalSendButton');
    const studioLiveRequestDock = document.getElementById('studioLiveRequestDock');
    const studioLiveRequestList = document.getElementById('studioLiveRequestList');
    const studioLiveRequestEmpty = document.getElementById('studioLiveRequestEmpty');
    const studioLiveApprovedList = document.getElementById('studioLiveApprovedList');
    const studioLiveGuestLayer = document.getElementById('studioLiveGuestLayer');
    const studioLiveStageReactions = document.getElementById('studioLiveStageReactions');
    const studioLiveConfirm = document.getElementById('studioLiveConfirm');
    const studioLiveConfirmCancel = document.getElementById('studioLiveConfirmCancel');
    const studioLiveConfirmOk = document.getElementById('studioLiveConfirmOk');

    const state = {
      live: <?php echo json_encode([
        'id' => (int)($currentLive['id'] ?? 0),
        'title' => (string)($currentLive['title'] ?? ''),
        'description' => (string)($currentLive['description'] ?? ''),
        'status' => $currentStatus ?: 'draft',
        'visibility' => $prefillVisibility,
        'stream_key' => (string)($currentLive['stream_key'] ?? ''),
        'share_count' => (int)($currentLive['share_count'] ?? 0),
        'schedule_input' => $prefillSchedule,
      ], JSON_UNESCAPED_SLASHES); ?>,
      historyCount: <?php echo (int)$historyCount; ?>,
      historySummary: <?php echo json_encode($historySummary ?: new stdClass(), JSON_UNESCAPED_SLASHES); ?>,
      cameraOn: <?php echo $currentStatus === 'live' ? 'true' : 'false'; ?>,
      micOn: true,
      deviceCameraEnabled: true,
      streamQuality: 'auto',
      frameRatePreference: 24,
      mirrorPreview: false,
      selectedCameraDeviceId: '',
      selectedMicDeviceId: '',
      selectedSpeakerDeviceId: '',
      source: 'webcam',
      softwareMode: 'file',
      softwareFile: null,
      softwareFileName: '',
      softwarePageUrl: '',
      previewObjectUrl: '',
      sourceCleanup: null,
      busy: false,
      mediaStream: null,
      roomPollTimer: null,
      roomReaction: '',
      snapshotTimer: null,
      guestRelayTimer: null,
      guestTileSnapshotTimer: null,
      guestTileSnapshotIntervalMs: 0,
      snapshotIntervalMs: 0,
      guestRelayIntervalMs: 0,
      snapshotBusy: false,
      guestSnapshotBusy: {},
      snapshotVersion: '',
      snapshotRelayVideo: null,
      snapshotRelayStream: null,
      signalPollTimer: null,
      peerConnections: {},
      justEnded: false,
      guestRequests: [],
      approvedGuests: [],
      guestStreams: {},
      guestSnapshotVersions: {},
      guestSnapshotUrls: {},
      guestHealthTimer: null,
      softwarePlaybackTimer: null,
      softwareLastCurrentTime: 0,
      softwareLastAdvanceAt: 0,
      sidebarMode: 'chat',
      reactionUsers: [],
      reactionCounts: {},
      reactionFilter: 'all'
    };
    const hostUserId = <?php echo (int)$meId; ?>;
    const rtcConfig = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
    const maxActiveGuests = 29;
    const peerDisconnectGraceMs = 8000;
    const guestFreezeGraceMs = 7000;
    let studioStageInitialized = false;
    let studioLastReactionCounts = { love: 0, like: 0, fire: 0, wow: 0, clap: 0 };

    function statusLabel(value) {
      const normalized = String(value || 'draft').toLowerCase();
      if (normalized === 'live') return 'LIVE';
      if (normalized === 'scheduled') return 'SCHEDULED';
      return 'DRAFT';
    }

    function visibilityLabel(value) {
      if (value === 'public') return 'Public room';
      if (value === 'friends') return 'Friends only';
      return 'Private room';
    }

    function setImageSourceWhenReady(image, url, options) {
      if (!image || !url) return;
      const settings = options || {};
      const token = String(settings.token || Date.now());
      image.dataset.loadToken = token;
      const loader = new Image();
      loader.onload = function() {
        if (image.dataset.loadToken !== token) return;
        image.src = url;
        if (typeof settings.onLoaded === 'function') {
          settings.onLoaded();
        }
      };
      loader.onerror = function() {
        if (image.dataset.loadToken !== token) return;
        if (typeof settings.onError === 'function') {
          settings.onError();
        }
      };
      loader.src = url;
    }

    function bindStudioGuestVideoHealth(userId, video) {
      const id = Number(userId || 0);
      const entry = state.guestStreams[id];
      if (!id || !entry || !video) return;
      if (entry.boundVideo === video) {
        return;
      }
      entry.boundVideo = video;
      entry.lastVideoTime = Number(video.currentTime || 0);
      entry.lastAdvanceAt = Date.now();
      const markProgress = function() {
        const currentTime = Number(video.currentTime || 0);
        if (currentTime > (entry.lastVideoTime || 0) || video.readyState >= 2) {
          entry.lastVideoTime = currentTime;
          entry.lastAdvanceAt = Date.now();
        }
      };
      video.addEventListener('playing', markProgress);
      video.addEventListener('timeupdate', markProgress);
      video.addEventListener('loadeddata', markProgress);
    }

    function stopGuestHealthLoop() {
      if (state.guestHealthTimer) {
        clearInterval(state.guestHealthTimer);
        state.guestHealthTimer = null;
      }
    }

    function syncGuestHealthLoop() {
      const activeGuestIds = Object.keys(state.guestStreams || {});
      if (!(state.live && Number(state.live.id || 0) > 0) || String(state.live.status || '').toLowerCase() !== 'live' || !activeGuestIds.length) {
        stopGuestHealthLoop();
        return;
      }
      if (state.guestHealthTimer) {
        return;
      }
      state.guestHealthTimer = window.setInterval(function() {
        Object.keys(state.guestStreams || {}).forEach(function(key) {
          const userId = Number(key || 0);
          const entry = state.guestStreams[userId];
          if (!entry || !entry.video) {
            return;
          }
          const age = Date.now() - Number(entry.lastAdvanceAt || 0);
          if (entry.video.readyState >= 2 && age > guestFreezeGraceMs) {
            Object.keys(state.peerConnections).forEach(function(peerKey) {
              const peerEntry = state.peerConnections[peerKey];
              if (!peerEntry || peerEntry.kind !== 'guest-publisher' || Number(peerEntry.viewerId || 0) !== userId) {
                return;
              }
              sendRtcSignal(peerEntry.viewerId, peerKey, 'bye', {}).catch(function() {});
              closePeerConnection(peerKey);
            });
          }
        });
      }, 3000);
    }

    function tuneSenderForTile(sender, kind) {
      if (!sender || !sender.track || typeof sender.getParameters !== 'function' || typeof sender.setParameters !== 'function') {
        return;
      }
      const params = sender.getParameters() || {};
      if (!params.encodings || !params.encodings.length) {
        params.encodings = [{}];
      }
      const encoding = params.encodings[0];
      const activeGuestCount = Math.min(maxActiveGuests, Array.isArray(state.approvedGuests) ? state.approvedGuests.length : 0);
      if (kind === 'guest-relay') {
        encoding.maxBitrate = activeGuestCount >= 6 ? 180000 : 450000;
        encoding.maxFramerate = activeGuestCount >= 6 ? 10 : 14;
        encoding.scaleResolutionDownBy = activeGuestCount >= 6 ? 2.5 : 1.75;
      } else if (kind === 'host-relay') {
        encoding.maxBitrate = activeGuestCount >= 6 ? 700000 : 1400000;
        encoding.maxFramerate = activeGuestCount >= 6 ? 12 : 24;
        encoding.scaleResolutionDownBy = activeGuestCount >= 6 ? 1.35 : 1;
      } else if (kind === 'guest-publisher') {
        encoding.maxBitrate = activeGuestCount >= 6 ? 220000 : 500000;
        encoding.maxFramerate = activeGuestCount >= 6 ? 10 : 14;
        encoding.scaleResolutionDownBy = activeGuestCount >= 6 ? 1.75 : 1;
      }
      sender.setParameters(params).catch(function() {});
    }

    function hostMediaProfile() {
      const frameRate = Number(state.frameRatePreference || 24) >= 30 ? 30 : 24;
      if (state.streamQuality === '720p') {
        return { width: 1280, height: 720, frameRate: frameRate };
      }
      if (state.streamQuality === '1080p') {
        return { width: 1920, height: 1080, frameRate: frameRate };
      }
      const activeGuestCount = Math.min(maxActiveGuests, Array.isArray(state.approvedGuests) ? state.approvedGuests.length : 0);
      if (activeGuestCount >= 6) {
        return { width: 640, height: 360, frameRate: Math.min(frameRate, 12) };
      }
      if (activeGuestCount >= 3) {
        return { width: 960, height: 540, frameRate: Math.min(frameRate, 18) };
      }
      return { width: 1280, height: 720, frameRate: frameRate };
    }

    function supportsSinkSelection(mediaNode) {
      return !!(mediaNode && typeof mediaNode.setSinkId === 'function');
    }

    async function refreshStudioMediaDevices() {
      if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
        return;
      }
      try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const cameras = devices.filter(function(device) { return device.kind === 'videoinput'; });
        const microphones = devices.filter(function(device) { return device.kind === 'audioinput'; });
        const speakers = devices.filter(function(device) { return device.kind === 'audiooutput'; });

        if (studioLiveCameraDeviceSelect) {
          studioLiveCameraDeviceSelect.innerHTML = '<option value="">System default camera</option>' + cameras.map(function(device, index) {
            const label = device.label || ('Camera ' + String(index + 1));
            const selected = device.deviceId === state.selectedCameraDeviceId ? ' selected' : '';
            return '<option value="' + escHtml(device.deviceId) + '"' + selected + '>' + escHtml(label) + '</option>';
          }).join('');
        }
        if (studioLiveMicDeviceSelect) {
          studioLiveMicDeviceSelect.innerHTML = '<option value="">System default microphone</option>' + microphones.map(function(device, index) {
            const label = device.label || ('Microphone ' + String(index + 1));
            const selected = device.deviceId === state.selectedMicDeviceId ? ' selected' : '';
            return '<option value="' + escHtml(device.deviceId) + '"' + selected + '>' + escHtml(label) + '</option>';
          }).join('');
        }
        if (studioLiveSpeakerDeviceSelect) {
          studioLiveSpeakerDeviceSelect.innerHTML = '<option value="">System default output</option>' + speakers.map(function(device, index) {
            const label = device.label || ('Output ' + String(index + 1));
            const selected = device.deviceId === state.selectedSpeakerDeviceId ? ' selected' : '';
            return '<option value="' + escHtml(device.deviceId) + '"' + selected + '>' + escHtml(label) + '</option>';
          }).join('');
          studioLiveSpeakerDeviceSelect.disabled = !supportsSinkSelection(previewVideo);
        }
      } catch (error) {}
    }

    async function applyStudioOutputDevice(deviceId) {
      state.selectedSpeakerDeviceId = String(deviceId || '');
      const targets = [previewVideo, studioLiveModalLocalVideo].filter(Boolean);
      if (!targets.length) {
        return false;
      }
      if (!targets.every(function(node) { return supportsSinkSelection(node); })) {
        return false;
      }
      try {
        await Promise.all(targets.map(function(node) {
          return node.setSinkId(state.selectedSpeakerDeviceId || '');
        }));
        return true;
      } catch (error) {
        return false;
      }
    }

    async function replaceHostAudioTrack() {
      if (!state.mediaStream || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return false;
      }
      try {
        const audioConstraints = {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        };
        if (state.selectedMicDeviceId) {
          audioConstraints.deviceId = { exact: state.selectedMicDeviceId };
        }
        const audioStream = await navigator.mediaDevices.getUserMedia({
          audio: audioConstraints,
          video: false
        });
        const newTrack = audioStream && audioStream.getAudioTracks ? audioStream.getAudioTracks()[0] : null;
        if (!newTrack) {
          if (audioStream) {
            audioStream.getTracks().forEach(function(track) { track.stop(); });
          }
          return false;
        }
        newTrack.enabled = !!state.micOn;
        const oldAudioTracks = typeof state.mediaStream.getAudioTracks === 'function' ? state.mediaStream.getAudioTracks() : [];
        oldAudioTracks.forEach(function(track) {
          try {
            if (typeof state.mediaStream.removeTrack === 'function') {
              state.mediaStream.removeTrack(track);
            }
            track.stop();
          } catch (error) {}
        });
        state.mediaStream.addTrack(newTrack);
        Object.keys(state.peerConnections || {}).forEach(function(key) {
          const entry = state.peerConnections[key];
          const pc = entry && entry.pc ? entry.pc : null;
          if (!pc || entry.kind !== 'host-relay' || typeof pc.getSenders !== 'function') {
            return;
          }
          const audioSender = pc.getSenders().find(function(sender) {
            return sender && sender.track && sender.track.kind === 'audio';
          });
          if (audioSender && typeof audioSender.replaceTrack === 'function') {
            audioSender.replaceTrack(newTrack).catch(function() {});
          } else if (typeof pc.addTrack === 'function') {
            try {
              pc.addTrack(newTrack, state.mediaStream);
            } catch (error) {}
          }
        });
        return true;
      } catch (error) {
        return false;
      }
    }

    function syncStudioLiveSettingsUi() {
      if (studioLiveSettingsPanel) {
        studioLiveSettingsPanel.setAttribute('aria-hidden', state.sidebarMode === 'settings' ? 'false' : 'true');
      }
      if (studioLiveCameraDeviceSelect) {
        studioLiveCameraDeviceSelect.value = state.selectedCameraDeviceId;
        studioLiveCameraDeviceSelect.disabled = state.source !== 'webcam';
      }
      if (studioLiveMicDeviceSelect) {
        studioLiveMicDeviceSelect.value = state.selectedMicDeviceId;
      }
      if (studioLiveSpeakerDeviceSelect) {
        studioLiveSpeakerDeviceSelect.value = state.selectedSpeakerDeviceId;
        studioLiveSpeakerDeviceSelect.disabled = !supportsSinkSelection(previewVideo);
      }
      if (studioLiveMicMuteToggle) {
        studioLiveMicMuteToggle.checked = !!state.micOn;
      }
      if (studioLiveCameraEnabledToggle) {
        studioLiveCameraEnabledToggle.checked = !!state.deviceCameraEnabled;
      }
      if (studioLiveQualitySelect) {
        studioLiveQualitySelect.value = state.streamQuality;
      }
      if (studioLiveFrameRateSelect) {
        studioLiveFrameRateSelect.value = String(Number(state.frameRatePreference || 24));
      }
      if (studioLiveMirrorToggle) {
        studioLiveMirrorToggle.checked = !!state.mirrorPreview;
      }
      if (previewVideo) {
        previewVideo.classList.toggle('is-mirrored-preview', !!state.mirrorPreview);
      }
      if (studioLiveModalLocalVideo) {
        studioLiveModalLocalVideo.classList.toggle('is-mirrored-preview', !!state.mirrorPreview);
      }
    }

    function syncHostMediaProfile() {
      if (state.mediaStream && state.mediaStream.getVideoTracks) {
        const videoTrack = state.mediaStream.getVideoTracks()[0];
        if (videoTrack && typeof videoTrack.applyConstraints === 'function') {
          const profile = hostMediaProfile();
          videoTrack.contentHint = 'motion';
          videoTrack.applyConstraints({
            width: { ideal: profile.width },
            height: { ideal: profile.height },
            frameRate: { ideal: profile.frameRate, max: profile.frameRate }
          }).catch(function() {});
        }
      }
      Object.keys(state.peerConnections || {}).forEach(function(key) {
        const entry = state.peerConnections[key];
        if (!entry || !entry.pc || typeof entry.pc.getSenders !== 'function') {
          return;
        }
        entry.pc.getSenders().forEach(function(sender) {
          tuneSenderForTile(sender, entry.kind);
        });
      });
    }

    function detailsReady() {
      return titleInput.value.trim() !== '' && descriptionInput.value.trim() !== '' && visibilityInput.value.trim() !== '';
    }

    function completedSteps() {
      const cameraReady = state.cameraOn ? 1 : 0;
      const detailsDone = detailsReady() ? 1 : 0;
      const liveDone = state.live && state.live.status === 'live' ? 1 : 0;
      return cameraReady + detailsDone + liveDone;
    }

    function setFeedback(message, kind = '') {
      feedback.textContent = message;
      feedback.className = 'studio-feedback' + (kind ? ` ${kind}` : '');
    }

    function setModalComposeFeedback(message, kind = '') {
      if (!studioModalComposeFeedback) return;
      studioModalComposeFeedback.textContent = message || '';
      studioModalComposeFeedback.className = 'studio-live-compose-feedback' + (kind ? ` ${kind}` : '');
    }

    function currentSourceConfig() {
      if (state.source === 'webcam') {
        return {
          stepTitle: 'Turn on camera',
          stepBody: 'Start with your webcam so the preview is ready before you go live.',
          inactiveHint: 'Step 1: turn on the host camera preview. Step 2: complete the room details. Step 3: start live or schedule the session.',
          readyHint: 'Camera is ready. Complete the title, description, and visibility before starting the live.',
          endedHint: 'Live ended. Please click Turn Camera On again, then complete the room details and start the next live.',
          onTitle: 'Camera preview is ready',
          onText: 'Your host camera is ready. Complete the room details and then start the live.',
          offTitle: 'Allow access to camera',
          offText: 'Your browser is not allowing camera access for Live',
          endedText: 'Your last live has ended. Please turn the camera on again before starting the next live.',
          startLabel: 'Turn Camera On',
          stopLabel: 'Turn Camera Off',
          statusOffLabel: 'CAMERA OFF',
          statusOnLabel: 'CAMERA ON',
          statusOffText: 'Preview is off. Click Turn Camera On when you want to show video again.',
          statusOnText: 'Preview is ready. Review the room details and then start or schedule the session.',
          endedStatusText: 'Your live has ended. Please click Turn Camera On again before you start a new live.',
          stopFeedback: 'Camera preview turned off.'
        };
      }

      if (state.softwareMode !== 'page' && state.softwareMode !== 'external') {
        return {
          stepTitle: 'Start software source',
          stepBody: 'Use a local video file or another software source before you go live.',
          inactiveHint: 'Step 1: start your software source. Step 2: complete the room details. Step 3: start live or schedule the session.',
          readyHint: 'Source is ready. Complete the title, description, and visibility before starting the live.',
          endedHint: 'Live ended. Please start your software source again, then complete the room details and start the next live.',
          onTitle: 'Video file is ready',
          onText: 'Your selected video file is feeding the live preview.',
          offTitle: state.softwareFile ? 'Video file is selected' : 'Choose a video file',
          offText: state.softwareFile
            ? 'Click Play Video File to load the selected file into the live preview.'
            : 'Select a local video file to use it as the live source.',
          endedText: 'Your last live has ended. Please play the video file again before starting the next live.',
          startLabel: state.softwareFile ? 'Play Video File' : 'Choose Video File',
          stopLabel: 'Stop Source',
          statusOffLabel: 'SOURCE OFF',
          statusOnLabel: 'SOURCE ON',
          statusOffText: state.softwareFile
            ? 'Preview is off. Click Play Video File when you want to show video again.'
            : 'Preview is off. Choose a video file when you want to show video again.',
          statusOnText: 'Preview is ready. Review the room details and then start or schedule the session.',
          endedStatusText: 'Your live has ended. Please play the video file again before you start a new live.',
          stopFeedback: 'Video file preview turned off.'
        };
      }

      if (state.softwareMode === 'page') {
        return {
          stepTitle: 'Start software source',
          stepBody: 'Open a browser page, then share that browser tab before you go live.',
          inactiveHint: 'Step 1: share the browser tab you want to stream. Step 2: complete the room details. Step 3: start live or schedule the session.',
          readyHint: 'Browser tab is ready. Complete the title, description, and visibility before starting the live.',
          endedHint: 'Live ended. Please share the browser tab again, then complete the room details and start the next live.',
          onTitle: 'Browser tab is ready',
          onText: 'The selected browser tab is feeding the live preview.',
          offTitle: 'Open a browser page',
          offText: 'Enter a page URL, then share that browser tab into the live preview.',
          endedText: 'Your last live has ended. Please share the browser tab again before starting the next live.',
          startLabel: 'Share Browser Tab',
          stopLabel: 'Stop Source',
          statusOffLabel: 'SOURCE OFF',
          statusOnLabel: 'SOURCE ON',
          statusOffText: 'Preview is off. Click Share Browser Tab when you want to show video again.',
          statusOnText: 'Preview is ready. Review the room details and then start or schedule the session.',
          endedStatusText: 'Your live has ended. Please share the browser tab again before you start a new live.',
          stopFeedback: 'Browser tab preview turned off.'
        };
      }

      if (state.softwareMode === 'external') {
        return {
          stepTitle: 'Start software source',
          stepBody: 'Share the app window that is showing your external feed before you go live.',
          inactiveHint: 'Step 1: share the window that shows your feed. Step 2: complete the room details. Step 3: start live or schedule the session.',
          readyHint: 'Shared feed window is ready. Complete the title, description, and visibility before starting the live.',
          endedHint: 'Live ended. Please share the feed window again, then complete the room details and start the next live.',
          onTitle: 'Feed window is ready',
          onText: 'The shared encoder or player window is feeding the live preview.',
          offTitle: 'Share encoder or player window',
          offText: 'Use this when OBS, NDI Studio Monitor, VLC, or another app is already showing the feed.',
          endedText: 'Your last live has ended. Please share the feed window again before starting the next live.',
          startLabel: 'Share Feed Window',
          stopLabel: 'Stop Source',
          statusOffLabel: 'SOURCE OFF',
          statusOnLabel: 'SOURCE ON',
          statusOffText: 'Preview is off. Click Share Feed Window when you want to show video again.',
          statusOnText: 'Preview is ready. Review the room details and then start or schedule the session.',
          endedStatusText: 'Your live has ended. Please share the feed window again before you start a new live.',
          stopFeedback: 'Shared feed window turned off.'
        };
      }

    }

    function syncSourceUi() {
      if (softwarePanel) {
        softwarePanel.hidden = state.source !== 'software';
      }
      sourceCards.forEach(function(card) {
        const cardSource = String(card.getAttribute('data-source') || 'webcam');
        card.classList.toggle('is-selected', cardSource === state.source);
      });
      softwareModeCards.forEach(function(card) {
        const mode = String(card.getAttribute('data-software-mode') || 'file');
        card.classList.toggle('is-selected', mode === state.softwareMode);
      });
      softwarePanels.forEach(function(panel) {
        const mode = String(panel.getAttribute('data-software-panel') || '');
        panel.classList.toggle('is-active', mode === state.softwareMode);
      });
      if (softwareFileName) {
        softwareFileName.textContent = state.softwareFileName || 'No file selected yet.';
      }
      if (softwarePageUrl && state.softwarePageUrl && softwarePageUrl.value !== state.softwarePageUrl) {
        softwarePageUrl.value = state.softwarePageUrl;
      }
    }

    function revokePreviewObjectUrl() {
      if (!state.previewObjectUrl) {
        return;
      }
      try {
        URL.revokeObjectURL(state.previewObjectUrl);
      } catch (error) {
        // ignore revoke failures
      }
      state.previewObjectUrl = '';
    }

    function clearSourceCleanup() {
      if (typeof state.sourceCleanup === 'function') {
        try {
          state.sourceCleanup();
        } catch (error) {
          // ignore cleanup failures
        }
      }
      state.sourceCleanup = null;
    }

    function clearPreviewElement() {
      if (!previewVideo) {
        return;
      }
      previewVideo.pause();
      previewVideo.onended = null;
      previewVideo.removeAttribute('src');
      previewVideo.srcObject = null;
      previewVideo.loop = false;
      previewVideo.classList.remove('is-host-streaming');
      try {
        previewVideo.load();
      } catch (error) {
        // ignore reload failures
      }
    }

    function bindStreamEnded(stream, message) {
      const track = stream && stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
      if (!track || typeof track.addEventListener !== 'function') {
        return null;
      }
      const handleEnded = function() {
        if (state.mediaStream !== stream) {
          return;
        }
        stopCameraStream();
        setFeedback(message, '');
        renderCameraState();
      };
      track.addEventListener('ended', handleEnded);
      return function() {
        track.removeEventListener('ended', handleEnded);
      };
    }

    function clearSnapshotRelayVideo() {
      const relayVideo = state.snapshotRelayVideo;
      state.snapshotRelayVideo = null;
      state.snapshotRelayStream = null;
      if (!relayVideo) {
        return;
      }
      relayVideo.pause();
      relayVideo.srcObject = null;
    }

    function ensureSnapshotRelayVideo() {
      if (!state.mediaStream) {
        clearSnapshotRelayVideo();
        return null;
      }
      if (state.snapshotRelayVideo && state.snapshotRelayStream === state.mediaStream) {
        if (state.snapshotRelayVideo.paused && !state.snapshotRelayVideo.ended) {
          state.snapshotRelayVideo.play().catch(function() {});
        }
        return state.snapshotRelayVideo;
      }

      clearSnapshotRelayVideo();
      const relayVideo = document.createElement('video');
      relayVideo.autoplay = true;
      relayVideo.playsInline = true;
      relayVideo.muted = true;
      relayVideo.defaultMuted = true;
      relayVideo.setAttribute('muted', 'muted');
      relayVideo.srcObject = state.mediaStream;
      relayVideo.play().catch(function() {});
      state.snapshotRelayVideo = relayVideo;
      state.snapshotRelayStream = state.mediaStream;
      return relayVideo;
    }

    function stopSoftwareFileHealthLoop() {
      if (state.softwarePlaybackTimer) {
        clearInterval(state.softwarePlaybackTimer);
        state.softwarePlaybackTimer = null;
      }
      state.softwareLastCurrentTime = 0;
      state.softwareLastAdvanceAt = 0;
    }

    function shouldWatchSoftwareFilePlayback() {
      return !!(previewVideo
        && state.source === 'software'
        && state.softwareMode === 'file'
        && state.cameraOn
        && state.mediaStream
        && state.softwareFile);
    }

    function markSoftwareFileProgress(forceRefresh) {
      if (!shouldWatchSoftwareFilePlayback() || !previewVideo) {
        return;
      }
      const currentTime = Number(previewVideo.currentTime || 0);
      if (forceRefresh || currentTime > (Number(state.softwareLastCurrentTime || 0) + 0.01) || !state.softwareLastAdvanceAt) {
        state.softwareLastCurrentTime = currentTime;
        state.softwareLastAdvanceAt = Date.now();
      }
    }

    function reviveSoftwareFilePlayback() {
      if (!shouldWatchSoftwareFilePlayback() || !previewVideo) {
        return;
      }
      const duration = Number(previewVideo.duration || 0);
      if (previewVideo.ended || (duration > 0 && Number(previewVideo.currentTime || 0) >= Math.max(0, duration - 0.25))) {
        try {
          previewVideo.currentTime = 0;
        } catch (error) {
          // ignore failed rewind attempts
        }
      }
      previewVideo.play().catch(function() {});
    }

    function syncSoftwareFileHealthLoop() {
      if (!shouldWatchSoftwareFilePlayback()) {
        stopSoftwareFileHealthLoop();
        return;
      }
      if (state.softwarePlaybackTimer) {
        return;
      }
      markSoftwareFileProgress(true);
      state.softwarePlaybackTimer = window.setInterval(function() {
        if (!shouldWatchSoftwareFilePlayback()) {
          stopSoftwareFileHealthLoop();
          return;
        }
        if (previewVideo.paused && !previewVideo.ended) {
          reviveSoftwareFilePlayback();
        }
        const age = Date.now() - Number(state.softwareLastAdvanceAt || 0);
        if (age > 3500) {
          reviveSoftwareFilePlayback();
        }
      }, 1500);
    }

    function normalizeSoftwarePageUrl(rawValue) {
      const raw = String(rawValue || '').trim();
      if (!raw) {
        return '';
      }
      try {
        const parsed = /^[a-z][a-z0-9+.-]*:/i.test(raw) || raw.startsWith('/')
          ? new URL(raw, window.location.origin)
          : new URL('https://' + raw, window.location.origin);
        if (!/^https?:$/i.test(String(parsed.protocol || ''))) {
          return '';
        }
        return parsed.toString();
      } catch (error) {
        return '';
      }
    }

    function openSoftwarePageHelper() {
      const normalizedUrl = normalizeSoftwarePageUrl(softwarePageUrl ? softwarePageUrl.value : state.softwarePageUrl);
      if (!normalizedUrl) {
        throw new Error('Enter a valid browser page URL first.');
      }
      state.softwarePageUrl = normalizedUrl;
      if (softwarePageUrl) {
        softwarePageUrl.value = normalizedUrl;
      }
      const helperWindow = window.open(normalizedUrl, '_blank');
      if (!helperWindow) {
        throw new Error('The browser page helper was blocked. Allow pop-ups and try again.');
      }
      return normalizedUrl;
    }

    function formatCompactCount(value) {
      const number = Number(value || 0);
      const abs = Math.abs(number);
      if (abs >= 1000000000) {
        return (number / 1000000000).toFixed(abs >= 10000000000 ? 0 : 1).replace(/\.0$/, '') + 'b';
      }
      if (abs >= 1000000) {
        return (number / 1000000).toFixed(abs >= 10000000 ? 0 : 1).replace(/\.0$/, '') + 'M';
      }
      if (abs >= 1000) {
        return (number / 1000).toFixed(abs >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k';
      }
      return String(number);
    }

    function normalizedHistorySummary() {
      const summary = state.historySummary || {};
      return {
        title: String(summary.title || 'No finished live yet'),
        ended_at_label: String(summary.ended_at_label || 'Finish a live session to keep its counts here.'),
        views: Number(summary.views || 0),
        comments: Number(summary.comments || 0),
        shares: Number(summary.shares || 0),
        love: Number(summary.love || 0),
        like: Number(summary.like || 0),
        smile: Number(summary.smile || 0),
        care: Number(summary.care || 0),
        angry: Number(summary.angry || 0)
      };
    }

    function syncHistorySummary() {
      const summary = normalizedHistorySummary();
      if (historySummaryTitle) historySummaryTitle.textContent = summary.title;
      if (historySummaryMeta) historySummaryMeta.textContent = summary.ended_at_label;
      if (historyViewsCount) historyViewsCount.textContent = formatCompactCount(summary.views);
      if (historyCommentsCount) historyCommentsCount.textContent = formatCompactCount(summary.comments);
      if (historySharesCount) historySharesCount.textContent = formatCompactCount(summary.shares);
      if (historyLoveCount) historyLoveCount.textContent = formatCompactCount(summary.love);
      if (historyLikeCount) historyLikeCount.textContent = formatCompactCount(summary.like);
      if (historySmileCount) historySmileCount.textContent = formatCompactCount(summary.smile);
      if (historyCareCount) historyCareCount.textContent = formatCompactCount(summary.care);
      if (historyAngryCount) historyAngryCount.textContent = formatCompactCount(summary.angry);
    }

    function shouldUseLiveStatsChart(live) {
      const currentLive = live || {};
      return String(currentLive.status || '').toLowerCase() === 'live';
    }

    function currentStatsChartValues(live, fallbackSummary) {
      const summary = fallbackSummary || normalizedHistorySummary();
      const currentLive = live || {};
      const useLiveCounts = shouldUseLiveStatsChart(currentLive);
      return {
        views: Number(useLiveCounts ? (currentLive.viewer_count || 0) : summary.views),
        comments: Number(useLiveCounts ? ((commentCount && commentCount.textContent) || 0) : summary.comments),
        shares: Number(useLiveCounts ? (currentLive.share_count || 0) : summary.shares),
        love: Number(useLiveCounts ? (document.querySelector('[data-reaction-count="love"]') ? document.querySelector('[data-reaction-count="love"]').textContent || 0 : 0) : summary.love),
        like: Number(useLiveCounts ? (document.querySelector('[data-reaction-count="like"]') ? document.querySelector('[data-reaction-count="like"]').textContent || 0 : 0) : summary.like),
        smile: Number(useLiveCounts ? (document.querySelector('[data-reaction-count="wow"]') ? document.querySelector('[data-reaction-count="wow"]').textContent || 0 : 0) : summary.smile),
        care: Number(useLiveCounts ? (document.querySelector('[data-reaction-count="clap"]') ? document.querySelector('[data-reaction-count="clap"]').textContent || 0 : 0) : summary.care),
        angry: Number(useLiveCounts ? (document.querySelector('[data-reaction-count="fire"]') ? document.querySelector('[data-reaction-count="fire"]').textContent || 0 : 0) : summary.angry)
      };
    }

    function syncStatsChartTitle(live) {
      if (!statsChartTitle) return;
      statsChartTitle.textContent = shouldUseLiveStatsChart(live) ? 'Live Room Statistics' : 'Recent Session Statistics';
    }

    function setChatSidebarOpen(isOpen) {
      setStudioSidebarMode(isOpen ? 'chat' : '');
    }

    function syncStudioDescriptionPanel() {
      const title = (state.live && state.live.title) || titleInput.value.trim() || 'Please stop by saying HI';
      const description = (state.live && state.live.description) || descriptionInput.value.trim() || 'Share update, answer questions, and go live with your audience.';
      const author = '<?php echo addslashes($meName); ?>' || 'Host';
      const stamp = new Date();
      const year = stamp.getFullYear();
      const month = String(stamp.getMonth() + 1).padStart(2, '0');
      const day = String(stamp.getDate()).padStart(2, '0');
      const hours = String(stamp.getHours()).padStart(2, '0');
      const minutes = String(stamp.getMinutes()).padStart(2, '0');
      if (studioLiveDescriptionTitle) {
        studioLiveDescriptionTitle.textContent = title;
      }
      if (studioLiveDescriptionAvatar) {
        studioLiveDescriptionAvatar.textContent = '<?php echo addslashes($initials); ?>';
      }
      if (studioLiveDescriptionSub) {
        studioLiveDescriptionSub.textContent = author + ' • ' + year + '-' + month + '-' + day + ' ' + hours + ':' + minutes;
      }
      if (studioLiveDescriptionBody) {
        studioLiveDescriptionBody.textContent = description;
      }
    }

    function setStudioSidebarMode(mode) {
      if (!studioLiveModalDialog) return;
      const nextMode = mode === 'reactions'
        ? 'reactions'
        : (mode === 'description'
          ? 'description'
          : (mode === 'settings' ? 'settings' : (mode === 'chat' ? 'chat' : '')));
      state.sidebarMode = nextMode;
      studioLiveModalDialog.classList.toggle('has-chat', nextMode !== '');
      studioLiveModalDialog.classList.toggle('sidebar-mode-chat', nextMode === 'chat');
      studioLiveModalDialog.classList.toggle('sidebar-mode-reactions', nextMode === 'reactions');
      studioLiveModalDialog.classList.toggle('sidebar-mode-description', nextMode === 'description');
      studioLiveModalDialog.classList.toggle('sidebar-mode-settings', nextMode === 'settings');
      if (studioLiveSettingsToggle) {
        studioLiveSettingsToggle.classList.toggle('is-active', nextMode === 'settings');
        studioLiveSettingsToggle.setAttribute('aria-pressed', nextMode === 'settings' ? 'true' : 'false');
      }
      if (studioLiveChatToggle) {
        studioLiveChatToggle.classList.toggle('is-active', nextMode === 'chat');
        studioLiveChatToggle.setAttribute('aria-pressed', nextMode === 'chat' ? 'true' : 'false');
      }
      if (studioLiveReactionToggle) {
        studioLiveReactionToggle.classList.toggle('is-active', nextMode === 'reactions');
        studioLiveReactionToggle.setAttribute('aria-pressed', nextMode === 'reactions' ? 'true' : 'false');
      }
      if (studioLiveDescriptionToggle) {
        studioLiveDescriptionToggle.classList.toggle('is-active', nextMode === 'description');
        studioLiveDescriptionToggle.setAttribute('aria-pressed', nextMode === 'description' ? 'true' : 'false');
      }
      if (nextMode === 'description') {
        syncStudioDescriptionPanel();
      }
      syncStudioLiveSettingsUi();
      syncStudioSidebarHeader();
    }

    function setEndConfirmOpen(isOpen) {
      if (!studioLiveConfirm) return;
      studioLiveConfirm.classList.toggle('is-open', !!isOpen);
      studioLiveConfirm.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }

    function escHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function initialsForName(value) {
      const parts = String(value || '').trim().split(/\s+/).filter(Boolean);
      if (!parts.length) return 'U';
      const first = parts[0].charAt(0) || '';
      const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
      return (first + last).toUpperCase() || 'U';
    }

    function commentTone(name) {
      const raw = String(name || '');
      let total = 0;
      for (let i = 0; i < raw.length; i += 1) total += raw.charCodeAt(i);
      return total % 360;
    }

    function reactionEmoji(value) {
      const key = String(value || '').toLowerCase();
      if (key === 'like') return '&#128077;';
      if (key === 'love') return '<span class="reaction-love-heart" aria-hidden="true">&#10084;</span>';
      if (key === 'clap') return '&#129392;';
      if (key === 'wow') return '&#128558;';
      if (key === 'fire') return '&#128545;';
      return '&#128077;';
    }

    function reactionLabel(value) {
      const key = String(value || '').toLowerCase();
      if (key === 'like') return 'Like';
      if (key === 'love') return 'Love';
      if (key === 'clap') return 'Care';
      if (key === 'wow') return 'Wow';
      if (key === 'fire') return 'Angry';
      return 'Reaction';
    }

    function reactionBurstEmoji(value) {
      const key = String(value || '').toLowerCase();
      if (key === 'love') return '❤️';
      if (key === 'like') return '👍';
      if (key === 'clap') return '🥰';
      if (key === 'wow') return '😮';
      if (key === 'fire') return '😡';
      return '👍';
    }

    function spawnStudioStageReaction(reaction, index) {
      if (!studioLiveStageReactions || !studioLiveModal || !studioLiveModal.classList.contains('is-open')) return;
      const bubble = document.createElement('div');
      bubble.className = 'studio-live-stage-reaction';
      if (String(reaction || '').toLowerCase() === 'love') {
        bubble.classList.add('is-love');
      }
      bubble.textContent = reactionBurstEmoji(reaction);
      const horizontal = [0, 20, -18, 38, -30][Number(index || 0) % 5];
      const vertical = [78, 62, 48, 34, 18][Number(index || 0) % 5];
      bubble.style.right = Math.max(12, 34 + horizontal) + 'px';
      bubble.style.bottom = (60 + vertical) + 'px';
      studioLiveStageReactions.appendChild(bubble);
      window.setTimeout(function() {
        bubble.remove();
      }, 5200);
    }

    function friendActionMeta(status) {
      const value = String(status || 'none');
      if (value === 'friends' || value === 'self') return { label: '', disabled: true, className: 'is-static' };
      if (value === 'outgoing_pending') return { label: 'Request sent', disabled: true, className: 'is-static' };
      if (value === 'incoming_pending') return { label: 'Accept request', disabled: true, className: 'is-static' };
      return { label: 'Add friend', disabled: false, className: '' };
    }

    function syncStudioSidebarHeader() {
      if (state.sidebarMode === 'reactions') {
        if (studioSidebarTitleText) studioSidebarTitleText.textContent = 'Reactions';
        if (studioSidebarTitleCount) {
          const total = ['love', 'like', 'fire', 'wow', 'clap'].reduce(function(sum, key) {
            return sum + Number(state.reactionCounts[key] || 0);
          }, 0);
          studioSidebarTitleCount.textContent = String(total);
        }
        return;
      }
      if (state.sidebarMode === 'description') {
        if (studioSidebarTitleText) studioSidebarTitleText.textContent = 'Description';
        if (studioSidebarTitleCount) studioSidebarTitleCount.textContent = '';
        return;
      }
      if (state.sidebarMode === 'settings') {
        if (studioSidebarTitleText) studioSidebarTitleText.textContent = 'Settings';
        if (studioSidebarTitleCount) studioSidebarTitleCount.textContent = '';
        return;
      }
      if (studioSidebarTitleText) studioSidebarTitleText.textContent = 'Comments';
      if (studioSidebarTitleCount) studioSidebarTitleCount.textContent = String(studioModalSidebarCommentCount ? studioModalSidebarCommentCount.textContent || '0' : 0);
    }

    function renderStudioReactionPanel() {
      if (!studioLiveReactionTabs || !studioLiveReactionList) return;
      const counts = state.reactionCounts || {};
      const tabOrder = ['all', 'like', 'love', 'wow', 'clap', 'fire'];
      const total = ['love', 'like', 'fire', 'wow', 'clap'].reduce(function(sum, key) {
        return sum + Number(counts[key] || 0);
      }, 0);
      studioLiveReactionTabs.innerHTML = tabOrder.map(function(key) {
        const count = key === 'all' ? total : Number(counts[key] || 0);
        const label = key === 'all' ? 'All' : reactionEmoji(key) + ' ' + escHtml(String(count));
        return '<button type="button" class="studio-live-reaction-tab' + (state.reactionFilter === key ? ' is-active' : '') + '" data-reaction-filter="' + key + '">' + label + (key === 'all' ? (' <span>' + escHtml(String(count)) + '</span>') : '') + '</button>';
      }).join('');
      const filtered = (state.reactionUsers || []).filter(function(item) {
        return state.reactionFilter === 'all' ? true : String(item.reaction || '') === state.reactionFilter;
      });
      if (!filtered.length) {
        studioLiveReactionList.innerHTML = '<div class="studio-live-reaction-empty">No reactions yet.</div>';
        syncStudioSidebarHeader();
        return;
      }
      studioLiveReactionList.innerHTML = filtered.map(function(item) {
        const name = escHtml(item.name || 'User');
        const initials = escHtml(initialsForName(item.name || 'User'));
        const tone = commentTone(item.name || 'User');
        const action = friendActionMeta(item.friend_status);
        const actionHtml = action.label
          ? '<button type="button" class="studio-live-reaction-action ' + action.className + '" data-reactor-action="friend" data-user-id="' + Number(item.user_id || 0) + '"' + (action.disabled ? ' disabled' : '') + '><i class="fa fa-user-plus" aria-hidden="true"></i> ' + escHtml(action.label) + '</button>'
          : '';
        return '<div class="studio-live-reaction-item" data-reaction-user="' + Number(item.user_id || 0) + '">'
          + '<div class="studio-live-reaction-avatar" style="background:linear-gradient(135deg, hsl(' + tone + ' 80% 62%), hsl(' + ((tone + 38) % 360) + ' 78% 54%));">' + initials + '<span class="studio-live-reaction-badge">' + reactionEmoji(item.reaction) + '</span></div>'
          + '<div class="studio-live-reaction-main"><div class="studio-live-reaction-name">' + name + '</div><div class="studio-live-reaction-time"><span class="studio-live-reaction-type">' + escHtml(reactionLabel(item.reaction)) + '</span>' + escHtml(item.created_at_label || 'Now') + '</div></div>'
          + actionHtml
          + '</div>';
      }).join('');
      syncStudioSidebarHeader();
    }

    async function sendStudioFriendRequest(peerId) {
      const formData = new FormData();
      formData.append('peer_id', String(peerId));
      const data = await fetchJsonSafe('ajax/friend_action.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      if (!data || !data.ok) {
        throw new Error(data && data.message ? data.message : 'Unable to send friend request.');
      }
      state.reactionUsers = (state.reactionUsers || []).map(function(item) {
        if (Number(item.user_id || 0) !== Number(peerId || 0)) return item;
        return Object.assign({}, item, { friend_status: String(data.status || 'outgoing_pending') });
      });
      renderStudioReactionPanel();
      return data;
    }

    function renderGuestRequestPanel() {
      const hasRequests = !!(state.guestRequests && state.guestRequests.length);
      if (studioLiveRequestDock) {
        studioLiveRequestDock.classList.toggle('is-open', hasRequests);
      }
      if (studioLiveRequestList) {
        studioLiveRequestList.innerHTML = (state.guestRequests || []).map((item) => {
          const name = escHtml(item.name || 'Viewer');
          return `
            <div class="studio-live-request-item">
              <div class="studio-live-request-name">${name}</div>
              <div class="studio-live-request-sub">Wants to join your live room as a guest.</div>
              <div class="studio-live-request-actions">
                <button type="button" class="studio-live-request-btn confirm" data-guest-action="confirm" data-guest-user="${Number(item.user_id || 0)}">Confirm</button>
                <button type="button" class="studio-live-request-btn" data-guest-action="deny" data-guest-user="${Number(item.user_id || 0)}">Deny</button>
              </div>
            </div>
          `;
        }).join('');
      }

      if (studioLiveRequestEmpty) {
        studioLiveRequestEmpty.style.display = hasRequests ? 'none' : '';
      }

      if (studioLiveApprovedList) {
        studioLiveApprovedList.innerHTML = (state.approvedGuests || []).map((item) => {
          const name = escHtml(item.name || 'Guest');
          return `<div class="studio-live-approved-pill">${name} joined live</div>`;
        }).join('');
      }
    }

    function removeGuestStream(userId) {
      const id = Number(userId || 0);
      const entry = state.guestStreams[id];
      if (!entry) return;
      try {
        if (entry.video && entry.video.srcObject) {
          entry.video.srcObject = null;
        }
      } catch (error) {}
      if (entry.relayVideo) {
        try {
          entry.relayVideo.pause();
          entry.relayVideo.srcObject = null;
        } catch (error) {}
      }
      delete state.guestStreams[id];
      delete state.guestSnapshotBusy[id];
    }

    function stopGuestTileSnapshotLoop() {
      if (state.guestTileSnapshotTimer) {
        clearInterval(state.guestTileSnapshotTimer);
        state.guestTileSnapshotTimer = null;
      }
      state.guestTileSnapshotIntervalMs = 0;
    }

    function usesSnapshotOnlyGuestStage() {
      return false;
    }

    function liveGuestVideoIds() {
      return (Array.isArray(state.approvedGuests) ? state.approvedGuests : [])
        .map(function(item) { return Number(item.user_id || 0); })
        .filter(Boolean);
    }

    function syncActiveGuestVideoPeers() {
      const activeIds = liveGuestVideoIds();
      Object.keys(state.peerConnections).forEach(function(key) {
        const entry = state.peerConnections[key];
        if (!entry) return;
        const targetGuestId = entry.kind === 'guest-publisher'
          ? Number(entry.viewerId || 0)
          : (entry.kind === 'guest-relay' ? Number(entry.guestRelayUserId || 0) : 0);
        if (!targetGuestId || activeIds.includes(targetGuestId)) {
          return;
        }
        if (entry.viewerId) {
          sendRtcSignal(entry.viewerId, key, 'bye', {}).catch(function() {});
        }
        closePeerConnection(key);
      });
    }

    function syncSnapshotOnlyGuestPeers() {
      const snapshotOnly = usesSnapshotOnlyGuestStage();
      Object.keys(state.peerConnections).forEach((key) => {
        const entry = state.peerConnections[key];
        if (!entry) return;
        const isGuestPeer = entry.kind === 'guest-publisher' || entry.kind === 'guest-relay';
        if (snapshotOnly && isGuestPeer) {
          closePeerConnection(key);
        }
      });
    }

    function refreshGuestTileSnapshots() {
      if (!studioLiveGuestLayer || !(state.live && Number(state.live.id || 0) > 0)) {
        return;
      }
      const snapshotTiles = Array.from(studioLiveGuestLayer.querySelectorAll('[data-guest-tile].has-snapshot'));
      if (!snapshotTiles.length) {
        return;
      }
      snapshotTiles.forEach((tile) => {
        const userId = Number(tile.getAttribute('data-guest-tile') || 0);
        const image = tile.querySelector('.studio-live-guest-image');
        if (!userId || !image) return;
        const refreshUrl = 'ajax/live_snapshot.php?live=' + encodeURIComponent(String(state.live.id))
          + '&guest_user_id=' + encodeURIComponent(String(userId))
          + '&r=' + encodeURIComponent(String(Date.now()));
        setImageSourceWhenReady(image, refreshUrl, {
          token: 'studio-guest-refresh-' + String(userId) + '-' + String(Date.now()),
          onLoaded: function() {
            tile.classList.add('snapshot-ready');
            state.guestSnapshotUrls[userId] = refreshUrl;
          },
          onError: function() {
            tile.classList.remove('snapshot-ready');
          }
        });
      });
    }

    function syncGuestTileSnapshotLoop() {
      if (!studioLiveGuestLayer || !(state.live && Number(state.live.id || 0) > 0) || String(state.live.status || '').toLowerCase() !== 'live') {
        stopGuestTileSnapshotLoop();
        return;
      }
      const snapshotTileCount = studioLiveGuestLayer.querySelectorAll('[data-guest-tile].has-snapshot').length;
      if (snapshotTileCount <= 0) {
        stopGuestTileSnapshotLoop();
        return;
      }
      const intervalMs = snapshotTileCount >= 2 ? 1400 : 1100;
      if (state.guestTileSnapshotTimer && state.guestTileSnapshotIntervalMs === intervalMs) {
        return;
      }
      if (state.guestTileSnapshotTimer) {
        clearInterval(state.guestTileSnapshotTimer);
      }
      refreshGuestTileSnapshots();
      state.guestTileSnapshotTimer = window.setInterval(refreshGuestTileSnapshots, intervalMs);
      state.guestTileSnapshotIntervalMs = intervalMs;
    }

    function syncHostDualStage() {
      if (!studioLiveModalStage || !studioLiveGuestLayer) return;
      const guestCount = studioLiveGuestLayer.querySelectorAll('[data-guest-tile]').length;
      const hostStageClasses = ['has-dual-stage', 'has-three-stage', 'has-four-stage', 'has-five-stage', 'has-six-stage', 'has-seven-stage', 'has-eight-stage', 'has-nine-stage', 'has-ten-stage', 'has-eleven-stage', 'has-twelve-stage', 'has-thirteen-stage', 'has-fourteen-stage', 'has-fifteen-stage', 'has-sixteen-stage', 'has-seventeen-stage', 'has-eighteen-stage', 'has-nineteen-stage', 'has-twenty-stage', 'has-twentyone-stage', 'has-twentytwo-stage', 'has-twentythree-stage', 'has-twentyfour-stage', 'has-twentyfive-stage', 'has-twentyplus-stage', 'has-gallery-stage'];
      const hostStageLayoutByGuestCount = {
        1: 'has-dual-stage',
        2: 'has-three-stage',
        3: 'has-four-stage',
        4: 'has-five-stage',
        5: 'has-six-stage',
        6: 'has-seven-stage',
        7: 'has-eight-stage',
        8: 'has-nine-stage',
        9: 'has-ten-stage',
        10: 'has-eleven-stage',
        11: 'has-twelve-stage',
        12: 'has-thirteen-stage',
        13: 'has-fourteen-stage',
        14: 'has-fifteen-stage',
        15: 'has-sixteen-stage',
        16: 'has-seventeen-stage',
        17: 'has-eighteen-stage',
        18: 'has-nineteen-stage',
        19: 'has-twenty-stage',
        20: 'has-twentyone-stage',
        21: 'has-twentytwo-stage',
        22: 'has-twentythree-stage',
        23: 'has-twentyfour-stage',
        24: 'has-twentyplus-stage',
        25: 'has-twentyplus-stage',
        26: 'has-twentyplus-stage',
        27: 'has-twentyplus-stage',
        28: 'has-twentyplus-stage',
        29: 'has-twentyplus-stage'
      };
      studioLiveModalStage.classList.remove(...hostStageClasses);
      if (guestCount <= 0) return;
      if (hostStageLayoutByGuestCount[guestCount]) {
        studioLiveModalStage.classList.add(hostStageLayoutByGuestCount[guestCount]);
        return;
      }
      studioLiveModalStage.classList.add('has-gallery-stage');
    }

    function renderGuestTiles() {
      if (!studioLiveGuestLayer) return;
      const approved = (Array.isArray(state.approvedGuests) ? state.approvedGuests : []).filter((item) => {
        return !!item;
      });
      const approvedIds = approved.map((item) => Number(item.user_id || 0)).filter(Boolean);
      const liveVideoIds = liveGuestVideoIds();
      syncActiveGuestVideoPeers();

      Object.keys(state.guestStreams).forEach((key) => {
        if (!approvedIds.includes(Number(key)) || !liveVideoIds.includes(Number(key))) {
          removeGuestStream(Number(key));
        }
      });

      Array.from(studioLiveGuestLayer.querySelectorAll('[data-guest-tile]')).forEach((tile) => {
        const tileId = Number(tile.getAttribute('data-guest-tile') || 0);
        if (!approvedIds.includes(tileId)) {
          delete state.guestSnapshotVersions[tileId];
          delete state.guestSnapshotUrls[tileId];
          tile.remove();
        }
      });

      approved.forEach((item) => {
        const userId = Number(item.user_id || 0);
        const name = String(item.name || 'Guest');
        const snapshotVersion = String(item.snapshot_version || '');
        const cameraEnabled = item.camera_enabled !== false;
        const entry = state.guestStreams[userId];
        const hasLiveStream = !!(entry && entry.stream);
        const connected = hasLiveStream;
        const useSnapshotTile = cameraEnabled && (!hasLiveStream || !liveVideoIds.includes(userId));
        let tile = studioLiveGuestLayer.querySelector(`[data-guest-tile="${userId}"]`);
        if (!tile) {
          tile = document.createElement('div');
          tile.className = 'studio-live-guest-tile';
          tile.setAttribute('data-guest-tile', String(userId));
          tile.innerHTML = `
            <video class="studio-live-guest-video" data-guest-video="${userId}" autoplay playsinline muted></video>
            <img class="studio-live-guest-image" data-guest-image="${userId}" alt="">
            <div class="studio-live-guest-placeholder" data-guest-placeholder="${userId}">
              <div class="studio-live-guest-placeholder-badge"></div>
            </div>
            <div class="studio-live-guest-camera-off" aria-hidden="true">
              <div class="studio-live-guest-camera-off-icon" aria-hidden="true">
                <i class="fa fa-video-camera"></i>
              </div>
            </div>
            <div class="studio-live-guest-meta">
              <div class="studio-live-guest-name"></div>
              <div class="studio-live-guest-state"></div>
            </div>
          `;
          studioLiveGuestLayer.appendChild(tile);
        }

        const nameNode = tile.querySelector('.studio-live-guest-name');
        const stateNode = tile.querySelector('.studio-live-guest-state');
        const video = tile.querySelector(`[data-guest-video="${userId}"]`);
        const image = tile.querySelector(`[data-guest-image="${userId}"]`);
        const placeholderBadge = tile.querySelector('.studio-live-guest-placeholder-badge');

        if (nameNode) {
          nameNode.textContent = name;
        }
        if (placeholderBadge) {
          placeholderBadge.textContent = initialsForName(name);
        }
        if (stateNode) {
          stateNode.textContent = !cameraEnabled
            ? 'Camera off'
            : (connected
            ? (useSnapshotTile ? 'Guest joined live' : 'Guest joined live')
            : 'Connecting guest camera...');
        }
        tile.classList.toggle('is-camera-off', !cameraEnabled);
        tile.classList.toggle('has-snapshot', useSnapshotTile);
        if (!useSnapshotTile || !cameraEnabled) {
          tile.classList.remove('snapshot-ready');
        }
        if (image) {
          image.alt = name;
          if (cameraEnabled && useSnapshotTile && state.live && Number(state.live.id || 0) > 0) {
            const snapshotUrl = 'ajax/live_snapshot.php?live=' + encodeURIComponent(String(state.live.id))
              + '&guest_user_id=' + encodeURIComponent(String(userId))
              + (snapshotVersion !== '' ? '&t=' + encodeURIComponent(String(snapshotVersion)) : '')
              + '&r=' + encodeURIComponent(String(Date.now()));
            if (state.guestSnapshotVersions[userId] !== snapshotVersion || state.guestSnapshotUrls[userId] !== snapshotUrl) {
              setImageSourceWhenReady(image, snapshotUrl, {
                token: 'studio-guest-' + String(userId) + '-' + String(Date.now()),
                onLoaded: function() {
                  tile.classList.add('snapshot-ready');
                  state.guestSnapshotVersions[userId] = snapshotVersion;
                  state.guestSnapshotUrls[userId] = snapshotUrl;
                },
                onError: function() {
                  tile.classList.remove('snapshot-ready');
                }
              });
            }
          } else {
            delete state.guestSnapshotVersions[userId];
            delete state.guestSnapshotUrls[userId];
            image.removeAttribute('src');
            delete image.dataset.loadToken;
            tile.classList.remove('snapshot-ready');
          }
        }
        if (entry && video && cameraEnabled && !useSnapshotTile) {
          entry.video = video;
          video.muted = true;
          video.defaultMuted = true;
          video.setAttribute('muted', 'muted');
          bindStudioGuestVideoHealth(userId, video);
          if (video.srcObject !== entry.stream) {
            video.srcObject = entry.stream;
          }
          video.muted = true;
          video.play().catch(() => {});
        } else if (video) {
          try {
            video.pause();
            video.srcObject = null;
          } catch (error) {}
          if (entry) {
            entry.video = null;
          }
        }
      });
      syncHostDualStage();
      syncGuestTileSnapshotLoop();
      syncGuestHealthLoop();
    }

    async function sendStudioComment(body) {
      const trimmed = String(body || '').trim();
      if (!trimmed) {
        throw new Error('Type a comment before sending.');
      }
      if (!(state.live && state.live.id)) {
        throw new Error('Create a live room first before sending comments.');
      }

      const formData = new FormData();
      formData.append('action', 'send_comment');
      formData.append('comment_body', trimmed);
      const data = await fetchJsonSafe('ajax/live_studio_room_action.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      if (!data || !data.ok) {
        throw new Error(data && data.error ? data.error : 'Unable to send comment');
      }
      return data;
    }

    async function updateGuestRequest(action, requestUserId) {
      if (!(state.live && state.live.id)) {
        throw new Error('No active live room');
      }
      const formData = new FormData();
      formData.append('action', action);
      formData.append('request_user_id', String(requestUserId));
      const data = await fetchJsonSafe('ajax/live_studio_room_action.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      if (!data || !data.ok) {
        throw new Error(data && data.error ? data.error : 'Unable to update guest request');
      }
      renderRoomData(data);
      return data;
    }

    async function fetchJsonSafe(url, options = {}) {
      const response = await fetch(url, options);
      const raw = await response.text();
      let data = null;

      try {
        data = raw ? JSON.parse(raw) : null;
      } catch (error) {
        const trimmed = String(raw || '').trim().toLowerCase();
        if (trimmed.startsWith('<!doctype') || trimmed.startsWith('<html') || trimmed.startsWith('<h')) {
          throw new Error('Server returned HTML instead of JSON. Refresh the page and try again.');
        }
        throw new Error('Server returned an invalid response. Refresh the page and try again.');
      }

      if (!response.ok) {
        throw new Error((data && data.error) ? data.error : 'Request failed');
      }

      return data;
    }

    function syncSummary() {
      const live = state.live || {};
      const status = String(live.status || 'draft').toLowerCase();
      currentSessionTitle.textContent = live.title || 'No active entry';
      if (studioModalTopTitle) {
        studioModalTopTitle.textContent = live.title || titleInput.value.trim() || 'Please stop by saying HI';
      }
      syncStudioDescriptionPanel();

      if (!live.id) {
        currentSessionMeta.textContent = 'Start one now or schedule a session from the launch form above';
      } else if (status === 'live') {
        currentSessionMeta.textContent = 'Live room is active and ready for viewers.';
      } else if (status === 'scheduled') {
        currentSessionMeta.textContent = scheduleInput.value ? `Scheduled for ${scheduleInput.value.replace('T', ' ')}` : 'Scheduled live session saved.';
      } else {
        currentSessionMeta.textContent = 'Draft saved. Complete setup checks before you start.';
      }

      currentSessionStatus.textContent = statusLabel(status);
      currentSessionStatus.className = `status-chip ${status}`;
      previewStatusPill.innerHTML = `<span class="tiny-dot"></span>${statusLabel(status)}`;
      historyCount.textContent = String(state.historyCount || 0);
      syncHistorySummary();
      if (streamKeyLabel) {
        streamKeyLabel.textContent = live.stream_key || 'Not created yet';
      }
      viewerCount.textContent = String(live.viewer_count || 0);
      if (shareCount) {
        shareCount.textContent = String(live.share_count || 0);
      }
      if (studioModalParticipantCount) {
        studioModalParticipantCount.textContent = String(live.viewer_count || 0);
      }
      if (studioModalSidebarViewCount) {
        studioModalSidebarViewCount.textContent = String(live.viewer_count || 0);
      }
      if (studioModalSidebarViewCountDock) {
        studioModalSidebarViewCountDock.textContent = String(live.viewer_count || 0);
      }
      if (studioModalVisibilityBadge) {
        studioModalVisibilityBadge.setAttribute('title', visibilityLabel(live.visibility || visibilityInput.value || 'private'));
      }
      state.snapshotVersion = String(live.snapshot_version || '');
      endLiveButton.style.display = status === 'live' ? '' : 'none';
      const fallbackSummary = normalizedHistorySummary();
      syncStatsChartTitle(live);
      updateStatsChart(currentStatsChartValues(live, fallbackSummary));
      syncSnapshotLoop();
    }

    function updateStatsChart(values) {
      const ticks = [statsTick5, statsTick4, statsTick3, statsTick2, statsTick1, statsTick0];
      const bars = [
        { valueNode: chartViewsValue, barNode: chartViewsBar, value: Number(values.views || 0) },
        { valueNode: chartCommentsValue, barNode: chartCommentsBar, value: Number(values.comments || 0) },
        { valueNode: chartSharesValue, barNode: chartSharesBar, value: Number(values.shares || 0) },
        { valueNode: chartLoveValue, barNode: chartLoveBar, value: Number(values.love || 0) },
        { valueNode: chartLikeValue, barNode: chartLikeBar, value: Number(values.like || 0) },
        { valueNode: chartSmileValue, barNode: chartSmileBar, value: Number(values.smile || 0) },
        { valueNode: chartCareValue, barNode: chartCareBar, value: Number(values.care || 0) },
        { valueNode: chartAngryValue, barNode: chartAngryBar, value: Number(values.angry || 0) }
      ];
      if (!ticks.every(Boolean) || !bars.every(function(item) { return item.valueNode && item.barNode; })) {
        return;
      }

      const maxRaw = Math.max.apply(null, bars.map(function(item) { return item.value; }).concat([10]));
      const maxValue = Math.max(25, Math.ceil((maxRaw * 1.2) / 5) * 5);
      const plotHeight = 196;
      const tickValues = [
        maxValue,
        Math.round(maxValue * 0.8),
        Math.round(maxValue * 0.6),
        Math.round(maxValue * 0.4),
        Math.round(maxValue * 0.2),
        0
      ];

      ticks.forEach(function(node, index) {
        node.textContent = formatCompactCount(tickValues[index]);
      });

      bars.forEach(function(item) {
        item.valueNode.textContent = formatCompactCount(item.value);
        item.barNode.style.height = (item.value > 0 ? Math.max(12, Math.round((item.value / maxValue) * plotHeight)) : 0) + 'px';
      });
    }

    function renderRoomData(payload) {
      const reactionCounts = payload && payload.reaction_counts ? payload.reaction_counts : {};
      const comments = payload && Array.isArray(payload.comments) ? payload.comments : [];
      const commentTotal = Number(payload && payload.comment_total ? payload.comment_total : comments.length);
      const myReaction = payload && payload.my_reaction ? String(payload.my_reaction) : '';
      const live = payload && payload.live ? payload.live : null;
      state.guestRequests = payload && Array.isArray(payload.guest_requests) ? payload.guest_requests : [];
      state.approvedGuests = payload && Array.isArray(payload.approved_guests)
        ? payload.approved_guests.slice(0, maxActiveGuests)
        : [];
      syncHostMediaProfile();

      if (live && state.live) {
        state.live.status = String(live.status || state.live.status || '');
        state.live.viewer_count = Number(live.viewer_count || 0);
        state.live.share_count = Number(live.share_count || 0);
        state.live.snapshot_version = String(live.snapshot_version || '');
        state.live.camera_enabled = !!live.camera_enabled;
        viewerCount.textContent = String(state.live.viewer_count || 0);
        if (shareCount) {
          shareCount.textContent = String(state.live.share_count || 0);
        }
        if (studioModalParticipantCount) {
          studioModalParticipantCount.textContent = String(state.live.viewer_count || 0);
        }
        if (studioModalSidebarViewCount) {
          studioModalSidebarViewCount.textContent = String(state.live.viewer_count || 0);
        }
        if (studioModalSidebarViewCountDock) {
          studioModalSidebarViewCountDock.textContent = String(state.live.viewer_count || 0);
        }
        state.snapshotVersion = String(live.snapshot_version || '');
      }

      let totalReactions = 0;
      ['love', 'like', 'fire', 'wow', 'clap'].forEach((key) => {
        const count = Number(reactionCounts[key] || 0);
        totalReactions += count;
        const countNode = document.querySelector(`[data-reaction-count="${key}"]`);
        const btn = document.querySelector(`[data-reaction="${key}"]`);
        if (countNode) countNode.textContent = String(count);
        if (btn) btn.classList.toggle('active', myReaction === key);
      });
      ['love', 'like', 'fire', 'wow', 'clap'].forEach((key) => {
        const nextCount = Number(reactionCounts[key] || 0);
        const prevCount = Number(studioLastReactionCounts[key] || 0);
        if (studioStageInitialized
          && studioLiveModal
          && studioLiveModal.classList.contains('is-open')
          && String((state.live || live || {}).status || '').toLowerCase() === 'live'
          && nextCount > prevCount) {
          const burst = Math.min(nextCount - prevCount, 3);
          for (let i = 0; i < burst; i += 1) {
            window.setTimeout(function() {
              spawnStudioStageReaction(key, i);
            }, i * 220);
          }
        }
        studioLastReactionCounts[key] = nextCount;
      });
      studioStageInitialized = true;
      reactionTotal.textContent = String(totalReactions);
      commentCount.textContent = String(commentTotal);
      syncStatsChartTitle(state.live || live || {});
      updateStatsChart(currentStatsChartValues(state.live || live || {}, normalizedHistorySummary()));
      if (studioModalChatCount) {
        studioModalChatCount.textContent = String(commentTotal);
      }
      if (studioModalReactionCount) {
        studioModalReactionCount.textContent = String(totalReactions);
      }
      if (studioModalSidebarReactionCount) {
        studioModalSidebarReactionCount.textContent = String(totalReactions);
      }
      if (studioModalSidebarCommentCount) {
        studioModalSidebarCommentCount.textContent = String(commentTotal);
      }
      if (studioModalCommentHeaderCount) {
        studioModalCommentHeaderCount.textContent = String(commentTotal);
      }
      if (studioSidebarTitleCount && state.sidebarMode !== 'reactions') {
        studioSidebarTitleCount.textContent = String(commentTotal);
      }
      state.reactionCounts = reactionCounts;
      state.reactionUsers = payload && Array.isArray(payload.reaction_users) ? payload.reaction_users : [];
      renderStudioReactionPanel();

      renderGuestRequestPanel();
      renderGuestTiles();
      syncSnapshotOnlyGuestPeers();

      if (!comments.length) {
        if (commentList) {
          commentList.innerHTML = '<div class="comment-item"><div class="comment-author">Studio</div><div class="comment-body">Comments will appear here after the host or viewers post into this live room.</div></div>';
        }
        if (studioModalCommentsBox && studioModalCommentList) {
          studioModalCommentsBox.classList.remove('has-comments');
          studioModalCommentList.innerHTML = 'Comments will appear here when viewers join your live session.';
        }
        return;
      }

      if (commentList) {
        const shouldStickPrimaryComments = (commentList.scrollHeight - commentList.scrollTop - commentList.clientHeight) <= 48;
        commentList.innerHTML = comments.map((item) => {
          const author = escHtml(item.author || 'User');
          const body = escHtml(item.body || '');
          return `<div class="comment-item"><div class="comment-author">${author}</div><div class="comment-body">${body}</div></div>`;
        }).join('');
        if (shouldStickPrimaryComments) {
          commentList.scrollTop = commentList.scrollHeight;
        }
      }

      if (studioModalCommentsBox && studioModalCommentList) {
        const shouldStickModalComments = (studioModalCommentList.scrollHeight - studioModalCommentList.scrollTop - studioModalCommentList.clientHeight) <= 48;
        studioModalCommentsBox.classList.add('has-comments');
        studioModalCommentList.innerHTML = comments.map((item) => {
          const author = escHtml(item.author || 'User');
          const body = escHtml(item.body || '');
          const isSelf = Number(item.user_id || 0) === Number(hostUserId || 0);
          const initials = escHtml(initialsForName(item.author || 'User'));
          const tone = commentTone(item.author || 'User');
          const meta = escHtml(String(item.created_at || '').trim() || 'Now');
          const likeCount = Number(item.like_count || 0);
          const likedByLabel = escHtml(String(item.liked_by_label || ''));
          return `<div class="studio-live-comment-card${isSelf ? ' is-self' : ''}" data-comment-id="${Number(item.id || 0)}" data-comment-author="${author}"><div class="studio-live-comment-avatar" style="background:linear-gradient(135deg, hsl(${tone} 80% 62%), hsl(${(tone + 38) % 360} 78% 54%));">${initials}</div><div class="studio-live-comment-main"><div class="studio-live-comment-author">${author}${isSelf ? '<span class="studio-live-comment-team">Team</span>' : ''}</div><div class="studio-live-comment-body">${body}</div><div class="studio-live-comment-meta"><span>${meta}</span><button type="button" class="studio-live-comment-reply">Reply</button><button type="button" class="studio-live-comment-like${item.liked_by_me ? ' is-liked' : ''}" aria-label="Like comment" title="${likedByLabel}"><i class="fa fa-heart-o" aria-hidden="true"></i>${likeCount > 0 ? `<span class="studio-live-comment-like-count">${likeCount}</span>` : ''}</button></div>${likedByLabel ? `<div class="studio-live-comment-likes">${likedByLabel}</div>` : ''}</div></div>`;
        }).join('');
        if (shouldStickModalComments) {
          studioModalCommentList.scrollTop = studioModalCommentList.scrollHeight;
        }
      }
    }

    async function pollRoomData() {
      if (!(state.live && state.live.id)) {
        return;
      }
      try {
        const data = await fetchJsonSafe('ajax/live_studio_room_action.php', {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store'
        });
        if (data && data.ok) {
          renderRoomData(data);
        }
      } catch (error) {
        // keep polling silent for host UI
      }
    }

    async function toggleStudioCommentLike(commentId) {
      const formData = new FormData();
      formData.append('action', 'toggle_comment_like');
      formData.append('comment_id', String(commentId));
      const data = await fetchJsonSafe('ajax/live_studio_room_action.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      if (!data || !data.ok) {
        throw new Error(data && data.error ? data.error : 'Unable to update comment like');
      }
      renderRoomData(data);
      return data;
    }

    async function persistStudioCameraEnabled() {
      if (!(state.live && Number(state.live.id || 0) > 0)) {
        return;
      }
      const formData = new FormData();
      formData.append('action', 'set_camera_enabled');
      formData.append('enabled', state.cameraOn && state.deviceCameraEnabled ? '1' : '0');
      try {
        const data = await fetchJsonSafe('ajax/live_studio_room_action.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
        if (data && data.ok) {
          renderRoomData(data);
        }
      } catch (error) {
        // keep camera visibility sync silent
      }
    }

    function restartRoomPolling() {
      if (state.roomPollTimer) {
        clearInterval(state.roomPollTimer);
        state.roomPollTimer = null;
      }
      if (!(state.live && state.live.id)) {
        return;
      }
      pollRoomData();
      state.roomPollTimer = window.setInterval(pollRoomData, 2000);
    }

    async function sendRtcSignal(receiverId, peerKey, signalType, payload) {
      const formData = new FormData();
      formData.append('live_id', String(state.live.id || 0));
      formData.append('receiver_id', String(receiverId));
      formData.append('peer_key', peerKey);
      formData.append('signal_type', signalType);
      formData.append('payload', JSON.stringify(payload || {}));
      const data = await fetchJsonSafe('ajax/live_signal.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      if (!data || !data.ok) {
        throw new Error(data && data.error ? data.error : 'Signal send failed');
      }
    }

    function closePeerConnection(peerKey) {
      const entry = state.peerConnections[peerKey];
      if (!entry) return;
      if (entry.disconnectTimer) {
        clearTimeout(entry.disconnectTimer);
        entry.disconnectTimer = null;
      }
      if (entry.kind === 'guest-publisher' && entry.viewerId) {
        removeGuestStream(entry.viewerId);
      }
      try {
        entry.pc.close();
      } catch (error) {
        // ignore close failure
      }
      delete state.peerConnections[peerKey];
      renderGuestTiles();
    }

    function ensureHostPeer(peerKey, viewerId) {
      const existing = state.peerConnections[peerKey];
      if (existing) {
        return existing.pc;
      }

      const pc = new RTCPeerConnection(rtcConfig);
      const guestViewMatch = peerKey.match(/-guest-view-(\d+)-/);
      const isGuestPublisher = peerKey.indexOf('-guest-') !== -1 && !guestViewMatch;
      const guestRelayUserId = guestViewMatch ? Number(guestViewMatch[1] || 0) : 0;
      const entry = {
        pc,
        viewerId: Number(viewerId || 0),
        kind: isGuestPublisher ? 'guest-publisher' : (guestRelayUserId > 0 ? 'guest-relay' : 'host-relay'),
        guestRelayUserId,
        disconnectTimer: null
      };
      state.peerConnections[peerKey] = entry;

      pc.onicecandidate = function(event) {
        if (!event.candidate || !entry.viewerId || !(state.live && state.live.id)) {
          return;
        }
        sendRtcSignal(entry.viewerId, peerKey, 'candidate', event.candidate.toJSON()).catch(function() {});
      };

      pc.onconnectionstatechange = function() {
        const value = String(pc.connectionState || '');
        if (value === 'connected') {
          if (entry.disconnectTimer) {
            clearTimeout(entry.disconnectTimer);
            entry.disconnectTimer = null;
          }
          return;
        }
        if (value === 'disconnected') {
          if (entry.disconnectTimer) {
            return;
          }
          entry.disconnectTimer = window.setTimeout(function() {
            entry.disconnectTimer = null;
            if (String(pc.connectionState || '') === 'disconnected') {
              closePeerConnection(peerKey);
            }
          }, peerDisconnectGraceMs);
          return;
        }
        if (entry.disconnectTimer) {
          clearTimeout(entry.disconnectTimer);
          entry.disconnectTimer = null;
        }
        if (value === 'failed' || value === 'closed') {
          closePeerConnection(peerKey);
        }
      };

      pc.ontrack = function(event) {
        if (entry.kind !== 'guest-publisher') return;
        if (!entry.viewerId) return;
        const stream = event.streams && event.streams[0] ? event.streams[0] : null;
        if (!stream) return;
        state.guestStreams[entry.viewerId] = {
          stream,
          video: null,
          relayVideo: null
        };
        renderGuestTiles();
        const relayKeysToRestart = [];
        Object.keys(state.peerConnections).forEach(function(key) {
          const peerEntry = state.peerConnections[key];
          if (!peerEntry || peerEntry.kind !== 'guest-relay' || peerEntry.guestRelayUserId !== entry.viewerId) {
            return;
          }
          relayKeysToRestart.push(key);
        });
        relayKeysToRestart.forEach(function(key) {
          const peerEntry = state.peerConnections[key];
          if (!peerEntry || !peerEntry.viewerId) return;
          sendRtcSignal(peerEntry.viewerId, key, 'bye', {}).catch(function() {});
          closePeerConnection(key);
        });
      };

      if (entry.kind === 'host-relay' && state.mediaStream) {
        state.mediaStream.getTracks().forEach(function(track) {
          const sender = pc.addTrack(track, state.mediaStream);
          tuneSenderForTile(sender, 'host-relay');
        });
      } else if (entry.kind === 'guest-relay' && entry.guestRelayUserId > 0) {
        const guestEntry = state.guestStreams[entry.guestRelayUserId];
        if (guestEntry && guestEntry.stream) {
          guestEntry.stream.getTracks().forEach(function(track) {
            const sender = pc.addTrack(track, guestEntry.stream);
            tuneSenderForTile(sender, 'guest-relay');
          });
        }
      }

      return pc;
    }

    async function handleRtcSignal(signal) {
      if (!(state.live && Number(state.live.id || 0) > 0 && String(state.live.status || '').toLowerCase() === 'live')) {
        return;
      }
      if (!state.mediaStream) {
        return;
      }

      const peerKey = String(signal.peer_key || '');
      const viewerId = Number(signal.sender_user_id || 0);
      const signalType = String(signal.signal_type || '');
      const payload = signal.payload || {};
      if (!peerKey || viewerId <= 0) {
        return;
      }

      if (usesSnapshotOnlyGuestStage() && peerKey.indexOf('-guest-') !== -1) {
        if (signalType === 'bye') {
          closePeerConnection(peerKey);
        }
        return;
      }

      const pc = ensureHostPeer(peerKey, viewerId);
      const entry = state.peerConnections[peerKey];
      if (entry) {
        entry.viewerId = viewerId;
      }

      if (signalType === 'offer') {
        if (!payload.sdp) return;
        await pc.setRemoteDescription(new RTCSessionDescription(payload));
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        await sendRtcSignal(viewerId, peerKey, 'answer', {
          type: answer.type,
          sdp: answer.sdp
        });
        return;
      }

      if (signalType === 'candidate') {
        if (!payload.candidate) return;
        try {
          await pc.addIceCandidate(new RTCIceCandidate(payload));
        } catch (error) {
          // ignore late candidates
        }
        return;
      }

      if (signalType === 'bye') {
        closePeerConnection(peerKey);
      }
    }

    async function pollRtcSignals() {
      if (!(state.live && Number(state.live.id || 0) > 0 && String(state.live.status || '').toLowerCase() === 'live')) {
        return;
      }
      if (!state.mediaStream) {
        return;
      }
      try {
        const data = await fetchJsonSafe('ajax/live_signal.php?live_id=' + encodeURIComponent(String(state.live.id)), {
          credentials: 'same-origin',
          cache: 'no-store'
        });
        if (!data || !data.ok || !Array.isArray(data.signals)) {
          return;
        }
        for (const signal of data.signals) {
          await handleRtcSignal(signal);
        }
      } catch (error) {
        // silent signaling poll
      }
    }

    function stopRtcPolling() {
      if (state.signalPollTimer) {
        clearInterval(state.signalPollTimer);
        state.signalPollTimer = null;
      }
      Object.keys(state.peerConnections).forEach(closePeerConnection);
    }

    function syncRtcHostLoop() {
      const shouldRun = !!(window.RTCPeerConnection
        && state.mediaStream
        && state.live
        && Number(state.live.id || 0) > 0
        && String(state.live.status || '').toLowerCase() === 'live');

      if (!shouldRun) {
        stopRtcPolling();
        return;
      }

      if (state.signalPollTimer) {
        return;
      }

      pollRtcSignals();
      state.signalPollTimer = window.setInterval(pollRtcSignals, 900);
    }

    function updateProgress() {
      const done = Math.min(3, completedSteps());
      const activeTarget = !state.cameraOn ? stepCamera : (!detailsReady() ? stepDetails : stepGoLive);
      const sourceConfig = currentSourceConfig();

      [stepCamera, stepDetails, stepGoLive].forEach((node) => node.classList.remove('is-active'));
      activeTarget.classList.add('is-active');

      progressBar.style.width = `${(done / 3) * 100}%`;
      progressCopy.textContent = `${done} / 3`;

      if (!state.cameraOn && state.justEnded) {
        stepHint.textContent = sourceConfig.endedHint;
      } else if (!state.cameraOn) {
        stepHint.textContent = sourceConfig.inactiveHint;
      } else if (!detailsReady()) {
        stepHint.textContent = sourceConfig.readyHint;
      } else if (state.live && state.live.status === 'live') {
        stepHint.textContent = 'Live room is running. You can keep hosting here or end the live when you are done.';
      } else {
        stepHint.textContent = 'All setup checks are ready. You can start live now or schedule this room for later.';
      }

      startLiveButton.disabled = state.busy || !state.cameraOn || !detailsReady() || (state.live && state.live.status === 'live');
      scheduleLiveButton.disabled = state.busy || !detailsReady();
      saveDraftButton.disabled = state.busy || !detailsReady();
      endLiveButton.disabled = state.busy || !(state.live && state.live.status === 'live');
    }

    function stopCameraStream() {
      clearSourceCleanup();
      const activeStream = state.mediaStream;
      state.mediaStream = null;
      if (activeStream) {
        activeStream.getTracks().forEach(function(track) {
          try {
            track.stop();
          } catch (error) {
            // ignore track stop failures
          }
        });
      }
      state.cameraOn = false;
      state.micOn = true;
      state.deviceCameraEnabled = true;
      persistStudioCameraEnabled();
      clearSnapshotRelayVideo();
      clearPreviewElement();
      revokePreviewObjectUrl();
      stopSoftwareFileHealthLoop();
      stopSnapshotLoop();
      stopRtcPolling();
    }

    async function attachHostMicrophoneTrack(stream) {
      if (!stream || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return false;
      }
      if (typeof stream.getAudioTracks === 'function' && stream.getAudioTracks().some(function(track) {
        return track.readyState === 'live';
      })) {
        return true;
      }
      try {
        const audioConstraints = {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        };
        if (state.selectedMicDeviceId) {
          audioConstraints.deviceId = { exact: state.selectedMicDeviceId };
        }
        const audioStream = await navigator.mediaDevices.getUserMedia({
          audio: audioConstraints,
          video: false
        });
        const audioTrack = audioStream && audioStream.getAudioTracks ? audioStream.getAudioTracks()[0] : null;
        if (!audioTrack || typeof stream.addTrack !== 'function') {
          if (audioStream) {
            audioStream.getTracks().forEach(function(track) { track.stop(); });
          }
          return false;
        }
        audioTrack.enabled = state.micOn;
        stream.addTrack(audioTrack);
        return true;
      } catch (error) {
        return false;
      }
    }

    function syncStudioLiveDeviceTracks() {
      if (!state.mediaStream) {
        return;
      }
      if (typeof state.mediaStream.getAudioTracks === 'function') {
        state.mediaStream.getAudioTracks().forEach(function(track) {
          if (track.readyState === 'live') {
            track.enabled = !!state.micOn;
          }
        });
      }
      if (typeof state.mediaStream.getVideoTracks === 'function') {
        state.mediaStream.getVideoTracks().forEach(function(track) {
          if (track.readyState === 'live') {
            track.enabled = !!state.deviceCameraEnabled;
          }
        });
      }
    }

    function renderStudioLiveDeviceControls() {
      const hasAudioTrack = !!(state.mediaStream && typeof state.mediaStream.getAudioTracks === 'function' && state.mediaStream.getAudioTracks().some(function(track) {
        return track.readyState === 'live';
      }));
      const hasVideoTrack = !!(state.mediaStream && typeof state.mediaStream.getVideoTracks === 'function' && state.mediaStream.getVideoTracks().some(function(track) {
        return track.readyState === 'live';
      }));
      if (studioLiveMicToggle) {
        const micIcon = studioLiveMicToggle.querySelector('i');
        const micIsActive = !!state.micOn && !!state.cameraOn && hasAudioTrack;
        studioLiveMicToggle.classList.toggle('is-active', micIsActive);
        studioLiveMicToggle.setAttribute('aria-pressed', micIsActive ? 'true' : 'false');
        studioLiveMicToggle.setAttribute('aria-label', micIsActive ? 'Turn microphone off' : 'Turn microphone on');
        if (micIcon) {
          micIcon.className = micIsActive ? 'fa fa-microphone' : 'fa fa-microphone has-off-slash';
        }
      }
      if (studioLiveCameraToggle) {
        const cameraIcon = studioLiveCameraToggle.querySelector('i');
        const cameraIsActive = !!state.deviceCameraEnabled && !!state.cameraOn && hasVideoTrack;
        studioLiveCameraToggle.classList.toggle('is-active', cameraIsActive);
        studioLiveCameraToggle.setAttribute('aria-pressed', cameraIsActive ? 'true' : 'false');
        studioLiveCameraToggle.setAttribute('aria-label', cameraIsActive ? 'Turn camera off' : 'Turn camera on');
        if (cameraIcon) {
          cameraIcon.className = cameraIsActive ? 'fa fa-video-camera' : 'fa fa-video-camera has-off-slash';
        }
      }
    }

    function syncStudioLiveModalCameraOffState() {
      if (!studioLiveModalStage || !studioLiveModalLocalVideo || !studioLiveModalCameraOff) {
        return;
      }
      const modalIsOpen = !!(studioLiveModal && studioLiveModal.classList.contains('is-open'));
      const shouldShowOwnerStage = !!(modalIsOpen && state.cameraOn && state.mediaStream);
      const shouldShowCameraOff = !!(modalIsOpen && state.cameraOn && !state.deviceCameraEnabled);
      studioLiveModalStage.classList.toggle('is-owner-live', shouldShowOwnerStage);
      studioLiveModalStage.classList.toggle('is-camera-off', shouldShowCameraOff);
      if (!shouldShowOwnerStage) {
        studioLiveModalLocalVideo.pause();
        studioLiveModalLocalVideo.srcObject = null;
        return;
      }
      if (studioLiveModalLocalVideo.srcObject !== state.mediaStream) {
        studioLiveModalLocalVideo.srcObject = state.mediaStream;
      }
      studioLiveModalLocalVideo.muted = true;
      if (shouldShowCameraOff) {
        studioLiveModalLocalVideo.pause();
      } else {
        studioLiveModalLocalVideo.play().catch(() => {});
      }
    }

    function syncStudioLiveDeviceControls() {
      syncStudioLiveDeviceTracks();
      renderStudioLiveDeviceControls();
      syncStudioLiveModalCameraOffState();
    }

    async function enableCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        throw new Error('This browser does not support camera access');
      }

      const existingStream = state.mediaStream;
      const profile = hostMediaProfile();
      const videoConstraints = {
        facingMode: 'user',
        width: { ideal: profile.width },
        height: { ideal: profile.height },
        frameRate: { ideal: profile.frameRate, max: Math.max(profile.frameRate, 30) }
      };
      if (state.selectedCameraDeviceId) {
        videoConstraints.deviceId = { exact: state.selectedCameraDeviceId };
        delete videoConstraints.facingMode;
      }
      const audioConstraints = {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      };
      if (state.selectedMicDeviceId) {
        audioConstraints.deviceId = { exact: state.selectedMicDeviceId };
      }
      const stream = await navigator.mediaDevices.getUserMedia({
        video: videoConstraints,
        audio: audioConstraints
      });

      if (existingStream) {
        stopCameraStream();
      }
      state.mediaStream = stream;
      syncStudioLiveDeviceTracks();
      clearPreviewElement();
      revokePreviewObjectUrl();
      previewVideo.srcObject = stream;
      previewVideo.classList.add('is-host-streaming');
      applyStudioOutputDevice(state.selectedSpeakerDeviceId).catch(function() {});
      await previewVideo.play().catch(() => {});
      state.cameraOn = true;
      state.justEnded = false;
      state.sourceCleanup = bindStreamEnded(stream, 'Camera preview ended.');
      persistStudioCameraEnabled();
      syncHostMediaProfile();
      syncSoftwareFileHealthLoop();
      syncSnapshotLoop();
      syncRtcHostLoop();
    }

    async function enableDisplaySource(mode) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
        throw new Error('This browser does not support screen sharing');
      }

      const profile = hostMediaProfile();
      const stream = await navigator.mediaDevices.getDisplayMedia({
        video: {
          width: { ideal: profile.width },
          height: { ideal: profile.height },
          frameRate: { ideal: profile.frameRate, max: Math.max(profile.frameRate, 24) }
        },
        audio: false
      });
      const track = stream && stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
      if (!track) {
        stream.getTracks().forEach(function(item) { item.stop(); });
        throw new Error('No video track was shared.');
      }
      track.contentHint = 'motion';

      stopCameraStream();
      await attachHostMicrophoneTrack(stream);
      clearPreviewElement();
      revokePreviewObjectUrl();
      previewVideo.srcObject = stream;
      previewVideo.classList.add('is-host-streaming');
      applyStudioOutputDevice(state.selectedSpeakerDeviceId).catch(function() {});
      await previewVideo.play().catch(() => {});
      state.mediaStream = stream;
      state.cameraOn = true;
      state.justEnded = false;
      syncStudioLiveDeviceTracks();
      persistStudioCameraEnabled();
      state.sourceCleanup = bindStreamEnded(
        stream,
        mode === 'page'
          ? 'Browser tab sharing ended.'
          : (mode === 'external' ? 'Shared feed window ended.' : 'Screen share ended.')
      );
      syncHostMediaProfile();
      syncSoftwareFileHealthLoop();
      syncSnapshotLoop();
      syncRtcHostLoop();
    }

    async function enableVideoFileSource(file) {
      if (!file) {
        throw new Error('Choose a local video file first.');
      }
      if (!previewVideo || !(previewVideo.captureStream || previewVideo.mozCaptureStream)) {
        throw new Error('This browser does not support video file capture.');
      }

      const fileUrl = URL.createObjectURL(file);
      stopCameraStream();
      clearPreviewElement();
      state.softwareFile = file;
      state.softwareFileName = String(file.name || 'Selected video file');
      state.previewObjectUrl = fileUrl;
      syncSourceUi();

      previewVideo.src = fileUrl;
      previewVideo.loop = true;
      previewVideo.classList.add('is-host-streaming');
      applyStudioOutputDevice(state.selectedSpeakerDeviceId).catch(function() {});
      await previewVideo.play();

      const captured = (previewVideo.captureStream || previewVideo.mozCaptureStream).call(previewVideo);
      if (!captured || !captured.getVideoTracks || !captured.getVideoTracks().length) {
        throw new Error('Unable to capture the selected video file.');
      }

      await attachHostMicrophoneTrack(captured);

      state.mediaStream = captured;
      state.cameraOn = true;
      state.justEnded = false;
      syncStudioLiveDeviceTracks();
      persistStudioCameraEnabled();
      markSoftwareFileProgress(true);
      syncHostMediaProfile();
      syncSoftwareFileHealthLoop();
      syncSnapshotLoop();
      syncRtcHostLoop();
    }

    async function activateSelectedSource() {
      if (state.source === 'webcam') {
        setFeedback('Requesting camera access...');
        await enableCamera();
        setFeedback('Camera preview is live and ready.', 'success');
        return true;
      }

      if (state.softwareMode !== 'page' && state.softwareMode !== 'external') {
        if (!state.softwareFile) {
          if (softwareVideoFileInput) {
            softwareVideoFileInput.click();
          }
          setFeedback('Choose a local video file to continue.');
          return false;
        }
        await enableVideoFileSource(state.softwareFile);
        setFeedback('Video file preview is live and ready.', 'success');
        return true;
      }

      if (state.softwareMode === 'page') {
        openSoftwarePageHelper();
        setFeedback('Choose that browser tab in the share picker.');
        await enableDisplaySource('page');
        setFeedback('Browser tab preview is live and ready.', 'success');
        return true;
      }

      if (state.softwareMode === 'external') {
        setFeedback('Choose the window that is showing your feed.');
        await enableDisplaySource('external');
        setFeedback('Feed window preview is live and ready.', 'success');
        return true;
      }
    }

    function renderCameraState() {
      const sourceConfig = currentSourceConfig();

      syncStudioLiveDeviceControls();
      syncStudioLiveSettingsUi();
      syncSourceUi();
      toggleStateText.textContent = state.cameraOn ? 'ON' : 'OFF';
      cameraButton.textContent = state.cameraOn ? sourceConfig.stopLabel : sourceConfig.startLabel;
      cameraSwitch.classList.toggle('is-on', state.cameraOn);
      cameraSwitch.setAttribute('aria-pressed', state.cameraOn ? 'true' : 'false');
      cameraPreview.classList.toggle('is-live', state.cameraOn && !!state.mediaStream);
      cameraPreview.classList.toggle('is-hidden-source-ui', state.source !== 'webcam');
      cameraPreview.classList.toggle('is-software-source', state.source !== 'webcam');
      if (previewLabelNode) {
        previewLabelNode.classList.toggle('is-hidden-source-ui', state.source !== 'webcam');
      }
      if (previewCamera) {
        previewCamera.setAttribute('aria-hidden', state.source !== 'webcam' ? 'true' : 'false');
      }
      if (stepCameraTitle) {
        stepCameraTitle.textContent = sourceConfig.stepTitle;
      }
      if (stepCameraCopy) {
        stepCameraCopy.textContent = sourceConfig.stepBody;
      }
      previewTitle.textContent = state.cameraOn ? sourceConfig.onTitle : sourceConfig.offTitle;
      previewText.textContent = state.cameraOn
        ? sourceConfig.onText
        : (state.justEnded
          ? sourceConfig.endedText
          : sourceConfig.offText);
      previewWarning.innerHTML = state.cameraOn
        ? `<strong>${sourceConfig.statusOnLabel}</strong>${sourceConfig.statusOnText}`
        : (state.justEnded
          ? `<strong>${sourceConfig.statusOffLabel}</strong>${sourceConfig.endedStatusText}`
          : `<strong>${sourceConfig.statusOffLabel}</strong>${sourceConfig.statusOffText}`);
      if (toggleAlertLine) {
        toggleAlertLine.innerHTML = state.cameraOn
          ? `${sourceConfig.statusOnLabel}<br>${sourceConfig.statusOnText}`
          : (state.justEnded
            ? `${sourceConfig.statusOffLabel}<br>${sourceConfig.endedStatusText}`
            : `${sourceConfig.statusOffLabel}<br>${sourceConfig.statusOffText}`);
      }
      previewActionPill.innerHTML = `${'<?php echo iconPowerMini(); ?>'}${state.cameraOn ? sourceConfig.stopLabel : sourceConfig.startLabel}`;
      syncStudioLiveModalCameraOffState();
      syncHostDualStage();
      updateProgress();
      syncSnapshotLoop();
      syncRtcHostLoop();
    }

    async function uploadSnapshotFrame() {
      if (state.snapshotBusy) return;
      if (!(state.live && Number(state.live.id || 0) > 0)) return;
      if (String(state.live.status || '').toLowerCase() !== 'live') return;
      if (!state.cameraOn || !state.mediaStream) return;

      state.snapshotBusy = true;
      try {
        const sourceVideo = ensureSnapshotRelayVideo() || previewVideo;
        if (!sourceVideo || sourceVideo.readyState < 2 || !sourceVideo.videoWidth || !sourceVideo.videoHeight) return;
        const maxWidth = 960;
        const scale = sourceVideo.videoWidth > maxWidth ? (maxWidth / sourceVideo.videoWidth) : 1;
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(sourceVideo.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(sourceVideo.videoHeight * scale));
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.drawImage(sourceVideo, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.86));
        if (!blob) return;

        const formData = new FormData();
        formData.append('live_id', String(state.live.id));
        formData.append('frame', blob, 'frame.jpg');

        const response = await fetch('ajax/live_snapshot.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (data && data.ok) {
          state.snapshotVersion = String(data.snapshot_version || '');
          if (state.live) {
            state.live.snapshot_version = state.snapshotVersion;
          }
        }
      } catch (error) {
        // keep snapshot upload silent
      } finally {
        state.snapshotBusy = false;
      }
    }

    async function uploadGuestSnapshotFrameForHost(userId) {
      if (!usesSnapshotOnlyGuestStage()) return;
      const id = Number(userId || 0);
      if (!id || state.guestSnapshotBusy[id]) return;
      if (!(state.live && Number(state.live.id || 0) > 0)) return;
      if (String(state.live.status || '').toLowerCase() !== 'live') return;
      const entry = state.guestStreams[id];
      if (!entry || !entry.stream) return;

      if (!entry.relayVideo) {
        entry.relayVideo = document.createElement('video');
        entry.relayVideo.autoplay = true;
        entry.relayVideo.playsInline = true;
        entry.relayVideo.muted = true;
        entry.relayVideo.srcObject = entry.stream;
        entry.relayVideo.play().catch(() => {});
      }
      const sourceVideo = entry.relayVideo;
      if (!sourceVideo || sourceVideo.readyState < 2 || !sourceVideo.videoWidth || !sourceVideo.videoHeight) {
        return;
      }

      state.guestSnapshotBusy[id] = true;
      try {
        const maxWidth = 720;
        const scale = sourceVideo.videoWidth > maxWidth ? (maxWidth / sourceVideo.videoWidth) : 1;
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(sourceVideo.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(sourceVideo.videoHeight * scale));
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.drawImage(sourceVideo, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        if (!blob) return;

        const formData = new FormData();
        formData.append('live_id', String(state.live.id));
        formData.append('guest_user_id', String(id));
        formData.append('frame', blob, 'guest-frame.jpg');

        await fetch('ajax/live_snapshot.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
      } catch (error) {
        // keep guest snapshot upload silent
      } finally {
        state.guestSnapshotBusy[id] = false;
      }
    }

    function stopSnapshotLoop() {
      if (state.snapshotTimer) {
        clearInterval(state.snapshotTimer);
        state.snapshotTimer = null;
      }
      if (state.guestRelayTimer) {
        clearInterval(state.guestRelayTimer);
        state.guestRelayTimer = null;
      }
      state.snapshotIntervalMs = 0;
      state.guestRelayIntervalMs = 0;
      state.snapshotBusy = false;
      stopGuestTileSnapshotLoop();
      stopGuestHealthLoop();
    }

    function desiredSnapshotIntervals() {
      if (!usesSnapshotOnlyGuestStage()) {
        return { host: 900, guest: 0 };
      }
      const guestCount = Object.keys(state.guestStreams || {}).length;
      if (guestCount >= 3) {
        return { host: 1200, guest: 1200 };
      }
      if (guestCount === 2) {
        return { host: 800, guest: 1800 };
      }
      if (guestCount === 1) {
        return { host: 600, guest: 900 };
      }
      return { host: 450, guest: 0 };
    }

    function syncSnapshotLoop() {
      const shouldRun = !!(state.cameraOn
        && state.mediaStream
        && state.live
        && Number(state.live.id || 0) > 0
        && String(state.live.status || '').toLowerCase() === 'live');

      if (!shouldRun) {
        stopSnapshotLoop();
        return;
      }

      ensureSnapshotRelayVideo();

      const intervals = desiredSnapshotIntervals();

      if (state.snapshotTimer) {
        if (state.snapshotIntervalMs !== intervals.host) {
          clearInterval(state.snapshotTimer);
          state.snapshotTimer = window.setInterval(function() {
            uploadSnapshotFrame();
          }, intervals.host);
          state.snapshotIntervalMs = intervals.host;
        }
        if (intervals.guest <= 0) {
          if (state.guestRelayTimer) {
            clearInterval(state.guestRelayTimer);
            state.guestRelayTimer = null;
          }
          state.guestRelayIntervalMs = 0;
        } else if (!state.guestRelayTimer || state.guestRelayIntervalMs !== intervals.guest) {
          if (state.guestRelayTimer) {
            clearInterval(state.guestRelayTimer);
          }
          state.guestRelayTimer = window.setInterval(function() {
            Object.keys(state.guestStreams).forEach(function(key) {
              uploadGuestSnapshotFrameForHost(Number(key));
            });
          }, intervals.guest);
          state.guestRelayIntervalMs = intervals.guest;
        }
        return;
      }

      const hostTick = function() {
        uploadSnapshotFrame();
      };
      const guestTick = function() {
        Object.keys(state.guestStreams).forEach(function(key) {
          uploadGuestSnapshotFrameForHost(Number(key));
        });
      };
      hostTick();
      if (intervals.guest > 0) {
        guestTick();
      }
      state.snapshotTimer = window.setInterval(hostTick, intervals.host);
      state.snapshotIntervalMs = intervals.host;
      if (intervals.guest > 0) {
        state.guestRelayTimer = window.setInterval(guestTick, intervals.guest);
        state.guestRelayIntervalMs = intervals.guest;
      }
      syncGuestTileSnapshotLoop();
    }

    function formPayload(action) {
      const formData = new FormData();
      formData.append('action', action);
      formData.append('title', titleInput.value.trim());
      formData.append('description', descriptionInput.value.trim());
      formData.append('visibility', visibilityInput.value);
      formData.append('scheduled_for', scheduleInput.value);
      if (window.MSBDeviceProfile && typeof window.MSBDeviceProfile.guess === 'function') {
        const profile = window.MSBDeviceProfile.guess();
        formData.append('device_label', profile.label || '');
        formData.append('device_viewport', profile.viewport || '');
      }
      return formData;
    }

    function openLiveModalForHost(liveId) {
      const id = Number(liveId || 0);
      if (!id) return;
      const url = 'live_watch.php?live=' + encodeURIComponent(String(id)) + '&embed=1';
      if (!studioLiveModal || !studioLiveModalFrame) {
        window.location.href = url;
        return;
      }
      if (studioLiveModalStage) {
        studioLiveModalStage.classList.add('is-direct-stage');
      }
      if (studioLiveStageReactions) {
        studioLiveStageReactions.innerHTML = '';
      }
      studioLiveModalFrame.src = 'about:blank';
      syncStudioLiveModalCameraOffState();
      syncHostDualStage();
      studioLiveModal.classList.add('is-open');
      studioLiveModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setStudioSidebarMode('chat');
      setEndConfirmOpen(false);
      setModalComposeFeedback('');
      syncStudioLiveDeviceControls();
      syncStudioLiveSettingsUi();
    }

    async function closeStudioLiveModal(endLiveFirst = true) {
      if (endLiveFirst && state.live && String(state.live.status || '').toLowerCase() === 'live' && !state.busy) {
        const ended = await runStudioAction('end_live', { skipModalClose: true });
        if (ended) {
          stopCameraStream();
          state.justEnded = true;
          state.live = {
            id: 0,
            title: titleInput.value.trim(),
            description: descriptionInput.value.trim(),
            status: 'draft',
            visibility: visibilityInput.value || 'private',
            stream_key: '',
            schedule_input: scheduleInput.value || '',
            viewer_count: 0,
            snapshot_version: ''
          };
          state.guestRequests = [];
          state.approvedGuests = [];
          renderRoomData({ comments: [], reaction_counts: {}, my_reaction: '', live: state.live });
          renderCameraState();
          syncSummary();
          restartRoomPolling();
          setFeedback(currentSourceConfig().endedText, 'success');
          stepHint.textContent = currentSourceConfig().endedHint;
        }
      }
      if (!studioLiveModal || !studioLiveModalFrame) return;
      studioLiveModal.classList.remove('is-open');
      studioLiveModal.setAttribute('aria-hidden', 'true');
      studioLiveModalFrame.src = 'about:blank';
      if (studioLiveStageReactions) {
        studioLiveStageReactions.innerHTML = '';
      }
      if (studioLiveModalStage) {
        studioLiveModalStage.classList.remove('is-direct-stage');
        studioLiveModalStage.classList.remove('is-owner-live');
        studioLiveModalStage.classList.remove('is-camera-off');
        studioLiveModalStage.classList.remove('has-dual-stage', 'has-three-stage', 'has-four-stage', 'has-five-stage', 'has-six-stage', 'has-seven-stage', 'has-eight-stage', 'has-nine-stage', 'has-ten-stage', 'has-eleven-stage', 'has-twelve-stage', 'has-thirteen-stage', 'has-fourteen-stage', 'has-fifteen-stage', 'has-sixteen-stage', 'has-seventeen-stage', 'has-eighteen-stage', 'has-nineteen-stage', 'has-twenty-stage', 'has-twentyone-stage', 'has-twentytwo-stage', 'has-twentythree-stage', 'has-twentyfour-stage', 'has-twentyfive-stage', 'has-twentyplus-stage', 'has-gallery-stage');
      }
      if (studioLiveModalLocalVideo) {
        studioLiveModalLocalVideo.pause();
        studioLiveModalLocalVideo.srcObject = null;
      }
      document.body.style.overflow = '';
      setStudioSidebarMode('');
      setEndConfirmOpen(false);
      setModalComposeFeedback('');
      syncStudioLiveDeviceControls();
      Object.keys(state.guestStreams).forEach((key) => removeGuestStream(Number(key)));
      renderGuestRequestPanel();
      renderGuestTiles();
    }

    async function runStudioAction(action, options = {}) {
      if (state.busy) return;
      state.busy = true;
      updateProgress();
      setFeedback('Saving live studio changes...');

      try {
        const data = await fetchJsonSafe('ajax/live_studio_host_action.php', {
          method: 'POST',
          body: formPayload(action),
          credentials: 'same-origin'
        });
        if (!data || !data.ok) {
          throw new Error(data && data.error ? data.error : 'Request failed');
        }
        state.live = data.live || { status: 'draft', snapshot_version: '' };
        state.historyCount = Number(data.history_count || 0);
        state.historySummary = data.history_summary || state.historySummary || {};
        if (state.live.schedule_input && !scheduleInput.value) {
          scheduleInput.value = state.live.schedule_input;
        }
        syncSummary();
        restartRoomPolling();
        syncSnapshotLoop();
        syncRtcHostLoop();
        if (action === 'start_live' && state.live && Number(state.live.id || 0) > 0) {
          state.justEnded = false;
          window.setTimeout(function() {
            openLiveModalForHost(state.live.id);
          }, 180);
          return true;
        }
        if (action === 'end_live' && !options.skipModalClose) {
          stopCameraStream();
          await closeStudioLiveModal(false);
        }
        setFeedback(
          action === 'start_live' ? 'Live room is now active.' :
          action === 'schedule_live' ? 'Live room scheduled successfully.' :
          action === 'end_live' ? 'Live room ended.' :
          'Draft saved successfully.',
          'success'
        );
        return true;
      } catch (error) {
        setFeedback(error.message || 'Unable to save changes', 'error');
        return false;
      } finally {
        state.busy = false;
        updateProgress();
      }
    }

    sourceCards.forEach((card) => {
      card.addEventListener('click', () => {
        const nextSource = String(card.dataset.source || 'webcam');
        if (nextSource === state.source) {
          syncSourceUi();
          renderCameraState();
          return;
        }
        state.source = nextSource;
        if (state.cameraOn) {
          stopCameraStream();
          setFeedback('Previous source turned off. Start the new source when ready.');
        }
        renderCameraState();
      });
    });

    softwareModeCards.forEach((card) => {
      card.addEventListener('click', () => {
        const nextMode = String(card.getAttribute('data-software-mode') || 'file');
        if (nextMode === state.softwareMode) {
          syncSourceUi();
          return;
        }
        state.softwareMode = nextMode;
        if (state.source === 'software' && state.cameraOn) {
          stopCameraStream();
          setFeedback('Previous software source stopped. Start the new source when ready.');
        }
        renderCameraState();
      });
    });

    if (softwarePageUrl) {
      const syncSoftwarePageValue = function() {
        state.softwarePageUrl = String(softwarePageUrl.value || '').trim();
      };
      softwarePageUrl.addEventListener('input', syncSoftwarePageValue);
      softwarePageUrl.addEventListener('change', syncSoftwarePageValue);
    }

    if (softwareFileButton && softwareVideoFileInput) {
      softwareFileButton.addEventListener('click', function() {
        softwareVideoFileInput.click();
      });
    }

    if (softwareVideoFileInput) {
      softwareVideoFileInput.addEventListener('change', async function() {
        const file = softwareVideoFileInput.files && softwareVideoFileInput.files[0] ? softwareVideoFileInput.files[0] : null;
        if (!file) {
          return;
        }
        state.softwareFile = file;
        state.softwareFileName = String(file.name || 'Selected video file');
        try {
          await enableVideoFileSource(file);
          setFeedback('Video file preview is live and ready.', 'success');
        } catch (error) {
          stopCameraStream();
          setFeedback(error.message || 'Unable to play the selected video file.', 'error');
        }
        softwareVideoFileInput.value = '';
        renderCameraState();
      });
    }

    if (previewVideo) {
      ['playing', 'loadeddata', 'canplay', 'timeupdate'].forEach(function(eventName) {
        previewVideo.addEventListener(eventName, function() {
          markSoftwareFileProgress(eventName !== 'timeupdate');
        });
      });
      ['pause', 'waiting', 'stalled', 'ended'].forEach(function(eventName) {
        previewVideo.addEventListener(eventName, function() {
          if (!shouldWatchSoftwareFilePlayback()) {
            return;
          }
          window.setTimeout(function() {
            reviveSoftwareFilePlayback();
          }, eventName === 'ended' ? 80 : 0);
        });
      });
    }

    document.addEventListener('visibilitychange', function() {
      if (shouldWatchSoftwareFilePlayback()) {
        reviveSoftwareFilePlayback();
      }
    });

    if (navigator.mediaDevices && typeof navigator.mediaDevices.addEventListener === 'function') {
      navigator.mediaDevices.addEventListener('devicechange', function() {
        refreshStudioMediaDevices().catch(function() {});
      });
    }

    if (softwareOpenPageButton) {
      softwareOpenPageButton.addEventListener('click', function() {
        try {
          openSoftwarePageHelper();
          setFeedback('Browser page opened in a new tab. Share that tab when you are ready.', 'success');
        } catch (error) {
          setFeedback(error.message || 'Unable to open the browser page helper.', 'error');
        }
        renderCameraState();
      });
    }

    if (softwarePageShareButton) {
      softwarePageShareButton.addEventListener('click', async function() {
        if (state.busy) return;
        try {
          openSoftwarePageHelper();
          setFeedback('Choose that browser tab in the share picker.');
          await enableDisplaySource('page');
          setFeedback('Browser tab preview is live and ready.', 'success');
        } catch (error) {
          stopCameraStream();
          setFeedback(error.message || 'Unable to share the browser tab.', 'error');
        }
        renderCameraState();
      });
    }

    if (softwareExternalWindowButton) {
      softwareExternalWindowButton.addEventListener('click', async function() {
        if (state.busy) return;
        try {
          setFeedback('Choose the window that is showing your feed.');
          await enableDisplaySource('external');
          setFeedback('Feed window preview is live and ready.', 'success');
        } catch (error) {
          stopCameraStream();
          setFeedback(error.message || 'Unable to share the selected feed window.', 'error');
        }
        renderCameraState();
      });
    }

    [cameraButton, cameraSwitch].forEach((control) => {
      control.addEventListener('click', async () => {
        if (state.busy) return;
        if (state.cameraOn) {
          stopCameraStream();
          setFeedback(currentSourceConfig().stopFeedback);
          renderCameraState();
          return;
        }

        try {
          await activateSelectedSource();
        } catch (error) {
          stopCameraStream();
          setFeedback(error.message || 'The selected source could not be started.', 'error');
        }
        renderCameraState();
      });
    });

    [titleInput, descriptionInput, visibilityInput, scheduleInput].forEach((field) => {
      field.addEventListener('input', updateProgress);
      field.addEventListener('change', updateProgress);
    });

    [titleInput, descriptionInput].forEach((field) => {
      field.addEventListener('input', syncStudioDescriptionPanel);
      field.addEventListener('change', syncStudioDescriptionPanel);
    });

    saveDraftButton.addEventListener('click', () => runStudioAction('save_draft'));
    scheduleLiveButton.addEventListener('click', () => runStudioAction('schedule_live'));
    startLiveButton.addEventListener('click', () => runStudioAction('start_live'));
    endLiveButton.addEventListener('click', () => runStudioAction('end_live'));

    if (sendCommentButton && commentInput) {
      sendCommentButton.addEventListener('click', async () => {
        try {
          const data = await sendStudioComment(commentInput.value);
          commentInput.value = '';
          if (studioModalCommentInput) {
            studioModalCommentInput.value = '';
          }
          renderRoomData(data);
          setFeedback('');
          setModalComposeFeedback('');
        } catch (error) {
          setFeedback(error.message || 'Unable to send comment', 'error');
          setModalComposeFeedback(error.message || 'Unable to send comment', 'error');
        }
      });
    }

    if (studioModalSendButton) {
      studioModalSendButton.addEventListener('click', async () => {
        try {
          const data = await sendStudioComment(studioModalCommentInput ? studioModalCommentInput.value : '');
          if (studioModalCommentInput) {
            studioModalCommentInput.value = '';
          }
          if (commentInput) {
            commentInput.value = '';
          }
          renderRoomData(data);
          setFeedback('');
          setModalComposeFeedback('');
        } catch (error) {
          setFeedback(error.message || 'Unable to send comment', 'error');
          setModalComposeFeedback(error.message || 'Unable to send comment', 'error');
        }
      });
    }

    if (studioModalCommentInput) {
      studioModalCommentInput.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          if (studioModalSendButton) {
            studioModalSendButton.click();
          }
        }
      });
    }

    if (studioModalCommentList) {
      studioModalCommentList.addEventListener('click', function(event) {
        const replyButton = event.target.closest('.studio-live-comment-reply');
        if (replyButton) {
          const comment = replyButton.closest('[data-comment-author]');
          const author = comment ? String(comment.getAttribute('data-comment-author') || '').trim() : '';
          if (studioModalCommentInput) {
            const prefix = author ? ('@' + author + ' ') : '';
            studioModalCommentInput.value = prefix;
            studioModalCommentInput.focus();
            try {
              studioModalCommentInput.setSelectionRange(studioModalCommentInput.value.length, studioModalCommentInput.value.length);
            } catch (error) {}
          }
          return;
        }

        const likeButton = event.target.closest('.studio-live-comment-like');
        if (likeButton) {
          const comment = likeButton.closest('[data-comment-id]');
          const commentId = Number(comment ? comment.getAttribute('data-comment-id') || 0 : 0);
          if (commentId > 0) {
            toggleStudioCommentLike(commentId).catch(function(error) {
              setModalComposeFeedback(error.message || 'Unable to update comment like', 'error');
            });
          }
        }
      });
    }

    reactionButtons.forEach((button) => {
      button.addEventListener('click', async () => {
        if (!(state.live && state.live.id)) {
          setFeedback('Create a live room first before reacting.', 'error');
          return;
        }
        try {
          const formData = new FormData();
          formData.append('action', 'react_live');
          formData.append('reaction', button.getAttribute('data-reaction') || '');
          const data = await fetchJsonSafe('ajax/live_studio_room_action.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          });
          if (!data || !data.ok) {
            throw new Error(data && data.error ? data.error : 'Unable to save reaction');
          }
          renderRoomData(data);
          setFeedback('Reaction updated for this room.', 'success');
        } catch (error) {
          setFeedback(error.message || 'Unable to save reaction', 'error');
        }
      });
    });

    window.addEventListener('beforeunload', () => {
      stopCameraStream();
      if (state.roomPollTimer) {
        clearInterval(state.roomPollTimer);
      }
      stopSnapshotLoop();
      stopRtcPolling();
    });

    if (studioLiveModalClose) {
      studioLiveModalClose.addEventListener('click', function() {
        setEndConfirmOpen(true);
      });
    }

    if (studioLiveTopClose) {
      studioLiveTopClose.addEventListener('click', function() {
        setEndConfirmOpen(true);
      });
    }

    if (studioLiveConfirmCancel) {
      studioLiveConfirmCancel.addEventListener('click', function() {
        setEndConfirmOpen(false);
      });
    }

    if (studioLiveConfirmOk) {
      studioLiveConfirmOk.addEventListener('click', function() {
        setEndConfirmOpen(false);
        closeStudioLiveModal(true);
      });
    }

    if (studioLiveSettingsToggle) {
      studioLiveSettingsToggle.addEventListener('click', function() {
        const isSettingsOpen = !!(studioLiveModalDialog && studioLiveModalDialog.classList.contains('has-chat') && state.sidebarMode === 'settings');
        setStudioSidebarMode(isSettingsOpen ? '' : 'settings');
        if (!isSettingsOpen) {
          refreshStudioMediaDevices().catch(function() {});
        }
      });
    }

    if (studioLiveCameraDeviceSelect) {
      studioLiveCameraDeviceSelect.addEventListener('change', async function() {
        state.selectedCameraDeviceId = String(studioLiveCameraDeviceSelect.value || '');
        syncStudioLiveSettingsUi();
        if (state.source === 'webcam' && state.cameraOn) {
          try {
            await enableCamera();
            setFeedback('Camera device updated.', 'success');
          } catch (error) {
            setFeedback(error.message || 'Unable to switch camera device.', 'error');
          }
          renderCameraState();
        }
      });
    }

    if (studioLiveMicDeviceSelect) {
      studioLiveMicDeviceSelect.addEventListener('change', async function() {
        state.selectedMicDeviceId = String(studioLiveMicDeviceSelect.value || '');
        syncStudioLiveSettingsUi();
        if (state.cameraOn) {
          const replaced = await replaceHostAudioTrack();
          if (replaced) {
            setFeedback('Microphone device updated.', 'success');
          } else {
            setFeedback('Microphone device will be used next time the source starts.', 'error');
          }
          renderCameraState();
        }
      });
    }

    if (studioLiveSpeakerDeviceSelect) {
      studioLiveSpeakerDeviceSelect.addEventListener('change', async function() {
        const nextDeviceId = String(studioLiveSpeakerDeviceSelect.value || '');
        const applied = await applyStudioOutputDevice(nextDeviceId);
        if (applied) {
          setFeedback('Output device updated.', 'success');
        } else {
          setFeedback('This browser does not allow output device switching here.', 'error');
        }
        syncStudioLiveSettingsUi();
      });
    }

    if (studioLiveMicMuteToggle) {
      studioLiveMicMuteToggle.addEventListener('change', function() {
        state.micOn = !!studioLiveMicMuteToggle.checked;
        syncStudioLiveDeviceControls();
        syncStudioLiveSettingsUi();
      });
    }

    if (studioLiveCameraEnabledToggle) {
      studioLiveCameraEnabledToggle.addEventListener('change', async function() {
        if (!state.cameraOn && studioLiveCameraEnabledToggle.checked) {
          state.deviceCameraEnabled = true;
          try {
            await activateSelectedSource();
          } catch (error) {
            stopCameraStream();
            setFeedback(error.message || 'The selected source could not be started.', 'error');
          }
          renderCameraState();
          return;
        }
        state.deviceCameraEnabled = !!studioLiveCameraEnabledToggle.checked;
        syncStudioLiveDeviceControls();
        syncStudioLiveSettingsUi();
        persistStudioCameraEnabled();
      });
    }

    if (studioLiveQualitySelect) {
      studioLiveQualitySelect.addEventListener('change', function() {
        state.streamQuality = String(studioLiveQualitySelect.value || 'auto');
        syncHostMediaProfile();
        syncStudioLiveSettingsUi();
      });
    }

    if (studioLiveFrameRateSelect) {
      studioLiveFrameRateSelect.addEventListener('change', function() {
        const nextFrameRate = Number(studioLiveFrameRateSelect.value || 24);
        state.frameRatePreference = nextFrameRate >= 30 ? 30 : 24;
        syncHostMediaProfile();
        syncStudioLiveSettingsUi();
      });
    }

    if (studioLiveMirrorToggle) {
      studioLiveMirrorToggle.addEventListener('change', function() {
        state.mirrorPreview = !!studioLiveMirrorToggle.checked;
        syncStudioLiveSettingsUi();
      });
    }

    if (studioLiveMicToggle) {
      studioLiveMicToggle.addEventListener('click', function() {
        const hasAudioTrack = !!(state.mediaStream && typeof state.mediaStream.getAudioTracks === 'function' && state.mediaStream.getAudioTracks().some(function(track) {
          return track.readyState === 'live';
        }));
        if (state.cameraOn && !hasAudioTrack) {
          setFeedback('Microphone access is not available for this source yet.', 'error');
          renderStudioLiveDeviceControls();
          return;
        }
        state.micOn = !state.micOn;
        syncStudioLiveDeviceControls();
      });
    }

    if (studioLiveCameraToggle) {
      studioLiveCameraToggle.addEventListener('click', async function() {
        if (state.busy) return;
        if (!state.cameraOn) {
          state.deviceCameraEnabled = true;
          try {
            await activateSelectedSource();
          } catch (error) {
            stopCameraStream();
            setFeedback(error.message || 'The selected source could not be started.', 'error');
          }
          renderCameraState();
          return;
        }
        state.deviceCameraEnabled = !state.deviceCameraEnabled;
        syncStudioLiveDeviceControls();
        persistStudioCameraEnabled();
      });
    }

    if (studioLiveChatToggle) {
      studioLiveChatToggle.addEventListener('click', function() {
        const isChatOpen = !!(studioLiveModalDialog && studioLiveModalDialog.classList.contains('has-chat') && state.sidebarMode === 'chat');
        setStudioSidebarMode(isChatOpen ? '' : 'chat');
      });
    }

    if (studioLiveReactionToggle) {
      studioLiveReactionToggle.addEventListener('click', function() {
        const isReactionOpen = !!(studioLiveModalDialog && studioLiveModalDialog.classList.contains('has-chat') && state.sidebarMode === 'reactions');
        setStudioSidebarMode(isReactionOpen ? '' : 'reactions');
      });
    }

    if (studioLiveDescriptionToggle) {
      studioLiveDescriptionToggle.addEventListener('click', function() {
        const isDescriptionOpen = !!(studioLiveModalDialog && studioLiveModalDialog.classList.contains('has-chat') && state.sidebarMode === 'description');
        setStudioSidebarMode(isDescriptionOpen ? '' : 'description');
      });
    }

    if (studioLiveSidebarClose) {
      studioLiveSidebarClose.addEventListener('click', function() {
        setStudioSidebarMode('');
      });
    }

    if (studioLiveReactionTabs) {
      studioLiveReactionTabs.addEventListener('click', function(event) {
        const tab = event.target.closest('[data-reaction-filter]');
        if (!tab) return;
        state.reactionFilter = String(tab.getAttribute('data-reaction-filter') || 'all');
        renderStudioReactionPanel();
      });
    }

    if (studioLiveReactionList) {
      studioLiveReactionList.addEventListener('click', function(event) {
        const actionButton = event.target.closest('[data-reactor-action="friend"][data-user-id]');
        if (!actionButton || actionButton.disabled) return;
        const peerId = Number(actionButton.getAttribute('data-user-id') || 0);
        if (peerId <= 0) return;
        sendStudioFriendRequest(peerId).catch(function(error) {
          setModalComposeFeedback(error.message || 'Unable to send friend request.', 'error');
        });
      });
    }

    if (studioLiveRequestList) {
      studioLiveRequestList.addEventListener('click', async function(event) {
        const button = event.target.closest('[data-guest-action][data-guest-user]');
        if (!button) return;
        const action = String(button.getAttribute('data-guest-action') || '');
        const requestUserId = Number(button.getAttribute('data-guest-user') || 0);
        if (!requestUserId) return;
        try {
          await updateGuestRequest(action === 'confirm' ? 'confirm_guest_request' : 'deny_guest_request', requestUserId);
          setFeedback(action === 'confirm' ? 'Guest request approved.' : 'Guest request denied.', 'success');
        } catch (error) {
          setFeedback(error.message || 'Unable to update guest request', 'error');
        }
      });
    }

    if (studioLiveModal) {
      studioLiveModal.addEventListener('click', function(event) {
        if (event.target === studioLiveModal) {
          closeStudioLiveModal(true);
        }
      });
    }

    if (studioLiveConfirm) {
      studioLiveConfirm.addEventListener('click', function(event) {
        if (event.target === studioLiveConfirm) {
          setEndConfirmOpen(false);
        }
      });
    }

    syncSummary();
    renderCameraState();
    refreshStudioMediaDevices().catch(function() {});
    restartRoomPolling();
    syncStatsChartTitle(state.live || {});
    updateStatsChart(currentStatsChartValues(state.live || {}, normalizedHistorySummary()));

    if (autoOpenLiveWatchId > 0) {
      window.setTimeout(function() {
        openLiveModalForHost(autoOpenLiveWatchId);
        if (window.history && typeof window.history.replaceState === 'function') {
          const nextUrl = new URL(window.location.href);
          nextUrl.searchParams.delete('open_live_watch');
          window.history.replaceState({}, document.title, nextUrl.toString());
        }
      }, 220);
    }

    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape' && studioLiveConfirm && studioLiveConfirm.classList.contains('is-open')) {
        setEndConfirmOpen(false);
        return;
      }
      if (event.key === 'Escape' && studioLiveModal && studioLiveModal.classList.contains('is-open')) {
        closeStudioLiveModal(true);
      }
    });
  </script>
</body>
</html>
<?php
function iconBase(string $path, string $viewBox = '0 0 24 24', string $attrs = ''): string
{
    return '<svg width="28" height="28" viewBox="' . $viewBox . '" fill="none" xmlns="http://www.w3.org/2000/svg" ' . $attrs . '>' . $path . '</svg>';
}

function iconChat(): string
{
    return iconBase('<path d="M5 5.5C5 4.12 6.12 3 7.5 3h9A2.5 2.5 0 0 1 19 5.5v6A2.5 2.5 0 0 1 16.5 14H10l-4.2 3.2c-.66.5-1.6.03-1.6-.8V5.5Z" fill="currentColor"/>');
}

function iconBell(): string
{
    return iconBase('<path d="M12 3a4 4 0 0 0-4 4v1.07c0 .7-.24 1.39-.68 1.93L6 11.6V13h12v-1.4l-1.32-1.6A3 3 0 0 1 16 8.07V7a4 4 0 0 0-4-4Z" stroke="currentColor" stroke-width="1.8"/><path d="M10 16a2 2 0 1 0 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>');
}

function iconPlus(): string
{
    return iconBase('<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>');
}

function iconGlobe(): string
{
    return iconBase('<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M3 12h18M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18" stroke="currentColor" stroke-width="1.5"/>');
}

function iconVideo(): string
{
    return iconBase('<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h7A2.5 2.5 0 0 1 16 7.5v9a2.5 2.5 0 0 1-2.5 2.5h-7A2.5 2.5 0 0 1 4 16.5v-9Z" fill="currentColor"/><path d="m16 10.2 4-2.2v8l-4-2.2v-3.6Z" fill="currentColor"/>');
}

function iconUserPlus(): string
{
    return iconBase('<path d="M8.5 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM3.5 19a5 5 0 0 1 10 0" fill="currentColor"/><path d="M18 8v6M15 11h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>');
}

function iconPower(): string
{
    return iconBase('<path d="M12 3v8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/><path d="M7.05 5.75A8 8 0 1 0 16.95 5.7" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/>');
}

function iconHelp(): string
{
    return iconBase('<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M9.8 9.1a2.5 2.5 0 1 1 4.32 1.68c-.66.68-1.38 1.08-1.62 2.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="17" r="1.2" fill="currentColor"/>');
}

function iconCard(): string
{
    return iconBase('<rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 9h18M8 14h3M13 14h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>');
}

function iconWebcam(): string
{
    return iconBase('<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="2.2"/><circle cx="12" cy="12" r="1.3" fill="currentColor"/>', '0 0 24 24', 'width="34" height="34"');
}

function iconWand(): string
{
    return iconBase('<path d="m14.7 5.3 4 4M5 19l8.8-8.8M12.3 3l.7 2.2L15 6l-2 .8-.7 2.2-.8-2.2L9.5 6l2-.8.8-2.2ZM18.2 9l.5 1.3L20 11l-1.3.5-.5 1.3-.5-1.3-1.2-.5 1.2-.7.5-1.3ZM6 10l.6 1.4L8 12l-1.4.6L6 14l-.6-1.4L4 12l1.4-.6L6 10Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>', '0 0 24 24', 'width="34" height="34"');
}

function iconPowerMini(): string
{
    return iconBase('<path d="M12 5v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M8.2 6.8a5.5 5.5 0 1 0 7.6 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>', '0 0 24 24', 'width="16" height="16" style="margin-right:6px;vertical-align:-2px;"');
}

function iconCamera(): string
{
    return iconBase('<path d="M7.5 8.5h9A2.5 2.5 0 0 1 19 11v5.5a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 5 16.5V11a2.5 2.5 0 0 1 2.5-2.5Z" fill="#fff"/><path d="m9 8.5 1.2-2h3.6l1.2 2" fill="#fff"/><circle cx="12" cy="13.5" r="2.5" fill="#0b0b0b"/>', '0 0 24 24', 'width="32" height="32"');
}

function iconChevron(): string
{
    return iconBase('<path d="m7 10 5 5 5-5" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/>', '0 0 24 24', 'width="18" height="18"');
}

function iconCalendar(): string
{
    return iconBase('<rect x="4" y="6" width="16" height="14" rx="2" stroke="currentColor" stroke-width="2"/><path d="M8 4v4M16 4v4M4 10h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>', '0 0 24 24', 'width="18" height="18"');
}

function iconVideoSolid(): string
{
    return iconBase('<path d="M3.5 8A2.5 2.5 0 0 1 6 5.5h7A2.5 2.5 0 0 1 15.5 8v8A2.5 2.5 0 0 1 13 18.5H6A2.5 2.5 0 0 1 3.5 16V8Z" fill="currentColor"/><path d="m15.5 10 5-2.5v9L15.5 14V10Z" fill="currentColor"/>', '0 0 24 24', 'width="28" height="28"');
}
?>
