<?php
declare(strict_types=1);

$msbArchiveEmbed = !empty($msbArchiveEmbed);
$msbArchiveMode = strtolower(trim((string)($msbArchiveMode ?? 'archive')));
if ($msbArchiveMode !== 'favorites') {
    $msbArchiveMode = 'archive';
}
$msbArchiveIsFav = ($msbArchiveMode === 'favorites');
$msbArchiveCanManage = array_key_exists('msbArchiveCanManage', get_defined_vars())
    ? !empty($msbArchiveCanManage)
    : true;
$storyCircles = isset($storyCircles) && is_array($storyCircles) ? $storyCircles : [];
$feedPosts = isset($feedPosts) && is_array($feedPosts) ? $feedPosts : [];
$hasStories = !empty($hasStories);
$backUrl = isset($backUrl) ? (string)$backUrl : 'profile.php?tab=gear';
$uid = isset($msbArchiveUid) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$msbArchiveUid) : '';
if ($uid === '') {
    $uid = $msbArchiveIsFav ? 'fav' : 'archive';
}

$pageTitle = $msbArchiveIsFav ? 'Favorites' : 'Archived posts';
$storiesAria = $msbArchiveIsFav ? 'Favorited stories' : 'Archived stories';
$storiesEmptyAria = $msbArchiveIsFav ? 'No favorited stories' : 'No archived stories';
$storiesEmptyIcon = $msbArchiveIsFav ? 'ion-ios-bookmarks-outline' : 'ion-ios-book-outline';
$storiesNote = $msbArchiveIsFav
    ? 'Only you can see favorited stories. Favorite another person’s story from the story door fries menu to add a circle here.'
    : 'Only you can see archived stories. Each hide from the story door fries menu adds the next circle here.';
$postsAria = $msbArchiveIsFav ? 'Favorited posts' : 'Archived posts';
$postsNote = $msbArchiveIsFav
    ? 'Favorited from the feed or public post-card fries menu stay here — separate from story circles above.'
    : 'Archived from the feed or public post-card fries menu stay here — separate from story circles above.';
$emptyTitle = $msbArchiveIsFav ? 'No favorites yet' : 'No archived items';
$emptyText = $msbArchiveIsFav
    ? 'Favorite a story from the story door fries menu (adds a circle above), or favorite another person’s post from Circle / Discover (shows under Posts).'
    : 'Archive a story from the story door fries menu (adds a circle above), or archive a post from Circle / Discover (shows under Posts).';
