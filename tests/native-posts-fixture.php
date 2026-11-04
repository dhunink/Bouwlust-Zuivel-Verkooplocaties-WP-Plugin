<?php
$posts = []; $meta = []; $fieldWrites = []; $failField = ''; $failInsert = false; $failOwner = false;
$acfFields = [
    ['name' => 'address', 'key' => 'field_6a87537d43248', 'type' => 'text'],
    ['name' => 'postal_code', 'key' => 'field_6a87547118145', 'type' => 'text'],
    ['name' => 'city', 'key' => 'field_6a87539e43249', 'type' => 'text'],
    ['name' => 'import_id', 'key' => 'field_6a8753c04324c', 'type' => 'text'],
    ['name' => 'location', 'key' => 'field_6a8755b152cb6', 'type' => 'google_map'],
    ['name' => 'show_on_map', 'key' => 'field_6aeb36d3db858', 'type' => 'true_false'],
];
function acf_get_field_groups($args) { return [['key' => 'group_6a8753596bc2c']]; }
function acf_get_fields($group) { return $GLOBALS['acfFields']; }
function post_type_exists($type) { return $type === 'zuivel_verkooppunt'; }
function get_posts($args) { return array_values(array_filter($GLOBALS['posts'], static fn($p) => $p->post_type === $args['post_type'] && in_array($p->post_status, (array) $args['post_status'], true) && (!isset($args['meta_key']) || get_post_meta($p->ID, $args['meta_key']) === $args['meta_value']))); }
function get_post($id) { return isset($GLOBALS['posts'][$id]) ? clone $GLOBALS['posts'][$id] : null; }
function get_post_type($id) { return get_post($id)?->post_type ?: false; }
function get_post_status($id) { return get_post($id)?->post_status ?: false; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) {
    if ($GLOBALS['failOwner'] && $key === '_bop_source_id') { return false; }
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
