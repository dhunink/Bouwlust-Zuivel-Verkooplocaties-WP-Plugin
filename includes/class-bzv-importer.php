<?php

if (!defined('ABSPATH')) {
    exit;
}

final class BZV_Importer {
    public const CPT = 'zuivel_verkooppunt';
    private const MANAGED_META = '_zuivel_import_managed';
    private const SOURCE_FINGERPRINT_META = '_zuivel_import_source_fingerprint';

    public static function normalize_rows(array $raw_rows): array {
        if (count($raw_rows) < 2) {
            return [];
        }

        $header = array_shift($raw_rows);
        $mapped = [];
        foreach ($header as $index => $label) {
            $normalized = self::normalize_header($label);
            if (in_array($normalized, ['klant', 'customer', 'naam', 'verkooppunt'], true)) {
                $mapped['customer'] = $index;
            }
            if (in_array($normalized, ['straat huisnr', 'straat huisnummer', 'adres', 'address', 'straat en huisnummer'], true)) {
                $mapped['address'] = $index;
            }
            if (in_array($normalized, ['postcode', 'postal code', 'postalcode'], true)) {
                $mapped['postal_code'] = $index;
            }
            if (in_array($normalized, ['plaats', 'city', 'woonplaats'], true)) {
                $mapped['city'] = $index;
            }
            if (in_array($normalized, ['import id', 'importid', 'id'], true)) {
                $mapped['source_id'] = $index;
            }
        }

        $labels = [
            'customer' => 'Klant',
            'address' => 'Straat + huisnr.',
            'postal_code' => 'Postcode',
            'city' => 'Plaats',
        ];
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $mapped)) {
                throw new RuntimeException('Verplichte kolom ontbreekt: ' . $label . '.');
            }
        }

        $rows = [];
        foreach ($raw_rows as $raw) {
            $row = [
                'customer' => trim((string) ($raw[$mapped['customer']] ?? '')),
                'address' => trim((string) ($raw[$mapped['address']] ?? '')),
                'postal_code' => strtoupper(trim((string) ($raw[$mapped['postal_code']] ?? ''))),
                'city' => trim((string) ($raw[$mapped['city']] ?? '')),
                'source_id' => isset($mapped['source_id']) ? trim((string) ($raw[$mapped['source_id']] ?? '')) : '',
            ];

            if ($row['customer'] === '' && $row['address'] === '' && $row['postal_code'] === '' && $row['city'] === '') {
                continue;
            }
            if ($row['customer'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public static function analyze_rows(array $rows): array {
        $analysis = [];
        foreach ($rows as $row) {
            $matches = self::find_existing_matches($row);
            if (count($matches) > 1) {
                $analysis[] = [
                    'row' => $row,
                    'action' => 'ambiguous',
                    'post_id' => 0,
                    'match_reason' => 'meerdere matches',
                ];
                continue;
            }

            if (!$matches) {
                $analysis[] = [
                    'row' => $row,
                    'action' => 'new',
                    'post_id' => 0,
                    'match_reason' => 'geen bestaande match',
                ];
                continue;
            }

            $post_id = (int) array_key_first($matches);
            $analysis[] = [
                'row' => $row,
                'action' => self::row_matches_post($row, $post_id) ? 'same' : 'update',
                'post_id' => $post_id,
                'match_reason' => $matches[$post_id],
            ];
        }
        return $analysis;
    }

    public static function count_missing_managed(array $analysis): int {
        $touched = array_values(array_filter(array_map(
            static fn($item) => (int) $item['post_id'],
            $analysis
        )));

        $managed = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => self::MANAGED_META,
            'meta_value' => '1',
        ]);

        return count(array_diff(array_map('intval', $managed), $touched));
    }

    public static function run(array $rows, bool $deactivate_missing = false): array {
        $analysis = self::analyze_rows($rows);
        $result = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'geocoded' => 0,
            'geocode_failed' => 0,
            'deactivated' => 0,
            'errors' => [],
        ];
        $touched_ids = [];

        foreach ($analysis as $item) {
            $row = $item['row'];
            if ($item['action'] === 'ambiguous') {
                $result['errors'][] = $row['customer'] . ': meerdere mogelijke bestaande matches; overgeslagen.';
                continue;
            }

            $post_id = (int) $item['post_id'];
            $is_new = !$post_id;

            if ($is_new) {
                $post_id = wp_insert_post([
                    'post_type' => self::CPT,
                    'post_status' => 'publish',
                    'post_title' => $row['customer'],
                ], true);

                if (is_wp_error($post_id)) {
                    $result['errors'][] = $row['customer'] . ': ' . $post_id->get_error_message();
                    continue;
                }
                $post_id = (int) $post_id;
                $result['created']++;
            } else {
                $update = ['ID' => $post_id];
                if (get_post_status($post_id) !== 'publish') {
                    $update['post_status'] = 'publish';
                }
                if ($item['action'] === 'update') {
                    $update['post_title'] = $row['customer'];
                    $result['updated']++;
                } else {
                    $result['unchanged']++;
                }
                if (count($update) > 1) {
                    wp_update_post($update);
                }
            }

            $touched_ids[] = $post_id;
            update_post_meta($post_id, self::MANAGED_META, '1');

            $existing_import_id = (string) get_field('import_id', $post_id);
            $import_id = $row['source_id'] !== ''
                ? $row['source_id']
                : ($existing_import_id ?: self::generate_import_id($post_id));

            update_field('address', $row['address'], $post_id);
            update_field('postal_code', $row['postal_code'], $post_id);
            update_field('city', $row['city'], $post_id);
            update_field('import_id', $import_id, $post_id);

            $fingerprint = self::address_fingerprint($row);
            $old_fingerprint = (string) get_post_meta($post_id, self::SOURCE_FINGERPRINT_META, true);
            $location = get_field('location', $post_id);
            $needs_geocode = $is_new || !$location || $old_fingerprint !== $fingerprint;

            if ($needs_geocode) {
                $geo = self::geocode($row);
                if (is_wp_error($geo)) {
                    // Laat bij een gewijzigd adres nooit stilletjes de oude pin op de kaart staan.
                    update_field('location', null, $post_id);
                    delete_post_meta($post_id, self::SOURCE_FINGERPRINT_META);
                    $result['geocode_failed']++;
                    $result['errors'][] = $row['customer'] . ': geocoding mislukt (' . $geo->get_error_message() . ').';
                } else {
                    update_field('location', $geo, $post_id);
                    update_post_meta($post_id, self::SOURCE_FINGERPRINT_META, $fingerprint);
                    $result['geocoded']++;
                }
            }
        }

        if ($deactivate_missing) {
            $managed = get_posts([
                'post_type' => self::CPT,
                'post_status' => ['publish', 'draft'],
                'numberposts' => -1,
                'fields' => 'ids',
                'meta_key' => self::MANAGED_META,
                'meta_value' => '1',
            ]);

            foreach ($managed as $post_id) {
                $post_id = (int) $post_id;
                if (!in_array($post_id, $touched_ids, true) && get_post_status($post_id) === 'publish') {
                    wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
                    $result['deactivated']++;
                }
            }
        }

        return $result;
    }

    private static function find_existing_matches(array $row): array {
        $found = [];

        if ($row['source_id'] !== '') {
            $ids = get_posts([
                'post_type' => self::CPT,
                'post_status' => 'any',
                'numberposts' => -1,
                'fields' => 'ids',
                'meta_key' => 'import_id',
                'meta_value' => $row['source_id'],
            ]);
            foreach ($ids as $id) {
                $found[(int) $id] = 'Import-ID';
            }
            if ($found) {
                return $found;
            }
        }

        $ids = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => 'address', 'value' => $row['address'], 'compare' => '='],
                ['key' => 'postal_code', 'value' => $row['postal_code'], 'compare' => '='],
            ],
        ]);
        foreach ($ids as $id) {
            $found[(int) $id] = 'adres + postcode';
        }
        if ($found) {
            return $found;
        }

        $ids = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'title' => $row['customer'],
        ]);
        foreach ($ids as $id) {
            $found[(int) $id] = 'gelijke klantnaam';
        }

        return $found;
    }

    private static function row_matches_post(array $row, int $post_id): bool {
        return self::clean(get_the_title($post_id)) === self::clean($row['customer'])
            && self::clean((string) get_field('address', $post_id)) === self::clean($row['address'])
            && self::clean((string) get_field('postal_code', $post_id)) === self::clean($row['postal_code'])
            && self::clean((string) get_field('city', $post_id)) === self::clean($row['city']);
    }

    private static function generate_import_id(int $post_id): string {
        return 'VP-' . str_pad((string) $post_id, 6, '0', STR_PAD_LEFT);
    }

    private static function geocode(array $row) {
        $key = self::google_api_key();
        if (!$key) {
            return new WP_Error('missing_api_key', 'geen Google Maps API-key beschikbaar voor server-side geocoding');
        }

        $address = trim($row['address'] . ', ' . $row['postal_code'] . ' ' . $row['city'] . ', Nederland');
        $url = add_query_arg([
            'address' => $address,
            'key' => $key,
            'region' => 'nl',
            'language' => 'nl',
        ], 'https://maps.googleapis.com/maps/api/geocode/json');

        $response = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status_code !== 200 || !is_array($body)) {
            return new WP_Error('geocode_http', 'ongeldige respons van Google');
        }
        if (($body['status'] ?? '') !== 'OK' || empty($body['results'][0])) {
            $message = $body['error_message'] ?? ($body['status'] ?? 'geen resultaat');
            return new WP_Error('geocode_status', $message);
        }

        $result = $body['results'][0];
        $components = [];
        foreach (($result['address_components'] ?? []) as $component) {
            foreach (($component['types'] ?? []) as $type) {
                $components[$type] = $component;
            }
        }

        return [
            'address' => $result['formatted_address'] ?? $address,
            'lat' => (float) ($result['geometry']['location']['lat'] ?? 0),
            'lng' => (float) ($result['geometry']['location']['lng'] ?? 0),
            'zoom' => 15,
            'place_id' => $result['place_id'] ?? '',
            'street_number' => $components['street_number']['long_name'] ?? '',
            'street_name' => $components['route']['long_name'] ?? '',
            'city' => $components['locality']['long_name'] ?? ($components['postal_town']['long_name'] ?? $row['city']),
            'state' => $components['administrative_area_level_1']['long_name'] ?? '',
            'post_code' => $components['postal_code']['long_name'] ?? $row['postal_code'],
            'country' => $components['country']['long_name'] ?? 'Nederland',
            'country_short' => $components['country']['short_name'] ?? 'NL',
        ];
    }

    private static function google_api_key(): string {
        $key = defined('ZUVI_GOOGLE_MAPS_API_KEY') ? (string) ZUVI_GOOGLE_MAPS_API_KEY : '';

        if (!$key && function_exists('acf_get_setting')) {
            $key = (string) acf_get_setting('google_api_key');
        }

        return (string) apply_filters('zuivel_import_google_api_key', $key);
    }

    private static function address_fingerprint(array $row): string {
        return sha1(
            self::clean($row['address']) . '|' .
            self::clean($row['postal_code']) . '|' .
            self::clean($row['city'])
        );
    }

    private static function clean(string $value): string {
        $value = remove_accents(wp_strip_all_tags($value));
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private static function normalize_header($value): string {
        $value = remove_accents((string) $value);
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[+_\-.\/]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
