<?php
declare(strict_types=1);

/**
 * Feed engagement helpers: watch attention, reusable sounds, stitch/duet, post product tags.
 * Keeps existing friends/follow/rooms behavior; only adds signals and hooks.
 */

if (!function_exists('msb_feed_engagement_ensure_schema')) {
    function msb_feed_engagement_ensure_schema(PDO $dbh): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (is_file(__DIR__ . '/msb_migrations.php')) {
            require_once __DIR__ . '/msb_migrations.php';
        }
        $mig = dirname(__DIR__, 2) . '/Data/migrations/20260814_msb_feed_engagement.sql';
        if (function_exists('msb_run_sql_migration_file')) {
            msb_run_sql_migration_file($dbh, $mig);
        }

        $cols = [
            'watch_ms_total' => "BIGINT(20) NOT NULL DEFAULT 0",
            'watch_completes' => "INT(11) NOT NULL DEFAULT 0",
            'watch_skips' => "INT(11) NOT NULL DEFAULT 0",
            'sound_id' => "INT(11) NULL DEFAULT NULL",
            'stitch_of_post_id' => "BIGINT(20) UNSIGNED NULL DEFAULT NULL",
            'duet_of_post_id' => "BIGINT(20) UNSIGNED NULL DEFAULT NULL",
        ];
        foreach ($cols as $name => $def) {
            try {
                $st = $dbh->query("SHOW COLUMNS FROM public_posts LIKE " . $dbh->quote($name));
                $has = $st && $st->fetch(PDO::FETCH_ASSOC);
                if (!$has) {
                    $dbh->exec("ALTER TABLE public_posts ADD COLUMN `{$name}` {$def}");
                }
            } catch (Throwable $e) {
                // non-fatal
            }
        }
        try {
            $st = $dbh->query("SHOW INDEX FROM public_posts WHERE Key_name = 'idx_posts_sound_id'");
            if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
                $dbh->exec('ALTER TABLE public_posts ADD KEY idx_posts_sound_id (sound_id)');
            }
        } catch (Throwable $e) {
        }
        try {
            $st = $dbh->query("SHOW INDEX FROM public_posts WHERE Key_name = 'idx_posts_stitch'");
            if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
                $dbh->exec('ALTER TABLE public_posts ADD KEY idx_posts_stitch (stitch_of_post_id)');
            }
        } catch (Throwable $e) {
        }
        try {
            $st = $dbh->query("SHOW INDEX FROM public_posts WHERE Key_name = 'idx_posts_duet'");
            if (!$st || !$st->fetch(PDO::FETCH_ASSOC)) {
                $dbh->exec('ALTER TABLE public_posts ADD KEY idx_posts_duet (duet_of_post_id)');
            }
        } catch (Throwable $e) {
        }

        // Ensure tables even if migration path failed
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS public_sounds (
                  id INT(11) NOT NULL AUTO_INCREMENT,
                  created_by INT(11) NOT NULL DEFAULT 0,
                  title VARCHAR(120) NOT NULL DEFAULT '',
                  artist VARCHAR(120) NOT NULL DEFAULT '',
                  source_post_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                  use_count INT(11) NOT NULL DEFAULT 0,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  KEY idx_sounds_title_artist (title, artist),
                  KEY idx_sounds_use (use_count),
                  KEY idx_sounds_created_by (created_by)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
        }
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS public_post_watch_events (
                  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                  post_id BIGINT(20) UNSIGNED NOT NULL,
                  user_id INT(11) NOT NULL,
                  watch_ms INT(11) NOT NULL DEFAULT 0,
                  duration_ms INT(11) NOT NULL DEFAULT 0,
                  completed TINYINT(1) NOT NULL DEFAULT 0,
                  skipped TINYINT(1) NOT NULL DEFAULT 0,
                  source ENUM('reel','feed','story') NOT NULL DEFAULT 'feed',
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  KEY idx_watch_post_created (post_id, created_at),
                  KEY idx_watch_user_created (user_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
        }
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS public_post_products (
                  id INT(11) NOT NULL AUTO_INCREMENT,
                  post_id BIGINT(20) UNSIGNED NOT NULL,
                  product_id INT(11) NOT NULL,
                  org_id INT(11) NOT NULL DEFAULT 0,
                  sort_order INT(11) NOT NULL DEFAULT 0,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_post_product (post_id, product_id),
                  KEY idx_ppp_post (post_id),
                  KEY idx_ppp_product (product_id),
                  KEY idx_ppp_org (org_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('msb_attention_score_sql')) {
    /**
     * SQL expression for attention-aware ranking (alias p + engagement subselect aliases).
     */
    function msb_attention_score_sql(string $pAlias = 'p'): string
    {
        $p = preg_replace('/[^a-zA-Z0-9_]/', '', $pAlias) ?: 'p';
        return "(COALESCE({$p}.watch_completes,0) * 8"
            . " + FLOOR(COALESCE({$p}.watch_ms_total,0) / 1000)"
            . " + COALESCE({$p}.views_count,0)"
            . " + (comment_count * 4)"
            . " + (like_count * 3)"
            . " + (love_count * 3)"
            . " + (share_count * 5))";
    }
}

if (!function_exists('msb_posts_has_attention_cols')) {
    function msb_posts_has_attention_cols(PDO $dbh): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $st = $dbh->query("SHOW COLUMNS FROM public_posts LIKE 'watch_ms_total'");
            $cache = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }
}

if (!function_exists('msb_record_watch_event')) {
    /**
     * @return array{ok:bool,error?:string}
     */
    function msb_record_watch_event(
        PDO $dbh,
        int $postId,
        int $userId,
        int $watchMs,
        int $durationMs,
        string $source = 'feed',
        bool $completed = false,
        bool $skipped = false
    ): array {
        if ($postId <= 0 || $userId <= 0) {
            return ['ok' => false, 'error' => 'bad_ids'];
        }
        msb_feed_engagement_ensure_schema($dbh);
        $watchMs = max(0, min(600000, $watchMs));
        $durationMs = max(0, min(600000, $durationMs));
        if ($watchMs < 250 && !$completed && !$skipped) {
            return ['ok' => true]; // ignore noise
        }
        $source = in_array($source, ['reel', 'feed', 'story'], true) ? $source : 'feed';
        if ($durationMs > 0 && $watchMs >= (int)floor($durationMs * 0.9)) {
            $completed = true;
        }
        if ($durationMs > 0 && $watchMs > 0 && $watchMs < (int)floor($durationMs * 0.2) && !$completed) {
            $skipped = true;
        }
        try {
            $stAuthor = $dbh->prepare('SELECT user_id FROM public_posts WHERE id = :id AND is_deleted = 0 LIMIT 1');
            $stAuthor->execute([':id' => $postId]);
            $authorId = (int)($stAuthor->fetchColumn() ?: 0);
            if ($authorId <= 0) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            if ($authorId === $userId) {
                return ['ok' => true]; // don't score self-watches
            }

            $dbh->prepare("
                INSERT INTO public_post_watch_events
                  (post_id, user_id, watch_ms, duration_ms, completed, skipped, source, created_at)
                VALUES
                  (:pid, :uid, :wms, :dms, :c, :s, :src, NOW())
            ")->execute([
                ':pid' => $postId,
                ':uid' => $userId,
                ':wms' => $watchMs,
                ':dms' => $durationMs,
                ':c' => $completed ? 1 : 0,
                ':s' => $skipped ? 1 : 0,
                ':src' => $source,
            ]);

            $sets = ['watch_ms_total = COALESCE(watch_ms_total,0) + :wms'];
            $params = [':wms' => $watchMs, ':id' => $postId];
            if ($completed) {
                $sets[] = 'watch_completes = COALESCE(watch_completes,0) + 1';
            }
            if ($skipped) {
                $sets[] = 'watch_skips = COALESCE(watch_skips,0) + 1';
            }
            $dbh->prepare('UPDATE public_posts SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1')
                ->execute($params);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db'];
        }
    }
}

