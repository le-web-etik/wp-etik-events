<?php
/**
 * includes/migrations/migrate-to-encrypted-users.php
 *
 * MIGRATION CRITIQUE : Passage à l'architecture chiffrée centralisée.
 * 
 * SÉQUENCE DE SÉCURITÉ (IMPÉRATIVE) :
 * 1. Création table wp_etik_users.
 * 2. Ajout colonne etik_user_id (FK).
 * 3. MIGRATION DES DONNÉES : Lecture PII en clair -> Création dans wp_etik_users -> Récupération ID.
 * 4. LINKAGE : Mise à jour des tables inscriptions/reservations avec le nouvel ID.
 * 5. NETTOYAGE : Vidage des colonnes PII en clair (email, nom, tel) DANS LES TABLES LIÉES.
 * 
 * ⚠️ NE JAMAIS inverser les étapes 4 et 5.
 */

namespace WP_Etik\Migrations;

defined( 'ABSPATH' ) || exit;

function migrate_to_encrypted_users() : void {
    global $wpdb;
    
    // -------------------------------------------------------------------------
    // ÉTAPE 0 : PRÉREQUIS
    // -------------------------------------------------------------------------
    // On s'assure que la classe Encryption est disponible
    if ( ! class_exists( 'WP_Etik\\Encryption' ) ) {
        $enc_file = WP_ETIK_PLUGIN_DIR . 'src/Encryption.php';
        if ( file_exists( $enc_file ) ) {
            require_once $enc_file;
        } else {
            error_log( '[WP-Etik Migration] Fichier Encryption.php introuvable. Migration abortée.' );
            return;
        }
    }

    $table_inscriptions = $wpdb->prefix . 'etik_inscriptions';
    $table_reservations = $wpdb->prefix . 'etik_reservations';
    $table_users        = $wpdb->prefix . 'etik_users';

    // -------------------------------------------------------------------------
    // ÉTAPE 1 : CRÉATION TABLE wp_etik_users (Si absente)
    // -------------------------------------------------------------------------
    // On recrée la structure ici pour être sûr qu'elle existe avant d'insérer
    $charset_collate = $wpdb->get_charset_collate();
    $sql_users = "CREATE TABLE IF NOT EXISTS {$table_users} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email_hash VARCHAR(64) NOT NULL,
        email_enc TEXT NOT NULL,
        first_name_enc TEXT NULL,
        last_name_enc TEXT NULL,
        phone_enc TEXT NULL,
        meta_enc LONGTEXT NULL,
        rgpd_request_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY email_hash (email_hash)
    ) {$charset_collate};";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_users );

    // Vérification post-création
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_users}'" ) !== $table_users ) {
        error_log( '[WP-Etik Migration] Échec création table wp_etik_users. Abort.' );
        return;
    }

    // -------------------------------------------------------------------------
    // ÉTAPE 2 : AJOUT COLONNE etik_user_id (Si absente)
    // -------------------------------------------------------------------------
    $cols_insc = $wpdb->get_col( "SHOW COLUMNS FROM {$table_inscriptions}" );
    if ( ! in_array( 'etik_user_id', $cols_insc, true ) ) {
        $wpdb->query( "ALTER TABLE {$table_inscriptions} ADD COLUMN etik_user_id BIGINT UNSIGNED NULL AFTER event_id" );
        $wpdb->query( "ALTER TABLE {$table_inscriptions} ADD KEY etik_user_id (etik_user_id)" );
    }

    $cols_res = $wpdb->get_col( "SHOW COLUMNS FROM {$table_reservations}" );
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_reservations}'" ) == $table_reservations ) {
        if ( ! in_array( 'etik_user_id', $cols_res, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_reservations} ADD COLUMN etik_user_id BIGINT UNSIGNED NULL AFTER slot_id" );
            $wpdb->query( "ALTER TABLE {$table_reservations} ADD KEY etik_user_id (etik_user_id)" );
        }
    }

    // -------------------------------------------------------------------------
    // ÉTAPE 3 & 4 : MIGRATION DES DONNÉES & LINKAGE (Cœur du script)
    // -------------------------------------------------------------------------
    // On traite d'abord les inscriptions, puis les réservations.
    // Pour chaque ligne ayant un email en clair :
    // 1. Chercher si le contact existe déjà dans wp_etik_users.
    // 2. Sinon, le créer (chiffrer les données).
    // 3. Mettre à jour la ligne avec le nouvel etik_user_id.

    migrate_table_contacts( $table_inscriptions, 'inscription' );
    
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_reservations}'" ) == $table_reservations ) {
        migrate_table_contacts( $table_reservations, 'reservation' );
    }

    // -------------------------------------------------------------------------
    // ÉTAPE 5 : NETTOYAGE DES DONNÉES EN CLAIR (SÉCURISÉ)
    // -------------------------------------------------------------------------
    // On ne vide les colonnes QUE si la colonne etik_user_id a été remplie.
    // Cela garantit qu'aucune donnée n'est perdue sans avoir été migrée.

    // Nettoyage Inscriptions
    $wpdb->query( "
        UPDATE {$table_inscriptions} 
        SET email = NULL, 
            email_hash = NULL, 
            first_name = NULL, 
            last_name = NULL, 
            phone = NULL 
        WHERE etik_user_id IS NOT NULL 
        AND email IS NOT NULL
    " );

    // Nettoyage Réservations
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_reservations}'" ) == $table_reservations ) {
        $wpdb->query( "
            UPDATE {$table_reservations} 
            SET email = NULL, 
                first_name = NULL, 
                last_name = NULL, 
                phone = NULL 
            WHERE etik_user_id IS NOT NULL 
            AND email IS NOT NULL
        " );
    }

    error_log( '[WP-Etik Migration] Migration vers utilisateurs chiffrés terminée avec succès.' );
}

