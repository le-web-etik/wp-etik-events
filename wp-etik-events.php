<?php
/**
 * Plugin Name:     WP Etik Events
 * Description:     Gestion d'événements, inscriptions front, et module Divi pour affichage.
 * Version:         1.0.2
 * Author:          Le Web Etik
 * Author URI:      https://lewebetik.fr
 * Text Domain:     wp-etik-events
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// dans le fichier principal du plugin (wp-etik-events.php) :
if ( ! defined( 'WP_ETIK_PLUGIN_DIR' ) ) {
    define( 'WP_ETIK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'WP_ETIK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// plugin bootstrap (wp-etik-events.php)
require_once plugin_dir_path(__FILE__) . 'includes/ajax-handlers.php';
require_once __DIR__ . '/src/Loader.php';

// includes/admin/duplicate-event.php
if ( file_exists( plugin_dir_path(__FILE__) . 'includes/admin/duplicate-event.php' ) ) {
    require_once plugin_dir_path(__FILE__) . 'includes/admin/duplicate-event.php';
}

// includes/admin/class-stripe-settings.php
// require_once plugin_dir_path(__FILE__) . 'includes/admin/class-stripe-settings.php';

use WP_Etik\Loader;

//define("WP_ETIK_HCAPTCHA_SECRET",     "");
add_option('wp_etik_hcaptcha_secret', '');
add_option('wp_etik_hcaptcha_sitekey', '');

add_action('wp_enqueue_scripts', 'wp_etik_force_wp_packages', 5);
add_action('admin_enqueue_scripts', 'wp_etik_force_wp_packages', 5);

function wp_etik_force_wp_packages() {
    // si Divi front builder ou admin builder
    if ( defined('ET_BUILDER_VERSION') || defined('ET_CORE_VERSION') || (isset($_GET['et_fb']) && $_GET['et_fb'] == '1') ) {
        // enqueue si le handle est enregistré
        $handles = ['wp-data','wp-element','wp-components','wp-i18n','wp-hooks','wp-api-fetch'];
        foreach ($handles as $h) {
            if (wp_script_is($h, 'registered')) {
                wp_enqueue_script($h);
            }
        }
    }
}

add_action('wp_enqueue_scripts', 'wp_etik_enqueue_inscription_assets');
function wp_etik_enqueue_inscription_assets() {
    
}


error_log('ETIK VERIF: class ET_Builder_Module exists? ' . (class_exists('ET_Builder_Module') ? 'yes' : 'no'));
if ( defined('ET_BUILDER_VERSION') ) error_log('ETIK VERIF: ET_BUILDER_VERSION = '. ET_BUILDER_VERSION);


$loader = new Loader();
$loader->run();
error_log('ETIK BOOT: plugin loaded at ' . date('c'));

add_action('admin_init', function(){
    if (! class_exists('ET_Builder_Module')) {
        error_log('ETIK CHECK: ET_Builder_Module NOT present');
        return;
    }
    if ( property_exists('ET_Builder_Module','modules') ) {
        $modules = ET_Builder_Module::$modules ?? [];
        error_log('ETIK CHECK: ET_Builder_Module::$modules count=' . count($modules));
        foreach($modules as $m) error_log('ETIK CHECK MODULE: ' . (is_object($m) ? get_class($m) : (string)$m));
    } else {
        error_log('ETIK CHECK: ET_Builder_Module::$modules property not found');
    }
});


// Activation hook to create role
register_activation_hook(__FILE__, [ 'WP_Etik\\Activator', 'activate' ]);

// Deactivation cleanup (optional)
register_deactivation_hook(__FILE__, [ 'WP_Etik\\Activator', 'deactivate' ]);

