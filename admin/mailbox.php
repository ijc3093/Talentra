<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/includes/identity.php';
require_once __DIR__ . '/includes/feedback_lane_helpers.php';
require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$controller = new Controller();
$dbh = $controller->pdo();

$meUser = myUsername();   // username stored in feedback_admin.sender/receiver
$meId   = myAdminId();
$meRole = myRoleId();

$adminId = $meId;

if ($meUser === '' || $meId <= 0 || $meRole <= 0) {
    die('Invalid session.');
}

// ✅ We store feedback_admin.sender/receiver as admin.friend_code
$meCode = '';
try {
    $stMe = $dbh->prepare("SELECT friend_code FROM admin WHERE idadmin = :id LIMIT 1");
    $stMe->execute([':id' => $meId]);
    $meCode = (string)($stMe->fetchColumn() ?: '');
} catch (Throwable $e) { $meCode = ''; }
if ($meCode === '') { $meCode = $meUser; } // fallback for legacy data

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function fmt_dt(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('M d, Y h:i A', $ts) : '';
}

function short_preview(string $s, int $n=60): string {
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    if (mb_strlen($s) <= $n) return $s;
    return mb_substr($s, 0, $n-1) . '…';
}

if (!function_exists('truncate_str')) {
    function truncate_str(string $s, int $len = 60): string {
        $s = trim(strip_tags($s));
        if (mb_strlen($s) <= $len) return $s;
        return mb_substr($s, 0, $len - 1) . '…';
    }
}

function preview_plain_from_html(string $html): string {
    $plain = mailbox_public_plain_text($html);
    $plain = trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
    return $plain;
}

/**
 * ✅ Resolve friend_code OR username into a human display label.
 */
