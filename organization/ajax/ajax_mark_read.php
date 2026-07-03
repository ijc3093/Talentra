<?php
// /Business_only3/organization/ajax/ajax_mark_read.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';

$out = ['ok' => false];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode($out);
        exit;
    }

    $orgId = (int)orgActiveOrgId();
    $meMid = (int)orgMemberId();
    $peer  = (int)($_POST['peer'] ?? 0);

    if ($orgId <= 0 || $meMid <= 0 || $peer <= 0) {
        echo json_encode($out);
        exit;
    }

    // Verify peer belongs to same org
    $stV = $dbh->prepare("\n        SELECT id\n        FROM org_members\n        WHERE org_id = :org\n          AND id = :peer\n          AND status = 1\n        LIMIT 1\n    ");
    $stV->execute([':org' => $orgId, ':peer' => $peer]);
    if (!$stV->fetch()) {
        echo json_encode($out);
        exit;
    }

    // Mark unread messages from this peer -> me as read
    $st = $dbh->prepare("\n        UPDATE org_messages\n        SET is_read = 1, read_at = NOW()\n        WHERE org_id = :org\n          AND sender_member_id = :peer\n          AND receiver_member_id = :me\n          AND is_read = 0\n    ");
    $st->execute([':org' => $orgId, ':peer' => $peer, ':me' => $meMid]);

    $out['ok'] = true;

} catch (Throwable $e) {
    // keep ok false
}

echo json_encode($out);
