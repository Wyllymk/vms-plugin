/**
 * VMS Admin Dashboard JavaScript.
 *
 * Handles admin dashboard interactions and audit log viewer.
 *
 * @package WyllyMk\VMS
 */

/* global jQuery, vmsAdmin */

( function ( $ ) {
	'use strict';

	$( function () {
		// Auto-refresh dashboard stats every 60 seconds.
		var $statsCards = $( '.vms-admin-cards' );
		if ( $statsCards.length ) {
			setInterval( function () {
				$.post( vmsAdmin.ajaxUrl, {
					action: 'vms_admin_dashboard_stats',
					nonce: vmsAdmin.nonce,
				} ).done( function ( response ) {
					if ( response.success && response.data ) {
						// Stats will be rendered server-side on next load.
						// This is a placeholder for live updates.
					}
				} );
			}, 60000 );
		}

		// Audit logs pagination.
		$( document ).on( 'click', '.vms-audit-page-btn', function ( e ) {
			e.preventDefault();
			var page = $( this ).data( 'page' );
			loadAuditLogs( page );
		} );

		function loadAuditLogs( page ) {
			var $container = $( '#vms-audit-logs-app' );
			if ( ! $container.length ) {
				return;
			}

			$container.html( '<p>' + vmsAdmin.i18n.loading + '</p>' );

			$.post( vmsAdmin.ajaxUrl, {
				action: 'vms_get_audit_logs',
				nonce: vmsAdmin.nonce,
				page: page || 1,
				per_page: 50,
			} ).done( function ( response ) {
				if ( ! response.success ) {
					$container.html( '<p>' + vmsAdmin.i18n.error + '</p>' );
					return;
				}

				var data = response.data;
				var html = '<table class="widefat striped"><thead><tr>';
				html += '<th>ID</th><th>Date</th><th>User</th><th>Action</th><th>Category</th><th>Entity</th><th>IP</th>';
				html += '</tr></thead><tbody>';

				if ( ! data.rows || data.rows.length === 0 ) {
					html += '<tr><td colspan="7">No logs found.</td></tr>';
				} else {
					data.rows.forEach( function ( row ) {
						html += '<tr>';
						html += '<td>' + ( row.id || '' ) + '</td>';
						html += '<td>' + ( row.created_at || '' ) + '</td>';
						html += '<td>' + ( row.user_id || 'System' ) + '</td>';
						html += '<td>' + ( row.action_type || '' ) + '</td>';
						html += '<td>' + ( row.action_category || '' ) + '</td>';
						html += '<td>' + ( row.entity_type || '' ) + ( row.entity_id ? ' #' + row.entity_id : '' ) + '</td>';
						html += '<td>' + ( row.ip_address || '' ) + '</td>';
						html += '</tr>';
					} );
				}

				html += '</tbody></table>';

				// Pagination.
				if ( data.pages > 1 ) {
					html += '<div style="margin-top:12px;">';
					for ( var i = 1; i <= data.pages; i++ ) {
						html += '<button class="button vms-audit-page-btn" data-page="' + i + '">' + i + '</button> ';
					}
					html += '</div>';
				}

				$container.html( html );
			} ).fail( function () {
				$container.html( '<p>' + vmsAdmin.i18n.error + '</p>' );
			} );
		}

		// Auto-load audit logs if on that page.
		if ( $( '#vms-audit-logs-app' ).length ) {
			loadAuditLogs( 1 );
		}
	} );
} )( jQuery );