/**
 * Helper : Migre les contacts d'une table donnée vers wp_etik_users et met à jour la FK.
 */
function migrate_table_contacts( string $table_name, string $type ) : void {
    global $wpdb;

    // Récupérer toutes les lignes qui ont un email mais PAS encore de etik_user_id
    // On limite par blocs de 1000 pour éviter les timeouts mémoire sur grosses bases
    $offset = 0;
    $limit  = 1000;
    
    do {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, email, first_name, last_name, phone 
             FROM {$table_name} 
             WHERE email IS NOT NULL AND email != '' AND etik_user_id IS NULL 
             LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            break;
        }

        foreach ( $rows as $row ) {
            $email = trim( strtolower( $row['email'] ) );
            if ( ! is_email( $email ) ) {
                continue;
            }

            // 1. Hash pour recherche
            $hash = hash_hmac( 'sha256', $email, defined( 'AUTH_KEY' ) ? AUTH_KEY : 'etik_fallback' );

            // 2. Chercher dans wp_etik_users
            $user_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}etik_users WHERE email_hash = %s LIMIT 1",
                $hash
            ) );

            // 3. Si inexistant, le créer
            if ( ! $user_id ) {
                // Chiffrement des données
                try {
                    $email_enc      = \WP_Etik\Encryption::encrypt( $email )['ciphertext'];
                    $fname_enc      = !empty( $row['first_name'] ) ? \WP_Etik\Encryption::encrypt( $row['first_name'] )['ciphertext'] : null;
                    $lname_enc      = !empty( $row['last_name'] ) ? \WP_Etik\Encryption::encrypt( $row['last_name'] )['ciphertext'] : null;
                    $phone_enc      = !empty( $row['phone'] ) ? \WP_Etik\Encryption::encrypt( $row['phone'] )['ciphertext'] : null;
                } catch ( \Exception $e ) {
                    error_log( "[WP-Etik Migration] Erreur chiffrement pour {$email}: " . $e->getMessage() );
                    continue; // Skip cette ligne pour ne pas bloquer tout le batch
                }

                $inserted = $wpdb->insert(
                    $wpdb->prefix . 'etik_users',
                    [
                        'email_hash'     => $hash,
                        'email_enc'      => $email_enc,
                        'first_name_enc' => $fname_enc,
                        'last_name_enc'  => $lname_enc,
                        'phone_enc'      => $phone_enc,
                        'created_at'     => current_time( 'mysql' ),
                    ],
                    [ '%s', '%s', '%s', '%s', '%s', '%s' ]
                );

                if ( $inserted ) {
                    $user_id = (int) $wpdb->insert_id;
                }
            }

            // 4. Mettre à jour la table source avec le nouvel ID
            if ( $user_id ) {
                $wpdb->update(
                    $table_name,
                    [ 'etik_user_id' => $user_id ],
                    [ 'id' => (int) $row['id'] ],
                    [ '%d' ],
                    [ '%d' ]
                );
            }
        }

        $offset += $limit;
    } while ( count( $rows ) === $limit );
}