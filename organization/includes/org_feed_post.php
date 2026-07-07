<?php
declare(strict_types=1);

require_once __DIR__ . '/org_public_publish.php';

if (!function_exists('org_feed_post_label')) {
    function org_feed_post_label(string $type): string
    {
        switch ($type) {
            case 'announcement': return 'Announcement';
            case 'direction':    return 'Direction';
            case 'update':       return 'Update';
            case 'weekly_update': return 'Weekly Update';
            case 'recognition':  return 'Recognition';
            default:             return ucfirst($type);
        }
    }

    function org_feed_post_types(): array
    {
        return [
            'announcement'  => 'Announcement — important business news',
            'direction'     => 'Direction — priorities and decisions',
            'update'        => 'Update — progress or status',
            'weekly_update' => 'Weekly update — recurring summary',
            'recognition'   => 'Recognition — culture & wins',
        ];
    }

    function org_feed_post_tab_for_type(string $postType): string
    {
        return $postType === 'recognition' ? 'culture' : 'work';
    }

    function org_feed_post_ensure_attachments_table(PDO $dbh): bool
    {
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS org_post_attachments (
                  id BIGINT NOT NULL AUTO_INCREMENT,
                  org_id BIGINT NOT NULL,
                  post_id BIGINT NOT NULL,
                  file_name VARCHAR(255) NOT NULL,
                  file_path VARCHAR(500) NOT NULL,
                  mime_type VARCHAR(120) NOT NULL,
                  file_size BIGINT NOT NULL DEFAULT 0,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  KEY idx_post (post_id),
                  KEY idx_org_post (org_id, post_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    function org_feed_post_safe_basename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^a-zA-Z0-9\.\-\_\s]+/', '', $name);
        $name = trim(preg_replace('/\s+/', '_', $name));
        if ($name === '') {
            $name = 'file';
        }
        return $name;
    }

    function org_feed_post_attachment_kind(string $ext): string
    {
        $e = strtolower(ltrim($ext, '.'));
        if (in_array($e, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return 'image';
        }
        if (in_array($e, ['mp4', 'webm', 'ogg', 'mov'], true)) {
            return 'video';
        }
        if ($e === 'pdf') {
            return 'pdf';
        }
        if (in_array($e, ['ppt', 'pptx'], true)) {
            return 'ppt';
        }
        return 'file';
    }

    function org_feed_post_handle_attachments(PDO $dbh, int $orgId, int $postId): int
    {
        if (empty($_FILES['attachments']) || !is_array($_FILES['attachments'])) {
            return 0;
        }

        $uploadDir = dirname(__DIR__) . '/uploads/feed';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            throw new RuntimeException('Upload folder is not writable.');
        }

        $names = $_FILES['attachments']['name'] ?? [];
        $tmp   = $_FILES['attachments']['tmp_name'] ?? [];
        $err   = $_FILES['attachments']['error'] ?? [];
        $size  = $_FILES['attachments']['size'] ?? [];
        $type  = $_FILES['attachments']['type'] ?? [];

        $count = 0;
        $maxFiles = 8;
        $maxBytes = 35 * 1024 * 1024;
        $n = is_array($names) ? count($names) : 0;

        $sql = "INSERT INTO org_post_attachments
                (org_id, post_id, file_name, file_path, mime_type, stored_name, original_name, mime, ext, file_size)
                VALUES
                (:org_id, :post_id, :file_name, :file_path, :mime_type, :stored_name, :original_name, :mime, :ext, :file_size)";
        $stIns = $dbh->prepare($sql);

        for ($i = 0; $i < $n && $count < $maxFiles; $i++) {
            $e = (int)($err[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($e === UPLOAD_ERR_NO_FILE || $e !== UPLOAD_ERR_OK) {
                continue;
            }

            $origName = (string)($names[$i] ?? '');
            $tmpName  = (string)($tmp[$i] ?? '');
            $fsize    = (int)($size[$i] ?? 0);
            $mime     = (string)($type[$i] ?? '');

            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                continue;
            }
            if ($fsize <= 0 || $fsize > $maxBytes) {
                continue;
            }

            $safeName = org_feed_post_safe_basename($origName);
            $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
            $kind = org_feed_post_attachment_kind($ext);
            if (!in_array($kind, ['image', 'video', 'pdf', 'ppt'], true)) {
                continue;
            }

            $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? ('.' . $ext) : '');
            $destAbs = $uploadDir . '/' . $stored;
            if (!@move_uploaded_file($tmpName, $destAbs)) {
                continue;
            }

            $relPath = 'uploads/feed/' . $stored;
            if (function_exists('finfo_open')) {
                try {
                    $fi = finfo_open(FILEINFO_MIME_TYPE);
                    if ($fi) {
                        $det = (string)finfo_file($fi, $destAbs);
                        if ($det !== '') {
                            $mime = $det;
                        }
                        finfo_close($fi);
                    }
                } catch (Throwable $ex) {
                    // ignore
                }
            }
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }

            $stIns->execute([
                ':org_id'        => $orgId,
                ':post_id'       => $postId,
                ':file_name'     => $safeName,
                ':file_path'     => $relPath,
                ':mime_type'     => $mime,
                ':stored_name'   => $stored,
                ':original_name' => $origName !== '' ? $origName : $safeName,
                ':mime'          => $mime,
                ':ext'           => $ext !== '' ? $ext : 'bin',
                ':file_size'     => $fsize,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Create an org feed post (shown on organization/feed.php).
     *
     * @return array{post_id:int,attachments:int,public_post_id:int,post_type:string}
     */
    function org_feed_post_create(
        PDO $dbh,
        int $orgId,
        int $meMemberId,
        string $meRole,
        string $postType,
        string $title,
        string $body,
        string $visibility,
        bool $alsoPublishPublic
    ): array {
        if (!in_array($meRole, ['admin', 'manager'], true)) {
            throw new RuntimeException('Only managers can create organization feed posts.');
        }

        $allowedTypes = array_keys(org_feed_post_types());
        if (!in_array($postType, $allowedTypes, true)) {
            throw new RuntimeException('Invalid post type.');
        }
        if ($visibility !== 'organization' && $visibility !== 'team') {
            $visibility = 'organization';
        }
        if (trim($body) === '') {
            throw new RuntimeException('Message is required.');
        }

        $title = trim($title);
        $body = trim($body);
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }
        if (mb_strlen($body) > 20000) {
            $body = mb_substr($body, 0, 20000);
        }

        $authorRole = ($meRole === 'admin') ? 'admin' : 'manager';

        $st = $dbh->prepare("
            INSERT INTO org_posts (
                org_id, author_id, author_role,
                post_type, title, body, visibility,
                comments_locked, created_at, updated_at
            )
            VALUES (
                :org, :aid, :ar,
                :pt, :t, :b, :v,
                0, NOW(), NOW()
            )
        ");
        $st->execute([
            ':org' => $orgId,
            ':aid' => $meMemberId,
            ':ar'  => $authorRole,
            ':pt'  => $postType,
            ':t'   => ($title === '' ? null : $title),
            ':b'   => $body,
            ':v'   => $visibility,
        ]);

        $postId = (int)$dbh->lastInsertId();
        org_feed_post_ensure_attachments_table($dbh);
        $attachments = org_feed_post_handle_attachments($dbh, $orgId, $postId);

        $publicPostId = 0;
        if ($alsoPublishPublic) {
            $publisherUserId = org_public_publish_publisher_user_id($dbh);
            if ($publisherUserId > 0) {
                $publicPostId = org_public_publish_from_org_post(
                    $dbh,
                    $publisherUserId,
                    $orgId,
                    $postId,
                    $title,
                    $body
                );
            }
        }

        return [
            'post_id' => $postId,
            'attachments' => $attachments,
            'public_post_id' => $publicPostId,
            'post_type' => $postType,
        ];
    }
}
