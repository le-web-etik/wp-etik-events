<?php
namespace WP_Etik;

defined( 'ABSPATH' ) || exit;

/**
 * Prestation_Booking
 *
 * Gestion front-end des réservations de prestations (nouvelle architecture).
 *
 * - PII stockées uniquement dans etik_users (chiffrées).
 * - Réponses formulaire dans etik_form_responses (JSON chiffré, 1 ligne par réservation).
 * - INSERT transactionnel (réservation + snapshot formulaire sont atomiques).
 * - Stripe : metadata[type]=reservation pour dispatch correct dans le webhook.
 * - Emails : données déchiffrées via Etik_User_Manager::get($etik_user_id).
 * - Retour paiement : même page (return_url passé par le module Divi via JS).
 */
class Prestation_Booking {

    private const DB_VERSION_OPT = 'etik_pres_booking_db_v';
    private const DB_VERSION     = 5;

    public function init() : void {
        add_action( 'init', [ $this, 'upgrade_db' ] );

        foreach ( [
            'etik_get_month_availability',
            'etik_get_day_slots',
            'etik_get_prestation_form',
            'etik_book_prestation',
        ] as $action ) {
            add_action( "wp_ajax_{$action}",        [ $this, "ajax_{$action}" ] );
            add_action( "wp_ajax_nopriv_{$action}", [ $this, "ajax_{$action}" ] );
        }
    }

    // ─── DB UPGRADE ───────────────────────────────────────────────────────────

