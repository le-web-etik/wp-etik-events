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

        // Ajouter le hook pour traiter le POST
        add_action( 'admin_post_wp_etik_generate_payment_page', [ self::class, 'handle_generate_payment_page_admin_post' ] );
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

        register_setting( self::OPTION_GROUP, 'wp_etik_payment_return_url', [ 
            'sanitize_callback' => 'esc_url_raw', 
            'default' => home_url( '/confirmation_de_paiement/' ), 
        ] );

        register_setting( self::OPTION_GROUP, 'wp_etik_hcaptcha_sitekey', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting( self::OPTION_GROUP, 'wp_etik_hcaptcha_secret', [
            'sanitize_callback' => [ $this, 'sanitize_and_encrypt_hcaptcha_secret' ],
            'default' => '',
        ]);

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

        // Afficher le message de retour s'il existe
        if ( isset( $_GET['wp_etik_message'] ) ) {
            $message = urldecode( $_GET['wp_etik_message'] );
            $type = $_GET['wp_etik_message_type'] ?? 'info';

            $class = '';
            if ( $type === 'success' ) {
                $class = 'notice-success';
            } elseif ( $type === 'error' ) {
                $class = 'notice-error';
            } else {
                $class = 'notice-info';
            }

            echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        ?>
        <div class="wrap etik-admin">
            <h1><?php esc_html_e( 'Paramètres de paiement', 'wp-etik-events' ); ?></h1>

            <!-- FORMULAIRE 1 : Générer la page de retour (admin-post) -->
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <!-- Ajouter la section pour la page de retour -->
                <?php $this->render_payment_page_section(); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">

                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::OPTION_GROUP );

                // render fields for each gateway
                foreach ( $this->gateways as $gw ) {
                    $gw->render_fields();
                }

                // Section hCaptcha
                $this->render_hcaptcha_section();

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
     * Gérer la création de la page de retour de paiement
     */
    public static function handle_generate_payment_page_admin_post() {
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        if ( empty( $_POST['wp_etik_generate_payment_page_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['wp_etik_generate_payment_page_nonce'] ), 'wp_etik_generate_payment_page_nonce' ) ) {
            wp_die( __( 'Nonce invalide.', 'wp-etik-events' ) );
        }

        // Récupération et normalisation
        $raw = trim( sanitize_text_field( $_POST['wp_etik_payment_return_url'] ?? '' ) );
        if ( empty( $raw ) ) {
            wp_die( __( 'URL requise.', 'wp-etik-events' ) );
        }

        // Si l'utilisateur a collé une URL complète, extraire le path
        if ( strpos( $raw, 'http' ) === 0 ) {
            $parts = wp_parse_url( $raw );
            $path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
        } else {
            $path = trim( $raw, '/' );
        }

        if ( empty( $path ) ) {
            wp_die( __( 'URL invalide.', 'wp-etik-events' ) );
        }

        $page_slug = $path;
 
        // chercher la page 
        $page = get_page_by_path( $page_slug, OBJECT, 'page' );
        $page_title = 'Confirmation de Paiement';

        // Créer la page
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
                wp_die( __( 'Erreur lors de la création de la page.', 'wp-etik-events' ) );
            }

            $page_id_int = (int) $page_id;
            $message = 'Page créée avec succès.';
        } else {

            $page_id_int = (int) $page->ID;
            $message = 'Page déjà existante. URL mise à jour.';
        }

        $permalink = get_permalink( $page_id_int );
        update_option( 'wp_etik_payment_page_id', (int) $page_id_int );
        update_option( 'wp_etik_payment_return_url', $permalink );

        // Rediriger vers la page avec un message
        $redirect_url = add_query_arg( [
            'page' => self::MENU_SLUG,
            'wp_etik_message' => urlencode( $message ),
            'wp_etik_message_type' => 'success',
        ], admin_url( 'edit.php?post_type=etik_event' ) );

        wp_redirect( $redirect_url );
        exit;
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
                    <input type="hidden" name="action" value="wp_etik_generate_payment_page" />
                    <?php wp_nonce_field( 'wp_etik_generate_payment_page_nonce', 'wp_etik_generate_payment_page_nonce' ); ?>
                </p>

            </div>
        </div>
        <?php
    }
 
    // Chiffrer le secret hCaptcha comme les clés Stripe/Mollie
    public function sanitize_and_encrypt_hcaptcha_secret( $new_value ) : string {
        $new_value = trim( (string) $new_value );
        if ( $new_value === '' ) {
            return get_option( 'wp_etik_hcaptcha_secret', '' );
        }

        $sanitized = sanitize_text_field( $new_value );

        if ( class_exists( '\\WP_Etik\\Encryption' ) ) {
            try {
                $enc = \WP_Etik\Encryption::encrypt( $sanitized );
                return $enc['ciphertext'];
            } catch ( \Exception $e ) {
                return get_option( 'wp_etik_hcaptcha_secret', '' );
            }
        }

        // Fallback : ne pas stocker en clair
        return get_option( 'wp_etik_hcaptcha_secret', '' );
    }

    // Helper pour récupérer le secret déchiffré
    public static function get_hcaptcha_secret() : string {
        if ( defined('WP_ETIK_HCAPTCHA_SECRET') && WP_ETIK_HCAPTCHA_SECRET ) {
            return WP_ETIK_HCAPTCHA_SECRET;
        }

        $enc = get_option( 'wp_etik_hcaptcha_secret', '' );
        if ( $enc && class_exists( '\\WP_Etik\\Encryption' ) ) {
            try {
                return \WP_Etik\Encryption::decrypt( $enc );
            } catch ( \Exception $e ) {
                return '';
            }
        }
        return '';
    }

    // Helper pour la sitekey (pas chiffrée, clé publique)
    public static function get_hcaptcha_sitekey() : string {
        if ( defined('WP_ETIK_HCAPTCHA_SITEKEY') && WP_ETIK_HCAPTCHA_SITEKEY ) {
            return WP_ETIK_HCAPTCHA_SITEKEY;
        }
        return (string) get_option( 'wp_etik_hcaptcha_sitekey', '' );
    }
    
    // Méthode dédiée à l'affichage de la section hCaptcha
    private function render_hcaptcha_section() : void {
        $sitekey    = esc_attr( get_option('wp_etik_hcaptcha_sitekey', '') );
        $has_secret = ! empty( get_option('wp_etik_hcaptcha_secret', '') );

        // Test de connexion hCaptcha si les deux clés sont présentes
        $test_result = null;
        if ( $sitekey && $has_secret ) {
            $test_result = get_option('lwe_hcaptcha_test_result', null);
        }
        ?>
        <div class="postbox">
            <h3 class="hndle">
                <span><?php esc_html_e( 'hCaptcha', 'wp-etik-events' ); ?></span>
            </h3>
            <div class="inside">
                <p>
                    <?php esc_html_e(
                        'Protégez vos formulaires d\'inscription avec hCaptcha. Obtenez vos clés sur',
                        'wp-etik-events'
                    ); ?>
                    <a href="https://www.hcaptcha.com" target="_blank" rel="noopener">hcaptcha.com</a>.
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wp_etik_hcaptcha_sitekey">
                                <?php esc_html_e( 'Site Key (clé publique)', 'wp-etik-events' ); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="wp_etik_hcaptcha_sitekey"
                                name="wp_etik_hcaptcha_sitekey"
                                value="<?php echo $sitekey; ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php esc_html_e(
                                    'Clé publique affichée dans le widget hCaptcha (ex. 10000000-ffff-ffff-ffff-000000000001).',
                                    'wp-etik-events'
                                ); ?>
                            </p>
                            <?php if ( $sitekey ) : ?>
                                <div class="lwe-field-status lwe-status-valid">
                                    <span class="dashicons dashicons-yes"></span>
                                    <?php esc_html_e( 'Site key configurée', 'wp-etik-events' ); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wp_etik_hcaptcha_secret">
                                <?php esc_html_e( 'Secret Key (clé privée)', 'wp-etik-events' ); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="wp_etik_hcaptcha_secret"
                                name="wp_etik_hcaptcha_secret"
                                value=""
                                class="regular-text"
                                autocomplete="new-password"
                            />
                            <p class="description">
                                <?php esc_html_e(
                                    'Clé secrète pour vérifier les tokens côté serveur. Laisser vide pour conserver la valeur existante.',
                                    'wp-etik-events'
                                ); ?>
                            </p>
                            <?php if ( $has_secret ) : ?>
                                <div class="lwe-field-status lwe-status-valid">
                                    <span class="dashicons dashicons-yes"></span>
                                    <?php esc_html_e( 'Secret key configurée et chiffrée', 'wp-etik-events' ); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ( is_array($test_result) ) : ?>
                                <div class="lwe-field-status <?php echo $test_result['success'] ? 'lwe-status-valid' : 'lwe-status-invalid'; ?>" style="margin-top:6px;">
                                    <span class="dashicons <?php echo $test_result['success'] ? 'dashicons-yes' : 'dashicons-no-alt'; ?>"></span>
                                    <?php echo esc_html( $test_result['message'] ); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <!-- ✅ Note sur la priorité des constantes wp-config.php -->
                <p class="description" style="margin-top:12px;padding:8px;background:#f9f9f9;border-left:3px solid #2ea3f2;">
                    <?php esc_html_e(
                        'Priorité : si les constantes WP_ETIK_HCAPTCHA_SITEKEY et WP_ETIK_HCAPTCHA_SECRET sont définies dans wp-config.php, elles priment sur ces options.',
                        'wp-etik-events'
                    ); ?>
                </p>
            </div>
        </div>
        <?php
    }

}

