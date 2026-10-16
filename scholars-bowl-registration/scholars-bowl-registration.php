<?php
/**
 * Plugin Name: Scholars' Bowl Registration System
 * Plugin URI: https://suscholarsbowl.com
 * Description: Complete registration system for Scholars' Bowl competitions with payment processing and admin management.
 * Version: 1.0.6
 * Author: Scholars' Bowl Team
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: scholars-bowl-registration
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SBR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SBR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SBR_VERSION', '1.0.6');

// Read-only REST API for the scoring system (1.0.7).
require_once SBR_PLUGIN_PATH . 'includes/rest-api.php';
SBR_REST_API::init();

class ScholarsBowlRegistration {

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Load College Bowl Payment module early
        // require_once SBR_PLUGIN_PATH . 'includes/college-bowl-payment.php'; // Disabled - using standalone plugin
        require_once SBR_PLUGIN_PATH . 'includes/admin-college-bowl.php';
    }

    public function init() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_shortcode('scholars_bowl_registration', array($this, 'registration_shortcode'));
        add_shortcode('scholars_bowl_complete_payment', array($this, 'payment_completion_shortcode'));
        add_action('wp_ajax_sbr_submit_registration', array($this, 'handle_registration_submission'));
        add_action('wp_ajax_nopriv_sbr_submit_registration', array($this, 'handle_registration_submission'));
        add_action('wp_ajax_sbr_calculate_total', array($this, 'ajax_calculate_total'));
        add_action('wp_ajax_nopriv_sbr_calculate_total', array($this, 'ajax_calculate_total'));
        add_action('wp_ajax_sbr_process_payment', array($this, 'process_stripe_payment'));
        add_action('wp_ajax_nopriv_sbr_process_payment', array($this, 'process_stripe_payment'));
        add_action('wp_ajax_sbr_complete_payment', array($this, 'handle_payment_completion'));
        add_action('wp_ajax_nopriv_sbr_complete_payment', array($this, 'handle_payment_completion'));
        add_action('wp_ajax_sbr_send_payment_reminder', array($this, 'send_payment_reminder'));
        add_action('wp_ajax_sbr_setup_tables', array($this, 'ajax_setup_tables'));
        add_action('wp_ajax_sbr_manual_create_tables', array($this, 'ajax_manual_create_tables'));


        // Handle payment completion requests
        add_action('template_redirect', array($this, 'handle_payment_completion_request'));

        // Check if we need to run setup
        $this->check_setup_needed();

        // Force table creation if activation hook didn't run
        $this->ensure_tables_exist();

        // Schedule cleanup of abandoned registrations
        add_action('wp_loaded', array($this, 'cleanup_abandoned_registrations'));
    }

    public function activate() {
        $this->create_database_table();
        $this->set_default_options();
        $this->create_default_event();
    }

    public function deactivate() {
        // Clean up if needed
    }

    public function create_database_table() {
        global $wpdb;

        $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        $payment_tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';
        $spam_log_table = $wpdb->prefix . 'scholars_bowl_spam_log';

        $charset_collate = $wpdb->get_charset_collate();

        // Log initial state
        error_log('SBR: Starting table creation process');
        error_log('SBR: WordPress prefix: ' . $wpdb->prefix);
        error_log('SBR: Events table name: ' . $events_table);
        error_log('SBR: Registrations table name: ' . $registrations_table);
        error_log('SBR: Charset collate: ' . $charset_collate);

        // Check if upgrade.php is available
        $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (!file_exists($upgrade_file)) {
            error_log('SBR: upgrade.php not found at: ' . $upgrade_file);
            return false;
        }

        // Include upgrade.php for dbDelta function
        require_once($upgrade_file);

        // Create events table - using proper dbDelta formatting
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
min_students int(2) NOT NULL DEFAULT 1,
max_additional int(2) NOT NULL DEFAULT 4,
free_coaches int(2) NOT NULL DEFAULT 2,
morning_session_time varchar(20) DEFAULT '8:30 AM',
afternoon_session_time varchar(20) DEFAULT '12:15 PM',
morning_classifications text,
afternoon_classifications text,
organization_name varchar(255),
organizer_name varchar(255),
organizer_email varchar(255),
organizer_phone varchar(50),
payment_address text,
stripe_mode varchar(10) DEFAULT 'test',
stripe_test_publishable_key varchar(255),
stripe_test_secret_key varchar(255),
stripe_live_publishable_key varchar(255),
stripe_live_secret_key varchar(255),
stripe_publishable_key varchar(255),
stripe_secret_key varchar(255),
from_email varchar(255),
reply_to_email varchar(255),
from_name varchar(255),
event_status varchar(20) DEFAULT 'active',
created_date datetime DEFAULT '0000-00-00 00:00:00',
PRIMARY KEY (id),
UNIQUE KEY event_slug (event_slug)
) $charset_collate;";

        // Create registrations table
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
school_classification varchar(20) NOT NULL,
session_time varchar(20) NOT NULL,
payment_option varchar(20) NOT NULL,
payment_method varchar(20) NOT NULL,
total_amount decimal(10,2) NOT NULL,
deposit_amount decimal(10,2) DEFAULT '0.00',
amount_paid decimal(10,2) DEFAULT '0.00',
balance_due decimal(10,2) DEFAULT '0.00',
payment_status varchar(20) DEFAULT 'pending',
registration_status varchar(20) DEFAULT 'pending',
payment_notes text,
payment_completion_token varchar(64),
stripe_payment_intent_id varchar(255),
registration_date datetime DEFAULT '0000-00-00 00:00:00',
last_updated datetime DEFAULT '0000-00-00 00:00:00',
PRIMARY KEY (id),
KEY event_id (event_id),
KEY payment_completion_token (payment_completion_token)
) $charset_collate;";

        // Create payment tokens table for secure payment completion
        $payment_tokens_sql = "CREATE TABLE $payment_tokens_table (
id mediumint(9) NOT NULL AUTO_INCREMENT,
registration_id mediumint(9) NOT NULL,
token varchar(64) NOT NULL,
token_type varchar(20) DEFAULT 'payment_completion',
amount_due decimal(10,2) NOT NULL,
expires_at datetime NOT NULL,
used_at datetime DEFAULT NULL,
created_at datetime DEFAULT '0000-00-00 00:00:00',
ip_address varchar(45),
user_agent text,
status varchar(20) DEFAULT 'active',
PRIMARY KEY (id),
UNIQUE KEY token (token),
KEY registration_id (registration_id),
KEY expires_at (expires_at),
KEY status (status)
) $charset_collate;";

        // Create spam log table for anti-spam tracking
        $spam_log_sql = "CREATE TABLE $spam_log_table (
id mediumint(9) NOT NULL AUTO_INCREMENT,
ip_address varchar(45) NOT NULL,
attempt_type varchar(50) NOT NULL,
form_data text,
spam_score int(3) DEFAULT 0,
blocked tinyint(1) DEFAULT 0,
reason varchar(255),
attempted_at datetime DEFAULT '0000-00-00 00:00:00',
user_agent text,
referrer varchar(500),
PRIMARY KEY (id),
KEY ip_address (ip_address),
KEY attempted_at (attempted_at),
KEY blocked (blocked)
) $charset_collate;";

        // Log SQL statements
        error_log('SBR: Events SQL: ' . $events_sql);
        error_log('SBR: Registrations SQL: ' . $registrations_sql);
        error_log('SBR: Payment Tokens SQL: ' . $payment_tokens_sql);
        error_log('SBR: Spam Log SQL: ' . $spam_log_sql);

        // Try to create tables using dbDelta
        try {
            error_log('SBR: Creating events table...');
            $events_result = dbDelta($events_sql);
            error_log('SBR: Events dbDelta result: ' . print_r($events_result, true));

            error_log('SBR: Creating registrations table...');
            $registrations_result = dbDelta($registrations_sql);
            error_log('SBR: Registrations dbDelta result: ' . print_r($registrations_result, true));

            error_log('SBR: Creating payment tokens table...');
            $payment_tokens_result = dbDelta($payment_tokens_sql);
            error_log('SBR: Payment tokens dbDelta result: ' . print_r($payment_tokens_result, true));

            error_log('SBR: Creating spam log table...');
            $spam_log_result = dbDelta($spam_log_sql);
            error_log('SBR: Spam log dbDelta result: ' . print_r($spam_log_result, true));

        } catch (Exception $e) {
            error_log('SBR: Exception during dbDelta: ' . $e->getMessage());
        }

        // If dbDelta fails, try direct SQL execution
        $events_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
        $registrations_exists = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;
        $payment_tokens_exists = $wpdb->get_var("SHOW TABLES LIKE '$payment_tokens_table'") == $payment_tokens_table;
        $spam_log_exists = $wpdb->get_var("SHOW TABLES LIKE '$spam_log_table'") == $spam_log_table;

        if (!$events_exists) {
            error_log('SBR: Events table not created by dbDelta, trying direct SQL');
            $result = $wpdb->query($events_sql);
            error_log('SBR: Direct SQL result for events table: ' . ($result !== false ? 'SUCCESS' : 'FAILED'));
            if ($wpdb->last_error) {
                error_log('SBR: Direct SQL error for events table: ' . $wpdb->last_error);
            }
        }

        if (!$registrations_exists) {
            error_log('SBR: Registrations table not created by dbDelta, trying direct SQL');
            $result = $wpdb->query($registrations_sql);
            error_log('SBR: Direct SQL result for registrations table: ' . ($result !== false ? 'SUCCESS' : 'FAILED'));
            if ($wpdb->last_error) {
                error_log('SBR: Direct SQL error for registrations table: ' . $wpdb->last_error);
            }
        }

        if (!$payment_tokens_exists) {
            error_log('SBR: Payment tokens table not created by dbDelta, trying direct SQL');
            $result = $wpdb->query($payment_tokens_sql);
            error_log('SBR: Direct SQL result for payment tokens table: ' . ($result !== false ? 'SUCCESS' : 'FAILED'));
            if ($wpdb->last_error) {
                error_log('SBR: Direct SQL error for payment tokens table: ' . $wpdb->last_error);
            }
        }

        if (!$spam_log_exists) {
            error_log('SBR: Spam log table not created by dbDelta, trying direct SQL');
            $result = $wpdb->query($spam_log_sql);
            error_log('SBR: Direct SQL result for spam log table: ' . ($result !== false ? 'SUCCESS' : 'FAILED'));
            if ($wpdb->last_error) {
                error_log('SBR: Direct SQL error for spam log table: ' . $wpdb->last_error);
            }
        }

        // Final verification
        $events_exists_final = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
        $registrations_exists_final = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;
        $payment_tokens_exists_final = $wpdb->get_var("SHOW TABLES LIKE '$payment_tokens_table'") == $payment_tokens_table;
        $spam_log_exists_final = $wpdb->get_var("SHOW TABLES LIKE '$spam_log_table'") == $spam_log_table;

        error_log('SBR: Final verification - Events table exists: ' . ($events_exists_final ? 'YES' : 'NO'));
        error_log('SBR: Final verification - Registrations table exists: ' . ($registrations_exists_final ? 'YES' : 'NO'));
        error_log('SBR: Final verification - Payment tokens table exists: ' . ($payment_tokens_exists_final ? 'YES' : 'NO'));
        error_log('SBR: Final verification - Spam log table exists: ' . ($spam_log_exists_final ? 'YES' : 'NO'));

        if ($wpdb->last_error) {
            error_log('SBR: Final MySQL error: ' . $wpdb->last_error);
        }

        return $events_exists_final && $registrations_exists_final && $payment_tokens_exists_final && $spam_log_exists_final;
    }

    public function ensure_tables_exist() {
        // Only run this once per request and only for admin users
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // Check if we've already tried this in this request
        static $tables_checked = false;
        if ($tables_checked) {
            return;
        }
        $tables_checked = true;

        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';
        $payment_tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';
        $spam_log_table = $wpdb->prefix . 'scholars_bowl_spam_log';

        $events_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
        $registrations_exists = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;
        $payment_tokens_exists = $wpdb->get_var("SHOW TABLES LIKE '$payment_tokens_table'") == $payment_tokens_table;
        $spam_log_exists = $wpdb->get_var("SHOW TABLES LIKE '$spam_log_table'") == $spam_log_table;

        // If tables don't exist, try to create them
        if (!$events_exists || !$registrations_exists || !$payment_tokens_exists || !$spam_log_exists) {
            error_log('SBR: Tables missing during init, attempting to create them');
            error_log('SBR: Events table exists: ' . ($events_exists ? 'YES' : 'NO'));
            error_log('SBR: Registrations table exists: ' . ($registrations_exists ? 'YES' : 'NO'));
            error_log('SBR: Payment tokens table exists: ' . ($payment_tokens_exists ? 'YES' : 'NO'));
            error_log('SBR: Spam log table exists: ' . ($spam_log_exists ? 'YES' : 'NO'));

            $this->create_database_table();

            // Set default options if tables were just created
            $this->set_default_options();

            // Create default event if tables were just created
            $this->create_default_event();
        } else {
            // Tables exist, but check if we need to add new columns
            $this->ensure_email_columns_exist();
        $this->add_payment_deadline_column();
        }
    }

    public function set_default_options() {
        $defaults = array(
            'sbr_event_name' => 'Scholars\' Bowl Competition',
            'sbr_event_date' => '2025-11-04',
            'sbr_event_location' => 'Wadley Campus of Southern Union State Community College',
            'sbr_early_bird_deadline' => '2025-10-01',
            'sbr_early_bird_rate' => '14.00',
            'sbr_standard_rate' => '15.00',
            'sbr_deposit_amount' => '50.00',
            'sbr_max_students' => '6',
            'sbr_max_additional' => '4',
            'sbr_free_coaches' => '2',
            'sbr_organization_name' => '',
            'sbr_organizer_name' => '',
            'sbr_organizer_email' => '',
            'sbr_organizer_phone' => '',
            'sbr_payment_address' => '',
            'sbr_stripe_mode' => 'test',
            'sbr_stripe_publishable_key' => 'pk_live_51I8ZwGAifsV2HHSa0jgLD6S16izScuihE2WtExBWzbyBsawOazS9cjt1aFyBsdSuK9nYwDD7Vh7LUOoa0Evb7Evb00yVEpTIJL',
            'sbr_stripe_secret_key' => '',
            'sbr_morning_session_time' => '8:30 AM',
            'sbr_afternoon_session_time' => '12:15 PM',
            'sbr_morning_classifications' => 'Private,1A,2A,3A',
            'sbr_afternoon_classifications' => '4A,5A,6A'
        );

        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
    }

    public function create_default_event() {
        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';

        // First check if events table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
        if (!$table_exists) {
            error_log('SBR: Cannot create default event - events table does not exist');
            return false;
        }

        // Check if default event already exists
        $existing_event = $wpdb->get_var("SELECT COUNT(*) FROM $events_table WHERE event_slug = 'scholars-bowl-2025'");

        if ($existing_event == 0) {
            $result = $wpdb->insert(
                $events_table,
                array(
                    'event_name' => 'Scholars\' Bowl Competition 2025',
                    'event_slug' => 'scholars-bowl-2025',
                    'event_date' => '2025-11-04',
                    'event_location' => 'Wadley Campus of Southern Union State Community College',
                    'early_bird_deadline' => '2025-10-01',
                    'early_bird_rate' => '14.00',
                    'standard_rate' => '15.00',
                    'deposit_amount' => '50.00',
                    'max_students' => '6',
                    'max_additional' => '4',
                    'free_coaches' => '2',
                    'morning_session_time' => '8:30 AM',
                    'afternoon_session_time' => '12:15 PM',
                    'morning_classifications' => 'Private,1A,2A,3A',
                    'afternoon_classifications' => '4A,5A,6A',
                    'organization_name' => get_option('sbr_organization_name', ''),
                    'organizer_name' => get_option('sbr_organizer_name', ''),
                    'organizer_email' => get_option('sbr_organizer_email', ''),
                    'organizer_phone' => get_option('sbr_organizer_phone', ''),
                    'payment_address' => get_option('sbr_payment_address', ''),
                    'stripe_publishable_key' => '', // Use global keys
                    'stripe_secret_key' => '', // Use global keys
                    'event_status' => 'active',
                    'created_date' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            if ($result === false) {
                error_log('SBR: Failed to create default event: ' . $wpdb->last_error);
                return false;
            } else {
                error_log('SBR: Default event created successfully with ID: ' . $wpdb->insert_id);
                return true;
            }
        } else {
            error_log('SBR: Default event already exists');
            return true;
        }
    }

    public function enqueue_scripts() {
        wp_enqueue_script('sbr-frontend', SBR_PLUGIN_URL . 'assets/frontend.js', array('jquery'), SBR_VERSION, true);
        wp_enqueue_style('sbr-frontend', SBR_PLUGIN_URL . 'assets/frontend.css', array(), SBR_VERSION);

        // Get Stripe key (global by default, event-specific override if available)
        $stripe_key = $this->get_active_stripe_publishable_key();

        // If we're on a page with a shortcode, try to get event-specific Stripe key
        global $post;
        if ($post && has_shortcode($post->post_content, 'scholars_bowl_registration')) {
            // Extract event slug from shortcode
            $pattern = '/\[scholars_bowl_registration[^\]]*event=["\']([^"\']+)["\'][^\]]*\]/';
            preg_match($pattern, $post->post_content, $matches);

            if (!empty($matches[1])) {
                global $wpdb;
                $events_table = $wpdb->prefix . 'scholars_bowl_events';
                $event = $wpdb->get_row($wpdb->prepare("SELECT stripe_publishable_key FROM $events_table WHERE event_slug = %s", $matches[1]));

                // Only use event-specific key if it's not empty, otherwise fall back to global active key
                if ($event && !empty($event->stripe_publishable_key)) {
                    $stripe_key = $event->stripe_publishable_key;
                }
            }
        }

        if ($stripe_key) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
        }

        wp_localize_script('sbr-frontend', 'sbr_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sbr_nonce'),
            'stripe_key' => $stripe_key
        ));
    }

    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'scholars-bowl') !== false) {
            wp_enqueue_script('sbr-admin', SBR_PLUGIN_URL . 'assets/admin.js', array('jquery'), SBR_VERSION, true);
            wp_enqueue_style('sbr-admin', SBR_PLUGIN_URL . 'assets/admin.css', array(), SBR_VERSION);
        }
    }

    public function admin_menu() {
        add_menu_page(
            'Scholars\' Bowl Registration',
            'Scholars\' Bowl',
            'manage_options',
            'scholars-bowl-events',
            array($this, 'admin_events_page'),
            'dashicons-clipboard',
            30
        );

        add_submenu_page(
            'scholars-bowl-events',
            'Events',
            'Events',
            'manage_options',
            'scholars-bowl-events',
            array($this, 'admin_events_page')
        );

        add_submenu_page(
            'scholars-bowl-events',
            'Registrations',
            'Registrations',
            'manage_options',
            'scholars-bowl-registrations',
            array($this, 'admin_registrations_page')
        );

        add_submenu_page(
            'scholars-bowl-events',
            'Add New Event',
            'Add New Event',
            'manage_options',
            'scholars-bowl-add-event',
            array($this, 'admin_add_event_page')
        );

        add_submenu_page(
            'scholars-bowl-events',
            'Settings',
            'Settings',
            'manage_options',
            'scholars-bowl-settings',
            array($this, 'admin_settings_page')
        );

        add_submenu_page(
            'scholars-bowl-events',
            'College Bowl Payments',
            'College Bowl',
            'manage_options',
            'college-bowl-payments',
            'college_bowl_admin_page'
        );

        add_submenu_page(
            'scholars-bowl-events',
            'College Bowl Settings',
            'CB Settings',
            'manage_options',
            'college-bowl-settings',
            'college_bowl_settings_page'
        );

        add_submenu_page(
            'scholars-bowl-events',
            'Stripe Setup Guide',
            'Stripe Setup Guide',
            'manage_options',
            'scholars-bowl-stripe-guide',
            array($this, 'admin_stripe_guide_page')
        );
    }

    public function admin_settings_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        include SBR_PLUGIN_PATH . 'includes/admin-settings.php';
    }

    public function admin_events_page() {
        // Show success messages from redirects
        if (isset($_GET['updated']) && $_GET['updated'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Event updated successfully!</p></div>';
        }
        if (isset($_GET['created']) && $_GET['created'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Event created successfully!</p></div>';
        }

        include SBR_PLUGIN_PATH . 'includes/admin-events.php';
    }

    public function admin_add_event_page() {
        $message = '';
        $message_type = '';

        if (isset($_POST['submit'])) {
            // Capture any output from save_event with error handling
            ob_start();
            $save_success = false;
            try {
                $this->save_event();
                $save_output = ob_get_clean();
                $save_success = true;
            } catch (Exception $e) {
                $save_output = ob_get_clean();
                error_log('SBR: Exception in save_event: ' . $e->getMessage());
                echo '<div class="notice notice-error"><p>Error saving event: ' . esc_html($e->getMessage()) . '</p></div>';
                $save_output = '';
                $save_success = false;
            }

            // Handle the save result
            if ($save_success && !empty($save_output) && strpos($save_output, 'notice-success') !== false) {
                // Success detected - perform redirect
                if (isset($_POST['event_id']) && !empty($_POST['event_id'])) {
                    // Event update
                    $redirect_url = add_query_arg(array(
                        'updated' => '1',
                        'event_id' => intval($_POST['event_id'])
                    ), admin_url('admin.php?page=scholars-bowl-events'));
                } else {
                    // New event creation
                    $redirect_url = add_query_arg(array(
                        'created' => '1'
                    ), admin_url('admin.php?page=scholars-bowl-events'));
                }

                // Ensure clean redirect
                if (!headers_sent()) {
                    error_log('SBR: Redirecting to events list after successful save');
                    wp_redirect($redirect_url);
                    exit;
                } else {
                    error_log('SBR: Headers already sent, cannot redirect - showing link instead');
                    $action = isset($_POST['event_id']) ? 'updated' : 'created';
                    echo '<div class="notice notice-success"><p>Event ' . $action . ' successfully! <a href="' . esc_url($redirect_url) . '">Return to events list</a></p></div>';
                }
            } elseif (!empty($save_output)) {
                // Error or other message - display it
                echo $save_output;
            } elseif (empty($save_output)) {
                // Fatal error likely occurred
                error_log('SBR: Empty save_output detected, possible fatal error');
                echo '<div class="notice notice-error"><p>An error occurred while saving the event. Please check the error logs.</p></div>';
            }
        }

        // Note: Success messages are now shown on the events list page after redirect

        include SBR_PLUGIN_PATH . 'includes/admin-add-event.php';
    }

    public function admin_registrations_page() {
        include SBR_PLUGIN_PATH . 'includes/admin-registrations.php';
    }

    public function admin_stripe_guide_page() {
        include SBR_PLUGIN_PATH . 'includes/admin-stripe-guide.php';
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Verify nonce
        if (!isset($_POST['sbr_settings_nonce']) || !wp_verify_nonce($_POST['sbr_settings_nonce'], 'sbr_settings')) {
            return;
        }

        // Only save global settings now
        $global_settings = array(
            'sbr_organization_name',
            'sbr_organizer_name',
            'sbr_organizer_email',
            'sbr_organizer_phone',
            'sbr_payment_address',
            'sbr_stripe_mode',
            'sbr_stripe_test_publishable_key',
            'sbr_stripe_test_secret_key',
            'sbr_stripe_live_publishable_key',
            'sbr_stripe_live_secret_key',
            'sbr_stripe_publishable_key',
            'sbr_stripe_secret_key',
            'sbr_default_from_email',
            'sbr_default_reply_to_email',
            'sbr_default_from_name',
            'sbr_default_registration_deadline',
            'sbr_registration_closed_message'
        );

        foreach ($global_settings as $setting) {
            if (isset($_POST[$setting])) {
                if (in_array($setting, ['sbr_organizer_email', 'sbr_default_from_email', 'sbr_default_reply_to_email'])) {
                    update_option($setting, sanitize_email($_POST[$setting]));
                } elseif ($setting === 'sbr_payment_address') {
                    update_option($setting, sanitize_textarea_field($_POST[$setting]));
                } else {
                    update_option($setting, sanitize_text_field($_POST[$setting]));
                }
            }
        }
    }

    public function save_event() {
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Permission denied.</p></div>';
            return;
        }

        // Verify nonce
        if (!isset($_POST['save_event_nonce']) || !wp_verify_nonce($_POST['save_event_nonce'], 'save_event')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }

        // Log the full POST data for debugging
        error_log('SBR: Save Event POST data: ' . print_r($_POST, true));

        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';

        // Clear any previous database errors
        $wpdb->last_error = '';

        // Ensure the table has all required columns (with error handling)
        try {
            $this->check_and_add_missing_columns();
        } catch (Exception $e) {
            error_log('SBR: Error in check_and_add_missing_columns: ' . $e->getMessage());
            echo '<div class="notice notice-warning"><p>Warning: Could not verify database schema. Event saving may fail.</p></div>';
        }

        // Validate required fields
        if (empty($_POST['event_name']) || empty($_POST['event_slug'])) {
            echo '<div class="notice notice-error"><p>Event name and slug are required.</p></div>';
            return;
        }

        // Validate numeric fields
        $min_students = intval($_POST['min_students'] ?? 1);
        $max_students = intval($_POST['max_students'] ?? 6);

        if ($min_students < 1 || $min_students > 20) {
            echo '<div class="notice notice-error"><p>Minimum students must be between 1 and 20.</p></div>';
            return;
        }

        if ($max_students < $min_students || $max_students > 20) {
            echo '<div class="notice notice-error"><p>Maximum students must be greater than or equal to minimum students and not exceed 20.</p></div>';
            return;
        }

        $event_data = array(
            'event_name' => wp_strip_all_tags($_POST['event_name']),
            'event_slug' => sanitize_title($_POST['event_slug']),
            'event_date' => sanitize_text_field($_POST['event_date']),
            'event_location' => sanitize_textarea_field($_POST['event_location']),
            'early_bird_deadline' => sanitize_text_field($_POST['early_bird_deadline']),
            'registration_deadline' => !empty($_POST['registration_deadline']) ? sanitize_text_field($_POST['registration_deadline']) : null,
            'payment_deadline' => !empty($_POST['payment_deadline']) ? sanitize_text_field($_POST['payment_deadline']) : null,
            'early_bird_rate' => floatval($_POST['early_bird_rate']),
            'standard_rate' => floatval($_POST['standard_rate']),
            'deposit_amount' => floatval($_POST['deposit_amount']),
            'max_students' => $max_students,
            'min_students' => $min_students,
            'max_additional' => intval($_POST['max_additional']),
            'free_coaches' => intval($_POST['free_coaches']),
            'morning_session_time' => sanitize_text_field($_POST['morning_session_time']),
            'afternoon_session_time' => sanitize_text_field($_POST['afternoon_session_time']),
            'morning_classifications' => sanitize_text_field($_POST['morning_classifications']),
            'afternoon_classifications' => sanitize_text_field($_POST['afternoon_classifications']),
            'organization_name' => sanitize_text_field($_POST['organization_name']),
            'organizer_name' => sanitize_text_field($_POST['organizer_name']),
            'organizer_email' => sanitize_email($_POST['organizer_email']),
            'organizer_phone' => sanitize_text_field($_POST['organizer_phone']),
            'payment_address' => sanitize_textarea_field($_POST['payment_address']),
            'from_email' => sanitize_email($_POST['from_email']),
            'reply_to_email' => sanitize_email($_POST['reply_to_email']),
            'from_name' => sanitize_text_field($_POST['from_name']),
            'stripe_mode' => sanitize_text_field($_POST['stripe_mode']),
            'stripe_test_publishable_key' => sanitize_text_field($_POST['stripe_test_publishable_key']),
            'stripe_test_secret_key' => sanitize_text_field($_POST['stripe_test_secret_key']),
            'stripe_live_publishable_key' => sanitize_text_field($_POST['stripe_live_publishable_key']),
            'stripe_live_secret_key' => sanitize_text_field($_POST['stripe_live_secret_key']),
            'stripe_publishable_key' => sanitize_text_field($_POST['stripe_publishable_key']),
            'stripe_secret_key' => sanitize_text_field($_POST['stripe_secret_key']),
            'event_status' => sanitize_text_field($_POST['event_status'])
        );

        $result = false;
        $message = '';
        $is_update = false;

        if (isset($_POST['event_id']) && !empty($_POST['event_id'])) {
            // Update existing event
            $event_id = intval($_POST['event_id']);
            $is_update = true;

            // Get current event data for comparison
            $current_event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events_table WHERE id = %d", $event_id));
            error_log('SBR: Current event data: ' . print_r($current_event, true));

            // Log the update attempt
            error_log('SBR: Attempting to update event ID: ' . $event_id);
            error_log('SBR: New update data: ' . print_r($event_data, true));

            // Check for duplicate slug (excluding current event)
            $existing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events_table WHERE event_slug = %s AND id != %d", $event_data['event_slug'], $event_id));
            if ($existing > 0) {
                echo '<div class="notice notice-error"><p>Event slug already exists. Please choose a different slug.</p></div>';
                return;
            }

            // Log the data format for debugging (34 fields total - added payment_deadline)
            $data_format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

            // Safety check: ensure data array and format array match
            if (count($event_data) !== count($data_format)) {
                error_log('SBR: Data/Format array mismatch - Data: ' . count($event_data) . ', Format: ' . count($data_format));
                echo '<div class="notice notice-error"><p>Internal error: Data format mismatch. Please contact support.</p></div>';
                return;
            }

            $result = $wpdb->update(
                $events_table,
                $event_data,
                array('id' => $event_id),
                $data_format,
                array('%d')
            );

            // Log the result and verify the update
            error_log('SBR: Update result: ' . ($result !== false ? $result : 'FALSE'));
            if ($wpdb->last_error) {
                error_log('SBR: Update error: ' . $wpdb->last_error);
                error_log('SBR: Last query: ' . $wpdb->last_query);
            }

            // Verify the update worked by fetching the updated data
            $updated_event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events_table WHERE id = %d", $event_id));
            error_log('SBR: Updated event data: ' . print_r($updated_event, true));

            $message = 'Event updated successfully!';
        } else {
            // Check for duplicate slug
            $existing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $events_table WHERE event_slug = %s", $event_data['event_slug']));
            if ($existing > 0) {
                echo '<div class="notice notice-error"><p>Event slug already exists. Please choose a different slug.</p></div>';
                return;
            }

            // Create new event (34 fields total - added payment_deadline)
            $data_format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

            // Safety check: ensure data array and format array match
            if (count($event_data) !== count($data_format)) {
                error_log('SBR: Insert Data/Format array mismatch - Data: ' . count($event_data) . ', Format: ' . count($data_format));
                echo '<div class="notice notice-error"><p>Internal error: Data format mismatch. Please contact support.</p></div>';
                return;
            }

            $result = $wpdb->insert($events_table, $event_data, $data_format);

            if ($wpdb->last_error) {
                error_log('SBR: Insert error: ' . $wpdb->last_error);
                error_log('SBR: Last query: ' . $wpdb->last_query);
            }

            $message = 'Event created successfully!';
        }

        // For updates, $wpdb->update() returns 0 if no rows changed (data identical) or number of rows updated
        // For inserts, $wpdb->insert() returns false on error or number of rows inserted
        if ($result !== false) {
            if ($is_update && $result === 0) {
                echo '<div class="notice notice-info"><p>No changes were made to the event (data was identical).</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . $message . '</p></div>';
            }
            // Clear the setup flag if this was the first event
            delete_option('sbr_needs_setup');
        } else {
            $error_message = $wpdb->last_error ?: 'Unknown database error occurred';
            $additional_info = '';

            // Check if it's a table structure issue
            if (strpos($error_message, 'Unknown column') !== false) {
                $additional_info = ' This may be due to missing database columns. Please deactivate and reactivate the plugin to update the database schema.';
            } elseif (strpos($error_message, 'Duplicate entry') !== false) {
                $additional_info = ' This event slug already exists. Please choose a different slug.';
            }

            echo '<div class="notice notice-error"><p>Database error: ' . esc_html($error_message) . $additional_info . '</p></div>';
            error_log('SBR Save Event Error: ' . $error_message);
            error_log('SBR Last query: ' . ($wpdb->last_query ?: 'No query recorded'));

            // Check table structure
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
            error_log('SBR Table exists: ' . ($table_exists ? 'YES' : 'NO'));

            if ($table_exists) {
                $columns = $wpdb->get_col("DESCRIBE $events_table");
                error_log('SBR Table columns: ' . implode(', ', $columns));
            }
        }
    }

    public function registration_shortcode($atts) {
        $atts = shortcode_atts(array(
            'event' => 'scholars-bowl-2025', // Default event slug
        ), $atts);

        ob_start();
        include SBR_PLUGIN_PATH . 'includes/registration-form.php';
        return ob_get_clean();
    }

    public function ajax_calculate_total() {
        check_ajax_referer('sbr_nonce', 'nonce');

        $competing_students = intval($_POST['competing_students']);
        $additional_students = intval($_POST['additional_students'] ?? 0);
        $additional_adults = intval($_POST['additional_adults'] ?? 0);
        $additional_attendees = $additional_students + $additional_adults; // Total for backward compatibility
        $coaches = intval($_POST['coaches']);
        $event_id = intval($_POST['event_id'] ?? 0);

        // Get event-specific settings
        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';

        if ($event_id > 0) {
            $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events_table WHERE id = %d", $event_id));
        } else {
            // Fallback to legacy options if no event_id provided
            $event = null;
        }

        if ($event) {
            $free_coaches_limit = intval($event->free_coaches);
            $early_bird_deadline = $event->early_bird_deadline;
            $early_bird_rate = floatval($event->early_bird_rate);
            $standard_rate = floatval($event->standard_rate);
            $deposit = floatval($event->deposit_amount);
        } else {
            // Legacy fallback
            $free_coaches_limit = intval(get_option('sbr_free_coaches', 2));
            $early_bird_deadline = get_option('sbr_early_bird_deadline');
            $early_bird_rate = floatval(get_option('sbr_early_bird_rate'));
            $standard_rate = floatval(get_option('sbr_standard_rate'));
            $deposit = floatval(get_option('sbr_deposit_amount'));
        }

        $total_attendees = $competing_students + $additional_attendees;
        $free_coaches = min($coaches, $free_coaches_limit);
        $paid_coaches = max(0, $coaches - $free_coaches);
        $total_attendees += $paid_coaches;

        $current_date = current_time('Y-m-d');
        $rate = ($current_date <= $early_bird_deadline) ? $early_bird_rate : $standard_rate;

        $total = $total_attendees * $rate;

        wp_send_json_success(array(
            'total' => $total,
            'deposit' => $deposit,
            'rate' => $rate,
            'is_early_bird' => ($current_date <= $early_bird_deadline)
        ));
    }

    public function handle_registration_submission() {
        check_ajax_referer('sbr_nonce', 'nonce');

        // Validate submission for spam
        $spam_check = $this->validate_submission_for_spam($_POST);
        if ($spam_check['blocked']) {
            wp_send_json_error($spam_check['reason']);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';
        $events_table = $wpdb->prefix . 'scholars_bowl_events';

        $event_id = intval($_POST['event_id'] ?? 0);
        $school_name = sanitize_text_field($_POST['school_name']);
        $contact_name = sanitize_text_field($_POST['contact_name']);
        $contact_email = sanitize_email($_POST['contact_email']);
        $contact_phone = sanitize_text_field($_POST['contact_phone']);
        $dietary_restrictions = isset($_POST['dietary_restrictions']) ? sanitize_textarea_field($_POST['dietary_restrictions']) : '';
        $competing_students = intval($_POST['competing_students']);
        $additional_students = intval($_POST['additional_students'] ?? 0);
        $additional_adults = intval($_POST['additional_adults'] ?? 0);
        $additional_attendees = $additional_students + $additional_adults; // Total for backward compatibility
        $coaches = intval($_POST['coaches']);
        $grade_level = sanitize_text_field($_POST['grade_level']);
        $payment_option = sanitize_text_field($_POST['payment_option']);
        $payment_method = sanitize_text_field($_POST['payment_method']);

        // Get event-specific settings
        if ($event_id > 0) {
            $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events_table WHERE id = %d", $event_id));
        } else {
            $event = null;
        }

        if ($event) {
            $morning_classifications = explode(',', $event->morning_classifications);
            $morning_classifications = array_map('trim', $morning_classifications);
            $free_coaches_limit = intval($event->free_coaches);
            $early_bird_deadline = $event->early_bird_deadline;
            $early_bird_rate = floatval($event->early_bird_rate);
            $standard_rate = floatval($event->standard_rate);
            $deposit_rate = floatval($event->deposit_amount);
        } else {
            // Legacy fallback
            $morning_classifications = explode(',', get_option('sbr_morning_classifications', 'Private,1A,2A,3A'));
            $morning_classifications = array_map('trim', $morning_classifications);
            $free_coaches_limit = intval(get_option('sbr_free_coaches', 2));
            $early_bird_deadline = get_option('sbr_early_bird_deadline');
            $early_bird_rate = floatval(get_option('sbr_early_bird_rate'));
            $standard_rate = floatval(get_option('sbr_standard_rate'));
            $deposit_rate = floatval(get_option('sbr_deposit_amount'));
        }

        $session_time = in_array($grade_level, $morning_classifications) ? 'morning' : 'afternoon';

        $total_attendees = $competing_students + $additional_attendees;
        $free_coaches = min($coaches, $free_coaches_limit);
        $paid_coaches = max(0, $coaches - $free_coaches);
        $total_attendees += $paid_coaches;

        $current_date = current_time('Y-m-d');
        $rate = ($current_date <= $early_bird_deadline) ? $early_bird_rate : $standard_rate;

        $total_amount = $total_attendees * $rate;
        $deposit_amount = ($payment_option === 'deposit') ? $deposit_rate : $total_amount;

        // Check for existing registrations from same email within last 10 minutes to prevent duplicates
        $recent_cutoff = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        $existing_registration = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name WHERE contact_email = %s AND school_name = %s AND registration_date > %s AND payment_status IN ('pending', 'processing')",
            $contact_email,
            $school_name,
            $recent_cutoff
        ));

        if ($existing_registration && $payment_method !== 'check' && $payment_method !== 'cash') {
            // For credit card payments, return the existing registration ID instead of creating a duplicate
            wp_send_json_success(array(
                'registration_id' => $existing_registration->id,
                'amount' => $deposit_amount,
                'requires_payment' => true,
                'message' => 'Using existing registration for payment processing.'
            ));
            return;
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'event_id' => $event_id,
                'school_name' => $school_name,
                'contact_name' => $contact_name,
                'contact_email' => $contact_email,
                'contact_phone' => $contact_phone,
                'dietary_restrictions' => $dietary_restrictions,
                'competing_students' => $competing_students,
                'additional_attendees' => $additional_attendees,
                'additional_students' => $additional_students,
                'additional_adults' => $additional_adults,
                'coaches' => $coaches,
                'grade_level' => $grade_level,
                'session_time' => $session_time,
                'payment_option' => $payment_option,
                'payment_method' => $payment_method,
                'total_amount' => $total_amount,
                'deposit_amount' => $deposit_amount,
                'payment_status' => ($payment_method === 'check' || $payment_method === 'cash') ? 'pending' : 'processing',
                'amount_paid' => 0,
                'balance_due' => $total_amount
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%f', '%f')
        );

        if ($result !== false) {
            $registration_id = $wpdb->insert_id;

            if ($payment_method === 'check' || $payment_method === 'cash') {
                // Generate payment completion link for check/cash payments if payment option is deposit
                if ($payment_option === 'deposit' && $total_amount > $deposit_amount) {
                    $balance_due = $total_amount - $deposit_amount;
                    $payment_token = $this->generate_payment_token_for_event($registration_id, $event_id, $balance_due);
                    if ($payment_token) {
                        error_log("SBR: Auto-generated payment completion token for check/cash registration $registration_id with balance $balance_due");
                    }
                }
                
                $this->send_confirmation_email($registration_id, $payment_method);
                $payment_type = $payment_method === 'check' ? 'check' : 'cash';
                wp_send_json_success(array(
                    'message' => "Registration successful! Check your email for {$payment_type} payment instructions.",
                    'registration_id' => $registration_id
                ));
            } else {
                wp_send_json_success(array(
                    'registration_id' => $registration_id,
                    'amount' => $deposit_amount,
                    'requires_payment' => true
                ));
            }
        } else {
            wp_send_json_error('Registration failed. Please try again.');
        }
    }

    public function process_stripe_payment() {
        check_ajax_referer('sbr_nonce', 'nonce');

        $registration_id = intval($_POST['registration_id']);
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'view';
        $payment_method_id = sanitize_text_field($_POST['payment_method_id']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $registration_id
        ));

        if (!$registration) {
            wp_send_json_error('Registration not found.');
            return;
        }

        // Get event-specific Stripe keys or fall back to global keys
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        $event = $wpdb->get_row($wpdb->prepare("SELECT stripe_secret_key FROM $events_table WHERE id = %d", $registration->event_id));

        $stripe_secret_key = null;
        if ($event && !empty($event->stripe_secret_key)) {
            $stripe_secret_key = $event->stripe_secret_key;
        } else {
            $stripe_secret_key = $this->get_active_stripe_secret_key();
        }

        require_once SBR_PLUGIN_PATH . 'includes/stripe-handler.php';
        $stripe_handler = new SBR_Stripe_Handler($stripe_secret_key);

        $result = $stripe_handler->process_payment(
            $payment_method_id,
            $registration->deposit_amount * 100, // Convert to cents
            $registration->contact_email,
            $registration_id
        );

        if ($result['success']) {
            // Calculate the correct amount paid and balance due
            $amount_paid = $registration->deposit_amount;
            $balance_due = max(0, $registration->total_amount - $amount_paid);

            // Determine payment status based on whether this is a deposit or full payment
            $payment_status = ($registration->payment_option === 'deposit' && $balance_due > 0.01) ? 'partial' : 'completed';

            $wpdb->update(
                $table_name,
                array(
                    'payment_status' => $payment_status,
                    'amount_paid' => $amount_paid,
                    'balance_due' => $balance_due,
                    'stripe_payment_intent_id' => $result['payment_intent_id']
                ),
                array('id' => $registration_id),
                array('%s', '%f', '%f', '%s'),
                array('%d')
            );

            // Generate payment completion link if there's a remaining balance
            if ($balance_due > 0.01) {
                $payment_token = $this->generate_payment_token_for_event($registration_id, $registration->event_id, $balance_due);
                if ($payment_token) {
                    error_log("SBR: Auto-generated payment completion token for registration $registration_id with balance $balance_due");
                }
            }

            $this->send_confirmation_email($registration_id, 'stripe');
            wp_send_json_success(array('message' => 'Payment successful! Check your email for confirmation.'));
        } else {
            // Mark registration as failed payment for cleanup purposes
            $wpdb->update(
                $table_name,
                array(
                    'payment_status' => 'failed',
                    'payment_notes' => 'Stripe payment failed: ' . $result['error']
                ),
                array('id' => $registration_id),
                array('%s', '%s'),
                array('%d')
            );

            wp_send_json_error($result['error']);
        }
    }

    public function send_confirmation_email($registration_id, $payment_method) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';
        $events_table = $wpdb->prefix . 'scholars_bowl_events';

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $registration_id
        ));

        if (!$registration) {
            return false;
        }

        // Get event details
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $events_table WHERE id = %d",
            $registration->event_id
        ));

        if (!$event) {
            return false;
        }

        $event_name = $event->event_name;
        $event_date = $event->event_date;
        $event_location = $event->event_location;
        $organizer_email = $event->organizer_email;
        $organizer_name = $event->organizer_name;
        $organization_name = $event->organization_name;
        $payment_address = $event->payment_address;

        $subject = "Registration Confirmation - $event_name";

        $message = "Dear {$registration->contact_name},\n\n";
        $message .= "Thank you for registering {$registration->school_name} for the $event_name.\n\n";
        $message .= "Event Details:\n";
        $message .= "Date: " . date('F j, Y', strtotime($event_date)) . "\n";
        $message .= "Location: $event_location\n";
        $morning_time = get_option('sbr_morning_session_time', '8:30 AM');
        $afternoon_time = get_option('sbr_afternoon_session_time', '12:15 PM');
        $session_display_time = ($registration->session_time === 'morning') ? $morning_time : $afternoon_time;
        $message .= "Session: " . ucfirst($registration->session_time) . " ($session_display_time)\n\n";

        $message .= "Registration Summary:\n";
        $message .= "Competing Students: {$registration->competing_students}\n";
        $message .= "Additional Attendees: {$registration->additional_attendees}\n";
        $message .= "Coaches: {$registration->coaches}\n";
        $message .= "Total Amount: $" . number_format($registration->total_amount, 2) . "\n";

        if ($payment_method === 'check') {
            $message .= "\nCheck Payment Instructions:\n";
            $message .= "Please mail your check to:\n$payment_address\n\n";
            $message .= "Make checks payable to: " . ($organization_name ?: 'Organization Name') . "\n";
            $message .= "Include this registration ID in the memo: #{$registration_id}\n";
        } elseif ($payment_method === 'cash') {
            $message .= "\nCash Payment Instructions:\n";
            $message .= "Please coordinate cash payment delivery with the event organizer.\n";
            $message .= "Contact: $organizer_name at $organizer_email\n";
            $message .= "Reference registration ID: #{$registration_id}\n";
        } else {
            $message .= "\nPayment Status: ";
            $message .= ($registration->balance_due > 0) ? "Deposit Paid\n" : "Completed\n";
            $message .= "Amount Paid: $" . number_format($registration->amount_paid, 2) . "\n";
            
            // Include payment completion link if there's a remaining balance
            if ($registration->balance_due > 0) {
                $message .= "Remaining Balance: $" . number_format($registration->balance_due, 2) . "\n\n";
                
                // Get the payment completion token for this registration
                $tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';
                $token_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT token, expires_at FROM $tokens_table 
                    WHERE registration_id = %d 
                    AND status = 'active' 
                    AND used_at IS NULL 
                    ORDER BY created_at DESC 
                    LIMIT 1",
                    $registration_id
                ));
                
                if ($token_row) {
                    $completion_page_url = home_url('/complete-payment/');
                    $payment_url = add_query_arg('token', $token_row->token, $completion_page_url);
                    $expiration_date = date('F j, Y', strtotime($token_row->expires_at));
                    
                    $message .= "Complete Your Payment Online:\n";
                    $message .= "Click the secure link below to pay your remaining balance with a credit card:\n\n";
                    $message .= "$payment_url\n\n";
                    $message .= "This payment link will expire on $expiration_date.\n\n";
                    $message .= "Alternatively, you can send a check or cash payment to the address below.\n";
                }
            }
        }

        $message .= "\nIf you have any questions, please contact us at $organizer_email.\n\n";
        $message .= "Thank you,\nScholars' Bowl Team";

        // Determine email configuration using inheritance (event overrides global)
        $from_email = !empty($event->from_email) ? $event->from_email : get_option('sbr_default_from_email', '');
        $reply_to_email = !empty($event->reply_to_email) ? $event->reply_to_email : get_option('sbr_default_reply_to_email', '');
        $from_name = !empty($event->from_name) ? $event->from_name : get_option('sbr_default_from_name', '');

        // Fall back to organizer email if no email configuration is set
        if (empty($from_email)) {
            $from_email = $organizer_email;
        }
        if (empty($reply_to_email)) {
            $reply_to_email = $organizer_email;
        }
        if (empty($from_name)) {
            $from_name = $organizer_name ?: $organization_name ?: 'Scholars\' Bowl Team';
        }

        // Set up wp_mail headers
        $headers = array();
        if (!empty($from_email) && !empty($from_name)) {
            $headers[] = "From: {$from_name} <{$from_email}>";
        } elseif (!empty($from_email)) {
            $headers[] = "From: {$from_email}";
        }

        if (!empty($reply_to_email)) {
            $headers[] = "Reply-To: {$reply_to_email}";
        }

        return wp_mail($registration->contact_email, $subject, $message, $headers);
    }

    public function check_setup_needed() {
        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

        // Check if tables exist
        $events_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
        $registrations_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;

        $has_events = false;

        if ($events_table_exists) {
            $event_count = $wpdb->get_var("SELECT COUNT(*) FROM $events_table");
            $has_events = $event_count > 0;
        }

        // Only show setup notice if tables don't exist OR if events table exists but has no events
        if (!$events_table_exists || !$registrations_table_exists || !$has_events) {
            update_option('sbr_needs_setup', true);
        } else {
            delete_option('sbr_needs_setup');
        }
    }

    public function admin_notices() {
        // Show setup notice if needed
        if (get_option('sbr_needs_setup')) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><strong>Scholars' Bowl Registration:</strong> Database tables need to be created for the new multi-event system.
                <a href="#" onclick="sbrSetupTables(); return false;" class="button button-primary" style="margin-left: 10px;" id="sbr-setup-btn">Setup Now</a>
                <a href="#" onclick="sbrManualCreateTables(); return false;" class="button button-secondary" style="margin-left: 10px;" id="sbr-manual-btn">Manual Debug</a>
                <span id="sbr-setup-status" style="margin-left: 10px;"></span></p>
            </div>
            <script>
            function sbrSetupTables() {
                var btn = document.getElementById('sbr-setup-btn');
                var status = document.getElementById('sbr-setup-status');

                // Show loading state
                btn.disabled = true;
                btn.textContent = 'Setting up...';
                status.innerHTML = '<span style="color: #0073aa;">⏳ Creating database tables...</span>';

                console.log('Starting SBR setup process...');

                jQuery.post(ajaxurl, {
                    action: 'sbr_setup_tables',
                    nonce: '<?php echo wp_create_nonce('sbr_setup'); ?>'
                })
                .done(function(response) {
                    console.log('Setup response:', response);
                    if (response.success) {
                        status.innerHTML = '<span style="color: #46b450;">✅ ' + response.data + '</span>';
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        status.innerHTML = '<span style="color: #dc3232;">❌ Setup failed: ' + (response.data || 'Unknown error') + '</span>';
                        btn.disabled = false;
                        btn.textContent = 'Retry Setup';
                    }
                })
                .fail(function(xhr, status, error) {
                    console.log('AJAX error:', xhr, status, error);
                    document.getElementById('sbr-setup-status').innerHTML = '<span style="color: #dc3232;">❌ Network error: ' + error + '</span>';
                    btn.disabled = false;
                    btn.textContent = 'Retry Setup';
                });
            }

            function sbrManualCreateTables() {
                var btn = document.getElementById('sbr-manual-btn');
                var status = document.getElementById('sbr-setup-status');

                btn.disabled = true;
                btn.textContent = 'Testing...';
                status.innerHTML = '<span style="color: #0073aa;">🔧 Running manual table creation test...</span>';

                jQuery.post(ajaxurl, {
                    action: 'sbr_manual_create_tables'
                })
                .done(function(response) {
                    console.log('Manual creation response:', response);
                    var data = response.data || {};
                    var message = 'Events table: ' + (data.events_table_exists ? 'EXISTS' : 'MISSING') +
                                  ', Registrations table: ' + (data.registrations_table_exists ? 'EXISTS' : 'MISSING');

                    if (response.success) {
                        status.innerHTML = '<span style="color: #46b450;">✅ ' + message + '</span>';
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        status.innerHTML = '<span style="color: #dc3232;">❌ ' + message + '</span>';
                        if (data.mysql_error) {
                            status.innerHTML += '<br><small>MySQL Error: ' + data.mysql_error + '</small>';
                        }
                    }
                    btn.disabled = false;
                    btn.textContent = 'Manual Debug';
                })
                .fail(function(xhr, status, error) {
                    console.log('Manual creation error:', xhr, status, error);
                    status.innerHTML = '<span style="color: #dc3232;">❌ AJAX error: ' + error + '</span>';
                    btn.disabled = false;
                    btn.textContent = 'Manual Debug';
                });
            }
            </script>
            <?php
        }

        // Check if Stripe library is available and show notice if needed
        $this->check_stripe_library_notice();
    }

    private function check_stripe_library_notice() {
        // Only show Stripe library notice on relevant admin pages
        $current_screen = get_current_screen();
        if (!$current_screen || strpos($current_screen->id, 'scholars-bowl') === false) {
            return;
        }

        // Check if Stripe is available from Formidable Forms or other sources
        $formidable_stripe_path = WP_PLUGIN_DIR . '/formidable-stripe/lib/Stripe.php';
        $stripe_available = class_exists('\Stripe\Stripe') || file_exists($formidable_stripe_path);

        // Check if we have global Stripe keys configured
        $has_global_keys = !empty(get_option('sbr_stripe_publishable_key')) && !empty(get_option('sbr_stripe_secret_key'));

        if (!$stripe_available && $has_global_keys) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><strong>Scholars' Bowl Registration:</strong> Stripe keys are configured but the Stripe PHP library is not available.
                For payment processing to work, install the Stripe library via <code>composer require stripe/stripe-php</code> or activate the Formidable Forms Stripe addon.</p>
            </div>
            <?php
        } elseif (!$has_global_keys) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>Scholars' Bowl Registration:</strong> To enable online payment processing,
                <a href="<?php echo admin_url('admin.php?page=scholars-bowl-settings'); ?>">configure your Stripe keys in Settings</a>.</p>
            </div>
            <?php
        }
    }

    public function ajax_setup_tables() {
        check_ajax_referer('sbr_setup', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            error_log('SBR: Starting AJAX table setup process');

            // Create database tables with error checking
            $table_creation_result = $this->create_database_table();

            if (!$table_creation_result) {
                error_log('SBR: Table creation failed in ajax_setup_tables');
                wp_send_json_error('Database table creation failed. Check error logs for details.');
                return;
            }

            // Verify tables exist before proceeding
            global $wpdb;
            $events_table = $wpdb->prefix . 'scholars_bowl_events';
            $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

            $events_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
            $registrations_exists = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;

            if (!$events_exists || !$registrations_exists) {
                error_log('SBR: Tables do not exist after creation attempt');
                wp_send_json_error('Table verification failed. Events table exists: ' . ($events_exists ? 'YES' : 'NO') . ', Registrations table exists: ' . ($registrations_exists ? 'YES' : 'NO'));
                return;
            }

            // Set default options if not set
            $this->set_default_options();

            // Create default event
            $this->create_default_event();

            // Force clear the setup flag immediately
            delete_option('sbr_needs_setup');

            // Also clear any cached table checks
            wp_cache_delete('sbr_table_check');

            // Re-run the check to ensure it's clear
            $this->check_setup_needed();

            error_log('SBR: AJAX table setup completed successfully');
            wp_send_json_success('Database setup completed successfully! Tables created: ' . $events_table . ', ' . $registrations_table . '. You can now manage multiple events.');

        } catch (Exception $e) {
            error_log('SBR Setup Error: ' . $e->getMessage());
            wp_send_json_error('Setup failed: ' . $e->getMessage());
        }
    }

    public function ajax_manual_create_tables() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        error_log('SBR: Manual table creation triggered');

        // Force table creation
        $result = $this->create_database_table();

        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

        $events_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
        $registrations_exists = $wpdb->get_var("SHOW TABLES LIKE '$registrations_table'") == $registrations_table;

        $response_data = array(
            'table_creation_result' => $result,
            'events_table_exists' => $events_exists,
            'registrations_table_exists' => $registrations_exists,
            'events_table_name' => $events_table,
            'registrations_table_name' => $registrations_table,
            'mysql_error' => $wpdb->last_error
        );

        if ($events_exists && $registrations_exists) {
            delete_option('sbr_needs_setup');
            wp_send_json_success($response_data);
        } else {
            wp_send_json_error($response_data);
        }
    }

    public function send_status_change_notification($registration_id, $old_status, $new_status, $notes = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $registration_id
        ));

        if (!$registration) {
            return false;
        }

        // Get event details
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $events_table WHERE id = %d",
            $registration->event_id
        ));

        if (!$event) {
            return false;
        }

        $to = $registration->contact_email;
        $subject = sprintf('[%s] Registration Status Update', $event->event_name);

        $message = sprintf(
            "Dear %s,\n\n" .
            "Your registration status for %s has been updated.\n\n" .
            "Registration Details:\n" .
            "- School: %s\n" .
            "- Event: %s\n" .
            "- Date: %s\n" .
            "- Previous Status: %s\n" .
            "- New Status: %s\n\n",
            $registration->contact_name,
            $event->event_name,
            $registration->school_name,
            $event->event_name,
            date('F j, Y', strtotime($event->event_date)),
            ucfirst($old_status ?: 'pending'),
            ucfirst($new_status)
        );

        if (!empty($notes)) {
            $message .= "Notes: " . $notes . "\n\n";
        }

        // Add status-specific information
        switch ($new_status) {
            case 'confirmed':
                $message .= "Your registration has been confirmed! Please make sure you have completed all payment requirements.\n\n";
                if ($registration->balance_due > 0) {
                    $message .= sprintf("Outstanding Balance: $%.2f\n", $registration->balance_due);
                    $message .= "Please send your remaining payment to:\n" . get_option('sbr_payment_address', '') . "\n\n";
                }
                break;
            case 'cancelled':
                $message .= "Your registration has been cancelled. If you have any questions, please contact the event organizer.\n\n";
                break;
            case 'waitlisted':
                $message .= "Your registration has been placed on a waiting list. We will notify you if a spot becomes available.\n\n";
                break;
            case 'incomplete':
                $message .= "Your registration is incomplete. Please contact the event organizer to resolve any missing information or payments.\n\n";
                break;
        }

        $message .= sprintf(
            "Event Contact: %s\n" .
            "Email: %s\n" .
            "Phone: %s\n\n" .
            "Thank you,\n" .
            "%s",
            $event->organizer_name,
            $event->organizer_email,
            $event->organizer_phone,
            $event->organizer_name
        );

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $event->organizer_name . ' <' . $event->organizer_email . '>',
            'Reply-To: ' . $event->organizer_email
        );

        return wp_mail($to, $subject, $message, $headers);
    }

    public function send_payment_confirmation($registration_id, $amount_paid, $notes = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $registration_id
        ));

        if (!$registration) {
            return false;
        }

        // Get event details
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $events_table WHERE id = %d",
            $registration->event_id
        ));

        if (!$event) {
            return false;
        }

        $to = $registration->contact_email;
        $subject = sprintf('[%s] Payment Received', $event->event_name);

        $message = sprintf(
            "Dear %s,\n\n" .
            "We have received your payment for %s.\n\n" .
            "Payment Details:\n" .
            "- School: %s\n" .
            "- Event: %s\n" .
            "- Amount Received: $%.2f\n" .
            "- Total Amount Due: $%.2f\n" .
            "- Remaining Balance: $%.2f\n\n",
            $registration->contact_name,
            $event->event_name,
            $registration->school_name,
            $event->event_name,
            $amount_paid,
            $registration->total_amount,
            max(0, $registration->balance_due ?: 0)
        );

        if (!empty($notes)) {
            $message .= "Payment Notes: " . $notes . "\n\n";
        }

        if (($registration->balance_due ?: 0) > 0) {
            $message .= sprintf(
                "You still have an outstanding balance of $%.2f. Please send your remaining payment to:\n\n%s\n\n",
                $registration->balance_due,
                get_option('sbr_payment_address', '')
            );
        } else {
            $message .= "Your registration is now fully paid. Thank you!\n\n";
        }

        $message .= sprintf(
            "Event Contact: %s\n" .
            "Email: %s\n" .
            "Phone: %s\n\n" .
            "Thank you,\n" .
            "%s",
            $event->organizer_name,
            $event->organizer_email,
            $event->organizer_phone,
            $event->organizer_name
        );

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $event->organizer_name . ' <' . $event->organizer_email . '>',
            'Reply-To: ' . $event->organizer_email
        );

        return wp_mail($to, $subject, $message, $headers);
    }

    public function generate_payment_completion_token($registration_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';

        // Generate a secure token
        $token = wp_generate_password(32, false);

        // Update the registration with the token and expiration (24 hours)
        $expiration = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $result = $wpdb->update(
            $table_name,
            [
                'payment_completion_token' => $token,
                'token_expiration' => $expiration
            ],
            ['id' => $registration_id],
            ['%s', '%s'],
            ['%d']
        );

        return $result !== false ? $token : false;
    }

    public function send_payment_completion_link($registration_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $registration_id
        ));

        if (!$registration || ($registration->balance_due ?: 0) <= 0) {
            return false;
        }

        // Generate payment completion token
        $token = $this->generate_payment_completion_token($registration_id);
        if (!$token) {
            return false;
        }

        // Get event details
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $events_table WHERE id = %d",
            $registration->event_id
        ));

        if (!$event) {
            return false;
        }

        $completion_url = home_url('/?sbr_complete_payment=' . $token);

        $to = $registration->contact_email;
        $subject = sprintf('[%s] Complete Your Payment', $event->event_name);

        $message = sprintf(
            "Dear %s,\n\n" .
            "You have an outstanding balance for your registration to %s.\n\n" .
            "Registration Details:\n" .
            "- School: %s\n" .
            "- Event: %s\n" .
            "- Date: %s\n" .
            "- Outstanding Balance: $%.2f\n\n" .
            "To complete your payment online, please click the secure link below:\n\n" .
            "%s\n\n" .
            "This link will expire in 24 hours for security reasons.\n\n" .
            "Alternatively, you can send a check or cash payment to:\n\n%s\n\n" .
            "Event Contact: %s\n" .
            "Email: %s\n" .
            "Phone: %s\n\n" .
            "Thank you,\n" .
            "%s",
            $registration->contact_name,
            $event->event_name,
            $registration->school_name,
            $event->event_name,
            date('F j, Y', strtotime($event->event_date)),
            $registration->balance_due,
            $completion_url,
            get_option('sbr_payment_address', ''),
            $event->organizer_name,
            $event->organizer_email,
            $event->organizer_phone,
            $event->organizer_name
        );

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $event->organizer_name . ' <' . $event->organizer_email . '>',
            'Reply-To: ' . $event->organizer_email
        );

        return wp_mail($to, $subject, $message, $headers);
    }

    public function handle_payment_completion_request() {
        if (!isset($_GET['sbr_complete_payment'])) {
            return;
        }

        $old_token = sanitize_text_field($_GET['sbr_complete_payment']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';

        // Find registration by old token
        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE payment_completion_token = %s",
            $old_token
        ));

        if (!$registration) {
            wp_die('Invalid payment link. Please contact the event organizer for assistance.');
        }

        // Check if already paid
        if (($registration->balance_due ?: 0) <= 0) {
            wp_die('This registration is already paid in full. Thank you!');
        }

        // Generate new token and redirect to new system
        $new_token = $this->generate_payment_token($registration->id, $registration->balance_due);
        
        if (!$new_token) {
            wp_die('Unable to process payment link. Please contact the event organizer for assistance.');
        }

        // Redirect to new payment completion page
        $completion_page_url = home_url('/complete-payment/');
        $redirect_url = add_query_arg('token', $new_token, $completion_page_url);
        
        wp_redirect($redirect_url);
        exit;
    }

    private function display_payment_completion_form($registration, $event, $token) {
        get_header();
        ?>
        <div class="scholars-bowl-payment-completion">
            <div class="payment-container">
                <h1>Complete Your Payment</h1>
                <h2><?php echo esc_html($event->event_name); ?></h2>

                <div class="registration-summary">
                    <h3>Registration Summary</h3>
                    <p><strong>School:</strong> <?php echo esc_html($registration->school_name); ?></p>
                    <p><strong>Contact:</strong> <?php echo esc_html($registration->contact_name); ?></p>
                    <p><strong>Event Date:</strong> <?php echo date('F j, Y', strtotime($event->event_date)); ?></p>
                    <p><strong>Total Amount:</strong> $<?php echo number_format($registration->total_amount, 2); ?></p>
                    <p><strong>Amount Paid:</strong> $<?php echo number_format($registration->amount_paid ?: 0, 2); ?></p>
                    <p><strong>Outstanding Balance:</strong> $<?php echo number_format($registration->balance_due, 2); ?></p>
                </div>

                <div class="payment-options">
                    <h3>Payment Options</h3>

                    <div class="payment-option">
                        <h4>Option 1: Credit Card Payment</h4>
                        <div id="stripe-payment-form" style="display: none;">
                            <form id="payment-form">
                                <div id="card-element">
                                    <!-- Stripe Elements will create form elements here -->
                                </div>
                                <div id="card-errors" role="alert"></div>
                                <button id="submit-payment" type="submit">
                                    Pay $<?php echo number_format($registration->balance_due, 2); ?>
                                </button>
                            </form>
                        </div>
                        <button id="show-stripe-form" class="btn btn-primary">Pay with Credit Card</button>
                    </div>

                    <div class="payment-option">
                        <h4>Option 2: Check or Cash Payment</h4>
                        <p>Send your payment to:</p>
                        <div class="payment-address">
                            <?php echo nl2br(esc_html(get_option('sbr_payment_address', ''))); ?>
                        </div>
                        <p><em>Please include your registration ID #<?php echo $registration->id; ?> with your payment.</em></p>
                    </div>
                </div>

                <div class="contact-info">
                    <h3>Questions?</h3>
                    <p>Contact the event organizer:</p>
                    <p>
                        <strong><?php echo esc_html($event->organizer_name); ?></strong><br>
                        Email: <a href="mailto:<?php echo esc_attr($event->organizer_email); ?>"><?php echo esc_html($event->organizer_email); ?></a><br>
                        Phone: <?php echo esc_html($event->organizer_phone); ?>
                    </p>
                </div>
            </div>
        </div>

        <style>
        .scholars-bowl-payment-completion {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            font-family: Arial, sans-serif;
        }

        .payment-container {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .registration-summary {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }

        .payment-options {
            margin: 30px 0;
        }

        .payment-option {
            background: #f5f5f5;
            padding: 20px;
            margin: 15px 0;
            border-radius: 5px;
            border-left: 4px solid #0073aa;
        }

        .payment-address {
            background: #fff;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-family: monospace;
            white-space: pre-line;
        }

        .btn {
            background: #0073aa;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background: #005a87;
        }

        #payment-form {
            margin-top: 20px;
            padding: 20px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        #card-element {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        #card-errors {
            color: #dc3232;
            margin: 10px 0;
        }

        #submit-payment {
            background: #46b450;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }

        #submit-payment:hover {
            background: #2e7e32;
        }

        .contact-info {
            background: #e6f3ff;
            padding: 20px;
            border-radius: 5px;
            margin-top: 30px;
        }
        </style>

        <script>
        document.getElementById('show-stripe-form').addEventListener('click', function() {
            document.getElementById('stripe-payment-form').style.display = 'block';
            this.style.display = 'none';

            // Initialize Stripe here when needed
            // This would require the Stripe JavaScript library and proper key handling
        });
        </script>

        <?php
        get_footer();
    }

    public function check_and_add_missing_columns() {
        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
        if (!$table_exists) {
            error_log('SBR: Events table does not exist, creating it...');
            $this->create_database_table();
            return;
        }

        // Get current columns
        $columns = $wpdb->get_col("DESCRIBE $events_table");
        $required_columns = [
            'min_students',
            'organization_name',
            'stripe_mode',
            'stripe_test_publishable_key',
            'stripe_test_secret_key',
            'stripe_live_publishable_key',
            'stripe_live_secret_key'
        ];

        $missing_columns = array_diff($required_columns, $columns);

        if (!empty($missing_columns)) {
            error_log('SBR: Missing columns detected: ' . implode(', ', $missing_columns));

            // Add missing columns one by one
            foreach ($missing_columns as $column) {
                $sql = '';
                switch ($column) {
                    case 'min_students':
                        $sql = "ALTER TABLE $events_table ADD COLUMN min_students int(2) NOT NULL DEFAULT 1";
                        break;
                    case 'organization_name':
                        $sql = "ALTER TABLE $events_table ADD COLUMN organization_name varchar(255)";
                        break;
                    case 'stripe_mode':
                        $sql = "ALTER TABLE $events_table ADD COLUMN stripe_mode varchar(10) DEFAULT 'test'";
                        break;
                    case 'stripe_test_publishable_key':
                        $sql = "ALTER TABLE $events_table ADD COLUMN stripe_test_publishable_key varchar(255)";
                        break;
                    case 'stripe_test_secret_key':
                        $sql = "ALTER TABLE $events_table ADD COLUMN stripe_test_secret_key varchar(255)";
                        break;
                    case 'stripe_live_publishable_key':
                        $sql = "ALTER TABLE $events_table ADD COLUMN stripe_live_publishable_key varchar(255)";
                        break;
                    case 'stripe_live_secret_key':
                        $sql = "ALTER TABLE $events_table ADD COLUMN stripe_live_secret_key varchar(255)";
                        break;
                }

                if ($sql) {
                    $result = $wpdb->query($sql);
                    if ($result === false) {
                        error_log("SBR: Failed to add column $column: " . $wpdb->last_error);
                    } else {
                        error_log("SBR: Successfully added column $column");
                    }
                }
            }
        }
    }

    public function add_payment_deadline_column() {
        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        
        // Check if column exists
        $column_exists = $wpdb->get_results(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '{$events_table}' 
            AND COLUMN_NAME = 'payment_deadline'"
        );
        
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE {$events_table} 
                ADD COLUMN payment_deadline date DEFAULT NULL 
                AFTER registration_deadline"
            );
            error_log('SBR: Added payment_deadline column to events table');
        }
    }



    public function ensure_email_columns_exist() {
        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table;
        if (!$table_exists) {
            error_log('SBR: Events table does not exist when checking for email columns');
            return;
        }

        // Get current columns
        $columns = $wpdb->get_col("DESCRIBE $events_table");
        $email_columns = ['from_email', 'reply_to_email', 'from_name'];

        $missing_columns = array_diff($email_columns, $columns);

        if (!empty($missing_columns)) {
            error_log('SBR: Missing email columns detected: ' . implode(', ', $missing_columns));

            // Add missing email columns
            foreach ($missing_columns as $column) {
                $sql = '';
                switch ($column) {
                    case 'from_email':
                        $sql = "ALTER TABLE $events_table ADD COLUMN from_email varchar(255)";
                        break;
                    case 'reply_to_email':
                        $sql = "ALTER TABLE $events_table ADD COLUMN reply_to_email varchar(255)";
                        break;
                    case 'from_name':
                        $sql = "ALTER TABLE $events_table ADD COLUMN from_name varchar(255)";
                        break;
                }

                if ($sql) {
                    $result = $wpdb->query($sql);
                    if ($result === false) {
                        error_log("SBR: Failed to add email column $column: " . $wpdb->last_error);
                    } else {
                        error_log("SBR: Successfully added email column $column");
                    }
                }
            }
        }
    }

    /**
     * Get the active Stripe publishable key based on current mode
     */
    public function get_active_stripe_publishable_key() {
        $mode = get_option('sbr_stripe_mode', 'test');

        if ($mode === 'live') {
            $key = get_option('sbr_stripe_live_publishable_key');
            if (!empty($key)) {
                return $key;
            }
        } else {
            $key = get_option('sbr_stripe_test_publishable_key');
            if (!empty($key)) {
                return $key;
            }
        }

        // Fallback to legacy key
        return get_option('sbr_stripe_publishable_key');
    }

    /**
     * Get the active Stripe secret key based on current mode
     */
    public function get_active_stripe_secret_key() {
        $mode = get_option('sbr_stripe_mode', 'test');

        if ($mode === 'live') {
            $key = get_option('sbr_stripe_live_secret_key');
            if (!empty($key)) {
                return $key;
            }
        } else {
            $key = get_option('sbr_stripe_test_secret_key');
            if (!empty($key)) {
                return $key;
            }
        }

        // Fallback to legacy key
        return get_option('sbr_stripe_secret_key');
    }

    // ==========================================================================
    // ANTI-SPAM PROTECTION METHODS
    // ==========================================================================

    /**
     * Check if IP is rate limited
     */
    public function is_rate_limited($ip_address) {
        global $wpdb;
        $spam_log_table = $wpdb->prefix . 'scholars_bowl_spam_log';

        // Check submissions in last 5 minutes
        $recent_submissions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $spam_log_table
             WHERE ip_address = %s
             AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
            $ip_address
        ));

        return $recent_submissions >= 3; // Max 3 submissions per 5 minutes
    }

    /**
     * Log spam attempt
     */
    public function log_spam_attempt($ip_address, $attempt_type, $form_data = '', $spam_score = 0, $blocked = false, $reason = '') {
        global $wpdb;
        $spam_log_table = $wpdb->prefix . 'scholars_bowl_spam_log';

        $wpdb->insert(
            $spam_log_table,
            array(
                'ip_address' => $ip_address,
                'attempt_type' => $attempt_type,
                'form_data' => is_array($form_data) ? json_encode($form_data) : $form_data,
                'spam_score' => $spam_score,
                'blocked' => $blocked ? 1 : 0,
                'reason' => $reason,
                'attempted_at' => current_time('mysql'),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                'referrer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : ''
            ),
            array('%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Calculate spam score for submission
     */
    public function calculate_spam_score($form_data) {
        $score = 0;

        // Check for honeypot field
        if (!empty($form_data['website'])) {
            $score += 100; // Instant spam if honeypot filled
        }

        // Check for suspicious patterns
        $suspicious_patterns = array(
            'http://', 'https://', 'www.', '.com', '.org', '.net',
            'viagra', 'cialis', 'pharmacy', 'casino', 'poker',
            'loan', 'credit', 'debt', 'mortgage', 'insurance'
        );

        $text_fields = array('school_name', 'contact_name', 'contact_email');
        foreach ($text_fields as $field) {
            if (isset($form_data[$field])) {
                $field_value = strtolower($form_data[$field]);
                foreach ($suspicious_patterns as $pattern) {
                    if (strpos($field_value, $pattern) !== false) {
                        $score += 25;
                    }
                }
            }
        }

        // Check for excessive length
        if (isset($form_data['school_name']) && strlen($form_data['school_name']) > 100) {
            $score += 15;
        }

        // Check for non-ASCII characters (might indicate bot)
        foreach ($text_fields as $field) {
            if (isset($form_data[$field]) && !mb_check_encoding($form_data[$field], 'ASCII')) {
                $score += 10;
            }
        }

        return $score;
    }

    /**
     * Validate form submission for spam
     */
    public function validate_submission_for_spam($form_data) {
        $ip_address = $this->get_client_ip();

        // Check rate limiting
        if ($this->is_rate_limited($ip_address)) {
            $this->log_spam_attempt($ip_address, 'rate_limit', $form_data, 100, true, 'Too many submissions');
            return array('blocked' => true, 'reason' => 'Too many submissions. Please wait before trying again.');
        }

        // Calculate spam score
        $spam_score = $this->calculate_spam_score($form_data);

        // Block if spam score is too high
        if ($spam_score >= 50) {
            $this->log_spam_attempt($ip_address, 'spam_score', $form_data, $spam_score, true, 'High spam score');
            return array('blocked' => true, 'reason' => 'Submission appears to be spam.');
        }

        // Log valid submission
        $this->log_spam_attempt($ip_address, 'valid_submission', $form_data, $spam_score, false, '');

        return array('blocked' => false, 'spam_score' => $spam_score);
    }

    /**
     * Get client IP address
     */
    public function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    // ==========================================================================
    // PAYMENT COMPLETION SYSTEM
    // ==========================================================================

    /**
     * Generate secure payment completion token
     */
    public function generate_payment_token($registration_id, $amount_due, $expires_days = 60) {
        global $wpdb;
        $payment_tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';

        // Generate unique token
        $token = bin2hex(random_bytes(32));

        // Set expiration date
        $expires_at = date('Y-m-d H:i:s', strtotime("+$expires_days days"));

        // Insert token
        $result = $wpdb->insert(
            $payment_tokens_table,
            array(
                'registration_id' => $registration_id,
                'token' => $token,
                'token_type' => 'payment_completion',
                'amount_due' => $amount_due,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql'),
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                'status' => 'active'
            ),
            array('%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s')
        );

        return $result ? $token : false;
    }

    /**
     * Validate payment completion token
     */

    /**
     * Generate payment token with expiration based on event payment deadline
     * @param int $registration_id
     * @param int $event_id
     * @param float $amount_due
     * @return string|false Token string or false on failure
     */
    public function generate_payment_token_for_event($registration_id, $event_id, $amount_due) {
        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';
        
        // Get event payment deadline
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT payment_deadline FROM $events_table WHERE id = %d",
            $event_id
        ));
        
        if (!$event) {
            error_log("SBR: Event not found when generating payment token for registration $registration_id");
            // Fallback to 60 days
            return $this->generate_payment_token($registration_id, $amount_due, 60);
        }
        
        // If payment deadline is set, calculate days until deadline
        if (!empty($event->payment_deadline)) {
            $deadline = new DateTime($event->payment_deadline . ' 23:59:59');
            $now = new DateTime();
            $interval = $now->diff($deadline);
            $days_until_deadline = $interval->days;
            
            // If deadline has passed, set token to expire in 7 days (grace period)
            if ($interval->invert == 1) {
                $deadline = new DateTime('+7 days');
                error_log("SBR: Payment deadline has passed for event $event_id, setting token to expire in 7 days as grace period");
            }
            
            // Generate token using custom expiration
            $payment_tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';
            $token = bin2hex(random_bytes(32));
            $expires_at = $deadline->format('Y-m-d H:i:s');
            
            $result = $wpdb->insert(
                $payment_tokens_table,
                array(
                    'registration_id' => $registration_id,
                    'token' => $token,
                    'token_type' => 'payment_completion',
                    'amount_due' => $amount_due,
                    'expires_at' => $expires_at,
                    'created_at' => current_time('mysql'),
                    'ip_address' => $this->get_client_ip(),
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                    'status' => 'active'
                ),
                array('%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result) {
                error_log("SBR: Generated payment token for registration $registration_id, expires at $expires_at");
                return $token;
            } else {
                error_log("SBR: Failed to generate payment token for registration $registration_id: " . $wpdb->last_error);
                return false;
            }
        }
        
        // No payment deadline set, use default 60 days
        error_log("SBR: No payment deadline set for event $event_id, using default 60 days");
        return $this->generate_payment_token($registration_id, $amount_due, 60);
    }

    public function validate_payment_token($token) {
        global $wpdb;
        $payment_tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';

        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT pt.*, r.school_name, r.contact_name, r.contact_email, r.balance_due
             FROM $payment_tokens_table pt
             JOIN {$wpdb->prefix}scholars_bowl_registrations r ON pt.registration_id = r.id
             WHERE pt.token = %s
             AND pt.status = 'active'
             AND pt.expires_at > NOW()
             AND pt.used_at IS NULL",
            $token
        ));

        return $token_data;
    }

    /**
     * Mark token as used
     */
    public function mark_token_used($token) {
        global $wpdb;
        $payment_tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';

        return $wpdb->update(
            $payment_tokens_table,
            array('used_at' => current_time('mysql'), 'status' => 'used'),
            array('token' => $token),
            array('%s', '%s'),
            array('%s')
        );
    }

    /**
     * Payment completion shortcode
     */
    public function payment_completion_shortcode($atts) {
        $atts = shortcode_atts(array(
            'token' => isset($_GET['token']) ? sanitize_text_field($_GET['token']) : ''
        ), $atts);

        if (empty($atts['token'])) {
            return '<div class="sbr-error">Invalid payment link. Please use the link from your email.</div>';
        }

        $token_data = $this->validate_payment_token($atts['token']);
        if (!$token_data) {
            return '<div class="sbr-error">This payment link has expired or is invalid.</div>';
        }

        // Enqueue scripts for payment processing
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
        wp_enqueue_script('sbr-payment-completion', SBR_PLUGIN_URL . 'js/payment-completion.js', array('jquery', 'stripe-js'), SBR_VERSION, true);

        // Localize script data
        wp_localize_script('sbr-payment-completion', 'sbrPaymentData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sbr_payment_completion'),
            'token' => $atts['token'],
            'stripe_key' => $this->get_active_stripe_publishable_key(),
            'amount' => $token_data->amount_due
        ));

        ob_start();
        include SBR_PLUGIN_PATH . 'templates/payment-completion.php';
        return ob_get_clean();
    }

    /**
     * Handle payment completion
     */
    public function handle_payment_completion() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'sbr_payment_completion')) {
            wp_die(json_encode(array('success' => false, 'message' => 'Security check failed')));
        }

        $token = sanitize_text_field($_POST['token']);
        $payment_method_id = sanitize_text_field($_POST['payment_method_id']);

        // Validate token
        $token_data = $this->validate_payment_token($token);
        if (!$token_data) {
            wp_die(json_encode(array('success' => false, 'message' => 'Invalid or expired payment link')));
        }

        // Process payment with Stripe
        $payment_result = $this->process_completion_payment($token_data, $payment_method_id);

        if ($payment_result['success']) {
            // Mark token as used
            $this->mark_token_used($token);

            // Update registration
            $this->update_registration_payment_status($token_data->registration_id, 'completed');

            // Send completion email
            $this->send_payment_completion_email($token_data->registration_id);
        }

        wp_die(json_encode($payment_result));
    }

    /**
     * Process completion payment via Stripe
     */
    public function process_completion_payment($token_data, $payment_method_id) {
        require_once SBR_PLUGIN_PATH . 'lib/stripe/init.php';

        $secret_key = $this->get_active_stripe_secret_key();
        if (!$secret_key) {
            return array('success' => false, 'message' => 'Payment processing not configured');
        }

        \Stripe\Stripe::setApiKey($secret_key);

        try {
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => $token_data->amount_due * 100, // Convert to cents
                'currency' => 'usd',
                'payment_method' => $payment_method_id,
                'confirm' => true,
                'return_url' => add_query_arg('payment_completed', '1', wp_get_referer()),
                'metadata' => array(
                    'registration_id' => $token_data->registration_id,
                    'token' => $token_data->token,
                    'type' => 'balance_payment'
                )
            ]);

            if ($payment_intent->status === 'succeeded') {
                return array(
                    'success' => true,
                    'message' => 'Payment completed successfully',
                    'payment_intent_id' => $payment_intent->id
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Payment requires additional authentication',
                    'client_secret' => $payment_intent->client_secret
                );
            }

        } catch (\Stripe\Exception\CardException $e) {
            return array('success' => false, 'message' => $e->getError()->message);
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Payment processing error');
        }
    }

    /**
     * Update registration payment status
     */
    public function update_registration_payment_status($registration_id, $status) {
        global $wpdb;
        $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

        $update_data = array(
            'payment_status' => $status,
            'last_updated' => current_time('mysql')
        );

        if ($status === 'completed') {
            $update_data['balance_due'] = 0.00;
        }

        return $wpdb->update(
            $registrations_table,
            $update_data,
            array('id' => $registration_id),
            array('%s', '%s', '%f'),
            array('%d')
        );
    }

    /**
     * Send payment completion email
     */
    public function send_payment_completion_email($registration_id) {
        global $wpdb;
        $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, e.event_name, e.event_date, e.from_email, e.reply_to_email, e.from_name
             FROM $registrations_table r
             JOIN {$wpdb->prefix}scholars_bowl_events e ON r.event_id = e.id
             WHERE r.id = %d",
            $registration_id
        ));

        if (!$registration) return false;

        $subject = 'Payment Completed - ' . $registration->event_name . ' Registration';

        $message = "Dear {$registration->contact_name},\n\n";
        $message .= "Your payment for the {$registration->event_name} registration has been completed successfully.\n\n";
        $message .= "Registration Details:\n";
        $message .= "School: {$registration->school_name}\n";
        $message .= "Contact: {$registration->contact_name}\n";
        $message .= "Students: {$registration->competing_students}\n";
        $message .= "Total Amount: $" . number_format($registration->total_amount, 2) . "\n";
        $message .= "Payment Status: Completed\n\n";
        $message .= "Thank you for your registration!\n\n";
        $message .= "Event Date: " . date('F j, Y', strtotime($registration->event_date)) . "\n\n";
        $message .= "We look forward to seeing your team at the competition!";

        // Set up email headers
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        $from_email = !empty($registration->from_email) ? $registration->from_email : get_option('sbr_default_from_email', get_option('admin_email'));
        $reply_to_email = !empty($registration->reply_to_email) ? $registration->reply_to_email : get_option('sbr_default_reply_to_email', $from_email);
        $from_name = !empty($registration->from_name) ? $registration->from_name : get_option('sbr_default_from_name', get_bloginfo('name'));

        if (!empty($from_email) && !empty($from_name)) {
            $headers[] = "From: {$from_name} <{$from_email}>";
        }
        if (!empty($reply_to_email)) {
            $headers[] = "Reply-To: {$reply_to_email}";
        }

        return wp_mail($registration->contact_email, $subject, $message, $headers);
    }

    /**
     * Send payment reminder email
     */
    public function send_payment_reminder() {
        // Verify nonce for admin action
        if (!wp_verify_nonce($_POST['nonce'], 'sbr_admin_action')) {
            wp_die(json_encode(array('success' => false, 'message' => 'Security check failed')));
        }

        if (!current_user_can('manage_options')) {
            wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
        }

        $registration_id = intval($_POST['registration_id']);
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'view';

        // Get registration details
        global $wpdb;
        $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';

        $registration = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, e.event_name, e.event_date, e.from_email, e.reply_to_email, e.from_name
             FROM $registrations_table r
             JOIN {$wpdb->prefix}scholars_bowl_events e ON r.event_id = e.id
             WHERE r.id = %d AND r.balance_due > 0",
            $registration_id
        ));

        if (!$registration) {
            wp_die(json_encode(array('success' => false, 'message' => 'Registration not found or balance is zero')));
        }

        // Generate payment token
        $token = $this->generate_payment_token($registration_id, $registration->balance_due);
        if (!$token) {
            wp_die(json_encode(array('success' => false, 'message' => 'Failed to generate payment link')));
        }

        // Create payment completion URL
        $payment_url = add_query_arg('token', $token, home_url('/complete-payment/'));

        // Send reminder email
        $subject = 'Payment Reminder - ' . $registration->event_name . ' Registration';

        $message = "Dear {$registration->contact_name},\n\n";
        $message .= "This is a reminder that you have an outstanding balance for your {$registration->event_name} registration.\n\n";
        $message .= "Registration Details:\n";
        $message .= "School: {$registration->school_name}\n";
        $message .= "Total Amount: $" . number_format($registration->total_amount, 2) . "\n";
        $message .= "Amount Paid: $" . number_format($registration->amount_paid, 2) . "\n";
        $message .= "Balance Due: $" . number_format($registration->balance_due, 2) . "\n\n";
        $message .= "To complete your payment online, please click the link below:\n";
        $message .= $payment_url . "\n\n";
        $message .= "This payment link will expire in 60 days.\n\n";
        $message .= "Event Date: " . date('F j, Y', strtotime($registration->event_date)) . "\n\n";
        $message .= "Please complete your payment to secure your registration.";

        // Set up email headers
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        $from_email = !empty($registration->from_email) ? $registration->from_email : get_option('sbr_default_from_email', get_option('admin_email'));
        $reply_to_email = !empty($registration->reply_to_email) ? $registration->reply_to_email : get_option('sbr_default_reply_to_email', $from_email);
        $from_name = !empty($registration->from_name) ? $registration->from_name : get_option('sbr_default_from_name', get_bloginfo('name'));

        if (!empty($from_email) && !empty($from_name)) {
            $headers[] = "From: {$from_name} <{$from_email}>";
        }
        if (!empty($reply_to_email)) {
            $headers[] = "Reply-To: {$reply_to_email}";
        }

        $result = wp_mail($registration->contact_email, $subject, $message, $headers);

        wp_die(json_encode(array(
            'success' => $result,
            'message' => $result ? 'Payment reminder sent successfully' : 'Failed to send email'
        )));
    }

    /**
     * Process registration submission
     */
    public function process_registration_submission($form_data) {
        global $wpdb;

        try {
            // Sanitize and validate input data
            $event_id = intval($form_data['event_id']);
            $school_name = sanitize_text_field($form_data['school_name']);
            $contact_name = sanitize_text_field($form_data['contact_name']);
            $contact_email = sanitize_email($form_data['contact_email']);
            $contact_phone = sanitize_text_field($form_data['contact_phone']);
            $competing_students = intval($form_data['competing_students']);
            $additional_attendees = intval($form_data['additional_attendees'] ?? 0);
            $coaches = intval($form_data['coaches'] ?? 0);
            $grade_level = sanitize_text_field($form_data['grade_level']);
            $payment_option = sanitize_text_field($form_data['payment_option']);
            $payment_method = sanitize_text_field($form_data['payment_method']);

            // Get event details
            $events_table = $wpdb->prefix . 'scholars_bowl_events';
            $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events_table WHERE id = %d", $event_id));

            if (!$event) {
                return array('success' => false, 'message' => 'Event not found');
            }

            // Calculate pricing
            $current_date = new DateTime();
            $early_bird_deadline = new DateTime($event->early_bird_deadline);
            $is_early_bird = $current_date <= $early_bird_deadline;
            $per_student_rate = $is_early_bird ? $event->early_bird_rate : $event->standard_rate;

            // Calculate total amount
            $students_cost = $competing_students * $per_student_rate;
            $additional_cost = $additional_attendees * $per_student_rate;
            $extra_coaches = max(0, $coaches - $event->free_coaches);
            $coaches_cost = $extra_coaches * $per_student_rate;
            $total_amount = $students_cost + $additional_cost + $coaches_cost;

            // Determine session time
            $morning_classifications = explode(',', $event->morning_classifications);
            $session_time = in_array($grade_level, $morning_classifications) ?
                $event->morning_session_time : $event->afternoon_session_time;

            // Calculate payment amounts
            if ($payment_option === 'deposit') {
                $amount_paid = min($event->deposit_amount, $total_amount);
                $balance_due = $total_amount - $amount_paid;
            } else {
                $amount_paid = $total_amount;
                $balance_due = 0.00;
            }

            // Insert registration
            $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';
            $registration_data = array(
                'event_id' => $event_id,
                'school_name' => $school_name,
                'contact_name' => $contact_name,
                'contact_email' => $contact_email,
                'contact_phone' => $contact_phone,
                'dietary_restrictions' => $dietary_restrictions,
                'competing_students' => $competing_students,
                'additional_attendees' => $additional_attendees,
                'coaches' => $coaches,
                'school_classification' => $grade_level,
                'session_time' => $session_time,
                'payment_option' => $payment_option,
                'payment_method' => $payment_method,
                'total_amount' => $total_amount,
                'deposit_amount' => $event->deposit_amount,
                'amount_paid' => $amount_paid,
                'balance_due' => $balance_due,
                'payment_status' => $payment_method === 'stripe' ? 'pending' : 'manual',
                'registration_status' => 'confirmed',
                'registration_date' => current_time('mysql'),
                'last_updated' => current_time('mysql')
            );

            $result = $wpdb->insert($registrations_table, $registration_data);

            if ($result === false) {
                return array('success' => false, 'message' => 'Database error: ' . $wpdb->last_error);
            }

            $registration_id = $wpdb->insert_id;

            // Generate payment completion token if there's a balance
            if ($balance_due > 0) {
                $token = $this->generate_payment_token($registration_id, $balance_due);
                if ($token) {
                    $wpdb->update(
                        $registrations_table,
                        array('payment_completion_token' => $token),
                        array('id' => $registration_id),
                        array('%s'),
                        array('%d')
                    );
                }
            }

            // Send confirmation email
            $this->send_registration_confirmation_email($registration_id, $registration_data);

            // Handle payment processing if needed
            if ($payment_method === 'stripe') {
                return $this->process_stripe_payment_for_registration($registration_id, $amount_paid);
            }

            return array(
                'success' => true,
                'message' => 'Registration submitted successfully',
                'registration_id' => $registration_id,
                'redirect_url' => add_query_arg('registration_id', $registration_id, home_url('/registration-confirmation/'))
            );

        } catch (Exception $e) {
            error_log('SBR Registration Error: ' . $e->getMessage());
            return array('success' => false, 'message' => 'An error occurred. Please try again.');
        }
    }

    /**
     * Send registration confirmation email
     */
    public function send_registration_confirmation_email($registration_id, $registration_data) {
        global $wpdb;
        $events_table = $wpdb->prefix . 'scholars_bowl_events';

        // Get event details
        $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events_table WHERE id = %d", $registration_data['event_id']));
        if (!$event) return false;

        $subject = 'Registration Confirmation - ' . $event->event_name;

        $message = "Dear {$registration_data['contact_name']},\n\n";
        $message .= "Thank you for registering for {$event->event_name}!\n\n";
        $message .= "Registration Details:\n";
        $message .= "School: {$registration_data['school_name']}\n";
        $message .= "Contact: {$registration_data['contact_name']}\n";
        $message .= "Phone: {$registration_data['contact_phone']}\n";
        $message .= "Students: {$registration_data['competing_students']}\n";
        $message .= "Additional Attendees: {$registration_data['additional_attendees']}\n";
        $message .= "Coaches: {$registration_data['coaches']}\n";
        $message .= "Classification: {$registration_data['school_classification']}\n";
        $message .= "Session Time: {$registration_data['session_time']}\n\n";
        $message .= "Payment Information:\n";
        $message .= "Total Amount: $" . number_format($registration_data['total_amount'], 2) . "\n";
        $message .= "Amount Paid: $" . number_format($registration_data['amount_paid'], 2) . "\n";

        if ($registration_data['balance_due'] > 0) {
            $message .= "Balance Due: $" . number_format($registration_data['balance_due'], 2) . "\n";
            $message .= "\nYou will receive a separate email with a link to complete your payment.\n";
        }

        $message .= "\nEvent Details:\n";
        $message .= "Date: " . date('F j, Y', strtotime($event->event_date)) . "\n";
        $message .= "Location: {$event->event_location}\n\n";
        $message .= "Registration ID: {$registration_id}\n\n";
        $message .= "Thank you for your registration!";

        // Set up email headers
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        $from_email = !empty($event->from_email) ? $event->from_email : get_option('sbr_default_from_email', get_option('admin_email'));
        $reply_to_email = !empty($event->reply_to_email) ? $event->reply_to_email : get_option('sbr_default_reply_to_email', $from_email);
        $from_name = !empty($event->from_name) ? $event->from_name : get_option('sbr_default_from_name', get_bloginfo('name'));

        if (!empty($from_email) && !empty($from_name)) {
            $headers[] = "From: {$from_name} <{$from_email}>";
        }
        if (!empty($reply_to_email)) {
            $headers[] = "Reply-To: {$reply_to_email}";
        }

        return wp_mail($registration_data['contact_email'], $subject, $message, $headers);
    }

    /**
     * Clean up abandoned registrations that have been in processing status for over 30 minutes
     * or failed status for over 24 hours
     */
    public function cleanup_abandoned_registrations() {
        // Only run cleanup occasionally to avoid performance issues
        if (rand(1, 20) !== 1) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'scholars_bowl_registrations';

        // Mark old processing registrations as failed (over 30 minutes)
        $processing_cutoff = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name
             SET payment_status = 'failed',
                 payment_notes = CONCAT(COALESCE(payment_notes, ''), ' [Auto-failed: Processing timeout]')
             WHERE payment_status = 'processing'
             AND registration_date < %s",
            $processing_cutoff
        ));

        // Delete failed registrations older than 7 days
        $failed_cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name
             WHERE payment_status = 'failed'
             AND registration_date < %s",
            $failed_cutoff
        ));
    }

}

