/**
 * VMS Settings page JavaScript.
 *
 * Handles: color pickers, media uploader for logo,
 * provider field visibility toggling, test SMS.
 *
 * @package WyllyMk\VMS
 */

/* global jQuery, wp, vmsSettings */

( function ( $ ) {
	'use strict';

	$( function () {
		// ---------------------------------------------------------------
		// Color Pickers
		// ---------------------------------------------------------------
		$( '.vms-color-picker' ).wpColorPicker();

		// ---------------------------------------------------------------
		// Media Uploader (logo)
		// ---------------------------------------------------------------
		let mediaFrame;

		$( document ).on( 'click', '.vms-media-select', function ( e ) {
			e.preventDefault();
			const $container = $( this ).closest( '.vms-media-field' );

			if ( mediaFrame ) {
				mediaFrame.open();
				return;
			}

			mediaFrame = wp.media( {
				title: vmsSettings.i18n.selectImage,
				button: { text: vmsSettings.i18n.useImage },
				library: { type: 'image' },
				multiple: false,
			} );

			mediaFrame.on( 'select', function () {
				const attachment = mediaFrame
					.state()
					.get( 'selection' )
					.first()
					.toJSON();

				$container.find( '.vms-media-id' ).val( attachment.id );
				$container
					.find( '.vms-media-preview' )
					.html(
						'<img src="' +
							attachment.sizes.thumbnail.url +
							'" style="max-height:80px;">'
					);
				$container.find( '.vms-media-remove' ).show();
			} );

			mediaFrame.open();
		} );

		$( document ).on( 'click', '.vms-media-remove', function ( e ) {
			e.preventDefault();
			const $container = $( this ).closest( '.vms-media-field' );
			$container.find( '.vms-media-id' ).val( '' );
			$container.find( '.vms-media-preview' ).empty();
			$( this ).hide();
		} );

		// ---------------------------------------------------------------
		// SMS Provider Field Visibility
		// ---------------------------------------------------------------
		function toggleProviderFields() {
			const selected = $( 'select[name="vms_sms_provider"]' ).val();
			$( '.vms-provider-field' ).hide();
			$( '.vms-provider-' + selected ).show();
		}

		$( 'select[name="vms_sms_provider"]' ).on(
			'change',
			toggleProviderFields
		);
		toggleProviderFields();

		// ---------------------------------------------------------------
		// Test SMS
		// ---------------------------------------------------------------
		$( '#vms-test-sms-btn' ).on( 'click', function () {
			const $btn = $( this );
			const $result = $( '#vms-test-result' );
			const phone = $( '#vms-test-phone' ).val().trim();

			if ( ! phone ) {
				$result
					.text( vmsSettings.i18n.error + ' phone required' )
					.css( 'color', '#d63638' );
				return;
			}

			$btn.prop( 'disabled', true );
			$result.text( vmsSettings.i18n.sending ).css( 'color', '#666' );

			$.post( vmsSettings.ajaxUrl, {
				action: 'vms_test_sms',
				nonce: vmsSettings.nonce,
				phone,
			} )
				.done( function ( response ) {
					if ( response.success ) {
						$result
							.text( vmsSettings.i18n.success )
							.css( 'color', '#00a32a' );
					} else {
						$result
							.text(
								vmsSettings.i18n.error +
									' ' +
									( response.data.error ||
										response.data.message ||
										'Unknown' )
							)
							.css( 'color', '#d63638' );
					}
				} )
				.fail( function ( xhr ) {
					$result
						.text(
							vmsSettings.i18n.error + ' HTTP ' + xhr.status
						)
						.css( 'color', '#d63638' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );
	} );
} )( jQuery );
