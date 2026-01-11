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

/**
 * Helper: send JSON error and exit
 */
function json_error( $message, $extra = [] ) {
    $payload = array_merge( [ 'message' => $message ], (array) $extra );
    wp_send_json_error( $payload );
}

/**
 * Helper: send JSON success and exit
 */
function json_success( $data = [] ) {
    wp_send_json_success( $data );
}

/**
 * Verify hCaptcha server-side if secret configured.
 * Returns true if OK, or false on failure (and optionally sets $error_msg).
 */
function verify_hcaptcha( $token, &$error_msg = '' ) {
    $secret = defined( 'WP_ETIK_HCAPTCHA_SECRET' ) ? WP_ETIK_HCAPTCHA_SECRET : get_option( 'wp_etik_hcaptcha_secret', '' );
    if ( empty( $secret ) ) {
        return true; // not configured -> skip
    }

    if ( empty( $token ) ) {
        $error_msg = 'Captcha manquant.';
        return false;
    }

    $resp = wp_remote_post( 'https://hcaptcha.com/siteverify', [
        'timeout' => 10,
        'body'    => [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
    ] );

    if ( is_wp_error( $resp ) ) {
        $error_msg = 'Erreur vérification captcha.';
        error_log( '[WP_ETIK] hCaptcha request error: ' . $resp->get_error_message() );
        return false;
    }

    $body = wp_remote_retrieve_body( $resp );
    $data = json_decode( $body, true );
    if ( empty( $data ) || empty( $data['success'] ) ) {
        $error_msg = 'Échec du captcha.';
        return false;
    }

    return true;
}

/**
 * Envoie un email de confirmation stylé, inspiré d'une page service "création site web"
 *
 * Attendu dans $args :
 *  - int    $inscription_id
 *  - string $token
 *  - string $first_name
 *  - string $event_title
 *  - string $recipient_email
 *  - string $from_name (optionnel)
 *  - string $from_email (optionnel)
 *  - string $logo_url (optionnel)
 *  - string $hero_url (optionnel)
 */
function wp_etik_send_confirmation_email_service_style( array $args ) {
    // required
    $inscription_id  = (int) ( $args['inscription_id'] ?? 0 );
    $event_id        = (int) ( $args['event_id'] ?? 0 );
    $token           = $args['token'] ?? '';
    $first_name      = sanitize_text_field( $args['first_name'] ?? '' );
    $event_title     = sanitize_text_field( $args['event_title'] ?? '' );
    $recipient_email = sanitize_email( $args['recipient_email'] ?? '' );

    // defaults / optionals
    $from_name = sanitize_text_field( $args['from_name'] ?? get_bloginfo( 'name' ) );
    $from_mail = sanitize_email( $args['from_email'] ?? get_bloginfo( 'admin_email' ) );
    $logo_url  = esc_url_raw( $args['logo_url'] ?? 'https://lewebetik.fr/wp-content/uploads/logo.png' );
    $hero_url  = esc_url_raw( $args['hero_url'] ?? 'https://lewebetik.fr/wp-content/uploads/hero_service.jpg' );

    if ( ! $inscription_id || empty( $token ) || ! is_email( $recipient_email ) ) {
        return new WP_Error( 'invalid_args', 'Paramètres manquants ou email invalide' );
    }

    // Récupérer la date de l'événement si fournie dans $args ou via meta (fallback)
    $event_date = '';
    if ( ! empty( $event_id ) ) {
        $meta_date = get_post_meta( $event_id, 'etik_start_date', true );
        if ( $meta_date ) {
            $event_date = sanitize_text_field( $meta_date );
        }
    }

    // confirmation URL (encode token)
    $confirmation_url = add_query_arg( [
        'wp_etik_action' => 'confirm_inscription',
        'id'             => $inscription_id,
        'token'          => rawurlencode( $token ),
    ], home_url( '/' ) );

    // color palette inspired by a service page (contrast, CTA)
    $brand_light   = '#f0fbf7';
    $brand_primary = '#2aa78a';
    $brand_accent  = '#ff9f1c';
    $text_dark     = '#102027';
    $muted         = '#5f6a6a';
    $card_radius   = '12px';

    // HTML content (inline styles) — inclut la date si disponible
    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
    $html .= '<body style="margin:0;padding:28px;background:' . esc_attr( $brand_light ) . ';font-family:Arial,Helvetica,sans-serif;color:' . esc_attr( $text_dark ) . ';">';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center">';
    $html .= '<table width="680" cellpadding="0" cellspacing="0" role="presentation" style="background:#fff;border-radius:' . $card_radius . ';overflow:hidden;box-shadow:0 10px 30px rgba(16,32,39,0.06)">';
    // header
    $html .= '<tr><td style="padding:18px 22px;background:#fff;">';
    $html .= '<table width="100%"><tr><td><a href="' . esc_url( site_url() ) . '">';
    $html .= '<img src="' . esc_url( $logo_url ) . '" alt="" width="100" style="display:block;border:0;"></a></td>';
    $html .= '<td align="right" style="color:' . esc_attr( $muted ) . '"><a href="' . esc_url( site_url() ) . '" style="text-decoration: none;">';
    $html .= '<span style="font-size:18px;font-weight:700;">' . esc_html( $from_name ) . '</span></a></td></tr></table></td></tr>';
    // hero
    $html .= '<tr><td style="padding:0;"><img src="' . esc_url( $hero_url ) . '" alt="" width="680" style="display:block;width:100%;height:auto;"></td></tr>';
    // main
    $html .= '<tr><td style="padding:22px 28px;">';
    $html .= '<h2 style="margin:0 0 8px;font-size:20px;color:' . esc_attr( $text_dark ) . '">Confirmez votre inscription</h2>';
    $html .= '<p style="margin:0 0 16px;color:' . esc_attr( $muted ) . ';font-size:14px;line-height:1.5;">Bonjour <strong>' . esc_html( $first_name ) . '</strong>, merci pour votre intérêt pour <strong>' . esc_html( $event_title ) . '</strong>.';
    if ( $event_date ) {
        $html .= ' La session est prévue le <strong>' . esc_html( $event_date ) . '</strong>.';
    }
    $html .= ' Pour finaliser votre inscription, cliquez sur le bouton ci‑dessous.</p>';

    // CTA
    $html .= '<p style="margin:18px 0;"><a href="' . esc_url( $confirmation_url ) . '" target="_blank" style="background:' . esc_attr( $brand_primary ) . ';color:#fff;padding:14px 22px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block;">Confirmer mon inscription</a></p>';

    // features block
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:18px;">';
    $html .= '<tr><td style="width:36%;vertical-align:top;padding-right:12px;"><div style="padding:12px;border-radius:10px;background:linear-gradient(180deg,rgba(42,167,138,0.06),rgba(255,159,28,0.02));font-weight:700;color:' . esc_attr( $brand_primary ) . '">Je vous re contact trés rapidement.</div></td>';
    $html .= '<td style="vertical-align:top;color:' . esc_attr( $muted ) . ';font-size:13px;">';
    $html .= '<ul style="margin:8px 0 0 16px;padding:0;color:' . esc_attr( $muted ) . ';">';
    $html .= '<li>Préparer vos textes et images</li>';
    $html .= '</ul></td></tr></table>';

    //  expiry
    $html .= '<p style="margin:18px 0 0;color:' . esc_attr( $muted ) . ';font-size:13px;">Le lien expirera dans <strong>24 heures</strong>. Si le bouton ne fonctionne pas, copiez-collez ce lien :</p>';
    $html .= '<p style="word-break:break-all;color:' . esc_attr( $brand_accent ) . ';"><a href="' . esc_url( $confirmation_url ) . '" style="color:' . esc_attr( $brand_accent ) . ';">' . esc_html( $confirmation_url ) . '</a></p>';
    $html .= '</td></tr>';

    // footer
    $html .= '<tr><td style="padding:18px 28px;background:' . esc_attr( $brand_light ) . ';font-size:13px;color:' . esc_attr( $muted ) . ';">';
    $html .= '<table width="100%"><tr><td>Vous pouvez nous contacter à <a href="mailto:' . esc_attr( $from_mail ) . '" style="color:' . esc_attr( $brand_primary ) . ';">' . esc_html( $from_mail ) . '</a></td>';
    $html .= '<td align="right"><a href="' . esc_url( home_url( '/contact/' ) ) . '" style="color:' . esc_attr( $brand_primary ) . '">Contact</a></td></tr></table></td></tr>';
    $html .= '</table></td></tr></table></body></html>';

    // plain text fallback (inclut date si dispo)
    $plain = "Bonjour " . $first_name . ",\n\n";
    $plain .= "Merci pour votre inscription à \"" . $event_title . "\".\n";
    if ( $event_date ) {
        $plain .= "Date de la session : " . $event_date . "\n";
    }
    $plain .= "Pour confirmer votre inscription (valable 24h) :\n" . $confirmation_url . "\n\n";
    $plain .= "Vous recevrez ensuite les informations pratiques.\n\n";
    $plain .= "Contact : " . $from_mail . "\n" . home_url();

    // headers
    $headers = [];
    $headers[] = 'From: ' . wp_specialchars_decode( $from_name ) . ' <' . sanitize_email( $from_mail ) . '>';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    // subject
    $subject = 'Confirmez votre inscription — ' . wp_strip_all_tags( $event_title );

    // send participant email (HTML)
    $sent_to_participant = wp_mail( $recipient_email, $subject, $html, $headers );

    // fallback to plain for participant if HTML failed
    if ( ! $sent_to_participant ) {
        $sent_to_participant = wp_mail( $recipient_email, $subject, $plain, [ 'From: ' . wp_specialchars_decode( $from_name ) . ' <' . sanitize_email( $from_mail ) . '>' ] );
    }

    // ---------------------------------------------------------------------------
    // --- Notification admin (simple email)
    $admin_to = 'reservation@lewebetik.fr';
    $admin_subject = 'Nouvelle réservation — ' . wp_strip_all_tags( $event_title );
    $admin_lines = [];
    $admin_lines[] = 'Nouvelle réservation enregistrée';
    $admin_lines[] = 'Inscription ID: ' . $inscription_id;
    $admin_lines[] = 'Événement: ' . $event_title;
    if ( ! empty( $event_date ) ) {
        $admin_lines[] = 'Date de l\'événement: ' . $event_date;
    }
    $admin_lines[] = 'Nom: ' . $first_name;
    $admin_lines[] = 'Email: ' . $recipient_email;
    if ( ! empty( $args['phone'] ) ) {
        $admin_lines[] = 'Téléphone: ' . sanitize_text_field( $args['phone'] );
    }
    $admin_message = implode( "\n", $admin_lines );

    // en-têtes simples pour admin (plain text)
    $admin_headers = [ 'From: ' . wp_specialchars_decode( $from_name ) . ' <' . sanitize_email( $from_mail ) . '>' ];

    // envoi admin
    $sent_to_admin = wp_mail( $admin_to, $admin_subject, $admin_message, $admin_headers );

    // retourne tableau avec le statut des deux envois
    return [
        'participant_sent' => (bool) $sent_to_participant,
        'admin_sent'       => (bool) $sent_to_admin,
    ];
}

/**
 * Crée ou récupère un utilisateur par email.
 * Retourne l'ID utilisateur, ou WP_Error en cas d'erreur.
 *
 * @param string $email
 * @param string $first_name
 * @param string $last_name
 * @return int|WP_Error
 */
function wp_etik_get_or_create_user( $email, $first_name, $last_name = '' ) {
    // Vérifier si l'utilisateur existe déjà
    $existing_user = get_user_by( 'email', $email );
    if ( $existing_user ) {
        return (int) $existing_user->ID;
    }

    // Générer un login unique
    $base = preg_replace( '/[^a-z0-9]/', '', strtolower( $first_name ) );
    if ( empty( $base ) ) {
        $base = 'user';
    }

    $attempt = 0;
    do {
        $suffix = wp_generate_password( 5, false, false );
        $login  = $base . $suffix;
        $attempt++;
        if ( $attempt > 8 ) {
            $login = $base . time();
            break;
        }
    } while ( username_exists( $login ) );

    // Générer un mot de passe
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
        error_log( '[WP_ETIK] User creation error: ' . $user_id->get_error_message() );
        return $user_id;
    }

    return (int) $user_id;
}

