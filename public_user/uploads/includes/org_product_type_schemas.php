<?php
declare(strict_types=1);

/**
 * Shared New / Used choices for resale-friendly product types.
 *
 * @return list<string>
 */
function org_product_type_condition_options(): array
{
    return [
        'New',
        'Used',
        'Used but excellent',
        'Used but good',
    ];
}

/**
 * Platform product-type schemas — drives dynamic PIM fields per selling type.
 *
 * @return array<string, array{label:string,aliases:list<string>,fields:list<array<string,mixed>>}>
 */
function org_product_type_schema_catalog(): array
{
    $condition = [
        'key' => 'condition',
        'label' => 'Condition',
        'type' => 'select',
        'required' => true,
        'options' => org_product_type_condition_options(),
    ];

    return [
        'car' => [
            'label' => 'Car',
            'aliases' => ['car', 'cars', 'automobile', 'vehicle', 'auto'],
            'fields' => [
                ['key' => 'make', 'label' => 'Make', 'type' => 'text', 'required' => true, 'placeholder' => 'Toyota'],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => true, 'placeholder' => 'Camry'],
                ['key' => 'year', 'label' => 'Year', 'type' => 'number', 'min' => 1900, 'max' => 2100, 'placeholder' => '2024'],
                $condition,
                ['key' => 'mileage', 'label' => 'Mileage', 'type' => 'number', 'min' => 0, 'unit' => 'mi', 'placeholder' => '45000'],
                ['key' => 'fuel_type', 'label' => 'Fuel', 'type' => 'select', 'options' => ['Gasoline', 'Diesel', 'Electric', 'Hybrid', 'Other']],
                ['key' => 'transmission', 'label' => 'Transmission', 'type' => 'select', 'options' => ['Automatic', 'Manual', 'CVT', 'Other']],
                ['key' => 'color', 'label' => 'Exterior color', 'type' => 'text', 'placeholder' => 'Silver'],
                ['key' => 'vin', 'label' => 'VIN (optional)', 'type' => 'text', 'maxlength' => 32],
            ],
        ],
        'mobile' => [
            'label' => 'Mobile / Phone',
            'aliases' => ['mobile', 'phone', 'smartphone', 'cell', 'cellphone', 'iphone', 'android'],
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'required' => true, 'placeholder' => 'Apple'],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => true, 'placeholder' => 'iPhone 15'],
                $condition,
                ['key' => 'storage_gb', 'label' => 'Storage (GB)', 'type' => 'select', 'options' => ['32', '64', '128', '256', '512', '1024']],
                ['key' => 'ram_gb', 'label' => 'RAM (GB)', 'type' => 'select', 'options' => ['2', '3', '4', '6', '8', '12', '16']],
                ['key' => 'screen_inches', 'label' => 'Screen size (in)', 'type' => 'text', 'placeholder' => '6.1'],
                ['key' => 'color', 'label' => 'Color', 'type' => 'text', 'placeholder' => 'Black'],
            ],
        ],
        'laptop' => [
            'label' => 'Laptop / Computer',
            'aliases' => ['laptop', 'computer', 'notebook', 'pc', 'macbook'],
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'required' => true, 'placeholder' => 'Dell'],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => true, 'placeholder' => 'XPS 15'],
                $condition,
                ['key' => 'processor', 'label' => 'Processor', 'type' => 'text', 'placeholder' => 'Intel i7'],
                ['key' => 'ram_gb', 'label' => 'RAM (GB)', 'type' => 'select', 'options' => ['4', '8', '16', '32', '64']],
                ['key' => 'storage_gb', 'label' => 'Storage (GB)', 'type' => 'select', 'options' => ['128', '256', '512', '1024', '2048']],
                ['key' => 'screen_inches', 'label' => 'Screen size (in)', 'type' => 'text', 'placeholder' => '15.6'],
            ],
        ],
        'shirt' => [
            'label' => 'Shirt / Apparel',
            'aliases' => ['shirt', 't-shirt', 'tshirt', 'tee', 'blouse', 'top', 'hoodie', 'sweater'],
            'fields' => [
                ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'required' => true, 'options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL']],
                ['key' => 'color', 'label' => 'Color', 'type' => 'text', 'required' => true, 'placeholder' => 'Navy'],
                $condition,
                ['key' => 'material', 'label' => 'Material', 'type' => 'text', 'placeholder' => 'Cotton'],
                ['key' => 'fit', 'label' => 'Fit', 'type' => 'select', 'options' => ['Regular', 'Slim', 'Relaxed', 'Oversized']],
                ['key' => 'gender', 'label' => 'Style', 'type' => 'select', 'options' => ['Men', 'Women', 'Unisex', 'Kids']],
            ],
        ],
        'cup' => [
            'label' => 'Cup / Drinkware',
            'aliases' => ['cup', 'mug', 'glass', 'bottle', 'drinkware', 'bowl', 'plate', 'dish'],
            'fields' => [
                ['key' => 'capacity', 'label' => 'Capacity', 'type' => 'text', 'placeholder' => '12 oz'],
                ['key' => 'material', 'label' => 'Material', 'type' => 'select', 'options' => ['Ceramic', 'Glass', 'Plastic', 'Stainless steel', 'Paper', 'Other']],
                ['key' => 'color', 'label' => 'Color', 'type' => 'text', 'placeholder' => 'White'],
                $condition,
                ['key' => 'dishwasher_safe', 'label' => 'Dishwasher safe', 'type' => 'select', 'options' => ['Yes', 'No', 'Top rack only']],
            ],
        ],
        'tv' => [
            'label' => 'TV',
            'aliases' => ['tv', 'television', 'smart tv', 'smarttv', 'oled', 'qled', 'led tv', 'android tv', 'google tv', 'roku tv'],
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'required' => true, 'placeholder' => 'Samsung'],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'placeholder' => 'QN90'],
                $condition,
                ['key' => 'screen_inches', 'label' => 'Screen size (in)', 'type' => 'number', 'min' => 10, 'max' => 200, 'required' => true, 'placeholder' => '55'],
                ['key' => 'resolution', 'label' => 'Resolution', 'type' => 'select', 'options' => ['HD (720p)', 'Full HD (1080p)', '4K', '8K']],
                ['key' => 'display_type', 'label' => 'Display type', 'type' => 'select', 'options' => ['LED', 'QLED', 'OLED', 'Mini-LED', 'Plasma', 'Other']],
                ['key' => 'refresh_rate_hz', 'label' => 'Refresh rate (Hz)', 'type' => 'select', 'options' => ['50', '60', '90', '120', '144', '240']],
                ['key' => 'smart_os', 'label' => 'Smart OS', 'type' => 'select', 'options' => ['Tizen', 'webOS', 'Android TV', 'Google TV', 'Roku', 'Fire TV', 'Other']],
            ],
        ],
        'bed' => [
            'label' => 'Bed',
            'aliases' => ['bed', 'mattress', 'bed frame', 'bedframe', 'bunk bed', 'queen bed', 'king bed'],
            'fields' => [
                ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'required' => true, 'options' => ['Twin', 'Twin XL', 'Full', 'Queen', 'King', 'California King']],
                ['key' => 'item_type', 'label' => 'Item type', 'type' => 'select', 'options' => ['Bed frame', 'Mattress', 'Frame + Mattress', 'Headboard', 'Other']],
                $condition,
                ['key' => 'material', 'label' => 'Material', 'type' => 'select', 'options' => ['Wood', 'Metal', 'Upholstered', 'Bamboo', 'Other']],
                ['key' => 'color', 'label' => 'Color', 'type' => 'text', 'placeholder' => 'Gray'],
                ['key' => 'dimensions', 'label' => 'Dimensions (optional)', 'type' => 'text', 'placeholder' => '80 x 60 x 14 in'],
            ],
        ],
        'shoe' => [
            'label' => 'Shoe',
            'aliases' => ['shoe', 'shoes', 'sneaker', 'sneakers', 'boot', 'boots', 'heel', 'heels', 'sandal', 'sandals', 'footwear'],
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'placeholder' => 'Nike'],
                ['key' => 'size', 'label' => 'Size', 'type' => 'text', 'required' => true, 'placeholder' => '9'],
                ['key' => 'size_unit', 'label' => 'Size system', 'type' => 'select', 'options' => ['US', 'UK', 'EU', 'CM', 'Other']],
                $condition,
                ['key' => 'gender', 'label' => 'Style', 'type' => 'select', 'options' => ['Men', 'Women', 'Unisex', 'Kids']],
                ['key' => 'color', 'label' => 'Color', 'type' => 'text', 'placeholder' => 'White'],
                ['key' => 'material', 'label' => 'Material', 'type' => 'text', 'placeholder' => 'Leather'],
            ],
        ],
        'trouser' => [
            'label' => 'Trouser',
            'aliases' => ['trouser', 'trousers', 'pant', 'pants', 'jean', 'jeans', 'chino', 'chinos', 'slacks'],
            'fields' => [
                ['key' => 'waist', 'label' => 'Waist', 'type' => 'text', 'required' => true, 'placeholder' => '32'],
                ['key' => 'inseam', 'label' => 'Inseam', 'type' => 'text', 'placeholder' => '32'],
                $condition,
                ['key' => 'fit', 'label' => 'Fit', 'type' => 'select', 'options' => ['Skinny', 'Slim', 'Regular', 'Relaxed', 'Straight', 'Bootcut', 'Tapered']],
                ['key' => 'color', 'label' => 'Color', 'type' => 'text', 'required' => true, 'placeholder' => 'Black'],
                ['key' => 'material', 'label' => 'Material', 'type' => 'text', 'placeholder' => 'Denim'],
                ['key' => 'gender', 'label' => 'Style', 'type' => 'select', 'options' => ['Men', 'Women', 'Unisex', 'Kids']],
            ],
        ],
        'bag' => [
            'label' => 'Bag',
            'aliases' => ['bag', 'bags', 'backpack', 'back pack', 'handbag', 'purse', 'wallet', 'duffel', 'suitcase', 'luggage'],
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'placeholder' => 'Coach'],
                ['key' => 'bag_type', 'label' => 'Bag type', 'type' => 'select', 'required' => true, 'options' => ['Backpack', 'Handbag', 'Crossbody', 'Tote', 'Duffel', 'Suitcase', 'Wallet', 'Other']],
                $condition,
                ['key' => 'material', 'label' => 'Material', 'type' => 'text', 'placeholder' => 'Leather'],
                ['key' => 'color', 'label' => 'Color', 'type' => 'text', 'placeholder' => 'Brown'],
                ['key' => 'dimensions', 'label' => 'Size / dimensions (optional)', 'type' => 'text', 'placeholder' => '16 x 12 x 6 in'],
            ],
        ],
        'lotion' => [
            'label' => 'Lotion',
            'aliases' => ['lotion', 'cream', 'moisturizer', 'moisturiser', 'skincare', 'body lotion', 'hand cream', 'sunscreen', 'balm'],
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'required' => true, 'placeholder' => 'CeraVe'],
                ['key' => 'product_name', 'label' => 'Product name', 'type' => 'text', 'placeholder' => 'Daily Moisturizing Lotion'],
                ['key' => 'volume', 'label' => 'Size / volume', 'type' => 'text', 'required' => true, 'placeholder' => '12 oz'],
                $condition,
                ['key' => 'skin_type', 'label' => 'Skin type', 'type' => 'select', 'options' => ['All', 'Dry', 'Oily', 'Combination', 'Sensitive', 'Normal']],
                ['key' => 'use_area', 'label' => 'Use on', 'type' => 'select', 'options' => ['Face', 'Body', 'Hands', 'Feet', 'Hair', 'Other']],
                ['key' => 'scent', 'label' => 'Scent', 'type' => 'text', 'placeholder' => 'Unscented'],
                ['key' => 'spf', 'label' => 'SPF (optional)', 'type' => 'text', 'placeholder' => '30'],
            ],
        ],
        'color' => [
            'label' => 'Color',
            'aliases' => ['color', 'colour', 'paint', 'hair color', 'hair colour', 'hair dye', 'dye', 'nail polish', 'nail colour'],
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'required' => true, 'placeholder' => 'Sherwin-Williams'],
                ['key' => 'shade', 'label' => 'Shade / color name', 'type' => 'text', 'required' => true, 'placeholder' => 'Navy Blue'],
                ['key' => 'color_type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => ['Paint', 'Hair color', 'Nail polish', 'Fabric dye', 'Other']],
                $condition,
                ['key' => 'finish', 'label' => 'Finish', 'type' => 'select', 'options' => ['Matte', 'Satin', 'Gloss', 'Semi-gloss', 'Eggshell', 'Metallic', 'Other']],
                ['key' => 'volume', 'label' => 'Size / volume', 'type' => 'text', 'placeholder' => '1 gallon'],
                ['key' => 'base', 'label' => 'Base (optional)', 'type' => 'text', 'placeholder' => 'Water-based'],
            ],
        ],
        'generic' => [
            'label' => 'General product',
            'aliases' => ['generic', 'other', 'item', 'product'],
            'fields' => [
                $condition,
                ['key' => 'brand', 'label' => 'Brand (optional)', 'type' => 'text'],
                ['key' => 'model', 'label' => 'Model / variant (optional)', 'type' => 'text'],
                ['key' => 'color', 'label' => 'Color (optional)', 'type' => 'text'],
                ['key' => 'dimensions', 'label' => 'Size / dimensions (optional)', 'type' => 'text', 'placeholder' => '10 x 5 x 2 in'],
            ],
        ],
    ];
}

