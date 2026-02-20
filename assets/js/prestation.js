jQuery(document).ready(function($) {
    // Gérer les réservations
    $('.etik-reservation-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $m = $form.closest('.etik-modal');
        clearFeedback($m);

        // Grab values
        var prestation_id = $form.find('input[name="prestation_id"]').val();
        var slot_id = $form.find('input[name="slot_id"]').val();
        var email = $form.find('input[name="email"]').val();
        var first_name = $form.find('input[name="first_name"]').val();
        var last_name = $form.find('input[name="last_name"]').val();
        var phone = $form.find('input[name="phone"]').val();
        var note = $form.find('input[name="note"]').val();

        // Client validation
        if (!first_name || !email || !phone || !prestation_id || !slot_id) {
            showFeedback($m, 'error', 'Veuillez remplir les champs obligatoires.');
            return;
        }

        var postData = {
            action: 'lwe_create_prestation_reservation',
            prestation_id: prestation_id,
            slot_id: slot_id,
            email: email,
            first_name: first_name,
            last_name: last_name,
            phone: phone,
            note: note,
            nonce: globalNonce
        };

        // hCaptcha: include response token if widget present
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

        // Disable submit UI
        var $submit = $form.find('button[type="submit"]').prop('disabled', true);
        showFeedback($m, '', 'Envoi en cours...');

        $.post(ajaxUrl, postData, function(resp){
            $submit.prop('disabled', false);
            if (resp && resp.success) {
                showFeedback($m, 'success', resp.data && resp.data.message ? resp.data.message : 'Réservation enregistrée. Vérifiez votre e-mail pour confirmer.');
                setTimeout(function(){ closeModal($m); }, 2200);
            } else {
                var msg = 'Erreur';
                if (resp && resp.data && resp.data.message) msg = resp.data.message;
                showFeedback($m, 'error', msg);
                // reset hcaptcha if present
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
});