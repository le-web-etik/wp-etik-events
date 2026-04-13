<?php
/**
 * Script de création du formulaire "Inscription Constellations"
 *
 * Usage depuis le plugin (ajout dans wp-etik-events.php) :
 *
 *   add_action( 'init', function() {
 *       if ( ! is_admin() || ! current_user_can('manage_options') ) return;
 *       if ( ! isset( $_GET['create_form_constellations'] ) ) return;
 *       require_once WP_ETIK_PLUGIN_DIR . 'includes/admin/create-form-constellations.php';
 *       $result = etik_create_form_constellations();
 *       $args   = [ 'page' => 'wp-etik-forms', 'etik_msg' => $result['status'] ];
 *       if ( ! empty( $result['form_id'] ) ) {
 *           $args['action']  = 'edit';
 *           $args['form_id'] = $result['form_id'];
 *       }
 *       wp_safe_redirect( add_query_arg( $args, admin_url('edit.php?post_type=etik_event') ) );
 *       exit;
 *   } );
 *
 * Visiter ensuite : /wp-admin/?create_form_constellations=1
 *
 * La fonction retourne :
 *   [ 'status' => 'created'|'exists'|'error', 'form_id' => int, 'message' => string ]
 */

defined( 'ABSPATH' ) || exit;

function etik_create_form_constellations() : array {
    global $wpdb;

    $forms_table  = $wpdb->prefix . 'etik_forms';
    $fields_table = $wpdb->prefix . 'etik_form_fields';

    // ── Vérifier que les tables existent ─────────────────────────────────
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$forms_table}'" ) !== $forms_table ) {
        return [
            'status'  => 'error',
            'form_id' => 0,
            'message' => "Table {$forms_table} introuvable. Réactivez le plugin.",
        ];
    }

    // ── Éviter les doublons ───────────────────────────────────────────────
    $existing_id = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT id FROM {$forms_table} WHERE slug = %s LIMIT 1", 'inscription-constellations' )
    );
    if ( $existing_id > 0 ) {
        return [
            'status'  => 'exists',
            'form_id' => $existing_id,
            'message' => "Le formulaire existe déjà (ID #{$existing_id}).",
        ];
    }

    // ── Textes ────────────────────────────────────────────────────────────
    $texte_securite = '<p>Les constellations peuvent parfois susciter des émotions intenses ; pour votre sécurité et celle du groupe il est important d\'écouter vos besoins et de demander un accompagnement adapté si besoin.</p>
<p>Vous vous engagez à nous <strong>prévenir avant votre inscription</strong> si vous êtes enceinte, si vous êtes concerné·e par un diagnostic psychiatrique ou si vous êtes sous traitement médicamenteux afin que nous puissions déterminer ensemble la pertinence de votre participation.</p>';

    $texte_rgpd = '<strong>Consentement pour la protection de la vie privée (RGPD)</strong><br>
