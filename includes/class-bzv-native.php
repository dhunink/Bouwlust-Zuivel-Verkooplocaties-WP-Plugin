<?php
if (!defined('ABSPATH')) { exit; }

/** WordPress owns posts and publication; ACF owns address and map editing. */
final class BZV_Native {
    public const TYPE = 'zuivel_verkooppunt';
    public static bool $importing = false;
    public static function init(): void {
        add_filter('acf/fields/google_map/api', [__CLASS__, 'google_key']);
        add_action('acf/validate_save_post', [__CLASS__, 'validate_editor']);
        add_action('wp_after_insert_post', [__CLASS__, 'saved'], 30, 1);
        add_action('acf/save_post', [__CLASS__, 'saved'], 30, 1);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) { add_action($hook, [__CLASS__, 'meta_changed'], 30, 4); }
        add_action('before_delete_post', [__CLASS__, 'purge']);
        add_action('trashed_post', [__CLASS__, 'purge']);
        add_filter('manage_edit-' . self::TYPE . '_columns', static function ($columns) { $columns['bzv_bop'] = 'BOP-status'; return $columns; }, 201);
        add_action('manage_' . self::TYPE . '_posts_custom_column', [__CLASS__, 'column'], 10, 2);
        add_action('add_meta_boxes_' . self::TYPE, [__CLASS__, 'instructions']);
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('init', [__CLASS__, 'register_looper']);
        add_filter('cs_looper_custom_bop_afnemers', [__CLASS__, 'looper']);
        add_shortcode('bop_afnemers', [__CLASS__, 'address_list']);
        add_filter('rocket_cache_reject_uri', [__CLASS__, 'cache_exclusion']);
        add_action('template_redirect', static function () { if (is_page(11088)) { if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); } nocache_headers(); } });
    }
    public static function google_key(array $api): array {
        if (function_exists('gp_address_autocomplete')) {
            $key = gp_address_autocomplete()->get_google_api_key();
            if (is_string($key) && $key !== '') { $api['key'] = $key; }
        }
        return $api;
    }
    public static function fields(): array {
        static $fields = null;
        if ($fields !== null) { return $fields; }
        if (!function_exists('acf_get_field_groups')) { throw new RuntimeException('ACF moet actief zijn.'); }
        $found = [];
        foreach (acf_get_field_groups(['post_type' => self::TYPE]) as $group) {
            foreach ((array) acf_get_fields($group['key']) as $field) {
                if (!in_array($field['name'], ['address', 'postal_code', 'city', 'location'], true)) { continue; }
                if (isset($found[$field['name']]) || $field['type'] !== ($field['name'] === 'location' ? 'google_map' : 'text')) { throw new RuntimeException('Controleer de bestaande ACF-veldgroep: locatievelden zijn niet eenduidig.'); }
                $found[$field['name']] = $field['key'];
            }
        }
        if (count($found) !== 4) { throw new RuntimeException('De ACF-velden address, postal_code, city en location ontbreken.'); }
        return $fields = $found;
    }
    public static function point($value): bool {
        return is_array($value) && isset($value['lat'], $value['lng']) && is_numeric($value['lat']) && is_numeric($value['lng']) &&
            is_finite((float) $value['lat']) && is_finite((float) $value['lng']) && abs((float) $value['lat']) <= 90 && abs((float) $value['lng']) <= 180;
    }
    public static function valid(int $id): bool {
        foreach (['address', 'postal_code', 'city'] as $key) { if (trim((string) get_post_meta($id, $key, true)) === '') { return false; } }
        return trim((string) get_post($id)->post_title) !== '' && self::point(get_post_meta($id, 'location', true));
    }
    public static function available(int $id): bool {
        return BZV_Postoffice::fresh() && get_post_meta($id, '_bop_active', true) === '1' &&
            get_post_meta($id, '_bop_generation', true) === (get_option(BZV_Postoffice::SNAPSHOT, [])['generation'] ?? '');
    }
    public static function validate_editor(): void {
        $id = absint($_POST['post_ID'] ?? 0);
        if (!$id || get_post_type($id) !== self::TYPE || !get_post_meta($id, '_bop_source_id', true) || (($_POST['post_status'] ?? '') !== 'publish' && !isset($_POST['publish']))) { return; }
        if (!self::available($id)) { acf_add_validation_error('', 'Publiceren kan alleen met een actuele, actieve BOP-afnemer.'); }
        foreach (self::fields() as $name => $key) {
            $value = isset($_POST['acf'][$key]) ? wp_unslash($_POST['acf'][$key]) : get_post_meta($id, $name, true);
            if ($name === 'location' && is_string($value)) { $value = json_decode($value, true); }
            if ($name === 'location' ? !self::point($value) : trim((string) $value) === '') {
                acf_add_validation_error('acf[' . $key . ']', 'Vul dit veld in voordat je publiceert.');
            }
        }
    }
    public static function saved($id): void {
        $id = (int) $id;
        if (self::$importing || get_post_type($id) !== self::TYPE || wp_is_post_revision($id)) { return; }
        if (get_post_meta($id, '_bop_source_id', true) && get_post_status($id) === 'publish' && (!self::available($id) || !self::valid($id))) {
            self::$importing = true;
            try { wp_update_post(['ID' => $id, 'post_status' => 'draft']); }
            finally { self::$importing = false; }
            set_transient('bzv_publish_notice_' . get_current_user_id(), true, 60);
        }
        self::purge();
    }
    public static function meta_changed($unused, $id, $key, $value): void {
        if (!self::$importing && get_post_type((int) $id) === self::TYPE && in_array($key, ['address', 'postal_code', 'city', 'location'], true)) { self::purge(); }
    }
    public static function notice(): void {
        $key = 'bzv_publish_notice_' . get_current_user_id();
        if (get_transient($key)) { delete_transient($key); echo '<div class="notice notice-warning"><p>De afnemer is als concept bewaard. Vul adres en kaartpositie in en controleer dat BOP de afnemer als actief aanlevert.</p></div>'; }
    }
    public static function instructions($post): void {
        if (!get_post_meta($post->ID, '_bop_source_id', true)) { return; }
        add_meta_box('bzv-bop-info', 'Afnemer uit BOP', static function ($post) {
            echo '<p>Controleer de naam, het adres en de locatie in het bestaande ACF-kaartveld. Klik daarna op <strong>Publiceren</strong>. Met <strong>Concept opslaan</strong> blijft de locatie verborgen.</p>';
            self::column('bzv_bop', (int) $post->ID);
        }, self::TYPE, 'side');
    }
    public static function column(string $column, int $id): void {
        if ($column !== 'bzv_bop') { return; }
        echo esc_html(!get_post_meta($id, '_bop_source_id', true) ? 'Niet gekoppeld' : (self::available($id) ? 'Actief' : 'Niet actief / lijst verlopen'));
    }
    public static function purge($id = null): void {
        if ($id && get_post_type((int) $id) !== self::TYPE) { return; }
        if (function_exists('rocket_clean_files')) { rocket_clean_files([get_permalink(11088)]); }
    }
    public static function cache_exclusion(array $uris): array { $uris[] = wp_parse_url(get_permalink(11088), PHP_URL_PATH); return array_values(array_unique($uris)); }
    public static function register_looper(): void {
        if (function_exists('cs_looper_provider_register')) { cs_looper_provider_register('bop-afnemers', ['label' => 'BOP afnemers', 'values' => [], 'filter' => [__CLASS__, 'looper']]); }
    }
    public static function looper($unused = [], $args = []): array {
        if (!BZV_Postoffice::fresh()) { return []; }
        $items = [];
        foreach (get_posts(['post_type' => self::TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'meta_key' => '_bop_active', 'meta_value' => '1']) as $post) {
            if (!get_post_meta($post->ID, '_bop_source_id', true) || !self::available($post->ID) || !self::valid($post->ID)) { continue; }
            $point = get_post_meta($post->ID, 'location', true);
            $item = ['postId' => $post->ID, 'name' => $post->post_title, 'address' => get_post_meta($post->ID, 'address', true),
                'postalCode' => get_post_meta($post->ID, 'postal_code', true), 'city' => get_post_meta($post->ID, 'city', true),
                'latitude' => (float) $point['lat'], 'longitude' => (float) $point['lng']];
            $item['mapsUrl'] = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($item['latitude'] . ',' . $item['longitude']);
            $item['popupHtml'] = '<strong>' . esc_html($item['name']) . '</strong><br>' . esc_html($item['address']) . '<br>' . esc_html($item['postalCode'] . ' ' . $item['city']) . '<br><a href="' . esc_url($item['mapsUrl']) . '" target="_blank" rel="noopener noreferrer">Bekijk in Google Maps</a>';
            $items[] = $item;
        }
        return $items;
    }
    public static function address_list(): string {
        $items = self::looper();
        if (!$items) { return '<p>Er zijn op dit moment geen afnemers om te tonen.</p>'; }
        return '<ul class="bop-afnemers">' . implode('', array_map(static fn($item) => '<li>' . $item['popupHtml'] . '</li>', $items)) . '</ul>';
    }
}
