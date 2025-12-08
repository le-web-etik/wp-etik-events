<?php
namespace WP_Etik;

if ( ! class_exists('ET_Builder_Module') ) return;

class Divi_Module extends \ET_Builder_Module {
    
    public $slug = 'etk_events';
    public $vb_support = 'on';

    public function init() {
        $this->name = esc_html__('Etik Events','wp-etik-events');
        $this->whitelisted_fields = [
            'title',
            'posts_number',
            'layout',
            'show_image',
            'style_title_color',
            'style_title_size',
            'style_date_color',
            'style_date_size',
            'style_price_color',
            'style_price_size',
            'style_excerpt_color',
            'style_excerpt_size',
            'custom_class',
        ];

        $this->fields_defaults = [
            'posts_number' => ['3'],
            'show_image' => ['on'],
            'layout' => ['grid'],
            'style_title_color' => ['#000000'],
            'style_title_size' => ['18px'],
            'style_date_color' => ['#666666'],
            'style_date_size' => ['14px'],
            'style_price_color' => ['#000000'],
            'style_price_size' => ['16px'],
            'style_excerpt_color' => ['#333333'],
            'style_excerpt_size' => ['14px'],
        ];
        
        // Déclarer l'onglet Style et ses toggles (apparaîtra dans le builder Divi)
        $this->settings_modal_toggles = [
            'general' => [
                'toggles' => [
                    'content' => [
                        'title' => esc_html__('Content', 'wp-etik-events'),
                        'priority' => 10,
                    ],
                ],
            ],
            'style' => [
                'toggles' => [
                    'main_style' => [
                        'title'    => esc_html__('Style', 'wp-etik-events'),
                        'priority' => 10,
                    ],
                    'media' => [
                        'title' => esc_html__('Image', 'wp-etik-events'),
                        'priority' => 20,
                    ],
                    'title' => [
                        'title' => esc_html__('Title', 'wp-etik-events'),
                        'priority' => 30,
                    ],
                ],
            ],
            'advanced' => [
                'toggles' => [
                    'custom_css' => [
                        'title' => esc_html__('Custom CSS', 'wp-etik-events'),
                    ],
                ],
            ],
        ];
    }

    public function get_fields() {
        return [
            'title' => [
                'label' => esc_html__('Titre du bloc','wp-etik-events'),
                'type' => 'text',
                'option_category'  => 'configuration',
            ],
            'posts_number' => [
                'label' => esc_html__('Nombre d\'événements','wp-etik-events'),
                'type' => 'text',
                'option_category'  => 'configuration',
            ],
            'layout' => [
                'label' => esc_html__('Mise en page','wp-etik-events'),
                'type' => 'select',
                'option_category'  => 'configuration',
                'options' => [
                    'grid' => esc_html__('Grille','wp-etik-events'),
                    'list' => esc_html__('Liste','wp-etik-events'),
                ],
            ],
            'show_image' => [
                'label' => esc_html__('Afficher image a la une','wp-etik-events'),
                'type' => 'yes_no_button',
                'option_category'  => 'configuration',
                'options'          => array(
					'on'  => et_builder_i18n( 'Yes' ),
					'off' => et_builder_i18n( 'No' ),
				),
            ],
            'style_title_color' => [
                'label' => esc_html__('Couleur titre','wp-etik-events'),
                'type' => 'color-alpha',
                'custom_color'    => true,
            ],
            'style_title_size' => [
                'label' => esc_html__('Taille titre','wp-etik-events'),
                'type' => 'text',
            ],
            'style_date_color' => [
                'label' => esc_html__('Couleur date','wp-etik-events'),
                'type' => 'color-alpha',
                'custom_color'    => true,
            ],
            'style_date_size' => [
                'label' => esc_html__('Taille date','wp-etik-events'),
                'type' => 'text',
            ],
            'style_price_color' => [
                'label' => esc_html__('Couleur prix','wp-etik-events'),
                'type' => 'color-alpha',
            ],
            'style_price_size' => [
                'label' => esc_html__('Taille prix','wp-etik-events'),
                'type' => 'text',
            ],
            'style_excerpt_color' => [
                'label' => esc_html__('Couleur description','wp-etik-events'),
                'type' => 'color-alpha',
            ],
            'style_excerpt_size' => [
                'label' => esc_html__('Taille description','wp-etik-events'),
                'type' => 'text',
            ],
            'custom_class' => [
                'label' => esc_html__('Classe CSS personnalisée','wp-etik-events'),
                'type' => 'text',
            ],
        ];
    }

