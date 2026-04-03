<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

if ( ! class_exists( __NAMESPACE__ . '\\Etik_Modal_Manager' ) ) :

/**
 * Gère le modal d'inscription global.
 *
 * Nouveautés :
 *  - Le formulaire affiché est chargé dynamiquement via AJAX selon l'event_id.
 *  - Chaque événement peut avoir son propre formulaire (méta etik_event_form_id).
 *  - Fallback : formulaire par défaut (is_default = 1 dans etik_forms).
 *  - Rendu des types : text, email, tel, number, date, textarea,
 *                      checkbox, radio, checkbox_group, select,
 *                      html (bloc texte), consent (RGPD).
 *  - Logique conditionnelle via data-attributes (gérée côté JS).
 */
class Etik_Modal_Manager {

    protected static bool $needed = false;

    public static function mark_needed() : void { self::$needed = true; }
    public static function is_needed()    : bool { return self::$needed; }

    // ─── ASSETS ─────────────────────────────────────────────────────────────

    public static function maybe_enqueue_assets() : void {
        if ( ! self::is_needed() ) return;

        $url  = WP_ETIK_PLUGIN_URL;
        $path = WP_ETIK_PLUGIN_DIR;

        wp_enqueue_style(
            'wp-etik-modal',
            $url . 'assets/css/etik-modal.css',
            [],
            filemtime( $path . 'assets/css/etik-modal.css' )
        );

        wp_register_script(
            'wp_etik_inscription_js',
            $url . 'assets/js/etik-inscription.js',
            [ 'jquery' ],
            filemtime( $path . 'assets/js/etik-inscription.js' ),
            true
        );

        wp_localize_script( 'wp_etik_inscription_js', 'WP_ETIK_AJAX', [
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'wp_etik_inscription_nonce' ),
            'form_nonce'       => wp_create_nonce( 'etik_get_form_html' ),
            'hcaptcha_sitekey' => get_option( 'wp_etik_hcaptcha_sitekey', '' ),
        ] );

        wp_enqueue_script( 'wp_etik_inscription_js' );

