<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/includes/external_social_feed.php';
require_once __DIR__ . '/includes/news2_engagement.php';
require_once __DIR__ . '/controller.php';

requireUserLogin();

header('Content-Type: application/json; charset=UTF-8');

$meId = (int)($_SESSION['user_id'] ?? 0);
$action = strtolower(trim((string)($_REQUEST['action'] ?? 'feed')));

function news2_api_user_name(): string
{
    $name = trim((string)($_SESSION['user_name'] ?? $_SESSION['user_login'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    try {
        $controller = new Controller();
        $dbh = $controller->pdo();
        $st = $dbh->prepare('SELECT COALESCE(NULLIF(name, ""), NULLIF(username, ""), friend_code) AS dn FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => (int)($_SESSION['user_id'] ?? 0)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return trim((string)($row['dn'] ?? 'User')) ?: 'User';
        }
    } catch (Throwable $e) {
    }
    return 'User';
}

try {
    @set_time_limit(90);
    if ($action === 'feed') {
        $category = strtolower(trim((string)($_GET['category'] ?? 'all')));
        $query = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 40);
        if ($limit < 1) {
            $limit = 40;
        }
        if ($limit > 80) {
            $limit = 80;
        }
        $items = social_news_collect($category, $query, $limit);
        $items = news2_engagement_attach_many($items, $meId);
        echo json_encode([
            'ok' => true,
            'category' => external_news_is_valid_category($category) ? $category : 'all',
            'count' => count($items),
            'items' => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'comments') {
        $itemId = trim((string)($_GET['item_id'] ?? ''));
        if ($itemId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing item_id.']);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'item_id' => $itemId,
            'comments' => news2_engagement_get_comments($itemId),
            'engagement' => news2_engagement_for_user(news2_engagement_read($itemId), $meId),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'react') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required.']);
            exit;
        }
        $itemId = trim((string)($_POST['item_id'] ?? ''));
        $reaction = strtolower(trim((string)($_POST['reaction'] ?? '')));
        if ($itemId === '' || $meId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
            exit;
        }
        $eng = news2_engagement_react($itemId, $meId, $reaction);
        echo json_encode(['ok' => true, 'item_id' => $itemId] + $eng, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'comment') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required.']);
            exit;
        }
        $itemId = trim((string)($_POST['item_id'] ?? ''));
        $text = trim((string)($_POST['text'] ?? ''));
        if ($itemId === '' || $text === '' || $meId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid comment.']);
            exit;
        }
        $comment = news2_engagement_add_comment($itemId, $meId, news2_api_user_name(), $text);
        $eng = news2_engagement_for_user(news2_engagement_read($itemId), $meId);
        echo json_encode([
            'ok' => true,
            'item_id' => $itemId,
            'comment' => $comment,
            'engagement' => $eng,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'News2 API error.']);
}
