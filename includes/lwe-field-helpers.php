<?php
/**
 * includes/lwe-field-helpers.php
 *
 * Helpers pour mapper les champs dynamiques du Form Builder
 * vers les colonnes fixes de la table etik_inscriptions.
 *
 * Les field_key en base sont auto-générés (field_1, field_2…).
 * On résout le mapping via le TYPE du champ et son LABEL.
 *
 * Colonnes fixes : email, first_name, last_name, phone
 * Tout le reste  : stocké en JSON chiffré dans `custom_data`
 */

namespace WP_Etik;

defined( 'ABSPATH' ) || exit;

/**
 * Résout la correspondance entre les champs du formulaire dynamique
 * et les colonnes fixes de la table etik_inscriptions.
 *
 * Stratégie de résolution :
 *   - email      → premier champ de type 'email'
 *   - phone      → premier champ de type 'tel'
 *   - first_name → champ de type 'text' dont le label matche prénom/firstname
 *   - last_name  → champ de type 'text' dont le label matche nom/lastname
 *
 * @param int $form_id  ID du formulaire
 * @return array [
 *   'email'      => 'field_3',   // field_key réel dans $_POST
 *   'first_name' => 'field_2',
 *   'last_name'  => 'field_1',
 *   'phone'      => 'field_4',
 * ]
 */
function lwe_resolve_field_map( int $form_id = 0 ) : array {

    $map = [
        'email'      => null,
        'first_name' => null,
        'last_name'  => null,
        'phone'      => null,
    ];

    if ( $form_id <= 0 ) {
        // Formulaire par défaut sans Form Builder → noms fixes attendus
        return [
            'email'      => 'email',
            'first_name' => 'first_name',
            'last_name'  => 'last_name',
            'phone'      => 'phone',
        ];
    }

    if ( ! class_exists( 'WP_Etik\\Form_Manager' ) ) {
        require_once WP_ETIK_PLUGIN_DIR . 'src/Form_Manager.php';
    }

    $fields = Form_Manager::get_fields( $form_id );
    if ( empty( $fields ) ) {
        return $map;
    }

    // ── 1. Résolution par TYPE unique (email, tel) ──────────────────────
    foreach ( $fields as $f ) {
        $type = strtolower( $f['type'] ?? '' );
        $key  = $f['field_key'] ?? '';

        if ( $type === 'email' && $map['email'] === null ) {
            $map['email'] = $key;
        }
        if ( $type === 'tel' && $map['phone'] === null ) {
            $map['phone'] = $key;
        }
    }

    // ── 2. Résolution par LABEL pour les champs text (prénom, nom) ──────
    $first_name_aliases = [ 'prenom', 'prénom', 'firstname', 'first_name', 'first name' ];
    $last_name_aliases  = [ 'nom', 'lastname', 'last_name', 'last name', 'nom de famille', 'family name' ];

    foreach ( $fields as $f ) {
        $type  = strtolower( $f['type'] ?? '' );
        $key   = $f['field_key'] ?? '';
        $label = mb_strtolower( trim( $f['label'] ?? '' ) );

        if ( $type !== 'text' ) {
            continue;
        }

        $label_norm = _lwe_remove_accents( $label );

        // ── Prénom ──
        if ( $map['first_name'] === null ) {
            foreach ( $first_name_aliases as $alias ) {
                $alias_norm = _lwe_remove_accents( $alias );
                if ( $label_norm === $alias_norm || strpos( $label_norm, $alias_norm ) !== false ) {
                    $map['first_name'] = $key;
                    break;
                }
            }
        }

        // ── Nom ──
        // "nom" est court → match exact uniquement pour éviter que "prénom" matche
        if ( $map['last_name'] === null ) {
            foreach ( $last_name_aliases as $alias ) {
                $alias_norm = _lwe_remove_accents( $alias );
                if ( $alias_norm === 'nom' ) {
                    if ( $label_norm === 'nom' ) {
                        $map['last_name'] = $key;
                        break;
                    }
                } elseif ( $label_norm === $alias_norm || strpos( $label_norm, $alias_norm ) !== false ) {
                    $map['last_name'] = $key;
                    break;
                }
            }
        }
    }

    return $map;
}

