<?php
namespace WP_Etik\Admin\Payments;

defined('ABSPATH') || exit;

class Webhook_Checker {

    public static function check_stripe_webhook(string $secret_key, string $expected_url): array {
        if ( empty($secret_key) ) {
            return ['success' => false, 'message' => 'Stripe secret key missing', 'details' => []];
        }

        // Appel API Stripe pour lister les webhook_endpoints
        $resp = wp_remote_get( 'https://api.stripe.com/v1/webhook_endpoints', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Accept'        => 'application/json',
                'User-Agent'    => 'WP-Etik/1.0',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error($resp) ) {
            return ['success' => false, 'message' => 'HTTP error: ' . $resp->get_error_message(), 'details' => []];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ( $code < 200 || $code >= 300 ) {
            return ['success' => false, 'message' => 'Stripe API returned HTTP ' . $code, 'details' => ['body' => substr($body,0,500)]];
        }

        $json = json_decode($body, true);
        if ( ! is_array($json) || empty($json['data']) ) {
            return ['success' => false, 'message' => 'No webhook endpoints found in Stripe account', 'details' => ['response' => $json]];
        }

        foreach ( $json['data'] as $we ) {
            if ( ! empty($we['url']) && rtrim($we['url'], '/') === rtrim($expected_url, '/') ) {
                return ['success' => true, 'message' => 'Webhook endpoint found in Stripe account', 'details' => ['id' => $we['id'], 'url' => $we['url']]];
            }
        }

        return ['success' => false, 'message' => 'No matching webhook endpoint found in Stripe account', 'details' => ['expected' => $expected_url, 'found' => array_column($json['data'], 'url')]];
    }

    public static function check_mollie_webhook(string $api_key, string $expected_url): array {
        if ( empty($api_key) ) {
            return ['success' => false, 'message' => 'Mollie API key missing', 'details' => []];
        }

        // On ne peut pas lister les webhooks via Mollie de la même façon.
        // Vérifions que l'URL REST est joignable (réponse HTTP).
        $resp = wp_remote_post( $expected_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode(['lwe_test' => 1]),
            'timeout' => 10,
        ] );

        if ( is_wp_error($resp) ) {
            return ['success' => false, 'message' => 'Endpoint unreachable: ' . $resp->get_error_message(), 'details' => []];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        // Stripe/Mollie endpoints peuvent renvoyer 400 si signature manquante, mais l'endpoint est joignable.
        if ( $code >= 200 && $code < 300 ) {
            return ['success' => true, 'message' => 'Webhook endpoint reachable (HTTP ' . $code . ')', 'details' => []];
        }

        return ['success' => true, 'message' => 'Endpoint reachable (HTTP ' . $code . '). Signature check may still fail for real webhooks.', 'details' => []];
    }
}
