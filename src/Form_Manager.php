<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/**
 * CRUD pour les formulaires et leurs champs.
 * Utilisé par Form_Builder_Admin et les handlers AJAX.
 *
 * Types de champs :
 *   text | email | tel | number | date | textarea
 *   checkbox        — case unique (J'accepte…)
 *   radio           — boutons Oui/Non ou choix multiples (options = JSON array)
 *   checkbox_group  — cases à cocher multiples       (options = JSON array)
 *   select          — liste déroulante                (options = JSON array)
 *   html            — bloc de texte affiché (pas de saisie) (options[0] = HTML)
 *   consent         — consentement RGPD               (options[0] = texte long)
 *
 * Logique conditionnelle (optionnelle) :
 *   Stockée dans help_text comme mini-JSON :
 *   {"if":"field_key","eq":"Oui","action":"show_msg","msg":"Veuillez nous contacter avant..."}
 */
class Form_Manager {

    // ─────────────────────────────────────────────────────────────────────────
    // TYPES DE CHAMPS
    // ─────────────────────────────────────────────────────────────────────────

    public static function get_field_types() : array {
        return [
            // Saisie simple
            'text'          => [ 'label' => 'Texte court',          'icon' => 'T',  'has_options' => false ],
            'email'         => [ 'label' => 'E-mail',               'icon' => '@',  'has_options' => false ],
            'tel'           => [ 'label' => 'Téléphone',            'icon' => '☎',  'has_options' => false ],
            'number'        => [ 'label' => 'Nombre',               'icon' => '#',  'has_options' => false ],
            'date'          => [ 'label' => 'Date',                 'icon' => '📅', 'has_options' => false ],
            'textarea'      => [ 'label' => 'Texte long',           'icon' => '¶',  'has_options' => false ],
            // Choix
            'checkbox'      => [ 'label' => 'Case à cocher',        'icon' => '☐',  'has_options' => false ],
            'radio'         => [ 'label' => 'Oui / Non (radio)',    'icon' => '◉',  'has_options' => true  ],
            'checkbox_group'=> [ 'label' => 'Cases multiples',      'icon' => '☑',  'has_options' => true  ],
            'select'        => [ 'label' => 'Liste déroulante',     'icon' => '▾',  'has_options' => true  ],
            // Affichage
            'html'          => [ 'label' => 'Bloc de texte',        'icon' => '📄', 'has_options' => true  ],
            'consent'       => [ 'label' => 'Consentement (RGPD)',  'icon' => '✓',  'has_options' => true  ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORMULAIRES — CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public static function get_forms() : array {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_forms';
        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY is_default DESC, created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    public static function get_form( int $form_id ) : ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}etik_forms WHERE id = %d LIMIT 1",
                $form_id
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Crée ou met à jour un formulaire.
     *
     * @param array $data  { title, description, slug?, attach_type, is_default }
     * @param int   $id    0 = création, >0 = mise à jour
     * @return int|\WP_Error  form_id ou WP_Error
     */
    public static function save_form( array $data, int $id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_forms';

        $row = [
            'title'       => sanitize_text_field( $data['title'] ?? '' ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'slug'        => sanitize_title( $data['slug'] ?? $data['title'] ?? '' ),
            'attach_type' => in_array( $data['attach_type'] ?? '', [ 'event', 'prestation', 'all' ] )
                             ? $data['attach_type'] : 'all',
            'is_default'  => intval( $data['is_default'] ?? 0 ),
        ];

        if ( empty( $row['title'] ) ) {
            return new \WP_Error( 'missing_title', 'Le titre du formulaire est requis.' );
        }

        if ( $id > 0 ) {
            $result = $wpdb->update( $table, $row, [ 'id' => $id ],
                [ '%s', '%s', '%s', '%s', '%d' ], [ '%d' ] );
            if ( $result === false ) {
                return new \WP_Error( 'db_error', 'Erreur lors de la mise à jour.' );
            }
            return $id;
        }

        // Unicité du slug
        $base  = $row['slug'];
        $count = 1;
        while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $row['slug'] ) ) ) {
            $row['slug'] = $base . '-' . $count++;
        }

        $result = $wpdb->insert( $table, $row, [ '%s', '%s', '%s', '%s', '%d' ] );
        if ( ! $result ) {
            return new \WP_Error( 'db_error', 'Erreur lors de la création.' );
        }
        return (int) $wpdb->insert_id;
    }

    public static function delete_form( int $form_id ) : bool {
        global $wpdb;
        $form = self::get_form( $form_id );
        if ( $form && intval( $form['is_default'] ) === 1 ) {
            return false; // formulaire par défaut protégé
        }
        $wpdb->delete( $wpdb->prefix . 'etik_form_fields', [ 'form_id' => $form_id ], [ '%d' ] );
        return (bool) $wpdb->delete( $wpdb->prefix . 'etik_forms', [ 'id' => $form_id ], [ '%d' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHAMPS — CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public static function get_fields( int $form_id ) : array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}etik_form_fields
                 WHERE form_id = %d ORDER BY sort_order ASC, id ASC",
                $form_id
            ),
            ARRAY_A
        ) ?: [];

        foreach ( $rows as &$row ) {
            $row['options_decoded'] = [];
            $row['conditional']     = null;

            if ( ! empty( $row['options'] ) ) {
                $decoded = json_decode( $row['options'], true );
                if ( is_array( $decoded ) ) {
                    $row['options_decoded'] = $decoded;
                }
            }

            // Lire la logique conditionnelle stockée dans help_text (JSON)
            if ( ! empty( $row['help_text'] ) ) {
                $maybe_json = json_decode( $row['help_text'], true );
                if ( is_array( $maybe_json ) && isset( $maybe_json['if'] ) ) {
                    $row['conditional'] = $maybe_json;
                    $row['help_text']   = ''; // ne pas afficher le JSON en front
                }
            }
        }
        return $rows;
    }

