<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

if ( ! class_exists(__NAMESPACE__ . '\\Etik_Modal_Manager') ) {

class Etik_Modal_Manager {
    protected static $needed = false;

    public static function mark_needed() {
        self::$needed = true;
    }

    public static function is_needed() {
        return self::$needed === true;
    }

    // Enqueue uniquement si demandé
    public static function maybe_enqueue_assets() {
        if ( ! self::is_needed() ) return;

        $dir_url  = WP_ETIK_PLUGIN_URL;
        $dir_path = WP_ETIK_PLUGIN_URL;

        // CSS du modal (léger)
        wp_enqueue_style('wp-etik-modal', $dir_url . 'assets/css/etik-modal.css', [], filemtime($dir_path . 'assets/css/etik-modal.css'));
      

        // Script jQuery (votre fichier)
        wp_register_script('wp_etik_inscription_js', $dir_url . 'assets/js/etik-inscription.js', ['jquery'], filemtime($dir_path . 'assets/js/etik-inscription.js'), true);
        wp_localize_script('wp_etik_inscription_js', 'WP_ETIK_AJAX', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wp_etik_inscription_nonce'),
            'hcaptcha_sitekey' => get_option('wp_etik_hcaptcha_sitekey', '') // ou constante
        ]);
        wp_enqueue_script('wp_etik_inscription_js');

        if ( get_option('wp_etik_hcaptcha_sitekey') ) {
            wp_enqueue_script('hcaptcha', 'https://hcaptcha.com/1/api.js', [], null, true);
        }


    }

    // Injecter le modal juste avant </body>
    public static function render_footer_modal() {
        if ( ! self::is_needed() ) return;
        ?>
        <div class="etik-modal" id="etik-global-modal" aria-hidden="true">
          <div class="etik-modal-backdrop" data-modal-close></div>
          <div class="etik-modal-dialog" role="dialog" aria-modal="true">
            <button class="etik-modal-close" data-modal-close aria-label="Fermer">&times;</button>
            <div class="etik-modal-content">
                <!-- dans render_footer_modal() -->
                <div class="et_pb_text_2">
                    <h3 id="etik-modal-title">Inscription à la formation</h3>
                </div>

                <div class="etik-panels">
                    <div class="etik-panel active" data-panel="insc">
                        <form class="etik-insc-form">
                            <div class="etik-modal-content" >
                                <!--<input type="hidden" name="action" value="wp_etik_handle_inscription">-->
                                <input type="hidden" name="action" value="lwe_create_checkout">
                                <input type="hidden" name="event_id" value="">
                                <label>Prénom <i>*</i>
                                    <input name="first_name" type="text" required>
                                </label>
                                <label>Nom
                                    <input name="last_name" type="text">
                                </label>
                                <label>E-mail <i>*</i>
                                    <input name="email" type="email" required>
                                </label>
                                <label>Téléphone <i>*</i>
                                    <input name="phone" type="text" required>
                                </label>
                                <label>Nom de domaine souhaité
                                    <input name="desired_domain" type="text">
                                </label>
                                <label style="display:flex;align-items:center;gap:8px;">
                                    <input name="has_domain" type="checkbox" value="1"> J'ai déjà un nom de domaine
                                </label>
                            </div>

                            <!-- hCaptcha placeholder (optionnel) -->
                            <!--<div class="etik-hcaptcha-placeholder" data-hcaptcha-sitekey=""></div>-->
                            <div class="etik-hcaptcha-placeholder" data-sitekey="2c78b576-80ee-42b8-8700-c4ac6f1652a2"></div>

                            <div class="etik-form-actions">
                                <button type="submit" class="etik-btn">S'inscrire</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="etik-feedback" aria-live="polite" style="display:none"></div>

            </div>
          </div>
        </div>
        <?php
    }
}

// Hooks globaux
add_action('wp_enqueue_scripts', [Etik_Modal_Manager::class, 'maybe_enqueue_assets'], 20);
add_action('wp_footer', [Etik_Modal_Manager::class, 'render_footer_modal'], 99);

}
