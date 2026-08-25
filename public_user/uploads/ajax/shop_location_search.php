<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../includes/shop_location.php';

header('Content-Type: application/json; charset=utf-8');

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'search'));

if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
    if (strlen($q) < 2) {
        echo json_encode(['ok' => false, 'error' => 'Enter a city, neighborhood, or ZIP.']);
        exit;
    }
    $geo = shop_location_geocode_query($q);
    if (!$geo) {
        echo json_encode(['ok' => false, 'error' => 'No places found. Try another search.']);
        exit;
    }
    echo json_encode(['ok' => true, 'place' => $geo]);
    exit;
}

if ($action === 'apply') {
    $payload = $_POST;
    if (empty($payload['city']) && empty($payload['label']) && !empty($payload['json'])) {
        $decoded = json_decode((string)$payload['json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $loc = shop_location_save_session([
        'label' => (string)($payload['label'] ?? ''),
        'city' => (string)($payload['city'] ?? ''),
        'state' => (string)($payload['state'] ?? ''),
        'country' => (string)($payload['country'] ?? ''),
        'postal' => (string)($payload['postal'] ?? ''),
        'miles' => (int)($payload['miles'] ?? 10),
        'lat' => $payload['lat'] ?? null,
        'lng' => $payload['lng'] ?? null,
    ]);
    if ($loc['label'] === '' && $loc['city'] === '') {
        echo json_encode(['ok' => false, 'error' => 'Choose a location first.']);
        exit;
    }
    if (($loc['lat'] === null || $loc['lng'] === null) && $loc['label'] !== '') {
        $geo = shop_location_geocode_query($loc['label']);
        if ($geo) {
            $loc = shop_location_save_session(array_merge($loc, [
                'lat' => $geo['lat'],
                'lng' => $geo['lng'],
                'city' => $loc['city'] !== '' ? $loc['city'] : $geo['city'],
                'state' => $loc['state'] !== '' ? $loc['state'] : $geo['state'],
                'country' => $loc['country'] !== '' ? $loc['country'] : $geo['country'],
                'label' => shop_location_format_label(
                    $loc['city'] !== '' ? $loc['city'] : $geo['city'],
                    $loc['state'] !== '' ? $loc['state'] : $geo['state'],
                    $loc['country'] !== '' ? $loc['country'] : $geo['country']
                ),
            ]));
        }
    }
    echo json_encode([
        'ok' => true,
        'location' => $loc,
        'summary' => shop_location_summary_text($loc),
        'redirect' => 'shop.php',
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