    /**
     * Sauvegarde tous les champs d'un formulaire (supprime et réinsère).
     *
     * Structure d'un champ :
     *   field_key, label, type, placeholder, required, options, help_text, sort_order
     *   conditional (optionnel) : { if, eq, action, msg }
     *
     * Stockage des options selon le type :
     *   radio / checkbox_group / select  → JSON array ["Oui","Non"]
     *   html / consent                   → JSON array [<contenu HTML>]
     *   autres                           → null
     */
    public static function save_fields( int $form_id, array $fields ) : bool {
        global $wpdb;
        $table      = $wpdb->prefix . 'etik_form_fields';
        $valid_types = array_keys( self::get_field_types() );

        $wpdb->delete( $table, [ 'form_id' => $form_id ], [ '%d' ] );

        $order = 1;
        foreach ( $fields as $field ) {
            $type = in_array( $field['type'] ?? '', $valid_types )
                ? $field['type'] : 'text';

            // ── Encodage des options ────────────────────────────────────────
            $options = null;

            if ( in_array( $type, [ 'radio', 'checkbox_group', 'select' ] ) ) {
                // Tableau de choix
                $raw = $field['options'] ?? [];
                if ( is_string( $raw ) ) {
                    $raw = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
                }
                if ( ! empty( $raw ) ) {
                    $options = wp_json_encode( array_values( (array) $raw ) );
                } else {
                    // Valeur par défaut pour radio
                    $options = $type === 'radio' ? wp_json_encode( [ 'Oui', 'Non' ] ) : null;
                }

            } elseif ( in_array( $type, [ 'html', 'consent' ] ) ) {
                // Contenu textuel/HTML → première entrée du tableau JSON
                $content = $field['options'] ?? '';
                if ( is_array( $content ) ) {
                    $content = $content[0] ?? '';
                }
                $options = wp_json_encode( [ wp_kses_post( $content ) ] );
            }

            // ── help_text ou logique conditionnelle ────────────────────────
            $help_text = '';
            if ( ! empty( $field['conditional'] ) && is_array( $field['conditional'] ) ) {
                // Stocker la condition en JSON dans help_text
                $help_text = wp_json_encode( $field['conditional'] );
            } elseif ( ! empty( $field['help_text'] ) ) {
                $help_text = sanitize_text_field( $field['help_text'] );
            }

            // ── Clé unique ─────────────────────────────────────────────────
            $field_key = sanitize_key( $field['field_key'] ?? $field['label'] ?? '' );
            if ( empty( $field_key ) ) {
                $field_key = 'field_' . $order;
            }

            $wpdb->insert(
                $table,
                [
                    'form_id'     => $form_id,
                    'field_key'   => $field_key,
                    'label'       => sanitize_text_field( $field['label'] ?? '' ),
                    'type'        => $type,
                    'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
                    'required'    => intval( $field['required'] ?? 0 ),
                    'options'     => $options,
                    'help_text'   => $help_text,
                    'sort_order'  => isset( $field['sort_order'] ) ? intval( $field['sort_order'] ) : $order,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ]
            );

            $order++;
        }

        return true;
    }

    /**
     * Réordonne les champs (drag & drop) sans effacer leur contenu.
     */
    public static function reorder_fields( int $form_id, array $ordered_ids ) : void {
        global $wpdb;
        foreach ( $ordered_ids as $order => $field_id ) {
            $wpdb->update(
                $wpdb->prefix . 'etik_form_fields',
                [ 'sort_order' => $order + 1 ],
                [ 'id' => intval( $field_id ), 'form_id' => $form_id ],
                [ '%d' ], [ '%d', '%d' ]
            );
        }
    }
}