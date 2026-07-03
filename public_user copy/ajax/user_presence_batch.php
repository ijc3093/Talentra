<?php
// /Business_only/ajax/user_presence_batch.php
// Purpose: Update current user's last_seen and return online/offline info for MANY peers (WhatsApp-like)

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



function online_info_local_batch(?string $lastSeen, int $thresholdSeconds = 300, ?int $ageSeconds = null): array {
    if ($ageSeconds !== null) {
        $online = ($ageSeconds <= $thresholdSeconds);
        return [
            'online' => $online,
            'label' => ($online ? 'Online' : seconds_ago_label((int)($ageSeconds ?? 999999))),
            'last_seen_label' => (string)($lastSeen ?? ''),
            'age_seconds' => $ageSeconds,
        ];
    }

    $lastSeen = (string)($lastSeen ?? '');
    if ($lastSeen === '') return ['online' => false, 'label' => 'Offline', 'last_seen_label' => '', 'age_seconds' => null];

    $ts = strtotime($lastSeen);
    if (!$ts) return ['online' => false, 'label' => 'Offline', 'last_seen_label' => '', 'age_seconds' => null];

    $age = time() - $ts;
    $online = ($age <= $thresholdSeconds);
    return [
        'online' => $online,
        'label' => ($online ? 'Online' : seconds_ago_label((int)($ageSeconds ?? 999999))),
        'last_seen_label' => date('M j, Y g:i A', $ts),
        'age_seconds' => $age,
    ];
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    // Always bump MY last_seen (keeps me online while I browse)
    $meId = (int)($_SESSION['user_id'] ?? 0);
    if ($meId > 0) {
        $st = $dbh->prepare("UPDATE users SET last_seen = NOW() WHERE id = :id LIMIT 1");
        $st->execute([':id' => $meId]);
    }

    // Accept peers as JSON array OR comma-separated string
    $rawPeers = $_POST['peers'] ?? '';
    $peers = [];
    if (is_array($rawPeers)) {
        $peers = $rawPeers;
    } else {
        $rawPeers = trim((string)$rawPeers);
        if ($rawPeers !== '') {
            $maybeJson = json_decode($rawPeers, true);
            if (is_array($maybeJson)) {
                $peers = $maybeJson;
            } else {
                $peers = preg_split('/\s*,\s*/', $rawPeers) ?: [];
            }
        }
    }

    // Normalize + de-dup
    $norm = [];
    foreach ($peers as $p) {
        $c = strtoupper(trim((string)$p));
        if ($c !== '') $norm[$c] = true;
    }
    $peerCodes = array_keys($norm);

    // Hard cap for safety
    if (count($peerCodes) > 200) {
        $peerCodes = array_slice($peerCodes, 0, 200);
    }

    if (!$peerCodes) {
        echo json_encode(['ok' => true, 'data' => new stdClass()]);
        exit;
    }

    $ph = implode(',', array_fill(0, count($peerCodes), '?'));
    $sql = "SELECT UPPER(friend_code) AS code, last_seen, status, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS age_seconds FROM users WHERE UPPER(friend_code) IN ($ph)";
    $st = $dbh->prepare($sql);
    $st->execute($peerCodes);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($peerCodes as $c) {
        $out[$c] = ['online' => false, 'label' => 'Offline'];
    }

    foreach ($rows as $r) {
        $code = strtoupper((string)($r['code'] ?? ''));
        if ($code === '') continue;

        if (!is_user_active($r['status'] ?? null)) {
            $out[$code] = ['online' => false, 'label' => 'Offline'];
            continue;
        }

        $age = (int)($r['age_seconds'] ?? 999999);
        $online = ($age <= 300);
        $out[$code] = [
            'online' => $online,
            'label' => ($online ? 'Online' : seconds_ago_label($age)),
            'last_seen_label' => (string)($r['last_seen'] ?? ''),
            'age_seconds' => $age,
        ];
}

    echo json_encode(['ok' => true, 'data' => $out]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