        if ( get_option( 'wp_etik_hcaptcha_sitekey' ) ) {
            wp_enqueue_script( 'hcaptcha', 'https://hcaptcha.com/1/api.js', [], null, true );
        }
    }

    // ─── MODAL HTML (footer) ────────────────────────────────────────────────

    /**
     * Injecte le squelette du modal dans le footer.
     * Le contenu du formulaire est vide — il est chargé par AJAX à l'ouverture.
     */
    public static function render_footer_modal() : void {
        if ( ! self::is_needed() ) return;

        $hcaptcha_sitekey = esc_attr( get_option( 'wp_etik_hcaptcha_sitekey', '' ) );
        ?>
        <div class="etik-modal" id="etik-global-modal" aria-hidden="true">
            <div class="etik-modal-backdrop" data-modal-close></div>
            <div class="etik-modal-dialog" role="dialog" aria-modal="true"
                 aria-labelledby="etik-modal-title">

                <button class="etik-modal-close" data-modal-close
                        aria-label="<?php esc_attr_e( 'Fermer', 'wp-etik-events' ); ?>">&times;</button>

                <div class="etik-modal-content">
                    <h3 id="etik-modal-title">
                        <?php esc_html_e( 'Inscription', 'wp-etik-events' ); ?>
                    </h3>

                    <div class="etik-panels">
                        <div class="etik-panel active" data-panel="insc">

                            <form class="etik-insc-form" novalidate>
                                <input type="hidden" name="action"   value="lwe_create_checkout">
                                <input type="hidden" name="event_id" value="">

                                <!-- Zone de chargement du formulaire dynamique -->
                                <div id="etik-form-fields-container">
                                    <div class="etik-form-loading" aria-live="polite"
                                         style="text-align:center;padding:24px;color:#888;">
                                        <?php esc_html_e( 'Chargement…', 'wp-etik-events' ); ?>
                                    </div>
                                </div>

                                <!-- hCaptcha (optionnel) -->
                                <?php if ( $hcaptcha_sitekey ) : ?>
                                    <div class="etik-hcaptcha-placeholder" style="margin:12px 0;"></div>
                                <?php endif; ?>

                                <div class="etik-modal-actions">
                                    <button type="submit" class="etik-submit-btn">
                                        <?php esc_html_e( "S'inscrire", 'wp-etik-events' ); ?>
                                    </button>
                                </div>
                            </form>

                        </div><!-- /.panel insc -->
                    </div><!-- /.etik-panels -->
                </div><!-- /.etik-modal-content -->

            </div><!-- /.etik-modal-dialog -->
        </div><!-- /.etik-modal -->
        <?php
    }

    // ─── AJAX : CHARGER LES CHAMPS DU FORMULAIRE ────────────────────────────

    /**
     * Retourne le HTML des champs pour l'event_id demandé.
     * Résolution : méta de l'événement → formulaire par défaut.
     *
     * Action : wp_ajax_nopriv_etik_get_form_html (public) +
     *          wp_ajax_etik_get_form_html         (admin)
     */
    public static function ajax_get_form_html() : void {
        check_ajax_referer( 'etik_get_form_html', 'nonce' );

        $event_id = intval( $_POST['event_id'] ?? 0 );
        $form_id  = self::resolve_form_id( $event_id );

        if ( ! $form_id ) {
            wp_send_json_error( [ 'message' => __( 'Aucun formulaire configuré.', 'wp-etik-events' ) ] );
        }

        // Vérifier que le formulaire est actif
        $active = get_post_meta( $event_id, 'etik_event_form_active', true );
        if ( $active === '0' ) {
            wp_send_json_error( [ 'message' => __( 'Les inscriptions sont désactivées pour cet événement.', 'wp-etik-events' ) ] );
        }

        $html = self::render_form_fields( $form_id );

        wp_send_json_success( [
            'html'    => $html,
            'form_id' => $form_id,
        ] );
    }

    /**
     * Résout le form_id pour un événement :
     *  1. Méta spécifique de l'événement (etik_event_form_id)
     *  2. Formulaire par défaut (is_default = 1)
     *  3. Premier formulaire disponible
     */
    public static function resolve_form_id( int $event_id ) : int {
        // 1. Méta de l'événement
        if ( $event_id > 0 ) {
            $meta_form_id = intval( get_post_meta( $event_id, 'etik_event_form_id', true ) );
            if ( $meta_form_id > 0 ) {
                return $meta_form_id;
            }
        }

        // 2. Formulaire par défaut
        global $wpdb;
        $default_id = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}etik_forms WHERE is_default = 1 LIMIT 1"
        );
        if ( $default_id ) return $default_id;

        // 3. Premier formulaire
        $first_id = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}etik_forms ORDER BY id ASC LIMIT 1"
        );
        return $first_id;
    }

    // ─── RENDU DES CHAMPS ────────────────────────────────────────────────────

    /**
     * Génère le HTML de tous les champs d'un formulaire.
     * Utilisé par ajax_get_form_html() et peut être appelé directement
     * pour un pré-rendu statique si nécessaire.
     */
    public static function render_form_fields( int $form_id ) : string {
        if ( ! class_exists( 'WP_Etik\\Form_Manager' ) ) {
            require_once WP_ETIK_PLUGIN_DIR . 'src/Form_Manager.php';
        }

        $fields = Form_Manager::get_fields( $form_id );
        if ( empty( $fields ) ) {
            return '<p class="etik-form-empty">'
                 . esc_html__( 'Ce formulaire ne contient aucun champ.', 'wp-etik-events' )
                 . '</p>';
        }

        $html = '';
        foreach ( $fields as $field ) {
            $html .= self::render_single_field( $field );
        }
        return $html;
    }

    /**
     * Rendu d'un seul champ selon son type.
     */
    private static function render_single_field( array $field ) : string {
        $type     = $field['type']        ?? 'text';
        $key      = $field['field_key']   ?? 'field';
        $label    = $field['label']       ?? '';
        $ph       = $field['placeholder'] ?? '';
        $required = ! empty( $field['required'] );
        $help     = ! empty( $field['help_text'] ) && ! isset( json_decode( $field['help_text'], true )['if'] )
                    ? $field['help_text'] : '';
        $options  = $field['options_decoded'] ?? [];
        $cond     = $field['conditional']    ?? null; // logique conditionnelle décodée

        // Attributs pour la logique conditionnelle (gérée par JS)
        $cond_attrs = '';
        if ( $cond ) {
            $cond_attrs  = ' data-cond-field="'  . esc_attr( $cond['if']     ?? '' ) . '"';
            $cond_attrs .= ' data-cond-value="'  . esc_attr( $cond['eq']     ?? '' ) . '"';
            $cond_attrs .= ' data-cond-action="' . esc_attr( $cond['action'] ?? '' ) . '"';
            if ( ! empty( $cond['msg'] ) ) {
                $cond_attrs .= ' data-cond-msg="' . esc_attr( $cond['msg'] ) . '"';
            }
            // Les champs conditionnels sont masqués par défaut (le JS les révèle)
            if ( ( $cond['action'] ?? '' ) === 'show_field' ) {
                $cond_attrs .= ' style="display:none;"';
            }
        }

        $req_attr  = $required ? ' required' : '';
        $req_mark  = $required ? ' <i aria-hidden="true">*</i>' : '';
        $lbl_esc   = esc_html( $label );
        $ph_esc    = esc_attr( $ph );
        $key_esc   = esc_attr( $key );
        $help_html = $help
            ? '<span class="etik-field-help">' . esc_html( $help ) . '</span>'
            : '';

        $wrap_open  = '<div class="etik-field etik-field-' . esc_attr( $type ) . '"' . $cond_attrs . '>';
        $wrap_close = $help_html . '</div>';

        switch ( $type ) {

            // ── Saisie simple ─────────────────────────────────────────────
            case 'text':
            case 'email':
            case 'tel':
            case 'number':
            case 'date':
                return $wrap_open
                     . '<label>' . $lbl_esc . $req_mark
                     . '<input type="' . esc_attr( $type ) . '" name="' . $key_esc . '"'
                     . $req_attr . ' placeholder="' . $ph_esc . '">'
                     . '</label>'
                     . $wrap_close;

            // ── Texte long ────────────────────────────────────────────────
            case 'textarea':
                return $wrap_open
                     . '<label>' . $lbl_esc . $req_mark
                     . '<textarea name="' . $key_esc . '"'
                     . $req_attr . ' placeholder="' . $ph_esc . '" rows="4"></textarea>'
                     . '</label>'
                     . $wrap_close;

            // ── Case unique ───────────────────────────────────────────────
            case 'checkbox':
                return $wrap_open
                     . '<label class="etik-check-single">'
                     . '<input type="checkbox" name="' . $key_esc . '" value="1"' . $req_attr . '> '
                     . $lbl_esc . $req_mark
                     . '</label>'
                     . $wrap_close;

            // ── Radio (Oui/Non ou choix personnalisés) ────────────────────
            case 'radio':
                $items = ! empty( $options ) ? $options : [ 'Oui', 'Non' ];
                $inner = '<fieldset class="etik-radio-group">'
                       . '<legend>' . $lbl_esc . $req_mark . '</legend>';
                foreach ( $items as $opt ) {
                    $opt_esc  = esc_attr( $opt );
                    $opt_html = esc_html( $opt );
                    $inner   .= '<label class="etik-radio-label">'
                              . '<input type="radio" name="' . $key_esc . '" value="' . $opt_esc . '"' . $req_attr . '> '
                              . $opt_html . '</label>';
                }
                $inner .= '</fieldset>';

                // Message conditionnel (ex: si Oui → avertissement)
                if ( $cond && ( $cond['action'] ?? '' ) === 'show_msg' && ! empty( $cond['msg'] ) ) {
                    $inner .= '<div class="etik-cond-msg" data-for="' . $key_esc . '" style="display:none;">'
                            . '<div class="etik-notice-warning">'
                            . esc_html( $cond['msg'] )
                            . '</div></div>';
                }

                return $wrap_open . $inner . $wrap_close;

            // ── Cases à cocher multiples ──────────────────────────────────
            case 'checkbox_group':
                $inner = '<fieldset class="etik-checkbox-group">'
                       . '<legend>' . $lbl_esc . $req_mark . '</legend>';
                foreach ( $options as $opt ) {
                    $opt_esc  = esc_attr( $opt );
                    $opt_html = esc_html( $opt );
                    $inner   .= '<label class="etik-checkbox-label">'
                              . '<input type="checkbox" name="' . $key_esc . '[]" value="' . $opt_esc . '"> '
                              . $opt_html . '</label>';
                }
                $inner .= '</fieldset>';
                return $wrap_open . $inner . $wrap_close;

            // ── Liste déroulante ──────────────────────────────────────────
            case 'select':
                $inner  = '<label>' . $lbl_esc . $req_mark
                        . '<select name="' . $key_esc . '"' . $req_attr . '>'
                        . '<option value="">— ' . esc_html__( 'choisir', 'wp-etik-events' ) . ' —</option>';
                foreach ( $options as $opt ) {
                    $inner .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
                }
                $inner .= '</select></label>';
                return $wrap_open . $inner . $wrap_close;

            // ── Bloc de texte / informatif ────────────────────────────────
            case 'html':
                $content = $options[0] ?? '';
                if ( ! $content ) return '';
                // $label comme titre facultatif
                $title_html = $label ? '<p class="etik-html-block-title"><strong>' . $lbl_esc . '</strong></p>' : '';
                return '<div class="etik-field etik-html-block">'
                     . $title_html
                     . wp_kses_post( $content )
                     . '</div>';

            // ── Consentement RGPD ─────────────────────────────────────────
            case 'consent':
                $consent_text = $options[0] ?? $label;
                return '<div class="etik-field etik-field-consent">'
                     . '<label class="etik-consent-label">'
                     . '<input type="checkbox" name="' . $key_esc . '" value="1"' . $req_attr . '> '
                     . wp_kses_post( $consent_text ) . $req_mark
                     . '</label>'
                     . $help_html
                     . '</div>';

            default:
                return '';
        }
    }

    // ─── ENREGISTREMENT DES HOOKS ────────────────────────────────────────────

    public static function register_ajax_hooks() : void {
        add_action( 'wp_ajax_etik_get_form_html',        [ static::class, 'ajax_get_form_html' ] );
        add_action( 'wp_ajax_nopriv_etik_get_form_html', [ static::class, 'ajax_get_form_html' ] );
    }
}

endif; // class_exists