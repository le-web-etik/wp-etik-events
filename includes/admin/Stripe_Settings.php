<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Stripe_Settings
 *
 * Page de configuration Stripe placée en sous‑menu du CPT "etik_event"
 * - stocke publishable en clair
 * - stocke secret et webhook chiffrés via \WP_Etik\Encryption
 */
class Stripe_Settings {

    const MENU_SLUG = 'wp-etik-stripe';
    const OPTION_GROUP = 'lwe_settings';
    const OPT_PUBLISHABLE = 'lwe_stripe_publishable';
    const OPT_SECRET_ENC = 'lwe_stripe_secret_enc';
    const OPT_WEBHOOK_ENC = 'lwe_stripe_webhook_enc';

    public static function init() : void {
        $self = new self();
        add_action( 'admin_menu', [ $self, 'add_menu' ] );
        add_action( 'admin_init', [ $self, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $self, 'enqueue_assets' ] );
    }

    /**
     * Ajoute le sous‑menu sous le CPT etik_event
     */
    public function add_menu() : void {
        add_submenu_page(
            'edit.php?post_type=etik_event',
            __( 'Stripe', 'wp-etik-events' ),
            __( 'Stripe', 'wp-etik-events' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * Enregistre les settings (publishable en clair, secret/webhook chiffrés)
     */
    public function register_settings() : void {
        register_setting( self::OPTION_GROUP, self::OPT_PUBLISHABLE, [
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        register_setting( self::OPTION_GROUP, self::OPT_SECRET_ENC, [
            'sanitize_callback' => [ $this, 'sanitize_and_encrypt_secret' ],
        ] );

        register_setting( self::OPTION_GROUP, self::OPT_WEBHOOK_ENC, [
            'sanitize_callback' => [ $this, 'sanitize_and_encrypt_webhook' ],
        ] );
    }

    /**
     * Enqueue admin assets si nécessaire (CSS/JS minimal)
     */
    public function enqueue_assets( $hook ) : void {
        // n'enqueue que sur notre page
        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( $screen->id !== 'etik_event_page_' . self::MENU_SLUG && $screen->id !== 'settings_page_' . self::MENU_SLUG ) return;

        // exemple : register simple style/script si besoin
        // wp_enqueue_style('lwe-admin-stripe', plugin_dir_url( __DIR__ . '/..' ) . 'assets/css/admin-stripe.css', [], WP_ETIK_EVENTS_VERSION );
        // wp_enqueue_script('lwe-admin-stripe', plugin_dir_url( __DIR__ . '/..' ) . 'assets/js/admin-stripe.js', ['jquery'], WP_ETIK_EVENTS_VERSION, true);
    }

    /**
     * Affiche la page d'options
     */
    public function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        $publishable = esc_attr( get_option( self::OPT_PUBLISHABLE, '' ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Réglages Stripe', 'wp-etik-events' ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::OPTION_GROUP );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_PUBLISHABLE); ?>"><?php esc_html_e('Publishable Key','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="text" id="<?php echo esc_attr(self::OPT_PUBLISHABLE); ?>"
                                   name="<?php echo esc_attr(self::OPT_PUBLISHABLE); ?>"
                                   value="<?php echo $publishable; ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Clé publique Stripe (ex. pk_test_...)','wp-etik-events'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"><?php esc_html_e('Secret Key','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"
                                   name="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"
                                   value="" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Clé secrète Stripe (ex. sk_test_...). Laisser vide pour conserver la valeur existante.','wp-etik-events'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"><?php esc_html_e('Webhook Secret','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"
                                   name="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"
                                   value="" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Signing secret pour vérifier les webhooks Stripe. Valeur fournie par Stripe.','wp-etik-events'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Enregistrer', 'wp-etik-events' ) ); ?>
            </form>

            <h2><?php esc_html_e('Notes','wp-etik-events'); ?></h2>
            <p><?php esc_html_e('Préférence : définir STRIPE_SECRET et STRIPE_WEBHOOK_SECRET dans wp-config.php pour les environnements professionnels. La page ci‑dessous permet une saisie simple pour les utilisateurs du plugin store.','wp-etik-events'); ?></p>
        </div>
        <?php
    }

    /**
     * Sanitize and encrypt secret key before saving to options
     * If empty, keep existing stored value
     */
    public function sanitize_and_encrypt_secret( $new_value ) {
        $new_value = trim( (string) $new_value );
        if ( $new_value === '' ) {
            return get_option( self::OPT_SECRET_ENC, '' );
        }

        $sanitized = sanitize_text_field( $new_value );

        if ( class_exists( '\\WP_Etik\\Encryption' ) ) {
            try {
                $enc = \WP_Etik\Encryption::encrypt( $sanitized );
                return $enc['ciphertext'];
            } catch ( \Exception $e ) {
                // en cas d'erreur, ne pas sauvegarder la valeur en clair
                return get_option( self::OPT_SECRET_ENC, '' );
            }
        }

        // fallback : ne pas stocker en clair
        return get_option( self::OPT_SECRET_ENC, '' );
    }

    /**
     * Sanitize and encrypt webhook secret before saving to options
     */
    public function sanitize_and_encrypt_webhook( $new_value ) {
        $new_value = trim( (string) $new_value );
        if ( $new_value === '' ) {
            return get_option( self::OPT_WEBHOOK_ENC, '' );
        }

        $sanitized = sanitize_text_field( $new_value );

        if ( class_exists( '\\WP_Etik\\Encryption' ) ) {
            try {
                $enc = \WP_Etik\Encryption::encrypt( $sanitized );
                return $enc['ciphertext'];
            } catch ( \Exception $e ) {
                return get_option( self::OPT_WEBHOOK_ENC, '' );
            }
        }

        return get_option( self::OPT_WEBHOOK_ENC, '' );
    }

    /**
     * Récupère les clés Stripe déchiffrées.
     * Priorité : constantes STRIPE_* si définies, sinon options chiffrées.
     *
     * @return array{publishable:string, secret:string, webhook:string}
     */
    public static function get_keys() : array {
        $publishable = defined( 'STRIPE_PUBLISHABLE_KEY' ) ? STRIPE_PUBLISHABLE_KEY : get_option( self::OPT_PUBLISHABLE, '' );

        // secret
        if ( defined( 'STRIPE_SECRET' ) && STRIPE_SECRET ) {
            $secret = STRIPE_SECRET;
        } else {
            $enc = get_option( self::OPT_SECRET_ENC, '' );
            $secret = '';
            if ( $enc && class_exists( '\\WP_Etik\\Encryption' ) ) {
                try {
                    $secret = \WP_Etik\Encryption::decrypt( $enc );
                } catch ( \Exception $e ) {
                    $secret = '';
                }
            }
        }

        // webhook
        if ( defined( 'STRIPE_WEBHOOK_SECRET' ) && STRIPE_WEBHOOK_SECRET ) {
            $webhook = STRIPE_WEBHOOK_SECRET;
        } else {
            $enc = get_option( self::OPT_WEBHOOK_ENC, '' );
            $webhook = '';
            if ( $enc && class_exists( '\\WP_Etik\\Encryption' ) ) {
                try {
                    $webhook = \WP_Etik\Encryption::decrypt( $enc );
                } catch ( \Exception $e ) {
                    $webhook = '';
                }
            }
        }

        return [
            'publishable' => (string) $publishable,
            'secret' => (string) $secret,
            'webhook' => (string) $webhook,
        ];
    }
}
