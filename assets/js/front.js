// Placeholder for future frontend behaviors
jQuery(function($){
    // custom behaviours can be added here

    jQuery(document).on('click', '.etik-btn-link', function(e){
        e.preventDefault();
        var $btn = jQuery(this);

        
        $btn.parents(".etik-event").find('.etik-excerpt').toggleClass('open');
        $btn.toggleClass('open');

        let isOpen = $btn.hasClass('open');
        if ( isOpen ) {
            $btn.text('Voir moins');
        } else {
            $btn.text('Voir plus');
        }
    });

    // cache btn voir plus
    $( ".etik-event .etik-excerpt" ).each(function( index ) {

        //console.log( index + ": " + $( this ).text() );
        let heightBox = $( this ).height();
        let heightContent =  $( this ).find('.etik-excerpt-content').height();
        if (heightBox < heightContent) {
            $( this ).parents(".etik-event").find('.etik-btn-link').show();
        }
    });

    


});
