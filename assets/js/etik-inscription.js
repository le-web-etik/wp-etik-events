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
 
  function loadFormFields(eventId, $m, callback) {
    var cacheKey = 'event_' + eventId;
 
    if (formCache[cacheKey] !== undefined) {
      callback(formCache[cacheKey]);
      return;
    }
 
    var $container = $m.find('#etik-form-fields-container');
    $container.html(
      '<div class="etik-form-loading" style="text-align:center;padding:24px;color:#888;">'
      + (window.WP_ETIK_AJAX && WP_ETIK_AJAX.i18n_loading ? WP_ETIK_AJAX.i18n_loading : 'Chargement…')
      + '</div>'
    );
 
    $.post(
      (window.WP_ETIK_AJAX && WP_ETIK_AJAX.ajax_url) || '/wp-admin/admin-ajax.php',
      {
        action:   'etik_get_form_html',
        nonce:    (window.WP_ETIK_AJAX && WP_ETIK_AJAX.form_nonce) || '',
        event_id: eventId,
      },
      function(res) {
        if (res && res.success && res.data && res.data.html) {
          formCache[cacheKey] = res.data.html;
          callback(res.data.html);
        } else {
          var msg = (res && res.data && res.data.message)
            ? res.data.message
            : 'Les inscriptions ne sont pas disponibles pour cet événement.';
          formCache[cacheKey] = null;
          callback(null, msg);
        }
      },
      'json'
    ).fail(function() {
      callback(null, 'Erreur de connexion.');
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

  $(document).on('submit', '.etik-insc-form', function(e){
    e.preventDefault();
    var $form = $(this);
    var $m    = $form.closest('.etik-modal');
    clearFeedback($m);

    var first_name     = $.trim($form.find('input[name="first_name"]').val()     || '');
    var last_name      = $.trim($form.find('input[name="last_name"]').val()      || '');
    var email          = $.trim($form.find('input[name="email"]').val()          || '');
    var phone          = $.trim($form.find('input[name="phone"]').val()          || '');
    var desired_domain = $.trim($form.find('input[name="desired_domain"]').val() || '');
    var has_domain     = $form.find('input[name="has_domain"]').is(':checked') ? 1 : 0;
    var event_id       = $.trim($form.find('input[name="event_id"]').val()       || '');

    // Validation côté client
    if (!first_name || !email || !phone || !event_id) {
      showFeedback($m, 'error', 'Veuillez remplir les champs obligatoires : Prénom, E-mail et Téléphone.');
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showFeedback($m, 'error', 'Adresse e-mail invalide.');
      return;
    }

    var action = $form.find('input[name="action"]').val() || 'lwe_create_checkout';

    var postData = {
      action:         action,
      first_name:     first_name,
      last_name:      last_name,
      email:          email,
      phone:          phone,
      desired_domain: desired_domain,
      has_domain:     has_domain,
      event_id:       event_id,
      nonce:          globalNonce
    };

    // hCaptcha
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

    var $submit = $form.find('button[type="submit"]').prop('disabled', true);
    showFeedback($m, '', 'Envoi en cours…');

    $.post(ajaxUrl, postData, function(resp){
      $submit.prop('disabled', false);

      if (resp && resp.success) {
        var data = resp.data || {};

        // Redirection Stripe Checkout si URL fournie
        if (data.checkout_url) {
          showFeedback($m, 'success', 'Redirection vers le paiement…');
          setTimeout(function(){ window.location.href = data.checkout_url; }, 800);
          return;
        }

        showFeedback($m, 'success', data.message || 'Inscription enregistrée. Vérifiez votre e-mail pour confirmer.');
        setTimeout(function(){ closeModal($m); }, 2200);

      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Une erreur est survenue.';
        showFeedback($m, 'error', msg);
        // Reset hCaptcha
        try {
          var w = $m.data(HCAPTCHA_WIDGET_KEY);
          if (typeof hcaptcha !== 'undefined' && w !== undefined) { hcaptcha.reset(w); }
        } catch(err) {}
      }
    }, 'json').fail(function(){
      $submit.prop('disabled', false);
      showFeedback($m, 'error', 'Erreur réseau. Veuillez réessayer.');
      try {
        var w2 = $m.data(HCAPTCHA_WIDGET_KEY);
        if (typeof hcaptcha !== 'undefined' && w2 !== undefined) { hcaptcha.reset(w2); }
      } catch(err) {}
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