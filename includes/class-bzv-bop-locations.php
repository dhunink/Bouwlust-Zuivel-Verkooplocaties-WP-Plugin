<?php
/** BOP is the sole source for the public map. No customer/order data or credentials reach the browser. */
if (!defined('ABSPATH')) { exit; }

final class BZV_Bop_Locations {
    private const SNAPSHOT = 'bzv_bop_locations_snapshot';
    private const STATUS = 'bzv_bop_locations_status';
    private const LOCK = 'bzv_bop_locations_lock';
    private const CRON = 'bzv_bop_locations_sync';
    private const EXPIRE = 'bzv_bop_locations_expire';
    public const MAX_AGE = 48 * HOUR_IN_SECONDS;

    public static function init(): void {
        add_filter('cron_schedules', [__CLASS__, 'schedules']);
        add_action('init', [__CLASS__, 'schedule']);
        add_action('init', [__CLASS__, 'register_looper']);
        add_action(self::CRON, [__CLASS__, 'sync']);
        add_action(self::EXPIRE, [__CLASS__, 'expire']);
        add_filter('cs_looper_custom_bop_afnemers', [__CLASS__, 'looper']);
        add_shortcode('bop_afnemers', [__CLASS__, 'address_list']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_bzv_bop_refresh', [__CLASS__, 'refresh']);
        add_action('admin_post_bzv_bop_connection', [__CLASS__, 'save_connection']);
        // Cached pages must not outlive the BOP publication. In addition to cron purges,
        // the map/list page bypasses full-page caches. The durable data cache still avoids API calls on visits.
        add_action('template_redirect', [__CLASS__, 'page_cache_policy']);
        add_filter('rocket_cache_reject_uri', [__CLASS__, 'rocket_exclusions']);
    }

    public static function schedules(array $schedules): array {
        $schedules['bzv_quarter_hour'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Elke 15 minuten (BOP)'];
        return $schedules;
    }
    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 10, 'bzv_quarter_hour', self::CRON); }
        if (get_option('bzv_bop_cache_version') !== BZV_VERSION) {
            if (function_exists('rocket_generate_config_file')) { rocket_generate_config_file(); }
            self::purge();
            update_option('bzv_bop_cache_version', BZV_VERSION, false);
        }
    }
    public static function register_looper(): void {
        if (function_exists('cs_looper_provider_register')) {
            cs_looper_provider_register('bop-afnemers', ['label' => 'BOP afnemers', 'values' => [], 'filter' => [__CLASS__, 'looper']]);
        }
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON);
        wp_clear_scheduled_hook(self::EXPIRE);
        self::purge();
    }
    private static function page_ids(): array {
        return array_values(array_filter(array_map('absint', (array) apply_filters('bzv_bop_locations_page_ids', [11088]))));
    }
    public static function page_cache_policy(): void {
        if (is_page(self::page_ids())) {
            if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
            nocache_headers();
        }
    }
    public static function rocket_exclusions(array $uris): array {
        foreach (self::page_ids() as $id) {
            $path = wp_parse_url(get_permalink($id), PHP_URL_PATH);
            if ($path) { $uris[] = $path; }
        }
        return array_values(array_unique($uris));
    }
    private static function purge(): void {
        if (!function_exists('rocket_clean_files')) { return; }
        $urls = array_values(array_filter(array_map('get_permalink', self::page_ids())));
        if ($urls) { rocket_clean_files($urls); }
    }
    private static function config(): array {
        if (defined('BOP_CUSTOMER_LOCATIONS_URL') || defined('BOP_CUSTOMER_LOCATIONS_TOKEN')) {
            return [defined('BOP_CUSTOMER_LOCATIONS_URL') ? rtrim((string) BOP_CUSTOMER_LOCATIONS_URL, '/') : '',
                defined('BOP_CUSTOMER_LOCATIONS_TOKEN') ? (string) BOP_CUSTOMER_LOCATIONS_TOKEN : ''];
        }
        return self::profile_config(absint(get_option('bzv_bop_connection_profile', 0)));
    }
    private static function profile_config(int $id): array {
        $class = '\\GC_API_Alchemist\\Connection_Profiles\\Connection_Profile';
        if (!$id || !class_exists($class)) { return ['', '']; }
        $profile = $class::get($id);
        if (!$profile || !in_array($profile->get_status(), ['publish', 'private'], true) || $profile->get_auth_type() !== 'bearer') { return ['', '']; }
        $token = $profile->get_auth_config_value('token', '');
        $base = rtrim($profile->get_base_url(), '/');
        $url = wp_parse_url($base);
        if (!is_string($token) || !preg_match('/^bop_post_v1\.[A-Za-z0-9_-]{3,64}\.[a-f0-9]{64}$/D', $token) ||
            !$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) ||
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) { return ['', '']; }
        return [$base, $token];
    }
    private static function status(string $message, bool $success = false): void {
        $old = get_option(self::STATUS, []);
        update_option(self::STATUS, ['attemptedAt' => time(), 'successAt' => $success ? time() : ($old['successAt'] ?? null),
            'message' => $message, 'ok' => $success], false);
    }

    public static function sync(): void {
        // add_option is a database-backed exclusive lock, including with a persistent object cache.
        if (!add_option(self::LOCK, time(), '', false)) {
            $lock = (int) get_option(self::LOCK, 0);
            if ($lock && $lock < time() - 120) { delete_option(self::LOCK); }
            return;
        }
        try {
            self::expire();
            [$base, $token] = self::config();
            $url = wp_parse_url($base);
            if (!$token || preg_match('/[\r\n]/', $token) || !$url || ($url['scheme'] ?? '') !== 'https' ||
                empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                self::status('De Postkantoor-verbinding moet nog worden ingesteld.');
                return;
            }
            $old = get_option(self::SNAPSHOT, null);
            $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
            if (is_array($old) && isset($old['eTag'])) { $headers['If-None-Match'] = $old['eTag']; }
            $response = wp_safe_remote_get($base . '/v1/customer-locations', [
                'headers' => $headers, 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 1024 * 1024,
                'sslverify' => true,
            ]);
            if (is_wp_error($response)) { self::status('Postkantoor is tijdelijk niet bereikbaar.'); return; }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 304 && is_array($old) && self::is_fresh($old)) {
                // A successful check is not a new BOP publication. Never extend publishedAt here.
                self::status('Lijst gecontroleerd; geen wijzigingen.', true);
                return;
            }
            if ($code !== 200) { self::status('Postkantoor gaf geen geldige lijst terug (HTTP ' . $code . ').'); return; }
            $snapshot = self::validate(wp_remote_retrieve_body($response), time());
            if (is_wp_error($snapshot)) { self::status($snapshot->get_error_message()); return; }
            // Generations exceed JavaScript's safe integer range; preserve and compare them as decimal strings.
            if (is_array($old) && self::compare_generation($snapshot['generation'], $old['generation']) < 0) {
                self::status('Een oudere versie is genegeerd.'); return;
            }
            if (is_array($old) && $snapshot['generation'] === $old['generation'] && $snapshot !== $old) {
                self::status('Tegenstrijdige versie ontvangen; de vorige lijst blijft bewaard.'); return;
            }
            $changed = $snapshot !== $old;
            if ($changed && !update_option(self::SNAPSHOT, $snapshot, false)) {
                self::status('De nieuwe lijst kon niet worden opgeslagen.'); return;
            }
            wp_clear_scheduled_hook(self::EXPIRE);
            wp_schedule_single_event($snapshot['publishedTimestamp'] + self::MAX_AGE, self::EXPIRE);
            if ($changed) { self::purge(); }
            self::status(count($snapshot['locations']) . ' afnemers beschikbaar vanuit BOP.', true);
        } finally { delete_option(self::LOCK); }
    }
    private static function compare_generation(string $a, string $b): int {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }
    private static function is_fresh(array $snapshot): bool {
        return isset($snapshot['publishedTimestamp']) && $snapshot['publishedTimestamp'] > time() - self::MAX_AGE;
    }
    public static function expire(): void {
        $snapshot = get_option(self::SNAPSHOT, null);
        if (is_array($snapshot) && !self::is_fresh($snapshot)) {
            // Delete cached Google coordinates too, rather than retaining an expired hidden list forever.
            delete_option(self::SNAPSHOT);
            self::purge();
        }
    }

    /** Validate the WHOLE replacement, never publish a partial or sanitized-away list. */
    public static function validate(string $body, int $now) {
        $data = json_decode($body, true, 32, JSON_BIGINT_AS_STRING);
        $fail = static fn() => new WP_Error('invalid_bop_snapshot', 'Postkantoor gaf onvolledige of verouderde gegevens terug.');
        if (!is_array($data) || !isset($data['generation'], $data['publishedAt'], $data['assessedOn'], $data['eTag'], $data['heading'], $data['locations'])) { return $fail(); }
        if (!is_string($data['generation']) && !is_int($data['generation'])) { return $fail(); }
        $generation = (string) $data['generation'];
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $generation) || self::compare_generation($generation, '9223372036854775807') > 0 ||
            !is_string($data['publishedAt']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})$/D', $data['publishedAt']) ||
            !is_string($data['eTag']) || !preg_match('/^"[a-fA-F0-9]{64}"$/D', $data['eTag']) || $data['heading'] !== 'Hier vind je' ||
            !is_array($data['locations']) || !array_is_list($data['locations']) || count($data['locations']) > 500) { return $fail(); }
        $published = strtotime($data['publishedAt']);
        if ($published === false || $published <= $now - self::MAX_AGE || $published > $now + 600) { return $fail(); }
        $amsterdam = (new DateTimeImmutable('@' . $published))->setTimezone(new DateTimeZone('Europe/Amsterdam'));
        if ($data['assessedOn'] !== $amsterdam->format('Y-m-d')) { return $fail(); }
        $ids = [];
        $locations = [];
        foreach ($data['locations'] as $item) {
            if (!is_array($item) || !isset($item['locationId']) || !is_string($item['locationId']) ||
                !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $item['locationId']) ||
                $item['locationId'] === '00000000-0000-0000-0000-000000000000' || isset($ids[strtolower($item['locationId'])])) { return $fail(); }
            $ids[strtolower($item['locationId'])] = true;
            foreach (['name' => 300, 'address' => 300, 'postalCode' => 20, 'city' => 160, 'countryCode' => 2] as $key => $max) {
                if (!isset($item[$key]) || !is_string($item[$key]) || !trim($item[$key]) ||
                    preg_match('/[\x00-\x1f\x7f]/', $item[$key]) || preg_match_all('/./us', $item[$key]) > $max) { return $fail(); }
            }
            if (!preg_match('/^[A-Z]{2}$/D', $item['countryCode'])) { return $fail(); }
            foreach (['latitude' => 90, 'longitude' => 180] as $key => $max) {
                if (!isset($item[$key]) || !(is_int($item[$key]) || is_float($item[$key])) || !is_finite((float) $item[$key]) || abs($item[$key]) > $max) { return $fail(); }
            }
            // A strict allowlist keeps unexpected internal data out of WordPress storage and markup.
            $locations[] = array_intersect_key($item, array_flip(['locationId', 'name', 'address', 'postalCode', 'city', 'countryCode', 'latitude', 'longitude']));
        }
        usort($locations, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['locationId'], $b['locationId']));
        return ['generation' => $generation, 'publishedAt' => $data['publishedAt'], 'publishedTimestamp' => $published,
            'assessedOn' => $data['assessedOn'], 'eTag' => $data['eTag'], 'locations' => $locations];
    }

    public static function looper($unused = [], $args = []): array {
        self::expire();
        $snapshot = get_option(self::SNAPSHOT, null);
        if (!is_array($snapshot) || !self::is_fresh($snapshot)) { return []; }
        return array_map(static function ($item) {
            $item['mapsUrl'] = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($item['latitude'] . ',' . $item['longitude']);
            $item['popupHtml'] = '<strong>' . esc_html($item['name']) . '</strong><br>' . esc_html($item['address']) . '<br>' .
                esc_html($item['postalCode'] . ' ' . $item['city']) . '<br><a href="' . esc_url($item['mapsUrl']) . '" target="_blank" rel="noopener noreferrer">Bekijk in Google Maps</a>';
            return $item;
        }, $snapshot['locations']);
    }
    public static function address_list(): string {
        $items = self::looper();
        if (!$items) {
            $snapshot = get_option(self::SNAPSHOT, null);
            return '<p class="bop-afnemers-empty">' . (is_array($snapshot) ? 'Er zijn op dit moment geen afnemers om te tonen.' : 'De lijst met afnemers is tijdelijk niet beschikbaar.') . '</p>';
        }
        $html = '<ul class="bop-afnemers">';
        foreach ($items as $item) { $html .= '<li>' . $item['popupHtml'] . '</li>'; }
        return $html . '</ul>';
    }
    public static function menu(): void {
        add_management_page('BOP afnemers', 'BOP afnemers', 'manage_options', 'bop-afnemers', [__CLASS__, 'admin']);
    }
    public static function refresh(): void {
        if (!current_user_can('manage_options')) { wp_die('Geen toegang.', '', ['response' => 403]); }
        check_admin_referer('bzv_bop_refresh');
        self::sync();
        wp_safe_redirect(admin_url('tools.php?page=bop-afnemers'));
        exit;
    }
    public static function save_connection(): void {
        if (!current_user_can('manage_options')) { wp_die('Geen toegang.', '', ['response' => 403]); }
        check_admin_referer('bzv_bop_connection');
        if (defined('BOP_CUSTOMER_LOCATIONS_URL') || defined('BOP_CUSTOMER_LOCATIONS_TOKEN')) { wp_die('De verbinding wordt beheerd in de serverconfiguratie.'); }
        $id = absint($_POST['connection_profile'] ?? 0);
        if (!self::profile_config($id)[1]) { wp_die('Kies een geldig Postkantoor-profiel met een websitecredential.'); }
        if (!add_option(self::LOCK, time(), '', false)) { wp_die('Er loopt een controle. Probeer het over een minuut opnieuw.'); }
        try {
            if (absint(get_option('bzv_bop_connection_profile', 0)) !== $id) {
                update_option('bzv_bop_connection_profile', $id, false);
                delete_option(self::SNAPSHOT);
                wp_clear_scheduled_hook(self::EXPIRE);
                self::purge();
            }
        } finally { delete_option(self::LOCK); }
        self::sync();
        wp_safe_redirect(admin_url('tools.php?page=bop-afnemers'));
        exit;
    }
    public static function admin(): void {
        if (!current_user_can('manage_options')) { return; }
        self::expire();
        $snapshot = get_option(self::SNAPSHOT, null);
        $status = get_option(self::STATUS, []);
        echo '<div class="wrap"><h1>BOP afnemers</h1><p>Beheer de afnemers, hun adres en kaartpositie in BOP. Deze website toont uitsluitend de gecontroleerde actieve lijst van het Postkantoor.</p>';
        echo '<p>' . esc_html($status['message'] ?? 'De koppeling is nog niet gecontroleerd.') . '</p>';
        if ($snapshot) {
            echo '<p>Laatste BOP-publicatie: ' . esc_html(wp_date('d-m-Y H:i', $snapshot['publishedTimestamp'])) . '. ' . count($snapshot['locations']) . ' afnemers.</p>';
        }
        if (!empty($status['attemptedAt'])) { echo '<p>Laatste controle: ' . esc_html(wp_date('d-m-Y H:i', $status['attemptedAt'])) . '.</p>'; }
        echo '<p>Automatische controle: elke 15 minuten. Zonder nieuwe BOP-publicatie vervalt de lijst na 48 uur.</p>';
        $class = '\\GC_API_Alchemist\\Connection_Profiles\\Connection_Profile';
        if (!defined('BOP_CUSTOMER_LOCATIONS_URL') && !defined('BOP_CUSTOMER_LOCATIONS_TOKEN') && class_exists($class)) {
            echo '<h2>Postkantoor-verbinding</h2><p>Kies het bestaande websiteprofiel. De sleutel blijft in API Connections opgeslagen en wordt hier niet getoond of gekopieerd.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bzv_bop_connection">';
            wp_nonce_field('bzv_bop_connection');
            echo '<label for="bop-connection-profile">Verbindingsprofiel</label> <select id="bop-connection-profile" name="connection_profile"><option value="0">Kies een verbinding</option>';
            foreach ($class::get_all() as $profile) {
                if (!self::profile_config($profile->get_id())[1]) { continue; }
                echo '<option value="' . (int) $profile->get_id() . '"' . selected((int) get_option('bzv_bop_connection_profile', 0), $profile->get_id(), false) . '>' . esc_html($profile->get_title()) . '</option>';
            }
            echo '</select>';
            submit_button('Verbinding opslaan en controleren');
            echo '</form>';
        }
        echo '<p><a href="https://erf.bwlst.nl/apps/bop/beheer/afnemers?view=review" target="_blank" rel="noopener noreferrer">Afnemers controleren in BOP</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bzv_bop_refresh">';
        wp_nonce_field('bzv_bop_refresh');
        submit_button('Lijst nu ophalen');
        echo '</form></div>';
    }
}
