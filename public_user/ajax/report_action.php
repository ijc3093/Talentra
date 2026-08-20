<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/msb_reports.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function msb_report_json(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

if ($meId <= 0) {
    msb_report_json(['ok' => false, 'error' => 'Please sign in to report.']);
}

$targetType = (string)($_POST['target_type'] ?? $_GET['target_type'] ?? '');
$targetId = (int)($_POST['target_id'] ?? $_GET['target_id'] ?? 0);
$reason = (string)($_POST['reason'] ?? $_GET['reason'] ?? 'other');
$details = (string)($_POST['details'] ?? $_GET['details'] ?? '');

$label = trim((string)($_SESSION['user_email'] ?? ''));
if ($label === '') {
    $label = trim((string)($_SESSION['user_login'] ?? $_SESSION['username'] ?? ''));
}

$res = msb_reports_create(
    $dbh,
    $meId,
    'user',
    $targetType,
    $targetId,
    $reason,
    $details,
    0,
    $label
);

if (empty($res['ok'])) {
    msb_report_json(['ok' => false, 'error' => (string)($res['error'] ?? 'Could not submit report.')]);
}

msb_report_json([
    'ok' => true,
    'id' => (int)($res['id'] ?? 0),
    'duplicate' => !empty($res['duplicate']),
    'message' => !empty($res['duplicate'])
        ? 'You already reported this. Our team will review it.'
        : 'Thanks — your report was sent to admin.',
]);
