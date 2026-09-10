<?php
declare(strict_types=1);

/**
 * Lightweight End Live endpoint for the door hub.
 * Avoids the heavy host_action history summary that can stall/crash under
 * concurrent snapshot/signal traffic (Safari: "Load failed").
 */

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function live_end_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$meId = (int)($_SESSION['user_id'] ?? 0);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($meId <= 0) {
    live_end_json(['ok' => false, 'error' => 'Invalid session'], 401);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    live_end_json(['ok' => false, 'error' => 'POST required'], 405);
}

$liveId = (int)($_POST['live_id'] ?? 0);

try {
    $dbh = (new Controller())->pdo();

    if ($liveId <= 0) {
        $stFind = $dbh->prepare("
            SELECT id
            FROM user_video_lives
            WHERE user_id = :uid
              AND status = 'live'
            ORDER BY COALESCE(started_at, updated_at, created_at) DESC, id DESC
            LIMIT 1
        ");
        $stFind->execute([':uid' => $meId]);
        $liveId = (int)($stFind->fetchColumn() ?: 0);
    }

    if ($liveId <= 0) {
        live_end_json(['ok' => true, 'ended' => false, 'live_id' => 0, 'message' => 'No live session to end']);
    }

    // Confirm ownership before ending.
    $stOwn = $dbh->prepare("
        SELECT id, status
        FROM user_video_lives
        WHERE id = :id
          AND user_id = :uid
        LIMIT 1
    ");
    $stOwn->execute([
        ':id' => $liveId,
        ':uid' => $meId,
    ]);
    $row = $stOwn->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        live_end_json(['ok' => false, 'error' => 'Live session not found'], 404);
    }

    $stEnd = $dbh->prepare("
        UPDATE user_video_lives
        SET status = 'ended',
            ended_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND user_id = :uid
          AND status IN ('live', 'scheduled', 'draft')
        LIMIT 1
    ");
    $stEnd->execute([
        ':id' => $liveId,
        ':uid' => $meId,
    ]);
    $ended = $stEnd->rowCount() > 0;

    // Best-effort cleanup — never fail the end response.
    try {
        $dbh->prepare("DELETE FROM user_video_live_viewers WHERE live_id = :live_id")
            ->execute([':live_id' => $liveId]);
    } catch (Throwable $e) {
    }
    try {
        $dbh->prepare("DELETE FROM user_video_live_guest_requests WHERE live_id = :live_id")
            ->execute([':live_id' => $liveId]);
    } catch (Throwable $e) {
    }
    try {
        $dbh->prepare("DELETE FROM user_video_live_signals WHERE live_id = :live_id")
            ->execute([':live_id' => $liveId]);
    } catch (Throwable $e) {
    }
    try {
        $marker = '[[live_post:' . $liveId . ']]';
        $dbh->prepare("
            UPDATE public_posts
            SET is_deleted = 1,
                updated_at = NOW()
            WHERE user_id = :uid
              AND is_deleted = 0
              AND body LIKE :marker_like
        ")->execute([
            ':uid' => $meId,
            ':marker_like' => '%' . $marker . '%',
        ]);
    } catch (Throwable $e) {
    }

    $snapshotPath = __DIR__ . '/../storage/live_snapshots/' . $liveId . '.jpg';
    if (is_file($snapshotPath)) {
        @unlink($snapshotPath);
    }
    foreach ((array)glob(__DIR__ . '/../storage/live_snapshots/' . $liveId . '_guest_*.jpg') as $guestSnapshotPath) {
        if (is_file($guestSnapshotPath)) {
            @unlink($guestSnapshotPath);
        }
    }

    live_end_json([
        'ok' => true,
        'ended' => $ended || strtolower(trim((string)($row['status'] ?? ''))) === 'ended',
        'live_id' => $liveId,
        'live' => null,
    ]);
} catch (Throwable $e) {
    error_log('live_end failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    live_end_json(['ok' => false, 'error' => 'Unable to end live'], 500);
}
