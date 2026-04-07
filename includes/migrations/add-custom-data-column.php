<?php
/**
 * includes/migrations/add-custom-data-column.php
 *
 * Migration : élargir les colonnes pour stocker les données chiffrées
 * (base64 d'un payload AES-256-CBC ≈ 88-300 chars selon la longueur du texte clair)
 *
 * + Ajout de custom_data (JSON chiffré) et email_hash (recherche)
 */

namespace WP_Etik\Migrations;

defined( 'ABSPATH' ) || exit;

function add_custom_data_column() : void {
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

    // ── Nouvelles colonnes ──────────────────────────────────────────────
    if ( ! in_array( 'custom_data', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN custom_data LONGTEXT NULL AFTER payment_session_id" );
    }

    if ( ! in_array( 'email_hash', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN email_hash VARCHAR(64) NULL AFTER email" );
        $wpdb->query( "ALTER TABLE {$table} ADD INDEX email_hash_idx (email_hash)" );
    }

    // ── Élargir les colonnes existantes pour les données chiffrées ──────
    // AES-256-CBC payload : 16 (IV) + 32 (HMAC) + ceil(len/16)*16 (cipher)
    // base64 → +33%. Un email de 50 chars → payload ~130 chars base64.
    // On met TEXT pour être tranquille.
    $wpdb->query( "ALTER TABLE {$table} MODIFY email TEXT NOT NULL" );
    $wpdb->query( "ALTER TABLE {$table} MODIFY first_name TEXT NULL" );
    $wpdb->query( "ALTER TABLE {$table} MODIFY last_name TEXT NULL" );
    $wpdb->query( "ALTER TABLE {$table} MODIFY phone TEXT NULL" );
}