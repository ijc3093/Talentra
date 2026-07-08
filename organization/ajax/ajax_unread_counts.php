<?php
// /Business_only3/organization/ajax/ajax_unread_counts.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ajax/ is one level below organization/, so go up for includes
require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../includes/org_header_counts.php';

$out = ['ok' => false, 'total' => 0, 'feedUnread' => 0, 'byPeer' => []];

try {
    $orgId = (int)orgActiveOrgId();
    $meMid = (int)orgMemberId();

    if ($orgId <= 0 || $meMid <= 0) {
        echo json_encode($out);
        exit;
    }

    $total = org_header_message_unread_count($dbh, $orgId, $meMid);
    $feedUnread = org_header_feed_unread_count($dbh, $orgId, $meMid);

    // Unread per sender peer (for left list pills)
    $stP = $dbh->prepare("\n        SELECT sender_member_id AS sid, COUNT(*) AS c\n        FROM org_messages\n        WHERE org_id = :org\n          AND receiver_member_id = :me\n          AND is_read = 0\n        GROUP BY sender_member_id\n    ");
    $stP->execute([':org' => $orgId, ':me' => $meMid]);

    $byPeer = [];
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (int)($r['sid'] ?? 0);
        $c   = (int)($r['c'] ?? 0);
        if ($sid > 0 && $c > 0) $byPeer[(string)$sid] = $c;
    }

    $out['ok'] = true;
    $out['total'] = $total;
    $out['feedUnread'] = $feedUnread;
    $out['byPeer'] = $byPeer;

} catch (Throwable $e) {
    // keep default out
}

echo json_encode($out);
