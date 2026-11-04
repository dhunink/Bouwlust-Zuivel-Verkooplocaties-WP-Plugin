<?php
require __DIR__ . '/native-fixture.php';
require __DIR__ . '/native-posts-fixture.php';
function wp_is_post_revision($id) { return false; }
function add_settings_error(...$args) { $GLOBALS['settingsErrors'][] = $args; }
function acf_add_validation_error(...$args) { $GLOBALS['validationErrors'][] = $args; }
class TestProfile {
    public static function get($id) { return $id === 123 ? new self() : null; }
    public function get_status() { return 'publish'; }
    public function get_auth_type() { return 'bearer'; }
    public function get_base_url() { return 'https://postoffice.example.test'; }
    public function get_auth_config_value($key, $default) { return 'bop_post_v1.fixture.' . str_repeat('a', 64); }
}
class_alias(TestProfile::class, 'GC_API_Alchemist\\Connection_Profiles\\Connection_Profile');
function gp_address_autocomplete() { return new class { public function get_google_api_key() { return 'existing-gravitywiz-fixture'; } }; }
require __DIR__ . '/../includes/class-bzv-directory.php';
require __DIR__ . '/../includes/class-bzv-native.php';
require __DIR__ . '/../includes/class-bzv-settings.php';
require __DIR__ . '/../includes/class-bzv-postoffice.php';
BZV_Native::init();
$store['bzv_native_settings'] = ['profile' => 123, 'automatic' => true, 'email' => true, 'recipients' => [1, 2]];
check(!BZV_Settings::get()['automatic'] && !BZV_Settings::get()['recipients'], 'Missing native ACF configuration cannot silently enable imports or add all administrators.');
$acfOptions = ['profile' => 123, 'automatic' => true, 'email' => true, 'recipients' => [1, 4], 'page' => 11088];
check(BZV_Native::google_key(['libraries' => 'places']) === ['libraries' => 'places', 'key' => 'existing-gravitywiz-fixture'], 'ACF reuses GravityWiz key through its getter without storing a second key.');
check(BZV_Native::google_key(['key' => 'existing-acf-fixture'])['key'] === 'existing-acf-fixture', 'A working ACF configuration is never overwritten by a differently restricted GravityWiz key.');
// Full private contract validation must finish before the first post is created.
$original = $directory;
foreach ([['customers', [$directory['customers'][0], $directory['customers'][0]]], ['generation', '1e9'], ['publishedAt', '2000-01-01T00:00:00Z']] as [$key, $bad]) {
    $directory[$key] = $bad; $result = BZV_Postoffice::sync(true); check(!$result['ok'] && !$posts, 'Invalid or old directory does not mutate posts.'); $directory = $original;
}
$directory['customers'][0]['address'] = 'Dorpsstraat 12'; $directory['customers'][0]['postalCode'] = '1234AB'; $directory['customers'][0]['city'] = 'Testdorp';
$result = BZV_Postoffice::sync(true); $postId = array_key_first($posts);
check($result['ok'] && $result['created'] === 1 && count($posts) === 1 && get_post_status($postId) === 'draft', 'First import creates exactly one native draft.');
check(get_post_meta($postId, 'show_on_map') === '1' && get_post_meta($postId, '_show_on_map') === 'field_6aeb36d3db858', 'New imports persist the native ACF default so Admin Columns can display and filter it.');
check(get_post_meta($postId, '_bop_address') === 'Dorpsstraat 12, 1234AB Testdorp' && !get_post_meta($postId, 'location') && !get_post_meta($postId, 'address'), 'Only source reference metadata is initialized; no duplicate address fields, coordinates or approvals are fabricated.');
check(count($mails) === 1 && str_contains($mails[0]['html'], 'post.php?post=' . $postId) && !str_contains($mails[0]['html'], 'bop-afnemers'), 'Selected users with a shared email get one short notice linking to the native editor.');
check(get_post_meta($postId, 'bop_inactive_since') === '', 'A newly active customer has no inactivity date.');
$directory['customers'][] = array_replace($directory['customers'][0], ['customerId' => 'source:' . str_repeat('1', 64), 'active' => false]);
check(BZV_Postoffice::sync(true)['created'] === 0 && count($posts) === 1, 'Legacy inactive records never create new WordPress posts.');
array_pop($directory['customers']);
BZV_Postoffice::sync(true); check(count($posts) === 1 && count($mails) === 1, 'Repeated import is idempotent, including mail.');
$_POST = ['post_ID' => $postId, 'publish' => 'Publiceren', 'post_status' => 'draft'];
BZV_Native::validate_editor(); check(count($validationErrors ?? []) === 1, 'Publish requires a valid map point through standard ACF validation.');
wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
check(get_post_status($postId) === 'draft' && !BZV_Native::looper(), 'Non-ACF publication cannot expose an incomplete post.');
// Reproduce the reported workflow: only choose the map; the old text fields stay empty.
foreach (['address', 'postal_code', 'city'] as $name) { update_post_meta($postId, $name, ''); }
$map = ['address' => 'Dorpsstraat 12, 1234 AB Testdorp, Nederland', 'street_name' => 'Dorpsstraat', 'street_number' => '12', 'post_code' => '1234 AB', 'city' => 'Testdorp', 'lat' => 52.0, 'lng' => 4.0];
update_field('field_6a8755b152cb6', $map, $postId);
check(BZV_Native::source_status($postId)[0] === 'Actieve afnemer' && BZV_Native::website_status($postId) === 'Verborgen — nog niet gepubliceerd', 'BOP activity is distinct from native publication.');
check(array_keys(BZV_Native::fields()) === ['location', 'show_on_map', 'bop_inactive_since'], 'Only native map, visibility and inactivity date fields are required.');
$GLOBALS['formPostId'] = $postId;
$statusField = BZV_Native::status_field(['key' => 'field_bzv_source_status', 'type' => 'message']);
check(str_contains($statusField['message'], 'Actieve afnemer') && str_contains($statusField['message'], 'BOP-brongegevens:'), 'Native read-only ACF message receives current source status and timestamp.');
$_POST['acf'] = ['field_6a8755b152cb6' => json_encode($map)];
$validationErrors = []; BZV_Native::validate_editor(); check(!$validationErrors, 'Complete active native post passes ACF validation.');
$purged = []; wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
check(get_post_status($postId) === 'publish' && count(BZV_Native::looper()) === 1 && $purged === [['https://example.test/zuivel/']], 'Native Publish exposes the ACF point and invalidates the target WP Rocket page.');
check(get_post_meta($postId, 'address') === '' && get_post_meta($postId, 'postal_code') === '' && get_post_meta($postId, 'city') === '', 'Saving a map leaves existing legacy data untouched; Admin Columns reads the map directly.');
check(BZV_Native::looper()[0]['address'] === $map['address'] && BZV_Native::looper()[0]['name'] === 'Voorbeeld &amp; Zonen' && !isset(BZV_Native::looper()[0]['popupHtml']), 'The Pro adapter returns escaped text and numeric coordinates, without presentation HTML.');
check(BZV_Native::website_status($postId) === 'Zichtbaar op de zuivelkaart', 'Read-only website status reflects actual published marker eligibility.');
delete_post_meta($postId, 'show_on_map');
check(BZV_Native::show_on_map($postId) && count(BZV_Native::looper()) === 1, 'Missing legacy visibility metadata preserves existing approved locations.');
BZV_Postoffice::sync(true); check(get_post_meta($postId, 'show_on_map') === '', 'Repeated import never initializes existing missing visibility choices.');
$purged = []; update_field('field_6aeb36d3db858', '0', $postId);
check(!BZV_Native::looper() && get_post_status($postId) === 'publish', 'Opt-out hides marker and public address without unpublishing the customer.');
check($purged === [['https://example.test/zuivel/']] && str_contains(BZV_Native::website_status($postId), 'staat uit'), 'Visibility changes purge the target page and explain the hidden state.');
BZV_Postoffice::sync(true); check(!BZV_Native::show_on_map($postId) && !BZV_Native::looper(), 'Repeated BOP imports preserve the local visibility choice.');
$_POST['acf']['field_6aeb36d3db858'] = '0'; $_POST['acf']['field_6a8755b152cb6'] = '';
$validationErrors = []; BZV_Native::validate_editor(); check(!$validationErrors, 'A hidden customer needs no geocoding in the native editor.');
update_field('field_6a8755b152cb6', '', $postId); BZV_Native::saved($postId);
check(get_post_status($postId) === 'publish' && !BZV_Native::looper(), 'Saving a hidden customer without a map retains publication and stays private to the map.');
$_POST['acf']['field_6aeb36d3db858'] = '1'; $validationErrors = []; BZV_Native::validate_editor();
check(count($validationErrors) === 1, 'Re-enabling a hidden location requires a valid map even when the saved choice is off.');
update_field('field_6aeb36d3db858', '1', $postId); BZV_Native::saved($postId);
check(get_post_status($postId) === 'draft' && !BZV_Native::looper(), 'Non-ACF enable without a location fails closed to draft.');
update_field('field_6a8755b152cb6', $map, $postId); $_POST['acf']['field_6a8755b152cb6'] = json_encode($map);
check(!BZV_Native::looper(), 'Enabled drafts still require conscious publication.');
wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
check(count(BZV_Native::looper()) === 1, 'A reviewed enabled location returns after native publication.');
$minimalMap = ['address' => 'Een adres zonder losse Google-onderdelen', 'lat' => 52.0, 'lng' => 4.0];
check(BZV_Native::valid_location($minimalMap), 'Optional Google address components never require duplicate manual input.');
check(!BZV_Native::valid_location(['lat' => 52, 'lng' => 4]) && !BZV_Native::valid_location(['address' => 'x', 'lat' => '1e999', 'lng' => 4]), 'Missing address and non-finite points remain blocked.');
$_POST['acf']['field_6a8755b152cb6'] = ''; $validationErrors = []; BZV_Native::validate_editor();
check(count($validationErrors) === 1, 'Clearing a posted map is rejected even if the previous saved map was valid.');
$_POST['acf']['field_6a8755b152cb6'] = json_encode($map);
update_post_meta($postId, 'address', 'Eigen adres 8'); BZV_Postoffice::sync(true);
check(get_post_meta($postId, 'address') === 'Eigen adres 8', 'Postkantoor never overwrites native editor changes.');
$directory['generation'] = (string) ((int) $directory['generation'] + 1); $directory['eTag'] = '"' . str_repeat('c', 64) . '"';
$directory['customers'][0]['aliases'] = [$directory['customers'][0]['customerId']]; $directory['customers'][0]['customerId'] = 'location:10000000-0000-0000-0000-111111111111'; $directory['customers'][0]['identityConfirmed'] = true;
check(BZV_Postoffice::sync(true)['ok'] && count($posts) === 1 && get_post_status($postId) === 'publish', 'Explicit BOP aliases retain the same native post and publication.');
$remoteMode = 'fail'; check(!BZV_Postoffice::sync(true)['ok'] && count(BZV_Native::looper()) === 1 && get_post_meta($postId, 'bop_inactive_since') === '', 'Network failure retains a still-fresh complete list without inventing inactivity.'); $remoteMode = 'fresh';
$store[BZV_Postoffice::SNAPSHOT]['publishedTimestamp'] = time() - 48 * 3600;
check(!BZV_Native::looper() && BZV_Native::source_status($postId)[0] === 'BOP-status niet actueel' && get_post_meta($postId, 'bop_inactive_since') === '', 'An expired source is distinct from an inactive customer and does not set its date.');
BZV_Postoffice::sync(true);
$directory['generation'] = (string) ((int) $directory['generation'] + 1); $directory['eTag'] = '"' . str_repeat('d', 64) . '"';
$directory['customers'][0]['active'] = false;
$failField = 'bop_inactive_since'; check(!BZV_Postoffice::sync(true)['ok'] && get_post_meta($postId, '_bop_active') === '1', 'Failed date storage does not silently accept an inactive transition.'); $failField = '';
BZV_Postoffice::sync(true);
check(get_post_status($postId) === 'draft' && !BZV_Native::looper() && BZV_Native::source_status($postId)[0] === 'Inactieve afnemer', 'BOP archiving drafts the post and has a clear inactive status.');
check(get_post_meta($postId, 'bop_inactive_since') === str_replace('-', '', $directory['assessedOn']) && get_post_meta($postId, '_bop_inactive_since') === 'field_bzv_inactive_since', 'The transition uses the snapshot assessment date and native ACF date storage.');
check(BZV_Native::protect_inactive_since('19990101', $postId) === get_post_meta($postId, 'bop_inactive_since'), 'Manual ACF submissions cannot rewrite a source-owned date.');
$directory['generation'] = (string) ((int) $directory['generation'] + 1); $directory['eTag'] = '"' . str_repeat('e', 64) . '"';
$directory['customers'][0]['active'] = true; BZV_Postoffice::sync(true);
check(get_post_status($postId) === 'draft' && get_post_meta($postId, 'bop_inactive_since') === '', 'Returning posts clear the date but require the normal native Publish action.');
wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
$returningCustomer = $directory['customers'][0]; $directory['customers'] = [];
$directory['generation'] = (string) ((int) $directory['generation'] + 1); $directory['eTag'] = '"' . str_repeat('2', 64) . '"';
check(BZV_Postoffice::sync(true)['ok'] && count($posts) === 1 && get_post_status($postId) === 'draft', 'A valid empty active-only snapshot retains the now inactive post as a draft.');
check(get_post_meta($postId, 'bop_inactive_since') === str_replace('-', '', $directory['assessedOn']) && get_post_meta($postId, 'location') === $map, 'Omission records its first confirmed date and retains the reviewed map.');
$earlierDate = (new DateTimeImmutable($directory['assessedOn']))->modify('-1 day')->format('Ymd');
$meta[$postId]['bop_inactive_since'] = $earlierDate;
BZV_Postoffice::sync(true); check(get_post_meta($postId, 'bop_inactive_since') === $earlierDate, 'A later repeat never resets an existing inactivity date.');
$directory['customers'] = [$returningCustomer]; $directory['generation'] = (string) ((int) $directory['generation'] + 1); $directory['eTag'] = '"' . str_repeat('3', 64) . '"';
BZV_Postoffice::sync(true); check(get_post_meta($postId, 'bop_inactive_since') === '' && get_post_status($postId) === 'draft' && count($posts) === 1, 'Return after omission reuses the same post without automatic publication.');
wp_update_post(['ID' => $postId, 'post_status' => 'trash']); BZV_Postoffice::sync(true);
check(get_post_status($postId) === 'trash' && count($posts) === 1, 'Trash is never resurrected or duplicated by sync.');
// A failed owner-meta write recovers by deterministic native slug, even after restart.
$directory['generation'] = (string) ((int) $directory['generation'] + 1); $directory['eTag'] = '"' . str_repeat('f', 64) . '"';
$directory['customers'][] = array_replace($directory['customers'][0], ['customerId' => 'source:' . str_repeat('e', 64), 'aliases' => [], 'identityConfirmed' => false, 'name' => 'Tweede afnemer']);
$failOwner = true; check(!BZV_Postoffice::sync(true)['ok'], 'Failed metadata writes are reported.'); $failOwner = false;
check(BZV_Postoffice::sync(true)['ok'] && count($posts) === 2, 'Retry creates no duplicate when ownership or first address writing fails.');
// Whole-snapshot identity ambiguity fails closed before any post writes.
$directory['generation'] = (string) ((int) $directory['generation'] + 1); $directory['eTag'] = '"' . str_repeat('9', 64) . '"';
$directory['customers'][0]['aliases'][] = $directory['customers'][1]['customerId']; array_pop($directory['customers']);
check(!BZV_Postoffice::sync(true)['ok'] && !BZV_Postoffice::fresh() && count($posts) === 2, 'Merge of two existing native posts is not guessed or published.');
$settingsInput = ['field_bzv_profile' => 123, 'field_bzv_email' => '1', 'field_bzv_recipients' => [3], 'field_bzv_page' => 11088];
check(BZV_Settings::validate(true, $settingsInput) === true, 'Existing non-admin users can be selected without added permissions.');
foreach ([['field_bzv_recipients', []], ['field_bzv_recipients', [999]], ['field_bzv_profile', 0], ['field_bzv_page', $postId]] as [$key, $bad]) {
    check(is_string(BZV_Settings::validate(true, array_replace($settingsInput, [$key => $bad]))), 'Invalid recipient/profile/page cannot be saved through ACF.');
}
$cap = false; check(is_string(BZV_Settings::validate(true, $settingsInput)), 'Non-admin cannot change connection settings.'); $cap = true;
check(BZV_Settings::validate('Previous validation error', $settingsInput) === 'Previous validation error', 'Earlier ACF validation errors remain intact.');
check(BZV_Settings::validate(true, array_replace($settingsInput, ['field_bzv_email' => '0', 'field_bzv_recipients' => []])) === true, 'Disabled notifications allow an empty selection.');
$store[BZV_Postoffice::SNAPSHOT] = ['generation' => 'fixture']; $purged = [];
BZV_Settings::profile_changed(123); check(isset($store[BZV_Postoffice::SNAPSHOT]), 'Saving the same native profile preserves the source generation.');
BZV_Settings::profile_changed(456); check(!isset($store[BZV_Postoffice::SNAPSHOT]) && count($purged) === 1, 'Changing the profile invalidates the old directory and cache.');
echo "Native workflow passed: private contract, stable CPT/aliases, ACF and GravityWiz reuse, standard Publish, cache/Pro output, recipient deduplication, stale data, archive/trash, failed writes and settings permissions.\n";
