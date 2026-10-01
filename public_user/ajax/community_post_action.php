<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
header('Content-Type: application/json; charset=utf-8');

$dbh = (new Controller())->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$csrf = (string)($_POST['csrf'] ?? '');
$expected = (string)($_SESSION['community_profile_csrf'] ?? '');
$communityPostId = (int)($_POST['community_post_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? '')));

if ($meId <= 0 || $expected === '' || !hash_equals($expected, $csrf)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Your session changed. Refresh and try again.']);
    exit;
}
if ($communityPostId <= 0 || !in_array($action, ['private','archive','delete'], true)) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Invalid post action.']);
    exit;
}

try {
    $st = $dbh->prepare("SELECT cp.id,cp.user_id,cp.public_post_id,cm.role FROM community_posts cp LEFT JOIN community_members cm ON cm.community_id=cp.community_id AND cm.user_id=:viewer AND cm.status='active' WHERE cp.id=:id AND cp.status<>'removed' LIMIT 1");
    $st->execute([':id'=>$communityPostId,':viewer'=>$meId]);
    $post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $canModerate = $post && in_array((string)($post['role'] ?? ''), ['owner','admin','moderator'], true);
    if (!$post || ((int)$post['user_id'] !== $meId && !$canModerate)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'You cannot change this post.']);
        exit;
    }
    $publicId = (int)($post['public_post_id'] ?? 0);
    $dbh->beginTransaction();
    if ($action === 'private') {
        $dbh->prepare("UPDATE community_posts SET visibility='private' WHERE id=:id")->execute([':id'=>$communityPostId]);
        if ($publicId > 0) $dbh->prepare("UPDATE public_posts SET visibility='private',is_deleted=0,is_archived=0,updated_at=NOW() WHERE id=:id")->execute([':id'=>$publicId]);
        $message = 'Moved to Gallery → Private.';
    } elseif ($action === 'archive') {
        $dbh->prepare("UPDATE community_posts SET status='removed' WHERE id=:id")->execute([':id'=>$communityPostId]);
        if ($publicId > 0) $dbh->prepare("UPDATE public_posts SET is_deleted=0,is_archived=1,updated_at=NOW() WHERE id=:id")->execute([':id'=>$publicId]);
        $message = 'Moved to Settings → Archived posts.';
    } else {
        $dbh->prepare("UPDATE community_posts SET status='removed' WHERE id=:id")->execute([':id'=>$communityPostId]);
        if ($publicId > 0) $dbh->prepare("UPDATE public_posts SET is_deleted=1,updated_at=NOW() WHERE id=:id")->execute([':id'=>$publicId]);
        $message = 'Community post deleted.';
    }
    $dbh->commit();
    echo json_encode(['ok'=>true,'action'=>$action,'message'=>$message]);
} catch (Throwable $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Could not update this community post.']);
}
