<?php
declare(strict_types=1);

/**
 * Shared helpers for the owner's private archived-post list.
 */

if (!function_exists('msb_archive_fetch_posts')) {
    /**
     * @return list<array<string,mixed>>
     */
    function msb_archive_fetch_posts(PDO $dbh, int $userId, int $limit = 200): array
    {
        $userId = max(0, $userId);
        $limit = max(1, min(200, $limit));
        if ($userId <= 0) {
            return [];
        }

        if (function_exists('device_profile_ensure_post_columns')) {
            device_profile_ensure_post_columns($dbh);
        }

        try {
            if (!function_exists('post_layout_select_sql')) {
                require_once __DIR__ . '/post_layout.php';
            }
            $layoutSelect = function_exists('post_layout_select_sql')
                ? post_layout_select_sql($dbh)
                : "'' AS declared_layout,";

            $st = $dbh->prepare("
              SELECT
                p.id,
                p.user_id,
                COALESCE(p.title,'') AS title,
                COALESCE(p.description,'') AS description,
                COALESCE(p.body,'') AS body,
                p.created_at,
                COALESCE(p.updated_at, p.created_at) AS updated_at,
                COALESCE(p.is_archived,0) AS is_archived,
                COALESCE(p.archived_as_story,0) AS archived_as_story,
                {$layoutSelect}
                (
                  SELECT aa.file_path
                  FROM public_post_attachments aa
                  WHERE aa.post_id = p.id
                  ORDER BY
                    CASE
                      WHEN aa.type IN ('image','gif') THEN 0
                      WHEN aa.type = 'video' THEN 1
                      ELSE 2
                    END,
                    aa.id ASC
                  LIMIT 1
                ) AS thumb_file,
                (
                  SELECT aa.thumb_path
                  FROM public_post_attachments aa
                  WHERE aa.post_id = p.id
                  ORDER BY
                    CASE
                      WHEN aa.type IN ('image','gif') THEN 0
                      WHEN aa.type = 'video' THEN 1
                      ELSE 2
                    END,
                    aa.id ASC
                  LIMIT 1
                ) AS thumb_path,
                (
                  SELECT aa.type
                  FROM public_post_attachments aa
                  WHERE aa.post_id = p.id
                  ORDER BY
                    CASE
                      WHEN aa.type IN ('image','gif') THEN 0
                      WHEN aa.type = 'video' THEN 1
                      ELSE 2
                    END,
                    aa.id ASC
                  LIMIT 1
                ) AS thumb_type,
                (SELECT COUNT(*) FROM public_post_attachments ac WHERE ac.post_id = p.id) AS attachment_count
              FROM public_posts p
              WHERE p.user_id = :me
                AND COALESCE(p.is_deleted,0) = 0
                AND COALESCE(p.is_archived,0) = 1
              ORDER BY COALESCE(p.updated_at, p.created_at) DESC, p.id DESC
              LIMIT {$limit}
            ");
            $st->execute([':me' => $userId]);
            $posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        foreach ($posts as &$post) {
            $thumb = trim((string)($post['thumb_path'] ?? ''));
            if ($thumb === '') {
                $thumb = trim((string)($post['thumb_file'] ?? ''));
            }
            $post['preview_src'] = msb_archive_media_src($thumb);
            $caption = trim((string)(($post['body'] ?? '') !== '' ? $post['body'] : ($post['description'] ?? '')));
            if ($caption === '') {
                $caption = trim((string)($post['title'] ?? ''));
            }
            if (function_exists('post_strip_layout_marker')) {
                $caption = post_strip_layout_marker($caption);
            }
            $post['preview_text'] = $caption;
            // Archive Stories circle = archived from story-door fries only.
            $post['is_story'] = !empty($post['archived_as_story']) ? 1 : 0;
        }
        unset($post);

        return $posts;
    }
}

if (!function_exists('msb_archive_media_src')) {
    function msb_archive_media_src(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('~^(https?:)?//~i', $path)) {
            return $path;
        }
        if (isset($path[0]) && $path[0] === '/') {
            return $path;
        }
        return './' . ltrim($path, './');
    }
}

if (!function_exists('msb_archive_time_ago')) {
    function msb_archive_time_ago(string $dt): string
    {
        $dt = trim($dt);
        if ($dt === '') {
            return '';
        }
        $ts = strtotime($dt);
        if ($ts === false) {
            return '';
        }
        $sec = max(0, time() - $ts);
        if ($sec < 60) {
            return 'just now';
        }
        if ($sec < 3600) {
            return ((int)floor($sec / 60)) . 'm ago';
        }
        if ($sec < 86400) {
            return ((int)floor($sec / 3600)) . 'h ago';
        }
        if ($sec < 86400 * 30) {
            return ((int)floor($sec / 86400)) . 'd ago';
        }
        return date('M j, Y', $ts);
    }
}

if (!function_exists('msb_archive_h')) {
    function msb_archive_h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('msb_archive_render_list_html')) {
    /**
     * @param list<array<string,mixed>> $posts
     */
    function msb_archive_render_list_html(array $posts, string $emptyTitle = 'No archived posts'): string
    {
        if (!$posts) {
            return '<div class="msb-archive-empty" role="status">'
                . '<i class="icon ion-ios-box" aria-hidden="true"></i>'
                . '<div class="msb-archive-empty-title">' . msb_archive_h($emptyTitle) . '</div>'
                . '<div class="msb-archive-empty-text">Use Archive on a post menu in For You or Discover. Hidden posts show up here for you only.</div>'
                . '</div>';
        }

        $html = '<div class="msb-archive-list" id="archiveList">';
        foreach ($posts as $post) {
            $pid = (int)($post['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $preview = trim((string)($post['preview_text'] ?? ''));
            $title = trim((string)($post['title'] ?? ''));
            if ($title === '' || strcasecmp($title, 'post') === 0) {
                if ($preview !== '') {
                    $title = function_exists('mb_substr')
                        ? (string)mb_substr($preview, 0, 72)
                        : substr($preview, 0, 72);
                } else {
                    $title = 'Post #' . $pid;
                }
            }
            $thumbType = strtolower(trim((string)($post['thumb_type'] ?? '')));
            $previewSrc = (string)($post['preview_src'] ?? '');
            $when = msb_archive_time_ago((string)($post['updated_at'] ?? $post['created_at'] ?? ''));
            $attachCount = (int)($post['attachment_count'] ?? 0);

            $thumbHtml = '<i class="icon ion-document-text" style="font-size:28px;"></i>';
            if ($previewSrc !== '' && $thumbType === 'video') {
                $thumbHtml = '<video src="' . msb_archive_h($previewSrc) . '" muted playsinline preload="metadata"></video>';
            } elseif ($previewSrc !== '') {
                $thumbHtml = '<img src="' . msb_archive_h($previewSrc) . '" alt="">';
            }

            $textHtml = '';
            if ($preview !== '' && $preview !== $title) {
                $textHtml = '<p class="msb-archive-item-text">' . msb_archive_h($preview) . '</p>';
            } elseif ($attachCount > 0) {
                $textHtml = '<p class="msb-archive-item-text">' . $attachCount . ' media file' . ($attachCount === 1 ? '' : 's') . '</p>';
            } else {
                $textHtml = '<p class="msb-archive-item-text">Text post</p>';
            }

            $html .= '<article class="msb-archive-item" data-post-id="' . $pid . '">'
                . '<div class="msb-archive-thumb" aria-hidden="true">' . $thumbHtml . '</div>'
                . '<div class="msb-archive-meta">'
                . '<h3 class="msb-archive-item-title">' . msb_archive_h($title) . '</h3>'
                . $textHtml
                . '<div class="msb-archive-item-time">Archived · ' . msb_archive_h($when) . '</div>'
                . '</div>'
                . '<div class="msb-archive-item-actions">'
                . '<button type="button" class="msb-archive-btn js-unarchive" data-post-id="' . $pid . '">Unarchive</button>'
                . '</div>'
                . '</article>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('msb_archive_render_css')) {
    function msb_archive_render_css(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<style id="msb-archive-list-css">
.msb-archive-empty{padding:28px 8px;text-align:center;color:var(--msb-palette-text-muted,#667085)}
.msb-archive-empty i{display:block;font-size:42px;margin-bottom:12px;opacity:.75}
.msb-archive-empty-title{font-weight:800;color:var(--msb-palette-text,#0f172a);margin-bottom:6px}
.msb-archive-empty-text{font-size:13px;line-height:1.45}
.msb-archive-list{display:grid;gap:12px}
.msb-archive-item{display:grid;grid-template-columns:84px minmax(0,1fr) auto;gap:12px;align-items:center;border:1px solid var(--msb-palette-border,#e5e7eb);border-radius:16px;padding:12px;background:var(--msb-palette-bg,#f8fafc)}
.msb-archive-thumb{width:84px;height:84px;border-radius:12px;overflow:hidden;background:#0f172a;display:grid;place-items:center;color:#94a3b8}
.msb-archive-thumb img,.msb-archive-thumb video{width:100%;height:100%;object-fit:cover;display:block}
.msb-archive-meta{min-width:0}
.msb-archive-item-title{font-weight:800;font-size:15px;line-height:1.3;margin:0 0 4px;word-break:break-word}
.msb-archive-item-text{margin:0;color:var(--msb-palette-text-muted,#475467);font-size:13px;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.msb-archive-item-time{margin-top:8px;font-size:12px;font-weight:700;color:var(--msb-palette-text-muted,#667085)}
.msb-archive-item-actions{display:flex;flex-direction:column;gap:8px}
.msb-archive-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:12px;border:0;background:#111827;color:#fff;font-weight:800;cursor:pointer}
.msb-archive-btn:disabled{opacity:.65;cursor:wait}
.msb-archive-note{margin:0 0 14px;padding:12px 14px;border-radius:12px;background:#eff6ff;color:#1e3a8a;border:1px solid #bfdbfe;font-size:13px;font-weight:700;line-height:1.45}
.msb-archive-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#0f172a;color:#fff;padding:12px 16px;border-radius:999px;font-weight:700;font-size:13px;z-index:9999;opacity:0;pointer-events:none;transition:opacity .18s ease}
.msb-archive-toast.is-on{opacity:1}
@media (max-width:700px){
  .msb-archive-item{grid-template-columns:64px minmax(0,1fr);grid-template-rows:auto auto}
  .msb-archive-thumb{width:64px;height:64px}
  .msb-archive-item-actions{grid-column:1 / -1}
}
</style>
        <?php
    }
}

if (!function_exists('msb_archive_render_unarchive_js')) {
    function msb_archive_render_unarchive_js(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<script id="msb-archive-unarchive-js">
(function(){
  if(window.__msbArchiveUnarchiveBound) return;
  window.__msbArchiveUnarchiveBound = true;
  var toastEl = document.getElementById('msbArchiveToast');
  if(!toastEl){
    toastEl = document.createElement('div');
    toastEl.id = 'msbArchiveToast';
    toastEl.className = 'msb-archive-toast';
    toastEl.setAttribute('role', 'status');
    toastEl.setAttribute('aria-live', 'polite');
    document.body.appendChild(toastEl);
  }
  var toastTimer = 0;
  function toast(msg){
    toastEl.textContent = String(msg || '');
    toastEl.classList.add('is-on');
    if(toastTimer) window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function(){ toastEl.classList.remove('is-on'); }, 2200);
  }
  function unarchivePost(postId, btn){
    postId = Number(postId || 0);
    if(!postId) return;
    if(btn) btn.disabled = true;
    var body = new URLSearchParams({ ajax:'archive', post_id:String(postId), archived:'0' });
    fetch('feed_api.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      credentials:'same-origin',
      body: body
    }).then(function(r){ return r.json(); }).then(function(res){
      if(!res || res.ok === false){
        if(btn) btn.disabled = false;
        toast((res && res.error) ? String(res.error) : 'Could not unarchive this post.');
        return;
      }
      document.querySelectorAll('.msb-archive-item[data-post-id="'+String(postId)+'"]').forEach(function(row){
        try{ row.remove(); }catch(e){}
      });
      toast('Post restored to your feeds.');
      document.querySelectorAll('.msb-archive-list').forEach(function(list){
        if(list.querySelector('.msb-archive-item')) return;
        list.outerHTML = <?= json_encode(msb_archive_render_list_html([]), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      });
    }).catch(function(){
      if(btn) btn.disabled = false;
      toast('Network error. Try again.');
    });
  }
  document.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('.js-unarchive') : null;
    if(!btn) return;
    e.preventDefault();
    unarchivePost(btn.getAttribute('data-post-id'), btn);
  });
})();
</script>
        <?php
    }
}
