<?php
if (!defined('ABSPATH')) { exit; }

/** Only connection and notification preferences; editing uses the native CPT screen. */
final class BZV_Settings {
    public const OPTION = 'bzv_native_settings';
    public static function init(): void {
        add_action('admin_menu', static fn() => add_options_page('BOP-koppeling', 'BOP-koppeling', 'manage_options', 'bop-connection', [__CLASS__, 'page']));
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_post_bzv_directory_import', [__CLASS__, 'run']);
        add_action('admin_menu', static function () {
            if (($_GET['page'] ?? '') === 'bop-afnemers' && current_user_can('manage_options')) { wp_safe_redirect(admin_url('edit.php?post_type=' . BZV_Native::TYPE)); exit; }
        });
    }
    public static function get(): array {
        $settings = get_option(self::OPTION, null);
        if (is_array($settings)) { return $settings; }
        $old = (array) get_option('bzv_afnemer_settings', []);
        $ids = ($old['recipientMode'] ?? 'administrators') === 'administrators' ? array_map('intval', get_users(['role' => 'administrator', 'fields' => 'ID'])) : array_map('intval', $old['recipientUserIds'] ?? []);
        return ['profile' => absint(get_option('bzv_bop_connection_profile', 0)), 'automatic' => false, 'email' => !empty($old['emailEnabled']), 'recipients' => $ids];
    }
    public static function register(): void {
        if (get_option(self::OPTION, null) === null) { add_option(self::OPTION, self::get(), '', false); }
        register_setting('bzv_connection', self::OPTION, ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize']]);
        add_settings_section('bzv_main', 'Postkantoor en meldingen', '__return_false', 'bop-connection');
        foreach (['profile' => 'Verbindingsprofiel', 'automatic' => 'Automatisch importeren', 'email' => 'E-mailmeldingen', 'recipients' => 'Ontvangers'] as $key => $label) {
            add_settings_field('bzv_' . $key, $label, [__CLASS__, 'field'], 'bop-connection', 'bzv_main', ['key' => $key]);
        }
    }
    public static function sanitize($input): array {
        $old = self::get();
        if (!current_user_can('manage_options') || !is_array($input)) { return $old; }
        $ids = is_array($input['recipients'] ?? []) ? array_values(array_unique(array_map('absint', $input['recipients'] ?? []))) : [];
        $ids = array_values(array_filter($ids));
        $users = $ids ? get_users(['include' => $ids, 'fields' => ['ID', 'user_email']]) : [];
        $next = ['profile' => absint($input['profile'] ?? 0), 'automatic' => !empty($input['automatic']), 'email' => !empty($input['email']), 'recipients' => $ids];
        if (count($ids) > 100 || count($ids) !== count($users) || array_filter($users, static fn($u) => !is_email($u->user_email)) || ($next['email'] && !$ids) || !BZV_Postoffice::profile($next['profile'])[1]) {
            add_settings_error(self::OPTION, 'invalid', 'Kies een geldig Postkantoor-profiel en bij ingeschakelde meldingen minstens één bestaande gebruiker met een geldig e-mailadres.'); return $old;
        }
        if ($next['profile'] !== $old['profile']) { delete_option(BZV_Postoffice::SNAPSHOT); BZV_Native::purge(); }
        return $next;
    }
    public static function field(array $args): void {
        $s = self::get(); $key = $args['key']; $name = self::OPTION . '[' . $key . ']';
        if (in_array($key, ['automatic', 'email'], true)) {
            echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($s[$key], true, false) . '> ' . ($key === 'automatic' ? 'Elke 15 minuten de BOP-afnemers bijwerken' : 'Geselecteerde gebruikers melden dat er nieuwe concepten klaarstaan') . '</label>'; return;
        }
        if ($key === 'profile') {
            echo '<select name="' . esc_attr($name) . '"><option value="0">Kies een verbinding</option>';
            $class = '\\GC_API_Alchemist\\Connection_Profiles\\Connection_Profile';
            foreach (class_exists($class) ? $class::get_all() : [] as $profile) {
                if (BZV_Postoffice::profile($profile->get_id())[1]) { echo '<option value="' . (int) $profile->get_id() . '" ' . selected($s[$key], $profile->get_id(), false) . '>' . esc_html($profile->get_title()) . '</option>'; }
            }
            echo '</select><p class="description">De verbinding en sleutel blijven beheerd onder Formulieren → API Connections.</p>'; return;
        }
        foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC']) as $user) {
            echo '<label><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . (int) $user->ID . '" ' . checked(in_array((int) $user->ID, $s['recipients'], true), true, false) . '> ' . esc_html($user->display_name . ' (' . $user->user_email . ')') . '</label><br>';
        }
        echo '<p class="description">Selectie verleent geen extra rechten. Gebruikers hebben de bestaande WordPress-rechten nodig om afnemers te bewerken en publiceren.</p>';
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>BOP-koppeling</h1><p>Afnemers controleer en publiceer je in de <a href="' . esc_url(admin_url('edit.php?post_type=' . BZV_Native::TYPE)) . '">gewone WordPress-lijst</a>, met de bestaande ACF-adres- en kaartvelden.</p>';
        settings_errors(); $status = get_option('bzv_directory_status', []);
        if ($status) { echo '<div class="notice notice-' . (!empty($status['ok']) ? 'success' : 'warning') . ' inline"><p>' . esc_html($status['message']) . ' (' . esc_html(wp_date('d-m-Y H:i', $status['at'])) . ')</p></div>'; }
        echo '<form method="post" action="options.php">'; settings_fields('bzv_connection'); do_settings_sections('bop-connection'); submit_button(); echo '</form>';
        echo '<p>Google wordt gebruikt via het bestaande ACF-kaartveld. De bestaande ACF-sleutel blijft behouden; anders wordt de sleutel hergebruikt uit <a href="' . esc_url(admin_url('admin.php?page=gf_settings&subview=gp-address-autocomplete')) . '">Gravity Forms → Instellingen → Autocomplete</a>. Er zijn hier geen aparte Google-sleutels.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bzv_directory_import">'; wp_nonce_field('bzv_directory_import');
        submit_button('Verbinding testen', 'secondary', 'test', false); echo ' '; submit_button('Nu importeren', 'secondary', 'import', false); echo '</form></div>';
    }
    public static function run(): void {
        if (!current_user_can('manage_options')) { wp_die('Geen toegang.', '', ['response' => 403]); }
        check_admin_referer('bzv_directory_import');
        if (isset($_POST['test'])) {
            try { $d = BZV_Postoffice::fetch(); $status = ['ok' => true, 'message' => 'Verbinding werkt: ' . count($d['customers']) . ' afnemers, waarvan ' . count(array_filter($d['customers'], static fn($c) => $c['active'])) . ' actief. Er zijn nog geen posts geïmporteerd.']; }
            catch (RuntimeException $e) { $status = ['ok' => false, 'message' => $e->getMessage()]; }
            update_option('bzv_directory_status', ['at' => time(), ...$status], false);
        } else { BZV_Postoffice::sync(true); }
        wp_safe_redirect(admin_url('options-general.php?page=bop-connection')); exit;
    }
}
