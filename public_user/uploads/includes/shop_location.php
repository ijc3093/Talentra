<?php
declare(strict_types=1);

/**
 * Buyer shop location preference (city/state + radius) for marketplace filtering.
 */

if (!function_exists('shop_location_radius_options')) {
    /** @return list<int> */
    function shop_location_radius_options(): array
    {
        return [5, 10, 25, 50, 100];
    }
}

if (!function_exists('shop_location_normalize_state')) {
    function shop_location_normalize_state(string $state): string
    {
        $state = trim($state);
        if ($state === '') {
            return '';
        }
        $map = [
            'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA',
            'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'florida' => 'FL', 'georgia' => 'GA',
            'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA',
            'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
            'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO',
            'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ',
            'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH',
            'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
            'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT',
            'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
            'district of columbia' => 'DC',
        ];
        $lower = mb_strtolower($state);
        if (isset($map[$lower])) {
            return $map[$lower];
        }
        if (preg_match('/^[A-Za-z]{2}$/', $state)) {
            return strtoupper($state);
        }
        return $state;
    }
}

if (!function_exists('shop_location_format_label')) {
    function shop_location_format_label(string $city, string $state, string $country = ''): string
    {
        $city = trim($city);
        $state = shop_location_normalize_state($state);
        $country = trim($country);
        if ($city !== '' && $state !== '') {
            return $city . ', ' . $state;
        }
        if ($city !== '') {
            return $city;
        }
        if ($state !== '') {
            return $state;
        }
        return $country !== '' ? $country : '';
    }
}

if (!function_exists('shop_location_summary_text')) {
    /** e.g. "Garland, TX · Within 10 mi" */
    function shop_location_summary_text(array $loc): string
    {
        $label = trim((string)($loc['label'] ?? ''));
        if ($label === '') {
            $label = shop_location_format_label(
                (string)($loc['city'] ?? ''),
                (string)($loc['state'] ?? ''),
                (string)($loc['country'] ?? '')
            );
        }
        $miles = max(1, (int)($loc['miles'] ?? 10));
        if ($label === '') {
            return 'Set your location';
        }
        return $label . ' · Within ' . $miles . ' mi';
    }
}

if (!function_exists('shop_location_default')) {
    /** @return array{label:string,city:string,state:string,country:string,postal:string,miles:int,lat:?float,lng:?float} */
    function shop_location_default(): array
    {
        return [
            'label' => '',
            'city' => '',
            'state' => '',
            'country' => '',
            'postal' => '',
            'miles' => 10,
            'lat' => null,
            'lng' => null,
        ];
    }
}

if (!function_exists('shop_location_from_session')) {
    /** @return array{label:string,city:string,state:string,country:string,postal:string,miles:int,lat:?float,lng:?float} */
    function shop_location_from_session(): array
    {
        $raw = $_SESSION['shop_location'] ?? null;
        if (!is_array($raw)) {
            return shop_location_default();
        }
        $miles = (int)($raw['miles'] ?? 10);
        if (!in_array($miles, shop_location_radius_options(), true)) {
            $miles = 10;
        }
        $city = trim((string)($raw['city'] ?? ''));
        $state = shop_location_normalize_state((string)($raw['state'] ?? ''));
        $country = trim((string)($raw['country'] ?? ''));
        $postal = trim((string)($raw['postal'] ?? ''));
        $label = trim((string)($raw['label'] ?? ''));
        if ($label === '') {
            $label = shop_location_format_label($city, $state, $country);
        }
        $lat = isset($raw['lat']) && $raw['lat'] !== '' && $raw['lat'] !== null ? (float)$raw['lat'] : null;
        $lng = isset($raw['lng']) && $raw['lng'] !== '' && $raw['lng'] !== null ? (float)$raw['lng'] : null;
        return [
            'label' => $label,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'postal' => $postal,
            'miles' => $miles,
            'lat' => $lat,
            'lng' => $lng,
        ];
    }
}

if (!function_exists('shop_location_save_session')) {
    /** @param array<string,mixed> $data */
    function shop_location_save_session(array $data): array
    {
        $miles = (int)($data['miles'] ?? 10);
        if (!in_array($miles, shop_location_radius_options(), true)) {
            $miles = 10;
        }
        $city = trim((string)($data['city'] ?? ''));
        $state = shop_location_normalize_state((string)($data['state'] ?? ''));
        $country = trim((string)($data['country'] ?? ''));
        $postal = trim((string)($data['postal'] ?? ''));
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            $label = shop_location_format_label($city, $state, $country);
        }
        $lat = isset($data['lat']) && $data['lat'] !== '' && $data['lat'] !== null ? (float)$data['lat'] : null;
        $lng = isset($data['lng']) && $data['lng'] !== '' && $data['lng'] !== null ? (float)$data['lng'] : null;
        $loc = [
            'label' => $label,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'postal' => $postal,
            'miles' => $miles,
            'lat' => $lat,
            'lng' => $lng,
        ];
        $_SESSION['shop_location'] = $loc;
        return $loc;
    }
}

