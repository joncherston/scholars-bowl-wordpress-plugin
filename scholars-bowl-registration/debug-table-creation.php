<?php
/**
 * Scholars' Bowl Registration - Manual Table Creation Debug Tool
 *
 * This file can be run to manually test table creation outside WordPress
 * Place this in your plugin directory and access via browser for debugging
 */

// Only allow access from admin users or if WordPress is not loaded
if (defined('ABSPATH')) {
    if (!current_user_can('manage_options')) {
        die('Access denied');
    }
} else {
    // Direct access - include WordPress
    require_once(__DIR__ . '/../../../../wp-config.php');
    if (!current_user_can('manage_options')) {
        die('Access denied');
    }
}

echo "<h1>Scholars' Bowl Registration - Table Creation Debug</h1>";

global $wpdb;

$events_table = $wpdb->prefix . 'scholars_bowl_events';
$registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

echo "<h2>Current Database Status</h2>";
echo "<p><strong>WordPress Prefix:</strong> " . $wpdb->prefix . "</p>";
echo "<p><strong>Events Table Name:</strong> " . $events_table . "</p>";
echo "<p><strong>Registrations Table Name:</strong> " . $registrations_table . "</p>";

// Check if tables exist
$events_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
$registrations_exists = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;

echo "<p><strong>Events Table Exists:</strong> " . ($events_exists ? 'YES' : 'NO') . "</p>";
echo "<p><strong>Registrations Table Exists:</strong> " . ($registrations_exists ? 'YES' : 'NO') . "</p>";

if (isset($_GET['create_tables'])) {
    echo "<h2>Creating Tables...</h2>";

    $charset_collate = $wpdb->get_charset_collate();

    // Create events table
    $events_sql = "CREATE TABLE $events_table (
id mediumint(9) NOT NULL AUTO_INCREMENT,
event_name varchar(255) NOT NULL,
event_slug varchar(100) NOT NULL,
event_date date NOT NULL,
event_location text NOT NULL,
early_bird_deadline date,
early_bird_rate decimal(10,2) NOT NULL DEFAULT '14.00',
standard_rate decimal(10,2) NOT NULL DEFAULT '15.00',
deposit_amount decimal(10,2) NOT NULL DEFAULT '50.00',
max_students int(2) NOT NULL DEFAULT 6,
max_additional int(2) NOT NULL DEFAULT 4,
free_coaches int(2) NOT NULL DEFAULT 2,
morning_session_time varchar(20) DEFAULT '8:30 AM',
afternoon_session_time varchar(20) DEFAULT '12:15 PM',
morning_classifications text,
afternoon_classifications text,
organizer_name varchar(255),
organizer_email varchar(255),
organizer_phone varchar(50),
payment_address text,
stripe_publishable_key varchar(255),
stripe_secret_key varchar(255),
event_status varchar(20) DEFAULT 'active',
created_date datetime DEFAULT '0000-00-00 00:00:00',
PRIMARY KEY (id),
UNIQUE KEY event_slug (event_slug)
) $charset_collate;";

    $registrations_sql = "CREATE TABLE $registrations_table (
id mediumint(9) NOT NULL AUTO_INCREMENT,
event_id mediumint(9) NOT NULL,
school_name varchar(255) NOT NULL,
contact_name varchar(255) NOT NULL,
contact_email varchar(255) NOT NULL,
contact_phone varchar(50) NOT NULL,
competing_students int(2) NOT NULL,
additional_attendees int(2) NOT NULL,
coaches int(2) NOT NULL,
grade_level varchar(20) NOT NULL,
session_time varchar(20) NOT NULL,
payment_option varchar(20) NOT NULL,
payment_method varchar(20) NOT NULL,
total_amount decimal(10,2) NOT NULL,
deposit_amount decimal(10,2) DEFAULT '0.00',
payment_status varchar(20) DEFAULT 'pending',
stripe_payment_intent_id varchar(255),
registration_date datetime DEFAULT '0000-00-00 00:00:00',
PRIMARY KEY (id),
KEY event_id (event_id)
) $charset_collate;";

    echo "<h3>SQL for Events Table:</h3>";
    echo "<pre>" . htmlspecialchars($events_sql) . "</pre>";

    echo "<h3>SQL for Registrations Table:</h3>";
    echo "<pre>" . htmlspecialchars($registrations_sql) . "</pre>";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    echo "<h3>Running dbDelta for Events Table...</h3>";
    $events_result = dbDelta($events_sql);
    echo "<pre>" . print_r($events_result, true) . "</pre>";

    echo "<h3>Running dbDelta for Registrations Table...</h3>";
    $registrations_result = dbDelta($registrations_sql);
    echo "<pre>" . print_r($registrations_result, true) . "</pre>";

    // Check if tables were created
    $events_exists_after = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
    $registrations_exists_after = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;

    echo "<h3>Results:</h3>";
    echo "<p><strong>Events Table Created:</strong> " . ($events_exists_after ? 'YES' : 'NO') . "</p>";
    echo "<p><strong>Registrations Table Created:</strong> " . ($registrations_exists_after ? 'YES' : 'NO') . "</p>";

    if ($wpdb->last_error) {
        echo "<p><strong>MySQL Error:</strong> " . $wpdb->last_error . "</p>";
    }

    echo '<p><a href="?">Refresh Status</a></p>';
} else {
    echo '<p><a href="?create_tables=1">Create Tables Now</a></p>';
}

if (isset($_GET['drop_tables'])) {
    echo "<h2>Dropping Tables...</h2>";

    $wpdb->query("DROP TABLE IF EXISTS $events_table");
    $wpdb->query("DROP TABLE IF EXISTS $registrations_table");

    echo "<p>Tables dropped. <a href='?'>Refresh to check status</a></p>";
} else {
    echo '<p><a href="?drop_tables=1">Drop Tables (for testing)</a></p>';
}

?>