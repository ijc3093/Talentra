<?php
// LOGIN_FIX_2026_07_25 — temporary; delete after debugging
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
echo "diag_ok LOGIN_FIX_2026_07_25\n";
echo 'php=' . PHP_VERSION . "\n";
echo 'mbstring=' . (extension_loaded('mbstring') ? '1' : '0') . "\n";

try {
    require_once __DIR__ . '/../config.php';
    $pdo = (new Config())->pdo();
    echo "db=ok\n";
} catch (Throwable $e) {
    echo 'db_fail=' . $e->getMessage() . "\n";
    exit;
}

try {
    $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    echo 'users_cols=' . implode(',', $cols) . "\n";
    foreach (['account_kind', 'mobile', 'friend_code', 'last_seen'] as $c) {
        echo $c . '=' . (in_array($c, $cols, true) ? '1' : '0') . "\n";
    }
} catch (Throwable $e) {
    echo 'users_fail=' . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/controller.php';
    $r = (new Controller())->userLoginAttempt('john_k', 'test123');
    echo 'login_attempt=' . json_encode($r) . "\n";
} catch (Throwable $e) {
    echo 'login_fail=' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
