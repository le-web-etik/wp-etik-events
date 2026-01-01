<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mollie_Gateway extends Abstract_Gateway {

    const OPT_APIKEY_ENC  = 'lwe_mollie_apikey_enc';
    const OPT_WEBHOOK_ENC = 'lwe_mollie_webhook_enc';

    public function __construct() {
        parent::__construct( 'mollie', __( 'Mollie', 'wp-etik-events' ) );
    }

    public function register_settings() : void {
        register_setting( self::OPTION_GROUP, self::OPT_APIKEY_ENC, [
            'sanitize_callback' => [ $this, 'sanitize_and_encrypt_apikey' ],
        ] );

        register_setting( self::OPTION_GROUP, self::OPT_WEBHOOK_ENC, [
            'sanitize_callback' => [ $this, 'sanitize_and_encrypt_webhook' ],
        ] );
    }

    public function render_fields() : void {
        $test = get_option( 'lwe_mollie_test_result', null );
        ?>
        <div class="postbox">
            <h3 class="hndle"><span><?php echo esc_html( $this->label ); ?></span></h3>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_APIKEY_ENC); ?>"><?php esc_html_e('Mollie API Key','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr(self::OPT_APIKEY_ENC); ?>"
                                name="<?php echo esc_attr(self::OPT_APIKEY_ENC); ?>"
                                value="" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Clé API Mollie (ex. test_xxx). Laisser vide pour conserver la valeur existante.','wp-etik-events'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"><?php esc_html_e('Mollie Webhook Secret','wp-etik-events'); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"
                                name="<?php echo esc_attr(self::OPT_WEBHOOK_ENC); ?>"
                                value="" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Secret pour vérifier les webhooks Mollie si applicable.','wp-etik-events'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php if ( is_array( $test ) ) : ?>
                    <div style="margin-top:10px;">
                        <?php if ( ! empty( $test['success'] ) ) : ?>
                            <div style="padding:8px;border-left:4px solid #46b450;background:#f0fff4;color:#0b6b2f;">
                                <strong><?php esc_html_e('Connexion Mollie OK','wp-etik-events'); ?></strong>
                                <div><?php echo esc_html( $test['message'] ); ?></div>
                                <div style="font-size:12px;color:#666;"><?php echo esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), (int) $test['time'] ) ); ?></div>
                            </div>
                        <?php else : ?>
                            <div style="padding:8px;border-left:4px solid #d63638;background:#fff6f6;color:#8a1f1f;">
                                <strong><?php esc_html_e('Échec de la connexion Mollie','wp-etik-events'); ?></strong>
                                <div><?php echo esc_html( $test['message'] ); ?></div>
                                <div style="font-size:12px;color:#666;"><?php echo esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), (int) $test['time'] ) ); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('Aucun test effectué pour Mollie.', 'wp-etik-events'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }


    public function sanitize_and_encrypt_apikey( $new_value ) {

        $new_value = trim( (string) $new_value );
        if ( $new_value === '' ) {
            return get_option( self::OPT_APIKEY_ENC, '' );
        }

        $sanitized = sanitize_text_field( $new_value );

        // tester la clé AVANT chiffrement
        if ( class_exists( '\\WP_Etik\\Admin\\Payments\\Clients\\Mollie_Client' ) ) {
            $test = \WP_Etik\Admin\Payments\Clients\Mollie_Client::test_api_key( $sanitized );
            update_option( 'lwe_mollie_test_result', [
                'success' => (bool) $test['success'],
                'message' => (string) $test['message'],
                'code'    => (int) $test['code'],
                'time'    => time(),
            ] );
        } else {
            update_option( 'lwe_mollie_test_result', [
                'success' => false,
                'message' => 'Mollie_Client missing',
                'code' => 0,
                'time' => time(),
            ] );
        }
        
        return $this->encrypt_or_keep_existing( self::OPT_APIKEY_ENC, (string) $new_value );
    }

    public function sanitize_and_encrypt_webhook( $new_value ) {
        return $this->encrypt_or_keep_existing( self::OPT_WEBHOOK_ENC, (string) $new_value );
    }

    public function get_keys() : array {
        if ( defined( 'MOLLIE_API_KEY' ) && MOLLIE_API_KEY ) {
            $key = MOLLIE_API_KEY;
        } else {
            $key = $this->decrypt_option( self::OPT_APIKEY_ENC );
        }

        $webhook = defined( 'MOLLIE_WEBHOOK_SECRET' ) && MOLLIE_WEBHOOK_SECRET ? MOLLIE_WEBHOOK_SECRET : $this->decrypt_option( self::OPT_WEBHOOK_ENC );

        return [
            'apikey' => (string) $key,
            'webhook' => (string) $webhook,
        ];
    }
}
