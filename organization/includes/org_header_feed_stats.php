<?php
declare(strict_types=1);

if (!function_exists('org_render_feed_toolbar_stats')) {
    function org_render_feed_toolbar_stats(PDO $dbh, int $orgId): void
    {
        if ($orgId <= 0) {
            return;
        }

        require_once __DIR__ . '/org_feed_pulse.php';
        $pulse = org_feed_pulse_stats($dbh, $orgId);
        ?>
        <div class="feed-toolbar-stats right-stats" aria-label="7-day feed activity">
          <span class="stat-pill" title="Posts in the last 7 days">
            <span class="icon"><i class="fa fa-file-text-o"></i></span>
            <span class="num"><?= (int)$pulse['posts_7d'] ?></span>
            <span class="lbl">Posts</span>
            <span class="sub">7d</span>
          </span>
          <span class="stat-pill" title="Replies in the last 7 days">
            <span class="icon"><i class="fa fa-comments-o"></i></span>
            <span class="num"><?= (int)$pulse['comments_7d'] ?></span>
            <span class="lbl">Replies</span>
            <span class="sub">7d</span>
          </span>
          <span class="stat-pill" title="Likes in the last 7 days">
            <span class="icon"><i class="fa fa-lightbulb-o"></i></span>
            <span class="num"><?= (int)$pulse['acks_7d'] ?></span>
            <span class="lbl">Likes</span>
            <span class="sub">7d</span>
          </span>
        </div>
        <?php
    }
}

if (!function_exists('org_render_header_feed_stats')) {
    function org_render_header_feed_stats(PDO $dbh, int $orgId): void
    {
        org_render_feed_toolbar_stats($dbh, $orgId);
    }
}
