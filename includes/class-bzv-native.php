<?php
if (!defined('ABSPATH')) { exit; }

/** WordPress owns posts and publication; ACF owns address and map editing. */
final class BZV_Native {
    public const TYPE = 'zuivel_verkooppunt';
    public static bool $importing = false;
    public static function init(): void {
        add_filter('acf/fields/google_map/api', [__CLASS__, 'google_key']);
        add_action('acf/validate_save_post', [__CLASS__, 'validate_editor']);
        foreach (['field_bzv_source_status', 'field_bzv_website_status', 'field_bzv_source_address'] as $key) { add_filter('acf/prepare_field/key=' . $key, [__CLASS__, 'status_field']); }
        add_action('wp_after_insert_post', [__CLASS__, 'saved'], 30, 1);
        add_action('acf/save_post', [__CLASS__, 'saved'], 30, 1);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) { add_action($hook, [__CLASS__, 'meta_changed'], 30, 4); }
        add_action('before_delete_post', [__CLASS__, 'purge']);
        add_action('trashed_post', [__CLASS__, 'purge']);
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('init', [__CLASS__, 'register_looper']);
    }
    public static function google_key(array $api): array {
        if (empty($api['key']) && function_exists('gp_address_autocomplete')) {
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
                if (!in_array($field['name'], ['location', 'show_on_map'], true)) { continue; }
                $type = ['location' => 'google_map', 'show_on_map' => 'true_false'][$field['name']] ?? 'text';
                if (isset($found[$field['name']]) || $field['type'] !== $type) { throw new RuntimeException('Controleer de bestaande ACF-veldgroep: locatievelden zijn niet eenduidig.'); }
                $found[$field['name']] = $field['key'];
            }
        }
        if (count($found) !== 2) { throw new RuntimeException('De ACF-velden location en show_on_map ontbreken.'); }
        return $fields = $found;
    }
    public static function point($value): bool {
        return is_array($value) && isset($value['lat'], $value['lng']) && is_numeric($value['lat']) && is_numeric($value['lng']) &&
            is_finite((float) $value['lat']) && is_finite((float) $value['lng']) && abs((float) $value['lat']) <= 90 && abs((float) $value['lng']) <= 180;
    }
    public static function valid_location($value): bool {
        return self::point($value) && is_string($value['address'] ?? null) && trim($value['address']) !== '';
    }
    public static function valid(int $id): bool {
        return trim((string) get_post($id)->post_title) !== '' && self::valid_location(get_post_meta($id, 'location', true));
    }
    public static function show_on_map(int $id): bool {
        $value = get_post_meta($id, 'show_on_map', true);
        // Match the native ACF default; existing approved locations need no migration.
        return $value === '' || (string) $value === '1';
    }
    public static function available(int $id): bool {
        return BZV_Postoffice::fresh() && get_post_meta($id, '_bop_active', true) === '1' &&
            get_post_meta($id, '_bop_generation', true) === (get_option(BZV_Postoffice::SNAPSHOT, [])['generation'] ?? '');
    }
    public static function validate_editor(): void {
        $id = absint($_POST['post_ID'] ?? $_POST['_acf_post_id'] ?? 0);
        if (!$id || get_post_type($id) !== self::TYPE || !get_post_meta($id, '_bop_source_id', true) ||
            (($_POST['post_status'] ?? '') !== 'publish' && !isset($_POST['publish']) && get_post_status($id) !== 'publish')) { return; }
        if (!self::available($id)) { acf_add_validation_error('', 'Publiceren kan alleen met een actuele, actieve BOP-afnemer.'); }
        $visibility = self::fields()['show_on_map'] ?? '';
        $show = isset($_POST['acf'][$visibility]) ? (string) wp_unslash($_POST['acf'][$visibility]) === '1' : self::show_on_map($id);
        if (!$show) { return; }
        $key = self::fields()['location'];
        $value = isset($_POST['acf'][$key]) ? wp_unslash($_POST['acf'][$key]) : get_post_meta($id, 'location', true);
        if (is_string($value)) { $value = json_decode($value, true); }
        if (!self::valid_location($value)) { acf_add_validation_error('acf[' . $key . ']', 'Selecteer een adres in het Google-kaartveld voordat je publiceert.'); }
    }
    public static function saved($id): void {
        $id = (int) $id;
        if (self::$importing || get_post_type($id) !== self::TYPE || wp_is_post_revision($id)) { return; }
        if (get_post_meta($id, '_bop_source_id', true) && get_post_status($id) === 'publish' && (!self::available($id) || (self::show_on_map($id) && !self::valid($id)))) {
            self::$importing = true;
            try { wp_update_post(['ID' => $id, 'post_status' => 'draft']); }
            finally { self::$importing = false; }
            set_transient('bzv_publish_notice_' . get_current_user_id(), true, 60);
        }
        self::purge();
    }
    public static function meta_changed($unused, $id, $key, $value): void {
        if (!self::$importing && get_post_type((int) $id) === self::TYPE && in_array($key, ['location', 'show_on_map'], true)) { self::purge(); }
    }
    public static function notice(): void {
        $key = 'bzv_publish_notice_' . get_current_user_id();
        if (get_transient($key)) { delete_transient($key); echo '<div class="notice notice-warning"><p>De afnemer is als concept bewaard. Selecteer een adres in het Google-kaartveld en controleer de alleen-lezen BOP-status.</p></div>'; }
    }
    public static function source_status(int $id): array {
        if (!get_post_meta($id, '_bop_source_id', true)) { return ['Niet gekoppeld aan BOP', 'Deze post heeft geen BOP-afnemerkoppeling.']; }
        if (!BZV_Postoffice::fresh()) { return ['BOP-status niet actueel', 'De laatste BOP-lijst is ouder dan 48 uur of ontbreekt. De afnemer blijft verborgen tot de verbinding is bijgewerkt.']; }
        if (get_post_meta($id, '_bop_active', true) !== '1') { return ['Inactieve afnemer', 'Deze afnemer voldoet volgens BOP momenteel niet aan de afnemerregels en wordt niet op de kaart getoond.']; }
        if (!self::available($id)) { return ['Synchronisatie onvolledig', 'De afnemer is nog niet volledig bijgewerkt uit de nieuwste BOP-lijst.']; }
        return ['Actieve afnemer', 'BOP bepaalt op basis van bestellingen dat deze afnemer actief is. Met Tonen op de openbare kaart op Ja verschijnt de afnemer na locatiecontrole en Publiceren op de kaart.'];
    }
    public static function website_status(int $id): string {
        if (!self::show_on_map($id)) { return 'Verborgen — tonen op de openbare kaart staat uit'; }
        if (!self::available($id)) { return 'Verborgen — zie BOP-status'; }
        if (!self::valid($id)) { return 'Verborgen — selecteer een Google-locatie'; }
        return get_post_status($id) === 'publish' ? 'Zichtbaar op de zuivelkaart' : 'Verborgen — nog niet gepubliceerd';
    }
    public static function status_field($field) {
        $id = (int) acf_get_form_data('post_id');
        if (get_post_type($id) !== self::TYPE) { return $field; }
        $stamp = get_option(BZV_Postoffice::SNAPSHOT, [])['publishedTimestamp'] ?? 0;
        $field['message'] = match ($field['key']) {
            'field_bzv_source_status' => implode("\n", self::source_status($id)) . ($stamp ? "\nBOP-brongegevens: " . wp_date('d-m-Y H:i', $stamp) : ''),
            'field_bzv_source_address' => get_post_meta($id, '_bop_address', true) ?: trim(implode(' ', array_map(static fn($key) => get_post_meta($id, $key, true), ['address', 'postal_code', 'city']))),
            default => self::website_status($id),
        };
        return $field;
    }
    public static function purge($id = null): void {
        if ($id && get_post_type((int) $id) !== self::TYPE) { return; }
        $page = BZV_Settings::get()['page'];
        if ($page && function_exists('rocket_clean_files')) { rocket_clean_files([get_permalink($page)]); }
    }
    public static function register_looper(): void {
        if (function_exists('cs_looper_provider_register')) { cs_looper_provider_register('bop-afnemers', ['label' => 'BOP afnemers', 'values' => [], 'filter' => [__CLASS__, 'looper']]); }
    }
    public static function looper($unused = [], $args = []): array {
        if (!BZV_Postoffice::fresh()) { return []; }
        $items = [];
        foreach (get_posts(['post_type' => self::TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'meta_key' => '_bop_active', 'meta_value' => '1']) as $post) {
            if (!get_post_meta($post->ID, '_bop_source_id', true) || !self::show_on_map($post->ID) || !self::available($post->ID) || !self::valid($post->ID)) { continue; }
            $point = get_post_meta($post->ID, 'location', true);
            // Only data crosses into Pro; popup and list markup live in the page template.
            $items[] = ['postId' => $post->ID, 'name' => esc_html($post->post_title), 'address' => esc_html($point['address']),
                'latitude' => (float) $point['lat'], 'longitude' => (float) $point['lng']];
        }
        return $items;
    }
}