/**
 * Supprime les accents d'une chaîne pour comparaison.
 */
function _lwe_remove_accents( string $str ) : string {
    if ( function_exists( 'remove_accents' ) ) {
        return mb_strtolower( remove_accents( $str ) );
    }
    $map = [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o',
        'ç' => 'c',
    ];
    return strtr( $str, $map );
}

/**
 * Récupère et sanitize une valeur POST par son field_key.
 *
 * @param string|null $field_key  Nom du champ dans $_POST (null si non résolu)
 * @param string      $type       Type attendu (email, text, tel, etc.)
 * @return string
 */
function lwe_get_post_field( ?string $field_key, string $type = 'text' ) : string {
    if ( ! $field_key || ! isset( $_POST[ $field_key ] ) ) {
        return '';
    }

    $raw = wp_unslash( $_POST[ $field_key ] );

    switch ( $type ) {
        case 'email':
            return sanitize_email( $raw );
        case 'tel':
            return sanitize_text_field( preg_replace( '/[^\d\s\+\-\.\(\)]/', '', $raw ) );
        case 'number':
            return is_numeric( $raw ) ? (string) $raw : '';
        default:
            return sanitize_text_field( $raw );
    }
}

/**
 * Collecte tous les champs POST du formulaire qui ne correspondent
 * PAS aux colonnes fixes. Retourne un tableau field_key => { label, value }.
 *
 * @param array $mapped_keys  Valeurs de lwe_resolve_field_map() (les field_keys mappés)
 * @param int   $form_id      ID du formulaire
 * @return array
 */
function lwe_collect_custom_fields( array $mapped_keys, int $form_id = 0 ) : array {

    $custom = [];

    if ( $form_id <= 0 ) {
        return $custom;
    }

    if ( ! class_exists( 'WP_Etik\\Form_Manager' ) ) {
        require_once WP_ETIK_PLUGIN_DIR . 'src/Form_Manager.php';
    }

    $fields    = Form_Manager::get_fields( $form_id );
    $skip_keys = array_filter( $mapped_keys ); // retirer les null

    foreach ( $fields as $f ) {
        $key  = $f['field_key'] ?? '';
        $type = $f['type']      ?? 'text';

        if ( ! $key || in_array( $type, [ 'html' ], true ) ) {
            continue;
        }

        if ( in_array( $key, $skip_keys, true ) ) {
            continue;
        }

        if ( ! isset( $_POST[ $key ] ) ) {
            continue;
        }

        $label = $f['label'] ?? $key;
        $raw   = wp_unslash( $_POST[ $key ] );

        switch ( $type ) {
            case 'checkbox':
            case 'consent':
                $custom[ $key ] = [
                    'label' => $label,
                    'value' => ! empty( $raw ) ? '1' : '0',
                ];
                break;

            case 'checkbox_group':
                $custom[ $key ] = [
                    'label' => $label,
                    'value' => is_array( $raw )
                        ? array_map( 'sanitize_text_field', $raw )
                        : [ sanitize_text_field( $raw ) ],
                ];
                break;

            default:
                $custom[ $key ] = [
                    'label' => $label,
                    'value' => sanitize_text_field( $raw ),
                ];
                break;
        }
    }

    return $custom;
}

/**
 * Valide les champs requis du formulaire dynamique.
 * Retourne null si tout est OK, sinon un tableau d'erreur.
 *
 * @param int   $form_id    ID du formulaire
 * @return array|null  Null si OK, sinon [ 'code', 'message', 'field' ]
 */
