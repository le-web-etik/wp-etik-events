<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

add_action('rest_api_init', function() {
    register_rest_route('lwe/v1', '/stripe-webhook', [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\handle_stripe_webhook',
        'permission_callback' => '__return_true',
    ]);
});

use WP_REST_Request;
use WP_REST_Response;

/**
 * Vérifie la signature Stripe (v1) et retourne true/false.
 */
function verify_stripe_signature( string $payload, string $sig_header, string $endpoint_secret, int $tolerance = 300 ) : bool {
    if ( empty($sig_header) || empty($endpoint_secret) ) return false;

    // parse header like: t=..., v1=..., v0=...
    $parts = explode(',', $sig_header);
    $map = [];
    foreach ($parts as $p) {
        $kv = explode('=', $p, 2);
        if ( count($kv) === 2 ) $map[ $kv[0] ] = $kv[1];
    }
    if ( empty($map['t']) || empty($map['v1']) ) return false;

    $timestamp = (int) $map['t'];
    if ( abs(time() - $timestamp) > $tolerance ) return false;

    $signed_payload = $timestamp . '.' . $payload;
    $expected_sig = hash_hmac('sha256', $signed_payload, $endpoint_secret);

    return hash_equals($expected_sig, $map['v1']);
}

/**
 * Webhook handler
 */
