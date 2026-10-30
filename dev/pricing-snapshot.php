<?php
/**
 * Pricing snapshot, to prove a plugin version changes no prices.
 *
 *   SBR_SNAPSHOT=/tmp/pricing-1.0.6.json wp eval-file dev/pricing-snapshot.php
 *
 * Records, for whatever plugin version is active:
 *   1. every registration's stored amounts (total, deposit, paid, balance, payment option/method);
 *   2. the public price quote (admin-ajax sbr_calculate_total) for a grid of team sizes on every event;
 *   3. a check-payment registration submitted through the public endpoint (admin-ajax
 *      sbr_submit_registration) without students and, when the version supports it, with six
 *      named students. Both are deleted afterwards.
 * Compare two snapshots with:  php dev/pricing-compare.php a.json b.json
 *
 * Needs the site running at home_url() (e.g. php -S). Development only.
 */

if (!defined('ABSPATH')) {
    exit(1);
}
global $wpdb;
$out_file = getenv('SBR_SNAPSHOT') ?: '/tmp/pricing-snapshot.json';
$reg = $wpdb->prefix . 'scholars_bowl_registrations';
$ev = $wpdb->prefix . 'scholars_bowl_events';
$ajax = admin_url('admin-ajax.php');
wp_set_current_user(0);
$nonce = wp_create_nonce('sbr_nonce');

$money = array('total_amount', 'deposit_amount', 'amount_paid', 'balance_due', 'payment_option', 'payment_method', 'payment_status', 'competing_students', 'additional_students', 'additional_adults', 'coaches');
$snap = array('plugin_version' => SBR_VERSION, 'registrations' => array(), 'quotes' => array(), 'submissions' => array());

foreach ((array) $wpdb->get_results("SELECT * FROM $reg ORDER BY id", ARRAY_A) as $r) {
    $row = array();
    foreach ($money as $k) {
        $row[$k] = isset($r[$k]) ? (string) $r[$k] : null;
    }
    $snap['registrations'][$r['id']] = $row;
}

$post = function ($body) use ($ajax) {
    $res = wp_remote_post($ajax, array('timeout' => 30, 'body' => $body));
    if (is_wp_error($res)) {
        return array('error' => $res->get_error_message());
    }
    $json = json_decode(wp_remote_retrieve_body($res), true);
    return $json === null ? array('raw' => substr(wp_remote_retrieve_body($res), 0, 300)) : $json;
};

foreach ((array) $wpdb->get_col("SELECT id FROM $ev ORDER BY id") as $event_id) {
    foreach (array(1, 4, 6) as $c) {
        foreach (array(0, 2) as $as) {
            foreach (array(0, 1) as $aa) {
                foreach (array(0, 2, 3, 4) as $coaches) {
                    $key = "e$event_id-c$c-as$as-aa$aa-co$coaches";
                    $snap['quotes'][$key] = $post(array(
                        'action' => 'sbr_calculate_total', 'nonce' => $nonce, 'event_id' => $event_id,
                        'competing_students' => $c, 'additional_students' => $as, 'additional_adults' => $aa,
                        'additional_attendees' => $as + $aa, 'coaches' => $coaches,
                    ));
                }
            }
        }
    }
}

$base = array(
    'action' => 'sbr_submit_registration', 'nonce' => $nonce, 'sbr_nonce' => $nonce,
    'event_id' => 2, 'event_slug' => 'hs-2026-dev', 'website' => '',
    'school_name' => 'Pricing Probe High School', 'contact_name' => 'Pat Probe',
    'contact_email' => 'probe@pricing.example', 'contact_phone' => '555-010-0999',
    'dietary_restrictions' => '', 'competing_students' => 5, 'additional_students' => 1,
    'additional_adults' => 1, 'coaches' => 3, 'grade_level' => '3A',
    'payment_option' => 'deposit', 'payment_method' => 'check',
);
$variants = array('no_students' => array());
if (version_compare(SBR_VERSION, '1.1.0', '>=')) {
    $students = array();
    foreach (array(array('Ana', 'Lee', '11'), array('Ben', 'Ortiz', '10'), array('Cara', 'Ng', '12'), array('Dev', 'Patel', '9'), array('Eli', 'Brooks', '11'), array('Fay', 'Moss', '')) as $i => $s) {
        $students["students[$i][first_name]"] = $s[0];
        $students["students[$i][last_name]"] = $s[1];
        $students["students[$i][grade]"] = $s[2];
    }
    $variants['six_students'] = $students;
}
foreach ($variants as $name => $extra) {
    $wpdb->query("DELETE FROM {$wpdb->prefix}scholars_bowl_spam_log"); // the form allows 3 submissions per 5 minutes
    $before = intval($wpdb->get_var("SELECT MAX(id) FROM $reg"));
    $response = $post($base + $extra);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $reg WHERE id > %d ORDER BY id DESC LIMIT 1", $before), ARRAY_A);
    $stored = array();
    foreach ($money as $k) {
        $stored[$k] = $row && isset($row[$k]) ? (string) $row[$k] : null;
    }
    $snap['submissions'][$name] = array('response_success' => !empty($response['success']), 'stored' => $stored);
    if ($row) {
        $wpdb->delete($reg, array('id' => $row['id']));
        foreach (array('scholars_bowl_students', 'scholars_bowl_roster_tokens', 'scholars_bowl_roster_status', 'scholars_bowl_payment_tokens') as $t) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $t)) === $wpdb->prefix . $t) {
                $wpdb->delete($wpdb->prefix . $t, array('registration_id' => $row['id']));
            }
        }
    }
}

file_put_contents($out_file, wp_json_encode($snap, JSON_PRETTY_PRINT));
echo "Wrote $out_file: " . count($snap['registrations']) . ' registrations, ' . count($snap['quotes']) . ' quotes, ' . count($snap['submissions']) . " submissions (plugin {$snap['plugin_version']}).\n";
