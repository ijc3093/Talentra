<?php
// /public_user/post_save.php — optimized for fast modal publish after eager media upload
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/post_categories.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/post_upload.php';
require_once __DIR__ . '/includes/post_tags.php';

sendNoCacheHeadersUser();

// JSON only for real XHR/fetch (Accept: application/json).
// A plain form POST with hidden ajax=1 must redirect — otherwise the create-post
// iframe shows Chrome's "Pretty-print" JSON page after publish.
$acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$wantsJson = (strpos($acceptHeader, 'application/json') !== false)
    || (
        (string)($_POST['ajax'] ?? '') === '1'
        && strpos($acceptHeader, 'text/html') === false
        && (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== ''
    );

function post_save_respond(string $redirect, bool $wantsJson, bool $ok = true, string $error = '', int $postId = 0, bool $isStory = false, string $visibility = '', string $surface = ''): void
{
    if ($wantsJson) {
        $payload = json_encode([
            'ok' => $ok,
            'redirect' => $redirect,
            'error' => $error,
            'post_id' => $postId > 0 ? $postId : null,
            'story' => $isStory,
            'visibility' => $visibility !== '' ? $visibility : null,
            'surface' => $surface !== '' ? $surface : null,
        ], JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
        header('Content-Length: ' . strlen((string)$payload));
        echo $payload;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            if (function_exists('ob_get_level')) {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
            }
            @flush();
        }
        exit;
    }
    header('Location: ' . $redirect, true, 303);
    exit;
}

if (empty($_SESSION['user_login']) || empty($_SESSION['user_id'])) {
    post_save_respond('index.php?session=reset', $wantsJson, false, 'auth');
}

staff_pub_deny_write();

error_reporting(E_ALL);
ini_set('display_errors', '0');

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
    post_save_respond('dashboard.php?err=session', $wantsJson, false, 'session');
}

$pendingTokens = [];
if (isset($_POST['pending_tokens']) && is_array($_POST['pending_tokens'])) {
    foreach ($_POST['pending_tokens'] as $tok) {
        $tok = preg_replace('/[^a-f0-9]/i', '', (string)$tok) ?? '';
        if ($tok !== '') {
            $pendingTokens[] = $tok;
        }
    }
} elseif (isset($_POST['pending_tokens']) && is_string($_POST['pending_tokens'])) {
    $decoded = json_decode((string)$_POST['pending_tokens'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $tok) {
            $tok = preg_replace('/[^a-f0-9]/i', '', (string)$tok) ?? '';
            if ($tok !== '') {
                $pendingTokens[] = $tok;
            }
        }
    }
}
$pendingTokens = array_values(array_unique($pendingTokens));
$slideBodiesByToken = [];
if (isset($_POST['slide_body']) && is_array($_POST['slide_body'])) {
    foreach ($_POST['slide_body'] as $tok => $text) {
        $tok = preg_replace('/[^a-f0-9]/i', '', (string)$tok) ?? '';
        if ($tok === '') continue;
        $slideBodiesByToken[$tok] = trim((string)$text);
    }
}
$slideTitlesByToken = [];
if (isset($_POST['slide_title']) && is_array($_POST['slide_title'])) {
    foreach ($_POST['slide_title'] as $tok => $text) {
        $tok = preg_replace('/[^a-f0-9]/i', '', (string)$tok) ?? '';
        if ($tok === '') continue;
        $slideTitlesByToken[$tok] = trim((string)$text);
    }
}
$existingSlideBodies = [];
if (isset($_POST['existing_slide_body']) && is_array($_POST['existing_slide_body'])) {
    foreach ($_POST['existing_slide_body'] as $aid => $text) {
        $aid = (int)$aid;
        if ($aid <= 0) continue;
        $existingSlideBodies[$aid] = trim((string)$text);
    }
}
$existingSlideTitles = [];
if (isset($_POST['existing_slide_title']) && is_array($_POST['existing_slide_title'])) {
    foreach ($_POST['existing_slide_title'] as $aid => $text) {
        $aid = (int)$aid;
        if ($aid <= 0) continue;
        $existingSlideTitles[$aid] = trim((string)$text);
    }
}
$removeAttachmentIds = [];
if (isset($_POST['remove_attachment_ids']) && is_array($_POST['remove_attachment_ids'])) {
    foreach ($_POST['remove_attachment_ids'] as $rid) {
        $rid = (int)$rid;
        if ($rid > 0) {
            $removeAttachmentIds[] = $rid;
        }
    }
    $removeAttachmentIds = array_values(array_unique($removeAttachmentIds));
}
$hasFreshFiles = isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])
    && count(array_filter((array)$_FILES['attachments']['error'], static fn($e) => (int)$e === UPLOAD_ERR_OK)) > 0;

