<?php
/**
 * Database upgrades (added in 1.1.0).
 *
 * Replacing the plugin through "Upload Plugin" does not run the activation hook, so the
 * schema is checked against a stored version (option sbr_db_version) on every request,
 * which costs one autoloaded option read. When it is behind, the upgrade:
 *
 *   - creates three new tables with dbDelta (students, roster tokens, roster status);
 *   - adds three nullable columns to the events table if they are missing
 *     (tournament_config, roster_deadline, updated_at);
 *   - fills updated_at for existing events.
 *
 * It never changes, drops or retypes existing tables or columns: the live schema has grown
 * by hand over time, so existing tables are not run through dbDelta here.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBR_Schema {
    const DB_VERSION = '1.1.0';
    const VERSION_OPTION = 'sbr_db_version';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 1);
    }

    public static function table($name) {
        global $wpdb;
        $map = array(
            'events' => 'scholars_bowl_events',
            'registrations' => 'scholars_bowl_registrations',
            'students' => 'scholars_bowl_students',
            'roster_tokens' => 'scholars_bowl_roster_tokens',
            'roster_status' => 'scholars_bowl_roster_status',
        );
        return $wpdb->prefix . $map[$name];
    }

    private static $table_cache = array();
    private static $column_cache = array();

    public static function table_exists($table, $refresh = false) {
        global $wpdb;
        if ($refresh || !isset(self::$table_cache[$table])) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            self::$table_cache[$table] = ($found === $table);
        }
        return self::$table_cache[$table];
    }

    public static function forget() {
        self::$table_cache = array();
        self::$column_cache = array();
    }

    /** Column names of a table (empty array if it does not exist). */
    public static function columns($table, $refresh = false) {
        global $wpdb;
        if ($refresh || !isset(self::$column_cache[$table])) {
            $cols = self::table_exists($table, $refresh) ? $wpdb->get_col("DESCRIBE `{$table}`", 0) : array();
            self::$column_cache[$table] = is_array($cols) ? $cols : array();
        }
        return self::$column_cache[$table];
    }

    public static function has_column($table, $column) {
        return in_array($column, self::columns($table), true);
    }

    /** True when the 1.1.0 roster tables are usable. */
    public static function roster_ready() {
        return self::table_exists(self::table('students')) && self::table_exists(self::table('roster_tokens')) && self::table_exists(self::table('roster_status'));
    }

    public static function maybe_upgrade() {
        if (get_option(self::VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        // If an upgrade attempt failed, don't retry on every request.
        if (get_transient('sbr_upgrade_retry_wait')) {
            return;
        }
        if (!self::table_exists(self::table('events')) || !self::table_exists(self::table('registrations'))) {
            return; // the plugin's own setup creates the base tables first
        }
        if (self::upgrade()) {
            update_option(self::VERSION_OPTION, self::DB_VERSION, true);
            update_option('sbr_flush_rewrite', 1, true);
        } else {
            set_transient('sbr_upgrade_retry_wait', 1, 10 * MINUTE_IN_SECONDS);
        }
    }

    /** Returns true when everything 1.1.0 needs exists. */
    public static function upgrade() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $students = self::table('students');
        $tokens = self::table('roster_tokens');
        $status = self::table('roster_status');

        if (!self::table_exists($students)) {
            dbDelta("CREATE TABLE $students (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
registration_id mediumint(9) NOT NULL,
sort_order tinyint(3) unsigned NOT NULL DEFAULT 1,
first_name varchar(100) NOT NULL DEFAULT '',
last_name varchar(100) NOT NULL DEFAULT '',
grade tinyint(3) unsigned DEFAULT NULL,
created_at datetime DEFAULT NULL,
updated_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
KEY registration_id (registration_id)
) $charset;");
        }
        if (!self::table_exists($tokens)) {
            dbDelta("CREATE TABLE $tokens (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
registration_id mediumint(9) NOT NULL,
token_hash char(64) NOT NULL,
purpose varchar(20) NOT NULL DEFAULT 'email',
created_at datetime DEFAULT NULL,
revoked_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY token_hash (token_hash),
KEY registration_id (registration_id)
) $charset;");
        }
        if (!self::table_exists($status)) {
            dbDelta("CREATE TABLE $status (
registration_id mediumint(9) NOT NULL,
roster_updated_at datetime DEFAULT NULL,
reminder_7d_sent_at datetime DEFAULT NULL,
reminder_1d_sent_at datetime DEFAULT NULL,
PRIMARY KEY  (registration_id)
) $charset;");
        }

        $events = self::table('events');
        $add = array(
            'tournament_config' => 'longtext NULL',
            'roster_deadline' => 'datetime NULL DEFAULT NULL',
            'updated_at' => 'datetime NULL DEFAULT NULL',
        );
        $have = self::columns($events, true);
        foreach ($add as $col => $def) {
            if (!in_array($col, $have, true)) {
                $wpdb->query("ALTER TABLE `{$events}` ADD COLUMN `{$col}` {$def}");
            }
        }
        $have = self::columns($events, true);
        if (in_array('updated_at', $have, true)) {
            $wpdb->query($wpdb->prepare("UPDATE `{$events}` SET updated_at = %s WHERE updated_at IS NULL", gmdate('Y-m-d H:i:s')));
        }

        // Re-check, forgetting cached answers from before the upgrade.
        self::forget();
        $ok = true;
        foreach (array($students, $tokens, $status) as $t) {
            $ok = $ok && self::table_exists($t);
        }
        foreach (array_keys($add) as $col) {
            $ok = $ok && in_array($col, $have, true);
        }
        if (!$ok) {
            error_log('SBR: 1.1.0 database upgrade incomplete: ' . $wpdb->last_error);
        }
        return $ok;
    }
}
