<?php
/**
 * includes/migrations/add-custom-data-column.php
 *
 * Ajoute les colonnes custom_data (JSON chiffré) et email_hash (recherche)
 * à la table etik_inscriptions.
 *
 * Appelé lors de l'activation ou de la mise à jour du plugin.
 */

namespace WP_Etik\Migrations;

defined( 'ABSPATH' ) || exit;

function add_custom_data_column() : void {
    global $wpdb;
    $table = $wpdb->prefix . 'etik_inscriptions';

    // Vérifier si la colonne existe déjà
    $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

    if ( ! in_array( 'custom_data', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN custom_data LONGTEXT NULL AFTER payment_session_id" );
    }

    if ( ! in_array( 'email_hash', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN email_hash VARCHAR(64) NULL AFTER email" );
        $wpdb->query( "ALTER TABLE {$table} ADD INDEX email_hash_idx (email_hash)" );
    }
}