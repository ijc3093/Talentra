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
  $path = trim((string)($_GET['path'] ?? ''));
  if ($path === '' || strpos($path, 'storage/') !== 0) {
    out(['ok' => false, 'error' => 'Invalid path']);
  }

  // Resolve to /Business_only3/attachment/<path>
  $base = realpath(__DIR__ . '/../../attachment');
  if ($base === false) out(['ok' => false, 'error' => 'Attachment folder missing']);

  $full = realpath($base . DIRECTORY_SEPARATOR . $path);
  if ($full === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) {
    out(['ok' => false, 'error' => 'File not found']);
  }

  // Prevent huge previews
  $max = 250 * 1024; // 250KB
  $size = filesize($full);
  if ($size === false || $size <= 0) out(['ok' => false, 'error' => 'Empty file']);
  if ($size > $max) out(['ok' => false, 'error' => 'File too large to preview (download instead)']);

  // Read as text
  $content = file_get_contents($full);
  if ($content === false) out(['ok' => false, 'error' => 'Could not read file']);

  // Keep it safe to embed
  $content = str_replace(["\r\n", "\r"], "\n", $content);

  out([
    'ok' => true,
    'text' => $content,
  ]);

} catch (Throwable $e) {
  out(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
}