    public function render($attrs, $content = null, $render_slug = null) {
        // charge modal et asset
        \WP_Etik\Etik_Modal_Manager::mark_needed();

        $posts_number = intval( $this->props['posts_number'] ?? 3 );
        $layout = $this->props['layout'] ?? 'grid';
        $show_image = ($this->props['show_image'] ?? 'on') === 'on';

        $style_title_color = esc_attr($this->props['style_title_color'] ?? '#000');
        $style_title_size = esc_attr($this->props['style_title_size'] ?? '18px');
        $style_date_color = esc_attr($this->props['style_date_color'] ?? '#0c71c3');
        $style_date_size = esc_attr($this->props['style_date_size'] ?? '14px');
        $style_price_color = esc_attr($this->props['style_price_color'] ?? '#000');
        $style_price_size = esc_attr($this->props['style_price_size'] ?? '16px');
        $style_excerpt_color = esc_attr($this->props['style_excerpt_color'] ?? '#333');
        $style_excerpt_size = esc_attr($this->props['style_excerpt_size'] ?? '14px');
        $custom_class = esc_attr($this->props['custom_class'] ?? '');

        /*$query = new \WP_Query([
            'post_type' => 'etik_event',
            'posts_per_page' => $posts_number,
            'order'     => 'ASC',
            'meta_key' => 'etik_start_date',
            'orderby'   => 'meta_value',
        ]);*/

        $today = current_time( 'Y-m-d' ); // "YYYY-mm-dd"

        $query = new \WP_Query([
            'post_type'      => 'etik_event',
            'posts_per_page' => $posts_number,
            'order'          => 'ASC',
            'meta_key'       => 'etik_start_date',
            'orderby'        => 'meta_value',
            'meta_query'     => [
                [
                    'key'     => 'etik_start_date',
                    'value'   => $today,
                    'compare' => '>',
                    'type'    => 'DATE',
                ],
            ],
        ]);


        $unique = uniqid('etik-events-');

        $output = '<div class="etik-events ' . esc_attr($custom_class) . ' etik-layout-' . esc_attr($layout) . '" id="' . esc_attr($unique) . '">';

        // inline style block scoped to this instance
        $output .= '<style>';
            $output .= '#' . $unique . ' .etik-title{ color:' . $style_title_color . '; font-size:' . $style_title_size . '; }';
            $output .= '#' . $unique . ' .etik-date{ color:' . $style_date_color . '; font-size:' . $style_date_size . '; }';
            $output .= '#' . $unique . ' .etik-price{ color:' . $style_price_color . '; font-size:' . $style_price_size . '; }';
            $output .= '#' . $unique . ' .etik-excerpt{ color:' . $style_excerpt_color . '; font-size:' . $style_excerpt_size . '; }';
        $output .= '</style>';

        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            $title = get_the_title();
            $excerpt = get_the_excerpt();
            $the_content = get_the_content();
            $start = get_post_meta($id, 'etik_start_date', true);
            $price = get_post_meta($id, 'etik_price', true);

            $output .= '<div class="etik-event etik-bis">';

            // GESTION IMAGE
            //if ($show_image && has_post_thumbnail()) {
                //$output .= '<div class="etik-thumb">' . get_the_post_thumbnail($id,'medium') . '</div>';
            //}
            if ($show_image) {
                if ( has_post_thumbnail($id) ) {
                    $thumb_id = get_post_thumbnail_id($id);
                    $src = wp_get_attachment_image_src($thumb_id, 'medium');
                    $img = wp_get_attachment_image($thumb_id, 'large');
                    if ($src && isset($src[0])) {
                        //$output .= '<div class="etik-thumb"><img src="' . esc_url($src[0]) . '" alt="' . esc_attr(get_the_title($id)) . '" /></div>';
                        $output .= '<div class="etik-thumb">'. $img .'</div>';
                        
                    } else {
                        // fallback si wp_get_attachment_image_src échoue
                        $output .= '<div class="etik-thumb"><img src="' . esc_url(get_stylesheet_directory_uri() . '/images/placeholder.png') . '" alt="" /></div>';
                    }
                }
            }
            // body
            $output .= '<div class="etik-body">';

                $output .= '<div class="et_pb_text_2">';
                    $timestamp = strtotime( esc_html($start) ); // ou get_the_date( 'U' ) etc.
                    $output .= '<div class="etik-date">' . date_i18n( 'l j M', $timestamp ) . '</div>';
                $output .= '</div>';

                
                $output .= '<div>';
                    $output .= '<h3 class="etik-title">' . esc_html($title) . '</h3>';
                    
                    $output .= '<div class="etik-price">' . ($price !== '' ? esc_html($price) . '€ ' : '') . '</div>';
                $output .= '</div>';

                $output .= '<div class="etik-excerpt">';
                    $output .= '<div class="etik-excerpt-content">';
                        $output .=  $the_content ;
                    $output .=  '</div>';
                $output .=  '</div>';
            
            
            $output .= '</div>';

            $output .= '<div class="etik-footer">';

            $output .= '<button type="button" class="etik-btn-link" style="display: none;">voir plus</button>';
            // bouton + modal (insérer dans la boucle de render)
            $output .= '<button type="button" class="etik-formation-btn" 
                data-event="'.esc_attr($id).'" 
                data-title="'.esc_attr($title).'" 
                data-bs-target="#etik-global-modal">S\'inscrire</button>';

            /*
            $btn_id = $unique . '-formation-btn-' . get_the_ID();
            $modal_id = $unique . '-formation-modal-' . get_the_ID();
            $nonce = wp_create_nonce('wp_etik_inscription_nonce'); // ou utilisez global nonce localisé

            $output .= '<button type="button" id="'.esc_attr($btn_id).'" class="etik-formation-btn" data-modal="#'.esc_attr($modal_id).'" data-event="'.esc_attr(get_the_ID()).'">S\'inscrire</button>';
            */

            $output .= '</div></div>';

        }

        wp_reset_postdata();
        $output .= '</div>';
        return $output;
    }

    /*public function render($attrs, $content = null, $render_slug = null) {
        error_log('ETIK render start uid:' . uniqid() . ' post_count:' . (isset($this->props['posts_number']) ? intval($this->props['posts_number']) : 0));
        return '<div class="etik-debug">ETIK MODULE RENDER TEST OK</div>';
    }*/
}
