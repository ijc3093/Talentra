<?php
declare(strict_types=1);

/**
 * Admin Disputes — customer/seller order disputes.
 * Viewport-fit UI matching userlist / publisher_requests; behavior preserved.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/admin_kind_tabs.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';

$kindFilter = admin_kind_from_request();
$lane = strtolower(trim((string)($_GET['lane'] ?? $_POST['lane'] ?? 'all')));
$lane = in_array($lane, ['all', 'customer', 'seller'], true) ? $lane : 'all';
$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
// Legacy unread/read + screenshot statuses
if ($filter === 'unread') {
    $filter = 'open';
} elseif ($filter === 'read') {
    $filter = 'resolved';
}
$filter = in_array($filter, ['all', 'open', 'awaiting', 'resolved', 'closed', 'unread', 'read'], true) ? $filter : 'all';
if ($filter === 'unread') { $filter = 'open'; }
if ($filter === 'read') { $filter = 'resolved'; }
$statusFilter = $filter;
$reasonFilter = strtolower(trim((string)($_GET['reason'] ?? 'all')));
$reasonAllow = ['all', 'not_received', 'damaged', 'wrong_item', 'refund', 'shipping', 'other'];
$reasonFilter = in_array($reasonFilter, $reasonAllow, true) ? $reasonFilter : 'all';
$qSearch = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
// Map audience kind → dispute lane emphasis
if (!isset($_GET['lane']) && !isset($_POST['lane'])) {
    if ($kindFilter === 'commerce') {
        $lane = 'seller';
    } elseif ($kindFilter === 'personal') {
        $lane = 'customer';
    }
}

function dispute_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function dispute_fmt(?string $dt): string
{
    return $dt ? date('M d, Y h:i A', strtotime($dt)) : '';
}

function dispute_preview(string $s, int $n = 70): string
{
    $s = trim(html_entity_decode(strip_tags($s)));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    if (mb_strlen($s) <= $n) {
        return $s;
    }
    return mb_substr($s, 0, $n - 1) . '…';
}

function dispute_lane_sql(string $lane): array
{
    if ($lane === 'seller') {
        return [
            "(
                LOWER(TRIM(COALESCE(f.scope, ''))) = 'seller'
                OR (
                    TRIM(COALESCE(f.scope, '')) = ''
                    AND (
                        COALESCE(f.title, '') LIKE 'Seller%'
                        OR COALESCE(f.feedbackdata, '') LIKE '[Seller dispute]%'
                    )
                )
            )",
            [],
        ];
    }
    if ($lane === 'customer') {
        return [
            "(
                LOWER(TRIM(COALESCE(f.scope, ''))) = 'customer'
                OR (
                    TRIM(COALESCE(f.scope, '')) = ''
                    AND (
                        COALESCE(f.title, '') LIKE 'Customer Dispute%'
                        OR COALESCE(f.feedbackdata, '') LIKE '[Dispute]%'
                    )
                )
            )",
            [],
        ];
    }
    return ['1=1', []];
}

function dispute_go(string $lane, string $filter, string $msgKey = '', string $kind = 'personal'): void
{
    $q = 'kind=' . urlencode($kind) . '&lane=' . urlencode($lane) . '&filter=' . urlencode($filter);
    if ($msgKey !== '') {
        $q .= '&msg=' . urlencode($msgKey);
    }
    header('Location: dispute.php?' . $q);
    exit;
}

function dispute_avatar_color(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

function dispute_initials(string $peer): string
{
    $peer = trim($peer);
    if ($peer === '') {
        return '?';
    }
    $local = explode('@', $peer)[0] ?? $peer;
    $local = preg_replace('/[^a-zA-Z0-9]+/', ' ', $local) ?? $local;
    $parts = array_values(array_filter(explode(' ', trim($local))));
    if (!$parts) {
        return mb_strtoupper(mb_substr($peer, 0, 1));
    }
    $a = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $b = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : mb_strtoupper(mb_substr($parts[0], 1, 1));
    return trim($a . $b) !== '' ? trim($a . $b) : '?';
}

function dispute_who_label(string $hint): string
{
    $hint = strtolower(trim($hint));
    if (strpos($hint, 'seller') !== false) {
        return 'Seller';
    }
    if (strpos($hint, 'customer') !== false || strpos($hint, 'dispute') !== false) {
        return 'Customer';
    }
    return 'Dispute';
}

function dispute_fetch_threads(PDO $dbh, string $lane, string $filter): array
{
    [$laneSql, $laneParams] = dispute_lane_sql($lane);
    $sql = "
      SELECT
        f.sender AS peer_key,
        f.sender AS peer_display,
        MIN(f.id_feedback_admin) AS thread_id,
        MAX(f.created_at) AS last_time,
        MIN(f.created_at) AS first_time,
        SUM(CASE WHEN f.is_read=0 AND f.receiver='Admin' THEN 1 ELSE 0 END) AS unread_count,
        SUBSTRING_INDEX(
          GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR ' ||| '),
          ' ||| ', 1
        ) AS last_message,
        SUBSTRING_INDEX(
          GROUP_CONCAT(COALESCE(NULLIF(TRIM(f.scope), ''), COALESCE(f.title, '')) ORDER BY f.created_at DESC SEPARATOR ' ||| '),
          ' ||| ', 1
        ) AS last_hint
      FROM feedback_admin f
      WHERE f.receiver = 'Admin'
        AND (
              f.channel = 'dispute'
           OR (
                f.channel = 'user_admin'
                AND (
                  COALESCE(f.title, '') LIKE '%Dispute%'
                  OR COALESCE(f.feedbackdata, '') LIKE '[Dispute]%'
                  OR COALESCE(f.feedbackdata, '') LIKE '[Seller dispute]%'
                )
              )
        )
        AND {$laneSql}
      GROUP BY f.sender
      ORDER BY last_time DESC
      LIMIT 500
    ";
    $st = $dbh->prepare($sql);
    $st->execute($laneParams);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function dispute_format_id(int $threadId): string
{
    if ($threadId <= 0) {
        return 'DSP-000000';
    }
    return 'DSP-' . str_pad((string)$threadId, 6, '0', STR_PAD_LEFT);
}

function dispute_parse_id_param(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/^DSP-0*([0-9]+)$/i', $raw, $m)) {
        return (int)$m[1];
    }
    if (ctype_digit($raw)) {
        return (int)$raw;
    }
    return 0;
}

function dispute_row_status(array $t): string
{
    $u = (int)($t['unread_count'] ?? 0);
    $last = strtotime((string)($t['last_time'] ?? '')) ?: 0;
    $who = dispute_who_label((string)($t['last_hint'] ?? ''));
    if ($u > 0) {
        return ($who === 'Seller') ? 'awaiting' : 'open';
    }
    if ($last > 0 && $last < strtotime('-30 days')) {
        return 'closed';
    }
    return 'resolved';
}

function dispute_row_priority(array $t): string
{
    $hay = strtolower((string)($t['last_message'] ?? '') . ' ' . (string)($t['last_hint'] ?? ''));
    if (preg_match('/\b(fraud|not received|refund|chargeback|urgent|scam)\b/', $hay)) {
        return 'high';
    }
    if (preg_match('/\b(damaged|wrong item|missing|late)\b/', $hay)) {
        return 'medium';
    }
    return 'low';
}

function dispute_parse_order_code(string $text): string
{
    if (preg_match('/\b(ORD-[A-Z0-9-]+|ORD-\d+)\b/i', $text, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\border[#:\s-]*([0-9]{3,})\b/i', $text, $m)) {
        return 'ORD-' . str_pad($m[1], 6, '0', STR_PAD_LEFT);
    }
    return '';
}

function dispute_parse_amount(string $text): string
{
    if (preg_match('/\$\s*([0-9]+(?:\.[0-9]{1,2})?)/', $text, $m)) {
        return '$' . number_format((float)$m[1], 2) . ' USD';
    }
    return '—';
}

function dispute_reason_title(string $hint, string $message): string
{
    $hint = trim(html_entity_decode(strip_tags($hint)));
    $message = trim(html_entity_decode(strip_tags($message)));
    $message = preg_replace('/^\[(?:Seller )?dispute\]\s*/i', '', $message) ?? $message;
    if ($hint !== '' && stripos($hint, 'dispute') === false) {
        return mb_substr($hint, 0, 80);
    }
    if (preg_match('/^([^\.\n!?]{8,60})/u', $message, $m)) {
        return trim($m[1]);
    }
    return $message !== '' ? mb_substr($message, 0, 48) : 'Dispute';
}

