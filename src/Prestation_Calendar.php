<?php
namespace WP_Etik;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prestation_Calendar {

    public function init() {
        add_shortcode( 'etik_prestation_calendar', [ $this, 'render_calendar' ] );
    }

    public function render_calendar( $atts ) {
        $atts = shortcode_atts( [
            'prestation_id' => 0,
            'year' => date( 'Y' ),
            'month' => date( 'm' ),
        ], $atts );

        $prestation_id = intval( $atts['prestation_id'] );
        $year = intval( $atts['year'] );
        $month = intval( $atts['month'] );

        // Récupérer les créneaux disponibles
        $slots = $this->get_available_slots( $prestation_id, $year, $month );

        // Générer le calendrier
        $calendar = $this->generate_calendar( $year, $month, $slots );

        return $calendar;
    }

    private function get_available_slots( $prestation_id, $year, $month ) {
        global $wpdb;
        $table = $wpdb->prefix . 'etik_prestation_slots';
        $closures_table = $wpdb->prefix . 'etik_prestation_closures';

        $sql = "SELECT s.*, c.closure_date
                FROM {$table} s
                LEFT JOIN {$closures_table} c ON s.prestation_id = c.prestation_id AND c.closure_date = DATE_ADD( s.start_date, INTERVAL 0 DAY )
                WHERE s.prestation_id = %d AND s.type = 'recurrent' AND s.is_closed = 0";

        $slots = $wpdb->get_results( $wpdb->prepare( $sql, $prestation_id ) );

        return $slots;
    }

    private function generate_calendar( $year, $month, $slots ) {
        $calendar = '<div class="etik-calendar">';
        $calendar .= '<h3>' . date( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ) . '</h3>';
        $calendar .= '<table class="etik-calendar-table">';
        $calendar .= '<thead><tr><th>Dim</th><th>Lun</th><th>Mar</th><th>Mer</th><th>Jeu</th><th>Ven</th><th>Sam</th></tr></thead>';
        $calendar .= '<tbody>';

        $first_day = date( 'N', mktime( 0, 0, 0, $month, 1, $year ) );
        $days_in_month = date( 't', mktime( 0, 0, 0, $month, 1, $year ) );

        $calendar .= '<tr>';
        for ( $i = 1; $i < $first_day; $i++ ) {
            $calendar .= '<td></td>';
        }

        for ( $day = 1; $day <= $days_in_month; $day++ ) {
            $date = date( 'Y-m-d', mktime( 0, 0, 0, $month, $day, $year ) );
            $class = '';

            // Vérifier si le jour est disponible
            $available = false;
            foreach ( $slots as $slot ) {
                if ( $slot->days && in_array( date( 'N', strtotime( $date ) ), explode( ',', $slot->days ) ) ) {
                    $available = true;
                    break;
                }
            }

            if ( $available ) {
                $class = 'etik-calendar-day-available';
            }

            $calendar .= '<td class="' . esc_attr( $class ) . '">' . $day . '</td>';

            if ( $day % 7 == 0 ) {
                $calendar .= '</tr><tr>';
            }
        }

        $calendar .= '</tr>';
        $calendar .= '</tbody>';
        $calendar .= '</table>';
        $calendar .= '</div>';

        return $calendar;
    }
}