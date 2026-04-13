<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

use WP_Error;

// fichier : wp-etik-events/src/Loader.php

class Loader {
    public function run() {

        // Charge webhooks.php AVANT rest_api_init
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Stripe_Settings.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/webhooks.php';

        spl_autoload_register( function($class){
            $prefix = __NAMESPACE__ . '\\';
            if (strpos($class, $prefix) !== 0) return;
            $relative = substr($class, strlen($prefix));
            $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) require $file;
        });

        add_action('init', [ $this, 'init_components' ]);
        add_action('plugins_loaded', [ $this, 'plugins_loaded' ], 15);
        add_action('admin_enqueue_scripts', [ $this, 'admin_assets' ]);
        add_action('wp_enqueue_scripts', [ $this, 'public_assets' ]);
        add_shortcode('wp_etik_payment_return', [$this, 'wp_etik_payment_return_shortcode']);
        
        // Charge les prestations
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Settings.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Meta.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Closures.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Reservation_List.php';
    }




    public function init_components() {

        
        $cpt = new CPT_Event();
        $cpt->register();

        $meta = new Meta_Event();
        $meta->init();

        $frontend = new Frontend_Register();
        $frontend->init();

        $inscriptions = new Frontend_Inscription();
        $inscriptions->init();

        require_once WP_ETIK_PLUGIN_DIR . 'src/Etik_Modal_Manager.php';
        if ( class_exists( '\\WP_Etik\\Etik_Modal_Manager' ) ) {
            \WP_Etik\Etik_Modal_Manager::register_ajax_hooks();
        }

        // Charger les prestations
        if ( is_admin() && current_user_can( 'manage_options' ) ) {
            $prestation_settings = new \WP_Etik\Admin\Prestation_Settings();
            $prestation_settings->init();
        }

        if ( is_admin() && current_user_can( 'manage_options' ) ) {
            $prestation_meta = new \WP_Etik\Admin\Prestation_Meta();
            $prestation_meta->init();

            $prestation_closures = new \WP_Etik\Admin\Prestation_Closures();
            $prestation_closures->init();

            $prestation_reservation_list = new \WP_Etik\Admin\Prestation_Reservation_List();
            $prestation_reservation_list->init();
        }

        // enregistrement et paiement
        if ( is_admin() && current_user_can( 'manage_options' ) ) {

            require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Form_Builder_Admin.php';
            \WP_Etik\Admin\Form_Builder_Admin::init();

            require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/admin-registrations.php';
            $registrations = new \WP_Etik\Admin\Registrations_Admin();
            $registrations->init();

            require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Stripe_Settings.php';
            require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Payments/Payments_Settings.php';

            // Initialise la page de réglages Paiements (Stripe + Mollie)
            if ( class_exists( __NAMESPACE__ . '\\Admin\\Payments_Settings' ) ) {
                \WP_Etik\Admin\Payments_Settings::init();
                //error_log('ETIK: Payments_Settings::init() called');
            } 
            /*else {
                error_log('ETIK: Payments_Settings class not found');
            }*/
            
        }


    }


    // require_once __DIR__ . '/Divi_Module.php';

    public function plugins_loaded() {
        // Log rapide
        Utils::log('plugins_loaded handler entered, ET_Builder_Module exists? ' . (class_exists('ET_Builder_Module') ? 'yes' : 'no'));

        $file = __DIR__ . '/Divi_Module.php'; // chemin vers src/Divi_Module.php

        // Si Divi est déjà disponible, inclure et instancier immédiatement
        if ( class_exists('ET_Builder_Module') ) {
            if ( file_exists($file) ) {
                require_once $file;
                if ( class_exists('WP_Etik\\Divi_Module') ) {
                    new \WP_Etik\Divi_Module();
                    Utils::log('Divi_Module instantiated immediately');
                } else {
                    Utils::log('WP_Etik\\Divi_Module class not found after require');
                }
            } else {
                Utils::log('Divi_Module file missing: ' . $file);
            }
            return;
        }

        // Sinon, attendre le hook fourni par Divi (une seule fois)
        add_action('et_builder_ready', function() use ($file){
            Utils::log('et_builder_ready fired, ET_Builder_Module exists? ' . (class_exists('ET_Builder_Module') ? 'yes' : 'no'));
            if ( file_exists($file) ) {
                require_once $file;
                if ( class_exists('WP_Etik\\Divi_Module') && class_exists('ET_Builder_Module') ) {
                    new \WP_Etik\Divi_Module();
                    Utils::log('Divi_Module instantiated on et_builder_ready');
                } else {
                    Utils::log('Divi_Module class missing or ET_Builder_Module missing at et_builder_ready');
                }
            } else {
                Utils::log('Divi_Module file missing at et_builder_ready: ' . $file);
            }
        }, 20);
    }


    public function admin_assets() {

        wp_enqueue_style('etik-admin', WP_ETIK_PLUGIN_URL . 'assets/css/admin.css', [], '1.0');
        wp_enqueue_script('etik-admin', WP_ETIK_PLUGIN_URL . 'assets/js/admin.js', ['jquery','jquery-ui-datepicker'], false, true);
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css','https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        
        wp_enqueue_style('wp-etik-modal', WP_ETIK_PLUGIN_URL . 'assets/css/etik-modal.css', [], '1.1');

        wp_enqueue_style( 'wp-etik-prestation-admin', WP_ETIK_PLUGIN_URL . 'assets/css/prestation.css', [], '1.0' );
        wp_enqueue_script( 'wp-etik-prestation-admin', WP_ETIK_PLUGIN_URL . 'assets/js/prestation.js', ['jquery'], '1.0', true );
        wp_localize_script( 'wp-etik-prestation-admin', 'ajaxurl', admin_url('admin-ajax.php') );
        
        // ne charger que dans le Visual Builder (paramètre GET utilisé par Divi)
        if ( empty($_GET['et_fb']) && empty($_GET['et_fb_in_iframe']) && empty($_GET['et_fb_preview']) ) {
            return;
        }

        $js_fs  = WP_ETIK_PLUGIN_DIR . 'assets/js/divi/module.js'; // chemin filesystem
        $js_url = WP_ETIK_PLUGIN_URL  . 'assets/js/divi/module.js'; // URL navigateur
 
        if ( file_exists( $js_fs ) ) {
            wp_register_script(
                'wp_etik_divi_module',
                $js_url,
                ['wp-element', 'react', 'react-dom'],
                filemtime( $js_fs ), // ✅ filemtime sur chemin FS
                true
            );
            wp_enqueue_script('wp_etik_divi_module');
 
            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                Utils::log('divi module.js enqueued (v=' . filemtime($js_fs) . ')');
            }
        } else {
            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                Utils::log('divi module.js MISSING at ' . $js_fs);
            }
        }

    }
    

    /*
        public function public_assets() {

            $dir_url = WP_ETIK_PLUGIN_URL;
            // Bootstrap CSS/JS (ou charger votre propre version)
            //wp_enqueue_style('bootstrap-etik-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css', [], null);
            //wp_enqueue_script('bootstrap-etik-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js', ['jquery'], null, true);

            // Localize data (ajax url + nonce)
            // $nonce = wp_create_nonce('wp_etik_inscription_nonce');

            // Style Front
            wp_enqueue_style('etik-front', $dir_url . 'assets/css/front.css', [], '1.0');
            wp_enqueue_style('wp-etik-modal', $dir_url . 'assets/css/etik-modal.css', [], '1.1');

            // script front
            wp_enqueue_script('etik-front', $dir_url . 'assets/js/front.js', ['jquery'], '1.0', true);

            wp_enqueue_script(
                'wp-etik-utils',
                $dir_url . 'assets/js/etik-utils.js',
                ['jquery'],
                WP_ETIK_VERSION,
                true
            );

            wp_register_script(
                'wp_etik_inscription_js',
                $dir_url . 'assets/js/etik-inscription.js',
                ['jquery', 'wp-etik-utils'], // ✅ dépendance explicite
                WP_ETIK_VERSION,
                true
            );

            wp_register_script(
                'wp-etik-prestation',
                $dir_url . 'assets/js/prestation.js',
                ['jquery', 'wp-etik-utils'], // ✅ dépendance explicite
                WP_ETIK_VERSION,
                true
            );
            

            wp_register_script('wp_etik_inscription_js', $dir_url . 'assets/js/etik-inscription.js', ['jquery'], '1.0', true);

            // CSS du modal (léger)
            wp_enqueue_style( 'wp-etik-modal' );

            // Script jQuery (votre fichier)
            wp_localize_script('wp_etik_inscription_js', 'WP_ETIK_AJAX', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wp_etik_inscription_nonce'),
                'hcaptcha_sitekey' => get_option('wp_etik_hcaptcha_sitekey', '') // ou constante
            ]);
            wp_enqueue_script('wp_etik_inscription_js');

            if ( get_option('wp_etik_hcaptcha_sitekey') ) {
                wp_enqueue_script('hcaptcha', 'https://hcaptcha.com/1/api.js', [], null, true);
            }
        }
    */

    public function public_assets() {

        $dir_url  = WP_ETIK_PLUGIN_URL;
        $dir_path = WP_ETIK_PLUGIN_DIR;

        // ── Styles front ───────────────────────────────────────────────────────
        wp_enqueue_style('etik-front',    $dir_url . 'assets/css/front.css',       [], WP_ETIK_VERSION);
        wp_enqueue_style('wp-etik-modal', $dir_url . 'assets/css/etik-modal.css',  [], WP_ETIK_VERSION);

        // ── Script utilitaire (doit être chargé avant les scripts qui en dépendent) ──
        wp_enqueue_script(
            'wp-etik-utils',
            $dir_url . 'assets/js/etik-utils.js',
            ['jquery'],
            WP_ETIK_VERSION,
            true
        );

        // ── Script front (voir plus / voir moins) ──────────────────────────────
        wp_enqueue_script(
            'etik-front',
            $dir_url . 'assets/js/front.js',
            ['jquery'],
            WP_ETIK_VERSION,
            true
        );

        // ── Script inscription (modal) ─────────────────────────────────────────
        // Une seule registration avec la bonne dépendance wp-etik-utils
        wp_register_script(
            'wp_etik_inscription_js',
            $dir_url . 'assets/js/etik-inscription.js',
            ['jquery', 'wp-etik-utils'],
            WP_ETIK_VERSION,
            true
        );

        // ── Script prestation ──────────────────────────────────────────────────
        wp_register_script(
            'wp-etik-prestation',
            $dir_url . 'assets/js/prestation.js',
            ['jquery', 'wp-etik-utils'],
            WP_ETIK_VERSION,
            true
        );

        // ── Localisation + enqueue inscription ────────────────────────────────
        wp_localize_script('wp_etik_inscription_js', 'WP_ETIK_AJAX', [
            'ajax_url'         => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('wp_etik_inscription_nonce'),
            'hcaptcha_sitekey' => get_option('wp_etik_hcaptcha_sitekey', ''),
        ]);
        wp_enqueue_script('wp_etik_inscription_js');

        // ── hCaptcha (chargé uniquement si sitekey configurée) ─────────────────
        if ( get_option('wp_etik_hcaptcha_sitekey') ) {
            wp_enqueue_script('hcaptcha', 'https://hcaptcha.com/1/api.js', [], null, true);
        }
    }

    /**
     * Shortcode pour afficher le retour de paiement
     */
    function wp_etik_payment_return_shortcode() {
        if ( ! isset( $_GET['status'] ) ) {
            return '<div class="notice notice-warning"><p>⚠️ Paramètre manquant. Veuillez contacter le support.</p></div>';
        }

        $status = sanitize_key( $_GET['status'] );
        //$msg = isset( $_GET['msg'] ) ? urldecode( $_GET['msg'] ) : '';
        $msg = '';

        // Définir les messages par défaut selon le statut
        $default_messages = [
            'success' => '✅ Paiement réussi. Votre inscription est confirmée.',
            'cancel'  => '❌ Paiement annulé. Votre réservation reste en attente.',
            'error'   => '⚠️ Une erreur est survenue. Veuillez contacter le support.',
        ];

        $message = $msg ?: $default_messages[$status] ?? $default_messages['error'];

        // Couleur et icône selon le statut
        $class = '';
        $icon = '';

        if ( $status === 'success' ) {
            $class = 'notice-success';
            $icon = 'dashicons-yes';
        } elseif ( $status === 'cancel' ) {
            $class = 'notice-error';
            $icon = 'dashicons-no-alt';
        } else {
            $class = 'notice-warning';
            $icon = 'dashicons-warning';
        }

        // HTML du message
        $output = '<div class="notice ' . esc_attr( $class ) . ' is-dismissible" style="padding:20px;max-width:600px;margin:20px auto;text-align:center;">';
        //$output .= '<span class="dashicons ' . esc_attr( $icon ) . '" style="font-size:30px;margin-bottom:10px;display:block;"></span>';
        $output .= '<p style="font-size:16px;margin:0;">' . esc_html( $message ) . '</p>';
        $output .= '<a href="' . esc_url( home_url() ) . '" class="button button-primary" style="margin-top:20px;">Retour à l\'accueil</a>';
        $output .= '</div>';

        return $output;
    }
}