En vertu de la législation européenne sur la protection des données, nous sommes tenus de vous demander votre consentement explicite concernant le traitement et l\'utilisation des informations mentionnées dans ce formulaire. Les informations recueillies sont enregistrées dans un fichier informatisé pour le suivi ultérieur de votre inscription et la communication des activités de « Judith-Constellations » (contacts, invitations, etc.) : elles seront conservées pour la durée de votre inscription. Ce suivi est effectué conformément au Règlement général sur la protection des données. Vous avez le droit de recevoir des informations concernant le traitement de vos données à caractère personnel, de les consulter, de les faire rectifier ou supprimer, d\'en restreindre le traitement, de vous opposer à leur traitement ou de demander qu\'elles soient transférées à un autre responsable du traitement des données. A cette fin, adressez-vous à votre adresse email à l\'attention de Judith.';

    $msg_condition_sante = 'Merci de nous contacter directement avant de finaliser votre inscription. Votre participation sera confirmée après cet échange.';

    // ── Définition des champs ─────────────────────────────────────────────
    $fields = [
        [ 'last_name',             'Nom',                                                                      'text',     '',                   1, [], '',                             1  ],
        [ 'first_name',            'Prénom',                                                                   'text',     'Votre prénom',        0, [], '',                             2  ],
        [ 'phone',                 'Téléphone (WhatsApp)',                                                     'tel',      '06 00 00 00 00',      0, [], 'Communication WhatsApp — non obligatoire', 3 ],
        [ 'email',                 'E-mail',                                                                   'email',    'votre@email.fr',      1, [], '',                             4  ],
        [ 'already_participated',  "J'ai déjà participé à une constellation de groupe",                        'radio',    '',                   0, ['Oui', 'Non'], '',                  5  ],
        [ 'info_securite',         'Informations importantes',                                                 'html',     '',                   0, [ $texte_securite ], '',             6  ],
        [ 'health_condition',      "Je suis enceinte / antécédents psychiatriques / médiqué·e pour ma santé mentale", 'radio', '', 1, ['Oui', 'Non'],
            wp_json_encode([ 'if' => 'health_condition', 'eq' => 'Oui', 'action' => 'show_msg', 'msg' => $msg_condition_sante ]), 7 ],
        [ 'declaration_exactitude',"Je déclare l'exactitude des informations transmises dans ce formulaire",  'checkbox', '',                   1, [], '',                             8  ],
        [ 'has_question',          "J'ai une question et désire être contacté·e",                              'radio',    '',                   0, ['Oui', 'Non'], '',                  9  ],
        [ 'question_phone',        'Numéro pour vous recontacter',                                             'tel',      '06 00 00 00 00',     0, [],
            wp_json_encode([ 'if' => 'has_question', 'eq' => 'Oui', 'action' => 'show_field' ]),                                                10 ],
        [ 'rgpd_info',             'Protection de la vie privée',                                              'html',     '',                   0, [ $texte_rgpd ], '',                11 ],
        [ 'consent_inscription',   "J'autorise « Judith-Constellations » à utiliser mes données pour le suivi de mon inscription", 'consent', '', 1,
            [ "J'autorise « Judith-Constellations » à utiliser mes données de contact pour le suivi de mon inscription." ], '', 12 ],
        [ 'consent_communication', "J'autorise « Judith-Constellations » à utiliser mes données pour communiquer au sujet de ses activités", 'consent', '', 0,
            [ "J'autorise « Judith-Constellations » à utiliser mes données de contact pour communiquer au sujet de ses activités (optionnel)." ], '', 13 ],
    ];
    // Colonnes : field_key | label | type | placeholder | required | options (array) | help_text (ou JSON cond.) | sort_order

    // ── Créer le formulaire ───────────────────────────────────────────────
    $wpdb->insert( $forms_table, [
        'title'       => 'Inscription Constellations',
        'description' => "Formulaire d'inscription pour les ateliers de constellations systémiques — Judith-Constellations.",
        'slug'        => 'inscription-constellations',
        'attach_type' => 'event',
        'is_default'  => 0,
    ], [ '%s', '%s', '%s', '%s', '%d' ] );

    $form_id = (int) $wpdb->insert_id;
    if ( ! $form_id ) {
        return [
            'status'  => 'error',
            'form_id' => 0,
            'message' => 'Échec création formulaire : ' . $wpdb->last_error,
        ];
    }

    // ── Insérer les champs ────────────────────────────────────────────────
    $types_choix = [ 'radio', 'checkbox_group', 'select' ];
    $types_html  = [ 'html', 'consent' ];

    foreach ( $fields as [ $key, $label, $type, $placeholder, $required, $opts, $help, $order ] ) {

        $options_json = null;

        if ( in_array( $type, $types_choix ) && ! empty( $opts ) ) {
            $options_json = wp_json_encode( array_values( $opts ) );

        } elseif ( in_array( $type, $types_html ) && ! empty( $opts ) ) {
            // $opts[0] = contenu HTML/texte
            $options_json = wp_json_encode( [ $opts[0] ] );
        }

        $wpdb->insert( $fields_table, [
            'form_id'     => $form_id,
            'field_key'   => $key,
            'label'       => $label,
            'type'        => $type,
            'placeholder' => $placeholder,
            'required'    => $required,
            'options'     => $options_json,
            'help_text'   => $help,
            'sort_order'  => $order,
        ], [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ] );
    }

    return [
        'status'  => 'created',
        'form_id' => $form_id,
        'message' => "Formulaire créé avec " . count( $fields ) . " champs (ID #{$form_id}).",
    ];
}