/**
 * etik-inscription.js
 * jQuery handler pour la modal d'inscription et le mode Full Picture.
 *
 * Déclencheurs de la modale :
 *   - .etik-formation-btn        (bouton texte, mode carte)
 *   - .etik-formation-btn-img    (image cliquable, mode full picture) ← nouveau
 *
 * Les deux portent les attributs :
 *   data-event="<post_id>"
 *   data-title="<titre de l'événement>"
 */

(function($){
  'use strict';

  var ajaxUrl         = (window.WP_ETIK_AJAX && WP_ETIK_AJAX.ajax_url)         ? WP_ETIK_AJAX.ajax_url         : '/wp-admin/admin-ajax.php';
  var globalNonce     = (window.WP_ETIK_AJAX && WP_ETIK_AJAX.nonce)            ? WP_ETIK_AJAX.nonce            : '';
  var hcaptchaSiteKey = (window.WP_ETIK_AJAX && WP_ETIK_AJAX.hcaptcha_sitekey) ? WP_ETIK_AJAX.hcaptcha_sitekey : '';

  // ── Helpers ──────────────────────────────────────────────────────────────────

  function $modal() { return $('#etik-global-modal'); }

  function showFeedback($m, type, msg) {
    var $fb = $m.find('.etik-feedback');
    if (!$fb.length) {
      $fb = $('<div class="etik-feedback" aria-live="polite"></div>').appendTo($m.find('.etik-modal-content').first());
    }
    $fb.removeClass('success error').addClass(type || '').text(msg || '').show();
  }

  function clearFeedback($m) {
    $m.find('.etik-feedback').hide().removeClass('success error').text('');
  }

  var HCAPTCHA_WIDGET_KEY = 'etik_hcaptcha_widget';

  function initHCaptcha($m) {
    if (!hcaptchaSiteKey || typeof hcaptcha === 'undefined') return;
    var placeholder = $m.find('.etik-hcaptcha-placeholder')[0];
    if (!placeholder) return;
    var existing = $m.data(HCAPTCHA_WIDGET_KEY);
    if (!existing && existing !== 0) {
      try {
        var widgetId = hcaptcha.render(placeholder, { sitekey: hcaptchaSiteKey });
        $m.data(HCAPTCHA_WIDGET_KEY, widgetId);
      } catch(e) {}
    } else {
      try { hcaptcha.reset(existing); } catch(e) {}
    }
  }

  function openModal(eventId, title) {
    var $m = $modal();
    if (!$m.length) return;

    $m.find('input[name="event_id"]').val(eventId);

    var $titleEl = $('#etik-modal-title');
    if ($titleEl.length) {
      $titleEl.text(title ? ('Inscription : ' + title) : "Inscription à la formation");
    }

    var $form = $m.find('form.etik-insc-form');
    if ($form.length) $form[0].reset();
    clearFeedback($m);

    if (hcaptchaSiteKey) initHCaptcha($m);

    $m.attr('aria-hidden', 'false');

    setTimeout(function(){
      $m.find('input, textarea, button, select').filter(':visible').first().focus();
    }, 40);
  }

  function closeModal($m) {
    try {
      var widgetId = $m.data(HCAPTCHA_WIDGET_KEY);
      if (typeof hcaptcha !== 'undefined' && widgetId !== undefined) {
        try { hcaptcha.reset(widgetId); } catch(e) {}
      }
    } catch(e){}
    $m.attr('aria-hidden', 'true');
  }

  // ── Déclencheurs de la modale ─────────────────────────────────────────────

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

  // ── Fermeture ─────────────────────────────────────────────────────────────

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

  // ── Focus trap ────────────────────────────────────────────────────────────

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

  // ── Soumission formulaire inscription ────────────────────────────────────

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

    // Validation client
    if (!first_name || !email || !phone || !event_id) {
      showFeedback($m, 'error', 'Veuillez remplir les champs obligatoires : Prénom, E-mail et Téléphone.');
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showFeedback($m, 'error', 'Adresse e-mail invalide.');
      return;
    }

    var postData = {
      action:         $form.find('input[name="action"]').val() || 'wp_etik_handle_inscription',
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
        showFeedback($m, 'success', resp.data && resp.data.message
          ? resp.data.message
          : 'Inscription enregistrée. Vérifiez votre e-mail pour confirmer.');
        setTimeout(function(){ closeModal($m); }, 2200);
      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Erreur';
        showFeedback($m, 'error', msg);
        try {
          var w = $m.data(HCAPTCHA_WIDGET_KEY);
          if (typeof hcaptcha !== 'undefined' && w !== undefined) { hcaptcha.reset(w); }
        } catch(e){}
      }
    }, 'json').fail(function(){
      $submit.prop('disabled', false);
      showFeedback($m, 'error', 'Erreur réseau. Réessayez.');
      try {
        var w2 = $m.data(HCAPTCHA_WIDGET_KEY);
        if (typeof hcaptcha !== 'undefined' && w2 !== undefined) { hcaptcha.reset(w2); }
      } catch(e){}
    });
  });

  // ── Init ──────────────────────────────────────────────────────────────────

  $(function(){
    var $m = $modal();
    if ($m.length) {
      $m.attr('aria-hidden', 'true');
    }
  });

})(jQuery);