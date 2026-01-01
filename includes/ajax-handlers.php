<?php
/**
 * includes/ajax-handlers.php
 * Handlers AJAX et endpoint de confirmation pour WP_Etik
 *
 * - wp_etik_handle_inscription : gestion inscription (création utilisateur si besoin, insertion pending + token, envoi email confirmation)
 * - wp_etik_handle_login       : (optionnel) connexion par email/mot de passe si vous l'utilisez encore
 * - init handler pour confirmation via lien GET (wp_etik_action=confirm_inscription)
 *
 * Remarques :
 * - Utilise current_time('timestamp') pour gestion TTL (respecte timezone WP)
 * - Vérifie hCaptcha si option/constante configurée
 * - Logge erreurs SQL via error_log pour debug
 */

namespace WP_Etik;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_nopriv_wp_etik_handle_inscription', __NAMESPACE__ . '\\handle_inscription' );
add_action( 'wp_ajax_wp_etik_handle_inscription', __NAMESPACE__ . '\\handle_inscription' );

//add_action( 'wp_ajax_nopriv_wp_etik_handle_login', __NAMESPACE__ . '\\handle_login' );
//add_action( 'wp_ajax_wp_etik_handle_login', __NAMESPACE__ . '\\handle_login' );

add_action( 'init', __NAMESPACE__ . '\\maybe_process_confirmation' );

add_action( 'wp_ajax_nopriv_lwe_create_checkout', 'lwe_create_checkout' );
add_action( 'wp_ajax_lwe_create_checkout', 'lwe_create_checkout' );

add_action( 'wp_ajax_lwe_test_webhook', 'lwe_test_webhook' );


require_once WP_ETIK_PLUGIN_DIR . 'includes/ajax-handlers-functions.php';


/**
 * Handler inscription
 */