// Fast path: media already uploaded via ajax/post_media_upload.php
$fastPath = $wantsJson && ($pendingTokens !== [] || !$hasFreshFiles);

$controller = new Controller();
$dbh = $controller->pdo();

// Skip heavy schema migrations on the hot publish path (tables already exist in production).
if (!$fastPath) {
    try {
        ensurePostCategorySchema($dbh);
        device_profile_ensure_post_columns($dbh);
        publisher_ensure_schema($dbh);
        post_attachments_ensure_slide_columns($dbh);
    } catch (Throwable $e) {
        // non-fatal
    }
} else {
    try { post_attachments_ensure_slide_columns($dbh); } catch (Throwable $e) {}
}

function firstExistingPostLayoutColumn(PDO $dbh): ?string {
    if (isset($_SESSION['post_layout_col_cache'])) {
        $cached = $_SESSION['post_layout_col_cache'];
        return ($cached === '' || $cached === null) ? null : (string)$cached;
    }
    $found = null;
    try {
        $rows = $dbh->query("SHOW COLUMNS FROM public_posts")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fields = array_map(static fn(array $r): string => (string)($r['Field'] ?? ''), $rows);
        foreach (['layout_type', 'layout', 'post_type', 'type'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $found = $candidate;
                break;
            }
        }
    } catch (Throwable $e) {
        $found = null;
    }
    $_SESSION['post_layout_col_cache'] = $found ?? '';
    return $found;
}

function layoutOverrideMarker(string $layoutOverride): string {
    $layoutOverride = trim($layoutOverride);
    if ($layoutOverride === '') return '';
    return '[[layout:' . preg_replace('/[^a-z0-9_]+/i', '', $layoutOverride) . ']]';
}

function stripLayoutOverrideMarker(string $description): string {
    return trim((string)preg_replace('/\[\[layout:[a-z0-9_]+\]\]/i', '', $description));
}

