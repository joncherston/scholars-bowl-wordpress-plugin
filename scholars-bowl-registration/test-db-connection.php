<?php
/**
 * Test database connection and table creation
 */

// Include WordPress configuration
require_once(__DIR__ . '/../../../../../wp-config.php');

// Check if MySQL extensions are available
echo "<h1>Database Extension Check</h1>";
echo "<p>mysqli extension: " . (extension_loaded('mysqli') ? 'LOADED' : 'NOT LOADED') . "</p>";
echo "<p>mysql extension: " . (extension_loaded('mysql') ? 'LOADED' : 'NOT LOADED') . "</p>";
echo "<p>pdo_mysql extension: " . (extension_loaded('pdo_mysql') ? 'LOADED' : 'NOT LOADED') . "</p>";

// Test WordPress database connection
global $wpdb;

if (isset($wpdb)) {
    echo "<h2>WordPress Database Connection</h2>";
    echo "<p>Database Host: " . DB_HOST . "</p>";
    echo "<p>Database Name: " . DB_NAME . "</p>";
    echo "<p>Database User: " . DB_USER . "</p>";
    echo "<p>Table Prefix: " . $wpdb->prefix . "</p>";

    // Test simple query
    $test_query = $wpdb->get_var("SELECT 1");
    echo "<p>Test Query Result: " . ($test_query == 1 ? 'SUCCESS' : 'FAILED') . "</p>";

    if ($wpdb->last_error) {
        echo "<p>Database Error: " . $wpdb->last_error . "</p>";
    }

    // List existing tables
    echo "<h3>Existing Tables:</h3>";
    $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>" . $table[0] . "</li>";
    }
    echo "</ul>";

    // Check if our specific tables exist
    $events_table = $wpdb->prefix . 'scholars_bowl_events';
    $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

    $events_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'");
    $registrations_exists = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'");

    echo "<h3>Our Tables:</h3>";
    echo "<p>$events_table: " . ($events_exists ? 'EXISTS' : 'DOES NOT EXIST') . "</p>";
    echo "<p>$registrations_table: " . ($registrations_exists ? 'EXISTS' : 'DOES NOT EXIST') . "</p>";

} else {
    echo "<p>WordPress database connection not available</p>";
}

?>