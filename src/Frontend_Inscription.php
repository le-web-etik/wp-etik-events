<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

class Frontend_Inscription {
    public function init() {
        add_shortcode('etik_event_register', [$this, 'render_event_registration']);
        add_action('init', [$this, 'handle_event_registration']);
    }

    public function render_event_registration($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'etik_event_register');
        $post_id = intval($atts['id']);
        if (!$post_id) return '';

        $start = get_post_meta($post_id, 'etik_start_date', true);
        $end = get_post_meta($post_id, 'etik_end_date', true);
        $price = get_post_meta($post_id, 'etik_price', true);
        $discount = get_post_meta($post_id, '_etik_discount', true);
        $payment_required = get_post_meta($post_id, '_etik_payment_required', true);

        ob_start();
        if (!is_user_logged_in()) {
            echo '<p>' . __('Vous devez être connecté pour vous inscrire','wp-etik-events') . '</p>';
            return ob_get_clean();
        }
        ?>
        <form method="post" class="etik-event-register">
            <input type="hidden" name="etik_event_id" value="<?php echo esc_attr($post_id); ?>">
            <?php wp_nonce_field('etik_event_register','etik_event_register_nonce'); ?>
            <p><strong><?php echo esc_html(get_the_title($post_id)); ?></strong></p>
            <p><?php _e('Date début','wp-etik-events'); ?>: <?php echo esc_html($start); ?></p>
            <?php if ($price && $payment_required === '1'): ?>
                <p><?php _e('Prix','wp-etik-events'); ?>: <?php echo esc_html($price); ?> €</p>
                <p><button type="submit" name="etik_event_pay"><?php _e('Payer et S\'inscrire','wp-etik-events'); ?></button></p>
            <?php else: ?>
                <p><button type="submit" name="etik_event_free"><?php _e('S\'inscrire gratuitement','wp-etik-events'); ?></button></p>
            <?php endif; ?>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_event_registration() {
        if ( isset($_POST['etik_event_free']) || isset($_POST['etik_event_pay']) ) {
            if (!wp_verify_nonce($_POST['etik_event_register_nonce'] ?? '', 'etik_event_register')) return;
            $post_id = intval($_POST['etik_event_id']);
            $user_id = get_current_user_id();
            if (!$user_id || !$post_id) return;

            $attendees = get_post_meta($post_id, '_etik_attendees', true);
            if (!is_array($attendees)) $attendees = [];
            $attendees[] = $user_id;
            update_post_meta($post_id, '_etik_attendees', array_unique($attendees));

            if (isset($_POST['etik_event_pay'])) {
                wp_redirect(site_url('/checkout-for-event?event=' . $post_id));
                exit;
            }

            wp_redirect(site_url('/inscription-confirmation'));
            exit;
        }
    }
}
