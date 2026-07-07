<?php
declare(strict_types=1);

if (!function_exists('org_render_feed_toolbar_tabs')) {
    function org_render_feed_toolbar_tabs(string $tab, int $unreadPostsCount): void
    {
        if ($tab !== 'work' && $tab !== 'culture' && $tab !== 'all') {
            $tab = 'work';
        }
        ?>
        <nav class="feed-tabs feed-tabs--toolbar" aria-label="Feed tabs">
          <a class="<?= $tab === 'work' ? 'active' : '' ?>" href="feed.php?tab=work">Work</a>
          <a class="<?= $tab === 'culture' ? 'active' : '' ?>" href="feed.php?tab=culture">Culture</a>
          <a class="<?= $tab === 'all' ? 'active' : '' ?>" href="feed.php?tab=all">All</a>
          <span class="stat-pill" title="Posts newer than your last successful load">
            <span class="icon"><i class="fa fa-envelope-open-o"></i></span>
            <span class="num" id="unreadPostsNum"><?= (int)$unreadPostsCount ?></span>
            <span class="lbl">Unread</span>
          </span>
        </nav>
        <?php
    }
}

if (!function_exists('org_render_header_feed_tabs')) {
    function org_render_header_feed_tabs(string $tab, int $unreadPostsCount): void
    {
        org_render_feed_toolbar_tabs($tab, $unreadPostsCount);
    }
}
