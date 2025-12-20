<?php

/**
 * Plugin Name: Smart Store Sync
 * Description: Import/update WooCommerce products from a CSV via REST API using your CSV headers, with flexible variation support.
 * Version: 1.3.1
 * Author: Kodyt
 * Author URI:        https://kodyt.com
 */

if (! defined('ABSPATH')) {
    exit;
}

define('MSI_PATH', plugin_dir_path(__FILE__));
define('MSI_URL',  plugin_dir_url(__FILE__));

// core includes
require_once MSI_PATH . 'includes/class-data-provider.php';
require_once MSI_PATH . 'includes/class-settings.php';

// extracted modules
require_once MSI_PATH . 'includes/helpers.php';
require_once MSI_PATH . 'includes/rest-handler.php';
require_once MSI_PATH . 'includes/image-handler.php';

// init settings class (existing file)
add_action('plugins_loaded', function () {
    if (class_exists('MSI_Settings')) {
        new MSI_Settings();
    }
});
