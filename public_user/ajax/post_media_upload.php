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
    // Keep application errors off HTTP 403. Hosts (and some Apache ErrorDocument
    // setups) replace a 403 body with HTML, and the create-post UI then shows http_403.
    if ($code === 403) {
        $code = 409;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json; charset=utf-8');
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

$headerCsrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
$csrf = trim((string)($_POST['csrf_token'] ?? $headerCsrf));
if ($csrf === '' && $headerCsrf !== '') {
    $csrf = $headerCsrf;
}
$sessionCsrf = csrfToken();
$csrfOk = ($sessionCsrf !== '' && $csrf !== '' && hash_equals($sessionCsrf, $csrf));
if (!$csrfOk && function_exists('requestHasValidCsrf') && requestHasValidCsrf()) {
    $csrfOk = true;
}
if (!$csrfOk) {
    post_media_json(['ok' => false, 'error' => 'csrf', 'message' => 'Session expired. Refresh the page and try again.'], 409);
}

// Release the session lock before moving large media. Pending tokens are
// stored on disk so parallel uploads no longer wait on each other.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$files = [];
if (isset($_FILES['attachments'])) {
    $att = $_FILES['attachments'];
    if (is_array($att['name'] ?? null)) {
        $n = count($att['name']);
        for ($i = 0; $i < $n; $i++) {
            $files[] = [
                'name' => $att['name'][$i] ?? '',
                'type' => $att['type'][$i] ?? '',
                'tmp_name' => $att['tmp_name'][$i] ?? '',
                'error' => $att['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $att['size'][$i] ?? 0,
            ];
        }
    } elseif (is_array($att)) {
        $files[] = $att;
    }
} elseif (isset($_FILES['file']) && is_array($_FILES['file'])) {
    $files[] = $_FILES['file'];
}

if ($files === []) {
    // Empty $_FILES often means the request exceeded post_max_size.
    $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = ini_get('post_max_size');
    if ($contentLen > 0 && empty($_POST) && empty($_FILES)) {
        post_media_json([
            'ok' => false,
            'error' => 'too_large',
            'message' => 'File is too large for the server upload limit' . ($postMax ? (' (' . $postMax . ')') : '') . '.',
        ], 413);
    }
    post_media_json(['ok' => false, 'error' => 'no_files', 'message' => 'No file received.'], 400);
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
            'web' => (string)($result['web'] ?? ''),
        ];
    } else {
        $errors[] = [
            'name' => (string)($file['name'] ?? ''),
            'error' => (string)($result['error'] ?? 'failed'),
        ];
    }
}

if ($saved === []) {
    $first = (string)(($errors[0]['error'] ?? '') ?: 'upload_failed');
    $messages = [
        'too_large' => 'File is too large (max ' . post_upload_max_label() . ').',
        'unsupported_type' => 'That file type is not supported. Use JPG, PNG, GIF, WEBP, MP4, MOV, PDF, or Office files.',
        'heic_unsupported' => 'HEIC/HEIF photos are not supported here. Export as JPG or PNG and try again.',
        'mime_mismatch' => 'File type did not match its contents. Try another file or re-export it.',
        'move_failed' => 'Could not save the file on the server. Try again.',
        'dir_not_writable' => 'Upload folder is not writable on the server.',
        'invalid_tmp' => 'Upload did not arrive completely. Try again.',
        'partial' => 'Upload was interrupted. Try again.',
        'cant_write' => 'Server could not write the temp upload. Try again.',
        'no_tmp_dir' => 'Server temp folder is missing.',
        'csrf' => 'Session expired. Refresh and try again.',
    ];
    post_media_json([
        'ok' => false,
        'error' => $first,
        'message' => $messages[$first] ?? ('Upload failed (' . $first . ').'),
        'errors' => $errors,
    ], 422);
}

post_media_json([
    'ok' => true,
    'files' => $saved,
    'errors' => $errors,
]);
