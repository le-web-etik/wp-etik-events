<?php
/**
 * includes/ajax-handler-checkout.php
 *
 * Handler AJAX : lwe_create_checkout
 * Version Hybride : Logique dynamique + Appel API Stripe direct (sans SDK)
 */

namespace WP_Etik;

defined( 'ABSPATH' ) || exit;

require_once WP_ETIK_PLUGIN_DIR . 'includes/lwe-field-helpers.php';

add_action( 'wp_ajax_nopriv_lwe_create_checkout', __NAMESPACE__ . '\\lwe_create_checkout' );
add_action( 'wp_ajax_lwe_create_checkout',        __NAMESPACE__ . '\\lwe_create_checkout' );

function lwe_create_checkout() : void {
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    // ── 1. Nonce & Sécurité ─────────────────────────────────────────────────
    $nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );
    if ( ! wp_verify_nonce( $nonce, 'wp_etik_inscription_nonce' ) ) {
        wp_send_json_error( [ 'code' => 'invalid_nonce', 'message' => 'Requête invalide.' ], 400 );
    }

    // ── 2. hCaptcha ─────────────────────────────────────────────────────────
    $captcha_token = sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ?? '' ) );
    $hc_error = '';
    if ( function_exists( __NAMESPACE__ . '\\verify_hcaptcha' ) && ! verify_hcaptcha( $captcha_token, $hc_error ) ) {
        wp_send_json_error( [ 'code' => 'captcha_failed', 'message' => $hc_error ?: 'Captcha échoué.' ], 400 );
    }

    // ── 3. Event & Formulaire ───────────────────────────────────────────────
    $event_id = intval( $_POST['event_id'] ?? 0 );
    if ( $event_id <= 0 ) {
        wp_send_json_error( [ 'code' => 'missing_event', 'message' => 'Événement manquant.' ], 400 );
    }
    
    $form_id = intval( $_POST['form_id'] ?? 0 );

    // ── 4. Résolution des champs dynamiques ─────────────────────────────────
    $field_map = lwe_resolve_field_map( $form_id );

    $email      = lwe_get_post_field( $field_map['email'],      'email' );
    $first_name = lwe_get_post_field( $field_map['first_name'], 'text' );
    $last_name  = lwe_get_post_field( $field_map['last_name'],  'text' );
    $phone      = lwe_get_post_field( $field_map['phone'],      'tel' );

    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'code' => 'invalid_email', 'message' => 'Email invalide.' ], 400 );
    }

    // ── 5. Champs custom & Chiffrement ──────────────────────────────────────
    $mapped_keys = array_filter( array_values( $field_map ) );
    $custom      = lwe_collect_custom_fields( $mapped_keys, $form_id );
    $custom_json = ! empty( $custom ) ? wp_json_encode( $custom ) : '';
    $email_hash  = lwe_email_search_hash( $email );

    // ── 6. Vérification Doublon ─────────────────────────────────────────────
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE event_id = %d AND email_hash = %s AND status NOT IN ('cancelled') LIMIT 1",
        $event_id, $email_hash
    ) );
    if ( $existing ) {
        wp_send_json_error( [ 'code' => 'already_registered', 'message' => 'Déjà inscrit.' ], 409 );
    }

    // ── 7. Gestion Places (Waitlist) ────────────────────────────────────────
    $max_places = intval( get_post_meta( $event_id, 'etik_max_place', true ) );
    $status     = 'pending';
    
    if ( $max_places > 0 ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status IN ('confirmed', 'pending')",
            $event_id
        ) );
        if ( $count >= $max_places ) {
            $status = 'waitlist';
        }
    }

    // ── 8. Utilisateur & Inscription ────────────────────────────────────────
    $user_id = 0;
    if ( function_exists( __NAMESPACE__ . '\\wp_etik_get_or_create_user' ) ) {
        $uid = wp_etik_get_or_create_user( $email, $first_name, $last_name );
        if ( ! is_wp_error( $uid ) ) $user_id = $uid;
    }

    $token         = wp_generate_password( 32, false, false );
    $token_expires = date( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) + 48 * 3600 );

    // Chiffrement des données
    $encrypted = lwe_encrypt_inscription_data( [
        'email'       => $email,
        'first_name'  => $first_name,
        'last_name'   => $last_name,
        'phone'       => $phone,
        'custom_data' => $custom_json,
    ] );

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

    $inserted = $wpdb->insert( $table, $row_data, [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
    
    if ( ! $inserted ) {
        error_log( '[WP-Etik] DB Insert Error: ' . $wpdb->last_error );
        wp_send_json_error( [ 'code' => 'db_error', 'message' => 'Erreur DB.' ], 500 );
    }

    $ins_id = (int) $wpdb->insert_id;

    // Si Waitlist → Fin ici
    if ( $status === 'waitlist' ) {
        wp_send_json_success( [
            'status'         => 'waitlist',
            'inscription_id' => $ins_id,
            'message'        => "Liste d'attente.",
        ] );
        return;
    }

    // ── 9. Vérification Paiement ────────────────────────────────────────────
    $payment_required = get_post_meta( $event_id, '_etik_payment_required', true ) === '1';
    $price_raw        = get_post_meta( $event_id, 'etik_price', true );
    $acompte_raw        = get_post_meta( $event_id, 'etik_acompte', true );
    
    $price_cents      = intval( floatval( $price_raw ) * 100 );
    $acompte_cents      = intval( floatval( $acompte_raw ) * 100 );

    if ( ! $payment_required || $price_cents <= 0 ) {
        // Pas de paiement
        wp_send_json_success( [
            'status'         => 'pending',
            'inscription_id' => $ins_id,
            'message'        => 'Inscription validée.',
        ] );
        return;
    }

    if ( $acompte_cents >= 0 ) {
        $price_cents = $acompte_cents;
    }

    // ── 10. CRÉATION SESSION STRIPE (Méthode wp_remote_post - Robuste) ─────
    
    // Charger la classe de configuration Stripe
    $stripe_settings_file = WP_ETIK_PLUGIN_DIR . 'includes/admin/Stripe_Settings.php';
    if ( ! file_exists( $stripe_settings_file ) ) {
        // Essayer l'autre chemin possible
        $stripe_settings_file = WP_ETIK_PLUGIN_DIR . 'src/Admin/Stripe_Settings.php'; 
    }

    if ( file_exists( $stripe_settings_file ) ) {
        require_once $stripe_settings_file;
    }

    // Vérifier si la classe existe (Ancienne méthode ou Nouvelle)
    $keys = [];
    if ( class_exists( '\\WP_Etik\\Admin\\Stripe_Settings' ) ) {
        $keys = \WP_Etik\Admin\Stripe_Settings::get_keys();
    } elseif ( class_exists( '\\WP_Etik\\Admin\\Payments_Settings' ) ) {
        try {
            $ps = new \WP_Etik\Admin\Payments_Settings();
            $all = $ps->get_all_keys();
            $keys = $all['stripe'] ?? [];
        } catch ( \Throwable $e ) {
            error_log( 'ETIK: Payments_Settings error: ' . $e->getMessage() );
        }
    }

    $secret = $keys['secret'] ?? '';

    if ( empty( $secret ) ) {
        error_log( '[WP-Etik] Stripe Secret Key missing.' );
        wp_send_json_success( [
            'status'         => 'pending',
            'inscription_id' => $ins_id,
            'message'        => 'Paiement non configuré (Clé secrète manquante).',
        ] );
        return;
    }

    $return_url  = function_exists( __NAMESPACE__ . '\\wp_etik_get_payment_return_url' )
        ? wp_etik_get_payment_return_url()
        : home_url();
    
    $success_url = add_query_arg( 'status', 'success', $return_url );
    $cancel_url  = add_query_arg( 'status', 'cancel',  $return_url );
    $event_title = get_the_title( $event_id ) ?? 'Inscription événement';

    // Préparation de la requête HTTP directe (comme dans l'ancienne version)
    $body = [
        'payment_method_types[]' => 'card',
        'mode'                   => 'payment',
        'line_items[0][price_data][currency]' => 'eur',
        'line_items[0][price_data][product_data][name]' => substr( $event_title, 0, 60 ),
        'line_items[0][price_data][unit_amount]' => $price_cents,
        'line_items[0][quantity]' => 1,
        'customer_email'         => $email,
        'metadata[inscription_id]' => (string) $ins_id,
        'metadata[event_id]'       => (string) $event_id,
        'success_url'            => $success_url,
        'cancel_url'             => $cancel_url,
    ];

    $args = [
        'body'    => $body,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'timeout' => 30,
    ];

    $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', $args );

    if ( is_wp_error( $response ) ) {
        error_log( '[WP-Etik] Stripe Network Error: ' . $response->get_error_message() );
        wp_send_json_error( [
            'code'    => 'stripe_network_error',
            'message' => 'Erreur de connexion à Stripe.',
        ], 502 );
    }

    $code      = wp_remote_retrieve_response_code( $response );
    $resp_body = wp_remote_retrieve_body( $response );
    $data      = json_decode( $resp_body, true );

    if ( $code !== 200 && $code !== 201 ) {
        $error_msg = $data['error']['message'] ?? 'Erreur inconnue';
        error_log( '[WP-Etik] Stripe API Error (' . $code . '): ' . $error_msg );
        wp_send_json_error( [
            'code'    => 'stripe_api_error',
            'message' => 'Erreur Stripe : ' . $error_msg,
        ], 502 );
    }

    $session_id   = $data['id'] ?? '';
    $checkout_url = $data['url'] ?? '';

    if ( $session_id ) {
        $wpdb->update(
            $table,
            [ 'payment_session_id' => $session_id ],
            [ 'id' => $ins_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    if ( ! empty( $checkout_url ) ) {
        wp_send_json_success( [
            'status'         => 'pending',
            'inscription_id' => $ins_id,
            'checkout_url'   => $checkout_url,
        ] );
    }

    wp_send_json_error( [
        'code'    => 'stripe_no_url',
        'message' => 'Aucune URL de paiement.'
    ], 500 );
}