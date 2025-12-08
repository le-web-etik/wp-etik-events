<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/*
// chiffrer
$enc = \WP_Etik\Encryption::encrypt('sk_test_...');
// $enc['ciphertext'] => stocker en option

// déchiffrer
$secret = \WP_Etik\Encryption::decrypt( get_option('lwe_stripe_secret_enc') );

// hash pour recherche
$email_hash = \WP_Etik\Encryption::hash_for_search('Adrien@example.com');
*/

/**
 * Encryption helper
 *
 * - AES-256-CBC + HMAC-SHA256
 * - encrypt(string) => ['ciphertext' => base64(iv . hmac . cipher)]
 * - decrypt(string) => plaintext
 * - hash_for_search(string) => sha256(lower(trim(value)))
 *
 * Dépend d'une constante WP_ETIK_ENC_KEY (recommandé) ou AUTH_KEY en fallback.
 */
class Encryption {

    /**
     * Retourne la clé brute 32 bytes (sha256)
     *
     * @return string
     * @throws \Exception
     */
    private static function get_key_raw() : string {
        if ( defined('WP_ETIK_ENC_KEY') && WP_ETIK_ENC_KEY ) {
            return hash('sha256', WP_ETIK_ENC_KEY, true);
        }
        if ( defined('AUTH_KEY') && AUTH_KEY ) {
            return hash('sha256', AUTH_KEY, true);
        }
        throw new \Exception('Encryption key not defined. Define WP_ETIK_ENC_KEY in wp-config.php');
    }

    /**
     * Chiffre une chaîne et retourne le payload encodé en base64
     *
     * @param string $plaintext
     * @return array{ciphertext:string}
     * @throws \Exception
     */
    public static function encrypt( string $plaintext ) : array {
        $key = self::get_key_raw();
        $ivlen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($ivlen);
        $cipher_raw = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher_raw === false) {
            throw new \Exception('Encryption failed.');
        }
        $hmac = hash_hmac('sha256', $cipher_raw, $key, true);
        $payload = base64_encode($iv . $hmac . $cipher_raw);
        return ['ciphertext' => $payload];
    }

    /**
     * Déchiffre un payload encodé par encrypt()
     *
     * @param string $payload_base64
     * @return string
     * @throws \Exception
     */
    public static function decrypt( string $payload_base64 ) : string {
        $key = self::get_key_raw();
        $c = base64_decode($payload_base64, true);
        if ($c === false) {
            throw new \Exception('Invalid ciphertext.');
        }
        $ivlen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($c, 0, $ivlen);
        $hmac = substr($c, $ivlen, 32); // 32 bytes raw
        $cipher_raw = substr($c, $ivlen + 32);
        $plain = openssl_decrypt($cipher_raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \Exception('Decryption failed.');
        }
        $calcmac = hash_hmac('sha256', $cipher_raw, $key, true);
        if (!hash_equals($hmac, $calcmac)) {
            throw new \Exception('Data integrity check failed.');
        }
        return $plain;
    }

    /**
     * Hash non réversible pour recherche (ex: email)
     *
     * @param string $value
     * @return string
     */
    public static function hash_for_search( string $value ) : string {
        return hash('sha256', mb_strtolower(trim($value)));
    }
}
