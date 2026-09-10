<?php
declare(strict_types=1);

/**
 * Placeholder when a post attachment file is missing from uploads/.
 */

if (!function_exists('msb_archive_media_file_exists')) {
    require_once __DIR__ . '/archive_posts.php';
}

if (!function_exists('msb_no_image_svg_inner')) {
    function msb_no_image_svg_inner(): string
    {
        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . '<rect x="3.2" y="5.2" width="17.6" height="13.6" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/>'
            . '<circle cx="15.55" cy="9.15" r="1.15" fill="currentColor"/>'
            . '<path d="M5.1 16.85l4.2-4.05 2.55 2.4 3.15-3.7 4 5.35" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M5.2 18.4 L18.8 5.6" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round"/>'
            . '</svg>';
    }
}

if (!function_exists('msb_unavailable_svg_inner')) {
    /** Circle-tab missing media: circle with landscape glyph and a strike-through. */
    function msb_unavailable_svg_inner(): string
    {
        return '<svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">'
            . '<circle cx="32" cy="32" r="21.5" fill="none" stroke="currentColor" stroke-width="2.15"/>'
            . '<rect x="22.2" y="24.4" width="19.6" height="15.2" rx="2.1" fill="none" stroke="currentColor" stroke-width="1.7"/>'
            . '<circle cx="27.9" cy="29.2" r="1.35" fill="currentColor"/>'
            . '<path d="M23.6 37.4l4.7-4.2 3.05 2.7 3.9-4.35 6.15 5.85" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M18.4 45.6 L45.6 18.4" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round"/>'
            . '</svg>';
    }
}

if (!function_exists('msb_unavailable_label')) {
    function msb_unavailable_label(): string
    {
        return function_exists('app_t') ? app_t('Media unavailable') : 'Media unavailable';
    }
}

if (!function_exists('msb_no_image_html')) {
    function msb_no_image_html(): string
    {
        $label = function_exists('app_t') ? app_t('No Image') : 'No Image';
        return '<div class="msb-no-image" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" style="border-radius:6px;overflow:hidden;background:#fff;clip-path:inset(0 round 6px);-webkit-clip-path:inset(0 round 6px)">'
            . msb_no_image_svg_inner()
            . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>';
    }
}

if (!function_exists('msb_unavailable_html')) {
    function msb_unavailable_html(): string
    {
        $label = msb_unavailable_label();
        return '<div class="msb-no-image msb-media-unavailable" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" style="border-radius:12px;overflow:hidden;background:#3d434b;">'
            . msb_unavailable_svg_inner()
            . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>';
    }
}

if (!function_exists('msb_media_is_missing')) {
    function msb_media_is_missing(string $src): bool
    {
        return trim($src) === '';
    }
}

if (!function_exists('msb_attachment_apply_missing')) {
    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    function msb_attachment_apply_missing(array $a): array
    {
        $fp = trim((string)($a['file_path'] ?? $a['url'] ?? ''));
        $a['missing'] = msb_media_is_missing($fp) ? 1 : 0;
        $tp = trim((string)($a['thumb_path'] ?? $a['thumb_url'] ?? ''));
        if ($tp !== '' && msb_media_is_missing($tp)) {
            $a['thumb_path'] = '';
            if (isset($a['thumb_url'])) {
                $a['thumb_url'] = '';
            }
        }
        return $a;
    }
}

