<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Meta Box CPT etik_prestation + handlers de formulaire et AJAX.
 */
class Prestation_Meta {

    // ─── INIT ───────────────────────────────────────────────────────────────

    public function init() : void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post',      [ $this, 'save_meta' ] );

        add_action( 'admin_print_scripts-post-new.php', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_print_scripts-post.php',     [ $this, 'enqueue_admin_assets' ] );

        add_filter( 'manage_etik_prestation_posts_columns',        [ $this, 'add_custom_columns' ] );
        add_action( 'manage_etik_prestation_posts_custom_column',  [ $this, 'columns_content' ], 10, 2 );
        add_filter( 'manage_edit-etik_prestation_sortable_columns',[ $this, 'sortable_columns' ], 11 );

        // Formulaire de création rapide
        add_action( 'admin_post_wp_etik_create_prestation', [ $this, 'handle_creation' ] );

        // Formulaire d'édition (notre page custom, pas le WP editor)
        add_action( 'admin_post_wp_etik_update_prestation', [ $this, 'handle_update' ] );

        // Suppression depuis la liste
        add_action( 'admin_init', [ $this, 'handle_delete_prestation' ] );

        // AJAX planning
        add_action( 'wp_ajax_etik_add_planning_slot',    [ $this, 'ajax_add_planning_slot' ] );
        add_action( 'wp_ajax_etik_delete_planning_slot', [ $this, 'ajax_delete_planning_slot' ] );
    }

    // ─── ASSETS ─────────────────────────────────────────────────────────────

    public function enqueue_admin_assets() : void {
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );

