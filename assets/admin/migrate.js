/* global jQuery, hseoMigrate */
( function ( $ ) {
	'use strict';

	$( function () {
		var $root = $( '#hseo-migrate' );
		if ( ! $root.length || ! window.hseoMigrate ) {
			return;
		}
		var cfg = window.hseoMigrate;

		function ajax( data ) {
			return $.post( cfg.ajaxUrl, $.extend( { action: cfg.action, nonce: cfg.nonce }, data ) );
		}

		var $scan = $( '#hseo-migrate-scan' );
		var $results = $( '#hseo-migrate-results' );

		$scan.on( 'click', function ( e ) {
			e.preventDefault();
			$scan.prop( 'disabled', true ).text( cfg.i18n.scanning );
			ajax( { op: 'detect' } ).done( function ( res ) {
				$scan.prop( 'disabled', false ).text( cfg.i18n.scan );
				var sources = ( res.data && res.data.sources ) || [];
				if ( ! sources.length ) {
					$results.html( '<p>' + cfg.i18n.none + '</p>' );
					return;
				}
				var html = '';
				sources.forEach( function ( s ) {
					html +=
						'<div class="hseo-migrate-source" data-source="' + s.key + '" data-total="' + s.total + '">' +
						'<strong>' + s.label + '</strong> — ' + s.total + ' ' + cfg.i18n.posts + ' ' +
						'<button type="button" class="button button-primary hseo-migrate-run">' + cfg.i18n.import + '</button> ' +
						'<label><input type="checkbox" class="hseo-migrate-overwrite" /> ' + cfg.i18n.overwrite + '</label>' +
						'<div class="hseo-migrate-progress" style="display:none"><div class="hseo-migrate-bar"><span></span></div><em></em></div>' +
						'</div>';
				} );
				$results.html( html );
			} );
		} );

		$results.on( 'click', '.hseo-migrate-run', function ( e ) {
			e.preventDefault();
			var $src = $( this ).closest( '.hseo-migrate-source' );
			var source = $src.data( 'source' );
			var total = parseInt( $src.data( 'total' ), 10 ) || 0;
			var overwrite = $src.find( '.hseo-migrate-overwrite' ).is( ':checked' ) ? 1 : 0;
			var $progress = $src.find( '.hseo-migrate-progress' ).show();
			var $bar = $progress.find( 'span' );
			var $label = $progress.find( 'em' );
			$src.find( '.hseo-migrate-run, .hseo-migrate-overwrite' ).prop( 'disabled', true );

			var offset = 0;
			var imported = 0;

			function step() {
				ajax( { op: 'import', source: source, offset: offset, overwrite: overwrite } ).done( function ( res ) {
					if ( ! res.success ) {
						$label.text( 'Error' );
						return;
					}
					var d = res.data;
					imported += d.imported;
					offset = d.offset;
					var pct = total ? Math.min( 100, Math.round( ( offset / total ) * 100 ) ) : 100;
					$bar.css( 'width', pct + '%' );
					$label.text( imported + ' / ' + total + ' ' + cfg.i18n.posts );
					if ( d.done ) {
						$bar.css( 'width', '100%' );
						$label.text( cfg.i18n.doneMsg.replace( '%d', imported ) );
					} else {
						step();
					}
				} );
			}
			step();
		} );
	} );
} )( jQuery );
