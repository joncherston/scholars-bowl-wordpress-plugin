<?php
/**
 * Script to delete all test registrations using WordPress database connection
 * Run this from WordPress admin or via WP CLI
 */

// Load WordPress
require_once(dirname(__FILE__) . '/../../../../wp-config.php');

global $wpdb;

echo "Checking scholars bowl tables...\n";

// Get table names
$registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';
$events_table = $wpdb->prefix . 'scholars_bowl_events';
$payment_tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';
$spam_log_table = $wpdb->prefix . 'scholars_bowl_spam_log';

// Check if tables exist
$tables = array(
    'registrations' => $registrations_table,
    'events' => $events_table,
    'payment_tokens' => $payment_tokens_table,
    'spam_log' => $spam_log_table
);

foreach ($tables as $name => $table) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    if ($exists) {
        echo "✓ Table exists: $table\n";

        if ($name === 'registrations') {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            echo "  - Contains $count registrations\n";

            if ($count > 0) {
                // Show sample data
                $samples = $wpdb->get_results("SELECT id, school_name, coach_name, coach_email, created_at FROM $table LIMIT 5", ARRAY_A);
                echo "  - Sample registrations:\n";
                foreach ($samples as $reg) {
                    echo "    ID: {$reg['id']}, School: {$reg['school_name']}, Coach: {$reg['coach_name']} ({$reg['coach_email']}), Created: {$reg['created_at']}\n";
                }

                echo "\n** To delete all registrations, uncomment the DELETE line below **\n";
                // Uncomment to actually delete:
                // $deleted = $wpdb->query("DELETE FROM $table");
                // echo "Deleted $deleted registrations from $table\n";
            }
        } elseif ($name === 'payment_tokens') {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            echo "  - Contains $count payment tokens\n";
            if ($count > 0) {
                echo "\n** To delete all payment tokens, uncomment the DELETE line below **\n";
                // Uncomment to actually delete:
                // $deleted = $wpdb->query("DELETE FROM $table");
                // echo "Deleted $deleted payment tokens from $table\n";
            }
        }
    } else {
        echo "✗ Table missing: $table\n";
    }
}

echo "\nDone checking tables.\n";
?>