<?php
// /Business_only3/organization/includes/avatar.php
// Lightweight avatar generator for org accounts (manager/staff).
// Produces an SVG with initials so you don't need an uploaded photo.
declare(strict_types=1);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Require org login so avatars aren't publicly scraped
require_once __DIR__ . '/session_org_login.php';
orgRequireLoginOnly();

require_once __DIR__ . '/../../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

// --- input ---
$type = strtolower((string)($_GET['type'] ?? ''));
$id   = (int)($_GET['id'] ?? 0);
$size = (int)($_GET['size'] ?? 80);
if ($size < 24) $size = 24;
if ($size > 240) $size = 240;

$name = '';
try {
    if ($id > 0 && ($type === 'manager' || $type === 'staff')) {
        if ($type === 'manager') {
            $st = $dbh->prepare("SELECT fullname, username, email FROM managers WHERE id = :id LIMIT 1");
        } else {
            $st = $dbh->prepare("SELECT fullname, username, email FROM staff_accounts WHERE id = :id LIMIT 1");
        }
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = trim((string)($row['fullname'] ?? ''));
        if ($name === '') $name = trim((string)($row['username'] ?? ''));
        if ($name === '') $name = trim((string)($row['email'] ?? ''));
    }
} catch (Throwable $e) {
    $name = '';
}

if ($name === '') {
    $name = ($type === 'manager') ? 'Manager' : 'Staff';
}

// initials (max 2)
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = '';
foreach ($parts as $p) {
    if ($p === '') continue;
    $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    if (mb_strlen($initials) >= 2) break;
}
if ($initials === '') $initials = 'U';

// deterministic background color
$palette = ['#2563eb', '#7c3aed', '#db2777', '#059669', '#d97706', '#0ea5e9', '#16a34a', '#ef4444'];
$hash = crc32($type . ':' . $id . ':' . $name);
$bg = $palette[(int)($hash % count($palette))];

$fg = '#ffffff';
$r = (int)round($size / 2);
$font = (int)round($size * 0.42);
$y = (int)round($size * 0.62);

// Escape for XML
$esc = static function(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= (int)$size ?>" height="<?= (int)$size ?>" viewBox="0 0 <?= (int)$size ?> <?= (int)$size ?>" role="img" aria-label="<?= $esc($name) ?>">
  <defs>
    <clipPath id="c">
      <circle cx="<?= (int)$r ?>" cy="<?= (int)$r ?>" r="<?= (int)$r ?>" />
    </clipPath>
  </defs>
  <g clip-path="url(#c)">
    <rect width="100%" height="100%" fill="<?= $esc($bg) ?>" />
    <text x="50%" y="<?= (int)$y ?>" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="<?= (int)$font ?>" font-weight="700" fill="<?= $esc($fg) ?>">
      <?= $esc($initials) ?>
    </text>
  </g>
</svg>
