<?php
namespace WP_Etik\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * Façade publique pour Stripe.
 * Délègue la logique API au client centralisé si disponible.
 */
class Service {

    /**
     * Récupère les clés Stripe depuis la page de paramètres (Payments_Settings) ou fallback.
     *
     * @return array{publishable:string, secret:string, webhook:string}
     */
    public static function keys(): array {
        // préférence : Payments_Settings centralisée
        if ( class_exists( '\\WP_Etik\\Admin\\Payments_Settings' ) ) {
            try {
                $ps = new \WP_Etik\Admin\Payments_Settings();
                $all = $ps->get_all_keys();
                if ( isset( $all['stripe'] ) && is_array( $all['stripe'] ) ) {
                    return [
                        'publishable' => (string) ( $all['stripe']['publishable'] ?? '' ),
                        'secret'      => (string) ( $all['stripe']['secret'] ?? '' ),
                        'webhook'     => (string) ( $all['stripe']['webhook'] ?? '' ),
                    ];
                }
            } catch ( \Throwable $e ) {
                error_log( 'ETIK: Payments_Settings::get_all_keys failed: ' . $e->getMessage() );
            }
        }

        // compatibilité ascendante : ancienne classe Stripe_Settings
        if ( class_exists( '\\WP_Etik\\Admin\\Stripe_Settings' ) ) {
            return \WP_Etik\Admin\Stripe_Settings::get_keys();
        }

        return [ 'publishable' => '', 'secret' => '', 'webhook' => '' ];
    }

    /**
     * Indique si Stripe est configuré (publishable + secret présents).
     *
     * @return bool
     */
    public static function enabled(): bool {
        $k = self::keys();
        return ! empty( $k['secret'] ) && ! empty( $k['publishable'] );
    }

    /**
     * Retourne un client Stripe (StripeClient) ou null.
     *
     * @return \Stripe\StripeClient|null
     */
    public static function client() {
        $k = self::keys();
        if ( empty( $k['secret'] ) ) {
            return null;
        }

        // délégation vers le client centralisé si présent
        if ( class_exists( '\\WP_Etik\\Admin\\Payments\\Clients\\Stripe_Client' ) ) {
            return \WP_Etik\Admin\Payments\Clients\Stripe_Client::client_from_secret( $k['secret'] );
        }

        // fallback direct vers la SDK Stripe si disponible
        if ( class_exists( '\\Stripe\\StripeClient' ) ) {
            return new \Stripe\StripeClient( $k['secret'] );
        }

        return null;
    }

    /**
     * Crée une session Checkout et retourne id + url.
     *
     * @param int $inscription_id
     * @param int $event_id
     * @param int $amount_cents
     * @param string $success_url
     * @param string $cancel_url
     * @return array|null
     */
    public static function createCheckoutSession( int $inscription_id, int $event_id, int $amount_cents, string $success_url, string $cancel_url ): ?array {
        $client = self::client();
        if ( ! $client ) {
            return null;
        }

        $params = [
            'payment_method_types' => [ 'card' ],
            'mode'                 => 'payment',
            'line_items'           => [[
                'price_data' => [
                    'currency'     => 'eur',
                    'product_data' => [ 'name' => 'Acompte réservation' ],
                    'unit_amount'  => $amount_cents,
                ],
                'quantity' => 1,
            ]],
            'metadata'    => [ 'inscription_id' => $inscription_id, 'event_id' => $event_id ],
            'success_url' => $success_url,
            'cancel_url'  => $cancel_url,
        ];

        // déléguer la création au client centralisé si disponible
        if ( class_exists( '\\WP_Etik\\Admin\\Payments\\Clients\\Stripe_Client' ) ) {
            return \WP_Etik\Admin\Payments\Clients\Stripe_Client::create_checkout_session( $client, $params );
        }

        // fallback direct
        $session = $client->checkout->sessions->create( $params );
        return [
            'id'  => $session->id ?? '',
            'url' => $session->url ?? '',
        ];
    }
}
