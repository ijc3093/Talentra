<?php
// /Business_only/ajax/user_presence_ping.php
// Purpose: Update current user's last_seen and return online/offline info for a peer (WhatsApp-like)

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';
/** Convert seconds to a friendly "Last seen X ago" label. */
function seconds_ago_label(int $sec): string {
    if ($sec < 0) $sec = 0;
    if ($sec < 10) return 'Last seen just now';
    if ($sec < 60) return 'Last seen ' . $sec . 's ago';
    $m = (int) floor($sec / 60);
    if ($m < 60) return 'Last seen ' . $m . 'm ago';
    $h = (int) floor($m / 60);
    if ($h < 24) return 'Last seen ' . $h . 'h ago';
    $d = (int) floor($h / 24);
    if ($d < 7) return 'Last seen ' . $d . 'd ago';
    $w = (int) floor($d / 7);
    if ($w < 5) return 'Last seen ' . $w . 'w ago';
    $mo = (int) floor($d / 30);
    if ($mo < 12) return 'Last seen ' . $mo . 'mo ago';
    $y = (int) floor($d / 365);
    return 'Last seen ' . $y . 'y ago';
}


/** Treat user as active unless status explicitly indicates inactive/disabled. */
function is_user_active($status): bool {
    if ($status === null) return true;
    // numeric statuses: 1/0
    if (is_numeric($status)) return ((int)$status) !== 0;
    $s = strtolower(trim((string)$status));
    if ($s === '') return true;
    return !in_array($s, ['inactive','disabled','banned','suspended','0','false','no'], true);
}



/**
 * Presence label:
 * - label: Online/Offline (simple)
 * - last_seen_label: human readable timestamp (optional for tooltip/UI)
 */
function online_info_local(?string $lastSeen, int $thresholdSeconds = 120): array {
    $lastSeen = (string)($lastSeen ?? '');
    if ($lastSeen === '') return ['online' => false, 'label' => 'Last seen recently', 'last_seen_label' => ''];

    $ts = strtotime($lastSeen);
    if (!$ts) return ['online' => false, 'label' => 'Last seen recently', 'last_seen_label' => ''];

    $online = (time() - $ts) <= $thresholdSeconds;
    return [
        'online' => $online,
        'label' => ($online ? 'Online' : 'Offline'),
        'last_seen_label' => date('M j, Y g:i A', $ts),
    ];
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    // Always bump MY last_seen
    $meId = (int)($_SESSION['user_id'] ?? 0);
    if ($meId > 0) {
        $st = $dbh->prepare("UPDATE users SET last_seen = NOW() WHERE id = :id LIMIT 1");
        $st->execute([':id' => $meId]);
    }

    $peer = strtoupper(trim((string)($_GET['peer'] ?? '')));
    if ($peer === '') {
        echo json_encode(['ok' => true, 'online' => true, 'label' => 'Online', 'last_seen_label' => date('M j, Y g:i A')]);
        exit;
    }

    $stPeer = $dbh->prepare("SELECT last_seen, status, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS age_seconds FROM users WHERE UPPER(friend_code) = :c LIMIT 1");
    $stPeer->execute([':c' => $peer]);
    $row = $stPeer->fetch(PDO::FETCH_ASSOC);

    if (!$row || !is_user_active($row['status'] ?? null)) {
        echo json_encode(['ok' => true, 'online' => false, 'label' => 'Last seen recently', 'last_seen_label' => '']);
        exit;
    }

    $age = (int)($row['age_seconds'] ?? 999999);
    $online = ($age <= 300);
    echo json_encode([
        'ok' => true,
        'online' => $online,
        'label' => ($online ? 'Online' : seconds_ago_label($age)),
        'last_seen_label' => (string)($row['last_seen'] ?? ''),
        'age_seconds' => $age,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
