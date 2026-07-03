<?php
declare(strict_types=1);

/**
 * HTML named color palettes for Gear → Appearance (users + publishers).
 * Slugs are lowercase HTML color names; hex values match standard HTML colors.
 */

function appearance_palette_basics(): array
{
    return [
        'light' => 'Light',
        'dark' => 'Dark',
    ];
}

function appearance_palette_decorative(): array
{
    return [
        'Pink' => [
            'pink' => 'Pink',
            'lightpink' => 'LightPink',
            'hotpink' => 'HotPink',
            'deeppink' => 'DeepPink',
            'palevioletred' => 'PaleVioletRed',
            'mediumvioletred' => 'MediumVioletRed',
        ],
        'Red' => [
            'lightsalmon' => 'LightSalmon',
            'salmon' => 'Salmon',
            'darksalmon' => 'DarkSalmon',
            'lightcoral' => 'LightCoral',
            'indianred' => 'IndianRed',
            'crimson' => 'Crimson',
            'firebrick' => 'FireBrick',
            'darkred' => 'DarkRed',
            'red' => 'Red',
        ],
        'Orange' => [
            'orangered' => 'OrangeRed',
            'tomato' => 'Tomato',
            'coral' => 'Coral',
            'darkorange' => 'DarkOrange',
            'orange' => 'Orange',
        ],
        'Yellow' => [
            'yellow' => 'Yellow',
            'lightyellow' => 'LightYellow',
            'lemonchiffon' => 'LemonChiffon',
            'lightgoldenrodyellow' => 'LightGoldenrodYellow',
            'papayawhip' => 'PapayaWhip',
            'moccasin' => 'Moccasin',
            'peachpuff' => 'PeachPuff',
            'palegoldenrod' => 'PaleGoldenrod',
            'khaki' => 'Khaki',
            'darkkhaki' => 'DarkKhaki',
            'gold' => 'Gold',
        ],
        'Brown' => [
            'cornsilk' => 'Cornsilk',
            'blanchedalmond' => 'BlanchedAlmond',
            'bisque' => 'Bisque',
            'navajowhite' => 'NavajoWhite',
            'wheat' => 'Wheat',
            'burlywood' => 'BurlyWood',
            'tan' => 'Tan',
            'rosybrown' => 'RosyBrown',
            'sandybrown' => 'SandyBrown',
            'goldenrod' => 'Goldenrod',
            'darkgoldenrod' => 'DarkGoldenrod',
            'peru' => 'Peru',
            'chocolate' => 'Chocolate',
            'saddlebrown' => 'SaddleBrown',
            'sienna' => 'Sienna',
            'brown' => 'Brown',
            'maroon' => 'Maroon',
        ],
        'Green' => [
            'darkolivegreen' => 'DarkOliveGreen',
            'olive' => 'Olive',
            'olivedrab' => 'OliveDrab',
            'yellowgreen' => 'YellowGreen',
            'limegreen' => 'LimeGreen',
            'lime' => 'Lime',
            'lawngreen' => 'LawnGreen',
            'chartreuse' => 'Chartreuse',
            'greenyellow' => 'GreenYellow',
            'springgreen' => 'SpringGreen',
            'mediumspringgreen' => 'MediumSpringGreen',
            'lightgreen' => 'LightGreen',
            'palegreen' => 'PaleGreen',
            'darkseagreen' => 'DarkSeaGreen',
            'mediumseagreen' => 'MediumSeaGreen',
            'seagreen' => 'SeaGreen',
            'forestgreen' => 'ForestGreen',
            'green' => 'Green',
            'darkgreen' => 'DarkGreen',
        ],
        'Cyan' => [
            'mediumaquamarine' => 'MediumAquamarine',
            'aqua' => 'Aqua',
            'cyan' => 'Cyan',
            'lightcyan' => 'LightCyan',
            'paleturquoise' => 'PaleTurquoise',
            'aquamarine' => 'Aquamarine',
            'turquoise' => 'Turquoise',
            'mediumturquoise' => 'MediumTurquoise',
            'darkturquoise' => 'DarkTurquoise',
            'lightseagreen' => 'LightSeaGreen',
            'cadetblue' => 'CadetBlue',
            'darkcyan' => 'DarkCyan',
            'teal' => 'Teal',
        ],
        'Blue' => [
            'lightsteelblue' => 'LightSteelBlue',
            'powderblue' => 'PowderBlue',
            'lightblue' => 'LightBlue',
            'skyblue' => 'SkyBlue',
            'lightskyblue' => 'LightSkyBlue',
            'deepskyblue' => 'DeepSkyBlue',
            'dodgerblue' => 'DodgerBlue',
            'cornflowerblue' => 'CornflowerBlue',
            'steelblue' => 'SteelBlue',
            'royalblue' => 'RoyalBlue',
            'blue' => 'Blue',
            'mediumblue' => 'MediumBlue',
            'darkblue' => 'DarkBlue',
            'navy' => 'Navy',
            'midnightblue' => 'MidnightBlue',
        ],
        'Purple / Violet' => [
            'lavender' => 'Lavender',
            'thistle' => 'Thistle',
            'plum' => 'Plum',
            'violet' => 'Violet',
            'orchid' => 'Orchid',
            'fuchsia' => 'Fuchsia',
            'magenta' => 'Magenta',
            'mediumorchid' => 'MediumOrchid',
            'mediumpurple' => 'MediumPurple',
            'blueviolet' => 'BlueViolet',
            'darkviolet' => 'DarkViolet',
            'darkorchid' => 'DarkOrchid',
            'darkmagenta' => 'DarkMagenta',
            'purple' => 'Purple',
            'indigo' => 'Indigo',
            'darkslateblue' => 'DarkSlateBlue',
            'rebeccapurple' => 'RebeccaPurple',
            'slateblue' => 'SlateBlue',
            'mediumslateblue' => 'MediumSlateBlue',
        ],
        'White / Neutral' => [
            'white' => 'White',
            'snow' => 'Snow',
            'honeydew' => 'Honeydew',
            'mintcream' => 'MintCream',
            'azure' => 'Azure',
            'aliceblue' => 'AliceBlue',
            'ghostwhite' => 'GhostWhite',
            'whitesmoke' => 'WhiteSmoke',
            'seashell' => 'Seashell',
            'beige' => 'Beige',
            'oldlace' => 'OldLace',
            'floralwhite' => 'FloralWhite',
            'ivory' => 'Ivory',
            'antiquewhite' => 'AntiqueWhite',
            'linen' => 'Linen',
            'lavenderblush' => 'LavenderBlush',
            'mistyrose' => 'MistyRose',
        ],
        'Gray / Black' => [
            'gainsboro' => 'Gainsboro',
            'lightgray' => 'LightGray',
            'silver' => 'Silver',
            'darkgray' => 'DarkGray',
            'gray' => 'Gray',
            'dimgray' => 'DimGray',
            'lightslategray' => 'LightSlateGray',
            'slategray' => 'SlateGray',
            'darkslategray' => 'DarkSlateGray',
            'black' => 'Black',
        ],
    ];
}

