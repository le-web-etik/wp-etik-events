<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Stripe_Gateway extends Abstract_Gateway {

    const OPT_PUBLISHABLE = 'lwe_stripe_publishable';
    const OPT_SECRET_ENC  = 'lwe_stripe_secret_enc';
    const OPT_WEBHOOK_ENC = 'lwe_stripe_webhook_enc';

    public function __construct() {
        parent::__construct( 'stripe', __( 'Stripe', 'wp-etik-events' ) );
    }

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

    public function render_fields() : void {

        // Récupérer clés via la gateway
        $keys = $this->get_keys(); // retourne ['publishable','secret','webhook']
        $expected = rest_url('lwe/v1/stripe-webhook');

        // Vérifier si on a déjà un résultat de test (option) sinon exécuter la vérif live
        $check = \WP_Etik\Admin\Payments\Webhook_Checker::check_stripe_webhook( $keys['secret'], $expected );

        // Nonce pour le test
        $nonce_webhook = wp_create_nonce( 'lwe_test_webhook_nonce' );

        // Récupérer le résultat du dernier test de la clé
        $test_key = get_option( 'lwe_stripe_test_result', null );

        // Statut de la clé Stripe (si testé)
        $stripe_status = '';
        $stripe_class = '';
        $stripe_icon = '';
        if ( is_array( $test_key ) ) {
            if ( ! empty( $test_key['success'] ) ) {
                $stripe_status = '✅ Clé Stripe valide';
                $stripe_class = 'lwe-status-valid';
                $stripe_icon = 'dashicons-yes';
            } else {
                $stripe_status = '❌ Clé Stripe invalide';
                $stripe_class = 'lwe-status-invalid';
                $stripe_icon = 'dashicons-no-alt';
            }
        } else {
            $stripe_status = '⚠️ Non testée';
            $stripe_class = 'lwe-status-pending';
            $stripe_icon = 'dashicons-warning';
        }

        // Statut du webhook
        $webhook_status = '';
        $webhook_class = '';
        $webhook_icon = '';
        if ( $check['success'] ) {
            $webhook_status = '✅ Webhook configuré';
            $webhook_class = 'lwe-status-valid';
            $webhook_icon = 'dashicons-yes';
        } else {
            $webhook_status = '❌ Webhook non configuré';
            $webhook_class = 'lwe-status-invalid';
            $webhook_icon = 'dashicons-no-alt';
        }

        // Valeurs actuelles
        $publishable = esc_attr( get_option( self::OPT_PUBLISHABLE, '' ) );
        $has_secret = ! empty( $keys['secret'] );
        $has_webhook = ! empty( $keys['webhook'] );

        ?>
        <div class="postbox">
            <h3 class="hndle"><span><?php echo esc_html( $this->label ); ?></span></h3>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_PUBLISHABLE); ?>"><?php esc_html_e('Publishable Key','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="text" id="<?php echo esc_attr(self::OPT_PUBLISHABLE); ?>"
                                name="<?php echo esc_attr(self::OPT_PUBLISHABLE); ?>"
                                value="<?php echo $publishable; ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Clé publique Stripe (ex. pk_test_...)','wp-etik-events'); ?></p>
                            <?php if ( ! empty( $publishable ) ) : ?>
                                <div class="lwe-field-status lwe-status-valid">
                                    <span class="dashicons dashicons-yes"></span> Clé remplie
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"><?php esc_html_e('Secret Key','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"
                                name="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"
                                value="" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Clé secrète Stripe. Laisser vide pour conserver la valeur existante.','wp-etik-events'); ?></p>
                            <?php if ( $has_secret ) : ?>
                                <div class="lwe-field-status lwe-status-valid">
                                    <span class="dashicons dashicons-yes"></span> Clé secrète configurée
                                </div>
                            <?php endif; ?>
                            <div class="lwe-stripe-status <?php echo esc_attr( $stripe_class ); ?>">
                                <span class="dashicons <?php echo esc_attr( $stripe_icon ); ?>"></span> <?php echo esc_html( $stripe_status ); ?>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"><?php esc_html_e('Webhook Secret','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"
                                name="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"
                                value="" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Signing secret pour vérifier les webhooks Stripe.','wp-etik-events'); ?></p>
                            <?php if ( $has_webhook ) : ?>
                                <div class="lwe-field-status lwe-status-valid">
                                    <span class="dashicons dashicons-yes"></span> Webhook secret configuré
                                </div>
                            <?php endif; ?>
                            <div class="lwe-webhook-status <?php echo esc_attr( $webhook_class ); ?>">
                                <span class="dashicons <?php echo esc_attr( $webhook_icon ); ?>"></span> <?php echo esc_html( $webhook_status ); ?>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- BOUTON POUR TESTER WEBHOOK -->
                <p>
                    <button type="button" class="button button-secondary lwe-test-webhook" data-gateway="stripe" data-nonce="<?php echo esc_attr( $nonce_webhook ); ?>">
                        <?php esc_html_e( 'Tester le webhook', 'wp-etik-events' ); ?>
                    </button>
                    <span class="lwe-test-spinner" style="display:none;margin-left:8px;"><?php esc_html_e( 'Test en cours…', 'wp-etik-events' ); ?></span>
                </p>
                <div class="lwe-test-result" id="lwe-test-result-stripe"></div>

                <!-- DERNIER TEST -->
                <?php
                $last = get_option('lwe_stripe_webhook_check', null);
                if ( is_array($last) ) {
                    echo '<div class="lwe-last-check" style="margin-top:8px;font-size:12px;color:#666;">';
                    echo 'Dernier test: ' . esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), (int) ($last['time'] ?? time()) ) ) . ' — ' . esc_html( $last['message'] ?? '' );
                    echo '</div>';
                }
                ?>
            </div>
        </div>

    <?php
    }

    public function sanitize_and_encrypt_secret( $new_value ) {

        $new_value = trim( (string) $new_value );
        if ( $new_value === '' ) {
            return get_option( self::OPT_SECRET_ENC, '' );
        }

        $sanitized = sanitize_text_field( $new_value );

        if ( class_exists( '\\WP_Etik\\Admin\\Payments\\Clients\\Stripe_Client' ) ) {
            $test = \WP_Etik\Admin\Payments\Clients\Stripe_Client::test_api_key( $sanitized );
            update_option( 'lwe_stripe_test_result', [
                'success' => (bool) $test['success'],
                'message' => (string) $test['message'],
                'code'    => (int) $test['code'],
                'time'    => time(),
            ] );
        } else {
            update_option( 'lwe_stripe_test_result', [
                'success' => false,
                'message' => 'Stripe_Client missing',
                'code' => 0,
                'time' => time(),
            ] );
        }
        
        return $this->encrypt_or_keep_existing( self::OPT_SECRET_ENC, (string) $new_value );
    }

    public function sanitize_and_encrypt_webhook( $new_value ) {
        return $this->encrypt_or_keep_existing( self::OPT_WEBHOOK_ENC, (string) $new_value );
    }

    public function get_keys() : array {
        $publishable = defined( 'STRIPE_PUBLISHABLE_KEY' ) ? STRIPE_PUBLISHABLE_KEY : get_option( self::OPT_PUBLISHABLE, '' );

        if ( defined( 'STRIPE_SECRET' ) && STRIPE_SECRET ) {
            $secret = STRIPE_SECRET;
        } else {
            $secret = $this->decrypt_option( self::OPT_SECRET_ENC );
        }

        if ( defined( 'STRIPE_WEBHOOK_SECRET' ) && STRIPE_WEBHOOK_SECRET ) {
            $webhook = STRIPE_WEBHOOK_SECRET;
        } else {
            $webhook = $this->decrypt_option( self::OPT_WEBHOOK_ENC );
        }

        return [
            'publishable' => (string) $publishable,
            'secret' => (string) $secret,
            'webhook' => (string) $webhook,
        ];
    }
}
