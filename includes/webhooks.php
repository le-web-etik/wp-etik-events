<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

add_action('rest_api_init', function() {
    register_rest_route('lwe/v1', '/stripe-webhook', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\handle_stripe_webhook',
        'permission_callback' => '__return_true',
    ]);
});

use WP_REST_Request;
use WP_REST_Response;

/**
 * Vérifie la signature Stripe (v1) et retourne true/false.
 */
function verify_stripe_signature(
    string $payload,
    string $sig_header,
    string $endpoint_secret,
    int    $tolerance = 300
) : bool {
    if ( empty($sig_header) || empty($endpoint_secret) ) {
        return false;
    }

    $parts = explode(',', $sig_header);
    $map   = [];
    foreach ( $parts as $p ) {
        $kv = explode('=', $p, 2);
        if ( count($kv) === 2 ) {
            $map[ $kv[0] ] = $kv[1];
        }
    }

    if ( empty($map['t']) || empty($map['v1']) ) {
        return false;
    }

    $timestamp = (int) $map['t'];
    if ( abs( time() - $timestamp ) > $tolerance ) {
        return false;
    }

    $signed_payload = $timestamp . '.' . $payload;
    $expected_sig   = hash_hmac('sha256', $signed_payload, $endpoint_secret);

    return hash_equals($expected_sig, $map['v1']);
}

/**
 * Webhook handler principal.
 *
 * ⚠️  Ne jamais utiliser wp_safe_redirect() / header('Location:') / exit ici.
 *     Stripe attend un HTTP 200 en retour direct, pas une redirection.
 */
function handle_stripe_webhook( \WP_REST_Request $request ) : \WP_REST_Response {

    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    $payload    = $request->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    // ── Récupération des clés via la façade centralisée ─────────────────────
    // Utilise WP_Etik\Stripe\Service qui gère la compatibilité ascendante
    // avec Payments_Settings ET l'ancienne Stripe_Settings.
    if ( ! class_exists('\\WP_Etik\\Stripe\\Service') ) {
        error_log('[WP-Etik] Webhook: Stripe\Service introuvable.');
        return new \WP_REST_Response('Configuration error', 500);
    }

    $keys            = \WP_Etik\Stripe\Service::keys();
    $endpoint_secret = $keys['webhook'] ?? '';

    // ── Vérification de la signature ────────────────────────────────────────
    if ( ! verify_stripe_signature($payload, $sig_header, $endpoint_secret) ) {
        error_log('[WP-Etik] Webhook: signature invalide ou webhook secret manquant.');
        return new \WP_REST_Response('Invalid signature', 400);
    }

    // ── Décodage du payload ─────────────────────────────────────────────────
    $event = json_decode($payload, true);
    if ( ! is_array($event) || empty($event['type']) ) {
        error_log('[WP-Etik] Webhook: payload invalide.');
        return new \WP_REST_Response('Invalid payload', 400);
    }

    $type = $event['type'];

    // ── Paiement confirmé ───────────────────────────────────────────────────
    if ( $type === 'checkout.session.completed' ) {


        /*// Dans handle_stripe_webhook()
        $type = $event['data']['object']['metadata']['type'] ?? '';

        if ( $type === 'inscription' ) {
            // Logique événement : Mail de confirmation event, mise à jour table inscriptions
            handle_event_confirmation( $inscription_id );
        } elseif ( $type === 'reservation' ) {
            // Logique prestation : Mail de rappel RDV, mise à jour table reservations
            handle_prestation_confirmation( $reservation_id );
        }*/

        $session = $event['data']['object'] ?? null;

        if ( $session ) {
            $ins_id        = intval( $session['metadata']['inscription_id'] ?? 0 );
            $session_id    = sanitize_text_field( $session['id'] ?? '' );
            $email         = sanitize_email( $session['customer_details']['email'] ?? '' );
            $customer_name = sanitize_text_field( $session['customer_details']['name'] ?? '' );

            if ( $ins_id ) {
                $wpdb->update(
                    $table,
                    [
                        'status'             => 'confirmed',
                        'payment_session_id' => $session_id,
                    ],
                    [ 'id' => $ins_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                if ( ! empty($email) ) {
                    wp_etik_send_confirmation_email_after_payment( $ins_id, $email, $customer_name );
                    wp_etik_send_admin_notification_email( $ins_id, $email, $customer_name );
                }

            } elseif ( $session_id ) {
                // Fallback : chercher par payment_session_id
                $wpdb->update(
                    $table,
                    [ 'status' => 'confirmed' ],
                    [ 'payment_session_id' => $session_id ],
                    [ '%s' ],
                    [ '%s' ]
                );

                $inscription = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, email FROM {$table} WHERE payment_session_id = %s LIMIT 1",
                        $session_id
                    ),
                    ARRAY_A
                );

                if ( ! empty($inscription['email']) ) {
                    wp_etik_send_confirmation_email_after_payment(
                        $inscription['id'],
                        $inscription['email'],
                        ''
                    );
                    wp_etik_send_admin_notification_email(
                        $inscription['id'],
                        $inscription['email'],
                        ''
                    );
                }
            }
        }

    // ── Session expirée ou paiement asynchrone échoué ───────────────────────
    } elseif ( in_array($type, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true) ) {

        $session    = $event['data']['object'] ?? null;
        $session_id = sanitize_text_field( $session['id'] ?? '' );

        if ( $session_id ) {
            $updated = $wpdb->update(
                $table,
                [ 'status' => 'cancelled' ],
                [ 'payment_session_id' => $session_id ],
                [ '%s' ],
                [ '%s' ]
            );

            if ( $updated === false ) {
                error_log( "[WP-Etik] Webhook: erreur DB lors du marquage cancelled — " . $wpdb->last_error );
            } else {
                error_log( "[WP-Etik] Webhook: session {$session_id} marquée cancelled (type: {$type})." );
            }
        }

        // ✅ Pas de redirection ici : Stripe est un appel API serveur-à-serveur.
        // Il n'y a pas de navigateur à rediriger.

    } else {
        // Événement non géré — on répond quand même 200 pour que Stripe arrête de réessayer.
        error_log( "[WP-Etik] Webhook: événement ignoré → {$type}" );
    }

    // ✅ Toujours terminer par 200 OK — c'est ce que Stripe attend.
    return new \WP_REST_Response(['status' => 'ok'], 200);
}

