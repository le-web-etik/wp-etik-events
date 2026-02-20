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
    }

    public function enqueue_admin_assets() {
        // Charger les scripts et styles
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
    }

    public function add_meta_boxes() {
        add_meta_box( 'etik_prestation_meta', __( 'Détails prestation', 'wp-etik-events' ), [ $this, 'meta_box_html' ], 'etik_prestation', 'normal', 'high' );
    }

    public function meta_box_html( $post ) {
        // Récupération des metas existantes
        $color = get_post_meta( $post->ID, 'etik_prestation_color', true );
        $price = get_post_meta( $post->ID, 'etik_prestation_price', true );
        $payment_required = get_post_meta( $post->ID, 'etik_prestation_payment_required', true );
        $max_place = get_post_meta( $post->ID, 'etik_prestation_max_place', true );

        wp_nonce_field( 'etik_prestation_save_meta', 'etik_prestation_meta_nonce' );
        ?>
        <div class="etik-meta-grid">
            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label><?php _e( 'Couleur', 'wp-etik-events' ); ?></label>
                    <input type="text" name="etik_prestation_color" value="<?php echo esc_attr( $color ); ?>" class="color-picker" />
                </div>

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
            </div>

            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label><?php _e( 'Places maximum par créneau', 'wp-etik-events' ); ?></label>
                    <input type="number" name="etik_prestation_max_place" value="<?php echo esc_attr( $max_place ); ?>" min="1" />
                    <div class="small"><?php _e( 'Par défaut 1', 'wp-etik-events' ); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_meta( $post_id ) {
        // sécurité
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['etik_prestation_meta_nonce'] ) || ! wp_verify_nonce( $_POST['etik_prestation_meta_nonce'], 'etik_prestation_save_meta' ) ) return;
        if ( get_post_type( $post_id ) !== 'etik_prestation' ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // sanitize & save
        $color_val = sanitize_text_field( $_POST['etik_prestation_color'] ?? '' );
        update_post_meta( $post_id, 'etik_prestation_color', $color_val );

        $price_val = isset( $_POST['etik_prestation_price'] ) ? floatval( $_POST['etik_prestation_price'] ) : 0;
        update_post_meta( $post_id, 'etik_prestation_price', $price_val );

        $payment_required = isset( $_POST['etik_prestation_payment_required'] ) ? '1' : '0';
        update_post_meta( $post_id, 'etik_prestation_payment_required', $payment_required );

        $max_place = isset( $_POST['etik_prestation_max_place'] ) ? intval( $_POST['etik_prestation_max_place'] ) : 1;
        update_post_meta( $post_id, 'etik_prestation_max_place', $max_place );
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