<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

/**
 * Shortcode: [etik_register_form]
 * Enregistre les infos dans wp_etik_users (chiffré). Pas de compte WP.
 */
class Frontend_Register {
    public function init() {
        add_shortcode('etik_register_form', [$this, 'render_form']);
        add_action('init', [$this, 'handle_submit']);
    }

    public function render_form() {
        if (isset($_GET['etik_registered']) && $_GET['etik_registered'] == '1') {
            return '<div class="etik-success">Vos coordonnées ont été enregistrées avec succès.</div>';
        }

        ob_start(); ?>
        <form method="post" class="etik-simple-form">
            <?php wp_nonce_field('etik_register_action', 'etik_register_nonce'); ?>
            
            <p>
                <label>Prénom *<br>
                <input type="text" name="first_name" required></label>
            </p>
            <p>
                <label>Nom<br>
                <input type="text" name="last_name"></label>
            </p>
            <p>
                <label>Email *<br>
                <input type="email" name="email" required></label>
            </p>
            <p>
                <label>Téléphone<br>
                <input type="tel" name="phone"></label>
            </p>
            
            <p><button type="submit" name="etik_register_submit">Enregistrer</button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_submit() {
        if (!isset($_POST['etik_register_submit'])) return;
        if (!wp_verify_nonce($_POST['etik_register_nonce'], 'etik_register_action')) {
            wp_die('Erreur de sécurité');
        }

        $data = [
            'email'      => sanitize_email($_POST['email']),
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name'  => sanitize_text_field($_POST['last_name']),
            'phone'      => sanitize_text_field($_POST['phone']),
        ];

        if (is_email($data['email']) && !empty($data['first_name'])) {
            Etik_User_Manager::find_or_create($data);
            
            // Redirection pour éviter resoumission
            wp_redirect(add_query_arg('etik_registered', '1', get_permalink()));
            exit;
        }
    }
}