<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $a): void { echo json_encode($a); exit; }

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) j(['ok' => false, 'error' => 'Invalid session']);

$afterSignalId = (int)($_GET['after_signal_id'] ?? 0);
$wait = (int)($_GET['wait'] ?? 0);
if ($wait < 0) $wait = 0;
if ($wait > 12) $wait = 12;

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $deadline = $wait > 0 ? (microtime(true) + $wait) : microtime(true);

    do {
        $stSig = $dbh->prepare("
            SELECT
              s.id,
              s.call_id,
              s.signal_type,
              s.payload,
              s.from_user_id,
              s.to_user_id,
              s.created_at,
              c.status AS call_status,
              c.caller_user_id,
              c.caller_code,
              c.callee_user_id,
              c.callee_code,
              u.name AS caller_name,
              u.username AS caller_username
            FROM user_video_call_signals s
            JOIN user_video_calls c ON c.id = s.call_id
            LEFT JOIN users u ON u.id = c.caller_user_id
            WHERE s.to_user_id = :me
              AND s.id > :after_id
              AND c.callee_user_id = :me2
              AND c.status IN ('initiated','ringing','active','ended','declined')
            ORDER BY s.id ASC
            LIMIT 100
        ");
        $stSig->execute([
            ':me' => $meId,
            ':after_id' => $afterSignalId,
            ':me2' => $meId,
        ]);
        $signals = $stSig->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lastSignalId = $afterSignalId;
        foreach ($signals as $sig) {
            $sid = (int)($sig['id'] ?? 0);
            if ($sid > $lastSignalId) $lastSignalId = $sid;
        }

        if ($signals) {
            $dbh->prepare("
                UPDATE user_video_call_signals
                SET consumed_at = NOW()
                WHERE to_user_id = :uid
                  AND id <= :max_id
            ")->execute([
                ':uid' => $meId,
                ':max_id' => $lastSignalId,
            ]);
        }

        if ($signals || $wait <= 0 || microtime(true) >= $deadline) {
            j([
                'ok' => true,
                'signals' => $signals,
                'last_signal_id' => $lastSignalId,
            ]);
        }

        usleep(250000);
    } while (microtime(true) < $deadline);

    j([
        'ok' => true,
        'signals' => [],
        'last_signal_id' => $afterSignalId,
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Database error']);
}