if (!function_exists('msb_upsert_sound_for_post')) {
    /**
     * Link or create a sound from music_title/artist; set post.sound_id and bump use_count.
     */
    function msb_upsert_sound_for_post(
        PDO $dbh,
        int $postId,
        int $userId,
        string $title,
        string $artist,
        int $preferSoundId = 0
    ): int {
        msb_feed_engagement_ensure_schema($dbh);
        $title = mb_substr(trim($title), 0, 120);
        $artist = mb_substr(trim($artist), 0, 120);
        if ($postId <= 0) {
            return 0;
        }
        $soundId = 0;
        try {
            if ($preferSoundId > 0) {
                $st = $dbh->prepare('SELECT id, title, artist FROM public_sounds WHERE id = :id LIMIT 1');
                $st->execute([':id' => $preferSoundId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($row) {
                    $soundId = (int)$row['id'];
                    if ($title === '') {
                        $title = (string)($row['title'] ?? '');
                    }
                    if ($artist === '') {
                        $artist = (string)($row['artist'] ?? '');
                    }
                }
            }
            if ($soundId <= 0 && ($title !== '' || $artist !== '')) {
                $st = $dbh->prepare('SELECT id FROM public_sounds WHERE title = :t AND artist = :a ORDER BY id ASC LIMIT 1');
                $st->execute([':t' => $title, ':a' => $artist]);
                $soundId = (int)($st->fetchColumn() ?: 0);
                if ($soundId <= 0) {
                    $dbh->prepare('
                        INSERT INTO public_sounds (created_by, title, artist, source_post_id, use_count, created_at)
                        VALUES (:uid, :t, :a, :pid, 0, NOW())
                    ')->execute([
                        ':uid' => $userId,
                        ':t' => $title,
                        ':a' => $artist,
                        ':pid' => $postId,
                    ]);
                    $soundId = (int)$dbh->lastInsertId();
                }
            }
            if ($soundId > 0) {
                $dbh->prepare('UPDATE public_posts SET sound_id = :sid WHERE id = :id LIMIT 1')
                    ->execute([':sid' => $soundId, ':id' => $postId]);
                $dbh->prepare('UPDATE public_sounds SET use_count = COALESCE(use_count,0) + 1 WHERE id = :id LIMIT 1')
                    ->execute([':id' => $soundId]);
                if ($title !== '' || $artist !== '') {
                    $dbh->prepare('UPDATE public_posts SET music_title = :t, music_artist = :a WHERE id = :id LIMIT 1')
                        ->execute([':t' => $title, ':a' => $artist, ':id' => $postId]);
                }
            }
        } catch (Throwable $e) {
            return 0;
        }
        return $soundId;
    }
}

if (!function_exists('msb_set_remix_parents')) {
    function msb_set_remix_parents(PDO $dbh, int $postId, int $stitchOf = 0, int $duetOf = 0): void
    {
        if ($postId <= 0) {
            return;
        }
        msb_feed_engagement_ensure_schema($dbh);
        try {
            $dbh->prepare('UPDATE public_posts SET stitch_of_post_id = :s, duet_of_post_id = :d WHERE id = :id LIMIT 1')
                ->execute([
                    ':s' => $stitchOf > 0 ? $stitchOf : null,
                    ':d' => $duetOf > 0 ? $duetOf : null,
                    ':id' => $postId,
                ]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('msb_save_post_products')) {
    /**
     * @param list<int> $productIds
     */
    function msb_save_post_products(PDO $dbh, int $postId, int $orgId, array $productIds): void
    {
        if ($postId <= 0) {
            return;
        }
        msb_feed_engagement_ensure_schema($dbh);
        $ids = [];
        foreach ($productIds as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) {
                $ids[$pid] = $pid;
            }
        }
        $ids = array_values($ids);
        try {
            $dbh->prepare('DELETE FROM public_post_products WHERE post_id = :pid')->execute([':pid' => $postId]);
            if (!$ids) {
                return;
            }
            $st = $dbh->prepare('
                INSERT INTO public_post_products (post_id, product_id, org_id, sort_order, created_at)
                VALUES (:post, :prod, :org, :ord, NOW())
            ');
            $ord = 0;
            foreach ($ids as $prodId) {
                if ($orgId > 0) {
                    $chk = $dbh->prepare('SELECT id FROM org_products WHERE id = :id AND org_id = :org AND COALESCE(is_deleted,0) = 0 LIMIT 1');
                    $chk->execute([':id' => $prodId, ':org' => $orgId]);
                    if (!(int)$chk->fetchColumn()) {
                        continue;
                    }
                }
                $st->execute([
                    ':post' => $postId,
                    ':prod' => $prodId,
                    ':org' => $orgId,
                    ':ord' => $ord++,
                ]);
            }
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('msb_post_products_for_posts')) {
    /**
     * @param list<int> $postIds
     * @return array<int, list<array{product_id:int,org_id:int,title:string,cover:string}>>
     */
    function msb_post_products_for_posts(PDO $dbh, array $postIds): array
    {
        $postIds = array_values(array_filter(array_map('intval', $postIds)));
        if (!$postIds) {
            return [];
        }
        msb_feed_engagement_ensure_schema($dbh);
        $out = [];
        try {
            $in = implode(',', array_fill(0, count($postIds), '?'));
            $sql = "
                SELECT pp.post_id, pp.product_id, pp.org_id,
                       COALESCE(op.title, '') AS title,
                       COALESCE(op.cover_image_path, '') AS cover
                FROM public_post_products pp
                LEFT JOIN org_products op ON op.id = pp.product_id AND COALESCE(op.is_deleted,0) = 0
                WHERE pp.post_id IN ({$in})
                ORDER BY pp.sort_order ASC, pp.id ASC
            ";
            $st = $dbh->prepare($sql);
            $st->execute($postIds);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)($row['post_id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                if (!isset($out[$pid])) {
                    $out[$pid] = [];
                }
                $out[$pid][] = [
                    'product_id' => (int)($row['product_id'] ?? 0),
                    'org_id' => (int)($row['org_id'] ?? 0),
                    'title' => (string)($row['title'] ?? ''),
                    'cover' => (string)($row['cover'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}

if (!function_exists('msb_post_products_row_html')) {
    /**
     * Buy chips linking to existing shop buy door (js-open-shop-buy-door).
     *
     * @param list<array{product_id?:int,title?:string}>|mixed $products
     */
    function msb_post_products_row_html($products, int $max = 4): string
    {
        if (!is_array($products) || $products === []) {
            return '';
        }
        $max = max(1, min(8, $max));
        $html = '<div class="mf-products-row" style="display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 2px;">';
        $n = 0;
        foreach ($products as $p) {
            if ($n >= $max) {
                break;
            }
            if (!is_array($p)) {
                continue;
            }
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $label = trim((string)($p['title'] ?? ''));
            if ($label === '') {
                $label = 'Product #' . $pid;
            }
            $html .= '<button type="button" class="mf-product-buy-chip js-open-shop-buy-door"'
                . ' data-shop-buy="' . $pid . '" data-product-id="' . $pid . '"'
                . ' style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border:0;border-radius:999px;'
                . 'background:rgba(15,23,42,.08);color:#0f172a;font-size:12px;font-weight:700;cursor:pointer;">'
                . '<i class="fa fa-shopping-bag" aria-hidden="true"></i>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . '</button>';
            $n++;
        }
        $html .= '</div>';
        return $n > 0 ? $html : '';
    }
}

if (!function_exists('msb_search_sounds')) {
    /**
     * @return list<array{id:int,title:string,artist:string,use_count:int}>
     */
    function msb_search_sounds(PDO $dbh, string $q = '', int $limit = 24): array
    {
        msb_feed_engagement_ensure_schema($dbh);
        $limit = max(1, min(50, $limit));
        $q = trim($q);
        try {
            if ($q !== '') {
                $like = '%' . $q . '%';
                $st = $dbh->prepare("
                    SELECT id, title, artist, use_count
                    FROM public_sounds
                    WHERE title LIKE :q OR artist LIKE :q2
                    ORDER BY use_count DESC, id DESC
                    LIMIT {$limit}
                ");
                $st->execute([':q' => $like, ':q2' => $like]);
            } else {
                $st = $dbh->query("
                    SELECT id, title, artist, use_count
                    FROM public_sounds
                    ORDER BY use_count DESC, id DESC
                    LIMIT {$limit}
                ");
            }
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (int)($r['id'] ?? 0),
                    'title' => (string)($r['title'] ?? ''),
                    'artist' => (string)($r['artist'] ?? ''),
                    'use_count' => (int)($r['use_count'] ?? 0),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}
