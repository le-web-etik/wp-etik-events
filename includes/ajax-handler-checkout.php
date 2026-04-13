<?php
/**
 * includes/ajax-handler-checkout.php
 *
 * Handler AJAX principal : lwe_create_checkout
 * 
 * Flux sécurisé :
 * 1. Validation (Nonce, Captcha, Champs requis).
 * 2. Gestion Contact : Création/Mise à jour dans wp_etik_users (Données chiffrées).
 * 3. Inscription : Création ligne dans wp_etik_inscriptions (Lien via etik_user_id, PII nulles).
 * 4. Réponses Formulaire : Stockage JSON chiffré dans wp_etik_form_responses.
 * 5. Paiement : Création session Stripe si nécessaire.
 * 
 * @package WP_Etik
 */

namespace WP_Etik;

defined( 'ABSPATH' ) || exit;

// Chargement des helpers nécessaires
require_once WP_ETIK_PLUGIN_DIR . 'includes/lwe-field-helpers.php';
require_once WP_ETIK_PLUGIN_DIR . 'src/Etik_User_Manager.php';
require_once WP_ETIK_PLUGIN_DIR . 'src/Encryption.php';

// Enregistrement des hooks AJAX
add_action( 'wp_ajax_nopriv_lwe_create_checkout', __NAMESPACE__ . '\\lwe_create_checkout' );
add_action( 'wp_ajax_lwe_create_checkout',        __NAMESPACE__ . '\\lwe_create_checkout' );

/**
 * Fonction principale de traitement
 */
