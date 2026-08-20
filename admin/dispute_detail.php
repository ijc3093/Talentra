<?php
declare(strict_types=1);

/**
 * admin/dispute_detail.php
 * Dispute Details — overview / messages / timeline / evidence / notes.
 * Reply + mark-read behavior preserved from the dispute inbox.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';

$lane = strtolower(trim((string)($_GET['lane'] ?? $_POST['lane'] ?? 'all')));
$lane = in_array($lane, ['all', 'customer', 'seller'], true) ? $lane : 'all';
$filter = strtolower(trim((string)($_GET['filter'] ?? $_POST['filter'] ?? 'all')));
if ($filter === 'open') {
    $filter = 'unread';
} elseif (in_array($filter, ['resolved', 'closed', 'awaiting'], true)) {
    $filter = $filter === 'awaiting' ? 'unread' : 'read';
}
$filter = in_array($filter, ['all', 'unread', 'read'], true) ? $filter : 'all';
$peer = trim((string)($_GET['peer'] ?? $_POST['reply_peer'] ?? $_POST['peer'] ?? ''));
$disputeIdRaw = trim((string)($_GET['id'] ?? $_GET['dispute_id'] ?? $_POST['id'] ?? $_POST['dispute_id'] ?? ''));
$tab = strtolower(trim((string)($_GET['tab'] ?? 'overview')));
$tab = in_array($tab, ['overview', 'messages', 'timeline', 'evidence', 'notes'], true) ? $tab : 'overview';

function dispute_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function dispute_fmt(?string $dt): string
{
    return $dt ? date('M d, Y h:i A', strtotime($dt)) : '—';
}

function dispute_fmt_short(?string $dt): string
{
    return $dt ? date('M d, Y', strtotime($dt)) : '—';
}

function dispute_preview(string $s, int $n = 90): string
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

function dispute_detail_go(string $lane, string $filter, string $peer, string $msgKey = '', string $tab = 'overview', int $disputeId = 0): void
{
    $q = 'lane=' . urlencode($lane) . '&filter=' . urlencode($filter) . '&tab=' . urlencode($tab);
    if ($disputeId > 0) {
        $q = 'id=' . $disputeId . '&' . $q;
    } else {
        $q = 'peer=' . urlencode($peer) . '&' . $q;
    }
    if ($msgKey !== '') {
        $q .= '&msg=' . urlencode($msgKey);
    }
    header('Location: dispute_detail.php?' . $q);
    exit;
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

function dispute_resolve_peer_by_id(PDO $dbh, int $id): array
{
    if ($id <= 0) {
        return ['peer' => '', 'thread_id' => 0];
    }
    try {
        $st = $dbh->prepare("
            SELECT sender, receiver, channel, title, feedbackdata
            FROM feedback_admin
            WHERE id_feedback_admin = :id
            LIMIT 1
        ");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['peer' => '', 'thread_id' => 0];
        }
        $sender = (string)($row['sender'] ?? '');
        $receiver = (string)($row['receiver'] ?? '');
        $peer = '';
        if (strcasecmp($receiver, 'Admin') === 0 && strpos($sender, '@') !== false) {
            $peer = $sender;
        } elseif (strcasecmp($sender, 'Admin') === 0 && strpos($receiver, '@') !== false) {
            $peer = $receiver;
        }
        if ($peer === '') {
            return ['peer' => '', 'thread_id' => 0];
        }
        // Canonical thread id = first message in this peer's dispute thread
        $tid = $dbh->prepare("
            SELECT MIN(id_feedback_admin)
            FROM feedback_admin
            WHERE receiver = 'Admin'
              AND sender = :peer
              AND (
                    channel = 'dispute'
                 OR (
                      channel = 'user_admin'
                      AND (
                        COALESCE(title, '') LIKE '%Dispute%'
                        OR COALESCE(feedbackdata, '') LIKE '[Dispute]%'
                        OR COALESCE(feedbackdata, '') LIKE '[Seller dispute]%'
                      )
                    )
              )
        ");
        $tid->execute([':peer' => $peer]);
        $threadId = (int)$tid->fetchColumn();
        return ['peer' => $peer, 'thread_id' => $threadId > 0 ? $threadId : $id];
    } catch (Throwable $e) {
        return ['peer' => '', 'thread_id' => 0];
    }
}

function dispute_resolve_id_by_peer(PDO $dbh, string $peer): int
{
    if ($peer === '' || strpos($peer, '@') === false) {
        return 0;
    }
    try {
        $st = $dbh->prepare("
            SELECT MIN(id_feedback_admin)
            FROM feedback_admin
            WHERE receiver = 'Admin'
              AND sender = :peer
              AND (
                    channel = 'dispute'
                 OR (
                      channel = 'user_admin'
                      AND (
                        COALESCE(title, '') LIKE '%Dispute%'
                        OR COALESCE(feedbackdata, '') LIKE '[Dispute]%'
                        OR COALESCE(feedbackdata, '') LIKE '[Seller dispute]%'
                      )
                    )
              )
        ");
        $st->execute([':peer' => $peer]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
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

function dispute_row_status(int $unread, string $who, ?string $lastTime): string
{
    if ($unread > 0) {
        return ($who === 'Seller') ? 'awaiting' : 'open';
    }
    $last = $lastTime ? (strtotime($lastTime) ?: 0) : 0;
    if ($last > 0 && $last < strtotime('-30 days')) {
        return 'closed';
    }
    return 'resolved';
}

function dispute_row_priority(string $hay): string
{
    $hay = strtolower($hay);
    if (preg_match('/\b(fraud|not received|refund|chargeback|urgent|scam)\b/', $hay)) {
        return 'high';
    }
    if (preg_match('/\b(damaged|wrong item|missing|late)\b/', $hay)) {
        return 'medium';
    }
    return 'low';
}

function dispute_fetch_threads(PDO $dbh, string $lane, string $filter): array
{
    [$laneSql, $laneParams] = dispute_lane_sql($lane);
    $sql = "
      SELECT
        f.sender AS peer_key,
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
        ) AS last_hint,
        COUNT(*) AS msg_count
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
    $threads = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($filter !== 'all') {
        $threads = array_values(array_filter($threads, static function ($t) use ($filter) {
            $u = (int)($t['unread_count'] ?? 0);
            return $filter === 'unread' ? ($u > 0) : ($u === 0);
        }));
    }
    return $threads;
}

$disputeId = dispute_parse_id_param($disputeIdRaw);
if ($disputeId > 0) {
    $resolved = dispute_resolve_peer_by_id($dbh, $disputeId);
    if ($resolved['peer'] !== '') {
        $peer = $resolved['peer'];
        $disputeId = (int)$resolved['thread_id'];
    }
} elseif ($peer !== '' && strpos($peer, '@') !== false) {
    $disputeId = dispute_resolve_id_by_peer($dbh, $peer);
}

function dispute_lookup_order(PDO $dbh, string $orderCode): ?array
{
    if ($orderCode === '' || $orderCode === '—') {
        return null;
    }
    $idGuess = 0;
    if (preg_match('/ORD-0*([0-9]+)/i', $orderCode, $m)) {
        $idGuess = (int)$m[1];
    }
    $queries = [
        "
            SELECT
                o.id, o.order_code, o.created_at, o.total_amount, o.status AS order_status,
                o.payment_status, o.payment_method, o.shipping_address, o.quantity,
                o.unit_price, o.product_name, o.product_id,
                org.name AS org_name,
                bu.username AS buyer_username, bu.email AS buyer_email,
                su.username AS seller_username, su.email AS seller_user_email,
                p.title AS product_title, p.cover_image_path AS product_cover,
                p.category AS product_category, p.product_code AS product_code,
                p.attributes_json AS product_attrs
            FROM org_orders o
            LEFT JOIN organizations org ON org.id = o.org_id
            LEFT JOIN users bu ON bu.id = o.buyer_user_id
            LEFT JOIN users su ON su.id = org.publisher_user_id
            LEFT JOIN org_products p ON p.id = o.product_id
            WHERE o.order_code = :code OR o.id = :id
            LIMIT 1
        ",
        "
            SELECT o.*, org.name AS org_name,
                   bu.username AS buyer_username, bu.email AS buyer_email,
                   p.title AS product_title, p.cover_image_path AS product_cover,
                   p.category AS product_category, p.product_code AS product_code
            FROM org_orders o
            LEFT JOIN organizations org ON org.id = o.org_id
            LEFT JOIN users bu ON bu.id = o.buyer_user_id
            LEFT JOIN org_products p ON p.id = o.product_id
            WHERE o.order_code = :code OR o.id = :id
            LIMIT 1
        ",
        "
            SELECT o.* FROM org_orders o
            WHERE o.order_code = :code OR o.id = :id
            LIMIT 1
        ",
    ];
    foreach ($queries as $sql) {
        try {
            $st = $dbh->prepare($sql);
            $st->execute([':code' => $orderCode, ':id' => $idGuess]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

// Mark read
if (isset($_GET['mark']) && (string)$_GET['mark'] === '1' && $peer !== '' && strpos($peer, '@') !== false) {
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
        $st2 = $dbh->prepare("
            UPDATE feedback_admin
            SET is_read = 1, read_at = NOW()
            WHERE channel = 'user_admin'
              AND receiver = 'Admin'
              AND sender = :peer
              AND is_read = 0
              AND (
                COALESCE(title, '') LIKE '%Dispute%'
                OR COALESCE(feedbackdata, '') LIKE '[Dispute]%'
                OR COALESCE(feedbackdata, '') LIKE '[Seller dispute]%'
              )
        ");
        $st2->execute([':peer' => $peer]);
        dispute_detail_go($lane, $filter, $peer, 'threadread', $tab, $disputeId);
    } catch (Throwable $e) {
        $error = 'Could not mark read.';
    }
}

// Update status (resolved/closed → mark read + admin note)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $peer = trim((string)($_POST['peer'] ?? $peer));
    $newStatus = strtolower(trim((string)($_POST['status'] ?? 'open')));
    $newStatus = in_array($newStatus, ['open', 'awaiting', 'resolved', 'closed'], true) ? $newStatus : 'open';
    $lane = strtolower(trim((string)($_POST['lane'] ?? $lane)));
    $lane = in_array($lane, ['all', 'customer', 'seller'], true) ? $lane : 'all';
    $filter = strtolower(trim((string)($_POST['filter'] ?? $filter)));
    $filter = in_array($filter, ['all', 'unread', 'read'], true) ? $filter : 'all';
    if ($peer === '' || strpos($peer, '@') === false) {
        $error = 'Invalid peer.';
    } else {
        try {
            if (in_array($newStatus, ['resolved', 'closed'], true)) {
                $mk = $dbh->prepare("
                    UPDATE feedback_admin
                    SET is_read = 1, read_at = NOW()
                    WHERE receiver='Admin' AND sender=:peer AND is_read=0
                      AND (
                        channel='dispute'
                        OR (channel='user_admin' AND (
                          COALESCE(title,'') LIKE '%Dispute%'
                          OR COALESCE(feedbackdata,'') LIKE '[Dispute]%'
                          OR COALESCE(feedbackdata,'') LIKE '[Seller dispute]%'
                        ))
                      )
                ");
                $mk->execute([':peer' => $peer]);
            }
            $note = '[Admin status] Set to ' . $newStatus . ' on ' . date('Y-m-d H:i');
            $ins = $dbh->prepare("
                INSERT INTO feedback_admin (sender, receiver, channel, scope, title, feedbackdata, attachment, is_read)
                VALUES ('Admin', :peer, 'dispute', 'customer', 'Admin Dispute Note', :d, NULL, 1)
            ");
            $ins->execute([':peer' => $peer, ':d' => $note]);
            if (!isset($_SESSION['dispute_ui_status']) || !is_array($_SESSION['dispute_ui_status'])) {
                $_SESSION['dispute_ui_status'] = [];
            }
            $_SESSION['dispute_ui_status'][$peer] = $newStatus;
            dispute_detail_go($lane, $filter, $peer, 'status', 'overview', $disputeId);
        } catch (Throwable $e) {
            $error = 'Could not update status.';
        }
    }
}

// Internal note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['note_text'])) {
    $peer = trim((string)($_POST['peer'] ?? $peer));
    $text = trim((string)($_POST['note_text'] ?? ''));
    $lane = strtolower(trim((string)($_POST['lane'] ?? $lane)));
    $lane = in_array($lane, ['all', 'customer', 'seller'], true) ? $lane : 'all';
    $filter = strtolower(trim((string)($_POST['filter'] ?? $filter)));
    $filter = in_array($filter, ['all', 'unread', 'read'], true) ? $filter : 'all';
    if ($peer === '' || strpos($peer, '@') === false) {
        $error = 'Invalid peer.';
    } elseif ($text === '') {
        $error = 'Type a note.';
    } else {
        try {
            $ins = $dbh->prepare("
                INSERT INTO feedback_admin (sender, receiver, channel, scope, title, feedbackdata, attachment, is_read)
                VALUES ('Admin', :peer, 'dispute', 'customer', 'Admin Dispute Note', :d, NULL, 1)
            ");
            $ins->execute([':peer' => $peer, ':d' => '[Admin note] ' . $text]);
            dispute_detail_go($lane, $filter, $peer, 'noted', 'notes', $disputeId);
        } catch (Throwable $e) {
            $error = 'Could not save note.';
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
    $filter = in_array($filter, ['all', 'unread', 'read'], true) ? $filter : 'all';
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
            $scope = $lane === 'seller' ? 'seller' : 'customer';
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
            dispute_detail_go($lane, $filter, $peer, 'replied', 'messages', $disputeId);
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
if (($_GET['msg'] ?? '') === 'status') {
    $msg = 'Status updated.';
}
if (($_GET['msg'] ?? '') === 'noted') {
    $msg = 'Note saved.';
}

$listHref = 'disputes.php?lane=' . rawurlencode($lane) . '&filter=' . rawurlencode($filter === 'unread' ? 'open' : ($filter === 'read' ? 'resolved' : 'all'));

if ($peer === '' || strpos($peer, '@') === false) {
    org_admin_render_head('Dispute Details');
    require_once __DIR__ . '/includes/admin_chrome.php';
    admin_chrome_open(null, [
        'title' => 'Dispute Details',
        'description' => 'Review and resolve buyer and seller disputes.',
    ]);
    echo '<div class="sh-mainpanel"><div class="sh-pagebody" style="padding:16px;">';
    echo '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:8px;font-weight:700;">Dispute thread not found.</div>';
    echo '<p style="margin-top:12px;"><a href="' . dispute_h($listHref) . '">← Back to Disputes</a></p>';
    echo '</div></div>';
    org_admin_render_foot();
    exit;
}

$threads = [];
try {
    $threads = dispute_fetch_threads($dbh, $lane, $filter);
} catch (Throwable $e) {
    $threads = [];
}

$prevPeer = '';
$nextPeer = '';
$prevId = 0;
$nextId = 0;
$navPos = 0;
$navTotal = count($threads);
$threadMeta = null;
foreach ($threads as $i => $t) {
    if ((string)($t['peer_key'] ?? '') === $peer || ((int)($t['thread_id'] ?? 0) > 0 && (int)$t['thread_id'] === $disputeId)) {
        $navPos = $i + 1;
        $threadMeta = $t;
        if ($disputeId <= 0) {
            $disputeId = (int)($t['thread_id'] ?? 0);
        }
        if ($i > 0) {
            $prevPeer = (string)($threads[$i - 1]['peer_key'] ?? '');
            $prevId = (int)($threads[$i - 1]['thread_id'] ?? 0);
        }
        if ($i < count($threads) - 1) {
            $nextPeer = (string)($threads[$i + 1]['peer_key'] ?? '');
            $nextId = (int)($threads[$i + 1]['thread_id'] ?? 0);
        }
        break;
    }
}

if ($threadMeta === null) {
    try {
        $threadsAll = dispute_fetch_threads($dbh, 'all', 'all');
        $navTotal = count($threadsAll);
        foreach ($threadsAll as $i => $t) {
            if ((string)($t['peer_key'] ?? '') === $peer || ((int)($t['thread_id'] ?? 0) > 0 && (int)$t['thread_id'] === $disputeId)) {
                $navPos = $i + 1;
                $threadMeta = $t;
                if ($disputeId <= 0) {
                    $disputeId = (int)($t['thread_id'] ?? 0);
                }
                if ($i > 0) {
                    $prevPeer = (string)($threadsAll[$i - 1]['peer_key'] ?? '');
                    $prevId = (int)($threadsAll[$i - 1]['thread_id'] ?? 0);
                }
                if ($i < count($threadsAll) - 1) {
                    $nextPeer = (string)($threadsAll[$i + 1]['peer_key'] ?? '');
                    $nextId = (int)($threadsAll[$i + 1]['thread_id'] ?? 0);
                }
                $lane = 'all';
                $filter = 'all';
                $listHref = 'disputes.php?lane=all&filter=all';
                break;
            }
        }
    } catch (Throwable $e) {
    }
}

$history = [];
try {
    $hst = $dbh->prepare("
        SELECT sender, receiver, title, feedbackdata, created_at, channel, scope, is_read, attachment
        FROM feedback_admin
        WHERE (
                channel = 'dispute'
             OR (
                  channel = 'user_admin'
                  AND (
                    COALESCE(title, '') LIKE '%Dispute%'
                    OR COALESCE(feedbackdata, '') LIKE '[Dispute]%'
                    OR COALESCE(feedbackdata, '') LIKE '[Seller dispute]%'
                  )
                )
              )
          AND (
                (sender = :p AND receiver = 'Admin')
             OR (sender = 'Admin' AND receiver = :p2)
          )
        ORDER BY id_feedback_admin ASC
        LIMIT 400
    ");
    $hst->execute([':p' => $peer, ':p2' => $peer]);
    $history = $hst->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    try {
        $hst = $dbh->prepare("
            SELECT sender, receiver, title, feedbackdata, created_at, channel, scope, is_read
            FROM feedback_admin
            WHERE (
                    channel = 'dispute'
                 OR (
                      channel = 'user_admin'
                      AND (
                        COALESCE(title, '') LIKE '%Dispute%'
                        OR COALESCE(feedbackdata, '') LIKE '[Dispute]%'
                        OR COALESCE(feedbackdata, '') LIKE '[Seller dispute]%'
                      )
                    )
                  )
              AND (
                    (sender = :p AND receiver = 'Admin')
                 OR (sender = 'Admin' AND receiver = :p2)
              )
            ORDER BY id_feedback_admin ASC
            LIMIT 400
        ");
        $hst->execute([':p' => $peer, ':p2' => $peer]);
        $history = $hst->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        $history = [];
    }
}

if (!$history && $threadMeta === null) {
    org_admin_render_head('Dispute Details');
    require_once __DIR__ . '/includes/admin_chrome.php';
    admin_chrome_open(null, [
        'title' => 'Dispute Details',
        'description' => 'Review and resolve buyer and seller disputes.',
    ]);
    echo '<div class="sh-mainpanel"><div class="sh-pagebody" style="padding:16px;">';
    echo '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:8px;font-weight:700;">Dispute thread not found.</div>';
    echo '<p style="margin-top:12px;"><a href="' . dispute_h($listHref) . '">← Back to Disputes</a></p>';
    echo '</div></div>';
    org_admin_render_foot();
    exit;
}

$unread = (int)($threadMeta['unread_count'] ?? 0);
$msgCount = max((int)($threadMeta['msg_count'] ?? 0), count($history));
$lastHint = (string)($threadMeta['last_hint'] ?? '');
if ($lastHint === '' && $history) {
    $last = $history[count($history) - 1];
    $lastHint = (string)($last['scope'] ?? $last['title'] ?? '');
}
$who = dispute_who_label($lastHint);
$lastTime = (string)($threadMeta['last_time'] ?? '');
if ($lastTime === '' && $history) {
    $lastTime = (string)($history[count($history) - 1]['created_at'] ?? '');
}
$firstTime = (string)($threadMeta['first_time'] ?? '');
if ($firstTime === '' && $history) {
    $firstTime = (string)($history[0]['created_at'] ?? '');
}

$allText = '';
$buyerMsgs = [];
$sellerMsgs = [];
$adminNotes = [];
$evidence = [];
foreach ($history as $row) {
    $body = (string)($row['feedbackdata'] ?? '');
    $allText .= ' ' . $body . ' ' . (string)($row['title'] ?? '');
    $fromAdmin = strcasecmp((string)($row['sender'] ?? ''), 'Admin') === 0;
    $scope = strtolower(trim((string)($row['scope'] ?? '')));
    $title = (string)($row['title'] ?? '');
    if ($fromAdmin) {
        if (stripos($body, '[Admin note]') === 0 || stripos($title, 'Note') !== false || stripos($body, '[Admin status]') === 0) {
            $adminNotes[] = $row;
        }
    } else {
        if ($scope === 'seller' || stripos($body, '[Seller dispute]') === 0 || stripos($title, 'Seller') === 0) {
            $sellerMsgs[] = $row;
        } else {
            $buyerMsgs[] = $row;
        }
    }
    $att = trim((string)($row['attachment'] ?? ''));
    if ($att !== '') {
        $evidence[] = [
            'path' => $att,
            'name' => basename($att),
            'when' => (string)($row['created_at'] ?? ''),
            'who' => $fromAdmin ? 'Admin' : 'Buyer',
        ];
    }
}

$status = dispute_row_status($unread, $who, $lastTime !== '' ? $lastTime : null);
if (!empty($_SESSION['dispute_ui_status'][$peer])) {
    $override = (string)$_SESSION['dispute_ui_status'][$peer];
    if (in_array($override, ['open', 'awaiting', 'resolved', 'closed'], true)) {
        $status = $override;
    }
}
$priority = dispute_row_priority($allText . ' ' . $lastHint);
$orderCode = dispute_parse_order_code($allText);
$amount = dispute_parse_amount($allText);
$reasonTitle = dispute_reason_title($lastHint, (string)($buyerMsgs[0]['feedbackdata'] ?? $threadMeta['last_message'] ?? ''));
$buyerDesc = '';
if ($buyerMsgs) {
    $buyerDesc = preg_replace('/^\[(?:Seller )?dispute\]\s*/i', '', (string)($buyerMsgs[0]['feedbackdata'] ?? '')) ?? '';
}
$sellerResponse = $sellerMsgs ? (string)($sellerMsgs[count($sellerMsgs) - 1]['feedbackdata'] ?? '') : '';
$sellerRespondedAt = $sellerMsgs ? (string)($sellerMsgs[count($sellerMsgs) - 1]['created_at'] ?? '') : '';
$sellerResponse = preg_replace('/^\[(?:Seller )?dispute\]\s*/i', '', $sellerResponse) ?? $sellerResponse;

