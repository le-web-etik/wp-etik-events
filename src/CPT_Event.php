<?php
namespace WP_Etik;

class CPT_Event {
    public function register() {
        $labels = [
            'name' => __('Événements','wp-etik-events'),
            'singular_name' => __('Événement','wp-etik-events'),
            'add_new_item' => __('Ajouter un événement','wp-etik-events'),
            'edit_item' => __('Éditer événement','wp-etik-events'),
        ];

        $args = [
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'supports' => ['title','editor','thumbnail','excerpt'],
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'evenement'],
            'menu_position' => 5,
            'menu_icon' => 'dashicons-calendar-alt',
        ];

        register_post_type('etik_event', $args);
    }
}
