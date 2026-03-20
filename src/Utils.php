<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/**
 * Classe utilitaire pour les fonctions réutilisables
 */
class Utils {

    /**
     * Envoie une réponse JSON d'erreur
     *
     * @param string $message
     * @param int $code
     */
    public static function send_json_error($message, $code = 400) {
        wp_send_json_error(['message' => $message], $code);
    }

    /**
     * Envoie une réponse JSON de succès
     *
     * @param array $data
     */
    public static function send_json_success($data = []) {
        wp_send_json_success($data);
    }

    /**
     * Valide un email
     *
     * @param string $email
     * @return bool
     */
    public static function is_valid_email($email) {
        return is_email($email);
    }

    /**
     * Valide un ID numérique
     *
     * @param mixed $id
     * @return bool
     */
    public static function is_valid_id($id) {
        return is_numeric($id) && intval($id) > 0;
    }

    /**
     * Récupère une valeur POST avec validation
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function get_post_value($key, $default = '') {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
    }

    /**
     * Vérifie un nonce
     *
     * @param string $nonce
     * @param string $action
     * @return bool
     */
    public static function verify_nonce($nonce, $action) {
        return wp_verify_nonce($nonce, $action);
    }

    /**
     * Log une erreur
     *
     * @param string $message
     */
    public static function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP_ETIK] ' . $message);
        }
    }

    /**
     * Cache une requête
     *
     * @param string $key
     * @param callable $callback
     * @param int $expiration
     * @return mixed
     */
    public static function cache_query($key, $callback, $expiration = 300) {
        $data = wp_cache_get($key);
        if ($data === false) {
            $data = $callback();
            wp_cache_set($key, $data, '', $expiration);
        }
        return $data;
    }
}