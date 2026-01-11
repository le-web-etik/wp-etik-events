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
    if ( ! class_exists('\\WP_Etik\\Admin\\Stripe_Settings') ) {
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
            $email = sanitize_email( $session['customer_details']['email'] ?? '' ); // si disponible
            $customer_name = sanitize_text_field( $session['customer_details']['name'] ?? '' );

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

                // Envoyer l'e-mail de confirmation
                if ( ! empty( $email ) ) {
                    wp_etik_send_confirmation_email_after_payment( $ins_id, $email, $customer_name );
                }

            } elseif ( $session_id ) {
                // fallback : chercher par payment_session_id
                $wpdb->update(
                    $table,
                    [ 'status' => 'confirmed' ],
                    [ 'payment_session_id' => $session_id ],
                    [ '%s' ],
                    [ '%s' ]
                );

                // Récupérer l'email depuis la base
                $inscription = $wpdb->get_row( $wpdb->prepare( "SELECT email FROM {$table} WHERE payment_session_id = %s LIMIT 1", $session_id ), ARRAY_A );
                if ( ! empty( $inscription['email'] ) ) {
                    wp_etik_send_confirmation_email_after_payment( $inscription['id'], $inscription['email'], '' );
                }
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

        // Rediriger vers la page avec statut 'error'
        $error_url = add_query_arg( [
            'status' => 'error',
            'msg' => urlencode( 'Le paiement a échoué. Veuillez contacter le support.' ),
        ], wp_etik_get_payment_return_url() );

        // Optionnel : rediriger via JS ou PHP
        wp_safe_redirect( $error_url );
        exit;
    }

    return new \WP_REST_Response('ok', 200);
}

/**
 * Envoyer un e-mail de confirmation après paiement
 *
 * @param int    $inscription_id
 * @param string $email
 * @param string $customer_name
 */
function wp_etik_send_confirmation_email_after_payment( $inscription_id, $email, $customer_name = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    $inscription = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $inscription_id ), ARRAY_A );

    if ( ! $inscription ) {
        return;
    }

    $event_id = intval( $inscription['event_id'] );
    $event_title = get_the_title( $event_id );

    $subject = "✅ Confirmation de votre inscription à {$event_title}";

    $message = '
    <html>
    <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
        <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
            <h2 style="color: #46b450;">✅ Confirmation d\'inscription</h2>
            <p>Bonjour ' . esc_html( $customer_name ?: $inscription['first_name'] ) . ',</p>
            <p>Merci pour votre paiement ! Votre inscription à la formation <strong>' . esc_html( $event_title ) . '</strong> est confirmée.</p>
            <p>Vous recevrez un rappel quelques jours avant l\'événement.</p>
            <p>Pour accéder à votre espace, <a href="' . esc_url( home_url( '/mon-espace' ) ) . '">cliquez ici</a>.</p>
            <p>À bientôt,<br>L\'équipe Le Web Etik</p>
        </div>
    </body>
    </html>
    ';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Le Web Etik <reservation@lewebetik.fr>'
    ];

    $sent = wp_mail( $email, $subject, $message, $headers );

    if ( ! $sent ) {
        error_log( "[WP-Etik] Email not sent to {$email} for inscription {$inscription_id}" );
    } else {
        error_log( "[WP-Etik] Email sent to {$email} for inscription {$inscription_id}" );
    }
}
