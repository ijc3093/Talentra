<?php
declare(strict_types=1);

/** Publisher search results on public.php when ?q= is set. */
if (!isset($pubDbh) || !$pubDbh instanceof PDO || trim((string)($publisherSearchQuery ?? '')) === '') {
    return;
}

$panelMeId = (int)($meId ?? 0);
if ($panelMeId > 0 && publisher_workspace_viewer($pubDbh, $panelMeId)) {
    return;
}

if (!function_exists('user_avatar_url')) {
    function user_avatar_url(array $user, int $size = 96): string
    {
        $img = trim((string)($user['image'] ?? ''));
        if ($img !== '' && $img !== 'default.jpg') {
            return $img;
        }
        return 'avatar.php?id=' . (int)($user['id'] ?? 0) . '&s=' . $size;
    }
}

$searchHits = publisher_attach_follow_state(
    $pubDbh,
    publisher_search($pubDbh, (string)$publisherSearchQuery, 12, true),
    (int)($meId ?? 0)
);
if (!$searchHits) {
    return;
}

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
?>
<section class="pub-search-results" aria-label="Publisher search results">
  <h2 class="pub-search-title">Publishers matching “<?= h((string)$publisherSearchQuery) ?>”</h2>
  <div class="pub-discover-scroll">
    <?php foreach ($searchHits as $pub): ?>
      <?php
        $pid = (int)($pub['id'] ?? 0);
        $catKey = (string)($pub['publisher_category'] ?? '');
        $catLabel = publisher_categories()[$catKey] ?? $catKey;
      ?>
      <article class="pub-discover-card">
        <a href="profile.php?u=<?= rawurlencode((string)($pub['username'] ?? '')) ?>" class="pub-discover-avatar">
          <img src="<?= h(user_avatar_url($pub, 96)) ?>" alt="" loading="lazy">
        </a>
        <div class="pub-discover-name"><?= h((string)($pub['name'] ?? '')) ?></div>
        <?php if ($catLabel !== ''): ?><div class="pub-discover-cat"><?= h($catLabel) ?></div><?php endif; ?>
        <button type="button" class="pub-follow-btn<?= !empty($pub['is_following']) ? ' is-following' : '' ?>" data-publisher-id="<?= $pid ?>">
          <?= !empty($pub['is_following']) ? 'Following' : 'Follow' ?>
        </button>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="pub-search-note">Tap <strong>Follow</strong> — their public posts will appear in your <a href="feed.php">Feed</a>.</p>
</section>
<style>
  .pub-search-results{margin:0 0 16px;padding:14px 16px;background:#fff;border:1px solid var(--public-border,#e5e7eb);border-radius:16px}
  .pub-search-title{margin:0 0 12px;font-size:15px;font-weight:900}
  .pub-search-note{margin:10px 0 0;font-size:12px;color:var(--public-muted,#6b7280)}
</style>
