<?php
namespace WP_Etik\Admin;

defined('ABSPATH') || exit;

use WP_Etik\Form_Manager;

class Form_Builder_Admin {

    const MENU_SLUG = 'wp-etik-forms';

    public static function init() : void {
        $self = new self();
        add_action( 'admin_menu',             [ $self, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $self, 'enqueue_assets' ] );
        add_action( 'wp_ajax_etik_save_form', [ $self, 'ajax_save_form' ] );
        add_action( 'wp_ajax_etik_delete_form', [ $self, 'ajax_delete_form' ] );
        add_action( 'wp_ajax_etik_reorder_fields', [ $self, 'ajax_reorder_fields' ] );
    }

    public function add_menu() : void {
        add_submenu_page(
            'edit.php?post_type=etik_event',
            __( 'Formulaires', 'wp-etik-events' ),
            __( 'Formulaires', 'wp-etik-events' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ) : void {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( $screen->id !== 'etik_event_page_' . self::MENU_SLUG ) return;

        // jQuery UI Sortable (déjà enregistré dans WP)
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_style( 'wp-etik-admin', WP_ETIK_PLUGIN_URL . 'assets/css/admin.css', [], WP_ETIK_VERSION );
        wp_enqueue_style( 'wp-etik-form-builder',
            WP_ETIK_PLUGIN_URL . 'assets/css/form-builder.css', [], WP_ETIK_VERSION
        );
        wp_enqueue_script( 'wp-etik-form-builder',
            WP_ETIK_PLUGIN_URL . 'assets/js/form-builder.js',
            [ 'jquery', 'jquery-ui-sortable' ],
            WP_ETIK_VERSION,
            true
        );

        wp_localize_script( 'wp-etik-form-builder', 'ETIK_FORMS', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'etik_form_builder' ),
            'field_types' => Form_Manager::get_field_types(),
            'strings'     => [
                'confirm_delete'       => __( 'Supprimer ce formulaire ?', 'wp-etik-events' ),
                'confirm_delete_field' => __( 'Supprimer ce champ ?', 'wp-etik-events' ),
                'saved'                => __( 'Formulaire enregistré.', 'wp-etik-events' ),
                'error'                => __( 'Erreur lors de l\'enregistrement.', 'wp-etik-events' ),
                'field_label_empty'    => __( 'Le libellé du champ ne peut pas être vide.', 'wp-etik-events' ),
                'default_protected'    => __( 'Le formulaire par défaut ne peut pas être supprimé.', 'wp-etik-events' ),
            ],
        ] );
    }

    // ── Rendu principal ───────────────────────────────────────────────────