$removeLabel = $msbArchiveIsFav ? 'Remove from Favorites' : 'Unarchive';
$viewerLabel = $msbArchiveIsFav ? 'Favorited item' : 'Archived item';
$openStoryLabel = $msbArchiveIsFav ? 'Open favorited story' : 'Open archived story';
$openPostLabel = $msbArchiveIsFav ? 'Favorited post from' : 'Archived post from';
?>
<div class="ig-archive" data-archive-mode="<?= msb_archive_h($msbArchiveMode) ?>">
  <header class="ig-archive-top">
    <div class="ig-archive-head">
      <?php if (!$msbArchiveEmbed): ?>
      <a class="ig-archive-back" href="<?= msb_archive_h($backUrl) ?>" aria-label="Back">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M15.5 4.5 8 12l7.5 7.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </a>
      <?php endif; ?>
      <h1 class="ig-archive-title"><?= msb_archive_h($pageTitle) ?></h1>
    </div>

    <div class="ig-archive-stories-block">
      <div class="ig-archive-stories-label">Moments</div>
      <div class="ig-stories-wrap">
      <div class="ig-stories-bar<?= $hasStories ? '' : ' is-empty' ?>" aria-label="<?= msb_archive_h($storiesAria) ?>">
        <div class="ig-stories-track<?= $hasStories ? '' : ' is-empty' ?>" id="<?= $uid ?>StoriesTrack">
          <?php if (!$hasStories): ?>
            <div class="ig-story-item ig-story-empty" role="status" aria-label="<?= msb_archive_h($storiesEmptyAria) ?>">
              <div class="ig-story-ring ig-story-ring-empty">
                <span class="ig-story-empty-icon" aria-hidden="true"><i class="icon <?= msb_archive_h($storiesEmptyIcon) ?>"></i></span>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($storyCircles as $circle): ?>
              <?php
                $cid = (int)$circle['postId'];
                $cSrc = (string)$circle['src'];
                $cType = (string)$circle['type'];
                $cCap = (string)$circle['caption'];
                $cLabel = (string)$circle['label'];
                $cRing = (string)$circle['ringSrc'];
                $cAuthor = (string)($circle['authorName'] ?? '');
                $cUser = (string)($circle['username'] ?? '');
                $cAvatar = (string)($circle['avatarUrl'] ?? $cRing);
                $cWhen = (string)($circle['createdAt'] ?? '');
                $slideJson = json_encode([[
                    'postId' => $cid,
                    'src' => $cSrc,
                    'type' => $cType,
                    'caption' => $cCap,
                    'timeLabel' => $cLabel,
                    'createdAt' => $cWhen,
                    'isArchived' => $msbArchiveIsFav ? 0 : 1,
                    'isFavorited' => $msbArchiveIsFav ? 1 : 0,
                ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
              ?>
              <button
                type="button"
                class="ig-story-item"
                data-story-key="s<?= $cid ?>"
                data-post-id="<?= $cid ?>"
                data-src="<?= msb_archive_h($cSrc) ?>"
                data-type="<?= msb_archive_h($cType) ?>"
                data-caption="<?= msb_archive_h($cCap) ?>"
                data-author-name="<?= msb_archive_h($cAuthor) ?>"
                data-username="<?= msb_archive_h($cUser) ?>"
                data-avatar="<?= msb_archive_h($cAvatar) ?>"
                data-time-label="<?= msb_archive_h($cLabel) ?>"
                data-story-slides="<?= msb_archive_h((string)$slideJson) ?>"
                aria-label="<?= msb_archive_h($openStoryLabel) ?> <?= msb_archive_h($cLabel) ?>"
              >
                <div class="ig-story-ring">
                  <?php if ($cType === 'video' && $cSrc !== ''): ?>
                    <video class="ig-story-thumb" src="<?= msb_archive_h($cSrc) ?>" muted playsinline preload="metadata"></video>
                  <?php elseif ($cSrc !== ''): ?>
                    <img class="ig-story-thumb" src="<?= msb_archive_h($cSrc) ?>" alt="">
                  <?php elseif ($cRing !== '' && $cRing !== $cSrc): ?>
                    <img class="ig-story-thumb" src="<?= msb_archive_h($cRing) ?>" alt="">
                  <?php else: ?>
                    <span class="ig-story-ring-text"><?= msb_archive_h(function_exists('mb_substr') ? (string)mb_substr($cCap, 0, 18) : substr($cCap, 0, 18)) ?></span>
                  <?php endif; ?>
                </div>
                <span class="ig-story-name"><?= msb_archive_h($cLabel) ?></span>
              </button>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if ($hasStories): ?>
          <button type="button" class="ig-stories-next" aria-label="Next stories" id="<?= $uid ?>StoriesNext">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
        <?php endif; ?>
      </div>
      </div>
      <p class="ig-archive-note ig-archive-note--stories"><?= msb_archive_h($storiesNote) ?></p>
    </div>
  </header>

  <?php if ($feedPosts || (!$hasStories && !$feedPosts)): ?>
  <div class="ig-archive-body">
  <?php if ($feedPosts): ?>
    <section class="ig-archive-section" aria-label="<?= msb_archive_h($postsAria) ?>">
    <div class="ig-archive-posts-meta">
      <div class="ig-archive-section-title">Posts</div>
      <p class="ig-archive-note" style="margin-top:0;margin-bottom:12px;"><?= msb_archive_h($postsNote) ?></p>
    </div>
    <div class="ig-archive-grid-scroll">
    <div class="ig-archive-grid" id="<?= $uid ?>PostList">
      <?php foreach ($feedPosts as $post): ?>
        <?php
          $pid = (int)($post['id'] ?? 0);
          if ($pid <= 0) {
              continue;
          }
          $previewSrc = (string)($post['preview_src'] ?? '');
          $thumbType = strtolower(trim((string)($post['thumb_type'] ?? '')));
          $isVideo = ($thumbType === 'video');
          $caption = trim((string)($post['preview_text'] ?? ''));
          $title = trim((string)($post['title'] ?? ''));
          if ($title === '' || strcasecmp($title, 'post') === 0) {
              $title = $caption !== '' ? $caption : ('Post #' . $pid);
          }
          $badge = msb_archive_date_badge((string)($post['saved_at'] ?? $post['updated_at'] ?? $post['created_at'] ?? ''));
          $openCaption = $caption !== '' ? $caption : $title;
          $loveC = (int)($post['love_count'] ?? 0);
          $comC = (int)($post['comment_count'] ?? 0);
          $viewsC = (int)($post['views_count'] ?? 0);
        ?>
        <button
          type="button"
          class="ig-archive-tile"
          data-post-id="<?= $pid ?>"
          data-src="<?= msb_archive_h($previewSrc) ?>"
          data-type="<?= msb_archive_h($isVideo ? 'video' : ($previewSrc !== '' ? 'image' : 'text')) ?>"
          data-caption="<?= msb_archive_h($openCaption) ?>"
          data-kind="post"
          aria-label="<?= msb_archive_h($openPostLabel) ?> <?= msb_archive_h(trim($badge['day'] . ' ' . $badge['month'])) ?>"
        >
          <?php if ($badge['day'] !== ''): ?>
            <span class="ig-archive-date">
              <span class="ig-archive-date-day"><?= msb_archive_h($badge['day']) ?></span>
              <span class="ig-archive-date-month"><?= msb_archive_h($badge['month']) ?></span>
            </span>
          <?php endif; ?>
          <?php if ($isVideo && $previewSrc !== ''): ?>
            <span class="ig-archive-video-mark" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </span>
            <video class="ig-archive-media" src="<?= msb_archive_h($previewSrc) ?>" muted playsinline preload="metadata"></video>
          <?php elseif ($previewSrc !== ''): ?>
            <img class="ig-archive-media" src="<?= msb_archive_h($previewSrc) ?>" alt="">
          <?php else: ?>
            <div class="ig-archive-fallback"><span><?= msb_archive_h($openCaption) ?></span></div>
          <?php endif; ?>
          <div class="react-overlay" aria-hidden="true">
            <span class="react-btn"><i class="icon ion-heart"></i> <span class="n"><?= (int)$loveC ?></span></span>
            <span class="react-btn"><i class="icon ion-chatbubble"></i> <span class="n"><?= (int)$comC ?></span></span>
            <span class="react-btn"><i class="icon ion-eye"></i> <span class="vnum"><?= (int)$viewsC ?></span></span>
          </div>
        </button>
      <?php endforeach; ?>
    </div>
    </div>
    </section>
  <?php endif; ?>

  <?php if (!$hasStories && !$feedPosts): ?>
    <div class="ig-archive-empty" role="status">
      <strong><?= msb_archive_h($emptyTitle) ?></strong>
      <p><?= msb_archive_h($emptyText) ?></p>
    </div>
  <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="ig-archive-viewer" id="<?= $uid ?>Viewer" aria-hidden="true">
  <div class="ig-archive-sheet" role="dialog" aria-modal="true" aria-label="<?= msb_archive_h($viewerLabel) ?>">
    <div class="ig-archive-sheet-preview" id="<?= $uid ?>ViewerPreview"></div>
    <div class="ig-archive-sheet-actions">
      <?php if ($msbArchiveCanManage): ?>
        <button type="button" class="ig-archive-sheet-btn is-danger ig-archive-remove-btn" id="<?= $uid ?>UnarchiveBtn"><?= msb_archive_h($removeLabel) ?></button>
      <?php endif; ?>
      <button type="button" class="ig-archive-sheet-btn ig-archive-close-btn" id="<?= $uid ?>ViewerClose">Cancel</button>
    </div>
  </div>
</div>
<div class="ig-archive-toast" id="<?= $uid ?>Toast" role="status" aria-live="polite"></div>