/**
 * Platform default labels for the "What things are you selling" dropdown.
 *
 * @return list<string>
 */
function org_product_type_platform_selling_labels(): array
{
    $labels = [];
    foreach (org_product_type_schema_catalog() as $slug => $def) {
        if ($slug === 'generic') {
            continue;
        }
        $label = trim((string)($def['label'] ?? ''));
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    return $labels;
}

/** Resolve seller label ("Car", "Mobile phone") to schema slug. */
function org_product_type_resolve_slug(string $sellingType): string
{
    $raw = mb_strtolower(trim($sellingType));
    if ($raw === '') {
        return '';
    }
    $catalog = org_product_type_schema_catalog();
    foreach ($catalog as $slug => $def) {
        if ($raw === $slug) {
            return $slug;
        }
        foreach ($def['aliases'] as $alias) {
            if ($raw === mb_strtolower($alias)) {
                return $slug;
            }
        }
    }
    // Partial match: prefer longest alias ("hair color" before "color")
    $bestSlug = '';
    $bestLen = 0;
    foreach ($catalog as $slug => $def) {
        if ($slug === 'generic') {
            continue;
        }
        foreach ($def['aliases'] as $alias) {
            $aliasLower = mb_strtolower($alias);
            $len = mb_strlen($aliasLower);
            if ($len > $bestLen && str_contains($raw, $aliasLower)) {
                $bestSlug = $slug;
                $bestLen = $len;
            }
        }
    }
    return $bestSlug !== '' ? $bestSlug : 'generic';
}

/**
 * @return array{slug:string,label:string,fields:list<array<string,mixed>>}|null
 */
function org_product_type_schema_for_selling_type(string $sellingType): ?array
{
    $slug = org_product_type_resolve_slug($sellingType);
    if ($slug === '') {
        return null;
    }
    $catalog = org_product_type_schema_catalog();
    $def = $catalog[$slug] ?? $catalog['generic'];
    return [
        'slug' => $slug,
        'label' => (string)$def['label'],
        'fields' => $def['fields'],
    ];
}

/**
 * @param array<string, mixed> $raw
 * @return array<string, string>
 */
function org_product_type_normalize_attributes(array $raw, string $sellingType): array
{
    $schema = org_product_type_schema_for_selling_type($sellingType);
    if (!$schema) {
        return [];
    }
    $out = [];
    foreach ($schema['fields'] as $field) {
        $key = (string)($field['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $val = trim((string)($raw[$key] ?? ''));
        if ($val === '') {
            continue;
        }
        $max = (int)($field['maxlength'] ?? 200);
        if ($max > 0 && mb_strlen($val) > $max) {
            $val = mb_substr($val, 0, $max);
        }
        $out[$key] = $val;
    }
    return $out;
}

/**
 * @return list<array{key:string,label:string,value:string}>
 */
function org_product_type_attributes_for_display(?string $attributesJson, string $sellingType): array
{
    if ($attributesJson === null || trim($attributesJson) === '') {
        return [];
    }
    $decoded = json_decode($attributesJson, true);
    if (!is_array($decoded)) {
        return [];
    }
    $schema = org_product_type_schema_for_selling_type($sellingType);
    if (!$schema) {
        return [];
    }
    $labels = [];
    $order = [];
    foreach ($schema['fields'] as $field) {
        $key = (string)($field['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $labels[$key] = (string)($field['label'] ?? $key);
        $order[] = $key;
    }
    $rowsByKey = [];
    foreach ($decoded as $key => $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        $key = (string)$key;
        $rowsByKey[$key] = [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'value' => $value,
        ];
    }
    $rows = [];
    foreach ($order as $key) {
        if (isset($rowsByKey[$key])) {
            $rows[] = $rowsByKey[$key];
            unset($rowsByKey[$key]);
        }
    }
    foreach ($rowsByKey as $row) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Buyer-facing facts for shop cards and product detail.
 *
 * @return array{
 *   selling_type:string,
 *   type_label:string,
 *   condition:string,
 *   rows:list<array{key:string,label:string,value:string}>,
 *   highlight:list<array{key:string,label:string,value:string}>,
 *   card_bits:list<string>
 * }
 */
function org_product_type_buyer_facts(?string $attributesJson, string $sellingType, int $highlightLimit = 6): array
{
    $sellingType = trim($sellingType);
    $schema = org_product_type_schema_for_selling_type($sellingType);
    $rows = org_product_type_attributes_for_display($attributesJson, $sellingType);
    $condition = '';
    $withoutCondition = [];
    foreach ($rows as $row) {
        if (($row['key'] ?? '') === 'condition') {
            $condition = (string)$row['value'];
            continue;
        }
        $withoutCondition[] = $row;
    }

    $priority = [
        'make', 'brand', 'model', 'year', 'size', 'waist', 'inseam', 'storage_gb', 'ram_gb',
        'screen_inches', 'mileage', 'bag_type', 'item_type', 'shade', 'volume', 'capacity',
        'material', 'color', 'resolution', 'fit',
    ];
    $byKey = [];
    foreach ($withoutCondition as $row) {
        $byKey[(string)$row['key']] = $row;
    }
    $highlight = [];
    foreach ($priority as $key) {
        if (isset($byKey[$key])) {
            $highlight[] = $byKey[$key];
            unset($byKey[$key]);
        }
        if (count($highlight) >= $highlightLimit) {
            break;
        }
    }
    if (count($highlight) < $highlightLimit) {
        foreach ($byKey as $row) {
            $highlight[] = $row;
            if (count($highlight) >= $highlightLimit) {
                break;
            }
        }
    }

    $cardBits = [];
    if ($sellingType !== '') {
        $cardBits[] = $sellingType;
    } elseif ($schema) {
        $cardBits[] = (string)$schema['label'];
    }
    if ($condition !== '') {
        $cardBits[] = $condition;
    }
    foreach (array_slice($highlight, 0, 3) as $row) {
        $cardBits[] = (string)$row['value'];
    }

    return [
        'selling_type' => $sellingType,
        'type_label' => $schema ? (string)$schema['label'] : $sellingType,
        'condition' => $condition,
        'rows' => $rows,
        'highlight' => $highlight,
        'card_bits' => $cardBits,
    ];
}