if (!function_exists('shop_location_seed_from_buyer')) {
    function shop_location_seed_from_buyer(PDO $dbh, int $userId): array
    {
        $current = shop_location_from_session();
        if ($current['city'] !== '' || $current['label'] !== '') {
            return $current;
        }
        if ($userId <= 0 || !function_exists('buyer_shipping_default_row')) {
            return $current;
        }
        try {
            require_once __DIR__ . '/buyer_shipping.php';
            $row = buyer_shipping_default_row($dbh, $userId);
        } catch (Throwable $e) {
            return $current;
        }
        if (!$row) {
            return $current;
        }
        $city = trim((string)($row['city'] ?? ''));
        $state = shop_location_normalize_state((string)($row['region'] ?? ''));
        $postal = trim((string)($row['postal_code'] ?? ''));
        $country = trim((string)($row['country'] ?? ''));
        if ($city === '' && $state === '' && $postal === '') {
            return $current;
        }
        $loc = shop_location_save_session([
            'city' => $city,
            'state' => $state,
            'country' => $country !== '' ? $country : 'United States',
            'postal' => $postal,
            'miles' => 10,
            'label' => shop_location_format_label($city, $state, $country),
            'lat' => null,
            'lng' => null,
        ]);
        return $loc;
    }
}

if (!function_exists('shop_location_haversine_miles')) {
    function shop_location_haversine_miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 3958.7613;
        $rlat1 = deg2rad($lat1);
        $rlat2 = deg2rad($lat2);
        $dlat = deg2rad($lat2 - $lat1);
        $dlng = deg2rad($lng2 - $lng1);
        $a = sin($dlat / 2) ** 2 + cos($rlat1) * cos($rlat2) * sin($dlng / 2) ** 2;
        return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}

if (!function_exists('shop_location_geocode_query')) {
    /**
     * Nominatim search (server-side).
     * @return array{lat:float,lng:float,label:string,city:string,state:string,country:string,postal:string}|null
     */
    function shop_location_geocode_query(string $query): ?array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) < 2) {
            return null;
        }
        $cacheKey = 'shop_geo:' . mb_strtolower($query);
        if (!isset($_SESSION['shop_geo_cache']) || !is_array($_SESSION['shop_geo_cache'])) {
            $_SESSION['shop_geo_cache'] = [];
        }
        if (isset($_SESSION['shop_geo_cache'][$cacheKey]) && is_array($_SESSION['shop_geo_cache'][$cacheKey])) {
            return $_SESSION['shop_geo_cache'][$cacheKey];
        }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => 1,
        ]);
        $json = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'User-Agent: myStoryBook-ShopLocation/1.0',
                    ],
                ]);
                $raw = curl_exec($ch);
                curl_close($ch);
                if (is_string($raw) && $raw !== '') {
                    $json = json_decode($raw, true);
                }
            }
        }
        if (!is_array($json) || !$json) {
            return null;
        }
        $hit = $json[0] ?? null;
        if (!is_array($hit)) {
            return null;
        }
        $addr = is_array($hit['address'] ?? null) ? $hit['address'] : [];
        $city = trim((string)($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? $addr['municipality'] ?? ''));
        $state = shop_location_normalize_state((string)($addr['state'] ?? $addr['region'] ?? ''));
        if ($state === '' && !empty($addr['state_code'])) {
            $state = shop_location_normalize_state((string)$addr['state_code']);
        }
        $country = trim((string)($addr['country'] ?? ''));
        $postal = trim((string)($addr['postcode'] ?? ''));
        $lat = isset($hit['lat']) ? (float)$hit['lat'] : null;
        $lng = isset($hit['lon']) ? (float)$hit['lon'] : null;
        if ($lat === null || $lng === null) {
            return null;
        }
        $label = shop_location_format_label($city, $state, $country);
        if ($label === '') {
            $label = trim((string)($hit['display_name'] ?? $query));
        }
        $out = [
            'lat' => $lat,
            'lng' => $lng,
            'label' => $label,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'postal' => $postal,
        ];
        $_SESSION['shop_geo_cache'][$cacheKey] = $out;
        return $out;
    }
}

