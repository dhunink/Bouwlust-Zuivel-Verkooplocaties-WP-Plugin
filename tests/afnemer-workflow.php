<?php
// Integration at the WordPress boundary. All HTTP and mail calls are local doubles; no real delivery.
const ABSPATH = __DIR__;
const HOUR_IN_SECONDS = 3600;
const MINUTE_IN_SECONDS = 60;
const BZV_VERSION = '0.4.0';
const BOP_CUSTOMER_LOCATIONS_URL = 'https://postoffice.example.test';
const BOP_CUSTOMER_LOCATIONS_TOKEN = 'test-only-not-a-credential';
class WP_Error { public function __construct(public $code, public $message, public $data = []) {} public function get_error_message() { return $this->message; } }
$store = []; $transients = []; $mails = []; $routes = []; $cap = true; $user = 17; $mailAccepted = true; $remoteMode = 'fresh';
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
function rocket_clean_files(...$args) {}
function esc_html($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return esc_html($t); }
function esc_url($t) { return esc_html($t); }
function esc_textarea($t) { return esc_html($t); }
function admin_url($path) { return 'https://wordpress.example.test/wp-admin/' . $path; }
function current_user_can($cap) { return $GLOBALS['cap']; }
function get_current_user_id() { return $GLOBALS['user']; }
function get_users($args) { return [(object) ['user_email' => 'one@example.test'], (object) ['user_email' => 'two@example.test']]; }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function wp_mail($to, $subject, $html, $headers) { $GLOBALS['mails'][] = compact('to', 'subject', 'html', 'headers'); return $GLOBALS['mailAccepted']; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function add_action(...$args) {}
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
$store[BZV_Afnemer_Reviews::SETTINGS] = ['enabled' => true, 'emailEnabled' => true, 'recipientMode' => 'administrators', 'browserKey' => 'browser-fixture', 'serverKey' => 'server-fixture'];
BZV_Afnemer_Reviews::routes();
foreach ($routes as $route) check($route['methods'] === 'POST' && $route['permission_callback'] === [BZV_Afnemer_Reviews::class, 'permission'], 'Every mutation requires POST and admin capability.');
BZV_Afnemer_Reviews::sync();
check(count($mails) === 2, 'All current WordPress administrators receive one notification.');
check(!str_contains($mails[0]['html'], 'bop_post_v1') && str_contains($mails[0]['html'], 'Locatie controleren'), 'Mail contains a review link, no credentials.');
check(!str_contains($mails[0]['html'], 'Voorbeeld & Zonen') && str_contains($mails[0]['html'], 'Voorbeeld &amp; Zonen'), 'Customer names are escaped in mail.');
check(BZV_Bop_Locations::looper() === [], 'Private candidates are absent from the public Pro provider.');
$remoteMode = 'unchanged'; BZV_Afnemer_Reviews::sync(); check(count($mails) === 2, 'An unchanged snapshot does not send again.');
$r = reset($store[BZV_Afnemer_Reviews::STATE]['records']); $id = $r['id'];
$cap = false;
check(is_wp_error(BZV_Afnemer_Reviews::handle('lookup', ['id' => $id, 'revision' => $r['revision']])), 'Anonymous or editor requests cannot look up private customers.');
$cap = true;
$lookup = BZV_Afnemer_Reviews::handle('lookup', ['id' => $id, 'revision' => $r['revision'], 'placeId' => 'test-place']);
check(count($lookup['items']) === 1 && count($lookup['items'][0]['addresses']) === 1, 'Selected Google point gets independent PDOK address proposals.');
check(!str_contains(json_encode($lookup), 'DO NOT COPY') && !str_contains(json_encode($transients), 'DO NOT COPY'), 'Google-formatted address is neither stored nor returned.');
$body = ['id' => $id, 'revision' => $r['revision'], 'fields' => ['name' => '<script>test</script>', ...$lookup['items'][0]['addresses'][0]],
    'token' => $lookup['items'][0]['token'], 'approve' => true, 'confirmed' => false];
check(is_wp_error(BZV_Afnemer_Reviews::handle('save', $body)), 'No approval without explicit confirmation.');
$body['confirmed'] = true; $bad = $body; $bad['fields']['address'] = 'Changed after lookup';
check(is_wp_error(BZV_Afnemer_Reviews::handle('save', $bad)), 'Lookup is bound to the proposed/queried address.');
$user = 18; check(is_wp_error(BZV_Afnemer_Reviews::handle('save', $body)), 'A different administrator cannot replay a lookup token.'); $user = 17;
check(!is_wp_error(BZV_Afnemer_Reviews::handle('save', $body)), 'Reviewed proposal can be approved.');
check(count(BZV_Bop_Locations::looper()) === 1 && !str_contains(BZV_Bop_Locations::address_list(), '<script>'), 'Approved record reaches escaped Pro map/list.');
check(is_wp_error(BZV_Afnemer_Reviews::handle('save', $body)), 'Stale repeated approval cannot overwrite later edits.');
$remoteMode = 'fail'; BZV_Afnemer_Reviews::sync();
check(count(BZV_Bop_Locations::looper()) === 1, 'Temporary transport error preserves a still-fresh reviewed location.');
check(!str_contains(json_encode($store), 'raw secret'), 'Raw upstream exceptions are never retained.');
$store[BZV_Afnemer_Reviews::STATE]['directory']['publishedTimestamp'] = time() - BZV_Afnemer_State::MAX_AGE;
check(BZV_Bop_Locations::looper() === [], 'Read path itself refuses stale activity even if cron has not run.');
$remoteMode = 'fresh'; BZV_Afnemer_Reviews::sync(); // contradictory same version correctly rejected
// Browser preview uses real PHP rendering and synthetic customer data, never production mail.
if (getenv('BZV_PREVIEW_DIR')) {
    $store[BZV_Afnemer_Reviews::STATE]['directory']['publishedTimestamp'] = time();
    $store[BZV_Afnemer_Reviews::STATE]['records'][$id]['name'] = 'Voorbeeld & Zonen';
    $store[BZV_Afnemer_Reviews::STATE]['records'][$id]['approvedAt'] = null;
    $store[BZV_Afnemer_Reviews::STATE]['records'][$id]['latitude'] = null;
    $store[BZV_Afnemer_Reviews::STATE]['records'][$id]['longitude'] = null;
    $preview = getenv('BZV_PREVIEW_DIR');
    $_GET = ['review' => $id]; ob_start(); BZV_Afnemer_Admin::render(); $html = ob_get_clean();
    file_put_contents($preview . '/review.html', '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="afnemer-review.css"><body style="margin:24px;background:#f4f5f6;font-family:system-ui">' . $html . '</body></html>');
    file_put_contents($preview . '/mail.html', $mails[0]['html']);
}
// A transport refusal retries only when due; an interrupted handoff needs deliberate retry.
$store[BZV_Afnemer_Reviews::STATE] = BZV_Afnemer_State::empty();
$mails = []; $mailAccepted = false; $remoteMode = 'fresh';
BZV_Afnemer_Reviews::sync();
check(count($mails) === 2, 'Each administrator was offered the first mail once.');
BZV_Afnemer_Reviews::sync();
check(count($mails) === 2, 'A mail failure is not retried immediately.');
foreach ($store[BZV_Afnemer_Reviews::STATE]['jobs'] as &$job) { $job['nextAttempt'] = time() - 1; } unset($job);
$mailAccepted = true; BZV_Afnemer_Reviews::sync();
check(count($mails) === 4, 'Due failed mails retry and can be accepted.');
$jobId = array_key_first($store[BZV_Afnemer_Reviews::STATE]['jobs']);
$store[BZV_Afnemer_Reviews::STATE]['jobs'][$jobId]['state'] = 'sending';
$store[BZV_Afnemer_Reviews::STATE]['jobs'][$jobId]['startedAt'] = time() - 600;
BZV_Afnemer_Reviews::sync();
check(count($mails) === 4, 'An uncertain handoff is never automatically repeated.');
check(!is_wp_error(BZV_Afnemer_Reviews::handle('retry-mail', ['id' => $jobId])), 'Administrator may deliberately retry an uncertain handoff.');
BZV_Afnemer_Reviews::sync();
check(count($mails) === 5, 'Manual retry offers only the selected mail again.');
echo "Afnemer workflow tests passed: administrator-only POST routes, Google proof/PDOK boundary, approval, conflict rejection, Pro output and deduplicated admin mail.\n";
