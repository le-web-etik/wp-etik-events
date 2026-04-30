/**
 * prestation-booking.js
 *
 * Machine à états : CALENDAR → SLOTS → FORM → SUCCESS
 *
 * Retour paiement : détecte ?etik_booking_paid=1 à l'initialisation et affiche le succès.
 * Vue semaine   : 7 jours glissants dans les données de disponibilité déjà chargées.
 */

(function ($) {
    'use strict';

    var i18n = (window.etikBooking || {}).i18n || {};

    // ── Initialisation ──────────────────────────────────────────────────────
    $(document).ready(function () {
        // Retour paiement : paramètre GET → afficher message de confirmation
        var params = new URLSearchParams(window.location.search);
        if (params.get('etik_booking_paid') === '1') {
            $('.etik-booking-module').each(function () {
                showSuccess($(this), i18n.booking_paid || 'Paiement confirmé — réservation enregistrée.');
            });
            // Nettoyer l'URL sans rechargement
            try {
                var clean = window.location.pathname +
                    (params.toString().replace(/etik_booking_paid=1&?|&?res_id=\d+/g, '') ? '?' + params.toString().replace(/etik_booking_paid=1&?|&?res_id=\d+/g, '').replace(/^&/, '') : '');
                window.history.replaceState({}, '', clean.replace(/\?$/, ''));
            } catch (e) {}
        }

        if (params.get('etik_booking_cancelled') === '1') {
            $('.etik-booking-module').each(function () {
                showFeedback($(this).find('.etik-booking-feedback'), 'error', 'Paiement annulé. Votre créneau reste disponible.');
                $(this).find('.etik-booking-feedback').show();
            });
        }

        // Initialiser chaque module
        $('.etik-booking-module').each(function () {
            Booking.init($(this));
        });
    });

    // ── Objet principal ─────────────────────────────────────────────────────
    var Booking = {

        init: function ($m) {
            var self = this;

            // Navigation mois précédent / suivant
            $m.on('click', '.etik-nav-prev', function () { self.navigateMonth($m, -1); });
            $m.on('click', '.etik-nav-next', function () { self.navigateMonth($m, +1); });

            // Basculement vue mois ↔ semaine
            $m.on('click', '.etik-toggle-view', function () {
                var v = $(this).data('view');
                $m.attr('data-view', v);
                $m.find('.etik-calendar').removeClass('etik-view-month etik-view-week').addClass('etik-view-' + v);
                if (v === 'week') self.initWeekView($m);
            });

            // Navigation semaine
            $m.on('click', '.etik-week-prev', function () { self.navigateWeek($m, -1); });
            $m.on('click', '.etik-week-next', function () { self.navigateWeek($m, +1); });

            // Clic sur un jour disponible
            $m.on('click keypress', '.etik-cal-day[data-date]', function (e) {
                if (e.type === 'keypress' && e.which !== 13) return;
                $m.find('.etik-cal-day').removeClass('etik-cal-selected');
                $(this).addClass('etik-cal-selected');
                self.loadSlots($m, $(this).data('date'));
            });

            // Sélection d'un créneau (radio-tag)
            $m.on('click', '.etik-slot-btn:not([disabled])', function () {
                $m.find('.etik-slot-btn').removeClass('etik-slot-active');
                $(this).addClass('etik-slot-active');
                $m.find('input[name="slot_id"]').val($(this).data('slot-id'));
                $m.find('input[name="booking_time"]').val($(this).data('time'));
                $m.find('.etik-slot-action').show();
            });

            // Bouton "Réserver" → ouvrir le formulaire
            $m.on('click', '.etik-btn-reserve', function () { self.openForm($m); });

            // Retours
            $m.on('click', '.etik-btn-back-cal',   function () { showStep($m, 'calendar'); });
            $m.on('click', '.etik-btn-back-slots',  function () { showStep($m, 'slots'); });

            // Soumission formulaire
            $m.on('submit', '.etik-booking-form', function (e) {
                e.preventDefault();
                self.submitBooking($m, $(this));
            });

            // Nouvelle réservation
            $m.on('click', '.etik-btn-new', function () { Booking.reset($m); });

            // ── Mode multi-prestation : étape 0 ──────────────────────────
            if ( $m.data('prestation-ids') ) {
 
                // Clic sur une carte de prestation → charger le calendrier
                $m.on('click', '.etik-prestation-card', function () {
                    var $card = $(this);
                    var pid   = $card.data('prestation-id');
 
                    // Propager les attributs de paiement sur le module
                    $m.data('prestation-id',    pid).attr('data-prestation-id',    pid);
                    $m.data('pay-required', $card.data('pay-required')).attr('data-pay-required', $card.data('pay-required'));
                    $m.data('price',        $card.data('price')).attr('data-price',        $card.data('price'));
 
                    // Mettre à jour le champ caché du formulaire
                    $m.find('input[name="prestation_id"]').val(pid);
 
                    // Charger le calendrier dynamiquement
                    self.loadCalendarForPrestation($m, pid);
                });
 
                // Retour depuis le calendrier → revenir à la sélection
                $m.on('click', '.etik-btn-back-prestation', function () {
                    $m.removeData('prestation-id').removeAttr('data-prestation-id');
                    $m.find('input[name="prestation_id"]').val('');
                    showStep($m, 'select-prestation');
                    resetSlots($m);
                });
            }

        },

        // ── Navigation mois ──────────────────────────────────────────────────

        navigateMonth: function ($m, delta) {
            var $wrap = $m.find('.etik-calendar-wrap');
            var year  = parseInt($wrap.data('year'),  10);
            var month = parseInt($wrap.data('month'), 10) + delta;

            if (month > 12) { month = 1;  year++; }
            if (month < 1)  { month = 12; year--; }

            setLoading($m, true);
            post({
                action:        'etik_get_month_availability',
                prestation_id: $m.data('prestation-id'),
                year:          year,
                month:         month,
            }, function (data) {
                setLoading($m, false);
                $wrap.data('year',  year).attr('data-year',  year);
                $wrap.data('month', month).attr('data-month', month);
                $wrap.html(buildCalendar(year, month, data));
                $m.find('.etik-month-label').text(monthLabel(year, month));
                var view = $m.attr('data-view') || 'month';
                $m.find('.etik-calendar').addClass('etik-view-' + view);
                if (view === 'week') Booking.initWeekView($m);
                resetSlots($m);
            }, function () { setLoading($m, false); });
        },

        // ── Vue semaine ──────────────────────────────────────────────────────

        initWeekView: function ($m) {
            var $wrap = $m.find('.etik-calendar-wrap');
            var today = todayStr();
            // Chercher la première cellule disponible ou aujourd'hui
            var $target = $m.find('.etik-cal-day[data-date="' + today + '"]');
            if (!$target.length) $target = $m.find('.etik-cal-day[data-date]').first();

            var anchor = $target.length ? $target.data('date') : today;
            $wrap.data('week-anchor', anchor);
            this.renderWeek($m, anchor);

            // Boutons navigation semaine (créés si absents)
            if (!$m.find('.etik-week-nav').length) {
                $m.find('.etik-booking-nav').append(
                    '<span class="etik-week-nav">' +
                    '<button type="button" class="etik-week-prev etik-nav-btn">&#171;</button>' +
                    '<button type="button" class="etik-week-next etik-nav-btn">&#187;</button>' +
                    '</span>'
                );
            }
        },

        navigateWeek: function ($m, delta) {
            var $wrap  = $m.find('.etik-calendar-wrap');
            var anchor = $wrap.data('week-anchor') || todayStr();
            var d      = new Date(anchor);
            d.setDate(d.getDate() + delta * 7);
            var newAnchor = fmtDate(d);
            $wrap.data('week-anchor', newAnchor);
            this.renderWeek($m, newAnchor);
        },

        renderWeek: function ($m, anchor) {
            // Trouver le lundi de la semaine
            var d = new Date(anchor);
            var dow = d.getDay() || 7; // 1=lun…7=dim
            d.setDate(d.getDate() - (dow - 1));

            // Masquer toutes les cellules, puis afficher les 7 jours de la semaine
            var $cells = $m.find('.etik-cal-day');
            $cells.addClass('etik-week-hidden');

            for (var i = 0; i < 7; i++) {
                var ds = fmtDate(d);
                var $c = $m.find('.etik-cal-day[data-date="' + ds + '"]');
                if ($c.length) $c.removeClass('etik-week-hidden');
                // Afficher aussi les cellules vides du début si nécessaire
                d.setDate(d.getDate() + 1);
            }
        },

        // ── Chargement dynamique du calendrier (mode multi) ──────────────────
        loadCalendarForPrestation: function ($m, pid) {
            var self   = this;
            var now    = new Date();
            var year   = now.getFullYear();
            var month  = now.getMonth() + 1;
            var view   = $m.attr('data-view') || 'month';
            var months = i18n.months || ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
            var label  = months[month - 1] + ' ' + year;
            var back   = escHtml( i18n.back_to_prestations || '← Prestations' );
 
            // Construire la nav + le wrapper calendrier dans l'étape 1
            var navHtml =
                '<div class="etik-booking-nav">' +
                    '<button type="button" class="etik-btn-back-prestation etik-nav-btn">' + back + '</button>' +
                    '<button type="button" class="etik-nav-prev" aria-label="Mois précédent">&#8249;</button>' +
                    '<span class="etik-month-label">' + escHtml(label) + '</span>' +
                    '<button type="button" class="etik-nav-next" aria-label="Mois suivant">&#8250;</button>' +
                    '<span style="flex:1"></span>' +
                    '<button type="button" class="etik-toggle-view" data-view="month" title="Vue mensuelle">&#9635;</button>' +
                    '<button type="button" class="etik-toggle-view" data-view="week"  title="Vue hebdomadaire">&#9776;</button>' +
                '</div>' +
                '<div class="etik-calendar-wrap etik-loading" data-year="' + year + '" data-month="' + month + '">' +
                    '<span class="etik-loading">' + escHtml(i18n.loading || 'Chargement…') + '</span>' +
                '</div>';
 
            $m.find('.etik-step-calendar').html(navHtml);
            showStep($m, 'calendar');
            scrollTo($m.find('.etik-step-calendar'));
 
            // Charger la disponibilité via AJAX
            post({
                action:        'etik_get_month_availability',
                prestation_id: pid,
                year:          year,
                month:         month,
            }, function (data) {
                var $wrap = $m.find('.etik-calendar-wrap');
                $wrap.removeClass('etik-loading');
                $wrap.html( buildCalendar(year, month, data) );
                $m.find('.etik-calendar').addClass('etik-view-' + view);
                if (view === 'week') self.initWeekView($m);
            }, function () {
                $m.find('.etik-calendar-wrap').html(
                    '<p class="etik-slot-error">Erreur de chargement du calendrier.</p>'
                );
            });
        },


        // ── Chargement créneaux ──────────────────────────────────────────────

        loadSlots: function ($m, date) {
            showStep($m, 'slots');
            $m.find('.etik-slots-heading').text(formatDateFr(date));
            $m.find('.etik-slots-list').html('<span class="etik-loading">' + (i18n.loading || 'Chargement…') + '</span>');
            $m.find('.etik-slot-action').hide();
            $m.find('input[name="booking_date"]').val(date);

            post({
                action:        'etik_get_day_slots',
                prestation_id: $m.data('prestation-id'),
                date:          date,
            }, function (data) {
                var slots = data.slots || [];
                if (!slots.length) {
                    $m.find('.etik-slots-list').html('<p class="etik-no-slots">' + (i18n.no_slots || 'Aucun créneau disponible.') + '</p>');
                    return;
                }
                var html = '';
                slots.forEach(function (s) {
                    html += '<button type="button" class="etik-slot-btn' + (!s.available ? ' etik-slot-full' : '') + '"'
                        + ' data-slot-id="' + esc(s.slot_id) + '"'
                        + ' data-time="' + esc(s.time) + '"'
                        + (!s.available ? ' disabled aria-disabled="true"' : '')
                        + '>'
                        + esc(s.time)
                        + (!s.available ? ' <small>(' + (i18n.full || 'Complet') + ')</small>' : '')
                        + '</button>';
                });
                $m.find('.etik-slots-list').html(html);
                scrollTo($m.find('.etik-step-slots'));
            }, function () {
                $m.find('.etik-slots-list').html('<p class="etik-slot-error">Erreur de chargement.</p>');
            });
        },

        // ── Ouverture formulaire ──────────────────────────────────────────────

        openForm: function ($m) {
            showStep($m, 'form');
            var $custom  = $m.find('.etik-custom-fields');
            var $submit  = $m.find('.etik-btn-submit');
            var payReq   = $m.attr('data-pay-required') === '1';
            var price    = parseFloat($m.attr('data-price') || '0');
            var date     = $m.find('input[name="booking_date"]').val();
            var time     = $m.find('input[name="booking_time"]').val();

            // Libellé bouton
            $submit.text(payReq && price > 0 ? (i18n.pay_reserve || 'Payer et réserver') : (i18n.reserve || 'Réserver'));

            // Récapitulatif
            $m.find('.etik-booking-recap').html(
                '<div class="etik-recap">'
                + '<span>📅 ' + esc(formatDateFr(date)) + '</span>'
                + ' <span>🕐 ' + esc(time) + '</span>'
                + (payReq && price > 0 ? ' <span>💳 ' + formatPrice(price) + '</span>' : '')
                + '</div>'
            );

            // Charger les champs du formulaire personnalisé
            $custom.html('<span class="etik-loading">' + (i18n.loading || 'Chargement…') + '</span>');
            post({
                action:        'etik_get_prestation_form',
                prestation_id: $m.data('prestation-id'),
            }, function (data) {
                $custom.html(data.html || '');
                $m.find('input[name="form_id"]').val(data.form_id || 0);
                if (data.payment_required) $submit.text(i18n.pay_reserve || 'Payer et réserver');
            }, function () { $custom.html(''); });

            scrollTo($m.find('.etik-step-form'));
        },

        // ── Soumission ────────────────────────────────────────────────────────
        // Pattern identique à celui de l'inscription événement (etik-insc-form) :
        //   1. Vérifier les champs contextuels (date/heure — noms fixes)
        //   2. Validation dynamique des [required] avec label pour le message
        //   3. Collecte dynamique de tous les champs nommés (pas de hardcoding)
        //   4. $.ajax() avec complete: pour lire le JSON même sur les 4xx/5xx

        submitBooking: function ($m, $form) {
            var $fb  = $m.find('.etik-booking-feedback');
            var $btn = $form.find('.etik-btn-submit');
            if (!$btn.data('orig-txt')) $btn.data('orig-txt', $btn.text());

            // ── 1. Champs de contexte (hidden, noms fixes) ─────────────────────
            var date = $.trim($form.find('input[name="booking_date"]').val() || '');
            var time = $.trim($form.find('input[name="booking_time"]').val() || '');
            var slotId = $form.find('input[name="slot_id"]').val();

            if (!date || !time || !slotId) {
                showFeedback($fb, 'error', 'Veuillez sélectionner un jour et un créneau.');
                return;
            }

            // ── 2. Validation dynamique et Debug Email ─────────────────────────
            // On récupère TOUS les champs pour voir ce qui est envoyé
            var formData = {};
            var missing = [];
            var emailValue = '';

            $form.find('.etik-custom-fields')
                 .find('input, select, textarea')
                 .not('[type="hidden"]')
                 .not('[disabled]')
                 .each(function () {
                     var $el        = $(this);
                     var name       = $el.attr('name');
                     if (!name) return;

                     var isRequired = $el.prop('required') || $el.data('required') == 1;
                     var type       = ($el.attr('type') || '').toLowerCase();
                     var val        = '';

                     if (type === 'checkbox') {
                         var checked = $form.find('input[type="checkbox"][name="' + name + '"]:checked').length;
                         val = checked > 0 ? '1' : '0';
                         // Si requis et non coché
                         if (isRequired && !checked) {
                             var lbl = $el.closest('label').text().trim() || name;
                             missing.push(lbl);
                         }
                     } else if (type === 'radio') {
                         var $checked = $form.find('input[type="radio"][name="' + name + '"]:checked');
                         val = $checked.length ? $checked.val() : '';
                         if (isRequired && !val) {
                             var lbl = $form.find('input[type="radio"][name="' + name + '"]').closest('label').first().text().trim() || name;
                             missing.push(lbl);
                         }
                     } else {
                         val = $.trim($el.val() || '');
                         if (isRequired && !val) {
                             var lbl = $el.closest('label').text().replace('*', '').trim() || name;
                             missing.push(lbl);
                         }
                     }

                     // Stockage pour debug et envoi
                     formData[name] = val;

                     // Capture spécifique de l'email pour validation stricte
                     if (type === 'email' && val) {
                         emailValue = val;
                     }
                 });

            // Validation stricte de l'email SI un champ de type email a été trouvé
            if (emailValue && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
                showFeedback($fb, 'error', 'Adresse e-mail invalide (format incorrect).');
                // Focus sur le champ email
                $form.find('.etik-custom-fields input[type="email"]').first().focus();
                return;
            }
            
            // Si aucun champ email n'a été trouvé par le sélecteur [type="email"], 
            // mais qu'on soupçonne qu'il est requis, on vérifie dans formData si une clé ressemble à un email
            // (Optionnel, pour le debug)
            if (!emailValue) {
                console.warn('[ETIK BOOKING] Aucun champ input[type="email"] trouvé dans le formulaire. Vérifiez le Form Builder.');
                // On laisse passer, car le serveur fera la validation finale, 
                // mais c'est souvent la cause de l'erreur si le type est "text" au lieu de "email".
            }

            if (missing.length) {
                showFeedback($fb, 'error',
                    (i18n.required || 'Champs obligatoires manquants') + ' : ' + missing.join(', ') + '.'
                );
                $form.find('.etik-custom-fields')
                     .find('[required], [data-required="1"]')
                     .filter(function () { 
                         var type = ($(this).attr('type') || '').toLowerCase();
                         if (type === 'checkbox') return !$(this).is(':checked');
                         return !$.trim($(this).val() || '');
                     })
                     .first().focus();
                return;
            }

            // ── 3. Préparation des données d'envoi ────────────────────────────────
            var postData = {
                action:        'etik_book_prestation',
                nonce:         (window.etikBooking || {}).nonce || '',
                prestation_id: $m.data('prestation-id'),
                return_url:    $m.data('return-url') || window.location.href,
            };

            // Fusion des champs hidden de contexte
            $form.find('input[type="hidden"]').each(function () {
                var name = $(this).attr('name');
                if (name) postData[name] = $(this).val();
            });

            // Fusion des champs dynamiques (déjà collectés dans formData)
            // On utilise la boucle précédente pour éviter de relire le DOM
            for (var key in formData) {
                if (formData.hasOwnProperty(key)) {
                    postData[key] = formData[key];
                }
            }

            // DEBUG : Afficher ce qui est envoyé dans la console (F12)
            console.log('[ETIK BOOKING] Données envoyées :', postData);

            // ── 4. Envoi AJAX ─────────────────────────────────────────────────────
            $btn.prop('disabled', true).addClass('etik-loading');
            showFeedback($fb, 'info', i18n.loading || 'Envoi en cours…');

            $.ajax({
                url:      (window.etikBooking || {}).ajax_url || '/wp-admin/admin-ajax.php',
                method:   'POST',
                data:     postData,
                dataType: 'json',

                complete: function (xhr) {
                    $btn.prop('disabled', false).removeClass('etik-loading').text($btn.data('orig-txt'));

                    var resp = null;
                    try { resp = JSON.parse(xhr.responseText); } catch (e) {
                        console.error('[ETIK BOOKING] Réponse non JSON :', xhr.responseText);
                    }

                    if (!resp) {
                        showFeedback($fb, 'error', 'Erreur réseau. Vérifiez la console (F12).');
                        return;
                    }

                    if (resp.success) {
                        var d = resp.data || {};
                        if (d.status === 'payment_required' && d.checkout_url) {
                            window.location.href = d.checkout_url;
                            return;
                        }
                        if (d.status === 'confirmed') {
                            showSuccess($m, d.message || i18n.booking_success || 'Réservation confirmée !');
                            return;
                        }
                        showSuccess($m, d.message || i18n.booking_success || 'Réservation enregistrée.');

                    } else {
                        // Affichage de l'erreur précise renvoyée par le serveur
                        var errMsg = (resp.data && resp.data.message) ? resp.data.message : 'Une erreur est survenue.';
                        console.error('[ETIK BOOKING] Erreur serveur :', resp.data);
                        showFeedback($fb, 'error', errMsg);
                    }
                }
            });
        },

        // ── Réinitialisation (mise à jour pour le mode multi) ────────────────
        reset: function ($m) {
            if ( $m.data('prestation-ids') ) {
                // Mode multi : revenir au choix de prestation
                $m.removeData('prestation-id').removeAttr('data-prestation-id');
                $m.find('input[name="prestation_id"]').val('');
                showStep($m, 'select-prestation');
            } else {
                // Mode mono : revenir au calendrier
                showStep($m, 'calendar');
            }
            $m.find('.etik-cal-day').removeClass('etik-cal-selected');
            $m.find('input[name="slot_id"], input[name="booking_date"], input[name="booking_time"]').val('');
            $m.find('.etik-booking-feedback').hide();
        },
    };

    // ── Construction calendrier côté client (après navigation mois) ─────────

    function buildCalendar(year, month, avail) {
        var daysInM   = new Date(year, month, 0).getDate();
        var firstDate = new Date(year, month - 1, 1);
        var firstDow  = firstDate.getDay() || 7; // 1=lun
        var today     = todayStr();
        var days      = i18n.days || ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

        var h = '<div class="etik-calendar etik-view-month"><div class="etik-cal-header">';
        days.forEach(function (d) { h += '<div class="etik-cal-hd">' + escHtml(d) + '</div>'; });
        h += '</div><div class="etik-cal-grid">';
        for (var e = 1; e < firstDow; e++) h += '<div class="etik-cal-day etik-cal-empty"></div>';

        for (var d = 1; d <= daysInM; d++) {
            var ds     = year + '-' + pad(month) + '-' + pad(d);
            var status = (avail && avail[ds]) || 'none';
            var cls    = 'etik-cal-day etik-cal-' + status;
            if (ds === today) cls += ' etik-cal-today';
            var click  = (status === 'available' || status === 'full') ? ' data-date="' + ds + '" role="button" tabindex="0"' : '';
            h += '<div class="' + cls + '"' + click + '><span class="etik-day-num">' + d + '</span><span class="etik-day-dot"></span></div>';
        }

        h += '</div></div>';
        return h;
    }

    // ── Helpers UI ───────────────────────────────────────────────────────────

    function showStep($m, step) {
        $m.find('.etik-booking-step').hide();
        $m.find('.etik-step-' + step).show();
    }

    function showSuccess($m, msg) {
        showStep($m, 'success');
        $m.find('.etik-success-msg').text(msg);
    }

    function showFeedback($fb, type, msg) {
        $fb.removeClass('etik-fb-error etik-fb-success etik-fb-info')
           .addClass('etik-fb-' + type)
           .html(escHtml(msg))
           .show();
    }

    function resetSlots($m) {
        $m.find('.etik-step-slots').hide();
        $m.find('.etik-step-form').hide();
        $m.find('.etik-slot-action').hide();
        $m.find('input[name="slot_id"], input[name="booking_time"], input[name="booking_date"]').val('');
    }

    function setLoading($m, s) {
        $m.find('.etik-calendar-wrap').toggleClass('etik-loading', s);
    }

    function scrollTo($el) {
        if (!$el.length) return;
        $('html,body').animate({ scrollTop: $el.offset().top - 60 }, 300);
    }

    // ── Helpers dates ────────────────────────────────────────────────────────

    function monthLabel(year, month) {
        var months = i18n.months || ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
        return months[month - 1] + ' ' + year;
    }

    function formatDateFr(ds) {
        if (!ds) return '';
        try {
            var p = ds.split('-');
            var months = i18n.months || ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
            return parseInt(p[2]) + ' ' + months[parseInt(p[1]) - 1] + ' ' + p[0];
        } catch (e) { return ds; }
    }

    function formatPrice(p) { return parseFloat(p).toFixed(2).replace('.', ',') + ' €'; }

    function todayStr() {
        var n = new Date();
        return n.getFullYear() + '-' + pad(n.getMonth() + 1) + '-' + pad(n.getDate());
    }

    function fmtDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    function pad(n) { return n < 10 ? '0' + n : String(n); }

    // ── Helpers sécurité ─────────────────────────────────────────────────────

    function esc(s) { return escHtml(String(s || '')); }

    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── AJAX helper ──────────────────────────────────────────────────────────

    function post(data, ok, fail) {
        var b = (window.etikBooking || {});
        data.nonce = data.nonce || b.nonce || '';
        $.post(b.ajax_url || '/wp-admin/admin-ajax.php', data, function (resp) {
            if (resp && resp.success) ok(resp.data || {});
            else if (fail) fail(resp);
        }).fail(function () { if (fail) fail(null); });
    }

})(jQuery);