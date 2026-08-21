<?php

if (!defined('ABSPATH')) {
    exit;
}

final class BZV_Admin {
    private const TRANSIENT_PREFIX = 'bzv_import_preview_';

    public static function init(): void {
        add_action('admin_notices', [__CLASS__, 'requirements_notice']);
        add_action('admin_head-edit.php', [__CLASS__, 'inject_import_button']);
        add_action('all_admin_notices', [__CLASS__, 'render_import_panel']);
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
        if (!self::is_cpt_list_screen() || !current_user_can('edit_posts')) {
            return;
        }

        $action = isset($_POST['zuivel_action']) ? sanitize_key(wp_unslash($_POST['zuivel_action'])) : '';
        $show = !empty($_GET['zuivel_import']) || $action !== '';
        if (!$show) {
            return;
        }

        echo '<div id="zuivel-import-panel" style="margin:12px 20px 18px 0;max-width:1200px;">';
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:18px 20px;">';
        echo '<h2 style="margin-top:0;">Verkooppunten importeren</h2>';
        echo '<p>Verwachte kolommen: <strong>Klant</strong>, <strong>Straat + huisnr.</strong>, <strong>Postcode</strong> en <strong>Plaats</strong>. CSV en XLSX worden ondersteund.</p>';

        if ($action === 'preview') {
            self::handle_preview();
        } elseif ($action === 'import') {
            self::handle_import();
        } else {
            self::render_upload_form();
        }

        echo '</div></div>';
    }

    private static function handle_preview(): void {
        check_admin_referer('bzv_import_preview', 'bzv_nonce');

        if (empty($_FILES['zuivel_file']) || !is_array($_FILES['zuivel_file'])) {
            self::error('Geen bestand ontvangen.');
            self::render_upload_form();
            return;
        }

        $file = $_FILES['zuivel_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            self::error('Het bestand kon niet worden geüpload.');
            self::render_upload_form();
            return;
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            self::error('Alleen CSV- en XLSX-bestanden worden ondersteund.');
            self::render_upload_form();
            return;
        }

        try {
            $raw_rows = BZV_File_Reader::read((string) $file['tmp_name'], $extension);
            $rows = BZV_Importer::normalize_rows($raw_rows);
        } catch (Throwable $error) {
            self::error('Bestand kon niet worden gelezen: ' . $error->getMessage());
            self::render_upload_form();
            return;
        }

        if (!$rows) {
            self::error('Geen geldige verkooppunten gevonden. Controleer het bestand en de kolomnamen.');
            self::render_upload_form();
            return;
        }

        $analysis = BZV_Importer::analyze_rows($rows);
        $token = wp_generate_password(24, false, false);
        set_transient(self::transient_key($token), ['rows' => $rows], HOUR_IN_SECONDS);

        self::render_preview(
            $analysis,
            $token,
            sanitize_file_name((string) $file['name'])
        );
    }

    private static function handle_import(): void {
        check_admin_referer('bzv_import_run', 'bzv_nonce');

        $token = isset($_POST['bzv_token']) ? sanitize_text_field(wp_unslash($_POST['bzv_token'])) : '';
        $key = self::transient_key($token);
        $payload = get_transient($key);

        if (!$payload || empty($payload['rows'])) {
            self::error('De importpreview is verlopen. Upload het bestand opnieuw.');
            self::render_upload_form();
            return;
        }

        $deactivate_missing = !empty($_POST['deactivate_missing']);
        $result = BZV_Importer::run($payload['rows'], $deactivate_missing);
        delete_transient($key);

        echo '<div class="notice notice-success inline"><p><strong>Import gereed.</strong> '
            . (int) $result['created'] . ' nieuw, '
            . (int) $result['updated'] . ' bijgewerkt, '
            . (int) $result['unchanged'] . ' ongewijzigd, '
            . (int) $result['geocoded'] . ' gegeocodeerd'
            . ($result['geocode_failed'] ? ', ' . (int) $result['geocode_failed'] . ' geocoding mislukt' : '')
            . ($result['deactivated'] ? ', ' . (int) $result['deactivated'] . ' op concept gezet' : '')
            . '.</p></div>';

        if ($result['errors']) {
            echo '<div class="notice notice-warning inline"><p><strong>Aandachtspunten:</strong></p><ul style="list-style:disc;padding-left:24px;">';
            foreach ($result['errors'] as $message) {
                echo '<li>' . esc_html($message) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<p><a class="button button-primary" href="' . esc_url(self::screen_url()) . '">Import sluiten</a> '
            . '<a class="button" href="' . esc_url(self::screen_url(['zuivel_import' => '1'])) . '">Nog een bestand importeren</a></p>';
    }

    private static function render_upload_form(): void {
        ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(self::screen_url(['zuivel_import' => '1'])); ?>" style="max-width:900px;">
            <?php wp_nonce_field('bzv_import_preview', 'bzv_nonce'); ?>
            <input type="hidden" name="zuivel_action" value="preview">
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

        echo '<form method="post" action="' . esc_url(self::screen_url(['zuivel_import' => '1'])) . '">';
        wp_nonce_field('bzv_import_run', 'bzv_nonce');
        echo '<input type="hidden" name="zuivel_action" value="import">';
        echo '<input type="hidden" name="bzv_token" value="' . esc_attr($token) . '">';

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

    private static function is_cpt_list_screen(): bool {
        if (!is_admin()) {
            return false;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && $screen->base === 'edit' && $screen->post_type === BZV_Importer::CPT;
    }

    private static function screen_url(array $args = []): string {
        $query = ['post_type' => BZV_Importer::CPT];
        if (!empty($_GET['layout'])) {
            $query['layout'] = sanitize_text_field(wp_unslash($_GET['layout']));
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
