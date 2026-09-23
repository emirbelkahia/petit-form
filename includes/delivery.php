<?php
/**
 * Bounded notification delivery from the leads table through WP-Cron.
 * Only failed channels are retried. A crash after sending but before saving
 * progress can still duplicate a notification; receivers should deduplicate.
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function petit_form_cron_schedules( $schedules ) {
	$schedules['petit_form_minute'] = array( 'interval' => 60, 'display' => 'Petit Form: every minute' );
	return $schedules;
}

function petit_form_schedule_delivery() {
	if ( ! wp_next_scheduled( 'petit_form_deliver_pending' ) ) {
		$result = wp_schedule_event( time() + 60, 'petit_form_minute', 'petit_form_deliver_pending' );
		if ( ! $result ) {
			petit_form_log( 'PF-E4002', 'Could not schedule notification delivery.' );
		}
	}
}

/**
 * Schedule health shared by the PF-E4003 admin warning and the probe.
 * A disabled visit trigger is valid when a host scheduler runs WP-Cron.
 *
 * @return string '' when healthy, 'missing' or 'late'.
 */
function petit_form_delivery_problem() {
	$next = wp_next_scheduled( 'petit_form_deliver_pending' );
	if ( false === $next ) {
		return 'missing';
	}
	return $next < time() - 15 * MINUTE_IN_SECONDS ? 'late' : '';
}

function petit_form_deactivate() {
	wp_clear_scheduled_hook( 'petit_form_deliver_pending' );
}

/** Process at most five leads per run, with three attempts per lead. */
function petit_form_deliver_pending() {
	global $wpdb;
	// A cron request may be the first request after a plugin update.
	petit_form_maybe_upgrade();
	$table = petit_form_table();
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE notify_after <= %d AND notify_attempts < 3 ORDER BY notify_after, id LIMIT 5", time()
	) );
	foreach ( $ids as $id ) {
		// A ten-minute lease prevents overlapping workers from owning a lead.
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET notify_attempts = notify_attempts + 1, notify_after = %d WHERE id = %d AND notify_after <= %d AND notify_attempts < 3",
			time() + 600, $id, time()
		) );
		if ( 1 !== $claimed ) {
			continue;
		}
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		if ( ! $lead ) {
			continue; // Deleted while being claimed.
		}
		$state  = json_decode( $lead->notify_data, true );
		$values = json_decode( $lead->data, true );
		$where  = array( 'id' => $id, 'notify_attempts' => $lead->notify_attempts );
		if ( ! is_array( $state ) || ! is_array( $values ) ) {
			petit_form_log( 'PF-E4002', 'Invalid notification data for lead ' . (int) $id );
			$wpdb->update( $table, array( 'notify_after' => null, 'notify_attempts' => 3 ), $where );
			continue;
		}
		foreach ( array( 'email', 'webhook' ) as $channel ) {
			if ( empty( $state['pending'][ $channel ] ) ) {
				continue;
			}
			try {
				$result = 'email' === $channel
					? petit_form_send_notification( $lead->form_id, $values, $id, $state['fields'] )
					: petit_form_send_webhook( $lead->form_id, $values, $id );
			} catch ( Throwable $error ) {
				$result = new WP_Error( 'email' === $channel ? 'PF-E4001' : 'PF-E4101', 'Notification transport threw an exception.' );
			}
			if ( is_wp_error( $result ) ) {
				petit_form_log( $result->get_error_code(), 'Lead ' . (int) $id . ': ' . $result->get_error_message() );
			} else {
				$state['pending'][ $channel ] = false;
				// Save each successful channel before attempting the next one.
				if ( 1 !== $wpdb->update( $table, array( 'notify_data' => wp_json_encode( $state ) ), $where ) ) {
					petit_form_log( 'PF-E4002', 'Could not save delivery progress for lead ' . (int) $id );
					continue 2;
				}
			}
		}
		$pending = ! empty( array_filter( $state['pending'] ) );
		$saved = $wpdb->update( $table, array(
			'notify_data'  => $pending ? wp_json_encode( $state ) : null,
			'notify_after' => $pending && $lead->notify_attempts < 3 ? time() + 300 : null,
		), $where );
		if ( false === $saved ) {
			petit_form_log( 'PF-E4002', 'Could not save delivery status for lead ' . (int) $id );
		}
	}
}
