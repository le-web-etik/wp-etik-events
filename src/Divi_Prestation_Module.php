<?php
namespace WP_Etik;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ET_Builder_Module' ) ) return;

/**
 * Module Divi : Réservation de prestation
 * Slug : etk_prestation_booking
 *
 * FIXES v2 :
 *  - Nonce : wp_enqueue_script AVANT wp_localize_script + static flag
 *  - Vue mensuelle supportée en mode split
 *  - Navigation semaine corrigée
 */
class Divi_Prestation_Module extends \ET_Builder_Module {

	public $slug       = 'etk_prestation_booking';
	public $vb_support = 'partial';

	public function init() : void {
		$this->name = esc_html__( 'Etik — Réservation prestation', 'wp-etik-events' );

		$this->whitelisted_fields = [
			'select_all_prestations',
			'prestation_ids',
			'prestation_id',
			'view_mode',
			'show_title',
			'show_description',
			'accent_color',
		];

		$this->fields_defaults = [
			'select_all_prestations' => [ 'off' ],
			'prestation_ids'         => [ '' ],
			'prestation_id'          => [ '0' ],
			'view_mode'              => [ 'month' ],
			'show_title'             => [ 'on' ],
			'show_description'       => [ 'on' ],
			'accent_color'           => [ '#2aa78a' ],
		];

		$this->settings_modal_toggles = [
			'general' => [ 'toggles' => [
				'content' => [ 'title' => esc_html__( 'Prestation', 'wp-etik-events' ),  'priority' => 10 ],
				'display' => [ 'title' => esc_html__( 'Affichage', 'wp-etik-events' ),   'priority' => 20 ],
			] ],
		];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// HELPERS
	// ─────────────────────────────────────────────────────────────────────────

	private function _get_prestations() : array {
		return get_posts( [
			'post_type'      => 'etik_prestation',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
	}

	private function _parse_prestation_ids() : array {
		$select_all = ( $this->props['select_all_prestations'] ?? 'off' ) === 'on';
		$posts      = $this->_get_prestations();

		if ( $select_all ) {
			return array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );
		}

		$legacy_id = intval( $this->props['prestation_id'] ?? 0 );
		$cb_value  = $this->props['prestation_ids'] ?? '';

		if ( $cb_value === '' && $legacy_id > 0 ) {
			return [ $legacy_id ];
		}

		$parts = ( $cb_value !== '' ) ? explode( '|', $cb_value ) : [];
		$ids   = [];
		foreach ( $posts as $i => $p ) {
			if ( ( $parts[ $i ] ?? 'off' ) === 'on' ) {
				$ids[] = (int) $p->ID;
			}
		}
		return $ids;
	}

	/**
	 * Fusionne la disponibilité de N prestations pour un mois.
	 * Priorité : available > full > closed > past > none
	 */
	private function _merge_month_avail( array $ids, int $year, int $month ) : array {
		static $prio = [ 'available' => 4, 'full' => 3, 'closed' => 2, 'past' => 1, 'none' => 0 ];
		$merged      = [];
		foreach ( $ids as $pid ) {
			foreach ( \WP_Etik\Prestation_Booking::get_month_availability( $pid, $year, $month ) as $date => $status ) {
				if ( ! isset( $merged[ $date ] ) || ( $prio[ $status ] ?? 0 ) > ( $prio[ $merged[ $date ] ] ?? 0 ) ) {
					$merged[ $date ] = $status;
				}
			}
		}
		return $merged;
	}

	/** Retourne le lundi de la semaine contenant $date. */
	private function _get_week_monday( string $date ) : \DateTime {
		$d   = new \DateTime( $date );
		$dow = (int) $d->format( 'N' );
		if ( $dow > 1 ) $d->modify( '-' . ( $dow - 1 ) . ' days' );
		return $d;
	}

	private function _week_label( \DateTime $mon, \DateTime $sun ) : string {
		$m = [ 'jan','fév','mar','avr','mai','juin','juil','août','sep','oct','nov','déc' ];
		$m1 = $m[ (int)$mon->format('n') - 1 ];
		$m2 = $m[ (int)$sun->format('n') - 1 ];
		$y  = $sun->format('Y');
		return $mon->format('n') === $sun->format('n')
			? $mon->format('j') . ' – ' . $sun->format('j') . ' ' . $m2 . ' ' . $y
			: $mon->format('j') . ' ' . $m1 . ' – ' . $sun->format('j') . ' ' . $m2 . ' ' . $y;
	}

	private function _format_date_fr( string $date ) : string {
		$j = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
		$m = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
		$ts = strtotime( $date );
		return $j[(int)date('w',$ts)] . ' ' . date('j',$ts) . ' ' . $m[(int)date('n',$ts)-1] . ' ' . date('Y',$ts);
	}

	private function _render_week_strip( \DateTime $monday, array $avail, string $today, string $selected ) : string {
		$fr  = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
		$sun = clone $monday; $sun->modify( '+6 days' );

		$html  = '<div class="etik-week-strip"';
		$html .= ' data-year="'  . esc_attr( $monday->format('Y') ) . '"';
		$html .= ' data-month="' . esc_attr( $monday->format('n') ) . '"';
		$html .= ' data-anchor="'. esc_attr( $monday->format('Y-m-d') ) . '">';
		$html .= '<div class="etik-week-nav">';
		$html .= '<button type="button" class="etik-week-prev etik-nav-btn">&#8249;</button>';
		$html .= '<span class="etik-week-label">' . esc_html( $this->_week_label( $monday, $sun ) ) . '</span>';
		$html .= '<button type="button" class="etik-week-next etik-nav-btn">&#8250;</button>';
		$html .= '</div><div class="etik-week-days">';

		$day = clone $monday;
		for ( $i = 0; $i < 7; $i++ ) {
			$date   = $day->format('Y-m-d');
			$status = $avail[$date] ?? 'none';
			$cls    = ['etik-week-day'];
			if ( $date === $today )    $cls[] = 'etik-day-today';
			if ( $date === $selected ) $cls[] = 'etik-day-selected';
			$cls[] = $date < $today ? 'etik-day-past' : 'etik-day-' . $status;
			$click = $date >= $today && in_array( $status, ['available','full'], true );

			$html .= '<button type="button" class="' . esc_attr( implode(' ',$cls) ) . '"'
				. ( $click ? ' data-date="' . esc_attr($date) . '"' : '' ) . '>'
				. '<span class="etik-wday-name">' . esc_html($fr[$i]) . '</span>'
				. '<span class="etik-wday-num">'  . esc_html($day->format('j')) . '</span>'
				. '<span class="etik-wday-dot"></span></button>';
			$day->modify('+1 day');
		}
		return $html . '</div></div>';
	}

	private function _render_slots_panel( array $ids, string $date, string $accent ) : string {
		$html = '';
		foreach ( $ids as $pid ) {
			$post  = get_post( (int)$pid );
			if ( !$post || $post->post_status !== 'publish' ) continue;
			$slots = \WP_Etik\Prestation_Booking::get_slots_for_date( (int)$pid, $date );
			if ( empty($slots) ) continue;

			$color   = get_post_meta($pid,'etik_prestation_color',true) ?: $accent;
			$price   = (float)get_post_meta($pid,'etik_prestation_price',true);
			$pay_req = (bool)get_post_meta($pid,'etik_prestation_payment_required',true);

			$html .= '<div class="etik-prest-group"'
				. ' data-prestation-id="' . esc_attr($pid) . '"'
				. ' data-pay-required="'  . ($pay_req ? '1' : '0') . '"'
				. ' data-price="'         . esc_attr(number_format($price, 2, '.', '')) . '"'
				. '><div class="etik-prest-group__header">'
				. '<span class="etik-prest-group__dot" style="background:' . esc_attr($color) . ';"></span>'
				. '<strong class="etik-prest-group__name">' . esc_html($post->post_title) . '</strong>';

			if ($price > 0) {
				$html .= '<span class="etik-prest-group__price">' . esc_html(number_format($price,2,',','').' €') . '</span>';
				if ($pay_req) $html .= '<span class="etik-badge-pay">' . esc_html__('Paiement requis','wp-etik-events') . '</span>';
			}
			$html .= '</div><div class="etik-prest-group__slots">';

			foreach ($slots as $s) {
				$ok  = (bool)($s['available'] ?? true);
				$cls = 'etik-slot-btn' . ($ok ? '' : ' etik-slot-full');
				$dis = $ok ? '' : ' disabled aria-disabled="true"';
				$html .= '<button type="button" class="' . esc_attr($cls) . '"'
					. ' data-slot-id="' . esc_attr($s['slot_id'] ?? '') . '"'
					. ' data-time="'   . esc_attr($s['time']    ?? '') . '"'
					. $dis . '>'
					. esc_html($s['time'] ?? '')
					. (!$ok ? ' <small>(' . esc_html__('Complet','wp-etik-events') . ')</small>' : '')
					. '</button>';
			}
			$html .= '</div></div>';
		}
		return $html ?: '<p class="etik-no-slots">' . esc_html__('Aucun créneau disponible ce jour.','wp-etik-events') . '</p>';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CHAMPS DIVI
	// ─────────────────────────────────────────────────────────────────────────

	public function get_fields() : array {
		$posts   = $this->_get_prestations();
		$cb_opts = [];
		foreach ($posts as $p) $cb_opts[(string)$p->ID] = esc_html($p->post_title);

		return [
			'select_all_prestations' => [
				'label'           => esc_html__('Toutes les prestations','wp-etik-events'),
				'type'            => 'yes_no_button',
				'option_category' => 'configuration',
				'options'         => ['on' => esc_html__('Oui','wp-etik-events'), 'off' => esc_html__('Non','wp-etik-events')],
				'default'         => 'off',
				'toggle_slug'     => 'content',
			],
			'prestation_ids' => [
				'label'           => esc_html__('Prestations','wp-etik-events'),
				'type'            => 'multiple_checkboxes',
				'option_category' => 'configuration',
				'options'         => !empty($cb_opts) ? $cb_opts : ['0' => esc_html__('— Aucune —','wp-etik-events')],
				'toggle_slug'     => 'content',
				'show_if'         => ['select_all_prestations' => 'off'],
				'description'     => esc_html__('1 cochée = calendrier direct. 2+ = vue unifiée (semaine ou mois selon option ci-dessous).','wp-etik-events'),
			],
			'prestation_id' => [
				'label'           => esc_html__('Prestation (ancien)','wp-etik-events'),
				'type'            => 'hidden',
				'option_category' => 'configuration',
				'toggle_slug'     => 'content',
			],
			'view_mode' => [
				'label'           => esc_html__('Affichage calendrier','wp-etik-events'),
				'type'            => 'select',
				'option_category' => 'configuration',
				'options'         => [
					'month' => esc_html__('Mensuel (grille)','wp-etik-events'),
					'week'  => esc_html__('Semaine (bandeau compact)','wp-etik-events'),
				],
				'default'     => 'month',
				'toggle_slug' => 'display',
				'description' => esc_html__('S\'applique aussi au mode multi-prestations.','wp-etik-events'),
			],
			'show_title' => [
				'label'           => esc_html__('Afficher le titre','wp-etik-events'),
				'type'            => 'yes_no_button',
				'option_category' => 'configuration',
				'options'         => ['on' => 'Oui', 'off' => 'Non'],
				'toggle_slug'     => 'display',
			],
			'show_description' => [
				'label'           => esc_html__('Afficher les descriptions','wp-etik-events'),
				'type'            => 'yes_no_button',
				'option_category' => 'configuration',
				'options'         => ['on' => 'Oui', 'off' => 'Non'],
				'toggle_slug'     => 'display',
			],
			'accent_color' => [
				'label'           => esc_html__('Couleur principale','wp-etik-events'),
				'type'            => 'color-alpha',
				'option_category' => 'configuration',
				'custom_color'    => true,
				'toggle_slug'     => 'display',
			],
		];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// RENDER — FIX NONCE : enqueue AVANT localize + static flag
	// ─────────────────────────────────────────────────────────────────────────

	public function render( $attrs, $content = null, $render_slug = null ) : string {
		$ids         = $this->_parse_prestation_ids();
		$view_mode   = sanitize_key( $this->props['view_mode']        ?? 'month' );
		$show_title  = ( $this->props['show_title']       ?? 'on' ) === 'on';
		$show_desc   = ( $this->props['show_description'] ?? 'on' ) === 'on';
		$accent      = sanitize_hex_color( $this->props['accent_color'] ?? '#2aa78a' ) ?: '#2aa78a';

		if ( empty($ids) ) {
			return '<p class="etik-no-prestation">' . esc_html__('Aucune prestation sélectionnée.','wp-etik-events') . '</p>';
		}

		// ── 1. Enqueue le CSS ─────────────────────────────────────────────────
		wp_enqueue_style(
			'etik-prestation-booking',
			WP_ETIK_PLUGIN_URL . 'assets/css/prestation-booking.css',
			[], WP_ETIK_VERSION
		);


		// ── 2. Enqueue le(s) script(s) AVANT wp_localize_script ──────────────
        wp_enqueue_script(
            'etik-prestation-booking-split',
            WP_ETIK_PLUGIN_URL . 'assets/js/prestation-booking-split.js',
            [ 'jquery'], WP_ETIK_VERSION, true
        );
		

		// ── 3. Localisation (static flag : une seule fois par page) ───────────
		static $etik_localized = false;
		if ( ! $etik_localized ) {
			wp_localize_script( 'etik-prestation-booking-split', 'etikBooking', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'etik_booking_nonce' ),
				'i18n'     => [
					'loading'            => __( 'Chargement…',                             'wp-etik-events' ),
					'no_slots'           => __( 'Aucun créneau disponible ce jour.',       'wp-etik-events' ),
					'no_slots_week'      => __( 'Aucun créneau cette semaine.',            'wp-etik-events' ),
					'reserve'            => __( 'Réserver',                                'wp-etik-events' ),
					'pay_reserve'        => __( 'Payer et réserver',                       'wp-etik-events' ),
					'full'               => __( 'Complet',                                 'wp-etik-events' ),
					'reserved'           => __( 'Réservé',                                 'wp-etik-events' ),
					'required'           => __( 'Champs requis :',                         'wp-etik-events' ),
					'booking_success'    => __( 'Votre réservation est confirmée !',       'wp-etik-events' ),
					'new_booking'        => __( 'Nouvelle réservation',                    'wp-etik-events' ),
					'cancel'             => __( 'Annuler',                                 'wp-etik-events' ),
					'pay_required_badge' => __( 'Paiement requis',                        'wp-etik-events' ),
					'booking_paid'       => __( 'Paiement confirmé — réservation enregistrée.', 'wp-etik-events' ),
					'days'               => [ 'Lun','Mar','Mer','Jeu','Ven','Sam','Dim' ],
					'months'             => [ 'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre' ],
				],
			] );
			$etik_localized = true;
		}

		// ── 4. Dispatch ───────────────────────────────────────────────────────
		return $this->_render_split( $ids, $view_mode, $show_title, $show_desc, $accent );
	}



	// ─────────────────────────────────────────────────────────────────────────
	// MULTI-PRESTATIONS — VUE UNIFIÉE (semaine OU mois selon view_mode)
	// ─────────────────────────────────────────────────────────────────────────

	private function _render_split( array $ids, string $vm, bool $title, bool $desc, string $accent ) : string {
		$today  = date('Y-m-d');
		$now    = new \DateTime();
		$year   = (int)$now->format('Y');
		$month  = (int)$now->format('n');
		$ret_url = esc_url( get_permalink() ?: home_url('/') );
		$uid     = 'etik-split-' . wp_unique_id();

		// Disponibilité fusionnée du mois courant
		$merged = $this->_merge_month_avail($ids, $year, $month);

		// Chercher le premier jour dispo (aujourd'hui ou prochain)
		$initial_date = $today;
		if ( !in_array($merged[$today] ?? 'none', ['available','full'], true) ) {
			for ( $d = new \DateTime($today), $max = 0; $max < 31; $d->modify('+1 day'), $max++ ) {
				$ds = $d->format('Y-m-d');
				// Si on change de mois, charger la dispo du nouveau mois
				if ( (int)$d->format('n') !== $month ) {
					$extra  = $this->_merge_month_avail($ids, (int)$d->format('Y'), (int)$d->format('n'));
					$merged = array_merge($merged, $extra);
				}
				if ( in_array($merged[$ds] ?? 'none', ['available','full'], true) ) {
					$initial_date = $ds; break;
				}
			}
		}

		ob_start();
		echo '<div id="' . esc_attr($uid) . '"'
			. ' class="etik-booking-module etik-booking-split etik-split-mode-' . esc_attr($vm) . '"'
			. ' data-prestation-ids="'  . esc_attr(wp_json_encode(array_map('intval',$ids))) . '"'
			. ' data-view="'            . esc_attr($vm) . '"'
			. ' data-selected-date="'   . esc_attr($initial_date) . '"'
			. ' data-return-url="'      . esc_attr($ret_url) . '"'
			. ' style="--etik-accent:'  . esc_attr($accent) . ';">';

		if ($title) {
			echo '<h3 class="etik-booking-title">' . esc_html__('Réserver une prestation','wp-etik-events') . '</h3>';
		}

		// ── Calendrier (semaine ou mois) ─────────────────────────────────────
		if ( $vm === 'week' ) {
			// ── Mode SEMAINE : bandeau compact ──────────────────────────────
			$monday = $this->_get_week_monday($today);
			$sunday = clone $monday; $sunday->modify('+6 days');
			// Charger mois adjacent si la semaine chevauche deux mois
			foreach ([$monday,$sunday] as $dt) {
				$y = (int)$dt->format('Y'); $m = (int)$dt->format('n');
				if ($y !== $year || $m !== $month) {
					$extra  = $this->_merge_month_avail($ids,$y,$m);
					$merged = array_merge($extra,$merged);
				}
			}
			echo $this->_render_week_strip($monday, $merged, $today, $initial_date);

		} else {
			// ── Mode MOIS : calendrier grille ───────────────────────────────
			echo '<div class="etik-split-calendar">';
			echo '<div class="etik-booking-nav">';
			echo '<button type="button" class="etik-nav-prev">&#8249;</button>';
			echo '<span class="etik-month-label">' . esc_html(date_i18n('F Y',mktime(0,0,0,$month,1,$year))) . '</span>';
			echo '<button type="button" class="etik-nav-next">&#8250;</button>';
			echo '</div>';
			echo '<div class="etik-calendar-wrap" data-year="' . esc_attr($year) . '" data-month="' . esc_attr($month) . '">';
			$this->_render_merged_calendar($year, $month, $merged, $today, $initial_date);
			echo '</div>';
			echo '<div class="etik-cal-legend">';
			echo '<span class="etik-legend-item"><span class="etik-legend-dot etik-dot-available"></span>' . esc_html__('Disponible','wp-etik-events') . '</span>';
			echo '<span class="etik-legend-item"><span class="etik-legend-dot etik-dot-full"></span>' . esc_html__('Complet','wp-etik-events') . '</span>';
			echo '<span class="etik-legend-item"><span class="etik-legend-dot etik-dot-closed"></span>' . esc_html__('Fermé','wp-etik-events') . '</span>';
			echo '</div></div>'; // .etik-split-calendar
		}

		// ── Panneau créneaux ─────────────────────────────────────────────────
		echo '<div class="etik-split-slots">';
		echo '<p class="etik-slots-date-heading">' . esc_html($this->_format_date_fr($initial_date)) . '</p>';
		echo '<div class="etik-slots-panel">';
		echo $this->_render_slots_panel($ids, $initial_date, $accent);
		echo '</div></div>'; // .etik-split-slots

		echo '</div>'; // .etik-booking-split
		return ob_get_clean();
	}

	/**
	 * Calendrier mensuel avec disponibilité fusionnée et jour sélectionné mis en évidence.
	 */
	private function _render_merged_calendar( int $year, int $month, array $avail, string $today, string $selected ) : void {
		$first_dow  = (int)date('N',mktime(0,0,0,$month,1,$year));
		$days_count = (int)date('t',mktime(0,0,0,$month,1,$year));
		$day_labels = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
		?>
		<div class="etik-calendar etik-view-month">
			<div class="etik-cal-header">
				<?php foreach ($day_labels as $dl) : ?><div class="etik-cal-hd"><?php echo esc_html($dl); ?></div><?php endforeach; ?>
			</div>
			<div class="etik-cal-grid">
				<?php for ($i = 1; $i < $first_dow; $i++) : ?><div class="etik-cal-day etik-cal-empty"></div><?php endfor; ?>
				<?php for ($d = 1; $d <= $days_count; $d++) :
					$date      = sprintf('%04d-%02d-%02d', $year, $month, $d);
					$status    = $avail[$date] ?? 'none';
					$cls       = ['etik-cal-day', "etik-cal-{$status}"];
					if ($date === $today)    $cls[] = 'etik-cal-today';
					if ($date === $selected) $cls[] = 'etik-cal-selected';
					$clickable = in_array($status,['available','full'],true);
				?><div class="<?php echo esc_attr(implode(' ',$cls)); ?>"
				       <?php if ($clickable) : ?>data-date="<?php echo esc_attr($date); ?>" role="button" tabindex="0" aria-label="<?php echo esc_attr(date_i18n('d M Y',strtotime($date))); ?>"<?php endif; ?>>
					<span class="etik-day-num"><?php echo esc_html($d); ?></span>
					<span class="etik-day-dot"></span>
				</div><?php endfor; ?>
			</div>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CALENDRIER SSR (mode mono, inchangé)
	// ─────────────────────────────────────────────────────────────────────────

	public function render_calendar( int $year, int $month, array $avail, string $view_mode = 'month' ) : void {
		$first_dow  = (int)date('N',mktime(0,0,0,$month,1,$year));
		$days_count = (int)date('t',mktime(0,0,0,$month,1,$year));
		$today      = date('Y-m-d');
		$day_labels = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
		?>
		<div class="etik-calendar etik-view-<?php echo esc_attr($view_mode); ?>">
			<div class="etik-cal-header"><?php foreach ($day_labels as $dl) : ?><div class="etik-cal-hd"><?php echo esc_html($dl); ?></div><?php endforeach; ?></div>
			<div class="etik-cal-grid">
				<?php for ($i=1;$i<$first_dow;$i++) : ?><div class="etik-cal-day etik-cal-empty"></div><?php endfor; ?>
				<?php for ($d=1;$d<=$days_count;$d++) :
					$date = sprintf('%04d-%02d-%02d',$year,$month,$d);
					$status = $avail[$date] ?? 'none';
					$cls = ['etik-cal-day',"etik-cal-{$status}"];
					if ($date===$today) $cls[]='etik-cal-today';
					$click = in_array($status,['available','full'],true);
				?><div class="<?php echo esc_attr(implode(' ',$cls)); ?>"
				       <?php if ($click):?>data-date="<?php echo esc_attr($date);?>" role="button" tabindex="0" aria-label="<?php echo esc_attr(date_i18n('d M Y',strtotime($date)));?>"<?php endif;?>>
					<span class="etik-day-num"><?php echo esc_html($d); ?></span><span class="etik-day-dot"></span>
				</div><?php endfor; ?>
			</div>
		</div>
		<?php
	}
}