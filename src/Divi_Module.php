<?php
namespace WP_Etik;

defined('ABSPATH') || exit;

if ( ! class_exists('ET_Builder_Module') ) return;

class Divi_Module extends \ET_Builder_Module {

    public $slug       = 'etk_events';
    public $vb_support = 'on';

    public function init() {
        $this->name = esc_html__( 'Etik Events', 'wp-etik-events' );

        $this->whitelisted_fields = [
            'title',
            'posts_number',
            'layout',
            'image_mode',   // ← nouveau : card | full
            'show_image',
            'image_height', // ← nouveau : hauteur en mode card
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
            'posts_number'       => ['3'],
            'show_image'         => ['on'],
            'image_mode'         => ['card'],
            'image_height'       => ['280px'],
            'layout'             => ['grid'],
            'style_title_color'  => ['#000000'],
            'style_title_size'   => ['18px'],
            'style_date_color'   => ['#0c71c3'],
            'style_date_size'    => ['14px'],
            'style_price_color'  => ['#FC6B0D'],
            'style_price_size'   => ['16px'],
            'style_excerpt_color'=> ['#333333'],
            'style_excerpt_size' => ['14px'],
        ];

        $this->settings_modal_toggles = [
            'general' => [
                'toggles' => [
                    'content' => [
                        'title'    => esc_html__( 'Contenu', 'wp-etik-events' ),
                        'priority' => 10,
                    ],
                ],
            ],
            'style' => [
                'toggles' => [
                    'main_style' => [
                        'title'    => esc_html__( 'Style', 'wp-etik-events' ),
                        'priority' => 10,
                    ],
                    'media' => [
                        'title'    => esc_html__( 'Image', 'wp-etik-events' ),
                        'priority' => 20,
                    ],
                    'title' => [
                        'title'    => esc_html__( 'Titre', 'wp-etik-events' ),
                        'priority' => 30,
                    ],
                ],
            ],
            'advanced' => [
                'toggles' => [
                    'custom_css' => [
                        'title' => esc_html__( 'CSS personnalisé', 'wp-etik-events' ),
                    ],
                ],
            ],
        ];
    }

    public function get_fields() {
        return [
            // ── Contenu ───────────────────────────────────────────────────────
            'title' => [
                'label'            => esc_html__( 'Titre du bloc', 'wp-etik-events' ),
                'type'             => 'text',
                'option_category'  => 'configuration',
            ],
            'posts_number' => [
                'label'            => esc_html__( "Nombre d'événements", 'wp-etik-events' ),
                'type'             => 'text',
                'option_category'  => 'configuration',
            ],
            'layout' => [
                'label'            => esc_html__( 'Mise en page', 'wp-etik-events' ),
                'type'             => 'select',
                'option_category'  => 'configuration',
                'options'          => [
                    'grid' => esc_html__( 'Grille', 'wp-etik-events' ),
                    'list' => esc_html__( 'Liste',  'wp-etik-events' ),
                ],
            ],

            // ── Image ─────────────────────────────────────────────────────────
            'show_image' => [
                'label'            => esc_html__( "Afficher l'image à la une", 'wp-etik-events' ),
                'type'             => 'yes_no_button',
                'option_category'  => 'configuration',
                'options'          => [
                    'on'  => et_builder_i18n( 'Yes' ),
                    'off' => et_builder_i18n( 'No' ),
                ],
            ],
            'image_mode' => [
                'label'            => esc_html__( "Mode d'affichage image", 'wp-etik-events' ),
                'type'             => 'select',
                'option_category'  => 'configuration',
                'options'          => [
                    'card' => esc_html__( 'Carte (image + contenu)', 'wp-etik-events' ),
                    'full' => esc_html__( 'Full Picture (image seule, clic = inscription)', 'wp-etik-events' ),
                ],
                'description'      => esc_html__( 'En mode Full Picture, seule l\'image s\'affiche. Un clic dessus ouvre directement le formulaire d\'inscription.', 'wp-etik-events' ),
            ],
            'image_height' => [
                'label'            => esc_html__( 'Hauteur image (ex: 280px, 40vh)', 'wp-etik-events' ),
                'type'             => 'text',
                'option_category'  => 'configuration',
                'description'      => esc_html__( 'S\'applique en mode Carte et Full Picture.', 'wp-etik-events' ),
            ],

            // ── Style ─────────────────────────────────────────────────────────
            'style_title_color' => [
                'label'        => esc_html__( 'Couleur titre', 'wp-etik-events' ),
                'type'         => 'color-alpha',
                'custom_color' => true,
            ],
            'style_title_size' => [
                'label' => esc_html__( 'Taille titre', 'wp-etik-events' ),
                'type'  => 'text',
            ],
            'style_date_color' => [
                'label'        => esc_html__( 'Couleur date', 'wp-etik-events' ),
                'type'         => 'color-alpha',
                'custom_color' => true,
            ],
            'style_date_size' => [
                'label' => esc_html__( 'Taille date', 'wp-etik-events' ),
                'type'  => 'text',
            ],
            'style_price_color' => [
                'label' => esc_html__( 'Couleur prix', 'wp-etik-events' ),
                'type'  => 'color-alpha',
            ],
            'style_price_size' => [
                'label' => esc_html__( 'Taille prix', 'wp-etik-events' ),
                'type'  => 'text',
            ],
            'style_excerpt_color' => [
                'label' => esc_html__( 'Couleur description', 'wp-etik-events' ),
                'type'  => 'color-alpha',
            ],
            'style_excerpt_size' => [
                'label' => esc_html__( 'Taille description', 'wp-etik-events' ),
                'type'  => 'text',
            ],
            'custom_class' => [
                'label' => esc_html__( 'Classe CSS personnalisée', 'wp-etik-events' ),
                'type'  => 'text',
            ],
        ];
    }

