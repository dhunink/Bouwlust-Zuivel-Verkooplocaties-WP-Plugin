<?php
// Generates reviewed native ACF import files locally; never connects to WordPress.
$backup = json_decode(file_get_contents(__DIR__ . '/../../evidence/standardization-before-0.7.0.json'), true, 512, JSON_THROW_ON_ERROR);
preg_match('/```json\s*(.*?)\s*```/s', $backup['acf']['content'][0]['text'], $match);
$rows = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['results'];
$group = []; $fields = [];
foreach ($rows as $row) {
    if ((int) $row['ID'] !== 30787 && (int) $row['post_parent'] !== 30787) { continue; }
    $data = unserialize($row['post_content'], ['allowed_classes' => false]);
    $data['key'] = $row['post_name'];
    if ($row['post_type'] === 'acf-field-group') { $group = ['key' => $data['key'], 'title' => $row['post_title'], ...$data, 'active' => true]; }
    else { $fields[(int) $row['menu_order']] = ['key' => $data['key'], 'label' => $row['post_title'], 'name' => $row['post_excerpt'], ...$data]; }
}
ksort($fields); $group['fields'] = array_values($fields);
function save_config($name, $data) { file_put_contents(__DIR__ . '/' . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"); }
function field($key, $label, $type, $extra = []) { return ['key' => $key, 'label' => $label, 'name' => substr($key, 6), 'type' => $type, 'required' => 0, ...$extra]; }
function message($key, $label, $text = '', $extra = []) { return field($key, $label, 'message', ['name' => '', 'message' => $text, 'new_lines' => 'br', 'esc_html' => 1, ...$extra]); }
save_config('acf-customer-fields-backup.json', [$group]);
$legacy = $group; $legacy['key'] = 'group_bzv_legacy_fields'; $legacy['title'] = 'Oude zuivelvelden (uitgeschakeld)'; $legacy['active'] = false;
$legacy['fields'] = array_values(array_filter($fields, fn($f) => !in_array($f['name'], ['location', 'show_on_map'], true)));
$group['fields'] = array_values(array_filter($fields, fn($f) => in_array($f['name'], ['location', 'show_on_map'], true)));
$group['fields'][0]['instructions'] = 'Zoek en controleer de juiste vestiging. Dit kaartveld bevat het openbare adres en de coördinaten.';
$status = ['key' => 'group_bzv_source_status', 'title' => 'BOP en website (alleen-lezen)', 'active' => true,
    'position' => 'side', 'style' => 'default', 'label_placement' => 'top', 'instruction_placement' => 'label', 'show_in_rest' => 0,
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'zuivel_verkooppunt']]],
    'fields' => [message('field_bzv_source_status', 'BOP-status'), message('field_bzv_website_status', 'Website'),
        message('field_bzv_source_address', 'Adres ter referentie', '', ['instructions' => 'Gebruik dit als uitgangspunt bij het zoeken. Het Google-kaartveld bepaalt het openbare adres.']),
        message('field_bzv_review_help', 'Controleren en publiceren', 'Kies Nee bij Tonen op de openbare kaart voor een afnemer zonder openbare verkooplocatie. Voor zichtbaarheid: kies Ja, controleer de Google-locatie en klik bewust op Publiceren. Een concept blijft verborgen. BOP werkt de bronstatus automatisch bij.')]];
// Move legacy definitions first; their values remain untouched and can be restored with ACF.
save_config('acf-customer-native-0.7.0.json', [$legacy, $group, $status]);
$settings = ['key' => 'group_bzv_connection', 'title' => 'Postkantoor en meldingen', 'active' => true,
    'position' => 'normal', 'style' => 'default', 'label_placement' => 'top', 'instruction_placement' => 'label', 'show_in_rest' => 0,
    'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'bop-koppeling']]],
    'fields' => [field('field_bzv_settings', '', 'group', ['name' => 'bzv_connection', 'layout' => 'block', 'sub_fields' => [
        field('field_bzv_profile', 'Verbindingsprofiel', 'post_object', ['name' => 'profile', 'post_type' => ['gcapi_conn_profile'], 'return_format' => 'id', 'required' => 1, 'ui' => 1, 'multiple' => 0, 'instructions' => 'De verbinding en sleutel beheer je onder Formulieren → API Connections.']),
        field('field_bzv_automatic', 'Automatisch importeren', 'true_false', ['name' => 'automatic', 'ui' => 1, 'default_value' => 0, 'instructions' => 'Elke 15 minuten de afnemers vanuit BOP bijwerken.']),
        field('field_bzv_email', 'E-mailmeldingen', 'true_false', ['name' => 'email', 'ui' => 1, 'default_value' => 0, 'instructions' => 'Meld de geselecteerde gebruikers dat er nieuwe afnemers klaarstaan voor controle.']),
        field('field_bzv_recipients', 'Ontvangers', 'user', ['name' => 'recipients', 'role' => [], 'multiple' => 1, 'allow_null' => 1, 'return_format' => 'id', 'instructions' => 'Selecteer WordPress-gebruikers. Dit verleent geen extra rechten; voor controle en publicatie blijven hun bestaande WordPress-rechten gelden.']),
        field('field_bzv_page', 'Pagina met de openbare kaart', 'post_object', ['name' => 'page', 'post_type' => ['page'], 'return_format' => 'id', 'required' => 1, 'ui' => 1, 'multiple' => 0, 'instructions' => 'De cache van deze pagina wordt ververst wanneer afnemers wijzigen.']),
    ]]),
    message('field_bzv_connection_help', 'Locatiecontrole', 'Afnemers controleer je in de gewone WordPress-lijst, met het bestaande ACF Google-kaartveld. Google gebruikt de bestaande ACF-sleutel, of de sleutel uit Gravity Forms → Instellingen → Autocomplete.'),
    message('field_bzv_import_actions', 'Importstatus en controle', '', ['instructions' => 'Deze acties gebruiken de opgeslagen instellingen. Sla wijzigingen eerst op.'])]];
$page = ['key' => 'ui_options_page_bzv_connection', 'title' => 'BOP-koppeling', 'active' => true, 'page_title' => 'BOP-koppeling', 'menu_slug' => 'bop-koppeling', 'parent_slug' => 'options-general.php', 'advanced_configuration' => 1, 'menu_title' => 'BOP-koppeling', 'redirect' => false, 'capability' => 'manage_options', 'data_storage' => 'options', 'post_id' => '', 'autoload' => 0, 'update_button' => 'Instellingen opslaan', 'updated_message' => 'BOP-instellingen opgeslagen.'];
save_config('acf-settings-native-0.7.0.json', [$page, $settings]);
echo "Native ACF configuration and rollback export prepared. No customer values changed.\n";