    public function upgrade_db() : void {
        if ( (int) get_option( self::DB_VERSION_OPT, 0 ) >= self::DB_VERSION ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'etik_reservations';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            update_option( self::DB_VERSION_OPT, self::DB_VERSION );
            return;
        }

        $cols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ), 'Field' );

        $alters = [
            'booking_date'       => "ADD COLUMN booking_date DATE NOT NULL DEFAULT '2000-01-01' AFTER slot_id",
            'booking_time'       => "ADD COLUMN booking_time VARCHAR(10) NOT NULL DEFAULT '' AFTER booking_date",
            'etik_user_id'       => "ADD COLUMN etik_user_id BIGINT UNSIGNED NULL AFTER booking_time",
            'token'              => "ADD COLUMN token VARCHAR(64) NULL",
            'payment_session_id' => "ADD COLUMN payment_session_id VARCHAR(200) NULL",
        ];

        foreach ( $alters as $col => $def ) {
            if ( ! in_array( $col, $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$table} {$def}" );
            }
        }

        // Index etik_user_id
        $has_idx = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'etik_user_id'" );
        if ( empty( $has_idx ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY etik_user_id (etik_user_id)" );
        }

        update_option( self::DB_VERSION_OPT, self::DB_VERSION );
    }

    // ─── AJAX : disponibilité mensuelle ───────────────────────────────────────

    public function ajax_etik_get_month_availability() : void {
        check_ajax_referer( 'etik_booking_nonce', 'nonce' );

        $prestation_id = intval( $_POST['prestation_id'] ?? 0 );
        $year          = intval( $_POST['year']  ?? date( 'Y' ) );
        $month         = intval( $_POST['month'] ?? date( 'n' ) );

        if ( $prestation_id <= 0 ) wp_send_json_error( [ 'message' => 'Prestation manquante.' ] );

        wp_send_json_success( self::get_month_availability( $prestation_id, $year, $month ) );
    }

    // ─── AJAX : créneaux d'une journée ────────────────────────────────────────

    public function ajax_etik_get_day_slots() : void {
        check_ajax_referer( 'etik_booking_nonce', 'nonce' );

        $prestation_id = intval( $_POST['prestation_id'] ?? 0 );
        $date          = sanitize_text_field( $_POST['date'] ?? '' );

        if ( $prestation_id <= 0 || ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_send_json_error( [ 'message' => 'Paramètres invalides.' ] );
        }

        wp_send_json_success( [ 'slots' => self::get_slots_for_date( $prestation_id, $date ), 'date' => $date ] );
    }

    // ─── AJAX : formulaire lié à la prestation ────────────────────────────────

    public function ajax_etik_get_prestation_form() : void {
        check_ajax_referer( 'etik_booking_nonce', 'nonce' );

        $prestation_id = intval( $_POST['prestation_id'] ?? 0 );
        if ( $prestation_id <= 0 ) wp_send_json_error( [ 'message' => 'Prestation manquante.' ] );

        $form_id          = self::resolve_form_id( $prestation_id );
        $payment_required = get_post_meta( $prestation_id, 'etik_prestation_payment_required', true ) === '1';
        $price            = (float) get_post_meta( $prestation_id, 'etik_prestation_price', true );
        $html             = '';

        if ( $form_id > 0 && class_exists( 'WP_Etik\\Etik_Modal_Manager' ) ) {
            $html = Etik_Modal_Manager::render_form_fields( $form_id );
        }

        wp_send_json_success( [
            'html'             => $html,
            'form_id'          => $form_id,
            'payment_required' => $payment_required,
            'price'            => $price,
            'price_formatted'  => number_format( $price, 2, ',', ' ' ) . ' €',
        ] );
    }

    // ─── AJAX : créer la réservation ──────────────────────────────────────────

        public function ajax_etik_book_prestation() : void {
        check_ajax_referer( 'etik_booking_nonce', 'nonce' );
 
        // Charger les helpers (même fichier que ajax-handler-checkout.php)
        if ( ! function_exists( 'WP_Etik\\lwe_resolve_field_map' ) ) {
            require_once WP_ETIK_PLUGIN_DIR . 'includes/lwe-field-helpers.php';
        }
        self::_load_deps();
 
        global $wpdb;
        $t_res  = $wpdb->prefix . 'etik_reservations';
        $t_resp = $wpdb->prefix . 'etik_form_responses';
 
        // ── 1. Contexte ──────────────────────────────────────────────────────
        $prestation_id = intval( $_POST['prestation_id'] ?? 0 );
        $slot_id       = intval( $_POST['slot_id']       ?? 0 );
        $booking_date  = sanitize_text_field( $_POST['booking_date'] ?? '' );
        $booking_time  = sanitize_text_field( $_POST['booking_time'] ?? '' );
        $return_url    = esc_url_raw( $_POST['return_url'] ?? '' );
 
        if ( $prestation_id <= 0 ) {
            wp_send_json_error( [ 'code' => 'missing_prestation', 'message' => 'Prestation manquante.' ], 400 );
        }
        if ( ! $booking_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $booking_date ) ) {
            wp_send_json_error( [ 'code' => 'missing_date', 'message' => 'Date invalide.' ], 400 );
        }
        if ( ! $booking_time ) {
            wp_send_json_error( [ 'code' => 'missing_time', 'message' => 'Heure manquante.' ], 400 );
        }
 
        // ── 2. Formulaire — résolution dynamique des champs ──────────────────
        // Même logique que ajax-handler-checkout.php :
        // Le JS envoie form_id comme hidden input.
        // On résout les field_key réels via le type/label du champ (pas les noms hardcodés).
        $form_id = intval( $_POST['form_id'] ?? 0 );
 
        // Fallback : formulaire par défaut lié aux prestations
        if ( $form_id <= 0 ) {
            $form_id = self::resolve_form_id( $prestation_id );
        }
 
        // Validation des champs requis déclarés dans le form builder
        $validation_error = lwe_validate_required_fields( $form_id );
        if ( $validation_error ) {
            wp_send_json_error( $validation_error, 400 );
        }
 
        // Mapping field_key → colonne standard (email, first_name, last_name, phone)
        // lwe_resolve_field_map() cherche par type (email, tel) puis par label (prénom, nom)
        $field_map  = lwe_resolve_field_map( $form_id );
 
        $email      = lwe_get_post_field( $field_map['email'],      'email' );
        $first_name = lwe_get_post_field( $field_map['first_name'], 'text' );
        $last_name  = lwe_get_post_field( $field_map['last_name'],  'text' );
        $phone      = lwe_get_post_field( $field_map['phone'],      'tel' );
 
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'code' => 'invalid_email', 'message' => 'Adresse e-mail invalide.' ], 400 );
        }
        if ( empty( $first_name ) ) {
            wp_send_json_error( [ 'code' => 'missing_name', 'message' => 'Le prénom est obligatoire.' ], 400 );
        }
 
        // ── 3. Vérification disponibilité (max_place) ────────────────────────
        $max_place = max( 1, (int) get_post_meta( $prestation_id, 'etik_prestation_max_place', true ) );
        $booked    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_res}
             WHERE prestation_id = %d AND booking_date = %s AND booking_time = %s
               AND status NOT IN ('cancelled')",
            $prestation_id, $booking_date, $booking_time
        ) );
 
        if ( $booked >= $max_place ) {
            wp_send_json_error( [ 'code' => 'slot_full', 'message' => 'Ce créneau est complet. Veuillez en choisir un autre.' ], 409 );
        }
 
        // ── 4. Contact chiffré (etik_users) — même API qu'ajax-handler-checkout ─
        $etik_user_id = Etik_User_Manager::find_or_create( [
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'phone'      => $phone,
        ] );
 
        if ( ! $etik_user_id ) {
            wp_send_json_error( [ 'code' => 'contact_error', 'message' => 'Erreur lors de l\'enregistrement du contact.' ], 500 );
        }
 
        $token = bin2hex( random_bytes( 24 ) );
 
        // ── 5. Transaction : réservation + snapshot formulaire ───────────────
        $wpdb->query( 'START TRANSACTION' );
 
        $ok = $wpdb->insert( $t_res, [
            'prestation_id' => $prestation_id,
            'slot_id'       => $slot_id,
            'booking_date'  => $booking_date,
            'booking_time'  => $booking_time,
            'etik_user_id'  => $etik_user_id,
            'token'         => $token,
            'status'        => 'pending',
            'created_at'    => current_time( 'mysql' ),
        ], [ '%d','%d','%s','%s','%d','%s','%s','%s' ] );
 
        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( [ 'code' => 'db_error', 'message' => 'Erreur base de données (réservation).' ], 500 );
        }
 
        $reservation_id = (int) $wpdb->insert_id;
 
        // Snapshot formulaire chiffré (même pattern que ajax-handler-checkout.php §6)
        if ( $form_id > 0 ) {
            $snapshot = $this->build_snapshot( $form_id, $field_map );
            if ( $snapshot ) {
                $ok2 = $wpdb->insert( $t_resp, [
                    'submission_id'   => $reservation_id,
                    'submission_type' => 'reservation',
                    'form_id'         => $form_id,
                    'form_snapshot'   => $snapshot,
                    'created_at'      => current_time( 'mysql' ),
                ], [ '%d','%s','%d','%s','%s' ] );
 
                if ( ! $ok2 ) {
                    $wpdb->query( 'ROLLBACK' );
                    wp_send_json_error( [ 'code' => 'db_error', 'message' => 'Erreur base de données (formulaire).' ], 500 );
                }
            }
        }
 
        $wpdb->query( 'COMMIT' );
 
        // ── 6. Paiement Stripe si requis ─────────────────────────────────────
        $pay_required = get_post_meta( $prestation_id, 'etik_prestation_payment_required', true ) === '1';
        $price        = (float) get_post_meta( $prestation_id, 'etik_prestation_price', true );
 
        if ( $pay_required && $price > 0 ) {
            $checkout = $this->stripe_session( $reservation_id, $prestation_id, $etik_user_id, $price, $return_url );
 
            if ( is_wp_error( $checkout ) ) {
                $wpdb->delete( $t_res, [ 'id' => $reservation_id ], [ '%d' ] );
                wp_send_json_error( [ 'message' => 'Erreur paiement : ' . $checkout->get_error_message() ] );
            }
 
            $wpdb->update( $t_res,
                [ 'payment_session_id' => $checkout['id'] ?? '' ],
                [ 'id' => $reservation_id ],
                [ '%s' ], [ '%d' ]
            );
 
            wp_send_json_success( [
                'status'       => 'payment_required',
                'checkout_url' => $checkout['url'] ?? '',
            ] );
        }
 
        // ── 7. Confirmation directe ───────────────────────────────────────────
        $wpdb->update( $t_res, [ 'status' => 'confirmed' ], [ 'id' => $reservation_id ], [ '%s' ], [ '%d' ] );
        self::send_client_email( $reservation_id );
        self::send_admin_email( $reservation_id );
 
        wp_send_json_success( [
            'status'         => 'confirmed',
            'reservation_id' => $reservation_id,
            'message'        => __( 'Votre réservation est confirmée ! Un e-mail de confirmation vous a été envoyé.', 'wp-etik-events' ),
        ] );
    }

    // ─── SNAPSHOT FORMULAIRE ─────────────────────────────────────────────────
 
    /**
     * Construit le snapshot JSON chiffré des réponses.
     * Reçoit $field_map pour exclure les champs de base déjà stockés dans etik_users.
     * Les champs de base (email, prénom, nom, tel) sont présents dans le snapshot
     * pour l'historique, mais ne sont PAS re-stockés en clair ailleurs.
     */
    private function build_snapshot( int $form_id, array $field_map = [] ) : ?string {
        if ( ! class_exists( 'WP_Etik\\Form_Manager' ) ) {
            require_once WP_ETIK_PLUGIN_DIR . 'src/Form_Manager.php';
        }
 
        $fields    = Form_Manager::get_fields( $form_id );
        $answers   = [];
        $questions = [];
 
        foreach ( $fields as $f ) {
            $k = $f['field_key'];
            $t = $f['type'];
            if ( $t === 'html' ) continue;
 
            $v = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : null;
            if ( is_array( $v ) ) $v = implode( ', ', array_map( 'sanitize_text_field', $v ) );
            elseif ( $v !== null ) $v = sanitize_text_field( $v );
 
            $questions[ $k ] = [ 'label' => $f['label'], 'type' => $t ];
            if ( $v !== null && $v !== '' ) $answers[ $k ] = $v;
        }
 
        if ( empty( $answers ) ) return null;
 
        $json = wp_json_encode( [
            'v'         => 1,
            'form_id'   => $form_id,
            'questions' => $questions,
            'answers'   => $answers,
            'ts'        => time(),
        ] );
 
        if ( ! class_exists( 'WP_Etik\\Encryption' ) ) {
            $p = WP_ETIK_PLUGIN_DIR . 'src/Encryption.php';
            if ( file_exists( $p ) ) require_once $p;
        }
 
        if ( class_exists( 'WP_Etik\\Encryption' ) ) {
            try { return Encryption::encrypt( $json )['ciphertext']; }
            catch ( \Exception $e ) {
                Utils::log( '[Prestation_Booking] encrypt: ' . $e->getMessage() );
                // Blocage par sécurité (comme ajax-handler-checkout.php §6)
                wp_send_json_error( [ 'code' => 'encryption_error', 'message' => 'Erreur de sécurité critique.' ], 500 );
            }
        }
 
        return $json; // fallback non chiffré si Encryption indisponible
    }

    // ─── DISPONIBILITÉ MENSUELLE ──────────────────────────────────────────────

    public static function get_month_availability( int $prestation_id, int $year, int $month ) : array {
        global $wpdb;

        $days_count = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
        $today      = date( 'Y-m-d' );
        $max_place  = max( 1, (int) get_post_meta( $prestation_id, 'etik_prestation_max_place', true ) );

        $slots = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}etik_prestation_slots
             WHERE prestation_id = %d AND is_closed = 0 AND type = 'recurrent'",
            $prestation_id
        ) ) ?: [];

        $from     = sprintf( '%04d-%02d-01', $year, $month );
        $to       = sprintf( '%04d-%02d-%02d', $year, $month, $days_count );
        $closures = $wpdb->get_col( $wpdb->prepare(
            "SELECT closure_date FROM {$wpdb->prefix}etik_prestation_closures
             WHERE prestation_id = %d AND closure_date BETWEEN %s AND %s",
            $prestation_id, $from, $to
        ) ) ?: [];

        $result = [];
        for ( $d = 1; $d <= $days_count; $d++ ) {
            $date = sprintf( '%04d-%02d-%02d', $year, $month, $d );
            $dow  = (int) date( 'N', strtotime( $date ) );

            if ( $date < $today )                         { $result[ $date ] = 'past';   continue; }
            if ( in_array( $date, $closures, true ) )     { $result[ $date ] = 'closed'; continue; }

            $day_slots = self::_slots_for_dow( $slots, $dow );
            if ( empty( $day_slots ) )                    { $result[ $date ] = 'none';   continue; }

            $has_free = false;
            foreach ( $day_slots as $s ) {
                $b = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}etik_reservations
                     WHERE prestation_id=%d AND booking_date=%s AND booking_time=%s
                       AND status NOT IN ('cancelled')",
                    $prestation_id, $date, $s->start_time
                ) );
                if ( $b < $max_place ) { $has_free = true; break; }
            }

            $result[ $date ] = $has_free ? 'available' : 'full';
        }

        return $result;
    }

    // ─── CRÉNEAUX D'UNE JOURNÉE ───────────────────────────────────────────────

    public static function get_slots_for_date( int $prestation_id, string $date ) : array {
        global $wpdb;
        $dow       = (int) date( 'N', strtotime( $date ) );
        $max_place = max( 1, (int) get_post_meta( $prestation_id, 'etik_prestation_max_place', true ) );

        $closed = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}etik_prestation_closures
             WHERE prestation_id=%d AND closure_date=%s",
            $prestation_id, $date
        ) );
        if ( $closed ) return [];

        $slots = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}etik_prestation_slots
             WHERE prestation_id=%d AND is_closed=0 AND type='recurrent' ORDER BY start_time ASC",
            $prestation_id
        ) ) ?: [];

        $result = [];
        foreach ( self::_slots_for_dow( $slots, $dow ) as $s ) {
            $b = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}etik_reservations
                 WHERE prestation_id=%d AND booking_date=%s AND booking_time=%s
                   AND status NOT IN ('cancelled')",
                $prestation_id, $date, $s->start_time
            ) );
            $result[] = [
                'slot_id'   => (int) $s->id,
                'time'      => $s->start_time,
                'duration'  => (int) $s->duration,
                'available' => $b < $max_place,
                'booked'    => $b,
                'max'       => $max_place,
            ];
        }
        return $result;
    }

    // ─── RÉSOLUTION FORMULAIRE ────────────────────────────────────────────────

    public static function resolve_form_id( int $prestation_id ) : int {
        $meta = (int) get_post_meta( $prestation_id, 'etik_prestation_form_id', true );
        if ( $meta > 0 ) return $meta;

        global $wpdb;
        foreach ( [
            "SELECT id FROM {$wpdb->prefix}etik_forms WHERE attach_type='prestation' ORDER BY id ASC LIMIT 1",
            "SELECT id FROM {$wpdb->prefix}etik_forms WHERE is_default=1 AND attach_type='all' LIMIT 1",
            "SELECT id FROM {$wpdb->prefix}etik_forms ORDER BY id ASC LIMIT 1",
        ] as $sql ) {
            $id = (int) $wpdb->get_var( $sql );
            if ( $id ) return $id;
        }
        return 0;
    }

    // ─── EMAILS ───────────────────────────────────────────────────────────────

    public static function send_client_email( int $reservation_id ) : void {
        $data = self::_email_data( $reservation_id );
        if ( ! $data ) return;

        [ $res, $contact, $prestation, $date_fmt, $site_name, $admin_mail ] = $data;

        $html = self::_email_html(
            '✅ ' . __( 'Réservation confirmée', 'wp-etik-events' ),
            '#2aa78a',
            sprintf( __( 'Bonjour %s,', 'wp-etik-events' ), esc_html( $contact['first_name'] ) ),
            sprintf( __( 'Votre réservation pour "%s" a bien été enregistrée.', 'wp-etik-events' ), esc_html( $prestation ) ),
            $date_fmt, $res['booking_time'], $site_name
        );

        wp_mail(
            $contact['email'],
            sprintf( __( 'Confirmation de votre réservation — %s', 'wp-etik-events' ), $prestation ),
            $html,
            [ 'From: ' . $site_name . ' <' . $admin_mail . '>', 'Content-Type: text/html; charset=UTF-8' ]
        );
    }

    public static function send_admin_email( int $reservation_id ) : void {
        $data = self::_email_data( $reservation_id );
        if ( ! $data ) return;

        [ $res, $contact, $prestation, $date_fmt, $site_name, $admin_mail ] = $data;

        $view_url = add_query_arg( [ 'page' => 'wp-etik-reservations' ], admin_url( 'edit.php?post_type=etik_event' ) );
        $body     = sprintf( __( 'Nouvelle réservation pour "%s".', 'wp-etik-events' ), esc_html( $prestation ) )
            . '<br><strong>' . __( 'Date :', 'wp-etik-events' ) . '</strong> ' . esc_html( $date_fmt ) . ' à ' . esc_html( $res['booking_time'] )
            . '<br><strong>' . __( 'Client :', 'wp-etik-events' ) . '</strong> ' . esc_html( $contact['first_name'] . ' ' . $contact['last_name'] )
            . '<br><strong>' . __( 'Email :', 'wp-etik-events' ) . '</strong> ' . esc_html( $contact['email'] )
            . ( $contact['phone'] ? '<br><strong>' . __( 'Tél :', 'wp-etik-events' ) . '</strong> ' . esc_html( $contact['phone'] ) : '' )
            . '<br><br><a href="' . esc_url( $view_url ) . '">' . __( 'Voir les réservations', 'wp-etik-events' ) . '</a>';

        $html = self::_email_html(
            '🔔 ' . __( 'Nouvelle réservation', 'wp-etik-events' ),
            '#0074d4', '', $body, $date_fmt, $res['booking_time'], $site_name
        );

        wp_mail(
            $admin_mail,
            sprintf( __( '🔔 Nouvelle réservation — %s', 'wp-etik-events' ), $prestation ),
            $html,
            [ 'From: ' . $site_name . ' <' . $admin_mail . '>', 'Content-Type: text/html; charset=UTF-8' ]
        );
    }

    // ─── STRIPE ───────────────────────────────────────────────────────────────

    private function stripe_session( int $reservation_id, int $prestation_id, int $etik_user_id, float $price, string $return_url ) {
        $cents = (int) round( $price * 100 );
        if ( $cents <= 0 ) return new \WP_Error( 'price', 'Montant invalide.' );

        $secret = '';
        if ( class_exists( 'WP_Etik\\Admin\\Payments_Settings' ) ) {
            try {
                $ps     = new \WP_Etik\Admin\Payments_Settings();
                $all    = $ps->get_all_keys();
                $secret = $all['stripe']['secret'] ?? '';
            } catch ( \Throwable $e ) {
                Utils::log( '[PrestationBooking] Stripe keys: ' . $e->getMessage() );
            }
        }
        if ( empty( $secret ) ) return new \WP_Error( 'no_key', 'Clé Stripe non configurée.' );

        // 1. Récupérer l'email du client depuis Etik_User_Manager pour le passer à Stripe
        $customer_email = '';
        if ( class_exists( 'WP_Etik\Etik_User_Manager' ) ) {
            $contact = Etik_User_Manager::get( $etik_user_id );
            if ( ! empty( $contact['email'] ) ) {
                $customer_email = $contact['email'];
            }
        }

        $base  = $return_url ?: home_url( '/' );
        $succ  = add_query_arg( [ 'etik_booking_paid' => 1, 'res_id' => $reservation_id ], $base );
        $canc  = add_query_arg( [ 'etik_booking_cancelled' => 1 ], $base );
        $title = get_the_title( $prestation_id ) ?: 'Réservation';

        $body_params = [
            'payment_method_types[]'                        => 'card',
            'mode'                                          => 'payment',
            'line_items[0][price_data][currency]'           => 'eur',
            'line_items[0][price_data][product_data][name]' => substr( $title, 0, 60 ),
            'line_items[0][price_data][unit_amount]'        => $cents,
            'line_items[0][quantity]'                       => 1,
            'metadata[type]'                                => 'reservation',
            'metadata[reservation_id]'                      => (string) $reservation_id,
            'metadata[etik_user_id]'                        => (string) $etik_user_id,
            'metadata[prestation_id]'                       => (string) $prestation_id,
            'success_url'                                   => $succ,
            'cancel_url'                                    => $canc,
        ];

        // 2. Ajout de l'email client si disponible
        if ( ! empty( $customer_email ) ) {
            $body_params['customer_email'] = $customer_email;
        }

        $resp = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', [
            'body'    => $body_params,
            'headers' => [ 
                'Authorization' => 'Bearer ' . $secret, 
                'Content-Type'  => 'application/x-www-form-urlencoded' 
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $resp ) ) return new \WP_Error( 'network', $resp->get_error_message() );

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code !== 200 && $code !== 201 ) {
            return new \WP_Error( 'stripe_api', $data['error']['message'] ?? 'Erreur Stripe.' );
        }

        return [ 'id' => $data['id'] ?? '', 'url' => $data['url'] ?? '' ];
    }

    // ─── FORM SNAPSHOT ────────────────────────────────────────────────────────
    /*
    private function build_snapshot( int $form_id ) : ?string {
        if ( ! class_exists( 'WP_Etik\\Form_Manager' ) ) {
            $p = WP_ETIK_PLUGIN_DIR . 'src/Form_Manager.php';
            if ( file_exists( $p ) ) require_once $p;
        }

        $fields    = Form_Manager::get_fields( $form_id );
        $answers   = [];
        $questions = [];

        foreach ( $fields as $f ) {
            $k = $f['field_key'];
            $t = $f['type'];
            if ( $t === 'html' ) continue;

            $v = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : null;
            if ( is_array( $v ) ) $v = implode( ', ', array_map( 'sanitize_text_field', $v ) );
            elseif ( $v !== null ) $v = sanitize_text_field( $v );

            $questions[ $k ] = [ 'label' => $f['label'], 'type' => $t ];
            if ( $v !== null && $v !== '' ) $answers[ $k ] = $v;
        }

        if ( empty( $answers ) ) return null;

        $json = wp_json_encode( [ 'v' => 1, 'form_id' => $form_id, 'questions' => $questions, 'answers' => $answers, 'ts' => time() ] );

        if ( ! class_exists( 'WP_Etik\\Encryption' ) ) {
            $p = WP_ETIK_PLUGIN_DIR . 'src/Encryption.php';
            if ( file_exists( $p ) ) require_once $p;
        }

        if ( class_exists( 'WP_Etik\\Encryption' ) ) {
            try { return Encryption::encrypt( $json )['ciphertext']; }
            catch ( \Exception $e ) { Utils::log( '[Prestation_Booking] enc: ' . $e->getMessage() ); }
        }

        return $json; // fallback non chiffré
    }
    */
    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private static function _slots_for_dow( array $slots, int $dow ) : array {
        return array_values( array_filter( $slots, function( $s ) use ( $dow ) {
            return ! empty( $s->days ) && in_array( $dow, array_map( 'intval', explode( ',', $s->days ) ), true );
        } ) );
    }

    private static function _load_deps() : void {
        if ( ! class_exists( 'WP_Etik\\Etik_User_Manager' ) ) {
            require_once WP_ETIK_PLUGIN_DIR . 'src/Etik_User_Manager.php';
        }
    }

    private static function _email_data( int $reservation_id ) : ?array {
        global $wpdb;
        $res = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}etik_reservations WHERE id=%d LIMIT 1", $reservation_id
        ), ARRAY_A );
        if ( ! $res || ! $res['etik_user_id'] ) return null;

        self::_load_deps();
        $contact = Etik_User_Manager::get( (int) $res['etik_user_id'] );
        if ( ! $contact || ! $contact['email'] ) return null;

        return [
            $res,
            $contact,
            get_the_title( (int) $res['prestation_id'] ) ?: 'Prestation',
            date_i18n( get_option( 'date_format' ), strtotime( $res['booking_date'] ) ),
            wp_specialchars_decode( get_bloginfo( 'name' ) ),
            get_option( 'admin_email' ),
        ];
    }

    private static function _email_html( string $heading, string $color, string $greeting, string $body, string $date, string $time, string $site ) : string {
        $h  = '<html><body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:0;">';
        $h .= '<div style="max-width:600px;margin:30px auto;padding:24px;border:1px solid #e5e5e5;border-radius:8px;">';
        $h .= '<h2 style="color:' . esc_attr( $color ) . ';margin-top:0;">' . $heading . '</h2>';
        if ( $greeting ) $h .= '<p>' . $greeting . '</p>';
        $h .= '<p>' . $body . '</p>';
        $h .= '<p style="color:#aaa;font-size:12px;">' . esc_html( $site ) . '</p>';
        $h .= '</div></body></html>';
        return $h;
    }
}