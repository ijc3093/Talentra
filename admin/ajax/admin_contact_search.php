<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/identity.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * MySQL: SHOW TABLES LIKE does NOT like prepared placeholders.
 * Use quote() instead.
 */
function tableExists(PDO $dbh, string $table): bool {
    $q = $dbh->query("SHOW TABLES LIKE " . $dbh->quote($table));
    return (bool)$q->fetchColumn();
}

try {
    $term = trim($_GET['term'] ?? '');
    $term = preg_replace('/\s+/', ' ', $term);

    if ($term === '' || mb_strlen($term) < 1) {
        echo json_encode(['ok' => true, 'items' => []]);
        exit;
    }

    $controller = new Controller();
    $dbh = $controller->pdo();

    $meId = myAdminId();
    if ($meId <= 0) {
        echo json_encode(['ok' => false, 'items' => [], 'error' => 'Invalid session']);
        exit;
    }

    // ✅ Pick the correct table name
    $contactsTable = null;
    if (tableExists($dbh, 'admin_contacts')) {
        $contactsTable = 'admin_contacts';
    } elseif (tableExists($dbh, 'admin_contacts')) {
        $contactsTable = 'admin_contacts';
    } else {
        echo json_encode(['ok' => false, 'items' => [], 'error' => 'No admin contacts table found']);
        exit;
    }

    $termUpper = strtoupper($term);
    $like      = '%' . $term . '%';
    $likeCode  = '%' . $termUpper . '%';

    // ✅ Only MY contacts
    $sql = "
        SELECT
            a.idadmin AS friend_admin_id,
            COALESCE(NULLIF(ac.display_name,''), a.username) AS full_name,
            COALESCE(a.friend_code,'') AS friend_code
        FROM {$contactsTable} ac
        JOIN admin a ON a.idadmin = ac.friend_admin_id
        WHERE ac.owner_admin_id = :me
          AND a.status = 1
          AND (
                COALESCE(NULLIF(ac.display_name,''), '') LIKE :like1
             OR a.username LIKE :like2
             OR UPPER(COALESCE(a.friend_code,'')) LIKE :like3
          )
        ORDER BY
          (UPPER(COALESCE(a.friend_code,'')) = :exactCode) DESC,
          (a.username = :exactUser) DESC,
          full_name ASC
        LIMIT 20
    ";

    $st = $dbh->prepare($sql);
    $st->execute([
        ':me'        => $meId,
        ':like1'     => $like,
        ':like2'     => $like,
        ':like3'     => $likeCode,
        ':exactCode' => $termUpper,
        ':exactUser' => $term,
    ]);

    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'    => true,
        'table' => $contactsTable,
        'count' => count($items),
        'items' => array_map(function($r){
            return [
                'friend_admin_id' => (int)($r['friend_admin_id'] ?? 0),
                'full_name'       => (string)($r['full_name'] ?? ''),
                'friend_code'     => (string)($r['friend_code'] ?? ''),
            ];
        }, $items)
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'items' => [], 'error' => $e->getMessage()]);
}
