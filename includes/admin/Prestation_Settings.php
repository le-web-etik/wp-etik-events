<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prestation_Settings {

    const MENU_SLUG = 'wp-etik-prestation';
    const OPTION_GROUP = 'lwe_prestation_settings';

    public static function init() : void {
        $self = new self();
        add_action( 'admin_menu', [ $self, 'add_menu' ] );
        add_action( 'admin_init', [ $self, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $self, 'enqueue_assets' ] );
    }

    public function __construct() {
        // Charger les fichiers nécessaires
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Meta.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Closures.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Reservation_List.php';
    }

    public function add_menu() : void {
        add_submenu_page(
            'edit.php?post_type=etik_event',
            __( 'Prestations', 'wp-etik-events' ),
            __( 'Prestations', 'wp-etik-events' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() : void {
        // Enregistrer les settings si nécessaire
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
            <h1><?php esc_html_e( 'Prestations', 'wp-etik-events' ); ?></h1>
            <p><?php esc_html_e( 'Gérez vos prestations récurrentes ici.', 'wp-etik-events' ); ?></p>
            <!-- Ajouter les onglets ou les sections ici -->
        </div>
        <?php
    }
}