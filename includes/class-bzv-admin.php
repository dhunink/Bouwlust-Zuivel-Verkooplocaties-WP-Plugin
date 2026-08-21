<?php

if (!defined('ABSPATH')) {
    exit;
}

final class BZV_Admin {
    private const TRANSIENT_PREFIX = 'bzv_import_';

    public static function init(): void {
        add_action('admin_notices', [__CLASS__, 'requirements_notice']);
        add_action('admin_head-edit.php', [__CLASS__, 'inject_import_button']);
        add_action('all_admin_notices', [__CLASS__, 'render_import_panel']);
        add_action('admin_post_bzv_preview', [__CLASS__, 'handle_preview_request']);
        add_action('admin_post_bzv_import', [__CLASS__, 'handle_import_request']);
        add_action('admin_post_bzv_retry_geocoding', [__CLASS__, 'handle_retry_geocoding_request']);
    }

    public static function requirements_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!post_type_exists(BZV_Importer::CPT)) {
            echo '<div class="notice notice-error"><p><strong>Bouwlust Zuivel Verkooplocaties:</strong> berichttype <code>' . esc_html(BZV_Importer::CPT) . '</code> bestaat niet.</p></div>';
        }
        if (!function_exists('update_field')) {
            echo '<div class="notice notice-error"><p><strong>Bouwlust Zuivel Verkooplocaties:</strong> ACF is niet actief.</p></div>';
        }
    }

    public static function inject_import_button(): void {
        if (!self::is_cpt_list_screen() || !current_user_can('edit_posts')) {
            return;
        }
        $url = self::screen_url(['zuivel_import' => '1']);
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var addNew = document.querySelector('.wrap .page-title-action');
            if (!addNew || document.getElementById('zuivel-import-page-action')) return;

            var button = document.createElement('a');
            button.id = 'zuivel-import-page-action';
            button.className = 'page-title-action';
            button.href = <?php echo wp_json_encode($url); ?>;
            button.textContent = 'Importeren';
            addNew.insertAdjacentElement('afterend', button);
        });
        </script>
        <?php
    }

    public static function render_import_panel(): void {
        if (!self::is_cpt_list_screen() || !current_user_can('edit_posts') || empty($_GET['zuivel_import'])) {
            return;
        }

        echo '<div id="zuivel-import-panel" style="margin:12px 20px 18px 0;max-width:1200px;">';
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:18px 20px;">';
        echo '<h2 style="margin-top:0;">Verkooppunten importeren</h2>';
        echo '<p>Verwachte kolommen: <strong>Klant</strong>, <strong>Straat + huisnr.</strong>, <strong>Postcode</strong> en <strong>Plaats</strong>. CSV en XLSX worden ondersteund.</p>';
        self::render_status_summary();

        $preview_token = isset($_GET['bzv_preview']) ? sanitize_text_field(wp_unslash($_GET['bzv_preview'])) : '';
        $result_token = isset($_GET['bzv_result']) ? sanitize_text_field(wp_unslash($_GET['bzv_result'])) : '';
        $retry_token = isset($_GET['bzv_retry']) ? sanitize_text_field(wp_unslash($_GET['bzv_retry'])) : '';
        $error_token = isset($_GET['bzv_error']) ? sanitize_text_field(wp_unslash($_GET['bzv_error'])) : '';

        if ($error_token) {
            $payload = get_transient(self::transient_key('error_' . $error_token));
            delete_transient(self::transient_key('error_' . $error_token));
            if (is_array($payload) && !empty($payload['message'])) {
                self::error((string) $payload['message']);
            }
            self::render_upload_form();
        } elseif ($retry_token) {
            $payload = get_transient(self::transient_key('retry_' . $retry_token));
            delete_transient(self::transient_key('retry_' . $retry_token));
            if (is_array($payload) && !empty($payload['result'])) {
                self::render_retry_result($payload['result']);
            } else {
                self::error('Het resultaat van de geocoding-herstelactie is niet meer beschikbaar.');
            }
            self::render_upload_form();
        } elseif ($result_token) {
            $payload = get_transient(self::transient_key('result_' . $result_token));
            delete_transient(self::transient_key('result_' . $result_token));
            if (is_array($payload) && !empty($payload['result'])) {
                self::render_result($payload['result']);
            } else {
                self::error('Het importresultaat is niet meer beschikbaar.');
                self::render_upload_form();
            }
        } elseif ($preview_token) {
            $payload = get_transient(self::transient_key('preview_' . $preview_token));
            if (is_array($payload) && !empty($payload['analysis']) && !empty($payload['rows'])) {
                self::render_preview($payload['analysis'], $preview_token, (string) ($payload['filename'] ?? 'bestand'));
            } else {
                self::error('De importpreview is verlopen. Upload het bestand opnieuw.');
                self::render_upload_form();
            }
        } else {
            self::render_upload_form();
        }

        echo '</div></div>';
    }

    public static function handle_preview_request(): void {
        if (!current_user_can('edit_posts')) {
            wp_die('Onvoldoende rechten.');
        }
        check_admin_referer('bzv_import_preview', 'bzv_nonce');

        $layout = isset($_POST['bzv_layout']) ? sanitize_text_field(wp_unslash($_POST['bzv_layout'])) : '';

        if (empty($_FILES['zuivel_file']) || !is_array($_FILES['zuivel_file'])) {
            self::redirect_error('Geen bestand ontvangen.', $layout);
        }

        $file = $_FILES['zuivel_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            self::redirect_error('Het bestand kon niet worden geüpload (uploadcode ' . $code . ').', $layout);
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            self::redirect_error('Alleen CSV- en XLSX-bestanden worden ondersteund.', $layout);
        }

        try {
            $raw_rows = BZV_File_Reader::read((string) $file['tmp_name'], $extension);
            $rows = BZV_Importer::normalize_rows($raw_rows);
            if (!$rows) {
                throw new RuntimeException('Geen geldige verkooppunten gevonden. Controleer het bestand en de kolomnamen.');
            }
            $analysis = BZV_Importer::analyze_rows($rows);
        } catch (Throwable $error) {
            self::redirect_error('Bestand kon niet worden gelezen: ' . $error->getMessage(), $layout);
        }

        $token = wp_generate_password(24, false, false);
        set_transient(
            self::transient_key('preview_' . $token),
            [
                'rows' => $rows,
                'analysis' => $analysis,
                'filename' => sanitize_file_name((string) $file['name']),
            ],
            HOUR_IN_SECONDS
        );

        wp_safe_redirect(self::screen_url_from_layout($layout, [
            'zuivel_import' => '1',
            'bzv_preview' => $token,
        ]));
        exit;
    }

    public static function handle_import_request(): void {
        if (!current_user_can('edit_posts')) {
            wp_die('Onvoldoende rechten.');
        }
        check_admin_referer('bzv_import_run', 'bzv_nonce');

        $layout = isset($_POST['bzv_layout']) ? sanitize_text_field(wp_unslash($_POST['bzv_layout'])) : '';
        $token = isset($_POST['bzv_token']) ? sanitize_text_field(wp_unslash($_POST['bzv_token'])) : '';
        $key = self::transient_key('preview_' . $token);
        $payload = get_transient($key);

        if (!is_array($payload) || empty($payload['rows'])) {
            self::redirect_error('De importpreview is verlopen. Upload het bestand opnieuw.', $layout);
        }

        $deactivate_missing = !empty($_POST['deactivate_missing']);
        $result = BZV_Importer::run($payload['rows'], $deactivate_missing);
        delete_transient($key);

        update_option('bzv_last_import', [
            'timestamp' => time(),
            'filename' => (string) ($payload['filename'] ?? ''),
            'record_count' => count($payload['rows']),
            'result' => $result,
        ], false);

        $result_token = wp_generate_password(20, false, false);
        set_transient(
            self::transient_key('result_' . $result_token),
            ['result' => $result],
            10 * MINUTE_IN_SECONDS
        );

        wp_safe_redirect(self::screen_url_from_layout($layout, [
            'zuivel_import' => '1',
            'bzv_result' => $result_token,
        ]));
        exit;
    }

    public static function handle_retry_geocoding_request(): void {
        if (!current_user_can('edit_posts')) {
            wp_die('Onvoldoende rechten.');
        }
        check_admin_referer('bzv_retry_geocoding', 'bzv_nonce');

        $layout = isset($_POST['bzv_layout']) ? sanitize_text_field(wp_unslash($_POST['bzv_layout'])) : '';
        $result = BZV_Maintenance::retry_missing_locations();

        $token = wp_generate_password(20, false, false);
        set_transient(
            self::transient_key('retry_' . $token),
            ['result' => $result],
            10 * MINUTE_IN_SECONDS
        );

        wp_safe_redirect(self::screen_url_from_layout($layout, [
            'zuivel_import' => '1',
            'bzv_retry' => $token,
        ]));
        exit;
    }

    private static function render_upload_form(): void {
        ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;">
            <?php wp_nonce_field('bzv_import_preview', 'bzv_nonce'); ?>
            <input type="hidden" name="action" value="bzv_preview">
            <input type="hidden" name="bzv_layout" value="<?php echo esc_attr(self::current_layout()); ?>">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="zuivel_file">Bestand</label></th>
                    <td>
                        <input type="file" id="zuivel_file" name="zuivel_file" accept=".csv,.xlsx" required>
                        <p class="description">CSV of XLSX met dezelfde kolomindeling als de huidige verkooppuntenlijst.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Bestand controleren'); ?>
            <a class="button" href="<?php echo esc_url(self::screen_url()); ?>">Annuleren</a>
        </form>
        <?php
    }

    private static function render_preview(array $analysis, string $token, string $filename): void {
        $counts = array_count_values(array_column($analysis, 'action'));
        $new = (int) ($counts['new'] ?? 0);
        $update = (int) ($counts['update'] ?? 0);
        $same = (int) ($counts['same'] ?? 0);
        $ambiguous = (int) ($counts['ambiguous'] ?? 0);
        $missing = BZV_Importer::count_missing_managed($analysis);

        echo '<div class="notice notice-info inline"><p><strong>' . esc_html($filename) . '</strong>: '
            . count($analysis) . ' regels — '
            . $new . ' nieuw, ' . $update . ' gewijzigd, ' . $same . ' ongewijzigd'
            . ($ambiguous ? ', <strong>' . $ambiguous . ' controleren</strong>' : '')
            . ($missing ? ', ' . $missing . ' eerder geïmporteerd maar nu ontbrekend' : '')
            . '.</p></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bzv_import_run', 'bzv_nonce');
        echo '<input type="hidden" name="action" value="bzv_import">';
        echo '<input type="hidden" name="bzv_token" value="' . esc_attr($token) . '">';
        echo '<input type="hidden" name="bzv_layout" value="' . esc_attr(self::current_layout()) . '">';

        echo '<div style="overflow:auto;max-height:560px;border:1px solid #dcdcde;background:#fff;">';
        echo '<table class="widefat striped"><thead><tr><th>Status</th><th>Klant</th><th>Adres</th><th>Postcode</th><th>Plaats</th><th>Match</th></tr></thead><tbody>';

        foreach ($analysis as $item) {
            $labels = [
                'new' => 'Nieuw',
                'update' => 'Bijwerken',
                'same' => 'Ongewijzigd',
                'ambiguous' => 'Controleren',
            ];
            $row = $item['row'];
            echo '<tr>';
            echo '<td><strong>' . esc_html($labels[$item['action']] ?? $item['action']) . '</strong></td>';
            echo '<td>' . esc_html($row['customer']) . '</td>';
            echo '<td>' . esc_html($row['address']) . '</td>';
            echo '<td>' . esc_html($row['postal_code']) . '</td>';
            echo '<td>' . esc_html($row['city']) . '</td>';
            echo '<td>' . esc_html($item['match_reason']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ($missing) {
            echo '<p><label><input type="checkbox" name="deactivate_missing" value="1"> Zet eerder geïmporteerde verkooppunten die nu ontbreken op <strong>concept</strong>.</label></p>';
            echo '<p class="description">Niet aangevinkt = ontbrekende verkooppunten blijven onaangeroerd.</p>';
        }

        echo '<p style="margin-top:20px;">';
        submit_button('Import uitvoeren', 'primary', 'submit', false);
        echo ' <a class="button" href="' . esc_url(self::screen_url()) . '">Annuleren</a></p>';
        echo '</form>';
    }

    private static function render_result(array $result): void {
        $has_problems = !empty($result['geocode_failed']) || !empty($result['errors']);
        $notice_class = $has_problems ? 'notice-warning' : 'notice-success';
        $title = $has_problems ? 'Import uitgevoerd met aandachtspunten.' : 'Import gereed.';

        echo '<div class="notice ' . esc_attr($notice_class) . ' inline"><p><strong>' . esc_html($title) . '</strong> '
            . (int) ($result['created'] ?? 0) . ' nieuw, '
            . (int) ($result['updated'] ?? 0) . ' bijgewerkt, '
            . (int) ($result['unchanged'] ?? 0) . ' ongewijzigd, '
            . (int) ($result['geocoded'] ?? 0) . ' gegeocodeerd'
            . (!empty($result['geocode_failed']) ? ', ' . (int) $result['geocode_failed'] . ' geocoding mislukt' : '')
            . (!empty($result['deactivated']) ? ', ' . (int) $result['deactivated'] . ' op concept gezet' : '')
            . '.</p></div>';

        if (!empty($result['errors'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Aandachtspunten:</strong></p><ul style="list-style:disc;padding-left:24px;">';
            foreach ($result['errors'] as $message) {
                echo '<li>' . esc_html($message) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<p><a class="button button-primary" href="' . esc_url(self::screen_url()) . '">Import sluiten</a> '
            . '<a class="button" href="' . esc_url(self::screen_url(['zuivel_import' => '1'])) . '">Nog een bestand importeren</a></p>';
    }

    private static function render_retry_result(array $result): void {
        $failed = (int) ($result['failed'] ?? 0);
        $notice_class = $failed ? 'notice-warning' : 'notice-success';
        $title = $failed ? 'Geocoding opnieuw uitgevoerd met aandachtspunten.' : 'Geocoding opnieuw uitgevoerd.';

        echo '<div class="notice ' . esc_attr($notice_class) . ' inline"><p><strong>' . esc_html($title) . '</strong> '
            . (int) ($result['succeeded'] ?? 0) . ' hersteld'
            . ($failed ? ', ' . $failed . ' mislukt' : '')
            . '.</p></div>';

        if (!empty($result['errors'])) {
            echo '<div class="notice notice-warning inline"><ul style="list-style:disc;padding-left:24px;">';
            foreach ($result['errors'] as $message) {
                echo '<li>' . esc_html($message) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    private static function render_status_summary(): void {
        $last = get_option('bzv_last_import');
        $missing = BZV_Maintenance::count_missing_locations();

        if (is_array($last) && !empty($last['timestamp'])) {
            $timestamp = (int) $last['timestamp'];
            $filename = (string) ($last['filename'] ?? '');
            $count = (int) ($last['record_count'] ?? 0);
            echo '<div style="margin:12px 0 16px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;">';
            echo '<strong>Laatste import:</strong> ' . esc_html(wp_date('d-m-Y H:i', $timestamp));
            if ($filename !== '') {
                echo ' · ' . esc_html($filename);
            }
            if ($count) {
                echo ' · ' . $count . ' records';
            }
            echo ' · ' . ($missing ? '<strong>' . (int) $missing . ' zonder kaartlocatie</strong>' : 'alle kaartlocaties compleet');
            echo '</div>';
        }

        if ($missing > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 18px;">';
            wp_nonce_field('bzv_retry_geocoding', 'bzv_nonce');
            echo '<input type="hidden" name="action" value="bzv_retry_geocoding">';
            echo '<input type="hidden" name="bzv_layout" value="' . esc_attr(self::current_layout()) . '">';
            submit_button('Geocoding opnieuw proberen (' . $missing . ')', 'secondary', 'submit', false);
            echo '<span class="description" style="margin-left:8px;">Probeert alleen verkooppunten zonder geldige kaartlocatie opnieuw.</span>';
            echo '</form>';
        }
    }

    private static function redirect_error(string $message, string $layout = ''): void {
        $token = wp_generate_password(20, false, false);
        set_transient(
            self::transient_key('error_' . $token),
            ['message' => $message],
            10 * MINUTE_IN_SECONDS
        );
        wp_safe_redirect(self::screen_url_from_layout($layout, [
            'zuivel_import' => '1',
            'bzv_error' => $token,
        ]));
        exit;
    }

    private static function is_cpt_list_screen(): bool {
        if (!is_admin()) {
            return false;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && $screen->base === 'edit' && $screen->post_type === BZV_Importer::CPT;
    }

    private static function current_layout(): string {
        return !empty($_GET['layout']) ? sanitize_text_field(wp_unslash($_GET['layout'])) : '';
    }

    private static function screen_url(array $args = []): string {
        return self::screen_url_from_layout(self::current_layout(), $args);
    }

    private static function screen_url_from_layout(string $layout, array $args = []): string {
        $query = ['post_type' => BZV_Importer::CPT];
        if ($layout !== '') {
            $query['layout'] = $layout;
        }
        return add_query_arg(array_merge($query, $args), admin_url('edit.php'));
    }

    private static function transient_key(string $token): string {
        return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
    }

    private static function error(string $message): void {
        echo '<div class="notice notice-error inline"><p>' . esc_html($message) . '</p></div>';
    }
}
