<?php
/**
 * Monthly Registration Report
 *
 * Sends a monthly email summarizing registrations from the previous calendar
 * month. Sends even when the count is zero, which keeps the outbound mail
 * provider (Brevo) seeing regular activity.
 *
 * Install:
 *   1. Upload this file to the child theme directory.
 *   2. Add this line to the child theme's functions.php:
 *        require_once get_stylesheet_directory() . '/registration-report.php';
 *
 * @package GeneratePress-Scholars-Bowl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * CONFIGURATION
 * ---------------------------------------------------------------------- */

/**
 * Who receives the report. Comma-separate for multiple recipients.
 */
if ( ! defined( 'SBRR_REPORT_TO' ) ) {
	define( 'SBRR_REPORT_TO', get_option( 'admin_email' ) );
}

/**
 * Day of month and hour (site local time) the report is sent.
 */
if ( ! defined( 'SBRR_SEND_DAY' ) ) {
	define( 'SBRR_SEND_DAY', 1 );
}
if ( ! defined( 'SBRR_SEND_HOUR' ) ) {
	define( 'SBRR_SEND_HOUR', 7 );
}

/**
 * OPTIONAL: custom registration table from the scholars-bowl-registration
 * plugin. Set these in wp-config.php if the plugin stores entries outside
 * Formidable. Leave undefined to skip.
 *
 *   define( 'SBRR_CUSTOM_TABLE', 'wp_sbr_registrations' );
 *   define( 'SBRR_CUSTOM_DATE_COL', 'created_at' );
 */

/**
 * OPTIONAL: send directly through the Brevo API instead of wp_mail().
 * This is the only way to guarantee the *API key* itself is exercised.
 * Add to wp-config.php:
 *
 *   define( 'SBRR_BREVO_API_KEY', 'xkeysib-...' );
 *   define( 'SBRR_BREVO_FROM', 'noreply@suscholarsbowl.com' );
 *   define( 'SBRR_BREVO_FROM_NAME', "SU Scholars' Bowl" );
 */

const SBRR_HOOK        = 'sbrr_send_monthly_report';
const SBRR_OPT_LASTRUN = 'sbrr_last_run';

/* -------------------------------------------------------------------------
 * SCHEDULING
 *
 * Uses a self-rescheduling single event rather than a recurring interval,
 * because calendar months are not a fixed number of seconds.
 * ---------------------------------------------------------------------- */

/**
 * Timestamp for the next send after a given moment.
 *
 * @param int|null $after Unix timestamp to schedule after. Defaults to now.
 * @return int Unix timestamp.
 */
function sbrr_next_run_timestamp( $after = null ) {
	$tz    = wp_timezone();
	$after = $after ? $after : time();

	$now = new DateTime( '@' . $after );
	$now->setTimezone( $tz );

	$next = new DateTime( 'first day of this month', $tz );
	$day  = min( (int) SBRR_SEND_DAY, 28 );
	$next->modify( '+' . ( $day - 1 ) . ' days' );
	$next->setTime( (int) SBRR_SEND_HOUR, 0, 0 );

	if ( $next->getTimestamp() <= $after ) {
		$next = new DateTime( 'first day of next month', $tz );
		$next->modify( '+' . ( $day - 1 ) . ' days' );
		$next->setTime( (int) SBRR_SEND_HOUR, 0, 0 );
	}

	return $next->getTimestamp();
}

/**
 * Make sure the job is on the schedule.
 */
function sbrr_ensure_scheduled() {
	if ( ! wp_next_scheduled( SBRR_HOOK ) ) {
		wp_schedule_single_event( sbrr_next_run_timestamp(), SBRR_HOOK );
	}
}
add_action( 'init', 'sbrr_ensure_scheduled' );

/**
 * Clear the job if the theme is switched away.
 */
function sbrr_unschedule() {
	$ts = wp_next_scheduled( SBRR_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, SBRR_HOOK );
	}
}
add_action( 'switch_theme', 'sbrr_unschedule' );

/* -------------------------------------------------------------------------
 * DATA COLLECTION
 * ---------------------------------------------------------------------- */

/**
 * UTC boundaries and label for a calendar month relative to now.
 *
 * @param int $offset_months 0 = current month, -1 = last month.
 * @return array
 */
