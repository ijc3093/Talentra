<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function signal_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function signal_table_exists(PDO $dbh, string $table): bool
{
    try {
        $st = $dbh->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function signal_ensure_table(PDO $dbh): bool
{
    if (signal_table_exists($dbh, 'user_video_live_signals')) {
        return true;
    }

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_signals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                live_id BIGINT UNSIGNED NOT NULL,
                sender_user_id INT NOT NULL,
                receiver_user_id INT NOT NULL,
                peer_key VARCHAR(80) NOT NULL,
                signal_type VARCHAR(20) NOT NULL,
                payload_json MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                consumed_at DATETIME NULL DEFAULT NULL,
                KEY idx_receiver_live (receiver_user_id, live_id, consumed_at, id),
                KEY idx_peer_live (peer_key, live_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function signal_load_live(PDO $dbh, int $liveId): ?array
{
    if ($liveId <= 0 || !signal_table_exists($dbh, 'user_video_lives')) {
        return null;
    }
    try {
        $st = $dbh->prepare("
            SELECT id, user_id, status, visibility
            FROM user_video_lives
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $liveId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function signal_can_view(PDO $dbh, array $live, int $meId): bool
{
    $ownerId = (int)($live['user_id'] ?? 0);
    $visibility = strtolower(trim((string)($live['visibility'] ?? 'private')));
    if ($ownerId === $meId) {
        return true;
    }
    if ($visibility === 'public') {
        return true;
    }
    return $visibility === 'friends' && fs_are_friends($dbh, $meId, $ownerId);
}

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$liveId = (int)($_GET['live_id'] ?? $_POST['live_id'] ?? 0);

if ($meId <= 0 || $liveId <= 0) {
    signal_json(['ok' => false, 'error' => 'Invalid request']);
}
if (!signal_ensure_table($dbh)) {
    signal_json(['ok' => false, 'error' => 'Signal storage unavailable']);
}

$live = signal_load_live($dbh, $liveId);
if (!$live) {
    signal_json(['ok' => false, 'error' => 'Live room not found']);
}

$ownerId = (int)($live['user_id'] ?? 0);
$isHost = $ownerId === $meId;
if (!$isHost && !signal_can_view($dbh, $live, $meId)) {
    signal_json(['ok' => false, 'error' => 'Access denied']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $peerKey = trim((string)($_GET['peer_key'] ?? ''));
    try {
        $sql = "
            SELECT id, sender_user_id, receiver_user_id, peer_key, signal_type, payload_json, created_at
            FROM user_video_live_signals
            WHERE receiver_user_id = :receiver
              AND live_id = :live_id
              AND consumed_at IS NULL
        ";
        $params = [
            ':receiver' => $meId,
            ':live_id' => $liveId,
        ];
        if ($peerKey !== '') {
            $sql .= " AND peer_key = :peer_key";
            $params[':peer_key'] = $peerKey;
        }
        $sql .= " ORDER BY id ASC LIMIT 100";
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows) {
            $ids = array_map(static function (array $row): int {
                return (int)($row['id'] ?? 0);
            }, $rows);
            $ids = array_values(array_filter($ids));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $up = $dbh->prepare("UPDATE user_video_live_signals SET consumed_at = NOW() WHERE id IN ($ph)");
                $up->execute($ids);
            }
        }

        $signals = array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'sender_user_id' => (int)($row['sender_user_id'] ?? 0),
                'receiver_user_id' => (int)($row['receiver_user_id'] ?? 0),
                'peer_key' => (string)($row['peer_key'] ?? ''),
                'signal_type' => (string)($row['signal_type'] ?? ''),
                'payload' => json_decode((string)($row['payload_json'] ?? '{}'), true) ?: [],
            ];
        }, $rows);

        signal_json(['ok' => true, 'signals' => $signals]);
    } catch (Throwable $e) {
        signal_json(['ok' => false, 'error' => 'Unable to load signals']);
    }
}

$receiverId = (int)($_POST['receiver_id'] ?? 0);
$peerKey = trim((string)($_POST['peer_key'] ?? ''));
$signalType = trim((string)($_POST['signal_type'] ?? ''));
$payload = $_POST['payload'] ?? '';

if ($receiverId <= 0 || $peerKey === '' || $signalType === '') {
    signal_json(['ok' => false, 'error' => 'Missing signal fields']);
}

$allowedTypes = ['offer', 'answer', 'candidate', 'bye'];
if (!in_array($signalType, $allowedTypes, true)) {
    signal_json(['ok' => false, 'error' => 'Invalid signal type']);
}

if (!$isHost) {
    if ($receiverId !== $ownerId) {
        signal_json(['ok' => false, 'error' => 'Invalid receiver']);
    }
} else {
    if ($receiverId === $ownerId) {
        signal_json(['ok' => false, 'error' => 'Invalid receiver']);
    }
}

$decoded = json_decode((string)$payload, true);
if (!is_array($decoded)) {
    $decoded = [];
}

try {
    $st = $dbh->prepare("
        INSERT INTO user_video_live_signals (live_id, sender_user_id, receiver_user_id, peer_key, signal_type, payload_json, created_at)
        VALUES (:live_id, :sender, :receiver, :peer_key, :signal_type, :payload_json, NOW())
    ");
    $st->execute([
        ':live_id' => $liveId,
        ':sender' => $meId,
        ':receiver' => $receiverId,
        ':peer_key' => mb_substr($peerKey, 0, 80),
        ':signal_type' => $signalType,
        ':payload_json' => json_encode($decoded, JSON_UNESCAPED_SLASHES),
    ]);
    signal_json(['ok' => true]);
} catch (Throwable $e) {
    signal_json(['ok' => false, 'error' => 'Unable to send signal']);
}
