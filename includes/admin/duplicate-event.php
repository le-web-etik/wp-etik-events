<?php
namespace WpEtikEvents\Admin;

defined('ABSPATH') || exit;

add_filter('post_row_actions', __NAMESPACE__ . '\\add_duplicate_row_action', 10, 2);
add_action('admin_action_lwe_duplicate_event', __NAMESPACE__ . '\\handle_duplicate_event');

/**
 * Ajoute le lien "Dupliquer" dans la liste des posts (post row actions)
 *
 * @param array   $actions
 * @param WP_Post $post
 * @return array
 */
function add_duplicate_row_action( $actions, $post ) {
    // Adapter le post_type si ton CPT s'appelle différemment (ex: 'lwe_event')
    $target_post_type = 'etik_event'; // <-- adapter si nécessaire

    if ( $post->post_type !== $target_post_type ) {
        return $actions;
    }

    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return $actions;
    }

    $nonce = wp_create_nonce( 'lwe_duplicate_event_' . $post->ID );
    $url = add_query_arg(
        [
            'action' => 'lwe_duplicate_event',
            'post'   => $post->ID,
            '_wpnonce' => $nonce,
        ],
        admin_url( 'admin.php' )
    );

    $actions['lwe_duplicate'] = '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr__( 'Dupliquer', 'wp-etik-events' ) . '">' . esc_html__( 'Dupliquer', 'wp-etik-events' ) . '</a>';
    return $actions;
}

/**
 * Handler admin pour la duplication
 * Gère deux cas :
 *  - si l'événement est un post type WP : duplique post, meta, taxonomies
 *
 * Ne copie pas les inscriptions (table lwe_inscription_event).
 */
function handle_duplicate_event() {
    if ( empty( $_GET['post'] ) ) {
        wp_die( esc_html__( 'ID d\'événement manquant.', 'wp-etik-events' ) );
    }

    $post_id = intval( $_GET['post'] );

    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'lwe_duplicate_event_' . $post_id ) ) {
        wp_die( esc_html__( 'Nonce invalide.', 'wp-etik-events' ) );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'Accès refusé.', 'wp-etik-events' ) );
    }

    // 1) Si le post existe en tant que WP post
    $post = get_post( $post_id );
    if ( $post && ! empty( $post->ID ) ) {
        try {
            $new_post_id = duplicate_wp_post( $post );
            $redirect = admin_url( 'post.php?post=' . $new_post_id . '&action=edit' );
            wp_safe_redirect( $redirect );
            exit;
        } catch ( \Exception $e ) {
            wp_die( esc_html__( 'Erreur lors de la duplication : ', 'wp-etik-events' ) . $e->getMessage() );
        }
    }


    wp_die( esc_html__( 'Type d\'événement non supporté pour duplication.', 'wp-etik-events' ) );
}

/**
 * Duplique un WP post (post + meta + taxonomies)
 *
 * @param WP_Post $post
 * @return int new post ID
 * @throws Exception
 */
function duplicate_wp_post( $post ) {
    // Préparer le nouveau post
    $new_post = [
        'post_title'   => wp_strip_all_tags( sprintf( 'Copie de %s', $post->post_title ) ),
        'post_content' => $post->post_content,
        'post_excerpt' => $post->post_excerpt,
        'post_status'  => 'draft',
        'post_type'    => $post->post_type,
        'post_author'  => get_current_user_id(),
    ];

    // Insert post
    $new_post_id = wp_insert_post( $new_post );
    if ( is_wp_error( $new_post_id ) || ! $new_post_id ) {
        throw new \Exception( 'Impossible de créer le post dupliqué.' );
    }

    // Duplicate post meta (skip protected meta if needed)
    $meta = get_post_meta( $post->ID );
    if ( ! empty( $meta ) ) {
        foreach ( $meta as $meta_key => $values ) {
            // Optionnel : ignorer certains meta keys
            if ( in_array( $meta_key, [ '_edit_lock', '_edit_last' ], true ) ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $new_post_id, $meta_key, maybe_unserialize( $value ) );
            }
        }
    }

    // Duplicate taxonomies
    $taxonomies = get_object_taxonomies( $post->post_type );
    if ( ! empty( $taxonomies ) ) {
        foreach ( $taxonomies as $tax ) {
            $terms = wp_get_object_terms( $post->ID, $tax, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $new_post_id, $terms, $tax );
            }
        }
    }

    return $new_post_id;
}

