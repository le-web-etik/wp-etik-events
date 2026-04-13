<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/**
 * Classe d'activation du plugin.
 * Gère la création des tables de base de données, des rôles (si nécessaire)
 * et des données par défaut.
 */
class Activator {

    /**
     * Méthode exécutée lors de l'activation du plugin.
     */
    public static function activate() {
        // 1. Enregistrer le CPT temporairement pour éviter les erreurs de rewrite
        $cpt = new CPT_Event();
        $cpt->register();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 2. Création des tables principales
        self::create_etik_users_table();      // NOUVEAU : Table contacts chiffrés
        self::create_inscription_table();     // Mise à jour avec etik_user_id
        self::create_prestation_tables();     // Slots, Réservations, Fermetures
        self::create_form_tables();           // Formulaires dynamiques
        self::create_form_responses_table();  // Réponses au Formulaires

        

        // 3. Exécution des migrations (ajout de colonnes si mises à jour)
        if (file_exists(WP_ETIK_PLUGIN_DIR . 'includes/migrations/add-custom-data-column.php')) {
            require_once WP_ETIK_PLUGIN_DIR . 'includes/migrations/add-custom-data-column.php';
            \WP_Etik\Migrations\add_custom_data_column();
        }

        // 4. Nettoyage des règles de réécriture
        if (get_option('etik_plugin_activated') !== 'yes') {
            flush_rewrite_rules();
            update_option('etik_plugin_activated', 'yes');
        }
    }

    /**
     * Méthode exécutée lors de la désactivation du plugin.
     */
    public static function deactivate() {
        flush_rewrite_rules();
        // Note : Nous ne supprimons PAS les tables ici pour préserver les données.
    }

    // =========================================================================
    // CRÉATION DES TABLES
    // =========================================================================

    /**
     * Crée la table wp_etik_users.
     * Source unique de vérité pour les contacts clients.
     * Toutes les données personnelles (PII) sont chiffrées.
     */
    private static function create_etik_users_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'etik_users';

        $sql = "CREATE TABLE {$table_name} (
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

