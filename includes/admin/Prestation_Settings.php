<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prestation_Settings {

    const MENU_SLUG = 'wp-etik-prestation';
    const OPTION_GROUP = 'lwe_prestation_settings';

    public static function init() : void {
        $self = new self();
        add_action( 'admin_menu', [ $self, 'add_menu' ] );
        add_action( 'admin_init', [ $self, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $self, 'enqueue_assets' ] );
    }

    public function __construct() {
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Meta.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Closures.php';
        require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/Prestation_Reservation_List.php';
    }

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
        // Enregistrer les settings si nécessaire
    }

    public function enqueue_assets( $hook ) : void {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( $screen->id !== 'etik_event_page_' . self::MENU_SLUG && $screen->id !== 'settings_page_' . self::MENU_SLUG ) return;

        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        ?>
        <div class="wrap etik-admin">
            <h1><?php esc_html_e( 'Prestations', 'wp-etik-events' ); ?></h1>
            <p><?php esc_html_e( 'Gérez vos prestations récurrentes ici.', 'wp-etik-events' ); ?></p>

            <!-- Bouton Ajouter -->
            <button type="button" class="button button-primary" id="add-prestation-btn">Ajouter une prestation</button>

            <!-- Onglets -->
            <div class="etik-tabs">
                <div class="etik-tab active" data-tab="list">Liste</div>
                <div class="etik-tab" data-tab="calendar">Calendrier</div>
                <div class="etik-tab" data-tab="closures">Fermetures</div>
                <div class="etik-tab" data-tab="reservations">Réservations</div>
            </div>

            <!-- Panneaux -->
            <div class="etik-panels">
                <div class="etik-panel active" data-panel="list">
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

        <!-- Modale -->
        <div class="etik-modal" id="etik-prestation-modal" aria-hidden="true">
            <div class="etik-modal-backdrop" data-modal-close></div>
            <div class="etik-modal-dialog" role="dialog" aria-modal="true">
                <button class="etik-modal-close" data-modal-close aria-label="Fermer">&times;</button>
                <div class="etik-modal-content">
                    <h3>Ajouter une prestation</h3>
                    <form id="etik-prestation-form">
                        <?php Prestation_Meta::render_form(); ?>
                        <div class="etik-form-actions">
                            <button type="submit" class="etik-btn">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_prestation_slots';

        $prestations = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Heure</th>
                    <th>Durée</th>
                    <th>Jours</th>
                    <th>Dates</th>
                    <th>État</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $prestations as $p ) : ?>
                <tr>
                    <td><?php echo esc_html( $p->id ); ?></td>
                    <td><?php echo esc_html( $p->type ); ?></td>
                    <td><?php echo esc_html( $p->start_time ); ?></td>
                    <td><?php echo esc_html( $p->duration ); ?> min</td>
                    <td><?php echo esc_html( $p->days ); ?></td>
                    <td><?php echo esc_html( $p->start_date ) . ' → ' . esc_html( $p->end_date ); ?></td>
                    <td><?php echo $p->is_closed ? 'Fermé' : 'Ouvert'; ?></td>
                    <td>
                        <a href="<?php echo admin_url( 'admin.php?page=wp-etik-prestation&edit=' . $p->id ); ?>">Modifier</a> |
                        <a href="<?php echo admin_url( 'admin.php?page=wp-etik-prestation&delete=' . $p->id ); ?>" onclick="return confirm('Confirmer la suppression ?');">Supprimer</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_calendar() {
        ?>
        <p>Calendrier des prestations (à venir)</p>
        <?php
    }

    private function render_closures() {
        ?>
        <p>Gestion des fermetures (à venir)</p>
        <?php
    }

    private function render_reservations() {
        ?>
        <p>Liste des réservations (à venir)</p>
        <?php
    }
}