if (!function_exists('msb_post_attachment_html')) {
    /**
     * @param array<string,mixed> $a
     * @param array<string,string> $opts
     */
    function msb_post_attachment_html(array $a, array $opts = []): string
    {
        $type = strtolower(trim((string)($a['type'] ?? 'image')));
        $raw = trim((string)($a['file_path'] ?? ''));
        $src = function_exists('msb_public_media_usable') ? msb_public_media_usable($raw) : $raw;
        $thumbRaw = trim((string)($a['thumb_path'] ?? ''));
        $thumb = $thumbRaw !== '' && function_exists('msb_public_media_usable')
            ? msb_public_media_usable($thumbRaw)
            : $thumbRaw;

        if (($type === 'image' || $type === 'gif' || $type === 'video') && $src === '') {
            return msb_unavailable_html();
        }

        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        if ($type === 'video') {
            $cls = trim((string)($opts['video_class'] ?? ''));
            $extra = trim((string)($opts['video_attrs'] ?? 'playsinline muted loop preload="auto"'));
            $poster = $thumb !== '' ? (' poster="' . $esc($thumb) . '"') : '';
            $classAttr = $cls !== '' ? (' class="' . $esc($cls) . '"') : '';
            return '<video' . $classAttr . ' src="' . $esc($src) . '"' . $poster . ' ' . $extra . '></video>';
        }

        if ($type === 'image' || $type === 'gif') {
            $cls = trim((string)($opts['img_class'] ?? ''));
            $extra = trim((string)($opts['img_attrs'] ?? ''));
            $classAttr = $cls !== '' ? (' class="' . $esc($cls) . '"') : '';
            $extraAttr = $extra !== '' ? (' ' . $extra) : '';
            return '<img' . $classAttr . ' src="' . $esc($src) . '" alt=""' . $extraAttr . '>';
        }

        $openLabel = function_exists('app_t') ? app_t('Open file') : 'Open file';
        return '<div class="file-tile"><div>'
            . '<i class="icon ion-document-text" style="font-size:48px"></i>'
            . '<div style="margin-top:12px"><a href="' . $esc($src !== '' ? $src : $raw) . '" target="_blank" style="color:#fff;font-weight:700">'
            . htmlspecialchars($openLabel, ENT_QUOTES, 'UTF-8')
            . '</a></div></div></div>';
    }
}

