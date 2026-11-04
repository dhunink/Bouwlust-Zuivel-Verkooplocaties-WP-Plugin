<?php
// WordPress/ACF boundary tests. No live posts, network requests or mail are made.
require __DIR__ . '/afnemer-fixture.php';
$posts = []; $meta = []; $fieldWrites = []; $failField = ''; $failInsert = false; $failOwner = false;
$acfFields = [
    ['name' => 'address', 'key' => 'field_6a87537d43248', 'type' => 'text'],
    ['name' => 'postal_code', 'key' => 'field_6a87547118145', 'type' => 'text'],
    ['name' => 'city', 'key' => 'field_6a87539e43249', 'type' => 'text'],
    ['name' => 'location', 'key' => 'field_6a8755b152cb6', 'type' => 'google_map'],
];
function acf_get_field_groups($args) { return [['key' => 'group_6a8753596bc2c']]; }
function acf_get_fields($group) { return $GLOBALS['acfFields']; }
function post_type_exists($type) { return $type === 'zuivel_verkooppunt'; }
function get_posts($args) { return array_values(array_filter($GLOBALS['posts'], static fn($p) => $p->post_type === $args['post_type'] && in_array($p->post_status, $args['post_status'], true))); }
function get_post($id) { return isset($GLOBALS['posts'][$id]) ? clone $GLOBALS['posts'][$id] : null; }
function get_post_type($id) { return get_post($id)?->post_type ?: false; }
function get_post_status($id) { return get_post($id)?->post_status ?: false; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) {
    if ($GLOBALS['failOwner'] && $key === '_bzv_afnemer_id') { return false; }
    if (get_post_meta($id, $key) === $value) { return false; }
    $hook = isset($GLOBALS['meta'][$id][$key]) ? 'updated_post_meta' : 'added_post_meta';
    $GLOBALS['meta'][$id][$key] = $value; do_action($hook, 1, $id, $key, $value); return true;
}
function delete_post_meta($id, $key) {
    if (!isset($GLOBALS['meta'][$id][$key])) { return false; }
    $old = $GLOBALS['meta'][$id][$key]; unset($GLOBALS['meta'][$id][$key]);
    do_action('deleted_post_meta', [1], $id, $key, $old); return true;
}
function wp_slash($input) { return $input; }
function wp_insert_post($input, $error = false) {
    if ($GLOBALS['failInsert']) { return new WP_Error('db', 'fixture insert failure'); }
    $id = count($GLOBALS['posts']) ? max(array_keys($GLOBALS['posts'])) + 1 : 1;
    $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => $input['post_type'], 'post_status' => $input['post_status'], 'post_title' => $input['post_title'], 'post_name' => $input['post_name'] ?? 'fixture'];
    foreach ($input['meta_input'] ?? [] as $k => $v) { update_post_meta($id, $k, $v); }
    do_action('wp_after_insert_post', $id); return $id;
}
function wp_update_post($input, $error = false) {
    if (!isset($GLOBALS['posts'][$input['ID']])) { return new WP_Error('db', 'missing post'); }
    foreach ($input as $k => $v) { $GLOBALS['posts'][$input['ID']]->$k = $v; }
    do_action('wp_after_insert_post', $input['ID']); return $input['ID'];
}
function update_field($key, $value, $id) {
    $fields = array_column($GLOBALS['acfFields'], null, 'key');
    check(isset($fields[$key]), 'New ACF data must use the actual field key, not only its name.');
    $name = $fields[$key]['name']; $GLOBALS['fieldWrites'][] = [$id, $key, $value];
    if ($GLOBALS['failField'] === $name) { return false; }
    update_post_meta($id, '_' . $name, $key);
    return $value === null ? delete_post_meta($id, $name) : update_post_meta($id, $name, $value);
}
require __DIR__ . '/../includes/class-bzv-afnemer-posts.php';
BZV_Afnemer_Posts::init();
function current_record() { return reset($GLOBALS['store'][BZV_Afnemer_Reviews::STATE]['records']); }
function submit_review(bool $approve = true, array $overrides = []) {
    $r = current_record();
    $fields = array_replace(array_intersect_key($r, array_flip(['name', 'address', 'postalCode', 'city', 'countryCode'])), $overrides);
    $lookup = BZV_Afnemer_Reviews::handle('lookup', ['id' => $r['id'], 'revision' => $r['revision'], 'fields' => $fields]);
    check(!is_wp_error($lookup), 'Fixture lookup succeeds for the current review.');
    return BZV_Afnemer_Reviews::handle('save', ['id' => $r['id'], 'revision' => $r['revision'], 'fields' => $fields,
        'token' => $lookup['items'][0]['token'], 'approve' => $approve, 'confirmed' => true]);
}
function next_directory() {
    $GLOBALS['directory']['generation'] = (string) ((int) $GLOBALS['directory']['generation'] + 1);
    $GLOBALS['directory']['eTag'] = '"' . hash('sha256', $GLOBALS['directory']['generation']) . '"';
}
$store[BZV_Afnemer_Reviews::SETTINGS] = ['enabled' => true, 'emailEnabled' => false, 'serverKey' => 'server-fixture'];
$legacy = wp_insert_post(['post_type' => 'zuivel_verkooppunt', 'post_status' => 'publish', 'post_title' => 'Voorbeeld & Zonen']);
update_post_meta($legacy, '_zuivel_import_managed', '1');
update_field('field_6a87537d43248', 'Dorpsstraat 12', $legacy);
update_field('field_6a87547118145', '1234AB', $legacy);
update_field('field_6a87539e43249', 'Testdorp', $legacy);
update_field('field_6a8755b152cb6', ['lat' => 1, 'lng' => 2, 'place_id' => 'legacy-place', 'address' => 'OLD GOOGLE ADDRESS'], $legacy);
$directory['customers'][0]['customerId'] = 'source:' . hash('sha256', 'Voorbeeld & Zonen');
BZV_Afnemer_Reviews::sync();
$r = current_record(); $id = $r['id'];
check(count($posts) === 1 && $r['postId'] === $legacy, 'One exact legacy source match reuses the existing CPT.');
check($r['address'] === 'Dorpsstraat 12' && $r['placeId'] === 'legacy-place', 'Own legacy address and place identity seed the proposal.');
check($r['latitude'] === null && !get_post_meta($legacy, 'location') && !$r['approvedAt'], 'Unknown-age imported coordinates are deleted, never approved.');
check(get_post_status($legacy) === 'draft' && !get_post_meta($legacy, '_zuivel_import_managed'), 'CPT starts as draft and is removed from legacy importer ownership.');
$fieldWrites = []; BZV_Afnemer_Reviews::sync();
check(count($posts) === 1 && !$fieldWrites, 'Repeated identical sync creates no duplicates or unnecessary ACF writes.');
$purged = [];
check(!is_wp_error(submit_review()), 'Explicit approval succeeds.');
check(get_post_status($legacy) === 'publish' && count(BZV_Afnemer_Reviews::locations()) === 1, 'Approval publishes the CPT and public map point.');
check(get_post_meta($legacy, '_location') === 'field_6a8755b152cb6', 'ACF reference metadata is present for native editors.');
check(get_post_meta($legacy, 'location')['address'] === 'Dorpsstraat 12, 1234AB Testdorp', 'Stored ACF label uses own address, never Google business/address content.');
check(BZV_Afnemer_Reviews::locations()[0]['postId'] === $legacy && BZV_Bop_Locations::looper()[0]['latitude'] === 52.0, 'Existing Pro provider consumes the verified CPT coordinates.');
check($purged === [['https://example.test/zuivel/']], 'Approval invalidates exactly the target WP Rocket page.');
$approved = current_record()['approvedAt']; $revision = current_record()['revision'];
wp_update_post(['ID' => $legacy, 'post_content' => 'Unrelated editor text']);
BZV_Afnemer_Reviews::reconcile_post_edits();
check(current_record()['approvedAt'] === $approved && current_record()['revision'] === $revision && count(BZV_Afnemer_Reviews::locations()) === 1, 'Unchanged location saves preserve approval.');
update_field('field_6a87537d43248', 'Dorpsstraat 14', $legacy);
check(!BZV_Afnemer_Reviews::locations(), 'Native address changes disappear from the provider immediately.');
BZV_Afnemer_Reviews::reconcile_post_edits();
check(current_record()['address'] === 'Dorpsstraat 14' && !current_record()['approvedAt'] && current_record()['latitude'] === null && get_post_status($legacy) === 'draft', 'Native address edits return to review; old coordinates cannot approve a new address.');
check(in_array(['https://example.test/zuivel/'], $purged, true), 'Native edits also purge the collection page.');
check(!is_wp_error(submit_review()), 'Changed address can be looked up and explicitly approved.');
$r = current_record();
check(!is_wp_error(BZV_Afnemer_Reviews::handle('withdraw', ['id' => $id, 'revision' => $r['revision']])), 'Withdrawal succeeds.');
check(get_post_status($legacy) === 'draft' && !BZV_Afnemer_Reviews::locations(), 'Withdrawal hides the CPT and marker.');
check(!is_wp_error(submit_review()), 'Owner can approve again.');
next_directory(); $directory['customers'][0]['active'] = false; BZV_Afnemer_Reviews::sync();
check(get_post_status($legacy) === 'draft' && !BZV_Afnemer_Reviews::locations(), 'BOP archive drafts the post.');
next_directory(); $directory['customers'][0]['active'] = true; BZV_Afnemer_Reviews::sync();
check(get_post_status($legacy) === 'publish' && count($posts) === 1, 'Active return reuses the same still-reviewed CPT.');
next_directory(); $directory['customers'][0]['aliases'] = [$directory['customers'][0]['customerId']];
$directory['customers'][0]['customerId'] = 'location:10000000-0000-0000-0000-999999999999';
$directory['customers'][0]['identityConfirmed'] = true; BZV_Afnemer_Reviews::sync();
check(current_record()['id'] === $id && current_record()['postId'] === $legacy && count($posts) === 1, 'Explicit BOP alias resolution retains the CPT and review URL.');
$store[BZV_Afnemer_Reviews::STATE]['records'][$id]['locatedAt'] = time() - BZV_Afnemer_State::GOOGLE_AGE;
check(!BZV_Afnemer_Reviews::locations(), 'Expired Google points are blocked before cron.');
BZV_Afnemer_Reviews::expire();
check(!get_post_meta($legacy, 'location') && get_post_status($legacy) === 'draft', 'Expiry physically clears ACF coordinates and drafts the post.');
check(!is_wp_error(submit_review()), 'A fresh lookup can restore approval.');
$failField = 'address';
check(is_wp_error(submit_review(true, ['address' => 'Dorpsstraat 16'])), 'Failed ACF writes return an error.');
check(get_post_status($legacy) === 'draft' && !BZV_Afnemer_Reviews::locations(), 'Partially written posts never reach the public map.');
$failField = ''; BZV_Afnemer_Reviews::sync();
check(count($posts) === 1 && get_post_meta($legacy, 'address') === 'Dorpsstraat 16', 'Retry repairs the existing post without duplicating it.');
update_field('field_6a8755b152cb6', ['lat' => 51.9, 'lng' => 4.2, 'place_id' => 'native-place'], $legacy);
BZV_Afnemer_Reviews::reconcile_post_edits();
check(current_record()['latitude'] === 51.9 && !current_record()['approvedAt'], 'Native ACF position is adopted as a new unapproved proposal.');
check(!is_wp_error(submit_review()), 'Approve before trash scenario.');
wp_update_post(['ID' => $legacy, 'post_status' => 'trash']); BZV_Afnemer_Reviews::reconcile_post_edits(); BZV_Afnemer_Reviews::sync();
check(get_post_status($legacy) === 'trash' && !BZV_Afnemer_Reviews::locations() && count($posts) === 1, 'Trashed posts are never resurrected by sync.');
$store[BZV_Afnemer_Reviews::STATE]['records'][$id]['locatedAt'] = time() - BZV_Afnemer_State::GOOGLE_AGE;
BZV_Afnemer_Reviews::expire(); check(!get_post_meta($legacy, 'location'), 'Expired ACF coordinates are also removed from trash.');
// A new customer requires a durable UUID before an insert failure; retry recovers it.
next_directory(); $directory['customers'][] = array_replace($directory['customers'][0], ['customerId' => 'source:' . str_repeat('e', 64), 'aliases' => [], 'identityConfirmed' => false, 'name' => 'Tweede afnemer']);
$failInsert = true; BZV_Afnemer_Reviews::sync();
$secondId = array_key_last($store[BZV_Afnemer_Reviews::STATE]['records']);
check($secondId !== $id && count($posts) === 1, 'An insert failure retains the committed review identity.');
$failInsert = false; BZV_Afnemer_Reviews::sync(); BZV_Afnemer_Reviews::sync();
check(count($posts) === 2 && $store[BZV_Afnemer_Reviews::STATE]['records'][$secondId]['postId'] !== $legacy, 'New customers get exactly one separate draft after retry.');
// An in-flight native edit blocks stale projection; reconciliation then preserves it.
$secondPost = $store[BZV_Afnemer_Reviews::STATE]['records'][$secondId]['postId'];
update_field('field_6a87539e43249', 'Nieuwe plaats', $secondPost);
try { BZV_Afnemer_Posts::sync(BZV_Afnemer_Reviews::state(), static fn() => null); check(false, 'Dirty projection must fail.'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'ondertussen gewijzigd'), 'Dirty projection has an actionable error.'); }
BZV_Afnemer_Reviews::reconcile_post_edits();
check($store[BZV_Afnemer_Reviews::STATE]['records'][$secondId]['city'] === 'Nieuwe plaats', 'Concurrent native changes survive the next reconciliation.');
next_directory(); $directory['customers'][] = array_replace($directory['customers'][1], ['customerId' => 'source:' . str_repeat('f', 64), 'name' => 'Derde afnemer']);
$failOwner = true; BZV_Afnemer_Reviews::sync();
check(count($posts) === 3, 'The insert can succeed while ownership metadata fails.');
$failOwner = false; BZV_Afnemer_Reviews::sync();
check(count($posts) === 3 && get_post_meta(3, BZV_Afnemer_Posts::OWNER), 'Durable UUID slug recovers an orphan from a failed metadata write.');
// Ambiguous old names never result in an arbitrary link or a third duplicate.
$store[BZV_Afnemer_Reviews::STATE] = BZV_Afnemer_State::empty();
$posts = []; $meta = [];
foreach ([1, 2] as $unused) {
    $p = wp_insert_post(['post_type' => 'zuivel_verkooppunt', 'post_status' => 'publish', 'post_title' => 'Dubbel']);
    update_post_meta($p, '_zuivel_import_managed', '1');
}
$directory['customers'] = [array_replace($directory['customers'][0], ['customerId' => 'source:' . hash('sha256', 'Dubbel'), 'aliases' => [], 'identityConfirmed' => false, 'name' => 'Dubbel'])];
BZV_Afnemer_Reviews::sync();
check(count($posts) === 2 && !get_post_meta(1, BZV_Afnemer_Posts::OWNER) && !get_post_meta(2, BZV_Afnemer_Posts::OWNER), 'Ambiguous legacy matches remain unbound.');
check(str_contains($store['bzv_afnemer_status']['message'], 'Meerdere oude CPT-posts'), 'Ambiguity is visible to the administrator.');
check(!$mails, 'CPT verification never sends a real or simulated notification.');
echo "CPT integration passed: stable identity, legacy reuse, ACF, explicit approval, native edits, Pro, targeted Rocket purge, archive/expiry/trash, concurrency and failed-write recovery.\n";
