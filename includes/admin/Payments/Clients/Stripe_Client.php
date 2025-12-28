<?php
namespace WP_Etik\Admin\Payments\Clients;

defined( 'ABSPATH' ) || exit;

use Stripe\StripeClient;

/**
 * Client léger pour Stripe : tests de clé et helpers de création de session.
 */
class Stripe_Client {

    /**
     * Teste la clé API Stripe en appelant un endpoint simple.
     *
     * @param string $api_key clé secrète Stripe (sk_test_xxx / sk_live_xxx)
     * @return array{success:bool, message:string, code:int}
     */
    public static function test_api_key( string $api_key ) : array {
        $api_key = trim( (string) $api_key );
        if ( $api_key === '' ) {
            return [ 'success' => false, 'message' => 'Empty API key', 'code' => 0 ];
        }

        $url = 'https://api.stripe.com/v1/charges?limit=1';
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
                'User-Agent'    => 'WP-Etik/1.0 (+https://example.org)',
            ],
            'timeout' => 15,
        ];

        $resp = wp_remote_get( $url, $args );

        if ( is_wp_error( $resp ) ) {
            return [ 'success' => false, 'message' => $resp->get_error_message(), 'code' => 0 ];
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );

        if ( $code >= 200 && $code < 300 ) {
            return [ 'success' => true, 'message' => 'Connexion OK', 'code' => $code ];
        }

        $msg = 'HTTP ' . $code;
        $json = json_decode( $body, true );
        if ( is_array( $json ) && ! empty( $json['error']['message'] ) ) {
            $msg = $json['error']['message'];
        } elseif ( ! empty( $body ) ) {
            $msg = substr( $body, 0, 200 );
        }

        return [ 'success' => false, 'message' => $msg, 'code' => $code ];
    }

    /**
     * Retourne une instance StripeClient à partir d'une clé secrète.
     *
     * @param string $secret
     * @return StripeClient|null
     */
    public static function client_from_secret( string $secret ) {
        $secret = trim( (string) $secret );
        if ( $secret === '' ) {
            return null;
        }

        if ( class_exists( '\\Stripe\\StripeClient' ) ) {
            return new StripeClient( $secret );
        }

        return null;
    }

    /**
     * Crée une session Checkout via une instance StripeClient.
     *
     * @param StripeClient $client
     * @param array $params Paramètres passés à $client->checkout->sessions->create()
     * @return array{id:string, url:string}
     */
    public static function create_checkout_session( $client, array $params ) : array {
        $session = $client->checkout->sessions->create( $params );
        return [
            'id'  => $session->id ?? '',
            'url' => $session->url ?? '',
        ];
    }
}
