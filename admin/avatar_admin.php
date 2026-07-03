<?php
// /Business_only3/admin/avatar_admin.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/controller.php';

// ✅ IMPORTANT: never print PHP warnings/notices into an image response
ini_set('display_errors', '0');
error_reporting(E_ALL);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$controller = new Controller();
$dbh = $controller->pdo();

$viewerId = (int)($_SESSION['admin_id'] ?? 0);
if ($viewerId <= 0) {
    http_response_code(403);
    exit;
}

function initials2(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') return '??';

    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), fn($p)=>trim($p) !== ''));
    if (!$parts) return '??';

    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = '';

    if (count($parts) > 1) {
        $second = mb_strtoupper(mb_substr($parts[count($parts)-1], 0, 1));
    } else {
        $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
    }

    $ini = trim($first.$second);
    return $ini !== '' ? $ini : '??';
}

function avatarColor(string $key): string {
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

// ✅ key can be friend_code or username (keep common chars)
$key = trim((string)($_GET['key'] ?? ''));
$key = preg_replace('/[^a-zA-Z0-9_\-@\.]/', '', $key) ?? '';

try {
    if ($key === '') {
        $st = $dbh->prepare("SELECT username, fullname, image, friend_code FROM admin WHERE idadmin = :id LIMIT 1");
        $st->execute([':id' => $viewerId]);
    } else {
        $st = $dbh->prepare("
            SELECT username, fullname, image, friend_code
            FROM admin
            WHERE friend_code = :k OR username = :k
            LIMIT 1
        ");
        $st->execute([':k' => $key]);
    }

    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $u = [];
}

$username   = (string)($u['username'] ?? '');
$fullname   = (string)($u['fullname'] ?? '');
$image      = (string)($u['image'] ?? '');
$friendCode = (string)($u['friend_code'] ?? '');

// ✅ If not found, still show something based on key
if ($username === '' && $fullname === '' && $friendCode === '' && $key !== '') {
    $friendCode = $key;
}

$label = trim($fullname) !== '' ? trim($fullname)
       : (trim($username) !== '' ? trim($username)
       : ($friendCode !== '' ? $friendCode : 'Admin'));

// ✅ IMPORTANT: Your images folder is /admin/images/
if ($image !== '') {
    $abs = __DIR__ . '/images/' . basename($image);
    if (is_file($abs)) {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = 'image/jpeg';
        if ($ext === 'png')  $mime = 'image/png';
        if ($ext === 'gif')  $mime = 'image/gif';
        if ($ext === 'webp') $mime = 'image/webp';

        header("Content-Type: " . $mime);
        readfile($abs);
        exit;
    }
}

// SVG fallback (always works)
$ini = initials2($label);
$bgKey = ($username !== '' ? $username : ($friendCode !== '' ? $friendCode : ($key !== '' ? $key : (string)$viewerId)));
$bg  = avatarColor($bgKey);

// ✅ Security / compatibility headers for SVG
header("Content-Type: image/svg+xml; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline';");

$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
  <defs>
    <style>.t{font:800 52px/1 Arial,Helvetica,sans-serif;}</style>
  </defs>
  <circle cx="64" cy="64" r="62" fill="{$bg}"/>
  <text x="64" y="80" text-anchor="middle" class="t" fill="#fff">{$ini}</text>
</svg>
SVG;

echo $svg;
exit;