new ScholarsBowlRegistration();
// Add Test Mode Fixer submenu
add_action('admin_menu', 'sbr_add_test_fixer_menu', 100);
function sbr_add_test_fixer_menu() {
    add_submenu_page(
        'scholars-bowl-events',
        'Fix Test Modes',
        'Fix Test Modes',
        'manage_options',
        'sbr-fix-test-modes',
        'sbr_fix_test_modes_page'
    );
}

function sbr_fix_test_modes_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scholars_bowl_registrations';
    
    if (isset($_POST['fix_test_modes'])) {
        check_admin_referer('sbr_fix_test_modes');
        
        // Set ID 13 to LIVE
        $wpdb->update($table_name, array('is_test_mode' => 0), array('id' => 13));
        
        // Set all IDs < 18 (except 13) to TEST
        $wpdb->query("UPDATE $table_name SET is_test_mode = 1 WHERE id < 18 AND id != 13");
        
        echo '<div class="notice notice-success"><p><strong>Success!</strong> Test mode flags have been updated. <a href="' . admin_url('admin.php?page=scholars-bowl-registrations') . '">View Registrations</a></p></div>';
    }
    
    $registrations = $wpdb->get_results("SELECT id, school_name, payment_status, payment_method, is_test_mode FROM $table_name ORDER BY id");
    ?>
    <div class="wrap">
        <h1>Fix Test Mode Flags</h1>
        <p>This tool will properly mark test transactions so they can be identified and deleted.</p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>School</th>
                    <th>Payment Status</th>
                    <th>Payment Method</th>
                    <th>Current Mode</th>
                    <th>Should Be</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $reg): 
                    $should_be_test = ($reg->id < 18 && $reg->id != 13);
                    $is_correct = ($should_be_test && $reg->is_test_mode) || (!$should_be_test && !$reg->is_test_mode);
                    $highlight = $is_correct ? '' : 'background: #fff3cd;';
                ?>
                    <tr style="<?php echo $highlight; ?>">
                        <td><?php echo $reg->id; ?></td>
                        <td><?php echo esc_html($reg->school_name); ?></td>
                        <td><?php echo $reg->payment_status; ?></td>
                        <td><?php echo $reg->payment_method; ?></td>
                        <td>
                            <?php if ($reg->is_test_mode): ?>
                                <span style="background: #ff9800; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">TEST</span>
                            <?php else: ?>
                                <span style="background: #4caf50; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">LIVE</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($should_be_test): ?>
                                <strong style="color: #ff9800;">TEST</strong>
                            <?php else: ?>
                                <strong style="color: #4caf50;">LIVE</strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <form method="post" style="margin-top: 20px;">
            <?php wp_nonce_field('sbr_fix_test_modes'); ?>
            <p><strong>This will:</strong></p>
            <ul style="margin-left: 20px;">
                <li>Mark all registrations with ID &lt; 18 (except #13) as TEST</li>
                <li>Mark registration #13 as LIVE (it was a check payment)</li>
                <li>Mark all registrations with ID &ge; 18 as LIVE</li>
            </ul>
            <button type="submit" name="fix_test_modes" class="button button-primary button-large">
                Fix Test Mode Flags Now
            </button>
        </form>
    </div>
    <?php
}