$order = dispute_lookup_order($dbh, $orderCode);
if ($order) {
    if ($amount === '—' && isset($order['total_amount'])) {
        $amount = '$' . number_format((float)$order['total_amount'], 2) . ' USD';
    }
    $codeFromDb = trim((string)($order['order_code'] ?? ''));
    if ($codeFromDb !== '') {
        $orderCode = $codeFromDb;
    } elseif ($orderCode === '' && !empty($order['id'])) {
        $orderCode = 'ORD-' . str_pad((string)(int)$order['id'], 6, '0', STR_PAD_LEFT);
    }
}

if ($disputeId <= 0) {
    $disputeId = (int)($threadMeta['thread_id'] ?? 0);
}
$dspId = dispute_format_id($disputeId);
$buyerName = $who === 'Seller'
    ? ((string)($order['buyer_username'] ?? '') ?: 'Customer')
    : (explode('@', $peer)[0] ?: 'Buyer');
$buyerEmail = $who === 'Seller'
    ? ((string)($order['buyer_email'] ?? '') ?: '—')
    : $peer;
$buyerHandle = '@' . preg_replace('/[^a-z0-9_]/i', '', strtolower(explode('@', $buyerName)[0] ?: 'buyer'));
if ($who !== 'Seller' && strpos($peer, '@') !== false) {
    $buyerHandle = $peer;
    $buyerEmail = $peer;
}
$sellerName = (string)($order['org_name'] ?? $order['seller_username'] ?? '');
if ($sellerName === '') {
    $sellerName = $who === 'Seller' ? (explode('@', $peer)[0] ?: 'Seller') : 'Shop';
}
$sellerEmail = (string)($order['seller_user_email'] ?? '');
if ($sellerEmail === '' && $who === 'Seller') {
    $sellerEmail = $peer;
}
$sellerHandle = '@' . preg_replace('/[^a-z0-9_]/i', '', strtolower(explode('@', $sellerName)[0] ?: 'seller'));
if ($who === 'Seller') {
    $sellerHandle = $peer;
}

