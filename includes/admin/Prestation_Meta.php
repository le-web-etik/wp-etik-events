<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prestation_Meta {

    public function init() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta' ] );
        add_action( 'admin_print_scripts-post-new.php', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_print_scripts-post.php', [ $this, 'enqueue_admin_assets' ] );
        add_filter( 'manage_etik_prestation_posts_columns', [ $this, 'add_custom_columns' ] );
        add_action( 'manage_etik_prestation_posts_custom_column', [ $this, 'columns_content_etik_prestation' ], 10, 2 );
        add_filter( "manage_edit-etik_prestation_sortable_columns", [ $this, 'lwe_sortable_columns' ], 11 );

        add_action( 'admin_post_wp_etik_create_prestation', [ $this, 'handle_creation' ] );
    }

    public function enqueue_admin_assets() {

        // Color Picker WordPress
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        // Datepicker
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );

        // Initialisation JS
        $js_init = "jQuery(document).ready(function($){ $('.color-picker').wpColorPicker(); $('.datepicker').datepicker({ dateFormat: 'yy-mm-dd' }); });";
        wp_add_inline_script( 'wp-color-picker', $js_init );
    }

    public function add_meta_boxes() {
        add_meta_box( 'etik_prestation_meta', __( 'Détails prestation', 'wp-etik-events' ), [ $this, 'meta_box_html' ], 'etik_prestation', 'normal', 'high' );
    }

    /**********
    public function meta_box_html( $post = null ) {
        $color = $post ? get_post_meta( $post->ID, 'etik_prestation_color', true ) : '';
        $price = $post ? get_post_meta( $post->ID, 'etik_prestation_price', true ) : '';
        $payment_required = $post ? get_post_meta( $post->ID, 'etik_prestation_payment_required', true ) : '';
        $max_place = $post ? get_post_meta( $post->ID, 'etik_prestation_max_place', true ) : '';

        wp_nonce_field( 'etik_prestation_save_meta', 'etik_prestation_meta_nonce' );
        ?>
        <div class="etik-meta-grid">
            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label><?php _e( 'Nom', 'wp-etik-events' ); ?></label>
                    <input type="text" name="post_title" value="<?php echo $post ? esc_attr( $post->post_title ) : ''; ?>" required />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e( 'Description', 'wp-etik-events' ); ?></label>
                    <textarea name="post_content" rows="5"><?php echo $post ? esc_textarea( $post->post_content ) : ''; ?></textarea>
                </div>

                <div class="etik-meta-field">
                    <label><?php _e( 'Couleur', 'wp-etik-events' ); ?></label>
                    <input type="text" name="etik_prestation_color" value="<?php echo esc_attr( $color ); ?>" class="color-picker" />
                </div>
            </div>

            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label><?php _e( 'Prix', 'wp-etik-events' ); ?></label>
                    <input type="number" step="0.01" name="etik_prestation_price" value="<?php echo esc_attr( $price ); ?>" />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e( 'Paiement obligatoire', 'wp-etik-events' ); ?></label>
                    <div class="small">
                        <input type="checkbox" name="etik_prestation_payment_required" value="1" <?php checked( $payment_required, '1' ); ?> />
                        <?php _e( 'Cocher si le paiement est requis pour valider l\'inscription', 'wp-etik-events' ); ?>
                    </div>
                </div>

                <div class="etik-meta-field">
                    <label><?php _e( 'Places maximum par créneau', 'wp-etik-events' ); ?></label>
                    <input type="number" name="etik_prestation_max_place" value="<?php echo esc_attr( $max_place ); ?>" min="1" />
                    <div class="small"><?php _e( 'Par défaut 1', 'wp-etik-events' ); ?></div>
                </div>
            </div>
        </div>
        <?php
    }
    **********/

    /**
     * Affiche le formulaire (Meta Box ou Formulaire de création)
     * 
     * @param WP_Post|null $post L'objet post si édition, null si création
     */
    public function meta_box_html( $post = null ) {
        // Récupération des valeurs (si $post existe) sinon valeurs par défaut
        $color            = $post ? get_post_meta( $post->ID, 'etik_prestation_color', true ) : '#2aa78a';
        $price            = $post ? get_post_meta( $post->ID, 'etik_prestation_price', true ) : '';
        $payment_required = $post ? get_post_meta( $post->ID, 'etik_prestation_payment_required', true ) : '';
        $max_place        = $post ? get_post_meta( $post->ID, 'etik_prestation_max_place', true ) : '1';
        
        $title            = $post ? $post->post_title : '';
        $content          = $post ? $post->post_content : '';

        // Affichage du nonce UNIQUEMENT si on est dans une meta box standard (pas dans notre form personnalisé)
        // Ou on peut le mettre toujours, mais le form personnalisé aura son propre nonce.
        // Ici, on suppose que si $post est null, c'est notre form personnalisé qui gère le nonce ailleurs.
        if ( $post ) {
            wp_nonce_field( 'etik_prestation_save_meta', 'etik_prestation_meta_nonce' );
        }
        ?>
        <div class="etik-meta-grid">
            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label><?php _e( 'Nom de la prestation', 'wp-etik-events' ); ?> <?php if(!$post) echo '<span style="color:red">*</span>'; ?></label>
                    <input type="text" name="post_title" value="<?php echo esc_attr( $title ); ?>" required style="width:100%;" <?php echo $post ? '' : 'placeholder="Ex: Coaching Individuel"'; ?> />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e( 'Description', 'wp-etik-events' ); ?></label>
                    <textarea name="post_content" rows="5" style="width:100%;"><?php echo esc_textarea( $content ); ?></textarea>
                </div>

                <div class="etik-meta-field">
                    <label><?php _e( 'Couleur (identifiant visuel)', 'wp-etik-events' ); ?></label>
                    <input type="text" name="etik_prestation_color" value="<?php echo esc_attr( $color ); ?>" class="color-picker" />
                    <?php if ( ! $post ) : ?>
                        <p class="description"><?php _e( 'Utilisé pour l\'affichage dans le calendrier.', 'wp-etik-events' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label><?php _e( 'Prix (€)', 'wp-etik-events' ); ?></label>
                    <input type="number" step="0.01" name="etik_prestation_price" value="<?php echo esc_attr( $price ); ?>" style="width:100%;" />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e( 'Paiement obligatoire', 'wp-etik-events' ); ?></label>
                    <div style="margin-top:5px;">
                        <input type="checkbox" name="etik_prestation_payment_required" id="etik_payment_req" value="1" <?php checked( $payment_required, '1' ); ?> />
                        <label for="etik_payment_req" style="font-weight:normal;"><?php _e( 'Requis pour valider la réservation', 'wp-etik-events' ); ?></label>
                    </div>
                </div>

                <div class="etik-meta-field">
                    <label><?php _e( 'Places maximum par créneau', 'wp-etik-events' ); ?></label>
                    <input type="number" name="etik_prestation_max_place" value="<?php echo esc_attr( $max_place ); ?>" min="1" style="width:100%;" />
                    <?php if ( ! $post ) : ?>
                        <div class="small"><?php _e( 'Par défaut 1', 'wp-etik-events' ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handler pour la création via formulaire personnalisé (admin-post)
     */
    public function handle_creation() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé.', 'wp-etik-events' ) );
        }

        check_admin_referer( 'wp_etik_create_prestation_nonce', 'wp_etik_prestation_nonce' );

        $title   = isset( $_POST['post_title'] ) ? sanitize_text_field( $_POST['post_title'] ) : '';
        $content = isset( $_POST['post_content'] ) ? wp_kses_post( $_POST['post_content'] ) : '';

        if ( empty( $title ) ) {
            wp_redirect( add_query_arg( [ 'page' => 'wp-etik-prestation', 'action' => 'add', 'error' => 'missing_title' ], admin_url( 'edit.php?post_type=etik_event' ) ) );
            exit;
        }

        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $content,
            'post_type'    => 'etik_prestation',
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_redirect( add_query_arg( [ 'page' => 'wp-etik-prestation', 'action' => 'add', 'error' => 'db_error' ], admin_url( 'edit.php?post_type=etik_event' ) ) );
            exit;
        }

        // On réutilise la logique de save_meta en simulant un POST pour les métas
        // Ou on appelle directement les update_post_meta (plus propre ici)
        $this->save_meta_custom( $post_id );

        wp_redirect( add_query_arg( [ 'page' => 'wp-etik-prestation', 'message' => 'prestation_created' ], admin_url( 'edit.php?post_type=etik_event' ) ) );
        exit;
    }

    /**
     * Sauvegarde des métas spécifique pour le handler de création
     * (Duplique la logique de save_meta pour éviter les conflits de nonce/contexte)
     */
    private function save_meta_custom( $post_id ) {
        $color_val = isset( $_POST['etik_prestation_color'] ) ? sanitize_text_field( $_POST['etik_prestation_color'] ) : '';
        update_post_meta( $post_id, 'etik_prestation_color', $color_val );

        $price_val = isset( $_POST['etik_prestation_price'] ) ? floatval( $_POST['etik_prestation_price'] ) : 0;
        update_post_meta( $post_id, 'etik_prestation_price', $price_val );

        $payment_required = isset( $_POST['etik_prestation_payment_required'] ) ? '1' : '0';
        update_post_meta( $post_id, 'etik_prestation_payment_required', $payment_required );

        $max_place = isset( $_POST['etik_prestation_max_place'] ) ? intval( $_POST['etik_prestation_max_place'] ) : 1;
        update_post_meta( $post_id, 'etik_prestation_max_place', $max_place );
    }

    public function save_meta( $post_id ) {
        // sécurité
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['etik_prestation_meta_nonce'] ) || ! wp_verify_nonce( $_POST['etik_prestation_meta_nonce'], 'etik_prestation_save_meta' ) ) return;
        if ( get_post_type( $post_id ) !== 'etik_prestation' ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $this->save_meta_custom( $post_id );
    }

    public function add_custom_columns( $columns ) {
        $columns['lwe_prestation_color'] = 'Couleur';
        $columns['lwe_prestation_price'] = 'Prix';
        $columns['lwe_prestation_max_place'] = 'Places';
        return $columns;
    }

    public function columns_content_etik_prestation( $column_name, $post_ID ) {
        $color = get_post_meta( $post_ID, 'etik_prestation_color', true );
        $price = get_post_meta( $post_ID, 'etik_prestation_price', true );
        $max_place = get_post_meta( $post_ID, 'etik_prestation_max_place', true );

        if ( $column_name == 'lwe_prestation_color' ) {
            echo '<span style="display:inline-block;width:20px;height:20px;background:' . esc_attr( $color ) . ';border:1px solid #000;"></span>';
        }
        if ( $column_name == 'lwe_prestation_price' ) {
            echo esc_html( $price );
        }
        if ( $column_name == 'lwe_prestation_max_place' ) {
            echo esc_html( $max_place );
        }
    }

    public function lwe_sortable_columns( $columns ) {
        $columns['lwe_prestation_price'] = 'etik_prestation_price';
        $columns['lwe_prestation_max_place'] = 'etik_prestation_max_place';
        return $columns;
    }
}