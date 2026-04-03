<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Page admin "Prestations" sous le menu Événements.
 *
 * Onglets : Liste | Calendrier | Fermetures | Réservations
 * La liste affiche les CPT etik_prestation avec couleur, prix, places et créneaux configurés.
 * Le formulaire d'ajout intègre les champs méta + un configurateur de récurrence hebdomadaire.
 */
class Prestation_Settings {

    const MENU_SLUG    = 'wp-etik-prestation';
    const OPTION_GROUP = 'lwe_prestation_settings';

    /** Noms courts des jours (lundi = 1 … dimanche = 7 selon ISO) */
    private static array $day_labels = [
        1 => 'Lun',
        2 => 'Mar',
        3 => 'Mer',
        4 => 'Jeu',
        5 => 'Ven',
        6 => 'Sam',
        7 => 'Dim',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // INIT
    // ─────────────────────────────────────────────────────────────────────────

    public static function init() : void {
        $self = new self();
        add_action( 'admin_menu',             [ $self, 'add_menu' ] );
        add_action( 'admin_init',             [ $self, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts',  [ $self, 'enqueue_assets' ] );
    }

    public function __construct() {
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Meta.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Closures.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Reservation_List.php';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MENU
    // ─────────────────────────────────────────────────────────────────────────

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

    public function register_settings() : void {
        // Rien à enregistrer ici pour l'instant
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ASSETS
    // ─────────────────────────────────────────────────────────────────────────

    public function enqueue_assets( $hook ) : void {
        // Color picker et datepicker partout dans l'admin (méta boxes)
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( $screen->id !== 'etik_event_page_' . self::MENU_SLUG ) return;

        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );

        // Initialisation du color picker + datepicker + UI créneaux
        $js_init = <<<'JS'
jQuery(document).ready(function($) {
    // Color picker
    $('.color-picker').wpColorPicker();

    // Datepicker
    $('.datepicker').datepicker({ dateFormat: 'yy-mm-dd' });

    // ── Gestion des créneaux ───────────────────────────────────────────────

    // Afficher / masquer les champs heure selon la case cochée
    $(document).on('change', '.etik-day-toggle', function() {
        var $row = $(this).closest('.etik-slot-day-row');
        $row.find('.etik-day-fields').toggle(this.checked);
    });

    // Appliquer l'heure / durée par défaut à tous les jours cochés
    $(document).on('click', '.etik-apply-defaults', function(e) {
        e.preventDefault();
        var $block = $(this).closest('.etik-slot-block');
        var defaultTime = $block.find('.etik-default-time').val();
        var defaultDur  = $block.find('.etik-default-duration').val();
        var defaultBreak = $block.find('.etik-default-break').val();

        $block.find('.etik-day-toggle:checked').each(function() {
            var $row = $(this).closest('.etik-slot-day-row');
            if (defaultTime)  $row.find('input[name$="[start_time]"]').val(defaultTime);
            if (defaultDur)   $row.find('input[name$="[duration]"]').val(defaultDur);
            if (defaultBreak) $row.find('input[name$="[break]"]').val(defaultBreak);
        });
    });

    // Sélectionner / désélectionner tous les jours ouvrés
    $(document).on('click', '.etik-toggle-week', function(e) {
        e.preventDefault();
        var $block = $(this).closest('.etik-slot-block');
        var checked = $(this).data('select') === 'work';
        $block.find('.etik-day-toggle').each(function() {
            var day = parseInt($(this).data('day'));
            var isWork = day >= 1 && day <= 5;
            var shouldCheck = (checked && isWork) || (!checked && false);
            if (checked) {
                $(this).prop('checked', isWork);
            } else {
                $(this).prop('checked', false);
            }
            $(this).trigger('change');
        });
    });

    // Ajouter un bloc de créneau supplémentaire (clone)
    var slotCount = 1;
    $(document).on('click', '#etik-add-slot', function(e) {
        e.preventDefault();
        slotCount++;
        var $tpl  = $('.etik-slot-block').first().clone(true);
        $tpl.find('.etik-slot-block-title').text('Créneau ' + slotCount);
        // Réinitialiser les valeurs clonées
        $tpl.find('input[type="checkbox"]').prop('checked', false).trigger('change');
        $tpl.find('input[type="time"], input[type="number"]').val('');
        // Renommer les indices de tableau
        $tpl.find('[name]').each(function() {
            $(this).attr('name', $(this).attr('name').replace(/slots\[\d+\]/, 'slots[' + (slotCount-1) + ']'));
        });
        $('#etik-slots-container').append($tpl);
    });

    // Supprimer un bloc de créneau
    $(document).on('click', '.etik-remove-slot', function(e) {
        e.preventDefault();
        if ($('.etik-slot-block').length > 1) {
            $(this).closest('.etik-slot-block').remove();
        }
    });
});
JS;
        wp_add_inline_script( 'wp-color-picker', $js_init );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAGE PRINCIPALE
    // ─────────────────────────────────────────────────────────────────────────

    public function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        $current_action = ( isset( $_GET['action'] ) && $_GET['action'] === 'add' ) ? 'add' : 'list';
        $meta_instance  = new Prestation_Meta();
        ?>
        <div class="wrap etik-admin">

            <?php $this->render_notices(); ?>

            <h1><?php esc_html_e( 'Prestations', 'wp-etik-events' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Gérez vos prestations récurrentes et leurs créneaux de disponibilité.', 'wp-etik-events' ); ?></p>

            <?php if ( $current_action === 'list' ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'action', 'add' ) ); ?>" class="button button-primary">
                    + <?php esc_html_e( 'Ajouter une prestation', 'wp-etik-events' ); ?>
                </a>
            <?php else : ?>
                <a href="<?php echo esc_url( remove_query_arg( 'action' ) ); ?>" class="button">
                    ← <?php esc_html_e( 'Retour à la liste', 'wp-etik-events' ); ?>
                </a>
            <?php endif; ?>

            <hr style="margin:16px 0;border-top:1px solid #ddd;">

            <?php if ( $current_action === 'add' ) : ?>
                <div class="postbox" style="max-width:960px;">
                    <h3 class="hndle"><span><?php esc_html_e( 'Nouvelle prestation', 'wp-etik-events' ); ?></span></h3>
                    <div class="inside">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="wp_etik_create_prestation">
                            <?php wp_nonce_field( 'wp_etik_create_prestation_nonce', 'wp_etik_prestation_nonce' ); ?>

                            <?php $meta_instance->meta_box_html( null ); ?>
                            <?php $this->render_slots_section(); ?>

                            <p style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;">
                                <button type="submit" class="button button-primary button-large">
                                    <?php esc_html_e( 'Enregistrer la prestation', 'wp-etik-events' ); ?>
                                </button>
                                <a href="<?php echo esc_url( remove_query_arg( 'action' ) ); ?>"
                                   class="button button-secondary" style="margin-left:8px;">
                                    <?php esc_html_e( 'Annuler', 'wp-etik-events' ); ?>
                                </a>
                            </p>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Onglets (toujours visibles) -->
            <div class="etik-tabs" style="margin-top:<?php echo $current_action === 'add' ? '24px' : '0'; ?>">
                <button class="etik-tab <?php echo $current_action === 'list' ? 'active' : ''; ?>" data-tab="list">
                    <?php esc_html_e( 'Liste', 'wp-etik-events' ); ?>
                </button>
                <button class="etik-tab" data-tab="calendar"><?php esc_html_e( 'Calendrier', 'wp-etik-events' ); ?></button>
                <button class="etik-tab" data-tab="closures"><?php esc_html_e( 'Fermetures', 'wp-etik-events' ); ?></button>
                <button class="etik-tab" data-tab="reservations"><?php esc_html_e( 'Réservations', 'wp-etik-events' ); ?></button>
            </div>

            <div class="etik-panels">
                <div class="etik-panel <?php echo $current_action === 'list' ? 'active' : ''; ?>" data-panel="list">
                    <?php $this->render_list(); ?>
                </div>
                <div class="etik-panel" data-panel="calendar">
                    <?php $this->render_calendar(); ?>
                </div>
                <div class="etik-panel" data-panel="closures">
                    <?php $this->render_closures(); ?>
                </div>
                <div class="etik-panel" data-panel="reservations">
                    <?php $this->render_reservations(); ?>
                </div>
            </div>

        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NOTICES
    // ─────────────────────────────────────────────────────────────────────────

    private function render_notices() : void {
        $messages = [
            'prestation_created' => [ 'success', __( 'Prestation enregistrée avec succès.', 'wp-etik-events' ) ],
            'prestation_updated' => [ 'success', __( 'Prestation mise à jour.', 'wp-etik-events' ) ],
            'prestation_deleted' => [ 'success', __( 'Prestation supprimée.', 'wp-etik-events' ) ],
            'missing_title'      => [ 'error',   __( 'Erreur : le titre est obligatoire.', 'wp-etik-events' ) ],
            'db_error'           => [ 'error',   __( 'Erreur lors de l\'enregistrement en base de données.', 'wp-etik-events' ) ],
        ];

        $key = sanitize_key( $_GET['message'] ?? '' );
        if ( $key && isset( $messages[ $key ] ) ) {
            [ $type, $text ] = $messages[ $key ];
            $class = $type === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LISTE DES PRESTATIONS (CPT etik_prestation)
    // ─────────────────────────────────────────────────────────────────────────

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
            echo '<p style="padding:16px 0;color:#666;">'
               . esc_html__( 'Aucune prestation trouvée. Cliquez sur « Ajouter une prestation » pour commencer.', 'wp-etik-events' )
               . '</p>';
            return;
        }

        $slots_table = $wpdb->prefix . 'etik_prestation_slots';
        $res_table   = $wpdb->prefix . 'etik_reservations';

        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:24px;"></th>
                    <th><?php esc_html_e( 'Prestation', 'wp-etik-events' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Prix', 'wp-etik-events' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Places max', 'wp-etik-events' ); ?></th>
                    <th style="width:110px;"><?php esc_html_e( 'Créneaux', 'wp-etik-events' ); ?></th>
                    <th style="width:110px;"><?php esc_html_e( 'Réservations', 'wp-etik-events' ); ?></th>
                    <th style="width:140px;"><?php esc_html_e( 'Actions', 'wp-etik-events' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $posts as $post ) :
                $color     = get_post_meta( $post->ID, 'etik_prestation_color', true ) ?: '#2aa78a';
                $price     = get_post_meta( $post->ID, 'etik_prestation_price', true );
                $max_place = get_post_meta( $post->ID, 'etik_prestation_max_place', true );
                $payment   = get_post_meta( $post->ID, 'etik_prestation_payment_required', true ) === '1';

                // Nombre de créneaux configurés
                $slot_count = (int) $wpdb->get_var(
                    $wpdb->prepare( "SELECT COUNT(*) FROM {$slots_table} WHERE prestation_id = %d AND is_closed = 0", $post->ID )
                );

                // Nombre de réservations confirmées
                $res_count = (int) $wpdb->get_var(
                    $wpdb->prepare( "SELECT COUNT(*) FROM {$res_table} WHERE prestation_id = %d AND status = 'confirmed'", $post->ID )
                );

                // Jours récurrents (agrégés sur tous les créneaux actifs)
                $days_raw = $wpdb->get_col(
                    $wpdb->prepare( "SELECT days FROM {$slots_table} WHERE prestation_id = %d AND is_closed = 0", $post->ID )
                );
                $all_days = [];
                foreach ( $days_raw as $d ) {
                    if ( $d ) {
                        foreach ( explode( ',', $d ) as $n ) {
                            $all_days[ (int) $n ] = true;
                        }
                    }
                }
                ksort( $all_days );
                $days_html = '';
                foreach ( $all_days as $n => $_ ) {
                    $label = self::$day_labels[ $n ] ?? $n;
                    $days_html .= '<span style="display:inline-block;font-size:11px;padding:1px 5px;margin:1px;border-radius:3px;background:#e8f4fd;color:#0073aa;">'
                                . esc_html( $label ) . '</span>';
                }

                $edit_url   = get_edit_post_link( $post->ID );
                $delete_url = wp_nonce_url(
                    add_query_arg( [
                        'page'    => self::MENU_SLUG,
                        'action'  => 'delete_prestation',
                        'post_id' => $post->ID,
                    ], admin_url( 'edit.php?post_type=etik_event' ) ),
                    'delete_prestation_' . $post->ID
                );
                ?>
                <tr>
                    <td>
                        <span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:<?php echo esc_attr( $color ); ?>;border:1px solid rgba(0,0,0,0.15);vertical-align:middle;"></span>
                    </td>
                    <td>
                        <strong>
                            <a href="<?php echo esc_url( $edit_url ); ?>">
                                <?php echo esc_html( $post->post_title ); ?>
                            </a>
                        </strong>
                        <?php if ( $post->post_status === 'draft' ) : ?>
                            <span style="color:#888;font-size:11px;"> — <?php esc_html_e( 'Brouillon', 'wp-etik-events' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $payment ) : ?>
                            <span style="font-size:11px;color:#FC6B0D;margin-left:4px;">€ <?php esc_html_e( 'Paiement requis', 'wp-etik-events' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $post->post_excerpt ) : ?>
                            <p class="description" style="margin:2px 0 0;"><?php echo esc_html( wp_trim_words( $post->post_excerpt, 15 ) ); ?></p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $price !== '' ? esc_html( number_format_i18n( (float) $price, 2 ) ) . ' €' : '—'; ?>
                    </td>
                    <td>
                        <?php echo $max_place ? esc_html( $max_place ) : '<span style="color:#888;">∞</span>'; ?>
                    </td>
                    <td>
                        <?php if ( $slot_count > 0 ) : ?>
                            <span style="font-weight:600;color:#0b7a4b;"><?php echo esc_html( $slot_count ); ?></span>
                            <span style="font-size:11px;color:#666;"> <?php esc_html_e( 'créneau(x)', 'wp-etik-events' ); ?></span>
                            <?php if ( $days_html ) : ?>
                                <div style="margin-top:4px;"><?php echo $days_html; ?></div>
                            <?php endif; ?>
                        <?php else : ?>
                            <span style="color:#a0a0a0;font-size:12px;font-style:italic;"><?php esc_html_e( 'Aucun créneau', 'wp-etik-events' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $res_count > 0 ) : ?>
                            <strong style="color:#0c71c3;"><?php echo esc_html( $res_count ); ?></strong>
                        <?php else : ?>
                            <span style="color:#a0a0a0;">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">
                            <?php esc_html_e( 'Modifier', 'wp-etik-events' ); ?>
                        </a>
                        <a class="button button-small button-link-delete"
                           href="<?php echo esc_url( $delete_url ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Supprimer cette prestation et ses créneaux ?', 'wp-etik-events' ); ?>');">
                            <?php esc_html_e( 'Supprimer', 'wp-etik-events' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION CRÉNEAUX RÉCURRENTS (dans le formulaire d'ajout)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_slots_section() : void {
        ?>
        <hr style="margin:24px 0 16px;border-top:1px solid #ddd;">

        <h3 style="margin:0 0 4px;font-size:14px;font-weight:600;">
            <?php esc_html_e( 'Créneaux de disponibilité (optionnel)', 'wp-etik-events' ); ?>
        </h3>
        <p class="description" style="margin:0 0 16px;">
            <?php esc_html_e( 'Configurez les jours et horaires récurrents pour cette prestation. Vous pourrez en ajouter ou modifier d\'autres après la création.', 'wp-etik-events' ); ?>
        </p>

        <div id="etik-slots-container">
            <?php $this->render_slot_block( 0 ); ?>
        </div>

        <p>
            <button type="button" id="etik-add-slot" class="button button-secondary">
                + <?php esc_html_e( 'Ajouter un autre créneau', 'wp-etik-events' ); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Génère un bloc de configuration de créneau (durée + grille jours).
     *
     * @param int   $index  Indice dans le tableau slots[] du formulaire
     * @param array $data   Valeurs pré-remplies (pour édition future)
     */
    private function render_slot_block( int $index = 0, array $data = [] ) : void {
        $default_time     = esc_attr( $data['start_time']     ?? '09:00' );
        $default_duration = esc_attr( $data['duration']       ?? '60' );
        $default_break    = esc_attr( $data['break_duration'] ?? '15' );
        $active_days      = ! empty( $data['days'] ) ? array_map( 'intval', explode( ',', $data['days'] ) ) : [];
        $day_times        = $data['day_times'] ?? []; // [ day => start_time ]
        ?>
        <div class="etik-slot-block postbox" style="margin-bottom:12px;padding:0;">
            <div class="inside" style="padding:14px 16px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                    <strong class="etik-slot-block-title">
                        <?php echo $index === 0
                            ? esc_html__( 'Créneau 1', 'wp-etik-events' )
                            : sprintf( esc_html__( 'Créneau %d', 'wp-etik-events' ), $index + 1 ); ?>
                    </strong>
                    <?php if ( $index > 0 ) : ?>
                        <button type="button" class="button-link button-link-delete etik-remove-slot" style="font-size:12px;">
                            ✕ <?php esc_html_e( 'Supprimer ce créneau', 'wp-etik-events' ); ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Valeurs par défaut + bouton Appliquer -->
                <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:14px;padding:10px 12px;background:#f6f7f7;border-radius:4px;border:1px solid #e2e4e7;">
                    <label style="font-size:13px;">
                        <?php esc_html_e( 'Heure de début', 'wp-etik-events' ); ?><br>
                        <input type="time" class="etik-default-time" value="<?php echo $default_time; ?>"
                               style="width:110px;margin-top:4px;">
                    </label>
                    <label style="font-size:13px;">
                        <?php esc_html_e( 'Durée (min)', 'wp-etik-events' ); ?><br>
                        <input type="number" class="etik-default-duration small-text" value="<?php echo $default_duration; ?>"
                               min="5" max="480" step="5" style="width:80px;margin-top:4px;">
                    </label>
                    <label style="font-size:13px;">
                        <?php esc_html_e( 'Pause (min)', 'wp-etik-events' ); ?><br>
                        <input type="number" class="etik-default-break small-text" value="<?php echo $default_break; ?>"
                               min="0" max="120" step="5" style="width:80px;margin-top:4px;">
                    </label>
                    <button type="button" class="button etik-apply-defaults" title="<?php esc_attr_e('Appliquer aux jours sélectionnés','wp-etik-events'); ?>" style="align-self:flex-end;">
                        ↓ <?php esc_html_e( 'Appliquer aux jours cochés', 'wp-etik-events' ); ?>
                    </button>
                </div>

                <!-- Raccourcis de sélection -->
                <div style="margin-bottom:10px;font-size:12px;">
                    <span style="color:#666;"><?php esc_html_e( 'Sélection rapide :', 'wp-etik-events' ); ?></span>
                    <button type="button" class="button-link etik-toggle-week" data-select="work" style="margin:0 8px;font-size:12px;">
                        <?php esc_html_e( 'Lun–Ven', 'wp-etik-events' ); ?>
                    </button>
                    <button type="button" class="button-link etik-toggle-week" data-select="none" style="font-size:12px;">
                        <?php esc_html_e( 'Tout désélectionner', 'wp-etik-events' ); ?>
                    </button>
                </div>

                <!-- Grille jours de la semaine -->
                <div style="border:1px solid #e2e4e7;border-radius:6px;overflow:hidden;">

                    <!-- En-tête -->
                    <div style="display:grid;grid-template-columns:120px 1fr 1fr 1fr;gap:0;background:#f0f0f1;padding:6px 12px;font-size:12px;font-weight:600;color:#555;border-bottom:1px solid #e2e4e7;">
                        <div><?php esc_html_e( 'Jour', 'wp-etik-events' ); ?></div>
                        <div><?php esc_html_e( 'Heure de début', 'wp-etik-events' ); ?></div>
                        <div><?php esc_html_e( 'Durée (min)', 'wp-etik-events' ); ?></div>
                        <div><?php esc_html_e( 'Pause (min)', 'wp-etik-events' ); ?></div>
                    </div>

                    <?php
                    $day_names = [
                        1 => __( 'Lundi',    'wp-etik-events' ),
                        2 => __( 'Mardi',    'wp-etik-events' ),
                        3 => __( 'Mercredi', 'wp-etik-events' ),
                        4 => __( 'Jeudi',    'wp-etik-events' ),
                        5 => __( 'Vendredi', 'wp-etik-events' ),
                        6 => __( 'Samedi',   'wp-etik-events' ),
                        7 => __( 'Dimanche', 'wp-etik-events' ),
                    ];

                    foreach ( $day_names as $day_num => $day_name ) :
                        $is_checked  = in_array( $day_num, $active_days, true );
                        $day_time    = esc_attr( $day_times[ $day_num ] ?? $default_time );
                        $is_weekend  = $day_num >= 6;
                        $row_bg      = $is_weekend ? '#fafafa' : '#fff';
                        $n           = "slots[{$index}][days][{$day_num}]";
                        ?>
                        <div class="etik-slot-day-row" style="display:grid;grid-template-columns:120px 1fr 1fr 1fr;gap:0;padding:8px 12px;background:<?php echo $row_bg; ?>;border-bottom:1px solid #f0f0f1;align-items:center;">

                            <!-- Jour + toggle -->
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none;">
                                <input type="checkbox"
                                       class="etik-day-toggle"
                                       data-day="<?php echo esc_attr( $day_num ); ?>"
                                       name="<?php echo esc_attr( $n ); ?>[enabled]"
                                       value="1"
                                       <?php checked( $is_checked ); ?>>
                                <span style="<?php echo $is_weekend ? 'color:#888;' : ''; ?>">
                                    <?php echo esc_html( $day_name ); ?>
                                </span>
                            </label>

                            <!-- Champs heure / durée / pause (masqués si non coché) -->
                            <div class="etik-day-fields" style="display:<?php echo $is_checked ? 'contents' : 'none'; ?>;contents;">
                                <div style="<?php echo $is_checked ? '' : 'visibility:hidden;'; ?>">
                                    <input type="time"
                                           name="<?php echo esc_attr( $n ); ?>[start_time]"
                                           value="<?php echo esc_attr( $day_times[ $day_num ] ?? $default_time ); ?>"
                                           style="width:110px;"
                                           <?php echo ! $is_checked ? 'disabled' : ''; ?>>
                                </div>
                                <div style="<?php echo $is_checked ? '' : 'visibility:hidden;'; ?>">
                                    <input type="number"
                                           name="<?php echo esc_attr( $n ); ?>[duration]"
                                           value="<?php echo $default_duration; ?>"
                                           min="5" max="480" step="5"
                                           class="small-text"
                                           style="width:80px;"
                                           <?php echo ! $is_checked ? 'disabled' : ''; ?>>
                                </div>
                                <div style="<?php echo $is_checked ? '' : 'visibility:hidden;'; ?>">
                                    <input type="number"
                                           name="<?php echo esc_attr( $n ); ?>[break_duration]"
                                           value="<?php echo $default_break; ?>"
                                           min="0" max="120" step="5"
                                           class="small-text"
                                           style="width:80px;"
                                           <?php echo ! $is_checked ? 'disabled' : ''; ?>>
                                </div>
                            </div>

                            <?php if ( ! $is_checked ) : ?>
                                <div></div><div></div><div></div>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>

                </div><!-- /.grille -->
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ONGLET CALENDRIER
    // ─────────────────────────────────────────────────────────────────────────

    private function render_calendar() : void {
        echo '<p style="padding:16px 0;color:#666;font-style:italic;">'
           . esc_html__( 'Vue calendrier des créneaux disponibles (à venir).', 'wp-etik-events' )
           . '</p>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ONGLET FERMETURES
    // ─────────────────────────────────────────────────────────────────────────

    private function render_closures() : void {
        echo '<p style="padding:16px 0;color:#666;font-style:italic;">'
           . esc_html__( 'Gestion des fermetures exceptionnelles (à venir).', 'wp-etik-events' )
           . '</p>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ONGLET RÉSERVATIONS
    // ─────────────────────────────────────────────────────────────────────────

    private function render_reservations() : void {
        global $wpdb;

        $res_table  = $wpdb->prefix . 'etik_reservations';
        $pres_table = $wpdb->prefix . 'posts';

        // Table check (évite une erreur fatale si la table n'existe pas encore)
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$res_table}'" ) !== $res_table ) {
            echo '<p class="description">' . esc_html__( 'Table des réservations non trouvée. Réactivez le plugin pour créer les tables.', 'wp-etik-events' ) . '</p>';
            return;
        }

        $reservations = $wpdb->get_results(
            "SELECT r.*, p.post_title AS prestation_title
             FROM {$res_table} r
             LEFT JOIN {$pres_table} p ON r.prestation_id = p.ID
             ORDER BY r.created_at DESC
             LIMIT 50"
        );

        if ( empty( $reservations ) ) {
            echo '<p style="padding:16px 0;color:#666;">' . esc_html__( 'Aucune réservation pour l\'instant.', 'wp-etik-events' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?php esc_html_e( 'Prestation', 'wp-etik-events' ); ?></th>
                    <th><?php esc_html_e( 'Client', 'wp-etik-events' ); ?></th>
                    <th><?php esc_html_e( 'Statut', 'wp-etik-events' ); ?></th>
                    <th><?php esc_html_e( 'Date réservation', 'wp-etik-events' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $reservations as $r ) :
                $user  = get_userdata( (int) $r->user_id );
                $name  = $user ? trim( $user->first_name . ' ' . $user->last_name ) ?: $user->user_email : '—';
                $colors = [ 'pending' => '#ffb02e', 'confirmed' => '#2aa78a', 'cancelled' => '#9aa0a6' ];
                $badge_bg = $colors[ $r->status ] ?? '#ddd';
                ?>
                <tr>
                    <td><?php echo esc_html( $r->id ); ?></td>
                    <td><?php echo esc_html( $r->prestation_title ); ?></td>
                    <td><?php echo esc_html( $name ); ?></td>
                    <td>
                        <span style="background:<?php echo esc_attr( $badge_bg ); ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;">
                            <?php echo esc_html( $r->status ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $r->created_at ) ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}