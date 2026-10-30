<?php
/**
 * Fictional test data, for local development only (same rows as dev/seed.sql).
 * Works on MariaDB/MySQL and on the SQLite Database Integration plugin.
 *
 *   wp eval-file dev/seed.php
 *
 * Adds the columns the live database has but the plugin's CREATE TABLE does not define,
 * then replaces all events and registrations with the fictional ones below.
 */

if (!defined('ABSPATH')) {
    exit(1);
}

global $wpdb;
$p = $wpdb->prefix;
$reg = "{$p}scholars_bowl_registrations";
$ev = "{$p}scholars_bowl_events";

$cols = function ($table) use ($wpdb) {
    return (array) $wpdb->get_col("DESCRIBE `$table`", 0);
};
$add = function ($table, $col, $def) use ($wpdb, $cols) {
    if (!in_array($col, $cols($table), true)) {
        $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    }
};

// Columns the live registrations/events tables have that no plugin code creates.
$add($reg, 'grade_level', 'varchar(20) DEFAULT NULL');
$add($reg, 'dietary_restrictions', 'text');
$add($reg, 'additional_students', 'int(2) DEFAULT 0');
$add($reg, 'additional_adults', 'int(2) DEFAULT 0');
$add($reg, 'is_test_mode', 'tinyint(1) DEFAULT 0');
$add($reg, 'discount_amount', "decimal(10,2) DEFAULT '0.00'");
$add($reg, 'discount_reason', 'text');
$add($reg, 'status_notes', 'text');
$add($ev, 'registration_deadline', 'date DEFAULT NULL');
$add($ev, 'payment_deadline', 'date DEFAULT NULL');
// The live registration_date column defaults to the current time.
$wpdb->query("ALTER TABLE `$reg` MODIFY registration_date datetime DEFAULT CURRENT_TIMESTAMP");

$wpdb->query("CREATE TABLE IF NOT EXISTS {$p}scholars_bowl_registration_audit (
  id mediumint(9) NOT NULL AUTO_INCREMENT,
  registration_id mediumint(9) NOT NULL,
  user_id bigint(20) DEFAULT NULL,
  user_name varchar(255) DEFAULT NULL,
  action varchar(50) DEFAULT NULL,
  field_changed varchar(100) DEFAULT NULL,
  old_value text,
  new_value text,
  timestamp datetime DEFAULT NULL,
  ip_address varchar(100) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY registration_id (registration_id)
)");
$wpdb->query("DELETE FROM {$p}scholars_bowl_registration_audit");
$wpdb->query("DELETE FROM `$reg`");
$wpdb->query("DELETE FROM `$ev`");
foreach (array('scholars_bowl_students', 'scholars_bowl_roster_tokens', 'scholars_bowl_roster_status') as $t) {
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $t)) === $p . $t) {
        $wpdb->query("DELETE FROM `{$p}{$t}`");
    }
}

$event_date = getenv('SBR_SEED_EVENT_DATE') ?: '2026-11-05';
$events = array(
    array(1, 'High School Scholars Bowl 2025 (dev)', 'hs-2025-dev', '2025-10-29', 'completed'),
    array(2, 'High School Scholars Bowl 2026 (dev)', 'hs-2026-dev', $event_date, 'active'),
);
foreach ($events as $e) {
    $wpdb->insert($ev, array(
        'id' => $e[0], 'event_name' => $e[1], 'event_slug' => $e[2], 'event_date' => $e[3],
        'event_location' => 'Wadley Campus', 'early_bird_deadline' => '2026-10-01', 'early_bird_rate' => 14.00,
        'standard_rate' => 15.00, 'deposit_amount' => 25.00, 'max_students' => 6, 'min_students' => 1,
        'max_additional' => 4, 'free_coaches' => 2,
        'morning_classifications' => 'Private,1A,2A,3A', 'afternoon_classifications' => '4A,5A,6A,7A',
        'morning_session_time' => '8:30 AM', 'afternoon_session_time' => '12:15 PM',
        'organization_name' => 'SUSCC Foundation (dev)', 'organizer_name' => 'Dev Organizer',
        'organizer_email' => 'organizer@example.test', 'organizer_phone' => '555-010-0000',
        'payment_address' => "PO Box 000\nWadley, AL 00000",
        'stripe_mode' => 'test', 'stripe_test_secret_key' => 'sk_test_DEV_SECRET_MUST_NEVER_APPEAR_IN_API',
        'event_status' => $e[4], 'created_date' => '2026-08-01 00:00:00',
    ));
}

