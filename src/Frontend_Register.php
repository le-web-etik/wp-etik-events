<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

class Frontend_Register {
    public function init() {
        add_shortcode('etik_register_form', [$this, 'render_form']);
        add_action('init', [$this, 'maybe_handle_registration']);
    }

    public function render_form($atts = []) {
        if (is_user_logged_in()) return '<p>' . __('Vous êtes déjà connecté','wp-etik-events') . '</p>';
        ob_start(); ?>
        <form method="post" class="etik-register">
            <p><label><?php _e('Prénom','wp-etik-events'); ?><br><input type="text" name="first_name" required></label></p>
            <p><label><?php _e('Nom','wp-etik-events'); ?><br><input type="text" name="last_name" required></label></p>
            <p><label><?php _e('Email','wp-etik-events'); ?><br><input type="email" name="user_email" required></label></p>
            <p><label><?php _e('Téléphone mobile','wp-etik-events'); ?><br><input type="text" name="telephone_mobile" required></label></p>
            <input type="hidden" name="etik_register_nonce" value="<?php echo wp_create_nonce('etik_register'); ?>">
            <p><button type="submit" name="etik_register"><?php _e('S\'inscrire','wp-etik-events'); ?></button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    public function maybe_handle_registration() {
        if (!isset($_POST['etik_register'])) return;
        if (!wp_verify_nonce($_POST['etik_register_nonce'] ?? '', 'etik_register')) return;

        $email = sanitize_email($_POST['user_email']);
        $first = sanitize_text_field($_POST['first_name']);
        $last = sanitize_text_field($_POST['last_name']);
        $phone = sanitize_text_field($_POST['telephone_mobile']);

        if (email_exists($email)) {
            // simple error message; you can replace with better UX
            wp_die(__('Email déjà utilisé','wp-etik-events'));
        }

        $base = sanitize_user( current( explode('@', $email ) ) );
        $username = $base . '_' . wp_generate_password(4,false,false);
        $password = wp_generate_password(12, false);

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_die($user_id->get_error_message());
        }

        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first,
            'last_name' => $last,
        ]);

        $user = new \WP_User($user_id);
        $user->set_role('client_web_etik');

        update_user_meta($user_id, 'telephone_mobile', $phone);
        update_user_meta($user_id, 'hebergement_actif', 'non');
        update_user_meta($user_id, 'date_abonnement', '');
        update_user_meta($user_id, 'nb_sites_web', 0);

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        wp_redirect(site_url('/merci-inscription'));
        exit;
    }
}