    public function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé', 'wp-etik-events' ) );
        }

        $action  = sanitize_text_field( $_GET['action'] ?? 'list' );
        $form_id = intval( $_GET['form_id'] ?? 0 );

        if ( $action === 'edit' && $form_id > 0 ) {
            $this->render_edit_page( $form_id );
        } elseif ( $action === 'new' ) {
            $this->render_edit_page( 0 );
        } else {
            $this->render_list_page();
        }
    }

    // ── Page liste ────────────────────────────────────────────────────────

    private function render_list_page() : void {
        $forms = Form_Manager::get_forms();
        ?>
        <div class="wrap etik-admin">
            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Formulaires d\'inscription', 'wp-etik-events' ); ?>
            </h1>
            <a href="<?php echo esc_url( add_query_arg([
                'page'   => self::MENU_SLUG,
                'action' => 'new',
            ], admin_url('edit.php?post_type=etik_event'))); ?>" class="page-title-action">
                <?php esc_html_e( 'Ajouter un formulaire', 'wp-etik-events' ); ?>
            </a>
            <hr class="wp-header-end">

            <?php if ( empty( $forms ) ) : ?>
                <p><?php esc_html_e( 'Aucun formulaire. Cliquez sur "Ajouter" pour commencer.', 'wp-etik-events' ); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Titre', 'wp-etik-events' ); ?></th>
                        <th><?php esc_html_e( 'Attachement', 'wp-etik-events' ); ?></th>
                        <th><?php esc_html_e( 'Champs', 'wp-etik-events' ); ?></th>
                        <th><?php esc_html_e( 'Créé le', 'wp-etik-events' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wp-etik-events' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $forms as $form ) :
                        $fields     = Form_Manager::get_fields( (int) $form['id'] );
                        $edit_url   = add_query_arg([
                            'page'    => self::MENU_SLUG,
                            'action'  => 'edit',
                            'form_id' => $form['id'],
                        ], admin_url('edit.php?post_type=etik_event'));
                        $attach_labels = [
                            'event'      => __('Événements', 'wp-etik-events'),
                            'prestation' => __('Prestations', 'wp-etik-events'),
                            'all'        => __('Tous', 'wp-etik-events'),
                        ];
                    ?>
                    <tr>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url( $edit_url ); ?>">
                                    <?php echo esc_html( $form['title'] ); ?>
                                </a>
                            </strong>
                            <?php if ( intval( $form['is_default'] ) ) : ?>
                                <span class="etik-badge-default">
                                    <?php esc_html_e( 'Défaut', 'wp-etik-events' ); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( $form['description'] ) : ?>
                                <p class="description"><?php echo esc_html( $form['description'] ); ?></p>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $attach_labels[ $form['attach_type'] ] ?? $form['attach_type'] ); ?></td>
                        <td><?php echo count( $fields ); ?></td>
                        <td><?php echo esc_html( date_i18n( get_option('date_format'), strtotime( $form['created_at'] ) ) ); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">
                                <?php esc_html_e( 'Modifier', 'wp-etik-events' ); ?>
                            </a>
                            <?php if ( ! intval( $form['is_default'] ) ) : ?>
                                <button type="button" class="button button-small button-link-delete etik-delete-form"
                                    data-form-id="<?php echo esc_attr( $form['id'] ); ?>">
                                    <?php esc_html_e( 'Supprimer', 'wp-etik-events' ); ?>
                                </button>
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

    // ── Page éditeur ──────────────────────────────────────────────────────

    private function render_edit_page( int $form_id ) : void {
        $form   = $form_id ? ( Form_Manager::get_form( $form_id ) ?? [] ) : [];
        $fields = $form_id ? Form_Manager::get_fields( $form_id ) : [];
        $is_default = intval( $form['is_default'] ?? 0 );

        $back_url = add_query_arg(
            [ 'page' => self::MENU_SLUG ],
            admin_url( 'edit.php?post_type=etik_event' )
        );
        ?>
        <div class="wrap etik-admin">
            <h1>
                <a href="<?php echo esc_url( $back_url ); ?>" style="font-size:14px;font-weight:400;margin-right:12px;">
                    ← <?php esc_html_e( 'Formulaires', 'wp-etik-events' ); ?>
                </a>
                <?php echo $form_id
                    ? esc_html( $form['title'] ?? __( 'Modifier le formulaire', 'wp-etik-events' ) )
                    : esc_html__( 'Nouveau formulaire', 'wp-etik-events' );
                ?>
                <?php if ( $is_default ) : ?>
                    <span class="etik-badge-default" style="font-size:13px;font-weight:400;">
                        <?php esc_html_e( 'Formulaire par défaut', 'wp-etik-events' ); ?>
                    </span>
                <?php endif; ?>
            </h1>
            <hr class="wp-header-end">

            <!-- Zone de feedback -->
            <div id="etik-form-feedback" style="display:none;margin-bottom:16px;"></div>

            <div id="etik-form-editor" data-form-id="<?php echo esc_attr( $form_id ); ?>">
                <!-- Panneau gauche : paramètres -->
                <div class="etik-editor-left">
                    <div class="postbox">
                        <h3 class="hndle"><?php esc_html_e( 'Paramètres', 'wp-etik-events' ); ?></h3>
                        <div class="inside">
                            <table class="form-table">
                                <tr>
                                    <th><label for="etik-form-title"><?php esc_html_e('Titre', 'wp-etik-events'); ?> <span style="color:#a12d2d">*</span></label></th>
                                    <td>
                                        <input type="text" id="etik-form-title" class="regular-text"
                                            value="<?php echo esc_attr( $form['title'] ?? '' ); ?>"
                                            placeholder="<?php esc_attr_e('Ex: Inscription formation', 'wp-etik-events'); ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="etik-form-description"><?php esc_html_e('Description', 'wp-etik-events'); ?></label></th>
                                    <td>
                                        <textarea id="etik-form-description" class="regular-text" rows="2"
                                            placeholder="<?php esc_attr_e('Description courte (optionnel)', 'wp-etik-events'); ?>"
                                        ><?php echo esc_textarea( $form['description'] ?? '' ); ?></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="etik-form-attach"><?php esc_html_e('Attacher à', 'wp-etik-events'); ?></label></th>
                                    <td>
                                        <select id="etik-form-attach">
                                            <?php foreach ([
                                                'all'        => __('Tous (événements + prestations)', 'wp-etik-events'),
                                                'event'      => __('Événements uniquement', 'wp-etik-events'),
                                                'prestation' => __('Prestations uniquement', 'wp-etik-events'),
                                            ] as $val => $lbl) : ?>
                                                <option value="<?php echo esc_attr($val); ?>"
                                                    <?php selected( $form['attach_type'] ?? 'all', $val ); ?>>
                                                    <?php echo esc_html($lbl); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e( 'Définit où ce formulaire peut être sélectionné.', 'wp-etik-events' ); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Panneau droit : constructeur de champs -->
                <div class="etik-editor-right">
                    <div class="postbox">
                        <h3 class="hndle">
                            <?php esc_html_e( 'Champs', 'wp-etik-events' ); ?>
                            <span class="etik-fields-count" style="font-weight:400;font-size:13px;margin-left:6px;color:#6b6b6b;">
                                (<?php echo count( $fields ); ?>)
                            </span>
                        </h3>
                        <div class="inside">
                            <!-- Barre d'ajout de champ -->
                            <div class="etik-add-field-bar">
                                <span style="font-size:13px;color:#6b6b6b;margin-right:8px;">
                                    <?php esc_html_e( 'Ajouter :', 'wp-etik-events' ); ?>
                                </span>
                                <?php foreach ( Form_Manager::get_field_types() as $type => $info ) : ?>
                                    <button type="button"
                                        class="button button-small etik-add-field-btn"
                                        data-type="<?php echo esc_attr($type); ?>"
                                        title="<?php echo esc_attr($info['label']); ?>">
                                        <?php echo esc_html( $info['label'] ); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <!-- Liste des champs (sortable) -->
                            <div id="etik-fields-list" class="etik-fields-list">
                                <?php if ( empty( $fields ) ) : ?>
                                    <div class="etik-fields-empty">
                                        <?php esc_html_e( 'Aucun champ. Cliquez sur un type ci-dessus pour commencer.', 'wp-etik-events' ); ?>
                                    </div>
                                <?php endif; ?>

                                <?php foreach ( $fields as $field ) :
                                    $this->render_field_row( $field );
                                endforeach; ?>
                            </div>

                            <!-- Bouton enregistrer -->
                            <div style="margin-top:20px;display:flex;gap:12px;align-items:center;">
                                <button type="button" id="etik-save-form" class="button button-primary">
                                    <?php esc_html_e( 'Enregistrer le formulaire', 'wp-etik-events' ); ?>
                                </button>
                                <span id="etik-save-spinner" style="display:none;">
                                    <span class="etik-spinner"></span>
                                    <?php esc_html_e( 'Enregistrement…', 'wp-etik-events' ); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Template HTML d'une ligne de champ (cloné par JS) -->
        <script type="text/template" id="etik-field-row-tpl">
        <?php $this->render_field_row( null, true ); ?>
        </script>
        <?php
    }

    /**
     * Affiche une ligne de champ (soit depuis BDD, soit template vide pour JS).
     */
    private function render_field_row( ?array $field, bool $is_template = false ) : void {
        $id          = $is_template ? '{{id}}' : esc_attr( $field['id'] ?? '' );
        $field_key   = $is_template ? '' : esc_attr( $field['field_key'] ?? '' );
        $label       = $is_template ? '' : esc_attr( $field['label'] ?? '' );
        $type        = $is_template ? 'text' : esc_attr( $field['type'] ?? 'text' );
        $placeholder = $is_template ? '' : esc_attr( $field['placeholder'] ?? '' );
        $required    = $is_template ? false : (bool) intval( $field['required'] ?? 0 );
        $help_text   = $is_template ? '' : esc_attr( $field['help_text'] ?? '' );
        $options_raw = $is_template ? '' : implode( "\n", $field['options_decoded'] ?? [] );
        $type_label  = Form_Manager::get_field_types()[$type]['label'] ?? $type;
        ?>
        <div class="etik-field-row" data-field-id="<?php echo $id; ?>" data-type="<?php echo $type; ?>">
            <!-- Handle drag & drop -->
            <span class="etik-drag-handle" title="<?php esc_attr_e('Glisser pour réordonner', 'wp-etik-events'); ?>">
                ⠿
            </span>

            <!-- Résumé (toujours visible) -->
            <div class="etik-field-summary">
                <span class="etik-field-type-badge etik-type-<?php echo esc_attr($type); ?>">
                    <?php echo esc_html( $type_label ); ?>
                </span>
                <strong class="etik-field-label-preview">
                    <?php echo $label ?: '<em style="font-weight:400;color:#6b6b6b;">' . esc_html__('(sans libellé)', 'wp-etik-events') . '</em>'; ?>
                </strong>
                <?php if ( $required ) : ?>
                    <span style="color:#a12d2d;font-size:11px;">*<?php esc_html_e('requis', 'wp-etik-events'); ?></span>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="etik-field-actions">
                <button type="button" class="etik-field-toggle button-link" title="<?php esc_attr_e('Modifier', 'wp-etik-events'); ?>">
                    ▾
                </button>
                <button type="button" class="etik-field-delete button-link button-link-delete" title="<?php esc_attr_e('Supprimer', 'wp-etik-events'); ?>">
                    ✕
                </button>
            </div>

            <!-- Détails (masqués par défaut, dépliés au clic) -->
            <div class="etik-field-details" style="display:none;">
                <div class="etik-field-grid">
                    <label>
                        <?php esc_html_e('Libellé', 'wp-etik-events'); ?> <span style="color:#a12d2d">*</span>
                        <input type="text" class="etik-f-label regular-text"
                            value="<?php echo $label; ?>"
                            placeholder="<?php esc_attr_e('Ex: Prénom', 'wp-etik-events'); ?>">
                    </label>

                    <label>
                        <?php esc_html_e('Clé interne', 'wp-etik-events'); ?>
                        <input type="text" class="etik-f-key regular-text"
                            value="<?php echo $field_key; ?>"
                            placeholder="<?php esc_attr_e('ex: first_name (auto si vide)', 'wp-etik-events'); ?>">
                        <span class="description"><?php esc_html_e('Identifiant utilisé dans le code.', 'wp-etik-events'); ?></span>
                    </label>

                    <label>
                        <?php esc_html_e('Texte fantôme (placeholder)', 'wp-etik-events'); ?>
                        <input type="text" class="etik-f-placeholder regular-text"
                            value="<?php echo $placeholder; ?>"
                            placeholder="<?php esc_attr_e('Ex: Votre prénom', 'wp-etik-events'); ?>">
                    </label>

                    <label>
                        <?php esc_html_e('Aide (texte sous le champ)', 'wp-etik-events'); ?>
                        <input type="text" class="etik-f-help regular-text"
                            value="<?php echo $help_text; ?>"
                            placeholder="<?php esc_attr_e('Ex: Champ facultatif', 'wp-etik-events'); ?>">
                    </label>

                    <label class="etik-field-required-wrap">
                        <input type="checkbox" class="etik-f-required" value="1"
                            <?php checked( $required ); ?>>
                        <?php esc_html_e('Champ obligatoire', 'wp-etik-events'); ?>
                    </label>
                </div>

                <!-- Options select (visible uniquement si type=select) -->
                <div class="etik-field-options-wrap" style="<?php echo $type === 'select' ? '' : 'display:none;'; ?>">
                    <label>
                        <?php esc_html_e('Options (une par ligne)', 'wp-etik-events'); ?>
                        <textarea class="etik-f-options regular-text" rows="4"
                            placeholder="<?php esc_attr_e("Option 1\nOption 2\nOption 3", 'wp-etik-events'); ?>"
                        ><?php echo esc_textarea( $options_raw ); ?></textarea>
                    </label>
                </div>
            </div>
        </div>
        <?php
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────

    public function ajax_save_form() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( ['message' => 'Accès refusé'], 403 );
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'etik_form_builder' ) ) {
            wp_send_json_error( ['message' => 'Nonce invalide'], 400 );
        }

        $form_id = intval( $_POST['form_id'] ?? 0 );
        $data    = [
            'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'attach_type' => sanitize_text_field( wp_unslash( $_POST['attach_type'] ?? 'all' ) ),
        ];

        $result = Form_Manager::save_form( $data, $form_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( ['message' => $result->get_error_message()] );
        }

        $saved_form_id = $result;

        // Sauvegarder les champs
        $fields_raw = isset( $_POST['fields'] ) ? $_POST['fields'] : [];
        $fields = [];

        foreach ( $fields_raw as $f ) {
            $fields[] = [
                'field_key'   => sanitize_key( wp_unslash( $f['field_key'] ?? '' ) ),
                'label'       => sanitize_text_field( wp_unslash( $f['label'] ?? '' ) ),
                'type'        => sanitize_text_field( wp_unslash( $f['type'] ?? 'text' ) ),
                'placeholder' => sanitize_text_field( wp_unslash( $f['placeholder'] ?? '' ) ),
                'required'    => intval( $f['required'] ?? 0 ),
                'options'     => sanitize_textarea_field( wp_unslash( $f['options'] ?? '' ) ),
                'help_text'   => sanitize_text_field( wp_unslash( $f['help_text'] ?? '' ) ),
            ];
        }

        Form_Manager::save_fields( $saved_form_id, $fields );

        wp_send_json_success( [
            'form_id' => $saved_form_id,
            'message' => __( 'Formulaire enregistré.', 'wp-etik-events' ),
            'redirect' => add_query_arg([
                'page'    => self::MENU_SLUG,
                'action'  => 'edit',
                'form_id' => $saved_form_id,
            ], admin_url('edit.php?post_type=etik_event')),
        ] );
    }

    public function ajax_delete_form() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( ['message' => 'Accès refusé'], 403 );
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'etik_form_builder' ) ) {
            wp_send_json_error( ['message' => 'Nonce invalide'], 400 );
        }

        $form_id = intval( $_POST['form_id'] ?? 0 );
        if ( ! $form_id ) {
            wp_send_json_error( ['message' => 'ID manquant'] );
        }

        $deleted = Form_Manager::delete_form( $form_id );
        if ( ! $deleted ) {
            wp_send_json_error( ['message' => __('Impossible de supprimer (formulaire par défaut ou introuvable).', 'wp-etik-events')] );
        }

        wp_send_json_success( ['message' => __('Formulaire supprimé.', 'wp-etik-events')] );
    }

    public function ajax_reorder_fields() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'etik_form_builder' ) ) {
            wp_send_json_error( [], 400 );
        }

        $form_id    = intval( $_POST['form_id'] ?? 0 );
        $order      = array_map( 'intval', (array) ( $_POST['order'] ?? [] ) );

        Form_Manager::reorder_fields( $form_id, $order );
        wp_send_json_success();
    }
}