<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Page admin "Prestations".
 * Onglets : Liste | Planning | Fermetures | Réservations
 */
class Prestation_Settings {

    const MENU_SLUG    = 'wp-etik-prestation';
    const OPTION_GROUP = 'lwe_prestation_settings';

    private static array $day_labels = [
        1 => 'Lun', 2 => 'Mar', 3 => 'Mer',
        4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim',
    ];
    private static array $day_names_full = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi',
        4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
    ];

    // ─── INIT ───────────────────────────────────────────────────────────────

    public static function init() : void {
        $self = new self();
        add_action( 'admin_menu',            [ $self, 'add_menu' ] );
        add_action( 'admin_init',            [ $self, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $self, 'enqueue_assets' ] );
    }

    public function __construct() {
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Meta.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Closures.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Reservation_List.php';
    }

    // ─── MENU ───────────────────────────────────────────────────────────────

    public function add_menu() : void {
        add_submenu_page(
            'edit.php?post_type=etik_event',
            __( 'Prestations', 'wp-etik-events' ),
            __( 'Prestations', 'wp-etik-events' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() : void {}

    // ─── ASSETS ─────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ) : void {
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( $screen->id !== 'etik_event_page_' . self::MENU_SLUG ) return;

        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style(
            'jquery-ui-css',
            'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css'
        );

        wp_localize_script( 'wp-color-picker', 'etikPlanning', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'etik_add_planning_slot' ),
        ] );

        wp_add_inline_script( 'wp-color-picker', $this->get_inline_js() );
    }

    private function get_inline_js() : string {
        return <<<'JS'
        jQuery(document).ready(function ($) {

            // ── Color picker ──────────────────────────────────────────────────────
            $('.color-picker').wpColorPicker();

            // ── Tabs ──────────────────────────────────────────────────────────────
            $(document).on('click', '.etik-tab', function () {
                var tab = $(this).data('tab');
                $('.etik-tab').css({'background':'#fff','color':'#2271b1','border-bottom-color':'#c3c4c7'});
                $(this).css({'background':'#0b5a8c','color':'#fff','border-bottom-color':'#0b5a8c'});
                $('.etik-panel').hide();
                $('.etik-panel[data-panel="' + tab + '"]').show();
            });

            // ── Créneaux : toggle jour (FIX : flexbox au lieu de display:contents) ─
            $(document).on('change', '.etik-day-toggle', function () {
                var $row = $(this).closest('.etik-slot-day-row');
                if (this.checked) {
                    $row.find('.etik-day-fields').css('display', 'flex').find('input').prop('disabled', false);
                    $row.find('.etik-day-closed').hide();
                } else {
                    $row.find('.etik-day-fields').hide().find('input').prop('disabled', true);
                    $row.find('.etik-day-closed').show();
                }
            });

            // ── Créneaux : appliquer valeurs par défaut ────────────────────────────
            $(document).on('click', '.etik-apply-defaults', function (e) {
                e.preventDefault();
                var $b = $(this).closest('.etik-slot-block');
                var t  = $b.find('.etik-default-time').val();
                var d  = $b.find('.etik-default-duration').val();
                var br = $b.find('.etik-default-break').val();
                $b.find('.etik-day-toggle:checked').each(function () {
                    var $r = $(this).closest('.etik-slot-day-row');
                    if (t)  $r.find('input[name$="[start_time]"]').val(t);
                    if (d)  $r.find('input[name$="[duration]"]').val(d);
                    if (br) $r.find('input[name$="[break_duration]"]').val(br);
                });
            });

            // ── Créneaux : sélection rapide ───────────────────────────────────────
            $(document).on('click', '.etik-select-workdays', function (e) {
                e.preventDefault();
                $(this).closest('.etik-slot-block').find('.etik-day-toggle').each(function () {
                    $(this).prop('checked', parseInt($(this).data('day')) <= 5).trigger('change');
                });
            });
            $(document).on('click', '.etik-select-none', function (e) {
                e.preventDefault();
                $(this).closest('.etik-slot-block').find('.etik-day-toggle')
                    .prop('checked', false).trigger('change');
            });

            // ── Créneaux : ajouter / supprimer un bloc ────────────────────────────
            var slotIdx = 1;
            $(document).on('click', '#etik-add-slot', function (e) {
                e.preventDefault();
                var $c = $('.etik-slot-block').first().clone(true);
                $c.find('.etik-slot-block-title').text('Créneau ' + (++slotIdx));
                $c.find('.etik-remove-slot').show();
                $c.find('input[type="checkbox"]').prop('checked', false).trigger('change');
                $c.find('input[type="time"], input[type="number"]').val('');
                $c.find('[name]').each(function () {
                    $(this).attr('name',
                        $(this).attr('name').replace(/slots\[\d+\]/, 'slots[' + (slotIdx-1) + ']'));
                });
                $('#etik-slots-container').append($c);
            });
            $(document).on('click', '.etik-remove-slot', function (e) {
                e.preventDefault();
                if ($('.etik-slot-block').length > 1) $(this).closest('.etik-slot-block').remove();
            });

            // ── Planning : préselectionner durée selon prestation ─────────────────
            $(document).on('change', '#etik-plan-prestation', function () {
                var dur = $(this).find(':selected').data('duration');
                if (dur) $('#etik-plan-duration').val(dur);
            });

            // ── Planning : AJAX ajout créneau ─────────────────────────────────────
            $(document).on('click', '#etik-plan-add-btn', function (e) {
                e.preventDefault();
                var prestId  = $('#etik-plan-prestation').val();
                var days     = $('input[name="etik_plan_days[]"]:checked').map(function(){return $(this).val();}).get();
                var timeVal  = $('#etik-plan-time').val();
                var duration = parseInt($('#etik-plan-duration').val()) || 0;
                var breakVal = parseInt($('#etik-plan-break').val()) || 0;

                if (!prestId || !days.length || !timeVal || !duration) {
                    alert('Veuillez renseigner la prestation, au moins un jour, l\'heure et la durée.');
                    return;
                }

                var $btn = $(this).prop('disabled', true).text('Enregistrement…');

                $.post(etikPlanning.ajaxUrl, {
                    action: 'etik_add_planning_slot',
                    nonce:  etikPlanning.nonce,
                    prestation_id:  prestId,
                    days:           days.join(','),
                    start_time:     timeVal,
                    duration:       duration,
                    break_duration: breakVal,
                }, function (res) {
                    $btn.prop('disabled', false).text('Ajouter ce créneau');
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Erreur lors de l\'ajout.');
                    }
                });
            });

            // ── Planning : supprimer un créneau ───────────────────────────────────
            $(document).on('click', '.etik-plan-delete-slot', function (e) {
                e.preventDefault();
                if (!confirm('Supprimer ce créneau ?')) return;
                var slotId = $(this).data('slot-id');
                var $block = $(this).closest('.etik-slot-event');

                $.post(etikPlanning.ajaxUrl, {
                    action:  'etik_delete_planning_slot',
                    nonce:   etikPlanning.nonce,
                    slot_id: slotId,
                }, function (res) {
                    if (res.success) {
                        $block.fadeOut(200, function () { $(this).remove(); });
                    } else {
                        alert(res.data || 'Erreur.');
                    }
                });
            });
        });
        JS;
    }

    // ─── PAGE PRINCIPALE ────────────────────────────────────────────────────

    public function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        $action     = sanitize_key( $_GET['action'] ?? '' );
        $active_tab = sanitize_key( $_GET['tab'] ?? 'list' );
        $edit_pid   = intval( $_GET['post_id'] ?? 0 );
        $meta       = new Prestation_Meta();
        ?>
        <div class="wrap etik-admin">

            <?php $this->render_notices(); ?>

            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Prestations', 'wp-etik-events' ); ?>
            </h1>
            <?php if ( ! in_array( $action, [ 'add', 'edit' ], true ) ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'action', 'add' ) ); ?>"
                   class="page-title-action">
                    + <?php esc_html_e( 'Ajouter une prestation', 'wp-etik-events' ); ?>
                </a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php if ( $action === 'add' ) : ?>
                <?php $this->render_prestation_form( null, $meta ); ?>

            <?php elseif ( $action === 'edit' && $edit_pid > 0 ) : ?>
                <?php
                // Stockage dans wp_posts sans CPT enregistré — get_post() lit la DB directement
                $post_to_edit = get_post( $edit_pid );
                if ( ! $post_to_edit || $post_to_edit->post_type !== 'etik_prestation' ) {
                    echo '<div class="notice notice-error"><p>'
                       . esc_html__( 'Prestation introuvable.', 'wp-etik-events' ) . '</p></div>';
                } else {
                    $this->render_prestation_form( $post_to_edit, $meta );
                }
                ?>
            <?php endif; ?>

            <!-- ── Onglets ─────────────────────────────────────────────────── -->
            <?php
            $tabs = [
                'list'         => __( 'Liste', 'wp-etik-events' ),
                'planning'     => __( 'Planning', 'wp-etik-events' ),
                'closures'     => __( 'Fermetures', 'wp-etik-events' ),
                'reservations' => __( 'Réservations', 'wp-etik-events' ),
            ];
            ?>
            <div style="border-bottom:1px solid #c3c4c7;margin-bottom:0;">
                <?php foreach ( $tabs as $slug => $label ) :
                    $is_active = ( $active_tab === $slug );
                    ?>
                    <button class="etik-tab"
                            data-tab="<?php echo esc_attr( $slug ); ?>"
                            style="padding:7px 18px;font-size:13px;cursor:pointer;
                                   border:1px solid <?php echo $is_active ? '#0b5a8c' : '#c3c4c7'; ?>;
                                   border-bottom:none;
                                   border-radius:3px 3px 0 0;
                                   margin-right:2px;
                                   margin-bottom:-1px;
                                   background:<?php echo $is_active ? '#0b5a8c' : '#fff'; ?>;
                                   color:<?php echo $is_active ? '#fff' : '#2271b1'; ?>;">
                        <?php echo esc_html( $label ); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;
                        padding:16px 20px 20px;margin-bottom:24px;">
                <?php foreach ( array_keys( $tabs ) as $slug ) : ?>
                    <div class="etik-panel"
                         data-panel="<?php echo esc_attr( $slug ); ?>"
                         style="display:<?php echo $active_tab === $slug ? 'block' : 'none'; ?>;">
                        <?php
                        switch ( $slug ) {
                            case 'list':         $this->render_list();         break;
                            case 'planning':     $this->render_planning();     break;
                            case 'closures':     $this->render_closures();     break;
                            case 'reservations': $this->render_reservations(); break;
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php
    }

    // ─── NOTICES ────────────────────────────────────────────────────────────

    private function render_notices() : void {
        $msgs = [
            'prestation_created' => [ 'success', __( 'Prestation enregistrée avec succès.', 'wp-etik-events' ) ],
            'prestation_updated' => [ 'success', __( 'Prestation mise à jour.', 'wp-etik-events' ) ],
            'prestation_deleted' => [ 'success', __( 'Prestation supprimée.', 'wp-etik-events' ) ],
            'missing_title'      => [ 'error',   __( 'Titre obligatoire.', 'wp-etik-events' ) ],
            'db_error'           => [ 'error',   __( 'Erreur lors de l\'enregistrement.', 'wp-etik-events' ) ],
        ];
        $key = sanitize_key( $_GET['message'] ?? '' );
        if ( $key && isset( $msgs[ $key ] ) ) {
            [ $type, $text ] = $msgs[ $key ];
            $cls = $type === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>'
               . esc_html( $text ) . '</p></div>';
        }
    }

    // ─── LISTE ──────────────────────────────────────────────────────────────

    private function render_list() : void {
        global $wpdb;

        $posts = get_posts( [
            'post_type'      => 'etik_prestation',
            'posts_per_page' => -1,
            'post_status'    => [ 'publish', 'draft' ],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        if ( empty( $posts ) ) {
            echo '<p style="padding:12px 0;color:#666;">'
               . esc_html__( 'Aucune prestation. Cliquez sur « + Ajouter une prestation » pour commencer.', 'wp-etik-events' )
               . '</p>';
            return;
        }

        $slots_table  = $wpdb->prefix . 'etik_prestation_slots';
        $planning_url = add_query_arg(
            [ 'page' => self::MENU_SLUG, 'tab' => 'planning' ],
            admin_url( 'edit.php?post_type=etik_event' )
        );
        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:22px;"></th>
                    <th><?php esc_html_e( 'Prestation', 'wp-etik-events' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Prix', 'wp-etik-events' ); ?></th>
                    <th style="width:85px;"><?php esc_html_e( 'Durée', 'wp-etik-events' ); ?></th>
                    <th style="width:75px;"><?php esc_html_e( 'Places', 'wp-etik-events' ); ?></th>
                    <th><?php esc_html_e( 'Créneaux', 'wp-etik-events' ); ?></th>
                    <th style="width:160px;"><?php esc_html_e( 'Actions', 'wp-etik-events' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $posts as $prestation ) :
                // ── Utiliser $prestation (jamais $post) pour ne pas écraser le global WP ──
                $pid      = (int) $prestation->ID;
                $color    = get_post_meta( $pid, 'etik_prestation_color',            true ) ?: '#2aa78a';
                $price    = get_post_meta( $pid, 'etik_prestation_price',            true );
                $duration = get_post_meta( $pid, 'etik_prestation_duration',         true );
                $maxpl    = get_post_meta( $pid, 'etik_prestation_max_place',        true );
                $payment  = get_post_meta( $pid, 'etik_prestation_payment_required', true ) === '1';

                $slot_count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$slots_table} WHERE prestation_id = %d AND is_closed = 0",
                        $pid
                    )
                );

                $days_raw = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT days FROM {$slots_table} WHERE prestation_id = %d AND is_closed = 0",
                        $pid
                    )
                );
                $all_days = [];
                foreach ( $days_raw as $dr ) {
                    foreach ( array_filter( explode( ',', (string) $dr ) ) as $n ) {
                        $all_days[ (int) $n ] = true;
                    }
                }
                ksort( $all_days );

                $edit_url   = add_query_arg( [
                    'page'    => self::MENU_SLUG,
                    'action'  => 'edit',
                    'post_id' => $pid,
                ], admin_url( 'edit.php?post_type=etik_event' ) );
                $delete_url = wp_nonce_url(
                    add_query_arg( [
                        'page'    => self::MENU_SLUG,
                        'action'  => 'delete_prestation',
                        'post_id' => $pid,
                    ], admin_url( 'edit.php?post_type=etik_event' ) ),
                    'delete_prestation_' . $pid
                );
                ?>
                <tr>
                    <td>
                        <span style="display:inline-block;width:16px;height:16px;border-radius:50%;
                              background:<?php echo esc_attr( $color ); ?>;
                              border:1px solid rgba(0,0,0,.15);vertical-align:middle;"></span>
                    </td>
                    <td>
                        <strong>
                            <a href="<?php echo esc_url( $edit_url ); ?>">
                                <?php echo esc_html( $prestation->post_title ); ?>
                            </a>
                        </strong>
                        <?php if ( $prestation->post_status === 'draft' ) : ?>
                            <span style="color:#888;font-size:11px;"> — Brouillon</span>
                        <?php endif; ?>
                        <?php if ( $payment ) : ?>
                            <span style="font-size:11px;color:#d63638;margin-left:6px;">€ requis</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $price !== '' ? esc_html( number_format_i18n( (float) $price, 2 ) ) . ' €' : '—'; ?></td>
                    <td><?php echo $duration ? esc_html( $duration ) . ' min' : '—'; ?></td>
                    <td><?php echo $maxpl ? esc_html( $maxpl ) : '<span style="color:#888;">∞</span>'; ?></td>
                    <td>
                        <?php if ( $slot_count > 0 ) : ?>
                            <strong style="color:#0b7a4b;"><?php echo esc_html( $slot_count ); ?></strong>
                            <?php foreach ( $all_days as $n => $_ ) :
                                $lbl = self::$day_labels[ $n ] ?? $n; ?>
                                <span style="display:inline-block;font-size:11px;padding:1px 5px;margin:1px 1px;
                                      border-radius:3px;background:<?php echo esc_attr( $color ); ?>22;
                                      color:<?php echo esc_attr( $color ); ?>;
                                      border:1px solid <?php echo esc_attr( $color ); ?>55;">
                                    <?php echo esc_html( $lbl ); ?>
                                </span>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <a href="<?php echo esc_url( $planning_url ); ?>"
                               style="color:#a0a0a0;font-size:12px;font-style:italic;">
                                Aucun créneau — ajouter via Planning ↗
                            </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">
                            <?php esc_html_e( 'Modifier', 'wp-etik-events' ); ?>
                        </a>&nbsp;
                        <a class="button button-small"
                           href="<?php echo esc_url( $delete_url ); ?>"
                           style="color:#d63638;border-color:#d63638;"
                           onclick="return confirm('Supprimer cette prestation et ses créneaux ?');">
                            <?php esc_html_e( 'Supprimer', 'wp-etik-events' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ─── PLANNING ───────────────────────────────────────────────────────────

    private function render_planning() : void {
        global $wpdb;

        // Constantes de grille
        $grid_start = 7;    // 07:00
        $grid_end   = 18;   // 18:00
        $px_per_min = 1.5;  // 30 px = 30 min
        $total_min  = ( $grid_end - $grid_start ) * 60;   // 660
        $total_px   = $total_min * $px_per_min;            // 990 px
        $header_h   = 34;

        // Prestations + créneaux
        $prestations = get_posts( [
            'post_type'      => 'etik_prestation',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $all_slots = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}etik_prestation_slots
             WHERE is_closed = 0 AND type = 'recurrent'
             ORDER BY start_time ASC"
        );

        // Indexer par jour
        $by_day = array_fill( 1, 7, [] );

        foreach ( $all_slots as $slot ) {
            $color = get_post_meta( (int) $slot->prestation_id, 'etik_prestation_color', true ) ?: '#2aa78a';
            $title = get_the_title( (int) $slot->prestation_id );
            [ $sh, $sm ] = array_map( 'intval', explode( ':', $slot->start_time ) );
            $top_px    = ( ( $sh - $grid_start ) * 60 + $sm ) * $px_per_min;
            $height_px = max( 22, (int) $slot->duration * $px_per_min );
            // Clamp dépassement 18h
            if ( $top_px + $height_px > $total_px ) $height_px = $total_px - $top_px;
            if ( $top_px < 0 || $top_px >= $total_px ) continue;

            foreach ( array_filter( explode( ',', (string) $slot->days ) ) as $d ) {
                $d = (int) $d;
                if ( $d < 1 || $d > 7 ) continue;
                $by_day[ $d ][] = [
                    'id'       => (int) $slot->id,
                    'top'      => round( $top_px ),
                    'height'   => round( $height_px ),
                    'color'    => $color,
                    'title'    => $title,
                    'time'     => $slot->start_time,
                    'duration' => (int) $slot->duration,
                ];
            }
        }
        ?>
        <div style="display:flex;gap:20px;align-items:flex-start;">

            <!-- ═══════════════ GRILLE HEBDOMADAIRE ════════════════════════ -->
            <div style="flex:1;min-width:0;overflow-x:auto;">
                <div style="display:flex;min-width:560px;">

                    <!-- Colonne heures -->
                    <div style="width:50px;flex-shrink:0;text-align:right;">
                        <div style="height:<?php echo esc_attr( $header_h ); ?>px;"></div>
                        <div style="position:relative;height:<?php echo esc_attr( $total_px ); ?>px;">
                            <?php for ( $h = $grid_start; $h <= $grid_end; $h++ ) :
                                $t = ( $h - $grid_start ) * 60 * $px_per_min;
                                ?>
                                <div style="position:absolute;top:<?php echo round( $t ) - 8; ?>px;right:6px;
                                            font-size:11px;color:#888;white-space:nowrap;line-height:1;">
                                    <?php echo sprintf( '%02d:00', $h ); ?>
                                </div>
                                <?php if ( $h < $grid_end ) :
                                    $t30 = $t + 30 * $px_per_min; ?>
                                    <div style="position:absolute;top:<?php echo round( $t30 ) - 7; ?>px;right:6px;
                                                font-size:10px;color:#ccc;line-height:1;">:30</div>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- 7 colonnes jours -->
                    <?php foreach ( self::$day_names_full as $day_num => $day_name ) :
                        $has_slots = ! empty( $by_day[ $day_num ] );
                        ?>
                        <div style="flex:1;min-width:70px;border-left:1px solid #e2e4e7;">

                            <!-- En-tête -->
                            <div style="height:<?php echo esc_attr( $header_h ); ?>px;
                                        display:flex;align-items:center;justify-content:center;
                                        font-size:12px;font-weight:600;
                                        color:<?php echo $day_num >= 6 ? '#888' : '#3c434a'; ?>;
                                        background:<?php echo $has_slots ? '#f0f4f8' : '#f0f0f1'; ?>;
                                        border-bottom:2px solid #c3c4c7;">
                                <?php echo esc_html( mb_substr( $day_name, 0, 3 ) ); ?>
                            </div>

                            <!-- Corps grille -->
                            <div style="position:relative;
                                        height:<?php echo esc_attr( $total_px ); ?>px;
                                        background:<?php echo $day_num >= 6 ? '#fafafa' : '#fff'; ?>;
                                        background-image:
                                            repeating-linear-gradient(to bottom,
                                                transparent 0px,
                                                transparent <?php echo round( 30 * $px_per_min ) - 1; ?>px,
                                                #eeeff0 <?php echo round( 30 * $px_per_min ) - 1; ?>px,
                                                #eeeff0 <?php echo round( 30 * $px_per_min ); ?>px,
                                                transparent <?php echo round( 30 * $px_per_min ); ?>px,
                                                transparent <?php echo round( 60 * $px_per_min ) - 1; ?>px,
                                                #e5e5e5 <?php echo round( 60 * $px_per_min ) - 1; ?>px,
                                                #e5e5e5 <?php echo round( 60 * $px_per_min ); ?>px
                                            );">

                                <?php foreach ( $by_day[ $day_num ] as $blk ) :
                                    $text_color = $this->contrast_color( $blk['color'] );
                                    $border_col = $this->darken_color( $blk['color'] );
                                    ?>
                                    <div class="etik-slot-event"
                                         title="<?php echo esc_attr( $blk['title'] . ' · ' . $blk['time'] . ' (' . $blk['duration'] . ' min)' ); ?>"
                                         style="position:absolute;
                                                top:<?php echo esc_attr( $blk['top'] ); ?>px;
                                                left:2px;right:2px;
                                                height:<?php echo esc_attr( $blk['height'] ); ?>px;
                                                background:<?php echo esc_attr( $blk['color'] ); ?>;
                                                border-radius:3px;
                                                border-left:3px solid <?php echo esc_attr( $border_col ); ?>;
                                                color:<?php echo esc_attr( $text_color ); ?>;
                                                font-size:11px;line-height:1.3;
                                                padding:3px 4px 3px 5px;
                                                overflow:hidden;cursor:default;
                                                box-shadow:0 1px 3px rgba(0,0,0,.12);z-index:2;">

                                        <?php if ( $blk['height'] >= 22 ) : ?>
                                            <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;">
                                                <?php echo esc_html( $blk['title'] ); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( $blk['height'] >= 38 ) : ?>
                                            <div style="opacity:.85;font-size:10px;">
                                                <?php echo esc_html( $blk['time'] . ' · ' . $blk['duration'] . ' min' ); ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Supprimer -->
                                        <span class="etik-plan-delete-slot"
                                              data-slot-id="<?php echo esc_attr( $blk['id'] ); ?>"
                                              style="position:absolute;top:2px;right:4px;
                                                     opacity:0;cursor:pointer;font-size:11px;
                                                     transition:opacity .15s;"
                                              title="Supprimer ce créneau">✕</span>
                                    </div>
                                <?php endforeach; ?>

                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>
            <!-- /grille -->

            <!-- ═══════════════ SIDEBAR ════════════════════════════════════ -->
            <div style="width:260px;flex-shrink:0;">

                <!-- Légende -->
                <div class="postbox" style="margin-bottom:12px;">
                    <h3 class="hndle" style="font-size:13px;padding:8px 12px;">
                        <span><?php esc_html_e( 'Prestations', 'wp-etik-events' ); ?></span>
                    </h3>
                    <div class="inside" style="padding:6px 12px 10px;">
                        <?php if ( empty( $prestations ) ) : ?>
                            <p class="description" style="font-size:12px;">
                                <?php esc_html_e( 'Aucune prestation créée.', 'wp-etik-events' ); ?>
                            </p>
                        <?php else :
                            foreach ( $prestations as $p ) :
                                $c  = get_post_meta( $p->ID, 'etik_prestation_color',    true ) ?: '#2aa78a';
                                $dd = get_post_meta( $p->ID, 'etik_prestation_duration',  true );
                                ?>
                                <div style="display:flex;align-items:center;gap:8px;
                                            margin-bottom:6px;font-size:13px;">
                                    <span style="display:inline-block;width:11px;height:11px;
                                                 border-radius:50%;background:<?php echo esc_attr( $c ); ?>;
                                                 flex-shrink:0;"></span>
                                    <span style="flex:1;"><?php echo esc_html( $p->post_title ); ?></span>
                                    <?php if ( $dd ) : ?>
                                        <span style="font-size:11px;color:#888;"><?php echo esc_html( $dd ); ?>min</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach;
                        endif; ?>
                    </div>
                </div>

                <!-- Ajout rapide -->
                <div class="postbox">
                    <h3 class="hndle" style="font-size:13px;padding:8px 12px;">
                        <span>+ <?php esc_html_e( 'Ajouter un créneau', 'wp-etik-events' ); ?></span>
                    </h3>
                    <div class="inside" style="padding:8px 12px 12px;">

                        <?php if ( empty( $prestations ) ) : ?>
                            <p class="description" style="font-size:12px;">
                                <?php esc_html_e( 'Créez d\'abord une prestation via le bouton « + Ajouter ».', 'wp-etik-events' ); ?>
                            </p>
                        <?php else : ?>

                        <div style="margin-bottom:9px;">
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px;">
                                <?php esc_html_e( 'Prestation', 'wp-etik-events' ); ?>
                            </label>
                            <select id="etik-plan-prestation" style="width:100%;font-size:13px;">
                                <option value=""><?php esc_html_e( '— choisir —', 'wp-etik-events' ); ?></option>
                                <?php foreach ( $prestations as $p ) :
                                    $c   = get_post_meta( $p->ID, 'etik_prestation_color',   true ) ?: '#2aa78a';
                                    $dur = get_post_meta( $p->ID, 'etik_prestation_duration', true ) ?: 60;
                                    ?>
                                    <option value="<?php echo esc_attr( $p->ID ); ?>"
                                            data-color="<?php echo esc_attr( $c ); ?>"
                                            data-duration="<?php echo esc_attr( $dur ); ?>">
                                        <?php echo esc_html( $p->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="margin-bottom:9px;">
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:5px;">
                                <?php esc_html_e( 'Jours', 'wp-etik-events' ); ?>
                            </label>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:3px 8px;">
                                <?php foreach ( self::$day_names_full as $n => $name ) : ?>
                                    <label style="font-size:12px;font-weight:normal;
                                                  display:flex;align-items:center;gap:5px;cursor:pointer;
                                                  color:<?php echo $n >= 6 ? '#888' : '#3c434a'; ?>">
                                        <input type="checkbox" name="etik_plan_days[]"
                                               value="<?php echo esc_attr( $n ); ?>"
                                               <?php checked( $n <= 5 ); ?>>
                                        <?php echo esc_html( self::$day_labels[ $n ] ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:9px;">
                            <div>
                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px;">
                                    <?php esc_html_e( 'Heure', 'wp-etik-events' ); ?>
                                </label>
                                <input type="time" id="etik-plan-time" value="09:00"
                                       style="width:100%;font-size:13px;">
                            </div>
                            <div>
                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px;">
                                    <?php esc_html_e( 'Durée (min)', 'wp-etik-events' ); ?>
                                </label>
                                <input type="number" id="etik-plan-duration" value="60"
                                       min="5" max="480" step="5"
                                       style="width:100%;font-size:13px;">
                            </div>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px;">
                                <?php esc_html_e( 'Pause (min)', 'wp-etik-events' ); ?>
                            </label>
                            <input type="number" id="etik-plan-break" value="0"
                                   min="0" max="120" step="5"
                                   style="width:100%;font-size:13px;">
                        </div>

                        <button id="etik-plan-add-btn" class="button button-primary"
                                style="width:100%;">
                            <?php esc_html_e( 'Ajouter ce créneau', 'wp-etik-events' ); ?>
                        </button>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- /sidebar -->

        </div>

        <style>
        /* Révéler le X au survol du bloc créneau */
        .etik-slot-event:hover .etik-plan-delete-slot { opacity: 0.75 !important; }
        .etik-plan-delete-slot:hover { opacity: 1 !important; }
        </style>
        <?php
    }

    // ─── FERMETURES ─────────────────────────────────────────────────────────

    private function render_closures() : void {
        echo '<p style="color:#666;font-style:italic;padding:12px 0;">'
           . esc_html__( 'Gestion des fermetures exceptionnelles (à venir).', 'wp-etik-events' )
           . '</p>';
    }

    // ─── RÉSERVATIONS ───────────────────────────────────────────────────────

    private function render_reservations() : void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'etik_reservations';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl}'" ) !== $tbl ) {
            echo '<p class="description">'
               . esc_html__( 'Table absente. Réactivez le plugin.', 'wp-etik-events' )
               . '</p>';
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT r.*, p.post_title AS prestation_title
             FROM {$tbl} r
             LEFT JOIN {$wpdb->posts} p ON r.prestation_id = p.ID
             ORDER BY r.created_at DESC LIMIT 50"
        );

        if ( empty( $rows ) ) {
            echo '<p style="padding:12px 0;color:#666;">'
               . esc_html__( 'Aucune réservation pour l\'instant.', 'wp-etik-events' )
               . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th><?php esc_html_e( 'Prestation', 'wp-etik-events' ); ?></th>
                    <th><?php esc_html_e( 'Client', 'wp-etik-events' ); ?></th>
                    <th style="width:110px;"><?php esc_html_e( 'Statut', 'wp-etik-events' ); ?></th>
                    <th style="width:150px;"><?php esc_html_e( 'Date', 'wp-etik-events' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $user = get_userdata( (int) $r->user_id );
                $name = $user ? ( trim( $user->first_name . ' ' . $user->last_name ) ?: $user->user_email ) : '—';
                $bc   = [ 'pending' => '#f0ad4e', 'confirmed' => '#2aa78a', 'cancelled' => '#9aa0a6' ][ $r->status ] ?? '#ccc';
                ?>
                <tr>
                    <td><?php echo (int) $r->id; ?></td>
                    <td><?php echo esc_html( $r->prestation_title ); ?></td>
                    <td><?php echo esc_html( $name ); ?></td>
                    <td>
                        <span style="background:<?php echo esc_attr( $bc ); ?>;color:#fff;
                              padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">
                            <?php echo esc_html( $r->status ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( date_i18n( get_option('date_format') . ' H:i', strtotime( $r->created_at ) ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ─── FORMULAIRE AJOUT / ÉDITION ─────────────────────────────────────────

    /**
     * Formulaire partagé pour la création (post=null) et l'édition d'une prestation.
     * Les prestations sont stockées dans wp_posts (post_type='etik_prestation')
     * sans CPT enregistré — toute la gestion UI passe par cette page custom.
     */
    private function render_prestation_form( $post, Prestation_Meta $meta ) : void {
        $is_edit = ( $post !== null );
        $title   = $is_edit ? __( 'Modifier la prestation', 'wp-etik-events' ) : __( 'Nouvelle prestation', 'wp-etik-events' );

        $wp_action   = $is_edit ? 'wp_etik_update_prestation' : 'wp_etik_create_prestation';
        $nonce_name  = 'wp_etik_prestation_nonce';
        $nonce_action = $is_edit ? 'wp_etik_update_prestation_nonce' : 'wp_etik_create_prestation_nonce';
        $back_url    = remove_query_arg( [ 'action', 'post_id' ] );
        ?>
        <div class="postbox" style="max-width:960px;margin:16px 0 24px;">
            <h3 class="hndle">
                <span><?php echo esc_html( $title ); ?></span>
            </h3>
            <div class="inside">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( $wp_action ); ?>">
                    <?php if ( $is_edit ) : ?>
                        <input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>">
                    <?php endif; ?>
                    <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
                    <?php $meta->meta_box_html( $post ); ?>
                    <?php if ( ! $is_edit ) : ?>
                        <?php $this->render_slots_section(); ?>
                    <?php else : ?>
                        <p class="description" style="margin-top:16px;">
                            <?php esc_html_e( 'Pour gérer les créneaux, utilisez l\'onglet Planning.', 'wp-etik-events' ); ?>
                        </p>
                    <?php endif; ?>
                    <p style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;">
                        <button type="submit" class="button button-primary button-large">
                            <?php echo $is_edit
                                ? esc_html__( 'Enregistrer les modifications', 'wp-etik-events' )
                                : esc_html__( 'Enregistrer la prestation', 'wp-etik-events' ); ?>
                        </button>
                        <a href="<?php echo esc_url( $back_url ); ?>"
                           class="button" style="margin-left:8px;">
                            <?php esc_html_e( 'Annuler', 'wp-etik-events' ); ?>
                        </a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    // ─── SECTION CRÉNEAUX (formulaire création) ─────────────────────────────

    private function render_slots_section() : void {
        ?>
        <hr style="margin:24px 0 14px;border-top:1px solid #ddd;">
        <h3 style="margin:0 0 4px;font-size:14px;font-weight:600;">
            <?php esc_html_e( 'Créneaux récurrents (optionnel)', 'wp-etik-events' ); ?>
        </h3>
        <p class="description" style="margin:0 0 14px;">
            <?php esc_html_e( 'Configurez les horaires habituels. Vous pourrez aussi les ajouter/modifier depuis l\'onglet Planning.', 'wp-etik-events' ); ?>
        </p>
        <div id="etik-slots-container">
            <?php $this->render_slot_block( 0 ); ?>
        </div>
        <p>
            <button type="button" id="etik-add-slot" class="button">
                + <?php esc_html_e( 'Ajouter un autre bloc de créneaux', 'wp-etik-events' ); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Un bloc de créneau = valeurs par défaut + grille jours.
     * FIX : utilise flexbox (jamais display:contents) pour éviter les bugs de grille CSS.
     */
    private function render_slot_block( int $index = 0, array $data = [] ) : void {
        $def_time  = esc_attr( $data['start_time']     ?? '09:00' );
        $def_dur   = esc_attr( $data['duration']       ?? '60' );
        $def_break = esc_attr( $data['break_duration'] ?? '0' );
        $act_days  = isset( $data['days'] )
            ? array_map( 'intval', explode( ',', $data['days'] ) )
            : [];
        ?>
        <div class="etik-slot-block postbox" style="margin-bottom:10px;">
            <div class="inside" style="padding:12px 16px;">

                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <strong class="etik-slot-block-title">
                        <?php printf( esc_html__( 'Créneau %d', 'wp-etik-events' ), $index + 1 ); ?>
                    </strong>
                    <button type="button" class="button-link button-link-delete etik-remove-slot"
                            style="<?php echo $index === 0 ? 'display:none;' : ''; ?>font-size:12px;">
                        ✕ <?php esc_html_e( 'Retirer', 'wp-etik-events' ); ?>
                    </button>
                </div>

                <!-- Valeurs par défaut -->
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;
                            padding:10px 12px;background:#f6f7f7;border-radius:4px;
                            border:1px solid #e2e4e7;margin-bottom:12px;">
                    <label style="font-size:12px;">
                        <?php esc_html_e( 'Heure par défaut', 'wp-etik-events' ); ?><br>
                        <input type="time" class="etik-default-time"
                               value="<?php echo $def_time; ?>" style="margin-top:4px;">
                    </label>
                    <label style="font-size:12px;">
                        <?php esc_html_e( 'Durée (min)', 'wp-etik-events' ); ?><br>
                        <input type="number" class="etik-default-duration small-text"
                               value="<?php echo $def_dur; ?>"
                               min="5" max="480" step="5"
                               style="width:75px;margin-top:4px;">
                    </label>
                    <label style="font-size:12px;">
                        <?php esc_html_e( 'Pause (min)', 'wp-etik-events' ); ?><br>
                        <input type="number" class="etik-default-break small-text"
                               value="<?php echo $def_break; ?>"
                               min="0" max="120" step="5"
                               style="width:75px;margin-top:4px;">
                    </label>
                    <button type="button" class="button etik-apply-defaults">
                        ↓ <?php esc_html_e( 'Appliquer aux jours cochés', 'wp-etik-events' ); ?>
                    </button>
                </div>

                <!-- Sélection rapide -->
                <p style="margin:0 0 8px;font-size:12px;">
                    <span style="color:#666;"><?php esc_html_e( 'Sélection rapide :', 'wp-etik-events' ); ?></span>
                    <button type="button" class="button-link etik-select-workdays"
                            style="margin:0 8px;font-size:12px;">
                        <?php esc_html_e( 'Lun–Ven', 'wp-etik-events' ); ?>
                    </button>
                    <button type="button" class="button-link etik-select-none"
                            style="font-size:12px;color:#d63638;">
                        <?php esc_html_e( 'Tout désélectionner', 'wp-etik-events' ); ?>
                    </button>
                </p>

                <!-- Grille jours (FLEXBOX — pas de display:contents) -->
                <div style="border:1px solid #e2e4e7;border-radius:4px;overflow:hidden;">

                    <div style="display:flex;background:#f0f0f1;padding:5px 12px;
                                border-bottom:1px solid #e2e4e7;
                                font-size:11px;font-weight:600;color:#555;">
                        <div style="width:120px;flex-shrink:0;">
                            <?php esc_html_e( 'Jour', 'wp-etik-events' ); ?>
                        </div>
                        <div style="flex:1;"><?php esc_html_e( 'Heure début', 'wp-etik-events' ); ?></div>
                        <div style="width:100px;"><?php esc_html_e( 'Durée (min)', 'wp-etik-events' ); ?></div>
                        <div style="width:100px;"><?php esc_html_e( 'Pause (min)', 'wp-etik-events' ); ?></div>
                    </div>

                    <?php foreach ( self::$day_names_full as $day_num => $day_name ) :
                        $checked    = in_array( $day_num, $act_days, true );
                        $is_weekend = $day_num >= 6;
                        $n          = "slots[{$index}][days][{$day_num}]";
                        ?>
                        <div class="etik-slot-day-row"
                             style="display:flex;align-items:center;padding:7px 12px;
                                    background:<?php echo $is_weekend ? '#fafafa' : '#fff'; ?>;
                                    border-bottom:1px solid #f0f0f1;min-height:40px;">

                            <!-- Checkbox + nom du jour -->
                            <div style="width:120px;flex-shrink:0;">
                                <label style="display:flex;align-items:center;gap:7px;
                                              font-size:13px;font-weight:normal;
                                              cursor:pointer;user-select:none;
                                              <?php echo $is_weekend ? 'color:#888;' : ''; ?>">
                                    <input type="checkbox"
                                           class="etik-day-toggle"
                                           data-day="<?php echo esc_attr( $day_num ); ?>"
                                           name="<?php echo esc_attr( "{$n}[enabled]" ); ?>"
                                           value="1"
                                           <?php checked( $checked ); ?>>
                                    <?php echo esc_html( $day_name ); ?>
                                </label>
                            </div>

                            <!-- Champs heure/durée/pause (flexbox, jamais display:contents) -->
                            <div class="etik-day-fields"
                                 style="<?php echo $checked ? 'display:flex;' : 'display:none;'; ?>
                                        align-items:center;gap:10px;flex:1;">

                                <input type="time"
                                       name="<?php echo esc_attr( "{$n}[start_time]" ); ?>"
                                       value="<?php echo $def_time; ?>"
                                       style="width:110px;"
                                       <?php echo ! $checked ? 'disabled' : ''; ?>>

                                <input type="number"
                                       name="<?php echo esc_attr( "{$n}[duration]" ); ?>"
                                       value="<?php echo $def_dur; ?>"
                                       min="5" max="480" step="5"
                                       class="small-text"
                                       style="width:70px;"
                                       <?php echo ! $checked ? 'disabled' : ''; ?>>
                                <span style="font-size:12px;color:#666;">min</span>

                                <input type="number"
                                       name="<?php echo esc_attr( "{$n}[break_duration]" ); ?>"
                                       value="<?php echo $def_break; ?>"
                                       min="0" max="120" step="5"
                                       class="small-text"
                                       style="width:70px;"
                                       <?php echo ! $checked ? 'disabled' : ''; ?>>
                                <span style="font-size:12px;color:#666;">
                                    <?php esc_html_e( 'pause', 'wp-etik-events' ); ?>
                                </span>
                            </div>

                            <!-- "Fermé" quand le jour est désactivé -->
                            <div class="etik-day-closed"
                                 style="<?php echo $checked ? 'display:none;' : 'display:block;'; ?>
                                        color:#aaa;font-size:12px;font-style:italic;">
                                <?php esc_html_e( 'Fermé', 'wp-etik-events' ); ?>
                            </div>

                        </div>
                    <?php endforeach; ?>

                </div><!-- /.grille jours -->
            </div>
        </div>
        <?php
    }

    // ─── UTILITAIRES COULEUR ────────────────────────────────────────────────

    private function contrast_color( string $hex ) : string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if ( strlen( $hex ) !== 6 ) return '#ffffff';
        $r   = hexdec( substr( $hex, 0, 2 ) );
        $g   = hexdec( substr( $hex, 2, 2 ) );
        $b   = hexdec( substr( $hex, 4, 2 ) );
        $lum = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
        return $lum > 0.55 ? '#1a1a1a' : '#ffffff';
    }

    private function darken_color( string $hex, int $amount = 40 ) : string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if ( strlen( $hex ) !== 6 ) return '#000000';
        return sprintf( '#%02x%02x%02x',
            max( 0, hexdec( substr( $hex, 0, 2 ) ) - $amount ),
            max( 0, hexdec( substr( $hex, 2, 2 ) ) - $amount ),
            max( 0, hexdec( substr( $hex, 4, 2 ) ) - $amount )
        );
    }
}