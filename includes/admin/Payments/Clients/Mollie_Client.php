<?php
namespace WP_Etik\Admin\Payments\Clients;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mollie_Client {

    public static function test_api_key( string $api_key ) : array {
        $api_key = trim( (string) $api_key );
        if ( $api_key === '' ) {
            return [ 'success' => false, 'message' => 'Empty API key', 'code' => 0 ];
        }

        $url = 'https://api.mollie.com/v2/methods';
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
        if ( is_array( $json ) && ! empty( $json['title'] ) ) {
            $msg = $json['title'] . ( ! empty( $json['detail'] ) ? ' - ' . $json['detail'] : '' );
        } elseif ( ! empty( $body ) ) {
            $msg = substr( $body, 0, 200 );
        }

        return [ 'success' => false, 'message' => $msg, 'code' => $code ];
    }
}
