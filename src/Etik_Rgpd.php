<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/**
 * Shortcode: [etik_rgpd_request]
 * Permet à un utilisateur de demander la suppression de ses données.
 */
class Etik_Rgpd {
    public function init() {
        add_shortcode('etik_rgpd_request', [$this, 'render_form']);
        add_action('init', [$this, 'handle_submit']);
    }

    public function render_form() {
        if (isset($_GET['rgpd_sent']) && $_GET['rgpd_sent'] == '1') {
            return '<div class="etik-rgpd-msg">Si votre adresse existe dans notre base, une demande de suppression a été transmise à l\'administrateur. Vous recevrez une confirmation une fois le traitement manuel effectué.</div>';
        }

        ob_start(); ?>
        <div class="etik-rgpd-box">
            <h3>Suppression de mes données (RGPD)</h3>
            <p>Conformément au RGPD, vous pouvez demander l'effacement de vos données personnelles. Cette action est irréversible.</p>
            
            <form method="post">
                <?php wp_nonce_field('etik_rgpd_action', 'etik_rgpd_nonce'); ?>
                <p>
                    <label>Votre adresse email *<br>
                    <input type="email" name="rgpd_email" required style="width:100%; max-width:300px;"></label>
                </p>
                <p><button type="submit" name="etik_rgpd_submit">Demander la suppression</button></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_submit() {
        if (!isset($_POST['etik_rgpd_submit'])) return;
        if (!wp_verify_nonce($_POST['etik_rgpd_nonce'], 'etik_rgpd_action')) {
            wp_die('Erreur de sécurité');
        }

        $email = sanitize_email($_POST['rgpd_email']);
        if (is_email($email)) {
            Etik_User_Manager::request_rgpd_deletion($email);
        }

        wp_redirect(add_query_arg('rgpd_sent', '1', get_permalink()));
        exit;
    }
}