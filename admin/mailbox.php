<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/includes/identity.php';
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
    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
    $plain = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return ($plain === '') ? '' : $clean;
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
   Peer selector (URL can pass username or friend_code)
========================================================== */
$peerCode  = trim((string)($_GET['peer'] ?? ''));
$threadUid = trim((string)($_GET['t'] ?? ''));

$peerUser = '';
$peerName = '';
$peerRole = 0;
$channelForPeer = '';

$threads = [];
$messages = [];
$subjectThreads = [];
$currentThreadTitle = '';
$errorMsg = '';

$senderMap = [];

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

try {
    // ---------------- LEFT THREADS ----------------
    if (!empty($internalChannels)) {

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

    // ---------------- RESOLVE PEER ----------------
    if ($peerCode !== '') {
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

                        $senderLabel = 'You';
                        $senderLabelForInitials = $meLabelForInitials;
                        $mIni = avatar_initials($senderLabelForInitials);
                        $mBg  = avatar_color($meCode);

                        $attList = [];
                        if ($attachmentsJson) {
                            $j = json_decode($attachmentsJson, true);
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

                        ob_start();
                        ?>
                        <div class="mg-b-15">
                          <div class="mailbox-body-header" style="position:static;border-bottom:0;">
                            <div class="media align-items-center">
                              <div class="wd-50 rounded-circle"
                                   style="height:50px;display:flex;align-items:center;justify-content:center;
                                          background:<?php echo h($mBg); ?>;color:#fff;font-weight:800;
                                          letter-spacing:.5px;font-size:18px;border:1px solid rgba(0,0,0,.08);">
                                <?php echo h($mIni); ?>
                              </div>
                              <div class="media-body mg-l-15">
                                <h6 class="tx-14 tx-inverse mg-b-5"><?php echo h($senderLabel); ?></h6>
                                <span class="d-block tx-12"><?php echo h(date('M d, Y h:i A')); ?></span>
                              </div>
                            </div>
                          </div>

                          <div class="mg-t-10"><?php echo $text; ?></div>

                          <?php if (!empty($attList)): ?>
                            <div class="mg-t-10">
                              <div class="tx-12 tx-gray-600 mg-b-5">Attachments:</div>
                              <div class="d-flex flex-wrap" style="gap:6px">
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
                            </div>
                          <?php endif; ?>
                        </div>
                        <?php
                        $html = ob_get_clean();

                        header('Content-Type: application/json');
                        echo json_encode(['ok'=>true,'html'=>$html]);
                        exit;
                    }

                    $uidForRedirect = $threadUid !== '' ? $threadUid : thread_uid($currentThreadTitle);
                    header('Location: mailbox.php?peer=' . urlencode($peerCode) . ($uidForRedirect !== '' ? '&t=' . urlencode($uidForRedirect) : ''));
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
                ORDER BY {$idCol} ASC
                LIMIT 400
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

    .sh-mainpanel{
      height: 100vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .sh-pagetitle{ flex: 0 0 auto; }
    .sh-pagebody{
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    .card-mailbox{
      flex: 1 1 auto;
      min-height: 0;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .card-mailbox > .card-header{
      flex: 0 0 auto;
    }
    .card-mailbox > .card-body{
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden;
      display: flex;
      padding: 0;
    }

    .mailbox-left{
      flex: 0 0 330px;
      width: 330px;
      min-width: 280px;
      max-width: 420px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      border-right: 1px solid rgba(0,0,0,.06);
      background: #fff;
    }
    .mailbox-left .input-group{ flex: 0 0 auto; padding: 12px; }
    .mailbox-list{
      flex: 1 1 auto;
      min-height: 0;
      overflow: auto;
    }

    .mailbox-right{
      flex: 0 0 235px;
      width: 235px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      border-left: 1px solid rgba(0,0,0,.06);
      background: #fff;
    }
    .mailbox-right .inputgroup{ flex: 0 0 auto; padding: 12px; }
    .mailboxlist{
      flex: 1 1 auto;
      min-height: 0;
      overflow: auto;
    }

    .mailbox-body{
      flex: 1 1 auto;
      min-width: 0;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      background: #fff;
    }

    .mailbox-body-header{
      flex: 0 0 auto;
      position: sticky;
      top: 0;
      z-index: 10;
      background: #fff;
      border-bottom: 1px solid rgba(0,0,0,.06);
    }

    .conversation-scroll{
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      /* padding: 15px 20px; */
      /* padding-bottom: 260px; fallback before JS runs */
    }

    .composer-wrap{
      flex: 0 0 auto;
      position: sticky;
      bottom: 0;
      z-index: 12;
      background: #fff;
      border-top: 1px solid rgba(0,0,0,.10);
      /* padding: 10px 12px; */
    }

    .composer-wrap .note-editor.note-frame{ margin-bottom: 0px; }
    /* .composer-wrap .note-editable{ max-height: 180px; overflow: auto; } */
    .note-editor .note-editable > p:first-child { margin-top: 0 !important; }
    .note-editor .note-editable > p:last-child  { margin-bottom: 0 !important; }
    .note-editor .note-editable p { margin: 6px 0; }
  </style>
</head>

<body>

<?php include('includes/header.php'); ?>
<?php include('includes/leftbar.php'); ?>

<div class="sh-mainpanel">
  

  <div class="sh-pagebody">
    <div class="card card-mailbox <?php echo ($peerUser!=='' ? 'show-msg' : ''); ?>">
      <div class="card-header bg-primary">
        <nav class="nav">
          <a id="mailboxBack" href="" class="nav-link nav-back"><i class="fa fa-angle-left"></i></a>
          <a href="#tabUsers" class="nav-link active" data-toggle="tab">Inbox</a>
          <a href="#tabFavorites" class="nav-link" data-toggle="tab">Outbox</a>
          <a href="#tabFavorites" class="nav-link" data-toggle="tab">Draft</a>
          <a href="#tabChat" class="nav-link" data-toggle="tab">Trash</a>
          <a href="compose.php" class="nav-link">New Compose</a>
        </nav>
      </div>

      <div class="card-body">

        <!-- LEFT -->
        <div class="mailbox-left">
          <div class="input-group">
            <input type="search" class="form-control" name="search" placeholder="Search messages">
            <span class="input-group-btn">
              <button class="btn"><i class="fa fa-search"></i></button>
            </span>
          </div>

          <div class="mailbox-list">
            <?php if (empty($threads)): ?>
              <div class="pd-15 tx-12 tx-gray-600">No conversations yet.</div>
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
              ?>
              <a href="mailbox.php?peer=<?php echo urlencode((string)$t['peer_code']); ?>" class="media <?php echo $active ? 'bg-gray-100' : ''; ?>">
                <div class="wd-50 rounded-circle"
                     style="height:50px;display:flex;align-items:center;justify-content:center;
                            background:<?php echo h($bg); ?>;color:#fff;font-weight:800;
                            letter-spacing:.5px;font-size:18px;border:1px solid rgba(0,0,0,.08);">
                  <?php echo h($ini); ?>
                </div>

                <div class="media-body mg-l-15">
                  <h6 class="tx-14 tx-inverse mg-b-2">
                    <?php echo h((string)$t['peer_display']); ?>
                    <?php if ($unread > 0): ?>
                      <span class="badge badge-danger mg-l-5"><?php echo $unread; ?></span>
                    <?php endif; ?>
                  </h6>
                  <span class="d-block tx-12"><?php echo h(fmt_dt((string)$t['last_time'])); ?></span>
                  <p class="tx-13 mg-t-5 mg-b-0"><?php echo h(short_preview($lastMsgPlain)); ?></p>
                </div>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- CENTER -->
        <div class="mailbox-body" style="right:235px;margin-top:10px;margin-left:10px;">
          <?php if ($peerCode === '' || $peerUser === ''): ?>
            <div class="pd-20 tx-13 tx-gray-600">Select a conversation on the left to view messages.</div>
          <?php else: ?>

          <div class="mailbox-body-header">
            <div class="media align-items-center">
              <div class="wd-50 rounded-circle"
                   style="height:50px;display:flex;align-items:center;justify-content:center;
                          background:<?php echo h($headerBg); ?>;color:#fff;font-weight:800;
                          letter-spacing:.5px;font-size:18px;border:1px solid rgba(0,0,0,.08);">
                <?php echo h($headerIni); ?>
              </div>

              <div class="media-body mg-l-15">
                <h6 class="tx-14 tx-inverse mg-b-5"><?php echo h($headerPeerLabel); ?></h6>
                <div class="tx-12 tx-gray-600" style="margin-top:-2px;">
                  Subject: <?php echo h($currentSubject); ?>
                </div>
                <span class="d-block tx-12"><?php echo h($channelForPeer); ?></span>
              </div>
            </div>
            <nav class="nav">
              <a href="" class="nav-link"><i class="icon ion-reply"></i></a>
              <a href="" class="nav-link"><i class="icon ion-reply-all"></i></a>
              <a href="" class="nav-link"><i class="icon ion-printer"></i></a>
              <a href="" class="nav-link"><i class="icon ion-android-more-horizontal"></i></a>
            </nav>
          </div>

          <?php if ($errorMsg !== ''): ?>
            <div class="alert alert-danger mg-20"><?php echo h($errorMsg); ?></div>
          <?php endif; ?>

          <div class="conversation-scroll" id="conversationScroll" style="margin-right:5px;">
            <?php if (empty($messages)): ?>
              <div class="tx-12 tx-gray-600">No messages yet.</div>
            <?php else: ?>
              <?php foreach ($messages as $m): ?>
                <div class="mg-b-15">
                  <div class="mailbox-body-header" style="position:static;border-bottom:0;">
                    <div class="media align-items-center">
                      <?php
                        $senderCode  = (string)($m['sender'] ?? '');
                        $senderLabel = $senderMap[$senderCode] ?? ($senderCode === $meCode ? 'You' : $senderCode);

                        $senderLabelForInitials = $senderNameForInitials[$senderCode] ?? $senderLabel;
                        if ($senderCode === $meCode) $senderLabelForInitials = $meLabelForInitials;

                        $mIni = avatar_initials($senderLabelForInitials);
                        $mBg  = avatar_color($senderCode !== '' ? $senderCode : $senderLabelForInitials);
                      ?>

                      <div class="wd-50 rounded-circle"
                           style="height:50px;display:flex;align-items:center;justify-content:center;
                                  background:<?php echo h($mBg); ?>;color:#fff;font-weight:800;
                                  letter-spacing:.5px;font-size:18px;border:1px solid rgba(0,0,0,.08);">
                        <?php echo h($mIni); ?>
                      </div>

                      <div class="media-body mg-l-15">
                        <h6 class="tx-14 tx-inverse mg-b-5"><?php echo h($senderLabel); ?></h6>
                        <span class="d-block tx-12"><?php echo h(fmt_dt((string)$m['created_at'])); ?></span>
                      </div>
                    </div>
                  </div>

                  <div class="mg-t-10"><?php echo (string)$m['feedbackdata']; ?></div>

                  <?php
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

                  <?php if (!empty($attList)): ?>
                    <div class="mg-t-10">
                      <div class="tx-12 tx-gray-600 mg-b-5">Attachments:</div>
                      <div class="d-flex flex-wrap" style="gap:6px">
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
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <!-- ✅ bottom anchor -->
            <div id="scrollAnchor" style="height:1px;"></div>
          </div>

          <div class="composer-wrap">
            <form method="POST" id="mbForm"
                  action="mailbox.php?peer=<?php echo urlencode($peerCode); ?><?php echo ($threadUid!==''?'&t='.urlencode($threadUid):''); ?>">
              <div class="form-group" style="margin-bottom:0;">
                <textarea name="message" id="message"></textarea>
                <input type="hidden" name="attachments" id="attachments" value="">
                <div id="attachmentList" class="mg-t-10"></div>
                <div class="mg-t-10">
                  <button type="submit" class="btn btn-primary" <?php echo ($peerUser===''?'disabled':''); ?>>Send</button>
                  <input type="file" id="attPicker" style="display:none" multiple>
                  <button type="button" class="btn btn-sm btn-outline-primary" id="attPickBtn">
                    <i class="fa fa-paperclip"></i> Attach files
                  </button>
                </div>
              </div>
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
                <a href="mailbox.php?peer=<?php echo urlencode((string)$peerCode); ?>&t=<?php echo urlencode($uid); ?>"
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

<?php include('includes/footer.php'); ?>

<script>
  $(function () {
    'use strict';

    // ✅ prevent browser restoring old scroll position on refresh/back
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

    // =========================
    // ✅ SCROLL + PADDING HELPERS
    // =========================
    function getConversationEl(){ return document.getElementById('conversationScroll'); }
    function getAnchorEl(){ return document.getElementById('scrollAnchor'); }

    function fixConversationPadding(){
      var convo = getConversationEl();
      var composer = document.querySelector('.composer-wrap');
      if (!convo || !composer) return;
      var pad = composer.offsetHeight + 24; // extra breathing room
      convo.style.paddingBottom = pad + 'px';
    }

    function isNearBottom(el, px){
      px = px || 160;
      if (!el) return true;
      return (el.scrollHeight - el.scrollTop - el.clientHeight) <= px;
    }

    function scrollToBottom(force){
      var el = getConversationEl();
      if (!el) return;

      if (!force && !isNearBottom(el, 180)) return;

      var anchor = getAnchorEl();
      if (anchor && anchor.scrollIntoView) {
        anchor.scrollIntoView({ block: 'end' });
      } else {
        el.scrollTop = el.scrollHeight;
      }
    }

    function settleBottom(force){
      fixConversationPadding();
      scrollToBottom(force !== false); // default true
      setTimeout(function(){ fixConversationPadding(); scrollToBottom(force !== false); }, 30);
      setTimeout(function(){ fixConversationPadding(); scrollToBottom(force !== false); }, 120);
      setTimeout(function(){ fixConversationPadding(); scrollToBottom(force !== false); }, 350);
    }

    // If images/iframes/videos load later, keep bottom
    function hookMediaLoadStick(){
      var el = getConversationEl();
      if (!el) return;
      var media = el.querySelectorAll('img, iframe, video');
      media.forEach(function(node){
        node.addEventListener('load', function(){ settleBottom(true); }, { once:true });
      });
    }

    // =========================
    // ✅ ATTACHMENT PREVIEW
    // =========================
    function renderAttachments(){
      var html = '';
      if (attachments.length){
        html += '<div class="tx-12 tx-gray-600 mg-b-5">Attachments (click to preview):</div>';
        html += '<div class="d-flex flex-wrap" style="gap:6px">';
        attachments.forEach(function(a, idx){
          html += '<button type="button" class="btn btn-sm btn-outline-secondary att-btn" data-idx="'+idx+'">'+esc(a.original || a.path)+'</button>';
          html += '<button type="button" class="btn btn-sm btn-outline-danger att-rm" data-idx="'+idx+'">&times;</button>';
        });
        html += '</div>';
      }
      $('#attachmentList').html(html);
      $('#attachments').val(JSON.stringify(attachments));

      // ✅ attachments area changes composer height -> recompute padding and keep bottom
      setTimeout(function(){
        fixConversationPadding();
        scrollToBottom(true);
      }, 50);
    }

    function openPreview(a){
      if (!a) return;
      var url = a.url || ('../attachment/' + a.path);
      var kind = guessKind(a.mime, a.path);
      $('#attModalLabel').text(a.original || a.path);
      var $body = $('#attModalBody');
      $body.html('');

      if (kind === 'image') {
        $body.html('<img src="'+esc(url)+'" class="img-fluid" alt="">');
      } else if (kind === 'video') {
        $body.html('<video controls style="width:100%;max-height:70vh;border-radius:10px"><source src="'+esc(url)+'"></video>');
      } else if (kind === 'pdf') {
        $body.html('<iframe src="'+esc(url)+'" style="width:100%;height:70vh;border:0"></iframe>');
      } else {
        $body.html('<div class="tx-12 tx-gray-600">Loading preview...</div>');
        $.getJSON('ajax/read_chat_attachment.php', {path: a.path})
          .done(function(r){
            if (r && r.ok) {
              var t = esc(r.text || '');
              $body.html('<pre style="white-space:pre-wrap;word-break:break-word;max-height:70vh;overflow:auto">'+t+'</pre>');
            } else {
              $body.html('<div class="alert alert-warning">'+esc((r && r.error) ? r.error : 'Cannot preview. Please download.')+'</div>');
            }
          })
          .fail(function(){
            $body.html('<div class="alert alert-warning">Cannot preview. Please download.</div>');
          });
      }

      $('#attModal').modal('show');
    }

    function uploadOneFile(file){
      if (!file) return;
      var data = new FormData();
      data.append('file', file);
      $.ajax({
        url: 'ajax/upload_chat_attachment.php',
        method: 'POST',
        data: data,
        contentType: false,
        processData: false,
        success: function(resp){
          if (typeof resp === 'string') {
            try { resp = JSON.parse(resp); } catch(e) {}
          }
          if (resp && resp.ok && resp.path) {
            attachments.push({
              path: resp.path,
              url: resp.url,
              original: resp.original || (resp.file || resp.path),
              mime: resp.mime || ''
            });
            renderAttachments();
          } else {
            alert((resp && resp.error) ? resp.error : 'Upload failed');
          }
        },
        error: function(){
          alert('Upload failed (server error).');
        }
      });
    }

    // =========================
    // ✅ SUMMERNOTE
    // =========================
    $('#message').summernote({
      height: 150,
      dialogsInBody: true,
      toolbar: [
        ['style', ['style']],
        ['font', ['bold', 'italic', 'underline', 'clear']],
        ['fontname', ['fontname']],
        ['fontsize', ['fontsize']],
        ['color', ['color']],
        ['para', ['ul', 'ol', 'paragraph']],
        ['insert', ['link', 'picture', 'video']],
        ['view', ['fullscreen', 'codeview', 'help']]
      ],
      callbacks: {
        onImageUpload: function(files) {
          if (!files || !files.length) return;
          uploadOneFile(files[0]);
        },
        onChange: function() {
          // ✅ editor height/content can change -> keep bottom padding correct
          fixConversationPadding();
        }
      }
    });

    $(document).on('shown.bs.modal', '.note-modal', function () {
      $(this).appendTo('body');
    });

    // =========================
    // ✅ PICK ATTACHMENTS
    // =========================
    $('#attPickBtn').on('click', function(){
      $('#attPicker').trigger('click');
    });
    $('#attPicker').on('change', function(){
      var fs = this.files || [];
      for (var i=0; i<fs.length; i++) uploadOneFile(fs[i]);
      this.value = '';
    });

    $(document).on('click', '.att-btn', function(){
      var idx = parseInt($(this).attr('data-idx'), 10);
      openPreview(attachments[idx]);
    });

    $(document).on('click', '.att-rm', function(){
      var idx = parseInt($(this).attr('data-idx'), 10);
      if (!isNaN(idx)) {
        attachments.splice(idx, 1);
        renderAttachments();
      }
    });

    $(document).on('click', '.js-att-open', function(){
      openPreview({
        path: $(this).data('path'),
        mime: $(this).data('mime') || '',
        original: $(this).data('name') || '',
        url: '../attachment/' + $(this).data('path')
      });
    });

    // =========================
    // ✅ INITIAL LOAD: go bottom (after layout settles)
    // =========================
    settleBottom(true);
    $(window).on('load', function(){
      settleBottom(true);
      hookMediaLoadStick();
    });

    // =========================
    // ✅ AJAX SEND: append + stay bottom (and NEVER hide under summernote)
    // =========================
    $('#mbForm').on('submit', function (e) {
      e.preventDefault();

      var code = $('#message').summernote('code') || '';
      var $form = $(this);

      var payload = {
        ajax: '1',
        message: code,
        attachments: JSON.stringify(attachments || [])
      };

      var convoEl = getConversationEl();
      var keepBottom = convoEl ? isNearBottom(convoEl, 220) : true; // if already at bottom, keep it

      $.ajax({
        url: $form.attr('action'),
        method: 'POST',
        data: payload,
        success: function(resp){
          if (typeof resp === 'string') {
            try { resp = JSON.parse(resp); } catch(e) {}
          }
          if (resp && resp.ok) {

            var $box = $('#conversationScroll');

            // append new message HTML before anchor, then re-append anchor at end
            var anchor = document.getElementById('scrollAnchor');
            if (anchor) anchor.remove();

            $box.append(resp.html);
            $box.append('<div id="scrollAnchor" style="height:0px;"></div>');

            // reset composer
            $('#message').summernote('code', '');
            attachments = [];
            renderAttachments();

            // after DOM updates, fix padding + stick to bottom (force if we were at bottom)
            requestAnimationFrame(function(){
              fixConversationPadding();
              scrollToBottom(keepBottom);
              hookMediaLoadStick();
            });
            setTimeout(function(){
              fixConversationPadding();
              scrollToBottom(keepBottom);
            }, 80);
            setTimeout(function(){
              fixConversationPadding();
              scrollToBottom(keepBottom);
            }, 220);

          } else {
            alert((resp && resp.error) ? resp.error : 'Send failed.');
          }
        },
        error: function(){
          alert('Send failed (server error).');
        }
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
