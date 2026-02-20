<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

class Activator {
    public static function activate() {
        // role
        if ( ! get_role('client_web_etik') && current_user_can('manage_options') ) {
            add_role('client_web_etik', 'Client Web Etik', ['read' => true, 'edit_posts' => false]);
        }

        // register CPT before flushing
        $cpt = new CPT_Event();
        $cpt->register();

        // create table now (executed during activation)
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'etik_inscriptions';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            email VARCHAR(191) NOT NULL,
            first_name VARCHAR(120) NULL,
            last_name VARCHAR(120) NULL,
            phone VARCHAR(50) NULL,
            desired_domain VARCHAR(191) NULL,
            has_domain TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            token VARCHAR(191) NULL,
            token_expires DATETIME NULL,
            registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payment_session_id VARCHAR(255) NULL,  
            amount INT NULL,                    
            reserved_at DATETIME NULL,          
            PRIMARY KEY (id),
            UNIQUE KEY event_user_unique (event_id, user_id),
            KEY event_idx (event_id),
            KEY email_idx (email)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Créer les tables pour les prestations
        self::create_prestation_tables();

        if ( get_option( 'etik_plugin_activated' ) !== 'yes' ) {
            flush_rewrite_rules();
            update_option( 'etik_plugin_activated', 'yes' );
        }
    }

    public static function deactivate() {
        // flush rewrite rules etc.
        flush_rewrite_rules();

        // Optionnel : supprimer les tables
        // self::drop_prestation_tables();
    }

    private static function create_prestation_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Table etik_prestation_slots
        $table_name = $wpdb->prefix . 'etik_prestation_slots';
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            prestation_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'recurrent',
            start_time VARCHAR(5) NOT NULL,
            duration INT NOT NULL DEFAULT 60,
            break_duration INT NOT NULL DEFAULT 15,
            days VARCHAR(10) NOT NULL DEFAULT '1,2,3,4,5,6,7',
            start_date DATE NULL,
            end_date DATE NULL,
            is_closed TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY prestation_id (prestation_id),
            KEY type (type),
            KEY start_time (start_time),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Table etik_reservations
        $table_name = $wpdb->prefix . 'etik_reservations';
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            prestation_id BIGINT UNSIGNED NOT NULL,
            slot_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY prestation_id (prestation_id),
            KEY slot_id (slot_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );

        // Table etik_prestation_closures
        $table_name = $wpdb->prefix . 'etik_prestation_closures';
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            prestation_id BIGINT UNSIGNED NOT NULL,
            global TINYINT(1) NOT NULL DEFAULT 0,
            prestation_ids TEXT NULL,
            closure_date DATE NOT NULL,
            PRIMARY KEY (id),
            KEY prestation_id (prestation_id),
            KEY global (global),
            KEY closure_date (closure_date)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    private static function drop_prestation_tables() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}etik_prestation_slots" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}etik_reservations" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}etik_prestation_closures" );
    }
}