/**
 * Récupère ou crée une inscription pour un événement + utilisateur.
 * Si une inscription `pending` existe déjà → met à jour le token.
 * Si une inscription `confirmed` existe → retourne une erreur.
 *
 * @param int    $event_id
 * @param int    $user_id
 * @param string $email
 * @param string $first_name
 * @param string $last_name
 * @param string $phone
 * @param string $desired_domain
 * @param int    $has_domain
 * @return array|WP_Error { 'id' => int, 'status' => 'pending'|'updated', 'token' => string, 'token_expires' => string }
 */
function wp_etik_get_or_update_inscription( $event_id, $user_id, $email, $first_name, $last_name, $phone, $desired_domain, $has_domain ) {
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    // Vérifier si déjà inscrit en `confirmed`
    $existing_confirmed = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE event_id = %d AND user_id = %d AND status = %s LIMIT 1",
        $event_id, $user_id, 'confirmed'
    ) );

    if ( $existing_confirmed ) {
        return new WP_Error( 'already_confirmed', 'Vous êtes déjà inscrit à cette formation.' );
    }

    // Vérifier si une inscription `pending` existe
    $pending_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, status FROM {$table} WHERE event_id = %d AND user_id = %d AND status = %s LIMIT 1",
        $event_id, $user_id, 'pending'
    ), ARRAY_A );

    $now_ts = (int) current_time( 'timestamp' );
    $ttl_secs = 48 * 3600;
    $expires_ts = $now_ts + $ttl_secs;
    $expires_mysql = date( 'Y-m-d H:i:s', $expires_ts );
    $token = wp_generate_password( 32, false, false );

    if ( $pending_row ) {
        // Mettre à jour l'inscription existante
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
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            error_log( "[WP_ETIK] DB update failed: {$wpdb->last_error}" );
            return new WP_Error( 'db_update_failed', 'Erreur lors de la mise à jour de l\'inscription.' );
        }

        return [
            'id'            => intval( $pending_row['id'] ),
            'status'        => 'updated',
            'token'         => $token,
            'token_expires' => $expires_mysql,
        ];
    }

    // Créer une nouvelle inscription
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
        error_log( '[WP_ETIK] Insert failed: ' . $wpdb->last_error );
        error_log( '[WP_ETIK] Query: ' . $wpdb->last_query );
        return new WP_Error( 'db_insert_failed', 'Erreur lors de la création de l\'inscription.' );
    }

    return [
        'id'            => (int) $wpdb->insert_id,
        'status'        => 'created',
        'token'         => $token,
        'token_expires' => $expires_mysql,
    ];
}

/**
 * Fonction pour récupérer l’URL
 */ 
function wp_etik_get_payment_return_url() {
    return get_option( 'wp_etik_payment_return_url', home_url( '/confirmation_de_paiement/' ) );
}