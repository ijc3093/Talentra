<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

header('Content-Type: application/json; charset=utf-8');

function out(array $data): void {
  echo json_encode($data);
  exit;
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(['ok' => false, 'error' => 'Invalid request method']);
  }

  if (!isset($_FILES['file'])) {
    out(['ok' => false, 'error' => 'No file uploaded']);
  }

  $f = $_FILES['file'];

  if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
    out(['ok' => false, 'error' => 'Upload failed', 'code' => ($f['error'] ?? -1)]);
  }

  $tmp  = (string)($f['tmp_name'] ?? '');
  $size = (int)($f['size'] ?? 0);
  $orig = (string)($f['name'] ?? '');

  if ($tmp === '' || !is_uploaded_file($tmp)) out(['ok' => false, 'error' => 'Invalid upload']);
  if ($size <= 0) out(['ok' => false, 'error' => 'Empty upload']);
  if ($size > 10 * 1024 * 1024) out(['ok' => false, 'error' => 'File too large (max 10MB)']);

  // Detect MIME safely
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($tmp) ?: 'application/octet-stream';

  // Allowed types for internal chat attachments.
  // NOTE: many code/text formats are detected as text/plain by fileinfo,
  // so we allow a safe set of text/* and common JSON/XML/JS types.
  $allowed = [
    // Images
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',

    // Video
    'video/mp4'  => 'mp4',
    'video/webm' => 'webm',
    'video/ogg'  => 'ogv',

    // Documents
    'application/pdf' => 'pdf',

    // Text / code
    'text/plain' => 'txt',
    'text/markdown' => 'md',
    'application/json' => 'json',
    'text/json' => 'json',
    'application/xml' => 'xml',
    'text/xml' => 'xml',
    'text/html' => 'html',
    'text/css' => 'css',
    'application/javascript' => 'js',
    'text/javascript' => 'js',
  ];

  if (!isset($allowed[$mime])) {
    out(['ok' => false, 'error' => 'File type not allowed', 'mime' => $mime]);
  }

  // Folder: /Business_only3/attachment/storage
  $attachmentDir = __DIR__ . '/../../attachment/storage';
  if (!is_dir($attachmentDir)) {
    if (!mkdir($attachmentDir, 0775, true)) {
      out(['ok' => false, 'error' => 'Cannot create attachment/storage folder']);
    }
  }

  $attachmentDirReal = realpath($attachmentDir);
  if ($attachmentDirReal === false) {
    out(['ok' => false, 'error' => 'attachment/storage not accessible']);
  }

  // Safe file name
  $ext  = $allowed[$mime];
  $rand = bin2hex(random_bytes(8));
  $name = 'chat_' . date('Ymd_His') . '_' . $rand . '.' . $ext;

  $dest = $attachmentDirReal . DIRECTORY_SEPARATOR . $name;

  if (!move_uploaded_file($tmp, $dest)) {
    out(['ok' => false, 'error' => 'Could not save uploaded file']);
  }

  // From /admin/ page, go up one level to /attachment/storage/
  $path = 'storage/' . $name; // relative to /attachment/
  // Keep slash in URL; only encode the filename part.
  $url  = '../attachment/storage/' . rawurlencode($name);

  out([
    'ok' => true,
    'file' => $name,
    'original' => $orig,
    'mime' => $mime,
    'path' => $path,
    'url' => $url
  ]);

} catch (Throwable $e) {
  out(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
}
