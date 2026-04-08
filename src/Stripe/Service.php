<?php
namespace WP_Etik\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * Façade publique pour Stripe.
 * Gère l'initialisation du client et la création de session.
 */
class Service {

    /**
     * Récupère les clés Stripe.
     * Priorité : Payments_Settings > Stripe_Settings (ancien) > Constantes WP
     */
    public static function keys(): array {
        // 1. Essayer la nouvelle classe centralisée
        if ( class_exists( '\\WP_Etik\\Admin\\Payments_Settings' ) ) {
            try {
                $ps = new \WP_Etik\Admin\Payments_Settings();
                $all = $ps->get_all_keys();
                if ( isset( $all['stripe'] ) && is_array( $all['stripe'] ) ) {
                    return [
                        'publishable' => trim( $all['stripe']['publishable'] ?? '' ),
                        'secret'      => trim( $all['stripe']['secret'] ?? '' ),
                        'webhook'     => trim( $all['stripe']['webhook'] ?? '' ),
                    ];
                }
            } catch ( \Throwable $e ) {
                error_log( 'ETIK: Payments_Settings failed: ' . $e->getMessage() );
            }
        }

        // 2. Fallback ancienne classe
        if ( class_exists( '\\WP_Etik\\Admin\\Stripe_Settings' ) ) {
            return \WP_Etik\Admin\Stripe_Settings::get_keys();
        }

        // 3. Fallback Constantes (wp-config.php)
        return [
            'publishable' => defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '',
            'secret'      => defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '',
            'webhook'     => defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '',
        ];
    }

    /**
     * Vérifie si Stripe est configuré.
     */
    public static function enabled(): bool {
        $k = self::keys();
        return ! empty( $k['secret'] ) && ! empty( $k['publishable'] );
    }

    /**
     * Initialise le client Stripe.
     * Vérifie que le SDK est bien chargé.
     *
     * @return \Stripe\StripeClient|null
     */
    public static function client() {
        $keys = self::keys();
        if ( empty( $keys['secret'] ) ) {
            error_log('[WP-Etik] Stripe Secret Key missing.');
            return null;
        }

        // Vérifier si le SDK Stripe est disponible
        // Cas A : Chargement automatique via Composer (recommandé)
        if ( class_exists( '\\Stripe\\StripeClient' ) ) {
            try {
                return new \Stripe\StripeClient( $keys['secret'] );
            } catch ( \Exception $e ) {
                error_log( '[WP-Etik] Stripe Client Init Error: ' . $e->getMessage() );
                return null;
            }
        }

        // Cas B : Fallback vers l'ancien statique (si vieille version du SDK)
        if ( class_exists( '\\Stripe\\Stripe' ) ) {
            try {
                \Stripe\Stripe::setApiKey( $keys['secret'] );
                // Retourne un objet factice ou null, car l'ancien SDK utilise des appels statiques
                // Mais pour createCheckoutSession, on aura besoin d'adapter l'appel.
                // Pour l'instant, on retourne null pour forcer l'usage du nouveau SDK.
                error_log('[WP-Etik] Ancient Stripe SDK detected. Please update to v10+.');
                return null;
            } catch ( \Exception $e ) {
                error_log( '[WP-Etik] Legacy Stripe Init Error: ' . $e->getMessage() );
                return null;
            }
        }

        error_log('[WP-Etik] Stripe SDK NOT FOUND. Install via Composer: "stripe/stripe-php".');
        return null;
    }

    /**
     * Crée une session Checkout.
     *
     * @throws \Exception Si la création échoue
     */
    public static function createCheckoutSession( int $inscription_id, int $event_id, int $amount_cents, string $success_url, string $cancel_url ): ?array {
        $client = self::client();
        
        if ( ! $client ) {
            throw new \Exception('Impossible d\'initialiser le client Stripe. Vérifiez les clés API et l\'installation du SDK.');
        }

        $event_title = get_the_title( $event_id ) ?? 'Inscription événement';

        $params = [
            'payment_method_types' => [ 'card' ],
            'mode'                 => 'payment',
            'line_items'           => [[
                'price_data' => [
                    'currency'     => 'eur',
                    'product_data' => [ 
                        'name' => substr($event_title, 0, 60), // Limite Stripe
                        'description' => 'Inscription via Le Web Etik',
                    ],
                    'unit_amount'  => $amount_cents,
                ],
                'quantity' => 1,
            ]],
            'metadata'    => [ 
                'inscription_id' => (string) $inscription_id, 
                'event_id'       => (string) $event_id,
                'site'           => home_url()
            ],
            'success_url' => $success_url,
            'cancel_url'  => $cancel_url,
            // Optionnel : pré-remplir l'email si vous l'avez récupéré avant cet appel
            // 'customer_email' => $customer_email, 
        ];

        try {
            $session = $client->checkout->sessions->create( $params );
            
            return [
                'id'  => $session->id,
                'url' => $session->url,
            ];
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            // Erreur spécifique Stripe (clé invalide, montant incorrect, etc.)
            error_log( '[WP-Etik] Stripe API Error: ' . $e->getMessage() );
            throw new \Exception('Erreur API Stripe: ' . $e->getMessage());
        } catch ( \Exception $e ) {
            error_log( '[WP-Etik] Stripe General Error: ' . $e->getMessage() );
            throw new \Exception('Erreur interne: ' . $e->getMessage());
        }
    }
}