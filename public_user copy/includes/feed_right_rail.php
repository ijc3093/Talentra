<?php
declare(strict_types=1);

/** Right quick-nav rail — set $feedRightRailActive before include (e.g. public.php). */
$feedRightRailActive = strtolower(trim((string)($feedRightRailActive ?? '')));
$frlActive = static function (string $slug) use ($feedRightRailActive): string {
    return ($feedRightRailActive === strtolower($slug)) ? ' is-active' : '';
};
?>
<aside class="feed-right-rail" aria-label="Quick navigation">
  <nav class="feed-right-nav" aria-label="Sidebar menu">
    <a class="feed-right-nav-item<?= $frlActive('news.php') ?>" href="news.php">
      <span class="feed-right-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg></span>
      <span class="feed-right-nav-label">News</span>
    </a>
    <a class="feed-right-nav-item<?= $frlActive('shop') ?>" href="#">
      <span class="feed-right-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg></span>
      <span class="feed-right-nav-label">Shop</span>
    </a>
    <a class="feed-right-nav-item<?= $frlActive('library') ?>" href="#">
      <span class="feed-right-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 17V9"/><path d="M12 17V7"/><path d="M16 17v-5"/></svg></span>
      <span class="feed-right-nav-label">Library</span>
    </a>
    <a class="feed-right-nav-item<?= $frlActive('apps') ?>" href="#">
      <span class="feed-right-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
      <span class="feed-right-nav-label">Apps</span>
    </a>
    <a class="feed-right-nav-item<?= $frlActive('agents') ?>" href="#">
      <span class="feed-right-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="8" width="14" height="10" rx="3"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><circle cx="10" cy="13" r="1"/><circle cx="14" cy="13" r="1"/><path d="M10 16h4"/></svg></span>
      <span class="feed-right-nav-label">Agents</span>
      <span class="feed-right-nav-badge">NEW</span>
    </a>
    <a class="feed-right-nav-item<?= $frlActive('research') ?>" href="#">
      <span class="feed-right-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20l6-6"/><path d="M14 4l6 6"/><path d="M9 15l-2 5 5-2 8-8-3-3-8 8z"/><circle cx="18" cy="6" r="2"/></svg></span>
      <span class="feed-right-nav-label">Deep research</span>
    </a>
  </nav>
</aside>