function sbrr_month_bounds( $offset_months = -1 ) {
	$tz = wp_timezone();

	$start = new DateTime( 'first day of this month', $tz );
	$start->setTime( 0, 0, 0 );
	if ( 0 !== $offset_months ) {
		$start->modify( sprintf( '%+d months', $offset_months ) );
	}

	$end = clone $start;
	$end->modify( '+1 month' );

	$utc       = new DateTimeZone( 'UTC' );
	$start_utc = clone $start;
	$end_utc   = clone $end;
	$start_utc->setTimezone( $utc );
	$end_utc->setTimezone( $utc );

	return array(
		'label'     => $start->format( 'F Y' ),
		'start_utc' => $start_utc->format( 'Y-m-d H:i:s' ),
		'end_utc'   => $end_utc->format( 'Y-m-d H:i:s' ),
	);
}

/**
 * Whether a database table exists.
 *
 * @param string $table Full table name.
 * @return bool
 */
function sbrr_table_exists( $table ) {
	global $wpdb;
	return (bool) $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
	);
}

/**
 * Count Formidable entries per form within a UTC window.
 *
 * @param string $start_utc MySQL datetime.
 * @param string $end_utc   MySQL datetime.
 * @return array Rows of form_name => total.
 */
function sbrr_count_formidable( $start_utc, $end_utc ) {
	global $wpdb;

	$items = $wpdb->prefix . 'frm_items';
	$forms = $wpdb->prefix . 'frm_forms';

	if ( ! sbrr_table_exists( $items ) ) {
		return array();
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT COALESCE(f.name, CONCAT('Form #', i.form_id)) AS source,
			        COUNT(*) AS total
			   FROM {$items} i
			   LEFT JOIN {$forms} f ON f.id = i.form_id
			  WHERE i.created_at >= %s
			    AND i.created_at <  %s
			    AND i.is_draft = 0
			  GROUP BY i.form_id, f.name
			  ORDER BY total DESC",
			$start_utc,
			$end_utc
		),
		ARRAY_A
	);

	return $rows ? $rows : array();
}

/**
 * Count rows in the optional custom registration table.
 *
 * @param string $start_utc MySQL datetime.
 * @param string $end_utc   MySQL datetime.
 * @return array
 */
function sbrr_count_custom_table( $start_utc, $end_utc ) {
	global $wpdb;

	if ( ! defined( 'SBRR_CUSTOM_TABLE' ) ) {
		return array();
	}

	$table = SBRR_CUSTOM_TABLE;
	$col   = defined( 'SBRR_CUSTOM_DATE_COL' ) ? SBRR_CUSTOM_DATE_COL : 'created_at';

	if ( ! sbrr_table_exists( $table ) ) {
		return array();
	}

	// Identifiers cannot be bound as parameters; they come from wp-config
	// constants, not user input, and are whitelisted to safe characters.
	$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
	$col   = preg_replace( '/[^A-Za-z0-9_]/', '', $col );

	$total = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE {$col} >= %s AND {$col} < %s",
			$start_utc,
			$end_utc
		)
	);

	return array(
		array(
			'source' => 'Registration plugin',
			'total'  => (int) $total,
		),
	);
}

/**
 * Assemble the full report data set.
 *
 * @param int $offset_months Month to report on, relative to now.
 * @return array
 */
function sbrr_gather_report( $offset_months = -1 ) {
	$bounds = sbrr_month_bounds( $offset_months );

	$rows = array_merge(
		sbrr_count_formidable( $bounds['start_utc'], $bounds['end_utc'] ),
		sbrr_count_custom_table( $bounds['start_utc'], $bounds['end_utc'] )
	);

	$total = 0;
	foreach ( $rows as $row ) {
		$total += (int) $row['total'];
	}

	// Year-to-date, for context.
	$tz       = wp_timezone();
	$year_std = new DateTime( 'first day of January this year', $tz );
	$year_std->setTime( 0, 0, 0 );
	$year_std->setTimezone( new DateTimeZone( 'UTC' ) );

	$ytd_rows = array_merge(
		sbrr_count_formidable( $year_std->format( 'Y-m-d H:i:s' ), $bounds['end_utc'] ),
		sbrr_count_custom_table( $year_std->format( 'Y-m-d H:i:s' ), $bounds['end_utc'] )
	);

	$ytd = 0;
	foreach ( $ytd_rows as $row ) {
		$ytd += (int) $row['total'];
	}

	return array(
		'label' => $bounds['label'],
		'rows'  => $rows,
		'total' => $total,
		'ytd'   => $ytd,
	);
}

/* -------------------------------------------------------------------------
 * EMAIL
 * ---------------------------------------------------------------------- */

/**
 * Build the HTML body.
 *
 * @param array $data Report data.
 * @return string
 */