// Add AJAX handler to toggle test mode for check/cash payments
add_action('wp_ajax_sbr_toggle_test_mode', 'sbr_toggle_test_mode');
function sbr_toggle_test_mode() {
    check_ajax_referer('sbr_toggle_test_mode', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'scholars_bowl_registrations';
    $registration_id = intval($_POST['registration_id']);
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'view';
    $new_mode = intval($_POST['is_test_mode']);
    
    $result = $wpdb->update(
        $table_name,
        array('is_test_mode' => $new_mode),
        array('id' => $registration_id),
        array('%d'),
        array('%d')
    );
    
    if ($result !== false) {
        wp_send_json_success(array(
            'message' => 'Test mode updated successfully',
            'is_test_mode' => $new_mode
        ));
    } else {
        wp_send_json_error('Failed to update test mode');
    }
}

// Add AJAX handler to get registration details for view popup
add_action('wp_ajax_sbr_get_registration_details', 'sbr_get_registration_details');
add_action('wp_ajax_sbr_create_registration_manual', 'sbr_create_registration_manual');
function sbr_get_registration_details() {
    check_ajax_referer('sbr_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    global $wpdb;
    $registration_id = intval($_POST['registration_id']);
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'view';
    
    $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';
    $events_table = $wpdb->prefix . 'scholars_bowl_events';
    
    $registration = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, e.event_name, e.event_date, e.event_location, e.standard_rate, e.free_coaches 
        FROM $registrations_table r 
        LEFT JOIN $events_table e ON r.event_id = e.id 
        WHERE r.id = %d",
        $registration_id
    ));
    
    if (!$registration) {
        wp_send_json_error('Registration not found');
    }
    
    // Build the HTML response
    ob_start();
    
    if ($mode === 'edit') {
        // EDIT MODE - Show edit form
        ?>
        <h2>Edit Registration #<?php echo $registration->id; ?></h2>
        
        <form id="edit-registration-form">
            <input type="hidden" name="registration_id" value="<?php echo $registration->id; ?>">
            <input type="hidden" id="event_base_price" value="<?php echo esc_attr($registration->standard_rate); ?>">
            <input type="hidden" id="event_additional_price" value="<?php echo esc_attr($registration->standard_rate); ?>">
            <input type="hidden" id="event_coach_price" value="<?php echo esc_attr($registration->standard_rate); ?>">
            <input type="hidden" id="event_free_coaches" value="<?php echo esc_attr($registration->free_coaches); ?>">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <h3>School Information</h3>
                    <p>
                        <label><strong>School Name:</strong></label><br>
                        <input type="text" id="school_name" name="school_name" value="<?php echo esc_attr($registration->school_name); ?>" style="width: 100%;" required>
                    </p>
                    <p>
                        <label><strong>Grade Level:</strong></label><br>
                        <select id="grade_level" name="grade_level" style="width: 100%;">
                            <option value="Private" <?php selected($registration->grade_level, 'Private'); ?>>Private</option>
                            <option value="1A" <?php selected($registration->grade_level, '1A'); ?>>1A</option>
                            <option value="2A" <?php selected($registration->grade_level, '2A'); ?>>2A</option>
                            <option value="3A" <?php selected($registration->grade_level, '3A'); ?>>3A</option>
                            <option value="4A" <?php selected($registration->grade_level, '4A'); ?>>4A</option>
                            <option value="5A" <?php selected($registration->grade_level, '5A'); ?>>5A</option>
                            <option value="6A" <?php selected($registration->grade_level, '6A'); ?>>6A</option>
                        </select>
                    </p>
                    <p>
                        <label><strong>Session:</strong></label><br>
                        <select id="session_time" name="session_time" style="width: 100%;">
                            <option value="morning" <?php selected($registration->session_time, 'morning'); ?>>Morning</option>
                            <option value="afternoon" <?php selected($registration->session_time, 'afternoon'); ?>>Afternoon</option>
                        </select>
                    </p>
                </div>
                
                <div>
                    <h3>Contact Information</h3>
                    <p>
                        <label><strong>Name:</strong></label><br>
                        <input type="text" id="contact_name" name="contact_name" value="<?php echo esc_attr($registration->contact_name); ?>" style="width: 100%;" required>
                    </p>
                    <p>
                        <label><strong>Email:</strong></label><br>
                        <input type="email" id="contact_email" name="contact_email" value="<?php echo esc_attr($registration->contact_email); ?>" style="width: 100%;" required>
                    </p>
                    <p>
                        <label><strong>Phone:</strong></label><br>
                        <input type="tel" id="contact_phone" name="contact_phone" value="<?php echo esc_attr($registration->contact_phone); ?>" style="width: 100%;" required>
                    </p>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <h3>Attendees</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 15px;">
                    <p>
                        <label><strong>Competing Students:</strong></label><br>
                        <input type="number" id="competing_students" name="competing_students" value="<?php echo esc_attr($registration->competing_students); ?>" min="1" max="6" style="width: 100%;" required onchange="calculateRegistrationTotals()">
                    </p>
                    <p>
                        <label><strong>Additional Students:</strong></label><br>
                        <input type="number" id="additional_students" name="additional_students" value="<?php echo esc_attr($registration->additional_students ?? 0); ?>" min="0" max="4" style="width: 100%;" onchange="calculateRegistrationTotals()">
                    </p>
                    <p>
                        <label><strong>Additional Adults:</strong></label><br>
                        <input type="number" id="additional_adults" name="additional_adults" value="<?php echo esc_attr($registration->additional_adults ?? 0); ?>" min="0" max="4" style="width: 100%;" onchange="calculateRegistrationTotals()">
                    </p>
                    <p>
                        <label><strong>Coaches:</strong></label><br>
                        <input type="number" id="coaches" name="coaches" value="<?php echo esc_attr($registration->coaches); ?>" min="0" max="4" style="width: 100%;" onchange="calculateRegistrationTotals()">
                    </p>
                </div>
                <p>
                    <label><strong>Dietary Restrictions:</strong></label><br>
                    <textarea id="dietary_restrictions" name="dietary_restrictions" rows="3" style="width: 100%;"><?php echo esc_textarea($registration->dietary_restrictions); ?></textarea>
                </p>
            </div>
            
            <div style="margin-top: 20px;">
                <h3>Payment & Registration Status</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <p>
                            <label><strong>Payment Status:</strong></label><br>
                            <select id="payment_status" name="payment_status" style="width: 100%;">
                                <option value="pending" <?php selected($registration->payment_status, 'pending'); ?>>Pending</option>
                                <option value="paid" <?php selected($registration->payment_status, 'paid'); ?>>Paid</option>
                                <option value="partial" <?php selected($registration->payment_status, 'partial'); ?>>Partial</option>
                            </select>
                        </p>
                        <p>
                            <label><strong>Registration Status:</strong></label><br>
                            <select id="registration_status" name="registration_status" style="width: 100%;">
                                <option value="pending" <?php selected($registration->registration_status, 'pending'); ?>>Pending</option>
                                <option value="confirmed" <?php selected($registration->registration_status, 'confirmed'); ?>>Confirmed</option>
                                <option value="cancelled" <?php selected($registration->registration_status, 'cancelled'); ?>>Cancelled</option>
                            </select>
                        </p>
                    </div>
                    <div>
                        <p>
                            <label><strong>Discount Amount ($):</strong></label><br>
                            <input type="number" id="discount_amount" name="discount_amount" value="<?php echo esc_attr($registration->discount_amount ?? 0); ?>" min="0" step="0.01" style="width: 100%;" onchange="calculateRegistrationTotals()">
                        </p>
                        <p>
                            <label><strong>Discount Reason:</strong></label><br>
                            <textarea id="discount_reason" name="discount_reason" rows="2" style="width: 100%;"><?php echo esc_textarea($registration->discount_reason ?? ''); ?></textarea>
                        </p>
                    </div>
                </div>
                <p>
                    <label><strong>Payment Notes:</strong></label><br>
                    <textarea id="payment_notes" name="payment_notes" rows="2" style="width: 100%;"><?php echo esc_textarea($registration->payment_notes); ?></textarea>
                </p>
                <p>
                    <label><strong>Status Notes:</strong></label><br>
                    <textarea id="status_notes" name="status_notes" rows="2" style="width: 100%;"><?php echo esc_textarea($registration->status_notes ?? ''); ?></textarea>
                </p>
                
                <div id="totals-display" style="background: #f0f0f0; padding: 15px; margin-top: 10px; border-radius: 5px;">
                    <p><strong>Current Total:</strong> $<span id="calculated-total"><?php echo number_format($registration->total_amount, 2); ?></span></p>
                    <p><strong>Amount Paid:</strong> $<?php echo number_format($registration->amount_paid ?: 0, 2); ?></p>
                    <p><strong>Balance Due:</strong> $<span id="calculated-balance" style="color: #dc3232; font-weight: bold;"><?php echo number_format($registration->balance_due ?: 0, 2); ?></span></p>
                </div>
            </div>
            
            <div style="margin-top: 20px; text-align: right;">
                <button type="button" class="button" onclick="cancelEdit(<?php echo $registration->id; ?>)">Cancel</button>
                <button type="button" class="button button-primary" onclick="saveRegistrationChanges(<?php echo $registration->id; ?>)">Save Changes</button>
            </div>
        </form>
        <?php
    } else {
        // VIEW MODE - Show registration details with Edit button
        ?>
    <h2>Registration #<?php echo $registration->id; ?></h2>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div>
            <h3>School Information</h3>
            <p><strong>School:</strong> <?php echo esc_html($registration->school_name); ?></p>
            <p><strong>Grade Level:</strong> <?php echo esc_html($registration->grade_level); ?></p>
            <p><strong>Session:</strong> <?php echo ucfirst($registration->session_time); ?></p>
        </div>
        
        <div>
            <h3>Contact Information</h3>
            <p><strong>Name:</strong> <?php echo esc_html($registration->contact_name); ?></p>
            <p><strong>Email:</strong> <a href="mailto:<?php echo esc_attr($registration->contact_email); ?>"><?php echo esc_html($registration->contact_email); ?></a></p>
            <p><strong>Phone:</strong> <?php echo esc_html($registration->contact_phone); ?></p>
        </div>
    </div>
    
    <div style="margin-top: 20px;">
        <h3>Attendees</h3>
        <p><strong>Competing Students:</strong> <?php echo $registration->competing_students; ?></p>
        <p><strong>Additional Student Attendees:</strong> <?php echo $registration->additional_students ?? 0; ?></p>
        <p><strong>Additional Adult Attendees:</strong> <?php echo $registration->additional_adults ?? 0; ?></p>
        <p><strong>Coaches:</strong> <?php echo $registration->coaches; ?></p>
        <?php if (!empty($registration->dietary_restrictions)): ?>
            <p><strong>Dietary Restrictions:</strong><br>
            <span style="background: #fff3cd; padding: 8px; display: inline-block; border-left: 3px solid #ffc107;">
                <?php echo nl2br(esc_html($registration->dietary_restrictions)); ?>
            </span></p>
        <?php endif; ?>
    </div>
    
    <div style="margin-top: 20px;">
        <h3>Payment Information</h3>
        <p><strong>Payment Method:</strong> <?php echo ucfirst($registration->payment_method); ?></p>
        <p><strong>Payment Option:</strong> <?php echo ucfirst($registration->payment_option); ?></p>
        <p><strong>Total Amount:</strong> $<?php echo number_format($registration->total_amount, 2); ?></p>
        <p><strong>Amount Paid:</strong> $<?php echo number_format($registration->amount_paid ?: 0, 2); ?></p>
        <p><strong>Balance Due:</strong> <span style="color: <?php echo ($registration->balance_due > 0) ? '#dc3232' : '#46b450'; ?>; font-weight: bold;">$<?php echo number_format($registration->balance_due ?: 0, 2); ?></span></p>
        <p><strong>Payment Status:</strong> <span class="status-<?php echo $registration->payment_status; ?>"><?php echo ucfirst($registration->payment_status); ?></span></p>
        <?php if ($registration->stripe_payment_intent_id): ?>
            <p><strong>Stripe Payment ID:</strong> <code><?php echo esc_html($registration->stripe_payment_intent_id); ?></code></p>
        <?php endif; ?>
    </div>
    
    <div style="margin-top: 20px;">
        <h3>Event Information</h3>
        <p><strong>Event:</strong> <?php echo esc_html($registration->event_name); ?></p>
        <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($registration->event_date)); ?></p>
        <p><strong>Location:</strong> <?php echo esc_html($registration->event_location); ?></p>
    </div>
    
    <div style="margin-top: 20px;">
        <h3>Registration Status</h3>
        <p><strong>Status:</strong> <span class="reg-status-<?php echo $registration->registration_status ?: 'pending'; ?>"><?php echo ucfirst($registration->registration_status ?: 'Pending'); ?></span></p>
        <p><strong>Registration Date:</strong> <?php echo date('F j, Y g:i A', strtotime($registration->registration_date)); ?></p>
        <?php if ($registration->payment_notes): ?>
            <p><strong>Payment Notes:</strong><br><?php echo nl2br(esc_html($registration->payment_notes)); ?></p>
        <?php endif; ?>
        <?php if ($registration->status_notes): ?>
            <p><strong>Status Notes:</strong><br><?php echo nl2br(esc_html($registration->status_notes)); ?></p>
        <?php endif; ?>
    </div>
    
    <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #ddd; text-align: right;">
        <button type="button" class="button button-primary" onclick="switchToEditMode(<?php echo $registration->id; ?>)">
            <span class="dashicons dashicons-edit" style="vertical-align: middle;"></span> Edit Registration
        </button>
    </div>
    <?php
    } // End if/else for mode
    
    $html = ob_get_clean();
    
    wp_send_json_success(array('html' => $html));
}


