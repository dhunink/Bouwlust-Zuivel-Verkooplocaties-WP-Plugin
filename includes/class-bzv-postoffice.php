<?php
if (!defined('ABSPATH')) { exit; }

/** Small adapter from Postkantoor's full directory to native WordPress posts. */
final class BZV_Postoffice {
    public const SNAPSHOT = 'bzv_directory_snapshot';
    private const LOCK = 'bzv_directory_lock';
    private static ?string $lease = null;
    public static function init(): void {
        add_filter('cron_schedules', static function ($s) { $s['bzv_quarter_hour'] = ['interval' => 900, 'display' => 'Elke 15 minuten (BOP)']; return $s; });
        add_action('init', [__CLASS__, 'schedule']);
        add_action('bzv_bop_locations_sync', [__CLASS__, 'sync']);
        add_action('bzv_bop_locations_expire', [BZV_Native::class, 'purge']);
    }
    public static function schedule(): void {
        if (!wp_next_scheduled('bzv_bop_locations_sync')) { wp_schedule_event(time() + 900, 'bzv_quarter_hour', 'bzv_bop_locations_sync'); }
        if (get_option('bzv_bop_cache_version') !== BZV_VERSION) {
            BZV_Native::purge(); update_option('bzv_bop_cache_version', BZV_VERSION, false);
        }
    }
    public static function deactivate(): void { wp_clear_scheduled_hook('bzv_bop_locations_sync'); wp_clear_scheduled_hook('bzv_bop_locations_expire'); }
    public static function profile(int $id): array {
        $class = '\\GC_API_Alchemist\\Connection_Profiles\\Connection_Profile';
        $profile = $id && class_exists($class) ? $class::get($id) : null;
        if (!$profile || !in_array($profile->get_status(), ['publish', 'private'], true) || $profile->get_auth_type() !== 'bearer') { return ['', '']; }
        $base = rtrim($profile->get_base_url(), '/'); $token = $profile->get_auth_config_value('token', ''); $url = wp_parse_url($base);
        if (!is_string($token) || !preg_match('/^bop_post_v1\.[A-Za-z0-9_-]{3,64}\.[a-f0-9]{64}$/D', $token) ||
            !$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) { return ['', '']; }
        return [$base, $token];
    }
    public static function fetch(): array {
        [$base, $token] = self::profile(BZV_Settings::get()['profile']);
        if (!$token) { throw new RuntimeException('Kies een geldig Postkantoor-profiel bij Instellingen → BOP-koppeling.'); }
        $response = wp_safe_remote_get($base . '/v1/customer-directory', ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1024 * 1024]);
        if (is_wp_error($response)) { throw new RuntimeException('Postkantoor is tijdelijk niet bereikbaar.'); }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) { throw new RuntimeException('Postkantoor geeft nog geen afnemerslijst terug (HTTP ' . $code . ').'); }
        return BZV_Directory::validate(wp_remote_retrieve_body($response), time());
    }
    public static function fresh(): bool { return (int) (get_option(self::SNAPSHOT, [])['publishedTimestamp'] ?? 0) > time() - BZV_Directory::MAX_AGE; }
    private static function acquire(): bool {
        global $wpdb;
        $value = time() . ':' . wp_generate_uuid4();
        if (!add_option(self::LOCK, $value, '', false)) {
            $old = get_option(self::LOCK);
            if (!is_string($old) || (int) $old >= time() - 120) { return false; }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $old));
            wp_cache_delete(self::LOCK, 'options');
            if (!add_option(self::LOCK, $value, '', false)) { return false; }
        }
        self::$lease = $value; return true;
    }
    private static function assert_lock(): void {
        global $wpdb;
        $owner = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK));
        if (self::$lease === null || $owner !== self::$lease || (int) self::$lease <= time() - 120) { throw new RuntimeException('De import duurde te lang. Probeer opnieuw.'); }
    }
    private static function release(): void {
        global $wpdb;
        if (self::$lease !== null) { $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, self::$lease)); wp_cache_delete(self::LOCK, 'options'); self::$lease = null; }
    }
    private static function meta(int $id, string $key, $value): void {
        update_post_meta($id, $key, $value);
        if (get_post_meta($id, $key, true) !== $value) { throw new RuntimeException('De BOP-koppeling kon niet volledig worden opgeslagen.'); }
    }
    private static function draft(int $id): void {
        $result = wp_update_post(['ID' => $id, 'post_status' => 'draft'], true);
        if (is_wp_error($result) || get_post_status($id) !== 'draft') { throw new RuntimeException('Een gearchiveerde afnemer kon niet op concept worden gezet.'); }
    }
    public static function sync(bool $manual = false): array {
        if (!$manual && !BZV_Settings::get()['automatic']) { return []; }
        if (!self::acquire()) { return ['ok' => false, 'message' => 'Er loopt al een import.']; }
        try {
            $directory = self::fetch(); $fields = BZV_Native::fields();
            if (!post_type_exists(BZV_Native::TYPE)) { throw new RuntimeException('Het posttype zuivel_verkooppunt ontbreekt.'); }
            $before = get_option(self::SNAPSHOT, []);
            if ($before && (BZV_Directory::generation($directory['generation'], $before['generation']) < 0 ||
                ($directory['generation'] === $before['generation'] && $directory['eTag'] !== $before['eTag']))) { throw new RuntimeException('Een oudere of tegenstrijdige afnemerslijst is geweigerd.'); }
            $posts = get_posts(['post_type' => BZV_Native::TYPE, 'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'], 'numberposts' => -1]);
            $owners = []; $slugs = [];
            foreach ($posts as $post) {
                $slugs[$post->post_name][] = $post->ID;
                foreach ((array) get_post_meta($post->ID, '_bop_identities', true) as $key) { if ($key !== '') { $owners[$key][] = $post->ID; } }
                $key = get_post_meta($post->ID, '_bop_source_id', true); if ($key) { $owners[$key][] = $post->ID; }
            }
            // Resolve the whole batch before writes: an ambiguous split/merge needs BOP correction.
            $plan = []; $claimed = [];
            foreach ($directory['customers'] as $customer) {
                $slug = 'bop-' . substr(hash('sha256', $customer['customerId']), 0, 32); $matches = $slugs[$slug] ?? [];
                foreach ([$customer['customerId'], ...$customer['aliases']] as $key) { $matches = [...$matches, ...($owners[$key] ?? [])]; }
                $matches = array_values(array_unique($matches)); $id = (int) ($matches[0] ?? 0);
                if (count($matches) > 1 || ($id && isset($claimed[$id]))) { delete_option(self::SNAPSHOT); throw new RuntimeException('BOP bevat een onduidelijke klantkoppeling. Los de dubbele of gesplitste koppeling eerst in BOP op.'); }
                if ($id) { $claimed[$id] = true; }
                $plan[] = [$id, $slug, $customer];
            }
            BZV_Native::$importing = true; $created = 0; $seen = [];
            foreach ($plan as [$id, $slug, $customer]) {
                self::assert_lock();
                if (!$id) {
                    $id = wp_insert_post(wp_slash(['post_type' => BZV_Native::TYPE, 'post_status' => 'draft', 'post_title' => $customer['name'], 'post_name' => $slug,
                        'meta_input' => ['_bop_source_id' => $customer['customerId']]]), true);
                    if (is_wp_error($id) || !$id) { throw new RuntimeException('Een nieuwe afnemerpost kon niet worden aangemaakt.'); }
                    $created++;
                }
                self::meta($id, '_bop_source_id', $customer['customerId']);
                $identities = array_values(array_unique(array_filter([...((array) get_post_meta($id, '_bop_identities', true)), $customer['customerId'], ...$customer['aliases']])));
                self::meta($id, '_bop_identities', $identities);
                // Initialise once; later BOP refreshes never overwrite the editor's local fields.
                if (!get_post_meta($id, '_bop_initialized', true)) {
                    if (isset($fields['show_on_map']) && get_post_meta($id, 'show_on_map', true) === '') {
                        update_field($fields['show_on_map'], '1', $id);
                        if (get_post_meta($id, 'show_on_map', true) !== '1') { throw new RuntimeException('De standaard kaartkeuze kon niet worden opgeslagen.'); }
                    }
                    self::meta($id, '_bop_address', trim($customer['address'] . ', ' . $customer['postalCode'] . ' ' . $customer['city'], ', '));
                    self::meta($id, '_bop_initialized', '1');
                }
                self::meta($id, '_bop_active', $customer['active'] ? '1' : '0');
                self::meta($id, '_bop_generation', $directory['generation']);
                if (!$customer['active'] && get_post_status($id) === 'publish') { self::draft($id); }
                $seen[$id] = true;
            }
            foreach ($posts as $post) {
                if (!isset($seen[$post->ID]) && get_post_meta($post->ID, '_bop_source_id', true)) {
                    self::assert_lock(); self::meta($post->ID, '_bop_active', '0');
                    if ($post->post_status === 'publish') { self::draft($post->ID); }
                }
            }
            self::assert_lock(); $snapshot = array_intersect_key($directory, array_flip(['generation', 'eTag', 'publishedTimestamp']));
            if ($snapshot !== get_option(self::SNAPSHOT) && !update_option(self::SNAPSHOT, $snapshot, false)) { throw new RuntimeException('De importstatus kon niet worden opgeslagen.'); }
            wp_clear_scheduled_hook('bzv_bop_locations_expire'); wp_schedule_single_event($directory['publishedTimestamp'] + BZV_Directory::MAX_AGE, 'bzv_bop_locations_expire');
            $result = ['ok' => true, 'count' => count($seen), 'created' => $created, 'message' => count($seen) . ' afnemers verwerkt; ' . $created . ' nieuwe concepten.'];
            if (self::notify(array_keys($seen))) { $result['message'] .= ' Een of meer meldingen konden niet worden overgedragen; controleer het SMTP-log.'; }
        } catch (RuntimeException $e) { $result = ['ok' => false, 'message' => $e->getMessage()]; }
        finally { BZV_Native::$importing = false; self::release(); BZV_Native::purge(); }
        $result['at'] = time(); update_option('bzv_directory_status', $result, false); return $result;
    }
    private static function notify(array $ids): int {
        $settings = BZV_Settings::get(); if (!$settings['email']) { return 0; }
        $failed = 0;
        $users = $settings['recipients'] ? get_users(['include' => $settings['recipients'], 'fields' => ['ID', 'user_email']]) : [];
        $recipients = array_unique(array_filter(array_map(static fn($user) => strtolower($user->user_email), $users), 'is_email'));
        foreach ($recipients as $email) {
            $pending = array_values(array_filter($ids, static fn($id) => get_post_status($id) === 'draft' && get_post_meta($id, '_bop_active', true) === '1' && !get_post_meta($id, '_bop_mail_' . hash('sha256', $email), true)));
            if (!$pending) { continue; }
            self::assert_lock(); $key = '_bop_mail_' . hash('sha256', $email);
            // Mark before handing off: a crash must not send the same automatic notice twice.
            foreach ($pending as $id) { self::meta($id, $key, 'sending'); }
            $single = count($pending) === 1;
            $html = '<p>Er ' . ($single ? 'is een nieuwe afnemer: ' . esc_html(get_post($pending[0])->post_title) : 'zijn ' . count($pending) . ' afnemers') . ' om te controleren. Controleer het adres en de kaartpositie en klik daarna op Publiceren.</p>';
            $url = $single ? 'post.php?post=' . $pending[0] . '&action=edit' : 'edit.php?post_type=' . BZV_Native::TYPE . '&post_status=draft';
            $html .= '<p><a style="display:inline-block;padding:12px 18px;background:#2271b1;color:#fff;text-decoration:none" href="' . esc_url(admin_url($url)) . '">' . ($single ? 'Locatie controleren' : 'Afnemers controleren') . '</a></p>';
            $accepted = wp_mail($email, 'Hoeve Bouwlust: afnemerlocatie controleren', $html, ['Content-Type: text/html; charset=UTF-8']);
            foreach ($pending as $id) { update_post_meta($id, $key, $accepted ? 'accepted' : 'failed'); }
            if (!$accepted) { $failed++; }
        }
        return $failed;
    }
}