/**
 * Envoyer un e-mail de confirmation après paiement.
 *
 * @param int    $inscription_id
 * @param string $email
 * @param string $customer_name
 */
function wp_etik_send_confirmation_email_after_payment(
    int    $inscription_id,
    string $email,
    string $customer_name = ''
) : void {
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    $inscription = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $inscription_id ),
        ARRAY_A
    );

    if ( function_exists( '\\WP_Etik\\lwe_decrypt_inscription_data' ) ) {
        $inscription = \WP_Etik\lwe_decrypt_inscription_data( $inscription );
    }

    if ( ! $inscription ) {
        return;
    }

    $event_id    = intval( $inscription['event_id'] );
    $event_title = get_the_title( $event_id );
    $prenom      = esc_html( $customer_name ?: $inscription['first_name'] );

    $subject = "✅ Confirmation de votre inscription à {$event_title}";

    $message = '
    <html>
    <body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;">
        <div style="max-width:600px;margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:8px;">
            <h2 style="color:#46b450;">✅ Confirmation d\'inscription</h2>
            <p>Bonjour ' . $prenom . ',</p>
            <p>Merci pour votre paiement ! Votre inscription à <strong>' . esc_html($event_title) . '</strong> est confirmée.</p>
            <p>Vous recevrez un rappel quelques jours avant l\'événement.</p>
            <p>À bientôt,<br>L\'équipe Le Web Etik</p>
        </div>
    </body>
    </html>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Le Web Etik <reservation@lewebetik.fr>',
    ];

    $sent = wp_mail( $email, $subject, $message, $headers );

    if ( ! $sent ) {
        error_log( "[WP-Etik] wp_mail échec pour {$email} (inscription #{$inscription_id})" );
    }
}

/**
 * Envoyer une notification admin après paiement.
 *
 * @param int    $inscription_id
 * @param string $email
 * @param string $customer_name
 */
function wp_etik_send_admin_notification_email(
    int    $inscription_id,
    string $email,
    string $customer_name = ''
) : void {
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    $inscription = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $inscription_id ),
        ARRAY_A
    );

    if ( ! $inscription ) {
        return;
    }

    $event_id    = intval( $inscription['event_id'] );
    $event_title = get_the_title( $event_id );
    $admin_email = get_option('admin_email');

    $subject = "🔔 Nouvelle inscription confirmée — {$event_title}";

    $message = '
    <html>
    <body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;">
        <div style="max-width:600px;margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:8px;">
            <h2 style="color:#0074d4;">🔔 Nouvelle inscription</h2>
            <p><strong>Événement :</strong> ' . esc_html($event_title) . '</p>
            <p><strong>Inscrit :</strong> ' . esc_html( $customer_name ?: ( $inscription['first_name'] . ' ' . $inscription['last_name'] ) ) . '</p>
            <p><strong>Email :</strong> ' . esc_html($email) . '</p>
            <p><strong>ID inscription :</strong> #' . intval($inscription_id) . '</p>
            <p><a href="' . esc_url( admin_url('admin.php?page=wp-etik-registrations&action=view&event_id=' . $event_id) ) . '">Voir les inscriptions</a></p>
        </div>
    </body>
    </html>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Le Web Etik <reservation@lewebetik.fr>',
    ];

    wp_mail( $admin_email, $subject, $message, $headers );
}