        $js = "jQuery(document).ready(function($){"
            . "$('.color-picker').wpColorPicker();"
            . "$('.datepicker').datepicker({dateFormat:'yy-mm-dd'});"
            . "});";
        wp_add_inline_script( 'wp-color-picker', $js );
    }

    // ─── META BOXES ─────────────────────────────────────────────────────────

    public function add_meta_boxes() : void {
        add_meta_box(
            'etik_prestation_meta',
            __( 'Détails prestation', 'wp-etik-events' ),
            [ $this, 'meta_box_html' ],
            'etik_prestation',
            'normal',
            'high'
        );
    }

    /**
     * Champs méta partagés entre la meta box (CPT) et le formulaire de création rapide.
     *
     * @param \WP_Post|null $post  null = mode création
     */
    public function meta_box_html( $post = null ) : void {
        global $wpdb;

        $color    = $post ? get_post_meta( $post->ID, 'etik_prestation_color',            true ) : '#2aa78a';
        $price    = $post ? get_post_meta( $post->ID, 'etik_prestation_price',            true ) : '';
        $duration = $post ? get_post_meta( $post->ID, 'etik_prestation_duration',         true ) : '60';
        $payment  = $post ? get_post_meta( $post->ID, 'etik_prestation_payment_required', true ) : '';
        $maxpl    = $post ? get_post_meta( $post->ID, 'etik_prestation_max_place',        true ) : '1';
        $title    = $post ? $post->post_title   : '';
        $content  = $post ? $post->post_content : '';

        if ( $post ) {
            wp_nonce_field( 'etik_prestation_save_meta', 'etik_prestation_meta_nonce' );
        }

        
        $forms_list  = $wpdb->get_results(
            "SELECT id, title FROM {$wpdb->prefix}etik_forms
            WHERE attach_type IN ('prestation','all') ORDER BY title ASC"
        ) ?: [];
        $sel_form_id = $post ? (int) get_post_meta( $post->ID, 'etik_prestation_form_id', true ) : 0;
        ?>
        <style>
        .etik-meta-grid { display:flex; gap:24px; flex-wrap:wrap; }
        .etik-meta-col  { flex:1; min-width:220px; }
        .etik-meta-field { margin-bottom:14px; }
        .etik-meta-field label { font-weight:600; font-size:13px; display:block; margin-bottom:4px; }
        </style>

        <div class="etik-meta-grid">
            <!-- Colonne gauche -->
            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label for="etik_p_title">
                        <?php esc_html_e( 'Nom de la prestation', 'wp-etik-events' ); ?>
                        <?php if ( ! $post ) : ?>
                            <span style="color:#d63638;"> *</span>
                        <?php endif; ?>
                    </label>
                    <input type="text" id="etik_p_title" name="post_title"
                           value="<?php echo esc_attr( $title ); ?>"
                           placeholder="<?php esc_attr_e( 'Ex : Coaching individuel', 'wp-etik-events' ); ?>"
                           style="width:100%;" required>
                </div>

                <div class="etik-meta-field">
                    <label for="etik_p_content">
                        <?php esc_html_e( 'Description', 'wp-etik-events' ); ?>
                    </label>
                    <textarea id="etik_p_content" name="post_content"
                              rows="4" style="width:100%;"><?php echo esc_textarea( $content ); ?></textarea>
                </div>

                <div class="etik-meta-field">
                    <label><?php esc_html_e( 'Couleur (identifiant visuel)', 'wp-etik-events' ); ?></label>
                    <input type="text" name="etik_prestation_color"
                           value="<?php echo esc_attr( $color ?: '#2aa78a' ); ?>"
                           class="color-picker">
                    <p class="description" style="margin-top:4px;">
                        <?php esc_html_e( 'Utilisée dans le planning et la liste.', 'wp-etik-events' ); ?>
                    </p>
                </div>

                
                <div class="etik-meta-field">
                    <label for="etik_p_form_id">
                        <?php esc_html_e( 'Formulaire de réservation', 'wp-etik-events' ); ?>
                    </label>
                    <select id="etik_p_form_id" name="etik_prestation_form_id" style="width:100%;">
                        <option value="0">
                            <?php esc_html_e( '— Formulaire par défaut —', 'wp-etik-events' ); ?>
                        </option>
                        <?php foreach ( $forms_list as $fm ) : ?>
                            <option value="<?php echo esc_attr( $fm->id ); ?>"
                                <?php selected( $sel_form_id, (int) $fm->id ); ?>>
                                <?php echo esc_html( $fm->title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="margin-top:4px;">
                        <?php esc_html_e(
                            'Formulaire affiché lors de la réservation front. "Défaut" = formulaire general (attach_type=all).',
                            'wp-etik-events'
                        ); ?>
                    </p>
                </div>
            </div>

            <!-- Colonne droite -->
            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label for="etik_p_price">
                        <?php esc_html_e( 'Prix (€)', 'wp-etik-events' ); ?>
                    </label>
                    <input type="number" id="etik_p_price" name="etik_prestation_price"
                           value="<?php echo esc_attr( $price ); ?>"
                           step="0.01" min="0" style="width:130px;">
                </div>

                <div class="etik-meta-field">
                    <label for="etik_p_duration">
                        <?php esc_html_e( 'Durée par défaut (minutes)', 'wp-etik-events' ); ?>
                    </label>
                    <input type="number" id="etik_p_duration" name="etik_prestation_duration"
                           value="<?php echo esc_attr( $duration ?: '60' ); ?>"
                           min="5" max="480" step="5" style="width:100px;">
                    <p class="description" style="margin-top:4px;">
                        <?php esc_html_e( 'Pré-rempli dans le configurateur de créneaux.', 'wp-etik-events' ); ?>
                    </p>
                </div>

                <div class="etik-meta-field">
                    <label><?php esc_html_e( 'Paiement', 'wp-etik-events' ); ?></label>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:8px;margin-top:4px;">
                        <input type="checkbox" name="etik_prestation_payment_required"
                               value="1" <?php checked( $payment, '1' ); ?>>
                        <?php esc_html_e( 'Paiement requis pour valider la réservation', 'wp-etik-events' ); ?>
                    </label>
                </div>

                <div class="etik-meta-field">
                    <label for="etik_p_max">
                        <?php esc_html_e( 'Places maximum par créneau', 'wp-etik-events' ); ?>
                    </label>
                    <input type="number" id="etik_p_max" name="etik_prestation_max_place"
                           value="<?php echo esc_attr( $maxpl ?: '1' ); ?>"
                           min="1" step="1" style="width:100px;">
                </div>
            </div>
        </div>
        <?php
    }

    // ─── SAUVEGARDE META (CPT edit) ──────────────────────────────────────────

    public function save_meta( int $post_id ) : void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['etik_prestation_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['etik_prestation_meta_nonce'], 'etik_prestation_save_meta' ) ) return;
        // Le CPT n'est pas enregistré → lire le post_type directement depuis la DB
        global $wpdb;
        $type = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $post_id
        ) );
        if ( $type !== 'etik_prestation' ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $this->save_meta_fields( $post_id );
    }

    /**
     * Ecrit les métas en base depuis $_POST. Réutilisé par handle_creation().
     */
    private function save_meta_fields( int $post_id ) : void {
        $fields = [
            'etik_prestation_color'     => 'sanitize_text_field',
            'etik_prestation_price'     => 'floatval',
            'etik_prestation_duration'  => 'intval',
            'etik_prestation_max_place' => 'intval',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $post_id, $key, $sanitizer( $_POST[ $key ] ) );
            }
        }

        $payment = isset( $_POST['etik_prestation_payment_required'] ) ? '1' : '0';
        update_post_meta( $post_id, 'etik_prestation_payment_required', $payment );

        $form_id_save = max( 0, intval( $_POST['etik_prestation_form_id'] ?? 0 ) );
        update_post_meta( $post_id, 'etik_prestation_form_id', $form_id_save );
    }

    // ─── HANDLER CRÉATION ───────────────────────────────────────────────────

    public function handle_creation() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé.', 'wp-etik-events' ) );
        }
        if ( ! isset( $_POST['wp_etik_prestation_nonce'] )
             || ! wp_verify_nonce( $_POST['wp_etik_prestation_nonce'], 'wp_etik_create_prestation_nonce' ) ) {
            wp_die( __( 'Nonce invalide.', 'wp-etik-events' ) );
        }

        $title = trim( sanitize_text_field( wp_unslash( $_POST['post_title'] ?? '' ) ) );
        if ( empty( $title ) ) {
            $this->redirect_with_message( 'missing_title' );
        }

        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => wp_kses_post( wp_unslash( $_POST['post_content'] ?? '' ) ),
            'post_type'    => 'etik_prestation',
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
        ] );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            $this->redirect_with_message( 'db_error' );
        }

        $this->save_meta_fields( $post_id );
        $this->save_slots_from_post( $post_id );

        $this->redirect_with_message( 'prestation_created' );
    }

    // ─── HANDLER SUPPRESSION ────────────────────────────────────────────────

    public function handle_delete_prestation() : void {
        if ( ( $_GET['action'] ?? '' ) !== 'delete_prestation' ) return;
        if ( ! isset( $_GET['post_id'] ) || ! current_user_can( 'manage_options' ) ) return;

        $post_id = intval( $_GET['post_id'] );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'delete_prestation_' . $post_id ) ) {
            wp_die( __( 'Nonce invalide.', 'wp-etik-events' ) );
        }

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'etik_prestation_slots',
            [ 'prestation_id' => $post_id ], [ '%d' ] );
        wp_delete_post( $post_id, true );

        $this->redirect_with_message( 'prestation_deleted' );
    }

    // ─── HANDLER MISE À JOUR ────────────────────────────────────────────────

    public function handle_update() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé.', 'wp-etik-events' ) );
        }
        if ( ! isset( $_POST['wp_etik_prestation_nonce'] )
             || ! wp_verify_nonce( $_POST['wp_etik_prestation_nonce'], 'wp_etik_update_prestation_nonce' ) ) {
            wp_die( __( 'Nonce invalide.', 'wp-etik-events' ) );
        }

        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            $this->redirect_with_message( 'db_error' );
        }

        // Vérifier que la prestation existe bien dans wp_posts
        $existing = get_post( $post_id );
        if ( ! $existing || $existing->post_type !== 'etik_prestation' ) {
            $this->redirect_with_message( 'db_error' );
        }

        $title = trim( sanitize_text_field( wp_unslash( $_POST['post_title'] ?? '' ) ) );
        if ( empty( $title ) ) {
            $this->redirect_with_message( 'missing_title' );
        }

        wp_update_post( [
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => wp_kses_post( wp_unslash( $_POST['post_content'] ?? '' ) ),
        ] );

        $this->save_meta_fields( $post_id );

        $this->redirect_with_message( 'prestation_updated' );
    }

    

    private function save_slots_from_post( int $post_id ) : void {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_prestation_slots';

        $slots_post = $_POST['slots'] ?? [];
        if ( empty( $slots_post ) || ! is_array( $slots_post ) ) return;

        foreach ( $slots_post as $block ) {
            $days_data = $block['days'] ?? [];
            if ( empty( $days_data ) ) continue;

            // Regrouper les jours par combinaison (time, duration, break)
            $groups = [];
            foreach ( $days_data as $day_num => $day ) {
                if ( empty( $day['enabled'] ) ) continue;

                $start_time    = sanitize_text_field( $day['start_time']    ?? '09:00' );
                $duration      = max( 5, intval( $day['duration']           ?? 60 ) );
                $break_duration = max( 0, intval( $day['break_duration']    ?? 0 ) );
                $key = "{$start_time}|{$duration}|{$break_duration}";

                if ( ! isset( $groups[ $key ] ) ) {
                    $groups[ $key ] = [
                        'start_time'     => $start_time,
                        'duration'       => $duration,
                        'break_duration' => $break_duration,
                        'days'           => [],
                    ];
                }
                $groups[ $key ]['days'][] = (int) $day_num;
            }

            foreach ( $groups as $g ) {
                sort( $g['days'] );
                $wpdb->insert(
                    $table,
                    [
                        'prestation_id'  => $post_id,
                        'type'           => 'recurrent',
                        'start_time'     => $g['start_time'],
                        'duration'       => $g['duration'],
                        'break_duration' => $g['break_duration'],
                        'days'           => implode( ',', $g['days'] ),
                        'is_closed'      => 0,
                    ],
                    [ '%d', '%s', '%s', '%d', '%d', '%s', '%d' ]
                );
            }
        }
    }

    // ─── AJAX : AJOUTER UN CRÉNEAU VIA PLANNING ─────────────────────────────

    public function ajax_add_planning_slot() : void {
        check_ajax_referer( 'etik_add_planning_slot', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Accès refusé.', 'wp-etik-events' ) );
        }

        $prestation_id  = intval( $_POST['prestation_id'] ?? 0 );
        $days_raw       = sanitize_text_field( $_POST['days'] ?? '' );
        $start_time     = sanitize_text_field( $_POST['start_time'] ?? '' );
        $duration       = max( 5, intval( $_POST['duration']       ?? 60 ) );
        $break_duration = max( 0, intval( $_POST['break_duration'] ?? 0 ) );

        if ( ! $prestation_id || ! $days_raw || ! $start_time ) {
            wp_send_json_error( __( 'Paramètres manquants.', 'wp-etik-events' ) );
        }

        // ── Validation : prestation_id doit exister dans wp_posts ────────────
        // On NE PAS utiliser get_post_type() car le CPT n'est pas enregistré.
        // On vérifie directement que le post existe et a le bon type.
        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'etik_prestation' LIMIT 1",
                $prestation_id
            )
        );
        if ( ! $exists ) {
            wp_send_json_error( __( 'Prestation introuvable (id=' . $prestation_id . ').', 'wp-etik-events' ) );
        }

        // Valider et normaliser les jours (1-7)
        $days = array_values( array_filter(
            array_map( 'intval', explode( ',', $days_raw ) ),
            fn( $d ) => $d >= 1 && $d <= 7
        ) );
        if ( empty( $days ) ) {
            wp_send_json_error( __( 'Aucun jour valide.', 'wp-etik-events' ) );
        }
        sort( $days );

        // Valider format heure HH:MM
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start_time ) ) {
            wp_send_json_error( __( 'Format d\'heure invalide.', 'wp-etik-events' ) );
        }

        $days_str = implode( ',', $days );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'etik_prestation_slots',
            [
                'prestation_id'  => $prestation_id,
                'type'           => 'recurrent',
                'start_time'     => $start_time,
                'duration'       => $duration,
                'break_duration' => $break_duration,
                'days'           => $days_str,
                'is_closed'      => 0,
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%d' ]
        );

        if ( ! $inserted ) {
            // Retourner l'erreur SQL pour faciliter le débogage
            wp_send_json_error(
                __( 'Erreur d\'insertion.', 'wp-etik-events' )
                . ( $wpdb->last_error ? ' SQL: ' . $wpdb->last_error : '' )
            );
        }

        wp_send_json_success( [
            'slot_id' => $wpdb->insert_id,
            'message' => __( 'Créneau ajouté avec succès.', 'wp-etik-events' ),
        ] );
    }

    // ─── AJAX : SUPPRIMER UN CRÉNEAU VIA PLANNING ───────────────────────────

    public function ajax_delete_planning_slot() : void {
        check_ajax_referer( 'etik_add_planning_slot', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Accès refusé.', 'wp-etik-events' ) );
        }

        $slot_id = intval( $_POST['slot_id'] ?? 0 );
        if ( ! $slot_id ) {
            wp_send_json_error( __( 'ID créneau manquant.', 'wp-etik-events' ) );
        }

        global $wpdb;
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'etik_prestation_slots',
            [ 'id' => $slot_id ],
            [ '%d' ]
        );

        if ( ! $deleted ) {
            wp_send_json_error( __( 'Créneau introuvable ou déjà supprimé.', 'wp-etik-events' ) );
        }

        wp_send_json_success( [ 'message' => __( 'Créneau supprimé.', 'wp-etik-events' ) ] );
    }

    // ─── COLONNES LISTE CPT ─────────────────────────────────────────────────

    public function add_custom_columns( array $columns ) : array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['etik_color']    = __( 'Couleur', 'wp-etik-events' );
                $new['etik_price']    = __( 'Prix', 'wp-etik-events' );
                $new['etik_duration'] = __( 'Durée', 'wp-etik-events' );
                $new['etik_maxpl']    = __( 'Places', 'wp-etik-events' );
                $new['etik_slots']    = __( 'Créneaux', 'wp-etik-events' );
            }
        }
        return $new;
    }

    public function columns_content( string $col, int $post_id ) : void {
        global $wpdb;
        switch ( $col ) {
            case 'etik_color':
                $c = get_post_meta( $post_id, 'etik_prestation_color', true ) ?: '#2aa78a';
                echo '<span style="display:inline-block;width:18px;height:18px;border-radius:50%;'
                   . 'background:' . esc_attr( $c ) . ';border:1px solid rgba(0,0,0,.15);vertical-align:middle;"></span>';
                break;
            case 'etik_price':
                $p = get_post_meta( $post_id, 'etik_prestation_price', true );
                echo $p !== '' ? esc_html( number_format_i18n( (float) $p, 2 ) ) . ' €' : '—';
                break;
            case 'etik_duration':
                $d = get_post_meta( $post_id, 'etik_prestation_duration', true );
                echo $d ? esc_html( $d ) . ' min' : '—';
                break;
            case 'etik_maxpl':
                $m = get_post_meta( $post_id, 'etik_prestation_max_place', true );
                echo $m ? esc_html( $m ) : '<span style="color:#888;">∞</span>';
                break;
            case 'etik_slots':
                $cnt = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}etik_prestation_slots WHERE prestation_id = %d AND is_closed = 0",
                        $post_id
                    )
                );
                echo $cnt
                    ? '<strong style="color:#0b7a4b;">' . esc_html( $cnt ) . '</strong>'
                    : '<span style="color:#888;font-style:italic;font-size:11px;">Aucun</span>';
                break;
        }
    }

    public function sortable_columns( array $columns ) : array {
        $columns['etik_price'] = 'etik_prestation_price';
        return $columns;
    }

    // ─── HELPER ─────────────────────────────────────────────────────────────

    private function redirect_with_message( string $key ) : void {
        wp_safe_redirect( add_query_arg(
            [ 'page' => Prestation_Settings::MENU_SLUG, 'message' => $key ],
            admin_url( 'edit.php?post_type=etik_event' )
        ) );
        exit;
    }
}