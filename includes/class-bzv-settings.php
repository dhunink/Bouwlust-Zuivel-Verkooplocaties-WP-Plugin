<?php
if (!defined('ABSPATH')) { exit; }

/** ACF owns the options page and fields; this adapter validates the BOP connection. */
final class BZV_Settings {
    public const FIELD = 'field_bzv_settings';
    public static function init(): void {
        add_filter('acf/validate_value/key=' . self::FIELD, [__CLASS__, 'validate'], 20, 2);
        add_filter('acf/update_value/key=field_bzv_profile', [__CLASS__, 'profile_changed']);
        add_filter('acf/update_value/key=field_bzv_page', static function ($value) { if ((int) $value !== self::get()['page']) { BZV_Native::purge(); } return $value; });
        add_action('acf/options_page/save', static function ($id, $slug) { if ($slug === 'bop-koppeling') { BZV_Native::purge(); } }, 20, 2);
        add_action('acf/render_field/key=field_bzv_import_actions', [__CLASS__, 'actions']);
        add_action('admin_footer', [__CLASS__, 'action_form']);
        add_action('admin_post_bzv_directory_import', [__CLASS__, 'run']);
        add_action('admin_menu', static function () {
            $redirect = ['bop-afnemers' => 'edit.php?post_type=' . BZV_Native::TYPE, 'bop-connection' => 'options-general.php?page=bop-koppeling'][$_GET['page'] ?? ''] ?? '';
            if ($redirect && current_user_can('manage_options')) { wp_safe_redirect(admin_url($redirect)); exit; }
        });
    }
    public static function get(): array {
        $s = function_exists('get_field') ? (array) get_field(self::FIELD, 'option') : [];
        return ['profile' => absint($s['profile'] ?? 0), 'automatic' => !empty($s['automatic']), 'email' => !empty($s['email']),
            'recipients' => array_values(array_unique(array_filter(array_map('absint', (array) ($s['recipients'] ?? []))))), 'page' => absint($s['page'] ?? 0)];
    }
    public static function validate($valid, $value) {
        if ($valid !== true) { return $valid; }
        if (!current_user_can('manage_options') || !is_array($value)) { return 'Geen toegang tot de BOP-instellingen.'; }
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) ($value['field_bzv_recipients'] ?? [])))));
        $users = $ids ? get_users(['include' => $ids, 'fields' => ['ID', 'user_email']]) : [];
        if (count($ids) > 100 || count($ids) !== count($users) || array_filter($users, static fn($u) => !is_email($u->user_email)) ||
            (!empty($value['field_bzv_email']) && !$ids) || !BZV_Postoffice::profile(absint($value['field_bzv_profile'] ?? 0))[1]) {
            return 'Kies een geldig Postkantoor-profiel en bij ingeschakelde meldingen minstens één bestaande gebruiker met een geldig e-mailadres.';
        }
        if (get_post_type(absint($value['field_bzv_page'] ?? 0)) !== 'page') { return 'Kies de bestaande pagina met de openbare kaart.'; }
        return true;
    }
    public static function profile_changed($value) {
        if ((int) $value !== self::get()['profile']) { delete_option(BZV_Postoffice::SNAPSHOT); BZV_Native::purge(); }
        return $value;
    }
    public static function actions(): void {
        if (!current_user_can('manage_options')) { return; }
        $status = get_option('bzv_directory_status', []);
        if ($status) { echo '<p>' . esc_html($status['message']) . ' (' . esc_html(wp_date('d-m-Y H:i', $status['at'])) . ')</p>'; }
        foreach (['test' => 'Verbinding testen', 'import' => 'Nu importeren'] as $action => $label) {
            echo '<button class="button" type="submit" form="bzv-directory-actions" name="bzv_operation" value="' . esc_attr($action) . '">' . esc_html($label) . '</button> ';
        }
    }
    public static function action_form(): void {
        if (($_GET['page'] ?? '') !== 'bop-koppeling' || !current_user_can('manage_options')) { return; }
        // Separate form ownership keeps import/test outside ACF's settings-save validation.
        echo '<form id="bzv-directory-actions" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bzv_directory_import">';
        wp_nonce_field('bzv_directory_import', '_bzv_nonce'); echo '</form>';
    }
    public static function run(): void {
        if (!current_user_can('manage_options') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Geen toegang.', '', ['response' => 403]); }
        check_admin_referer('bzv_directory_import', '_bzv_nonce');
        if (($_POST['bzv_operation'] ?? '') === 'test') {
            try { $d = BZV_Postoffice::fetch(); $status = ['ok' => true, 'message' => 'Verbinding werkt: ' . count($d['customers']) . ' afnemers, waarvan ' . count(array_filter($d['customers'], static fn($c) => $c['active'])) . ' actief. Er zijn nog geen posts geïmporteerd.']; }
            catch (RuntimeException $e) { $status = ['ok' => false, 'message' => $e->getMessage()]; }
            update_option('bzv_directory_status', ['at' => time(), ...$status], false);
        } elseif (($_POST['bzv_operation'] ?? '') === 'import') { BZV_Postoffice::sync(true); }
        wp_safe_redirect(admin_url('options-general.php?page=bop-koppeling')); exit;
    }
}
