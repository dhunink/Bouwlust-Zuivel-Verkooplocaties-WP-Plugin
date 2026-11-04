<?php
if (!defined('ABSPATH')) { exit; }

/** The review workflow owns one native CPT; the map reads its verified ACF values. */
final class BZV_Afnemer_Posts {
    public const TYPE = 'zuivel_verkooppunt';
    public const OWNER = '_bzv_afnemer_id';
    private const DIRTY = '_bzv_afnemer_edited';
    private const STAMP = '_bzv_afnemer_projection';
    private static bool $writing = false;
    private static ?array $fields = null;

    public static function init(): void {
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [__CLASS__, 'meta_changed'], 30, 4);
        }
        add_action('wp_after_insert_post', [__CLASS__, 'post_changed'], 30, 1);
        add_action('shutdown', [BZV_Afnemer_Reviews::class, 'reconcile_post_edits']);
        add_action('add_meta_boxes_' . self::TYPE, [__CLASS__, 'meta_box']);
        add_filter('manage_' . self::TYPE . '_posts_columns', [__CLASS__, 'columns']);
        // Admin Columns Pro replaces the saved screen headings at priority 200.
        add_filter('manage_edit-' . self::TYPE . '_columns', [__CLASS__, 'columns'], 201);
        add_action('manage_' . self::TYPE . '_posts_custom_column', [__CLASS__, 'column'], 10, 2);
    }
    private static function fields(): array {
        if (self::$fields !== null) { return self::$fields; }
        if (!function_exists('acf_get_field_groups') || !function_exists('update_field')) { throw new RuntimeException('ACF moet actief zijn voor de afnemerposts.'); }
        $result = [];
        foreach (acf_get_field_groups(['post_type' => self::TYPE]) as $group) {
            foreach ((array) acf_get_fields($group['key']) as $field) {
                $logical = $field['name'] === 'adress' ? 'address' : $field['name'];
                if (!in_array($logical, ['address', 'postal_code', 'city', 'location'], true)) { continue; }
                if (isset($result[$logical]) || $field['type'] !== ($logical === 'location' ? 'google_map' : 'text')) {
                    throw new RuntimeException('De ACF-velden voor afnemers zijn niet eenduidig. Controleer de veldgroep.');
                }
                $result[$logical] = $field;
            }
        }
        if (count($result) !== 4) { throw new RuntimeException('De ACF-velden address, postal_code, city en location moeten aanwezig zijn.'); }
        return self::$fields = $result;
    }
    private static function posts(): array {
        return get_posts(['post_type' => self::TYPE, 'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'], 'numberposts' => -1]);
    }
    private static function payload(array $r): array {
        return ['name' => (string) $r['name'], 'address' => (string) $r['address'], 'postalCode' => (string) $r['postalCode'],
            'city' => (string) $r['city'], 'countryCode' => (string) $r['countryCode'],
            'latitude' => is_numeric($r['latitude']) ? (float) $r['latitude'] : null,
            'longitude' => is_numeric($r['longitude']) ? (float) $r['longitude'] : null, 'placeId' => (string) $r['placeId']];
    }
    private static function native(int $id): array {
        $fields = self::fields();
        $location = get_post_meta($id, $fields['location']['name'], true);
        $location = is_array($location) ? $location : [];
        return ['name' => (string) get_post($id)->post_title,
            'address' => (string) get_post_meta($id, $fields['address']['name'], true),
            'postalCode' => (string) get_post_meta($id, $fields['postal_code']['name'], true),
            'city' => (string) get_post_meta($id, $fields['city']['name'], true),
            'countryCode' => (string) (get_post_meta($id, '_bzv_country_code', true) ?: 'NL'),
            'latitude' => isset($location['lat']) && is_numeric($location['lat']) ? (float) $location['lat'] : null,
            'longitude' => isset($location['lng']) && is_numeric($location['lng']) ? (float) $location['lng'] : null,
            'placeId' => (string) ($location['place_id'] ?? get_post_meta($id, '_bzv_place_id', true))];
    }
    private static function stamp(array $r, bool $publish): string {
        return hash('sha256', wp_json_encode([self::payload($r), $publish, $r['approvedAt'], $r['approvedBy'], $r['source']['customerId'] ?? '']));
    }
    /** Recover by durable UUID, or reuse one exact legacy source identity; never fuzzy-match. */
    public static function prepare(array $state, callable $check): array {
        if (!$state['records']) { return $state; }
        if (!post_type_exists(self::TYPE)) { throw new RuntimeException('Het bestaande posttype zuivel_verkooppunt ontbreekt.'); }
        self::fields(); $posts = self::posts(); $owners = []; $legacy = []; $recoverable = [];
        foreach ($posts as $post) {
            $owner = get_post_meta($post->ID, self::OWNER, true);
            if ($owner) { $owners[$owner][] = (int) $post->ID; }
            elseif (str_starts_with($post->post_name ?? '', 'bop-afnemer-')) {
                $recoverable[substr($post->post_name, strlen('bop-afnemer-'))][] = (int) $post->ID;
            }
            elseif (get_post_meta($post->ID, '_zuivel_import_managed', true) === '1' && $post->post_status !== 'trash') {
                $legacy['source:' . hash('sha256', $post->post_title)][] = (int) $post->ID;
            }
        }
        foreach ($state['records'] as &$r) {
            $check(); $ids = $owners[$r['id']] ?? [];
            if (count($ids) > 1) { throw new RuntimeException('Meerdere CPT-posts zijn gekoppeld aan dezelfde afnemer. Controleer de koppeling.'); }
            $id = $ids[0] ?? 0;
            if (!empty($r['postId']) && (int) $r['postId'] !== $id) { throw new RuntimeException('Een gekoppelde afnemerpost is verwijderd of gewijzigd. Herstel eerst de CPT-koppeling.'); }
            if (!$id) {
                $matches = $recoverable[$r['id']] ?? [];
                foreach ([$r['source']['customerId'], ...$r['source']['aliases']] as $key) { $matches = [...$matches, ...($legacy[$key] ?? [])]; }
                $matches = array_values(array_unique($matches));
                if (count($matches) > 1) { throw new RuntimeException('Meerdere oude CPT-posts passen bij ' . $r['name'] . '. Maak die koppeling eerst eenduidig.'); }
                $id = $matches[0] ?? 0;
                if ($id) {
                    if (get_post_status($id) === 'trash') { throw new RuntimeException('De aangemaakte afnemerpost is naar de prullenbak verplaatst. Herstel eerst die post.'); }
                    $existing = self::native($id);
                    foreach (['address', 'postalCode', 'city'] as $key) { if ($r[$key] === '') { $r[$key] = $existing[$key]; } }
                    if (!$r['placeId']) { $r['placeId'] = $existing['placeId']; }
                    // Unknown-age Google coordinates from the historical import are never reapproved.
                    foreach ($legacy as &$candidates) { $candidates = array_values(array_diff($candidates, [$id])); } unset($candidates);
                } else {
                    self::$writing = true;
                    try {
                        $id = wp_insert_post(wp_slash(['post_type' => self::TYPE, 'post_status' => 'draft', 'post_title' => $r['name'],
                            'post_name' => 'bop-afnemer-' . $r['id'], 'meta_input' => [self::OWNER => $r['id']]]), true);
                    } finally { self::$writing = false; }
                    if (is_wp_error($id) || !$id) { throw new RuntimeException('De afnemerpost kon niet worden aangemaakt.'); }
                }
                update_post_meta((int) $id, self::OWNER, $r['id']);
                if (get_post_meta((int) $id, self::OWNER, true) !== $r['id']) { throw new RuntimeException('De CPT-koppeling kon niet worden opgeslagen.'); }
                delete_post_meta((int) $id, '_zuivel_import_managed');
                $owners[$r['id']] = [(int) $id];
            }
            if (empty($r['postId']) && !$r['approvedAt']) {
                $existing = self::native((int) $id);
                foreach (['address', 'postalCode', 'city'] as $key) { if ($r[$key] === '') { $r[$key] = $existing[$key]; } }
                if (!$r['placeId']) { $r['placeId'] = $existing['placeId']; }
            }
            $r['postId'] = (int) $id;
        } unset($r);
        return $state;
    }
    public static function import_edits(array $state): array {
        $eligible = array_column(BZV_Afnemer_State::public_locations($state, time()), null, 'locationId');
        foreach ($state['records'] as &$r) {
            $id = (int) ($r['postId'] ?? 0);
            if (!$id || !get_post($id) || !get_post_meta($id, self::DIRTY, true)) { continue; }
            if (get_post_meta($id, self::OWNER, true) !== $r['id']) { throw new RuntimeException('De CPT-koppeling is gewijzigd.'); }
            $native = self::native($id); $old = self::payload($r);
            $r['postEditFingerprint'] = hash('sha256', wp_json_encode($native));
            // Saving unrelated post content does not change a location review.
            if ($native === $old && get_post_status($id) === (isset($eligible[$r['id']]) ? 'publish' : 'draft')) { continue; }
            $addressChanged = array_intersect_key($native, array_flip(['address', 'postalCode', 'city', 'countryCode'])) !== array_intersect_key($old, array_flip(['address', 'postalCode', 'city', 'countryCode']));
            $pointChanged = [$native['latitude'], $native['longitude'], $native['placeId']] !== [$old['latitude'], $old['longitude'], $old['placeId']];
            foreach ($native as $key => $value) { $r[$key] = $value; }
            if ($addressChanged && !$pointChanged) { $r['latitude'] = $r['longitude'] = null; $r['locatedAt'] = null; }
            elseif ($pointChanged) { $r['coordinateSource'] = 'google'; $r['locatedAt'] = time(); }
            if (!BZV_Afnemer_State::coordinates($r, time())) { $r['latitude'] = $r['longitude'] = null; $r['locatedAt'] = null; }
            $status = get_post_status($id);
            $r['deferred'] = $status === 'trash' || ($status !== 'publish' && $r['approvedAt'] !== null);
            $r['approvedAt'] = null; $r['approvedBy'] = null; $r['revision']++; $r['reviewVersion']++;
            $r['history'][] = ['at' => time(), 'user' => get_current_user_id(), 'action' => 'cpt-edited'];
            $r['history'] = array_slice($r['history'], -20);
        } unset($r);
        return $state;
    }
    public static function sync(array $state, callable $check): void {
        $eligible = array_column(BZV_Afnemer_State::public_locations($state, time()), null, 'locationId');
        foreach ($state['records'] as $r) {
            $check(); $id = (int) $r['postId'];
            if (get_post_meta($id, self::OWNER, true) !== $r['id']) { throw new RuntimeException('De CPT-koppeling is gewijzigd.'); }
            if (get_post_status($id) === 'trash') {
                if (!BZV_Afnemer_State::coordinates($r, time())) {
                    self::$writing = true;
                    try { update_field(self::fields()['location']['key'], null, $id); }
                    finally { self::$writing = false; }
                }
                delete_post_meta($id, self::DIRTY); continue;
            }
            // Do not overwrite a native edit made after the review state was read.
            if (get_post_meta($id, self::DIRTY, true) && ($r['postEditFingerprint'] ?? '') !== hash('sha256', wp_json_encode(self::native($id)))) {
                throw new RuntimeException('Een verkooppunt-post is ondertussen gewijzigd. Vernieuw de controle.');
            }
            $publish = isset($eligible[$r['id']]); $target = $publish ? 'publish' : 'draft';
            $stamp = self::stamp($r, $publish);
            if (get_post_meta($id, self::STAMP, true) === $stamp && get_post_status($id) === $target && self::native($id) === self::payload($r)) { continue; }
            self::$writing = true;
            try {
                delete_post_meta($id, self::STAMP);
                $result = wp_update_post(wp_slash(['ID' => $id, 'post_status' => 'draft', 'post_title' => $r['name']]), true);
                if (is_wp_error($result) || !$result) { throw new RuntimeException('De afnemerpost kon niet worden bijgewerkt.'); }
                $values = ['address' => $r['address'], 'postal_code' => $r['postalCode'], 'city' => $r['city'], 'location' => null];
                if (BZV_Afnemer_State::coordinates($r, time())) {
                    $values['location'] = ['address' => trim($r['address'] . ', ' . $r['postalCode'] . ' ' . $r['city']),
                        'lat' => (float) $r['latitude'], 'lng' => (float) $r['longitude'], 'zoom' => 16, 'place_id' => $r['placeId']];
                }
                foreach (self::fields() as $logical => $field) {
                    update_field($field['key'], $values[$logical], $id);
                }
                update_post_meta($id, '_bzv_country_code', $r['countryCode']); update_post_meta($id, '_bzv_place_id', $r['placeId']);
                update_post_meta($id, '_bzv_bop_customer_id', $r['source']['customerId']);
                update_post_meta($id, '_bzv_active', $r['active'] ? '1' : '0');
                update_post_meta($id, '_bzv_approved_at', $r['approvedAt'] ?? '');
                update_post_meta($id, '_bzv_approved_by', $r['approvedBy'] ?? '');
                if (self::native($id) !== self::payload($r)) { throw new RuntimeException('De locatievelden zijn niet volledig opgeslagen. De post blijft concept.'); }
                if ($publish) {
                    $result = wp_update_post(['ID' => $id, 'post_status' => 'publish'], true);
                    if (is_wp_error($result) || get_post_status($id) !== 'publish') { throw new RuntimeException('De gecontroleerde post kon niet worden gepubliceerd.'); }
                }
                update_post_meta($id, self::STAMP, $stamp);
                if (get_post_meta($id, self::STAMP, true) !== $stamp) { throw new RuntimeException('De publicatiecontrole kon niet worden opgeslagen.'); }
                delete_post_meta($id, self::DIRTY);
            } finally { self::$writing = false; }
        }
    }
    public static function locations(array $state): array {
        $items = [];
        foreach (BZV_Afnemer_State::public_locations($state, time()) as $item) {
            $r = $state['records'][$item['locationId']]; $id = (int) ($r['postId'] ?? 0);
            if (!$id || get_post_type($id) !== self::TYPE || get_post_status($id) !== 'publish' || get_post_meta($id, self::OWNER, true) !== $r['id'] || get_post_meta($id, self::DIRTY, true)) { continue; }
            if (get_post_meta($id, self::STAMP, true) !== self::stamp($r, true)) { continue; }
            $native = self::native($id);
            if ($native !== self::payload($r)) { continue; }
            unset($native['placeId']); $items[] = ['locationId' => $r['id'], 'postId' => $id, ...$native];
        }
        return $items;
    }
    public static function meta_changed($meta_id, $post_id, $meta_key, $value): void {
        if (!in_array($meta_key, ['address', 'adress', 'postal_code', 'city', 'location', '_bzv_country_code'], true)) { return; }
        self::post_changed((int) $post_id);
    }
    public static function post_changed($post_id): void {
        if (self::$writing || get_post_type((int) $post_id) !== self::TYPE || !get_post_meta((int) $post_id, self::OWNER, true)) { return; }
        update_post_meta((int) $post_id, self::DIRTY, '1'); delete_post_meta((int) $post_id, self::STAMP);
    }
    public static function has_edits(array $state): bool {
        foreach ($state['records'] as $r) { if (!empty($r['postId']) && get_post_meta($r['postId'], self::DIRTY, true)) { return true; } }
        return false;
    }
    public static function reviewable(array $r): bool {
        $id = (int) ($r['postId'] ?? 0);
        return $id && get_post_type($id) === self::TYPE && get_post_status($id) !== 'trash' &&
            get_post_meta($id, self::OWNER, true) === $r['id'] && !get_post_meta($id, self::DIRTY, true);
    }
    public static function columns(array $columns): array { $columns['bzv_review'] = 'BOP-controle'; return $columns; }
    public static function column(string $column, int $id): void {
        if ($column !== 'bzv_review') { return; }
        $owner = get_post_meta($id, self::OWNER, true);
        if (!$owner) { echo 'Oude import / nog niet gekoppeld'; return; }
        $r = BZV_Afnemer_Reviews::state()['records'][$owner] ?? null;
        echo esc_html(!$r || !$r['active'] ? 'Niet actief' : ($r['approvedAt'] && get_post_status($id) === 'publish' ? 'Gecontroleerd' : 'Te controleren'));
        if (current_user_can('manage_options')) { echo '<br><a href="' . esc_url(BZV_Afnemer_Reviews::review_url($owner)) . '">Locatie controleren</a>'; }
    }
    public static function meta_box($post): void {
        if (!get_post_meta($post->ID, self::OWNER, true)) { return; }
        add_meta_box('bzv-afnemer-review', 'BOP-locatiecontrole', static function ($post) {
            echo '<p>Deze post hoort bij een afnemer uit BOP. Een gewijzigd adres of kaartpunt moet opnieuw gecontroleerd worden voordat het op de zuivelkaart verschijnt.</p>';
            self::column('bzv_review', (int) $post->ID);
        }, self::TYPE, 'side', 'high');
    }
}
