/* global jQuery, wp */
( function ( $ ) {
	'use strict';

	$( function () {
		// Media picker for the social image field.
		$( '.hseo-image-pick' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $target = $( $( this ).data( 'target' ) );
			if ( ! window.wp || ! wp.media ) {
				return;
			}
			var frame = wp.media( { title: 'Select social image', multiple: false } );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$target.val( attachment.url ).trigger( 'input' );
			} );
			frame.open();
		} );

		$( '.hseo-image-clear' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( $( this ).data( 'target' ) ).val( '' ).trigger( 'input' );
		} );

		// Character counters.
		$( '.hseo-count' ).each( function () {
			var out = this;
			var $input = $( '#' + $( out ).data( 'for' ) );
			var update = function () {
				out.textContent = ( $input.val() || '' ).length;
			};
			$input.on( 'input', update );
			update();
		} );

		// Live SERP preview.
		var $title = $( '#hseo-title' );
		var $desc = $( '#hseo-desc' );
		var $pTitle = $( '.hseo-serp-title' );
		var $pDesc = $( '.hseo-serp-desc' );
		var fallback = $pTitle.data( 'fallback' ) || '';

		$title.on( 'input', function () {
			$pTitle.text( $title.val() || fallback );
		} );
		$desc.on( 'input', function () {
			$pDesc.text( $desc.val() );
		} );

		// Live social-image preview — mirrors the og:image fallback chain
		// (per-post image → featured image → site default).
		var $img = $( '#hseo-image' );
		var $media = $( '.hseo-serp-media' );
		var $pImg = $( '.hseo-serp-img' );
		if ( $media.length ) {
			var imgFallback = $media.data( 'featured' ) || '';
			var imgDefault = $media.data( 'default' ) || '';
			var updateImg = function () {
				var src = ( $img.val() || '' ) || imgFallback || imgDefault;
				if ( src ) {
					$pImg.attr( 'src', src );
					$media.removeAttr( 'hidden' );
				} else {
					$media.attr( 'hidden', 'hidden' );
				}
			};
			$img.on( 'input', updateImg );
			updateImg();
		}

		// AI crawler block-list presets.
		$( '.hseo-bot-preset' ).on( 'click', function ( e ) {
			e.preventDefault();
			var preset = $( this ).data( 'preset' );
			$( '.hseo-bots input[type=checkbox]' ).each( function () {
				var type = $( this ).data( 'bot-type' );
				this.checked = preset === 'all'
					|| ( preset === 'training' && type === 'training' )
					|| ( preset === 'search' && ( type === 'training' || type === 'search' ) );
			} );
		} );
	} );
} )( jQuery );
