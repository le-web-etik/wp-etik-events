jQuery(document).ready(function($){
    function statusBadgeHtml(status){
        var map = {
            'confirmed': { color: '#2aa78a', label: 'Confirmed' },
            'pending': { color: '#ffb02e', label: 'Pending' },
            'cancelled': { color: '#9aa0a6', label: 'Cancelled' },
            'banned': { color: '#d9534f', label: 'Banned' }
        };
        var s = map[status] || { color: '#6c757d', label: status };
        return '<span class="etik-status-badge" style="background:' + s.color + ';color:#fff;padding:4px 8px;border-radius:12px;font-size:12px;display:inline-block;">' + s.label + '</span>';
    }

    // click handler: toggle dropdown and lazy-load via ajax
    $(document).on('click', '.wp-etik-toggle-registrants', function(e){
        e.preventDefault();
        var btn = $(this);
        var eventId = btn.data('event-id');
        var row = $('#registrants-row-' + eventId);
        var container = row.find('.etik-registrants-container');
        var loader = container.find('.etik-registrants-loader');
        var content = container.find('.etik-registrants-content');

        // toggle visibility when already loaded
        if ( row.is(':visible') ) {
            row.hide();
            return;
        }

        // show row and loader
        row.show();
        loader.show();
        content.hide();
        //

        // if already loaded once, show content without ajax
        if ( container.data('loaded') == 1 ) {
            loader.hide();
            content.show();
            return;
        }

        // vide le contenue
        content.empty();
        // fetch nonce from the cell
        var nonce = $('.etik-inscrits-col[data-event-id="' + eventId + '"]').data('nonce');
        // ajax post
        $.post( WP_ETIK_REG.ajax_url, {
            action: 'wp_etik_get_registrants',
            event_id: eventId,
            nonce: nonce
        }, function(resp){
            loader.hide();
            if ( resp.success ) {
                var rows = resp.data.rows;
                if ( ! rows || rows.length === 0 ) {
                    content.html('<div class="etik-no-registrants">' + WP_ETIK_REG.strings.no_registrants + '</div>');
                } else {
                    // build small table inspired by the provided image
                    var html = '<table class="widefat striped"><thead><tr><th>ID</th><th>Nom</th><th>E-mail</th><th>Téléphone</th><th>Statut</th><th>Inscrit le</th></tr></thead><tbody>';
                    for ( var i=0; i<rows.length; i++ ) {
                        var r = rows[i];
                        html += '<tr>';
                        html += '<td>' + escapeHtml(r.id) + '</td>';
                        html += '<td>' + escapeHtml(r.first_name + ' ' + r.last_name) + '</td>';
                        html += '<td>' + escapeHtml(r.email) + '</td>';
                        html += '<td>' + escapeHtml(r.phone) + '</td>';
                        html += '<td>' + statusBadgeHtml(r.status) + '</td>';
                        html += '<td>' + escapeHtml(r.registered_at) + '</td>';
                        html += '</tr>';
                    }
                    html += '</tbody></table>';
                    content.html(html);
                }
                content.show();
                container.data('loaded', 1);

                // update inscrits count cell (in case changed)
                var countCell = $('.etik-inscrits-col[data-event-id="' + eventId + '"]');
                countCell.text( resp.data.count + ' / ' + countCell.text().split('/')[1].trim() );
            } else {
                content.html('<div class="etik-error">Erreur: ' + (resp.data || 'unknown') + '</div>');
                content.show();
            }
        }, 'json').fail(function(){
            loader.hide();
            content.html('<div class="etik-error">Erreur réseau</div>');
            content.show();
        });
    });

    // small helper to escape html
    function escapeHtml(text) {
        if ( text === null || text === undefined ) return '';
        return String(text).replace(/[&<>"'`=\/]/g, function(s) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
                '/': '&#x2F;',
                '`': '&#x60;',
                '=': '&#x3D;'
            })[s];
        });
    }
});
