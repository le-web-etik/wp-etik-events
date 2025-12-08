<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Registrations_Admin {

    // méthode init() ou constructeur de Registrations_Admin
    public function init() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_wp_etik_export_registrations', [ $this, 'export_registrations' ] ); // si tu veux garder export coté handler, sinon tu peux supprimer
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handler pour charger les inscrits (admin only)
        add_action( 'wp_ajax_wp_etik_get_registrants', [ $this, 'ajax_get_registrants' ] );
    }


    public function add_menu() {
        // place the submenu under the post type menu slug 'edit.php?post_type=etik_event'
        add_submenu_page(
            'edit.php?post_type=etik_event',
            __( 'Inscriptions', 'wp-etik-events' ),
            __( 'Inscriptions', 'wp-etik-events' ),
            'manage_options',
            'wp-etik-registrations',
            [ $this, 'page_events_list' ]
        );
    }

    public function enqueue_assets( $hook ) {
        // limiter au bon hook/page
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'wp-etik-registrations' ) {
            wp_enqueue_style( 'wp-etik-admin-css', WP_ETIK_PLUGIN_URL . 'assets/css/admin.css', [], filemtime( WP_ETIK_PLUGIN_DIR . 'assets/css/admin.css' ) );
            // enqueue our admin script where we'll implement ajax loader
            wp_enqueue_script( 'wp-etik-registrations-js', WP_ETIK_PLUGIN_URL . 'assets/js/admin-registrations.js', [ 'jquery' ], filemtime( WP_ETIK_PLUGIN_DIR . 'assets/js/admin-registrations.js' ), true );

            // nonce base (we'll generate per event in JS request using this base)
            $base_nonce = wp_create_nonce( 'wp_etik_registrants' ); // used only to mark page allowed; per-event nonce is generated dynamically in links too
            wp_localize_script( 'wp-etik-registrations-js', 'WP_ETIK_REG', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'base_nonce' => $base_nonce,
                'strings' => [
                    'loading' => __( 'Chargement...', 'wp-etik-events' ),
                    'no_registrants' => __( 'Aucun inscrit', 'wp-etik-events' ),
                ],
            ] );
        }
    }


    /**
     * Page principale : liste des événements (par défaut à venir)
     */
    public function page_events_list() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        // filtre: upcoming (default), past, all
        $view = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : 'upcoming';

        // posts_per_page & pagination
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page = 20;

        // build meta_query depending on how etik_start_date is stored (assume DATETIME Y-m-d H:i:s)
        $now = current_time( 'mysql' );
        $meta_query = [];
        if ( $view === 'upcoming' ) {
            $meta_query[] = [
                'key'     => 'etik_start_date',
                'value'   => $now,
                'compare' => '>=',
                'type'    => 'DATETIME',
            ];
        } elseif ( $view === 'past' ) {
            $meta_query[] = [
                'key'     => 'etik_start_date',
                'value'   => $now,
                'compare' => '<',
                'type'    => 'DATETIME',
            ];
        }

        $qargs = [
            'post_type'      => 'etik_event',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'meta_key'       => 'etik_start_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ];
        if ( ! empty( $meta_query ) ) {
            $qargs['meta_query'] = $meta_query;
        }

        $events = new \WP_Query( $qargs );

        // render
        ?>
        <div class="wrap">
            <h1><?php _e( 'Gestion des inscriptions', 'wp-etik-events' ); ?></h1>

            <p class="wp-etik-actions">
                <a class="button <?php echo $view === 'upcoming' ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'wp-etik-registrations', 'view' => 'upcoming' ], admin_url( 'edit.php?post_type=etik_event' ) ) ); ?>"><?php _e( 'Événements à venir', 'wp-etik-events' ); ?></a>
                <a class="button <?php echo $view === 'past' ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'wp-etik-registrations', 'view' => 'past' ], admin_url( 'edit.php?post_type=etik_event' ) ) ); ?>"><?php _e( 'Événements passés', 'wp-etik-events' ); ?></a>
                <a class="button <?php echo $view === 'all' ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'wp-etik-registrations', 'view' => 'all' ], admin_url( 'edit.php?post_type=etik_event' ) ) ); ?>"><?php _e( 'Tous', 'wp-etik-events' ); ?></a>
            </p>

            <form method="get" style="margin-bottom:12px;">
                <input type="hidden" name="page" value="wp-etik-registrations" />
                <label style="margin-right:8px;"><?php _e( 'Rechercher', 'wp-etik-events' ); ?></label>
                <input type="search" name="s" value="<?php echo isset($_GET['s']) ? esc_attr($_GET['s']) : ''; ?>" />
                <button class="button"><?php _e( 'Filtrer', 'wp-etik-events' ); ?></button>
            </form>

            <!-- table header -->
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e( 'ID', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'Titre', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'Début', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'Inscrits', 'wp-etik-events' ); ?></th> <!-- nb / max -->
                        <th><?php _e( 'Actions', 'wp-etik-events' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if ( $events->have_posts() ) :
                    while ( $events->have_posts() ) : $events->the_post();
                        $post_id = get_the_ID();
                        $start = get_post_meta( $post_id, 'etik_start_date', true );
                        $max = get_post_meta( $post_id, 'etik_max_place', true ); // peut être vide

                        global $wpdb;
                        $table = $wpdb->prefix . 'etik_inscriptions';
                        // compter tous les inscrits (tous statuts) et les confirmés si besoin
                        $count_all = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $post_id ) );

                        ?>
                        <tr id="event-row-<?php echo esc_attr( $post_id ); ?>">
                            <td><?php echo esc_html( $post_id ); ?></td>
                            <td><strong><?php the_title(); ?></strong><br><small><?php echo esc_html( get_post_meta( $post_id, '_etik_summary', true ) ); ?></small></td>
                            <td><?php echo esc_html( $start ); ?></td>

                            <?php
                            $nonce_per_event = wp_create_nonce( 'wp_etik_registrants_' . $post_id ); 
                            ?>
                            <td class="etik-inscrits-col" data-event-id="<?php echo esc_attr( $post_id ); ?>" data-nonce="<?php echo esc_attr( $nonce_per_event ); ?>">
                                <?php echo esc_html( $count_all ); ?> / <?php echo esc_html( $max ?: '-' ); ?>
                            </td>

                            <td>
                                <button class="button wp-etik-toggle-registrants" data-event-id="<?php echo esc_attr( $post_id ); ?>">
                                    <?php _e( 'Voir inscrits', 'wp-etik-events' ); ?>
                                </button>
                            </td>
                        </tr>

                        <!-- dropdown row: hidden container where JS injecte le table des inscrits -->
                        <tr class="etik-registrants-row" id="registrants-row-<?php echo esc_attr( $post_id ); ?>" style="display:none;">
                            <td colspan="5" class="etik-registrants-cell">
                                <div class="etik-registrants-container" data-loaded="0" data-event-id="<?php echo esc_attr( $post_id ); ?>">
                                    <!-- loader + content will be injected by JS -->
                                    <div class="etik-registrants-loader" style="display:none;">
                                        <div class="etik-spinner"></div>
                                        <?php _e( 'Chargement...', 'wp-etik-events' ); ?>
                                    </div>
                                    <div class="etik-registrants-content" style="display:none;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php
                    endwhile;
                    wp_reset_postdata();
                else :
                    ?>
                    <tr><td colspan="5"><?php _e( 'Aucun événement trouvé.', 'wp-etik-events' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>


            <?php
            // pagination simple
            $total = $events->found_posts;
            $max_pages = $events->max_num_pages;
            if ( $max_pages > 1 ) {
                $base = add_query_arg( [ 'page' => 'wp-etik-registrations', 'view' => $view, 's' => ( $_GET['s'] ?? '' ) ], admin_url( 'edit.php?post_type=etik_event' ) );
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links( [
                    'base'      => $base . '%_%',
                    'format'    => '&paged=%#%',
                    'current'   => $paged,
                    'total'     => $max_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                echo '</div></div>';
            }
            ?>

        </div>
        <?php

        // route to registrants view if requested
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'view' && isset( $_GET['event_id'] ) ) {
            $this->page_event_registrants( intval( $_GET['event_id'] ) );
        }

    } // end page_events_list


    /**
     * Détail des inscrits pour un événement
     */
    public function page_event_registrants( $event_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'etik_inscriptions';

        // pagination
        $paged = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page = 50;
        $offset = ( $paged - 1 ) * $per_page;

        // optional status filter
        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        $where = $wpdb->prepare( "WHERE event_id = %d", $event_id );
        if ( $status_filter ) {
            $where .= $wpdb->prepare( " AND status = %s", $status_filter );
        }

        // total count
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

        // fetch rows (ordered by registered_at desc)
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY registered_at DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );

        // event title
        $event_title = get_the_title( $event_id );

        ?>
        <div style="margin-top:24px;">
            <h2><?php echo esc_html( $event_title ); ?> — <?php _e( 'Inscrits', 'wp-etik-events' ); ?></h2>

            <p>
                <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'wp-etik-registrations', 'action' => 'view', 'event_id' => $event_id ], admin_url( 'edit.php?post_type=etik_event' ) ) ); ?>"><?php _e( 'Retour événements', 'wp-etik-events' ); ?></a>

                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_etik_export_registrations&event_id=' . $event_id ), 'wp_etik_export_' . $event_id ) ); ?>"><?php _e( 'Exporter CSV', 'wp-etik-events' ); ?></a>
            </p>

            <form method="get" style="margin-bottom:12px;">
                <input type="hidden" name="page" value="wp-etik-registrations" />
                <input type="hidden" name="action" value="view" />
                <input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
                <label><?php _e( 'Filtrer par statut', 'wp-etik-events' ); ?></label>
                <select name="status">
                    <option value=""><?php _e( 'Tous', 'wp-etik-events' ); ?></option>
                    <option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php _e( 'Pending', 'wp-etik-events' ); ?></option>
                    <option value="confirmed" <?php selected( $status_filter, 'confirmed' ); ?>><?php _e( 'Confirmed', 'wp-etik-events' ); ?></option>
                    <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>><?php _e( 'Cancelled', 'wp-etik-events' ); ?></option>
                </select>
                <button class="button"><?php _e( 'Filtrer', 'wp-etik-events' ); ?></button>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e( 'ID', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'Nom', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'E‑mail', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'Téléphone', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'Status', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'Inscrit le', 'wp-etik-events' ); ?></th>
                        <th><?php _e( 'Actions', 'wp-etik-events' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $rows ) ) : ?>
                        <?php foreach ( $rows as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r['id'] ); ?></td>
                                <td><?php echo esc_html( $r['first_name'] . ' ' . $r['last_name'] ); ?></td>
                                <td><?php echo esc_html( $r['email'] ); ?></td>
                                <td><?php echo esc_html( $r['phone'] ); ?></td>
                                <td><?php echo esc_html( $r['status'] ); ?></td>
                                <td><?php echo esc_html( $r['registered_at'] ); ?></td>
                                <td>
                                    <?php
                                    // actions: confirm, cancel, view user profile
                                    $confirm_url = wp_nonce_url( add_query_arg( [ 'page' => 'wp-etik-registrations', 'action' => 'confirm', 'id' => $r['id'], 'event_id' => $event_id ], admin_url( 'edit.php?post_type=etik_event' ) ), 'wp_etik_confirm_' . $r['id'] );
                                    $cancel_url  = wp_nonce_url( add_query_arg( [ 'page' => 'wp-etik-registrations', 'action' => 'cancel', 'id' => $r['id'], 'event_id' => $event_id ], admin_url( 'edit.php?post_type=etik_event' ) ), 'wp_etik_cancel_' . $r['id'] );
                                    $user_profile = $r['user_id'] ? get_edit_user_link( $r['user_id'] ) : '';
                                    ?>
                                    <?php if ( $r['status'] !== 'confirmed' ) : ?>
                                        <a class="button" href="<?php echo esc_url( $confirm_url ); ?>"><?php _e( 'Confirmer', 'wp-etik-events' ); ?></a>
                                    <?php endif; ?>
                                    <?php if ( $r['status'] !== 'cancelled' ) : ?>
                                        <a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php _e( 'Annuler', 'wp-etik-events' ); ?></a>
                                    <?php endif; ?>
                                    <?php if ( $user_profile ) : ?>
                                        <a class="button" target="_blank" href="<?php echo esc_url( $user_profile ); ?>"><?php _e( 'Profil', 'wp-etik-events' ); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7"><?php _e( 'Aucun inscrit', 'wp-etik-events' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            // pagination links for registrants
            $total_pages = (int) ceil( $total / $per_page );
            if ( $total_pages > 1 ) {
                $base = add_query_arg( [ 'page' => 'wp-etik-registrations', 'action' => 'view', 'event_id' => $event_id ], admin_url( 'edit.php?post_type=etik_event' ) );
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links( [
                    'base'      => $base . '%_%',
                    'format'    => '&paged=%#%',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                echo '</div></div>';
            }
            ?>

        </div>
        <?php

    } // end page_event_registrants


    /**
     * Export des inscriptions CSV (POST handler via admin-post)
     */
    public function export_registrations() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        // note: event_id must be provided and nonce checked
        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_die( __( 'Event ID missing', 'wp-etik-events' ) );
        }
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wp_etik_export_' . $event_id ) ) {
            wp_die( __( 'Nonce invalide', 'wp-etik-events' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'etik_inscriptions';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY registered_at DESC", $event_id ), ARRAY_A );

        if ( empty( $rows ) ) {
            wp_die( __( 'Aucun inscrit pour cet événement', 'wp-etik-events' ) );
        }

        // headers CSV
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=inscriptions_event_' . $event_id . '.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, [ 'id','first_name','last_name','email','phone','status','registered_at' ] );
        foreach ( $rows as $r ) {
            fputcsv( $output, [ $r['id'], $r['first_name'], $r['last_name'], $r['email'], $r['phone'], $r['status'], $r['registered_at'] ] );
        }
        fclose( $output );
        exit;
    }

    public function ajax_get_registrants() {
        // capability + nonce
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Accès refusé', 'wp-etik-events' ), 403 );
        }

        $event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $event_id || ! wp_verify_nonce( $nonce, 'wp_etik_registrants_' . $event_id ) ) {
            wp_send_json_error( __( 'Requête invalide', 'wp-etik-events' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'etik_inscriptions';

        // fetch limited number (eviter sur-chargement) ; pagination possible à ajouter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, first_name, last_name, email, phone, status, registered_at FROM {$table} WHERE event_id = %d ORDER BY registered_at DESC LIMIT %d",
                $event_id,
                500 // plafond ; ajuste si voulu
            ),
            ARRAY_A
        );

        if ( $rows === null ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( '[WP_ETIK] DB error: ' . $wpdb->last_error );
            wp_send_json_error( __( 'Erreur serveur', 'wp-etik-events' ), 500 );
        }

        // retourner résultat
        wp_send_json_success( [
            'event_id' => $event_id,
            'count'    => count( $rows ),
            'rows'     => $rows,
        ] );
    }


} // end class

// bootstrap (call from plugin admin loader)
//$registrations_admin = new Registrations_Admin();
//$registrations_admin->init();