if (!function_exists('msb_missing_media_print_assets')) {
    function msb_missing_media_print_assets(): void
    {
        if (!empty($GLOBALS['msb_missing_media_assets_printed'])) {
            return;
        }
        $GLOBALS['msb_missing_media_assets_printed'] = true;
        $label = function_exists('app_t') ? app_t('No Image') : 'No Image';
        $inner = msb_no_image_svg_inner() . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        $circleLabel = msb_unavailable_label();
        $circleInner = msb_unavailable_svg_inner() . '<span>' . htmlspecialchars($circleLabel, ENT_QUOTES, 'UTF-8') . '</span>';
        ?>
<style id="msb-no-image-css">
.msb-no-image{
  display:flex !important;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap:8px;
  width:100%;
  min-height:240px;
  height:auto;
  box-sizing:border-box;
  color:#9aa0a6;
  background:#fff !important;
  border-radius:6px !important;
  overflow:hidden !important;
  clip-path:inset(0 round 6px);
  -webkit-clip-path:inset(0 round 6px);
  pointer-events:none;
  user-select:none;
}
.msb-no-image svg{
  width:56px;
  height:56px;
  display:block;
}
.msb-no-image span{
  font-family:inherit;
  font-size:13px;
  font-weight:700;
  letter-spacing:.01em;
  line-height:1.2;
  color:#9aa0a6;
}
.media-stage > .msb-no-image,
.mf-media > .msb-no-image,
.reel-stage > .msb-no-image,
.media-slide > .msb-no-image,
.mf-media-slide > .msb-no-image{
  min-height:240px;
  border-radius:6px;
  overflow:hidden;
}
body.feed-insta-ui .mf-card.mf-media-missing,
body.feed-insta-ui .mf-card.is-single-image-post.mf-media-missing,
body.feed-insta-ui .mf-card.is-single-video-post.mf-media-missing,
body.feed-insta-ui .mf-card.is-single-image-post.mf-media-missing.mf-image-error,
body.feed-insta-ui .mf-card.is-single-video-post.mf-media-missing.mf-video-error,
body.public-page .post.public-post-card.mf-media-missing,
body.public-page .post.public-post-card.is-single-image-post.mf-media-missing,
body.public-page .post.public-post-card.is-single-video-post.mf-media-missing{
  display:block !important;
  visibility:visible !important;
  opacity:1 !important;
}
/* Profile Posts tab: keep missing-media cards visible (same plate as Circle). */
#profilePostsFeed .mf-card.mf-media-missing,
#profilePostsFeed .mf-card.is-single-image-post.mf-media-missing,
#profilePostsFeed .mf-card.is-single-video-post.mf-media-missing,
#profilePostsFeed .mf-card.is-single-image-post.mf-media-missing.mf-image-error,
#profilePostsFeed .mf-card.is-single-video-post.mf-media-missing.mf-video-error{
  display:block !important;
  visibility:visible !important;
  opacity:1 !important;
}
#profilePostsFeed .mf-card .mf-media:has(> .msb-no-image),
#profilePostsFeed .mf-card .media-stage:has(> .msb-no-image),
#profilePostsFeed .mf-card .media-slide:has(> .msb-no-image),
#profilePostsFeed .mf-card .mf-media-slide:has(> .msb-no-image){
  display:block !important;
  visibility:visible !important;
  min-height:240px !important;
  height:auto !important;
  background:transparent !important;
  border-radius:0 !important;
  overflow:visible !important;
}
#profilePostsFeed .msb-no-image,
#profilePostsFeed .msb-media-unavailable{
  display:flex !important;
  flex-direction:column !important;
  align-items:center !important;
  justify-content:center !important;
  gap:10px !important;
  box-sizing:border-box !important;
  width:100% !important;
  max-width:100% !important;
  height:100% !important;
  min-height:240px !important;
  margin:0 !important;
  padding:16px 12px !important;
  color:#c8cdd3 !important;
  background:#3d434b !important;
  border:1px solid #2f343b !important;
  border-radius:12px !important;
  overflow:hidden !important;
  clip-path:none !important;
  -webkit-clip-path:none !important;
}
#profilePostsFeed .msb-no-image svg,
#profilePostsFeed .msb-media-unavailable svg{
  width:64px !important;
  height:64px !important;
  flex:0 0 64px !important;
  color:#c8cdd3 !important;
}
#profilePostsFeed .msb-no-image span,
#profilePostsFeed .msb-media-unavailable span{
  font-size:13px !important;
  font-weight:600 !important;
  letter-spacing:0 !important;
  line-height:1.25 !important;
  color:#c8cdd3 !important;
  text-align:center !important;
}
#profilePostsFeed .mf-card.mf-media-missing > .mf-actions,
#profilePostsFeed .mf-card:has(.msb-no-image) > .mf-actions{
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}
/* Clips theater: keep the slide; only the plate is white. */
body.reel-page .reel-slide.mf-media-missing,
body.reel-page .reel-slide:has(.msb-no-image){
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
}
body.reel-page .reel-slide.mf-media-missing .reel-card-row,
body.reel-page .reel-slide:has(.msb-no-image) .reel-card-row{
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}
body.reel-page .reel-stage:has(> .msb-no-image){
  background:transparent !important;
  box-shadow:none !important;
}
body.reel-page .reel-stage > .msb-no-image{
  display:flex !important;
  width:100% !important;
  height:100% !important;
  min-height:240px !important;
  border-radius:6px !important;
  overflow:hidden !important;
  background:#fff !important;
  clip-path:inset(0 round 6px);
  -webkit-clip-path:inset(0 round 6px);
}
body.reel-page .reel-slide.mf-media-missing .reel-video,
body.reel-page .reel-slide.mf-media-missing .reel-image,
body.reel-page .reel-stage:has(> .msb-no-image) > .reel-video,
body.reel-page .reel-stage:has(> .msb-no-image) > .reel-image{
  display:none !important;
}
body.reel-page .reel-slide.mf-media-missing .reel-mute,
body.reel-page .reel-slide.mf-media-missing .reel-progress,
body.reel-page .reel-slide.mf-media-missing .reel-bottom-fade,
body.reel-page .reel-slide:has(.msb-no-image) .reel-mute,
body.reel-page .reel-slide:has(.msb-no-image) .reel-progress,
body.reel-page .reel-slide:has(.msb-no-image) .reel-bottom-fade{
  display:none !important;
}
/* Discover: same unavailable plate as Circle (full media box, not a device frame). */
body.public-page.feed-insta-ui .post.public-post-card .media-stage:has(.msb-no-image),
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .media-stage,
body.public-page.feed-insta-ui .post.public-post-card .reel-stage:has(> .msb-no-image){
  background:transparent !important;
}
body.public-page.feed-insta-ui .post.public-post-card .media-stage > .msb-no-image,
body.public-page.feed-insta-ui .post.public-post-card .media-slide > .msb-no-image,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage > .msb-no-image,
body.public-page.feed-insta-ui .post.public-post-card .reel-stage > .msb-no-image{
  display:flex !important;
  flex-direction:column !important;
  align-items:center !important;
  justify-content:center !important;
  gap:10px !important;
  grid-column:1 !important;
  grid-row:3 !important;
  justify-self:stretch !important;
  box-sizing:border-box !important;
  width:100% !important;
  max-width:100% !important;
  height:100% !important;
  min-height:240px !important;
  margin:0 !important;
  padding:16px 12px !important;
  color:#c8cdd3 !important;
  background:#3d434b !important;
  border:1px solid #2f343b !important;
  border-radius:12px !important;
  overflow:hidden !important;
  clip-path:none !important;
  -webkit-clip-path:none !important;
}
body.public-page.feed-insta-ui .post.public-post-card .msb-no-image svg,
body.public-page.feed-insta-ui .post.public-post-card .msb-media-unavailable svg{
  width:64px !important;
  height:64px !important;
  flex:0 0 64px !important;
  color:#c8cdd3 !important;
}
body.public-page.feed-insta-ui .post.public-post-card .msb-no-image span,
body.public-page.feed-insta-ui .post.public-post-card .msb-media-unavailable span{
  font-size:13px !important;
  font-weight:600 !important;
  letter-spacing:0 !important;
  line-height:1.25 !important;
  color:#c8cdd3 !important;
  text-align:center !important;
}
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .standard-media-actions,
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .standard-media-btn,
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .standard-media-actions .action-count,
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .standard-media-name,
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .standard-media-time{
  color:var(--msb-palette-text, var(--public-text)) !important;
  -webkit-text-fill-color:var(--msb-palette-text, var(--public-text)) !important;
  text-shadow:none !important;
}
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing > .post-header,
body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post).mf-media-missing > .post-header,
body.public-page.feed-insta-ui .post.public-post-card:has(.msb-no-image) > .post-header,
body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post):has(.msb-no-image) > .post-header{
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  align-items:center !important;
  justify-content:space-between !important;
  width:100% !important;
  padding:0 0 12px !important;
  box-sizing:border-box !important;
  position:relative !important;
  z-index:8 !important;
  background:transparent !important;
}
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing > .post-header .name,
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing > .post-header .time,
body.public-page.feed-insta-ui .post.public-post-card:has(.msb-no-image) > .post-header .name,
body.public-page.feed-insta-ui .post.public-post-card:has(.msb-no-image) > .post-header .time{
  color:var(--msb-palette-text, var(--public-text)) !important;
  -webkit-text-fill-color:var(--msb-palette-text, var(--public-text)) !important;
  text-shadow:none !important;
}
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .standard-media-topbar,
body.public-page.feed-insta-ui .post.public-post-card:has(.msb-no-image) .standard-media-topbar{
  position:relative !important;
  inset:auto !important;
  grid-row:1 !important;
  grid-column:1 !important;
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
  z-index:8 !important;
  color:var(--msb-palette-text, var(--public-text)) !important;
}
body.public-page.feed-insta-ui .post.public-post-card:has(> .post-header) .media-stage > .standard-media-topbar{
  display:none !important;
}
html[data-theme="dark"] .msb-no-image:not(.msb-media-unavailable),
html.theme-dark .msb-no-image:not(.msb-media-unavailable),
body.dark .msb-no-image:not(.msb-media-unavailable),
html[data-theme="dark"] .msb-no-image:not(.msb-media-unavailable) span,
html.theme-dark .msb-no-image:not(.msb-media-unavailable) span,
body.dark .msb-no-image:not(.msb-media-unavailable) span{
  background:#fff;
  color:#9aa0a6;
}