$postId = (int)($_POST['post_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$body = trim((string)($_POST['body'] ?? ''));
$visibility = (string)($_POST['visibility'] ?? 'public');
$layoutOverride = post_allowed_layout_override((string)($_POST['layout_override'] ?? ''));
$categoryId = (int)($_POST['category_id'] ?? 0);
$isStoryPost = ($layoutOverride === 'story');
$isPublisherPoster = ((string)($_POST['publisher_account'] ?? '') === '1')
    || (!empty($_SESSION['account_kind']) && strtolower((string)$_SESSION['account_kind']) === 'publisher');
if (!$isPublisherPoster && !$fastPath) {
    $isPublisherPoster = publisher_account_is($dbh, $meId);
}
$visibility = publisher_post_visibility($dbh, $meId, $visibility);
$description = stripLayoutOverrideMarker($description);
$description = post_strip_music_marker($description);

// Keep originals for people-tag sync, then hide mention-only captions from stored body/title
// (header already shows "John is sharing with Akin").
$bodyForTags = $body;
$titleForTags = $title;
$descriptionForTags = $description;
if (function_exists('msb_text_is_people_tag_only')) {
    if (msb_text_is_people_tag_only($body)) {
        $body = '';
    }
    if (msb_text_is_people_tag_only($title)) {
        $title = '';
    }
    if (msb_text_is_people_tag_only($description)) {
        $description = '';
    }
}

$musicTitle = mb_substr(trim((string)($_POST['music_title'] ?? '')), 0, 120);
$musicArtist = mb_substr(trim((string)($_POST['music_artist'] ?? '')), 0, 120);

if (mb_strlen($title) > 120) $title = mb_substr($title, 0, 120);
if (mb_strlen($description) > 255) $description = mb_substr($description, 0, 255);

// Modal edit form always posts description="" (caption lives in body). Keep existing
// description / layout markers on update so we do not wipe them accidentally.
$preserveExistingDescription = ($postId > 0 && $description === '');


$formLooksEmpty = ($title === '' && $description === '' && $body === ''
    && !$hasFreshFiles
    && $pendingTokens === []);

if ($formLooksEmpty) {
    // Create with nothing: reject. Edit with nothing new: allow (media-only / Friends↔Public moves).
    if ($postId <= 0 || $meId <= 0) {
        post_save_respond('dashboard.php?err=empty', $wantsJson, false, 'empty');
    }
    $editPostExists = false;
    try {
        $stExist = $dbh->prepare("
            SELECT id
            FROM public_posts
            WHERE id = :id AND user_id = :uid AND is_deleted = 0
            LIMIT 1
        ");
        $stExist->execute([':id' => $postId, ':uid' => $meId]);
        $editPostExists = (int)($stExist->fetchColumn() ?: 0) > 0;
    } catch (Throwable $e) {
        $editPostExists = false;
    }
    if (!$editPostExists) {
        post_save_respond('dashboard.php?err=empty', $wantsJson, false, 'empty');
    }
}

$deviceProfile = device_profile_read_from_request();
$deviceLabel = (string)($deviceProfile['label'] ?? '');
$deviceViewport = (string)($deviceProfile['viewport'] ?? '');

try {
    // Prefer a real layout column so story-circle creates stay tagged.
    $layoutColumn = null;
    if (function_exists('post_layout_ensure_column')) {
        $layoutColumn = post_layout_ensure_column($dbh);
    }
    if (!$layoutColumn) {
        $layoutColumn = firstExistingPostLayoutColumn($dbh);
    }
    if ($layoutColumn) {
        $_SESSION['post_layout_col_cache'] = $layoutColumn;
    }

    $hasTextContent = ($title !== '' || $description !== '' || $body !== '');
    $incomingAttachmentTypes = [];
    if ($pendingTokens !== []) {
        $bag = post_upload_pending_bag();
        foreach ($pendingTokens as $tok) {
            if (!isset($bag[$tok]) || !is_array($bag[$tok])) continue;
            if ((int)($bag[$tok]['user_id'] ?? 0) !== $meId) continue;
            $t = (string)($bag[$tok]['type'] ?? 'file');
            if ($t !== '') $incomingAttachmentTypes[] = $t;
        }
    }

    // Fast category resolve: use posted id or null (skip seed/ensure).
    $resolvedCategoryId = null;
    if ($categoryId > 0) {
        $resolvedCategoryId = $categoryId;
    } elseif (!$fastPath) {
        $detectedCategoryType = detectPostCategoryType($incomingAttachmentTypes, $hasTextContent);
        $resolvedCategoryId = resolveUserPostCategoryId($dbh, $meId, 0, $detectedCategoryType) ?: null;
    }

    // Marker fallback when DB has no layout column (keeps story vs feed-card distinct).
    if (!$layoutColumn && $layoutOverride !== '') {
        $description = trim(layoutOverrideMarker($layoutOverride) . ' ' . $description);
    }

    $inserted = false;
    if ($postId > 0) {
        $st = $dbh->prepare("SELECT id, description FROM public_posts WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
        $st->execute([':id' => $postId, ':uid' => $meId]);
        $existingEditRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$existingEditRow) {
            post_save_respond('dashboard.php?err=forbidden', $wantsJson, false, 'forbidden');
        }
        if ($preserveExistingDescription) {
            $description = (string)($existingEditRow['description'] ?? '');
            if (!$layoutColumn && $layoutOverride !== '' && stripos($description, '[[layout:') === false) {
                $description = trim(layoutOverrideMarker($layoutOverride) . ' ' . $description);
            }
        }
        try {
            if ($layoutColumn) {
                $stU = $dbh->prepare("UPDATE public_posts SET title=:t, description=:d, body=:b, visibility=:v, music_title=:mt, music_artist=:ma, {$layoutColumn}=:layoutv, category_id=:cid, updated_at=NOW() WHERE id=:id LIMIT 1");
                $stU->execute([':t'=>$title ?: null, ':d'=>$description ?: null, ':b'=>$body ?: null, ':v'=>$visibility, ':mt'=>$musicTitle, ':ma'=>$musicArtist, ':layoutv'=>$layoutOverride ?: null, ':cid'=>$resolvedCategoryId, ':id'=>$postId]);
            } else {
                throw new RuntimeException('no-layout-col');
            }
        } catch (Throwable $e) {
            if ($layoutOverride !== '' && stripos($description, '[[layout:') === false) {
                $description = trim(layoutOverrideMarker($layoutOverride) . ' ' . $description);
            }
            $stU = $dbh->prepare("UPDATE public_posts SET title=:t, description=:d, body=:b, visibility=:v, music_title=:mt, music_artist=:ma, category_id=:cid, updated_at=NOW() WHERE id=:id LIMIT 1");
            $stU->execute([':t'=>$title ?: null, ':d'=>$description ?: null, ':b'=>$body ?: null, ':v'=>$visibility, ':mt'=>$musicTitle, ':ma'=>$musicArtist, ':cid'=>$resolvedCategoryId, ':id'=>$postId]);
            $_SESSION['post_layout_col_cache'] = '';
        }
    } else {
        try {
            if ($layoutColumn) {
                $stI = $dbh->prepare("INSERT INTO public_posts (user_id, title, description, body, visibility, device_label, device_viewport, music_title, music_artist, {$layoutColumn}, category_id, created_at, updated_at, is_deleted)
                                      VALUES (:uid, :t, :d, :b, :v, :dl, :dv, :mt, :ma, :layoutv, :cid, NOW(), NOW(), 0)");
                $stI->execute([':uid'=>$meId, ':t'=>$title ?: null, ':d'=>$description ?: null, ':b'=>$body ?: null, ':v'=>$visibility, ':dl'=>$deviceLabel, ':dv'=>$deviceViewport, ':mt'=>$musicTitle, ':ma'=>$musicArtist, ':layoutv'=>$layoutOverride ?: null, ':cid'=>$resolvedCategoryId]);
            } else {
                throw new RuntimeException('no-layout-col');
            }
        } catch (Throwable $e) {
            if ($layoutOverride !== '' && stripos($description, '[[layout:') === false) {
                $description = trim(layoutOverrideMarker($layoutOverride) . ' ' . $description);
            }
            $stI = $dbh->prepare("INSERT INTO public_posts (user_id, title, description, body, visibility, device_label, device_viewport, music_title, music_artist, category_id, created_at, updated_at, is_deleted)
                                  VALUES (:uid, :t, :d, :b, :v, :dl, :dv, :mt, :ma, :cid, NOW(), NOW(), 0)");
            $stI->execute([':uid'=>$meId, ':t'=>$title ?: null, ':d'=>$description ?: null, ':b'=>$body ?: null, ':v'=>$visibility, ':dl'=>$deviceLabel, ':dv'=>$deviceViewport, ':mt'=>$musicTitle, ':ma'=>$musicArtist, ':cid'=>$resolvedCategoryId]);
            $_SESSION['post_layout_col_cache'] = '';
        }
        $postId = (int)$dbh->lastInsertId();
        $inserted = true;
    }

    $uploadAttempts = 0;
    $uploadSaved = 0;

    if ($pendingTokens !== []) {
        $claimed = post_upload_claim_pending($dbh, $meId, $postId, $pendingTokens, false, $slideBodiesByToken, $slideTitlesByToken);
        $uploadSaved += (int)($claimed['saved'] ?? 0);
        $uploadAttempts += count($pendingTokens);
    }

    // Remove slides the user deleted in the create/edit form.
    if ($postId > 0 && $removeAttachmentIds !== []) {
        try {
            $stDel = $dbh->prepare(
                'DELETE FROM public_post_attachments
                 WHERE id = :aid AND post_id = :pid
                 LIMIT 1'
            );
            foreach ($removeAttachmentIds as $aid) {
                $aid = (int)$aid;
                if ($aid <= 0) continue;
                $stDel->execute([':aid' => $aid, ':pid' => $postId]);
                unset($existingSlideBodies[$aid], $existingSlideTitles[$aid]);
            }
        } catch (Throwable $eDel) {
            // non-fatal
        }
    }

    // Update per-slide captions on existing attachments (edit / presentation).
    if ($postId > 0 && ($existingSlideBodies !== [] || $existingSlideTitles !== [])) {
        try {
            post_attachments_ensure_slide_columns($dbh);
            $ids = array_values(array_unique(array_merge(array_keys($existingSlideBodies), array_keys($existingSlideTitles))));
            $stUp = $dbh->prepare(
                'UPDATE public_post_attachments
                 SET slide_title = :st, slide_body = :sb
                 WHERE id = :aid AND post_id = :pid
                 LIMIT 1'
            );
            foreach ($ids as $aid) {
                $aid = (int)$aid;
                if ($aid <= 0) continue;
                $stTitle = trim((string)($existingSlideTitles[$aid] ?? ''));
                $stBody = trim((string)($existingSlideBodies[$aid] ?? ''));
                if (function_exists('mb_substr')) {
                    $stTitle = mb_substr($stTitle, 0, 120);
                } else {
                    $stTitle = substr($stTitle, 0, 120);
                }
                $stUp->execute([
                    ':st' => $stTitle,
                    ':sb' => $stBody !== '' ? $stBody : null,
                    ':aid' => $aid,
                    ':pid' => $postId,
                ]);
            }
        } catch (Throwable $eSlide) {
            // non-fatal
        }
    }

    // Only process multipart files when not already pre-uploaded.
    if (!$fastPath && $hasFreshFiles && isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $names = $_FILES['attachments']['name'];
        $tmp   = $_FILES['attachments']['tmp_name'];
        $err   = $_FILES['attachments']['error'];
        $type  = $_FILES['attachments']['type'];
        $size  = $_FILES['attachments']['size'];
        $allowedExt = post_upload_allowed_ext();
        $allowedMimeByExt = post_upload_allowed_mime_by_ext();
        $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
        [$subDir, $ym] = post_upload_ensure_dir();

        for ($i = 0; $i < count($names); $i++) {
            if ((int)$err[$i] !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmp[$i])) continue;
            $uploadAttempts++;
            $orig = post_upload_safe_filename((string)$names[$i]);
            $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
            if ($ext === '') $ext = 'bin';
            if (!in_array($ext, $allowedExt, true)) continue;
            $detectedMime = $finfo ? strtolower(trim((string)$finfo->file($tmp[$i]))) : '';
            if (!post_upload_mime_is_allowed($detectedMime, $ext, $allowedMimeByExt)) continue;
            $ext = post_upload_ext_from_mime($detectedMime, $ext);
            if (!in_array($ext, $allowedExt, true)) continue;
            if (is_numeric($size[$i]) && (int)$size[$i] > 50 * 1024 * 1024) continue;
            $mime = $detectedMime !== '' ? $detectedMime : (string)($type[$i] ?? '');
            $attType = post_upload_att_type($mime, $ext);
            $fname = 'p' . $postId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destAbs = $subDir . '/' . $fname;
            if (!move_uploaded_file($tmp[$i], $destAbs)) continue;
            $webPath = 'uploads/posts/' . $ym . '/' . $fname;
            $stA = $dbh->prepare("INSERT INTO public_post_attachments (post_id, type, file_path, thumb_path, created_at) VALUES (:pid, :t, :fp, NULL, NOW())");
            $stA->execute([':pid' => $postId, ':t' => $attType, ':fp' => $webPath]);
            $uploadSaved++;
        }
    }

    // Create entry → destination after submit:
    // - Profile story "+" → profile.php story circle (?story_post=)
    // - Story circle "+" + Friends → feed.php story circle (?story_post=)
    // - Story circle "+" + Public  → public.php story circle (?story_post=)
    // - Left-nav "+" + Friends     → feed.php post card (?post=)
    // - Left-nav "+" + Public      → public.php post card (?post=)
    // - Any "+" + Private          → profile.php Gallery → Private tab
    $returnToRaw = trim((string)($_POST['return_to'] ?? ''));
    $returnToBase = strtolower((string)preg_replace('/[?#].*$/', '', $returnToRaw));
    $fromProfileStory = ($isStoryPost && (substr($returnToBase, -11) === 'profile.php' || $returnToBase === 'profile.php'));
    if ($visibility === 'private') {
        $dest = 'profile.php';
        $queryKey = $isStoryPost ? 'story_post' : 'post';
        $redirectParams = [
            'tab' => 'gallery',
            'gallery_vis' => 'private',
            $queryKey => $postId,
            'fresh' => 1,
        ];
        $redirect = $dest . '?' . http_build_query($redirectParams);
    } elseif ($fromProfileStory) {
        $dest = 'profile.php';
        $redirectParams = [
            'tab' => 'gallery',
            'story_post' => $postId,
            'fresh' => 1,
        ];
        $redirect = $dest . '?' . http_build_query($redirectParams);
    } else {
        $dest = publisher_post_redirect($dbh, $meId, $visibility);
        $queryKey = $isStoryPost ? 'story_post' : 'post';
        $redirectParams = [
            $queryKey => $postId,
            'fresh' => 1,
        ];
        if ($visibility === 'public' && $dest === 'public.php') {
            $redirectParams = ['tab' => 'public'] + $redirectParams;
        } elseif ($visibility === 'friends' && $dest === 'feed.php') {
            $redirectParams = ['tab' => 'for-you'] + $redirectParams;
        }
        $redirect = $dest . '?' . http_build_query($redirectParams);
    }
    if ($uploadAttempts > 0 && $uploadSaved === 0) {
        $redirect .= '&upload_warn=1';
    }

    // Sync @mentions / people tags and notify newly tagged users.
    if ($postId > 0) {
        try {
            $explicitTagIds = [];
            $rawTagged = trim((string)($_POST['tagged_user_ids'] ?? ''));
            if ($rawTagged !== '') {
                foreach (preg_split('/[\s,]+/', $rawTagged) ?: [] as $piece) {
                    $tid = (int)$piece;
                    if ($tid > 0) {
                        $explicitTagIds[] = $tid;
                    }
                }
            }
            $mentionTexts = [$titleForTags, $bodyForTags, $descriptionForTags];
            foreach ($slideBodiesByToken as $sb) {
                $mentionTexts[] = (string)$sb;
            }
            foreach ($slideTitlesByToken as $stt) {
                $mentionTexts[] = (string)$stt;
            }
            foreach ($existingSlideBodies as $sb) {
                $mentionTexts[] = (string)$sb;
            }
            foreach ($existingSlideTitles as $stt) {
                $mentionTexts[] = (string)$stt;
            }
            $tagIds = msb_mention_ids_from_texts($dbh, $mentionTexts, $explicitTagIds);
            msb_post_tags_sync($dbh, $postId, $meId, $tagIds, $visibility, true);
        } catch (Throwable $eTag) {
            // Tagging failure must not block publish.
        }
    }

    // What’s up: notify followers when a publisher publishes a new post.
    if (!empty($inserted) && $postId > 0 && empty($isStoryPost) && function_exists('publisher_notify_followers_of_post')) {
        try {
            publisher_notify_followers_of_post($dbh, $meId, $postId, $visibility);
        } catch (Throwable $eFollowNoti) {
            // non-fatal
        }
    }

    post_save_respond($redirect, $wantsJson, true, '', $postId, $isStoryPost, $visibility, $dest);

} catch (Throwable $e) {
    post_save_respond('dashboard.php?err=server', $wantsJson, false, 'server');
}
