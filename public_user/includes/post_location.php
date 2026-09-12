<?php
declare(strict_types=1);

/**
 * Create-post location search (Nominatim + map-link paste).
 */

if (!function_exists('post_location_http_get')) {
    function post_location_http_get(string $url, int $timeout = 8, bool $followRedirects = true): ?string
    {
        if ($url === '' || !function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/html,*/*',
                'User-Agent: Talsora-PostLocation/1.0',
            ],
        ];
        if (defined('CURL_IPRESOLVE_V4')) {
            $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        curl_close($ch);
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}

if (!function_exists('post_location_looks_like_url')) {
    function post_location_looks_like_url(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $text)) {
            return true;
        }
        return (bool)preg_match('#^(?:www\.)?(?:google\.[^/\s]+/maps|maps\.google\.|maps\.app\.goo\.gl|goo\.gl/maps|maps\.apple\.com|openstreetmap\.org|osm\.org)#i', $text);
    }
}

if (!function_exists('post_location_normalize_url')) {
    function post_location_normalize_url(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $text)) {
            $text = 'https://' . ltrim($text, '/');
        }
        return $text;
    }
}

if (!function_exists('post_location_parse_map_link')) {
    /**
     * @return array{q:string,lat:?float,lng:?float,source_url:string}
     */
    function post_location_parse_map_link(string $raw): array
    {
        $out = ['q' => '', 'lat' => null, 'lng' => null, 'source_url' => ''];
        $url = post_location_normalize_url($raw);
        if ($url === '') {
            return $out;
        }
        $out['source_url'] = $url;

        // Resolve short Google Maps links.
        if (preg_match('#(?:maps\.app\.goo\.gl|goo\.gl/maps)/#i', $url)) {
            $ch = function_exists('curl_init') ? curl_init($url) : false;
            if ($ch !== false) {
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_NOBODY => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_HTTPHEADER => ['User-Agent: Talsora-PostLocation/1.0'],
                ];
                curl_setopt_array($ch, $opts);
                $hdr = curl_exec($ch);
                $info = curl_getinfo($ch);
                curl_close($ch);
                $redirect = '';
                if (is_array($info) && !empty($info['redirect_url'])) {
                    $redirect = (string)$info['redirect_url'];
                } elseif (is_string($hdr) && preg_match('/^Location:\s*(.+)$/mi', $hdr, $m)) {
                    $redirect = trim((string)$m[1]);
                }
                if ($redirect !== '') {
                    $url = $redirect;
                    $out['source_url'] = $url;
                }
            }
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $out;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        $fragment = (string)($parts['fragment'] ?? '');

        foreach (['q', 'query', 'address', 'destination', 'daddr', 'saddr'] as $key) {
            if (!empty($query[$key]) && is_string($query[$key])) {
                $out['q'] = trim(urldecode($query[$key]));
                break;
            }
        }
        if ($out['q'] === '' && !empty($query['near']) && is_string($query['near'])) {
            $out['q'] = trim(urldecode($query['near']));
        }

        if (!empty($query['ll']) && is_string($query['ll']) && preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $query['ll'], $m)) {
            $out['lat'] = (float)$m[1];
            $out['lng'] = (float)$m[2];
        }
        if (($out['lat'] === null || $out['lng'] === null) && !empty($query['center']) && is_string($query['center'])
            && preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $query['center'], $m)) {
            $out['lat'] = (float)$m[1];
            $out['lng'] = (float)$m[2];
        }

        // Google Maps place path: /maps/place/Name/@lat,lng
        if (preg_match('#/maps/place/([^/@]+)#i', $path . '/' . $fragment, $m) && $out['q'] === '') {
            $out['q'] = trim(urldecode(str_replace('+', ' ', (string)$m[1])));
        }
        if (preg_match('#/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)#', $url, $m)) {
            $out['lat'] = (float)$m[1];
            $out['lng'] = (float)$m[2];
        }
        if (preg_match('#/search/([^/?]+)#i', $path, $m) && $out['q'] === '') {
            $out['q'] = trim(urldecode(str_replace('+', ' ', (string)$m[1])));
        }

        // OpenStreetMap #map=z/lat/lon
        if (preg_match('#map=(\d+)/(-?\d+(?:\.\d+)?)/(-?\d+(?:\.\d+)?)#', $fragment, $m)) {
            $out['lat'] = (float)$m[2];
            $out['lng'] = (float)$m[3];
        }
        if (!empty($query['mlat']) && !empty($query['mlon'])) {
            $out['lat'] = (float)$query['mlat'];
            $out['lng'] = (float)$query['mlon'];
        }

        // Fallback: any lat,lng pair in the URL.
        if (($out['lat'] === null || $out['lng'] === null)
            && preg_match('/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', $url, $m)) {
            $out['lat'] = (float)$m[1];
            $out['lng'] = (float)$m[2];
        }

        // Non-map hosts: treat path slug as weak query.
        if ($out['q'] === '' && $out['lat'] === null && !preg_match('#(google|goo\.gl|apple|openstreetmap|osm\.org)#i', $host)) {
            $out['q'] = trim(urldecode(str_replace(['-', '_', '+'], ' ', basename($path))));
        }

        return $out;
    }
}

