<?php
if (!defined('ABSPATH')) { exit; }

final class BZV_Afnemer_Google {
    /** Only place ID and coordinates leave Google responses; addresses come from our data/PDOK. */
    public static function locate(string $key, string $place, array $address = []): array {
        if ($key === '') { throw new RuntimeException('De Google-instellingen moeten nog worden ingevuld.'); }
        if ($place !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,300}\z/', $place)) { throw new RuntimeException('Kies een geldige Google-locatie.'); }
        $query = ['key' => $key, 'language' => 'nl', 'region' => 'nl'];
        if ($place !== '') { $query['place_id'] = $place; }
        else {
            foreach (['address', 'postalCode', 'city', 'countryCode'] as $f) {
                if (!BZV_Afnemer_State::text($address[$f] ?? null, 300)) { throw new RuntimeException('Vul eerst het volledige adres in.'); }
            }
            $query['address'] = implode(', ', array_intersect_key($address, array_flip(['address', 'postalCode', 'city', 'countryCode'])));
        }
        $response = wp_safe_remote_get('https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($query),
            ['timeout' => 10, 'redirection' => 0, 'limit_response_size' => 262144, 'sslverify' => true]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { throw new RuntimeException('Google is tijdelijk niet bereikbaar. Je invoer blijft staan.'); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) { throw new RuntimeException('Google vond geen bruikbare locatie. Kies een ander voorstel of pas het adres aan.'); }
        $results = [];
        foreach (array_slice($data['results'], 0, 3) as $item) {
            $position = $item['geometry']['location'] ?? [];
            if (!isset($position['lat'], $position['lng']) || !is_numeric($position['lat']) || !is_numeric($position['lng']) ||
                abs((float) $position['lat']) > 90 || abs((float) $position['lng']) > 180 || !is_finite((float) $position['lat']) || !is_finite((float) $position['lng']) ||
                !preg_match('/\A[A-Za-z0-9_-]{1,300}\z/', $item['place_id'] ?? '')) { continue; }
            $results[] = ['latitude' => (float) $position['lat'], 'longitude' => (float) $position['lng'], 'placeId' => $item['place_id'],
                'coordinateSource' => 'google', 'locatedAt' => time(),
                'approximate' => !empty($item['partial_match']) || !in_array($item['geometry']['location_type'] ?? '', ['ROOFTOP'], true)];
        }
        if (!$results) { throw new RuntimeException('Google gaf geen geldige kaartpositie terug.'); }
        return $results;
    }
    /** Open Dutch address registry. A nearby address is a proposal, never proof of the business's address. */
    public static function address_suggestions(float $lat, float $lng): array {
        if ($lat < 50.7 || $lat > 53.7 || $lng < 3.2 || $lng > 7.3) { return []; }
        $url = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/reverse?' . http_build_query([
            'lat' => $lat, 'lon' => $lng, 'type' => 'adres', 'distance' => 150, 'rows' => 3, 'fl' => 'straatnaam,huisnummer,huisletter,huisnummertoevoeging,postcode,woonplaatsnaam']);
        $response = wp_safe_remote_get($url, ['timeout' => 8, 'redirection' => 0, 'limit_response_size' => 131072, 'sslverify' => true]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return []; }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $items = [];
        foreach (array_slice($body['response']['docs'] ?? [], 0, 3) as $doc) {
            $a = ['address' => trim(($doc['straatnaam'] ?? '') . ' ' . ($doc['huisnummer'] ?? '') . ($doc['huisletter'] ?? '') .
                (empty($doc['huisnummertoevoeging']) ? '' : '-' . $doc['huisnummertoevoeging'])),
                'postalCode' => $doc['postcode'] ?? '', 'city' => $doc['woonplaatsnaam'] ?? '', 'countryCode' => 'NL'];
            if (BZV_Afnemer_State::text($a['address'], 300) && BZV_Afnemer_State::text($a['postalCode'], 20) && BZV_Afnemer_State::text($a['city'], 160)) { $items[] = $a; }
        }
        return $items;
    }
}
