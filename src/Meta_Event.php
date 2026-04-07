<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

class Meta_Event {
    public function init() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta']);
        add_action('admin_print_scripts-post-new.php', [$this, 'enqueue_admin_assets']);
        add_action('admin_print_scripts-post.php', [$this, 'enqueue_admin_assets']);
        add_filter('manage_etik_event_posts_columns', [$this, 'add_custom_columns']);
        add_action('manage_etik_event_posts_custom_column', [$this, 'columns_content_etik_event'], 10, 2);
        add_filter( "manage_edit-etik_event_sortable_columns", [$this, 'lwe_sortable_columns'], 11 );

        add_action( 'add_meta_boxes', [ $this, 'add_form_meta_box' ] );
        add_action( 'save_post',      [ $this, 'save_form_meta' ] );
        
    }

    public function enqueue_admin_assets() {
        // Optionnel : si tu veux enqueued un CSS dédié, fais-le via wp_enqueue_style
        // Ici on ne fait rien car le style est injecté directement dans la meta box pour simplicité
    }

    public function add_meta_boxes() {
        
        add_meta_box(
            'etik_event_meta',
            __('Détails événement','wp-etik-events'),
            [$this, 'meta_box_html'], 
            'etik_event', 
            'normal', 
            'high'
        );
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
                    
                    <div class="small">
                        <input type="checkbox" name="etik_payment_required" value="1" <?php checked($payment_required, '1'); ?> />
                        <?php _e('Cocher si le paiement est requis pour valider l\'inscription','wp-etik-events'); ?>
                    </div>
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

    /**
     * 
     */
    public function columns_content_etik_event($column_name, $post_ID){

        if ($column_name == 'lwe_date_event_strat') {
            $start = get_post_meta($post_ID, 'etik_start_date', true);
            echo $start ;
        }
        if ($column_name == 'lwe_date_event_end') {
            $end = get_post_meta($post_ID, 'etik_end_date', true);
            echo $end;
        }
        if ($column_name == 'nb_resa') {
            global $wpdb;
            $table = $wpdb->prefix . 'etik_inscriptions';
            $max_place = get_post_meta( $post_ID, 'etik_max_place', true );

            // ✅ Compter les inscriptions confirmées depuis la BDD
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = 'confirmed'",
                $post_ID
            ));

            $display_max = $max_place ? esc_html( $max_place ) : '∞';

            // ✅ Coloration si complet
            $style = ( $max_place && $count >= (int)$max_place )
                ? 'color:#a12d2d;font-weight:bold;'
                : 'color:#0b7a4b;';

            echo '<span style="' . $style . '">' . $count . ' / ' . $display_max . '</span>';
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

    /**
     * Meta box "Formulaire d'inscription" sur chaque événement.
     * Permet de choisir quel formulaire utiliser et d'activer/désactiver
     * les inscriptions pour cet événement.
     */
    public function add_form_meta_box() {
        add_meta_box(
            'etik_event_form',
            __( 'Formulaire d inscription', 'wp-etik-events' ),
            [$this, 'render_form_meta_box'],
            'etik_event',
            'side',
            'high'
        );
    }
 
    public function render_form_meta_box( \WP_Post $post ) {
        wp_nonce_field( 'etik_event_form_meta_save', 'etik_event_form_nonce' );
 
        $current_form_id = intval( get_post_meta( $post->ID, 'etik_event_form_id',     true ) );
        $form_active     = get_post_meta( $post->ID, 'etik_event_form_active', true );
        // Par défaut actif si la méta n'a jamais été enregistrée
        if ( $form_active === '' ) $form_active = '1';
 
        // Charger la liste des formulaires
        global $wpdb;
        $forms = $wpdb->get_results(
            "SELECT id, title, is_default FROM {$wpdb->prefix}etik_forms ORDER BY is_default DESC, title ASC",
            ARRAY_A
        ) ?: [];
        ?>
        <div style="line-height:1.8;">
 
            <!-- Inscriptions actives ? ─────────────────────────────────── -->
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:10px;cursor:pointer;">
                <input type="checkbox"
                       name="etik_event_form_active"
                       value="1"
                       id="etik_form_active_toggle"
                       <?php checked( $form_active, '1' ); ?>>
                <?php esc_html_e( 'Inscriptions actives', 'wp-etik-events' ); ?>
            </label>
 
            <!-- Choix du formulaire ─────────────────────────────────────── -->
            <div id="etik-form-select-wrap"
                 style="<?php echo $form_active !== '1' ? 'opacity:.45;pointer-events:none;' : ''; ?>">
 
                <label for="etik_event_form_id"
                       style="display:block;font-size:12px;color:#555;margin-bottom:4px;">
                    <?php esc_html_e( 'Formulaire à utiliser :', 'wp-etik-events' ); ?>
                </label>
 
                <?php if ( empty( $forms ) ) : ?>
                    <p style="font-size:12px;color:#888;font-style:italic;">
                        <?php esc_html_e( 'Aucun formulaire créé.', 'wp-etik-events' ); ?>
                        <a href="<?php echo esc_url( admin_url('edit.php?post_type=etik_event&page=wp-etik-forms&action=new') ); ?>">
                            <?php esc_html_e( 'Créer un formulaire', 'wp-etik-events' ); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <select name="etik_event_form_id"
                            id="etik_event_form_id"
                            style="width:100%;font-size:13px;">
                        <option value="0">
                            — <?php esc_html_e( 'Formulaire par défaut', 'wp-etik-events' ); ?> —
                        </option>
                        <?php foreach ( $forms as $form ) : ?>
                            <option value="<?php echo esc_attr( $form['id'] ); ?>"
                                    <?php selected( $current_form_id, (int) $form['id'] ); ?>>
                                <?php echo esc_html( $form['title'] ); ?>
                                <?php if ( $form['is_default'] ) : ?>
                                    (<?php esc_html_e( 'par défaut', 'wp-etik-events' ); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
 
                    <?php if ( $current_form_id > 0 ) : ?>
                        <a href="<?php echo esc_url( add_query_arg(
                            [ 'page' => 'wp-etik-forms', 'action' => 'edit', 'form_id' => $current_form_id ],
                            admin_url('edit.php?post_type=etik_event')
                        ) ); ?>"
                           style="display:block;font-size:11px;margin-top:4px;">
                            ✏️ <?php esc_html_e( 'Modifier ce formulaire', 'wp-etik-events' ); ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
 
        </div>
 
        <script>
        (function($){
            $('#etik_form_active_toggle').on('change', function(){
                var $wrap = $('#etik-form-select-wrap');
                if (this.checked) {
                    $wrap.css({ opacity: '', 'pointer-events': '' });
                } else {
                    $wrap.css({ opacity: '.45', 'pointer-events': 'none' });
                }
            });
        })(jQuery);
        </script>
        <?php
    }
 
    public function save_form_meta( int $post_id ) {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )            return;
        if ( get_post_type( $post_id ) !== 'etik_event' )             return;
        if ( ! isset( $_POST['etik_event_form_nonce'] ) )             return;
        if ( ! wp_verify_nonce( $_POST['etik_event_form_nonce'], 'etik_event_form_meta_save' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) )            return;
 
        // Formulaire choisi
        $form_id = intval( $_POST['etik_event_form_id'] ?? 0 );
        update_post_meta( $post_id, 'etik_event_form_id', $form_id );
 
        // Actif ?
        $active = isset( $_POST['etik_event_form_active'] ) ? '1' : '0';
        update_post_meta( $post_id, 'etik_event_form_active', $active );
    }

    
}
