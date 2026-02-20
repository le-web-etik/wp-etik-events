<?php
namespace WP_Etik;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prestation_Event {

    public function init() {
        add_action( 'init', [ $this, 'register_cpt' ] );
    }

    public function register_cpt() {
        $labels = [
            'name' => 'Prestations',
            'singular_name' => 'Prestation',
            'add_new' => 'Ajouter une prestation',
            'add_new_item' => 'Ajouter une nouvelle prestation',
            'edit_item' => 'Éditer la prestation',
            'new_item' => 'Nouvelle prestation',
            'view_item' => 'Voir la prestation',
            'search_items' => 'Rechercher des prestations',
            'not_found' => 'Aucune prestation trouvée',
            'not_found_in_trash' => 'Aucune prestation dans la corbeille',
            'parent_item_colon' => 'Prestation parente :',
            'menu_name' => 'Prestations',
        ];

        $args = [
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => [ 'slug' => 'prestation' ],
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 20,
            'supports' => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments' ],
            'taxonomies' => [],
            'show_in_rest' => true,
            'rest_base' => 'prestations',
        ];

        register_post_type( 'etik_prestation', $args );
    }
}