function lwe_validate_required_fields( int $form_id ) : ?array {

    if ( $form_id <= 0 ) {
        return null;
    }

    if ( ! class_exists( 'WP_Etik\\Form_Manager' ) ) {
        require_once WP_ETIK_PLUGIN_DIR . 'src/Form_Manager.php';
    }

    $fields = Form_Manager::get_fields( $form_id );

    foreach ( $fields as $f ) {
        $key      = $f['field_key'] ?? '';
        $type     = $f['type']      ?? 'text';
        $label    = $f['label']     ?? $key;
        $required = ! empty( $f['required'] );

        if ( ! $required || ! $key || in_array( $type, [ 'html' ], true ) ) {
            continue;
        }

        $value = $_POST[ $key ] ?? '';
        if ( is_string( $value ) ) {
            $value = trim( $value );
        }

        $is_empty = false;

        if ( $type === 'checkbox' || $type === 'consent' ) {
            $is_empty = empty( $value );
        } elseif ( $type === 'checkbox_group' ) {
            $is_empty = empty( $value ) || ( is_array( $value ) && count( $value ) === 0 );
        } elseif ( $type === 'email' ) {
            $is_empty = ! is_email( sanitize_email( $value ) );
        } else {
            $is_empty = $value === '';
        }

        if ( $is_empty ) {
            return [
                'code'    => 'missing_field',
                'message' => sprintf( 'Le champ « %s » est obligatoire.', $label ),
                'field'   => $key,
            ];
        }
    }

    return null;
}

/**
 * Chiffre les données sensibles d'une inscription avant stockage.
 */
function lwe_encrypt_inscription_data( array $data ) : array {
    if ( ! class_exists( '\\WP_Etik\\Encryption' ) ) {
        require_once WP_ETIK_PLUGIN_DIR . 'src/Encryption.php';
    }

    $fields_to_encrypt = [ 'email', 'first_name', 'last_name', 'phone', 'custom_data' ];

    foreach ( $fields_to_encrypt as $key ) {
        if ( ! isset( $data[ $key ] ) || $data[ $key ] === '' || $data[ $key ] === null ) {
            continue;
        }
        try {
            $enc = Encryption::encrypt( (string) $data[ $key ] );
            $data[ $key ] = $enc['ciphertext'];
        } catch ( \Exception $e ) {
            error_log( '[WP_ETIK] Encryption failed for ' . $key . ': ' . $e->getMessage() );
        }
    }

    return $data;
}

/**
 * Déchiffre les données sensibles d'une inscription.
 */
function lwe_decrypt_inscription_data( array $row ) : array {
    if ( ! class_exists( '\\WP_Etik\\Encryption' ) ) {
        require_once WP_ETIK_PLUGIN_DIR . 'src/Encryption.php';
    }

    $fields_to_decrypt = [ 'email', 'first_name', 'last_name', 'phone', 'custom_data' ];

    foreach ( $fields_to_decrypt as $key ) {
        if ( ! isset( $row[ $key ] ) || $row[ $key ] === '' || $row[ $key ] === null ) {
            continue;
        }
        try {
            $row[ $key ] = Encryption::decrypt( $row[ $key ] );
        } catch ( \Exception $e ) {
            // Donnée pas chiffrée (migration) → on la laisse
        }
    }

    return $row;
}

/**
 * Hash de recherche pour l'email (permet de chercher sans déchiffrer).
 */
function lwe_email_search_hash( string $email ) : string {
    $normalized = mb_strtolower( trim( $email ) );
    if ( class_exists( '\\WP_Etik\\Encryption' ) ) {
        // Utiliser la méthode de hash de Encryption si elle existe
        if ( method_exists( Encryption::class, 'hash_for_search' ) ) {
            return Encryption::hash_for_search( $normalized );
        }
    }
    // Fallback : HMAC avec AUTH_KEY pour ne pas être inversible
    return hash_hmac( 'sha256', $normalized, AUTH_KEY );
}