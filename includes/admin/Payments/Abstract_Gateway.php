<?php
namespace WP_Etik\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classe abstraite pour un gateway de paiement
 * Définit l'API minimale : register_settings, render_fields, get_keys, sanitize helpers
 */
abstract class Abstract_Gateway {

    /** identifiant unique du gateway (ex: stripe, mollie) */
    protected string $id;

    /** label affiché dans l'UI */
    protected string $label;

    /** option group partagé */
    protected const OPTION_GROUP = 'lwe_settings';

    public function __construct( string $id, string $label ) {
        $this->id = $id;
        $this->label = $label;
    }

    /** renvoie l'id du gateway */
    public function get_id() : string {
        return $this->id;
    }

    /** Enregistrer les settings (appelé depuis admin_init) */
    abstract public function register_settings() : void;

    /** Rendre les champs HTML (appelé depuis la page de settings) */
    abstract public function render_fields() : void;

    /**
     * Retourne les clés/données déchiffrées pour ce gateway
     * Format libre selon gateway
     *
     * @return array
     */
    abstract public function get_keys() : array;

    /**
     * Helper : encrypt et stocke via WP_Etik\Encryption si disponible
     * Retourne la valeur à stocker (ciphertext) ou valeur existante si erreur/empty
     */
    protected function encrypt_or_keep_existing( string $option_name, string $value ) : string {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return get_option( $option_name, '' );
        }
        $sanitized = sanitize_text_field( $value );
        if ( class_exists( '\\WP_Etik\\Encryption' ) ) {
            try {
                $enc = \WP_Etik\Encryption::encrypt( $sanitized );
                return $enc['ciphertext'];
            } catch ( \Exception $e ) {
                return get_option( $option_name, '' );
            }
        }
        // fallback : ne pas stocker en clair
        return get_option( $option_name, '' );
    }

    /** Helper pour récupérer et déchiffrer une option chiffrée */
    protected function decrypt_option( string $option_name ) : string {
        $enc = get_option( $option_name, '' );
        if ( $enc && class_exists( '\\WP_Etik\\Encryption' ) ) {
            try {
                return (string) \WP_Etik\Encryption::decrypt( $enc );
            } catch ( \Exception $e ) {
                return '';
            }
        }
        return '';
    }
}
