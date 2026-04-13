<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/**
 * Gestionnaire unique des contacts clients.
 * Aucun compte WP n'est créé. Tout est stocké chiffré dans wp_etik_users.
 */
class Etik_User_Manager {

    private const TABLE = 'etik_users';

    /**
     * Trouve un utilisateur par email ou le crée.
     * Garantit l'unicité grâce au hash.
     * 
     * @param array $data ['email' => '', 'first_name' => '', 'last_name' => '', 'phone' => '', 'meta' => []]
     * @return int ID du contact (etik_user_id)
     */
    public static function find_or_create(array $data): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $email = trim(strtolower(sanitize_email($data['email'] ?? '')));
        if (!is_email($email)) {
            Utils::log('[Etik_User_Manager] Email invalide.');
            return 0;
        }

        $hash = self::hash_email($email);

        // 1. Recherche existant
        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email_hash = %s LIMIT 1",
            $hash
        ));

        if ($existing_id > 0) {
            // Optionnel : Mettre à jour les infos si elles ont changé (nom, tel)
            // self::update($existing_id, $data); 
            return $existing_id;
        }

        // 2. Création
        $inserted = $wpdb->insert($table, [
            'email_hash'     => $hash,
            'email_enc'      => self::enc($email),
            'first_name_enc' => self::enc(sanitize_text_field($data['first_name'] ?? '')),
            'last_name_enc'  => self::enc(sanitize_text_field($data['last_name'] ?? '')),
            'phone_enc'      => self::enc(sanitize_text_field($data['phone'] ?? '')),
            'meta_enc'       => !empty($data['meta']) ? self::enc(wp_json_encode($data['meta'])) : null,
            'created_at'     => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        if (!$inserted) {
            Utils::log('[Etik_User_Manager] Échec INSERT: ' . $wpdb->last_error);
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Récupère les données déchiffrées d'un contact.
     */
    public static function get(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);

        return $row ? self::decrypt_row($row) : null;
    }

    /**
     * Récupère l'historique complet (inscriptions + réservations) d'un contact.
     */
    public static function get_history(int $etik_user_id): array {
        global $wpdb;

        $inscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, e.post_title as event_title 
             FROM {$wpdb->prefix}etik_inscriptions i
             LEFT JOIN {$wpdb->posts} e ON i.event_id = e.ID
             WHERE i.etik_user_id = %d
             ORDER BY i.registered_at DESC",
            $etik_user_id
        ), ARRAY_A) ?: [];

        $reservations = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title as prestation_title 
             FROM {$wpdb->prefix}etik_reservations r
             LEFT JOIN {$wpdb->posts} p ON r.prestation_id = p.ID
             WHERE r.etik_user_id = %d
             ORDER BY r.created_at DESC",
            $etik_user_id
        ), ARRAY_A) ?: [];

        return ['inscriptions' => $inscriptions, 'reservations' => $reservations];
    }

    /**
     * Demande de suppression RGPD.
     * Enregistre la date et notifie l'admin. Ne confirme pas l'existence de l'email au front.
     */
    public static function request_rgpd_deletion(string $email): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $hash = self::hash_email(trim(strtolower(sanitize_email($email))));

        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email_hash = %s LIMIT 1", $hash
        ));

        if ($id > 0) {
            // Marquer la demande
            $wpdb->update($table, ['rgpd_request_at' => current_time('mysql')], ['id' => $id], ['%s'], ['%d']);

            // Notifier l'admin
            $admin_email = get_option('admin_email');
            $site_name = get_bloginfo('name');
            $admin_url = admin_url('edit.php?post_type=etik_event&page=wp-etik-contacts&etik_uid=' . $id);

            $subject = "[{$site_name}] Demande suppression RGPD - Contact #{$id}";
            $message = "Une demande de suppression a été reçue pour le contact #{$id}.\n\n";
            $message .= "Action requise : Connexion admin -> Suppression manuelle.\n";
            $message .= "Lien direct : {$admin_url}";

            wp_mail($admin_email, $subject, $message);
        }
        // Si l'email n'existe pas, on ne fait rien (sécurité : ne pas révéler la BDD).
    }

    /**
     * Suppression définitive par l'admin.
     * Anonymise les tables liées puis supprime le contact.
     */
    public static function admin_delete(int $id): bool {
        global $wpdb;

        // 1. Anonymiser inscriptions
        $wpdb->update(
            $wpdb->prefix . 'etik_inscriptions',
            [
                'email' => '[SUPPRIMÉ]', 'email_hash' => '', 
                'first_name' => '[SUPPRIMÉ]', 'last_name' => '', 'phone' => '',
                'custom_data' => null, 'etik_user_id' => null
            ],
            ['etik_user_id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d'],
            ['%d']
        );

        // 2. Anonymiser réservations
        $wpdb->update(
            $wpdb->prefix . 'etik_reservations',
            [
                'email' => '[SUPPRIMÉ]', 'first_name' => '[SUPPRIMÉ]', 
                'last_name' => '', 'phone' => '', 'form_data' => null, 'etik_user_id' => null
            ],
            ['etik_user_id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%d'],
            ['%d']
        );

        // 3. Supprimer le contact
        return (bool) $wpdb->delete($wpdb->prefix . self::TABLE, ['id' => $id], ['%d']);
    }

    // --- Helpers Chiffrement ---

    private static function enc(string $value): string {
        if ($value === '') return '';
        if (!class_exists('WP_Etik\Encryption')) return $value; // Fallback
        try {
            return Encryption::encrypt($value)['ciphertext'];
        } catch (\Exception $e) {
            Utils::log('Encryption error: ' . $e->getMessage());
            return $value;
        }
    }

    private static function hash_email(string $email): string {
        // Utilise la fonction existante si dispo, sinon HMAC simple
        if (function_exists('WP_Etik\lwe_email_search_hash')) {
            return \WP_Etik\lwe_email_search_hash($email);
        }
        return hash_hmac('sha256', strtolower(trim($email)), defined('AUTH_KEY') ? AUTH_KEY : 'etik_fallback_key');
    }

    private static function decrypt_row(array $row): array {
        $keys = ['email' => 'email_enc', 'first_name' => 'first_name_enc', 'last_name' => 'last_name_enc', 'phone' => 'phone_enc'];
        foreach ($keys as $clean => $enc) {
            $row[$clean] = isset($row[$enc]) ? self::dec($row[$enc]) : '';
        }
        
        if (!empty($row['meta_enc'])) {
            $row['meta'] = json_decode(self::dec($row['meta_enc']), true) ?: [];
        }
        return $row;
    }

    private static function dec(string $value): string {
        if ($value === '') return '';
        if (!class_exists('WP_Etik\Encryption')) return $value;
        try {
            return Encryption::decrypt($value);
        } catch (\Exception $e) {
            return $value; // Si échec (ex: donnée non chiffrée ancienne), on retourne tel quel
        }
    }
}