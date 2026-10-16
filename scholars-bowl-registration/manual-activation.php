<?php
/**
 * Manual activation trigger for testing
 * Access this file directly to test table creation
 */

// Basic security check
if (!isset($_GET['action']) || $_GET['action'] !== 'manual_activate') {
    die('Access denied. Use ?action=manual_activate to proceed.');
}

// Include WordPress
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/../../../../../wp-blog-header.php');

// Check user permissions
if (!current_user_can('manage_options')) {
    die('Access denied - insufficient permissions');
}

echo "<h1>Manual Activation Test</h1>";

// Include our plugin file
require_once(__DIR__ . '/scholars-bowl-registration.php');

// Create an instance of our class
$sbr = new ScholarsBowlRegistration();

echo "<h2>Testing Table Creation</h2>";

// Call the table creation function
$result = $sbr->create_database_table();

echo "<p><strong>Table Creation Result:</strong> " . ($result ? 'SUCCESS' : 'FAILED') . "</p>";

// Call set_default_options
echo "<h2>Setting Default Options</h2>";
$sbr->set_default_options();
echo "<p>Default options set.</p>";

// Call create_default_event
echo "<h2>Creating Default Event</h2>";
$event_result = $sbr->create_default_event();
echo "<p><strong>Default Event Creation Result:</strong> " . ($event_result ? 'SUCCESS' : 'FAILED') . "</p>";

// Show table status
global $wpdb;
$events_table = $wpdb->prefix . 'scholars_bowl_events';
$registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

$events_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
$registrations_exists = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;

echo "<h2>Final Status</h2>";
echo "<p>Events table ($events_table): " . ($events_exists ? 'EXISTS' : 'DOES NOT EXIST') . "</p>";
echo "<p>Registrations table ($registrations_table): " . ($registrations_exists ? 'EXISTS' : 'DOES NOT EXIST') . "</p>";

if ($events_exists) {
    $event_count = $wpdb->get_var("SELECT COUNT(*) FROM $events_table");
    echo "<p>Number of events in table: $event_count</p>";
}

// Check and clear setup needed flag
$needs_setup = get_option('sbr_needs_setup');
echo "<p>Setup needed flag: " . ($needs_setup ? 'TRUE' : 'FALSE') . "</p>";

if ($events_exists && $registrations_exists) {
    delete_option('sbr_needs_setup');
    echo "<p>Setup flag cleared.</p>";
}

echo "<h2>Error Log</h2>";
echo "<p>Check your WordPress error log for detailed debugging information.</p>";

?>