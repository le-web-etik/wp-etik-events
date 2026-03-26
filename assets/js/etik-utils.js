// ✅ NOUVEAU — assets/js/etik-utils.js
(function(window, $){
    'use strict';

    window.EtikUtils = {

        showFeedback: function($modal, type, msg) {
            var $fb = $modal.find('.etik-feedback');
            if ( ! $fb.length ) {
                $fb = $('<div class="etik-feedback" aria-live="polite"></div>')
                    .appendTo( $modal.find('.etik-modal-content').first() );
            }
            $fb.removeClass('success error').addClass(type || '').html(msg || '').show();
        },

        clearFeedback: function($modal) {
            $modal.find('.etik-feedback').hide().removeClass('success error').text('');
        },

        closeModal: function($modal) {
            $modal.attr('aria-hidden', 'true');
        },

        escapeHtml: function(text) {
            if ( text === null || text === undefined ) return '';
            return String(text).replace(/[&<>"'`=\/]/g, function(s) {
                return {
                    '&':'&amp;','<':'&lt;','>':'&gt;',
                    '"':'&quot;',"'":'&#39;',
                    '/':'&#x2F;','`':'&#x60;','=':'&#x3D;'
                }[s];
            });
        }
    };

})(window, jQuery);