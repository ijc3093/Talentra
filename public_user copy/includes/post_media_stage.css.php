<?php
declare(strict_types=1);

/**
 * Post media stage sizing — shared with public.php .media-stage rules.
 * Pass scope ".mf-feed" for feed cards, ".ig-feed" for public page, etc.
 */
function post_media_stage_css(string $scope = ''): string
{
    $scope = trim($scope);
    $root = $scope !== '' ? $scope : '';
    $card = $root !== '' ? ($root . ' .mf-card') : '.post';
    $prefix = $root !== '' ? ($root . ' ') : '';

    $s = static function (string $selector) use ($prefix): string {
        return $prefix . $selector;
    };

    $lines = [];

    $lines[] = $card . '{--post-media-radius:18px;--post-media-max:680px;--post-phone-max:430px;--post-tablet-max:620px;--post-landscape-max:760px;--post-square-max:620px;--post-portrait-max:520px}';
    $lines[] = $card . '.is-single-video-post:not(.mf-card-phone-shot){width:min(100%,var(--post-media-card-width,var(--post-media-max)));max-width:100%;margin-left:auto;margin-right:auto}';
    $lines[] = $card . '.is-single-image-post:not(.mf-card-phone-shot){width:min(100%,var(--post-media-card-width,var(--post-media-max)));max-width:100%;margin-left:auto;margin-right:auto}';
    $lines[] = $card . '.is-multi-media-post{width:100%;max-width:100%;margin-left:auto;margin-right:auto}';

    $lines[] = $s('.media-stage{position:relative;background:transparent;overflow:hidden;border-radius:var(--post-media-radius)}');
    $lines[] = $s('.media-stage.has-carousel{max-height:min(82vh,900px)}');
    $lines[] = $s('.media-stage.has-carousel .media-carousel,') . $s('.media-stage.has-carousel .media-slides,') . $s('.media-stage.has-carousel .media-slide{height:100%}');
    $lines[] = $s('.media-stage.standard-video-stage{background:transparent;aspect-ratio:auto;max-height:none;overflow:visible;border-radius:var(--post-media-radius)}');
    $lines[] = $s('.media-stage.standard-image-stage{background:transparent;aspect-ratio:auto;max-height:none;overflow:visible;border-radius:var(--post-media-radius)}');
    $lines[] = $s('.media-stage video,') . $s('.media-stage img{display:block;width:100%;height:auto;max-height:840px;background:transparent}');
    $lines[] = $s('.media-stage.standard-video-stage > video{width:100%;height:auto;max-height:min(78svh,960px);background:transparent;border-radius:var(--post-media-radius);object-fit:contain;object-position:center center}');
    $lines[] = $s('.media-stage.standard-video-stage.single-portrait,') . $s('.media-stage.standard-video-stage.single-landscape,') . $s('.media-stage.standard-video-stage.single-square{aspect-ratio:auto;max-height:none;overflow:visible}');
    $lines[] = $s('.media-stage.standard-image-stage > img{width:100%;height:auto;max-height:min(78svh,960px);background:transparent;border-radius:var(--post-media-radius);object-fit:contain;object-position:center center}');
    $lines[] = $s('.media-stage video{object-fit:contain;object-position:center center}');
    $lines[] = $s('.media-stage img{object-fit:cover;object-position:center center}');

    $lines[] = $s('.single-portrait{aspect-ratio:9/13;max-height:850px;overflow:hidden}');
    $lines[] = $s('.single-portrait img,') . $s('.single-portrait video{height:100%;width:100%}');
    $lines[] = $s('.single-portrait img{object-fit:cover;object-position:center center}');
    $lines[] = $s('.single-portrait video{object-fit:contain;object-position:center center}');

    $lines[] = $s('.single-landscape{overflow:hidden}');
    $lines[] = $s('.single-landscape img,') . $s('.single-landscape video{height:100%;width:100%}');
    $lines[] = $s('.single-landscape img{object-fit:cover;object-position:center center}');
    $lines[] = $s('.single-landscape video{object-fit:contain;object-position:center center}');

    $lines[] = $s('.single-square{overflow:hidden}');
    $lines[] = $s('.single-square img,') . $s('.single-square video{height:100%;width:100%}');
    $lines[] = $s('.single-square img{object-fit:cover;object-position:center center}');
    $lines[] = $s('.single-square video{object-fit:contain;object-position:center center}');

    $lines[] = $s('.media-stage.phone-shot{width:min(72vw,var(--post-phone-max));max-width:100%;margin-inline:auto;overflow:hidden;max-height:min(78svh,900px);background:transparent;border-radius:28px;box-shadow:0 20px 44px rgba(0,0,0,.22);aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667)}');
    $lines[] = $s('.media-stage.phone-shot img,') . $s('.media-stage.phone-shot video{width:100%;height:100%;max-height:none}');
    $lines[] = $s('.media-stage.phone-shot img{object-fit:cover}');
    $lines[] = $s('.media-stage.phone-shot video{object-fit:contain}');
    $lines[] = $s('.media-stage.phone-shot.standard-video-stage{overflow:hidden;background:transparent;border-radius:28px;box-shadow:0 20px 44px rgba(0,0,0,.18);aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667);max-height:min(78svh,900px)}');
    $lines[] = $s('.media-stage.phone-shot.standard-video-stage > video{width:100%;height:100%;max-height:none;object-fit:contain;border-radius:0;background:transparent}');
    $lines[] = $s('.media-stage.phone-shot.standard-image-stage{overflow:hidden;aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667);background:transparent;border-radius:28px;box-shadow:0 20px 44px rgba(0,0,0,.18);max-height:min(78svh,900px)}');
    $lines[] = $s('.media-stage.phone-shot.standard-image-stage > img{width:100%;height:100%;max-height:none;object-fit:contain;border-radius:0;background:transparent}');
    $lines[] = $s('.media-stage.phone-shot.single-portrait,') . $s('.media-stage.phone-shot.single-landscape,') . $s('.media-stage.phone-shot.single-square{aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667)}');

    $lines[] = $s('.media-carousel{position:relative;width:100%;height:100%}');
    $lines[] = $s('.media-slides{display:flex;width:100%;height:100%;transition:transform .28s ease}');
    $lines[] = $s('.media-slide{flex:0 0 100%;width:100%;height:100%;background:transparent;display:flex;align-items:center;justify-content:center}');
    $lines[] = $s('.media-slide > img,') . $s('.media-slide > video{width:100%;height:100%;background:transparent}');
    $lines[] = $s('.media-slide > img{object-fit:cover;object-position:center center}');
    $lines[] = $s('.media-slide > video{object-fit:contain;object-position:center center}');
    $lines[] = $s('.media-stage.single-landscape .media-slide > img,') . $s('.media-stage.single-landscape .media-slide > video,') . $s('.media-stage.single-square .media-slide > img,') . $s('.media-stage.single-square .media-slide > video,') . $s('.media-stage.single-portrait .media-slide > img,') . $s('.media-stage.single-portrait .media-slide > video{height:100%}');

    $lines[] = '@media (max-width:767.98px){';
    $lines[] = '  ' . $card . '.is-single-video-post:not(.mf-card-phone-shot),' . $card . '.is-single-image-post:not(.mf-card-phone-shot),' . $card . '.is-multi-media-post{width:100%}';
    $lines[] = '  ' . $s('.media-stage.standard-video-stage > video{max-height:calc(100svh - 210px);border-radius:var(--post-media-radius)}');
    $lines[] = '  ' . $s('.media-stage.standard-image-stage > img{max-height:calc(100svh - 210px);border-radius:var(--post-media-radius)}');
    $lines[] = '}';

    $lines[] = '@media (min-width:768px) and (max-width:1199.98px){';
    $lines[] = '  ' . $card . '.is-single-video-post:not(.mf-card-phone-shot){width:min(100%,420px)}';
    $lines[] = '  ' . $card . '.is-single-image-post:not(.mf-card-phone-shot){width:min(100%,420px)}';
    $lines[] = '  ' . $card . '.is-multi-media-post{width:100%}';
    $lines[] = '  ' . $s('.media-stage.has-carousel{max-height:min(78vh,760px)}');
    $lines[] = '}';

    $lines[] = '@media (min-width:1200px){';
    $lines[] = '  ' . $card . '.is-single-video-post:not(.mf-card-phone-shot){width:min(100%,460px)}';
    $lines[] = '  ' . $card . '.is-single-image-post:not(.mf-card-phone-shot){width:min(100%,460px)}';
    $lines[] = '  ' . $card . '.is-multi-media-post{width:100%}';
    $lines[] = '  ' . $s('.media-stage.has-carousel{max-height:min(82vh,900px)}');
    $lines[] = '}';

    if (strpos($scope, 'mf-feed') !== false) {
        $lines[] = $s('.media-stage{background:transparent}');
        $lines[] = $s('.media-stage.standard-video-stage{background:transparent;aspect-ratio:auto;max-height:none;overflow:visible;border:0;border-radius:var(--post-media-radius)}');
        $lines[] = $s('.media-stage video,') . $s('.media-stage.standard-video-stage > video{background:transparent}');
        $lines[] = $s('.mf-media-shell{position:relative;width:100%}');
        $lines[] = $s('.mf-media-shell > .mf-media-top-actions{position:absolute;top:12px;right:12px;z-index:25;display:flex;align-items:center;gap:8px;pointer-events:none}');
        $lines[] = $s('.mf-media-shell > .mf-media-top-actions .mf-friend-btn{display:inline-flex;align-items:center;justify-content:center;pointer-events:auto;margin:0;box-shadow:0 4px 14px rgba(15,23,42,.24)}');
        $lines[] = $card . '.mf-card-reel .mf-media-shell > .mf-media-top-actions{top:14px;right:14px}';
        $lines[] = $s('.mf-feed .mf-head .mf-friend-btn.mf-media-follow-btn{display:none!important}');
        $lines[] = $s('.mf-feed .mf-card:has(.mf-media-shell) .mf-head .mf-friend-btn{display:none!important}');
        $lines[] = $s('.mf-media-shell > .mf-media-top-actions{display:flex!important}');
        $lines[] = $s('.mf-media-shell > .mf-media-top-actions .mf-friend-btn{display:inline-flex!important}');
        $lines[] = $s('.mf-media-shell:has(> .mf-head--on-media){display:grid!important;grid-template:1fr / 1fr;background:transparent!important}');
        if (strpos($scope, 'profilePostsFeed') !== false) {
            $lines[] = $s('.mf-card:has(.mf-head--on-media){padding:8px 40px!important;box-sizing:border-box!important}');
            $lines[] = $s('.mf-card:has(.mf-head--on-media) > .mf-actions{padding:10px 0 8px!important}');
        }
        $pubCard = $card . '[data-is-publisher="1"]';
        $lines[] = $pubCard . ' .mf-media-shell:has(> .mf-head--on-media) > .mf-media,'
            . $pubCard . ' .mf-media-shell:has(> .mf-head--on-media) > .media-stage,'
            . $pubCard . ' .mf-media-shell:has(> .mf-head--on-media) > .mf-head--on-media,'
            . $pubCard . ' .mf-media-shell:has(> .mf-head--on-media) > .mf-media-top-actions,'
            . $pubCard . ' .mf-media-shell:has(> .standard-media-bottom) > .standard-media-bottom{grid-area:1 / 1}';
        $lines[] = $s('.mf-media-shell:has(> .mf-head--on-media) > .mf-media,') . $s('.mf-media-shell:has(> .mf-head--on-media) > .media-stage{width:100%!important;max-width:100%!important;margin:0!important;padding:0!important;background:transparent!important}');
        $lines[] = $s('.mf-media-shell > .mf-head--on-media{position:relative!important;align-self:start!important;justify-self:stretch!important;z-index:25!important;display:flex!important;align-items:center!important;gap:12px!important;padding:2px 6px 14px!important;box-sizing:border-box!important;width:100%!important;pointer-events:none;background:transparent!important;margin:0!important}');
        $lines[] = $s('.mf-media-shell > .mf-head--on-media .mf-peer-link,') . $s('.mf-media-shell > .mf-head--on-media .mf-menu-wrap,') . $s('.mf-media-shell > .mf-head--on-media .post-card-menu-wrap,') . $s('.mf-media-shell > .mf-head--on-media .post-card-menu-btn{pointer-events:auto;background:transparent!important}');
        $lines[] = $s('.mf-media-shell > .mf-head--on-media .mf-name,') . $s('.mf-media-shell > .mf-head--on-media .mf-time,') . $s('.mf-media-shell > .mf-head--on-media .mf-dot,') . $s('.mf-media-shell > .mf-head--on-media .mf-menu-btn{color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.34)}');
        $lines[] = $s('.mf-media-shell > .mf-head--on-media .mf-avatar img{border-color:#fff}');
        $lines[] = $s('.mf-media-shell:has(.mf-head--on-media) > .mf-media-top-actions{align-self:start!important;justify-self:end!important;position:relative!important;top:12px!important;right:calc(14px + 34px + 8px)!important;z-index:40!important}');

        // Publisher only: action bar overlay on media.
        $lines[] = $pubCard . ' .mf-media-shell > .standard-media-bottom{align-self:end;justify-self:stretch;position:absolute;left:0;right:0;bottom:0;z-index:12;pointer-events:none;padding:12px 14px 6px;background:none;color:#fff}';
        $lines[] = $pubCard . ' .mf-media-shell > .standard-media-bottom .mf-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0;margin:0;border-top:0;pointer-events:auto}';
        $lines[] = $pubCard . ' .mf-media-shell > .standard-media-bottom .mf-act,'
            . $pubCard . ' .mf-media-shell > .standard-media-bottom .mf-act i,'
            . $pubCard . ' .mf-media-shell > .standard-media-bottom .mf-act .mf-num{color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.55)}';
        $lines[] = $pubCard . ' .mf-media-shell > .standard-media-bottom .mf-act.is-love i{color:#ef2b7b!important;text-shadow:none!important}';
        $lines[] = $pubCard . ' .mf-media-shell > .standard-media-bottom .mf-act.is-like i{color:#2563eb!important;text-shadow:none!important}';
        $lines[] = $pubCard . ' .mf-media-shell > .standard-media-bottom .mf-act.is-share i{color:#9ca3af!important;text-shadow:none!important}';
        $lines[] = $pubCard . ' .mf-media-shell > .standard-media-bottom .mf-act.is-save i{color:#f59e0b!important;text-shadow:none!important}';

        // Mobile only: show the iPhone device frame for phone-origin posts.
        $lines[] = '@media (max-width:767.98px){';
        $lines[] = '  ' . $s('.mf-card-phone-shot .media-stage.phone-shot{width:min(72vw,var(--post-phone-max));max-width:100%;margin-inline:auto;border-radius:28px;overflow:hidden;box-shadow:none;max-height:min(78svh,900px);aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667);background:transparent}');
        $lines[] = '  ' . $s('.mf-card-phone-shot .media-stage.phone-shot.standard-video-stage{overflow:hidden;aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667);background:transparent;border:0;border-radius:28px;box-shadow:none}');
        $lines[] = '  ' . $s('.mf-card-phone-shot .media-stage.phone-shot.standard-video-stage > video{width:100%;height:100%;max-height:none;object-fit:contain;background:transparent;border-radius:0}');
        $lines[] = '  ' . $s('.mf-card-phone-shot .media-stage.phone-shot.standard-image-stage{overflow:hidden;aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667);background:transparent;border-radius:28px;box-shadow:none}');
        $lines[] = '  ' . $s('.mf-card-phone-shot .media-stage.phone-shot.standard-image-stage > img{width:100%;height:100%;max-height:none;object-fit:contain;background:transparent;border-radius:0}');
        $lines[] = '  ' . $card . '.mf-card-phone-shot.is-single-video-post{width:auto;max-width:100%;margin-inline:auto}';
        $lines[] = '  ' . $card . '.mf-card-phone-shot.is-single-image-post{width:auto;max-width:100%;margin-inline:auto}';
        $lines[] = '  ' . $card . '.mf-card-phone-shot:not(.is-multi-media-post):not(.mf-card-reel){width:auto;max-width:100%;margin-inline:auto}';
        $lines[] = '}';

        // Desktop/tablet: device label stays in the header; media uses feed width + file aspect ratio.
        $lines[] = '@media (min-width:768px){';
        $lines[] = '  ' . $card . '.mf-card-phone-shot.is-single-video-post,' . $card . '.mf-card-phone-shot.is-single-image-post{width:min(100%,var(--post-media-card-width,var(--post-media-max)));max-width:100%;margin-inline:auto}';
        $lines[] = '  ' . $card . '.mf-card-phone-shot:not(.is-multi-media-post):not(.mf-card-reel){width:min(100%,var(--post-media-card-width,var(--post-media-max)));max-width:100%;margin-inline:auto}';
        $lines[] = '  ' . $s('.mf-card-phone-shot .media-stage.phone-shot{width:100%;max-width:100%;margin-inline:0;border-radius:var(--post-media-radius);box-shadow:none;aspect-ratio:auto;max-height:none;overflow:visible;background:transparent}');
        $lines[] = '  ' . $s('.mf-card-phone-shot .media-stage.phone-shot.standard-video-stage,'). $s('.mf-card-phone-shot .media-stage.phone-shot.standard-image-stage{aspect-ratio:auto;overflow:visible;border-radius:var(--post-media-radius);max-height:none;background:transparent;border:0;box-shadow:none}');
        $lines[] = '  ' . $s('.mf-card-phone-shot .media-stage.phone-shot.standard-video-stage > video,'). $s('.mf-card-phone-shot .media-stage.phone-shot.standard-image-stage > img{width:100%;height:auto;max-height:min(78svh,960px);object-fit:contain;background:transparent;border-radius:var(--post-media-radius)}');
        $lines[] = '}';
    }

    return implode("\n", $lines);
}
