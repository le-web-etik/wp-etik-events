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

        // Enregistrement par defaut de l’URL retour de paiement
        register_setting( 'wp_etik_settings', 'wp_etik_payment_return_url', [
            'sanitize_callback' => 'esc_url_raw',
            'default' => home_url( '/confirmation_de_paiement/' ),
        ] );
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

            <?php
            // Ajouter la section pour la page de retour
            $this->render_payment_page_section();
            ?>

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
            $out[ $gw->get_id() ] = $gw->get_keys();
        }
        return $out;
    }

    /**
     * Créer la page de retour de paiement si elle n’existe pas
     */
    public function wp_etik_create_payment_page() {
        $page_title = 'Confirmation de Paiement';
        $page_slug = 'confirmation_de_paiement';

        // Vérifier si la page existe déjà
        $page = get_page_by_path( $page_slug );

        if ( ! $page ) {
            $page_id = wp_insert_post([
                'post_title'   => $page_title,
                'post_name'    => $page_slug,
                'post_content' => '[wp_etik_payment_return]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ]);

            if ( ! is_wp_error( $page_id ) ) {
                update_option( 'wp_etik_payment_page_id', $page_id );
            }
        } else {
            update_option( 'wp_etik_payment_page_id', $page->ID );
        }
    }

    /**
     * Gérer la création de la page de retour de paiement
     */
    public function handle_generate_payment_page( $url ) {
        // Nettoyer l'URL
        $url = trim( $url, '/' );
        if ( empty( $url ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'URL requise.', 'wp-etik-events' ) . '</p></div>';
            return;
        }

        // Créer la page
        $page_title = 'Confirmation de Paiement';
        $page_slug = $url;

        $page = get_page_by_path( $page_slug );

        if ( ! $page ) {
            $page_id = wp_insert_post([
                'post_title'   => $page_title,
                'post_name'    => $page_slug,
                'post_content' => '[wp_etik_payment_return]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ]);

            if ( is_wp_error( $page_id ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Erreur lors de la création de la page.', 'wp-etik-events' ) . '</p></div>';
                return;
            }

            update_option( 'wp_etik_payment_page_id', $page_id );
            update_option( 'wp_etik_payment_return_url', home_url( '/' . $page_slug . '/' ) );

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Page créée avec succès.', 'wp-etik-events' ) . '</p></div>';
        } else {
            update_option( 'wp_etik_payment_page_id', $page->ID );
            update_option( 'wp_etik_payment_return_url', home_url( '/' . $page_slug . '/' ) );

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Page déjà existante. URL mise à jour.', 'wp-etik-events' ) . '</p></div>';
        }
    }


    /**
     * Afficher la section pour la page de retour de paiement
     */
    public function render_payment_page_section() {
        ?>
        <div class="postbox">
            <h3 class="hndle"><span><?php esc_html_e( 'Page de retour de paiement', 'wp-etik-events' ); ?></span></h3>
            <div class="inside">
                <p><?php esc_html_e( 'Créez une page pour afficher les retours de paiement (succès, annulation, erreur).', 'wp-etik-events' ); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wp_etik_payment_return_url"><?php esc_html_e( 'URL de la page', 'wp-etik-events' ); ?></label></th>
                        <td>
                            <input type="text" id="wp_etik_payment_return_url" name="wp_etik_payment_return_url" value="<?php echo esc_attr( get_option( 'wp_etik_payment_return_url', home_url( '/confirmation_de_paiement/' ) ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Exemple : /paiement/ ou /confirmation-de-paiement/. La page sera créée si elle n’existe pas.', 'wp-etik-events' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p>
                    <input type="submit" name="wp_etik_generate_payment_page" class="button button-primary" value="<?php esc_html_e( 'Générer la page', 'wp-etik-events' ); ?>" />
                </p>

                <?php
                // Afficher le message de retour s'il existe
                if ( isset( $_POST['wp_etik_generate_payment_page'] ) && isset( $_POST['wp_etik_payment_return_url'] ) ) {
                    $url = sanitize_text_field( $_POST['wp_etik_payment_return_url'] );
                    $this->handle_generate_payment_page( $url );
                }
                ?>
            </div>
        </div>
        <?php
    }
 

    

}