    // =========================================================================
    // RENDER
    // =========================================================================

    public function render( $attrs, $content = null, $render_slug = null ) {

        // Active la modal globale
        \WP_Etik\Etik_Modal_Manager::mark_needed();

        // ── Lecture des props ─────────────────────────────────────────────────
        $posts_number  = max( 1, intval( $this->props['posts_number'] ?? 3 ) );
        $layout        = $this->props['layout']      ?? 'grid';
        $show_image    = ( $this->props['show_image'] ?? 'on' ) === 'on';
        $image_mode    = $this->props['image_mode']  ?? 'card'; // 'card' | 'full'
        $image_height  = esc_attr( $this->props['image_height'] ?? '280px' );
        $custom_class  = esc_attr( $this->props['custom_class'] ?? '' );

        $style_title_color   = esc_attr( $this->props['style_title_color']   ?? '#000' );
        $style_title_size    = esc_attr( $this->props['style_title_size']    ?? '18px' );
        $style_date_color    = esc_attr( $this->props['style_date_color']    ?? '#0c71c3' );
        $style_date_size     = esc_attr( $this->props['style_date_size']     ?? '14px' );
        $style_price_color   = esc_attr( $this->props['style_price_color']   ?? '#FC6B0D' );
        $style_price_size    = esc_attr( $this->props['style_price_size']    ?? '16px' );
        $style_excerpt_color = esc_attr( $this->props['style_excerpt_color'] ?? '#333' );
        $style_excerpt_size  = esc_attr( $this->props['style_excerpt_size']  ?? '14px' );

        // ── Requête événements futurs ─────────────────────────────────────────
        $today = current_time( 'Y-m-d' );
        $query = new \WP_Query( [
            'post_type'      => 'etik_event',
            'posts_per_page' => $posts_number,
            'order'          => 'ASC',
            'meta_key'       => 'etik_start_date',
            'orderby'        => 'meta_value',
            'meta_query'     => [ [
                'key'     => 'etik_start_date',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE',
            ] ],
        ] );

        $unique       = 'etik-' . uniqid();
        $mode_class   = 'etik-mode-' . esc_attr( $image_mode );

        // ── CSS scopé à cette instance ────────────────────────────────────────
        $output  = '<style>';
        $output .= "#{$unique} .etik-thumb img, #{$unique} .etik-thumb-full { height:{$image_height}; }";
        $output .= "#{$unique} .etik-title { color:{$style_title_color}; font-size:{$style_title_size}; }";
        $output .= "#{$unique} .etik-date  { color:{$style_date_color};  font-size:{$style_date_size};  }";
        $output .= "#{$unique} .etik-price { color:{$style_price_color}; font-size:{$style_price_size}; }";
        $output .= "#{$unique} .etik-excerpt { color:{$style_excerpt_color}; font-size:{$style_excerpt_size}; }";
        // Surcharge couleurs overlay full-picture avec les valeurs de style configurées
        $output .= "#{$unique} .etik-overlay .etik-title { color:#ffffff; }";
        $output .= "#{$unique} .etik-overlay .etik-date  { color:rgba(255,255,255,0.85); border-color:rgba(255,255,255,0.4); }";
        $output .= "#{$unique} .etik-overlay .etik-price { color:#ffffff; border-color:rgba(255,255,255,0.6); }";
        $output .= '</style>';

        $output .= '<div class="etik-events ' . esc_attr( $custom_class ) . ' etik-layout-' . esc_attr( $layout ) . ' ' . $mode_class . '" id="' . esc_attr( $unique ) . '">';

        if ( ! $query->have_posts() ) {
            $output .= '<p class="etik-no-events">' . esc_html__( 'Aucun événement à venir.', 'wp-etik-events' ) . '</p>';
        }

        while ( $query->have_posts() ) {
            $query->the_post();
            $id          = get_the_ID();
            $title       = get_the_title();
            $the_content = get_the_content();
            $start       = get_post_meta( $id, 'etik_start_date', true );
            $price       = get_post_meta( $id, 'etik_price', true );
            $timestamp   = $start ? strtotime( $start ) : 0;
            $date_label  = $timestamp ? date_i18n( 'l j M', $timestamp ) : '';
            $price_label = ( $price !== '' && $price !== false ) ? esc_html( $price ) . '€' : '';

            // Data attributes communs pour le déclenchement modal
            $data_attrs = sprintf(
                'data-event="%s" data-title="%s"',
                esc_attr( $id ),
                esc_attr( $title )
            );

            // ── MODE FULL PICTURE ─────────────────────────────────────────────
            if ( $image_mode === 'full' ) {
                $output .= $this->render_full_card( $id, $title, $date_label, $price_label, $data_attrs, $show_image );
                continue;
            }

            // ── MODE CARTE (défaut) ───────────────────────────────────────────
            $output .= $this->render_card(
                $id, $title, $the_content, $date_label, $price_label, $data_attrs, $show_image
            );
        }

        wp_reset_postdata();
        $output .= '</div>';
        return $output;
    }

