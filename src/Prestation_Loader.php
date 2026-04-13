<?php
namespace WP_Etik;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Prestation_Loader {

    public function run() {
        // Charger les fichiers nécessaires
        require_once WP_ETIK_PLUGIN_DIR . 'src/Prestation_Event.php';
        require_once WP_ETIK_PLUGIN_DIR . 'src/Prestation_Slot_Manager.php';
        require_once WP_ETIK_PLUGIN_DIR . 'src/Prestation_Reservation.php';
        require_once WP_ETIK_PLUGIN_DIR . 'src/Prestation_Calendar.php';
    }
}