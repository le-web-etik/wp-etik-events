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

        // récupérer clés via la gateway
        $keys = $this->get_keys(); // retourne ['publishable','secret','webhook']
        $expected = rest_url('lwe/v1/stripe-webhook');

        // vérifier si on a déjà un résultat de test (option) sinon exécuter la vérif live
        $check = \WP_Etik\Admin\Payments\Webhook_Checker::check_stripe_webhook( $keys['secret'], $expected );

        //
        $nonce_webhook = wp_create_nonce( 'lwe_test_webhook_nonce' );

        $publishable = esc_attr( get_option( self::OPT_PUBLISHABLE, '' ) );

        //lwe_stripe_test_result
        $test_key = get_option( 'lwe_stripe_test_result', null );
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
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"><?php esc_html_e('Secret Key','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"
                                name="<?php echo esc_attr(self::OPT_SECRET_ENC); ?>"
                                value="" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Clé secrète Stripe. Laisser vide pour conserver la valeur existante.','wp-etik-events'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"><?php esc_html_e('Webhook Secret','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"
                                name="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"
                                value="" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Signing secret pour vérifier les webhooks Stripe.','wp-etik-events'); ?></p>
                            
                            <?php // affichage (config webhook)
                            if ( $check['success'] ) {
                                echo '<div style="padding:8px;border-left:4px solid #46b450;background:#f0fff4;color:#0b6b2f;">';
                                echo '<strong>Webhook Stripe configuré</strong><div>' . esc_html($check['message']) . '</div>';
                                echo '</div>';
                            } else {
                                echo '<div style="padding:8px;border-left:4px solid #d63638;background:#fff6f6;color:#8a1f1f;">';
                                echo '<strong>Webhook Stripe non configuré</strong><div>' . esc_html($check['message']) . '</div>';
                                if ( ! empty($check['details']) ) {
                                    echo '<div style="font-size:12px;color:#666;">' . esc_html( wp_json_encode($check['details']) ) . '</div>';
                                }
                                echo '</div>';
                            } 
                            
                            $last = get_option('lwe_stripe_webhook_check', null);
                            if ( is_array($last) ) {
                                echo '<div class="lwe-last-check">Dernier test: ' . esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), (int) ($last['time'] ?? time()) ) ) . ' — ' . esc_html( $last['message'] ?? '' ) . '</div>';
                            }
                            ?>



                        </td>
                    </tr>
                </table>
                
                <!-- BOUTON POUR TESTER WEBHOOK -->
                <p>
                    <button type="button" class="button button-secondary lwe-test-webhook" data-gateway="stripe" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        <?php esc_html_e( 'Tester le webhook', 'wp-etik-events' ); ?>
                    </button>
                    <span class="lwe-test-spinner" style="display:none;margin-left:8px;"><?php esc_html_e( 'Test en cours…', 'wp-etik-events' ); ?></span>
                </p>
                <div class="lwe-test-result" id="lwe-test-result-stripe"></div>

                <!-- AFFICHE ETAT CLE STRIPE -->
                <?php if ( is_array( $test_key ) ) : ?>
                    <div style="margin-top:10px;">
                        <?php if ( ! empty( $test_key['success'] ) ) : ?>
                            <div style="padding:8px;border-left:4px solid #46b450;background:#f0fff4;color:#0b6b2f;">
                                <strong><?php esc_html_e('Connexion Stripe OK','wp-etik-events'); ?></strong>
                                <div><?php echo esc_html( $test_key['message'] ); ?></div>
                                <div style="font-size:12px;color:#666;"><?php echo esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), (int) $test_key['time'] ) ); ?></div>
                            </div>
                        <?php else : ?>
                            <div style="padding:8px;border-left:4px solid #d63638;background:#fff6f6;color:#8a1f1f;">
                                <strong><?php esc_html_e('Échec de la connexion Stripe','wp-etik-events'); ?></strong>
                                <div><?php echo esc_html( $test_key['message'] ); ?></div>
                                <div style="font-size:12px;color:#666;"><?php echo esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), (int) $test_key['time'] ) ); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('Aucun test effectué pour Stripe.', 'wp-etik-events'); ?></p>
                <?php endif; ?>
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
