/**
 * etik-inscription.js
 * jQuery handler pour la modal d'inscription et le mode Full Picture.
 *
 * Déclencheurs de la modale :
 *   - .etik-formation-btn        (bouton texte, mode carte)
 *   - .etik-formation-btn-img    (image cliquable, mode full picture)
 *
 * Les deux portent les attributs :
 *   data-event="<post_id>"
 *   data-title="<titre de l'événement>"
 *
 * Dépend de : jquery, wp-etik-utils (EtikUtils)
 */

(function($){
  'use strict';

  var ajaxUrl         = (window.WP_ETIK_AJAX && WP_ETIK_AJAX.ajax_url)         ? WP_ETIK_AJAX.ajax_url         : '/wp-admin/admin-ajax.php';
  var globalNonce     = (window.WP_ETIK_AJAX && WP_ETIK_AJAX.nonce)            ? WP_ETIK_AJAX.nonce            : '';
  var hcaptchaSiteKey = (window.WP_ETIK_AJAX && WP_ETIK_AJAX.hcaptcha_sitekey) ? WP_ETIK_AJAX.hcaptcha_sitekey : '';

  var HCAPTCHA_WIDGET_KEY = 'etik_hcaptcha_widget';

  // ── Helpers locaux (délèguent à EtikUtils si dispo, sinon fallback) ──────────

  function showFeedback($m, type, msg) {
    if (window.EtikUtils) {
      EtikUtils.showFeedback($m, type, msg);
    } else {
      var $fb = $m.find('.etik-feedback');
      if (!$fb.length) {
        $fb = $('<div class="etik-feedback" aria-live="polite"></div>').appendTo($m.find('.etik-modal-content').first());
      }
      $fb.removeClass('success error').addClass(type || '').html(msg || '').show();
    }
  }

  function clearFeedback($m) {
    if (window.EtikUtils) {
      EtikUtils.clearFeedback($m);
    } else {
      $m.find('.etik-feedback').hide().removeClass('success error').text('');
    }
  }

  function closeModal($m) {
    // Réinitialiser hCaptcha si présent
    try {
      var widgetId = $m.data(HCAPTCHA_WIDGET_KEY);
      if (typeof hcaptcha !== 'undefined' && widgetId !== undefined) {
        hcaptcha.reset(widgetId);
      }
    } catch(e) {}

    if (window.EtikUtils) {
      EtikUtils.closeModal($m);
    } else {
      $m.attr('aria-hidden', 'true');
    }
  }

  // ── Sélecteur modal global ────────────────────────────────────────────────────

  function $modal() { return $('#etik-global-modal'); }

  // ── hCaptcha ──────────────────────────────────────────────────────────────────

  function initHCaptcha($m) {
    if (!hcaptchaSiteKey || typeof hcaptcha === 'undefined') return;
    var placeholder = $m.find('.etik-hcaptcha-placeholder')[0];
    if (!placeholder) return;
    var existing = $m.data(HCAPTCHA_WIDGET_KEY);
    if (existing === undefined || existing === null) {
      try {
        var widgetId = hcaptcha.render(placeholder, { sitekey: hcaptchaSiteKey });
        $m.data(HCAPTCHA_WIDGET_KEY, widgetId);
      } catch(e) {
        console.warn('[Etik] hCaptcha render error:', e);
      }
    } else {
      try { hcaptcha.reset(existing); } catch(e) {}
    }
  }

  // ── Ouverture de la modal ─────────────────────────────────────────────────────

   // ── Cache local des formulaires (évite AJAX répétés) ─────────────────────
  var formCache = {};
 
  // ── Chargement dynamique du formulaire ────────────────────────────────────
 
  // ── Chargement dynamique du formulaire ────────────────────────────────────
 
  function loadFormFields(eventId, $m, callback) {
    var cacheKey = 'event_' + eventId;
 
    // 1. Vérifier le cache
    if (formCache[cacheKey] !== undefined) {
      callback(formCache[cacheKey]);
      return;
    }
 
    var $container = $m.find('#etik-form-fields-container');
    $container.html(
      '<div class="etik-form-loading" style="text-align:center;padding:24px;color:#888;">'
      + 'Chargement du formulaire...'
      + '</div>'
    );
 
    // 2. Récupération SÉCURISÉE des variables AJAX
    var reqAjaxUrl = '/wp-admin/admin-ajax.php';
    var reqNonce = '';
    
    if (window.WP_ETIK_AJAX) {
        if (WP_ETIK_AJAX.ajax_url) reqAjaxUrl = WP_ETIK_AJAX.ajax_url;
        if (WP_ETIK_AJAX.form_nonce) reqNonce = WP_ETIK_AJAX.form_nonce;
    }

    // DEBUG CONSOLE (À laisser temporairement pour vérifier)
    console.log('[Etik Debug] AJAX URL:', reqAjaxUrl);
    console.log('[Etik Debug] Nonce reçu:', reqNonce ? 'OK ( longueur: ' + reqNonce.length + ')' : 'VIDE !');

    if (!reqNonce) {
        console.error('[Etik Erreur] Le nonce form_nonce est vide. Vérifiez wp_localize_script dans PHP.');
        callback(null, 'Erreur de configuration (nonce manquant).');
        return;
    }
 
    // 3. Requête AJAX
    $.post(
      reqAjaxUrl,
      {
        action:   'etik_get_form_html',
        nonce:    reqNonce, // Utilisation de la variable vérifiée
        event_id: eventId,
      },
      function(res) {
        if (res && res.success && res.data && res.data.html) {
          formCache[cacheKey] = res.data.html;
          callback(res.data.html);
        } else {
          var msg = 'Erreur inconnue.';
          if (res && res.data && res.data.message) {
              msg = res.data.message;
          } else if (res && typeof res === 'string') {
              msg = 'Réponse inattendue: ' + res.substring(0, 100);
          }
          formCache[cacheKey] = null;
          callback(null, msg);
        }
      },
      'json'
    ).fail(function(xhr, status, error) {
        console.error('[Etik Erreur] Échec AJAX:', status, error);
        console.error('[Etik Erreur] Réponse brute:', xhr.responseText);
        callback(null, 'Erreur de connexion (' + status + ').');
    });
  }
 
  // ── Ouverture de la modal (REMPLACE l'ancienne openModal) ─────────────────
 
  function openModal(eventId, title) {
    var $m = $modal();
    if (!$m.length) {
      console.warn('[Etik] Modal #etik-global-modal introuvable.');
      return;
    }
 
    // event_id dans le formulaire caché
    $m.find('input[name="event_id"]').val(eventId);
 
    // Titre
    var $titleEl = $('#etik-modal-title');
    if ($titleEl.length) {
      $titleEl.text(title ? ('Inscription : ' + title) : 'Inscription');
    }
 
    // Reset + feedback
    clearFeedback($m);
 
    // Ouvrir immédiatement (squelette vide visible pendant le chargement)
    $m.attr('aria-hidden', 'false');
 
    // Charger les champs via AJAX
    loadFormFields(eventId, $m, function(html, errorMsg) {
      var $container = $m.find('#etik-form-fields-container');
 
      if (html) {
        $container.html(html);
 
        // Remettre event_id (reset possible après injection)
        $m.find('input[name="event_id"]').val(eventId);
 
        // Initialiser la logique conditionnelle
        initConditionalFields($m);
 
        // Focus premier champ
        setTimeout(function(){
          $m.find('input:visible:not([type=hidden]), textarea:visible, select:visible').first().focus();
        }, 60);
 
      } else {
        // Inscriptions désactivées ou erreur
        $container.html(
          '<div class="etik-form-disabled" style="text-align:center;padding:20px;color:#888;">'
          + '<p>' + (errorMsg || 'Inscriptions non disponibles.') + '</p>'
          + '</div>'
        );
        // Masquer le bouton submit
        $m.find('.etik-submit-btn').hide();
        return;
      }
 
      $m.find('.etik-submit-btn').show();
    });
 
    // hCaptcha
    if (hcaptchaSiteKey) initHCaptcha($m);
  }
 
  // ── Logique conditionnelle (radio → show/hide/msg) ────────────────────────
 
  function initConditionalFields($m) {
    // Écouter les changements sur les champs radio/select qui ont des dépendants
    $m.find('[data-cond-field]').each(function() {
      var condField  = $(this).data('cond-field');
      var condValue  = String( $(this).data('cond-value') );
      var condAction = $(this).data('cond-action');
      var condMsg    = $(this).data('cond-msg') || '';
      var $target    = $(this);
 
      // Écouter le champ source
      $m.on('change.cond', '[name="' + condField + '"]', function() {
        var val = String( $(this).val() );
        var matches = (val === condValue);
 
        if (condAction === 'show_field') {
          $target.toggle(matches);
          $target.find('input, select, textarea').prop('disabled', !matches);
 
        } else if (condAction === 'show_msg') {
          // Le message est rendu dans un div.etik-cond-msg[data-for="field_key"]
          $m.find('.etik-cond-msg[data-for="' + condField + '"]').toggle(matches);
        }
      });
    });
  }
 
  // ── Nettoyage des écouteurs conditionnels à la fermeture ─────────────────
  // (Ajouter ceci dans la fonction closeModal existante ou en écoute)
  $(document).on('click', '.etik-modal-backdrop, .etik-modal-close, [data-modal-close]', function(){
    var $m = $(this).closest('.etik-modal');
    if ($m.length) {
      $m.off('.cond'); // retirer les écouteurs conditionnels
    }
  });

  // ── Déclencheurs ──────────────────────────────────────────────────────────────

  // Mode carte : bouton texte "S'inscrire"
  $(document).on('click', '.etik-formation-btn', function(e){
    e.preventDefault();
    var $btn = $(this);
    openModal(
      $btn.data('event') || $btn.attr('data-event') || '',
      $btn.data('title') || $btn.attr('data-title') || ''
    );
  });

  // Mode full picture : clic sur l'image (bouton .etik-formation-btn-img)
  $(document).on('click', '.etik-formation-btn-img', function(e){
    e.preventDefault();
    var $btn = $(this);
    openModal(
      $btn.data('event') || $btn.attr('data-event') || '',
      $btn.data('title') || $btn.attr('data-title') || ''
    );
  });

  // ── Fermeture ─────────────────────────────────────────────────────────────────

  $(document).on('click', '.etik-modal-backdrop, .etik-modal-close, [data-modal-close]', function(e){
    e.preventDefault();
    var $m = $(this).closest('.etik-modal');
    if ($m.length) closeModal($m);
  });

  $(document).on('keydown', function(e){
    if (e.key === 'Escape' || e.keyCode === 27) {
      var $m = $modal();
      if ($m.length && $m.attr('aria-hidden') === 'false') closeModal($m);
    }
  });

  // ── Focus trap (accessibilité) ────────────────────────────────────────────────

  $(document).on('keydown', '.etik-modal[aria-hidden="false"]', function(e){
    if (e.key !== 'Tab' && e.keyCode !== 9) return;
    var $m       = $(this);
    var focusable = $m.find('a[href], area[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex]').filter(':visible');
    if (!focusable.length) return;
    var first = focusable.first()[0];
    var last  = focusable.last()[0];
    if (e.shiftKey) {
      if (document.activeElement === first) { e.preventDefault(); last.focus(); }
    } else {
      if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
    }
  });

  // ── Soumission formulaire inscription ─────────────────────────────────────────

  // ── Soumission formulaire inscription ────────────────────────────────────────

  $(document).on('submit', '.etik-insc-form', function(e){
    e.preventDefault();
    var $form = $(this);
    var $m    = $form.closest('.etik-modal');
    clearFeedback($m);

    var event_id = $.trim($form.find('input[name="event_id"]').val() || '');
    if (!event_id) {
      showFeedback($m, 'error', 'Événement introuvable.');
      return;
    }

    // ── 1. Validation dynamique ───────────────────────────────────────────────
    // On parcourt TOUS les champs requis du formulaire tel qu'il a été rendu,
    // sans présumer de leurs noms. Chaque champ requis porte l'attribut
    // [required] ou [data-required="1"] posé par render_form_fields().

    var missing = [];

    $form.find('#etik-form-fields-container')
         .find('input, select, textarea')
         .not('[type="hidden"]')
         .not('[disabled]')
         .each(function(){
            var $el       = $(this);
            var isRequired = $el.prop('required') || $el.data('required') == 1;
            if (!isRequired) return; // champ optionnel → on skip

            var val  = $.trim($el.val() || '');
            var type = ($el.attr('type') || '').toLowerCase();

            // Gestion checkbox standalone (consent, etc.)
            if (type === 'checkbox' && !$el.is(':checked')) {
              val = '';
            }

            // Gestion groupe checkbox (checkbox_group) :
            // au moins une case cochée dans le groupe
            if (type === 'checkbox') {
              var name    = $el.attr('name') || '';
              var checked = $form.find('input[type="checkbox"][name="' + name + '"]:checked').length;
              if (checked === 0) {
                val = '';
              } else {
                val = 'ok'; // groupe valide
              }
            }

            if (!val) {
              // Retrouver le label associé pour un message utile
              var fieldName = $el.attr('name') || '';
              var $label    = $form.find('label[for="' + $el.attr('id') + '"]');
              var labelText = $.trim($label.text().replace('*', '').replace(':', '')) || fieldName;
              missing.push(labelText);
            }
         });

    // Valider aussi l'email si présent (format)
    var $emailField = $form.find('input[type="email"], input[name="email"]').first();
    if ($emailField.length) {
      var emailVal = $.trim($emailField.val() || '');
      if (emailVal && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
        showFeedback($m, 'error', 'Adresse e-mail invalide.');
        $emailField.focus();
        return;
      }
    }

    if (missing.length) {
      showFeedback($m, 'error',
        'Veuillez remplir les champs obligatoires : ' + missing.join(', ') + '.'
      );
      // Focus sur le premier champ manquant
      $form.find('#etik-form-fields-container')
           .find('[required], [data-required="1"]')
           .filter(function(){ return !$.trim($(this).val()); })
           .first().focus();
      return;
    }

    // ── 2. Collecte dynamique de TOUS les champs nommés du formulaire ─────────
    // On ne hardcode plus first_name/email/phone : on sérialise tout.

    var postData = { action: 'lwe_create_checkout', event_id: event_id, nonce: globalNonce };

    $form.find('#etik-form-fields-container')
         .find('input, select, textarea')
         .not('[disabled]')
         .each(function(){
            var $el  = $(this);
            var name = $el.attr('name');
            if (!name) return;

            var type = ($el.attr('type') || '').toLowerCase();

            // Ignorer les cases non cochées (sauf si elles sont seules dans leur groupe)
            if (type === 'checkbox') {
              if ($el.is(':checked')) {
                // Valeur envoyée quand cochée
                postData[name] = $el.val() || '1';
              } else {
                // Si déjà dans postData (groupe) → on ne réécrit pas
                if (!(name in postData)) postData[name] = '0';
              }
              return;
            }

            // Radio : ne garder que le bouton sélectionné
            if (type === 'radio') {
              if ($el.is(':checked')) postData[name] = $el.val();
              return;
            }

            // Champ texte/email/tel/select/textarea
            postData[name] = $.trim($el.val() || '');
         });

    // ── 3. hCaptcha ───────────────────────────────────────────────────────────
    if (hcaptchaSiteKey && typeof hcaptcha !== 'undefined') {
      var widgetId = $m.data(HCAPTCHA_WIDGET_KEY);
      if (widgetId !== undefined) {
        var token = hcaptcha.getResponse(widgetId);
        if (!token) {
          showFeedback($m, 'error', 'Veuillez compléter le captcha.');
          return;
        }
        postData['h-captcha-response'] = token;
      }
    }

    // ── 4. Envoi AJAX ─────────────────────────────────────────────────────────
    var $btn = $form.find('.etik-submit-btn');
    $btn.prop('disabled', true).addClass('etik-loading');

    $.post(ajaxUrl, postData, function(resp){
      $btn.prop('disabled', false).removeClass('etik-loading');

      if (resp.success) {
        var d = resp.data || {};

        if (d.checkout_url) {
          window.location.href = d.checkout_url;
          return;
        }

        if (d.status === 'waitlist') {
          showFeedback($m, 'info', d.message || "Vous avez été ajouté(e) à la liste d'attente.");
          return;
        }

        if (d.status === 'confirmed') {
          showFeedback($m, 'success', d.message || 'Inscription confirmée !');
          return;
        }

        showFeedback($m, 'success', d.message || 'Inscription enregistrée.');

      } else {
        var errMsg = (resp.data && resp.data.message)
          ? resp.data.message
          : 'Une erreur est survenue. Veuillez réessayer.';

        // Réinitialiser hCaptcha si le token était invalide
        if (resp.data && resp.data.code === 'captcha_failed') {
          if (typeof hcaptcha !== 'undefined') {
            var wId = $m.data(HCAPTCHA_WIDGET_KEY);
            if (wId !== undefined) hcaptcha.reset(wId);
          }
        }

        showFeedback($m, 'error', errMsg);
      }
    }).fail(function(){
      $btn.prop('disabled', false).removeClass('etik-loading');
      showFeedback($m, 'error', 'Erreur réseau. Veuillez réessayer.');
    });

  });

  // ── Init ──────────────────────────────────────────────────────────────────────

  $(function(){
    var $m = $modal();
    if ($m.length) {
      $m.attr('aria-hidden', 'true');
    }
  });

})(jQuery);