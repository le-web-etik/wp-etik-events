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
    $wpdb->prefix . 'etik_inscriptions',
    $wpdb->prefix . 'etik_prestation_slots',
    $wpdb->prefix . 'etik_reservations',
    $wpdb->prefix . 'etik_prestation_closures',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Supprimer les options
delete_option( 'etik_plugin_activated' );
delete_option( 'wp_etik_payment_return_url' );
delete_option( 'wp_etik_payment_page_id' );
delete_option( 'wp_etik_settings' );
delete_option( 'wp_etik_hcaptcha_sitekey' );
delete_option( 'wp_etik_hcaptcha_secret' );

// Supprimer les rôles
remove_role( 'client_web_etik' );

// Supprimer les CPT
// (WordPress les supprime automatiquement si tu as utilisé `register_post_type` avec 'public' => true)

// Flush rewrite rules
flush_rewrite_rules();