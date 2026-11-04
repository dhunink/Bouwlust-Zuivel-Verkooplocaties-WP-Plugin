<?php
// Integration at the WordPress boundary. All HTTP and mail calls are local doubles; no real delivery.
const ABSPATH = __DIR__;
const HOUR_IN_SECONDS = 3600;
const MINUTE_IN_SECONDS = 60;
const BZV_VERSION = '0.5.0';
const BOP_CUSTOMER_LOCATIONS_URL = 'https://postoffice.example.test';
const BOP_CUSTOMER_LOCATIONS_TOKEN = 'test-only-not-a-credential';
class WP_Error { public function __construct(public $code, public $message, public $data = []) {} public function get_error_message() { return $this->message; } }
$store = []; $transients = []; $mails = []; $routes = []; $cap = true; $user = 17; $mailAccepted = true; $remoteMode = 'fresh';
$wpUsers = [
    1 => (object) ['ID' => 1, 'display_name' => 'Anna Beheer', 'user_email' => 'one@example.test', 'roles' => ['administrator']],
    2 => (object) ['ID' => 2, 'display_name' => 'Bram Beheer', 'user_email' => 'two@example.test', 'roles' => ['administrator']],
    3 => (object) ['ID' => 3, 'display_name' => 'Cato Redactie', 'user_email' => 'editor@example.test', 'roles' => ['editor']],
    4 => (object) ['ID' => 4, 'display_name' => 'Daan Dubbel', 'user_email' => 'ONE@example.test', 'roles' => ['subscriber']],
];
$directory = ['generation' => '639293040000000001', 'publishedAt' => gmdate('c'),
    'assessedOn' => (new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d'),
    'eTag' => '"' . str_repeat('a', 64) . '"', 'customers' => [['customerId' => 'source:' . str_repeat('b', 64), 'aliases' => [],
        'name' => 'Voorbeeld & Zonen', 'active' => true, 'identityConfirmed' => false, 'address' => '', 'postalCode' => '', 'city' => '', 'countryCode' => 'NL']]];
function get_option($key, $default = false) { return $GLOBALS['store'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $changed = get_option($key) !== $value; $GLOBALS['store'][$key] = $value; return $changed; }
function add_option($key, $value, $unused = '', $autoload = null) { if (array_key_exists($key, $GLOBALS['store'])) return false; $GLOBALS['store'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['store'][$key]); }
function wp_generate_uuid4() { static $count = 0; return sprintf('10000000-0000-0000-0000-%012d', ++$count); }
function wp_cache_delete(...$args) {}
$wpdb = new class { public $options = 'wp_options'; public function prepare($sql, ...$args) { return $args; }
    public function get_var($args) { return $GLOBALS['store'][$args[0]] ?? null; }
    public function query($args) { if (($GLOBALS['store'][$args[0]] ?? null) === $args[1]) { unset($GLOBALS['store'][$args[0]]); return 1; } return 0; } };
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_safe_remote_get($url, $args) {
    if (str_contains($url, 'postoffice.example')) {
        if ($GLOBALS['remoteMode'] === 'fail') return new WP_Error('network', 'raw secret exception');
        return ['code' => $GLOBALS['remoteMode'] === 'unchanged' ? 304 : 200, 'body' => json_encode($GLOBALS['directory'])];
    }
    if (str_contains($url, 'maps.googleapis.com')) return ['code' => 200, 'body' => json_encode(['status' => 'OK', 'results' => [
        ['place_id' => 'test-place', 'formatted_address' => 'DO NOT COPY GOOGLE ADDRESS', 'geometry' => ['location' => ['lat' => 52.0, 'lng' => 4.0], 'location_type' => 'ROOFTOP']]]])];
    if (str_contains($url, 'api.pdok.nl')) return ['code' => 200, 'body' => json_encode(['response' => ['docs' => [
        ['straatnaam' => 'Dorpsstraat', 'huisnummer' => 12, 'postcode' => '1234AB', 'woonplaatsnaam' => 'Testdorp']]]])];
    throw new RuntimeException('Unexpected HTTP destination: ' . $url);
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_schedule_single_event(...$args) {}
function wp_clear_scheduled_hook(...$args) {}
function apply_filters($hook, $value) { return $value; }
function absint($v) { return abs((int) $v); }
function get_permalink($id) { return 'https://example.test/zuivel/'; }
function rocket_clean_files($urls) { $GLOBALS['purged'][] = $urls; }
function esc_html($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return esc_html($t); }
function esc_url($t) { return esc_html($t); }
function esc_textarea($t) { return esc_html($t); }
function admin_url($path) { return 'https://wordpress.example.test/wp-admin/' . $path; }
function current_user_can($cap) { return $GLOBALS['cap']; }
function get_current_user_id() { return $GLOBALS['user']; }
function get_users($args) { return array_values(array_filter($GLOBALS['wpUsers'], static fn($u) =>
    (!isset($args['role']) || in_array($args['role'], $u->roles, true)) && (empty($args['include']) || in_array($u->ID, $args['include'], true)))); }
function user_can($id, $capability) { return isset($GLOBALS['wpUsers'][$id]) && in_array('administrator', $GLOBALS['wpUsers'][$id]->roles, true); }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function wp_mail($to, $subject, $html, $headers) { $GLOBALS['mails'][] = compact('to', 'subject', 'html', 'headers'); return $GLOBALS['mailAccepted']; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function add_action($hook, $callback, $priority = 10, $accepted = 1) { $GLOBALS['actions'][$hook][$priority][] = [$callback, $accepted]; }
function add_filter(...$args) {}
function do_action($hook, ...$args) { $actions = $GLOBALS['actions'][$hook] ?? []; ksort($actions); foreach ($actions as $callbacks) foreach ($callbacks as [$callback, $accepted]) { $callback(...array_slice($args, 0, $accepted)); } }
function register_rest_route($ns, $route, $args) { $GLOBALS['routes'][$route] = $args; }
function nocache_headers() {}
function wp_unslash($v) { return $v; }
function wp_nonce_field(...$args) { echo '<input type="hidden" name="_wpnonce" value="local-preview-nonce">'; }
function submit_button($text, $type = '', $name = '', $wrap = true) { echo '<button class="button">' . esc_html($text) . '</button>'; }
function wp_date($format, $date) { return gmdate($format, $date); }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function checked($a, $b, $echo = true) { return $a === $b ? ' checked' : ''; }
function selected($a, $b, $echo = true) { return $a === $b ? ' selected' : ''; }
require __DIR__ . '/../includes/class-bzv-afnemer-state.php';
require __DIR__ . '/../includes/class-bzv-afnemer-google.php';
require __DIR__ . '/../includes/class-bzv-afnemer-reviews.php';
require __DIR__ . '/../includes/class-bzv-afnemer-admin.php';
require __DIR__ . '/../includes/class-bzv-bop-locations.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
