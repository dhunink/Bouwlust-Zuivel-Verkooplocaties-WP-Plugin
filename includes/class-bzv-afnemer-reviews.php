<?php
if (!defined('ABSPATH')) { exit; }

final class BZV_Afnemer_Reviews {
    public const STATE = 'bzv_afnemer_state';
    public const SETTINGS = 'bzv_afnemer_settings';
    private const LOCK = 'bzv_afnemer_lock';
    private static ?string $lock = null;
    public static function settings(): array {
        $settings = array_merge(['enabled' => false, 'emailEnabled' => false, 'recipientMode' => 'administrators', 'recipientUserIds' => [],
            'browserKey' => '', 'serverKey' => '', 'mapId' => '47fb9ccd93293e4b50ccd2ee'], (array) get_option(self::SETTINGS, []));
        // Never turn old free-text addresses or an unknown mode into a broader audience.
        if (!in_array($settings['recipientMode'], ['administrators', 'users'], true)) {
            $settings['recipientMode'] = 'users'; $settings['recipientUserIds'] = []; $settings['emailEnabled'] = false;
        }
        unset($settings['recipients']);
        return $settings;
    }
    public static function recipients(): array {
        wp_cache_delete(self::SETTINGS, 'options');
        $s = self::settings();
        $query = ['role' => 'administrator', 'fields' => ['ID', 'user_email']];
        if ($s['recipientMode'] === 'users') {
            $ids = array_filter((array) $s['recipientUserIds'], static fn($id) => is_int($id) && $id > 0);
            if (!$ids) { return []; } // WordPress treats include=[] as an unrestricted query.
            $query = ['include' => $ids, 'fields' => ['ID', 'user_email']];
        }
        return array_values(array_unique(array_filter(array_map(static fn($user) => strtolower($user->user_email),
            get_users($query)), 'is_email')));
    }
    public static function recipient_options() {
        if (!self::permission()) { return new WP_Error('forbidden', 'Geen toegang.', ['status' => 403]); }
        nocache_headers();
        $s = self::settings();
        return ['recipientMode' => $s['recipientMode'], 'recipientUserIds' => $s['recipientUserIds'],
            'users' => array_map(static fn($user) => ['id' => (int) $user->ID, 'name' => $user->display_name,
                'email' => $user->user_email, 'canReview' => user_can((int) $user->ID, 'manage_options'), 'validEmail' => (bool) is_email($user->user_email)],
                get_users(['orderby' => 'display_name', 'order' => 'ASC', 'fields' => ['ID', 'display_name', 'user_email']]))];
    }
    public static function update_recipients(array $input) {
        if (!self::permission()) { return new WP_Error('forbidden', 'Geen toegang.', ['status' => 403]); }
        try {
            $before = self::settings(); $after = BZV_Afnemer_Admin::recipient_settings($input, $before);
            if ($after !== $before && !update_option(self::SETTINGS, $after, false)) { throw new RuntimeException('Opslaan is niet gelukt. Probeer het opnieuw.'); }
            return ['recipientMode' => $after['recipientMode'], 'recipientUserIds' => $after['recipientUserIds']];
        } catch (RuntimeException $e) { return new WP_Error('afnemer_recipients', $e->getMessage(), ['status' => 400]); }
    }
    public static function connection_changed(): void {
        if (!self::acquire()) { throw new RuntimeException('Er loopt een controle. Probeer de profielwisseling later opnieuw.'); }
        try {
            $state = self::state(); $state['directory'] = null;
            foreach ($state['records'] as &$record) {
                $record['active'] = false; $record['approvedAt'] = null; $record['approvedBy'] = null;
                $record['revision']++; $record['reviewVersion']++;
            } unset($record);
            self::persist($state);
        } finally { self::release(); }
    }
    public static function enabled(): bool { return (bool) self::settings()['enabled']; }
    public static function state(): array { return get_option(self::STATE, BZV_Afnemer_State::empty()); }
    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_post_bzv_afnemer_settings', [__CLASS__, 'save_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }
    public static function permission(): bool { return current_user_can('manage_options'); }
    public static function routes(): void {
        register_rest_route('bzv/v1', '/afnemers/recipient-options', ['methods' => 'GET',
            'permission_callback' => [__CLASS__, 'permission'], 'callback' => [__CLASS__, 'recipient_options']]);
        register_rest_route('bzv/v1', '/afnemers/recipients', ['methods' => 'POST',
            'permission_callback' => [__CLASS__, 'permission'], 'callback' => static fn($request) => self::update_recipients($request->get_json_params() ?? [])]);
        foreach (['lookup', 'save', 'withdraw', 'retry-mail'] as $action) {
            register_rest_route('bzv/v1', '/afnemers/' . $action, ['methods' => 'POST',
                'permission_callback' => [__CLASS__, 'permission'], 'callback' => static fn($request) => self::handle($action, $request->get_json_params() ?? [])]);
        }
    }
    /** Compare-and-delete leases: an expired process may never release a newer owner's lock. */
    private static function acquire(): bool {
        global $wpdb;
        if (self::$lock !== null) { throw new LogicException('Nested afnemer lock.'); }
        $value = time() . ':' . wp_generate_uuid4();
        if (!add_option(self::LOCK, $value, '', false)) {
            $old = get_option(self::LOCK, '');
            if (!is_string($old) || (int) explode(':', $old)[0] >= time() - 120) { return false; }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $old));
            wp_cache_delete(self::LOCK, 'options');
            if (!add_option(self::LOCK, $value, '', false)) { return false; }
        }
        self::$lock = $value;
        // Another request may have committed after this request first read the option.
        wp_cache_delete(self::STATE, 'options');
        return true;
    }
    private static function release(): void {
        global $wpdb;
        if (self::$lock !== null) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, self::$lock));
            wp_cache_delete(self::LOCK, 'options'); self::$lock = null;
        }
    }
    private static function assert_lock(): void {
        global $wpdb;
        $owner = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK));
        if (self::$lock === null || $owner !== self::$lock || (int) explode(':', self::$lock)[0] <= time() - 120) {
            throw new RuntimeException('De verwerking duurde te lang. Probeer het opnieuw.');
        }
    }
    private static function persist(array $state): array {
        self::assert_lock();
        // Commit UUIDs before creating posts so retries recover partial work instead of duplicating it.
        if ($state !== self::state() && !update_option(self::STATE, $state, false)) { throw new RuntimeException('Opslaan is niet gelukt. Probeer het opnieuw.'); }
        if (class_exists('BZV_Afnemer_Posts')) {
            $state = BZV_Afnemer_Posts::prepare($state, static fn() => self::assert_lock());
            self::assert_lock();
            if ($state !== self::state() && !update_option(self::STATE, $state, false)) { throw new RuntimeException('De CPT-koppelingen konden niet worden opgeslagen.'); }
            BZV_Afnemer_Posts::sync($state, static fn() => self::assert_lock());
        }
        return $state;
    }
    public static function reconcile_post_edits(): void {
        if (!class_exists('BZV_Afnemer_Posts') || !BZV_Afnemer_Posts::has_edits(self::state())) { return; }
        if (!self::acquire()) { BZV_Bop_Locations::purge(); return; }
        try { self::persist(BZV_Afnemer_Posts::import_edits(self::state())); }
        catch (RuntimeException $e) { self::status($e->getMessage()); }
        finally { self::release(); BZV_Bop_Locations::purge(); }
    }
    private static function status(string $text, bool $ok = false): void {
        update_option('bzv_afnemer_status', ['at' => time(), 'ok' => $ok, 'message' => $text], false);
    }
    public static function sync(): void {
        self::reconcile_post_edits();
        if (!self::enabled() || !self::acquire()) { return; }
        try {
            $state = self::persist(BZV_Afnemer_State::expire(self::state(), time()));
            [$base, $token] = BZV_Bop_Locations::config();
            $url = wp_parse_url($base);
            if (!$token || preg_match('/[\r\n]/', $token) || !$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) ||
                isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                throw new RuntimeException('Kies eerst de Postkantoor-verbinding.');
            }
            $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
            if (BZV_Afnemer_State::fresh($state, time())) { $headers['If-None-Match'] = $state['directory']['eTag']; }
            $response = wp_safe_remote_get($base . '/v1/customer-directory', ['headers' => $headers, 'timeout' => 15, 'redirection' => 0,
                'sslverify' => true, 'limit_response_size' => 1024 * 1024]);
            if (is_wp_error($response)) { throw new RuntimeException('Postkantoor is tijdelijk niet bereikbaar.'); }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $directory = BZV_Afnemer_State::validate(wp_remote_retrieve_body($response), time());
                $state = BZV_Afnemer_State::merge($state, $directory, 'wp_generate_uuid4');
                $state = self::persist($state);
                wp_clear_scheduled_hook('bzv_bop_locations_expire');
                wp_schedule_single_event($directory['publishedTimestamp'] + BZV_Afnemer_State::MAX_AGE, 'bzv_bop_locations_expire');
            } elseif ($code !== 304 || !BZV_Afnemer_State::fresh($state, time())) {
                throw new RuntimeException('Postkantoor gaf geen actuele afnemerslijst terug (HTTP ' . $code . ').');
            }
            // A 304 is not a renewed BOP assessment. Never extend its expiry.
            $settings = self::settings();
            if ($settings['emailEnabled']) {
                $state = BZV_Afnemer_State::queue_notices($state, self::recipients(), time(), 'wp_generate_uuid4');
                $state = self::persist($state);
            }
            self::status('Afnemerslijst bijgewerkt. ' . count(array_filter($state['records'], static fn($r) => $r['active'])) . ' actieve afnemers.', true);
        } catch (RuntimeException $e) { self::status($e->getMessage()); return; }
        finally { self::release(); BZV_Bop_Locations::purge(); }
        self::refresh_coordinates();
        self::deliver_notices();
    }
    public static function expire(): void {
        if (!self::acquire()) { return; }
        try { self::persist(BZV_Afnemer_State::expire(self::state(), time())); }
        catch (RuntimeException $e) { self::status($e->getMessage()); }
        finally { self::release(); BZV_Bop_Locations::purge(); }
    }
    public static function locations(): array {
        try { return class_exists('BZV_Afnemer_Posts') ? BZV_Afnemer_Posts::locations(self::state()) : BZV_Afnemer_State::public_locations(self::state(), time()); }
        catch (RuntimeException $e) { return []; }
    }
    public static function review_url(string $id = ''): string {
        return admin_url('tools.php?page=bop-afnemers' . ($id === '' ? '' : '&review=' . rawurlencode($id)));
    }
    private static function ready(array $state, string $id, int $revision): array {
        if (!isset($state['records'][$id]) || $state['records'][$id]['revision'] !== $revision) {
            throw new RuntimeException('Deze afnemer is ondertussen gewijzigd. Laad de actuele gegevens opnieuw.');
        }
        if (!BZV_Afnemer_State::fresh($state, time()) || !$state['records'][$id]['active']) {
            throw new RuntimeException('Deze afnemer is niet meer actief of de BOP-lijst moet eerst worden vernieuwd.');
        }
        if (class_exists('BZV_Afnemer_Posts') && !BZV_Afnemer_Posts::reviewable($state['records'][$id])) {
            throw new RuntimeException('De gekoppelde post is gewijzigd of ontbreekt. Vernieuw de lijst en open de controle opnieuw.');
        }
        return $state['records'][$id];
    }
    public static function handle(string $action, array $input) {
        if (!self::permission()) { return new WP_Error('forbidden', 'Geen toegang.', ['status' => 403]); }
        self::reconcile_post_edits();
        if (!self::enabled()) { return new WP_Error('disabled', 'De WordPress-controle is nog niet geactiveerd.', ['status' => 409]); }
        $id = (string) ($input['id'] ?? ''); $revision = (int) ($input['revision'] ?? 0);
        try {
            $state = self::state();
            if ($action === 'lookup') {
                $record = self::ready($state, $id, $revision);
                $results = BZV_Afnemer_Google::locate(self::settings()['serverKey'], (string) ($input['placeId'] ?? ''), (array) ($input['fields'] ?? []));
                foreach ($results as &$location) {
                    $addresses = BZV_Afnemer_Google::address_suggestions($location['latitude'], $location['longitude']);
                    $token = bin2hex(random_bytes(24));
                    set_transient('bzv_lookup_' . hash('sha256', $token), ['id' => $id, 'revision' => $revision, 'user' => get_current_user_id(),
                        'expiresAt' => time() + 7200, 'location' => $location, 'addresses' => [(array) ($input['fields'] ?? []), ...$addresses]], 7200);
                    $location['token'] = $token;
                    $location['addresses'] = $addresses;
                } unset($location);
                return ['items' => $results];
            }
            if (!self::acquire()) { throw new RuntimeException('Er loopt al een verwerking. Probeer het zo opnieuw.'); }
            try {
                $state = self::state();
                if ($action === 'retry-mail') {
                    $job = &$state['jobs'][$id];
                    if (!$job || !in_array($job['state'], ['failed', 'sending', 'uncertain'], true) ||
                        ($job['state'] === 'sending' && ($job['startedAt'] ?? 0) > time() - 300)) { throw new RuntimeException('Deze melding kan nu niet opnieuw worden aangeboden.'); }
                    $job['state'] = 'queued'; $job['nextAttempt'] = time(); $job['attempts'] = 0; unset($job);
                } else {
                    $record = self::ready($state, $id, $revision);
                    if ($action === 'withdraw') {
                        $state['records'][$id]['approvedAt'] = null; $state['records'][$id]['approvedBy'] = null;
                        $state['records'][$id]['deferred'] = true; $state['records'][$id]['revision']++;
                    } else {
                        $fields = (array) ($input['fields'] ?? []);
                        $token = (string) ($input['token'] ?? '');
                        if ($token !== '') {
                            $proof = get_transient('bzv_lookup_' . hash('sha256', $token));
                            if (!$proof || $proof['expiresAt'] <= time() || $proof['id'] !== $id || $proof['revision'] !== $revision || $proof['user'] !== get_current_user_id()) {
                                throw new RuntimeException('Het locatievoorstel is verlopen. Zoek de locatie opnieuw op.');
                            }
                            $addressKeys = array_flip(['address', 'postalCode', 'city', 'countryCode']);
                            $expected = array_intersect_key($fields, $addressKeys);
                            $matches = array_filter($proof['addresses'], static fn($a) => array_intersect_key($a, $addressKeys) == $expected);
                            if (!$matches) { throw new RuntimeException('Het adres is na het opzoeken gewijzigd. Zoek de kaartpositie opnieuw op.'); }
                            $location = $proof['location'];
                        } else {
                            // Saving an existing reviewed point is allowed, but a new address needs a fresh lookup.
                            foreach (['address', 'postalCode', 'city', 'countryCode'] as $f) {
                                if (($fields[$f] ?? null) !== $record[$f]) { throw new RuntimeException('Zoek de positie van het gewijzigde adres eerst opnieuw op.'); }
                            }
                            $location = array_intersect_key($record, array_flip(['latitude', 'longitude', 'placeId', 'locatedAt', 'coordinateSource']));
                        }
                        $approve = ($input['approve'] ?? false) === true;
                        if ($approve && ($input['confirmed'] ?? false) !== true) { throw new RuntimeException('Bevestig dat dit de juiste vestiging, het adres en de kaartpositie zijn.'); }
                        $state = BZV_Afnemer_State::approve($state, $id, $revision, $fields, $location, get_current_user_id(), time(), $approve);
                    }
                }
                self::persist($state);
            } finally { self::release(); BZV_Bop_Locations::purge(); }
            return ['ok' => true, 'url' => self::review_url(), 'message' => $action === 'withdraw' ? 'De vermelding is ingetrokken.' : 'Opgeslagen.'];
        } catch (RuntimeException $e) { return new WP_Error('afnemer_review', $e->getMessage(), ['status' => 409]); }
    }
    private static function refresh_coordinates(): void {
        if (!self::settings()['serverKey']) { return; }
        $refreshed = 0;
        foreach (self::state()['records'] as $id => $record) {
            if ($refreshed >= 3) { break; }
            if ($record['coordinateSource'] !== 'google' || !$record['placeId'] || !$record['active'] ||
                ($record['locatedAt'] ?? 0) > time() - 27 * 86400 || ($record['refreshAfter'] ?? 0) > time()) { continue; }
            $refreshed++;
            try { $fresh = BZV_Afnemer_Google::locate(self::settings()['serverKey'], $record['placeId'])[0]; }
            catch (RuntimeException $e) { $fresh = null; }
            if (!self::acquire()) { return; }
            try {
                $state = self::state(); $r = &$state['records'][$id];
                if ($r['revision'] !== $record['revision']) { continue; }
                $r['refreshAfter'] = time() + 3600;
                if ($fresh) {
                    $changed = $r['latitude'] !== $fresh['latitude'] || $r['longitude'] !== $fresh['longitude'] || $r['placeId'] !== $fresh['placeId'];
                    foreach (['latitude', 'longitude', 'placeId', 'locatedAt', 'coordinateSource'] as $field) { $r[$field] = $fresh[$field]; }
                    $r['revision']++;
                    if ($changed) { $r['approvedAt'] = null; $r['approvedBy'] = null; $r['deferred'] = false; $r['reviewVersion']++; }
                }
                unset($r); self::persist(BZV_Afnemer_State::expire($state, time()));
            } catch (RuntimeException $e) { self::status($e->getMessage()); return; }
            finally { self::release(); BZV_Bop_Locations::purge(); }
            // Bound each cron request; subsequent runs handle the remaining locations.

        }
    }
    public static function mail(array $records): array {
        $count = count($records); $one = $count === 1;
        $title = $one ? 'Een nieuwe afnemer controleren' : $count . ' afnemers controleren';
        $intro = $one ? 'Er staat een afnemer klaar voor ‘Hier vind je’.' : 'Er staan ' . $count . ' afnemers klaar voor ‘Hier vind je’.';
        $links = '';
        foreach (array_slice($records, 0, 3) as $r) {
            $links .= '<p><strong>' . esc_html($r['name']) . '</strong><br><a style="display:inline-block;background:#173c58;color:#fff;padding:12px 18px;border-radius:6px;text-decoration:none;margin-top:8px" href="' . esc_url(self::review_url($r['id'])) . '">Locatie controleren</a></p>';
        }
        $html = '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:0;background:#f3f5f7;font-family:Arial,sans-serif;color:#203443">' .
            '<table role="presentation" style="width:100%;max-width:560px;margin:0 auto;background:#fff"><tr><td style="padding:20px;background:#173c58;color:#fff;font-size:18px">Hoeve Bouwlust</td></tr>' .
            '<tr><td style="padding:24px;line-height:1.6"><h1 style="font-size:22px;line-height:1.3">' . esc_html($title) . '</h1><p>' . esc_html($intro) . '</p>' .
            '<p><strong>Wat moet je doen?</strong><br>Bekijk de locatievoorstellen, pas zo nodig het adres aan en keur de juiste vestiging goed. Daarna kan de locatie op de website verschijnen.</p>' . $links .
            ($count > 3 ? '<p><a href="' . esc_url(self::review_url()) . '">Alle ' . $count . ' afnemers bekijken</a></p>' : '') .
            '<p style="font-size:13px;color:#60717d">Je controleert de locatie op de website. Een VPN-verbinding is niet nodig.</p></td></tr></table></body></html>';
        return ['subject' => 'Bouwlust: ' . lcfirst($title), 'html' => $html];
    }
    private static function deliver_notices(): void {
        if (!self::settings()['emailEnabled']) { return; }
        $done = 0;
        foreach (self::state()['jobs'] as $id => $snapshot) {
            if ($done >= 3 || !in_array($snapshot['state'], ['queued', 'failed'], true) || $snapshot['nextAttempt'] > time() || $snapshot['attempts'] >= 3) { continue; }
            if (!self::acquire()) { return; }
            $mail = null; $recipient = null;
            try {
                $state = self::state(); $job = &$state['jobs'][$id];
                if (!BZV_Afnemer_State::fresh($state, time()) || !in_array($job['state'], ['queued', 'failed'], true)) { continue; }
                $records = [];
                foreach ($job['items'] as $item) {
                    $r = $state['records'][$item['id']] ?? null;
                    if ($r && $r['active'] && !$r['approvedAt'] && !$r['deferred'] && $r['reviewVersion'] === $item['version']) { $records[] = $r; }
                }
                if (!$records || !in_array($job['recipient'], self::recipients(), true)) {
                    $job['state'] = 'cancelled';
                    foreach ($job['items'] as $item) { $state['notices'][$item['key']]['state'] = 'cancelled'; }
                }
                else { $mail = self::mail($records); $recipient = $job['recipient']; $job['state'] = 'sending'; $job['startedAt'] = time(); $job['attempts']++; }
                unset($job); self::persist($state);
            } finally { self::release(); }
            if (!$mail) { continue; }
            // A crash after handoff leaves 'sending': never blindly resend an uncertain delivery.
            $accepted = wp_mail($recipient, $mail['subject'], $mail['html'], ['Content-Type: text/html; charset=UTF-8']); $done++;
            if (!self::acquire()) { return; }
            try {
                $state = self::state(); $state['jobs'][$id]['state'] = $accepted ? 'accepted' : 'failed';
                $state['jobs'][$id]['finishedAt'] = time(); $state['jobs'][$id]['nextAttempt'] = time() + 900;
                foreach ($state['jobs'][$id]['items'] as $item) { $state['notices'][$item['key']]['state'] = $accepted ? 'accepted' : 'failed'; }
                self::persist($state);
            } finally { self::release(); }
        }
    }
    public static function save_settings(): void { BZV_Afnemer_Admin::save_settings(); }
    public static function assets(string $hook): void { BZV_Afnemer_Admin::assets($hook); }
}