// Add AJAX handler to generate payment link for admin copy feature
add_action('wp_ajax_sbr_generate_payment_link', 'sbr_generate_payment_link');
function sbr_generate_payment_link() {
    check_ajax_referer('sbr_generate_link', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    global $sbr_instance, $wpdb;
    if (!$sbr_instance) {
        $sbr_instance = new ScholarsBowlRegistration();
    }
    
    $registration_id = intval($_POST['registration_id']);
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'view';
    
    // Get registration details
    $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';
    $registration = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $registrations_table WHERE id = %d",
        $registration_id
    ));
    
    if (!$registration) {
        wp_send_json_error('Registration not found.');
        return;
    }
    
    if ($registration->balance_due <= 0) {
        wp_send_json_error('This registration has no outstanding balance.');
        return;
    }
    
    // Check if there's an existing active token for this registration
    $tokens_table = $wpdb->prefix . 'scholars_bowl_payment_tokens';
    $existing_token = $wpdb->get_row($wpdb->prepare(
        "SELECT token, expires_at FROM $tokens_table 
        WHERE registration_id = %d 
        AND status = 'active' 
        AND used_at IS NULL 
        AND expires_at > NOW()
        ORDER BY created_at DESC 
        LIMIT 1",
        $registration_id
    ));
    
    if ($existing_token) {
        // Use existing valid token
        $token = $existing_token->token;
        $message = 'Using existing payment link (expires ' . date('M j, Y', strtotime($existing_token->expires_at)) . ')';
    } else {
        // Generate new token using the event-based expiration
        $token = $sbr_instance->generate_payment_token_for_event(
            $registration_id, 
            $registration->event_id, 
            $registration->balance_due
        );
        
        if (!$token) {
            wp_send_json_error('Failed to generate payment link.');
            return;
        }
        
        // Get the expiration date for the message
        $token_row = $wpdb->get_row($wpdb->prepare(
            "SELECT expires_at FROM $tokens_table WHERE token = %s",
            $token
        ));
        $message = 'New payment link generated (expires ' . date('M j, Y', strtotime($token_row->expires_at)) . ')';
    }
    
    // Create the proper completion URL using new system
    $completion_page_url = home_url('/complete-payment/');
    $completion_url = add_query_arg('token', $token, $completion_page_url);
    
    wp_send_json_success(array(
        'link' => $completion_url,
        'message' => $message
    ));
}