    // =========================================================================
    // MODE FULL PICTURE
    // Image seule = toute la carte. Clic sur l'image → modale inscription.
    // Overlay titre + date + prix au hover.
    // =========================================================================

    private function render_full_card(
        int    $id,
        string $title,
        string $date_label,
        string $price_label,
        string $data_attrs,
        bool   $show_image
    ) : string {

        $img_html = '';
        if ( $show_image && has_post_thumbnail( $id ) ) {
            $img_html = wp_get_attachment_image(
                get_post_thumbnail_id( $id ),
                'large',
                false,
                [ 'class' => 'etik-thumb-full-img' ]
            );
        }

        // Si pas d'image : placeholder coloré avec les infos
        $has_img_class = $img_html ? 'has-image' : 'no-image';

        $price_html = $price_label
            ? '<span class="etik-price">' . esc_html( $price_label ) . '</span>'
            : '';

        $date_html = $date_label
            ? '<div class="etik-date">' . esc_html( $date_label ) . '</div>'
            : '';

        $out  = '<div class="etik-event etik-event--full ' . esc_attr( $has_img_class ) . '">';

        // La zone cliquable = toute la carte
        $out .= '<button type="button"'
              . ' class="etik-thumb-full etik-formation-btn-img"'
              . ' ' . $data_attrs
              . ' aria-label="' . esc_attr( sprintf( __( "S'inscrire à %s", 'wp-etik-events' ), $title ) ) . '">';

        // Image
        if ( $img_html ) {
            $out .= $img_html;
        }

        // Overlay
        $out .= '<div class="etik-overlay" aria-hidden="true">';
        $out .= '<div class="etik-overlay-inner">';
        $out .= $date_html;
        $out .= '<h3 class="etik-title">' . esc_html( $title ) . '</h3>';
        $out .= $price_html;
        $out .= '<span class="etik-overlay-cta">'
              . esc_html__( "S'inscrire →", 'wp-etik-events' )
              . '</span>';
        $out .= '</div></div>';

        $out .= '</button>';
        $out .= '</div>'; // .etik-event--full

        return $out;
    }

    // =========================================================================
    // MODE CARTE (comportement original, légèrement nettoyé)
    // =========================================================================

    private function render_card(
        int    $id,
        string $title,
        string $the_content,
        string $date_label,
        string $price_label,
        string $data_attrs,
        bool   $show_image
    ) : string {

        $out = '<div class="etik-event">';

        // Image
        if ( $show_image && has_post_thumbnail( $id ) ) {
            $img = wp_get_attachment_image( get_post_thumbnail_id( $id ), 'large' );
            $out .= '<div class="etik-thumb">' . $img . '</div>';
        }

        // Body
        $out .= '<div class="etik-body">';

        $out .= '<div class="et_pb_text_2">';
        if ( $date_label ) {
            $out .= '<div class="etik-date">' . esc_html( $date_label ) . '</div>';
        }
        $out .= '</div>';

        $out .= '<div>';
        $out .= '<h3 class="etik-title">' . esc_html( $title ) . '</h3>';
        if ( $price_label ) {
            $out .= '<div class="etik-price">' . esc_html( $price_label ) . '</div>';
        }
        $out .= '</div>';

        $out .= '<div class="etik-excerpt"><div class="etik-excerpt-content">' . $the_content . '</div></div>';

        $out .= '</div>'; // .etik-body

        // Footer
        $out .= '<div class="etik-footer">';
        $out .= '<button type="button" class="etik-btn-link" style="display:none;">voir plus</button>';
        $out .= '<button type="button" class="etik-formation-btn" ' . $data_attrs . '>'
              . esc_html__( "S'inscrire", 'wp-etik-events' )
              . '</button>';
        $out .= '</div>';

        $out .= '</div>'; // .etik-event

        return $out;
    }
}