<?php
namespace WP_Etik;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_nopriv_lwe_create_prestation_reservation', __NAMESPACE__ . '\\lwe_create_prestation_reservation' );
add_action( 'wp_ajax_lwe_create_prestation_reservation', __NAMESPACE__ . '\\lwe_create_prestation_reservation' );
add_action( 'wp_ajax_lwe_create_prestation', __NAMESPACE__ . '\\lwe_create_prestation' );

function lwe_create_prestation_reservation() {
    global $wpdb;

    // nonce
    $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'wp_etik_inscription_nonce' ) ) {
        wp_send_json_error( [ 'code' => 'invalid_nonce', 'message' => 'Requête invalide (nonce).' ] );
    }

    $prestation_id = isset( $_POST['prestation_id'] ) ? intval( $_POST['prestation_id'] ) : 0;
    $slot_id = isset( $_POST['slot_id'] ) ? intval( $_POST['slot_id'] ) : 0;
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

    if ( $prestation_id <= 0 || $slot_id <= 0 || empty( $email ) || empty( $first_name ) || empty( $phone ) ) {
        wp_send_json_error( [ 'code' => 'missing_fields', 'message' => 'Veuillez remplir tous les champs obligatoires.' ] );
    }

    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'code' => 'invalid_email', 'message' => 'Adresse email invalide.' ] );
    }

    // Créer l'utilisateur
    $user_id = wp_etik_get_or_create_user( $email, $first_name, $last_name );
    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( [ 'code' => 'user_creation_failed', 'message' => 'Erreur lors de la création du compte utilisateur.' ] );
    }

    // Vérifier si le créneau est disponible
    $table = $wpdb->prefix . 'etik_reservations';
    $reserved_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE slot_id = %d AND status = %s",
        $slot_id, 'confirmed'
    ) );

    $max_place = get_post_meta( $prestation_id, 'etik_prestation_max_place', true );
    if ( $reserved_count >= $max_place ) {
        wp_send_json_error( [ 'code' => 'slot_full', 'message' => 'Le créneau est complet.' ] );
    }

    // Créer la réservation
    $inserted = $wpdb->insert(
        $table,
        [
            'prestation_id' => $prestation_id,
            'slot_id' => $slot_id,
            'user_id' => $user_id,
            'status' => 'pending',
            'created_at' => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%d', '%s', '%s' ]
    );

    if ( ! $inserted ) {
        wp_send_json_error( [ 'code' => 'db_error', 'message' => 'Erreur lors de la création de la réservation.' ] );
    }

    $reservation_id = (int) $wpdb->insert_id;

    // Sauvegarder les infos utilisateur
    update_user_meta( $user_id, 'etik_user_info', [
        'phone' => $phone,
        'note' => $note,
    ] );

    // Envoi du paiement
    $payment_required = get_post_meta( $prestation_id, 'etik_prestation_payment_required', true );
    if ( $payment_required === '1' ) {
        // Utiliser la même logique que lwe_create_checkout()
        // ... (à implémenter)
    }

    wp_send_json_success( [
        'status' => 'pending',
        'reservation_id' => $reservation_id,
        'message' => 'Réservation enregistrée. Un email de confirmation vous a été envoyé.'
    ] );
}



function lwe_create_prestation() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Accès refusé' ] );
    }

    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'wp_etik_prestation_save' ) ) {
        wp_send_json_error( [ 'message' => 'Requête invalide (nonce).' ] );
    }

    $title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';
    $content = isset( $_POST['post_content'] ) ? wp_kses_post( wp_unslash( $_POST['post_content'] ) ) : '';
    $color = isset( $_POST['etik_prestation_color'] ) ? sanitize_text_field( wp_unslash( $_POST['etik_prestation_color'] ) ) : '';
    $price = isset( $_POST['etik_prestation_price'] ) ? floatval( $_POST['etik_prestation_price'] ) : 0;
    $payment_required = isset( $_POST['etik_prestation_payment_required'] ) ? '1' : '0';
    $max_place = isset( $_POST['etik_prestation_max_place'] ) ? intval( $_POST['etik_prestation_max_place'] ) : 1;

    if ( empty( $title ) ) {
        wp_send_json_error( [ 'message' => 'Le titre est requis.' ] );
    }

    // Créer la prestation
    $post_id = wp_insert_post( [
        'post_type' => 'etik_prestation',
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Erreur lors de la création de la prestation.' ] );
    }

    // Enregistrer les métadonnées
    update_post_meta( $post_id, 'etik_prestation_color', $color );
    update_post_meta( $post_id, 'etik_prestation_price', $price );
    update_post_meta( $post_id, 'etik_prestation_payment_required', $payment_required );
    update_post_meta( $post_id, 'etik_prestation_max_place', $max_place );

    wp_send_json_success( [
        'message' => 'Prestation créée avec succès.',
        'post_id' => $post_id,
        'title' => $title,
    ] );
}