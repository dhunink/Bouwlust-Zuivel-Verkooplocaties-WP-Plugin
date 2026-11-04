<?php
/**
 * Plugin Name: Bouwlust Zuivel Verkooplocaties
 * Description: BOP-afnemers als WordPress-posts met bestaande ACF-locaties, Pro-kaart en Bouwlust-vertalingen.
 * Version: 0.6.6
 * Author: Hoeve Bouwlust
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BZV_VERSION', '0.6.6');
define('BZV_PLUGIN_FILE', __FILE__);
define('BZV_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once BZV_PLUGIN_DIR . 'includes/class-bzv-translations.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-directory.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-native.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-postoffice.php';
require_once BZV_PLUGIN_DIR . 'includes/class-bzv-settings.php';
BZV_Translations::init();
BZV_Native::init();
BZV_Postoffice::init();
BZV_Settings::init();
register_deactivation_hook(__FILE__, [BZV_Postoffice::class, 'deactivate']);