function handle_stripe_webhook( \WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    $payload = $request->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    // récupérer webhook secret (décrypté) via ta classe de settings
    if ( ! class_exists('\\WP_Etik\\Stripe_Settings') ) {
        return new \WP_REST_Response('Stripe settings missing', 500);
    }
    $keys = \WP_Etik\Admin\Stripe_Settings::get_keys();
    $endpoint_secret = $keys['webhook'] ?? '';

    // vérifier signature
    if ( ! verify_stripe_signature($payload, $sig_header, $endpoint_secret) ) {
        return new \WP_REST_Response('Invalid signature', 400);
    }

    $event = json_decode($payload, true);
    if ( ! is_array($event) || empty($event['type']) ) {
        return new \WP_REST_Response('Invalid payload', 400);
    }

    // traiter les événements utiles
    $type = $event['type'];

    if ( $type === 'checkout.session.completed' ) {
        $session = $event['data']['object'] ?? null;
        if ( $session ) {
            $ins_id = intval( $session['metadata']['inscription_id'] ?? 0 );
            $session_id = sanitize_text_field( $session['id'] ?? '' );
            // si metadata present, on met à jour par id
            if ( $ins_id ) {
                $wpdb->update(
                    $table,
                    [
                        'status' => 'confirmed',
                        'payment_session_id' => $session_id,
                    ],
                    [ 'id' => $ins_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
            } elseif ( $session_id ) {
                // fallback : chercher par payment_session_id
                $wpdb->update(
                    $table,
                    [ 'status' => 'confirmed' ],
                    [ 'payment_session_id' => $session_id ],
                    [ '%s' ],
                    [ '%s' ]
                );
            }
        }
    } elseif ( in_array($type, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true) ) {
        $session = $event['data']['object'] ?? null;
        if ( $session ) {
            $session_id = sanitize_text_field( $session['id'] ?? '' );
            if ( $session_id ) {
                $wpdb->update(
                    $table,
                    [ 'status' => 'cancelled' ],
                    [ 'payment_session_id' => $session_id ],
                    [ '%s' ],
                    [ '%s' ]
                );
            }
        }
    }

    return new \WP_REST_Response('ok', 200);
}

/**
 * Handler webhook Stripe (utilise stripe-php)
 * Assure : vérification signature, idempotence, fallback par session id.
 */
function lwe_stripe_webhook_handler( WP_REST_Request $request ) {
    global $wpdb;
    $ins_table = $wpdb->prefix . 'etik_inscriptions';

    $payload = $request->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    // Récupérer webhook secret via ta fonction/setting
    if ( ! function_exists('lwe_get_stripe_keys') ) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('lwe_stripe_webhook_handler: lwe_get_stripe_keys() missing');
        }
        return new WP_REST_Response('Stripe settings missing', 500);
    }

    $keys = lwe_get_stripe_keys();
    $endpoint_secret = $keys['webhook'] ?? '';

    if ( empty( $endpoint_secret ) ) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('lwe_stripe_webhook_handler: webhook secret not configured');
        }
        return new WP_REST_Response('Webhook secret not configured', 500);
    }

    // Vérifier que stripe-php est disponible
    if ( ! class_exists('\Stripe\Webhook') ) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('lwe_stripe_webhook_handler: stripe-php library not loaded');
        }
        return new WP_REST_Response('Stripe library missing', 500);
    }

    // Vérification de la signature et construction de l'événement
    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
    } catch (\UnexpectedValueException $e) {
        // payload invalide
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('Stripe webhook invalid payload: ' . $e->getMessage());
        }
        return new WP_REST_Response('Invalid payload', 400);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        // signature invalide
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('Stripe webhook signature verification failed: ' . $e->getMessage());
        }
        return new WP_REST_Response('Invalid signature', 400);
    } catch (\Exception $e) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('Stripe webhook error: ' . $e->getMessage());
        }
        return new WP_REST_Response('Webhook error', 400);
    }

    // Traiter l'événement
    $type = $event->type ?? '';
    $object = $event->data->object ?? null;

    if ( ! $object ) {
        return new WP_REST_Response('No object in event', 400);
    }

    // Helper pour mettre à jour l'inscription de façon idempotente
    $update_inscription_by_id = function( int $ins_id, array $data ) use ( $wpdb, $ins_table ) {
        if ( $ins_id <= 0 ) return false;

        // Récupérer statut actuel pour éviter downgrades
        $current = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$ins_table} WHERE id = %d", $ins_id ), ARRAY_A );
        if ( ! $current ) return false;

        // Si déjà confirmé, ne rien faire
        if ( isset($current['status']) && $current['status'] === 'confirmed' && ( $data['status'] ?? '' ) !== 'confirmed' ) {
            return true;
        }

        $wpdb->update( $ins_table, $data, [ 'id' => $ins_id ], array_fill(0, count($data), '%s'), [ '%d' ] );
        return true;
    };

    // Helper fallback : update by payment_session_id
    $update_inscription_by_session = function( string $session_id, array $data ) use ( $wpdb, $ins_table ) {
        if ( empty($session_id) ) return false;
        $current = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$ins_table} WHERE payment_session_id = %s", $session_id ), ARRAY_A );
        if ( ! $current ) return false;
        if ( isset($current['status']) && $current['status'] === 'confirmed' && ( $data['status'] ?? '' ) !== 'confirmed' ) {
            return true;
        }
        $wpdb->update( $ins_table, $data, [ 'payment_session_id' => $session_id ], array_fill(0, count($data), '%s'), [ '%s' ] );
        return true;
    };

    // Main switch
    if ( $type === 'checkout.session.completed' ) {
        $session = $object;
        $ins_id = intval( $session->metadata->inscription_id ?? 0 );
        $session_id = sanitize_text_field( $session->id ?? '' );

        $data = [
            'status' => 'confirmed',
            'payment_session_id' => $session_id,
        ];

        $ok = false;
        if ( $ins_id ) {
            $ok = $update_inscription_by_id( $ins_id, $data );
        }
        if ( ! $ok && $session_id ) {
            $ok = $update_inscription_by_session( $session_id, $data );
        }

        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('Stripe webhook checkout.session.completed processed for session ' . $session_id . ' ins_id=' . ($ins_id ?: 'n/a') );
        }
    } elseif ( in_array( $type, [ 'checkout.session.expired', 'checkout.session.async_payment_failed' ], true ) ) {
        $session = $object;
        $ins_id = intval( $session->metadata->inscription_id ?? 0 );
        $session_id = sanitize_text_field( $session->id ?? '' );

        $data = [ 'status' => 'cancelled' ];

        $ok = false;
        if ( $ins_id ) {
            $ok = $update_inscription_by_id( $ins_id, $data );
        }
        if ( ! $ok && $session_id ) {
            $ok = $update_inscription_by_session( $session_id, $data );
        }

        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('Stripe webhook session failed/expired for session ' . $session_id . ' ins_id=' . ($ins_id ?: 'n/a') );
        }
    } else {
        // autres événements ignorés mais renvoyer 200
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('Stripe webhook ignored event type: ' . $type);
        }
    }

    return new WP_REST_Response('ok', 200);
}
