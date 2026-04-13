<?php
/**
 * includes/migrations/add-custom-data-column.php
 *
 * Migration : 
 * 1. Ajoute custom_data (JSON) et email_hash (si absent).
 * 2. NETTOIE les colonnes PII en clair (email, nom, tel) de la table inscriptions 
 *    car ces données sont désormais centralisées dans wp_etik_users.
 */

namespace WP_Etik\Migrations;

defined( 'ABSPATH' ) || exit;

function add_custom_data_column() : void {
    global $wpdb;
    
    // -------------------------------------------------------------------------
    // 1. GESTION TABLE etik_inscriptions
    // -------------------------------------------------------------------------
    $table_inscriptions = $wpdb->prefix . 'etik_inscriptions';
    $columns_insc = $wpdb->get_col( "SHOW COLUMNS FROM {$table_inscriptions}" );

    // Ajouter custom_data si absent
    if ( ! in_array( 'custom_data', $columns_insc, true ) ) {
        $wpdb->query( "ALTER TABLE {$table_inscriptions} ADD COLUMN custom_data LONGTEXT NULL AFTER payment_session_id" );
    }

    // Ajouter email_hash si absent (pour rétrocompatibilité ancienne version)
    if ( ! in_array( 'email_hash', $columns_insc, true ) ) {
        $wpdb->query( "ALTER TABLE {$table_inscriptions} ADD COLUMN email_hash VARCHAR(64) NULL AFTER email" );
        $wpdb->query( "ALTER TABLE {$table_inscriptions} ADD INDEX email_hash_idx (email_hash)" );
    }

    // Ajouter etik_user_id si absent (Nouvelle archi)
    if ( ! in_array( 'etik_user_id', $columns_insc, true ) ) {
        $wpdb->query( "ALTER TABLE {$table_inscriptions} ADD COLUMN etik_user_id BIGINT UNSIGNED NULL AFTER event_id" );
        $wpdb->query( "ALTER TABLE {$table_inscriptions} ADD KEY etik_user_id (etik_user_id)" );
    }

    // -------------------------------------------------------------------------
    // 2. NETTOYAGE DES DONNÉES SENSIBLES (RGPD & SÉCURITÉ)
    // -------------------------------------------------------------------------
    // Si la table wp_etik_users existe, on suppose que la nouvelle archi est active.
    // On vide alors les colonnes PII de la table inscriptions pour éviter la redondance en clair.
    
    $table_users = $wpdb->prefix . 'etik_users';
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_users}'" ) == $table_users;

    if ( $table_exists ) {
        // On ne supprime pas les colonnes (pour ne pas casser d'ancien code non mis à jour immédiatement),
        // mais on vide leur contenu pour garantir qu'aucune donnée sensible ne reste en clair.
        
        $wpdb->query( "UPDATE {$table_inscriptions} SET 
            email = NULL, 
            email_hash = NULL, 
            first_name = NULL, 
            last_name = NULL, 
            phone = NULL 
            WHERE email IS NOT NULL" 
        );
        
        // Optionnel : Si vous voulez vraiment supprimer les colonnes physiquement (plus radical)
        // Décommentez les lignes suivantes avec prudence :
        /*
        if ( in_array( 'email', $columns_insc, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_inscriptions} DROP COLUMN email" );
        }
        if ( in_array( 'first_name', $columns_insc, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_inscriptions} DROP COLUMN first_name" );
        }
        // ... idem pour last_name, phone, etc.
        */
    }

    // -------------------------------------------------------------------------
    // 3. GESTION TABLE etik_reservations (Même logique de nettoyage)
    // -------------------------------------------------------------------------
    $table_reservations = $wpdb->prefix . 'etik_reservations';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_reservations}'" ) == $table_reservations ) {
        
        // S'assurer que etik_user_id existe
        $cols_res = $wpdb->get_col( "SHOW COLUMNS FROM {$table_reservations}" );
        if ( ! in_array( 'etik_user_id', $cols_res, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_reservations} ADD COLUMN etik_user_id BIGINT UNSIGNED NULL AFTER slot_id" );
            $wpdb->query( "ALTER TABLE {$table_reservations} ADD KEY etik_user_id (etik_user_id)" );
        }

        // Nettoyage PII si wp_etik_users existe
        if ( $table_exists ) {
            $wpdb->query( "UPDATE {$table_reservations} SET 
                email = NULL, 
                first_name = NULL, 
                last_name = NULL, 
                phone = NULL 
                WHERE email IS NOT NULL" 
            );
        }
    }
}