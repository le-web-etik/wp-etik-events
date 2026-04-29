<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Chargement du Manager pour le déchiffrement
if ( ! class_exists( 'WP_Etik\\Etik_User_Manager' ) ) {
    require_once WP_ETIK_PLUGIN_DIR . 'src/Etik_User_Manager.php';
}
if ( ! class_exists( 'WP_Etik\\Encryption' ) ) {
    require_once WP_ETIK_PLUGIN_DIR . 'src/Encryption.php';
}

class Prestation_Reservation_List {

    public function init() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
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

    public function enqueue_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        // Chargement de CSS si nécessaire pour le tableau
        if ( $screen->id !== 'etik_event_page_wp-etik-reservations' ) return;
        wp_enqueue_style( 'wp-etik-admin', WP_ETIK_PLUGIN_URL . 'assets/css/admin.css' );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        global $wpdb;
        $table_res      = $wpdb->prefix . 'etik_reservations';
        $table_users    = $wpdb->prefix . 'etik_users';
        $table_posts    = $wpdb->prefix . 'posts';
        $table_slots    = $wpdb->prefix . 'etik_prestation_slots';
        $table_responses= $wpdb->prefix . 'etik_form_responses';

        // -------------------------------------------------------------------------
        // REQUÊTE SQL OPTIMISÉE
        // Récupère les réservations + Infos client chiffrées (via JOIN)
        // -------------------------------------------------------------------------
        $reservations = $wpdb->get_results( "
            SELECT 
                r.id, r.etik_user_id, r.created_at, r.booking_date, r.booking_time, r.status,
                p.post_title as prestation_title,
                s.start_time, s.duration,
                u.email_enc, u.first_name_enc, u.last_name_enc, u.phone_enc
            FROM {$table_res} r
            LEFT JOIN {$table_posts} p ON r.prestation_id = p.ID
            LEFT JOIN {$table_slots} s ON r.slot_id = s.id
            LEFT JOIN {$table_users} u ON r.etik_user_id = u.id
            ORDER BY r.booking_date DESC, r.created_at DESC
            LIMIT 100
        ", ARRAY_A );

        ?>
        <div class="wrap etik-admin">
            <h1><?php esc_html_e( 'Réservations de Prestations', 'wp-etik-events' ); ?></h1>
            <p><?php esc_html_e( 'Liste des réservations confirmées et en attente.', 'wp-etik-events' ); ?></p>

            <?php if ( empty( $reservations ) ) : ?>
                <div class="notice notice-info"><p><?php esc_html_e( 'Aucune réservation trouvée.', 'wp-etik-events' ); ?></p></div>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:100px;"><?php esc_html_e( 'Date & Heure', 'wp-etik-events' ); ?></th>
                        <th><?php esc_html_e( 'Prestation', 'wp-etik-events' ); ?></th>
                        <th><?php esc_html_e( 'Client (Déchiffré)', 'wp-etik-events' ); ?></th>
                        <th style="width:150px;"><?php esc_html_e( 'Contact', 'wp-etik-events' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Statut', 'wp-etik-events' ); ?></th>
                        <th><?php esc_html_e( 'Détails Formulaire', 'wp-etik-events' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $reservations as $res ) : 

                        // Déchiffrement des données client
                        $user_data = null;

                        if ( ! empty( $res['etik_user_id'] ) ) {
                            $user_data = \WP_Etik\Etik_User_Manager::get( (int) $res['etik_user_id'] );
                        }

                        // Extraction sécurisée des données (ou valeur par défaut '-')
                        $first_name = $user_data['first_name'] ?? '-';
                        $last_name  = $user_data['last_name'] ?? '-';
                        $email      = $user_data['email'] ?? '-';
                        $phone      = $user_data['phone'] ?? '-';
                        // ----------------------

                        // Récupération optionnelle des réponses du formulaire (ex: Note, Domaine)
                        $form_details = $this->get_form_details( $res['id'], 'reservation' );
                        
                        // Couleur du statut
                        $status_color = '#ccc';
                        if ( $res['status'] === 'confirmed' ) $status_color = '#2aa78a';
                        elseif ( $res['status'] === 'pending' ) $status_color = '#f0ad4e';
                        elseif ( $res['status'] === 'cancelled' ) $status_color = '#d63638';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $res['booking_date'] ) ) ); ?></strong><br>
                            <small><?php echo esc_html( $res['booking_time'] ); ?> (<?php echo esc_html( $res['duration'] ); ?>min)</small>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $res['prestation_title'] ); ?></strong>
                        </td>
                        <td>
                            <?php echo esc_html( trim( $first_name . ' ' . $last_name ) ); ?>
                        </td>
                        <td>
                            <small><?php echo esc_html( $email ); ?></small><br>
                            <small><?php echo esc_html( $phone ); ?></small>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo $status_color; ?>;">
                                <?php echo esc_html( ucfirst( $res['status'] ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( ! empty( $form_details ) ) : ?>
                                <details style="font-size:12px;cursor:pointer;">
                                    <summary style="color:#0073aa;"><?php esc_html_e( 'Voir réponses', 'wp-etik-events' ); ?></summary>
                                    <div style="margin-top:5px;padding:5px;background:#f9f9f9;border:1px solid #ddd;">
                                        <?php foreach ( $form_details as $label => $value ) : ?>
                                            <div style="margin-bottom:2px;">
                                                <strong><?php echo esc_html( $label ); ?>:</strong> 
                                                <?php echo esc_html( $value ); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php else : ?>
                                <span style="color:#999;font-style:italic;"><?php esc_html_e( 'Aucun détail supplémentaire', 'wp-etik-events' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Helper : Récupère et déchiffre les réponses du formulaire pour une réservation donnée.
     * Retourne un tableau simple [ 'Label Question' => 'Réponse' ].
     */
    private function get_form_details( int $reservation_id, string $type ) : array {
        global $wpdb;
        
        $blob = $wpdb->get_var( $wpdb->prepare(
            "SELECT form_snapshot FROM {$wpdb->prefix}etik_form_responses 
             WHERE submission_id = %d AND submission_type = %s 
             LIMIT 1",
            $reservation_id, $type
        ) );

        if ( empty( $blob ) ) {
            return [];
        }

        try {
            $json_string = \WP_Etik\Encryption::decrypt( $blob );
            $data = json_decode( $json_string, true );

            if ( ! isset( $data['questions'], $data['answers'] ) ) {
                return [];
            }

            $details = [];
            // On fusionne questions et réponses pour l'affichage
            foreach ( $data['questions'] as $key => $q_info ) {
                if ( isset( $data['answers'][ $key ] ) ) {
                    // On ignore les champs techniques ou vides si désiré
                    $label = $q_info['label'];
                    $val   = $data['answers'][ $key ];
                    
                    // Optionnel : Ignorer les champs de base déjà affichés dans la colonne Client
                    if ( in_array( strtolower($label), ['prénom', 'nom', 'email', 'téléphone', 'prenom', 'tel'] ) ) {
                        continue; 
                    }
                    
                    $details[ $label ] = $val;
                }
            }
            return $details;

        } catch ( \Exception $e ) {
            error_log( '[WP-Etik] Impossible de déchiffrer les réponses formulaire : ' . $e->getMessage() );
            return [ 'Erreur' => 'Données illisibles' ];
        }
    }
}