function handle_inscription() {
    global $wpdb;

    // nonce
    $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'wp_etik_inscription_nonce' ) ) {
        json_error( 'Requête invalide (nonce).' );
    }

    // optional hCaptcha
    $hcaptcha_token = isset( $_POST['h-captcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ) ) : '';
    if ( ! verify_hcaptcha( $hcaptcha_token, $hc_error ) ) {
        json_error( $hc_error ?: 'Erreur captcha.' );
    }

    // required fields
    $first_name     = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $email          = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone          = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $event_id       = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;

    if ( empty( $first_name ) || empty( $email ) || empty( $phone ) || empty( $event_id ) ) {
        json_error( 'Veuillez remplir tous les champs obligatoires (Prénom, E-mail, Téléphone, événement).' );
    }

    if ( ! is_email( $email ) ) {
        json_error( 'Adresse e-mail invalide.' );
    }

    // optional fields
    $last_name      = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $desired_domain = isset( $_POST['desired_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['desired_domain'] ) ) : '';
    $has_domain     = isset( $_POST['has_domain'] ) ? intval( $_POST['has_domain'] ) : 0;

    // find user by email
    $existing_user = get_user_by( 'email', $email );
    if ( $existing_user ) {
        $user_id = $existing_user->ID;
    } else {
        // generate login: sanitized first_name + 5 random chars, ensure uniqueness
        $base = preg_replace( '/[^a-z0-9]/', '', strtolower( $first_name ) );
        if ( empty( $base ) ) {
            $base = 'user';
        }
        // generate a candidate and ensure uniqueness
        $attempt = 0;
        do {
            $suffix = wp_generate_password( 5, false, false );
            $login   = $base . $suffix;
            $attempt++;
            if ( $attempt > 8 ) {
                $login = $base . time();
                break;
            }
        } while ( username_exists( $login ) );

        // generate random password 12..16 chars
        $pass_length = rand( 12, 16 );
        $password = wp_generate_password( $pass_length, true, true );

        $userdata = [
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( $first_name . ' ' . $last_name ),
        ];

        $user_id = wp_insert_user( $userdata );
        if ( is_wp_error( $user_id ) ) {
            error_log( '[WP_ETIK] user creation error: ' . $user_id->get_error_message() );
            json_error( 'Erreur lors de la création du compte utilisateur.' );
        }

        // optional: notify user of credentials (consider security implications)
        /*$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $subject = "Votre compte sur {$site_name}";
        $body    = "Bonjour " . $first_name . ",\n\n";
        $body   .= "Un compte a été créé pour vous sur {$site_name}.\n\n";
        $body   .= "Identifiant : {$login}\nMot de passe : {$password}\n\n";
        $body   .= "Vous pouvez vous connecter et modifier votre mot de passe depuis votre espace.\n\n";
        wp_mail( $email, $subject, $body );*/
    }

    // prepare inscription row
    $table = $wpdb->prefix . 'etik_inscriptions';

    // check for confirmed duplicate
    $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d AND user_id = %d AND status = %s LIMIT 1", $event_id, $user_id, 'confirmed' ) );
    if ( $existing_id ) {
        json_error( 'Vous êtes déjà inscrit à cette formation.' );
    }

    // if there is a pending entry for same event/user -> refresh token instead of inserting duplicate
    $pending_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE event_id = %d AND user_id = %d AND status = %s LIMIT 1", $event_id, $user_id, 'pending' ), ARRAY_A );

    // generate token & expiry (48 hours)
    $now_ts     = (int) current_time( 'timestamp' );
    $ttl_secs   = 48 * 3600;
    $expires_ts = $now_ts + $ttl_secs;
    $expires_mysql = date( 'Y-m-d H:i:s', $expires_ts );
    $token = wp_generate_password( 32, false, false );

    if ( $pending_row ) {
        // update token and timestamp
        $updated = $wpdb->update(
            $table,
            [
                'email'         => $email,
                'first_name'    => $first_name,
                'last_name'     => $last_name,
                'phone'         => $phone,
                'desired_domain'=> $desired_domain,
                'has_domain'    => $has_domain,
                'token'         => $token,
                'token_expires' => $expires_mysql,
                'registered_at' => current_time( 'mysql' ),
                'status'        => 'pending',
            ],
            [ 'id' => intval( $pending_row['id'] ) ],
            [ '%s','%s','%s','%s','%s','%d','%s','%s','%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            error_log( "[WP_ETIK] DB update failed: {$wpdb->last_error}" );
            json_error( 'Erreur lors de l\'enregistrement.' );
        }

        $inscription_id = intval( $pending_row['id'] );
    } else {
        // insert new row
        $insert_row = [
            'event_id'       => $event_id,
            'user_id'        => $user_id,
            'email'          => $email,
            'first_name'     => $first_name,
            'last_name'      => $last_name,
            'phone'          => $phone,
            'desired_domain' => $desired_domain,
            'has_domain'     => $has_domain,
            'status'         => 'pending',
            'token'          => $token,
            'token_expires'  => $expires_mysql,
            'registered_at'  => current_time( 'mysql' ),
        ];

        $format = [
            '%d', // event_id
            '%d', // user_id
            '%s', // email
            '%s', // first_name
            '%s', // last_name
            '%s', // phone
            '%s', // desired_domain
            '%d', // has_domain
            '%s', // status
            '%s', // token
            '%s', // token_expires
            '%s', // registered_at
        ];

        $inserted = $wpdb->insert( $table, $insert_row, $format );
        if ( $inserted === false ) {
            error_log( '[WP_ETIK] insert failed: ' . $wpdb->last_error );
            error_log( '[WP_ETIK] query: ' . $wpdb->last_query );
            json_error( 'Erreur lors de l\'enregistrement.' );
        }

        $inscription_id = (int) $wpdb->insert_id;
    }

    // send confirmation email
    $confirmation_url = add_query_arg( [
        'wp_etik_action' => 'confirm_inscription',
        'id'             => $inscription_id,
        'token'          => $token,
    ], home_url( '/' ) );

    /*$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $subject = "Confirmez votre inscription à la formation";
    $message = "Bonjour " . $first_name . ",\n\n";
    $message .= "Pour confirmer votre inscription à la formation, cliquez sur le lien suivant :\n\n";
    $message .= $confirmation_url . "\n\n";
    $message .= "Ce lien expirera dans 48 heures.\n\n";
    $message .= "Si vous n'avez pas demandé cette inscription, ignorez cet email.\n\n";

    wp_mail( $email, $subject, $message );*/

    $event_title = get_the_title($event_id);

    wp_etik_send_confirmation_email_service_style([
        'inscription_id'  => $inscription_id,
        'event_id'        => $event_id,
        'token'           => $token,
        'first_name'      => $first_name,
        'event_title'     => $event_title,
        'recipient_email' => $email,
        'from_name'       => 'Le Web Etik',
        'from_email'      => 'reservation@lewebetik.fr',
        'logo_url'        => 'https://lewebetik.fr/wp-content/uploads/2022/11/logo01.png',
        'hero_url'        => 'https://lewebetik.fr/wp-content/uploads/2025/11/bandeau_01-e1763731275968.png',
    ]);

    json_success( [ 'message' => 'Inscription enregistrée. Un email de confirmation vous a été envoyé.' ] );
}

/**
 * Confirmation endpoint called via GET link with id & token.
 * Example: https://example.com/?wp_etik_action=confirm_inscription&id=123&token=abcd
 */
function maybe_process_confirmation() {
    if ( empty( $_GET['wp_etik_action'] ) || $_GET['wp_etik_action'] !== 'confirm_inscription' ) {
        return;
    }

    $id    = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
    $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

    if ( ! $id || empty( $token ) ) {
        wp_die( 'Lien invalide.' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND token = %s LIMIT 1", $id, $token ), ARRAY_A );
    if ( ! $row ) {
        wp_die( 'Inscription introuvable ou token invalide.' );
    }

    // compare timestamps using WP timezone
    $now_ts = (int) current_time( 'timestamp' );
    $now_ts = (int) current_time( 'timestamp' );
    $expires_ts = 0;
    if ( ! empty( $row['token_expires'] ) ) {
        $expires_ts = strtotime( $row['token_expires'] );
    }

    if ( $expires_ts && $expires_ts < $now_ts ) {
        wp_die( 'Le lien a expiré.' );
    }

    // mark as confirmed
    $updated = $wpdb->update(
        $table,
        [
            'status'        => 'confirmed',
            'token'         => null,
            'token_expires' => null,
        ],
        [ 'id' => $id ],
        [ '%s','%s','%s' ],
        [ '%d' ]
    );

    if ( $updated === false ) {
        error_log( '[WP_ETIK] confirmation update failed: ' . $wpdb->last_error );
        wp_die( 'Erreur lors de la confirmation.' );
    }

    // Option: redirect to a thank-you page if exists
    $thank_you = home_url( '/inscription-confirme/' );
    wp_safe_redirect( $thank_you );
    exit;
}


/**
 * AJAX handler : créer une inscription + Checkout Session Stripe si place disponible.
 *
 * Reçoit POST:
 *  - event_id (int)
 *  - email, first_name, last_name, phone (optionnel)
 *
 * Retour JSON:
 *  - success: true/false
 *  - data: { status: 'pending'|'waitlist', inscription_id: int, checkout_url?: string }
 */
function lwe_create_checkout() {
    // nonce
    if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'lwe_nonce' ) ) {
        wp_send_json_error( [ 'code' => 'invalid_nonce' ], 400 );
    }

    global $wpdb;

    $event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
    if ( $event_id <= 0 ) {
        wp_send_json_error( [ 'code' => 'missing_event' ], 400 );
    }

    // sanitize inputs
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

    // table
    $table = $wpdb->prefix . 'etik_inscriptions';

    // capacity from CPT meta
    $max_place = intval( get_post_meta( $event_id, 'etik_max_place', true ) );
    if ( $max_place <= 0 ) {
        $max_place = PHP_INT_MAX;
    }

    // count confirmed inscriptions for this event
    $confirmed_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = %s",
        $event_id, 'confirmed'
    ) );

    // if full -> create waitlist entry
    if ( $confirmed_count >= $max_place ) {
        $inserted = $wpdb->insert(
            $table,
            [
                'event_id'      => $event_id,
                'user_id'       => 0,
                'email'         => $email,
                'first_name'    => $first_name,
                'last_name'     => $last_name,
                'phone'         => $phone,
                'status'        => 'waitlist',
                'registered_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $inserted ) {
            $ins_id = (int) $wpdb->insert_id;
            wp_send_json_success( [
                'status' => 'waitlist',
                'inscription_id' => $ins_id,
                'message' => __( 'L\'événement est complet. Vous êtes inscrit sur la liste d\'attente.', 'wp-etik-events' )
            ] );
        } else {
            wp_send_json_error( [ 'code' => 'db_error' ], 500 );
        }
    }

    // place available -> create pending inscription
    $amount = 10000; // 100 € en cents (modifier si nécessaire)
    $now = current_time( 'mysql' );

    $inserted = $wpdb->insert(
        $table,
        [
            'event_id'      => $event_id,
            'user_id'       => 0,
            'email'         => $email,
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'phone'         => $phone,
            'status'        => 'pending',
            'amount'        => $amount,
            'reserved_at'   => $now,
            'registered_at' => $now,
        ],
        [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
    );

    if ( ! $inserted ) {
        wp_send_json_error( [ 'code' => 'db_error' ], 500 );
    }

    $ins_id = (int) $wpdb->insert_id;

    // Récupérer clés Stripe via la classe admin (adapter le nom si nécessaire)
    if ( ! class_exists( '\\WP_Etik\\Admin\\Stripe_Settings' ) ) {
        // pas de settings class : informer et garder la réservation pending
        wp_send_json_success( [
            'status' => 'pending',
            'inscription_id' => $ins_id,
            'message' => __( 'Réservation enregistrée. Le paiement Stripe n\'est pas configuré.', 'wp-etik-events' )
        ] );
    }

    $keys = \WP_Etik\Admin\Stripe_Settings::get_keys();
    $secret = $keys['secret'] ?? '';
    if ( empty( $secret ) ) {
        wp_send_json_success( [
            'status' => 'pending',
            'inscription_id' => $ins_id,
            'message' => __( 'Réservation enregistrée. Le paiement Stripe n\'est pas configuré.', 'wp-etik-events' )
        ] );
    }

    // success / cancel URLs (customize as needed)
    $success_url = add_query_arg( [ 'lwe_ins' => $ins_id, 'status' => 'success' ], home_url( '/' ) );
    $cancel_url  = add_query_arg( [ 'lwe_ins' => $ins_id, 'status' => 'cancel' ], home_url( '/' ) );

    $body = [
        'payment_method_types[]' => 'card',
        'mode' => 'payment',
        'line_items[0][price_data][currency]' => 'eur',
        'line_items[0][price_data][product_data][name]' => 'Acompte réservation',
        'line_items[0][price_data][unit_amount]' => $amount,
        'line_items[0][quantity]' => 1,
        'metadata[inscription_id]' => (string) $ins_id,
        'metadata[event_id]' => (string) $event_id,
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
    ];

    $args = [
        'body' => $body,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'timeout' => 20,
    ];

    $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', $args );

    if ( is_wp_error( $response ) ) {
        // erreur réseau : laisser pending et informer
        // optionnel : logger en debug
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log( 'Stripe request error: ' . $response->get_error_message() );
        }
        wp_send_json_error( [ 'code' => 'stripe_network_error' ], 502 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $resp_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $resp_body, true );

    if ( $code !== 200 && $code !== 201 ) {
        // erreur Stripe : annuler la réservation pending ou la laisser selon ta politique
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log( 'Stripe API error: ' . print_r( $data, true ) );
        }
        // garder pending mais informer le client
        wp_send_json_error( [ 'code' => 'stripe_api_error', 'message' => $data['error']['message'] ?? 'Stripe error' ], 502 );
    }

    // récupérer id et url de la session
    $session_id = $data['id'] ?? '';
    $checkout_url = $data['url'] ?? '';

    if ( $session_id ) {
        // stocker payment_session_id
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
            'status' => 'pending',
            'inscription_id' => $ins_id,
            'checkout_url' => $checkout_url
        ] );
    }

    // fallback
    wp_send_json_error( [ 'code' => 'stripe_no_url' ], 500 );
}

/**
 * AJAX Handler - Test configuration des webhooks
 */
function lwe_test_webhook() {
if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Access denied' ], 403 );
    }

    // Vérifier nonce si envoyé
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'lwe_test_webhook_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce' ], 400 );
    }

    $gateway = isset( $_POST['gateway'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway'] ) ) : '';
    if ( ! in_array( $gateway, [ 'stripe', 'mollie' ], true ) ) {
        wp_send_json_error( [ 'message' => 'Unknown gateway' ], 400 );
    }

    // Récupérer les clés via Payments_Settings
    if ( ! class_exists( '\\WP_Etik\\Admin\\Payments_Settings' ) ) {
        wp_send_json_error( [ 'message' => 'Payments_Settings not available' ], 500 );
    }

    try {
        $ps = new \WP_Etik\Admin\Payments_Settings();
        $all = $ps->get_all_keys();
    } catch ( \Throwable $e ) {
        wp_send_json_error( [ 'message' => 'Failed to get keys: ' . $e->getMessage() ], 500 );
    }

    if ( $gateway === 'stripe' ) {
        $keys = $all['stripe'] ?? [];
        $secret = $keys['secret'] ?? '';
        $expected_url = rest_url( 'lwe/v1/stripe-webhook' );

        if ( ! class_exists( '\\WP_Etik\\Admin\\Payments\\Webhook_Checker' ) ) {
            wp_send_json_error( [ 'message' => 'Webhook_Checker missing' ], 500 );
        }

        $res = \WP_Etik\Admin\Payments\Webhook_Checker::check_stripe_webhook( $secret, $expected_url );
        // Optionnel : stocker le résultat
        update_option( 'lwe_stripe_webhook_check', array_merge( $res, [ 'time' => time() ] ) );
        wp_send_json_success( $res );
    }

    if ( $gateway === 'mollie' ) {
        $keys = $all['mollie'] ?? [];
        $apikey = $keys['apikey'] ?? '';
        $expected_url = rest_url( 'lwe/v1/mollie-webhook' );

        if ( ! class_exists( '\\WP_Etik\\Admin\\Payments\\Webhook_Checker' ) ) {
            wp_send_json_error( [ 'message' => 'Webhook_Checker missing' ], 500 );
        }

        $res = \WP_Etik\Admin\Payments\Webhook_Checker::check_mollie_webhook( $apikey, $expected_url );
        update_option( 'lwe_mollie_webhook_check', array_merge( $res, [ 'time' => time() ] ) );
        wp_send_json_success( $res );
    }

    wp_send_json_error( [ 'message' => 'Unhandled case' ], 500 );
}