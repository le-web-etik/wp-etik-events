<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Meta Box et gestion des champs CPT etik_prestation.
 *
 * Champs méta : couleur, prix, durée par défaut, paiement requis, places max.
 * Handler de création (admin-post) : crée le CPT + les créneaux récurrents.
 * Handler de suppression : supprime le CPT + ses créneaux.
 */
class Prestation_Meta {

    // ─────────────────────────────────────────────────────────────────────────
    // INIT
    // ─────────────────────────────────────────────────────────────────────────

    public function init() : void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post',      [ $this, 'save_meta' ] );

        add_action( 'admin_print_scripts-post-new.php', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_print_scripts-post.php',     [ $this, 'enqueue_admin_assets' ] );

        add_filter( 'manage_etik_prestation_posts_columns',        [ $this, 'add_custom_columns' ] );
        add_action( 'manage_etik_prestation_posts_custom_column',   [ $this, 'columns_content' ], 10, 2 );
        add_filter( 'manage_edit-etik_prestation_sortable_columns', [ $this, 'sortable_columns' ], 11 );

        // Handlers de formulaire (admin-post)
        add_action( 'admin_post_wp_etik_create_prestation', [ $this, 'handle_creation' ] );
        add_action( 'admin_init',                            [ $this, 'handle_delete_prestation' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ASSETS
    // ─────────────────────────────────────────────────────────────────────────

    public function enqueue_admin_assets() : void {
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );

        $js = "jQuery(document).ready(function($){ $('.color-picker').wpColorPicker(); $('.datepicker').datepicker({ dateFormat: 'yy-mm-dd' }); });";
        wp_add_inline_script( 'wp-color-picker', $js );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // META BOX (édition CPT)
    // ─────────────────────────────────────────────────────────────────────────

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
     * Affiche les champs méta de la prestation.
     * Utilisé à la fois dans la meta box (CPT édition) et dans le formulaire de création rapide.
     *
     * @param \WP_Post|null $post  null = formulaire de création
     */
    public function meta_box_html( $post = null ) : void {
        $color     = $post ? get_post_meta( $post->ID, 'etik_prestation_color',            true ) : '#2aa78a';
        $price     = $post ? get_post_meta( $post->ID, 'etik_prestation_price',            true ) : '';
        $duration  = $post ? get_post_meta( $post->ID, 'etik_prestation_duration',         true ) : '60';
        $payment   = $post ? get_post_meta( $post->ID, 'etik_prestation_payment_required', true ) : '';
        $max_place = $post ? get_post_meta( $post->ID, 'etik_prestation_max_place',        true ) : '1';
        $title     = $post ? $post->post_title   : '';
        $content   = $post ? $post->post_content : '';

        if ( $post ) {
            wp_nonce_field( 'etik_prestation_save_meta', 'etik_prestation_meta_nonce' );
        }
        ?>
        <div class="etik-meta-grid">

            <!-- Colonne gauche -->
            <div class="etik-meta-col">

                <div class="etik-meta-field">
                    <label for="etik_p_title">
                        <?php esc_html_e( 'Nom de la prestation', 'wp-etik-events' ); ?>
                        <?php if ( ! $post ) : ?><span style="color:#a12d2d;"> *</span><?php endif; ?>
                    </label>
                    <input type="text" id="etik_p_title" name="post_title"
                           value="<?php echo esc_attr( $title ); ?>"
                           placeholder="<?php esc_attr_e( 'Ex : Coaching individuel', 'wp-etik-events' ); ?>"
                           style="width:100%;" required>
                </div>

                <div class="etik-meta-field">
                    <label for="etik_p_content"><?php esc_html_e( 'Description', 'wp-etik-events' ); ?></label>
                    <textarea id="etik_p_content" name="post_content" rows="4"
                              style="width:100%;"><?php echo esc_textarea( $content ); ?></textarea>
                </div>

                <div class="etik-meta-field">
                    <label><?php esc_html_e( 'Couleur (identifiant visuel)', 'wp-etik-events' ); ?></label>
                    <input type="text" name="etik_prestation_color"
                           value="<?php echo esc_attr( $color ); ?>"
                           class="color-picker">
                    <p class="description"><?php esc_html_e( 'Utilisée dans le calendrier et la liste.', 'wp-etik-events' ); ?></p>
                </div>

            </div>

            <!-- Colonne droite -->
            <div class="etik-meta-col">

                <div class="etik-meta-field">
                    <label for="etik_p_price"><?php esc_html_e( 'Prix (€)', 'wp-etik-events' ); ?></label>
                    <input type="number" id="etik_p_price" name="etik_prestation_price"
                           value="<?php echo esc_attr( $price ); ?>"
                           step="0.01" min="0" style="width:140px;">
                </div>

                <div class="etik-meta-field">
                    <label for="etik_p_duration">
                        <?php esc_html_e( 'Durée par défaut (minutes)', 'wp-etik-events' ); ?>
                    </label>
                    <input type="number" id="etik_p_duration" name="etik_prestation_duration"
                           value="<?php echo esc_attr( $duration !== '' ? $duration : '60' ); ?>"
                           min="5" max="480" step="5" style="width:100px;">
                    <p class="description"><?php esc_html_e( 'Durée proposée par défaut lors de la configuration des créneaux.', 'wp-etik-events' ); ?></p>
                </div>

                <div class="etik-meta-field">
                    <label><?php esc_html_e( 'Paiement', 'wp-etik-events' ); ?></label>
                    <div style="margin-top:6px;">
                        <label style="font-weight:normal;display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="etik_prestation_payment_required" value="1"
                                   <?php checked( $payment, '1' ); ?>>
                            <?php esc_html_e( 'Paiement requis pour valider la réservation', 'wp-etik-events' ); ?>
                        </label>
                    </div>
                </div>

                <div class="etik-meta-field">
                    <label for="etik_p_max"><?php esc_html_e( 'Places maximum par créneau', 'wp-etik-events' ); ?></label>
                    <input type="number" id="etik_p_max" name="etik_prestation_max_place"
                           value="<?php echo esc_attr( $max_place !== '' ? $max_place : '1' ); ?>"
                           min="1" step="1" style="width:100px;">
                    <p class="description"><?php esc_html_e( 'Nombre maximum de réservations simultanées pour ce créneau.', 'wp-etik-events' ); ?></p>
                </div>

            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SAUVEGARDE DES MÉTAS (CPT edit page)
    // ─────────────────────────────────────────────────────────────────────────

    public function save_meta( int $post_id ) : void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['etik_prestation_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['etik_prestation_meta_nonce'], 'etik_prestation_save_meta' ) ) return;
        if ( get_post_type( $post_id ) !== 'etik_prestation' ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $this->save_meta_fields( $post_id );
    }

    /**
     * Enregistre tous les champs méta en base depuis $_POST.
     * Appelée depuis save_meta() et handle_creation().
     */
    private function save_meta_fields( int $post_id ) : void {
        $fields = [
            'etik_prestation_color'            => 'sanitize_text_field',
            'etik_prestation_price'            => 'floatval',
            'etik_prestation_duration'         => 'intval',
            'etik_prestation_max_place'        => 'intval',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $post_id, $key, $sanitizer( $_POST[ $key ] ) );
            }
        }

        // Checkbox paiement
        $payment = isset( $_POST['etik_prestation_payment_required'] ) ? '1' : '0';
        update_post_meta( $post_id, 'etik_prestation_payment_required', $payment );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HANDLER CRÉATION (admin-post)
    // ─────────────────────────────────────────────────────────────────────────

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

        // Méta
        $this->save_meta_fields( $post_id );

        // Créneaux récurrents
        $this->save_slots_from_post( $post_id );

        $this->redirect_with_message( 'prestation_created' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HANDLER SUPPRESSION
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_delete_prestation() : void {
        if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'delete_prestation' ) return;
        if ( ! isset( $_GET['post_id'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $post_id = intval( $_GET['post_id'] );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'delete_prestation_' . $post_id ) ) {
            wp_die( __( 'Nonce invalide.', 'wp-etik-events' ) );
        }

        // Supprimer les créneaux associés
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'etik_prestation_slots',
            [ 'prestation_id' => $post_id ],
            [ '%d' ]
        );

        // Supprimer le CPT (dans la corbeille)
        wp_delete_post( $post_id, true );

        $this->redirect_with_message( 'prestation_deleted' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SAUVEGARDE DES CRÉNEAUX
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lit les données POST[slots] et crée les lignes dans etik_prestation_slots.
     *
     * Structure POST attendue :
     *   slots[0][days][1][enabled] = 1
     *   slots[0][days][1][start_time] = 09:00
     *   slots[0][days][1][duration] = 60
     *   slots[0][days][1][break_duration] = 15
     *   ...
     *
     * Chaque "bloc" de jours ayant la même (start_time, duration, break) est regroupé
     * en un seul enregistrement (days = "1,2,3,4,5").
     * Les jours avec des paramètres différents créent des enregistrements séparés.
     */
    private function save_slots_from_post( int $post_id ) : void {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_prestation_slots';

        $slots_post = $_POST['slots'] ?? [];
        if ( empty( $slots_post ) || ! is_array( $slots_post ) ) {
            return;
        }

        foreach ( $slots_post as $block ) {
            $days_data = $block['days'] ?? [];
            if ( empty( $days_data ) ) continue;

            // Regrouper les jours par combinaison (start_time, duration, break)
            $groups = [];
            foreach ( $days_data as $day_num => $day ) {
                if ( empty( $day['enabled'] ) ) continue;

                $start_time    = sanitize_text_field( $day['start_time']    ?? '09:00' );
                $duration      = max( 5, intval( $day['duration']            ?? 60 ) );
                $break_duration = max( 0, intval( $day['break_duration']     ?? 15 ) );

                // Clé de regroupement
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

            // Insérer un enregistrement par groupe
            foreach ( $groups as $group ) {
                sort( $group['days'] );
                $days_str = implode( ',', $group['days'] );

                $wpdb->insert(
                    $table,
                    [
                        'prestation_id'  => $post_id,
                        'type'           => 'recurrent',
                        'start_time'     => $group['start_time'],
                        'duration'       => $group['duration'],
                        'break_duration' => $group['break_duration'],
                        'days'           => $days_str,
                        'is_closed'      => 0,
                    ],
                    [ '%d', '%s', '%s', '%d', '%d', '%s', '%d' ]
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COLONNES PERSONNALISÉES (liste CPT)
    // ─────────────────────────────────────────────────────────────────────────

    public function add_custom_columns( array $columns ) : array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['etik_color']     = __( 'Couleur', 'wp-etik-events' );
                $new['etik_price']     = __( 'Prix', 'wp-etik-events' );
                $new['etik_duration']  = __( 'Durée', 'wp-etik-events' );
                $new['etik_max_place'] = __( 'Places', 'wp-etik-events' );
                $new['etik_slots']     = __( 'Créneaux', 'wp-etik-events' );
            }
        }
        return $new;
    }

    public function columns_content( string $col, int $post_id ) : void {
        global $wpdb;

        switch ( $col ) {
            case 'etik_color':
                $color = get_post_meta( $post_id, 'etik_prestation_color', true ) ?: '#2aa78a';
                echo '<span style="display:inline-block;width:20px;height:20px;border-radius:50%;background:'
                   . esc_attr( $color ) . ';border:1px solid rgba(0,0,0,0.15);vertical-align:middle;"></span>';
                break;

            case 'etik_price':
                $p = get_post_meta( $post_id, 'etik_prestation_price', true );
                echo $p !== '' ? esc_html( number_format_i18n( (float) $p, 2 ) ) . ' €' : '—';
                break;

            case 'etik_duration':
                $d = get_post_meta( $post_id, 'etik_prestation_duration', true );
                echo $d ? esc_html( $d ) . ' min' : '—';
                break;

            case 'etik_max_place':
                $m = get_post_meta( $post_id, 'etik_prestation_max_place', true );
                echo $m ? esc_html( $m ) : '<span style="color:#888;">∞</span>';
                break;

            case 'etik_slots':
                $count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}etik_prestation_slots WHERE prestation_id = %d AND is_closed = 0",
                        $post_id
                    )
                );
                if ( $count > 0 ) {
                    echo '<span style="color:#0b7a4b;font-weight:600;">' . esc_html( $count ) . '</span>';
                } else {
                    echo '<span style="color:#888;font-style:italic;font-size:11px;">'
                       . esc_html__( 'Aucun', 'wp-etik-events' ) . '</span>';
                }
                break;
        }
    }

    public function sortable_columns( array $columns ) : array {
        $columns['etik_price']     = 'etik_prestation_price';
        $columns['etik_max_place'] = 'etik_prestation_max_place';
        return $columns;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function redirect_with_message( string $message_key ) : void {
        wp_safe_redirect( add_query_arg(
            [ 'page' => Prestation_Settings::MENU_SLUG, 'message' => $message_key ],
            admin_url( 'edit.php?post_type=etik_event' )
        ) );
        exit;
    }
}