if (!function_exists('post_location_format_label')) {
    function post_location_format_label(string $name, string $city, string $state, string $country): string
    {
        $parts = [];
        $name = trim($name);
        $city = trim($city);
        $state = trim($state);
        $country = trim($country);
        if ($name !== '' && strcasecmp($name, $city) !== 0) {
            $parts[] = $name;
        } elseif ($city !== '') {
            $parts[] = $city;
        } elseif ($name !== '') {
            $parts[] = $name;
        }
        if ($state !== '') {
            $parts[] = $state;
        }
        if ($country !== '' && !in_array(strtolower($country), ['united states', 'united states of america', 'usa'], true)) {
            $parts[] = $country;
        } elseif ($country !== '' && count($parts) < 2) {
            $parts[] = $country;
        }
        $label = implode(', ', array_values(array_unique($parts)));
        return mb_substr($label, 0, 120);
    }
}

if (!function_exists('post_location_row_from_nominatim')) {
    /**
     * @param array<string,mixed> $hit
     * @return array{label:string,name:string,city:string,state:string,country:string,lat:float,lng:float,display_name:string}
     */
    function post_location_row_from_nominatim(array $hit): array
    {
        $addr = is_array($hit['address'] ?? null) ? $hit['address'] : [];
        $name = trim((string)($hit['name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($addr['tourism'] ?? $addr['amenity'] ?? $addr['leisure'] ?? $addr['natural'] ?? ''));
        }
        $city = trim((string)($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? $addr['municipality'] ?? $addr['county'] ?? ''));
        $state = trim((string)($addr['state'] ?? $addr['region'] ?? ''));
        $country = trim((string)($addr['country'] ?? ''));
        $lat = (float)($hit['lat'] ?? 0);
        $lng = (float)($hit['lon'] ?? 0);
        $display = trim((string)($hit['display_name'] ?? ''));
        $label = post_location_format_label($name, $city, $state, $country);
        if ($label === '') {
            $label = mb_substr($display !== '' ? $display : $name, 0, 120);
        }
        return [
            'label' => $label,
            'name' => $name,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'lat' => $lat,
            'lng' => $lng,
            'display_name' => $display,
        ];
    }
}

if (!function_exists('post_location_nominatim_search')) {
    /**
     * @return list<array{label:string,name:string,city:string,state:string,country:string,lat:float,lng:float,display_name:string}>
     */
    function post_location_nominatim_search(string $query, int $limit = 8): array
    {
        $query = trim($query);
        $limit = max(1, min(12, $limit));
        if ($query === '' || mb_strlen($query) < 2) {
            return [];
        }
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => $limit,
        ]);
        $raw = post_location_http_get($url);
        if ($raw === null) {
            return [];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($json as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $row = post_location_row_from_nominatim($hit);
            if ($row['label'] === '' || ($row['lat'] == 0.0 && $row['lng'] == 0.0)) {
                continue;
            }
            $key = mb_strtolower($row['label'] . '|' . round($row['lat'], 4) . '|' . round($row['lng'], 4));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('post_location_nominatim_reverse')) {
    /**
     * @return array{label:string,name:string,city:string,state:string,country:string,lat:float,lng:float,display_name:string}|null
     */
    function post_location_nominatim_reverse(float $lat, float $lng): ?array
    {
        if (!is_finite($lat) || !is_finite($lng)) {
            return null;
        }
        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'json',
            'addressdetails' => 1,
        ]);
        $raw = post_location_http_get($url);
        if ($raw === null) {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }
        $row = post_location_row_from_nominatim($json);
        $row['lat'] = $lat;
        $row['lng'] = $lng;
        return $row['label'] !== '' ? $row : null;
    }
}

if (!function_exists('post_location_search')) {
    /**
     * Search by place name or pasted map URL.
     *
     * @return array{ok:bool,places:list<array<string,mixed>>,error:string,mode:string}
     */
    function post_location_search(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['ok' => false, 'places' => [], 'error' => 'Type a place name or paste a map link.', 'mode' => ''];
        }

        if (post_location_looks_like_url($input)) {
            $parsed = post_location_parse_map_link($input);
            $places = [];
            if ($parsed['lat'] !== null && $parsed['lng'] !== null) {
                $rev = post_location_nominatim_reverse((float)$parsed['lat'], (float)$parsed['lng']);
                if ($rev) {
                    if ($parsed['q'] !== '' && $rev['name'] === '') {
                        $rev['name'] = $parsed['q'];
                        $rev['label'] = post_location_format_label($parsed['q'], $rev['city'], $rev['state'], $rev['country']);
                    }
                    $places[] = $rev;
                }
            }
            if ($parsed['q'] !== '') {
                foreach (post_location_nominatim_search($parsed['q'], 8) as $hit) {
                    $places[] = $hit;
                }
            }
            // Deduplicate.
            $seen = [];
            $uniq = [];
            foreach ($places as $p) {
                $key = mb_strtolower(($p['label'] ?? '') . '|' . round((float)($p['lat'] ?? 0), 4));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $uniq[] = $p;
            }
            if ($uniq === []) {
                return [
                    'ok' => false,
                    'places' => [],
                    'error' => 'Could not read that map link. Try a place name instead.',
                    'mode' => 'url',
                ];
            }
            return ['ok' => true, 'places' => array_slice($uniq, 0, 8), 'error' => '', 'mode' => 'url'];
        }

        if (mb_strlen($input) < 2) {
            return ['ok' => false, 'places' => [], 'error' => 'Keep typing a place name…', 'mode' => 'text'];
        }

        $places = post_location_nominatim_search($input, 8);
        if ($places === []) {
            return ['ok' => false, 'places' => [], 'error' => 'No places found. Try another name or paste a map link.', 'mode' => 'text'];
        }
        return ['ok' => true, 'places' => $places, 'error' => '', 'mode' => 'text'];
    }
}
