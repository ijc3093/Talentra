<?php
declare(strict_types=1);

/**
 * One-time demo publisher accounts (CNN, Fox News, ABC, …).
 * Run once in browser or CLI, then delete or restrict access.
 *
 * Default password for all seeded accounts: Publisher@12345
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';

$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);

$defaultPassword = 'Publisher@12345';
$passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);

$publishers = [
    [
        'name' => 'CNN',
        'username' => 'cnn',
        'email' => 'cnn@talentra.demo',
        'category' => 'news',
        'tagline' => 'Breaking news and daily updates from CNN',
        'welcome_title' => 'Welcome to CNN on Talentra',
        'welcome_body' => 'Follow us for breaking news, politics, and world updates. Tap Follow on public.php to see our posts in your feed.',
    ],
    [
        'name' => 'Fox News',
        'username' => 'foxnews',
        'email' => 'foxnews@talentra.demo',
        'category' => 'news',
        'tagline' => 'America\'s most-watched cable news network',
        'welcome_title' => 'Fox News is now on Talentra',
        'welcome_body' => 'Follow Fox News for top stories, opinion, and live coverage.',
    ],
    [
        'name' => 'ABC News',
        'username' => 'abc',
        'email' => 'abc@talentra.demo',
        'category' => 'news',
        'tagline' => 'Trusted news from ABC',
        'welcome_title' => 'ABC News — official publisher account',
        'welcome_body' => 'Search ABC on public.php and tap Follow to get our latest posts in feed.php.',
    ],
    [
        'name' => 'NBC News',
        'username' => 'nbc',
        'email' => 'nbc@talentra.demo',
        'category' => 'news',
        'tagline' => 'NBC News — breaking news and in-depth reporting',
        'welcome_title' => 'NBC News on Talentra',
        'welcome_body' => 'Follow NBC News for national and international headlines.',
    ],
    [
        'name' => 'BBC News',
        'username' => 'bbc',
        'email' => 'bbc@talentra.demo',
        'category' => 'news',
        'tagline' => 'Impartial news from the BBC',
        'welcome_title' => 'BBC News publisher account',
        'welcome_body' => 'Global news from the BBC. Follow us to see updates in your personal feed.',
    ],
];

$created = [];
$skipped = [];
$postsAdded = 0;

foreach ($publishers as $pub) {
    $username = (string)$pub['username'];
    $email = (string)$pub['email'];

    $chk = $dbh->prepare('SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1');
    $chk->execute([':u' => $username, ':e' => $email]);
    $existingId = (int)($chk->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $skipped[] = $username . ' (already exists, id ' . $existingId . ')';
        $userId = $existingId;
    } else {
        $friendCode = publisher_make_friend_code($dbh);
        $tagline = (string)$pub['tagline'];
        $designation = $tagline !== '' ? $tagline : ('Official ' . $pub['name'] . ' on Talentra');

        $ins = $dbh->prepare("
            INSERT INTO users
                (name, username, friend_code, email, password, gender, mobile, designation, role,
                 account_kind, publisher_category, publisher_tagline, image, status, created_at)
            VALUES
                (:name, :username, :friend_code, :email, :password, 'N/A', 'N/A', :designation, 4,
                 'publisher', :publisher_category, :publisher_tagline, 'default.jpg', 1, NOW())
        ");
        $ins->execute([
            ':name' => (string)$pub['name'],
            ':username' => $username,
            ':friend_code' => $friendCode,
            ':email' => $email,
            ':password' => $passwordHash,
            ':designation' => $designation,
            ':publisher_category' => (string)$pub['category'],
            ':publisher_tagline' => mb_substr($tagline, 0, 250),
        ]);
        $userId = (int)$dbh->lastInsertId();
        $created[] = $username . ' (id ' . $userId . ', login: ' . $email . ')';
    }

    $welcomeTitle = trim((string)($pub['welcome_title'] ?? ''));
    if ($userId > 0 && $welcomeTitle !== '') {
        $postChk = $dbh->prepare('SELECT 1 FROM public_posts WHERE user_id = :uid AND title = :t AND is_deleted = 0 LIMIT 1');
        $postChk->execute([':uid' => $userId, ':t' => $welcomeTitle]);
        if (!$postChk->fetchColumn()) {
            $stPost = $dbh->prepare("
                INSERT INTO public_posts (user_id, title, description, body, visibility, created_at, updated_at, is_deleted)
                VALUES (:uid, :title, :desc, :body, 'public', NOW(), NOW(), 0)
            ");
            $stPost->execute([
                ':uid' => $userId,
                ':title' => $welcomeTitle,
                ':desc' => mb_substr((string)($pub['tagline'] ?? ''), 0, 255) ?: null,
                ':body' => (string)($pub['welcome_body'] ?? ''),
            ]);
            $postsAdded++;
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
echo '<h1>Publisher seed complete</h1>';
echo '<p><strong>Password for all demo accounts:</strong> ' . htmlspecialchars($defaultPassword, ENT_QUOTES, 'UTF-8') . '</p>';

if ($created) {
    echo '<h2>Created</h2><ul>';
    foreach ($created as $line) {
        echo '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';
} else {
    echo '<p>No new accounts created.</p>';
}

if ($skipped) {
    echo '<h2>Skipped</h2><ul>';
    foreach ($skipped as $line) {
        echo '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';
}

echo '<p>Welcome posts added this run: <strong>' . (int)$postsAdded . '</strong></p>';
echo '<p>Test: open <a href="public.php">public.php</a>, search <em>CNN</em>, tap Follow, then check <a href="feed.php">feed.php</a>.</p>';
echo '<p><strong>Delete seed_publishers.php</strong> after seeding in production.</p>';
