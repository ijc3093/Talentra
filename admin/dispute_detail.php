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

function dispute_fmt_time(?string $dt): string
{
    return $dt ? date('g:i A', strtotime($dt)) : '';
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

/** Plain chat text for bubbles (strip XML/HTML leftovers; keep readable). */
function dispute_plain_display(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/^<\?xml[^>]*\?>/i', '', $s) ?? $s;
    $s = preg_replace('/^<!DOCTYPE[^>]*>/i', '', $s) ?? $s;
    $s = trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $s = preg_replace('/^\[(?:Seller )?dispute\]\s*/i', '', $s) ?? $s;
    $s = preg_replace('/^\[Admin dispute\]\s*/i', '', $s) ?? $s;
    $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;
    $s = preg_replace("/\n{2,}/", "\n", $s) ?? $s;
    return trim($s, " \t\n\r\0\x0B");
}

/**
 * Strip product/dispute context lines already shown on the header product card.
 */
function dispute_plain_for_chat(string $raw, bool $hasProductCard = false): string
{
    $s = dispute_plain_display($raw);
    if ($s === '') {
        return '';
    }
    // Drop trailing duplicated context blocks after a horizontal rule / separator.
    $s = preg_replace('/\n[-–—_]{3,}\s*\n[\s\S]*$/u', '', $s) ?? $s;
    if ($hasProductCard) {
        $lines = preg_split('/\R/u', $s) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $t = trim((string)$line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^(Seller business|Product ID\s*#|Product:|Dispute ID:)\s*/i', $t)) {
                continue;
            }
            if (preg_match('/^Please review this product report/i', $t)) {
                continue;
            }
            $kept[] = $t;
        }
        $s = trim(implode("\n", $kept));
    }
    return $s;
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

/* Product card + Product ID history for Messages (Admin ↔ customer) */
$ddMsgProductFocus = null;
$ddMsgProductHistory = [];
$ddBuyerUserId = 0;
if ($who !== 'Seller' && strpos($peer, '@') !== false) {
    try {
        $stBuyer = $dbh->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:e) AND status = 1 LIMIT 1');
        $stBuyer->execute([':e' => $peer]);
        $ddBuyerUserId = (int)($stBuyer->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $ddBuyerUserId = 0;
    }
}
if ($ddBuyerUserId > 0) {
    require_once __DIR__ . '/../public_user/includes/commerce_disputes.php';
    require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';
    require_once __DIR__ . '/../public_user/includes/org_shop.php';
    if (function_exists('commerce_dispute_list_for_buyer')) {
        $buyerCases = commerce_dispute_list_for_buyer($dbh, $ddBuyerUserId, 40);
        $byPid = [];
        foreach ($buyerCases as $c) {
            $pid = (int)($c['product_id'] ?? 0);
            if ($pid <= 0 || isset($byPid[$pid])) {
                continue;
            }
            $byPid[$pid] = [
                'product_id' => $pid,
                'product_title' => (string)($c['product_title'] ?? ('Product #' . $pid)),
                'product_cover' => (string)($c['product_cover'] ?? ''),
                'code' => (string)($c['code'] ?? ''),
                'time_label' => (string)($c['time_label'] ?? ''),
                'seller_business' => (string)($c['seller_business'] ?? ''),
            ];
        }
        $ddMsgProductHistory = array_values($byPid);
    }
    $openCase = function_exists('commerce_dispute_buyer_latest_open_case')
        ? commerce_dispute_buyer_latest_open_case($dbh, $ddBuyerUserId)
        : null;
    if (is_array($openCase)) {
        $opid = (int)($openCase['product_id'] ?? 0);
        if ($opid > 0 && function_exists('commerce_messaging_product_focus')) {
            $ddMsgProductFocus = commerce_messaging_product_focus($dbh, $opid);
            if (is_array($ddMsgProductFocus)) {
                $ddMsgProductFocus['seller_business'] = trim((string)($openCase['seller_business_name'] ?? ''));
            }
        }
        if (!is_array($ddMsgProductFocus) && $opid > 0) {
            $ddMsgProductFocus = [
                'id' => $opid,
                'title' => trim((string)($openCase['product_title'] ?? ('Product #' . $opid))),
                'code' => '',
                'cover' => function_exists('org_shop_cover_url')
                    ? org_shop_cover_url((string)($openCase['product_cover_path'] ?? ''))
                    : '',
                'buyer_href' => '../public_user/product_detail.php?id=' . $opid,
                'seller_business' => trim((string)($openCase['seller_business_name'] ?? '')),
            ];
        }
    }
}
// Fallback from order / message Product ID #N
if (!is_array($ddMsgProductFocus)) {
    $opid = (int)($order['product_id'] ?? 0);
    if ($opid <= 0) {
        foreach ($history as $hr) {
            if (preg_match('/Product\s*ID\s*#\s*(\d+)\b/i', (string)($hr['feedbackdata'] ?? ''), $mPid)) {
                $opid = (int)$mPid[1];
                break;
            }
        }
    }
    if ($opid > 0) {
        if (!function_exists('commerce_messaging_product_focus')) {
            require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';
            require_once __DIR__ . '/../public_user/includes/org_shop.php';
        }
        $ddMsgProductFocus = function_exists('commerce_messaging_product_focus')
            ? commerce_messaging_product_focus($dbh, $opid)
            : null;
        if (!is_array($ddMsgProductFocus)) {
            $coverPath = (string)($order['product_cover'] ?? '');
            $ddMsgProductFocus = [
                'id' => $opid,
                'title' => $productTitle !== '' && $productTitle !== 'Item in dispute' ? $productTitle : ('Product #' . $opid),
                'code' => $productSku !== '—' ? $productSku : '',
                'cover' => (strpos($coverPath, 'http') === 0 || strpos($coverPath, '/') === 0)
                    ? $coverPath
                    : ($coverPath !== '' ? ('../' . ltrim($coverPath, '/')) : ''),
                'buyer_href' => '../public_user/product_detail.php?id=' . $opid,
                'seller_business' => $sellerName !== 'Shop' ? $sellerName : '',
            ];
        }
    }
}
if (!$ddMsgProductHistory && is_array($ddMsgProductFocus) && (int)($ddMsgProductFocus['id'] ?? 0) > 0) {
    $ddMsgProductHistory[] = [
        'product_id' => (int)$ddMsgProductFocus['id'],
        'product_title' => (string)($ddMsgProductFocus['title'] ?? ''),
        'product_cover' => (string)($ddMsgProductFocus['cover'] ?? ''),
        'code' => (string)($ddMsgProductFocus['code'] ?? ''),
        'time_label' => '',
        'seller_business' => (string)($ddMsgProductFocus['seller_business'] ?? ''),
    ];
}
// Also collect other Product IDs from this thread
foreach ($history as $hr) {
    if (!preg_match_all('/Product\s*ID\s*#\s*(\d+)\b/i', (string)($hr['feedbackdata'] ?? ''), $mm)) {
        continue;
    }
    foreach ($mm[1] as $pidRaw) {
        $pid = (int)$pidRaw;
        if ($pid <= 0) {
            continue;
        }
        $exists = false;
        foreach ($ddMsgProductHistory as $ex) {
            if ((int)($ex['product_id'] ?? 0) === $pid) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            continue;
        }
        if (!function_exists('commerce_messaging_product_focus')) {
            require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';
        }
        $focus = function_exists('commerce_messaging_product_focus')
            ? commerce_messaging_product_focus($dbh, $pid)
            : null;
        $ddMsgProductHistory[] = [
            'product_id' => $pid,
            'product_title' => is_array($focus) ? (string)($focus['title'] ?? ('Product #' . $pid)) : ('Product #' . $pid),
            'product_cover' => is_array($focus) ? (string)($focus['cover'] ?? '') : '',
            'code' => is_array($focus) ? (string)($focus['code'] ?? '') : '',
            'time_label' => '',
            'seller_business' => '',
        ];
    }
}
$ddMsgProductHistoryJson = json_encode($ddMsgProductHistory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($ddMsgProductHistoryJson === false) {
    $ddMsgProductHistoryJson = '[]';
}

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
    padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;
    background:var(--msb-palette-bg, #f4f6fb);
    color:var(--msb-palette-text, #0f172a);
  }
  .dd-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;}
  .dd-back{flex:0 0 auto;font-size:12px;font-weight:700;color:var(--azia-muted,#64748b);text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
  .dd-back:hover{color:#2563eb;text-decoration:none;}
  .dd-hero{flex:0 0 auto;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
  .dd-hero h1{margin:0;font-size:22px;font-weight:800;color:var(--azia-text,#0f172a);line-height:1.2;}
  .dd-hero-meta{margin-top:6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--azia-muted,#64748b);font-weight:600;}
  .dd-hero-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .dd-btn{height:34px;padding:0 12px;border-radius:8px;border:1px solid #e2e8f0;background:var(--azia-card,#fff);font-size:12px;font-weight:700;color:var(--azia-text,#334155);display:inline-flex;align-items:center;gap:6px;text-decoration:none;cursor:pointer;}
  .dd-btn:hover{background:var(--msb-palette-surface-2,var(--azia-card,#f8fafc));text-decoration:none;color:var(--azia-text,#0f172a);}
  .dd-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .dd-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .dd-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;}
  .dd-pill .dot{width:6px;height:6px;border-radius:999px;background:currentColor;}
  .dd-pill.open{background:#fef3c7;color:#b45309;}
  .dd-pill.awaiting{background:#f3e8ff;color:#7c3aed;}
  .dd-pill.resolved{background:#dcfce7;color:#15803d;}
  .dd-pill.closed{background:#f1f5f9;color:var(--azia-muted,#64748b);}
  .dd-pill.high{background:#fee2e2;color:#b91c1c;}
  .dd-pill.medium{background:#ffedd5;color:#c2410c;}
  .dd-pill.low{background:#dbeafe;color:#1d4ed8;}
  .dd-pill.ok{background:#dcfce7;color:#15803d;}
  .dd-tabs{flex:0 0 auto;display:flex;gap:0;background:var(--azia-card,#fff);border:1px solid #eef2f7;border-radius:10px;padding:0 6px;overflow:auto;}
  .dd-tabs a{flex:0 0 auto;padding:10px 14px;font-size:12px;font-weight:800;color:var(--azia-muted,#64748b);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;}
  .dd-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .dd-tabs a .cnt{font-weight:700;color:var(--azia-muted,#94a3b8);margin-left:4px;}
  .dd-board{flex:1 1 auto;min-height:0;display:grid;grid-template-columns:minmax(0,1.7fr) minmax(280px,.9fr);gap:10px;overflow:hidden;}
  .dd-main,.dd-side{min-height:0;min-width:0;overflow:auto;display:flex;flex-direction:column;gap:10px;}
  .dd-card{background:var(--azia-card,#fff);border:1px solid #eef2f7;border-radius:12px;padding:14px 16px;box-shadow:0 1px 2px rgba(15,23,42,.03);}
  .dd-card h2{margin:0 0 12px;font-size:14px;font-weight:800;color:var(--azia-text,#0f172a);display:flex;align-items:center;justify-content:space-between;gap:8px;}
  .dd-card h3{margin:0 0 8px;font-size:12px;font-weight:800;color:var(--azia-text,#0f172a);}
  .dd-parties{display:grid;grid-template-columns:1fr auto 1fr auto 1fr;gap:10px;align-items:center;margin-bottom:14px;}
  .dd-person{display:flex;align-items:center;gap:10px;min-width:0;}
  .dd-av{width:40px;height:40px;border-radius:999px;color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 40px;}
  .dd-name{font-weight:800;font-size:13px;color:var(--azia-text,#0f172a);}
  .dd-sub{font-size:11px;color:var(--azia-muted,#64748b);font-weight:600;}
  .dd-link{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;}
  .dd-link:hover{text-decoration:underline;}
  .dd-order-mid{text-align:center;padding:8px 10px;border:1px dashed #e2e8f0;border-radius:10px;background:var(--msb-palette-surface-2,var(--azia-card,#f8fafc));min-width:120px;}
  .dd-order-mid .oid{font-weight:800;font-size:12px;color:var(--azia-text,#0f172a);}
  .dd-arrow{color:var(--azia-muted,#94a3b8);font-size:14px;text-align:center;}
  .dd-grid3{display:grid;grid-template-columns:1.4fr .8fr .7fr;gap:12px;}
  .dd-k{font-size:10px;font-weight:800;color:var(--azia-muted,#94a3b8);text-transform:uppercase;letter-spacing:.03em;}
  .dd-v{font-size:13px;font-weight:800;color:var(--azia-text,#0f172a);margin-top:3px;}
  .dd-desc{font-size:12px;color:var(--azia-muted,#475569);line-height:1.45;margin-top:4px;}
  .dd-item{display:flex;gap:12px;align-items:flex-start;}
  .dd-thumb{width:72px;height:72px;border-radius:10px;object-fit:cover;background:#f1f5f9;border:1px solid #e2e8f0;flex:0 0 72px;display:flex;align-items:center;justify-content:center;color:var(--azia-muted,#94a3b8);font-size:22px;overflow:hidden;}
  .dd-thumb img{width:100%;height:100%;object-fit:cover;}
  .dd-item-meta{display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;margin-top:6px;font-size:11px;color:var(--azia-muted,#64748b);}
  .dd-item-meta b{color:var(--azia-text,#0f172a);font-weight:700;}
  .dd-item-right{margin-left:auto;text-align:right;flex:0 0 auto;}
  .dd-ev{display:flex;gap:10px;overflow:auto;padding-bottom:4px;}
  .dd-ev-card{flex:0 0 140px;border:1px solid #eef2f7;border-radius:10px;overflow:hidden;background:var(--msb-palette-surface-2,var(--azia-card,#fafbfc));}
  .dd-ev-card .pic{height:88px;background:#e2e8f0;display:flex;align-items:center;justify-content:center;color:var(--azia-muted,#64748b);font-size:20px;overflow:hidden;}
  .dd-ev-card .pic img{width:100%;height:100%;object-fit:cover;}
  .dd-ev-card .cap{padding:6px 8px;font-size:10px;font-weight:700;color:var(--azia-text,#334155);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .dd-seller-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;}
  .dd-field{margin-bottom:12px;}
  .dd-field label{display:block;font-size:11px;font-weight:800;color:var(--azia-muted,#64748b);margin-bottom:4px;}
  .dd-field select,.dd-field input,.dd-field textarea{width:100%;height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;background:var(--azia-card,#fff);color:var(--azia-text,#0f172a);box-sizing:border-box;}
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
  .dd-ticon.gray{background:#f1f5f9;color:var(--azia-muted,#64748b);}
  .dd-ttitle{font-size:12px;font-weight:800;color:var(--azia-text,#0f172a);}
  .dd-tsub{font-size:11px;color:var(--azia-muted,#64748b);margin-top:1px;}
  .dd-twhen{font-size:10px;color:var(--azia-muted,#94a3b8);margin-top:2px;font-weight:600;}
  .dd-kv{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12px;}
  .dd-kv:last-child{border-bottom:0;}
  .dd-kv .k{color:var(--azia-muted,#64748b);font-weight:700;}
  .dd-kv .v{color:var(--azia-text,#0f172a);font-weight:800;text-align:right;max-width:62%;}
  /* Match admin mailbox / Help chat bubble sizing exactly */
  .dd-chat{
    display:flex;
    flex-direction:column;
    gap:12px;
    align-items:stretch;
    padding:8px 4px 10px;
  }
  .dd-msg{
    display:flex;
    gap:8px;
    align-items:flex-end;
    max-width:min(78%,560px);
  }
  .dd-msg.me{align-self:flex-end;flex-direction:row-reverse;}
  .dd-msg.them{align-self:flex-start;}
  .dd-msg-ava{
    width:28px;height:28px;border-radius:999px;flex:0 0 28px;
    display:inline-flex;align-items:center;justify-content:center;
    color:#fff;font-size:11px;font-weight:800;
  }
  .dd-msg.me .dd-msg-ava{display:none;}
  .dd-msg-stack{
    display:flex;
    flex-direction:column;
    gap:4px;
    min-width:0;
    max-width:100%;
  }
  .dd-msg.me .dd-msg-stack{align-items:flex-end;}
  .dd-msg.them .dd-msg-stack{align-items:flex-start;}
  .dd-bubble{
    display:block;
    width:fit-content;
    max-width:100%;
    box-sizing:border-box;
    margin:0;
    padding:10px 12px;
    border-radius:14px;
    border:1px solid #e5e7eb;
    font-size:13.5px;
    font-weight:500;
    line-height:1.45;
    white-space:pre-wrap;
    word-break:break-word;
    text-align:left;
    background:var(--azia-card,#fff);
    color:var(--azia-text,#0f172a);
  }
  .dd-bubble.them{
    background:#f1f5f9 !important;
    border-color:#f1f5f9 !important;
    color:var(--azia-text,#0f172a) !important;
    -webkit-text-fill-color:var(--azia-text,#0f172a) !important;
    border-bottom-left-radius:5px;
  }
  .dd-bubble.me{
    background:#2563eb !important;
    border-color:#2563eb !important;
    color:#ffffff !important;
    -webkit-text-fill-color:#ffffff !important;
    border-bottom-right-radius:5px;
  }
  .dd-bubble.me a{color:#dbeafe !important;-webkit-text-fill-color:#dbeafe !important;}
  .dd-msg-time{
    font-size:11px;
    font-weight:500;
    color:var(--azia-muted,#94a3b8) !important;
    -webkit-text-fill-color:var(--azia-muted,#94a3b8) !important;
    line-height:1.2;
    margin:0 2px;
  }
  .dd-msg.me .dd-msg-time{text-align:right;}
  .dd-msg.them .dd-msg-time{text-align:left;}
  .dd-msg[hidden]{display:none!important;}
  .dd-chat-shell{display:flex;flex-direction:column;flex:1 1 auto;min-height:0;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;}
  .dd-chat-head{
    display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid #eef2f7;flex:0 0 auto;flex-wrap:nowrap;
  }
  .dd-chat-head-ava{
    width:40px;height:40px;border-radius:999px;flex:0 0 40px;display:inline-flex;align-items:center;justify-content:center;
    color:#fff;font-size:13px;font-weight:800;
  }
  .dd-chat-head-meta{min-width:0;flex:0 1 auto;}
  .dd-chat-head-name{margin:0;font-size:14px;font-weight:800;color:#0f172a;}
  .dd-chat-head-status{font-size:12px;font-weight:600;color:#16a34a;display:flex;align-items:center;gap:5px;margin-top:2px;}
  .dd-chat-head-status i{font-size:8px;}
  .dd-chat-product{
    display:inline-flex;align-items:center;gap:8px;min-width:0;max-width:min(280px,42vw);
    padding:4px 8px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;flex:1 1 auto;margin-left:auto;
  }
  .dd-chat-product[hidden]{display:none!important;}
  .dd-chat-product img{width:36px;height:36px;border-radius:6px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
  .dd-chat-product > div{min-width:0;}
  .dd-chat-product strong{display:block;font-size:12px;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .dd-chat-product-id{display:block;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .dd-chat-product a{flex:0 0 auto;font-size:11px;font-weight:800;color:#2563eb;text-decoration:none;padding:4px 8px;border-radius:6px;}
  .dd-chat-product a:hover{background:#eff6ff;text-decoration:none;}
  .dd-chat-more{position:relative;flex:0 0 auto;}
  .dd-chat-more-btn{
    width:34px;height:34px;border:0;border-radius:999px;background:transparent;color:#64748b;
    display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
  }
  .dd-chat-more-btn:hover,.dd-chat-more.is-open .dd-chat-more-btn{background:#f1f5f9;color:#0f172a;}
  .dd-chat-more-menu{
    display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:50;min-width:260px;max-width:min(360px,90vw);
    padding:6px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.12);
  }
  .dd-chat-more.is-open .dd-chat-more-menu{display:block;}
  .dd-history-head{padding:8px 12px 6px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748b;}
  .dd-history-list{max-height:280px;overflow:auto;}
  .dd-history-empty{padding:10px 12px;font-size:12px;color:#64748b;}
  .dd-history-item{
    display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:0;border-radius:8px;
    background:transparent;text-align:left;cursor:pointer;
  }
  .dd-history-item:hover,.dd-history-item.is-active{background:#f1f5f9;}
  .dd-history-item img{width:32px;height:32px;border-radius:6px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
  .dd-history-item-label{display:block;font-size:13px;font-weight:800;color:#0f172a;line-height:1.25;}
  .dd-history-item-meta{display:block;margin-top:2px;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .dd-history-bar{
    display:none;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid #eef2f7;background:#fff;flex:0 0 auto;
  }
  .dd-history-bar.is-open{display:flex;}
  .dd-history-back{
    border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#334155;
    font-size:12px;font-weight:800;padding:6px 10px;cursor:pointer;
  }
  .dd-history-title{font-size:13px;font-weight:800;color:#0f172a;margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .dd-alert{padding:8px 10px;border-radius:8px;font-size:12px;font-weight:700;}
  .dd-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .dd-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .dd-drop{position:relative;}
  .dd-drop-menu{display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:40;min-width:180px;background:var(--azia-card,#fff);border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 24px rgba(15,23,42,.12);padding:4px;}
  .dd-drop.open .dd-drop-menu{display:block;}
  .dd-drop-menu a,.dd-drop-menu button{display:block;width:100%;text-align:left;padding:8px 10px;border:0;background:transparent;border-radius:7px;font-size:12px;font-weight:700;color:var(--azia-text,#334155);text-decoration:none;cursor:pointer;}
  .dd-drop-menu a:hover,.dd-drop-menu button:hover{background:var(--msb-palette-surface-2,var(--azia-card,#f8fafc));}
  .dd-empty{padding:18px 8px;text-align:center;color:var(--azia-muted,#64748b);font-size:12px;}
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
            <span>Dispute ID: <b style="color:var(--azia-text,#0f172a);"><?= dispute_h($dspId) ?></b></span>
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
                <div class="dd-sub" style="font-weight:800;color:var(--azia-text,#0f172a);"><?= dispute_h($amount) ?></div>
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
            <div class="dd-desc" style="font-size:13px;color:var(--azia-text,#334155);">
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
            <div class="dd-desc" style="font-size:13px;color:var(--azia-text,#334155);">
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

          <?php if ($tab === 'messages'):
            $ddProdId = (int)($ddMsgProductFocus['id'] ?? 0);
            $ddProdTitle = trim((string)($ddMsgProductFocus['title'] ?? ''));
            $ddProdCover = trim((string)($ddMsgProductFocus['cover'] ?? ''));
            $ddProdCode = trim((string)($ddMsgProductFocus['code'] ?? ''));
            $ddProdHref = trim((string)($ddMsgProductFocus['buyer_href'] ?? ''));
            $ddProdIdLabel = $ddProdId > 0 ? ('Product ID #' . $ddProdId) : '';
            if ($ddProdIdLabel !== '' && $ddProdCode !== '') {
                $ddProdIdLabel .= ' · ' . $ddProdCode;
            }
            if ($ddProdTitle === '' && $ddProdId > 0) {
                $ddProdTitle = 'Product #' . $ddProdId;
            }
            if ($ddProdHref === '' && $ddProdId > 0) {
                $ddProdHref = '../public_user/product_detail.php?id=' . $ddProdId;
            }
            if ($ddProdCover !== '' && strpos($ddProdCover, 'http') !== 0 && strpos($ddProdCover, '/') !== 0) {
                $ddProdCover = '../' . ltrim($ddProdCover, '/');
            }
            $ddPeerLabel = $buyerName !== '' ? $buyerName : (explode('@', $peer)[0] ?: 'Customer');
            $ddPeerIni = dispute_initials((string)$peer);
            $ddPeerBg = dispute_avatar_color((string)$peer);
            $hasProdCard = $ddProdId > 0;
          ?>
          <section class="dd-card" style="flex:1 1 auto;display:flex;flex-direction:column;min-height:420px;padding:0;overflow:hidden;">
            <div class="dd-chat-shell" id="ddChatShell">
              <div class="dd-chat-head">
                <div class="dd-chat-head-ava" style="background:<?= dispute_h($ddPeerBg) ?>;" aria-hidden="true"><?= dispute_h($ddPeerIni) ?></div>
                <div class="dd-chat-head-meta">
                  <p class="dd-chat-head-name"><?= dispute_h($ddPeerLabel) ?></p>
                  <div class="dd-chat-head-status"><i class="fa fa-circle" aria-hidden="true"></i> <span>Active now</span></div>
                </div>
                <?php if ($hasProdCard): ?>
                  <div class="dd-chat-product" id="ddChatProduct">
                    <img src="<?= dispute_h($ddProdCover !== '' ? $ddProdCover : ('../public_user/avatar.php?name=' . rawurlencode($ddProdTitle))) ?>" alt="">
                    <div>
                      <strong><?= dispute_h($ddProdTitle) ?></strong>
                      <?php if ($ddProdIdLabel !== ''): ?><span class="dd-chat-product-id"><?= dispute_h($ddProdIdLabel) ?></span><?php endif; ?>
                    </div>
                    <?php if ($ddProdHref !== ''): ?><a href="<?= dispute_h($ddProdHref) ?>" target="_blank" rel="noopener">View</a><?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="dd-chat-product" id="ddChatProduct" hidden></div>
                <?php endif; ?>
                <div class="dd-chat-more" id="ddChatMore">
                  <button type="button" class="dd-chat-more-btn" id="ddChatMoreBtn" aria-label="Case history" aria-haspopup="menu" aria-expanded="false">
                    <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
                  </button>
                  <div class="dd-chat-more-menu" id="ddChatMoreMenu" role="menu">
                    <div class="dd-history-head">Case history</div>
                    <div class="dd-history-list" id="ddHistoryList">
                      <div class="dd-history-empty">Loading…</div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="dd-history-bar" id="ddHistoryBar">
                <button type="button" class="dd-history-back" id="ddHistoryBack">← Back</button>
                <p class="dd-history-title" id="ddHistoryTitle">Case history</p>
              </div>
              <script type="application/json" id="ddProductHistoryData"><?= $ddMsgProductHistoryJson ?></script>
              <div style="flex:1 1 auto;overflow:auto;min-height:160px;padding:12px 14px;" id="ddChatBody">
                <?php if (!$history): ?>
                  <div class="dd-empty">No messages in this dispute thread.</div>
                <?php else: ?>
                  <div class="dd-chat">
                    <?php
                      $ddRunningPid = $ddProdId;
                      foreach ($history as $row):
                      $fromAdmin = strcasecmp((string)($row['sender'] ?? ''), 'Admin') === 0;
                      $body = dispute_plain_for_chat((string)($row['feedbackdata'] ?? ''), $hasProdCard);
                      if ($body === '') {
                          // Keep a short placeholder if the whole body was context-only.
                          $rawPlain = dispute_plain_display((string)($row['feedbackdata'] ?? ''));
                          if ($rawPlain === '') {
                              continue;
                          }
                          if ($hasProdCard && preg_match('/Product\s*ID\s*#\s*\d+/i', $rawPlain)) {
                              $body = 'Opened a product report.';
                          } else {
                              $body = $rawPlain;
                          }
                      }
                      $when = dispute_fmt_time($row['created_at'] ?? null);
                      $peerIni = dispute_initials((string)$peer);
                      $peerBg = dispute_avatar_color((string)$peer);
                      $msgPid = 0;
                      if (preg_match('/Product\s*ID\s*#\s*(\d+)\b/i', (string)($row['feedbackdata'] ?? ''), $pidM)) {
                          $msgPid = (int)$pidM[1];
                          $ddRunningPid = $msgPid;
                      } elseif ($ddRunningPid > 0) {
                          $msgPid = $ddRunningPid;
                      }
                    ?>
                      <div class="dd-msg <?= $fromAdmin ? 'me' : 'them' ?>"<?= $msgPid > 0 ? ' data-product-id="' . (int)$msgPid . '"' : '' ?>>
                        <div class="dd-msg-ava" style="background:<?= dispute_h($peerBg) ?>;" aria-hidden="true"><?= dispute_h($peerIni) ?></div>
                        <div class="dd-msg-stack">
                          <div class="dd-bubble <?= $fromAdmin ? 'me' : 'them' ?>"><?= dispute_h($body) ?></div>
                          <?php if ($when !== ''): ?>
                            <div class="dd-msg-time"><?= dispute_h($when) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <form method="post" autocomplete="off" style="padding:12px 14px;border-top:1px solid #eef2f7;flex:0 0 auto;">
                <input type="hidden" name="reply_peer" value="<?= dispute_h($peer) ?>">
                <input type="hidden" name="id" value="<?= (int)$disputeId ?>">
                <input type="hidden" name="lane" value="<?= dispute_h($lane) ?>">
                <input type="hidden" name="filter" value="<?= dispute_h($filter) ?>">
                <div class="dd-field" style="margin-bottom:10px;">
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
            </div>
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
                    <div class="cap" style="color:var(--azia-muted,#94a3b8);padding-top:0;"><?= dispute_h(dispute_fmt($ev['when'] !== '' ? $ev['when'] : null)) ?> · <?= dispute_h((string)$ev['who']) ?></div>
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
                  <div style="border:1px solid #eef2f7;border-radius:10px;padding:10px 12px;background:var(--msb-palette-surface-2,var(--azia-card,#fafbfc));">
                    <div class="dd-desc" style="color:var(--azia-text,#334155);"><?= dispute_h((string)($n['feedbackdata'] ?? '')) ?></div>
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

<style id="dd-bubble-contrast-final">
  /* Sent bubbles: accent + white text; peer: themed surface + contrasted ink */
  body.azia-admin .dd-bubble.me,
  html[data-msb-appearance] body.azia-admin .dd-bubble.me,
  html[data-msb-appearance] body.azia-admin .dd-card .dd-bubble.me,
  html.dark-auto body.azia-admin .dd-bubble.me,
  html.msb-palette-active body.azia-admin .dd-bubble.me {
    background: var(--msb-palette-action, #2563eb) !important;
    border: 1px solid var(--msb-palette-action, #2563eb) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    padding: 10px 12px !important;
    border-radius: 14px !important;
    border-bottom-right-radius: 5px !important;
    font-size: 13.5px !important;
    line-height: 1.45 !important;
    min-height: 0 !important;
    height: auto !important;
  }
  body.azia-admin .dd-bubble.them,
  html[data-msb-appearance] body.azia-admin .dd-bubble.them,
  html[data-msb-appearance] body.azia-admin .dd-card .dd-bubble.them,
  html.dark-auto body.azia-admin .dd-bubble.them,
  html.msb-palette-active body.azia-admin .dd-bubble.them {
    background: var(--msb-palette-surface-2, var(--msb-palette-hover-bg, #f1f5f9)) !important;
    border: 1px solid var(--msb-palette-border, transparent) !important;
    color: var(--msb-palette-text, #0f172a) !important;
    -webkit-text-fill-color: var(--msb-palette-text, #0f172a) !important;
    padding: 10px 12px !important;
    border-radius: 14px !important;
    border-bottom-left-radius: 5px !important;
    font-size: 13.5px !important;
    line-height: 1.45 !important;
    min-height: 0 !important;
    height: auto !important;
    text-align: left !important;
  }
  body.azia-admin .dd-msg-time,
  html[data-msb-appearance] body.azia-admin .dd-msg-time,
  html.dark-auto body.azia-admin .dd-msg-time,
  html.msb-palette-active body.azia-admin .dd-msg-time {
    color: var(--msb-palette-text-muted, #94a3b8) !important;
    -webkit-text-fill-color: var(--msb-palette-text-muted, #94a3b8) !important;
  }
</style>
<script>
document.addEventListener('click', function(e){
  var drop = document.getElementById('ddMoreDrop');
  if (!drop) return;
  if (!drop.contains(e.target)) drop.classList.remove('open');
});
(function(){
  var body = document.getElementById('ddChatBody');
  if (body) body.scrollTop = body.scrollHeight;

  var moreWrap = document.getElementById('ddChatMore');
  var moreBtn = document.getElementById('ddChatMoreBtn');
  var historyList = document.getElementById('ddHistoryList');
  var historyBar = document.getElementById('ddHistoryBar');
  var historyBack = document.getElementById('ddHistoryBack');
  var historyTitle = document.getElementById('ddHistoryTitle');
  var productEl = document.getElementById('ddChatProduct');
  if (!moreWrap || !moreBtn || !historyList || !body) return;

  var viewingPid = 0;
  var history = [];
  try {
    var raw = document.getElementById('ddProductHistoryData');
    history = raw ? (JSON.parse(raw.textContent || '[]') || []) : [];
  } catch (e) {
    history = [];
  }

  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function closeMenu() {
    moreWrap.classList.remove('is-open');
    moreBtn.setAttribute('aria-expanded', 'false');
  }
  function renderList() {
    if (!history.length) {
      historyList.innerHTML = '<div class="dd-history-empty">No product history yet.</div>';
      return;
    }
    historyList.innerHTML = '';
    history.forEach(function (c) {
      var pid = parseInt(c.product_id || 0, 10) || 0;
      if (pid <= 0) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dd-history-item' + (viewingPid === pid ? ' is-active' : '');
      btn.setAttribute('role', 'menuitem');
      var title = String(c.product_title || ('Product #' + pid));
      var cover = String(c.product_cover || '');
      var img = cover || ('../public_user/avatar.php?name=' + encodeURIComponent(title));
      var meta = [title, c.time_label || ''].filter(Boolean).join(' · ');
      btn.innerHTML =
        '<img src="' + esc(img) + '" alt="">' +
        '<span>' +
          '<span class="dd-history-item-label">' + esc('Product ID #' + pid + ' history') + '</span>' +
          (meta ? '<span class="dd-history-item-meta">' + esc(meta) + '</span>' : '') +
        '</span>';
      btn.addEventListener('click', function () {
        openProductHistory(pid, c);
      });
      historyList.appendChild(btn);
    });
  }
  function setProductCard(c, pid) {
    if (!productEl) return;
    pid = parseInt(pid || 0, 10) || 0;
    if (pid <= 0) {
      productEl.hidden = true;
      productEl.innerHTML = '';
      return;
    }
    var title = String((c && (c.product_title || c.title)) || ('Product #' + pid));
    var cover = String((c && (c.product_cover || c.cover)) || '');
    var code = String((c && c.code) || '');
    var idLabel = 'Product ID #' + pid + (code ? (' · ' + code) : '');
    var href = '../public_user/product_detail.php?id=' + pid;
    var img = cover || ('../public_user/avatar.php?name=' + encodeURIComponent(title));
    productEl.hidden = false;
    productEl.innerHTML =
      '<img src="' + esc(img) + '" alt="">' +
      '<div><strong>' + esc(title) + '</strong><span class="dd-chat-product-id">' + esc(idLabel) + '</span></div>' +
      '<a href="' + esc(href) + '" target="_blank" rel="noopener">View</a>';
  }
  function openProductHistory(pid, meta) {
    pid = parseInt(pid || 0, 10) || 0;
    if (pid <= 0) return;
    viewingPid = pid;
    closeMenu();
    if (historyBar) historyBar.classList.add('is-open');
    if (historyTitle) historyTitle.textContent = 'Product ID #' + pid + ' history';
    setProductCard(meta || null, pid);
    var rows = body.querySelectorAll('.dd-msg');
    var any = false;
    rows.forEach(function (row) {
      var rowPid = parseInt(row.getAttribute('data-product-id') || '0', 10) || 0;
      var show = rowPid === pid;
      row.hidden = !show;
      if (show) any = true;
    });
    var empty = body.querySelector('.dd-empty-history');
    if (!any) {
      if (!empty) {
        empty = document.createElement('div');
        empty.className = 'dd-empty dd-empty-history';
        empty.textContent = 'No messages saved for Product ID #' + pid + '.';
        body.insertBefore(empty, body.firstChild);
      }
      empty.hidden = false;
    } else if (empty) {
      empty.hidden = true;
    }
    body.scrollTop = body.scrollHeight;
  }
  function exitHistory() {
    viewingPid = 0;
    if (historyBar) historyBar.classList.remove('is-open');
    body.querySelectorAll('.dd-msg').forEach(function (row) { row.hidden = false; });
    var empty = body.querySelector('.dd-empty-history');
    if (empty) empty.hidden = true;
    body.scrollTop = body.scrollHeight;
  }

  moreBtn.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var open = !moreWrap.classList.contains('is-open');
    moreWrap.classList.toggle('is-open', open);
    moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) renderList();
  });
  if (historyBack) historyBack.addEventListener('click', exitHistory);
  document.addEventListener('click', function (e) {
    if (!moreWrap.classList.contains('is-open')) return;
    if (moreWrap.contains(e.target)) return;
    closeMenu();
  });
  renderList();
})();
</script>
<?php org_admin_render_foot(); ?>
