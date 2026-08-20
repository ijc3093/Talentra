<?php
declare(strict_types=1);

/**
 * Organization manager/staff → platform abuse reports.
 */

require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../../public_user/includes/msb_reports.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function org_report_json(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// Any logged-in org account can report (manager or staff).
if (!function_exists('orgAccountId') || (int)orgAccountId() <= 0) {
    org_report_json(['ok' => false, 'error' => 'Please sign in.']);
}

$orgId = (int)(function_exists('orgActiveOrgId') ? orgActiveOrgId() : ($_SESSION['org_active_org_id'] ?? 0));
$managerId = (int)($_SESSION['manager_id'] ?? $_SESSION['org_manager_id'] ?? 0);
$staffId = (int)($_SESSION['staff_id'] ?? $_SESSION['org_account_id'] ?? 0);
$isManager = function_exists('isOrgManager') ? isOrgManager() : ($managerId > 0);
$reporterKind = $isManager ? 'manager' : 'staff';
$reporterId = $isManager ? max($managerId, (int)orgAccountId()) : max($staffId, (int)orgAccountId());

// Prefer linked publisher user id when available so admin can see a public_user face.
$publisherUserId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
if ($publisherUserId > 0) {
    $reporterId = $publisherUserId;
    $reporterKind = 'user';
}

$label = trim((string)($_SESSION['manager_email'] ?? $_SESSION['org_email'] ?? ''));
if ($label === '') {
    $label = trim((string)($_SESSION['manager_username'] ?? $_SESSION['org_username'] ?? 'org'));
}
$label = 'org:' . $label;

$targetType = (string)($_POST['target_type'] ?? $_GET['target_type'] ?? '');
$targetId = (int)($_POST['target_id'] ?? $_GET['target_id'] ?? 0);
$reason = (string)($_POST['reason'] ?? $_GET['reason'] ?? 'other');
$details = (string)($_POST['details'] ?? $_GET['details'] ?? '');

$res = msb_reports_create(
    $dbh,
    $reporterId,
    $reporterKind,
    $targetType,
    $targetId,
    $reason,
    $details,
    $orgId,
    $label
);

if (empty($res['ok'])) {
    org_report_json(['ok' => false, 'error' => (string)($res['error'] ?? 'Could not submit report.')]);
}

org_report_json([
    'ok' => true,
    'id' => (int)($res['id'] ?? 0),
    'duplicate' => !empty($res['duplicate']),
    'message' => !empty($res['duplicate'])
        ? 'Already reported — pending admin review.'
        : 'Thanks — report sent to platform admin.',
]);
