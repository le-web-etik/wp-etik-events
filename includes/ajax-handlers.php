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

//add_action( 'wp_ajax_nopriv_lwe_create_checkout',  __NAMESPACE__ . '\\lwe_create_checkout' );
//add_action( 'wp_ajax_lwe_create_checkout',  __NAMESPACE__ . '\\lwe_create_checkout' );

add_action( 'wp_ajax_lwe_test_webhook',  __NAMESPACE__ . '\\lwe_test_webhook' );


require_once WP_ETIK_PLUGIN_DIR . 'includes/ajax-handlers-functions.php';

require_once WP_ETIK_PLUGIN_DIR . 'includes/lwe-field-helpers.php';
require_once WP_ETIK_PLUGIN_DIR . 'includes/ajax-handler-checkout.php';


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
 * AJAX Handler — Créer une inscription + Checkout Session Stripe
 *
 * Champs POST attendus :
 *   - event_id       (int)
 *   - email          (string)
 *   - first_name     (string)
 *   - last_name      (string)
 *   - phone          (string, optionnel)
 *   - desired_domain (string, optionnel) — domaine souhaité par le client
 *   - has_domain     (0|1)              — le client a-t-il déjà un domaine
 *   - nonce          (string)
 *   - h-captcha-response (string)       — token hCaptcha
 *
 * Réponse JSON :
 *   success: true  → { status, inscription_id, checkout_url? }
 *   success: false → { code, message }
 */