body.feed-page.feed-insta-ui .mf-feed .mf-card .msb-no-image,
body.feed-page.feed-insta-ui .mf-feed .mf-card .msb-media-unavailable,
body.feed-page.feed-insta-ui .mf-feed .mf-card .media-stage.single-landscape > .msb-no-image,
body.feed-page.feed-insta-ui .mf-feed .mf-card.single-landscape .media-stage > .msb-no-image,
body.feed-page.feed-insta-ui .mf-feed .mf-card .media-stage.single-square > .msb-no-image,
body.feed-page.feed-insta-ui .mf-feed .mf-card.single-square .media-stage > .msb-no-image,
body.feed-page.feed-insta-ui .mf-feed .mf-card .media-stage.single-portrait > .msb-no-image,
body.feed-page.feed-insta-ui .mf-feed .mf-card .media-stage.phone-shot > .msb-no-image,
body.feed-page.feed-insta-ui .mf-feed .mf-card.mf-card-phone-shot .media-stage > .msb-no-image{
  display:flex !important;
  flex-direction:column !important;
  align-items:center !important;
  justify-content:center !important;
  gap:10px !important;
  box-sizing:border-box !important;
  width:100% !important;
  max-width:100% !important;
  height:100% !important;
  min-height:240px !important;
  margin:0 !important;
  padding:16px 12px !important;
  color:#c8cdd3 !important;
  background:#3d434b !important;
  border:1px solid #2f343b !important;
  border-radius:12px !important;
  overflow:hidden !important;
  clip-path:none !important;
  -webkit-clip-path:none !important;
}
body.feed-page.feed-insta-ui .mf-feed > .mf-card .msb-no-image svg,
body.feed-page.feed-insta-ui .mf-feed > .mf-card .msb-media-unavailable svg{
  width:64px !important;
  height:64px !important;
  flex:0 0 64px !important;
  color:#c8cdd3 !important;
}
body.feed-page.feed-insta-ui .mf-feed > .mf-card .msb-no-image span,
body.feed-page.feed-insta-ui .mf-feed > .mf-card .msb-media-unavailable span{
  font-size:13px !important;
  font-weight:600 !important;
  letter-spacing:0 !important;
  line-height:1.25 !important;
  color:#c8cdd3 !important;
  text-align:center !important;
}
body.feed-page.feed-insta-ui .mf-feed > .mf-card .media-stage:has(> .msb-no-image),
body.feed-page.feed-insta-ui .mf-feed > .mf-card.mf-media-missing .media-stage{
  background:transparent !important;
}
body.feed-page.feed-insta-ui .mf-feed .mf-card.mf-media-missing > .mf-actions,
body.feed-page.feed-insta-ui .mf-feed .mf-card:has(.msb-no-image) > .mf-actions,
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .standard-media-bottom,
body.public-page.feed-insta-ui .post.public-post-card:has(.msb-no-image) .standard-media-bottom,
body.public-page.feed-insta-ui .post.public-post-card.mf-media-missing .standard-media-actions,
body.public-page.feed-insta-ui .post.public-post-card:has(.msb-no-image) .standard-media-actions{
  display:flex !important;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
}
</style>
<script>
(function(){
  var LABEL = <?= json_encode($label, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var INNER = <?= json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var CIRCLE_LABEL = <?= json_encode($circleLabel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var CIRCLE_INNER = <?= json_encode($circleInner, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  function usesUnavailablePlate(el){
    var body = document.body;
    if (!body) return false;
    if (body.classList.contains('reel-page')) return false;
    if (el && el.closest && el.closest('#profilePostsFeed')) return true;
    if (body.classList.contains('feed-page')) {
      return !el || !el.closest || !!el.closest('.mf-feed');
    }
    if (body.classList.contains('public-page')) return true;
    if (body.classList.contains('profile-page')) {
      return !el || !el.closest || !!el.closest('#profilePostsFeed');
    }
    return false;
  }
  function plateHtml(unavailable){
    var label = unavailable ? CIRCLE_LABEL : LABEL;
    var inner = unavailable ? CIRCLE_INNER : INNER;
    var cls = unavailable ? 'msb-no-image msb-media-unavailable' : 'msb-no-image';
    var style = unavailable
      ? 'border-radius:12px;overflow:hidden;background:#3d434b;'
      : 'border-radius:6px;overflow:hidden;background:#fff;clip-path:inset(0 round 6px);-webkit-clip-path:inset(0 round 6px)';
    return '<div class="'+cls+'" role="img" aria-label="'+label+'" style="'+style+'">'+inner+'</div>';
  }
  function markReady(node){
    if (!node || !node.closest) return;
    var stage = node.closest('.media-stage, .mf-media, .reel-stage');
    var card = node.closest('.mf-card, .public-post-card, article.post, .reel-slide');
    if (stage) stage.classList.add('mf-media-sized');
    if (!card) return;
    card.classList.add('mf-media-missing');
    card.classList.add('mf-frame-painted');
    card.classList.remove('mf-image-error', 'mf-video-error');
    if (card.classList.contains('reel-slide')) card.classList.add('reel-media-ready');
    if (card.classList.contains('is-single-image-post')) card.classList.add('mf-image-ready');
    if (card.classList.contains('is-single-video-post')) card.classList.add('mf-video-ready');
  }
  function inPostMedia(el){
    if (!el || !el.closest) return false;
    if (el.closest('.msb-no-image, .avatar-thumb, .mf-avatar, .shop-cover-missing, .org-shop-cover, .shop-tile-cover, .shop-cover')) return false;
    if (el.closest('.avatar-thumb, .standard-media-topbar .avatar, .mf-head .avatar, .mf-avatar')) return false;
    if (el.closest('.explore-tile, .explore-grid')) return false;
    if (document.body.classList.contains('reel-page') && el.closest('.reel-stage')) return false;
    return !!el.closest('.media-stage, .mf-media, .reel-stage, .media-slide, .mf-media-slide, .pv-media');
  }
  function clearFrameLock(el){
    if(!el) return;
    if(el.classList){
      el.classList.remove('msb-phone-frame', 'msb-tablet-frame');
    }
    if(!el.style) return;
    ['width','min-width','max-width','height','min-height','max-height','aspect-ratio','margin','margin-top','margin-left','margin-right'].forEach(function(p){
      try{ el.style.removeProperty(p); }catch(e){}
    });
    var stage = el.closest && el.closest('.media-stage, .mf-media');
    if(stage && stage.style){
      ['aspect-ratio','height','min-height','max-height','overflow'].forEach(function(p){
        try{ stage.style.removeProperty(p); }catch(e){}
      });
    }
  }
  function paintCirclePlate(wrap){
    if (!wrap) return;
    wrap.className = 'msb-no-image msb-media-unavailable';
    wrap.setAttribute('role', 'img');
    wrap.setAttribute('aria-label', CIRCLE_LABEL);
    wrap.style.borderRadius = '12px';
    wrap.style.overflow = 'hidden';
    wrap.style.background = '#3d434b';
    wrap.style.clipPath = '';
    wrap.style.webkitClipPath = '';
    wrap.innerHTML = CIRCLE_INNER;
    clearFrameLock(wrap);
  }
  function show(el){
    if (!el || el.__msbNoImg) return;
    if (el.classList && el.classList.contains('msb-no-image')) {
      if (usesUnavailablePlate(el) && !el.classList.contains('msb-media-unavailable')) paintCirclePlate(el);
      else clearFrameLock(el);
      markReady(el);
      return;
    }
    el.__msbNoImg = true;
    var unavailable = usesUnavailablePlate(el);
    var wrap = document.createElement('div');
    if (unavailable) {
      paintCirclePlate(wrap);
    } else {
      wrap.className = 'msb-no-image';
      wrap.setAttribute('role', 'img');
      wrap.setAttribute('aria-label', LABEL);
      wrap.style.borderRadius = '6px';
      wrap.style.overflow = 'hidden';
      wrap.style.background = '#fff';
      wrap.style.clipPath = 'inset(0 round 6px)';
      wrap.style.webkitClipPath = 'inset(0 round 6px)';
      wrap.innerHTML = INNER;
    }
    markReady(el);
    if (el.parentNode) el.parentNode.replaceChild(wrap, el);
    markReady(wrap);
  }
  window.MSBNoImage = {
    html: function(opts){
      var unavailable = opts === 'circle' || (opts && opts.variant === 'circle') || usesUnavailablePlate(null);
      return plateHtml(!!unavailable);
    },
    replace: show,
    markReady: markReady
  };
  document.addEventListener('error', function(e){
    var t = e.target;
    if (!t || !t.tagName) return;
    var n = t.tagName.toLowerCase();
    if (n !== 'img' && n !== 'video') return;
    if (!inPostMedia(t)) return;
    if (t.classList && (t.classList.contains('reel-image') || t.classList.contains('reel-video'))) return;
    if (t.closest && t.closest('.explore-tile')) return;
    if (document.body.classList.contains('reel-page') && t.closest && t.closest('.reel-stage')) return;
    if (n === 'video') {
      var code = (t.error && t.error.code) ? Number(t.error.code) : 0;
      if (code === 1) return;
      var retries = Number(t.dataset.msbMediaRetries || 0);
      if (retries < 1 && t.getAttribute('src')) {
        t.dataset.msbMediaRetries = '1';
        window.setTimeout(function(){
          try{ t.load(); }catch(err){}
        }, 160);
        return;
      }
    }
    show(t);
  }, true);
  function scan(root){
    var scope = root && root.querySelectorAll ? root : document;
    if (scope.classList && scope.classList.contains('msb-no-image')) markReady(scope);
    if (scope.querySelectorAll) {
      scope.querySelectorAll('.msb-no-image').forEach(function(ph){
        if (usesUnavailablePlate(ph) && !ph.classList.contains('msb-media-unavailable')) paintCirclePlate(ph);
        else clearFrameLock(ph);
        markReady(ph);
      });
    }
    var listRoot = scope.querySelectorAll ? scope : document;
    listRoot.querySelectorAll('.media-stage img, .media-stage video, .mf-media img, .mf-media video, .reel-stage img, .reel-stage video, .media-slide img, .media-slide video, .mf-media-slide img, .mf-media-slide video').forEach(function(el){
      if (!inPostMedia(el)) return;
      if (el.tagName !== 'IMG') return;
      if (el.classList && el.classList.contains('reel-image')) return;
      if (!(el.complete && el.naturalWidth === 0 && (el.currentSrc || el.getAttribute('src')))) return;
      if (el.dataset && el.dataset.msbScanPending === '1') return;
      if (el.dataset) el.dataset.msbScanPending = '1';
      window.requestAnimationFrame(function(){
        if (!el || !el.parentNode) return;
        if (el.naturalWidth > 0) return;
        if (!el.complete) return;
        show(el);
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ scan(document); });
  } else {
    scan(document);
  }
  if (typeof MutationObserver !== 'undefined') {
    var mo = new MutationObserver(function(muts){
      for (var i = 0; i < muts.length; i++) {
        var nodes = muts[i].addedNodes;
        for (var j = 0; j < nodes.length; j++) {
          var n = nodes[j];
          if (n && n.nodeType === 1) scan(n);
        }
      }
    });
    var start = function(){
      mo.observe(document.body, { childList: true, subtree: true });
    };
    if (document.body) start();
    else document.addEventListener('DOMContentLoaded', start);
  }
})();
</script>
        <?php
    }
}
