<?php

if (!defined('ABSPATH')) {
    exit;
}

final class BZV_Maintenance {
    private const CPT = 'zuivel_verkooppunt';
    private const MANAGED_META = '_zuivel_import_managed';
    private const SOURCE_FINGERPRINT_META = '_zuivel_import_source_fingerprint';

    public static function count_missing_locations(): int {
        return count(self::missing_location_ids());
    }

    public static function retry_missing_locations(): array {
        $result = [
            'attempted' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (!function_exists('update_field')) {
            $result['errors'][] = 'ACF is niet actief.';
            $result['failed'] = self::count_missing_locations();
            return $result;
        }

        foreach (self::missing_location_ids() as $post_id) {
            $row = self::row_from_post($post_id);
            if ($row['address'] === '' || $row['postal_code'] === '' || $row['city'] === '') {
                $result['failed']++;
                $result['errors'][] = get_the_title($post_id) . ': adresgegevens zijn niet compleet.';
                continue;
            }

            $result['attempted']++;
            $geo = self::geocode($row);
            if (is_wp_error($geo)) {
                $result['failed']++;
                $result['errors'][] = get_the_title($post_id) . ': ' . $geo->get_error_message();
                continue;
            }

            $field_key = self::field_key('location');
            update_field($field_key ?: 'location', $geo, $post_id);
            update_post_meta($post_id, self::SOURCE_FINGERPRINT_META, self::address_fingerprint($row));
            $result['succeeded']++;
        }

        update_option('bzv_last_geocode_retry', [
            'timestamp' => time(),
            'result' => $result,
        ], false);

        return $result;
    }

    private static function missing_location_ids(): array {
        $ids = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => self::MANAGED_META,
            'meta_value' => '1',
        ]);

        $missing = [];
        foreach ($ids as $post_id) {
            $location = function_exists('get_field') ? get_field('location', (int) $post_id) : null;
            if (!$location) {
                $missing[] = (int) $post_id;
            }
        }
        return $missing;
    }

    private static function row_from_post(int $post_id): array {
        return [
            'address' => self::first_value($post_id, ['address', 'adress']),
            'postal_code' => strtoupper(self::first_value($post_id, ['postal_code', '_postal_code', '__postal_code'])),
            'city' => self::first_value($post_id, ['city']),
        ];
    }

    private static function first_value(int $post_id, array $names): string {
        foreach ($names as $name) {
            $raw = get_post_meta($post_id, $name, true);
            if ($raw !== '' && $raw !== null) {
                return trim((string) $raw);
            }

            if (function_exists('get_field')) {
                $value = get_field($name, $post_id);
                if (is_scalar($value) && (string) $value !== '') {
                    return trim((string) $value);
                }
            }
        }
        return '';
    }

    private static function field_key(string $name): string {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return '';
        }

        $groups = acf_get_field_groups(['post_type' => self::CPT]);
        if (!$groups) {
            $groups = acf_get_field_groups();
        }

        foreach ($groups as $group) {
            $fields = acf_get_fields($group['key']);
            if (!$fields) {
                continue;
            }
            foreach ($fields as $field) {
                if (($field['name'] ?? '') === $name && !empty($field['key'])) {
                    return (string) $field['key'];
                }
            }
        }
        return '';
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
        $value = function_exists('remove_accents') ? remove_accents($value) : $value;
        $value = wp_strip_all_tags($value);
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
