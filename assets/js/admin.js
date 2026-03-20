jQuery(function($){
    $('.datepicker').datepicker({ dateFormat: 'yy-mm-dd' });

    //----------------------------------------------------------------------------------------------
    // verif webhook
    /*$(document).on('click', '.lwe-test-webhook', function(e){
        e.preventDefault();
        var $btn = $(this);
        var gateway = $btn.data('gateway');
        var nonce = $btn.data('nonce');
        var $spinner = $btn.siblings('.lwe-test-spinner');
        var $result = $('#lwe-test-result-' + gateway);

        $spinner.show();
        $btn.prop('disabled', true);
        $result.html('');

        $.post(ajaxurl, {
            action: 'lwe_test_webhook',
            gateway: gateway,
            nonce: nonce
        }, function(response){
            $spinner.hide();
            $btn.prop('disabled', false);
            if ( response.success ) {
                var data = response.data;
                var html = '<div style="padding:8px;border-left:4px solid #46b450;background:#f0fff4;color:#0b6b2f;">' +
                           '<strong>Test OK</strong><div>' + $('<div>').text(data.message).html() + '</div></div>';
                $result.html(html);
            } else {
                var msg = response.data && response.data.message ? response.data.message : (response.data || 'Erreur');
                var html = '<div style="padding:8px;border-left:4px solid #d63638;background:#fff6f6;color:#8a1f1f;">' +
                           '<strong>Échec</strong><div>' + $('<div>').text(msg).html() + '</div></div>';
                $result.html(html);
            }
        }).fail(function(jqXHR){
            $spinner.hide();
            $btn.prop('disabled', false);
            $result.html('<div class="notice notice-error"><p>Erreur réseau ou serveur</p></div>');
        });
    });*/

    //----------------------------------------------------------------------------------------------
    // Vérification du webhook
    $(document).on('click', '.lwe-test-webhook', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var gateway = $btn.data('gateway');
        var nonce = $btn.data('nonce');
        var $spinner = $btn.siblings('.lwe-test-spinner');
        var $result = $('#lwe-test-result-' + gateway);

        // Désactiver le bouton et afficher le spinner
        $spinner.show();
        $btn.prop('disabled', true);
        $result.html('');

        $.post(ajaxurl, {
            action: 'lwe_test_webhook',
            gateway: gateway,
            nonce: nonce
        }, function(response) {
            $spinner.hide();
            $btn.prop('disabled', false);

            if (response.success) {
                var data = response.data;
                var message = data.message || 'Test réussi.';
                var html = '<div style="padding:8px;border-left:4px solid #46b450;background:#f0fff4;color:#0b6b2f;">' +
                        '<strong>✅ Test OK</strong><div>' + $('<div>').text(message).html() + '</div></div>';
                $result.html(html);
            } else {
                var message = response.data && response.data.message ? response.data.message : 'Erreur inconnue';
                var html = '<div style="padding:8px;border-left:4px solid #d63638;background:#fff6f6;color:#8a1f1f;">' +
                        '<strong>❌ Échec</strong><div>' + $('<div>').text(message).html() + '</div></div>';
                $result.html(html);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            $spinner.hide();
            $btn.prop('disabled', false);
            var errorMsg = 'Erreur réseau : ' + textStatus + ' ' + errorThrown;
            var html = '<div style="padding:8px;border-left:4px solid #d63638;background:#fff6f6;color:#8a1f1f;">' +
                    '<strong>❌ Erreur réseau</strong><div>' + $('<div>').text(errorMsg).html() + '</div></div>';
            $result.html(html);
        });
    });

  
});
