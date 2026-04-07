<?php
/**
 * includes/ajax-handler-checkout.php
 *
 * Handler AJAX : lwe_create_checkout
 *
 * Flux :
 *   1. Nonce + captcha
 *   2. Validation dynamique des champs requis (via Form Builder)
 *   3. Résolution champs dynamiques → colonnes DB (type + label)
 *   4. Collecte champs custom → JSON
 *   5. Chiffrement AES-256-CBC
 *   6. Insertion + Stripe si paiement
 */

namespace WP_Etik;

defined( 'ABSPATH' ) || exit;

require_once WP_ETIK_PLUGIN_DIR . 'includes/lwe-field-helpers.php';

add_action( 'wp_ajax_nopriv_lwe_create_checkout', __NAMESPACE__ . '\\lwe_create_checkout' );
add_action( 'wp_ajax_lwe_create_checkout',        __NAMESPACE__ . '\\lwe_create_checkout' );

function lwe_create_checkout() : void {

    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    // ── 1. Nonce ────────────────────────────────────────────────────────
    $nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );
    if ( ! wp_verify_nonce( $nonce, 'wp_etik_inscription_nonce' ) ) {
        wp_send_json_error( [ 'code' => 'invalid_nonce', 'message' => 'Requête invalide.' ], 400 );
    }

    // ── 2. hCaptcha ─────────────────────────────────────────────────────
    $captcha_token = sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ?? '' ) );
    $hc_error = '';
    if ( function_exists( __NAMESPACE__ . '\\verify_hcaptcha' ) && ! verify_hcaptcha( $captcha_token, $hc_error ) ) {
        wp_send_json_error( [ 'code' => 'captcha_failed', 'message' => $hc_error ?: 'Vérification anti-robot échouée.' ], 400 );
    }

    // ── 3. Event ────────────────────────────────────────────────────────
    $event_id = intval( $_POST['event_id'] ?? 0 );
    if ( $event_id <= 0 ) {
        wp_send_json_error( [ 'code' => 'missing_event', 'message' => "ID de l'événement manquant." ], 400 );
    }
    $event = get_post( $event_id );
    if ( ! $event || $event->post_status !== 'publish' ) {
        wp_send_json_error( [ 'code' => 'event_not_found', 'message' => 'Événement introuvable.' ], 404 );
    }

    // ── 4. Validation dynamique des champs requis ───────────────────────
    $form_id = intval( $_POST['form_id'] ?? 0 );

    $validation_error = lwe_validate_required_fields( $form_id );
    if ( $validation_error !== null ) {
        wp_send_json_error( $validation_error, 400 );
    }

    // ── 5. Résolution champs dynamiques → colonnes DB ───────────────────
    $field_map = lwe_resolve_field_map( $form_id );

    $email      = lwe_get_post_field( $field_map['email'],      'email' );
    $first_name = lwe_get_post_field( $field_map['first_name'], 'text' );
    $last_name  = lwe_get_post_field( $field_map['last_name'],  'text' );
    $phone      = lwe_get_post_field( $field_map['phone'],      'tel' );

    // Double vérification email (même si validate_required l'a fait)
    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'code' => 'invalid_email', 'message' => 'Adresse e-mail invalide.' ], 400 );
    }

    // ── 6. Champs custom → JSON ────────────────────────────────────────
    $mapped_keys = array_filter( array_values( $field_map ) );
    $custom      = lwe_collect_custom_fields( $mapped_keys, $form_id );
    $custom_json = ! empty( $custom ) ? wp_json_encode( $custom ) : '';

    // ── 7. Hash email pour recherche ────────────────────────────────────
    $email_hash = lwe_email_search_hash( $email );

    // ── 8. Doublon ──────────────────────────────────────────────────────
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE event_id = %d AND email_hash = %s AND status NOT IN ('cancelled')
         LIMIT 1",
        $event_id,
        $email_hash
    ) );
    if ( $existing ) {
        wp_send_json_error( [
            'code'    => 'already_registered',
            'message' => 'Cette adresse e-mail est déjà inscrite pour cet événement.',
        ], 409 );
    }

    // ── 9. Places ───────────────────────────────────────────────────────
    $max_places = intval( get_post_meta( $event_id, 'etik_max_place', true ) );
    $status     = 'pending';
    if ( $max_places > 0 ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE event_id = %d AND status IN ('confirmed', 'pending')",
            $event_id
        ) );
        if ( $count >= $max_places ) {
            $status = 'waitlist';
        }
    }

    // ── 10. Utilisateur WP ──────────────────────────────────────────────
    $user_id = 0;
    if ( function_exists( __NAMESPACE__ . '\\wp_etik_get_or_create_user' ) ) {
        $user_id = wp_etik_get_or_create_user( $email, $first_name, $last_name );
        if ( is_wp_error( $user_id ) ) {
            $user_id = 0;
        }
    }

    // ── 11. Token de confirmation ───────────────────────────────────────
    $token         = wp_generate_password( 32, false, false );
    $token_expires = date( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) + 48 * 3600 );

    // ── 12. Chiffrement ─────────────────────────────────────────────────
    // Garder les valeurs en clair pour l'email de confirmation
    $email_clear      = $email;
    $first_name_clear = $first_name;
    $phone_clear      = $phone;

    $encrypted = lwe_encrypt_inscription_data( [
        'email'       => $email,
        'first_name'  => $first_name,
        'last_name'   => $last_name,
        'phone'       => $phone,
        'custom_data' => $custom_json,
    ] );

    // ── 13. Insertion ───────────────────────────────────────────────────
    $row_data = [
        'event_id'           => $event_id,
        'user_id'            => $user_id,
        'email'              => $encrypted['email'],
        'email_hash'         => $email_hash,
        'first_name'         => $encrypted['first_name'],
        'last_name'          => $encrypted['last_name'],
        'phone'              => $encrypted['phone'],
        'custom_data'        => $encrypted['custom_data'],
        'status'             => $status,
        'token'              => $token,
        'token_expires'      => $token_expires,
        'registered_at'      => current_time( 'mysql' ),
    ];

    $formats = [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

    $inserted = $wpdb->insert( $table, $row_data, $formats );
    if ( ! $inserted ) {
        error_log( '[WP-Etik] DB insert error: ' . $wpdb->last_error );
        wp_send_json_error( [ 'code' => 'db_error', 'message' => "Erreur lors de l'enregistrement." ], 500 );
    }

    $ins_id = (int) $wpdb->insert_id;

    // ── 14. Waitlist → pas de paiement ──────────────────────────────────
    if ( $status === 'waitlist' ) {
        wp_send_json_success( [
            'status'         => 'waitlist',
            'inscription_id' => $ins_id,
            'message'        => "L'événement est complet. Vous êtes sur liste d'attente.",
        ] );
        return;
    }

    // ── 15. Paiement requis ? ───────────────────────────────────────────
    $payment_required = get_post_meta( $event_id, '_etik_payment_required', true ) === '1';
    $price_raw        = get_post_meta( $event_id, 'etik_price', true );
    $price_cents      = intval( floatval( $price_raw ) * 100 );

    if ( ! $payment_required || $price_cents <= 0 ) {
        // Pas de paiement → email de confirmation
        $event_title = get_the_title( $event_id );

        if ( function_exists( __NAMESPACE__ . '\\wp_etik_send_confirmation_email_service_style' ) ) {
            wp_etik_send_confirmation_email_service_style( [
                'inscription_id'  => $ins_id,
                'event_id'        => $event_id,
                'token'           => $token,
                'first_name'      => $first_name_clear,
                'event_title'     => $event_title,
                'recipient_email' => $email_clear,
                'phone'           => $phone_clear,
            ] );
        }

        wp_send_json_success( [
            'status'         => 'pending',
            'inscription_id' => $ins_id,
            'message'        => 'Inscription enregistrée. Vérifiez votre e-mail pour confirmer.',
        ] );
        return;
    }

    // ── 16. Stripe ──────────────────────────────────────────────────────
    if ( ! class_exists( '\\WP_Etik\\Stripe\\Service' ) ) {
        wp_send_json_error( [ 'code' => 'stripe_unavailable', 'message' => 'Service de paiement non disponible.' ], 500 );
        return;
    }

    $event_title = get_the_title( $event_id );
    $return_url  = function_exists( __NAMESPACE__ . '\\wp_etik_get_payment_return_url' )
        ? wp_etik_get_payment_return_url()
        : home_url();
    $success_url = add_query_arg( 'status', 'success', $return_url );
    $cancel_url  = add_query_arg( 'status', 'cancel',  $return_url );

    $checkout = \WP_Etik\Stripe\Service::createCheckoutSession(
        $ins_id, $event_id, $price_cents, $success_url, $cancel_url
    );

    if ( ! $checkout || empty( $checkout['url'] ) ) {
        wp_send_json_error( [ 'code' => 'stripe_error', 'message' => 'Erreur lors de la création du paiement.' ], 502 );
        return;
    }

    $wpdb->update( $table, [ 'payment_session_id' => $checkout['id'] ], [ 'id' => $ins_id ], [ '%s' ], [ '%d' ] );

    wp_send_json_success( [
        'status'         => 'pending',
        'inscription_id' => $ins_id,
        'checkout_url'   => $checkout['url'],
    ] );
}