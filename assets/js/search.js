/**
 * RBA Search — Frontend JS
 * @package ResortBookingAddon
 * @since   1.0.1
 */
/* global rba_search_config, jQuery */
( function ( $ ) {
    'use strict';
    if ( typeof rba_search_config === 'undefined' ) return;
    var cfg = rba_search_config;

    // Live availability badge (dùng trên trang phòng)
    $( '.rba-live-check' ).each( function () {
        var $el = $( this );
        var roomId = $el.data( 'room' ), cin = $el.data( 'checkin' ), cout = $el.data( 'checkout' );
        if ( ! roomId || ! cin || ! cout ) return;
        $.post( cfg.ajax_url, { action: 'rba_check_availability', nonce: cfg.nonce, room_id: roomId, check_in: cin, check_out: cout }, function ( res ) {
            if ( ! res.success ) return;
            var $b = $el.find( '.rba-availability-badge' );
            if ( res.data.available ) {
                $b.removeClass( 'full limited' ).addClass( 'available' ).text( '✅ Còn phòng' );
            } else {
                $b.removeClass( 'available limited' ).addClass( 'full' ).text( '❌ Hết phòng' );
            }
        } );
    } );

    // Tour slot selector
    $( document ).on( 'click', '.rba-select-slot', function () {
        var $btn = $( this ), time = $btn.data( 'time' ), $wrap = $btn.closest( '.rba-tour-slots' );
        $wrap.find( '.rba-select-slot' ).removeClass( 'selected' );
        $btn.addClass( 'selected' );
        $( 'input[name="tour_slot"]' ).val( time );
    } );

    // Price calendar loader
    window.rbaLoadPriceCalendar = function ( roomId, year, month ) {
        $.post( cfg.ajax_url, { action: 'rba_get_price_calendar', nonce: cfg.nonce, room_id: roomId, year: year, month: month }, function ( res ) {
            if ( res.success ) $( document ).trigger( 'rba:price_calendar_loaded', [ res.data ] );
        } );
    };

    // Format price
    window.rbaFormatPrice = function ( amount ) {
        return parseInt( amount, 10 ).toLocaleString( 'vi-VN' ) + ' ' + ( cfg.currency || 'VNĐ' );
    };
} )( jQuery );
