<?php
namespace WP_Etik;

defined( 'ABSPATH' ) || exit;

class Activator {

    public static function activate() : void {
        // ── CAPTURE de toute sortie inattendue ────────────────────────────────
        // dbDelta() produit du HTML lors de la création/modification de tables
        // (ex: "Installed table etik_users"). Ce buffer l'intercepte silencieusement
        // pour éviter l'erreur WordPress "3780 caractères de sortie inattendus".
        ob_start();

        try {
            self::_do_activate();
        } finally {
            ob_end_clean(); // Toujours exécuté, même si exception
        }
    }

    private static function _do_activate() : void {
        $cpt = new CPT_Event();
        $cpt->register();

        // Clé de hachage stable pour HMAC email (générée une seule fois, jamais changée)
        if ( ! get_option( 'etik_hash_key' ) ) {
            update_option( 'etik_hash_key', bin2hex( random_bytes( 32 ) ), false );
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        self::create_etik_users_table();
        self::create_inscription_table();
        self::create_prestation_tables();
        self::create_form_tables();
        self::create_form_responses_table();

        // Migration : lecture PII → etik_users chiffré → nettoyage colonnes sources
        $migration = WP_ETIK_PLUGIN_DIR . 'includes/migrations/migrate-to-encrypted-users.php';
        if ( file_exists( $migration ) ) {
            require_once $migration;
            \WP_Etik\Migrations\migrate_to_encrypted_users();
        }

        if ( get_option( 'etik_plugin_activated' ) !== 'yes' ) {
            flush_rewrite_rules();
            update_option( 'etik_plugin_activated', 'yes' );
        }
    }

    public static function deactivate() : void {
        flush_rewrite_rules();
    }

    // ─── etik_users ───────────────────────────────────────────────────────────

    private static function create_etik_users_table() : void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        // Règle critique : AUCUN commentaire SQL (--) dans les strings CREATE TABLE.
        // dbDelta() ne les supporte pas et produit du texte inattendu.
        dbDelta( "CREATE TABLE {$wpdb->prefix}etik_users (
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
        ) {$c};" );
    }

    // ─── etik_inscriptions ────────────────────────────────────────────────────

    private static function create_inscription_table() : void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$wpdb->prefix}etik_inscriptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            etik_user_id BIGINT UNSIGNED NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            token VARCHAR(191) NULL,
            token_expires DATETIME NULL,
            registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payment_session_id VARCHAR(255) NULL,
            amount INT NULL,
            reserved_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY event_idx (event_id),
            KEY etik_user_id (etik_user_id),
            KEY status_idx (status)
        ) {$c};" );
    }

    // ─── Tables prestations ───────────────────────────────────────────────────

    private static function create_prestation_tables() : void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$wpdb->prefix}etik_prestation_slots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            prestation_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'recurrent',
            start_time VARCHAR(5) NOT NULL,
            duration INT NOT NULL DEFAULT 60,
            break_duration INT NOT NULL DEFAULT 15,
            days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
            start_date DATE NULL,
            end_date DATE NULL,
            is_closed TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY prestation_id (prestation_id),
            KEY start_time (start_time)
        ) {$c};" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}etik_reservations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            prestation_id BIGINT UNSIGNED NOT NULL,
            slot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            booking_date DATE NOT NULL,
            booking_time VARCHAR(10) NOT NULL DEFAULT '',
            etik_user_id BIGINT UNSIGNED NULL,
            form_data LONGTEXT NULL,
            token VARCHAR(64) NULL,
            payment_session_id VARCHAR(200) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY prestation_id (prestation_id),
            KEY booking_date (booking_date),
            KEY etik_user_id (etik_user_id),
            KEY status (status)
        ) {$c};" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}etik_prestation_closures (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            prestation_id BIGINT UNSIGNED NOT NULL,
            global TINYINT(1) NOT NULL DEFAULT 0,
            prestation_ids TEXT NULL,
            closure_date DATE NOT NULL,
            PRIMARY KEY (id),
            KEY prestation_id (prestation_id),
            KEY closure_date (closure_date)
        ) {$c};" );
    }

    // ─── Tables formulaires ───────────────────────────────────────────────────

    private static function create_form_tables() : void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$wpdb->prefix}etik_forms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(191) NOT NULL,
            description TEXT NULL,
            slug VARCHAR(191) NOT NULL DEFAULT '',
            attach_type VARCHAR(20) NOT NULL DEFAULT 'all',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {$c};" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}etik_form_fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            field_key VARCHAR(100) NOT NULL,
            label VARCHAR(191) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'text',
            placeholder VARCHAR(191) NULL,
            required TINYINT(1) NOT NULL DEFAULT 0,
            options TEXT NULL,
            help_text VARCHAR(255) NULL,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY sort_order (sort_order)
        ) {$c};" );

        self::maybe_create_default_form();
    }

    // ─── Table réponses formulaires ───────────────────────────────────────────

    private static function create_form_responses_table() : void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        // Pas de UNIQUE KEY sur submission_id : permet les retry sans erreur DB
        dbDelta( "CREATE TABLE {$wpdb->prefix}etik_form_responses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            submission_type VARCHAR(20) NOT NULL,
            form_id BIGINT UNSIGNED NOT NULL,
            form_snapshot LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY submission_idx (submission_id, submission_type),
            KEY form_idx (form_id),
            KEY created_at_idx (created_at)
        ) {$c};" );
    }

    // ─── Formulaire par défaut ────────────────────────────────────────────────

    private static function maybe_create_default_form() : void {
        global $wpdb;
        $tf = $wpdb->prefix . 'etik_forms';
        $ff = $wpdb->prefix . 'etik_form_fields';

        if ( $wpdb->get_var( "SELECT id FROM {$tf} WHERE is_default = 1 LIMIT 1" ) ) {
            return;
        }

        $wpdb->insert( $tf, [
            'title'       => 'Formulaire d\'inscription standard',
            'description' => 'Créé automatiquement à l\'activation.',
            'slug'        => 'inscription-standard',
            'attach_type' => 'all',
            'is_default'  => 1,
        ], [ '%s', '%s', '%s', '%s', '%d' ] );

        $fid = (int) $wpdb->insert_id;
        if ( ! $fid ) return;

        foreach ( [
            [ 'first_name', 'Prénom',    'text',  'Votre prénom',   1, 1 ],
            [ 'last_name',  'Nom',        'text',  'Votre nom',      0, 2 ],
            [ 'email',      'E-mail',     'email', 'votre@email.fr', 1, 3 ],
            [ 'phone',      'Téléphone',  'tel',   '06 xx xx xx xx', 0, 4 ],
        ] as [ $key, $label, $type, $ph, $req, $order ] ) {
            $wpdb->insert( $ff, [
                'form_id'     => $fid,
                'field_key'   => $key,
                'label'       => $label,
                'type'        => $type,
                'placeholder' => $ph,
                'required'    => $req,
                'sort_order'  => $order,
                'help_text'   => '',
                'options'     => null,
            ], [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ] );
        }
    }
}