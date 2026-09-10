<?php
declare(strict_types=1);

/**
 * App language picker for Settings (app_language).
 * Stored values are English names (legacy + current). Labels are native + English.
 */
function app_language_options(): array
{
    return [
        'Afrikaans' => 'Afrikaans',
        'Albanian' => 'Shqip (Albanian)',
        'Amharic' => 'አማርኛ (Amharic)',
        'Arabic' => 'العربية (Arabic)',
        'Armenian' => 'Հայերեն (Armenian)',
        'Azerbaijani' => 'Azərbaycan (Azerbaijani)',
        'Basque' => 'Euskara (Basque)',
        'Belarusian' => 'Беларуская (Belarusian)',
        'Bengali' => 'বাংলা (Bengali)',
        'Bosnian' => 'Bosanski (Bosnian)',
        'Bulgarian' => 'Български (Bulgarian)',
        'Burmese' => 'မြန်မာ (Burmese)',
        'Catalan' => 'Català (Catalan)',
        'Chinese (Simplified)' => '简体中文 (Chinese, Simplified)',
        'Chinese (Traditional)' => '繁體中文 (Chinese, Traditional)',
        'Croatian' => 'Hrvatski (Croatian)',
        'Czech' => 'Čeština (Czech)',
        'Danish' => 'Dansk (Danish)',
        'Dutch' => 'Nederlands (Dutch)',
        'English' => 'English',
        'Estonian' => 'Eesti (Estonian)',
        'Filipino' => 'Filipino',
        'Finnish' => 'Suomi (Finnish)',
        'French' => 'Français (French)',
        'Galician' => 'Galego (Galician)',
        'Georgian' => 'ქართული (Georgian)',
        'German' => 'Deutsch (German)',
        'Greek' => 'Ελληνικά (Greek)',
        'Gujarati' => 'ગુજરાતી (Gujarati)',
        'Haitian Creole' => 'Kreyòl ayisyen (Haitian Creole)',
        'Hausa' => 'Hausa',
        'Hebrew' => 'עברית (Hebrew)',
        'Hindi' => 'हिन्दी (Hindi)',
        'Hungarian' => 'Magyar (Hungarian)',
        'Icelandic' => 'Íslenska (Icelandic)',
        'Igbo' => 'Igbo',
        'Indonesian' => 'Bahasa Indonesia (Indonesian)',
        'Irish' => 'Gaeilge (Irish)',
        'Italian' => 'Italiano (Italian)',
        'Japanese' => '日本語 (Japanese)',
        'Javanese' => 'Basa Jawa (Javanese)',
        'Kannada' => 'ಕನ್ನಡ (Kannada)',
        'Kazakh' => 'Қазақ тілі (Kazakh)',
        'Khmer' => 'ខ្មែរ (Khmer)',
        'Korean' => '한국어 (Korean)',
        'Kurdish' => 'Kurdî (Kurdish)',
        'Lao' => 'ລາວ (Lao)',
        'Latvian' => 'Latviešu (Latvian)',
        'Lithuanian' => 'Lietuvių (Lithuanian)',
        'Macedonian' => 'Македонски (Macedonian)',
        'Malay' => 'Bahasa Melayu (Malay)',
        'Malayalam' => 'മലയാളം (Malayalam)',
        'Marathi' => 'मराठी (Marathi)',
        'Mongolian' => 'Монгол (Mongolian)',
        'Nepali' => 'नेपाली (Nepali)',
        'Norwegian' => 'Norsk (Norwegian)',
        'Pashto' => 'پښتو (Pashto)',
        'Persian' => 'فارسی (Persian)',
        'Polish' => 'Polski (Polish)',
        'Portuguese' => 'Português (Portuguese)',
        'Punjabi' => 'ਪੰਜਾਬੀ (Punjabi)',
        'Romanian' => 'Română (Romanian)',
        'Russian' => 'Русский (Russian)',
        'Serbian' => 'Српски (Serbian)',
        'Sign Language' => 'Sign Language',
        'Sinhala' => 'සිංහල (Sinhala)',
        'Slovak' => 'Slovenčina (Slovak)',
        'Slovenian' => 'Slovenščina (Slovenian)',
        'Somali' => 'Soomaali (Somali)',
        'Spanish' => 'Español (Spanish)',
        'Swahili' => 'Kiswahili (Swahili)',
        'Swedish' => 'Svenska (Swedish)',
        'Tamil' => 'தமிழ் (Tamil)',
        'Telugu' => 'తెలుగు (Telugu)',
        'Thai' => 'ไทย (Thai)',
        'Turkish' => 'Türkçe (Turkish)',
        'Ukrainian' => 'Українська (Ukrainian)',
        'Urdu' => 'اردو (Urdu)',
        'Uzbek' => 'Oʻzbek (Uzbek)',
        'Vietnamese' => 'Tiếng Việt (Vietnamese)',
        'Welsh' => 'Cymraeg (Welsh)',
        'Xhosa' => 'isiXhosa (Xhosa)',
        'Yoruba' => 'Yorùbá (Yoruba)',
        'Zulu' => 'isiZulu (Zulu)',
    ];
}

function app_language_values(): array
{
    return array_keys(app_language_options());
}

