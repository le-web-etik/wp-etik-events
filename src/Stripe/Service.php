<?php
namespace WP_Etik\Stripe;

use Stripe\StripeClient;

defined('ABSPATH') || exit;

class Service {
    public static function keys(): array {
        // utilise la classe admin Stripe_Settings_Admin si présente
        if ( class_exists('\\WP_Etik\\Admin\\Stripe_Settings_Admin') ) {
            return \WP_Etik\Admin\Stripe_Settings_Admin::get_keys();
        }
        return ['publishable'=>'','secret'=>'','webhook'=>''];
    }

    public static function enabled(): bool {
        $k = self::keys();
        return !empty($k['secret']) && !empty($k['publishable']);
    }

    public static function client(): ?StripeClient {
        $k = self::keys();
        if (empty($k['secret'])) return null;
        return new StripeClient($k['secret']);
    }

    public static function createCheckoutSession(int $inscription_id, int $event_id, int $amount_cents, string $success_url, string $cancel_url): ?array {
        $client = self::client();
        if (!$client) return null;
        $session = $client->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => 'Acompte réservation'],
                    'unit_amount' => $amount_cents
                ],
                'quantity' => 1
            ]],
            'metadata' => ['inscription_id' => $inscription_id, 'event_id' => $event_id],
            'success_url' => $success_url,
            'cancel_url' => $cancel_url
        ]);
        return ['id'=>$session->id, 'url'=>$session->url];
    }
}