$rows = array(
    // id, event, school, contact, email, phone, competing, add_att, add_st, add_ad, coaches, grade, session, option, method, total, paid, balance, pay_status, reg_status, notes, dietary, test, date
    array(12, 1, 'Pinecrest Academy', 'Avery Testperson', 'avery@pinecrest.example', '5550100101', 6, 0, 0, 0, 1, 'Private', 'morning', 'full', 'credit', 90, 90, 0, 'completed', 'confirmed', '', 'None', 0, '2025-10-09 13:38:50'),
    array(18, 1, 'Live Test 4', 'Test Admin', 'admin@example.test', '5550100000', 3, 0, 0, 0, 0, 'Private', 'morning', 'deposit', 'credit', 45, 25, 20, 'pending', 'pending', '', 'None', 1, '2025-10-09 17:45:05'),
    array(36, 2, 'Maple Grove High School', 'Sam Notreal', 'sam@maplegrove.example', '5550100107', 8, 0, 0, 0, 2, '5A', 'afternoon', 'full', 'check', 112, 0, 112, 'pending', 'pending', '', 'None', 0, '2026-08-27 14:56:36'),
    array(40, 2, 'Cedar Hollow School', 'Morgan Example', 'morgan@cedarhollow.example', '555-010-0103', 8, 3, 3, 0, 2, '2A', 'morning', 'full', 'credit', 53, 53, 0, 'completed', 'confirmed', '', 'One vegetarian student', 0, '2026-09-22 13:01:38'),
    array(44, 2, 'Riverbend Christian A', 'Jordan Sample', 'jsample@riverbend.example', '5550100102', 7, 0, 0, 0, 2, '1A', 'morning', 'deposit', 'credit', 98, 25, 73, 'partial', 'confirmed', '', 'None', 0, '2026-09-29 17:38:59'),
    array(45, 2, 'Riverbend Christian B', 'Jordan Sample', 'jsample@riverbend.example', '5550100102', 7, 4, 0, 4, 0, '1A', 'morning', 'deposit', 'credit', 154, 25, 129, 'partial', 'pending', '', 'None', 0, '2026-09-29 17:41:31'),
    array(46, 2, 'Summit High School', 'Riley Fictional', 'riley@summit.example', '5550100105', 6, 1, 0, 1, 1, '6A', 'afternoon', 'full', 'credit', 98, 98, 0, 'completed', 'confirmed', '', 'None', 0, '2026-10-01 18:04:30'),
    array(47, 2, 'Lakeview High School', 'Casey Placeholder', 'casey@lakeview.example', '555-010-0104', 6, 0, 0, 0, 1, '', 'afternoon', 'full', 'check', 84, 0, 84, 'pending', 'cancelled', 'Withdrew', 'None', 0, '2026-09-18 15:51:29'),
    array(49, 2, 'Oak Ridge Academy', 'Taylor Madeup', 'taylor@oakridge.example', '5550100106', 6, 0, 0, 0, 1, '', 'morning', 'full', 'credit', 90, 90, 0, 'completed', 'confirmed', '', 'None', 0, '2026-10-10 10:00:00'),
);
foreach ($rows as $r) {
    $wpdb->insert($reg, array(
        'id' => $r[0], 'event_id' => $r[1], 'school_name' => $r[2], 'contact_name' => $r[3], 'contact_email' => $r[4],
        'contact_phone' => $r[5], 'competing_students' => $r[6], 'additional_attendees' => $r[7], 'additional_students' => $r[8],
        'additional_adults' => $r[9], 'coaches' => $r[10], 'school_classification' => $r[0] === 47 ? '4A' : '', 'grade_level' => $r[11],
        'session_time' => $r[12], 'payment_option' => $r[13], 'payment_method' => $r[14], 'total_amount' => $r[15],
        'amount_paid' => $r[16], 'balance_due' => $r[17], 'payment_status' => $r[18], 'registration_status' => $r[19],
        'payment_notes' => $r[20], 'dietary_restrictions' => $r[21], 'is_test_mode' => $r[22],
        'registration_date' => $r[23], 'last_updated' => $r[23],
    ));
}
echo 'Seeded ' . intval($wpdb->get_var("SELECT COUNT(*) FROM `$ev`")) . ' events and ' . intval($wpdb->get_var("SELECT COUNT(*) FROM `$reg`")) . " registrations.\n";