function app_language_bcp47_map(): array
{
    return [
        'Afrikaans' => 'af',
        'Albanian' => 'sq',
        'Amharic' => 'am',
        'Arabic' => 'ar',
        'Armenian' => 'hy',
        'Azerbaijani' => 'az',
        'Basque' => 'eu',
        'Belarusian' => 'be',
        'Bengali' => 'bn',
        'Bosnian' => 'bs',
        'Bulgarian' => 'bg',
        'Burmese' => 'my',
        'Catalan' => 'ca',
        'Chinese (Simplified)' => 'zh-Hans',
        'Chinese (Traditional)' => 'zh-Hant',
        'Croatian' => 'hr',
        'Czech' => 'cs',
        'Danish' => 'da',
        'Dutch' => 'nl',
        'English' => 'en',
        'Estonian' => 'et',
        'Filipino' => 'fil',
        'Finnish' => 'fi',
        'French' => 'fr',
        'Galician' => 'gl',
        'Georgian' => 'ka',
        'German' => 'de',
        'Greek' => 'el',
        'Gujarati' => 'gu',
        'Haitian Creole' => 'ht',
        'Hausa' => 'ha',
        'Hebrew' => 'he',
        'Hindi' => 'hi',
        'Hungarian' => 'hu',
        'Icelandic' => 'is',
        'Igbo' => 'ig',
        'Indonesian' => 'id',
        'Irish' => 'ga',
        'Italian' => 'it',
        'Japanese' => 'ja',
        'Javanese' => 'jv',
        'Kannada' => 'kn',
        'Kazakh' => 'kk',
        'Khmer' => 'km',
        'Korean' => 'ko',
        'Kurdish' => 'ku',
        'Lao' => 'lo',
        'Latvian' => 'lv',
        'Lithuanian' => 'lt',
        'Macedonian' => 'mk',
        'Malay' => 'ms',
        'Malayalam' => 'ml',
        'Marathi' => 'mr',
        'Mongolian' => 'mn',
        'Nepali' => 'ne',
        'Norwegian' => 'nb',
        'Pashto' => 'ps',
        'Persian' => 'fa',
        'Polish' => 'pl',
        'Portuguese' => 'pt',
        'Punjabi' => 'pa',
        'Romanian' => 'ro',
        'Russian' => 'ru',
        'Serbian' => 'sr',
        'Sign Language' => 'sgn',
        'Sinhala' => 'si',
        'Slovak' => 'sk',
        'Slovenian' => 'sl',
        'Somali' => 'so',
        'Spanish' => 'es',
        'Swahili' => 'sw',
        'Swedish' => 'sv',
        'Tamil' => 'ta',
        'Telugu' => 'te',
        'Thai' => 'th',
        'Turkish' => 'tr',
        'Ukrainian' => 'uk',
        'Urdu' => 'ur',
        'Uzbek' => 'uz',
        'Vietnamese' => 'vi',
        'Welsh' => 'cy',
        'Xhosa' => 'xh',
        'Yoruba' => 'yo',
        'Zulu' => 'zu',
    ];
}

function app_language_normalize(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'English';
    }
    $options = app_language_options();
    if (isset($options[$value])) {
        return $value;
    }
    $lower = strtolower($value);
    foreach ($options as $name => $_label) {
        if (strtolower($name) === $lower) {
            return $name;
        }
    }
    $code = strtolower(str_replace('_', '-', $value));
    if ($code === 'zh' || $code === 'zh-cn' || $code === 'zh-hans') {
        return 'Chinese (Simplified)';
    }
    if ($code === 'zh-tw' || $code === 'zh-hk' || $code === 'zh-hant') {
        return 'Chinese (Traditional)';
    }
    $flipped = array_change_key_case(array_flip(app_language_bcp47_map()), CASE_LOWER);
    if (isset($flipped[$code])) {
        return $flipped[$code];
    }
    return 'English';
}

function app_language_label(string $value): string
{
    $name = app_language_normalize($value);
    $options = app_language_options();
    return (string)($options[$name] ?? $name);
}

function app_language_html_tag(string $value): string
{
    $name = app_language_normalize($value);
    return (string)(app_language_bcp47_map()[$name] ?? 'en');
}

function app_language_dir(string $value): string
{
    $tag = app_language_html_tag($value);
    $rtl = ['ar', 'he', 'fa', 'ur', 'ps'];
    return in_array($tag, $rtl, true) ? 'rtl' : 'ltr';
}

function app_language_ensure_column(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
        if (!$chk || !$chk->fetchColumn()) {
            return;
        }
        $st = $dbh->query("SHOW COLUMNS FROM user_profile_settings LIKE 'app_language'");
        $info = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        $type = strtolower((string)($info['Type'] ?? ''));
        if ($type === '') {
            $dbh->exec("ALTER TABLE user_profile_settings ADD COLUMN app_language VARCHAR(80) NOT NULL DEFAULT 'English'");
            return;
        }
        if (strpos($type, 'enum') === 0 || (preg_match('/varchar\((\d+)\)/', $type, $m) && (int)$m[1] < 80)) {
            $dbh->exec("ALTER TABLE user_profile_settings MODIFY app_language VARCHAR(80) NOT NULL DEFAULT 'English'");
        }
    } catch (Throwable $e) {
        // Keep saving if ALTER is not allowed.
    }
}