function sbrr_build_html( $data ) {
	$site  = get_bloginfo( 'name' );
	$brand = '#27428e';

	$html  = '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">';
	$html .= '<div style="border-top:6px solid ' . $brand . ';padding:24px 0 8px;">';
	$html .= '<h2 style="margin:0 0 4px;color:' . $brand . ';font-size:20px;">Monthly Registration Report</h2>';
	$html .= '<p style="margin:0;color:#777;font-size:14px;">' . esc_html( $site ) . ' &bull; ' . esc_html( $data['label'] ) . '</p>';
	$html .= '</div>';

	$html .= '<div style="background:#f8fafe;border:1px solid #ddd;border-radius:6px;padding:20px;margin:20px 0;text-align:center;">';
	$html .= '<div style="font-size:40px;font-weight:700;color:' . $brand . ';line-height:1;">' . (int) $data['total'] . '</div>';
	$html .= '<div style="font-size:14px;color:#555;margin-top:6px;">registration' . ( 1 === (int) $data['total'] ? '' : 's' ) . ' in ' . esc_html( $data['label'] ) . '</div>';
	$html .= '</div>';

	if ( ! empty( $data['rows'] ) ) {
		$html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
		$html .= '<tr><th style="text-align:left;padding:8px;border-bottom:2px solid ' . $brand . ';">Source</th>';
		$html .= '<th style="text-align:right;padding:8px;border-bottom:2px solid ' . $brand . ';">Count</th></tr>';
		foreach ( $data['rows'] as $row ) {
			$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( $row['source'] ) . '</td>';
			$html .= '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . (int) $row['total'] . '</td></tr>';
		}
		$html .= '</table>';
	} else {
		$html .= '<p style="font-size:14px;color:#555;">No registration activity recorded for this period.</p>';
	}

	$html .= '<p style="font-size:14px;color:#555;margin-top:20px;">Year to date: <strong>' . (int) $data['ytd'] . '</strong></p>';

	$html .= '<p style="font-size:12px;color:#999;border-top:1px solid #eee;padding-top:12px;margin-top:24px;">';
	$html .= 'Automated monthly report. Sent regardless of activity level to maintain an active mail-provider connection.';
	$html .= '</p></div>';

	return $html;
}

/**
 * Send via the Brevo transactional API.
 *
 * @param string $subject Subject line.
 * @param string $html    HTML body.
 * @return true|WP_Error
 */