function appearance_palette_hex_values(): array
{
    return [
        'pink' => '#FFC0CB', 'lightpink' => '#FFB6C1', 'hotpink' => '#FF69B4', 'deeppink' => '#FF1493',
        'palevioletred' => '#DB7093', 'mediumvioletred' => '#C71585',
        'lightsalmon' => '#FFA07A', 'salmon' => '#FA8072', 'darksalmon' => '#E9967A', 'lightcoral' => '#F08080',
        'indianred' => '#CD5C5C', 'crimson' => '#DC143C', 'firebrick' => '#B22222', 'darkred' => '#8B0000', 'red' => '#FF0000',
        'orangered' => '#FF4500', 'tomato' => '#FF6347', 'coral' => '#FF7F50', 'darkorange' => '#FF8C00', 'orange' => '#FFA500',
        'yellow' => '#FFFF00', 'lightyellow' => '#FFFFE0', 'lemonchiffon' => '#FFFACD', 'lightgoldenrodyellow' => '#FAFAD2',
        'papayawhip' => '#FFEFD5', 'moccasin' => '#FFE4B5', 'peachpuff' => '#FFDAB9', 'palegoldenrod' => '#EEE8AA',
        'khaki' => '#F0E68C', 'darkkhaki' => '#BDB76B', 'gold' => '#FFD700',
        'cornsilk' => '#FFF8DC', 'blanchedalmond' => '#FFEBCD', 'bisque' => '#FFE4C4', 'navajowhite' => '#FFDEAD',
        'wheat' => '#F5DEB3', 'burlywood' => '#DEB887', 'tan' => '#D2B48C', 'rosybrown' => '#BC8F8F', 'sandybrown' => '#F4A460',
        'goldenrod' => '#DAA520', 'darkgoldenrod' => '#B8860B', 'peru' => '#CD853F', 'chocolate' => '#D2691E',
        'saddlebrown' => '#8B4513', 'sienna' => '#A0522D', 'brown' => '#A52A2A', 'maroon' => '#800000',
        'darkolivegreen' => '#556B2F', 'olive' => '#808000', 'olivedrab' => '#6B8E23', 'yellowgreen' => '#9ACD32',
        'limegreen' => '#32CD32', 'lime' => '#00FF00', 'lawngreen' => '#7CFC00', 'chartreuse' => '#7FFF00',
        'greenyellow' => '#ADFF2F', 'springgreen' => '#00FF7F', 'mediumspringgreen' => '#00FA9A', 'lightgreen' => '#90EE90',
        'palegreen' => '#98FB98', 'darkseagreen' => '#8FBC8F', 'mediumseagreen' => '#3CB371', 'seagreen' => '#2E8B57',
        'forestgreen' => '#228B22', 'green' => '#008000', 'darkgreen' => '#006400',
        'mediumaquamarine' => '#66CDAA', 'aqua' => '#00FFFF', 'cyan' => '#00FFFF', 'lightcyan' => '#E0FFFF',
        'paleturquoise' => '#AFEEEE', 'aquamarine' => '#7FFFD4', 'turquoise' => '#40E0D0', 'mediumturquoise' => '#48D1CC',
        'darkturquoise' => '#00CED1', 'lightseagreen' => '#20B2AA', 'cadetblue' => '#5F9EA0', 'darkcyan' => '#008B8B', 'teal' => '#008080',
        'lightsteelblue' => '#B0C4DE', 'powderblue' => '#B0E0E6', 'lightblue' => '#ADD8E6', 'skyblue' => '#87CEEB',
        'lightskyblue' => '#87CEFA', 'deepskyblue' => '#00BFFF', 'dodgerblue' => '#1E90FF', 'cornflowerblue' => '#6495ED',
        'steelblue' => '#4682B4', 'royalblue' => '#4169E1', 'blue' => '#0000FF', 'mediumblue' => '#0000CD',
        'darkblue' => '#00008B', 'navy' => '#000080', 'midnightblue' => '#191970',
        'lavender' => '#E6E6FA', 'thistle' => '#D8BFD8', 'plum' => '#DDA0DD', 'violet' => '#EE82EE', 'orchid' => '#DA70D6',
        'fuchsia' => '#FF00FF', 'magenta' => '#FF00FF', 'mediumorchid' => '#BA55D3', 'mediumpurple' => '#9370DB',
        'blueviolet' => '#8A2BE2', 'darkviolet' => '#9400D3', 'darkorchid' => '#9932CC', 'darkmagenta' => '#8B008B',
        'purple' => '#800080', 'indigo' => '#4B0082', 'darkslateblue' => '#483D8B', 'rebeccapurple' => '#663399',
        'slateblue' => '#6A5ACD', 'mediumslateblue' => '#7B68EE',
        'white' => '#FFFFFF', 'snow' => '#FFFAFA', 'honeydew' => '#F0FFF0', 'mintcream' => '#F5FFFA', 'azure' => '#F0FFFF',
        'aliceblue' => '#F0F8FF', 'ghostwhite' => '#F8F8FF', 'whitesmoke' => '#F5F5F5', 'seashell' => '#FFF5EE',
        'beige' => '#F5F5DC', 'oldlace' => '#FDF5E6', 'floralwhite' => '#FFFAF0', 'ivory' => '#FFFFF0',
        'antiquewhite' => '#FAEBD7', 'linen' => '#FAF0E6', 'lavenderblush' => '#FFF0F5', 'mistyrose' => '#FFE4E1',
        'gainsboro' => '#DCDCDC', 'lightgray' => '#D3D3D3', 'silver' => '#C0C0C0', 'darkgray' => '#A9A9A9',
        'gray' => '#808080', 'dimgray' => '#696969', 'lightslategray' => '#778899', 'slategray' => '#708090',
        'darkslategray' => '#2F4F4F', 'black' => '#000000',
    ];
}

