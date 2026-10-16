<?php
// Debug script to manually trigger plugin activation
require_once('/opt/easyengine/sites/suscholarsbowl.com/app/htdocs/wp-config.php');
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

// Include the plugin
include_once('scholars-bowl-registration.php');

// Create plugin instance and manually run activation
$plugin = new Scholars_Bowl_Registration();

echo "Creating database tables...\n";
$plugin->create_database_table();

echo "Setting default options...\n";
$plugin->set_default_options();

echo "Creating default event...\n";
$plugin->create_default_event();

echo "Plugin activation completed successfully!\n";

// Check if tables were created
global $wpdb;
$tables = $wpdb->get_results("SHOW TABLES LIKE '%scholars_bowl%'");
echo "Created tables:\n";
foreach ($tables as $table) {
    echo "- " . current($table) . "\n";
}

// Check if default event exists
$events_table = $wpdb->prefix . 'scholars_bowl_events';
$events = $wpdb->get_results("SELECT * FROM $events_table");
echo "\nEvents in database:\n";
foreach ($events as $event) {
    echo "- ID: {$event->id}, Name: {$event->event_name}, Slug: {$event->event_slug}\n";
}
?>