function admin_label_by_key(PDO $dbh, string $key): string {
    $key = trim($key);
    if ($key === '') return '';

    try {
        $st = $dbh->prepare("SELECT username, fullname, friend_code FROM admin WHERE friend_code = :k LIMIT 1");
        $st->execute([':k' => $key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            return (string)($r['fullname'] ?: $r['username'] ?: $r['friend_code'] ?: $key);
        }
    } catch (Throwable $e) {}

    try {
        $st = $dbh->prepare("SELECT username, fullname, friend_code FROM admin WHERE username = :k LIMIT 1");
        $st->execute([':k' => $key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            return (string)($r['fullname'] ?: $r['username'] ?: $r['friend_code'] ?: $key);
        }
    } catch (Throwable $e) {}

    return $key;
}

/**
 * ✅ FIXED: Always returns TWO letters
 */
function avatar_initials(string $name): string {
    $name = trim((string)$name);
    if ($name === '') return '??';

    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

    if ($name === '') return '??';

    $parts = array_values(array_filter(explode(' ', $name), fn($p)=>trim($p) !== ''));

    if (!$parts) return '??';

    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = '';

    if (count($parts) > 1) {
        $second = mb_strtoupper(mb_substr($parts[count($parts)-1], 0, 1));
    } else {
        $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
    }

    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}

function avatar_color(string $key): string {
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

function sanitize_summernote_html(string $html): string
{
    $html = trim($html);

    $html = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html) ?? $html;
    $html = trim($html);
    if ($html === '') return '';

    if (!class_exists('DOMDocument')) {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#\son\w+="[^"]*"#i', '', $html) ?? $html;
        $html = strip_tags($html, '<p><br><b><strong><i><em><u><ul><ol><li><a><span>');
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return ($plain === '') ? '' : trim($html);
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $allowed = ['p','br','b','strong','i','em','u','ul','ol','li','a','span'];
    $styleAllowed = [
        'color','background-color','text-align','font-family','font-size',
        'font-weight','font-style','text-decoration',
    ];

    $nodes = iterator_to_array($dom->getElementsByTagName('*'));
    foreach ($nodes as $node) {
        $tag = strtolower($node->nodeName);

        if (!in_array($tag, $allowed, true)) {
            $text = (string)($node->textContent ?? '');
            if ($node->parentNode) {
                $node->parentNode->replaceChild($dom->createTextNode($text), $node);
            }
            continue;
        }

        $href = ($tag === 'a') ? (string)$node->getAttribute('href') : '';
        $sty  = (in_array($tag, ['p','span'], true)) ? (string)$node->getAttribute('style') : '';

        if ($node->hasAttributes()) {
            $attrs = [];
            foreach ($node->attributes as $attr) { $attrs[] = $attr->nodeName; }
            foreach ($attrs as $a) { $node->removeAttribute($a); }
        }

        if ($tag === 'a' && $href !== '') {
            if (preg_match('#^(https?://|mailto:|/|\.\./|\./)#i', $href) || strpos($href, '#') === 0) {
                $node->setAttribute('href', $href);
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if (($tag === 'p' || $tag === 'span') && $sty !== '') {
            $cleanStyles = [];
            foreach (preg_split('/;/', $sty) as $decl) {
                $decl = trim((string)$decl);
                if ($decl === '' || strpos($decl, ':') === false) continue;

                $parts = explode(':', $decl, 2);
                $prop = strtolower(trim((string)$parts[0]));
                $val  = trim((string)($parts[1] ?? ''));

                if (!in_array($prop, $styleAllowed, true)) continue;
                if (preg_match('#url\s*\(|expression\s*\(#i', $val)) continue;

                $val = preg_replace('#[^a-zA-Z0-9\s\-\#\(\),\.\%"]+#', '', $val);
                $val = trim((string)$val);
                if ($val === '') continue;

                $cleanStyles[] = $prop . ': ' . $val;
            }
            if (!empty($cleanStyles)) {
                $node->setAttribute('style', implode('; ', $cleanStyles));
            }
        }
    }

    $clean = trim($dom->saveHTML() ?: '');
    // DOMDocument may keep the encoding PI we inject for loadHTML — never persist it.
    $clean = preg_replace('/^<\?xml[^>]*\?>/i', '', $clean) ?? $clean;
    $clean = preg_replace('/^<!DOCTYPE[^>]*>/i', '', $clean) ?? $clean;
    $clean = trim($clean);
    $plain = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return ($plain === '') ? '' : $clean;
}

/** Plain text for public Help replies (seller/customer UI is text bubbles, not HTML). */
function mailbox_public_plain_text(string $htmlOrText): string
{
    $s = trim($htmlOrText);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/^<\?xml[^>]*\?>/i', '', $s) ?? $s;
    $s = preg_replace('/^<!DOCTYPE[^>]*>/i', '', $s) ?? $s;
    $s = trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
    return trim($s);
}

/* ==========================================================
   ✅ NEW: get MY real label for initials (NOT "You")
========================================================== */
$meLabelForInitials = $meUser;
try {
    $stMy = $dbh->prepare("SELECT fullname, username, friend_code FROM admin WHERE idadmin = :id LIMIT 1");
    $stMy->execute([':id'=>$meId]);
    $myRow = $stMy->fetch(PDO::FETCH_ASSOC);
    if ($myRow) {
        $meLabelForInitials = (string)($myRow['fullname'] ?: $myRow['username'] ?: $meUser);
    }
} catch (Throwable $e) {}

/* ==========================================================
   Peer selector (URL can pass username, friend_code, or public email)
========================================================== */
$peerCode  = trim((string)($_GET['peer'] ?? ''));
$threadUid = trim((string)($_GET['t'] ?? ''));
$viewRaw = strtolower(trim((string)($_GET['view'] ?? '')));
$mailboxView = in_array($viewRaw, ['public', 'internal'], true) ? $viewRaw : '';
// Help Center reply links use peer=email&view=public
if ($mailboxView === '' && $peerCode !== '' && strpos($peerCode, '@') !== false) {
    $mailboxView = 'public';
}
$isPublicMailbox = ($mailboxView === 'public');
$mailboxLane = $isPublicMailbox
    ? feedback_normalize_public_lane((string)($_GET['lane'] ?? 'all'))
    : 'all';
$mailboxViewQs = $mailboxView !== '' ? ('&view=' . rawurlencode($mailboxView)) : '';
if ($isPublicMailbox && $mailboxLane !== 'all') {
    $mailboxViewQs .= '&lane=' . rawurlencode($mailboxLane);
}
$mailboxLaneLabel = feedback_lane_inbox_title($mailboxLane);
[$mailboxLaneSql, $mailboxLaneParams] = $isPublicMailbox
    ? feedback_sql_public_lane($mailboxLane, 'f')
    : ['1=1', []];
$mailboxNotReportSql = feedback_sql_not_content_report('f');
$mailboxHelpBackUrl = 'feedback.php?view=public&filter=unread'
    . ($mailboxLane !== 'all' ? '&lane=' . rawurlencode($mailboxLane) : '');
$mailboxInboxHref = 'mailbox.php?view=public'
    . ($mailboxLane !== 'all' ? '&lane=' . rawurlencode($mailboxLane) : '');

$peerUser = '';
$peerName = '';
$peerRole = 0;
$peerCoverUrl = '';
$peerMetaLine = '';
$channelForPeer = '';

$threads = [];
$messages = [];
$subjectThreads = [];
$currentThreadTitle = '';
$errorMsg = '';

$senderMap = [];
$senderNameForInitials = [];

$internalChannels = allowedInternalChannelsForMe();

const THREAD_DELIM = '||THREAD||';

function thread_subject(string $title): string {
    $parts = explode(THREAD_DELIM, $title, 2);
    return trim((string)($parts[0] ?? ''));
}
function thread_uid(string $title): string {
    $parts = explode(THREAD_DELIM, $title, 2);
    return trim((string)($parts[1] ?? ''));
}

function feedback_admin_id_col(PDO $dbh): string {
    static $col = null;
    if ($col) return $col;

    try {
        $st = $dbh->query("SHOW KEYS FROM feedback_admin WHERE Key_name = 'PRIMARY'");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && !empty($row['Column_name'])) {
            $col = (string)$row['Column_name'];
            return $col;
        }
    } catch (Throwable $e) {}

    $candidates = ['id_feedback_admin','id','feedback_id','idfeedback','id_feedback','idfeedback_admin','feedback_admin_id'];
    try {
        $st = $dbh->query("SHOW COLUMNS FROM feedback_admin");
        $cols = $st ? $st->fetchAll(PDO::FETCH_COLUMN, 0) : [];
        foreach ($candidates as $c) {
            if (in_array($c, $cols, true)) { $col = $c; return $col; }
        }
        if (!empty($cols[0])) { $col = (string)$cols[0]; return $col; }
    } catch (Throwable $e) {}

    $col = 'id_feedback_admin';
    return $col;
}

/**
 * @return array{name:string,username:string,email:string,avatar:string}
 */
function mailbox_public_peer_profile(PDO $dbh, string $email): array
{
    $email = trim($email);
    $out = ['name' => '', 'username' => '', 'email' => $email, 'avatar' => ''];
    if ($email === '' || strpos($email, '@') === false) {
        return $out;
    }
    try {
        $st = $dbh->prepare("
            SELECT id, name, username, email
            FROM users
            WHERE LOWER(email) = LOWER(:e)
            LIMIT 1
        ");
        $st->execute([':e' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return $out;
        }
        $out['name'] = trim((string)($row['name'] ?? ''));
        $out['username'] = trim((string)($row['username'] ?? ''));
        $out['email'] = trim((string)($row['email'] ?? $email));
        if (function_exists('user_avatar_url')) {
            $out['avatar'] = (string)user_avatar_url($row, 80);
        }
    } catch (Throwable $e) {
        // keep email-only fallback
    }
    return $out;
}

try {
    if ($isPublicMailbox) {
        if (!isAdmin()) {
            $errorMsg = 'Only Admin can open public Help conversations.';
        } else {
            $publicMe = 'Admin';

            // ---------------- LEFT THREADS (lane-scoped Help / Support) ----------------
            // Include Admin replies (often empty scope) so the preview shows the latest message.
            $listLaneSql = "({$mailboxLaneSql})";
            $listLaneParams = $mailboxLaneParams;
            if ($mailboxLane !== 'all') {
                $listLaneSql = "(
                    ({$mailboxLaneSql})
                    OR (
                        f.sender = 'Admin'
                        AND f.receiver LIKE '%@%'
                        AND (
                            LOWER(TRIM(COALESCE(f.scope, ''))) = :list_lane_scope
                            OR TRIM(COALESCE(f.scope, '')) = ''
                        )
                    )
                )";
                $listLaneParams = array_merge($mailboxLaneParams, [
                    ':list_lane_scope' => $mailboxLane,
                ]);
            }
            $sqlThreads = "
                SELECT
                    peer_key AS peer_code,
                    peer_key AS peer_username,
                    COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), peer_key) AS peer_display,
                    MAX(last_time) AS last_time,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(last_message ORDER BY last_time DESC SEPARATOR ' ||| '),
                        ' ||| ', 1
                    ) AS last_message,
                    SUM(unread_count) AS unread_count
                FROM (
                    SELECT
                        CASE
                            WHEN f.sender = 'Admin' THEN f.receiver
                            ELSE f.sender
                        END AS peer_key,
                        f.created_at AS last_time,
                        f.feedbackdata AS last_message,
                        CASE WHEN f.is_read = 0 AND f.receiver = 'Admin' THEN 1 ELSE 0 END AS unread_count
                    FROM feedback_admin f
                    WHERE f.channel = 'user_admin'
                      AND (
                            (f.receiver = 'Admin' AND f.sender LIKE '%@%')
                         OR (f.sender = 'Admin' AND f.receiver LIKE '%@%')
                      )
                      AND {$mailboxNotReportSql}
                      AND {$listLaneSql}
                ) x
                LEFT JOIN users u ON LOWER(u.email) = LOWER(x.peer_key)
                WHERE peer_key <> ''
                GROUP BY peer_key, peer_display
                ORDER BY last_time DESC
                LIMIT 200
            ";
            try {
                $stmt = $dbh->prepare($sqlThreads);
                $stmt->execute($listLaneParams);
                $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $eThreads) {
                // Fallback without users join / subquery complexity
                $stmt = $dbh->prepare("
                    SELECT
                        f.sender AS peer_code,
                        f.sender AS peer_username,
                        f.sender AS peer_display,
                        MAX(f.created_at) AS last_time,
                        SUBSTRING_INDEX(
                            GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR ' ||| '),
                            ' ||| ', 1
                        ) AS last_message,
                        SUM(CASE WHEN f.is_read=0 THEN 1 ELSE 0 END) AS unread_count
                    FROM feedback_admin f
                    WHERE f.receiver = 'Admin'
                      AND f.channel = 'user_admin'
                      AND f.sender LIKE '%@%'
                      AND {$mailboxNotReportSql}
                      AND ({$mailboxLaneSql})
                    GROUP BY f.sender
                    ORDER BY last_time DESC
                    LIMIT 200
                ");
                $stmt->execute($mailboxLaneParams);
                $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            // ---------------- RESOLVE PEER ----------------
            if ($peerCode !== '') {
                foreach ($threads as $t) {
                    if (strcasecmp((string)$t['peer_code'], $peerCode) === 0 || strcasecmp((string)$t['peer_username'], $peerCode) === 0) {
                        $peerCode = (string)$t['peer_code'];
                        $peerUser = $peerCode;
                        $peerName = (string)($t['peer_display'] ?? $peerCode);
                        break;
                    }
                }

                if ($peerUser === '') {
                    $peerUser = $peerCode;
                }

                $prof = mailbox_public_peer_profile($dbh, $peerUser);
                if ($prof['name'] !== '') {
                    $peerName = $prof['name'];
                } elseif ($prof['username'] !== '') {
                    $peerName = $prof['username'];
                } elseif ($peerName === '') {
                    $peerName = $peerUser;
                }
                $peerCoverUrl = $prof['avatar'];
                $peerMetaLine = $peerUser;
                if ($prof['username'] !== '') {
                    $peerMetaLine = '@' . $prof['username'] . ' · ' . $peerUser;
                }
                $channelForPeer = 'user_admin';

                // ---------------- SUBJECT THREADS (same lane only) ----------------
                $stThreads = $dbh->prepare("
                    SELECT
                        f.title,
                        MAX(f.created_at) AS last_time,
                        SUBSTRING_INDEX(
                            GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR ' ||| '),
                            ' ||| ', 1
                        ) AS last_message,
                        COUNT(*) AS msg_count
                    FROM feedback_admin f
                    WHERE f.channel = 'user_admin'
                      AND (
                            (f.sender = :me AND f.receiver = :peer)
                         OR (f.sender = :peer2 AND f.receiver = :me2)
                      )
                      AND f.title IS NOT NULL
                      AND f.title <> ''
                      AND {$mailboxNotReportSql}
                      AND ({$mailboxLaneSql})
                    GROUP BY f.title
                    ORDER BY last_time DESC
                    LIMIT 300
                ");
                $stThreads->execute(array_merge([
                    ':me' => $publicMe,
                    ':peer' => $peerCode,
                    ':peer2' => $peerCode,
                    ':me2' => $publicMe,
                ], $mailboxLaneParams));
                $subjectThreads = $stThreads->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // Public Help is one thread per peer+lane. Do NOT pin to a subject title —
                // Admin replies often use a different title and would never load.
                $currentThreadTitle = '';
                $threadUid = '';

                // ---------------- POST SEND ----------------
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && $peerUser !== '') {
                    $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1');
                    $raw = (string)($_POST['message'] ?? '');
                    $text = mailbox_public_plain_text(sanitize_summernote_html($raw));
                    if ($text === '') {
                        $text = mailbox_public_plain_text($raw);
                    }

                    $attachmentsJson = null;
                    $attRaw = (string)($_POST['attachments'] ?? '');
                    if ($attRaw !== '') {
                        $att = json_decode($attRaw, true);
                        if (is_array($att)) {
                            $clean = [];
                            $base = realpath(__DIR__ . '/../attachment');
                            foreach ($att as $one) {
                                if (!is_array($one)) continue;
                                $path = trim((string)($one['path'] ?? ''));
                                $orig = trim((string)($one['original'] ?? ''));
                                $mime = trim((string)($one['mime'] ?? ''));
                                if ($path === '' || strpos($path, 'storage/') !== 0) continue;
                                if ($base !== false) {
                                    $full = realpath($base . DIRECTORY_SEPARATOR . $path);
                                    if ($full === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) continue;
                                }
                                $clean[] = ['path' => $path, 'original' => $orig, 'mime' => $mime];
                            }
                            if (!empty($clean)) $attachmentsJson = json_encode($clean);
                        }
                    }

                    if ($text !== '' || $attachmentsJson !== null) {
                        // Keep Admin replies under a stable lane title so history stays together.
                        $replyPrefix = feedback_lane_reply_title($mailboxLane);
                        if ($currentThreadTitle === '') {
                            foreach ($subjectThreads as $row) {
                                $tTitle = trim((string)($row['title'] ?? ''));
                                if ($tTitle !== '') {
                                    $currentThreadTitle = $tTitle;
                                    $threadUid = thread_uid($currentThreadTitle);
                                    break;
                                }
                            }
                        }
                        if ($currentThreadTitle === '') {
                            $uid = bin2hex(random_bytes(8));
                            $currentThreadTitle = $replyPrefix . ' ' . THREAD_DELIM . ' ' . $uid;
                            $threadUid = $uid;
                        }

                        $replyScope = in_array($mailboxLane, ['personal', 'customer', 'seller', 'publisher'], true)
                            ? $mailboxLane
                            : '';
                        try {
                            if ($replyScope !== '') {
                                $ins = $dbh->prepare("
                                    INSERT INTO feedback_admin (sender, receiver, channel, scope, title, feedbackdata, attachment, is_read)
                                    VALUES ('Admin', :r, :ch, :scope, :title, :d, :att, 0)
                                ");
                                $ins->execute([
                                    ':r' => $peerCode,
                                    ':ch' => $channelForPeer,
                                    ':scope' => $replyScope,
                                    ':title' => $currentThreadTitle,
                                    ':d' => $text,
                                    ':att' => $attachmentsJson,
                                ]);
                            } else {
                                throw new RuntimeException('no-scope');
                            }
                        } catch (Throwable $eIns) {
                            $ins = $dbh->prepare("
                                INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
                                VALUES ('Admin', :r, :ch, :title, :d, :att, 0)
                            ");
                            $ins->execute([
                                ':r' => $peerCode,
                                ':ch' => $channelForPeer,
                                ':title' => $currentThreadTitle,
                                ':d' => $text,
                                ':att' => $attachmentsJson,
                            ]);
                        }

                        // Always reload full peer+lane conversation (no subject pin).
                        $currentThreadTitle = '';
                        $threadUid = '';

                        $uidForRedirect = '';
                        if ($isAjax) {
                            header('Content-Type: application/json');
                            echo json_encode(['ok' => true, 'reload' => true]);
                            exit;
                        }
                        header('Location: mailbox.php?peer=' . urlencode($peerCode) . $mailboxViewQs);
                        exit;
                    }

                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => false, 'error' => 'Message cannot be empty.']);
                        exit;
                    }
                    $errorMsg = 'Message cannot be empty.';
                }

                // ---------------- LOAD CONVERSATION (full Help + dispute history) ----------------
                // Left list stays lane-scoped so Customer/Seller peers do not mix.
                // Once a peer is open, load complete Admin↔peer Support history
                // (Help user_admin + dispute), excluding content reports only.
                $mk = $dbh->prepare("
                    UPDATE feedback_admin f
                    SET f.is_read = 1, f.read_at = NOW()
                    WHERE f.channel IN ('user_admin', 'dispute')
                      AND f.sender = :peer
                      AND f.receiver = 'Admin'
                      AND f.is_read = 0
                      AND {$mailboxNotReportSql}
                ");
                $mk->execute([':peer' => $peerCode]);

                $idCol = feedback_admin_id_col($dbh);
                $q = $dbh->prepare("
                    SELECT f.{$idCol} AS id, f.sender, f.receiver, f.feedbackdata, f.attachment, f.created_at, f.title, f.channel
                    FROM feedback_admin f
                    WHERE f.channel IN ('user_admin', 'dispute')
                      AND (
                            (f.sender = 'Admin' AND f.receiver = :peer)
                         OR (f.sender = :peer2 AND f.receiver = 'Admin')
                      )
                      AND {$mailboxNotReportSql}
                    ORDER BY f.created_at ASC, f.{$idCol} ASC
                ");
                $q->execute([
                    ':peer' => $peerCode,
                    ':peer2' => $peerCode,
                ]);
                $messages = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $senderMap = [];
                $senderMap[$publicMe] = 'You';
                $senderMap[$peerCode] = $peerName !== '' ? $peerName : $peerUser;
                $senderNameForInitials = [];
                $senderNameForInitials[$publicMe] = $meLabelForInitials;
                $senderNameForInitials[$peerCode] = $peerName !== '' ? $peerName : $peerUser;

                // Treat Admin as "me" for bubble initials in this view
                $meCode = $publicMe;
            }
        }
    } elseif (!empty($internalChannels)) {

        $inKeys = [];
        $bind   = [
            ':me_recv' => $meCode,
            ':me_send' => $meCode,
            ':owner'   => $meId,
            ':me1'     => $meCode,
            ':me2'     => $meCode,
        ];

        foreach (array_values($internalChannels) as $i => $ch) {
            $k = ':ch' . $i;
            $inKeys[] = $k;
            $bind[$k] = $ch;
        }

        $inSql = implode(',', $inKeys);

        $sqlThreads = "
            SELECT
                a.friend_code AS peer_code,
                COALESCE(NULLIF(ac.display_name,''), NULLIF(a.fullname,''), a.username) AS peer_display,
                a.username AS peer_username,
                MAX(f.created_at) AS last_time,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR ' ||| '),
                    ' ||| ', 1
                ) AS last_message,
                SUM(CASE WHEN f.is_read=0 AND f.receiver = :me_recv THEN 1 ELSE 0 END) AS unread_count
            FROM feedback_admin f
            JOIN admin a
              ON a.friend_code = CASE WHEN f.sender = :me_send THEN f.receiver ELSE f.sender END
            LEFT JOIN admin_contacts ac
              ON ac.owner_admin_id = :owner
            AND ac.friend_admin_id = a.idadmin
            WHERE (f.sender = :me1 OR f.receiver = :me2)
              AND f.channel IN ($inSql)
            GROUP BY a.friend_code, peer_display, a.username
            ORDER BY last_time DESC
            LIMIT 200
        ";

        $stmt = $dbh->prepare($sqlThreads);
        $stmt->execute($bind);
        $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ---------------- RESOLVE PEER (internal admin chat) ----------------
    if (!$isPublicMailbox && $peerCode !== '') {
        foreach ($threads as $t) {
            if (
                strcasecmp((string)$t['peer_username'], $peerCode) === 0 ||
                strcasecmp((string)$t['peer_code'], $peerCode) === 0
            ) {
                $peerUser = (string)$t['peer_username'];
                $peerName = (string)$t['peer_display'];
                $peerCode = (string)$t['peer_code'];
                break;
            }
        }

        if ($peerUser === '' && $peerCode !== '') {
            $stP = $dbh->prepare("SELECT username, fullname, role, status, friend_code FROM admin WHERE friend_code = :fc LIMIT 1");
            $stP->execute([':fc' => $peerCode]);
            $pr = $stP->fetch(PDO::FETCH_ASSOC);
            if ($pr && (int)$pr['status'] === 1) {
                $peerUser = (string)($pr['username'] ?? '');
                $peerName = (string)($pr['fullname'] ?? $peerUser);
                $peerRole = (int)($pr['role'] ?? 0);
            }
        }

        if ($peerName === '' && $peerCode !== '') {
            $peerName = admin_label_by_key($dbh, $peerCode);
        }

        if ($peerUser !== '') {
            $st = $dbh->prepare("SELECT role, status FROM admin WHERE username = :u LIMIT 1");
            $st->execute([':u' => $peerUser]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['status'] === 1) {
                $peerRole = (int)$row['role'];
                $channelForPeer = channelForAdminRoles($meRole, $peerRole);
            }
        }

        // ---------------- SUBJECT THREADS ----------------
        if ($peerUser !== '' && $channelForPeer !== '') {
            $stThreads = $dbh->prepare("
                SELECT
                    title,
                    MAX(created_at) AS last_time,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(feedbackdata ORDER BY created_at DESC SEPARATOR ' ||| '),
                        ' ||| ', 1
                    ) AS last_message,
                    COUNT(*) AS msg_count
                FROM feedback_admin
                WHERE channel = :ch
                  AND (
                        (sender = :me AND receiver = :peer)
                     OR (sender = :peer2 AND receiver = :me2)
                  )
                  AND title IS NOT NULL
                  AND title <> ''
                GROUP BY title
                ORDER BY last_time DESC
                LIMIT 300
            ");
            $stThreads->execute([
                ':ch' => $channelForPeer,
                ':me' => $meCode,
                ':peer' => $peerCode,
                ':peer2' => $peerCode,
                ':me2' => $meCode,
            ]);
            $subjectThreads = $stThreads->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($subjectThreads)) {
                if ($threadUid !== '') {
                    foreach ($subjectThreads as $row) {
                        $uid = thread_uid((string)$row['title']);
                        if ($uid !== '' && hash_equals($uid, $threadUid)) {
                            $currentThreadTitle = (string)$row['title'];
                            break;
                        }
                    }
                }
                if ($currentThreadTitle === '') {
                    $currentThreadTitle = (string)$subjectThreads[0]['title'];
                    $threadUid = thread_uid($currentThreadTitle);
                }
            }
        }

        // ---------------- POST SEND (AJAX + fallback redirect) ----------------
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $peerUser !== '') {

            $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1');

            $raw = (string)($_POST['message'] ?? '');
            $text = sanitize_summernote_html($raw);

            $attachmentsJson = null;
            $attRaw = (string)($_POST['attachments'] ?? '');
            if ($attRaw !== '') {
                $att = json_decode($attRaw, true);
                if (is_array($att)) {
                    $clean = [];
                    $base = realpath(__DIR__ . '/../attachment');
                    foreach ($att as $one) {
                        if (!is_array($one)) continue;
                        $path = trim((string)($one['path'] ?? ''));
                        $orig = trim((string)($one['original'] ?? ''));
                        $mime = trim((string)($one['mime'] ?? ''));
                        if ($path === '' || strpos($path, 'storage/') !== 0) continue;

                        if ($base !== false) {
                            $full = realpath($base . DIRECTORY_SEPARATOR . $path);
                            if ($full === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) continue;
                        }
                        $clean[] = ['path' => $path, 'original' => $orig, 'mime' => $mime];
                    }
                    if (!empty($clean)) $attachmentsJson = json_encode($clean);
                }
            }

            if ($text !== '' || $attachmentsJson !== null) {

                if ($channelForPeer === '') {
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'You cannot chat with this role.']); exit; }
                    $errorMsg = 'You cannot chat with this role.';
                } else {

                    if ($currentThreadTitle === '') {
                        $uid = bin2hex(random_bytes(8));
                        $currentThreadTitle = 'No Subject ' . THREAD_DELIM . ' ' . $uid;
                        $threadUid = $uid;
                    }

                    $ins = $dbh->prepare("
                        INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
                        VALUES (:s, :r, :ch, :title, :d, :att, 0)
                    ");
                    $ins->execute([
                        ':s' => $meCode,
                        ':r' => $peerCode,
                        ':ch' => $channelForPeer,
                        ':title' => $currentThreadTitle,
                        ':d' => $text,
                        ':att' => $attachmentsJson
                    ]);

                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => true, 'reload' => true]);
                        exit;
                    }

                    $uidForRedirect = $threadUid !== '' ? $threadUid : thread_uid($currentThreadTitle);
                    header('Location: mailbox.php?peer=' . urlencode($peerCode) . $mailboxViewQs . ($uidForRedirect !== '' ? '&t=' . urlencode($uidForRedirect) : ''));
                    exit;
                }
            } else {
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Message cannot be empty.']); exit; }
                $errorMsg = 'Message cannot be empty.';
            }
        }

        // ---------------- LOAD CONVERSATION ----------------
        if ($peerUser !== '' && $channelForPeer !== '') {

            $mk = $dbh->prepare("
                UPDATE feedback_admin
                SET is_read = 1, read_at = NOW()
                WHERE channel = :ch
                  AND sender = :peer
                  AND receiver = :me
                  AND is_read = 0
            ");
            $mk->execute([':ch'=>$channelForPeer, ':peer'=>$peerCode, ':me'=>$meCode]);

            $idCol = feedback_admin_id_col($dbh);

            $q = $dbh->prepare("
                SELECT {$idCol} AS id, sender, receiver, feedbackdata, attachment, created_at, title
                FROM feedback_admin
                WHERE channel = :ch
                  AND (
                        (sender = :me AND receiver = :peer)
                    OR  (sender = :peer2 AND receiver = :me2)
                  )
                  AND (:title1 = '' OR title = :title2)
                ORDER BY created_at ASC, {$idCol} ASC
            ");
            $q->execute([
                ':ch'     => $channelForPeer,
                ':me'     => $meCode,
                ':peer'   => $peerCode,
                ':peer2'  => $peerCode,
                ':me2'    => $meCode,
                ':title1' => $currentThreadTitle,
                ':title2' => $currentThreadTitle,
            ]);
            $messages = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $senderMap = [];
            $senderMap[$meCode] = 'You';
            if ($peerCode !== '') {
                $senderMap[$peerCode] = $peerName !== '' ? $peerName : ($peerUser !== '' ? $peerUser : $peerCode);
            }

            $senderNameForInitials = [];
            $senderNameForInitials[$meCode] = $meLabelForInitials;
            if ($peerCode !== '') {
                $senderNameForInitials[$peerCode] = $peerName !== '' ? $peerName : ($peerUser !== '' ? $peerUser : $peerCode);
            }

            $need = [];
            foreach ($messages as $mm) {
                $sc = (string)($mm['sender'] ?? '');
                if ($sc !== '' && !isset($senderMap[$sc])) $need[$sc] = true;
            }

            if (!empty($need)) {
                foreach (array_keys($need) as $k) {
                    $lbl = admin_label_by_key($dbh, $k);
                    $senderMap[$k] = $lbl;
                    $senderNameForInitials[$k] = $lbl;
                }
            }
        }
    }

} catch (Throwable $e) {
    $errorMsg = 'DB error: ' . $e->getMessage();
}