$productTitle = (string)($order['product_title'] ?? $order['product_name'] ?? '');
if ($productTitle === '') {
    $productTitle = 'Item in dispute';
}
$productCover = (string)($order['product_cover'] ?? '');
$productSku = (string)($order['product_code'] ?? '—');
$productCat = (string)($order['product_category'] ?? '—');
$productSize = (string)($order['product_size'] ?? '—');
$productColor = (string)($order['product_color'] ?? '—');
if (($productSize === '—' || $productColor === '—') && !empty($order['product_attrs'])) {
    $attrs = json_decode((string)$order['product_attrs'], true);
    if (is_array($attrs)) {
        if ($productSize === '—' && !empty($attrs['size'])) {
            $productSize = (string)$attrs['size'];
        }
        if ($productColor === '—' && !empty($attrs['color'])) {
            $productColor = (string)$attrs['color'];
        }
    }
}
$productQty = (int)($order['quantity'] ?? 1);
$productPrice = isset($order['unit_price'])
    ? ('$' . number_format((float)$order['unit_price'], 2) . ' USD')
    : $amount;

$orderDate = (string)($order['created_at'] ?? $firstTime);
$paymentMethod = (string)($order['payment_method'] ?? '—');
$paymentStatus = (string)($order['payment_status'] ?? $order['order_status'] ?? '—');
$shippingAddr = trim((string)($order['shipping_address'] ?? ''));
$orderViewHref = !empty($order['id']) ? ('open_order_detail.php?id=' . (int)$order['id']) : '';

