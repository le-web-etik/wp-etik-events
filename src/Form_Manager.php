<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/**
 * CRUD pour les formulaires et leurs champs.
 * Utilisé par Form_Builder_Admin et les handlers AJAX.
 */
class Form_Manager {

    // ── Types de champs supportés ──────────────────────────────────────────

    public static function get_field_types() : array {
        return [
            'text'     => [ 'label' => 'Texte court',       'icon' => 'T' ],
            'email'    => [ 'label' => 'E-mail',             'icon' => '@' ],
            'tel'      => [ 'label' => 'Téléphone',          'icon' => '☎' ],
            'number'   => [ 'label' => 'Nombre',             'icon' => '#' ],
            'textarea' => [ 'label' => 'Texte long',         'icon' => '¶' ],
            'checkbox' => [ 'label' => 'Case à cocher',      'icon' => '✓' ],
            'select'   => [ 'label' => 'Liste déroulante',   'icon' => '▾' ],
            'date'     => [ 'label' => 'Date',               'icon' => '📅' ],
        ];
    }

    // ── Formulaires ────────────────────────────────────────────────────────

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
        $table = $wpdb->prefix . 'etik_forms';
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $form_id ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Crée ou met à jour un formulaire.
     *
     * @param array $data  { title, description, slug, attach_type, is_default }
     * @param int   $id    0 = création, >0 = mise à jour
     * @return int|\WP_Error  form_id ou erreur
     */
    public static function save_form( array $data, int $id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_forms';

        $row = [
            'title'       => sanitize_text_field( $data['title'] ?? '' ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'slug'        => sanitize_title( $data['slug'] ?? $data['title'] ?? '' ),
            'attach_type' => in_array( $data['attach_type'] ?? '', ['event','prestation','all'] )
                             ? $data['attach_type']
                             : 'all',
            'is_default'  => intval( $data['is_default'] ?? 0 ),
        ];

        if ( empty( $row['title'] ) ) {
            return new \WP_Error( 'missing_title', 'Le titre du formulaire est requis.' );
        }

        if ( $id > 0 ) {
            $result = $wpdb->update( $table, $row, ['id' => $id], ['%s','%s','%s','%s','%d'], ['%d'] );
            if ( $result === false ) {
                return new \WP_Error( 'db_error', 'Erreur lors de la mise à jour.' );
            }
            return $id;
        }

        // Assurer l'unicité du slug
        $base_slug = $row['slug'];
        $counter   = 1;
        while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $row['slug'] ) ) ) {
            $row['slug'] = $base_slug . '-' . $counter++;
        }

        $result = $wpdb->insert( $table, $row, ['%s','%s','%s','%s','%d'] );
        if ( ! $result ) {
            return new \WP_Error( 'db_error', 'Erreur lors de la création.' );
        }
        return (int) $wpdb->insert_id;
    }

    public static function delete_form( int $form_id ) : bool {
        global $wpdb;

        // Protéger le formulaire par défaut
        $form = self::get_form( $form_id );
        if ( $form && intval( $form['is_default'] ) === 1 ) {
            return false;
        }

        $wpdb->delete( $wpdb->prefix . 'etik_form_fields', ['form_id' => $form_id], ['%d'] );
        $deleted = $wpdb->delete( $wpdb->prefix . 'etik_forms', ['id' => $form_id], ['%d'] );
        return (bool) $deleted;
    }

    // ── Champs ─────────────────────────────────────────────────────────────

    public static function get_fields( int $form_id ) : array {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_form_fields';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d ORDER BY sort_order ASC, id ASC",
                $form_id
            ),
            ARRAY_A
        ) ?: [];

        // Décoder options JSON
        foreach ( $rows as &$row ) {
            if ( ! empty( $row['options'] ) ) {
                $row['options_decoded'] = json_decode( $row['options'], true ) ?: [];
            } else {
                $row['options_decoded'] = [];
            }
        }
        return $rows;
    }

    /**
     * Sauvegarde tous les champs d'un formulaire (remplace l'existant).
     *
     * @param int   $form_id
     * @param array $fields  Tableau de champs (chacun = associatif)
     * @return bool|\WP_Error
     */
    public static function save_fields( int $form_id, array $fields ) {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_form_fields';

        // Supprimer les champs existants
        $wpdb->delete( $table, ['form_id' => $form_id], ['%d'] );

        $valid_types = array_keys( self::get_field_types() );
        $order = 1;

        foreach ( $fields as $field ) {
            $type = in_array( $field['type'] ?? '', $valid_types ) ? $field['type'] : 'text';

            // Encoder les options select en JSON
            $options = null;
            if ( $type === 'select' && ! empty( $field['options'] ) ) {
                $raw_options = is_array( $field['options'] )
                    ? $field['options']
                    : array_filter( array_map( 'trim', explode( "\n", $field['options'] ) ) );
                $options = wp_json_encode( array_values( $raw_options ) );
            }

            // Générer le field_key depuis le label si non fourni
            $field_key = sanitize_key( $field['field_key'] ?? $field['label'] ?? 'champ' );
            if ( empty( $field_key ) ) {
                $field_key = 'field_' . $order;
            }

            $wpdb->insert( $table, [
                'form_id'     => $form_id,
                'field_key'   => $field_key,
                'label'       => sanitize_text_field( $field['label'] ?? '' ),
                'type'        => $type,
                'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
                'required'    => intval( $field['required'] ?? 0 ),
                'options'     => $options,
                'help_text'   => sanitize_text_field( $field['help_text'] ?? '' ),
                'sort_order'  => $order,
            ], [ '%d','%s','%s','%s','%s','%d','%s','%s','%d' ] );

            $order++;
        }

        return true;
    }

    /**
     * Remet à jour uniquement les sort_order (pour le drag & drop via AJAX).
     *
     * @param int   $form_id
     * @param array $ordered_ids  [ field_id1, field_id2, ... ] dans le nouvel ordre
     */
    public static function reorder_fields( int $form_id, array $ordered_ids ) : void {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_form_fields';
        foreach ( $ordered_ids as $order => $field_id ) {
            $wpdb->update(
                $table,
                [ 'sort_order' => $order + 1 ],
                [ 'id' => intval( $field_id ), 'form_id' => $form_id ],
                [ '%d' ],
                [ '%d', '%d' ]
            );
        }
    }
}