<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Payments_Settings {

    const MENU_SLUG = 'wp-etik-parametres';
    const OPTION_GROUP = 'lwe_settings';

    /** @var Abstract_Gateway[] */
    protected array $gateways = [];

    public static function init() : void {
        $self = new self();
        add_action( 'admin_menu', [ $self, 'add_menu' ] );
        add_action( 'admin_init', [ $self, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $self, 'enqueue_assets' ] );
    }

    public function __construct() {

        $base = WP_ETIK_PLUGIN_DIR . 'includes/admin/Payments/';
        $base_clients = WP_ETIK_PLUGIN_DIR . 'includes/admin/Payments/Clients/';

        $files = [
            $base . 'Abstract_Gateway.php',
            $base . 'Stripe_Gateway.php',
            $base . 'Mollie_Gateway.php',
            $base . 'Webhook_Checker.php',
            $base_clients . 'Stripe_Client.php',
            $base_clients . 'Mollie_Client.php',
        ];

        foreach ( $files as $f ) {
            if ( file_exists( $f ) ) {
                require_once $f;
            } 
            /*else {
                error_log( 'ETIK: Payments_Settings missing file: ' . $f );
            }*/
        }

        // instancier les gateways disponibles
        // on vérifie class_exists pour éviter les erreurs fatales si un fichier manque
        if ( class_exists( __NAMESPACE__ . '\\Stripe_Gateway' ) ) {
            $this->gateways[] = new Stripe_Gateway();
        }

        if ( class_exists( __NAMESPACE__ . '\\Mollie_Gateway' ) ) {
            $this->gateways[] = new Mollie_Gateway();
        }

        // plus tard : $this->gateways[] = new Another_Gateway();
    }

    public function add_menu() : void {
        add_submenu_page(
            'edit.php?post_type=etik_event',
            __( 'Paramètres', 'wp-etik-events' ),
            __( 'Paramètres', 'wp-etik-events' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() : void {
        foreach ( $this->gateways as $gw ) {
            $gw->register_settings();
        }
    }

    public function enqueue_assets( $hook ) : void {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( $screen->id !== 'etik_event_page_' . self::MENU_SLUG && $screen->id !== 'settings_page_' . self::MENU_SLUG ) return;
        // enqueue si besoin
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }
        ?>
        <div class="wrap etik-admin">
            <h1><?php esc_html_e( 'Paramètres de paiement', 'wp-etik-events' ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::OPTION_GROUP );

                // render fields for each gateway
                foreach ( $this->gateways as $gw ) {
                    
                    $gw->render_fields();
                }
                submit_button( __( 'Enregistrer', 'wp-etik-events' ) );
                ?>
            </form>

        </div>
        <?php
    }

    /**
     * Helper public pour récupérer toutes les clés
     * @return array gateway_id => keys
     */
    public function get_all_keys() : array {
        $out = [];
        foreach ( $this->gateways as $gw ) {
            $out[ $gw->id ] = $gw->get_keys();
        }
        return $out;
    }
}
