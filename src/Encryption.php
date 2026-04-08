<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/**
 * Encryption helper
 *
 * - AES-256-CBC + HMAC-SHA256
 * - encrypt(string) => ['ciphertext' => base64(iv . hmac . cipher)]
 * - decrypt(string) => plaintext
 * - hash_for_search(string) => sha256(lower(trim(value)))
 *
 * Dépend d'une constante WP_ETIK_ENC_KEY (recommandé) ou AUTH_KEY en fallback.
 *
 * Payload chiffré = base64( IV[16] . HMAC[32] . ciphertext[>=16] )
 * Taille minimale brute = 64 bytes → ~88 chars en base64.
 */
class Encryption {

    /**
     * Taille minimale d'un payload chiffré valide en base64.
     * 16 (IV) + 32 (HMAC) + 16 (1 bloc AES min) = 64 bytes brut → ceil(64/3)*4 = 88 chars base64.
     */
    private const MIN_PAYLOAD_LENGTH = 80;

    /**
     * Retourne la clé brute 32 bytes (sha256)
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
     * Vérifie si une valeur ressemble à un payload chiffré valide.
     *
     * Un payload chiffré est du base64 pur (pas de sk_live_, pas de whsec_, etc.)
     * et fait au minimum ~88 caractères.
     *
     * @param string $value
     * @return bool
     */
    public static function is_encrypted( string $value ) : bool {
        // Trop court pour être un payload chiffré
        if ( strlen( $value ) < self::MIN_PAYLOAD_LENGTH ) {
            return false;
        }

        // Les clés Stripe/API commencent par des préfixes connus → pas chiffré
        $plain_prefixes = [ 'sk_', 'pk_', 'whsec_', 'rk_', 'sk_test_', 'sk_live_', 'pk_test_', 'pk_live_' ];
        foreach ( $plain_prefixes as $prefix ) {
            if ( strpos( $value, $prefix ) === 0 ) {
                return false;
            }
        }

        // Vérifier que c'est du base64 valide
        if ( base64_decode( $value, true ) === false ) {
            return false;
        }

        // Le payload décodé doit faire au moins 64 bytes (IV + HMAC + 1 bloc)
        $decoded = base64_decode( $value, true );
        if ( strlen( $decoded ) < 64 ) {
            return false;
        }

        return true;
    }

    /**
     * Chiffre une chaîne et retourne le payload encodé en base64.
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
     * Déchiffre un payload encodé par encrypt().
     *
     * Si la valeur ne ressemble pas à un payload chiffré (ex: clé Stripe en clair,
     * valeur stockée avant l'ajout du chiffrement), elle est retournée telle quelle.
     *
     * @param string $payload_base64
     * @return string
     * @throws \Exception  Seulement si le payload SEMBLE chiffré mais est corrompu
     */
    public static function decrypt( string $payload_base64 ) : string {
        // Valeur vide
        if ( $payload_base64 === '' ) {
            return '';
        }

        // Si ça ne ressemble pas à un payload chiffré → retourner tel quel
        if ( ! self::is_encrypted( $payload_base64 ) ) {
            return $payload_base64;
        }

        $key = self::get_key_raw();
        $c = base64_decode($payload_base64, true);
        if ($c === false) {
            throw new \Exception('Invalid ciphertext.');
        }

        $ivlen = openssl_cipher_iv_length('aes-256-cbc');

        // Double vérification de la taille
        if ( strlen($c) < $ivlen + 32 + 16 ) {
            // Payload trop court → probablement pas chiffré, retourner tel quel
            return $payload_base64;
        }

        $iv = substr($c, 0, $ivlen);
        $hmac = substr($c, $ivlen, 32);
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
     */
    public static function hash_for_search( string $value ) : string {
        return hash('sha256', mb_strtolower(trim($value)));
    }
}