function lwe_create_checkout() : void {
    global $wpdb;
    
    $table_inscriptions = $wpdb->prefix . 'etik_inscriptions';
    $table_responses    = $wpdb->prefix . 'etik_form_responses';

    // =========================================================================
    // 1. SÉCURITÉ & VALIDATION
    // =========================================================================

    // Nonce
    $nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );
    if ( ! wp_verify_nonce( $nonce, 'wp_etik_inscription_nonce' ) ) {
        wp_send_json_error( [ 'code' => 'invalid_nonce', 'message' => 'Requête invalide (sécurité).' ], 400 );
    }

    // hCaptcha
    $captcha_token = sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ?? '' ) );
    $hc_error = '';
    if ( function_exists( __NAMESPACE__ . '\\verify_hcaptcha' ) && ! verify_hcaptcha( $captcha_token, $hc_error ) ) {
        wp_send_json_error( [ 'code' => 'captcha_failed', 'message' => $hc_error ?: 'Vérification anti-robot échouée.' ], 400 );
    }

    // Contexte (Event & Form)
    $event_id = intval( $_POST['event_id'] ?? 0 );
    if ( $event_id <= 0 ) {
        wp_send_json_error( [ 'code' => 'missing_event', 'message' => 'Événement introuvable.' ], 400 );
    }

    $form_id = intval( $_POST['form_id'] ?? 0 );
    // Fallback vers formulaire par défaut si non spécifié (optionnel)
    if ( $form_id <= 0 ) {
        $form_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}etik_forms WHERE is_default = 1 LIMIT 1" );
    }

    // =========================================================================
    // 2. RÉSOLUTION & VALIDATION DES CHAMPS
    // =========================================================================

    // Validation des champs requis définis dans le Form Builder
    $validation_error = lwe_validate_required_fields( $form_id );
    if ( $validation_error ) {
        wp_send_json_error( $validation_error, 400 );
    }

    // Mapping des champs dynamiques vers les colonnes standards
    $field_map = lwe_resolve_field_map( $form_id );

    $email      = lwe_get_post_field( $field_map['email'],      'email' );
    $first_name = lwe_get_post_field( $field_map['first_name'], 'text' );
    $last_name  = lwe_get_post_field( $field_map['last_name'],  'text' );
    $phone      = lwe_get_post_field( $field_map['phone'],      'tel' );

    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'code' => 'invalid_email', 'message' => 'Adresse e-mail invalide.' ], 400 );
    }

    if ( empty( $first_name ) ) {
        wp_send_json_error( [ 'code' => 'missing_name', 'message' => 'Le prénom est obligatoire.' ], 400 );
    }

    // =========================================================================
    // 3. GESTION DU CONTACT (wp_etik_users)
    // =========================================================================
    // C'est ici que la magie opère : on ne crée PAS de WP_User.
    // On utilise notre table chiffrée dédiée.

    $etik_user_id = Etik_User_Manager::find_or_create( [
        'email'      => $email,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'phone'      => $phone,
        // 'meta' => [...] // Optionnel : données supplémentaires
    ] );

    if ( ! $etik_user_id ) {
        wp_send_json_error( [ 'code' => 'contact_creation_failed', 'message' => 'Erreur lors de l\'enregistrement du contact.' ], 500 );
    }

    // =========================================================================
    // 4. VÉRIFICATION DOUBLON & PLACES
    // =========================================================================

    $email_hash = Etik_User_Manager::hash_email( $email );

    // Vérifier si déjà inscrit à cet événement (statut non annulé)
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table_inscriptions} 
         WHERE event_id = %d AND etik_user_id = %d AND status NOT IN ('cancelled') 
         LIMIT 1",
        $event_id, $etik_user_id
    ) );

    if ( $existing ) {
        wp_send_json_error( [ 'code' => 'already_registered', 'message' => 'Vous êtes déjà inscrit à cet événement.' ], 409 );
    }

    // Gestion de la liste d'attente (Waitlist)
    $max_places = intval( get_post_meta( $event_id, 'etik_max_place', true ) );
    $status     = 'pending';

    if ( $max_places > 0 ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_inscriptions} WHERE event_id = %d AND status IN ('confirmed', 'pending')",
            $event_id
        ) );
        if ( $count >= $max_places ) {
            $status = 'waitlist';
        }
    }

    // =========================================================================
    // 5. CRÉATION DE L'INSCRIPTION (wp_etik_inscriptions)
    // =========================================================================
    // IMPORTANT : On insère NULL dans les colonnes PII (email, nom, tel).
    // La seule référence est etik_user_id.

    $token         = wp_generate_password( 32, false, false );
    $token_expires = date( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) + 48 * 3600 );

    $row_data = [
        'event_id'           => $event_id,
        'etik_user_id'       => $etik_user_id,
        'user_id'            => 0,             // Obsolète
        'email'              => null,          // Vide (données dans wp_etik_users)
        'email_hash'         => null,          // Vide
        'first_name'         => null,          // Vide
        'last_name'          => null,          // Vide
        'phone'              => null,          // Vide
        'desired_domain'     => null,          // Optionnel, peut rester null
        'has_domain'         => 0,
        'status'             => $status,
        'token'              => $token,
        'token_expires'      => $token_expires,
        'registered_at'      => current_time( 'mysql' ),
        'custom_data'        => null,          // On n'utilise plus ce champ pour les PII
    ];

    $formats = [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

    $inserted = $wpdb->insert( $table_inscriptions, $row_data, $formats );

    if ( ! $inserted ) {
        error_log( '[WP-Etik] DB Insert Error: ' . $wpdb->last_error );
        wp_send_json_error( [ 'code' => 'db_error', 'message' => 'Erreur lors de l\'enregistrement en base de données.' ], 500 );
    }

    $inscription_id = (int) $wpdb->insert_id;

    // =========================================================================
    // 6. STOCKAGE DES RÉPONSES FORMULAIRE (JSON CHIFFRÉ)
    // =========================================================================
    // On crée un objet JSON complet : { questions: {...}, answers: {...}, meta: {...} }
    // Puis on le chiffre en un seul bloc.

    if ( $form_id > 0 && class_exists( 'WP_Etik\\Form_Manager' ) ) {
        $fields = Form_Manager::get_fields( $form_id );
        
        $answers = [];
        $questions_snapshot = [];

        foreach ( $fields as $field ) {
            $key   = $field['field_key'];
            $label = $field['label'];
            $type  = $field['type'];

            // Ignorer les champs HTML purs (pas de saisie utilisateur)
            if ( $type === 'html' ) continue;

            // Récupération de la valeur brute
            $value_raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
            
            // Gestion des tableaux (checkboxes multiples)
            if ( is_array( $value_raw ) ) {
                $value_raw = implode( ', ', $value_raw );
            }

            // Sanitization et stockage
            if ( $value_raw !== null && $value_raw !== '' ) {
                $answers[ $key ] = sanitize_text_field( $value_raw );
            }

            // Snapshot de la question (pour l'historique)
            $questions_snapshot[ $key ] = [
                'label' => $label,
                'type'  => $type,
            ];
        }

        // Construction du payload final
        $payload = [
            'version'   => '1.0',
            'form_id'   => $form_id,
            'questions' => $questions_snapshot,
            'answers'   => $answers,
        ];

        $json_string = wp_json_encode( $payload );

        // Chiffrement du bloc entier
        $encrypted_blob = '';
        try {
            $encrypted_blob = Encryption::encrypt( $json_string )['ciphertext'];
        } catch ( \Exception $e ) {
            error_log( '[WP-Etik] Encryption failed: ' . $e->getMessage() );
            // En cas d'échec de chiffrement, on bloque par sécurité
            wp_send_json_error( [ 'code' => 'encryption_error', 'message' => 'Erreur de sécurité critique.' ], 500 );
        }

        // Insertion dans la table de réponses
        if ( ! empty( $encrypted_blob ) ) {
            $wpdb->insert(
                $table_responses,
                [
                    'submission_id'   => $inscription_id,
                    'submission_type' => 'inscription',
                    'form_id'         => $form_id,
                    'form_snapshot'   => $encrypted_blob,
                    'created_at'      => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%d', '%s', '%s' ]
            );
        }
    }

    // =========================================================================
    // 7. GESTION LISTE D'ATTENTE
    // =========================================================================
    if ( $status === 'waitlist' ) {
        wp_send_json_success( [
            'status'         => 'waitlist',
            'inscription_id' => $inscription_id,
            'message'        => "L'événement est complet. Vous avez été ajouté(e) à la liste d'attente.",
        ] );
        return;
    }

    // =========================================================================
    // 8. GESTION PAIEMENT (STRIPE)
    // =========================================================================
    
    $payment_required = get_post_meta( $event_id, '_etik_payment_required', true ) === '1';
    $price_raw        = get_post_meta( $event_id, 'etik_price', true );
    $acompte_raw      = get_post_meta( $event_id, 'etik_acompte', true );
    
    $price_cents = intval( floatval( $price_raw ) * 100 );
    
    if ( $acompte_raw !== '' && $acompte_raw !== false && floatval( $acompte_raw ) > 0 ) {
        $price_cents = intval( floatval( $acompte_raw ) * 100 );
    }

    if ( ! $payment_required || $price_cents <= 0 ) {
        wp_send_json_success( [
            'status'         => 'confirmed',
            'inscription_id' => $inscription_id,
            'message'        => 'Inscription validée avec succès.',
        ] );
        return;
    }

    // --- Configuration Stripe ---
    $stripe_secret = '';
    
    if ( class_exists( '\\WP_Etik\\Admin\\Payments_Settings' ) ) {
        try {
            $ps = new \WP_Etik\Admin\Payments_Settings();
            $all = $ps->get_all_keys();
            $stripe_secret = $all['stripe']['secret'] ?? '';
        } catch ( \Throwable $e ) {
            error_log( '[WP-Etik] Payments_Settings error: ' . $e->getMessage() );
        }
    } elseif ( class_exists( '\\WP_Etik\\Admin\\Stripe_Settings' ) ) {
        $keys = \WP_Etik\Admin\Stripe_Settings::get_keys();
        $stripe_secret = $keys['secret'] ?? '';
    }

    if ( empty( $stripe_secret ) ) {
        wp_send_json_success( [
            'status'         => 'pending_payment_error',
            'inscription_id' => $inscription_id,
            'message'        => 'Paiement requis mais non configuré.',
        ] );
        return;
    }

    // --- Préparation Session Stripe ---
    $return_url  = function_exists( __NAMESPACE__ . '\\wp_etik_get_payment_return_url' ) 
                   ? wp_etik_get_payment_return_url() 
                   : home_url();
    
    $success_url = add_query_arg( 'status', 'success', $return_url );
    $cancel_url  = add_query_arg( 'status', 'cancel',  $return_url );
    $event_title = get_the_title( $event_id ) ?? 'Inscription événement';

    $body = [
        'payment_method_types[]' => 'card',
        'mode'                   => 'payment',
        'line_items[0][price_data][currency]' => 'eur',
        'line_items[0][price_data][product_data][name]' => substr( $event_title, 0, 60 ),
        'line_items[0][price_data][unit_amount]' => $price_cents,
        'line_items[0][quantity]' => 1,
        'customer_email'         => $email, 
        
        // MÉTADONNÉES CRITIQUES POUR LE WEBHOOK
        'metadata[inscription_id]' => (string) $inscription_id,
        'metadata[etik_user_id]'   => (string) $etik_user_id,
        'metadata[type]'           => 'inscription', // NOUVEAU : Distinction claire
        'metadata[event_id]'       => (string) $event_id, // Bonus : Pour logs
        
        'success_url'            => $success_url,
        'cancel_url'             => $cancel_url,
    ];

    $args = [
        'body'    => $body,
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'timeout' => 30,
    ];

    $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', $args );

    if ( is_wp_error( $response ) ) {
        error_log( '[WP-Etik] Stripe Network Error: ' . $response->get_error_message() );
        wp_send_json_error( [ 'code' => 'stripe_network', 'message' => 'Erreur de connexion à Stripe.' ], 502 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 && $code !== 201 ) {
        $error_msg = $data['error']['message'] ?? 'Erreur inconnue';
        error_log( '[WP-Etik] Stripe API Error (' . $code . '): ' . $error_msg );
        wp_send_json_error( [ 'code' => 'stripe_api', 'message' => 'Erreur Stripe : ' . $error_msg ], 502 );
    }

    $session_id   = $data['id'] ?? '';
    $checkout_url = $data['url'] ?? '';

    // Mise à jour de l'inscription avec l'ID de session
    if ( $session_id ) {
        $wpdb->update(
            $table_inscriptions,
            [ 'payment_session_id' => $session_id ],
            [ 'id' => $inscription_id ],
            [ '%s' ], [ '%d' ]
        );
    }

    if ( ! empty( $checkout_url ) ) {
        wp_send_json_success( [
            'status'         => 'pending_payment',
            'inscription_id' => $inscription_id,
            'checkout_url'   => $checkout_url,
        ] );
    }

    wp_send_json_error( [ 'code' => 'stripe_no_url', 'message' => 'Aucune URL de paiement générée.' ], 500 );
}