function appearance_palette_groups_for_select(): array
{
    $groups = [
        ['label' => 'Basics', 'options' => appearance_palette_basics()],
    ];
    foreach (appearance_palette_decorative() as $label => $options) {
        $groups[] = ['label' => $label, 'options' => $options];
    }
    return $groups;
}

function appearance_palette_all_slugs(): array
{
    $slugs = array_keys(appearance_palette_basics());
    foreach (appearance_palette_decorative() as $options) {
        $slugs = array_merge($slugs, array_keys($options));
    }
    return array_values(array_unique($slugs));
}

function appearance_palette_is_valid_slug(string $slug): bool
{
    $slug = strtolower(trim($slug));
    if ($slug === 'system') {
        return true;
    }
    if (in_array($slug, ['light', 'dark'], true)) {
        return true;
    }
    return array_key_exists($slug, appearance_palette_hex_values());
}

function appearance_palette_normalize_mode(string $mode): string
{
    $mode = strtolower(trim($mode));
    if ($mode === '' || $mode === 'system') {
        return 'system';
    }
    if (appearance_palette_is_valid_slug($mode)) {
        return $mode;
    }
    return 'system';
}

function appearance_palette_dark_hex(): string
{
    return '#171d24';
}

function appearance_palette_light_hex(): string
{
    return '#f5f7fb';
}

