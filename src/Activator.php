<?php
namespace WP_Etik;

class Activator {
    public static function activate() {
        // role
        if ( ! get_role('client_web_etik') ) {
            add_role('client_web_etik', 'Client Web Etik', ['read' => true, 'edit_posts' => false]);
        }

        // register CPT before flushing
        $cpt = new CPT_Event();
        $cpt->register();
        flush_rewrite_rules();

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
            PRIMARY KEY (id),
            UNIQUE KEY event_user_unique (event_id, user_id),
            KEY event_idx (event_id),
            KEY email_idx (email)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function deactivate() {
        // flush rewrite rules etc.
        flush_rewrite_rules();

        // Remove table on deactivate if desired
        global $wpdb;
        $table = $wpdb->prefix . 'etik_inscriptions';
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }
}
