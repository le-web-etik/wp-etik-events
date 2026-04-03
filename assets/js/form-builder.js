jQuery(function($){
    'use strict';

    var cfg = window.ETIK_FORMS || {};

    // ── Drag & drop ───────────────────────────────────────────────────────
    $('#etik-fields-list').sortable({
        handle:       '.etik-drag-handle',
        placeholder:  'etik-field-placeholder',
        tolerance:    'pointer',
        // Mise à jour de l'ordre en BDD via AJAX après chaque déplacement
        update: function() {
            var formId = $('#etik-form-editor').data('form-id');
            if ( ! formId ) return; // pas encore sauvegardé

            var order = [];
            $('#etik-fields-list .etik-field-row').each(function(){
                var fid = $(this).data('field-id');
                if ( fid && fid !== '{{id}}' ) order.push(fid);
            });

            $.post(cfg.ajax_url, {
                action:  'etik_reorder_fields',
                nonce:   cfg.nonce,
                form_id: formId,
                order:   order,
            });
        }
    });

    // ── Ajouter un champ ──────────────────────────────────────────────────
    $(document).on('click', '.etik-add-field-btn', function(){
        var type    = $(this).data('type');
        var $empty  = $('.etik-fields-empty');
        var $tpl    = $( $('#etik-field-row-tpl').html() );
        var uid     = 'new_' + Date.now();
 
        $tpl.attr('data-field-id', uid).attr('data-type', type);
        $tpl.find('.etik-field-type-badge')
            .attr('class', 'etik-field-type-badge etik-type-' + type)
            .text( cfg.field_types[type] ? cfg.field_types[type].label : type );
 
        // ── Affichage conditionnel des zones selon le type ───────────────
 
        // Choix (select / radio / checkbox_group) → textarea une option/ligne
        if ( ['select', 'radio', 'checkbox_group'].indexOf(type) !== -1 ) {
            $tpl.find('.etik-field-options-wrap').show();
            // Radio : pré-remplir Oui / Non par défaut
            if ( type === 'radio' ) {
                $tpl.find('.etik-f-options').val('Oui\nNon');
            }
        } else {
            $tpl.find('.etik-field-options-wrap').hide();
        }
 
        // Contenu HTML (html / consent) → grande textarea riche
        if ( ['html', 'consent'].indexOf(type) !== -1 ) {
            $tpl.find('.etik-field-html-wrap').show();
            // Pas de libellé obligatoire pour html
            if ( type === 'html' ) {
                $tpl.find('.etik-required-wrap').hide();
                $tpl.find('.etik-f-label').attr('placeholder', 'Titre du bloc (optionnel)');
            }
        } else {
            $tpl.find('.etik-field-html-wrap').hide();
        }
 
        // Déplie immédiatement le nouveau champ
        $tpl.find('.etik-field-details').show();
        $tpl.find('.etik-field-toggle').text('▴');
 
        if ( $empty.length ) $empty.remove();
        $('#etik-fields-list').append($tpl);
        $tpl.find('.etik-f-label').focus();
    });

    // ── Déplier / replier un champ ────────────────────────────────────────
    $(document).on('click', '.etik-field-toggle', function(){
        var $row     = $(this).closest('.etik-field-row');
        var $details = $row.find('.etik-field-details');
        var open     = $details.is(':visible');
        $details.slideToggle(120);
        $(this).text(open ? '▾' : '▴');
    });

    // ── Mettre à jour le résumé en temps réel ─────────────────────────────
    $(document).on('input change', '.etik-f-label', function(){
        var $row = $(this).closest('.etik-field-row');
        var val  = $(this).val().trim();
        $row.find('.etik-field-label-preview').text(
            val || '(sans libellé)'
        );
    });

    $(document).on('change', '.etik-f-required', function(){
        var $row  = $(this).closest('.etik-field-row');
        var req   = $(this).is(':checked');
        var $badge = $row.find('.etik-field-summary .etik-required-badge');
        if ( req && ! $badge.length ) {
            $row.find('.etik-field-label-preview').after(
                '<span class="etik-required-badge" style="color:#a12d2d;font-size:11px;margin-left:4px;">*requis</span>'
            );
        } else if ( ! req ) {
            $badge.remove();
        }
    });

    // ── Supprimer un champ ────────────────────────────────────────────────
    $(document).on('click', '.etik-field-delete', function(){
        if ( ! confirm( cfg.strings.confirm_delete_field ) ) return;
        $(this).closest('.etik-field-row').fadeOut(200, function(){ $(this).remove(); });
    });

    // ── Supprimer un formulaire (page liste) ──────────────────────────────
    $(document).on('click', '.etik-delete-form', function(){
        if ( ! confirm( cfg.strings.confirm_delete ) ) return;

        var $btn   = $(this);
        var formId = $btn.data('form-id');
        $btn.prop('disabled', true);

        $.post(cfg.ajax_url, {
            action:  'etik_delete_form',
            nonce:   cfg.nonce,
            form_id: formId,
        }, function(resp){
            if ( resp.success ) {
                $btn.closest('tr').fadeOut(200, function(){ $(this).remove(); });
            } else {
                alert( resp.data && resp.data.message ? resp.data.message : cfg.strings.error );
                $btn.prop('disabled', false);
            }
        }, 'json').fail(function(){
            alert( cfg.strings.error );
            $btn.prop('disabled', false);
        });
    });

    // ── Enregistrer le formulaire ─────────────────────────────────────────
    $(document).on('click', '#etik-save-form', function(){
        var $btn     = $(this);
        var $spinner = $('#etik-save-spinner');
        var $feedback = $('#etik-form-feedback');
        var formId   = parseInt( $('#etik-form-editor').data('form-id') ) || 0;

        var title = $.trim( $('#etik-form-title').val() );
        if ( ! title ) {
            showFeedback('error', 'Le titre du formulaire est requis.');
            return;
        }

        // Collecter les champs
        var fields = [];
        var valid  = true;
        $('#etik-fields-list .etik-field-row').each(function(){

            var $row  = $(this);
            var label = $.trim( $row.find('.etik-f-label').val() );
            if ( ! label ) {
                valid = false;
                showFeedback('error', cfg.strings.field_label_empty);
                $row.find('.etik-field-details').show();
                $row.find('.etik-f-label').focus();
                return false; // break each
            }

            var type = $row.data('type');
 
            // Pour html/consent : le contenu vient de .etik-f-html-content
            // Pour les autres   : les choix viennent de .etik-f-options
            var optionsValue;
            if ( ['html', 'consent'].indexOf(type) !== -1 ) {
                optionsValue = $row.find('.etik-f-html-content').val();
            } else {
                optionsValue = $row.find('.etik-f-options').val();
            }

            fields.push({
                field_key:   $row.find('.etik-f-key').val(),
                label:       label,
                type:        type,
                placeholder: $row.find('.etik-f-placeholder').val(),
                required:    $row.find('.etik-f-required').is(':checked') ? 1 : 0,
                options:     optionsValue,
                help_text:   $row.find('.etik-f-help').val(),
            });
        });

        if ( ! valid ) return;

        $btn.prop('disabled', true);
        $spinner.show();
        $feedback.hide();

        $.post(cfg.ajax_url, {
            action:      'etik_save_form',
            nonce:       cfg.nonce,
            form_id:     formId,
            title:       title,
            description: $('#etik-form-description').val(),
            attach_type: $('#etik-form-attach').val(),
            fields:      fields,
        }, function(resp){
            $spinner.hide();
            $btn.prop('disabled', false);

            if ( resp.success ) {
                showFeedback('success', resp.data.message || cfg.strings.saved);
                // Mettre à jour l'URL si nouveau formulaire
                if ( resp.data.redirect && ! formId ) {
                    setTimeout(function(){
                        window.location.href = resp.data.redirect;
                    }, 800);
                }
            } else {
                showFeedback('error', (resp.data && resp.data.message) ? resp.data.message : cfg.strings.error);
            }
        }, 'json').fail(function(){
            $spinner.hide();
            $btn.prop('disabled', false);
            showFeedback('error', cfg.strings.error);
        });
    });

    // ── Helper feedback ───────────────────────────────────────────────────
    function showFeedback(type, msg) {
        var $f = $('#etik-form-feedback');
        var cls = type === 'success' ? 'notice-success' : 'notice-error';
        $f.attr('class', 'notice ' + cls + ' is-dismissible')
          .html('<p>' + $('<div>').text(msg).html() + '</p>')
          .show();
        $('html, body').animate({ scrollTop: $f.offset().top - 60 }, 200);
    }
});