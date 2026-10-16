<?php
/**
 * Registrations CSV export.
 *
 * In 1.0.7 the export runs on admin_init, before WordPress starts sending the
 * admin page, so the download is a clean CSV file instead of the admin page
 * followed by CSV rows. The columns are unchanged except for three new ones
 * at the end (Classification, Event ID, Event Name). When the Registrations
 * page is filtered to one event, only that event is exported.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('export_registrations_csv')) {
    function export_registrations_csv($registrations) {
        if (empty($registrations)) {
            wp_die('No registrations to export.');
        }

        $filename = 'scholars_bowl_registrations_' . date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // CSV headers
        $headers = [
            'ID', 'School Name', 'Contact Name', 'Contact Email', 'Contact Phone', 'Dietary Restrictions',
            'Competing Students', 'Additional Students', 'Additional Adults', 'Coaches', 'Grade Level',
            'Session Time', 'Total Amount', 'Amount Paid', 'Balance Due',
            'Payment Status', 'Payment Method', 'Registration Status',
            'Registration Date', 'Payment Notes',
            // Added in 1.0.7 at the end, so the earlier columns keep their positions.
            'Classification', 'Event ID', 'Event Name'
        ];
        fputcsv($output, $headers);

        global $wpdb;
        $event_names = [];
        foreach ((array) $wpdb->get_results("SELECT id, event_name FROM {$wpdb->prefix}scholars_bowl_events") as $ev) {
            $event_names[$ev->id] = $ev->event_name;
        }

        // CSV data
        foreach ($registrations as $reg) {
            $row = [
                $reg->id,
                $reg->school_name,
                $reg->contact_name,
                $reg->contact_email,
                $reg->contact_phone,
                $reg->dietary_restrictions ?: 'None',
                $reg->competing_students,
                $reg->additional_students ?? 0,
                $reg->additional_adults ?? 0,
                $reg->coaches,
                $reg->grade_level,
                $reg->session_time,
                number_format($reg->total_amount, 2),
                number_format($reg->amount_paid ?: 0, 2),
                number_format($reg->balance_due ?: 0, 2),
                $reg->payment_status,
                $reg->payment_method,
                $reg->registration_status ?: 'pending',
                $reg->registration_date,
                $reg->payment_notes ?: '',
                !empty($reg->grade_level) ? $reg->grade_level : (isset($reg->school_classification) ? $reg->school_classification : ''),
                $reg->event_id,
                isset($event_names[$reg->event_id]) ? $event_names[$reg->event_id] : ''
            ];
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
}

// The registrations to export: one event when the Registrations page is filtered to it, otherwise all.
function sbr_registrations_for_export() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scholars_bowl_registrations';
    if (!empty($_GET['event_filter'])) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE event_id = %d ORDER BY registration_date DESC",
            intval($_GET['event_filter'])
        ));
    }
    return $wpdb->get_results("SELECT * FROM $table_name ORDER BY registration_date DESC");
}

function sbr_maybe_export_registrations_csv() {
    if (!is_admin() || !isset($_GET['page'], $_GET['action'])) {
        return;
    }
    if ($_GET['page'] !== 'scholars-bowl-registrations' || $_GET['action'] !== 'export_csv') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    export_registrations_csv(sbr_registrations_for_export());
}
add_action('admin_init', 'sbr_maybe_export_registrations_csv');
