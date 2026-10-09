<?php
// Executable contract tests without WordPress or network access: php tests/bop-locations.php
const ABSPATH = __DIR__;
const HOUR_IN_SECONDS = 3600;
const MINUTE_IN_SECONDS = 60;
const BOP_CUSTOMER_LOCATIONS_URL = 'https://postoffice.example.test';
const BOP_CUSTOMER_LOCATIONS_TOKEN = 'local-test-only';
class WP_Error { public function __construct(public string $code, public string $message) {} public function get_error_message() { return $this->message; } }
$store = []; $calls = []; $purges = 0; $response = []; $scheduled = [];
function get_option($key, $default = false) { return $GLOBALS['store'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $changed = get_option($key) !== $value; $GLOBALS['store'][$key] = $value; return $changed; }
function add_option($key, $value, $unused = '', $autoload = null) { if (isset($GLOBALS['store'][$key])) return false; $GLOBALS['store'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['store'][$key]); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_safe_remote_get($url, $args) { $GLOBALS['calls'][] = [$url, $args]; return $GLOBALS['response']; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_schedule_single_event($timestamp, $hook) { $GLOBALS['scheduled'][$hook] = $timestamp; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['scheduled'][$hook]); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function apply_filters($hook, $value) { return $value; }
function absint($value) { return abs((int) $value); }
function get_permalink($id) { return 'https://example.test/zuivel/'; }
function rocket_clean_files($urls) { $GLOBALS['purges']++; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_url($text) { return esc_html($text); }
require __DIR__ . '/../includes/class-bzv-bop-locations.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
function fixture($generation = '639000000000000001', $age = 0) {
    $published = time() - $age;
    return ['generation' => $generation, 'publishedAt' => gmdate('Y-m-d\TH:i:s\Z', $published),
        'assessedOn' => (new DateTimeImmutable('@' . $published))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d'),
        'eTag' => '"' . str_repeat('a', 64) . '"', 'heading' => 'Hier vind je',
        'locations' => [['locationId' => '10000000-0000-0000-0000-000000000001', 'name' => '<script>alert(1)</script>',
            'address' => 'Dorpsstraat 1', 'postalCode' => '1234 AB', 'city' => 'Dorp', 'countryCode' => 'NL', 'latitude' => 52.0, 'longitude' => 4.0]]];
}
function respond($data) { $GLOBALS['response'] = ['code' => 200, 'body' => json_encode($data)]; }
$base = fixture();
respond($base); BZV_Bop_Locations::sync();
check(count(BZV_Bop_Locations::looper()) === 1, 'First complete publication installs one marker.');
check($store['bzv_bop_locations_snapshot']['generation'] === $base['generation'], 'Large generation must remain exact.');
check($calls[0][1]['redirection'] === 0 && $calls[0][1]['sslverify'], 'Never forward credentials on a redirect.');
check(!str_contains(BZV_Bop_Locations::address_list(), '<script>'), 'Escape all source text in marker and list markup.');
check(!str_contains(BZV_Bop_Locations::address_list(), BOP_CUSTOMER_LOCATIONS_TOKEN), 'Credentials must stay server-side.');
check($purges === 1, 'Changed list purges affected page.');
$before = $store['bzv_bop_locations_snapshot'];
$response = ['code' => 304]; BZV_Bop_Locations::sync();
check($store['bzv_bop_locations_snapshot'] === $before && $purges === 1, '304 must not extend BOP publication time or purge.');
foreach (['nullcoords', 'duplicate', 'stale', 'future', 'missingaddress', 'badcountry', 'badtime', 'partial', 'oldgeneration'] as $change) {
    $data = fixture('639000000000000002');
    switch ($change) {
        case 'nullcoords': $data['locations'][0]['latitude'] = null; break;
        case 'duplicate': $data['locations'][] = $data['locations'][0]; break;
        case 'stale': $data = fixture('639000000000000002', BZV_Bop_Locations::MAX_AGE); break;
        case 'future': $data = fixture('639000000000000002', -601); break;
        case 'missingaddress': unset($data['locations'][0]['address']); break;
        case 'badcountry': $data['locations'][0]['countryCode'] = 'nl'; break;
        case 'badtime': $data['publishedAt'] = 'now'; break;
        case 'partial': $data['locations'][] = ['name' => 'Broken second item']; break;
        case 'oldgeneration': $data['generation'] = '639000000000000000'; break;
    }
    respond($data); BZV_Bop_Locations::sync();
    check($store['bzv_bop_locations_snapshot'] === $before, "$change must not replace good data.");
}
foreach ([new WP_Error('network', 'secret upstream data'), ['code' => 401], ['code' => 503], ['code' => 200, 'body' => '<html>bad gateway</html>']] as $error) {
    $response = $error; BZV_Bop_Locations::sync();
    check($store['bzv_bop_locations_snapshot'] === $before, 'Failures preserve last good snapshot.');
    check(!str_contains($store['bzv_bop_locations_status']['message'], 'secret upstream data'), 'Do not persist raw network exceptions.');
}
$empty = fixture('639000000000000003'); $empty['locations'] = []; respond($empty); BZV_Bop_Locations::sync();
check(BZV_Bop_Locations::looper() === [] && isset($store['bzv_bop_locations_snapshot']), 'Valid empty is authoritative, not an error.');
check(str_contains(BZV_Bop_Locations::address_list(), 'geen afnemers'), 'Valid empty gets its own visitor message.');
respond(fixture('639000000000000004')); BZV_Bop_Locations::sync();
$store['bzv_bop_locations_snapshot']['publishedTimestamp'] = time() - BZV_Bop_Locations::MAX_AGE;
$response = ['code' => 304]; BZV_Bop_Locations::sync();
check(!isset($store['bzv_bop_locations_snapshot']) && BZV_Bop_Locations::looper() === [], 'Expired snapshot is removed, including cached coordinates, even on a 304.');
check(str_contains(BZV_Bop_Locations::address_list(), 'tijdelijk niet beschikbaar'), 'Expired is distinguishable from authoritative empty.');
$store['bzv_bop_locations_lock'] = time(); $count = count($calls); BZV_Bop_Locations::sync();
check(count($calls) === $count, 'Concurrent cron/admin refresh cannot interleave snapshots.');
fwrite(STDOUT, "BOP WordPress contract tests passed (validation, replacement, expiry, cache, escaping, auth, concurrency).\n");