if (!function_exists('shop_location_attach_seller_addresses')) {
    /**
     * Attach seller_city / seller_state / seller_country / seller_postal / seller_lat / seller_lng to products.
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    function shop_location_attach_seller_addresses(PDO $dbh, array $products): array
    {
        if (!$products) {
            return $products;
        }
        $orgIds = [];
        foreach ($products as $p) {
            $oid = (int)($p['org_id'] ?? 0);
            if ($oid > 0) {
                $orgIds[$oid] = $oid;
            }
        }
        if (!$orgIds) {
            return $products;
        }

        $addrByOrg = [];
        try {
            $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
            $st = $dbh->prepare("SELECT org_id, shop_json FROM org_settings WHERE org_id IN ({$placeholders})");
            $st->execute(array_values($orgIds));
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $oid = (int)($row['org_id'] ?? 0);
                $decoded = json_decode((string)($row['shop_json'] ?? ''), true);
                $addr = is_array($decoded['address'] ?? null) ? $decoded['address'] : [];
                $city = trim((string)($addr['city'] ?? ''));
                $state = shop_location_normalize_state((string)($addr['state'] ?? ''));
                $country = trim((string)($addr['country'] ?? ''));
                $postal = trim((string)($addr['postal_code'] ?? ''));
                $lat = isset($addr['lat']) && $addr['lat'] !== '' ? (float)$addr['lat'] : null;
                $lng = isset($addr['lng']) && $addr['lng'] !== '' ? (float)$addr['lng'] : null;
                $addrByOrg[$oid] = [
                    'city' => $city,
                    'state' => $state,
                    'country' => $country,
                    'postal' => $postal,
                    'lat' => $lat,
                    'lng' => $lng,
                ];
            }
        } catch (Throwable $e) {
            $addrByOrg = [];
        }

        foreach ($products as &$p) {
            $oid = (int)($p['org_id'] ?? 0);
            $info = $addrByOrg[$oid] ?? ['city' => '', 'state' => '', 'country' => '', 'postal' => '', 'lat' => null, 'lng' => null];
            $p['seller_city'] = $info['city'];
            $p['seller_state'] = $info['state'];
            $p['seller_country'] = $info['country'];
            $p['seller_postal'] = $info['postal'];
            $p['seller_lat'] = $info['lat'];
            $p['seller_lng'] = $info['lng'];
        }
        unset($p);
        return $products;
    }
}

if (!function_exists('shop_location_resolve_seller_coords')) {
    /** @param array<string,mixed> $product */
    function shop_location_resolve_seller_coords(array &$product): bool
    {
        $lat = $product['seller_lat'] ?? null;
        $lng = $product['seller_lng'] ?? null;
        if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
            $product['seller_lat'] = (float)$lat;
            $product['seller_lng'] = (float)$lng;
            return true;
        }
        $city = trim((string)($product['seller_city'] ?? ''));
        $state = trim((string)($product['seller_state'] ?? ''));
        $postal = trim((string)($product['seller_postal'] ?? ''));
        $query = trim($city . ($state !== '' ? ', ' . $state : '') . ($postal !== '' ? ' ' . $postal : ''));
        if ($query === '') {
            return false;
        }
        $geo = shop_location_geocode_query($query);
        if (!$geo) {
            return false;
        }
        $product['seller_lat'] = $geo['lat'];
        $product['seller_lng'] = $geo['lng'];
        return true;
    }
}

if (!function_exists('shop_location_product_in_range')) {
    /** @param array<string,mixed> $product @param array<string,mixed> $loc */
    function shop_location_product_in_range(array $product, array $loc): bool
    {
        $city = trim((string)($loc['city'] ?? ''));
        $state = shop_location_normalize_state((string)($loc['state'] ?? ''));
        $miles = max(1, (int)($loc['miles'] ?? 10));
        $buyerLat = $loc['lat'] ?? null;
        $buyerLng = $loc['lng'] ?? null;

        $sellerCity = trim((string)($product['seller_city'] ?? ''));
        $sellerState = shop_location_normalize_state((string)($product['seller_state'] ?? ''));

        // No seller address → cannot match a location radius.
        if ($sellerCity === '' && $sellerState === '' && empty($product['seller_postal'])) {
            return false;
        }

        // Same city/state always counts as in range.
        if ($city !== '' && $sellerCity !== '' && strcasecmp($city, $sellerCity) === 0) {
            if ($state === '' || $sellerState === '' || strcasecmp($state, $sellerState) === 0) {
                return true;
            }
        }

        if ($buyerLat === null || $buyerLng === null) {
            // Fall back to state-only match when we lack coordinates.
            if ($state !== '' && $sellerState !== '' && strcasecmp($state, $sellerState) === 0) {
                return true;
            }
            return false;
        }

        if (!shop_location_resolve_seller_coords($product)) {
            if ($state !== '' && $sellerState !== '' && strcasecmp($state, $sellerState) === 0) {
                return true;
            }
            return false;
        }

        $dist = shop_location_haversine_miles(
            (float)$buyerLat,
            (float)$buyerLng,
            (float)$product['seller_lat'],
            (float)$product['seller_lng']
        );
        return $dist <= ($miles + 0.25);
    }
}
