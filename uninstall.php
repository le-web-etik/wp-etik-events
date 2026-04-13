<?php
/**
 * Uninstall script for WP Etik Events
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Supprimer les tables
$tables = [
    // Architecture Chiffrée (Nouveau)
    $wpdb->prefix . 'etik_users',              // Contacts clients (PII chiffrées)
    $wpdb->prefix . 'etik_form_responses',     // Réponses formulaires (JSON chiffré)
    
    // Événements & Inscriptions
    $wpdb->prefix . 'etik_inscriptions',       // Liaison événements
    
    // Prestations & Réservations
    $wpdb->prefix . 'etik_prestation_slots',   // Créneaux horaires
    $wpdb->prefix . 'etik_reservations',       // Réservations
    $wpdb->prefix . 'etik_prestation_closures',// Fermetures exceptionnelles
    
    // Formulaires Dynamiques
    $wpdb->prefix . 'etik_forms',              // Définition des formulaires
    $wpdb->prefix . 'etik_form_fields',        // Champs des formulaires
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// =========================================================================
// 2. SUPPRESSION DES OPTIONS DE CONFIGURATION
// =========================================================================

$options = [
    // État du plugin
    'etik_plugin_activated',
    
    // Clés de Sécurité & Chiffrement (CRITIQUE)
    'etik_hash_key',              // Clé de hachage stable (HMAC)
    'etik_encryption_key',        // Clé de chiffrement (si stockée en option)
    
    // Configuration Paiement (Stripe / Mollie)
    'wp_etik_payment_return_url',
    'wp_etik_payment_page_id',
    'wp_etik_stripe_settings',    // Ancien nom possible
    'wp_etik_mollie_settings',    // Ancien nom possible
    'lwe_stripe_secret_enc',      // Clé Stripe chiffrée (si existante)
    'lwe_stripe_publishable',
    'lwe_stripe_webhook_secret',
    
    // Configuration Sécurité (hCaptcha / reCAPTCHA)
    'wp_etik_hcaptcha_sitekey',
    'wp_etik_hcaptcha_secret',
    'wp_etik_recaptcha_sitekey',
    'wp_etik_recaptcha_secret',
    
    // Réglages Généraux
    'wp_etik_settings',
    'etik_default_form_id',
    
    // Options temporaires ou transients spécifiques
    'etik_transient_cleanup_done',
];

foreach ( $options as $option ) {
    delete_option( $option );
    
    // Nettoyage éventuel pour les réseaux multisites (optionnel, mais propre)
    // delete_site_option( $option ); 
}

// Supprimer les CPT
// (WordPress les supprime automatiquement si tu as utilisé `register_post_type` avec 'public' => true)

// =========================================================================
// 4. NETTOYAGE FINAL
// =========================================================================

// Flush rewrite rules
flush_rewrite_rules();