<?php
declare(strict_types=1);

require_once __DIR__ . '/app_languages.php';

function app_i18n_catalog(): array
{
    static $catalog = null;
    if (is_array($catalog)) {
        return $catalog;
    }
    $catalog = [];
    $dir = __DIR__ . '/locales';
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if ($code === '' || $code[0] === '_') {
                continue;
            }
            $map = include $file;
            if (is_array($map) && $code !== '') {
                $catalog[$code] = $map;
            }
        }
        $chromeFile = $dir . '/_chrome.php';
        if (is_file($chromeFile)) {
            $chromeAll = include $chromeFile;
            if (is_array($chromeAll)) {
                foreach ($chromeAll as $code => $chromeMap) {
                    if (!is_array($chromeMap) || $code === '') {
                        continue;
                    }
                    $existing = $catalog[$code] ?? [];
                    // Chrome last: Home/nav strings win over leftover English in Settings locale files.
                    $catalog[$code] = array_merge($existing, $chromeMap);
                }
            }
        }
    }
    return $catalog;
}

function app_i18n_use(string $language): void
{
    $language = app_language_normalize($language);
    $GLOBALS['msb_app_i18n_lang'] = app_language_html_tag($language);
    $GLOBALS['msbAppLangTag'] = app_language_html_tag($language);
    $GLOBALS['msbAppLangDir'] = app_language_dir($language);
}

function app_i18n_boot(?PDO $dbh = null, int $userId = 0): void
{
    $lang = trim((string)($_SESSION['app_language'] ?? ''));
    if ($lang === '' && $dbh instanceof PDO) {
        if ($userId <= 0) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if (function_exists('profile_session_owner_user_id')) {
                $owner = profile_session_owner_user_id();
                if ($owner > 0) {
                    $userId = $owner;
                }
            }
        }
        if ($userId > 0) {
            try {
                $st = $dbh->prepare('SELECT app_language FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
                $st->execute([':uid' => $userId]);
                $lang = trim((string)($st->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $lang = '';
            }
        }
    }
    if ($lang === '') {
        $lang = 'English';
    }
    $lang = app_language_normalize($lang);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['app_language'] = $lang;
    }
    app_i18n_use($lang);
    $GLOBALS['msbAppLangTag'] = app_language_html_tag($lang);
    $GLOBALS['msbAppLangDir'] = app_language_dir($lang);
}

function app_html_lang_attrs(string $extraClass = ''): string
{
    if (empty($GLOBALS['msbAppLangTag'])) {
        app_i18n_boot();
    }
    $lang = htmlspecialchars((string)($GLOBALS['msbAppLangTag'] ?? 'en'), ENT_QUOTES, 'UTF-8');
    $dir = htmlspecialchars((string)($GLOBALS['msbAppLangDir'] ?? 'ltr'), ENT_QUOTES, 'UTF-8');
    $out = 'lang="' . $lang . '" dir="' . $dir . '"';
    $class = trim($extraClass);
    if ($class !== '') {
        $out .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
    }
    return $out;
}

function app_t(string $text): string
{
    if ($text === '') {
        return '';
    }
    if (empty($GLOBALS['msb_app_i18n_lang'])) {
        app_i18n_boot();
    }
    $lang = (string)($GLOBALS['msb_app_i18n_lang'] ?? 'en');
    if ($lang === 'en' || $lang === '') {
        return $text;
    }
    $catalog = app_i18n_catalog();
    if (isset($catalog[$lang][$text])) {
        return (string)$catalog[$lang][$text];
    }
    $short = strtolower((string)strtok($lang, '-'));
    if ($short !== $lang && isset($catalog[$short][$text])) {
        return (string)$catalog[$short][$text];
    }
    foreach ($catalog as $code => $map) {
        if (strtolower((string)strtok((string)$code, '-')) === $short && isset($map[$text])) {
            return (string)$map[$text];
        }
    }
    return $text;
}

function app_t_map(array $options): array
{
    $out = [];
    foreach ($options as $key => $label) {
        $out[$key] = app_t((string)$label);
    }
    return $out;
}

function app_t_attr(string $text): string
{
    return htmlspecialchars(app_t($text), ENT_QUOTES, 'UTF-8');
}

function app_i18n_current_map(): array
{
    if (empty($GLOBALS['msb_app_i18n_lang'])) {
        app_i18n_boot();
    }
    $lang = (string)($GLOBALS['msb_app_i18n_lang'] ?? 'en');
    if ($lang === 'en' || $lang === '') {
        return [];
    }
    $catalog = app_i18n_catalog();
    $map = [];
    if (isset($catalog[$lang]) && is_array($catalog[$lang])) {
        $map = $catalog[$lang];
    }
    $short = strtolower((string)strtok($lang, '-'));
    if ($short !== $lang && isset($catalog[$short]) && is_array($catalog[$short])) {
        $map = array_merge($catalog[$short], $map);
    }
    return $map;
}

function app_i18n_language_display(): string
{
    $stored = trim((string)($_SESSION['app_language'] ?? 'English'));
    if ($stored === '') {
        $stored = 'English';
    }
    if (function_exists('app_language_label')) {
        $label = trim(app_language_label($stored));
        if ($label !== '' && preg_match('/^(.+?)\s+\(/u', $label, $m)) {
            return trim($m[1]);
        }
        if ($label !== '') {
            return $label;
        }
    }
    return $stored;
}

function app_i18n_print_js(): void
{
    if (!empty($GLOBALS['msb_i18n_js_printed'])) {
        return;
    }
    $GLOBALS['msb_i18n_js_printed'] = true;
    $json = json_encode(
        app_i18n_current_map(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
    );
    if (!is_string($json)) {
        $json = '{}';
    }
    echo '<script>window.MSB_I18N=' . $json . ';window.msbT=function(s){s=String(s==null?"":s);if(!s)return s;var d=window.MSB_I18N||{};return Object.prototype.hasOwnProperty.call(d,s)?String(d[s]):s;};</script>' . "\n";
}
