/**
 * prestation-booking-split.js
 *
 * Vue unifiée multi-prestations (et mono-prestation) :
 *  · Bandeau 7 jours OU Calendrier Mois (selon option)
 *  · Créneaux de TOUTES les prestations affichés sans clic intermédiaire
 *  · Clic créneau → formulaire inline (accordéon)
 *  · Navigation Semaine & Mois → AJAX (cache mois en mémoire)
 *  · Autonome : tous les helpers sont inclus dans ce fichier.
 */

(function ($) {
    'use strict';

    var i18n = (window.etikBooking || {}).i18n || {};

    // ── Initialisation ────────────────────────────────────────────────────────

    $(document).ready(function () {
        $('.etik-booking-split').each(function () {
            BookingSplit.init($(this));
        });
    });

    // ── Objet principal ───────────────────────────────────────────────────────

    var BookingSplit = {

        // Cache disponibilité mensuelle : { 'YYYY-M': { 'YYYY-MM-DD': status } }
        _cache: {},

        init: function ($m) {
            var self = this;

            // ── Pré-remplir le cache depuis le HTML SSR (si disponible) ───────
            // Cela évite un rechargement AJAX immédiat si le HTML est déjà là
            $m.find('.etik-week-day').each(function () {
                var $d   = $(this);
                var date = $d.data('date');
                if (!date) return;
                var status = 'none';
                var cls    = ' ' + ($d.attr('class') || '') + ' ';
                if (cls.indexOf(' etik-day-available ') > -1) status = 'available';
                else if (cls.indexOf(' etik-day-full ')      > -1) status = 'full';
                else if (cls.indexOf(' etik-day-closed ')    > -1) status = 'closed';
                else if (cls.indexOf(' etik-day-past ')      > -1) status = 'past';
                
                var key = date.substr(0, 4) + '-' + parseInt(date.substr(5, 2));
                if (!self._cache[key]) self._cache[key] = {};
                self._cache[key][date] = status;
            });

            // ── Navigation Mois ───────────────────────────────────────────────
            $m.on('click', '.etik-nav-prev', function () { self.navigateMonth($m, -1); });
            $m.on('click', '.etik-nav-next', function () { self.navigateMonth($m, +1); });

            // ── Basculement Vue Mois ↔ Semaine ────────────────────────────────
            $m.on('click', '.etik-toggle-view', function () {
                var v = $(this).data('view');
                $m.attr('data-view', v);
                // Si on passe en vue semaine, on initialise le bandeau
                if (v === 'week') {
                    var anchor = $m.data('selected-date') || todayStr();
                    self.renderWeek($m, anchor);
                }
            });

            // ── Navigation Semaine ────────────────────────────────────────────
            $m.on('click', '.etik-week-prev', function () { self.navigateWeek($m, -1); });
            $m.on('click', '.etik-week-next', function () { self.navigateWeek($m, +1); });

            // ── Sélection d'un jour (Calendrier ou Bandeau) ───────────────────
            // Délégation d'événement pour fonctionner même après reconstruction HTML
            $m.on('click keypress', '.etik-cal-day[data-date], .etik-week-day[data-date]', function (e) {
                if (e.type === 'keypress' && e.which !== 13) return;
                
                var $clicked = $(this);
                var date = $clicked.data('date');
                if (!date) return;

                // Gestion visuelle de la sélection
                $m.find('.etik-cal-day, .etik-week-day').removeClass('etik-cal-selected etik-day-selected');
                $clicked.addClass($clicked.hasClass('etik-cal-day') ? 'etik-cal-selected' : 'etik-day-selected');
                
                // Mise à jour data attribute
                $m.data('selected-date', date);
                
                // Mise à jour titre date
                var $heading = $m.find('.etik-slots-date-heading');
                if ($heading.length) {
                    $heading.text(formatDateFr(date));
                }

                // Chargement des créneaux
                self.loadSlots($m, date);
            });

            // ── Clic sur un créneau ───────────────────────────────────────────
            $m.on('click', '.etik-slot-btn:not([disabled])', function () {
                self.openInlineForm($m, $(this));
            });

            // ── Fermeture du formulaire inline ────────────────────────────────
            $m.on('click', '.etik-cancel-form', function () {
                var $wrap  = $(this).closest('.etik-inline-form-wrap');
                var slotId = $wrap.data('slot-id');
                $m.find('.etik-slot-btn[data-slot-id="' + slotId + '"]').removeClass('etik-slot-active');
                $wrap.slideUp(200, function () { $(this).remove(); });
            });

            // ── Soumission du formulaire inline ───────────────────────────────
            $m.on('submit', '.etik-inline-booking-form', function (e) {
                e.preventDefault();
                self.submitBooking($m, $(this));
            });

            // ── Nouvelle réservation (depuis message de succès) ───────────────
            $m.on('click', '.etik-btn-new', function () {
                $(this).closest('.etik-inline-form-wrap').slideUp(200, function () {
                    $(this).remove();
                });
                $m.find('.etik-slot-btn').removeClass('etik-slot-active');
            });
        },

        // ─── Navigation Mois (AJAX Multi) ─────────────────────────────────────

        navigateMonth: function ($m, delta) {
            var self   = this;
            var $wrap  = $m.find('.etik-calendar-wrap');
            
            var year  = parseInt($wrap.data('year'), 10);
            var month = parseInt($wrap.data('month'), 10) + delta;

            if (month > 12) { month = 1;  year++; }
            if (month < 1)  { month = 12; year--; }

            // Afficher le loader
            self.setLoading($m, true);

            var ids = $m.data('prestation-ids') || [];
            var cacheKey = year + '-' + month;

            // Vérifier le cache d'abord
            if (self._cache[cacheKey]) {
                self._rebuildCalendar($m, year, month, self._cache[cacheKey]);
                self.setLoading($m, false);
                return;
            }

            // Appel AJAX MULTI (fonctionne pour 1 ou N IDs)
            post({
                action:         'etik_get_month_avail_multi',
                prestation_ids: JSON.stringify(ids),
                year:           year,
                month:          month,
            }, function (data) {
                // Sauvegarder dans le cache
                self._cache[cacheKey] = data;
                self._rebuildCalendar($m, year, month, data);
                self.setLoading($m, false);
            }, function () {
                $wrap.html('<p class="etik-slot-error">Erreur de chargement du calendrier.</p>');
                self.setLoading($m, false);
            });
        },

        // ─── Reconstruction du Calendrier (Mois) ──────────────────────────────

        _rebuildCalendar: function ($m, year, month, data) {
            var self = this;
            var $wrap = $m.find('.etik-calendar-wrap');
            
            // 1. Mettre à jour les attributs
            $wrap.data('year', year).attr('data-year', year);
            $wrap.data('month', month).attr('data-month', month);
            
            // 2. Mettre à jour le label
            var months = i18n.months || ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
            $m.find('.etik-month-label').text(months[month - 1] + ' ' + year);

            // 3. Reconstruire le HTML
            $wrap.html(self.buildMultiCalendar(year, month, data));

            // 4. Gérer la vue Semaine si active
            var view = $m.attr('data-view') || 'month';
            if (view === 'week') {
                var today = todayStr();
                // Si on est dans le mois courant, garder aujourd'hui, sinon prendre le 1er
                var currentMonthStr = year + '-' + String(month).padStart(2,'0');
                var anchor = today.startsWith(currentMonthStr) ? today : (year + '-' + String(month).padStart(2,'0') + '-01');
                self.renderWeek($m, anchor);
            }

            // 5. Sélection automatique d'un jour
            var selectedDate = null;
            var today = todayStr();
            
            // Essayer de sélectionner aujourd'hui si dispo et dans le mois
            if (data[today] === 'available' && today.startsWith(currentMonthStr)) {
                selectedDate = today;
            } else {
                // Sinon trouver le premier jour dispo du mois
                for (var d = 1; d <= 31; d++) {
                    var ds = year + '-' + String(month).padStart(2,'0') + '-' + String(d).padStart(2,'0');
                    if (data[ds] === 'available') {
                        selectedDate = ds;
                        break;
                    }
                }
            }

            if (selectedDate) {
                $m.data('selected-date', selectedDate);
                $m.find('.etik-slots-date-heading').text(formatDateFr(selectedDate));
                // Déclencher visuellement la sélection
                $m.find('.etik-cal-day[data-date="' + selectedDate + '"]').addClass('etik-cal-selected');
                $m.find('.etik-week-day[data-date="' + selectedDate + '"]').addClass('etik-day-selected');
                self.loadSlots($m, selectedDate);
            } else {
                // Aucun jour dispo
                $m.find('.etik-slots-panel').html('<p class="etik-no-slots">Aucun créneau disponible ce mois-ci.</p>');
            }
        },

        // ─── Navigation Semaine ───────────────────────────────────────────────

        navigateWeek: function ($m, delta) {
            var anchor = $m.find('.etik-week-strip').data('anchor') || todayStr();
            var d = new Date(anchor + 'T00:00:00');
            d.setDate(d.getDate() + delta * 7);
            var newAnchor = fmtDate(d);
            this.renderWeek($m, newAnchor);
        },

        renderWeek: function ($m, anchor) {
            var self    = this;
            var today   = todayStr();
            var daysFr  = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
            var moisFr  = ['jan','fév','mar','avr','mai','juin','juil','août','sep','oct','nov','déc'];

            // Lundi de la semaine
            var d   = new Date(anchor + 'T00:00:00');
            var dow = d.getDay() || 7;
            d.setDate(d.getDate() - (dow - 1));
            var monday = new Date(d.getTime());

            var sunday = new Date(monday.getTime());
            sunday.setDate(sunday.getDate() + 6);

            // Label de semaine
            var label;
            if (monday.getMonth() === sunday.getMonth()) {
                label = monday.getDate() + ' – ' + sunday.getDate() + ' ' + moisFr[sunday.getMonth()] + ' ' + sunday.getFullYear();
            } else {
                label = monday.getDate() + ' ' + moisFr[monday.getMonth()] + ' – ' + sunday.getDate() + ' ' + moisFr[sunday.getMonth()] + ' ' + sunday.getFullYear();
            }

            // Construire les 7 boutons
            var html       = '';
            var firstAvail = null;
            var cur        = new Date(monday.getTime());
            var todayInWeek = false;
            var ids = $m.data('prestation-ids') || [];

            // Charger les mois nécessaires pour le cache si manquant
            var toLoad = [];
            [ monday, sunday ].forEach(function (dt) {
                var key = dt.getFullYear() + '-' + (dt.getMonth() + 1);
                if (!self._cache[key]) toLoad.push({ y: dt.getFullYear(), m: dt.getMonth() + 1, key: key });
            });

            if (toLoad.length > 0) {
                // Simple dédoublonnage et chargement
                var unique = {};
                toLoad = toLoad.filter(function (mo) {
                    if (unique[mo.key]) return false;
                    unique[mo.key] = true;
                    return true;
                });
                
                var pending = toLoad.length;
                toLoad.forEach(function (mo) {
                    post({
                        action: 'etik_get_month_avail_multi',
                        prestation_ids: JSON.stringify(ids),
                        year: mo.y, month: mo.m
                    }, function(data) {
                        self._cache[mo.key] = data;
                        if (--pending === 0) self._renderWeekStrip($m, monday, label, today, daysFr);
                    }, function() {
                        self._cache[mo.key] = {};
                        if (--pending === 0) self._renderWeekStrip($m, monday, label, today, daysFr);
                    });
                });
                return; // Sortie temporaire, le rendu se fera dans le callback
            }

            this._renderWeekStrip($m, monday, label, today, daysFr);
        },

        _renderWeekStrip: function ($m, monday, label, today, daysFr) {
            var self = this;
            var sunday = new Date(monday.getTime());
            sunday.setDate(sunday.getDate() + 6);
            var selected = $m.data('selected-date') || today;

            var html = '';
            var cur = new Date(monday.getTime());

            for (var i = 0; i < 7; i++) {
                var ds      = fmtDate(cur);
                var cacheK  = cur.getFullYear() + '-' + (cur.getMonth() + 1);
                var status  = (self._cache[cacheK] || {})[ds] || 'none';
                var cls     = ['etik-week-day'];

                if (ds === today)    cls.push('etik-day-today');
                if (ds === selected) cls.push('etik-day-selected');
                
                var isPast = ds < today;
                if (isPast) cls.push('etik-day-past');
                else        cls.push('etik-day-' + status);

                var clickable = !isPast && (status === 'available' || status === 'full');

                html += '<button type="button" class="' + cls.join(' ') + '"'
                    + (clickable ? ' data-date="' + ds + '"' : '')
                    + '>'
                    + '<span class="etik-wday-name">' + daysFr[i] + '</span>'
                    + '<span class="etik-wday-num">'  + cur.getDate() + '</span>'
                    + '<span class="etik-wday-dot"></span>'
                    + '</button>';

                cur.setDate(cur.getDate() + 1);
            }

            var $strip = $m.find('.etik-week-strip');
            if ($strip.length === 0) {
                // Créer le bandeau s'il n'existe pas (changement de vue)
                var stripHtml = '<div class="etik-week-strip" data-anchor="' + fmtDate(monday) + '">' +
                    '<div class="etik-week-nav">' +
                    '<button type="button" class="etik-week-prev etik-nav-btn">&#8249;</button>' +
                    '<span class="etik-week-label">' + label + '</span>' +
                    '<button type="button" class="etik-week-next etik-nav-btn">&#8250;</button>' +
                    '</div><div class="etik-week-days">' + html + '</div></div>';
                
                $m.find('.etik-calendar-wrap').before(stripHtml);
            } else {
                $strip.data('anchor', fmtDate(monday)).attr('data-anchor', fmtDate(monday));
                $strip.find('.etik-week-label').text(label);
                $strip.find('.etik-week-days').html(html);
            }
        },

        // ─── Chargement créneaux (AJAX) ───────────────────────────────────────

        loadSlots: function ($m, date) {
            var self   = this;
            var ids    = $m.data('prestation-ids') || [];
            var $panel = $m.find('.etik-slots-panel');

            $panel.html(
                '<span class="etik-loading-inline">'
                + '<span class="etik-spinner-sm"></span> '
                + escHtml(i18n.loading || 'Chargement…')
                + '</span>'
            );

            post({
                action:         'etik_get_day_slots_multi',
                prestation_ids: JSON.stringify(ids),
                date:           date,
            }, function (data) {
                self._renderSlots($m, data.prestations || []);
            }, function () {
                $panel.html('<p class="etik-slot-error">Erreur de chargement.</p>');
            });
        },

        // ─── Rendu créneaux ───────────────────────────────────────────────────

        _renderSlots: function ($m, prestations) {
            var $panel = $m.find('.etik-slots-panel');

            if (!prestations.length) {
                $panel.html(
                    '<p class="etik-no-slots">' + escHtml(i18n.no_slots || 'Aucun créneau disponible ce jour.') + '</p>'
                );
                return;
            }

            var html = '';
            prestations.forEach(function (p) {
                html += '<div class="etik-prest-group"'
                    + ' data-prestation-id="' + esc(p.id) + '"'
                    + ' data-pay-required="'  + (p.pay_required ? '1' : '0') + '"'
                    + ' data-price="'         + esc(p.price) + '"'
                    + '>';

                html += '<div class="etik-prest-group__header">'
                    + '<span class="etik-prest-group__dot" style="background:' + esc(p.color) + ';"></span>'
                    + '<strong class="etik-prest-group__name">' + escHtml(p.title) + '</strong>';

                if (p.price > 0) {
                    html += '<span class="etik-prest-group__price">' + escHtml(p.price_formatted) + '</span>';
                    if (p.pay_required) {
                        html += '<span class="etik-badge-pay">' + escHtml(i18n.pay_required_badge || 'Paiement requis') + '</span>';
                    }
                }
                html += '</div>'; // __header

                html += '<div class="etik-prest-group__slots">';
                p.slots.forEach(function (s) {
                    var ok  = !!s.available;
                    var dis = ok ? '' : ' disabled aria-disabled="true"';
                    html += '<button type="button" class="etik-slot-btn' + (ok ? '' : ' etik-slot-full') + '"'
                        + ' data-slot-id="' + esc(s.slot_id) + '"'
                        + ' data-time="'    + esc(s.time)    + '"'
                        + dis + '>'
                        + escHtml(s.time)
                        + (!ok ? ' <small>(' + escHtml(i18n.full || 'Complet') + ')</small>' : '')
                        + '</button>';
                });
                html += '</div>'; // __slots
                html += '</div>'; // .etik-prest-group
            });

            $panel.html(html);
        },

        // ─── Formulaire inline (accordéon) ────────────────────────────────────

        openInlineForm: function ($m, $btn) {
            var self     = this;
            var $group   = $btn.closest('.etik-prest-group');
            var pid      = $group.data('prestation-id');
            var slotId   = $btn.data('slot-id');
            var time     = $btn.data('time');
            var date     = $m.data('selected-date');
            var payReq   = $group.data('pay-required') === '1';
            var price    = parseFloat($group.data('price') || '0');
            var returnUrl = $m.data('return-url') || '';
            var submitLbl = (payReq && price > 0)
                ? (i18n.pay_reserve || 'Payer et réserver')
                : (i18n.reserve    || 'Réserver');

            // Toggle : refermer si c'est le même créneau
            var $existing = $group.next('.etik-inline-form-wrap');
            if ($existing.length && $existing.data('slot-id') == slotId) {
                $btn.removeClass('etik-slot-active');
                $existing.slideUp(200, function () { $(this).remove(); });
                return;
            }

            // Retirer tout formulaire ouvert
            $m.find('.etik-inline-form-wrap').remove();
            $m.find('.etik-slot-btn').removeClass('etik-slot-active');
            $btn.addClass('etik-slot-active');

            var recap = '<div class="etik-inline-recap">'
                + '<span>📅 ' + escHtml(formatDateFr(date)) + '</span>'
                + ' <span>🕐 ' + escHtml(time) + '</span>'
                + (price > 0 ? ' <span>💳 ' + escHtml(formatPrice(price)) + '</span>' : '')
                + '</div>';

            var $form = $([
                '<div class="etik-inline-form-wrap" style="display:none;" data-slot-id="' + esc(slotId) + '">',
                recap,
                '<div class="etik-booking-feedback" style="display:none;"></div>',
                '<form class="etik-inline-booking-form" novalidate>',
                '  <input type="hidden" name="prestation_id" value="' + esc(pid)       + '">',
                '  <input type="hidden" name="slot_id"       value="' + esc(slotId)    + '">',
                '  <input type="hidden" name="booking_date"  value="' + esc(date)      + '">',
                '  <input type="hidden" name="booking_time"  value="' + esc(time)      + '">',
                '  <input type="hidden" name="form_id"       value="">',
                '  <input type="hidden" name="return_url"    value="' + esc(returnUrl) + '">',
                '  <div class="etik-custom-fields">',
                '    <span class="etik-loading">' + escHtml(i18n.loading || 'Chargement…') + '</span>',
                '  </div>',
                '  <div class="etik-form-actions">',
                '    <button type="submit" class="etik-btn etik-btn-submit">' + escHtml(submitLbl) + '</button>',
                '    <button type="button" class="etik-cancel-form etik-btn etik-btn-sec">✕ ' + escHtml(i18n.cancel || 'Annuler') + '</button>',
                '  </div>',
                '</form>',
                '</div>',
            ].join(''));

            $group.after($form);
            $form.slideDown(220);
            scrollTo($form);

            // Charger les champs dynamiques du formulaire
            post({
                action:        'etik_get_prestation_form',
                prestation_id: pid,
            }, function (data) {
                $form.find('.etik-custom-fields').html(data.html || '');
                $form.find('input[name="form_id"]').val(data.form_id || 0);
                if (data.payment_required && data.price > 0) {
                    $form.find('.etik-btn-submit').text(i18n.pay_reserve || 'Payer et réserver');
                }
            }, function () {
                $form.find('.etik-custom-fields').html('');
            });
        },

        // ─── Soumission du formulaire ─────────────────────────────────────────

        submitBooking: function ($m, $form) {
            var $wrap = $form.closest('.etik-inline-form-wrap');
            var $fb   = $wrap.find('.etik-booking-feedback');
            var $btn  = $form.find('.etik-btn-submit');
            if (!$btn.data('orig-txt')) $btn.data('orig-txt', $btn.text());

            // Vérifications de base
            var date   = $.trim($form.find('input[name="booking_date"]').val()  || '');
            var time   = $.trim($form.find('input[name="booking_time"]').val()  || '');
            var slotId = $form.find('input[name="slot_id"]').val();
            if (!date || !time || !slotId) {
                showFeedback($fb, 'error', 'Erreur interne : créneau introuvable.');
                return;
            }

            // Champs requis
            var missing = [];
            $form.find('.etik-custom-fields').find('[required]').each(function () {
                var $el = $(this);
                var v   = (($el.attr('type') || '').toLowerCase() === 'checkbox')
                    ? $el.prop('checked')
                    : $.trim($el.val());
                if (!v) {
                    missing.push(
                        $el.closest('.etik-field').find('label').first().text()
                            .replace(/\s*\*\s*$/, '').trim() || $el.attr('name') || '?'
                    );
                }
            });
            if (missing.length) {
                showFeedback($fb, 'error', (i18n.required || 'Champs requis :') + ' ' + missing.join(', '));
                return;
            }

            // Collecte des données
            var data = {
                action: 'etik_book_prestation',
                nonce:  (window.etikBooking || {}).nonce || '',
            };
            $form.find('[name]').each(function () {
                var $el  = $(this);
                var name = $el.attr('name');
                var type = ($el.attr('type') || '').toLowerCase();
                if ((type === 'checkbox' || type === 'radio') && !$el.prop('checked')) return;
                data[name] = $el.val();
            });

            $btn.prop('disabled', true).text(i18n.loading || 'Envoi…');
            $fb.hide();

            $.ajax({
                url:      (window.etikBooking || {}).ajax_url || '/wp-admin/admin-ajax.php',
                method:   'POST',
                data:     data,
                dataType: 'json',
                complete: function (xhr) {
                    $btn.prop('disabled', false).text($btn.data('orig-txt'));

                    var resp;
                    try {
                        resp = xhr.responseJSON || JSON.parse(xhr.responseText);
                    } catch (e) {
                        showFeedback($fb, 'error', 'Réponse invalide.');
                        return;
                    }

                    if (resp && resp.success) {
                        var d = resp.data || {};

                        if (d.status === 'payment_required' && d.checkout_url) {
                            window.location.href = d.checkout_url;
                            return;
                        }

                        // Succès
                        $form.slideUp(150);
                        $wrap.find('.etik-inline-recap').hide();
                        var $success = $([
                            '<div class="etik-inline-success">',
                            '<span>✅</span>',
                            '<span>' + escHtml(d.message || i18n.booking_success || 'Réservation confirmée !') + '</span>',
                            '<button type="button" class="etik-btn-new etik-btn etik-btn-sec">',
                            escHtml(i18n.new_booking || 'Nouvelle réservation'),
                            '</button>',
                            '</div>',
                        ].join('')).appendTo($wrap);

                        // Marquer le créneau comme réservé (UI)
                        var sid    = $wrap.data('slot-id');
                        var $sbtn  = $m.find('.etik-slot-btn[data-slot-id="' + sid + '"]');
                        $sbtn.addClass('etik-slot-full etik-slot-active')
                             .prop('disabled', true);
                        if (!$sbtn.find('small').length) {
                            $sbtn.append(' <small>(' + escHtml(i18n.reserved || 'Réservé') + ')</small>');
                        }

                        scrollTo($success);

                    } else {
                        var msg = (resp && resp.data && resp.data.message)
                            ? resp.data.message
                            : 'Une erreur est survenue.';
                        showFeedback($fb, 'error', msg);
                    }
                },
            });
        },

        // ─── Helpers Internes ─────────────────────────────────────────────────

        setLoading: function ($m, s) {
            $m.find('.etik-calendar-wrap').toggleClass('etik-loading', s);
        },

        buildMultiCalendar: function (year, month, avail) {
            var daysInM   = new Date(year, month, 0).getDate();
            var firstDate = new Date(year, month - 1, 1);
            var firstDow  = firstDate.getDay() || 7; // 1=Lun, 7=Dim
            var today     = todayStr();
            var days      = i18n.days || ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
            var selected  = $('.etik-booking-split').data('selected-date') || today;

            var h = '<div class="etik-calendar etik-view-month">';
            
            // En-tête
            h += '<div class="etik-cal-header">';
            days.forEach(function (d) { h += '<div class="etik-cal-hd">' + escHtml(d) + '</div>'; });
            h += '</div><div class="etik-cal-grid">';

            // Cellules vides
            for (var e = 1; e < firstDow; e++) {
                h += '<div class="etik-cal-day etik-cal-empty"></div>';
            }

            // Jours
            for (var d = 1; d <= daysInM; d++) {
                var ds     = year + '-' + String(month).padStart(2,'0') + '-' + String(d).padStart(2,'0');
                var status = (avail && avail[ds]) || 'none';
                
                var cls = ['etik-cal-day', 'etik-cal-' + status];
                if (ds === today)    cls.push('etik-cal-today');
                if (ds === selected) cls.push('etik-cal-selected');

                var clickable = (status === 'available' || status === 'full');
                var attrs = clickable 
                    ? ' data-date="' + ds + '" role="button" tabindex="0"' 
                    : '';

                h += '<div class="' + escHtml(cls.join(' ')) + '"' + attrs + '>';
                h += '<span class="etik-day-num">' + d + '</span>';
                h += '<span class="etik-day-dot"></span>';
                h += '</div>';
            }

            h += '</div></div>';
            return h;
        }
    };

    // ── Helpers Globaux (Utilitaires) ─────────────────────────────────────────

    function showFeedback($fb, type, msg) {
        $fb.removeClass('etik-fb-error etik-fb-success etik-fb-info')
           .addClass('etik-fb-' + type)
           .html(escHtml(msg))
           .show();
    }

    function scrollTo($el) {
        if (!$el || !$el.length) return;
        $('html,body').animate({ scrollTop: $el.offset().top - 70 }, 300);
    }

    function formatDateFr(ds) {
        if (!ds) return '';
        try {
            var parts  = ds.split('-');
            var mois   = i18n.months || ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
            var jours  = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
            var d      = new Date(ds + 'T00:00:00');
            return jours[d.getDay()] + ' ' + parseInt(parts[2]) + ' ' + mois[parseInt(parts[1]) - 1] + ' ' + parts[0];
        } catch (e) { return ds; }
    }

    function formatPrice(p) { return parseFloat(p || 0).toFixed(2).replace('.', ',') + ' €'; }

    function todayStr() {
        var n = new Date();
        return n.getFullYear() + '-' + pad(n.getMonth() + 1) + '-' + pad(n.getDate());
    }

    function fmtDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    function pad(n) { return n < 10 ? '0' + n : String(n); }

    function esc(s) { return String(s == null ? '' : s); }

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function post(data, ok, fail) {
        var b = (window.etikBooking || {});
        data.nonce = data.nonce || b.nonce || '';
        $.post(b.ajax_url || '/wp-admin/admin-ajax.php', data, function (resp) {
            if (resp && resp.success) ok(resp.data || {});
            else if (fail) fail(resp);
        }).fail(function () { if (fail) fail(null); });
    }

})(jQuery);