$currentSubject = '';
if ($currentThreadTitle !== '') $currentSubject = thread_subject($currentThreadTitle);
if (trim($currentSubject) === '') $currentSubject = 'No Subject';

$headerPeerLabel = ($peerName !== '' ? $peerName : ($peerUser !== '' ? $peerUser : $peerCode));
$headerPeerKey   = ($peerCode !== '' ? $peerCode : $headerPeerLabel);
$headerIni       = avatar_initials($headerPeerLabel);
$headerBg        = avatar_color($headerPeerKey);
if (!isset($peerCoverUrl)) $peerCoverUrl = '';
if (!isset($peerMetaLine)) $peerMetaLine = '';
if ($peerMetaLine === '' && $isPublicMailbox && $peerUser !== '') {
    $peerMetaLine = $peerUser;
}

/* Product focus + Product ID history for Admin ↔ customer/seller Support conversations */
$mbProductFocus = null;
$mbProductHistory = [];
$mbPeerUserId = 0;
$mbConcernSide = ''; // 'customer' | 'seller'
if ($isPublicMailbox && $peerCode !== '' && strpos($peerCode, '@') !== false) {
    try {
        $stPeer = $dbh->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:e) AND status = 1 LIMIT 1');
        $stPeer->execute([':e' => $peerCode]);
        $mbPeerUserId = (int)($stPeer->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $mbPeerUserId = 0;
    }
    if ($mbPeerUserId > 0) {
        require_once __DIR__ . '/../public_user/includes/commerce_disputes.php';
        require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';
        require_once __DIR__ . '/../public_user/includes/org_shop.php';

        $sellerCases = function_exists('commerce_dispute_list_history_for_seller')
            ? commerce_dispute_list_history_for_seller($dbh, $mbPeerUserId, 40)
            : [];
        $buyerCases = function_exists('commerce_dispute_list_for_buyer')
            ? commerce_dispute_list_for_buyer($dbh, $mbPeerUserId, 40)
            : [];

        if ($mailboxLane === 'seller') {
            $mbConcernSide = 'seller';
        } elseif ($mailboxLane === 'customer') {
            $mbConcernSide = 'customer';
        } elseif (!empty($sellerCases)) {
            $mbConcernSide = 'seller';
        } elseif (!empty($buyerCases)) {
            $mbConcernSide = 'customer';
        }

        $sourceCases = $mbConcernSide === 'seller' ? $sellerCases : ($mbConcernSide === 'customer' ? $buyerCases : []);
        if ($sourceCases) {
            $byPid = [];
            foreach ($sourceCases as $c) {
                $pid = (int)($c['product_id'] ?? 0);
                if ($pid <= 0 || isset($byPid[$pid])) {
                    continue;
                }
                $byPid[$pid] = [
                    'id' => (int)($c['id'] ?? 0),
                    'product_id' => $pid,
                    'product_title' => (string)($c['product_title'] ?? ('Product #' . $pid)),
                    'product_cover' => (string)($c['product_cover'] ?? ''),
                    'code' => (string)($c['code'] ?? ''),
                    'time_label' => (string)($c['time_label'] ?? ''),
                    'is_open' => !empty($c['is_open']),
                    'seller_business' => (string)($c['seller_business'] ?? ''),
                    'dispute_code' => (string)($c['code'] ?? ''),
                    'concern_label' => $mbConcernSide === 'seller' ? 'Customer concern' : '',
                ];
            }
            $mbProductHistory = array_values($byPid);
            usort($mbProductHistory, static function ($a, $b) {
                return ((int)($b['product_id'] ?? 0)) <=> ((int)($a['product_id'] ?? 0));
            });
        }

        $openCase = null;
        if ($mbConcernSide === 'seller' && function_exists('commerce_dispute_seller_latest_open_case')) {
            $openCase = commerce_dispute_seller_latest_open_case($dbh, $mbPeerUserId);
        } elseif ($mbConcernSide === 'customer' && function_exists('commerce_dispute_buyer_latest_open_case')) {
            $openCase = commerce_dispute_buyer_latest_open_case($dbh, $mbPeerUserId);
        }

        if (is_array($openCase)) {
            $opid = (int)($openCase['product_id'] ?? 0);
            $odsp = trim((string)($openCase['dispute_code'] ?? ''));
            if ($odsp === '' && function_exists('commerce_dispute_format_id')) {
                $odsp = commerce_dispute_format_id((int)($openCase['id'] ?? 0));
            }
            if ($opid > 0 && function_exists('commerce_messaging_product_focus')) {
                $mbProductFocus = commerce_messaging_product_focus($dbh, $opid);
            }
            if (!is_array($mbProductFocus) && $opid > 0) {
                $cover = function_exists('org_shop_cover_url')
                    ? org_shop_cover_url((string)($openCase['product_cover_path'] ?? ''))
                    : '';
                $mbProductFocus = [
                    'id' => $opid,
                    'title' => trim((string)($openCase['product_title'] ?? ('Product #' . $opid))),
                    'code' => '',
                    'cover' => $cover,
                    'price' => '',
                    'buyer_href' => 'open_product_detail.php?id=' . $opid,
                ];
            }
            if (is_array($mbProductFocus)) {
                $mbProductFocus['seller_business'] = trim((string)($openCase['seller_business_name'] ?? ''));
                $mbProductFocus['dispute_code'] = $odsp;
                if ($mbConcernSide === 'seller') {
                    $mbProductFocus['concern_label'] = 'Customer concern';
                }
                // Admin mailbox: relative product_detail.php would 404 under /admin/.
                $mbProductFocus['buyer_href'] = 'open_product_detail.php?id=' . $opid;
            }
        }
    }
    // Fallback / merge: Product IDs mentioned in this thread always appear in ⋯ history.
    if (!empty($messages)) {
        if (!function_exists('commerce_messaging_product_focus')) {
            require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';
        }
        $seen = [];
        foreach ($mbProductHistory as $hRow) {
            $spid = (int)($hRow['product_id'] ?? 0);
            if ($spid > 0) {
                $seen[$spid] = true;
            }
        }
        foreach ($messages as $mRow) {
            $t = (string)($mRow['feedbackdata'] ?? '');
            if (!preg_match_all('/Product\s*ID\s*#\s*(\d+)\b/i', $t, $mm)) {
                continue;
            }
            foreach ($mm[1] as $pidRaw) {
                $pid = (int)$pidRaw;
                if ($pid <= 0 || isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;
                $focus = (function_exists('commerce_messaging_product_focus'))
                    ? commerce_messaging_product_focus($dbh, $pid)
                    : null;
                $mbProductHistory[] = [
                    'id' => 0,
                    'product_id' => $pid,
                    'product_title' => is_array($focus) ? (string)($focus['title'] ?? ('Product #' . $pid)) : ('Product #' . $pid),
                    'product_cover' => is_array($focus) ? (string)($focus['cover'] ?? '') : '',
                    'code' => is_array($focus) ? (string)($focus['code'] ?? '') : '',
                    'time_label' => '',
                    'is_open' => false,
                    'seller_business' => '',
                    'dispute_code' => '',
                    'concern_label' => $mbConcernSide === 'seller' ? 'Customer concern' : '',
                ];
            }
        }
        if ($mbConcernSide === '' && $mbProductHistory) {
            // Infer seller thread from message prefixes when lane is all.
            foreach ($messages as $mRow) {
                $blob = (string)($mRow['feedbackdata'] ?? '') . ' ' . (string)($mRow['title'] ?? '') . ' ' . (string)($mRow['scope'] ?? '');
                if (preg_match('/\[(?:Seller help|Seller dispute|Admin dispute)\]/i', $blob)
                    || strcasecmp(trim((string)($mRow['scope'] ?? '')), 'seller') === 0
                ) {
                    $mbConcernSide = 'seller';
                    break;
                }
            }
            if ($mbConcernSide === 'seller') {
                foreach ($mbProductHistory as &$hRow) {
                    $hRow['concern_label'] = 'Customer concern';
                }
                unset($hRow);
            }
        }
        if ($mbProductHistory) {
            usort($mbProductHistory, static function ($a, $b) {
                return ((int)($b['product_id'] ?? 0)) <=> ((int)($a['product_id'] ?? 0));
            });
        }
    }
    if (!is_array($mbProductFocus) && $mbProductHistory) {
        $first = $mbProductHistory[0];
        $pid = (int)($first['product_id'] ?? 0);
        if ($pid > 0 && function_exists('commerce_messaging_product_focus')) {
            $mbProductFocus = commerce_messaging_product_focus($dbh, $pid);
        }
        if (!is_array($mbProductFocus) && $pid > 0) {
            $mbProductFocus = [
                'id' => $pid,
                'title' => (string)($first['product_title'] ?? ('Product #' . $pid)),
                'code' => (string)($first['code'] ?? ''),
                'cover' => (string)($first['product_cover'] ?? ''),
                'price' => '',
                'buyer_href' => 'open_product_detail.php?id=' . $pid,
            ];
        }
        if (is_array($mbProductFocus)) {
            $mbProductFocus['seller_business'] = (string)($first['seller_business'] ?? '');
            $mbProductFocus['dispute_code'] = (string)($first['dispute_code'] ?? $first['code'] ?? '');
            if ($mbConcernSide === 'seller' || (string)($first['concern_label'] ?? '') !== '') {
                $mbProductFocus['concern_label'] = 'Customer concern';
            }
            $mbProductFocus['buyer_href'] = 'open_product_detail.php?id=' . $pid;
        }
    }
    // Always include the focused product in ⋯ history (open concern may be the only row).
    if (is_array($mbProductFocus)) {
        $focusPid = (int)($mbProductFocus['id'] ?? 0);
        if ($focusPid > 0) {
            // Never keep relative product_detail.php — it 404s under /admin/.
            $mbProductFocus['buyer_href'] = 'open_product_detail.php?id=' . $focusPid;
            $hasFocus = false;
            foreach ($mbProductHistory as $hRow) {
                if ((int)($hRow['product_id'] ?? 0) === $focusPid) {
                    $hasFocus = true;
                    break;
                }
            }
            if (!$hasFocus) {
                array_unshift($mbProductHistory, [
                    'id' => 0,
                    'product_id' => $focusPid,
                    'product_title' => (string)($mbProductFocus['title'] ?? ('Product #' . $focusPid)),
                    'product_cover' => (string)($mbProductFocus['cover'] ?? ''),
                    'code' => (string)($mbProductFocus['code'] ?? ''),
                    'time_label' => '',
                    'is_open' => true,
                    'seller_business' => (string)($mbProductFocus['seller_business'] ?? ''),
                    'dispute_code' => (string)($mbProductFocus['dispute_code'] ?? ''),
                    'concern_label' => (string)($mbProductFocus['concern_label'] ?? ''),
                ]);
            }
        }
    }
}
$mbProductHistoryJson = json_encode($mbProductHistory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($mbProductHistoryJson === false) {
    $mbProductHistoryJson = '[]';
}
$mbBuyerUserId = $mbPeerUserId; // backwards-compatible alias

/* ==========================================================
   LOAD ADMIN PROFILE (SAFE: by ID)
========================================================== */
$stmt = $dbh->prepare("
  SELECT idadmin, fullname, username, email, image, role
  FROM admin
  WHERE idadmin = :id
  LIMIT 1
");
$stmt->execute([':id' => $adminId]);
$user = $stmt->fetch(PDO::FETCH_OBJ);

$adminLogin  = $user->fullname ?? '';
$adminRoleId = (int)($user->role ?? 1);

// NOTE: roleMap/roleNameRaw/baseRoleName are assumed to exist (your project)
$roleName    = $roleMap[$adminRoleId] ?? 'Admin';

$rawRoleId = (int)($_SESSION['userRole'] ?? 0);
$displayRole = ucfirst(roleNameRaw($dbh, $rawRoleId));
$baseRole    = baseRoleName($dbh, $rawRoleId);

$avatarWeb = '../images/profile.jpg';
if ($user && !empty($user->image)) {
    $imgPath = __DIR__ . '../images/' . $user->image;
    if (file_exists($imgPath)) $avatarWeb = '../images/' . $user->image;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Mailbox Page</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link href="../lib/select2/css/select2.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">

  <style>
    html, body { height: 100%; overflow: hidden; }
    body.azia-admin { background: var(--msb-palette-bg, var(--azia-bg, #f4f6fb)); }

    .sh-mainpanel{
      height: 100vh;
      max-height: 100dvh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      padding-top: 78px;
      box-sizing: border-box;
    }
    .sh-pagebody{
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      width: 100% !important;
      max-width: none !important;
      margin: 0 12px 12px !important;
      padding: 0 !important;
      box-sizing: border-box;
    }

    /* Kill shamcey absolute mailbox layout — full-bleed flex shell */
    .card-mailbox{
      flex: 1 1 auto;
      min-height: 0;
      width: 100% !important;
      max-width: none !important;
      display: flex !important;
      flex-direction: column !important;
      overflow: hidden;
      border: 1px solid var(--msb-palette-border, #e5e7eb) !important;
      border-radius: 12px;
      box-shadow: 0 1px 2px rgba(15,23,42,.04);
      background: var(--azia-card, #fff) !important;
    }
    .card-mailbox > .card-header{
      flex: 0 0 auto;
      background: var(--azia-card, #fff) !important;
      border-bottom: 1px solid var(--msb-palette-border, #eef2f7) !important;
      padding: 10px 14px !important;
    }
    .card-mailbox > .card-header .nav{ align-items: center; gap: 4px; }
    .card-mailbox > .card-header .nav-link{
      color: var(--azia-muted, #64748b) !important;
      font-size: 13px;
      font-weight: 700;
      padding: 6px 10px !important;
      height: auto !important;
      border-radius: 8px;
      border-right: 0 !important;
      background: transparent !important;
    }
    .card-mailbox > .card-header .nav-link.active,
    .card-mailbox > .card-header .nav-link:hover{
      color: var(--msb-palette-action, #2563eb) !important;
      background: var(--msb-palette-action-soft, #eff6ff) !important;
    }
    .card-mailbox > .card-body{
      flex: 1 1 auto !important;
      min-height: 0 !important;
      height: auto !important;
      max-height: none !important;
      overflow: hidden !important;
      display: flex !important;
      flex-direction: row !important;
      position: relative !important;
      padding: 0 !important;
      width: 100% !important;
    }

    /* Public Help: inset conversation cards like customer Messages (gray gutter on the right) */
    body.mb-public-view .card-mailbox{
      background: transparent !important;
      border: 0 !important;
      box-shadow: none !important;
      border-radius: 0 !important;
    }
    body.mb-public-view .card-mailbox > .card-header{
      margin: 0 28px 8px 12px;
      border-radius: 12px;
      border: 1px solid var(--msb-palette-border, #e5e7eb) !important;
      box-shadow: 0 1px 2px rgba(15,23,42,.04);
    }
    body.mb-public-view .card-mailbox > .card-body{
      gap: 12px;
      padding: 0 0 12px 0 !important;
      background: var(--msb-palette-bg, var(--azia-bg, #f4f6fb)) !important;
      overflow: visible !important;
    }
    body.mb-public-view .mailbox-left{
      border-radius: 12px !important;
      border: 1px solid var(--msb-palette-border, #e5e7eb) !important;
      border-right: 1px solid var(--msb-palette-border, #e5e7eb) !important;
      box-shadow: 0 1px 2px rgba(15,23,42,.04);
      overflow: hidden !important;
      margin-left: 12px;
    }
    body.mb-public-view .mailbox-body{
      /* margin-right set below after base .mailbox-body */
      border-radius: 12px !important;
      border: 1px solid var(--msb-palette-border, #e5e7eb) !important;
      box-shadow: 0 1px 2px rgba(15,23,42,.04);
      overflow: hidden !important;
      background: var(--azia-card, #fff) !important;
    }

    .mailbox-left{
      position: relative !important;
      top: auto !important;
      left: auto !important;
      right: auto !important;
      bottom: auto !important;
      z-index: auto !important;
      flex: 0 0 300px !important;
      width: 300px !important;
      max-width: 34% !important;
      height: auto !important;
      min-width: 240px !important;
      display: flex !important;
      flex-direction: column !important;
      overflow: hidden !important;
      border-right: 1px solid var(--msb-palette-border, #eef2f7) !important;
      background: var(--azia-card, #fff) !important;
    }
    .mailbox-left .input-group{
      position: relative !important;
      top: auto !important;
      left: auto !important;
      right: auto !important;
      flex: 0 0 auto;
      padding: 12px !important;
      margin: 0;
      border-bottom: 1px solid var(--msb-palette-border, #eef2f7) !important;
    }
    .mailbox-left .form-control{
      height: 36px;
      border-radius: 9px;
      border: 1px solid var(--msb-palette-border, #e2e8f0) !important;
      background: var(--msb-palette-input-bg, var(--azia-card, #fff)) !important;
      color: var(--azia-text, #0f172a) !important;
      font-size: 13px;
    }
    .mailbox-left .form-control::placeholder{
      color: var(--azia-muted, #94a3b8) !important;
      opacity: 1;
    }
    .mailbox-list{
      position: relative !important;
      top: auto !important;
      left: auto !important;
      right: auto !important;
      bottom: auto !important;
      flex: 1 1 auto !important;
      min-height: 0 !important;
      overflow: auto !important;
      padding: 0 6px 10px;
    }
    .mailbox-list > .media{
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 10px;
      margin: 0 0 4px;
      border-radius: 10px;
      text-decoration: none !important;
      color: inherit;
      border: 1px solid transparent;
    }
    .mailbox-list > .media:hover{
      background: var(--msb-palette-hover-bg, var(--msb-palette-surface-2, #f8fafc)) !important;
    }
    .mailbox-list > .media.bg-gray-100{
      background: var(--msb-palette-action-soft, #eff6ff) !important;
      border-color: var(--msb-palette-border, #bfdbfe) !important;
    }
    .mailbox-list h6{ margin: 0; font-size: 13px; font-weight: 800; color: var(--azia-text, #0f172a) !important; }
    .mailbox-list .tx-12, .mailbox-list .tx-11, .mailbox-list .tx-gray-600{
      color: var(--azia-muted, #64748b) !important;
    }
    .mailbox-list p{ margin: 4px 0 0; font-size: 12px; color: var(--azia-muted, #64748b) !important; line-height: 1.35; }
    .mailbox-list .pd-15{
      color: var(--azia-muted, #94a3b8) !important;
      font-size: 13px !important;
      font-weight: 600;
      padding: 28px 16px !important;
      text-align: center;
    }

    .mailbox-body{
      position: relative !important;
      top: auto !important;
      left: auto !important;
      right: auto !important;
      bottom: auto !important;
      z-index: auto !important;
      flex: 1 1 auto !important;
      width: auto !important;
      min-width: 0 !important;
      height: auto !important;
      display: flex !important;
      flex-direction: column !important;
      overflow: hidden !important;
      background: var(--msb-palette-surface-2, var(--azia-bg, #f8fafc)) !important;
      margin: 0 !important;
    }

    /* Conversation card inset from the right edge (matches customer Messages example) */
    body.mb-public-view .mailbox-body{
      margin: 0 28px 0 0 !important;
      border-radius: 12px !important;
      border: 1px solid var(--msb-palette-border, #e5e7eb) !important;
      box-shadow: 0 1px 2px rgba(15,23,42,.04);
      overflow: hidden !important;
      background: var(--azia-card, #fff) !important;
    }

    .mailbox-body-header{
      flex: 0 0 auto;
      position: relative;
      top: auto;
      z-index: 40;
      overflow: visible;
      background: var(--azia-card, #fff) !important;
      border-bottom: 1px solid var(--msb-palette-border, #eef2f7);
      border-top: 0 !important;
      padding: 12px 16px !important;
      margin: 0 !important;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .mailbox-body-header h6{ margin: 0 0 2px; font-size: 14px; font-weight: 800; color: var(--azia-text, #0f172a) !important; }
    .mailbox-body-header .nav,
    .mailbox-body-header .mb-head-nav{
      gap: 2px; margin: 0 12px 0 0 !important; font-size: inherit !important; line-height: normal !important;
      flex:0 0 auto;
    }
    .mailbox-body-header .nav-link{
      width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
      color: var(--azia-muted, #64748b) !important; border-radius: 8px; padding: 0;
    }
    .mailbox-body-header .nav-link:hover{
      background: var(--msb-palette-hover-bg, #f1f5f9) !important;
      color: var(--azia-text, #0f172a) !important;
    }
    .mb-head-product{
      display:inline-flex;align-items:center;gap:8px;min-width:0;max-width:min(280px,42vw);
      padding:4px 8px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;flex:0 1 auto;
      margin-right:16px;
    }
    .mb-head-product[hidden]{display:none!important;}
    .mb-head-product img{width:36px;height:36px;border-radius:6px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
    .mb-head-product > div{min-width:0;}
    .mb-head-product strong{display:block;font-size:12px;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .mb-head-product .mb-head-product-id{display:block;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .mb-head-product .mb-head-product-biz{display:block;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .mb-head-product a{flex:0 0 auto;font-size:11px;font-weight:800;color:#2563eb;text-decoration:none;padding:4px 8px;border-radius:6px;}
    .mb-head-product a:hover{background:#eff6ff;text-decoration:none;}
    .mb-more{position:relative;flex:0 0 auto;margin-left:4px;z-index:45;}
    .mb-more-btn{
      width:32px;height:32px;border:0;border-radius:8px;background:transparent;color:#64748b;
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
    }
    .mb-more-btn:hover,.mb-more.is-open .mb-more-btn{background:#f1f5f9;color:#0f172a;outline:2px solid #2563eb;outline-offset:1px;}
    .mb-more-menu{
      display:none;position:fixed;left:0;top:0;z-index:2147483000;min-width:280px;max-width:min(380px,92vw);
      padding:6px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;
      box-shadow:0 12px 32px rgba(15,23,42,.22);
    }
    .mb-more-menu.mb-open{display:block;}
    body > .mb-more-menu.mb-open{display:block;}
    .mb-history-head{
      padding:8px 12px 6px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748b;
    }
    .mb-history-list{max-height:280px;overflow:auto;}
    .mb-history-empty{padding:10px 12px;font-size:12px;color:#64748b;}
    .mb-history-item{
      display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:0;border-radius:8px;
      background:transparent;text-align:left;cursor:pointer;
    }
    .mb-history-item:hover,.mb-history-item.is-active{background:#f1f5f9;}
    .mb-history-item img{width:32px;height:32px;border-radius:6px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;}
    .mb-history-item-label{display:block;font-size:13px;font-weight:800;color:#0f172a;line-height:1.25;}
    .mb-history-item-meta{display:block;margin-top:2px;font-size:11px;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .mb-history-bar{
      display:none;align-items:center;gap:10px;padding:8px 16px;border-bottom:1px solid #eef2f7;background:#fff;flex:0 0 auto;
    }
    .mb-history-bar.is-open{display:flex;}
    .mb-history-back{
      border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#334155;
      font-size:12px;font-weight:800;padding:6px 10px;cursor:pointer;
    }
    .mb-history-title{font-size:13px;font-weight:800;color:#0f172a;margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .mb-row[hidden]{display:none!important;}

    .conversation-scroll{
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 14px 12px 12px 16px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      box-sizing: border-box;
    }
    .mb-empty{
      margin: auto;
      text-align: center;
      color: var(--azia-muted, #94a3b8) !important;
      font-size: 13px;
      font-weight: 600;
      padding: 28px 16px;
    }
    .mb-row{
      display: flex;
      gap: 8px;
      align-items: flex-end;
      max-width: min(78%, 560px);
    }
    .mb-row.me{ align-self: flex-end; flex-direction: row-reverse; margin-right: 0; }
    .mb-row.them{ align-self: flex-start; margin-left: 0; }
    .mb-ava{
      width: 28px; height: 28px; border-radius: 999px; flex: 0 0 28px;
      display: inline-flex; align-items: center; justify-content: center;
      color: #fff; font-size: 11px; font-weight: 800;
    }
    .mb-row.me .mb-ava{ display: none; }
    .mb-bubble-wrap{ min-width: 0; display: flex; flex-direction: column; gap: 4px; }
    .mb-row.me .mb-bubble-wrap{ align-items: flex-end; }
    .mb-row.them .mb-bubble-wrap{ align-items: flex-start; }
    .mb-meta{
      display: flex; align-items: center; gap: 6px;
      font-size: 11px; color: var(--azia-muted, #94a3b8); margin: 2px 2px 0;
    }
    .mb-row.me .mb-meta{ justify-content: flex-end; }
    .mb-bubble{
      padding: 10px 12px; border-radius: 4px;
      font-size: 13.5px; line-height: 1.45;
      white-space: pre-wrap; word-break: break-word;
      background: var(--azia-card, #fff); color: var(--azia-text, #0f172a);
      border: 1px solid var(--msb-palette-border, #e5e7eb);
    }
    .mb-row.me .mb-bubble{
      background: var(--msb-palette-action, #2563eb); color: #fff; border-color: var(--msb-palette-action, #2563eb);
    }
    .mb-row.me .mb-bubble a{ color: #dbeafe; }
    .mb-atts{ display: flex; flex-wrap: wrap; gap: 6px; margin-top: 0; }

    .composer-wrap{
      flex: 0 0 auto;
      position: relative;
      bottom: auto;
      z-index: 2;
      background: var(--azia-card, #fff) !important;
      border-top: 1px solid var(--msb-palette-border, #eef2f7);
      padding: 10px 12px 12px;
    }
    .mb-compose-bar{
      display: grid;
      grid-template-columns: minmax(0,1fr) auto;
      gap: 8px;
      align-items: end;
    }
    .mb-compose-input{
      border: 1px solid var(--msb-palette-border, #e2e8f0);
      border-radius: 12px;
      background: var(--msb-palette-input-bg, var(--msb-palette-surface-2, #f8fafc)) !important;
      padding: 8px 10px;
      min-height: 44px;
    }
    .mb-compose-input textarea{
      width: 100%;
      min-height: 40px;
      max-height: 120px;
      border: 0;
      background: transparent;
      resize: none;
      outline: none;
      font-size: 13.5px;
      line-height: 1.4;
      color: var(--azia-text, #0f172a) !important;
      padding: 0;
      margin: 0;
    }
    .mb-compose-actions{ display: flex; gap: 6px; align-items: center; }
    .mb-compose-actions .btn{
      height: 40px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 800;
      padding: 0 14px;
    }
    .mb-compose-actions .btn-attach{
      width: 40px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid var(--msb-palette-border, #e2e8f0);
      background: var(--azia-card, #fff);
      color: var(--azia-muted, #64748b);
    }
    #attachmentList{ margin-top: 8px; }

    .mailbox-right{
      position: relative !important;
      flex: 0 0 220px !important;
      width: 220px !important;
      min-width: 180px;
      display: flex !important;
      flex-direction: column !important;
      overflow: hidden !important;
      border-left: 1px solid var(--msb-palette-border, #eef2f7);
      background: var(--azia-card, #fff) !important;
    }
    .mailbox-right .inputgroup{ flex: 0 0 auto; padding: 12px; }
    .mailboxlist{
      flex: 1 1 auto;
      min-height: 0;
      overflow: auto;
      padding: 0 8px 10px;
    }
    body.mb-public-view .mailbox-right{ display: none !important; }

    @media (max-width: 1100px){
      .mailbox-right{ display: none !important; }
      .mailbox-left{ flex-basis: 260px !important; width: 260px !important; max-width: 40% !important; }
    }
    @media (max-width: 760px){
      .card-mailbox > .card-body{ flex-direction: column !important; }
      .mailbox-left{
        flex: 0 0 auto !important;
        width: 100% !important;
        max-width: none !important;
        max-height: 42%;
        border-right: 0 !important;
        border-bottom: 1px solid var(--msb-palette-border, #eef2f7) !important;
      }
    }
  </style>
</head>

<body class="azia-admin<?= $isPublicMailbox ? ' mb-public-view' : '' ?>">

<?php
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open('Mailbox', [
    'title' => 'Mailbox',
    'description' => $isPublicMailbox
        ? ($mailboxLaneLabel . ' — conversations stay in this lane only.')
        : 'Your admin workspace messages.',
]);
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="card card-mailbox <?php echo ($peerUser!=='' ? 'show-msg' : ''); ?>">
      <div class="card-header">
        <nav class="nav">
          <a href="<?php echo h($isPublicMailbox ? $mailboxInboxHref : 'mailbox.php'); ?>" class="nav-link active"><?php echo h($isPublicMailbox ? $mailboxLaneLabel : 'Inbox'); ?></a>
          <?php if (!$isPublicMailbox): ?>
            <a href="compose.php" class="nav-link">Compose</a>
          <?php else: ?>
            <a href="<?php echo h($mailboxHelpBackUrl); ?>" class="nav-link">Back to Help</a>
          <?php endif; ?>
        </nav>
      </div>

      <div class="card-body">

        <!-- LEFT -->
        <div class="mailbox-left">
          <div class="input-group">
            <input type="search" class="form-control" name="search" placeholder="Search messages" id="mbThreadSearch">
          </div>

          <div class="mailbox-list" id="mbThreadList">
            <?php if (empty($threads)): ?>
              <div class="mb-empty">No conversations yet.</div>
            <?php else: ?>
              <?php foreach ($threads as $t):
                $active = ($peerCode !== '' && (strcasecmp((string)$t['peer_username'], $peerCode) === 0 || strcasecmp((string)$t['peer_code'], $peerCode) === 0));
                $unread = (int)($t['unread_count'] ?? 0);
                $lastMsgHtml  = (string)($t['last_message'] ?? '');
                $lastMsgPlain = preview_plain_from_html($lastMsgHtml);

                $peerDisplay = (string)($t['peer_display'] ?? '');
                $peerKey     = (string)($t['peer_code'] ?? $t['peer_username'] ?? $peerDisplay);

                $ini = avatar_initials($peerDisplay !== '' ? $peerDisplay : $peerKey);
                $bg  = avatar_color($peerKey);
                $listCover = '';
                if ($isPublicMailbox && strpos((string)$t['peer_code'], '@') !== false) {
                    $listProf = mailbox_public_peer_profile($dbh, (string)$t['peer_code']);
                    $listCover = (string)($listProf['avatar'] ?? '');
                    if ($listProf['name'] !== '') {
                        $peerDisplay = $listProf['name'];
                    }
                }
                $searchHay = strtolower($peerDisplay . ' ' . (string)$t['peer_code'] . ' ' . $lastMsgPlain);
              ?>
              <a href="mailbox.php?peer=<?php echo urlencode((string)$t['peer_code']); ?><?php echo h($mailboxViewQs); ?>"
                 class="media <?php echo $active ? 'bg-gray-100' : ''; ?>"
                 data-search="<?php echo h($searchHay); ?>">
                <?php if ($listCover !== ''): ?>
                <div class="wd-50 rounded-circle" style="width:40px;height:40px;overflow:hidden;border:1px solid rgba(0,0,0,.08);flex:0 0 40px;border-radius:999px;">
                  <img src="<?php echo h($listCover); ?>" alt="" style="width:40px;height:40px;object-fit:cover;display:block;">
                </div>
                <?php else: ?>
                <div class="wd-50 rounded-circle"
                     style="width:40px;height:40px;flex:0 0 40px;display:flex;align-items:center;justify-content:center;
                            background:<?php echo h($bg); ?>;color:#fff;font-weight:800;
                            letter-spacing:.5px;font-size:13px;border:1px solid rgba(0,0,0,.08);border-radius:999px;">
                  <?php echo h($ini); ?>
                </div>
                <?php endif; ?>

                <div class="media-body" style="min-width:0;">
                  <h6 class="tx-14 tx-inverse mg-b-2">
                    <?php echo h($peerDisplay !== '' ? $peerDisplay : (string)$t['peer_display']); ?>
                    <?php if ($unread > 0): ?>
                      <span class="badge badge-danger mg-l-5"><?php echo $unread; ?></span>
                    <?php endif; ?>
                  </h6>
                  <?php if ($isPublicMailbox): ?>
                    <span class="d-block tx-11 tx-gray-600"><?php echo h((string)$t['peer_code']); ?></span>
                  <?php endif; ?>
                  <span class="d-block tx-12"><?php echo h(fmt_dt((string)$t['last_time'])); ?></span>
                  <p class="tx-13 mg-t-5 mg-b-0"><?php echo h(short_preview($lastMsgPlain)); ?></p>
                </div>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- CENTER -->
        <div class="mailbox-body">
          <?php if ($peerCode === '' || $peerUser === ''): ?>
            <div class="mb-empty">Select a conversation on the left to view messages.</div>
          <?php else: ?>

          <div class="mailbox-body-header">
            <div class="media align-items-center" style="min-width:0;flex:1 1 auto;">
              <?php if ($peerCoverUrl !== ''): ?>
              <div class="wd-50 rounded-circle" style="width:40px;height:40px;overflow:hidden;border:1px solid rgba(0,0,0,.08);flex:0 0 40px;border-radius:999px;">
                <img src="<?php echo h($peerCoverUrl); ?>" alt="" style="width:40px;height:40px;object-fit:cover;display:block;">
              </div>
              <?php else: ?>
              <div class="wd-50 rounded-circle"
                   style="width:40px;height:40px;flex:0 0 40px;display:flex;align-items:center;justify-content:center;
                          background:<?php echo h($headerBg); ?>;color:#fff;font-weight:800;
                          letter-spacing:.5px;font-size:13px;border:1px solid rgba(0,0,0,.08);border-radius:999px;">
                <?php echo h($headerIni); ?>
              </div>
              <?php endif; ?>

              <div class="media-body mg-l-12" style="min-width:0;">
                <h6 class="tx-14 tx-inverse mg-b-5"><?php echo h($headerPeerLabel); ?></h6>
                <?php if ($peerMetaLine !== ''): ?>
                  <div class="tx-12 tx-gray-600" style="margin-top:-2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo h($peerMetaLine); ?></div>
                <?php endif; ?>
                <div class="tx-12 tx-gray-600" style="margin-top:2px;">
                  <?php echo h($isPublicMailbox ? $mailboxLaneLabel : ('Subject: ' . $currentSubject)); ?>
                </div>
              </div>
            </div>
            <?php
              $mbProdId = (int)($mbProductFocus['id'] ?? 0);
              $mbProdTitle = trim((string)($mbProductFocus['title'] ?? ''));
              $mbProdCover = trim((string)($mbProductFocus['cover'] ?? ''));
              $mbProdCode = trim((string)($mbProductFocus['code'] ?? ''));
              $mbProdHref = trim((string)($mbProductFocus['buyer_href'] ?? ''));
              $mbProdBiz = trim((string)($mbProductFocus['seller_business'] ?? ''));
              $mbProdConcern = trim((string)($mbProductFocus['concern_label'] ?? ''));
              $mbProdDispute = trim((string)($mbProductFocus['dispute_code'] ?? ''));
              $mbProdBizLine = '';
              if ($mbProdConcern !== '') {
                  $mbProdBizLine = $mbProdDispute !== '' ? ($mbProdConcern . ' · ' . $mbProdDispute) : $mbProdConcern;
              } elseif ($mbProdBiz !== '') {
                  $mbProdBizLine = 'Seller: ' . $mbProdBiz;
              }
              $mbProdIdLabel = $mbProdId > 0 ? ('Product ID #' . $mbProdId) : '';
              if ($mbProdIdLabel !== '' && $mbProdCode !== '') {
                  $mbProdIdLabel .= ' · ' . $mbProdCode;
              }
              if ($mbProdTitle === '' && $mbProdId > 0) {
                  $mbProdTitle = 'Product #' . $mbProdId;
              }
              if ($mbProdHref === '' || preg_match('#(^|/)product_detail\.php(\?|$)#i', $mbProdHref)) {
                  $mbProdHref = $mbProdId > 0 ? ('open_product_detail.php?id=' . $mbProdId) : '';
              }
            ?>
            <?php if ($isPublicMailbox && $mbProdId > 0): ?>
              <div class="mb-head-product" id="mbHeadProduct">
                <img src="<?php echo h($mbProdCover !== '' ? $mbProdCover : ('../public_user/avatar.php?name=' . rawurlencode($mbProdTitle))); ?>" alt="">
                <div>
                  <strong><?php echo h($mbProdTitle); ?></strong>
                  <?php if ($mbProdIdLabel !== ''): ?><span class="mb-head-product-id"><?php echo h($mbProdIdLabel); ?></span><?php endif; ?>
                  <?php if ($mbProdBizLine !== ''): ?><span class="mb-head-product-biz"><?php echo h($mbProdBizLine); ?></span><?php endif; ?>
                </div>
                <?php if ($mbProdHref !== ''): ?><a href="<?php echo h($mbProdHref); ?>">View</a><?php endif; ?>
              </div>
            <?php elseif ($isPublicMailbox): ?>
              <div class="mb-head-product" id="mbHeadProduct" hidden></div>
            <?php endif; ?>
            <nav class="nav mb-head-nav" style="align-items:center;overflow:visible;position:relative;z-index:46;flex:0 0 auto;">
              <?php if ($isPublicMailbox): ?>
                <?php
                  $mbHistoryRows = $mbProductHistory;
                  if (empty($mbHistoryRows) && !empty($mbProdId) && (int)$mbProdId > 0) {
                      $mbHistoryRows = [[
                          'product_id' => (int)$mbProdId,
                          'product_title' => (string)$mbProdTitle,
                          'product_cover' => (string)$mbProdCover,
                          'code' => (string)$mbProdCode,
                          'concern_label' => (string)$mbProdConcern,
                          'dispute_code' => (string)$mbProdDispute,
                          'time_label' => '',
                      ]];
                  }
                ?>
                <div class="mb-more" id="mbMore">
                  <button type="button" class="mb-more-btn" id="mbMoreBtn" aria-label="Product history" aria-haspopup="menu" aria-expanded="false">
                    <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
                  </button>
                </div>
                <div class="mb-more-menu" id="mbMoreMenu" role="menu" aria-label="Product history">
                  <div class="mb-history-head">Product history</div>
                  <div class="mb-history-list" id="mbHistoryList">
                    <?php if (empty($mbHistoryRows)): ?>
                      <div class="mb-history-empty">No product history yet.</div>
                    <?php else: ?>
                      <?php foreach ($mbHistoryRows as $hCase):
                        $hPid = (int)($hCase['product_id'] ?? 0);
                        if ($hPid <= 0) {
                            continue;
                        }
                        $hTitle = trim((string)($hCase['product_title'] ?? ('Product #' . $hPid)));
                        $hCover = trim((string)($hCase['product_cover'] ?? ''));
                        $hCode = trim((string)($hCase['code'] ?? ''));
                        $hConcern = trim((string)($hCase['concern_label'] ?? ''));
                        $hDsp = trim((string)($hCase['dispute_code'] ?? ''));
                        $hMeta = $hConcern !== '' ? $hTitle : trim($hTitle . (!empty($hCase['time_label']) ? (' · ' . $hCase['time_label']) : ''));
                        $hImg = $hCover !== '' ? $hCover : ('../public_user/avatar.php?name=' . rawurlencode($hTitle));
                      ?>
                        <button type="button"
                                class="mb-history-item"
                                role="menuitem"
                                data-product-id="<?= (int)$hPid ?>"
                                data-product-title="<?= h($hTitle) ?>"
                                data-product-cover="<?= h($hCover) ?>"
                                data-product-code="<?= h($hCode) ?>"
                                data-concern-label="<?= h($hConcern) ?>"
                                data-dispute-code="<?= h($hDsp) ?>">
                          <img src="<?= h($hImg) ?>" alt="">
                          <span>
                            <span class="mb-history-item-label"><?= h('Product ID #' . $hPid . ' history') ?></span>
                            <?php if ($hMeta !== ''): ?><span class="mb-history-item-meta"><?= h($hMeta) ?></span><?php endif; ?>
                          </span>
                        </button>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
              <a href="javascript:window.print()" class="nav-link" title="Print"><i class="icon ion-printer"></i></a>
            </nav>
          </div>

          <?php if ($isPublicMailbox): ?>
            <div class="mb-history-bar" id="mbHistoryBar">
              <button type="button" class="mb-history-back" id="mbHistoryBack">← Back</button>
              <p class="mb-history-title" id="mbHistoryTitle">Product history</p>
            </div>
          <?php endif; ?>

          <?php if ($errorMsg !== ''): ?>
            <div class="alert alert-danger" style="margin:10px 16px 0;"><?php echo h($errorMsg); ?></div>
          <?php endif; ?>

          <div class="conversation-scroll" id="conversationScroll">
            <?php if (empty($messages)): ?>
              <div class="mb-empty">No messages yet. Send a reply below.</div>
            <?php else: ?>
              <?php
                $mbRunningPid = 0;
                foreach ($messages as $m):
                $senderCode  = (string)($m['sender'] ?? '');
                $isMe = ($senderCode === $meCode) || ($isPublicMailbox && strcasecmp($senderCode, 'Admin') === 0);
                $senderLabel = $senderMap[$senderCode] ?? ($isMe ? 'You' : $senderCode);
                $senderLabelForInitials = $senderNameForInitials[$senderCode] ?? $senderLabel;
                if ($isMe) $senderLabelForInitials = $meLabelForInitials;
                $mIni = avatar_initials($senderLabelForInitials);
                $mBg  = avatar_color($senderCode !== '' ? $senderCode : $senderLabelForInitials);
                $bodyHtml = (string)($m['feedbackdata'] ?? '');
                // Prefer plain text for bubble readability (strip XML/HTML leftovers from old sanitizer).
                $plainBody = mailbox_public_plain_text($bodyHtml);
                $msgPid = 0;
                if ($isPublicMailbox && $plainBody !== '') {
                    $plainBody = preg_replace(
                        '/^\[(?:Seller help|Seller dispute|Help|Personal help|Publisher help|Dispute|Admin dispute)\]\s*/i',
                        '',
                        $plainBody
                    ) ?? $plainBody;
                    $plainBody = trim($plainBody);
                    // Capture Product ID before stripping card-context lines from the bubble.
                    if (preg_match('/Product\s*ID\s*#\s*(\d+)\b/i', $plainBody, $pidM)) {
                        $msgPid = (int)$pidM[1];
                        $mbRunningPid = $msgPid;
                    } elseif ($mbRunningPid > 0) {
                        $msgPid = $mbRunningPid;
                    }
                    // When a product card is shown, drop trailing context lines (shown on the card).
                    if ($mbProdId > 0) {
                        $lines = preg_split('/\R/u', $plainBody) ?: [];
                        $kept = [];
                        foreach ($lines as $line) {
                            $t = trim((string)$line);
                            if ($t === '') {
                                continue;
                            }
                            if (preg_match('/^(Org\s*ID|Order(?:\s*code)?|Product\s*ID\s*#|Product:|Dispute\s*ID:|Seller business|Seller response due)\s*:?\s*/i', $t)) {
                                continue;
                            }
                            // Also strip inline "— Org ID: N … Product ID #N" tails after em dash.
                            $t = preg_replace(
                                '/\s*[—\-]\s*(?:Org\s*ID\s*:\s*\d+\s*)?(?:Order\s*:\s*.+?\s*)?(?:Product\s*ID\s*#\s*\d+\s*)?$/iu',
                                '',
                                $t
                            ) ?? $t;
                            $t = trim($t);
                            if ($t === '') {
                                continue;
                            }
                            $kept[] = $t;
                        }
                        $plainBody = trim(implode("\n", $kept));
                    }
                } elseif ($isPublicMailbox && $mbRunningPid > 0) {
                    $msgPid = $mbRunningPid;
                }
                $attRaw = trim((string)($m['attachment'] ?? ''));
                $attList = [];
                if ($attRaw !== '') {
                    $j = json_decode($attRaw, true);
                    if (is_array($j)) {
                        foreach ($j as $one) {
                            if (!is_array($one)) continue;
                            $p = trim((string)($one['path'] ?? ''));
                            if ($p === '' || strpos($p, 'storage/') !== 0) continue;
                            $attList[] = [
                                'path' => $p,
                                'original' => trim((string)($one['original'] ?? '')),
                                'mime' => trim((string)($one['mime'] ?? '')),
                            ];
                        }
                    }
                }
              ?>
                <div class="mb-row <?= $isMe ? 'me' : 'them' ?>"<?= $msgPid > 0 ? ' data-product-id="' . (int)$msgPid . '"' : '' ?>>
                  <div class="mb-ava" style="background:<?= h($mBg) ?>;"><?= h($mIni) ?></div>
                  <div class="mb-bubble-wrap">
                    <div class="mb-bubble"><?= h($plainBody !== '' ? $plainBody : ' ') ?></div>
                    <?php if (!empty($attList)): ?>
                      <div class="mb-atts">
                        <?php foreach ($attList as $a):
                          $name = $a['original'] ?: basename($a['path']);
                        ?>
                          <button type="button"
                                  class="btn btn-sm btn-outline-secondary js-att-open"
                                  data-path="<?php echo h($a['path']); ?>"
                                  data-name="<?php echo h($name); ?>"
                                  data-mime="<?php echo h($a['mime']); ?>">
                            <?php echo h($name); ?>
                          </button>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <div class="mb-meta">
                      <strong style="color:inherit;font-weight:700;"><?= h($isMe ? 'You' : $senderLabel) ?></strong>
                      <span><?= h(fmt_dt((string)$m['created_at'])) ?></span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
            <div id="scrollAnchor" style="height:1px;"></div>
          </div>

          <div class="composer-wrap">
            <form method="POST" id="mbForm"
                  action="mailbox.php?peer=<?php echo urlencode($peerCode); ?><?php echo h($mailboxViewQs); ?><?php echo ($threadUid!==''?'&t='.urlencode($threadUid):''); ?>">
              <div class="mb-compose-bar">
                <div class="mb-compose-input">
                  <textarea name="message" id="message" rows="2" placeholder="Write a reply…" <?= $peerUser===''?'disabled':'' ?>></textarea>
                  <input type="hidden" name="attachments" id="attachments" value="">
                </div>
                <div class="mb-compose-actions">
                  <input type="file" id="attPicker" style="display:none" multiple>
                  <button type="button" class="btn btn-attach" id="attPickBtn" title="Attach files" <?= $peerUser===''?'disabled':'' ?>>
                    <i class="fa fa-paperclip"></i>
                  </button>
                  <button type="submit" class="btn btn-primary" <?php echo ($peerUser===''?'disabled':''); ?>>Send</button>
                </div>
              </div>
              <div id="attachmentList"></div>
            </form>
          </div>

          <?php endif; ?>
        </div>

        <!-- RIGHT -->
        <div class="mailbox-right">
          <div class="inputgroup">
            <input type="search" class="form-control" name="search" placeholder="Search messages">
            <span class="input-group-btn">
              <button class="btn"><i class="fa fa-search"></i></button>
            </span>
          </div>

          <div class="mailboxlist">
            <?php if ($peerUser === '' || $channelForPeer === ''): ?>
              <div class="pd-15 tx-12 tx-gray-600">Select a conversation (left) to view subject history.</div>
            <?php elseif (empty($subjectThreads)): ?>
              <div class="pd-15 tx-12 tx-gray-600">No subject history yet.</div>
            <?php else: ?>
              <?php foreach ($subjectThreads as $st):
                $title = (string)($st['title'] ?? '');
                $uid = thread_uid($title);
                $subject = thread_subject($title);
                $active = ($currentThreadTitle !== '' && $title === $currentThreadTitle);
                $lastMsgHtml  = (string)($st['last_message'] ?? '');
                $lastMsgPlain = preview_plain_from_html($lastMsgHtml);
                $cnt = (int)($st['msg_count'] ?? 0);
              ?>
                <a href="mailbox.php?peer=<?php echo urlencode((string)$peerCode); ?><?php echo h($mailboxViewQs); ?>&t=<?php echo urlencode($uid); ?>"
                   class="media <?php echo $active ? 'bg-gray-100' : ''; ?>"
                   style="padding:8px;">
                  <div class="media-body">
                    <div class="d-flex justify-content-between">
                      <h6 class="tx-13 mg-b-0 tx-inverse"><?php echo h($subject !== '' ? $subject : 'No Subject'); ?></h6>
                      <span class="tx-11 tx-gray-600"><?php echo h(fmt_dt((string)($st['last_time'] ?? ''))); ?></span>
                    </div>
                    <p class="tx-12 mg-b-0 tx-gray-600"><?php echo h(short_preview($lastMsgPlain, 70)); ?></p>
                    <span class="tx-11 tx-gray-600">Messages: <?php echo (int)$cnt; ?></span>
                  </div>
                </a>
                <hr style="margin:6px 0;">
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../js/shamcey.js"></script>

<script>
/* Product history ⋯ — standalone so it cannot fail with the mailbox jQuery block */
(function () {
  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }
  ready(function () {
    var moreBtn = document.getElementById('mbMoreBtn');
    var moreWrap = document.getElementById('mbMore');
    var moreMenu = document.getElementById('mbMoreMenu');
    var historyList = document.getElementById('mbHistoryList');
    var historyBar = document.getElementById('mbHistoryBar');
    var historyBack = document.getElementById('mbHistoryBack');
    var historyTitle = document.getElementById('mbHistoryTitle');
    var productEl = document.getElementById('mbHeadProduct');
    var thread = document.getElementById('conversationScroll');
    if (!moreBtn || !moreMenu) return;

    var open = false;
    var viewingPid = 0;

    function esc(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function place() {
      var r = moreBtn.getBoundingClientRect();
      var w = Math.max(280, Math.min(360, window.innerWidth - 16));
      moreMenu.style.width = w + 'px';
      moreMenu.style.left = Math.min(window.innerWidth - w - 8, Math.max(8, r.right - w)) + 'px';
      moreMenu.style.top = (r.bottom + 6) + 'px';
    }
    function closeMenu() {
      open = false;
      moreMenu.classList.remove('mb-open');
      moreMenu.style.display = 'none';
      if (moreWrap) moreWrap.classList.remove('is-open');
      moreBtn.setAttribute('aria-expanded', 'false');
    }
    function openMenu() {
      open = true;
      if (moreMenu.parentElement !== document.body) {
        document.body.appendChild(moreMenu);
      }
      moreMenu.classList.add('mb-open');
      moreMenu.style.display = 'block';
      if (moreWrap) moreWrap.classList.add('is-open');
      moreBtn.setAttribute('aria-expanded', 'true');
      place();
    }
    function setProductCard(meta, pid) {
      if (!productEl) return;
      pid = parseInt(pid || 0, 10) || 0;
      if (pid <= 0) {
        productEl.hidden = true;
        productEl.innerHTML = '';
        return;
      }
      var title = String((meta && (meta.product_title || meta.title)) || ('Product #' + pid));
      var cover = String((meta && (meta.product_cover || meta.cover)) || '');
      var code = String((meta && meta.code) || '');
      var concern = String((meta && meta.concern_label) || '');
      var dispute = String((meta && meta.dispute_code) || '');
      var idLabel = 'Product ID #' + pid + (code ? (' · ' + code) : '');
      var bizLine = concern ? (dispute ? (concern + ' · ' + dispute) : concern) : '';
      var img = cover || ('../public_user/avatar.php?name=' + encodeURIComponent(title));
      productEl.hidden = false;
      productEl.innerHTML =
        '<img src="' + esc(img) + '" alt="">' +
        '<div><strong>' + esc(title) + '</strong>' +
          '<span class="mb-head-product-id">' + esc(idLabel) + '</span>' +
          (bizLine ? '<span class="mb-head-product-biz">' + esc(bizLine) + '</span>' : '') +
        '</div>' +
        '<a href="open_product_detail.php?id=' + pid + '">View</a>';
    }
    function openHistory(pid, meta) {
      pid = parseInt(pid || 0, 10) || 0;
      if (pid <= 0 || !thread) return;
      viewingPid = pid;
      closeMenu();
      if (historyBar) historyBar.classList.add('is-open');
      if (historyTitle) historyTitle.textContent = 'Product ID #' + pid + ' history';
      setProductCard(meta || {}, pid);
      var any = false;
      Array.prototype.forEach.call(thread.querySelectorAll('.mb-row'), function (row) {
        var show = (parseInt(row.getAttribute('data-product-id') || '0', 10) || 0) === pid;
        row.hidden = !show;
        if (show) any = true;
      });
      var empty = thread.querySelector('.mb-empty-history');
      if (!any) {
        if (!empty) {
          empty = document.createElement('div');
          empty.className = 'mb-empty mb-empty-history';
          thread.insertBefore(empty, thread.firstChild);
        }
        empty.hidden = false;
        empty.textContent = 'No messages saved for Product ID #' + pid + '.';
      } else if (empty) {
        empty.hidden = true;
      }
    }
    function exitHistory() {
      viewingPid = 0;
      if (historyBar) historyBar.classList.remove('is-open');
      if (!thread) return;
      Array.prototype.forEach.call(thread.querySelectorAll('.mb-row'), function (row) {
        row.hidden = false;
      });
      var empty = thread.querySelector('.mb-empty-history');
      if (empty) empty.hidden = true;
    }

    moreBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (open) closeMenu();
      else openMenu();
    }, true);

    if (historyList) {
      historyList.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('.mb-history-item') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        openHistory(btn.getAttribute('data-product-id'), {
          product_title: btn.getAttribute('data-product-title') || '',
          product_cover: btn.getAttribute('data-product-cover') || '',
          code: btn.getAttribute('data-product-code') || '',
          concern_label: btn.getAttribute('data-concern-label') || '',
          dispute_code: btn.getAttribute('data-dispute-code') || ''
        });
      });
    }
    if (historyBack) historyBack.addEventListener('click', exitHistory);

    document.addEventListener('click', function (e) {
      if (!open) return;
      if (moreBtn.contains(e.target) || moreMenu.contains(e.target)) return;
      closeMenu();
    });
    window.addEventListener('resize', function () { if (open) place(); });
  });
})();
</script>

<script>
  $(function () {
    'use strict';

    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

    var attachments = [];

    function esc(s){
      return String(s || '').replace(/[&<>"']/g, function(m){
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
      });
    }

    function guessKind(mime, path){
      var ext = (path || '').split('.').pop().toLowerCase();
      if ((mime || '').indexOf('image/') === 0 || ['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0) return 'image';
      if ((mime || '').indexOf('video/') === 0 || ['mp4','webm','ogv','ogg','mov'].indexOf(ext) >= 0) return 'video';
      if (mime === 'application/pdf' || ext === 'pdf') return 'pdf';
      return 'text';
    }

    function getConversationEl(){ return document.getElementById('conversationScroll'); }

    function nearBottom(el, px){
      if (!el) return true;
      return (el.scrollHeight - el.scrollTop - el.clientHeight) <= (px || 80);
    }

    function scrollToBottom(force){
      var el = getConversationEl();
      if (!el) return;
      if (force || nearBottom(el, 120)) {
        el.scrollTop = el.scrollHeight + 200;
      }
    }

    function settleBottom(force){
      scrollToBottom(!!force);
      requestAnimationFrame(function(){ scrollToBottom(!!force); });
      setTimeout(function(){ scrollToBottom(!!force); }, 60);
      setTimeout(function(){ scrollToBottom(!!force); }, 180);
    }

    function renderAttachments(){
      var $box = $('#attachmentList').empty();
      $('#attachments').val(attachments.length ? JSON.stringify(attachments) : '');
      if (!attachments.length) return;
      attachments.forEach(function(a, idx){
        var name = a.original || a.path || ('file-' + (idx+1));
        $box.append(
          '<span class="badge badge-light" style="margin:0 6px 6px 0;padding:6px 8px;border:1px solid #e2e8f0;">' +
            esc(name) +
            ' <a href="javascript:void(0)" class="att-rm" data-idx="'+idx+'" style="margin-left:6px;color:#b91c1c;">×</a>' +
          '</span>'
        );
      });
    }

    function uploadOneFile(file){
      if (!file) return;
      var fd = new FormData();
      fd.append('file', file);
      $.ajax({
        url: 'ajax/mailbox_upload.php',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp){
          if (resp && resp.ok) {
            attachments.push({
              path: resp.path || resp.file,
              original: resp.original || (resp.file || resp.path),
              mime: resp.mime || ''
            });
            renderAttachments();
          } else {
            alert((resp && resp.error) ? resp.error : 'Upload failed');
          }
        },
        error: function(){ alert('Upload failed (server error).'); }
      });
    }

    function openPreview(a){
      if (!a) return;
      var url = a.url || ('../attachment/' + (a.path || ''));
      var kind = guessKind(a.mime, a.path || url);
      var $body = $('#attModalBody').empty();
      $('#attModalLabel').text(a.original || a.path || 'Attachment');
      if (kind === 'image') {
        $body.html('<img src="'+esc(url)+'" style="max-width:100%;height:auto;border-radius:8px;">');
      } else if (kind === 'pdf') {
        $body.html('<iframe src="'+esc(url)+'" style="width:100%;height:70vh;border:0;border-radius:8px;"></iframe>');
      } else {
        $body.html('<a class="btn btn-primary" href="'+esc(url)+'" target="_blank" rel="noopener">Download / open</a>');
      }
      $('#attModal').modal('show');
    }

    // Auto-grow textarea
    var $ta = $('#message');
    function growTa(){
      if (!$ta.length) return;
      $ta.css('height', 'auto');
      $ta.css('height', Math.min(120, Math.max(40, $ta[0].scrollHeight)) + 'px');
    }
    $ta.on('input', growTa);
    growTa();

    // Thread search
    $('#mbThreadSearch').on('input', function(){
      var q = String($(this).val() || '').toLowerCase().trim();
      $('#mbThreadList > a.media').each(function(){
        var hay = String($(this).attr('data-search') || '').toLowerCase();
        $(this).toggle(!q || hay.indexOf(q) !== -1);
      });
    });

    $('#attPickBtn').on('click', function(){ $('#attPicker').trigger('click'); });
    $('#attPicker').on('change', function(){
      var fs = this.files || [];
      for (var i=0; i<fs.length; i++) uploadOneFile(fs[i]);
      this.value = '';
    });
    $(document).on('click', '.att-rm', function(){
      var idx = parseInt($(this).attr('data-idx'), 10);
      if (!isNaN(idx)) { attachments.splice(idx, 1); renderAttachments(); }
    });
    $(document).on('click', '.js-att-open', function(){
      openPreview({
        path: $(this).data('path'),
        mime: $(this).data('mime') || '',
        original: $(this).data('name') || '',
        url: '../attachment/' + $(this).data('path')
      });
    });

    settleBottom(true);
    $(window).on('load', function(){ settleBottom(true); });

    $('#mbForm').on('submit', function (e) {
      e.preventDefault();
      var code = String($('#message').val() || '').trim();
      if (!code && !attachments.length) return;

      var $form = $(this);
      var payload = {
        ajax: '1',
        message: code,
        attachments: attachments.length ? JSON.stringify(attachments) : ''
      };

      $.ajax({
        url: $form.attr('action'),
        method: 'POST',
        data: payload,
        dataType: 'json',
        success: function(resp){
          if (resp && resp.ok) {
            if (resp.reload) {
              window.location.reload();
              return;
            }
            var html = resp.html || '';
            if (html) {
              var $box = $('#conversationScroll');
              $box.find('.mb-empty').remove();
              $('#scrollAnchor').before(html);
            }
            $('#message').val('');
            growTa();
            attachments = [];
            renderAttachments();
            settleBottom(true);
          } else {
            alert((resp && resp.error) ? resp.error : 'Send failed.');
          }
        },
        error: function(){ alert('Send failed (server error).'); }
      });
    });
  });
</script>

<div class="modal fade" id="attModal" tabindex="-1" role="dialog" aria-labelledby="attModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="attModalLabel">Attachment</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="attModalBody"></div>
    </div>
  </div>
</div>

</body>
</html>