function dispute_reason_key(string $title): string
{
    $t = strtolower($title);
    if (preg_match('/not received|never arrived|missing package/', $t)) {
        return 'not_received';
    }
    if (preg_match('/damaged|broken|defective/', $t)) {
        return 'damaged';
    }
    if (preg_match('/wrong item|incorrect/', $t)) {
        return 'wrong_item';
    }
    if (preg_match('/refund|chargeback/', $t)) {
        return 'refund';
    }
    if (preg_match('/late|delayed|shipping/', $t)) {
        return 'shipping';
    }
    return 'other';
}


// Mark one thread read
if (isset($_GET['mark']) && $_GET['mark'] !== '') {
    $peer = trim((string)$_GET['mark']);
    if ($peer !== '' && strpos($peer, '@') !== false) {
        try {
            [$laneSql, $laneParams] = dispute_lane_sql($lane);
            $st = $dbh->prepare("
                UPDATE feedback_admin f
                SET f.is_read = 1, f.read_at = NOW()
                WHERE f.channel = 'dispute'
                  AND f.receiver = 'Admin'
                  AND f.sender = :peer
                  AND f.is_read = 0
                  AND {$laneSql}
            ");
            $st->execute(array_merge([':peer' => $peer], $laneParams));
            dispute_go($lane, $filter, 'threadread', $kindFilter);
        } catch (Throwable $e) {
            $error = 'Could not mark read.';
        }
    }
}

// Reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_peer'])) {
    $peer = trim((string)($_POST['reply_peer'] ?? ''));
    $text = trim((string)($_POST['reply_text'] ?? ''));
    $lane = strtolower(trim((string)($_POST['lane'] ?? $lane)));
    $lane = in_array($lane, ['all', 'customer', 'seller'], true) ? $lane : 'all';
    $filter = strtolower(trim((string)($_POST['filter'] ?? $filter)));
    $filter = in_array($filter, ['all', 'open', 'awaiting', 'resolved', 'closed', 'unread', 'read'], true) ? $filter : 'all';
    if ($peer === '' || strpos($peer, '@') === false) {
        $error = 'Invalid peer.';
    } elseif ($text === '') {
        $error = 'Type a reply.';
    } else {
        try {
            $ins = $dbh->prepare("
                INSERT INTO feedback_admin (sender, receiver, channel, scope, title, feedbackdata, attachment, is_read)
                VALUES ('Admin', :peer, 'dispute', :scope, 'Admin Dispute Reply', :d, NULL, 0)
            ");
            $scope = $lane === 'seller' ? 'seller' : ($lane === 'customer' ? 'customer' : 'customer');
            if ($lane === 'all') {
                $sc = $dbh->prepare("
                    SELECT scope FROM feedback_admin
                    WHERE channel='dispute' AND sender=:peer AND receiver='Admin'
                    ORDER BY id_feedback_admin DESC LIMIT 1
                ");
                $sc->execute([':peer' => $peer]);
                $got = strtolower(trim((string)$sc->fetchColumn()));
                if (in_array($got, ['customer', 'seller'], true)) {
                    $scope = $got;
                }
            }
            $ins->execute([
                ':peer' => $peer,
                ':scope' => $scope,
                ':d' => $text,
            ]);
            $mk = $dbh->prepare("
                UPDATE feedback_admin
                SET is_read = 1, read_at = NOW()
                WHERE channel='dispute' AND receiver='Admin' AND sender=:peer AND is_read=0
            ");
            $mk->execute([':peer' => $peer]);
            $tid = 0;
            try {
                $tidSt = $dbh->prepare("
                    SELECT MIN(id_feedback_admin)
                    FROM feedback_admin
                    WHERE receiver='Admin' AND sender=:peer
                      AND (
                        channel='dispute'
                        OR (channel='user_admin' AND (
                          COALESCE(title,'') LIKE '%Dispute%'
                          OR COALESCE(feedbackdata,'') LIKE '[Dispute]%'
                          OR COALESCE(feedbackdata,'') LIKE '[Seller dispute]%'
                        ))
                      )
                ");
                $tidSt->execute([':peer' => $peer]);
                $tid = (int)$tidSt->fetchColumn();
            } catch (Throwable $e) {
                $tid = 0;
            }
            if ($tid > 0) {
                header('Location: dispute_detail.php?id=' . $tid . '&lane=' . urlencode($lane) . '&filter=' . urlencode($filter) . '&msg=replied');
            } else {
                header('Location: dispute_detail.php?peer=' . urlencode($peer) . '&lane=' . urlencode($lane) . '&filter=' . urlencode($filter) . '&msg=replied');
            }
            exit;
        } catch (Throwable $e) {
            $error = 'Could not send reply.';
        }
    }
}

if (($_GET['msg'] ?? '') === 'threadread') {
    $msg = 'Dispute thread marked as read.';
}
if (($_GET['msg'] ?? '') === 'replied') {
    $msg = 'Reply sent.';
}

$threads = [];
$allThreads = [];
$customerThreads = [];
$sellerThreads = [];
try {
    $allThreads = dispute_fetch_threads($dbh, 'all', 'all');
    $customerThreads = dispute_fetch_threads($dbh, 'customer', 'all');
    $sellerThreads = dispute_fetch_threads($dbh, 'seller', 'all');
    $threads = dispute_fetch_threads($dbh, $lane, $filter);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : ('DB error: ' . $e->getMessage());
    $threads = [];
}
// Audience kind counts map to lanes: personal≈customer, commerce≈seller, publisher≈0
$kindCounts = [
    'personal' => count($customerThreads),
    'publisher' => 0,
    'commerce' => count($sellerThreads),
];
if ($kindFilter === 'publisher') {
    $threads = [];
} elseif ($kindFilter === 'commerce') {
    // Prefer seller lane list already applied when lane=seller; if lane=all show seller only
    if ($lane === 'all') {
        $threads = dispute_fetch_threads($dbh, 'seller', $filter);
    }
} elseif ($kindFilter === 'personal') {
    if ($lane === 'all') {
        $threads = dispute_fetch_threads($dbh, 'customer', $filter);
    }
}

$totalThreads = count($allThreads);
$customerCount = count($customerThreads);
$sellerCount = count($sellerThreads);

$statusCounts = ['all' => 0, 'open' => 0, 'awaiting' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($allThreads as &$__t) {
    $__t['_status'] = dispute_row_status($__t);
    $__t['_priority'] = dispute_row_priority($__t);
    $st = $__t['_status'];
    $statusCounts['all']++;
    if (isset($statusCounts[$st])) {
        $statusCounts[$st]++;
    }
}
unset($__t);

foreach ($threads as &$__t) {
    $__t['_status'] = dispute_row_status($__t);
    $__t['_priority'] = dispute_row_priority($__t);
}
unset($__t);

// Apply screenshot status tabs
if ($statusFilter !== 'all') {
    $threads = array_values(array_filter($threads, static function ($t) use ($statusFilter) {
        return ($t['_status'] ?? '') === $statusFilter;
    }));
}

if ($reasonFilter !== 'all') {
    $threads = array_values(array_filter($threads, static function ($t) use ($reasonFilter) {
        $title = dispute_reason_title((string)($t['last_hint'] ?? ''), (string)($t['last_message'] ?? ''));
        return dispute_reason_key($title) === $reasonFilter;
    }));
}

if ($qSearch !== '') {
    $qLow = mb_strtolower($qSearch);
    $threads = array_values(array_filter($threads, static function ($t) use ($qLow) {
        $hay = mb_strtolower(implode(' ', [
            (string)($t['peer_key'] ?? ''),
            (string)($t['last_message'] ?? ''),
            (string)($t['last_hint'] ?? ''),
        ]));
        return mb_strpos($hay, $qLow) !== false;
    }));
}

if ($dateFrom !== '' || $dateTo !== '') {
    $fromTs = $dateFrom !== '' ? strtotime($dateFrom . ' 00:00:00') : 0;
    $toTs = $dateTo !== '' ? strtotime($dateTo . ' 23:59:59') : PHP_INT_MAX;
    $threads = array_values(array_filter($threads, static function ($t) use ($fromTs, $toTs) {
        $ts = strtotime((string)($t['last_time'] ?? '')) ?: 0;
        return $ts >= $fromTs && $ts <= $toTs;
    }));
}

$weekAgo = strtotime('-7 days') ?: 0;
$prevWeek = strtotime('-14 days') ?: 0;
$curWeek = 0;
$prevWeekN = 0;
foreach ($allThreads as $t) {
    $ts = strtotime((string)($t['last_time'] ?? '')) ?: 0;
    if ($ts >= $weekAgo) {
        $curWeek++;
    } elseif ($ts >= $prevWeek) {
        $prevWeekN++;
    }
}
$pctTrend = static function (int $cur, int $prev): string {
    if ($prev <= 0) {
        return $cur > 0 ? '+100% vs last 7 days' : '0% vs last 7 days';
    }
    $pct = (($cur - $prev) / $prev) * 100;
    $sign = $pct >= 0 ? '+' : '';
    return $sign . number_format($pct, 1) . '% vs last 7 days';
};
$trendLabel = $pctTrend($curWeek, $prevWeekN);

$uiFilter = $statusFilter;
$href = static function (array $overrides = []) use ($kindFilter, $lane, $uiFilter, $reasonFilter, $qSearch, $dateFrom, $dateTo): string {
    $params = array_merge([
        'kind' => $kindFilter,
        'lane' => $lane,
        'filter' => $uiFilter,
        'reason' => $reasonFilter,
        'q' => $qSearch,
        'from' => $dateFrom,
        'to' => $dateTo,
    ], $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === 'all' && !in_array($k, ['kind', 'lane', 'filter'], true)) {
            if ($k === 'filter' && $v === 'all') {
                // keep
            } elseif ($v === '' || $v === 'all') {
                unset($params[$k]);
            }
        }
    }
    if (($params['reason'] ?? 'all') === 'all') unset($params['reason']);
    if (($params['q'] ?? '') === '') unset($params['q']);
    if (($params['from'] ?? '') === '') unset($params['from']);
    if (($params['to'] ?? '') === '') unset($params['to']);
    return 'dispute.php?' . http_build_query($params);
};

$statusLabels = [
    'open' => 'Open',
    'awaiting' => 'Awaiting Response',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];
$reasonLabels = [
    'not_received' => 'Item not received',
    'damaged' => 'Damaged / defective',
    'wrong_item' => 'Wrong item',
    'refund' => 'Refund / chargeback',
    'shipping' => 'Shipping delay',
    'other' => 'Other',
];
$priorityLabels = [
    'high' => 'High',
    'medium' => 'Medium',
    'low' => 'Low',
];

org_admin_render_head('Disputes');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Disputes',
    'description' => 'Review and resolve buyer and seller disputes.',
]);
?>

<style>
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
  }
  .ds-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;}
  .ds-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:8px;}
  .ds-btn{height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;text-decoration:none;cursor:pointer;}
  .ds-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .ds-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .ds-btn.view{height:28px;padding:0 10px;border-radius:8px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800;}
  .ds-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
  .ds-card{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;box-shadow:0 1px 2px rgba(15,23,42,.04);text-decoration:none;color:inherit;display:block;min-width:0;}
  .ds-card:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
  .ds-card.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
  .ds-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .ds-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .ds-card-top .delta{font-size:10px;font-weight:800;color:#16a34a;}
  .ds-ico{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;}
  .ds-ico.blue{background:#dbeafe;color:#2563eb;}
  .ds-ico.yellow{background:#fef9c3;color:#ca8a04;}
  .ds-ico.purple{background:#f3e8ff;color:#7c3aed;}
  .ds-ico.green{background:#f0fdf4;color:#16a34a;}
  .ds-ico.gray{background:#f1f5f9;color:#64748b;}
  .ds-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
  .ds-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}
  .ds-tabs{flex:0 0 auto;display:flex;align-items:center;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:0 4px;overflow:auto;}
  .ds-tabs a{flex:0 0 auto;padding:8px 12px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;}
  .ds-tabs a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .ds-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .ds-tabs .spacer{flex:1 1 auto;}
  .ds-main{flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;}
  .ds-filters{flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;}
  .ds-search{position:relative;flex:1 1 160px;min-width:140px;max-width:240px;}
  .ds-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .ds-search input,.ds-filters select,.ds-filters input[type="date"]{height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;}
  .ds-search input{width:100%;padding-left:28px;}
  .ds-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;}
  .ds-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1280px;}
  .ds-table th{text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#64748b;padding:8px 8px;border-bottom:1px solid #eef2f7;background:#fff;position:sticky;top:0;z-index:3;white-space:nowrap;}
  .ds-table td{padding:10px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;}
  .ds-table tr:hover td{background:#f8fafc;}
  .ds-id{font-weight:800;color:#0f172a;}
  .ds-id a{color:#2563eb;text-decoration:none;font-weight:800;}
  .ds-id a:hover{text-decoration:underline;}
  .ds-sub{font-size:10px;color:#64748b;font-weight:600;}
  .ds-person{display:flex;align-items:center;gap:8px;min-width:0;}
  .ds-av{width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 28px;}
  .ds-name{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ds-reason .t{font-weight:800;color:#0f172a;}
  .ds-reason .d{font-size:10px;color:#64748b;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;}
  .ds-amt{font-weight:800;white-space:nowrap;}
  .ds-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap;}
  .ds-pill .dot{width:6px;height:6px;border-radius:999px;background:currentColor;}
  .ds-pill.open{background:#fef3c7;color:#b45309;}
  .ds-pill.awaiting{background:#f3e8ff;color:#7c3aed;}
  .ds-pill.resolved{background:#dcfce7;color:#15803d;}
  .ds-pill.closed{background:#f1f5f9;color:#64748b;}
  .ds-pill.high{background:#fee2e2;color:#b91c1c;}
  .ds-pill.medium{background:#ffedd5;color:#c2410c;}
  .ds-pill.low{background:#dbeafe;color:#1d4ed8;}
  .ds-acts{display:flex;align-items:center;gap:6px;}
  .ds-alert{padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
  .ds-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .ds-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .ds-foot{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #eef2f7;}
  .ds-foot .muted{font-size:11px;color:#64748b;font-weight:600;}
  .dataTables_wrapper .dataTables_paginate{float:none;text-align:center;padding:0;}
  .dataTables_wrapper .dataTables_paginate .paginate_button{min-width:28px !important;height:28px !important;padding:0 7px !important;margin:0 2px !important;border-radius:7px !important;border:1px solid #e2e8f0 !important;background:#fff !important;font-size:11px !important;font-weight:700 !important;line-height:26px !important;}
  .dataTables_wrapper .dataTables_paginate .paginate_button.current{background:#2563eb !important;border-color:#2563eb !important;color:#fff !important;}
  .dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter{display:none !important;}
  #datatable1_wrapper{display:contents;}
  @media (max-width:1100px){.ds-wrap{overflow:auto;}.ds-cards{grid-template-columns:repeat(2,minmax(0,1fr));}}
  <?= admin_kind_tabs_css('ak') ?>
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="ds-wrap">
      <?= admin_kind_tabs_html(
          $kindFilter,
          $kindCounts,
          static function ($k) use ($href) {
              $laneFor = $k === 'commerce' ? 'seller' : ($k === 'personal' ? 'customer' : 'all');
              return $href(['kind' => $k, 'lane' => $laneFor]);
          },
          'dispute_h',
          'ak',
          'disputes'
      ) ?>

      <?php if ($error !== ''): ?><div class="ds-alert bad"><?= dispute_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="ds-alert ok"><?= dispute_h($msg) ?></div><?php endif; ?>

      <div class="ds-cards">
        <a class="ds-card<?= $uiFilter === 'all' ? ' is-active' : '' ?>" href="<?= dispute_h($href(['filter' => 'all'])) ?>">
          <div class="ds-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="ds-ico blue"><i class="fa fa-files-o"></i></div><div class="lab">Total Disputes</div></div><div class="delta"><?= dispute_h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($statusCounts['all']) ?></div><div class="sub">All threads</div>
        </a>
        <a class="ds-card<?= $uiFilter === 'open' ? ' is-active' : '' ?>" href="<?= dispute_h($href(['filter' => 'open'])) ?>">
          <div class="ds-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="ds-ico yellow"><i class="fa fa-folder-open-o"></i></div><div class="lab">Open Disputes</div></div><div class="delta"><?= dispute_h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($statusCounts['open']) ?></div><div class="sub">Needs review</div>
        </a>
        <a class="ds-card<?= $uiFilter === 'awaiting' ? ' is-active' : '' ?>" href="<?= dispute_h($href(['filter' => 'awaiting'])) ?>">
          <div class="ds-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="ds-ico purple"><i class="fa fa-clock-o"></i></div><div class="lab">Awaiting Response</div></div><div class="delta"><?= dispute_h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($statusCounts['awaiting']) ?></div><div class="sub">Seller / waiting</div>
        </a>
        <a class="ds-card<?= $uiFilter === 'resolved' ? ' is-active' : '' ?>" href="<?= dispute_h($href(['filter' => 'resolved'])) ?>">
          <div class="ds-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="ds-ico green"><i class="fa fa-check-circle"></i></div><div class="lab">Resolved</div></div><div class="delta"><?= dispute_h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($statusCounts['resolved']) ?></div><div class="sub">Cleared</div>
        </a>
        <a class="ds-card<?= $uiFilter === 'closed' ? ' is-active' : '' ?>" href="<?= dispute_h($href(['filter' => 'closed'])) ?>">
          <div class="ds-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="ds-ico gray"><i class="fa fa-lock"></i></div><div class="lab">Closed</div></div><div class="delta"><?= dispute_h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($statusCounts['closed']) ?></div><div class="sub">Older resolved</div>
        </a>
      </div>

      <nav class="ds-tabs" aria-label="Dispute status">
        <a href="<?= dispute_h($href(['filter' => 'all'])) ?>" class="<?= $uiFilter === 'all' ? 'is-active' : '' ?>">All Disputes<span class="cnt">(<?= (int)$statusCounts['all'] ?>)</span></a>
        <a href="<?= dispute_h($href(['filter' => 'open'])) ?>" class="<?= $uiFilter === 'open' ? 'is-active' : '' ?>">Open<span class="cnt">(<?= (int)$statusCounts['open'] ?>)</span></a>
        <a href="<?= dispute_h($href(['filter' => 'awaiting'])) ?>" class="<?= $uiFilter === 'awaiting' ? 'is-active' : '' ?>">Awaiting Response<span class="cnt">(<?= (int)$statusCounts['awaiting'] ?>)</span></a>
        <a href="<?= dispute_h($href(['filter' => 'resolved'])) ?>" class="<?= $uiFilter === 'resolved' ? 'is-active' : '' ?>">Resolved<span class="cnt">(<?= (int)$statusCounts['resolved'] ?>)</span></a>
        <a href="<?= dispute_h($href(['filter' => 'closed'])) ?>" class="<?= $uiFilter === 'closed' ? 'is-active' : '' ?>">Closed<span class="cnt">(<?= (int)$statusCounts['closed'] ?>)</span></a>
        <span class="spacer"></span>
        <select aria-label="Bulk actions" style="height:28px;border:1px solid #e2e8f0;border-radius:8px;font-size:11px;font-weight:700;margin:4px 6px;padding:0 8px;">
          <option value="">Bulk Actions</option>
          <option value="noop" disabled>Coming soon</option>
        </select>
      </nav>

      <div class="ds-main">
        <form class="ds-filters" id="dsFilters" method="get">
          <input type="hidden" name="kind" value="<?= dispute_h($kindFilter) ?>">
          <select name="filter" aria-label="Status" onchange="this.form.submit()">
            <option value="all"<?= $uiFilter === 'all' ? ' selected' : '' ?>>All Statuses</option>
            <?php foreach ($statusLabels as $k => $lab): ?>
              <option value="<?= dispute_h($k) ?>"<?= $uiFilter === $k ? ' selected' : '' ?>><?= dispute_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="reason" aria-label="Dispute reason" onchange="this.form.submit()">
            <option value="all"<?= $reasonFilter === 'all' ? ' selected' : '' ?>>All Dispute Reasons</option>
            <?php foreach ($reasonLabels as $k => $lab): ?>
              <option value="<?= dispute_h($k) ?>"<?= $reasonFilter === $k ? ' selected' : '' ?>><?= dispute_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="lane" aria-label="Sellers / buyers" onchange="this.form.submit()">
            <option value="all"<?= $lane === 'all' ? ' selected' : '' ?>>All Sellers</option>
            <option value="seller"<?= $lane === 'seller' ? ' selected' : '' ?>>Sellers only</option>
            <option value="customer"<?= $lane === 'customer' ? ' selected' : '' ?>>All Buyers</option>
          </select>
          <input type="date" name="from" value="<?= dispute_h($dateFrom) ?>" aria-label="From" onchange="this.form.submit()">
          <input type="date" name="to" value="<?= dispute_h($dateTo) ?>" aria-label="To" onchange="this.form.submit()">
          <div class="ds-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= dispute_h($qSearch) ?>" placeholder="Search disputes..." autocomplete="off">
          </div>
          <button type="submit" class="ds-btn primary"><i class="fa fa-sliders"></i> Filter</button>
          <button type="button" class="ds-btn" title="Export coming soon"><i class="fa fa-download"></i> Export</button>
          <select id="dsPageLen" aria-label="Per page">
            <option value="10" selected>10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
          </select>
        </form>

        <div class="ds-table-wrap">
          <table id="datatable1" class="ds-table display" style="width:100%;">
            <thead>
              <tr>
                <th style="width:28px;"><input type="checkbox" id="dsSelectAll"></th>
                <th>Dispute ID</th>
                <th>Order ID</th>
                <th>Buyer</th>
                <th>Seller</th>
                <th>Reason</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Created At</th>
                <th>Last Updated</th>
                <th style="width:120px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($threads as $i => $t):
                $peer = (string)($t['peer_key'] ?? '');
                $plain = (string)($t['last_message'] ?? '');
                $who = dispute_who_label((string)($t['last_hint'] ?? ''));
                $st = (string)($t['_status'] ?? 'open');
                $pri = (string)($t['_priority'] ?? 'low');
                $threadId = (int)($t['thread_id'] ?? 0);
                $dspId = dispute_format_id($threadId);
                $txn = '#' . (1000000 + ($threadId > 0 ? $threadId : (abs(crc32($peer)) % 900000)));
                $orderCode = dispute_parse_order_code($plain . ' ' . (string)($t['last_hint'] ?? ''));
                if ($orderCode === '') {
                    $orderCode = '—';
                }
                $amount = dispute_parse_amount($plain);
                $reasonTitle = dispute_reason_title((string)($t['last_hint'] ?? ''), $plain);
                $reasonBody = dispute_preview($plain, 64);
                $created = dispute_fmt($t['first_time'] ?? $t['last_time'] ?? null);
                $updated = dispute_fmt($t['last_time'] ?? null);
                $buyerName = $who === 'Seller' ? 'Buyer' : ($peer !== '' ? explode('@', $peer)[0] : 'Buyer');
                $sellerName = $who === 'Seller' ? (explode('@', $peer)[0] ?? 'Seller') : 'Seller';
                $buyerHandle = $who !== 'Seller' ? ($peer !== '' ? $peer : '—') : '—';
                $sellerHandle = $who === 'Seller' ? $peer : '—';
                if ($who === 'Seller') {
                    $sellerName = $peer !== '' ? (explode('@', $peer)[0] ?: 'Seller') : 'Seller';
                    $sellerHandle = $peer;
                    $buyerName = 'Customer';
                    $buyerHandle = '—';
                } else {
                    $buyerName = $peer !== '' ? (explode('@', $peer)[0] ?: 'Buyer') : 'Buyer';
                    $buyerHandle = $peer !== '' ? '@' . preg_replace('/@.*/', '', $peer) : '—';
                    if (strpos($peer, '@') !== false) {
                        $buyerHandle = $peer;
                    }
                    $sellerName = 'Shop';
                    $sellerHandle = '—';
                }
                $listFilter = $uiFilter === 'open' ? 'unread' : ($uiFilter === 'resolved' || $uiFilter === 'closed' ? 'read' : 'all');
                $openHref = $threadId > 0
                    ? ('dispute_detail.php?id=' . $threadId . '&lane=' . rawurlencode($lane) . '&filter=' . rawurlencode($listFilter))
                    : ('dispute_detail.php?peer=' . rawurlencode($peer) . '&lane=' . rawurlencode($lane) . '&filter=' . rawurlencode($listFilter));
                $markHref = 'dispute.php?kind=' . rawurlencode($kindFilter) . '&lane=' . rawurlencode($lane) . '&filter=' . rawurlencode($uiFilter) . '&mark=' . rawurlencode($peer);
                $buyerIni = dispute_initials($buyerName);
                $sellerIni = dispute_initials($sellerName);
            ?>
              <tr>
                <td><input type="checkbox" class="ds-row-check" value="<?= dispute_h($peer) ?>"></td>
                <td>
                  <div class="ds-id"><a href="<?= dispute_h($openHref) ?>"><?= dispute_h($dspId) ?></a></div>
                  <div class="ds-sub"><?= dispute_h($txn) ?></div>
                </td>
                <td>
                  <div class="ds-id"><?= dispute_h($orderCode) ?></div>
                  <div class="ds-sub"><?= dispute_h($created) ?></div>
                </td>
                <td>
                  <div class="ds-person">
                    <span class="ds-av" style="background:<?= dispute_h(dispute_avatar_color($buyerHandle . $buyerName)) ?>;"><?= dispute_h($buyerIni) ?></span>
                    <div style="min-width:0;">
                      <div class="ds-name"><?= dispute_h(ucwords(str_replace(['.', '_'], ' ', $buyerName))) ?></div>
                      <div class="ds-sub"><?= dispute_h($buyerHandle) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="ds-person">
                    <span class="ds-av" style="background:<?= dispute_h(dispute_avatar_color($sellerHandle . $sellerName)) ?>;"><?= dispute_h($sellerIni) ?></span>
                    <div style="min-width:0;">
                      <div class="ds-name"><?= dispute_h(ucwords(str_replace(['.', '_'], ' ', $sellerName))) ?></div>
                      <div class="ds-sub"><?= dispute_h($sellerHandle) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="ds-reason">
                    <div class="t"><?= dispute_h($reasonTitle) ?></div>
                    <div class="d" title="<?= dispute_h($reasonBody) ?>"><?= dispute_h($reasonBody) ?></div>
                  </div>
                </td>
                <td><span class="ds-amt"><?= dispute_h($amount) ?></span></td>
                <td><span class="ds-pill <?= dispute_h($st) ?>"><span class="dot"></span><?= dispute_h($statusLabels[$st] ?? ucfirst($st)) ?></span></td>
                <td><span class="ds-pill <?= dispute_h($pri) ?>"><span class="dot"></span><?= dispute_h($priorityLabels[$pri] ?? ucfirst($pri)) ?></span></td>
                <td><div class="ds-sub" style="color:#475569;"><?= dispute_h($created) ?></div></td>
                <td><div class="ds-sub" style="color:#475569;"><?= dispute_h($updated) ?></div></td>
                <td>
                  <div class="ds-acts">
                    <a class="ds-btn view" href="<?= dispute_h($openHref) ?>">View</a>
                    <div class="fries-menu">
                      <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true"><span class="fries-icon" aria-hidden="true"></span></button>
                      <div class="fries-dropdown" role="menu">
                        <a class="fries-item" role="menuitem" href="<?= dispute_h($openHref) ?>"><i class="fa fa-eye"></i> Open thread</a>
                        <?php if ((int)($t['unread_count'] ?? 0) > 0): ?>
                          <a class="fries-item" role="menuitem" href="<?= dispute_h($markHref) ?>"><i class="fa fa-check"></i> Mark read</a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="ds-foot">
          <div class="muted" id="dsShowing">Showing 0 disputes</div>
          <div id="dsPagerHost"></div>
          <div class="muted"><span id="visibleDisputeCount"><?= (int)count($threads) ?></span> in this view</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/datatables/jquery.dataTables.js"></script>
<script src="js/admin-fries-menu.js?v=1"></script>
<script>
$(function() {
  var hasRows = <?= count($threads) > 0 ? 'true' : 'false' ?>;
  if (!hasRows) {
    $('#dsShowing').text('Showing 0 disputes');
    return;
  }
  var dt = $('#datatable1').DataTable({
    paging: true,
    pageLength: 10,
    lengthChange: false,
    info: true,
    autoWidth: false,
    order: [[10, 'desc']],
    columnDefs: [{ orderable: false, targets: [0, 11] }],
    dom: 'tp',
    language: { paginate: { previous: '‹', next: '›' } },
    drawCallback: function() {
      var info = this.api().page.info();
      var from = info.recordsDisplay === 0 ? 0 : (info.start + 1);
      $('#dsShowing').text('Showing ' + from + ' to ' + info.end + ' of ' + info.recordsDisplay + ' disputes.');
      $('#visibleDisputeCount').text(info.recordsDisplay);
      var $pag = $(this.api().table().container()).find('.dataTables_paginate');
      if ($pag.length) $('#dsPagerHost').empty().append($pag);
    }
  });
  setTimeout(function(){ var $pag=$('#datatable1_paginate'); if($pag.length) $('#dsPagerHost').empty().append($pag); }, 0);
  $('#dsPageLen').on('change', function(){ dt.page.len(parseInt(this.value,10)||10).draw(); });
  $('#dsSelectAll').on('change', function(){ $('.ds-row-check').prop('checked', this.checked); });
});
</script>
<?php org_admin_render_foot(); ?>
