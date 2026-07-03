<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/identity.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();

function out(array $a): void { echo json_encode($a); exit; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$meId = (int)myAdminId();
$meUser = (string)myUsername();
if ($meId <= 0 || $meUser === '') out(['ok'=>false,'error'=>'Invalid session']);

$meCode = '';
try {
    $stMe = $dbh->prepare("SELECT friend_code FROM admin WHERE idadmin = :id LIMIT 1");
    $stMe->execute([':id' => $meId]);
    $meCode = (string)($stMe->fetchColumn() ?: '');
} catch (Throwable $e) { $meCode = ''; }
if ($meCode === '') $meCode = $meUser; // legacy fallback

// Inputs
$peerCode = trim((string)($_GET['peer'] ?? ''));
$ch       = trim((string)($_GET['ch'] ?? ''));
$title    = (string)($_GET['title'] ?? '');
$since    = (int)($_GET['since'] ?? 0);

if ($peerCode === '' || $ch === '') out(['ok'=>false,'error'=>'Missing peer/channel']);

// Detect feedback_admin PK column (same logic as mailbox.php)
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
    if (count($parts) > 1) $second = mb_strtoupper(mb_substr($parts[count($parts)-1], 0, 1));
    else $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}
function avatar_color(string $key): string {
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}
function fmt_dt(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('M d, Y h:i A', $ts) : '';
}
function admin_label_by_key(PDO $dbh, string $key): string {
    $key = trim($key);
    if ($key === '') return '';
    try {
        $st = $dbh->prepare("SELECT username, fullname, friend_code FROM admin WHERE friend_code = :k LIMIT 1");
        $st->execute([':k' => $key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return (string)($r['fullname'] ?: $r['username'] ?: $r['friend_code'] ?: $key);
    } catch (Throwable $e) {}
    try {
        $st = $dbh->prepare("SELECT username, fullname, friend_code FROM admin WHERE username = :k LIMIT 1");
        $st->execute([':k' => $key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return (string)($r['fullname'] ?: $r['username'] ?: $r['friend_code'] ?: $key);
    } catch (Throwable $e) {}
    return $key;
}

// Pull newer messages
$idCol = feedback_admin_id_col($dbh);

$params = [
    ':ch'     => $ch,
    ':me'     => $meCode,
    ':peer'   => $peerCode,
    ':since'  => $since,
    ':title1' => $title,
    ':title2' => $title,
];

$sql = "
  SELECT {$idCol} AS id, sender, receiver, feedbackdata, attachment, created_at, title
  FROM feedback_admin
  WHERE channel = :ch
    AND {$idCol} > :since
    AND (
          (sender = :me AND receiver = :peer)
      OR  (sender = :peer AND receiver = :me)
    )
    AND (:title1 = '' OR title = :title2)
  ORDER BY {$idCol} ASC
  LIMIT 80
";

try {
    $st = $dbh->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    out(['ok'=>false,'error'=>'DB error']);
}

// mark incoming as read
try {
    $mk = $dbh->prepare("
        UPDATE feedback_admin
        SET is_read = 1, read_at = NOW()
        WHERE channel = :ch
          AND sender = :peer
          AND receiver = :me
          AND is_read = 0
          AND (:title1 = '' OR title = :title2)
    ");
    $mk->execute([
        ':ch'=>$ch, ':peer'=>$peerCode, ':me'=>$meCode,
        ':title1'=>$title, ':title2'=>$title
    ]);
} catch (Throwable $e) { /* ignore */ }

$messages = [];
$newLast = $since;

foreach ($rows as $m) {
    $mid = (int)($m['id'] ?? 0);
    if ($mid > $newLast) $newLast = $mid;

    $senderCode  = (string)($m['sender'] ?? '');
    $senderLabel = ($senderCode === $meCode) ? 'You' : admin_label_by_key($dbh, $senderCode);

    // initials: for "You" still use your real name if you want, but we don’t have it here -> use senderLabel fallback
    $ini = avatar_initials($senderLabel === 'You' ? $meUser : $senderLabel);
    $bg  = avatar_color($senderCode !== '' ? $senderCode : $senderLabel);

    $text = (string)($m['feedbackdata'] ?? '');
    $created = fmt_dt((string)($m['created_at'] ?? ''));

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

    ob_start();
    ?>
    <div class="mg-b-15" data-mid="<?php echo (int)$mid; ?>">
      <div class="mailbox-body-header" style="position:static;border-bottom:0;">
        <div class="media align-items-center">
          <div class="wd-50 rounded-circle"
               style="height:50px;display:flex;align-items:center;justify-content:center;
                      background:<?php echo h($bg); ?>;color:#fff;font-weight:800;
                      letter-spacing:.5px;font-size:18px;border:1px solid rgba(0,0,0,.08);">
            <?php echo h($ini); ?>
          </div>

          <div class="media-body mg-l-15">
            <h6 class="tx-14 tx-inverse mg-b-5"><?php echo h($senderLabel); ?></h6>
            <span class="d-block tx-12"><?php echo h($created); ?></span>
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

    $messages[] = ['id'=>$mid, 'html'=>$html];
}

out(['ok'=>true, 'messages'=>$messages, 'last_id'=>$newLast]);
