<?php
namespace WP_Etik;

class Meta_Event {
    public function init() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta']);
        add_action('admin_print_scripts-post-new.php', [$this, 'enqueue_admin_assets']);
        add_action('admin_print_scripts-post.php', [$this, 'enqueue_admin_assets']);
        add_filter('manage_etik_event_posts_columns', [$this, 'add_custom_columns']);
        add_action('manage_etik_event_posts_custom_column', [$this, 'columns_content_etik_event'], 10, 2);
        add_filter( "manage_edit-etik_event_sortable_columns", [$this, 'lwe_sortable_columns'], 11 );
        
    }

    public function enqueue_admin_assets() {
        // Optionnel : si tu veux enqueued un CSS dédié, fais-le via wp_enqueue_style
        // Ici on ne fait rien car le style est injecté directement dans la meta box pour simplicité
    }

    public function add_meta_boxes() {
        add_meta_box('etik_event_meta', __('Détails événement','wp-etik-events'), [$this, 'meta_box_html'], 'etik_event', 'normal', 'high');
    }

    public function meta_box_html($post) {
        // Récupération des metas existantes
        $start = get_post_meta($post->ID, 'etik_start_date', true);
        $end = get_post_meta($post->ID, 'etik_end_date', true);
        $price = get_post_meta($post->ID, 'etik_price', true);
        $lieux  = get_post_meta($post->ID, 'etik_lieux', true);
        $discount = get_post_meta($post->ID, '_etik_discount', true);
        $payment_required = get_post_meta($post->ID, '_etik_payment_required', true);
        $min_place = get_post_meta($post->ID, 'etik_min_place', true);
        $max_place = get_post_meta($post->ID, 'etik_max_place', true);

        wp_nonce_field('etik_save_meta', 'etik_meta_nonce');
        ?>
    

        <div class="etik-meta-grid">
            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label><?php _e('Date début','wp-etik-events'); ?></label>
                    <input type="text" name="etik_start_date" value="<?php echo esc_attr($start); ?>" class="datepicker" />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e('Prix','wp-etik-events'); ?></label>
                    <input type="number" step="0.01" name="etik_price" value="<?php echo esc_attr($price); ?>" />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e('Lieux','wp-etik-events'); ?></label>
                    <input type="text" name="etik_lieux" value="<?php echo esc_attr($lieux); ?>" />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e('Paiement obligatoire','wp-etik-events'); ?></label>
                    <input type="checkbox" name="etik_payment_required" value="1" <?php checked($payment_required, '1'); ?> />
                    <div class="small"><?php _e('Cocher si le paiement est requis pour valider l\'inscription','wp-etik-events'); ?></div>
                </div>
            </div>

            <div class="etik-meta-col">
                <div class="etik-meta-field">
                    <label><?php _e('Date fin','wp-etik-events'); ?></label>
                    <input type="text" name="etik_end_date" value="<?php echo esc_attr($end); ?>" class="datepicker" />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e('Remise en %','wp-etik-events'); ?></label>
                    <input type="number" step="0.01" name="etik_discount" value="<?php echo esc_attr($discount); ?>" />
                </div>

                <div class="etik-meta-field">
                    <label><?php _e('Places minimum / maximum','wp-etik-events'); ?></label>
                    <div style="display:flex;gap:8px;">
                        <input type="number" name="etik_min_place" value="<?php echo esc_attr($min_place); ?>" placeholder="<?php esc_attr_e('Min','wp-etik-events'); ?>" style="flex:1;" min="0" />
                        <input type="number" name="etik_max_place" value="<?php echo esc_attr($max_place); ?>" placeholder="<?php esc_attr_e('Max','wp-etik-events'); ?>" style="flex:1;" min="0" />
                    </div>
                    <div class="small"><?php _e('Laisser vide pour pas de limite','wp-etik-events'); ?></div>
                </div>
            </div>
        </div>

        <?php
    }

    public function save_meta($post_id) {
        // sécurité
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['etik_meta_nonce']) || !wp_verify_nonce($_POST['etik_meta_nonce'],'etik_save_meta')) return;
        if (get_post_type($post_id) !== 'etik_event') return;
        if (!current_user_can('edit_post', $post_id)) return;

        // sanitize & save
        $start_val = sanitize_text_field($_POST['etik_start_date'] ?? '');
        update_post_meta($post_id, 'etik_start_date', $start_val);

        $end_val = sanitize_text_field($_POST['etik_end_date'] ?? '');
        update_post_meta($post_id, 'etik_end_date', $end_val);

        $price_val = isset($_POST['etik_price']) ? floatval($_POST['etik_price']) : 0;
        update_post_meta($post_id, 'etik_price', $price_val);

        $discount_val = isset($_POST['etik_discount']) ? floatval($_POST['etik_discount']) : 0;
        update_post_meta($post_id, '_etik_discount', $discount_val);

        $payment_required = isset($_POST['etik_payment_required']) ? '1' : '0';
        update_post_meta($post_id, '_etik_payment_required', $payment_required);

        $lieux_val = sanitize_text_field($_POST['etik_lieux'] ?? '');
        update_post_meta($post_id, 'etik_lieux', $lieux_val);

        // new fields: min/max places (nullable integers)
        $min_place = isset($_POST['etik_min_place']) && $_POST['etik_min_place'] !== '' ? intval($_POST['etik_min_place']) : '';
        $max_place = isset($_POST['etik_max_place']) && $_POST['etik_max_place'] !== '' ? intval($_POST['etik_max_place']) : '';

        // ensure logical consistency: if both present and min > max, swap or adjust
        if ( $min_place !== '' && $max_place !== '' && $min_place > $max_place ) {
            // choose to set max = min to keep consistency
            $max_place = $min_place;
        }

        if ( $min_place === '' ) {
            delete_post_meta( $post_id, 'etik_min_place' );
        } else {
            update_post_meta( $post_id, 'etik_min_place', $min_place );
        }

        if ( $max_place === '' ) {
            delete_post_meta( $post_id, 'etik_max_place' );
        } else {
            update_post_meta( $post_id, 'etik_max_place', $max_place );
        }
    }

    public function add_custom_columns($columns) {
        
        $columns['lwe_date_event_strat']     = 'Date début';
        $columns['lwe_date_event_end']     = 'Date fin';
        $columns['nb_resa']     = 'Inscription';

        return $columns;
    }

    public function columns_content_etik_event($column_name, $post_ID){

        $start = get_post_meta($post_ID, 'etik_start_date', true);
        $end = get_post_meta($post_ID, 'etik_end_date', true);
        $max_place = get_post_meta($post_ID, 'etik_max_place', true);

        if ($column_name == 'lwe_date_event_strat') {
            echo $start ;
        }
        if ($column_name == 'lwe_date_event_end') {
            echo $end;
        }
        if ($column_name == 'nb_resa') {
            echo 0 . " / " . $max_place;
        }
    }

    	/**
	 * Make the SEO Score column sortable.
	 *
	 * @param array $columns Array of column names.
	 *
	 * @return array
	 */
	public function lwe_sortable_columns( $columns ) {
		$columns['lwe_date_event_strat'] = 'lwe_date_event_strat';
		$columns['lwe_date_event_end'] = 'lwe_date_event_end';

		return $columns;
	}


    
}
