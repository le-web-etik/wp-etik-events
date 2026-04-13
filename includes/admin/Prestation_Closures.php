<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prestation_Closures {

    public function init() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta' ] );
        add_action( 'admin_print_scripts-post-new.php', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_print_scripts-post.php', [ $this, 'enqueue_admin_assets' ] );
    }

    public function enqueue_admin_assets() {
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
    }

    public function add_meta_boxes() {
        add_meta_box( 'etik_prestation_closures_meta', __( 'Fermetures', 'wp-etik-events' ), [ $this, 'meta_box_html' ], 'etik_prestation', 'normal', 'high' );
    }

    public function meta_box_html( $post ) {
        // Récupération des fermetures existantes
        $closures = get_post_meta( $post->ID, 'etik_prestation_closures', true );
        if ( ! is_array( $closures ) ) {
            $closures = [];
        }

        wp_nonce_field( 'etik_prestation_closures_save', 'etik_prestation_closures_nonce' );
        ?>
        <div class="etik-closures-list">
            <div class="etik-closure-item">
                <label><?php _e( 'Date de fermeture', 'wp-etik-events' ); ?></label>
                <input type="text" name="etik_prestation_closures_date[]" class="datepicker" />
                <label><?php _e( 'Global', 'wp-etik-events' ); ?></label>
                <input type="checkbox" name="etik_prestation_closures_global[]" value="1" />
                <label><?php _e( 'Prestations concernées', 'wp-etik-events' ); ?></label>
                <select name="etik_prestation_closures_prestation_ids[]" multiple="multiple">
                    <?php
                    $prestations = get_posts( [
                        'post_type' => 'etik_prestation',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                    ] );
                    foreach ( $prestations as $prestation ) {
                        echo '<option value="' . esc_attr( $prestation->ID ) . '">' . esc_html( $prestation->post_title ) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <button type="button" class="button button-secondary" id="add-closure"><?php _e( 'Ajouter une fermeture', 'wp-etik-events' ); ?></button>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#add-closure').on('click', function() {
                var item = $('.etik-closure-item').first().clone();
                item.find('input').val('');
                item.find('select').val([]);
                $('.etik-closures-list').append(item);
            });
        });
        </script>
        <?php
    }

    public function save_meta( $post_id ) {
        // sécurité
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['etik_prestation_closures_nonce'] ) || ! wp_verify_nonce( $_POST['etik_prestation_closures_nonce'], 'etik_prestation_closures_save' ) ) return;
        if ( get_post_type( $post_id ) !== 'etik_prestation' ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // sanitize & save
        $closures = [];
        if ( isset( $_POST['etik_prestation_closures_date'] ) ) {
            foreach ( $_POST['etik_prestation_closures_date'] as $i => $date ) {
                $closure = [
                    'date' => sanitize_text_field( $date ),
                    'global' => isset( $_POST['etik_prestation_closures_global'][$i] ) ? '1' : '0',
                    'prestation_ids' => isset( $_POST['etik_prestation_closures_prestation_ids'][$i] ) ? array_map( 'intval', $_POST['etik_prestation_closures_prestation_ids'][$i] ) : [],
                ];
                $closures[] = $closure;
            }
        }

        update_post_meta( $post_id, 'etik_prestation_closures', $closures );
    }
}