function appearance_palette_hex_for_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    if ($slug === 'light') {
        return appearance_palette_light_hex();
    }
    if ($slug === 'dark') {
        return appearance_palette_dark_hex();
    }
    return appearance_palette_hex_values()[$slug] ?? '#f5f7fb';
}

function appearance_palette_is_dark_slug(string $slug): bool
{
    $slug = strtolower(trim($slug));
    if ($slug === 'dark') {
        return true;
    }
    if ($slug === 'light') {
        return false;
    }

    static $forcedDark = [
        'black', 'midnightblue', 'navy', 'darkblue', 'mediumblue', 'indigo', 'darkslateblue',
        'darkslategray', 'darkgreen', 'maroon', 'darkred', 'purple', 'darkmagenta', 'darkviolet',
        'darkorchid', 'brown', 'saddlebrown', 'dimgray', 'gray', 'darkgray', 'olive', 'darkolivegreen',
        'teal', 'darkcyan', 'forestgreen', 'seagreen', 'rebeccapurple',
    ];
    if (in_array($slug, $forcedDark, true)) {
        return true;
    }

    $hex = appearance_palette_hex_for_slug($slug);
    if (!preg_match('/^#([0-9a-f]{6})$/i', $hex, $m)) {
        return false;
    }
    $r = hexdec(substr($m[1], 0, 2));
    $g = hexdec(substr($m[1], 2, 2));
    $b = hexdec(substr($m[1], 4, 2));
    $luma = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    $max = max($r, $g, $b);
    // Vivid hues (e.g. pure red) read as dark by luma alone but should use light chrome.
    if ($max > 180 && $luma < 0.5) {
        return false;
    }
    return $luma < 0.45;
}

function appearance_palette_uses_dark_chrome(string $slug): bool
{
    $slug = strtolower(trim($slug));
    if ($slug === 'dark') {
        return true;
    }
    if ($slug === 'light' || $slug === 'system') {
        return false;
    }
    return appearance_palette_is_dark_slug($slug);
}

function appearance_palette_js_map(): array
{
    $map = [];
    foreach (appearance_palette_hex_values() as $slug => $hex) {
        $map[$slug] = [
            'hex' => $hex,
            'dark' => appearance_palette_is_dark_slug($slug),
            'darkChrome' => appearance_palette_uses_dark_chrome($slug),
        ];
    }
    $map['light'] = ['hex' => '#f5f7fb', 'dark' => false, 'darkChrome' => false];
    $map['dark'] = ['hex' => appearance_palette_dark_hex(), 'dark' => true, 'darkChrome' => true];
    return $map;
}

function appearance_palette_ensure_schema(PDO $dbh): void
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
        $st = $dbh->query("SHOW COLUMNS FROM user_profile_settings LIKE 'appearance_mode'");
        $col = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($col && stripos((string)($col['Type'] ?? ''), 'enum') !== false) {
            $dbh->exec("ALTER TABLE user_profile_settings MODIFY appearance_mode VARCHAR(48) NOT NULL DEFAULT 'system'");
        }
    } catch (Throwable $e) {
        // non-fatal
    }
}
