<?php
namespace WP_Etik;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ET_Builder_Module' ) ) return;

/**
 * Module Divi : Réservation de prestation
 * Slug : etk_prestation_booking
 *
 * UX (3 étapes) :
 *  1. Calendrier mensuel/hebdomadaire — couleur par disponibilité
 *  2. Liste de boutons-créneaux (sélection unique)
 *  3. Formulaire + bouton Réserver (ou Payer et réserver)
 */
class Divi_Prestation_Module extends \ET_Builder_Module {

    public $slug       = 'etk_prestation_booking';
    public $vb_support = 'partial';

    public function init() : void {
        $this->name = esc_html__( 'Etik — Réservation prestation', 'wp-etik-events' );

        $this->whitelisted_fields = [ 'prestation_id', 'view_mode', 'show_title', 'show_description', 'accent_color' ];
        $this->fields_defaults    = [
            'prestation_id'    => [ '0' ],
            'view_mode'        => [ 'month' ],
            'show_title'       => [ 'on' ],
            'show_description' => [ 'on' ],
            'accent_color'     => [ '#2aa78a' ],
        ];

        $this->settings_modal_toggles = [
            'general' => [ 'toggles' => [
                'content' => [ 'title' => esc_html__( 'Prestation', 'wp-etik-events' ),  'priority' => 10 ],
                'display' => [ 'title' => esc_html__( 'Affichage', 'wp-etik-events' ),   'priority' => 20 ],
            ] ],
        ];
    }

    public function get_fields() : array {
        $options = [ '0' => esc_html__( '— Choisir une prestation —', 'wp-etik-events' ) ];
        foreach ( get_posts( [ 'post_type' => 'etik_prestation', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ] ) as $p ) {
            $options[ (string) $p->ID ] = $p->post_title;
        }

        return [
            'prestation_id' => [
                'label'           => esc_html__( 'Prestation', 'wp-etik-events' ),
                'type'            => 'select',
                'option_category' => 'configuration',
                'options'         => $options,
                'toggle_slug'     => 'content',
            ],
            'view_mode' => [
                'label'           => esc_html__( 'Vue initiale', 'wp-etik-events' ),
                'type'            => 'select',
                'option_category' => 'configuration',
                'options'         => [
                    'month' => esc_html__( 'Mensuelle', 'wp-etik-events' ),
                    'week'  => esc_html__( 'Hebdomadaire', 'wp-etik-events' ),
                ],
                'toggle_slug' => 'display',
            ],
            'show_title' => [
                'label'           => esc_html__( 'Afficher le titre', 'wp-etik-events' ),
                'type'            => 'yes_no_button',
                'option_category' => 'configuration',
                'options'         => [ 'on' => esc_html__( 'Oui', 'wp-etik-events' ), 'off' => esc_html__( 'Non', 'wp-etik-events' ) ],
                'toggle_slug'     => 'display',
            ],
            'show_description' => [
                'label'           => esc_html__( 'Afficher la description', 'wp-etik-events' ),
                'type'            => 'yes_no_button',
                'option_category' => 'configuration',
                'options'         => [ 'on' => esc_html__( 'Oui', 'wp-etik-events' ), 'off' => esc_html__( 'Non', 'wp-etik-events' ) ],
                'toggle_slug'     => 'display',
            ],
            'accent_color' => [
                'label'           => esc_html__( 'Couleur principale', 'wp-etik-events' ),
                'type'            => 'color-alpha',
                'option_category' => 'configuration',
                'custom_color'    => true,
                'toggle_slug'     => 'display',
            ],
        ];
    }

