<?php
if (!defined('ABSPATH')) { exit; }

final class BZV_Afnemer_Admin {
    public static function recipient_settings(array $input, array $settings): array {
        $mode = $input['recipientMode'] ?? '';
        if (!in_array($mode, ['administrators', 'users'], true)) { throw new RuntimeException('Kies alle beheerders of geselecteerde WordPress-gebruikers.'); }
        $settings['recipientMode'] = $mode;
        if ($mode === 'users') {
            $raw = $input['recipientUserIds'] ?? [];
            if (!is_array($raw) || count($raw) > 100) { throw new RuntimeException('Selecteer maximaal honderd WordPress-gebruikers.'); }
            $ids = [];
            foreach ($raw as $id) {
                if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]*\z/', (string) $id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                    throw new RuntimeException('De gebruikersselectie is ongeldig. Laad de pagina opnieuw.');
                }
                $ids[] = (int) $id;
            }
            $ids = array_values(array_unique($ids)); sort($ids);
            $users = $ids ? get_users(['include' => $ids, 'fields' => ['ID', 'user_email']]) : [];
            if (count($users) !== count($ids) || array_filter($users, static fn($user) => !is_email($user->user_email))) {
                throw new RuntimeException('Een geselecteerde gebruiker bestaat niet meer of heeft geen geldig e-mailadres. Laad de pagina opnieuw.');
            }
            if (!$ids && $settings['emailEnabled']) { throw new RuntimeException('Selecteer minstens één gebruiker of schakel automatische meldingen uit.'); }
            $settings['recipientUserIds'] = $ids;
        }
        unset($settings['recipients']);
        return $settings;
    }
    public static function assets(string $hook): void {
        if ($hook !== 'tools_page_bop-afnemers' || !current_user_can('manage_options')) { return; }
        wp_enqueue_style('bzv-afnemer-review', plugins_url('assets/afnemer-review.css', BZV_PLUGIN_FILE), [], BZV_VERSION);
        wp_enqueue_script('bzv-afnemer-review', plugins_url('assets/afnemer-review.js', BZV_PLUGIN_FILE), [], BZV_VERSION, true);
        $settings = BZV_Afnemer_Reviews::settings();
        wp_localize_script('bzv-afnemer-review', 'BZVReview', ['rest' => rest_url('bzv/v1/afnemers/'), 'nonce' => wp_create_nonce('wp_rest'),
            'browserKey' => $settings['browserKey'], 'mapId' => $settings['mapId'], 'googleReady' => $settings['serverKey'] !== '' && $settings['browserKey'] !== '' && $settings['mapId'] !== '']);
    }
    public static function save_settings(): void {
        if (!current_user_can('manage_options')) { wp_die('Geen toegang.', '', ['response' => 403]); }
        check_admin_referer('bzv_afnemer_settings');
        $s = BZV_Afnemer_Reviews::settings();
        $s['enabled'] = isset($_POST['enabled']); $s['emailEnabled'] = isset($_POST['emailEnabled']);
        try { $s = self::recipient_settings(wp_unslash($_POST), $s); }
        catch (RuntimeException $e) { wp_die(esc_html($e->getMessage())); }
        foreach (['browserKey', 'serverKey'] as $key) {
            $value = trim((string) wp_unslash($_POST[$key] ?? ''));
            if ($value !== '') {
                if (!preg_match('/\AAIza[A-Za-z0-9_-]{20,100}\z/', $value)) { wp_die('De Google-sleutel heeft geen geldig formaat.'); }
                $s[$key] = $value;
            }
        }
        $map = trim((string) wp_unslash($_POST['mapId'] ?? ''));
        if (!preg_match('/\A[a-fA-F0-9]{8,64}\z/', $map)) { wp_die('Vul een geldig Google map-ID in.'); }
        $s['mapId'] = $map;
        if ($s['serverKey'] !== '' && $s['serverKey'] === $s['browserKey']) { wp_die('Gebruik aparte Google-sleutels voor de browser en de server.'); }
        if ($s['emailEnabled'] && (!$s['enabled'] || !$s['serverKey'] || !$s['browserKey'])) { wp_die('Stel de controlepagina en Google eerst in voordat je meldingen inschakelt.'); }
        update_option(BZV_Afnemer_Reviews::SETTINGS, $s, false);
        BZV_Bop_Locations::purge();
        wp_safe_redirect(BZV_Afnemer_Reviews::review_url() . '&settings=saved'); exit;
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        nocache_headers();
        $state = BZV_Afnemer_Reviews::state(); $s = BZV_Afnemer_Reviews::settings();
        $fresh = BZV_Afnemer_State::fresh($state, time());
        $pending = array_filter($state['records'], static fn($r) => $r['active'] && !$r['approvedAt'] && !$r['deferred']);
        $published = BZV_Afnemer_Reviews::locations();
        $id = (string) wp_unslash($_GET['review'] ?? '');
        echo '<div class="wrap bzv-review"><header><p class="bzv-eyebrow">Hoeve Bouwlust · Hier vind je</p><h1>Afnemers</h1><p>Controleer de voorgestelde vestiging en geef de locatie vrij voor de website.</p></header>';
        echo '<p>Elke afnemer heeft een eigen verkooppunt-post. Goedkeuring publiceert die post en ververst de kaart op de zuivelpagina. <a href="' . esc_url(admin_url('edit.php?post_type=zuivel_verkooppunt')) . '">Alle verkooppunt-posts bekijken</a></p>';
        if (!$s['enabled']) { echo '<div class="bzv-notice">De nieuwe controle staat nog uit. Stel hieronder de koppeling en Google in en activeer daarna de WordPress-controle.</div>'; }
        elseif (!$fresh) { echo '<div class="bzv-notice" role="status">De actuele BOP-lijst ontbreekt. Locaties worden pas getoond en goedgekeurd zodra die weer beschikbaar is.</div>'; }
        if ($id !== '') {
            if (!isset($state['records'][$id])) { echo '<div class="bzv-notice">Deze controle bestaat niet meer. Kies hieronder een actuele afnemer.</div>'; }
            else { self::record($state['records'][$id], $fresh && $s['enabled']); echo '</div>'; return; }
        }
        echo '<div class="bzv-stats"><div><strong>' . count($pending) . '</strong> Te controleren</div><div><strong>' . count($published) . '</strong> Op de website</div><div><strong>' . count(array_filter($state['records'], static fn($r) => !$r['active'])) . '</strong> Niet actief</div></div>';
        echo '<div class="bzv-toolbar"><label>Zoeken <input id="bzv-filter" type="search" placeholder="Naam of plaats"></label><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bzv_bop_refresh">';
        wp_nonce_field('bzv_bop_refresh'); submit_button('Lijst vernieuwen', 'secondary', 'submit', false); echo '</form></div>';
        $records = array_values($state['records']);
        usort($records, static fn($a, $b) => [$a['active'] ? 0 : 1, $a['approvedAt'] ? 1 : 0, $a['name']] <=> [$b['active'] ? 0 : 1, $b['approvedAt'] ? 1 : 0, $b['name']]);
        echo '<div class="bzv-records">';
        foreach ($records as $r) {
            $label = !$r['active'] ? 'Niet actief in BOP' : ($r['approvedAt'] ? 'Goedgekeurd' : ($r['deferred'] ? 'Nog niet publiceren' : 'Te controleren'));
            echo '<article class="bzv-row" data-filter="' . esc_attr(strtolower($r['name'] . ' ' . $r['city'])) . '"><div><h2>' . esc_html($r['name']) . '</h2><p>' . esc_html(trim($r['address'] . ' · ' . $r['city'], ' ·')) . '</p></div><span class="bzv-badge">' . esc_html($label) . '</span><a class="button" href="' . esc_url(BZV_Afnemer_Reviews::review_url($r['id'])) . '">' . ($r['approvedAt'] ? 'Bekijken' : 'Controleren') . '</a></article>';
        }
        if (!$records) { echo '<p>Er zijn nog geen afnemers ontvangen. Haal de lijst op zodra de Postkantoor-verbinding klaarstaat.</p>'; }
        echo '</div>';
        $status = get_option('bzv_afnemer_status', []);
        if ($status) { echo '<p class="description">' . esc_html($status['message']) . ' Laatste controle: ' . esc_html(wp_date('d-m-Y H:i', $status['at'])) . '.</p>'; }
        self::settings($s);
        self::mail_status($state);
        echo '</div>';
    }
    private static function record(array $r, bool $fresh): void {
        $editable = $fresh && $r['active'];
        $public = array_intersect_key($r, array_flip(['id', 'revision', 'name', 'address', 'postalCode', 'city', 'countryCode', 'latitude', 'longitude', 'placeId', 'coordinateSource', 'locatedAt', 'approvedAt']));
        if (!BZV_Afnemer_State::coordinates($r, time())) { $public['latitude'] = $public['longitude'] = null; }
        echo '<p><a href="' . esc_url(BZV_Afnemer_Reviews::review_url()) . '">← Alle afnemers</a></p><h2 class="bzv-name">' . esc_html($r['name']) . '</h2>';
        if (!empty($r['postId'])) { echo '<p><a href="' . esc_url(get_edit_post_link($r['postId'])) . '">Bijbehorende verkooppunt-post bekijken</a></p>'; }
        if (!$editable) { echo '<p class="bzv-notice">Deze afnemer is niet actief of de BOP-lijst is verouderd. De locatie kan nu niet worden gepubliceerd.</p>'; }
        if ($r['identityConflict']) { echo '<p class="bzv-notice">De klantkoppeling in BOP is gewijzigd. Controleer opnieuw of dit de juiste vestiging is.</p>'; }
        echo '<script type="application/json" id="bzv-record">' . wp_json_encode($public, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        echo '<form id="bzv-review-form" class="bzv-panel"><div class="bzv-columns"><section><h3>Voorgestelde locatie</h3><p>We zoeken alvast op de bedrijfsnaam. Kies de juiste vestiging; een zoekresultaat is nog geen goedkeuring.</p><label for="bzv-search">Bedrijfsnaam of adres</label><div class="bzv-search"><input id="bzv-search" value="' . esc_attr(trim($r['source']['name'] . ' ' . $r['source']['city'])) . '"><button type="button" class="button" id="bzv-search-button">Zoeken</button></div><div id="bzv-suggestions"></div><p id="bzv-lookup-status" role="status" aria-live="polite">Locatievoorstellen worden geladen…</p><div id="bzv-map" aria-label="Voorgestelde kaartpositie"></div></section>';
        echo '<section><h3>Gegevens op de website</h3><p>Controleer ook het huisnummer. Een adres in de buurt hoeft niet het adres van dit bedrijf te zijn.</p><div id="bzv-address-choices"></div>';
        foreach (['name' => 'Naam', 'address' => 'Straat en huisnummer', 'postalCode' => 'Postcode', 'city' => 'Plaats', 'countryCode' => 'Landcode'] as $key => $label) {
            echo '<label for="bzv-' . esc_attr($key) . '">' . esc_html($label) . '</label><input id="bzv-' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($r[$key]) . '" required maxlength="' . (['postalCode' => 20, 'city' => 160, 'countryCode' => 2][$key] ?? 300) . '">';
        }
        echo '<p><button type="button" class="button" id="bzv-geocode">Positie van dit adres opzoeken</button></p><p id="bzv-coordinates" class="description"></p><p class="description">Adresvoorstellen: PDOK/BAG. Je kunt het adres hierboven aanpassen.</p></section></div>';
        echo '<div class="bzv-decision"><label class="bzv-confirm"><input type="checkbox" id="bzv-confirm"' . (!$editable ? ' disabled' : '') . '> Ik heb gecontroleerd dat dit de juiste vestiging, het juiste adres en de juiste kaartpositie zijn.</label><p id="bzv-save-status" role="status" aria-live="polite"></p><div class="bzv-actions"><button class="button button-primary button-hero" id="bzv-approve" type="submit"' . (!$editable ? ' disabled' : '') . '>Goedkeuren en publiceren</button><button class="button" id="bzv-draft" type="button"' . (!$editable ? ' disabled' : '') . '>Opslaan als concept</button>';
        if ($r['approvedAt']) { echo '<button type="button" class="button" id="bzv-withdraw"' . (!$editable ? ' disabled' : '') . '>Vermelding intrekken</button>'; }
        echo '</div><p class="description">De locatie verschijnt alleen zolang BOP deze afnemer als actief aanmerkt.</p></div></form>';
    }
    private static function settings(array $s): void {
        echo '<details class="bzv-settings"' . (!$s['enabled'] ? ' open' : '') . '><summary>Instellingen en meldingen</summary>';
        $class = '\\GC_API_Alchemist\\Connection_Profiles\\Connection_Profile';
        if (!defined('BOP_CUSTOMER_LOCATIONS_URL') && !defined('BOP_CUSTOMER_LOCATIONS_TOKEN') && class_exists($class)) {
            echo '<h3>Postkantoor</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bzv_bop_connection">';
            wp_nonce_field('bzv_bop_connection');
            echo '<label for="bzv-profile">Verbindingsprofiel</label><select id="bzv-profile" name="connection_profile"><option value="0">Kies een verbinding</option>';
            foreach ($class::get_all() as $profile) {
                if (!BZV_Bop_Locations::profile_config($profile->get_id())[1]) { continue; }
                echo '<option value="' . (int) $profile->get_id() . '"' . selected((int) get_option('bzv_bop_connection_profile', 0), $profile->get_id(), false) . '>' . esc_html($profile->get_title()) . '</option>';
            }
            echo '</select>'; submit_button('Verbinding opslaan', 'secondary'); echo '</form>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bzv_afnemer_settings">';
        wp_nonce_field('bzv_afnemer_settings');
        echo '<p><label><input type="checkbox" name="enabled"' . checked($s['enabled'], true, false) . '> Locaties in WordPress controleren en publiceren</label></p><p><label><input type="checkbox" name="emailEnabled"' . checked($s['emailEnabled'], true, false) . '> Automatische afnemermeldingen inschakelen</label></p>';
        self::recipient_fields($s);
        echo '<p class="description">Bij activering bundelen we bestaande controles in één bericht per ontvanger. Latere meldingen gaan alleen over nieuwe controles.</p><h3>Google</h3><p>Gebruik aparte sleutels voor deze website en voor de webserver. Bestaande sleutels worden niet weergegeven; laat een veld leeg om de ingestelde sleutel te behouden.</p>';
        foreach (['browserKey' => 'Browsersleutel (Maps JavaScript en Places UI Kit)', 'serverKey' => 'Serversleutel (alleen Geocoding)'] as $key => $label) {
            echo '<label for="bzv-setting-' . esc_attr($key) . '">' . esc_html($label) . '</label><input id="bzv-setting-' . esc_attr($key) . '" name="' . esc_attr($key) . '" type="password" autocomplete="new-password" placeholder="' . ($s[$key] ? 'Ingesteld — leeg laten om te behouden' : 'Nog niet ingesteld') . '">';
        }
        echo '<label for="bzv-map-id">Google map-ID</label><input id="bzv-map-id" name="mapId" value="' . esc_attr($s['mapId']) . '">';
        submit_button('Instellingen opslaan'); echo '</form></details>';
    }
    public static function recipient_fields(array $s): void {
        $options = BZV_Afnemer_Reviews::recipient_options();
        if (is_wp_error($options)) { return; }
        echo '<label for="bzv-recipient-mode">Wie ontvangt de afnemermeldingen?</label><select id="bzv-recipient-mode" name="recipientMode"><option value="administrators"' . selected($s['recipientMode'], 'administrators', false) . '>Alle WordPress-beheerders</option><option value="users"' . selected($s['recipientMode'], 'users', false) . '>Geselecteerde WordPress-gebruikers</option></select>';
        echo '<p id="bzv-all-admins-note" class="description">Bij alle beheerders volgen de ontvangers automatisch de beheerdersrol, ook als er later een beheerder bijkomt.</p>';
        echo '<fieldset id="bzv-recipient-users"><legend>Gebruikers selecteren</legend><p class="description">Deze selectie geldt bij ‘Geselecteerde WordPress-gebruikers’. We gebruiken hun actuele e-mailadres. Dubbele adressen krijgen één bericht.</p><label for="bzv-recipient-search">Zoeken op naam of e-mailadres</label><input type="search" id="bzv-recipient-search" placeholder="Zoek een gebruiker"><p id="bzv-recipient-count" aria-live="polite"></p><div class="bzv-user-list">';
        foreach ($options['users'] as $user) {
            echo '<label class="bzv-recipient" data-filter="' . esc_attr(strtolower($user['name'] . ' ' . $user['email'])) . '"><input type="checkbox" name="recipientUserIds[]" value="' . $user['id'] . '"' . checked(in_array($user['id'], $s['recipientUserIds'], true), true, false) . (!$user['validEmail'] ? ' disabled' : '') . '><span><strong>' . esc_html($user['name']) . '</strong><span>' . esc_html($user['email']) . '</span><small>' . (!$user['validEmail'] ? 'Geen geldig e-mailadres' : ($user['canReview'] ? 'Kan locaties controleren' : 'Ontvangt de melding; kan geen locaties controleren')) . '</small></span></label>';
        }
        echo '</div><p id="bzv-recipient-empty" hidden>Geen gebruikers gevonden.</p><p class="description">Selecteren geeft geen extra beheerrechten. Locaties controleren en goedkeuren blijft voorbehouden aan gebruikers met de bestaande beheerrechten.</p></fieldset>';
    }
    private static function mail_status(array $state): void {
        $issues = array_filter($state['jobs'], static fn($j) => $j['state'] === 'failed' || ($j['state'] === 'sending' && ($j['startedAt'] ?? 0) < time() - 300));
        if (!$issues) { return; }
        echo '<details class="bzv-settings"><summary>Meldingen die aandacht vragen (' . count($issues) . ')</summary><p>Bij een onderbroken verzending is onbekend of de mail is bezorgd. Controleer dit voordat je opnieuw probeert.</p>';
        foreach ($issues as $job) {
            echo '<p>' . esc_html($job['recipient']) . ': ' . ($job['state'] === 'failed' ? 'niet aangeboden' : 'bezorging onbekend') . ' <button type="button" class="button bzv-retry-mail" data-job="' . esc_attr($job['id']) . '">Opnieuw aanbieden</button></p>';
        }
        echo '</details>';
    }
}