        dbDelta($sql);
    }

    /**
     * Crée la table wp_etik_inscriptions.
     * STRUCTURE SIMPLIFIÉE : 
     * - Plus de colonnes PII en clair (email, nom, tel...).
     * - Tout passe par etik_user_id (lien vers wp_etik_users).
     * - custom_data conservé pour les données spécifiques à l'événement (chiffrées par le handler).
     */
    private static function create_inscription_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'etik_inscriptions';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            etik_user_id BIGINT UNSIGNED NULL,
            
            -- Colonnes héritées (gardées pour rétrocompatibilité temporaire mais vidées par migration)
            user_id BIGINT UNSIGNED NULL DEFAULT 0,
            email TEXT NULL,                -- Sera vidé
            email_hash VARCHAR(64) NULL,    -- Sera vidé
            first_name TEXT NULL,           -- Sera vidé
            last_name TEXT NULL,            -- Sera vidé
            phone TEXT NULL,                -- Sera vidé
            
            -- Données spécifiques à l'inscription (peuvent rester en clair ou chiffrées selon besoin)
            desired_domain VARCHAR(191) NULL,
            has_domain TINYINT(1) NOT NULL DEFAULT 0,
            custom_data LONGTEXT NULL,      -- JSON chiffré si nécessaire
            
            -- Gestion de l'état et paiement
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
            KEY status_idx (status),
            KEY registered_at_idx (registered_at)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Crée les tables liées aux prestations (Créneaux, Réservations, Fermetures).
     */
    private static function create_prestation_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // 1. Table des créneaux (slots)
        $table_slots = $wpdb->prefix . 'etik_prestation_slots';
        $sql_slots = "CREATE TABLE {$table_slots} (
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
            KEY type (type),
            KEY start_time (start_time),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) {$charset_collate};";
        dbDelta($sql_slots);

        // 2. Table des réservations
        $table_res = $wpdb->prefix . 'etik_reservations';
        $sql_res = "CREATE TABLE {$table_res} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            prestation_id BIGINT UNSIGNED NOT NULL,
            slot_id BIGINT UNSIGNED NOT NULL,
            etik_user_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL DEFAULT 0,
            booking_date DATE NOT NULL,
            booking_time VARCHAR(10) NOT NULL DEFAULT '',
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(200) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            form_data LONGTEXT NULL,
            token VARCHAR(64) NULL,
            payment_session_id VARCHAR(200) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY prestation_id (prestation_id),
            KEY slot_id (slot_id),
            KEY etik_user_id (etik_user_id),
            KEY booking_date (booking_date),
            KEY status (status)
        ) {$charset_collate};";
        dbDelta($sql_res);

        // 3. Table des fermetures exceptionnelles
        $table_closures = $wpdb->prefix . 'etik_prestation_closures';
        $sql_closures = "CREATE TABLE {$table_closures} (
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
        dbDelta($sql_closures);
    }

    /**
     * Crée les tables liées aux formulaires dynamiques.
     */
    private static function create_form_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // 1. Table des formulaires
        $table_forms = $wpdb->prefix . 'etik_forms';
        $sql_forms = "CREATE TABLE {$table_forms} (
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
        ) {$charset_collate};";
        dbDelta($sql_forms);

        // 2. Table des champs de formulaire
        $table_fields = $wpdb->prefix . 'etik_form_fields';
        $sql_fields = "CREATE TABLE {$table_fields} (
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
        ) {$charset_collate};";
        dbDelta($sql_fields);

        // 3. Créer le formulaire par défaut s'il n'existe pas
        self::maybe_create_default_form();
    }

    /**
     * Crée la table wp_etik_form_responses.
     * Approche "JSON Monolithique" : Une ligne par soumission contenant tout le formulaire chiffré.
     */
    private static function create_form_responses_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'etik_form_responses';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,       -- ID de l'inscription ou réservation
            submission_type VARCHAR(20) NOT NULL,         -- 'inscription' ou 'reservation'
            form_id BIGINT UNSIGNED NOT NULL,             -- ID du formulaire utilisé
            form_snapshot LONGTEXT NOT NULL,              -- JSON chiffré : { questions: [...], answers: {...} }
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_submission (submission_id, submission_type), -- Une seule réponse par inscription
            KEY form_idx (form_id),
            KEY created_at_idx (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    // =========================================================================
    // DONNÉES PAR DÉFAUT
    // =========================================================================

    /**
     * Insère un formulaire d'inscription standard si aucun formulaire par défaut n'existe.
     */
    private static function maybe_create_default_form() {
        global $wpdb;
        $table_forms = $wpdb->prefix . 'etik_forms';
        $table_fields = $wpdb->prefix . 'etik_form_fields';

        // Vérifier si un formulaire par défaut existe déjà
        $exists = $wpdb->get_var("SELECT id FROM {$table_forms} WHERE is_default = 1 LIMIT 1");
        if ($exists) {
            return;
        }

        // Créer le formulaire par défaut
        $wpdb->insert($table_forms, [
            'title'       => 'Formulaire d\'inscription standard',
            'description' => 'Formulaire généré automatiquement à l\'activation du plugin.',
            'slug'        => 'inscription-standard',
            'attach_type' => 'all',
            'is_default'  => 1,
        ], ['%s', '%s', '%s', '%s', '%d']);

        $form_id = (int) $wpdb->insert_id;
        if (!$form_id) {
            return;
        }

        // Champs par défaut
        $fields = [
            [
                'field_key'   => 'first_name',
                'label'       => 'Prénom',
                'type'        => 'text',
                'placeholder' => 'Votre prénom',
                'required'    => 1,
                'sort_order'  => 1,
            ],
            [
                'field_key'   => 'last_name',
                'label'       => 'Nom',
                'type'        => 'text',
                'placeholder' => 'Votre nom',
                'required'    => 0,
                'sort_order'  => 2,
            ],
            [
                'field_key'   => 'email',
                'label'       => 'E-mail',
                'type'        => 'email',
                'placeholder' => 'votre@email.fr',
                'required'    => 1,
                'sort_order'  => 3,
            ],
            [
                'field_key'   => 'phone',
                'label'       => 'Téléphone',
                'type'        => 'tel',
                'placeholder' => '06 00 00 00 00',
                'required'    => 0,
                'sort_order'  => 4,
            ],
        ];

        foreach ($fields as $field) {
            $wpdb->insert($table_fields, array_merge($field, ['form_id' => $form_id, 'help_text' => '', 'options' => null]), [
                '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'
            ]);
        }
    }
}