    public function render( $attrs, $content = null, $render_slug = null ) : string {
        $prestation_id    = intval( $this->props['prestation_id']    ?? 0 );
        $view_mode        = sanitize_key( $this->props['view_mode']  ?? 'month' );
        $show_title       = ( $this->props['show_title']       ?? 'on' ) === 'on';
        $show_description = ( $this->props['show_description'] ?? 'on' ) === 'on';
        $accent_color     = sanitize_hex_color( $this->props['accent_color'] ?? '#2aa78a' ) ?: '#2aa78a';

        // ── Assets ────────────────────────────────────────────────────────────
        wp_enqueue_style(  'etik-prestation-booking', WP_ETIK_PLUGIN_URL . 'assets/css/prestation-booking.css', [], WP_ETIK_VERSION );
        wp_enqueue_script( 'etik-prestation-booking', WP_ETIK_PLUGIN_URL . 'assets/js/prestation-booking.js',  [ 'jquery' ], WP_ETIK_VERSION, true );

        // Passé une seule fois (le module peut être plusieurs fois sur la page)
        if ( ! wp_script_is( 'etik-prestation-booking', 'done' ) ) {
            wp_localize_script( 'etik-prestation-booking', 'etikBooking', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'etik_booking_nonce' ),
                'i18n'     => [
                    'loading'         => __( 'Chargement…', 'wp-etik-events' ),
                    'no_slots'        => __( 'Aucun créneau disponible ce jour.', 'wp-etik-events' ),
                    'reserve'         => __( 'Réserver', 'wp-etik-events' ),
                    'pay_reserve'     => __( 'Payer et réserver', 'wp-etik-events' ),
                    'full'            => __( 'Complet', 'wp-etik-events' ),
                    'required'        => __( 'Veuillez remplir tous les champs obligatoires.', 'wp-etik-events' ),
                    'booking_success' => __( 'Votre réservation est confirmée !', 'wp-etik-events' ),
                    'booking_paid'    => __( 'Paiement reçu — réservation confirmée !', 'wp-etik-events' ),
                    'back'            => __( '← Retour', 'wp-etik-events' ),
                    'new_booking'     => __( 'Nouvelle réservation', 'wp-etik-events' ),
                    'days'            => [ __( 'Lun', 'wp-etik-events' ), __( 'Mar', 'wp-etik-events' ), __( 'Mer', 'wp-etik-events' ), __( 'Jeu', 'wp-etik-events' ), __( 'Ven', 'wp-etik-events' ), __( 'Sam', 'wp-etik-events' ), __( 'Dim', 'wp-etik-events' ) ],
                    'months'          => [ __( 'Janvier', 'wp-etik-events' ), __( 'Février', 'wp-etik-events' ), __( 'Mars', 'wp-etik-events' ), __( 'Avril', 'wp-etik-events' ), __( 'Mai', 'wp-etik-events' ), __( 'Juin', 'wp-etik-events' ), __( 'Juillet', 'wp-etik-events' ), __( 'Août', 'wp-etik-events' ), __( 'Septembre', 'wp-etik-events' ), __( 'Octobre', 'wp-etik-events' ), __( 'Novembre', 'wp-etik-events' ), __( 'Décembre', 'wp-etik-events' ) ],
                ],
            ] );
        }

        if ( ! $prestation_id ) {
            return '<div class="etik-booking-notice">' . esc_html__( 'Aucune prestation sélectionnée.', 'wp-etik-events' ) . '</div>';
        }

        $post = get_post( $prestation_id );
        if ( ! $post || $post->post_type !== 'etik_prestation' ) {
            return '<div class="etik-booking-notice">' . esc_html__( 'Prestation introuvable.', 'wp-etik-events' ) . '</div>';
        }

        // Disponibilité du mois courant (SSR pour affichage immédiat sans AJAX)
        $year  = (int) date( 'Y' );
        $month = (int) date( 'n' );
        $avail = Prestation_Booking::get_month_availability( $prestation_id, $year, $month );

        $price     = (float) get_post_meta( $prestation_id, 'etik_prestation_price', true );
        $pay_req   = get_post_meta( $prestation_id, 'etik_prestation_payment_required', true ) === '1';
        $color     = sanitize_hex_color( get_post_meta( $prestation_id, 'etik_prestation_color', true ) ) ?: $accent_color;
        $return_url = esc_url( get_permalink() ?: home_url( '/' ) );

        ob_start();
        ?>
        <div class="etik-booking-module"
             data-prestation-id="<?php echo esc_attr( $prestation_id ); ?>"
             data-view="<?php echo esc_attr( $view_mode ); ?>"
             data-pay-required="<?php echo $pay_req ? '1' : '0'; ?>"
             data-price="<?php echo esc_attr( number_format( $price, 2, '.', '' ) ); ?>"
             data-return-url="<?php echo esc_attr( $return_url ); ?>"
             style="--etik-accent:<?php echo esc_attr( $accent_color ); ?>;">

            <?php if ( $show_title ) : ?>
                <h3 class="etik-booking-title" style="color:<?php echo esc_attr( $color ); ?>;">
                    <?php echo esc_html( $post->post_title ); ?>
                </h3>
            <?php endif; ?>

            <?php if ( $show_description && ! empty( $post->post_content ) ) : ?>
                <div class="etik-booking-desc"><?php echo wp_kses_post( wpautop( $post->post_content ) ); ?></div>
            <?php endif; ?>

            <?php if ( $price > 0 ) : ?>
                <p class="etik-booking-price">
                    <?php echo esc_html( number_format( $price, 2, ',', ' ' ) . ' €' ); ?>
                    <?php if ( $pay_req ) : ?>
                        <span class="etik-badge-pay"><?php esc_html_e( 'Paiement requis', 'wp-etik-events' ); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <!-- Étape 1 : Calendrier -->
            <div class="etik-booking-step etik-step-calendar">
                <div class="etik-booking-nav">
                    <button type="button" class="etik-nav-prev" aria-label="<?php esc_attr_e( 'Mois précédent', 'wp-etik-events' ); ?>">&#8249;</button>
                    <span class="etik-month-label"><?php echo esc_html( date_i18n( 'F Y', mktime( 0,0,0,$month,1,$year ) ) ); ?></span>
                    <button type="button" class="etik-nav-next" aria-label="<?php esc_attr_e( 'Mois suivant', 'wp-etik-events' ); ?>">&#8250;</button>
                    <span style="flex:1"></span>
                    <button type="button" class="etik-toggle-view" data-view="month" title="<?php esc_attr_e( 'Vue mensuelle', 'wp-etik-events' ); ?>">&#9635;</button>
                    <button type="button" class="etik-toggle-view" data-view="week"  title="<?php esc_attr_e( 'Vue hebdomadaire', 'wp-etik-events' ); ?>">&#9776;</button>
                </div>
                <div class="etik-calendar-wrap" data-year="<?php echo esc_attr( $year ); ?>" data-month="<?php echo esc_attr( $month ); ?>">
                    <?php $this->render_calendar( $year, $month, $avail, $view_mode ); ?>
                </div>
                <div class="etik-cal-legend">
                    <span class="etik-legend-item"><span class="etik-legend-dot etik-dot-available"></span><?php esc_html_e( 'Disponible', 'wp-etik-events' ); ?></span>
                    <span class="etik-legend-item"><span class="etik-legend-dot etik-dot-full"></span><?php esc_html_e( 'Complet', 'wp-etik-events' ); ?></span>
                    <span class="etik-legend-item"><span class="etik-legend-dot etik-dot-closed"></span><?php esc_html_e( 'Fermé', 'wp-etik-events' ); ?></span>
                </div>
            </div>

            <!-- Étape 2 : Créneaux -->
            <div class="etik-booking-step etik-step-slots" style="display:none;">
                <h4 class="etik-slots-heading"></h4>
                <div class="etik-slots-list"></div>
                <div class="etik-slot-action" style="display:none;">
                    <button type="button" class="etik-btn-reserve etik-btn">
                        <?php esc_html_e( 'Réserver', 'wp-etik-events' ); ?>
                    </button>
                    <button type="button" class="etik-btn-back-cal etik-btn etik-btn-sec">
                        <?php esc_html_e( '← Calendrier', 'wp-etik-events' ); ?>
                    </button>
                </div>
            </div>

            <!-- Étape 3 : Formulaire -->
            <div class="etik-booking-step etik-step-form" style="display:none;">
                <h4 class="etik-form-heading"><?php esc_html_e( 'Vos informations', 'wp-etik-events' ); ?></h4>
                <div class="etik-booking-feedback" style="display:none;"></div>
                <form class="etik-booking-form" novalidate>
                    <input type="hidden" name="prestation_id" value="<?php echo esc_attr( $prestation_id ); ?>">
                    <input type="hidden" name="slot_id"       value="">
                    <input type="hidden" name="booking_date"  value="">
                    <input type="hidden" name="booking_time"  value="">
                    <input type="hidden" name="form_id"       value="">
                    <input type="hidden" name="return_url"    value="<?php echo esc_attr( $return_url ); ?>">


                    <!-- Champs dynamiques du formulaire personnalisé -->
                    <div class="etik-custom-fields"></div>

                    <!-- Récapitulatif de la sélection -->
                    <div class="etik-booking-recap"></div>

                    <div class="etik-form-actions">
                        <button type="submit" class="etik-btn etik-btn-submit"><?php esc_html_e( 'Réserver', 'wp-etik-events' ); ?></button>
                        <button type="button" class="etik-btn-back-slots etik-btn etik-btn-sec"><?php esc_html_e( '← Créneaux', 'wp-etik-events' ); ?></button>
                    </div>
                </form>
            </div>

            <!-- Confirmation -->
            <div class="etik-booking-step etik-step-success" style="display:none;">
                <div class="etik-success-box">
                    <p class="etik-success-msg"></p>
                    <button type="button" class="etik-btn-new etik-btn etik-btn-sec"><?php esc_html_e( 'Nouvelle réservation', 'wp-etik-events' ); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ─── CALENDRIER SSR ───────────────────────────────────────────────────────

    public function render_calendar( int $year, int $month, array $avail, string $view_mode = 'month' ) : void {
        $first_dow  = (int) date( 'N', mktime( 0,0,0,$month,1,$year ) ); // 1=lun
        $days_count = (int) date( 't', mktime( 0,0,0,$month,1,$year ) );
        $today      = date( 'Y-m-d' );
        $day_labels = [ 'Lun','Mar','Mer','Jeu','Ven','Sam','Dim' ];
        ?>
        <div class="etik-calendar etik-view-<?php echo esc_attr( $view_mode ); ?>">
            <div class="etik-cal-header">
                <?php foreach ( $day_labels as $dl ) : ?>
                    <div class="etik-cal-hd"><?php echo esc_html( $dl ); ?></div>
                <?php endforeach; ?>
            </div>
            <div class="etik-cal-grid">
                <?php for ( $i = 1; $i < $first_dow; $i++ ) : ?>
                    <div class="etik-cal-day etik-cal-empty"></div>
                <?php endfor; ?>

                <?php for ( $d = 1; $d <= $days_count; $d++ ) : ?>
                    <?php
                    $date   = sprintf( '%04d-%02d-%02d', $year, $month, $d );
                    $status = $avail[ $date ] ?? 'none';
                    $cls    = [ 'etik-cal-day', "etik-cal-{$status}" ];
                    if ( $date === $today ) $cls[] = 'etik-cal-today';
                    $clickable = in_array( $status, [ 'available', 'full' ], true );
                    ?>
                    <div class="<?php echo esc_attr( implode( ' ', $cls ) ); ?>"
                         <?php if ( $clickable ) : ?>data-date="<?php echo esc_attr( $date ); ?>" role="button" tabindex="0" aria-label="<?php echo esc_attr( date_i18n( 'd M Y', strtotime( $date ) ) ); ?>"<?php endif; ?>>
                        <span class="etik-day-num"><?php echo esc_html( $d ); ?></span>
                        <span class="etik-day-dot"></span>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php
    }
}