/*
function lwe_create_checkout() {
 
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';
 
    // ── 1. Nonce ────────────────────────────────────────────────────────────
    $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'wp_etik_inscription_nonce' ) ) {
        wp_send_json_error( [
            'code'    => 'invalid_nonce',
            'message' => 'Requête invalide (nonce).',
        ], 400 );
    }
 
    // ── 2. Vérification hCaptcha ────────────────────────────────────────────
    $captcha_token = isset( $_POST['h-captcha-response'] )
        ? sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ) )
        : '';
 
    if ( ! \WP_Etik\verify_hcaptcha( $captcha_token, $hc_error ) ) {
        wp_send_json_error( [
            'code'    => 'captcha_failed',
            'message' => $hc_error ?: 'Vérification anti-robot échouée. Veuillez réessayer.',
        ], 400 );
    }
 
    // ── 3. Résolution dynamique des champs via Form_Manager ──────────────────
    // Le JS envoie form_id (ajouté comme hidden input au chargement du formulaire).
    // On utilise Form_Manager pour trouver les vraies clés par type,
    // quelle que soit la field_key définie dans le Form Builder.
    $event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
    if ( $event_id <= 0 ) {
        wp_send_json_error( [
            'code'    => 'missing_event',
            'message' => "ID de l'événement manquant ou invalide.",
        ], 400 );
    }
 
    $form_id   = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;
    $field_map = \WP_Etik\lwe_resolve_field_map( $form_id );
 
    $email      = \WP_Etik\lwe_get_post_field( $field_map['email'],      'email' );
    $first_name = \WP_Etik\lwe_get_post_field( $field_map['first_name'], 'text'  );
    $last_name  = \WP_Etik\lwe_get_post_field( $field_map['last_name'],  'text'  );
    $phone      = \WP_Etik\lwe_get_post_field( $field_map['phone'],      'tel'   );
 
    // Champs spécifiques à l'événement
    $desired_domain = isset( $_POST['desired_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['desired_domain'] ) ) : '';
    $has_domain     = isset( $_POST['has_domain'] ) ? ( intval( $_POST['has_domain'] ) ? 1 : 0 ) : 0;
 
    if ( ! is_email( $email ) ) {
        wp_send_json_error( [
            'code'    => 'invalid_email',
            'message' => 'Adresse e-mail invalide.',
        ], 400 );
    }
 
    if ( empty( $first_name ) || empty( $last_name ) ) {
        wp_send_json_error( [
            'code'    => 'missing_name',
            'message' => 'Prénom et nom sont obligatoires.',
        ], 400 );
    }
 
    // ── 4. Récupération de l'événement ───────────────────────────────────────
    $event = get_post( $event_id );
    if ( ! $event || $event->post_status !== 'publish' ) {
        wp_send_json_error( [
            'code'    => 'event_not_found',
            'message' => 'Événement introuvable.',
        ], 404 );
    }
 
    $event_title = get_the_title( $event_id );
    $price_cents = intval( get_post_meta( $event_id, 'etik_event_price', true ) ) * 100;
    $max_places  = intval( get_post_meta( $event_id, 'etik_event_max_places', true ) );
 
    // ── 5. Vérification des places disponibles ───────────────────────────────
    $confirmed_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status IN ('confirmed', 'pending')",
        $event_id
    ) );
 
    $status = 'pending';
    if ( $max_places > 0 && $confirmed_count >= $max_places ) {
        $status = 'waitlist';
    }
 
    // ── 6. Vérification doublon (même email + même événement non annulé) ─────
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE event_id = %d AND email = %s AND status NOT IN ('cancelled') LIMIT 1",
        $event_id,
        $email
    ) );
 
    if ( $existing ) {
        wp_send_json_error( [
            'code'    => 'already_registered',
            'message' => 'Cette adresse e-mail est déjà inscrite pour cet événement.',
        ], 409 );
    }
 
    // ── 7. Insertion de l'inscription ────────────────────────────────────────
    $inserted = $wpdb->insert(
        $table,
        [
            'event_id'   => $event_id,
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'phone'      => $phone,
            'status'     => $status,
            'created_at' => current_time( 'mysql' ),
        ],
        [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
    );
 
    if ( ! $inserted ) {
        error_log( '[WP-Etik] DB insert error: ' . $wpdb->last_error );
        wp_send_json_error( [
            'code'    => 'db_error',
            'message' => "Erreur lors de l'enregistrement. Veuillez réessayer.",
        ], 500 );
    }
 
    $ins_id = $wpdb->insert_id;
 
    // ── 8. Sauvegarde des champs spécifiques en meta ─────────────────────────
    // Ces données sont stockées en post_meta de l'inscription pour ne pas
    // alourdir la table principale et rester extensibles.
    if ( $desired_domain !== '' ) {
        update_post_meta( $ins_id, 'lwe_desired_domain', $desired_domain );
        // Remarque : si la table etik_inscriptions a des colonnes dédiées,
        // remplacer par un wpdb->update() sur ces colonnes.
    }
    update_post_meta( $ins_id, 'lwe_has_domain', $has_domain );
 
    // ── 9. Liste d'attente : pas de paiement, juste confirmation d'attente ───
    if ( $status === 'waitlist' ) {
        wp_send_json_success( [
            'status'         => 'waitlist',
            'inscription_id' => $ins_id,
            'message'        => "L'événement est complet. Vous avez été ajouté(e) à la liste d'attente.",
        ] );
    }
 
    // ── 10. Création de la session Stripe si paiement requis ─────────────────
    if ( $price_cents <= 0 ) {
        // Pas de paiement requis → inscription confirmée directement
        $wpdb->update(
            $table,
            [ 'status' => 'confirmed' ],
            [ 'id' => $ins_id ],
            [ '%s' ],
            [ '%d' ]
        );
        wp_send_json_success( [
            'status'         => 'confirmed',
            'inscription_id' => $ins_id,
            'message'        => 'Inscription confirmée.',
        ] );
    }
 
    // Appel Stripe via la façade centralisée
    if ( ! class_exists( '\\WP_Etik\\Stripe\\Service' ) ) {
        wp_send_json_error( [
            'code'    => 'stripe_unavailable',
            'message' => 'Service de paiement non disponible.',
        ], 500 );
    }
 
    $checkout = \WP_Etik\Stripe\Service::create_checkout_session( [
        'amount'         => $price_cents,
        'currency'       => 'eur',
        'product_name'   => $event_title,
        'customer_email' => $email,
        'metadata'       => [
            'inscription_id' => (string) $ins_id,
            'event_id'       => (string) $event_id,
        ],
        'success_url'    => home_url( '?status=success' ),
        'cancel_url'     => home_url( '?status=cancel' ),
    ] );
 
    if ( is_wp_error( $checkout ) ) {
        error_log( '[WP-Etik] Stripe session error: ' . $checkout->get_error_message() );
        wp_send_json_error( [
            'code'    => 'stripe_error',
            'message' => 'Erreur lors de la création du paiement : ' . $checkout->get_error_message(),
        ], 502 );
    }
 
    $session_id   = $checkout['id'] ?? '';
    $checkout_url = $checkout['url'] ?? '';
 
    if ( $session_id ) {
        $wpdb->update(
            $table,
            [ 'payment_session_id' => $session_id ],
            [ 'id' => $ins_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }
 
    if ( empty( $checkout_url ) ) {
        wp_send_json_error( [
            'code'    => 'stripe_no_url',
            'message' => 'Aucune URL de paiement retournée par Stripe.',
        ], 500 );
    }
 
    wp_send_json_success( [
        'status'         => 'pending',
        'inscription_id' => $ins_id,
        'checkout_url'   => $checkout_url,
    ] );
}
*/




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