<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prestation_Reservation_List {

    public function init() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=etik_event',
            __( 'Réservations', 'wp-etik-events' ),
            __( 'Réservations', 'wp-etik-events' ),
            'manage_options',
            'wp-etik-reservations',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        // Enregistrer les settings si nécessaire
    }

    public function enqueue_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( $screen->id !== 'etik_event_page_wp-etik-reservations' ) return;
        // enqueue si besoin
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        ?>
        <div class="wrap etik-admin">
            <h1><?php esc_html_e( 'Réservations', 'wp-etik-events' ); ?></h1>
            <p><?php esc_html_e( 'Liste des réservations triée par date.', 'wp-etik-events' ); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Prestation</th>
                        <th>Créneau</th>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Téléphone</th>
                        <th>Email</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Récupérer les réservations
                    global $wpdb;
                    $table = $wpdb->prefix . 'etik_reservations';
                    $reservations = $wpdb->get_results( "
                        SELECT r.*, p.post_title as prestation_title, s.start_time, s.duration
                        FROM $table r
                        JOIN {$wpdb->prefix}posts p ON r.prestation_id = p.ID
                        JOIN {$wpdb->prefix}etik_prestation_slots s ON r.slot_id = s.id
                        ORDER BY r.created_at DESC
                    " );

                    foreach ( $reservations as $reservation ) {
                        $user = get_user_by( 'ID', $reservation->user_id );
                        $user_info = get_user_meta( $reservation->user_id, 'etik_user_info', true );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $reservation->created_at ); ?></td>
                            <td><?php echo esc_html( $reservation->prestation_title ); ?></td>
                            <td><?php echo esc_html( $reservation->start_time ); ?> - <?php echo esc_html( $reservation->duration ); ?> min</td>
                            <td><?php echo esc_html( $user->first_name ?? '' ); ?></td>
                            <td><?php echo esc_html( $user->last_name ?? '' ); ?></td>
                            <td><?php echo esc_html( $user_info['phone'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $user->user_email ?? '' ); ?></td>
                            <td><?php echo esc_html( $user_info['note'] ?? '' ); ?></td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}