// Helper function to log audit trail
function sbr_log_audit($registration_id, $field, $old_value, $new_value, $action = 'updated') {
    global $wpdb;
    $audit_table = $wpdb->prefix . 'scholars_bowl_registration_audit';
    
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;
    $user_name = $current_user->display_name;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $wpdb->insert(
        $audit_table,
        array(
            'registration_id' => $registration_id,
            'user_id' => $user_id,
            'user_name' => $user_name,
            'action' => $action,
            'field_changed' => $field,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'timestamp' => current_time('mysql'),
            'ip_address' => $ip_address
        ),
        array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
    );
}

// AJAX: Save registration edits from admin
add_action('wp_ajax_sbr_save_registration_edit', 'sbr_save_registration_edit');
function sbr_save_registration_edit() {
    check_ajax_referer('sbr_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    global $wpdb;
    $registrations_table = $wpdb->prefix . 'scholars_bowl_registrations';
    $events_table = $wpdb->prefix . 'scholars_bowl_events';
    
    $registration_id = intval($_POST['registration_id']);
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'view';
    
    // Get current registration data
    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $registrations_table WHERE id = %d",
        $registration_id
    ));
    
    if (!$current) {
        wp_send_json_error('Registration not found');
    }
    
    // Get event data for pricing
    $event = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $events_table WHERE id = %d",
        $current->event_id
    ));
    
    // Prepare new data
    $new_data = array(
        'school_name' => sanitize_text_field($_POST['school_name']),
        'grade_level' => sanitize_text_field($_POST['grade_level']),
        'session_time' => sanitize_text_field($_POST['session_time']),
        'contact_name' => sanitize_text_field($_POST['contact_name']),
        'contact_email' => sanitize_email($_POST['contact_email']),
        'contact_phone' => sanitize_text_field($_POST['contact_phone']),
        'competing_students' => intval($_POST['competing_students']),
        'additional_students' => intval($_POST['additional_students']),
        'additional_adults' => intval($_POST['additional_adults']),
        'coaches' => intval($_POST['coaches']),
        'dietary_restrictions' => sanitize_textarea_field($_POST['dietary_restrictions']),
        'payment_status' => sanitize_text_field($_POST['payment_status']),
        'payment_notes' => sanitize_textarea_field($_POST['payment_notes']),
        'registration_status' => sanitize_text_field($_POST['registration_status']),
        'discount_amount' => floatval($_POST['discount_amount'] ?? 0),
        'discount_reason' => sanitize_textarea_field($_POST['discount_reason'] ?? '')
    );
    
    // Validate discount
    if ($new_data['discount_amount'] > 0 && empty($new_data['discount_reason'])) {
        wp_send_json_error('Discount reason is required when applying a discount');
    }
    
    // Calculate total based on event pricing
    $per_student_rate = floatval($event->standard_rate);
    $total = $new_data['competing_students'] * $per_student_rate;
    $total += $new_data['additional_students'] * $per_student_rate;
    $total += $new_data['additional_adults'] * $per_student_rate;
    $extra_coaches = max(0, $new_data['coaches'] - intval($event->free_coaches));
    $total += $extra_coaches * $per_student_rate;
    $total -= $new_data['discount_amount'];
    
    $new_data['total_amount'] = $total;
    $new_data['balance_due'] = $total - floatval($current->amount_paid);
    $new_data['last_updated'] = current_time('mysql');
    
    // Update the registration
    $result = $wpdb->update(
        $registrations_table,
        $new_data,
        array('id' => $registration_id),
        array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%f', '%f', '%s'),
        array('%d')
    );
    
    if ($result !== false) {
        // Log changes in audit trail
        foreach ($new_data as $field => $value) {
            if (isset($current->$field) && $current->$field != $value) {
                sbr_log_audit($registration_id, $field, $current->$field, $value, 'updated');
            }
        }
        
        wp_send_json_success(array('message' => 'Registration updated successfully'));
    } else {
        wp_send_json_error('Database update failed: ' . $wpdb->last_error);
    }
}