$dueTs = $firstTime !== '' ? (strtotime($firstTime) + 7 * 86400) : false;
$dueLabel = $dueTs ? date('M d, Y', $dueTs) : '—';
$overdueDays = 0;
if ($dueTs && time() > $dueTs && in_array($status, ['open', 'awaiting'], true)) {
    $overdueDays = (int)floor((time() - $dueTs) / 86400);
}

$statusLabels = [
    'open' => 'Open',
    'awaiting' => 'Awaiting Response',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];
$priorityLabels = [
    'high' => 'High',
    'medium' => 'Medium',
    'low' => 'Low',
];

$activity = [];
$activity[] = ['icon' => 'fa-flag', 'tone' => 'blue', 'title' => 'Dispute opened', 'sub' => 'Buyer opened this dispute', 'when' => $firstTime];
if ($evidence) {
    $activity[] = ['icon' => 'fa-paperclip', 'tone' => 'purple', 'title' => 'Evidence uploaded by buyer', 'sub' => count($evidence) . ' file(s) attached', 'when' => $evidence[0]['when'] ?? $firstTime];
}
if ($buyerMsgs) {
    $activity[] = ['icon' => 'fa-bell', 'tone' => 'orange', 'title' => 'Seller notified', 'sub' => 'Notification sent to seller', 'when' => (string)($buyerMsgs[0]['created_at'] ?? $firstTime)];
}
if ($sellerMsgs) {
    $activity[] = ['icon' => 'fa-reply', 'tone' => 'green', 'title' => 'Seller responded', 'sub' => dispute_preview($sellerResponse, 48), 'when' => $sellerRespondedAt];
}
if ($adminNotes) {
    $lastNote = $adminNotes[count($adminNotes) - 1];
    $activity[] = ['icon' => 'fa-sticky-note', 'tone' => 'gray', 'title' => 'Admin note added', 'sub' => dispute_preview((string)($lastNote['feedbackdata'] ?? ''), 48), 'when' => (string)($lastNote['created_at'] ?? '')];
}

$detailQs = 'lane=' . rawurlencode($lane) . '&filter=' . rawurlencode($filter);
$detailBase = static function (int $id = 0, string $peerKey = '') use ($detailQs, $disputeId, $peer): string {
    $id = $id > 0 ? $id : $disputeId;
    if ($id > 0) {
        return 'dispute_detail.php?id=' . $id . '&' . $detailQs;
    }
    $peerKey = $peerKey !== '' ? $peerKey : $peer;
    return 'dispute_detail.php?peer=' . rawurlencode($peerKey) . '&' . $detailQs;
};
$tabHref = static function (string $t) use ($detailBase): string {
    return $detailBase() . '&tab=' . rawurlencode($t);
};
$prevHref = ($prevId > 0 || $prevPeer !== '') ? ($detailBase($prevId, $prevPeer)) : '';
$nextHref = ($nextId > 0 || $nextPeer !== '') ? ($detailBase($nextId, $nextPeer)) : '';
$markHref = $detailBase() . '&mark=1&tab=' . rawurlencode($tab);

$adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'Admin');
$adminIni = dispute_initials($adminName);

org_admin_render_head('Dispute Details');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Dispute Details',
    'description' => 'Review and resolve this buyer / seller dispute.',
]);
?>

<style>
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
  }
  .dd-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;}
  .dd-back{flex:0 0 auto;font-size:12px;font-weight:700;color:#64748b;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
  .dd-back:hover{color:#2563eb;text-decoration:none;}
  .dd-hero{flex:0 0 auto;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
  .dd-hero h1{margin:0;font-size:22px;font-weight:800;color:#0f172a;line-height:1.2;}
  .dd-hero-meta{margin-top:6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:#64748b;font-weight:600;}
  .dd-hero-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .dd-btn{height:34px;padding:0 12px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:12px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:6px;text-decoration:none;cursor:pointer;}
  .dd-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .dd-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .dd-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .dd-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;}
  .dd-pill .dot{width:6px;height:6px;border-radius:999px;background:currentColor;}
  .dd-pill.open{background:#fef3c7;color:#b45309;}
  .dd-pill.awaiting{background:#f3e8ff;color:#7c3aed;}
  .dd-pill.resolved{background:#dcfce7;color:#15803d;}
  .dd-pill.closed{background:#f1f5f9;color:#64748b;}
  .dd-pill.high{background:#fee2e2;color:#b91c1c;}
  .dd-pill.medium{background:#ffedd5;color:#c2410c;}
  .dd-pill.low{background:#dbeafe;color:#1d4ed8;}
  .dd-pill.ok{background:#dcfce7;color:#15803d;}
  .dd-tabs{flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:0 6px;overflow:auto;}
  .dd-tabs a{flex:0 0 auto;padding:10px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;}
  .dd-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .dd-tabs a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .dd-board{flex:1 1 auto;min-height:0;display:grid;grid-template-columns:minmax(0,1.7fr) minmax(280px,.9fr);gap:10px;overflow:hidden;}
  .dd-main,.dd-side{min-height:0;min-width:0;overflow:auto;display:flex;flex-direction:column;gap:10px;}
  .dd-card{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:14px 16px;box-shadow:0 1px 2px rgba(15,23,42,.03);}
  .dd-card h2{margin:0 0 12px;font-size:14px;font-weight:800;color:#0f172a;display:flex;align-items:center;justify-content:space-between;gap:8px;}
  .dd-card h3{margin:0 0 8px;font-size:12px;font-weight:800;color:#0f172a;}
  .dd-parties{display:grid;grid-template-columns:1fr auto 1fr auto 1fr;gap:10px;align-items:center;margin-bottom:14px;}
  .dd-person{display:flex;align-items:center;gap:10px;min-width:0;}
  .dd-av{width:40px;height:40px;border-radius:999px;color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 40px;}
  .dd-name{font-weight:800;font-size:13px;color:#0f172a;}
  .dd-sub{font-size:11px;color:#64748b;font-weight:600;}
  .dd-link{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;}
  .dd-link:hover{text-decoration:underline;}
  .dd-order-mid{text-align:center;padding:8px 10px;border:1px dashed #e2e8f0;border-radius:10px;background:#f8fafc;min-width:120px;}
  .dd-order-mid .oid{font-weight:800;font-size:12px;color:#0f172a;}
  .dd-arrow{color:#94a3b8;font-size:14px;text-align:center;}
  .dd-grid3{display:grid;grid-template-columns:1.4fr .8fr .7fr;gap:12px;}
  .dd-k{font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;}
  .dd-v{font-size:13px;font-weight:800;color:#0f172a;margin-top:3px;}
  .dd-desc{font-size:12px;color:#475569;line-height:1.45;margin-top:4px;}
  .dd-item{display:flex;gap:12px;align-items:flex-start;}
  .dd-thumb{width:72px;height:72px;border-radius:10px;object-fit:cover;background:#f1f5f9;border:1px solid #e2e8f0;flex:0 0 72px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:22px;overflow:hidden;}
  .dd-thumb img{width:100%;height:100%;object-fit:cover;}
  .dd-item-meta{display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;margin-top:6px;font-size:11px;color:#64748b;}
  .dd-item-meta b{color:#0f172a;font-weight:700;}
  .dd-item-right{margin-left:auto;text-align:right;flex:0 0 auto;}
  .dd-ev{display:flex;gap:10px;overflow:auto;padding-bottom:4px;}
  .dd-ev-card{flex:0 0 140px;border:1px solid #eef2f7;border-radius:10px;overflow:hidden;background:#fafbfc;}
  .dd-ev-card .pic{height:88px;background:#e2e8f0;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:20px;overflow:hidden;}
  .dd-ev-card .pic img{width:100%;height:100%;object-fit:cover;}
  .dd-ev-card .cap{padding:6px 8px;font-size:10px;font-weight:700;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .dd-seller-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;}
  .dd-field{margin-bottom:12px;}
  .dd-field label{display:block;font-size:11px;font-weight:800;color:#64748b;margin-bottom:4px;}
  .dd-field select,.dd-field input,.dd-field textarea{width:100%;height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;background:#fff;color:#0f172a;box-sizing:border-box;}
  .dd-field textarea{height:auto;min-height:90px;padding:8px 10px;resize:vertical;}
  .dd-assign{display:flex;align-items:center;gap:8px;}
  .dd-assign .chg{margin-left:auto;font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;}
  .dd-overdue{color:#b91c1c;font-size:11px;font-weight:800;margin-top:4px;}
  .dd-timeline{list-style:none;margin:0;padding:0;}
  .dd-timeline li{display:flex;gap:10px;padding:0 0 14px;position:relative;}
  .dd-timeline li:last-child{padding-bottom:0;}
  .dd-timeline li:not(:last-child)::before{content:'';position:absolute;left:13px;top:28px;bottom:0;width:2px;background:#e2e8f0;}
  .dd-ticon{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:11px;flex:0 0 28px;position:relative;z-index:1;}
  .dd-ticon.blue{background:#dbeafe;color:#2563eb;}
  .dd-ticon.purple{background:#f3e8ff;color:#7c3aed;}
  .dd-ticon.orange{background:#ffedd5;color:#ea580c;}
  .dd-ticon.green{background:#dcfce7;color:#16a34a;}
  .dd-ticon.gray{background:#f1f5f9;color:#64748b;}
  .dd-ttitle{font-size:12px;font-weight:800;color:#0f172a;}
  .dd-tsub{font-size:11px;color:#64748b;margin-top:1px;}
  .dd-twhen{font-size:10px;color:#94a3b8;margin-top:2px;font-weight:600;}
  .dd-kv{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12px;}
  .dd-kv:last-child{border-bottom:0;}
  .dd-kv .k{color:#64748b;font-weight:700;}
  .dd-kv .v{color:#0f172a;font-weight:800;text-align:right;max-width:62%;}
  .dd-chat{display:flex;flex-direction:column;gap:10px;}
  .dd-bubble{max-width:92%;padding:10px 12px;border-radius:12px;font-size:12px;line-height:1.4;white-space:pre-wrap;}
  .dd-bubble.them{align-self:flex-start;background:#f8fafc;border:1px solid #e2e8f0;color:#0f172a;}
  .dd-bubble.me{align-self:flex-end;background:#2563eb;border:1px solid #2563eb;color:#fff;}
  .dd-bubble .meta{font-size:10px;opacity:.8;margin-top:4px;font-weight:700;}
  .dd-alert{padding:8px 10px;border-radius:8px;font-size:12px;font-weight:700;}
  .dd-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .dd-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .dd-drop{position:relative;}
  .dd-drop-menu{display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:40;min-width:180px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 24px rgba(15,23,42,.12);padding:4px;}
  .dd-drop.open .dd-drop-menu{display:block;}
  .dd-drop-menu a,.dd-drop-menu button{display:block;width:100%;text-align:left;padding:8px 10px;border:0;background:transparent;border-radius:7px;font-size:12px;font-weight:700;color:#334155;text-decoration:none;cursor:pointer;}
  .dd-drop-menu a:hover,.dd-drop-menu button:hover{background:#f8fafc;}
  .dd-empty{padding:18px 8px;text-align:center;color:#64748b;font-size:12px;}
  .dd-panel{display:none;}
  .dd-panel.is-active{display:contents;}
  .dd-side-only .dd-main{display:none;}
  @media (max-width:1100px){
    .dd-wrap{overflow:auto;}
    .dd-board{grid-template-columns:1fr;overflow:visible;}
    .dd-parties{grid-template-columns:1fr; }
    .dd-arrow{display:none;}
    .dd-grid3{grid-template-columns:1fr;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="dd-wrap">
      <a class="dd-back" href="<?= dispute_h($listHref) ?>"><i class="fa fa-arrow-left"></i> Back to Disputes</a>

      <?php if ($error !== ''): ?><div class="dd-alert bad"><?= dispute_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="dd-alert ok"><?= dispute_h($msg) ?></div><?php endif; ?>

      <div class="dd-hero">
        <div>
          <h1>Dispute Details</h1>
          <div class="dd-hero-meta">
            <span class="dd-pill <?= dispute_h($status) ?>"><span class="dot"></span><?= dispute_h($statusLabels[$status] ?? ucfirst($status)) ?></span>
            <span>Dispute ID: <b style="color:#0f172a;"><?= dispute_h($dspId) ?></b></span>
            <span>Created: <?= dispute_h(dispute_fmt($firstTime !== '' ? $firstTime : null)) ?></span>
            <?php if ($navPos > 0): ?><span><?= (int)$navPos ?> / <?= (int)$navTotal ?></span><?php endif; ?>
          </div>
        </div>
        <div class="dd-hero-actions">
          <div class="dd-drop" id="ddMoreDrop">
            <button type="button" class="dd-btn" onclick="document.getElementById('ddMoreDrop').classList.toggle('open')">More Actions <i class="fa fa-caret-down"></i></button>
            <div class="dd-drop-menu">
              <?php if ($unread > 0): ?>
                <a href="<?= dispute_h($markHref) ?>"><i class="fa fa-check"></i> Mark read</a>
              <?php endif; ?>
              <a href="<?= $prevHref !== '' ? dispute_h($prevHref) : '#' ?>" <?= $prevHref === '' ? 'style="opacity:.45;pointer-events:none;"' : '' ?>><i class="fa fa-chevron-left"></i> Previous dispute</a>
              <a href="<?= $nextHref !== '' ? dispute_h($nextHref) : '#' ?>" <?= $nextHref === '' ? 'style="opacity:.45;pointer-events:none;"' : '' ?>>Next dispute <i class="fa fa-chevron-right"></i></a>
              <a href="<?= dispute_h($listHref) ?>"><i class="fa fa-list"></i> All disputes</a>
              <a href="<?= dispute_h($tabHref('messages')) ?>"><i class="fa fa-reply"></i> Reply in Messages</a>
            </div>
          </div>
          <button type="submit" form="ddStatusForm" class="dd-btn primary"><i class="fa fa-refresh"></i> Update Status</button>
        </div>
      </div>

      <nav class="dd-tabs" aria-label="Dispute sections">
        <a href="<?= dispute_h($tabHref('overview')) ?>" class="<?= $tab === 'overview' ? 'is-active' : '' ?>">Overview</a>
        <a href="<?= dispute_h($tabHref('messages')) ?>" class="<?= $tab === 'messages' ? 'is-active' : '' ?>">Messages<span class="cnt">(<?= (int)$msgCount ?>)</span></a>
        <a href="<?= dispute_h($tabHref('timeline')) ?>" class="<?= $tab === 'timeline' ? 'is-active' : '' ?>">Timeline</a>
        <a href="<?= dispute_h($tabHref('evidence')) ?>" class="<?= $tab === 'evidence' ? 'is-active' : '' ?>">Evidence<span class="cnt">(<?= (int)count($evidence) ?>)</span></a>
        <a href="<?= dispute_h($tabHref('notes')) ?>" class="<?= $tab === 'notes' ? 'is-active' : '' ?>">Notes<span class="cnt">(<?= (int)count($adminNotes) ?>)</span></a>
      </nav>

      <div class="dd-board">
        <div class="dd-main">

          <?php if ($tab === 'overview'): ?>
          <section class="dd-card">
            <h2>Dispute Summary</h2>
            <div class="dd-parties">
              <div>
                <div class="dd-k" style="margin-bottom:6px;">Buyer</div>
                <div class="dd-person">
                  <span class="dd-av" style="background:<?= dispute_h(dispute_avatar_color($buyerEmail . $buyerName)) ?>;"><?= dispute_h(dispute_initials($buyerName)) ?></span>
                  <div style="min-width:0;">
                    <div class="dd-name"><?= dispute_h(ucwords(str_replace(['.', '_'], ' ', $buyerName))) ?></div>
                    <div class="dd-sub"><?= dispute_h($buyerHandle) ?></div>
                    <div class="dd-sub"><?= dispute_h($buyerEmail) ?></div>
                    <?php if ($who !== 'Seller'): ?>
                      <a class="dd-link" href="userlist.php?q=<?= rawurlencode($peer) ?>">View Profile</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="dd-arrow"><i class="fa fa-long-arrow-right"></i></div>
              <div class="dd-order-mid">
                <div class="dd-k">Order</div>
                <div class="oid"><?= dispute_h($orderCode !== '' ? $orderCode : '—') ?></div>
                <div class="dd-sub"><?= dispute_h(dispute_fmt_short($orderDate !== '' ? $orderDate : null)) ?></div>
                <div class="dd-sub" style="font-weight:800;color:#0f172a;"><?= dispute_h($amount) ?></div>
                <?php if ($orderViewHref !== ''): ?>
                  <a class="dd-link" href="<?= dispute_h($orderViewHref) ?>">View Order</a>
                <?php endif; ?>
              </div>
              <div class="dd-arrow"><i class="fa fa-long-arrow-right"></i></div>
              <div>
                <div class="dd-k" style="margin-bottom:6px;">Seller</div>
                <div class="dd-person">
                  <span class="dd-av" style="background:<?= dispute_h(dispute_avatar_color($sellerEmail . $sellerName)) ?>;"><?= dispute_h(dispute_initials($sellerName)) ?></span>
                  <div style="min-width:0;">
                    <div class="dd-name"><?= dispute_h(ucwords(str_replace(['.', '_'], ' ', $sellerName))) ?></div>
                    <div class="dd-sub"><?= dispute_h($sellerHandle) ?></div>
                    <div class="dd-sub"><?= dispute_h($sellerEmail !== '' ? $sellerEmail : '—') ?></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="dd-grid3">
              <div>
                <div class="dd-k">Reason</div>
                <div class="dd-v"><?= dispute_h($reasonTitle) ?></div>
                <div class="dd-desc"><?= dispute_h(dispute_preview($buyerDesc !== '' ? $buyerDesc : (string)($threadMeta['last_message'] ?? ''), 140)) ?></div>
              </div>
              <div>
                <div class="dd-k">Amount</div>
                <div class="dd-v"><?= dispute_h($amount) ?></div>
              </div>
              <div>
                <div class="dd-k">Priority</div>
                <div class="dd-v"><span class="dd-pill <?= dispute_h($priority) ?>"><span class="dot"></span><?= dispute_h($priorityLabels[$priority] ?? ucfirst($priority)) ?></span></div>
              </div>
            </div>
          </section>

          <section class="dd-card">
            <h2>Item Information</h2>
            <div class="dd-item">
              <div class="dd-thumb">
                <?php if ($productCover !== ''): ?>
                  <img src="<?= dispute_h((strpos($productCover, 'http') === 0 || strpos($productCover, '/') === 0) ? $productCover : ('../' . ltrim($productCover, '/'))) ?>" alt="">
                <?php else: ?>
                  <i class="fa fa-cube"></i>
                <?php endif; ?>
              </div>
              <div style="min-width:0;flex:1 1 auto;">
                <div class="dd-name"><?= dispute_h($productTitle) ?></div>
                <div class="dd-item-meta">
                  <div>Size: <b><?= dispute_h($productSize) ?></b></div>
                  <div>Color: <b><?= dispute_h($productColor) ?></b></div>
                  <div>SKU: <b><?= dispute_h($productSku) ?></b></div>
                  <div>Category: <b><?= dispute_h($productCat) ?></b></div>
                </div>
              </div>
              <div class="dd-item-right">
                <div class="dd-k">Quantity</div>
                <div class="dd-v"><?= (int)$productQty ?></div>
                <div class="dd-k" style="margin-top:8px;">Price</div>
                <div class="dd-v"><?= dispute_h($productPrice) ?></div>
              </div>
            </div>
          </section>

          <section class="dd-card">
            <h2>Description from Buyer</h2>
            <div class="dd-desc" style="font-size:13px;color:#334155;">
              <?= dispute_h($buyerDesc !== '' ? $buyerDesc : 'No buyer description provided.') ?>
            </div>
          </section>

          <section class="dd-card">
            <h2>Evidence from Buyer <span class="dd-sub" style="font-weight:700;"><?= (int)count($evidence) ?> file(s)</span></h2>
            <?php if (!$evidence): ?>
              <div class="dd-empty">No evidence attachments on this thread yet.</div>
            <?php else: ?>
              <div class="dd-ev">
                <?php foreach ($evidence as $ev):
                    $path = (string)$ev['path'];
                    $href = (strpos($path, 'http') === 0 || strpos($path, '/') === 0) ? $path : ('../' . ltrim($path, '/'));
                    $isImg = (bool)preg_match('/\.(png|jpe?g|gif|webp)$/i', $path);
                ?>
                  <a class="dd-ev-card" href="<?= dispute_h($href) ?>" target="_blank" rel="noopener">
                    <div class="pic">
                      <?php if ($isImg): ?>
                        <img src="<?= dispute_h($href) ?>" alt="">
                      <?php else: ?>
                        <i class="fa fa-file-o"></i>
                      <?php endif; ?>
                    </div>
                    <div class="cap" title="<?= dispute_h((string)$ev['name']) ?>"><?= dispute_h((string)$ev['name']) ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section class="dd-card">
            <h2>
              Seller Response
              <?php if ($sellerResponse !== ''): ?>
                <span class="dd-pill ok"><span class="dot"></span>Responded</span>
              <?php else: ?>
                <span class="dd-pill closed"><span class="dot"></span>Pending</span>
              <?php endif; ?>
            </h2>
            <div class="dd-desc" style="font-size:13px;color:#334155;">
              <?= dispute_h($sellerResponse !== '' ? $sellerResponse : 'Seller has not responded yet.') ?>
            </div>
            <?php if ($sellerResponse !== ''): ?>
              <div class="dd-seller-foot">
                <span class="dd-sub">Responded on <?= dispute_h(dispute_fmt($sellerRespondedAt !== '' ? $sellerRespondedAt : null)) ?></span>
                <a class="dd-link" href="<?= dispute_h($tabHref('messages')) ?>">View Response</a>
              </div>
            <?php endif; ?>
          </section>
          <?php endif; ?>

          <?php if ($tab === 'messages'): ?>
          <section class="dd-card" style="flex:1 1 auto;display:flex;flex-direction:column;min-height:280px;">
            <h2>Messages</h2>
            <div style="flex:1 1 auto;overflow:auto;min-height:160px;margin-bottom:12px;" id="ddChatBody">
              <?php if (!$history): ?>
                <div class="dd-empty">No messages in this dispute thread.</div>
              <?php else: ?>
                <div class="dd-chat">
                  <?php foreach ($history as $row):
                    $fromAdmin = strcasecmp((string)($row['sender'] ?? ''), 'Admin') === 0;
                    $body = (string)($row['feedbackdata'] ?? '');
                  ?>
                    <div class="dd-bubble <?= $fromAdmin ? 'me' : 'them' ?>">
                      <?= dispute_h($body) ?>
                      <div class="meta"><?= dispute_h($fromAdmin ? 'Admin' : $peer) ?> · <?= dispute_h(dispute_fmt($row['created_at'] ?? null)) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
            <form method="post" autocomplete="off">
              <input type="hidden" name="reply_peer" value="<?= dispute_h($peer) ?>">
              <input type="hidden" name="id" value="<?= (int)$disputeId ?>">
              <input type="hidden" name="lane" value="<?= dispute_h($lane) ?>">
              <input type="hidden" name="filter" value="<?= dispute_h($filter) ?>">
              <div class="dd-field">
                <label>Reply</label>
                <textarea name="reply_text" placeholder="Reply to this dispute…" required></textarea>
              </div>
              <div style="display:flex;justify-content:flex-end;gap:8px;">
                <?php if ($unread > 0): ?>
                  <a class="dd-btn" href="<?= dispute_h($detailBase() . '&mark=1&tab=messages') ?>">Mark read</a>
                <?php endif; ?>
                <button type="submit" class="dd-btn primary"><i class="fa fa-paper-plane"></i> Send reply</button>
              </div>
            </form>
          </section>
          <?php endif; ?>

          <?php if ($tab === 'timeline'): ?>
          <section class="dd-card">
            <h2>Timeline</h2>
            <ul class="dd-timeline">
              <?php foreach ($activity as $a): ?>
                <li>
                  <span class="dd-ticon <?= dispute_h($a['tone']) ?>"><i class="fa <?= dispute_h($a['icon']) ?>"></i></span>
                  <div>
                    <div class="dd-ttitle"><?= dispute_h($a['title']) ?></div>
                    <div class="dd-tsub"><?= dispute_h($a['sub']) ?></div>
                    <div class="dd-twhen"><?= dispute_h(dispute_fmt($a['when'] !== '' ? $a['when'] : null)) ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
          <?php endif; ?>

          <?php if ($tab === 'evidence'): ?>
          <section class="dd-card">
            <h2>Evidence <span class="dd-sub"><?= (int)count($evidence) ?> file(s)</span></h2>
            <?php if (!$evidence): ?>
              <div class="dd-empty">No evidence attachments found on this thread.</div>
            <?php else: ?>
              <div class="dd-ev" style="flex-wrap:wrap;">
                <?php foreach ($evidence as $ev):
                    $path = (string)$ev['path'];
                    $href = (strpos($path, 'http') === 0 || strpos($path, '/') === 0) ? $path : ('../' . ltrim($path, '/'));
                    $isImg = (bool)preg_match('/\.(png|jpe?g|gif|webp)$/i', $path);
                ?>
                  <a class="dd-ev-card" href="<?= dispute_h($href) ?>" target="_blank" rel="noopener">
                    <div class="pic">
                      <?php if ($isImg): ?><img src="<?= dispute_h($href) ?>" alt=""><?php else: ?><i class="fa fa-file-o"></i><?php endif; ?>
                    </div>
                    <div class="cap"><?= dispute_h((string)$ev['name']) ?></div>
                    <div class="cap" style="color:#94a3b8;padding-top:0;"><?= dispute_h(dispute_fmt($ev['when'] !== '' ? $ev['when'] : null)) ?> · <?= dispute_h((string)$ev['who']) ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
          <?php endif; ?>

          <?php if ($tab === 'notes'): ?>
          <section class="dd-card">
            <h2>Internal Notes</h2>
            <?php if (!$adminNotes): ?>
              <div class="dd-empty" style="margin-bottom:12px;">No internal notes yet.</div>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px;">
                <?php foreach ($adminNotes as $n): ?>
                  <div style="border:1px solid #eef2f7;border-radius:10px;padding:10px 12px;background:#fafbfc;">
                    <div class="dd-desc" style="color:#334155;"><?= dispute_h((string)($n['feedbackdata'] ?? '')) ?></div>
                    <div class="dd-twhen" style="margin-top:6px;"><?= dispute_h(dispute_fmt($n['created_at'] ?? null)) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
              <input type="hidden" name="peer" value="<?= dispute_h($peer) ?>">
              <input type="hidden" name="id" value="<?= (int)$disputeId ?>">
              <input type="hidden" name="lane" value="<?= dispute_h($lane) ?>">
              <input type="hidden" name="filter" value="<?= dispute_h($filter) ?>">
              <div class="dd-field">
                <label>Add note</label>
                <textarea name="note_text" placeholder="Internal note for admins…" required></textarea>
              </div>
              <div style="display:flex;justify-content:flex-end;">
                <button type="submit" class="dd-btn primary">Save note</button>
              </div>
            </form>
          </section>
          <?php endif; ?>

        </div>

        <aside class="dd-side">
          <section class="dd-card">
            <h2>Status &amp; Assignment</h2>
            <form method="post" id="ddStatusForm" autocomplete="off">
              <input type="hidden" name="update_status" value="1">
              <input type="hidden" name="peer" value="<?= dispute_h($peer) ?>">
              <input type="hidden" name="id" value="<?= (int)$disputeId ?>">
              <input type="hidden" name="lane" value="<?= dispute_h($lane) ?>">
              <input type="hidden" name="filter" value="<?= dispute_h($filter) ?>">
              <div class="dd-field">
                <label>Status</label>
                <select name="status">
                  <?php foreach ($statusLabels as $k => $lab): ?>
                    <option value="<?= dispute_h($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= dispute_h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </form>
            <div class="dd-field">
              <label>Assigned To</label>
              <div class="dd-assign">
                <span class="dd-av" style="width:32px;height:32px;flex-basis:32px;font-size:10px;background:<?= dispute_h(dispute_avatar_color($adminName)) ?>;"><?= dispute_h($adminIni) ?></span>
                <div>
                  <div class="dd-name" style="font-size:12px;"><?= dispute_h(ucwords(str_replace(['.', '_'], ' ', $adminName))) ?></div>
                  <div class="dd-sub">Support Agent</div>
                </div>
                <span class="chg" title="Assignment coming soon">Change</span>
              </div>
            </div>
            <div class="dd-field" style="margin-bottom:0;">
              <label>Due Date</label>
              <input type="text" value="<?= dispute_h($dueLabel) ?>" readonly>
              <?php if ($overdueDays > 0): ?>
                <div class="dd-overdue">Overdue by <?= (int)$overdueDays ?> day<?= $overdueDays === 1 ? '' : 's' ?></div>
              <?php endif; ?>
            </div>
          </section>

          <section class="dd-card">
            <h2>Dispute Activity</h2>
            <ul class="dd-timeline">
              <?php foreach (array_slice($activity, 0, 6) as $a): ?>
                <li>
                  <span class="dd-ticon <?= dispute_h($a['tone']) ?>"><i class="fa <?= dispute_h($a['icon']) ?>"></i></span>
                  <div>
                    <div class="dd-ttitle"><?= dispute_h($a['title']) ?></div>
                    <div class="dd-tsub"><?= dispute_h($a['sub']) ?></div>
                    <div class="dd-twhen"><?= dispute_h(dispute_fmt($a['when'] !== '' ? $a['when'] : null)) ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>

          <section class="dd-card">
            <h2>Order Summary</h2>
            <div class="dd-kv"><span class="k">Order ID</span><span class="v"><?php if ($orderViewHref !== ''): ?><a class="dd-link" href="<?= dispute_h($orderViewHref) ?>"><?= dispute_h($orderCode !== '' ? $orderCode : '—') ?></a><?php else: ?><?= dispute_h($orderCode !== '' ? $orderCode : '—') ?><?php endif; ?></span></div>
            <div class="dd-kv"><span class="k">Order Date</span><span class="v"><?= dispute_h(dispute_fmt_short($orderDate !== '' ? $orderDate : null)) ?></span></div>
            <div class="dd-kv"><span class="k">Payment Method</span><span class="v"><?= dispute_h($paymentMethod !== '' ? $paymentMethod : '—') ?></span></div>
            <div class="dd-kv"><span class="k">Payment Status</span><span class="v"><span class="dd-pill ok"><span class="dot"></span><?= dispute_h($paymentStatus !== '' ? ucfirst($paymentStatus) : '—') ?></span></span></div>
            <div class="dd-kv" style="align-items:flex-start;"><span class="k">Shipping Address</span><span class="v" style="white-space:pre-wrap;font-weight:600;"><?= dispute_h($shippingAddr !== '' ? $shippingAddr : '—') ?></span></div>
          </section>
        </aside>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('click', function(e){
  var drop = document.getElementById('ddMoreDrop');
  if (!drop) return;
  if (!drop.contains(e.target)) drop.classList.remove('open');
});
(function(){
  var body = document.getElementById('ddChatBody');
  if (body) body.scrollTop = body.scrollHeight;
})();
</script>
<?php org_admin_render_foot(); ?>
