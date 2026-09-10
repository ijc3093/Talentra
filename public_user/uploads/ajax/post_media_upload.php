<?php
declare(strict_types=1);

/**
 * Eager media upload for create-post modal.
 * Uploads while the user fills the form so Submit only saves metadata.
 */
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../includes/staff_publisher_access.php';
require_once __DIR__ . '/../includes/post_upload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Connection: close');

function post_media_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    header('Content-Length: ' . strlen((string)$body));
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        @flush();
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    post_media_json(['ok' => false, 'error' => 'method'], 405);
}

if (empty($_SESSION['user_login']) || empty($_SESSION['user_id'])) {
    post_media_json(['ok' => false, 'error' => 'auth'], 401);
}

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
    post_media_json(['ok' => false, 'error' => 'auth'], 401);
}

if (staff_pub_is_readonly()) {
    post_media_json(['ok' => false, 'error' => 'readonly'], 403);
}

$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if ($csrf === '' || !hash_equals(csrfToken(), $csrf)) {
    post_media_json(['ok' => false, 'error' => 'csrf'], 403);
}

// Release the session lock before moving large media. Pending tokens are
// stored on disk so parallel uploads no longer wait on each other.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$files = [];
if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
    $n = count($_FILES['attachments']['name']);
    for ($i = 0; $i < $n; $i++) {
        $files[] = [
            'name' => $_FILES['attachments']['name'][$i] ?? '',
            'type' => $_FILES['attachments']['type'][$i] ?? '',
            'tmp_name' => $_FILES['attachments']['tmp_name'][$i] ?? '',
            'error' => $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $_FILES['attachments']['size'][$i] ?? 0,
        ];
    }
} elseif (isset($_FILES['file']) && is_array($_FILES['file'])) {
    $files[] = $_FILES['file'];
}

if ($files === []) {
    post_media_json(['ok' => false, 'error' => 'no_files'], 400);
}

$saved = [];
$errors = [];
foreach ($files as $file) {
    $result = post_upload_store_pending($meId, $file);
    if (!empty($result['ok'])) {
        $saved[] = [
            'token' => (string)$result['token'],
            'name' => (string)$result['name'],
            'type' => (string)$result['type'],
            'size' => (int)$result['size'],
        ];
    } else {
        $errors[] = [
            'name' => (string)($file['name'] ?? ''),
            'error' => (string)($result['error'] ?? 'failed'),
        ];
    }
}

if ($saved === []) {
    post_media_json(['ok' => false, 'error' => 'upload_failed', 'errors' => $errors], 422);
}

post_media_json([
    'ok' => true,
    'files' => $saved,
    'errors' => $errors,
]);
