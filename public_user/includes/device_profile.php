<?php
declare(strict_types=1);

function device_profile_table_has_column(PDO $dbh, string $table, string $column): bool
{
    try {
        $st = $dbh->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $st->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function device_profile_ensure_post_columns(PDO $dbh): void
{
    try {
        if (!device_profile_table_has_column($dbh, 'public_posts', 'device_label')) {
            $dbh->exec("ALTER TABLE public_posts ADD COLUMN device_label VARCHAR(120) NOT NULL DEFAULT '' AFTER visibility");
        }
        if (!device_profile_table_has_column($dbh, 'public_posts', 'device_viewport')) {
            $dbh->exec("ALTER TABLE public_posts ADD COLUMN device_viewport VARCHAR(32) NOT NULL DEFAULT '' AFTER device_label");
        }
        if (!device_profile_table_has_column($dbh, 'public_posts', 'music_title')) {
            $dbh->exec("ALTER TABLE public_posts ADD COLUMN music_title VARCHAR(120) NOT NULL DEFAULT '' AFTER device_viewport");
        }
        if (!device_profile_table_has_column($dbh, 'public_posts', 'music_artist')) {
            $dbh->exec("ALTER TABLE public_posts ADD COLUMN music_artist VARCHAR(120) NOT NULL DEFAULT '' AFTER music_title");
        }
        if (!device_profile_table_has_column($dbh, 'public_posts', 'is_archived')) {
            $dbh->exec("ALTER TABLE public_posts ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_deleted");
        }
    } catch (Throwable $e) {
        // keep callers resilient
    }
}

function device_profile_ensure_live_columns(PDO $dbh): void
{
    try {
        if (!device_profile_table_has_column($dbh, 'user_video_lives', 'device_label')) {
            $dbh->exec("ALTER TABLE user_video_lives ADD COLUMN device_label VARCHAR(120) NOT NULL DEFAULT '' AFTER visibility");
        }
        if (!device_profile_table_has_column($dbh, 'user_video_lives', 'device_viewport')) {
            $dbh->exec("ALTER TABLE user_video_lives ADD COLUMN device_viewport VARCHAR(32) NOT NULL DEFAULT '' AFTER device_label");
        }
    } catch (Throwable $e) {
        // keep callers resilient
    }
}

function device_profile_normalize_label(string $label): string
{
    $label = trim(preg_replace('/\s+/u', ' ', $label));
    if ($label === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($label, 0, 120);
    }
    return substr($label, 0, 120);
}

function device_profile_normalize_viewport(string $viewport): string
{
    $viewport = trim($viewport);
    if ($viewport === '') {
        return '';
    }
    if (!preg_match('/^\d{2,5}x\d{2,5}(?:@\d(?:\.\d+)?x)?$/', $viewport)) {
        return '';
    }
    return substr($viewport, 0, 32);
}

function device_profile_guess_from_user_agent(string $userAgent, string $viewport = ''): string
{
    $ua = strtolower($userAgent);
    $width = 0;
    $height = 0;
    if (preg_match('/^(\d{2,5})x(\d{2,5})/', $viewport, $m)) {
        $width = (int)$m[1];
        $height = (int)$m[2];
    }
    $short = min($width, $height);
    $long = max($width, $height);

    if (strpos($ua, 'iphone') !== false) {
        $key = $short > 0 && $long > 0 ? ($short . 'x' . $long) : '';
        $map = device_profile_iphone_viewport_map();
        return $map[$key] ?? 'iPhone';
    }

    if (strpos($ua, 'ipad') !== false || (strpos($ua, 'macintosh') !== false && strpos($ua, 'mobile') !== false)) {
        $map = device_profile_ipad_viewport_map();
        $key = $short > 0 && $long > 0 ? ($short . 'x' . $long) : '';
        return $map[$key] ?? 'iPad';
    }

    if (strpos($ua, 'surface duo') !== false) {
        return 'Surface Duo';
    }
    if (strpos($ua, 'surface pro') !== false || ($short === 912 && $long === 1368)) {
        return 'Surface Pro';
    }
    if (strpos($ua, 'pixel 7') !== false) {
        return 'Pixel 7';
    }
    if (strpos($ua, 'pixel') !== false) {
        return 'Google Pixel';
    }
    if (strpos($ua, 'sm-g998') !== false || strpos($ua, 's20 ultra') !== false) {
        return 'Samsung Galaxy S20 Ultra';
    }
    if (strpos($ua, 'galaxy z fold') !== false || ($short === 344 && $long === 882)) {
        return 'Galaxy Z Fold';
    }
    if (strpos($ua, 'android') !== false) {
        if ($short >= 700) {
            return 'Android Tablet';
        }
        return 'Android Phone';
    }

    if ($short >= 900 || $long >= 1400) {
        return 'Desktop / Laptop';
    }
    if ($short >= 700) {
        return 'Tablet';
    }
    if ($short > 0) {
        return 'Phone';
    }
    return 'Desktop / Laptop';
}

function device_profile_read_from_request(): array
{
    $label = device_profile_normalize_label((string)($_POST['device_label'] ?? $_GET['device_label'] ?? ''));
    $viewport = device_profile_normalize_viewport((string)($_POST['device_viewport'] ?? $_GET['device_viewport'] ?? ''));

    if ($label === '') {
        $label = device_profile_guess_from_user_agent((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), $viewport);
    }

    return [
        'label' => $label,
        'viewport' => $viewport,
    ];
}

function device_profile_meta_suffix(?string $deviceLabel): string
{
    $deviceLabel = trim((string)$deviceLabel);
    return $deviceLabel !== '' ? (' · ' . $deviceLabel) : '';
}

function device_profile_ipad_viewport_map(): array
{
    return [
        '744x1133' => 'iPad mini (8.3")',
        '768x1024' => 'iPad (9.7" / 10.2")',
        '810x1080' => 'iPad (10.2")',
        '820x1180' => 'iPad Air / iPad (10.9")',
        '834x1112' => 'iPad Pro 10.5"',
        '834x1194' => 'iPad Air 11" / iPad Pro 11"',
        '1024x1366' => 'iPad Pro 12.9" / iPad Air 13"',
        '1032x1376' => 'iPad Pro 13"',
    ];
}

/**
 * CSS viewport (short x long) → iPhone model label.
 * Physical sizes reference: Apple spec sheets (H × W in inches).
 */
function device_profile_iphone_viewport_map(): array
{
    return [
        '320x568' => 'iPhone SE (1st Gen)',
        '375x667' => 'iPhone SE (2nd & 3rd Gen) / iPhone 6 / 7 / 8',
        '360x780' => 'iPhone 12 Mini / 13 Mini',
        '375x812' => 'iPhone X / XS / 11 Pro',
        '390x844' => 'iPhone 12 / 13 / 14 / 15 / 16 / 17 / 16e',
        '393x852' => 'iPhone 15 Pro / 16 Pro / 17 Pro',
        '402x874' => 'iPhone 16 Pro / 17 Pro',
        '414x736' => 'iPhone 6 / 7 / 8 Plus',
        '414x896' => 'iPhone XR / 11 / XS Max',
        '428x926' => 'iPhone 12 / 13 / 14 Pro Max',
        '430x932' => 'iPhone 14 Plus / 15 Plus / 16 Plus / 15 Pro Max / 16 Pro Max',
        '440x956' => 'iPhone 16 Pro Max / 17 Pro Max',
        '420x912' => 'iPhone 17 Air',
    ];
}

function device_profile_viewport_to_style(string $viewport): string
{
    if (!preg_match('/^(\d{2,5})x(\d{2,5})/', trim($viewport), $m)) {
        return '';
    }
    return '--device-ar-w:' . $m[1] . ';--device-ar-h:' . $m[2] . ';';
}

function device_profile_iphone_default_viewport(string $label): string
{
    $lower = strtolower(trim($label));
    if ($lower === '' || strpos($lower, 'iphone') === false) {
        return '390x844';
    }

    $rules = [
        'se (1st' => '320x568',
        '17 pro max' => '440x956',
        '17 air' => '420x912',
        '17 pro' => '393x852',
        '17' => '390x844',
        '16 pro max' => '440x956',
        '16 pro' => '402x874',
        '16 plus' => '430x932',
        '16e' => '390x844',
        '16' => '390x844',
        '15 pro max' => '430x932',
        '15 pro' => '393x852',
        '15 plus' => '430x932',
        '15' => '390x844',
        '14 pro max' => '428x926',
        '14 plus' => '430x932',
        '14 pro' => '393x852',
        '14' => '390x844',
        '13 pro max' => '428x926',
        '13 mini' => '360x780',
        '13 pro' => '390x844',
        '13' => '390x844',
        '12 pro max' => '428x926',
        '12 mini' => '360x780',
        '12 pro' => '390x844',
        '12' => '390x844',
        '11 pro max' => '414x896',
        '11 pro' => '375x812',
        '11' => '414x896',
        'xr' => '414x896',
        'xs max' => '414x896',
        'xs' => '375x812',
        ' x' => '375x812',
        '8 plus' => '414x736',
        '7 plus' => '414x736',
        '6s plus' => '414x736',
        '6 plus' => '414x736',
        'se (2nd' => '375x667',
        'se (3rd' => '375x667',
        '8' => '375x667',
        '7' => '375x667',
        '6s' => '375x667',
        '6' => '375x667',
    ];

    foreach ($rules as $needle => $viewport) {
        if (strpos($lower, $needle) !== false) {
            return $viewport;
        }
    }

    return '390x844';
}

function device_profile_label_from_viewport(string $viewport, string $fallback = ''): string
{
    $viewport = trim($viewport);
    if (!preg_match('/^(\d{2,5})x(\d{2,5})/', $viewport, $m)) {
        return $fallback;
    }
    $short = min((int)$m[1], (int)$m[2]);
    $long = max((int)$m[1], (int)$m[2]);
    $key = $short . 'x' . $long;

    $iphoneMap = device_profile_iphone_viewport_map();
    if (isset($iphoneMap[$key])) {
        return $iphoneMap[$key];
    }

    $ipadMap = device_profile_ipad_viewport_map();
    if (isset($ipadMap[$key])) {
        return $ipadMap[$key];
    }

    return $fallback;
}

function device_profile_is_tablet_label(string $label): bool
{
    $lower = strtolower(trim($label));
    if ($lower === '') {
        return false;
    }
    if (strpos($lower, 'iphone') !== false || strpos($lower, 'android phone') !== false || strpos($lower, 'pixel') !== false) {
        return false;
    }
    return strpos($lower, 'ipad') !== false
        || strpos($lower, 'android tablet') !== false
        || strpos($lower, 'samsung tablet') !== false
        || ($lower === 'tablet')
        || (strpos($lower, 'tablet') !== false && strpos($lower, 'laptop') === false);
}

function device_profile_card_meta(string $label, string $viewport): array
{
    $label = trim($label);
    $viewport = device_profile_normalize_viewport($viewport);
    $style = '';
    $phoneShot = false;
    $tabletShot = false;
    $w = 0;
    $h = 0;

    if (preg_match('/^(\d{2,5})x(\d{2,5})/', $viewport, $m)) {
        $w = (int)$m[1];
        $h = (int)$m[2];
        $short = min($w, $h);
        $long = max($w, $h);
        $style = '--device-ar-w:' . $w . ';--device-ar-h:' . $h . ';';
        if ($short <= 480 && ($long / max($short, 1)) >= 1.2) {
            $phoneShot = true;
        } elseif ($short > 480 && $short < 900) {
            $tabletShot = true;
        }
    }

    if (!$phoneShot) {
        $lower = strtolower($label);
        if (strpos($lower, 'iphone') !== false
            || strpos($lower, 'android phone') !== false
            || strpos($lower, 'pixel') !== false) {
            $phoneShot = true;
        }
    }

    if (!$phoneShot && !$tabletShot && device_profile_is_tablet_label($label)) {
        $tabletShot = true;
    }

    $deviceFrame = '';
    if ($w > 0 && $h > 0) {
        $short = min($w, $h);
        if ($phoneShot) {
            $deviceFrame = 'phone-shot';
        } elseif ($short < 900) {
            $deviceFrame = 'tablet-shot';
            $tabletShot = true;
        } else {
            $deviceFrame = 'desktop-shot';
        }
    } elseif ($phoneShot) {
        $deviceFrame = 'phone-shot';
    } elseif ($tabletShot) {
        $deviceFrame = 'tablet-shot';
    }

    if ($phoneShot) {
        $tabletShot = false;
    }

    if ($style === '' && $phoneShot) {
        $fallbackViewport = $viewport !== '' ? $viewport : device_profile_iphone_default_viewport($label);
        $style = device_profile_viewport_to_style($fallbackViewport);
    } elseif ($style === '' && $tabletShot) {
        $style = '--device-ar-w:834;--device-ar-h:1194;';
    }

    return [
        'phone_shot' => $phoneShot,
        'tablet_shot' => $tabletShot,
        'device_frame' => $deviceFrame,
        'style' => $style,
        'label' => $label,
        'viewport' => $viewport,
    ];
}

function device_profile_media_shape(string $type, string $filePath, string $thumbPath, int $attachmentCount): string
{
    if ($attachmentCount !== 1) {
        return '';
    }

    $baseDir = dirname(__DIR__);
    $type = strtolower(trim($type));
    $shapeClass = 'single-square';

    if ($type === 'video') {
        $posterPath = trim($thumbPath);
        $absPoster = $posterPath !== ''
            ? ($baseDir . '/' . ltrim(preg_replace('~^\./~', '', $posterPath), '/'))
            : '';
        if ($absPoster !== '' && is_file($absPoster)) {
            $size = @getimagesize($absPoster);
            if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                if ($size[1] > $size[0] * 1.1) {
                    $shapeClass = 'single-portrait';
                } elseif ($size[0] > $size[1] * 1.15) {
                    $shapeClass = 'single-landscape';
                }
            }
        } else {
            $shapeClass = 'single-landscape';
        }
        return $shapeClass;
    }

    if ($type !== 'image' && $type !== 'gif') {
        return '';
    }

    $srcForSize = trim($filePath);
    $abs = $srcForSize !== ''
        ? ($baseDir . '/' . ltrim(preg_replace('~^\./~', '', $srcForSize), '/'))
        : '';
    if ($abs === '' || !is_file($abs)) {
        return $shapeClass;
    }

    $size = @getimagesize($abs);
    if (!is_array($size) || empty($size[0]) || empty($size[1])) {
        return $shapeClass;
    }

    if ($size[1] > $size[0] * 1.1) {
        $shapeClass = 'single-portrait';
    } elseif ($size[0] > $size[1] * 1.15) {
        $shapeClass = 'single-landscape';
    }

    return $shapeClass;
}
