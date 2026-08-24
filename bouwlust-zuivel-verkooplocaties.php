<?php
/**
 * Plugin Name: Bouwlust Zuivel Verkooplocaties
 * Description: Beheer en periodieke import van zuivel-verkooplocaties naar het ACF-berichttype zuivel_verkooppunt, inclusief Google-geocoding.
 * Version: 0.2.1
 * Author: Hoeve Bouwlust
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BZV_VERSION', '0.2.1');
define('BZV_PLUGIN_FILE', __FILE__);
define('BZV_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once BZV_PLUGIN_DIR . 'includes/class-bzv-translations.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-file-reader.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-importer.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-acf-bridge.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-maintenance.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-admin.php';

BZV_Translations::init();
BZV_ACF_Bridge::init();
BZV_Admin::init();
