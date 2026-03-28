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

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( $screen->id !== 'etik_event_page_' . self::MENU_SLUG && $screen->id !== 'settings_page_' . self::MENU_SLUG ) return;

        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );

        $js_init = "jQuery(document).ready(function($){ $('.color-picker').wpColorPicker(); $('.datepicker').datepicker({ dateFormat: 'yy-mm-dd' }); });";
        wp_add_inline_script( 'wp-color-picker', $js_init );
    }

    /**
     * 
     */
    public function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        // Détection du mode : 'add' pour afficher le formulaire, sinon liste par défaut
        $current_action = isset( $_GET['action'] ) && $_GET['action'] === 'add' ? 'add' : 'list';

        $current_action = isset( $_GET['action'] ) && $_GET['action'] === 'add' ? 'add' : 'list';
        $meta_instance = new Prestation_Meta();
        ?>
        <div class="wrap etik-admin">

            <?php
            // Affichage des messages de notification
            if ( isset( $_GET['message'] ) && $_GET['message'] === 'prestation_created' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Prestation enregistrée avec succès.', 'wp-etik-events' ) . '</p></div>';
            }
            if ( isset( $_GET['error'] ) && $_GET['error'] === 'missing_title' ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Erreur : Le titre est obligatoire.', 'wp-etik-events' ) . '</p></div>';
            }
            ?>

            <h1><?php esc_html_e( 'Prestations', 'wp-etik-events' ); ?></h1>
            <p><?php esc_html_e( 'Gérez vos prestations récurrentes ici.', 'wp-etik-events' ); ?></p>

            <!-- Bouton Ajouter -->
            <?php if ( $current_action === 'list' ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'action', 'add' ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Ajouter une prestation', 'wp-etik-events' ); ?>
                </a>
            <?php else : ?>
                <a href="<?php echo esc_url( remove_query_arg( 'action' ) ); ?>" class="button">
                    <span class="dashicons dashicons-arrow-left-alt" style="margin-top:3px; vertical-align:middle;"></span> 
                    <?php esc_html_e( 'Retour à la liste', 'wp-etik-events' ); ?>
                </a>
            <?php endif; ?>

            <hr style="margin: 20px 0; border-top: 1px solid #ccc;">

            <!-- Panneau Formulaire (Affiché si action=add) -->
            <?php if ( $current_action === 'add' ) : ?>
                <div class="etik-form-panel" style="background:#fff; padding:25px; border:1px solid #c3c4c7; box-shadow:0 1px 1px rgba(0,0,0,.04); margin-bottom:20px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Nouvelle prestation', 'wp-etik-events' ); ?></h2>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="wp_etik_create_prestation">
                        <?php wp_nonce_field( 'wp_etik_create_prestation_nonce', 'wp_etik_prestation_nonce' ); ?>
                        
                        <div style="margin-top:15px;">
                            <!-- Appel de la meta box avec NULL pour simuler un nouveau post -->
                            <?php $meta_instance->meta_box_html( null ); ?>
                        </div>

                        <div class="etik-form-actions" style="margin-top:25px; border-top:1px solid #eee; padding-top:20px;">
                            <button type="submit" class="button button-primary button-large">
                                <?php esc_html_e( 'Enregistrer la prestation', 'wp-etik-events' ); ?>
                            </button>
                            <a href="<?php echo esc_url( remove_query_arg( 'action' ) ); ?>" class="button button-secondary" style="margin-left:10px;">
                                <?php esc_html_e( 'Annuler', 'wp-etik-events' ); ?>
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Onglets de navigation (Toujours visibles) -->
            <div class="etik-tabs">
                <div class="etik-tab <?php echo $current_action === 'list' ? 'active' : ''; ?>" data-tab="list">Liste</div>
                <div class="etik-tab" data-tab="calendar">Calendrier</div>
                <div class="etik-tab" data-tab="closures">Fermetures</div>
                <div class="etik-tab" data-tab="reservations">Réservations</div>
            </div>

            <!-- Panneaux de contenu -->
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