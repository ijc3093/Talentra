<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$controller = new Controller();
$dbh = $controller->pdo();

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
function normalize_spaces(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?? '';
}
function initials_from_name(string $nameOrCode): string {
    $s = normalize_spaces($nameOrCode);
    if ($s === '') return '?';
    $parts = explode(' ', $s);
    $first = $parts[0] ?? '';
    $last  = $parts[count($parts) - 1] ?? '';
    $a = strtoupper(function_exists('mb_substr') ? (string)mb_substr($first, 0, 1) : substr($first, 0, 1));
    $b = '';
    if (count($parts) >= 2) {
        $b = strtoupper(function_exists('mb_substr') ? (string)mb_substr($last, 0, 1) : substr($last, 0, 1));
    } else {
        $b = strtoupper(function_exists('mb_substr') ? (string)mb_substr($first, 1, 1) : substr($first, 1, 1));
    }
    $out = trim($a . $b);
    if ($out === '') {
        $out = strtoupper(function_exists('mb_substr') ? (string)mb_substr($s, 0, 2) : substr($s, 0, 2));
    }
    return $out !== '' ? $out : '?';
}
function color_from_key(string $key): array {
    $k = normalize_spaces($key);
    if ($k === '') $k = '?';
    $hex = sha1($k);
    $n = hexdec(substr($hex, 0, 8));
    $h = $n % 360;
    $s = 70; $l = 45;
    $s1 = $s / 100; $l1 = $l / 100;
    $c = (1 - abs(2 * $l1 - 1)) * $s1;
    $x = $c * (1 - abs((($h / 60) % 2) - 1));
    $m = $l1 - $c / 2;
    $r = 0; $g = 0; $b = 0;
    if ($h < 60)      { $r = $c; $g = $x; $b = 0; }
    elseif ($h < 120) { $r = $x; $g = $c; $b = 0; }
    elseif ($h < 180) { $r = 0; $g = $c; $b = $x; }
    elseif ($h < 240) { $r = 0; $g = $x; $b = $c; }
    elseif ($h < 300) { $r = $x; $g = 0; $b = $c; }
    else              { $r = $c; $g = 0; $b = $x; }
    $R = (int)round(($r + $m) * 255);
    $G = (int)round(($g + $m) * 255);
    $B = (int)round(($b + $m) * 255);
    return [$R, $G, $B];
}
function svg_avatar(string $initials, string $key, int $size): string {
    [$R, $G, $B] = color_from_key($key);
    $bg = sprintf('#%02X%02X%02X', $R, $G, $B);
    $txt = $initials !== '' ? $initials : '?';
    $txt = strtoupper(function_exists('mb_substr') ? (string)mb_substr($txt, 0, 2) : substr($txt, 0, 2));
    $fontSize = (int)round($size * 0.44);
    $ring = 'rgba(255,255,255,0.18)';
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $bg . '"/><stop offset="1" stop-color="' . $bg . '"/></linearGradient></defs>'
        . '<circle cx="' . ($size/2) . '" cy="' . ($size/2) . '" r="' . ($size/2 - 1) . '" fill="url(#g)"/>'
        . '<circle cx="' . ($size/2) . '" cy="' . ($size/2) . '" r="' . ($size/2 - 2) . '" fill="none" stroke="' . $ring . '" stroke-width="2"/>'
        . '<text x="50%" y="50%" text-anchor="middle" dominant-baseline="central" font-family="Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif" font-weight="700" font-size="' . $fontSize . '" fill="#FFFFFF">' . h($txt) . '</text>'
        . '</svg>';
}
function resolve_avatar_file(string $rawPath): string {
    $cleanPath = ltrim(str_replace('\\', '/', trim($rawPath)), '/');
    if ($cleanPath === '') return '';
    $base = realpath(__DIR__) ?: __DIR__;
    $candidates = [
        __DIR__ . '/' . $cleanPath,
        __DIR__ . '/images/' . basename($cleanPath),
        __DIR__ . '/uploads/avatars/' . basename($cleanPath),
        __DIR__ . '/uploads/' . basename($cleanPath),
    ];
    foreach ($candidates as $candidate) {
        $abs = realpath($candidate) ?: '';
        if ($abs !== '' && strpos($abs, $base) === 0 && is_file($abs)) {
            return $abs;
        }
    }
    return '';
}
function output_file(string $abs): void {
    $mime = function_exists('mime_content_type') ? (string)@mime_content_type($abs) : '';
    if ($mime === '') {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml'];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($abs));
    readfile($abs);
    exit;
}

$email = trim((string)($_GET['email'] ?? ''));
$name  = trim((string)($_GET['name']  ?? ''));
$key   = trim((string)($_GET['key']   ?? ''));
$userId = (int)($_GET['u'] ?? ($_GET['user_id'] ?? 0));
$friendCode = strtoupper(trim((string)($_GET['friend_code'] ?? ($_GET['code'] ?? ''))));
$username = trim((string)($_GET['username'] ?? ''));
$size  = (int)($_GET['s'] ?? 96);
if ($size < 32) $size = 32;
if ($size > 512) $size = 512;

if ($userId <= 0 && $email === '' && $friendCode === '' && $username === '') {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($email === '') $email = trim((string)($_SESSION['user_email'] ?? ($_SESSION['email'] ?? $_SESSION['user_login'] ?? '')));
    if ($friendCode === '') $friendCode = strtoupper(trim((string)($_SESSION['user_friend_code'] ?? '')));
    if ($username === '') $username = trim((string)($_SESSION['user_login'] ?? ''));
    if ($name === '') $name = trim((string)($_SESSION['user_name'] ?? ''));
}

$userRow = [];
try {
    if ($userId > 0) {
        $st = $dbh->prepare("SELECT id, email, username, friend_code, fullname, name, image, image_blob, image_type FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $userRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if (!$userRow && $friendCode !== '') {
        $st = $dbh->prepare("SELECT id, email, username, friend_code, fullname, name, image, image_blob, image_type FROM users WHERE UPPER(TRIM(COALESCE(friend_code,''))) = :fc LIMIT 1");
        $st->execute([':fc' => $friendCode]);
        $userRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if (!$userRow && $username !== '') {
        $st = $dbh->prepare("SELECT id, email, username, friend_code, fullname, name, image, image_blob, image_type FROM users WHERE TRIM(COALESCE(username,'')) = :u LIMIT 1");
        $st->execute([':u' => $username]);
        $userRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if (!$userRow && $email !== '') {
        $st = $dbh->prepare("SELECT id, email, username, friend_code, fullname, name, image, image_blob, image_type FROM users WHERE email = :e LIMIT 1");
        $st->execute([':e' => $email]);
        $userRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $userRow = [];
}

if ($userRow) {
    $userId = (int)($userRow['id'] ?? $userId);
    if ($email === '') $email = trim((string)($userRow['email'] ?? ''));
    if ($friendCode === '') $friendCode = strtoupper(trim((string)($userRow['friend_code'] ?? '')));
    if ($username === '') $username = trim((string)($userRow['username'] ?? ''));
    if ($name === '') {
        $name = trim((string)($userRow['fullname'] ?? ''));
        if ($name === '') $name = trim((string)($userRow['name'] ?? ''));
        if ($name === '') $name = trim((string)($userRow['username'] ?? ''));
    }
}

$avatarPath = '';
try {
    $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
    $hasSettings = (bool)($chk && $chk->fetchColumn());
    if ($hasSettings) {
        if ($userId > 0) {
            $st = $dbh->prepare("SELECT avatar_image_path FROM user_profile_settings WHERE user_id = :uid LIMIT 1");
            $st->execute([':uid' => $userId]);
            $avatarPath = trim((string)$st->fetchColumn());
        }
        if ($avatarPath === '' && $email !== '') {
            $st = $dbh->prepare("SELECT ups.avatar_image_path FROM user_profile_settings ups INNER JOIN users u ON u.id = ups.user_id WHERE u.email = :e LIMIT 1");
            $st->execute([':e' => $email]);
            $avatarPath = trim((string)$st->fetchColumn());
        }
        if ($avatarPath === '' && $friendCode !== '') {
            $st = $dbh->prepare("SELECT ups.avatar_image_path FROM user_profile_settings ups INNER JOIN users u ON u.id = ups.user_id WHERE UPPER(TRIM(COALESCE(u.friend_code,''))) = :fc LIMIT 1");
            $st->execute([':fc' => $friendCode]);
            $avatarPath = trim((string)$st->fetchColumn());
        }
        if ($avatarPath === '' && $username !== '') {
            $st = $dbh->prepare("SELECT ups.avatar_image_path FROM user_profile_settings ups INNER JOIN users u ON u.id = ups.user_id WHERE TRIM(COALESCE(u.username,'')) = :u LIMIT 1");
            $st->execute([':u' => $username]);
            $avatarPath = trim((string)$st->fetchColumn());
        }
    }
} catch (Throwable $e) {
    $avatarPath = '';
}

if ($avatarPath !== '') {
    $abs = resolve_avatar_file($avatarPath);
    if ($abs !== '') {
        output_file($abs);
    }
}

$legacyImage = trim((string)($userRow['image'] ?? ''));
if ($legacyImage !== '' && strtolower($legacyImage) !== 'default.jpg' && strtolower($legacyImage) !== 'default.png') {
    $abs = resolve_avatar_file($legacyImage);
    if ($abs !== '') {
        output_file($abs);
    }
}

$imageBlob = (string)($userRow['image_blob'] ?? '');
$imageType = (string)($userRow['image_type'] ?? 'image/jpeg');
if ($imageBlob !== '') {
    header('Content-Type: ' . ($imageType !== '' ? $imageType : 'image/jpeg'));
    echo $imageBlob;
    exit;
}

$useName = $name;
if ($useName === '' && $friendCode !== '') $useName = $friendCode;
if ($useName === '' && $email !== '') $useName = explode('@', $email)[0] ?? $email;
if ($useName === '' && $username !== '') $useName = $username;
$initials = initials_from_name($useName);
$colorKey = $key !== '' ? $key : $initials;
header('Content-Type: image/svg+xml; charset=UTF-8');
echo svg_avatar($initials, $colorKey, $size);
exit;