function sbrr_send_via_brevo( $subject, $html ) {
	if ( ! defined( 'SBRR_BREVO_API_KEY' ) || ! defined( 'SBRR_BREVO_FROM' ) ) {
		return new WP_Error( 'sbrr_no_key', 'Brevo API constants are not defined.' );
	}

	$to = array();
	foreach ( explode( ',', SBRR_REPORT_TO ) as $addr ) {
		$addr = trim( $addr );
		if ( is_email( $addr ) ) {
			$to[] = array( 'email' => $addr );
		}
	}

	if ( empty( $to ) ) {
		return new WP_Error( 'sbrr_no_recipient', 'No valid recipient address.' );
	}

	$response = wp_remote_post(
		'https://api.brevo.com/v3/smtp/email',
		array(
			'timeout' => 20,
			'headers' => array(
				'api-key'      => SBRR_BREVO_API_KEY,
				'content-type' => 'application/json',
				'accept'       => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'sender'      => array(
						'email' => SBRR_BREVO_FROM,
						'name'  => defined( 'SBRR_BREVO_FROM_NAME' ) ? SBRR_BREVO_FROM_NAME : get_bloginfo( 'name' ),
					),
					'to'          => $to,
					'subject'     => $subject,
					'htmlContent' => $html,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error(
			'sbrr_brevo_http',
			'Brevo API returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response )
		);
	}

	return true;
}

/**
 * Build and send the report.
 *
 * @param int  $offset_months Month to report on.
 * @param bool $is_test       Whether this is a manual test send.
 * @return array Result summary.
 */
function sbrr_send_report( $offset_months = -1, $is_test = false ) {
	$data    = sbrr_gather_report( $offset_months );
	$html    = sbrr_build_html( $data );
	$subject = sprintf(
		'%s%s Registrations — %s (%d)',
		$is_test ? '[TEST] ' : '',
		get_bloginfo( 'name' ),
		$data['label'],
		$data['total']
	);

	if ( defined( 'SBRR_BREVO_API_KEY' ) ) {
		$sent   = sbrr_send_via_brevo( $subject, $html );
		$method = 'Brevo API';
	} else {
		$sent   = wp_mail(
			SBRR_REPORT_TO,
			$subject,
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		$method = 'wp_mail()';
	}

	$result = array(
		'time'    => time(),
		'label'   => $data['label'],
		'total'   => $data['total'],
		'method'  => $method,
		'test'    => $is_test,
		'success' => ( true === $sent ),
		'error'   => is_wp_error( $sent ) ? $sent->get_error_message() : ( $sent ? '' : 'Mail function returned false.' ),
	);

	update_option( SBRR_OPT_LASTRUN, $result, false );

	return $result;
}

/**
 * Cron callback: send, then reschedule for next month.
 */
function sbrr_run_scheduled() {
	sbrr_send_report( -1, false );
	wp_schedule_single_event( sbrr_next_run_timestamp( time() + 60 ), SBRR_HOOK );
}
add_action( SBRR_HOOK, 'sbrr_run_scheduled' );

/* -------------------------------------------------------------------------
 * ADMIN SCREEN
 * ---------------------------------------------------------------------- */

/**
 * Register the admin page under Tools.
 */
function sbrr_admin_menu() {
	add_management_page(
		'Registration Report',
		'Registration Report',
		'manage_options',
		'sbrr-report',
		'sbrr_admin_page'
	);
}
add_action( 'admin_menu', 'sbrr_admin_menu' );

/**
 * Render the admin page.
 */
function sbrr_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';

	if ( isset( $_POST['sbrr_test'] ) && check_admin_referer( 'sbrr_test_action' ) ) {
		$result = sbrr_send_report( -1, true );
		$notice = $result['success']
			? '<div class="notice notice-success"><p>Test report sent via ' . esc_html( $result['method'] ) . ' to ' . esc_html( SBRR_REPORT_TO ) . '.</p></div>'
			: '<div class="notice notice-error"><p>Send failed: ' . esc_html( $result['error'] ) . '</p></div>';
	}

	if ( isset( $_POST['sbrr_reschedule'] ) && check_admin_referer( 'sbrr_test_action' ) ) {
		sbrr_unschedule();
		sbrr_ensure_scheduled();
		$notice = '<div class="notice notice-success"><p>Schedule reset.</p></div>';
	}

	$next     = wp_next_scheduled( SBRR_HOOK );
	$last     = get_option( SBRR_OPT_LASTRUN );
	$preview  = sbrr_gather_report( -1 );
	$fmt      = get_option( 'date_format' ) . ' g:i a';

	echo '<div class="wrap"><h1>Monthly Registration Report</h1>';
	echo wp_kses_post( $notice );

	echo '<table class="widefat striped" style="max-width:720px;margin-top:16px;"><tbody>';

	echo '<tr><td><strong>Recipient</strong></td><td>' . esc_html( SBRR_REPORT_TO ) . '</td></tr>';

	echo '<tr><td><strong>Delivery method</strong></td><td>' .
		( defined( 'SBRR_BREVO_API_KEY' ) ? 'Brevo API (direct)' : 'wp_mail() — routed by whatever mail plugin is active' ) .
		'</td></tr>';

	echo '<tr><td><strong>Next scheduled send</strong></td><td>' .
		( $next ? esc_html( wp_date( $fmt, $next ) ) : '<span style="color:#b32d2e;">Not scheduled</span>' ) .
		'</td></tr>';

	if ( $last ) {
		echo '<tr><td><strong>Last send</strong></td><td>' .
			esc_html( wp_date( $fmt, $last['time'] ) ) . ' — ' .
			( $last['success'] ? 'success' : 'FAILED: ' . esc_html( $last['error'] ) ) .
			( $last['test'] ? ' (test)' : '' ) .
			'</td></tr>';
	} else {
		echo '<tr><td><strong>Last send</strong></td><td>Never</td></tr>';
	}

	echo '<tr><td><strong>' . esc_html( $preview['label'] ) . ' count</strong></td><td>' . (int) $preview['total'] . '</td></tr>';
	echo '<tr><td><strong>Year to date</strong></td><td>' . (int) $preview['ytd'] . '</td></tr>';

	echo '</tbody></table>';

	echo '<form method="post" style="margin-top:20px;">';
	wp_nonce_field( 'sbrr_test_action' );
	submit_button( 'Send Test Report Now', 'primary', 'sbrr_test', false );
	echo ' ';
	submit_button( 'Reset Schedule', 'secondary', 'sbrr_reschedule', false );
	echo '</form>';

	echo '<p style="margin-top:20px;color:#666;max-width:720px;">WP-Cron only fires when someone loads the site. During inactive months, add a system cron on the Docker host so this runs reliably.</p>';

	echo '</div>';
}
