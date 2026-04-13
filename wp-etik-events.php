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

    define( 'WP_ETIK_DEBUG', defined('WP_DEBUG') && WP_DEBUG );
}

define( 'WP_ETIK_VERSION', '1.04' );

// plugin bootstrap (wp-etik-events.php)
require_once plugin_dir_path(__FILE__) . 'includes/ajax-handlers.php';
require_once __DIR__ . '/src/Loader.php';

// includes/admin/duplicate-event.php
if ( file_exists( plugin_dir_path(__FILE__) . 'includes/admin/duplicate-event.php' ) ) {
    require_once plugin_dir_path(__FILE__) . 'includes/admin/duplicate-event.php';
}

use WP_Etik\Loader;

//define("WP_ETIK_HCAPTCHA_SECRET",     "");
//add_option('wp_etik_hcaptcha_secret', '');
//add_option('wp_etik_hcaptcha_sitekey', '');

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



if ( defined('ET_BUILDER_VERSION') ) error_log('ETIK VERIF: ET_BUILDER_VERSION = '. ET_BUILDER_VERSION);


$loader = new Loader();
$loader->run();

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

//add_action( 'wp_loaded', [ 'WP_Etik\\Etik_Modal_Manager', 'register_ajax_hooks' ] );

// Activation hook to create role
register_activation_hook(__FILE__, [ 'WP_Etik\\Activator', 'activate' ]);

// Deactivation cleanup (optional)
register_deactivation_hook(__FILE__, [ 'WP_Etik\\Activator', 'deactivate' ]);

/**
 * Snippet à ajouter dans wp-etik-events.php
 * (par exemple juste avant register_activation_hook)
 *
 * Déclenchement : visiter /wp-admin/?create_form_constellations=1
 * Une seule exécution possible (doublon détecté automatiquement).
 */
 /*
add_action( 'init', function () {
 
    // Uniquement en admin, connecté administrateur, avec le paramètre GET
    if ( ! is_admin() )                                          return;
    if ( ! current_user_can( 'manage_options' ) )                return;
    if ( ! isset( $_GET['create_form_constellations'] ) )        return;
 
    require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/create-form-constellations.php';
 
    $result = etik_create_form_constellations();
 
    // Construire l'URL de redirection vers la page Formulaires avec un message
    $base = admin_url( 'edit.php?post_type=etik_event' );
    $args = [ 'page' => 'wp-etik-forms', 'etik_msg' => $result['status'] ];
 
    if ( $result['form_id'] > 0 ) {
        // Ouvrir directement l'éditeur du formulaire créé (ou existant)
        $args['action']  = 'edit';
        $args['form_id'] = $result['form_id'];
    }
 
    wp_safe_redirect( add_query_arg( $args, $base ) );
    exit;
 
} );
 */
/**
 * Afficher la notice issue de la redirection ci-dessus.
 * (À ajouter aussi dans wp-etik-events.php, ou dans Form_Builder_Admin::enqueue_assets)
 */
/*
add_action( 'admin_notices', function () {
 
    if ( ! isset( $_GET['etik_msg'] ) ) return;
 
    $notices = [
        'created' => [ 'success', '✅ Formulaire « Inscription Constellations » créé avec succès.' ],
        'exists'  => [ 'warning', '⚠️ Le formulaire existe déjà — ouverture de l\'éditeur.' ],
        'error'   => [ 'error',   '❌ Erreur lors de la création du formulaire. Vérifiez les logs.' ],
    ];
 
    $key = sanitize_key( $_GET['etik_msg'] );
    if ( ! isset( $notices[ $key ] ) ) return;
 
    [ $type, $text ] = $notices[ $key ];
    echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>'
       . esc_html( $text ) . '</p></div>';
 
} );
 */