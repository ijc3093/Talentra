<?php
// /Business_only3/organization/upload_org_media.php
// Upload attachments for org direct messages.
// Returns JSON: {ok:true, attachment_id, path, mime, name}

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

header('Content-Type: application/json; charset=utf-8');

function jfail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();

if ($orgId <= 0 || $memberId <= 0) {
    jfail('Not authenticated.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jfail('Invalid request method.', 405);
}

if (!isset($_FILES['file'])) {
    jfail('No file uploaded.');
}

$f = $_FILES['file'];
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jfail('Upload failed.');
}

$tmp  = (string)($f['tmp_name'] ?? '');
$name = (string)($f['name'] ?? 'attachment');
$size = (int)($f['size'] ?? 0);

if ($size <= 0) jfail('Empty upload.');
if ($size > 10_000_000) jfail('File too large (max 10MB).');

$fi = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$fi->file($tmp);

$allowed = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'video/mp4'  => 'mp4',
    'application/pdf' => 'pdf',
];

if (!isset($allowed[$mime])) {
    jfail('Unsupported file type: ' . $mime);
}

$ext = $allowed[$mime];

$uploadDir = __DIR__ . '/uploads/chat_attachments';
$webDir    = 'uploads/chat_attachments';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

$rand = bin2hex(random_bytes(6));
$filename = 'org_' . $orgId . '_m' . $memberId . '_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
$dest = $uploadDir . '/' . $filename;

if (!move_uploaded_file($tmp, $dest)) {
    jfail('Could not save file.');
}

$relPath = $webDir . '/' . $filename;

try {
    // You MUST have org_attachments table for this insert to work.
    $st = $dbh->prepare(
        "INSERT INTO org_attachments (org_id, uploaded_by_member_id, mime_type, original_name, path, size_bytes, created_at)
         VALUES (:org, :by, :mime, :name, :path, :sz, NOW())"
    );
    $st->execute([
        ':org'  => $orgId,
        ':by'   => $memberId,
        ':mime' => $mime,
        ':name' => $name,
        ':path' => $relPath,
        ':sz'   => $size,
    ]);

    $aid = (int)$dbh->lastInsertId();

    echo json_encode([
        'ok' => true,
        'attachment_id' => $aid,
        'path' => $relPath,
        'mime' => $mime,
        'name' => $name,
    ], JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    // if DB insert fails, remove file so it doesn't orphan
    @unlink($dest);
    jfail('DB save failed: ' . $e->getMessage(), 500);
}
