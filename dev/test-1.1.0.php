<?php
/**
 * Integration tests for 1.1.0, run against a local site (development only):
 *
 *   wp eval-file dev/test-1.1.0.php
 *
 * Needs: the site served at home_url() (e.g. php -S with a router), dev/seed.php data,
 * dev/mail-log-mu-plugin.php installed (writes mail to wp-content/sbr-mail.log), an admin user
 * "admin", and the webhook listener (dev/webhook-listener.php) on http://localhost:8091 with
 * SBR_WEBHOOK_LOG=/tmp/sbr-notify.log (override with env SBR_NOTIFY_LOG).
 *
 * Prints one line per check and exits 1 if any check fails.
 */

if (!defined('ABSPATH')) {
    exit(1);
}

global $wpdb;
$GLOBALS['sbr_t'] = array('pass' => 0, 'fail' => 0);
function t_ok($cond, $label, $detail = '') {
    if ($cond) {
        $GLOBALS['sbr_t']['pass']++;
        echo "  ok    $label\n";
    } else {
        $GLOBALS['sbr_t']['fail']++;
        echo "  FAIL  $label" . ($detail !== '' ? "  -- $detail" : '') . "\n";
    }
}
function t_section($name) {
    echo "\n== $name\n";
}

$P = $wpdb->prefix;
$REG = "{$P}scholars_bowl_registrations";
$EV = "{$P}scholars_bowl_events";
$ST = "{$P}scholars_bowl_students";
$STATUS = "{$P}scholars_bowl_roster_status";
$TOK = "{$P}scholars_bowl_roster_tokens";
$mail_log = WP_CONTENT_DIR . '/sbr-mail.log';
$notify_log = getenv('SBR_NOTIFY_LOG') ?: '/tmp/sbr-notify.log';
$ajax = admin_url('admin-ajax.php');
@unlink($mail_log);
@file_put_contents($notify_log, '');

function t_http($method, $url, $args = array()) {
    $args = $args + array('timeout' => 30, 'redirection' => 0);
    $args['method'] = $method;
    $r = wp_remote_request($url, $args);
    if (is_wp_error($r)) {
        return array('code' => 0, 'body' => $r->get_error_message(), 'headers' => array());
    }
    return array('code' => wp_remote_retrieve_response_code($r), 'body' => wp_remote_retrieve_body($r), 'headers' => wp_remote_retrieve_headers($r));
}
function t_mails() {
    $f = WP_CONTENT_DIR . '/sbr-mail.log';
    $out = array();
    foreach (file_exists($f) ? file($f) : array() as $line) {
        $out[] = json_decode($line, true);
    }
    return $out;
}
function t_notices() {
    $f = getenv('SBR_NOTIFY_LOG') ?: '/tmp/sbr-notify.log';
    $out = array();
    clearstatcache();
    foreach (file_exists($f) ? file($f) : array() as $line) {
        $out[] = json_decode($line, true);
    }
    return $out;
}
function t_wait_notices($n, $seconds = 8) {
    $end = microtime(true) + $seconds;
    while (count(t_notices()) < $n && microtime(true) < $end) {
        usleep(200000);
    }
    return t_notices();
}
function t_roster_link($text) {
    return preg_match('#(https?://[^\s]+/team-roster/[a-f0-9]{64}/)#', (string) $text, $m) ? $m[1] : null;
}
function t_nonce_from($html, $name) {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $m) ? $m[1] : null;
}
/** Log the CLI in as admin and return cookies an HTTP request can use; nonces made afterwards match them. */
function t_admin_cookies() {
    static $cookies = null;
    if ($cookies) {
        return $cookies;
    }
    $user = get_user_by('login', 'admin');
    wp_set_current_user($user->ID);
    $exp = time() + 3 * HOUR_IN_SECONDS;
    $token = WP_Session_Tokens::get_instance($user->ID)->create($exp);
    $auth = wp_generate_auth_cookie($user->ID, $exp, 'auth', $token);
    $logged_in = wp_generate_auth_cookie($user->ID, $exp, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $logged_in;
    $cookies = array(AUTH_COOKIE => $auth, LOGGED_IN_COOKIE => $logged_in);
    return $cookies;
}
function t_public_nonce() {
    $uid = get_current_user_id();
    $cookie = isset($_COOKIE[LOGGED_IN_COOKIE]) ? $_COOKIE[LOGGED_IN_COOKIE] : null;
    wp_set_current_user(0);
    unset($_COOKIE[LOGGED_IN_COOKIE]);
    $n = wp_create_nonce('sbr_nonce');
    wp_set_current_user($uid);
    if ($cookie) {
        $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    }
    return $n;
}
function t_submit($fields, $headers = array()) {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}scholars_bowl_spam_log");
    $nonce = t_public_nonce();
    $body = $fields + array(
        'action' => 'sbr_submit_registration', 'nonce' => $nonce, 'sbr_nonce' => $nonce, 'event_id' => 2,
        'event_slug' => 'hs-2026-dev', 'website' => '', 'contact_name' => 'Pat Tester', 'contact_phone' => '555-010-0111',
        'dietary_restrictions' => '', 'additional_students' => 0, 'additional_adults' => 0, 'coaches' => 1,
        'payment_option' => 'full', 'payment_method' => 'check',
    );
    $start = microtime(true);
    $r = t_http('POST', admin_url('admin-ajax.php'), array('body' => $body, 'headers' => $headers));
    $r['elapsed'] = microtime(true) - $start;
    $r['json'] = json_decode($r['body'], true);
    return $r;
}

$admin = t_admin_cookies();
$token_hash = get_option('sbr_api_token_hash');
if (!$token_hash) {
    $api_token = bin2hex(random_bytes(32));
    update_option('sbr_api_token_hash', hash('sha256', $api_token), false);
    $token_hash = get_option('sbr_api_token_hash');
}
$api_token = getenv('SBR_API_TOKEN') ?: null;

/* ===================================================================== */
t_section('Schema');
t_ok(get_option('sbr_db_version') === '1.1.0', 'sbr_db_version is 1.1.0');
foreach (array($ST, $TOK, $STATUS) as $t) {
    t_ok($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t, "table $t exists");
}
$ev_cols = $wpdb->get_col("DESCRIBE $EV", 0);
foreach (array('tournament_config', 'roster_deadline', 'updated_at') as $c) {
    t_ok(in_array($c, $ev_cols, true), "events.$c exists");
}
t_ok((bool) wp_next_scheduled('sbr_roster_reminders'), 'hourly reminder job is scheduled');

/* ===================================================================== */
t_section('Tournament model');
$n = SBR_Tournament::normalize(array('divisions' => array(
    array('name' => 'A', 'session' => 'morning', 'classifications' => array('1A', '2A'), 'format' => 'bracket'),
    array('name' => 'B', 'session' => 'afternoon', 'classifications' => array('2A'), 'format' => 'round_robin'),
)));
t_ok(count($n['errors']) === 1 && strpos($n['errors'][0], '2A is in two divisions') === 0, 'a classification in two divisions is rejected', implode(' | ', $n['errors']));
$n = SBR_Tournament::normalize(array('divisions' => array(array('name' => 'X', 'session' => 'noon', 'classifications' => array('9A'), 'format' => 'swiss', 'bracket_size' => 6, 'seeding_rounds' => 0))));
t_ok(count($n['errors']) === 5, 'bad session, classification, format, bracket size and seeding rounds are all reported', implode(' | ', $n['errors']));
$n = SBR_Tournament::normalize(array('divisions' => array()));
t_ok($n['errors'] === array('Add at least one division.'), 'at least one division is required');
$d = SBR_Tournament::default_config();
t_ok($d['awards'] === array('class_places' => 2, 'division_places' => 1, 'individual_places' => 3), 'default awards are 2 / 1 / 3');
t_ok(wp_list_pluck($d['divisions'], 'name') === array('Private', 'Public 1A–3A', 'Public 4A–7A'), 'default divisions');
$syn = SBR_Tournament::synthesize((object) array('morning_classifications' => 'Private,1A,2A,3A,4A', 'afternoon_classifications' => '5A,6A'));
t_ok(wp_list_pluck($syn['divisions'], 'classifications') === array(array('Private'), array('1A', '2A', '3A'), array('5A', '6A'), array('4A')), 'legacy event: defaults restricted to its classifications, leftovers get their own division', wp_json_encode($syn['divisions']));
t_ok(SBR_Tournament::derive_session_classifications($d) === array('morning' => 'Private,1A,2A,3A', 'afternoon' => '4A,5A,6A,7A'), 'session lists derived from divisions');
$times = array('8:30 AM' => '08:30', '12:15 PM' => '12:15', '12:00 AM' => '00:00', '1 pm' => '13:00', '13:05' => '13:05', 'noonish' => null, '' => null);
$all = true;
foreach ($times as $in => $want) {
    $all = $all && SBR_Tournament::parse_time($in) === $want;
}
t_ok($all, 'session start times parse to HH:MM');
$dl = SBR_Tournament::roster_deadline((object) array('event_date' => '2026-10-28', 'roster_deadline' => null));
t_ok($dl && $dl->format('c') === '2026-10-27T23:59:59-05:00', 'default roster deadline is 11:59:59 pm the day before, site time', $dl ? $dl->format('c') : 'null');
$dl = SBR_Tournament::roster_deadline((object) array('event_date' => '2026-10-28', 'roster_deadline' => '2026-10-26 17:00:00'));
t_ok($dl && $dl->format('c') === '2026-10-26T17:00:00-05:00', 'a per-event roster deadline is used');

/* ===================================================================== */
t_section('Webhook settings and signature');
t_ok(SBR_Webhook::sanitize_url('http://example.com') === '', 'http:// rejected for a public host');
t_ok(SBR_Webhook::sanitize_url('https://scoring.example.org/') === 'https://scoring.example.org', 'https URL accepted (trailing slash removed)');
t_ok(SBR_Webhook::sanitize_url('http://localhost:8091') === 'http://localhost:8091', 'http://localhost accepted');
t_ok(SBR_Webhook::sanitize_url('https://u:p@x.org') === '', 'credentials in the URL rejected');
update_option('sbr_scoring_url', 'http://localhost:8091', false);
update_option('sbr_scoring_notify', 1, false);
$msg = SBR_Webhook::build('registration.saved', 12, 34, 1761800000);
$payload = json_decode($msg['body'], true);
t_ok(array_keys($payload) === array('site', 'type', 'event_id', 'registration_id', 'sent_at'), 'payload has exactly site/type/event_id/registration_id/sent_at');
t_ok($payload['sent_at'] === '2025-10-30T04:53:20Z' && $payload['site'] === home_url(), 'sent_at is ISO 8601 UTC; site is home_url()');
t_ok($msg['headers']['X-SBR-Signature'] === 'sha256=' . hash_hmac('sha256', '1761800000.' . $msg['body'], $token_hash), 'signature is HMAC-SHA256(token hash, timestamp.body)');
// Burst coalescing (unit): more than 5 registration notices for one event become one with registration_id null.
$rp = new ReflectionProperty('SBR_Webhook', 'queue');
$rp->setAccessible(true);
$q = array();
for ($i = 1; $i <= 8; $i++) {
    $q["registration.saved|2|$i"] = array('registration.saved', 2, $i);
}
$q['event.saved|2|'] = array('event.saved', 2, null);
$rp->setValue(null, $q);
$pending = SBR_Webhook::pending();
$rp->setValue(null, array());
t_ok(count($pending) === 2 && in_array(array('registration.saved', 2, null), $pending, true), 'a burst of 8 registration notices is coalesced into one', wp_json_encode($pending));

/* ===================================================================== */
t_section('Event editor (admin HTTP)');
$event = $wpdb->get_row("SELECT * FROM $EV WHERE id = 2", ARRAY_A);
$form_nonce = wp_create_nonce('save_event');
$base_fields = array('submit' => 'Update Event', 'save_event_nonce' => $form_nonce, 'event_id' => 2);
foreach (array('event_name', 'event_slug', 'event_date', 'event_location', 'early_bird_deadline', 'registration_deadline', 'payment_deadline', 'early_bird_rate', 'standard_rate', 'deposit_amount', 'max_students', 'min_students', 'max_additional', 'free_coaches', 'morning_session_time', 'afternoon_session_time', 'morning_classifications', 'afternoon_classifications', 'organization_name', 'organizer_name', 'organizer_email', 'organizer_phone', 'payment_address', 'from_email', 'reply_to_email', 'from_name', 'stripe_mode', 'stripe_test_publishable_key', 'stripe_test_secret_key', 'stripe_live_publishable_key', 'stripe_live_secret_key', 'stripe_publishable_key', 'stripe_secret_key', 'event_status') as $k) {
    $base_fields[$k] = isset($event[$k]) ? (string) $event[$k] : '';
}
$divs = array(
    array('key' => 'private', 'name' => 'Private', 'session' => 'morning', 'classifications' => array('Private'), 'format' => 'round_robin', 'seeding_rounds' => 3, 'bracket_size' => 4),
    array('key' => 'public-1a-3a', 'name' => 'Public 1A–3A', 'session' => 'morning', 'classifications' => array('1A', '2A', '3A'), 'format' => 'bracket', 'seeding_rounds' => 4, 'bracket_size' => 8, 'third_place_game' => '1'),
    array('key' => 'public-4a-7a', 'name' => 'Public 4A–7A', 'session' => 'afternoon', 'classifications' => array('4A', '5A', '6A', '7A'), 'format' => 'bracket', 'seeding_rounds' => 3, 'bracket_size' => 4),
);
$build = function ($divisions, $awards, $deadline = '') use ($base_fields) {
    $f = $base_fields;
    foreach ($divisions as $i => $d) {
        foreach ($d as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $j => $vv) {
                    $f["sbr_divisions[$i][$k][$j]"] = $vv;
                }
            } else {
                $f["sbr_divisions[$i][$k]"] = $v;
            }
        }
    }
    foreach ($awards as $k => $v) {
        $f["sbr_awards[$k]"] = $v;
    }
    $f['roster_deadline'] = $deadline;
    return $f;
};
$before_updated = $wpdb->get_var("SELECT updated_at FROM $EV WHERE id = 2");
// invalid: 3A in two divisions
$bad = $divs;
$bad[2]['classifications'][] = '3A';
$r = t_http('POST', admin_url('admin.php?page=scholars-bowl-add-event&event_id=2'), array('cookies' => $admin, 'body' => $build($bad, array('class_places' => 2, 'division_places' => 1, 'individual_places' => 3))));
t_ok(strpos($r['body'], '3A is in two divisions') !== false, 'admin: a classification in two divisions shows an error', 'HTTP ' . $r['code']);
t_ok($wpdb->get_var("SELECT tournament_config FROM $EV WHERE id = 2") === null || $wpdb->get_var("SELECT tournament_config FROM $EV WHERE id = 2") === '', 'admin: invalid structure is not saved');
sleep(1);
@file_put_contents($notify_log, '');
$r = t_http('POST', admin_url('admin.php?page=scholars-bowl-add-event&event_id=2'), array('cookies' => $admin, 'body' => $build($divs, array('class_places' => 3, 'division_places' => 1, 'individual_places' => 5), '')));
$row = $wpdb->get_row("SELECT * FROM $EV WHERE id = 2");
$cfg = json_decode((string) $row->tournament_config, true);
t_ok(is_array($cfg) && count($cfg['divisions']) === 3, 'admin: divisions saved as JSON', 'HTTP ' . $r['code'] . ' ' . substr(strip_tags($r['body']), 0, 0));
t_ok($cfg && $cfg['divisions'][0]['format'] === 'round_robin' && $cfg['divisions'][1]['bracket_size'] === 8 && $cfg['divisions'][1]['seeding_rounds'] === 4 && $cfg['divisions'][1]['third_place_game'] === true && $cfg['divisions'][2]['third_place_game'] === false, 'admin: format, bracket size, seeding rounds and third-place game saved');
t_ok($cfg && $cfg['awards'] === array('class_places' => 3, 'division_places' => 1, 'individual_places' => 5), 'admin: award settings saved');
t_ok($row->morning_classifications === 'Private,1A,2A,3A' && $row->afternoon_classifications === '4A,5A,6A,7A', 'admin: morning/afternoon classifications derived from divisions');
t_ok($row->updated_at !== $before_updated, 'admin: updated_at changes on save');
$notes = t_wait_notices(1);
t_ok(count($notes) >= 1 && $notes[0]['payload']['type'] === 'event.saved' && $notes[0]['payload']['event_id'] === 2 && $notes[0]['signature_valid'], 'webhook: event.saved delivered with a valid signature', wp_json_encode($notes));
// Move 3A to the afternoon: classification lists follow.
$moved = $divs;
$moved[1]['classifications'] = array('1A', '2A');
$moved[2]['classifications'] = array('3A', '4A', '5A', '6A', '7A');
t_http('POST', admin_url('admin.php?page=scholars-bowl-add-event&event_id=2'), array('cookies' => $admin, 'body' => $build($moved, array('class_places' => 3, 'division_places' => 1, 'individual_places' => 5), '2026-11-03T18:00')));
$row = $wpdb->get_row("SELECT * FROM $EV WHERE id = 2");
t_ok($row->morning_classifications === 'Private,1A,2A' && $row->afternoon_classifications === '3A,4A,5A,6A,7A', 'admin: moving a classification updates the session lists');
t_ok($row->roster_deadline === '2026-11-03 18:00:00', 'admin: roster deadline saved in site time', (string) $row->roster_deadline);
// restore defaults with blank deadline
t_http('POST', admin_url('admin.php?page=scholars-bowl-add-event&event_id=2'), array('cookies' => $admin, 'body' => $build($divs, array('class_places' => 2, 'division_places' => 1, 'individual_places' => 3), '')));
$row = $wpdb->get_row("SELECT * FROM $EV WHERE id = 2");
t_ok($row->roster_deadline === null && $row->morning_classifications === 'Private,1A,2A,3A', 'admin: blank roster deadline stored as NULL');
// New event gets default divisions and awards
$new = $build(SBR_Tournament::default_divisions(), SBR_Tournament::default_awards());
unset($new['event_id']);
$new['event_name'] = 'Test Event Created By Tests';
$new['event_slug'] = 'test-event-' . wp_rand(1000, 9999);
$new['submit'] = 'Create Event';
foreach (SBR_Tournament::default_divisions() as $i => $d) {
    $new["sbr_divisions[$i][third_place_game]"] = '1';
}
t_http('POST', admin_url('admin.php?page=scholars-bowl-add-event'), array('cookies' => $admin, 'body' => $new));
$created = $wpdb->get_row($wpdb->prepare("SELECT * FROM $EV WHERE event_slug = %s", $new['event_slug']));
$ccfg = $created ? json_decode($created->tournament_config, true) : null;
t_ok($created && $ccfg && wp_list_pluck($ccfg['divisions'], 'name') === array('Private', 'Public 1A–3A', 'Public 4A–7A') && $ccfg['awards']['individual_places'] === 3, 'admin: a new event is created with the default structure', $created ? $created->tournament_config : 'not created');
// Delete it through the events page: event.deleted
@file_put_contents($notify_log, '');
if ($created) {
    $url = add_query_arg('_wpnonce', wp_create_nonce('delete_event_' . $created->id), admin_url('admin.php?page=scholars-bowl-events&action=delete&event_id=' . $created->id));
    t_http('GET', $url, array('cookies' => $admin));
    $notes = t_wait_notices(1);
    t_ok(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $EV WHERE id = %d", $created->id)) && $notes && $notes[0]['payload']['type'] === 'event.deleted', 'admin: deleting an event sends event.deleted', wp_json_encode($notes));
}
$edit_page = t_http('GET', admin_url('admin.php?page=scholars-bowl-add-event&event_id=2'), array('cookies' => $admin));
t_ok(strpos($edit_page['body'], 'Tournament Structure') !== false && substr_count($edit_page['body'], 'class="sbr-division"') >= 3, 'admin: the event editor shows the divisions');

/* ===================================================================== */
t_section('Registration form and confirmation email');
$page = t_http('GET', home_url('/register/'));
t_ok($page['code'] === 200 && strpos($page['body'], 'Competing Students') !== false && substr_count($page['body'], 'name="students[') === 24, 'form shows 6 optional student rows (first, last, grade, id)');
@unlink($mail_log);
@file_put_contents($notify_log, '');
$with = t_submit(array('school_name' => 'Roster Test High', 'contact_email' => 'coach@rostertest.example', 'competing_students' => 4, 'grade_level' => '2A',
    'students[0][first_name]' => 'Ana', 'students[0][last_name]' => 'Lee', 'students[0][grade]' => '11',
    'students[1][first_name]' => 'Ben', 'students[1][last_name]' => "O'Neil", 'students[1][grade]' => '',
));
t_ok(!empty($with['json']['success']), 'submit with 2 students (check): accepted', $with['body']);
$rid = isset($with['json']['data']['registration_id']) ? intval($with['json']['data']['registration_id']) : 0;
$studs = SBR_Roster::get_students($rid);
t_ok(count($studs) === 2 && $studs[0]->first_name === 'Ana' && $studs[1]->last_name === "O'Neil" && $studs[1]->grade === null && intval($studs[0]->grade) === 11, 'students stored in their own table in order', wp_json_encode($studs));
$mails = t_mails();
$conf = null;
foreach ($mails as $m) {
    if ($m['to'] === 'coach@rostertest.example') {
        $conf = $m;
    }
}
t_ok($conf && strpos($conf['subject'], 'Registration Confirmation') === 0, 'confirmation email sent');
t_ok($conf && strpos($conf['message'], 'Roster status: Incomplete: 2 of 4 competing students named.') !== false, 'confirmation email states the roster is incomplete (2 of 4)', $conf ? $conf['message'] : '');
$link = $conf ? t_roster_link($conf['message']) : null;
t_ok((bool) $link, 'confirmation email includes the roster link', (string) $link);
$tokrow = $wpdb->get_row($wpdb->prepare("SELECT * FROM $TOK WHERE registration_id = %d", $rid));
$raw = $link ? substr(trim(parse_url($link, PHP_URL_PATH), '/'), strlen('team-roster/')) : '';
t_ok($tokrow && $tokrow->token_hash === hash('sha256', $raw) && strpos(wp_json_encode($wpdb->get_results("SELECT * FROM $TOK")), $raw) === false, 'only the SHA-256 of the link token is stored');
$notes = t_wait_notices(1);
t_ok($notes && $notes[0]['payload']['type'] === 'registration.saved' && $notes[0]['payload']['registration_id'] === $rid && $notes[0]['signature_valid'], 'webhook: registration.saved delivered', wp_json_encode($notes));
t_ok($with['elapsed'] < 6, 'registration request time with the app up: ' . round($with['elapsed'], 2) . 's');

$bad = t_submit(array('school_name' => 'Bad Roster High', 'contact_email' => 'bad@roster.example', 'competing_students' => 4, 'grade_level' => '2A', 'students[0][first_name]' => 'OnlyFirst'));
t_ok(empty($bad['json']['success']) && strpos((string) $bad['json']['data'], 'last name') !== false, 'a student row with only a first name is rejected with a message');
$tamper = t_submit(array('school_name' => 'Tamper High', 'contact_email' => 'tamper@x.example', 'competing_students' => 4, 'grade_level' => '9A'));
t_ok(empty($tamper['json']['success']), 'an unknown classification is rejected server-side', $tamper['body']);
$tamper = t_submit(array('school_name' => 'Tamper High', 'contact_email' => 'tamper@x.example', 'competing_students' => 0, 'grade_level' => '2A'));
t_ok(empty($tamper['json']['success']), 'zero competing students is rejected server-side');
$tamper = t_submit(array('school_name' => 'Tamper High', 'contact_email' => 'tamper@x.example', 'competing_students' => 4, 'grade_level' => '2A', 'payment_method' => '<b>x</b>'));
t_ok(empty($tamper['json']['success']), 'an unknown payment method is rejected server-side');
$spam_rows = $wpdb->get_col("SELECT form_data FROM {$P}scholars_bowl_spam_log");
t_ok(strpos(implode(' ', $spam_rows), 'OnlyFirst') === false, 'student names are not written to the spam log');
// Card charge guard: an already-paid registration can't be charged again.
$r = t_http('POST', $ajax, array('body' => array('action' => 'sbr_process_payment', 'nonce' => t_public_nonce(), 'registration_id' => 12, 'payment_method_id' => 'pm_card_visa')));
$j = json_decode($r['body'], true);
t_ok(empty($j['success']) && strpos((string) $j['data'], 'not waiting for a card payment') !== false && $wpdb->get_var("SELECT payment_status FROM $REG WHERE id = 12") === 'completed', 'a paid registration cannot be sent to Stripe again', $r['body']);

/* ===================================================================== */
t_section('Roster page');
$r = t_http('GET', $link);
t_ok($r['code'] === 200 && strpos($r['body'], 'Roster Test High') !== false && strpos($r['body'], 'High School Scholars Bowl 2026 (dev)') !== false, 'link opens the roster page with school and event', 'HTTP ' . $r['code']);
t_ok(strpos($r['body'], 'class="site-header"') !== false && strpos($r['body'], 'site-info') !== false, 'roster page is inside the site header and footer');
$hdrs = $r['headers'];
t_ok(isset($hdrs['x-robots-tag']) && strpos($hdrs['x-robots-tag'], 'noindex') !== false && isset($hdrs['referrer-policy']) && isset($hdrs['cache-control']) && strpos($hdrs['cache-control'], 'no-cache') !== false, 'roster page: noindex, no-referrer, no-cache');
$nonce = t_nonce_from($r['body'], 'sbr_roster_nonce');
t_ok((bool) $nonce, 'roster form has a nonce');
$ids = array();
foreach (SBR_Roster::get_students($rid) as $s) {
    $ids[] = intval($s->id);
}
@file_put_contents($notify_log, '');
$post = array('sbr_roster_nonce' => $nonce,
    'students[0][id]' => $ids[0], 'students[0][first_name]' => 'Ana', 'students[0][last_name]' => 'Lee-Park', 'students[0][grade]' => '11',
    'students[1][id]' => $ids[1], 'students[1][first_name]' => '', 'students[1][last_name]' => '', 'students[1][grade]' => '',
    'students[2][id]' => 0, 'students[2][first_name]' => 'Cara', 'students[2][last_name]' => 'Ng', 'students[2][grade]' => '12',
    'students[3][id]' => 0, 'students[3][first_name]' => 'Dev', 'students[3][last_name]' => 'Patel', 'students[3][grade]' => '9',
    'students[4][id]' => 0, 'students[4][first_name]' => 'Eli', 'students[4][last_name]' => 'Brooks', 'students[4][grade]' => '10',
);
$r = t_http('POST', $link, array('body' => $post));
t_ok($r['code'] === 303 && strpos((string) $r['headers']['location'], 'saved=1') !== false, 'saving redirects back with saved=1', 'HTTP ' . $r['code']);
$studs = SBR_Roster::get_students($rid);
t_ok(count($studs) === 4 && intval($studs[0]->id) === $ids[0] && $studs[0]->last_name === 'Lee-Park' && wp_list_pluck($studs, 'sort_order') == array(1, 2, 3, 4), 'roster saved: edit keeps the row id, blank row deleted, new rows added, order compacted', wp_json_encode($studs));
t_ok(SBR_Roster::is_complete($wpdb->get_row("SELECT * FROM $REG WHERE id = $rid"), $studs), 'roster is now complete (4 of 4)');
$notes = t_wait_notices(1);
t_ok($notes && $notes[0]['payload']['type'] === 'roster.saved' && $notes[0]['payload']['registration_id'] === $rid && $notes[0]['signature_valid'], 'webhook: roster.saved delivered', wp_json_encode($notes));
$r = t_http('GET', $link . '?saved=1');
t_ok(strpos($r['body'], 'Roster saved.') !== false && strpos($r['body'], 'Roster complete') !== false, 'page confirms the save and shows the roster complete');
$r = t_http('POST', $link, array('body' => array('sbr_roster_nonce' => $nonce, 'students[0][first_name]' => 'Solo')));
t_ok($r['code'] === 200 && strpos($r['body'], 'The roster was not saved') !== false && strpos($r['body'], 'aria-invalid="true"') !== false && count(SBR_Roster::get_students($rid)) === 4, 'an invalid row is reported on the page and nothing changes');
$r = t_http('POST', $link, array('body' => array('sbr_roster_nonce' => 'bad', 'students[0][first_name]' => 'X', 'students[0][last_name]' => 'Y')));
t_ok(strpos($r['body'], 'could not be saved') !== false && count(SBR_Roster::get_students($rid)) === 4, 'a bad nonce is refused');
// lock
$wpdb->update($EV, array('roster_deadline' => '2026-10-01 12:00:00'), array('id' => 2));
$r = t_http('GET', $link);
t_ok(strpos($r['body'], 'The roster locked on') !== false && strpos($r['body'], 'check-in') !== false && strpos($r['body'], 'name="sbr_roster_nonce"') === false, 'after the deadline the roster is read-only and says changes are made at check-in');
$r = t_http('POST', $link, array('body' => $post + array('students[5][first_name]' => 'Late', 'students[5][last_name]' => 'Entry')));
t_ok(count(SBR_Roster::get_students($rid)) === 4, 'after the deadline a save is refused');
$wpdb->update($EV, array('roster_deadline' => null), array('id' => 2));
// rate limit (an address of its own via X-Forwarded-For, which the plugin's client-IP logic honors)
$xff = array('X-Forwarded-For' => '1.2.3.' . wp_rand(10, 250));
$codes = array();
for ($i = 0; $i < 20; $i++) {
    $codes[] = t_http('GET', home_url('/team-roster/' . bin2hex(random_bytes(32)) . '/'), array('headers' => $xff))['code'];
}
t_ok(count(array_unique($codes)) === 1 && $codes[0] === 404, '20 bad links: 404 each');
$r21 = t_http('GET', home_url('/team-roster/' . bin2hex(random_bytes(32)) . '/'), array('headers' => $xff));
$rgood = t_http('GET', $link, array('headers' => $xff));
t_ok($r21['code'] === 429 && $rgood['code'] === 429, 'after 20 bad links in an hour the address gets 429, even with a good link');
t_ok(t_http('GET', $link)['code'] === 200, 'other addresses are not affected');
t_ok(t_http('GET', home_url('/team-roster/not-a-token/'), array('headers' => array('X-Forwarded-For' => '5.6.7.8')))['code'] === 404, 'a malformed token is a 404');
t_ok(t_http('GET', add_query_arg('sbr_roster_token', $raw, home_url('/')))['code'] === 200, 'the ?sbr_roster_token= form of the link also works');

/* ===================================================================== */
t_section('Admin: list, dialog, roster edit, resend link');
$list = t_http('GET', admin_url('admin.php?page=scholars-bowl-registrations&event_filter=2'), array('cookies' => $admin));
t_ok(strpos($list['body'], 'sbr-roster-badge is-complete') !== false && strpos($list['body'], 'sbr-roster-badge is-incomplete') !== false && strpos($list['body'], '>Roster<') !== false, 'registrations list shows roster status');
t_ok(strpos($list['body'], 'class="sbr-amount-cell"') !== false, 'Mark Paid finds the amount cell by class');
$adm_nonce = wp_create_nonce('sbr_admin_nonce');
$r = t_http('POST', $ajax, array('cookies' => $admin, 'body' => array('action' => 'sbr_get_registration_details', 'nonce' => $adm_nonce, 'registration_id' => 36)));
$j = json_decode($r['body'], true);
t_ok(!empty($j['success']) && strpos($j['data']['html'], 'sbr-admin-roster') !== false, 'the View dialog includes the roster panel');
@file_put_contents($notify_log, '');
$r = t_http('POST', $ajax, array('cookies' => $admin, 'body' => array('action' => 'sbr_admin_save_roster', 'nonce' => $adm_nonce, 'registration_id' => 36,
    'students[0][first_name]' => 'Gia', 'students[0][last_name]' => 'Hart', 'students[0][grade]' => '12')));
$j = json_decode($r['body'], true);
t_ok(!empty($j['success']) && count(SBR_Roster::get_students(36)) === 1, 'admin can save a roster (no deadline for admins)', $r['body']);
t_ok(count(t_wait_notices(1)) >= 1, 'webhook: admin roster save notifies');
$r = t_http('POST', $ajax, array('cookies' => $admin, 'body' => array('action' => 'sbr_admin_save_roster', 'nonce' => 'bad', 'registration_id' => 36)));
t_ok($r['code'] === 403 || strpos($r['body'], '-1') === 0, 'admin roster save needs a valid nonce');
$r = t_http('POST', $ajax, array('body' => array('action' => 'sbr_admin_save_roster', 'nonce' => $adm_nonce, 'registration_id' => 36)));
t_ok($r['code'] === 400 || $r['body'] === '0', 'admin roster save is not available to visitors');
@unlink($mail_log);
$r = t_http('POST', $ajax, array('cookies' => $admin, 'body' => array('action' => 'sbr_resend_roster_link', 'nonce' => $adm_nonce, 'registration_id' => $rid)));
$j = json_decode($r['body'], true);
$mails = t_mails();
$new_link = $mails ? t_roster_link($mails[0]['message']) : null;
t_ok(!empty($j['success']) && $new_link && $new_link === $j['data']['link'] && $new_link !== $link, 'Resend roster link emails a new link', $r['body']);
t_ok(t_http('GET', $link, array('headers' => array('X-Forwarded-For' => '9.9.9.9')))['code'] === 404 && t_http('GET', $new_link)['code'] === 200, 'the old link stops working; the new one works');
$link = $new_link;

/* ===================================================================== */
t_section('REST API');
$api_headers = $api_token ? array('X-SBR-Token' => $api_token) : array();
$rest_cookies = $api_token ? array() : $admin;
$rest_nonce = $api_token ? '' : wp_create_nonce('wp_rest');
if (!$api_token) {
    $api_headers['X-WP-Nonce'] = $rest_nonce;
}
$r = t_http('GET', rest_url('sbr/v1/events'));
t_ok($r['code'] === 401, 'events without a token: 401');
$r = t_http('GET', rest_url('sbr/v1/registrations?event_id=2'));
t_ok($r['code'] === 401, 'registrations without a token: 401');
$r = t_http('GET', rest_url('sbr/v1/events'), array('headers' => $api_headers, 'cookies' => $rest_cookies));
$j = json_decode($r['body'], true);
$e2 = null;
foreach ((array) $j['events'] as $e) {
    if ($e['id'] === 2) {
        $e2 = $e;
    }
}
$want_keys = array('id', 'name', 'slug', 'date', 'status', 'morning_classifications', 'afternoon_classifications', 'morning_session_time', 'afternoon_session_time', 'updated_at', 'timezone', 'roster_deadline', 'sessions', 'divisions', 'awards');
t_ok($e2 && array_keys($e2) === $want_keys, 'event keys: existing ones, then updated_at, timezone, roster_deadline, sessions, divisions, awards', $e2 ? implode(',', array_keys($e2)) : $r['body']);
t_ok($e2 && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $e2['updated_at']) && $e2['timezone'] === 'America/Chicago' && $e2['roster_deadline'] === '2026-11-04T23:59:59-06:00', 'updated_at (UTC Z), timezone, roster_deadline (site offset)', $e2 ? wp_json_encode(array($e2['updated_at'], $e2['timezone'], $e2['roster_deadline'])) : '');
t_ok($e2 && $e2['sessions'] === array(
    array('key' => 'morning', 'label' => 'Morning', 'start_time' => '08:30', 'classifications' => array('Private', '1A', '2A', '3A')),
    array('key' => 'afternoon', 'label' => 'Afternoon', 'start_time' => '12:15', 'classifications' => array('4A', '5A', '6A', '7A')),
), 'sessions match the spec');
t_ok($e2 && $e2['divisions'][0] === array('key' => 'private', 'name' => 'Private', 'session' => 'morning', 'classifications' => array('Private'), 'format' => 'round_robin', 'seeding_rounds' => 3, 'bracket_size' => 4, 'third_place_game' => false), 'division shape and types', $e2 ? wp_json_encode($e2['divisions'][0]) : '');
t_ok($e2 && $e2['awards'] === array('class_places' => 2, 'division_places' => 1, 'individual_places' => 3), 'awards');
$e1 = null;
foreach ((array) $j['events'] as $e) {
    if ($e['id'] === 1) {
        $e1 = $e;
    }
}
t_ok($e1 && count($e1['divisions']) === 3 && $e1['divisions'][1]['key'] === 'public-1a-3a' && $e1['awards']['individual_places'] === 3, 'a pre-1.1.0 event gets synthesized default divisions');
t_ok(strpos($r['body'], 'sk_test_') === false && strpos($r['body'], 'stripe') === false, 'no Stripe keys in the events output');
$r = t_http('GET', rest_url('sbr/v1/registrations?event_id=2'), array('headers' => $api_headers, 'cookies' => $rest_cookies));
$j = json_decode($r['body'], true);
$mine = null;
$r36 = null;
foreach ((array) $j['registrations'] as $x) {
    if ($x['id'] === $rid) {
        $mine = $x;
    }
    if ($x['id'] === 36) {
        $r36 = $x;
    }
}
t_ok($mine && array_slice(array_keys($mine), -3) === array('students', 'roster_complete', 'roster_updated_at'), 'registration gains students, roster_complete, roster_updated_at at the end');
t_ok($mine && count($mine['students']) === 4 && $mine['students'][0] === array('id' => $ids[0], 'first_name' => 'Ana', 'last_name' => 'Lee-Park', 'grade' => '11', 'sort_order' => 1), 'student shape and types (grade is a string)', $mine ? wp_json_encode($mine['students'][0]) : '');
t_ok($mine && $mine['roster_complete'] === true && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $mine['roster_updated_at']), 'roster_complete true; roster_updated_at UTC');
t_ok($r36 && $r36['roster_complete'] === false && count($r36['students']) === 1, 'incomplete roster reported (1 of 6)');
$none = null;
foreach ((array) $j['registrations'] as $x) {
    if ($x['id'] === 49) {
        $none = $x;
    }
}
t_ok($none && $none['students'] === array() && $none['roster_complete'] === false && $none['roster_updated_at'] === null, 'a registration with no roster: [] / false / null');
t_ok(strpos($r['body'], 'total_amount') === false && strpos($r['body'], 'amount_paid') === false && strpos($r['body'], 'payment_notes') === false, 'still no payment amounts or notes');

/* ===================================================================== */
t_section('CSV export');
$csv_url = admin_url('admin.php?page=scholars-bowl-registrations&action=export_csv&event_filter=2');
$r = t_http('GET', $csv_url, array('cookies' => $admin));
t_ok($r['code'] === 403 || strpos($r['body'], 'ID,') !== 0, 'export without a nonce is refused');
$r = t_http('GET', add_query_arg('_wpnonce', wp_create_nonce('sbr_export_csv'), $csv_url), array('cookies' => $admin));
$lines = array_map('str_getcsv', array_filter(explode("\n", $r['body'])));
$head = $lines ? $lines[0] : array();
$old = array('ID', 'School Name', 'Contact Name', 'Contact Email', 'Contact Phone', 'Dietary Restrictions', 'Competing Students', 'Additional Students', 'Additional Adults', 'Coaches', 'Grade Level', 'Session Time', 'Total Amount', 'Amount Paid', 'Balance Due', 'Payment Status', 'Payment Method', 'Registration Status', 'Registration Date', 'Payment Notes', 'Classification', 'Event ID', 'Event Name');
t_ok(strpos((string) $r['headers']['content-type'], 'text/csv') === 0 && array_slice($head, 0, 23) === $old, 'CSV keeps the 1.0.7 columns in order', implode('|', $head));
t_ok(array_slice($head, 23) === SBR_Roster::csv_headers() && count($head) === 43, 'CSV appends Roster Status, Students Named, and 6 x first/last/grade');
$mine_row = null;
foreach ($lines as $l) {
    if ($l[0] === (string) $rid) {
        $mine_row = $l;
    }
}
t_ok($mine_row && $mine_row[23] === 'Complete' && $mine_row[24] === '4' && $mine_row[25] === 'Ana' && $mine_row[26] === 'Lee-Park' && $mine_row[27] === '11', 'CSV roster values', $mine_row ? implode('|', array_slice($mine_row, 23, 6)) : '');

/* ===================================================================== */
t_section('Webhook: app down does not break the request');
update_option('sbr_scoring_url', 'http://localhost:8099', false);
$down = t_submit(array('school_name' => 'App Down Academy', 'contact_email' => 'down@app.example', 'competing_students' => 3, 'grade_level' => '1A'));
t_ok(!empty($down['json']['success']), 'registration succeeds while the scoring app is unreachable');
t_ok($down['elapsed'] < 4.5, 'and returns within the 3 s notification timeout: ' . round($down['elapsed'], 2) . 's');
sleep(1);
$last = get_option('sbr_scoring_notify_last');
wp_cache_delete('sbr_scoring_notify_last', 'options');
$last = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'sbr_scoring_notify_last'");
$last = maybe_unserialize($last);
t_ok(is_array($last) && $last['ok'] === false, 'the failure is recorded for the API Access page', wp_json_encode($last));
update_option('sbr_scoring_url', 'http://localhost:8091', false);
update_option('sbr_scoring_notify', 0, false);
@file_put_contents($notify_log, '');
t_submit(array('school_name' => 'Notify Off Academy', 'contact_email' => 'off@app.example', 'competing_students' => 3, 'grade_level' => '1A'));
sleep(1);
t_ok(count(t_notices()) === 0, 'nothing is sent when notifications are switched off');
update_option('sbr_scoring_notify', 1, false);
// cancellation by admin -> registration.saved
@file_put_contents($notify_log, '');
$r = t_http('POST', admin_url('admin.php?page=scholars-bowl-registrations'), array('cookies' => $admin, 'body' => array('action' => 'update_status', 'sbr_nonce' => wp_create_nonce('sbr_update_status'), '_wp_http_referer' => '/wp-admin/admin.php?page=scholars-bowl-registrations', 'registration_id' => 49, 'registration_status' => 'cancelled', 'status_notes' => 'test cancel')));
$notes = t_wait_notices(1);
t_ok($notes && $notes[0]['payload']['type'] === 'registration.saved' && $notes[0]['payload']['registration_id'] === 49, 'cancelling a registration sends registration.saved', wp_json_encode($notes));
$wpdb->update($REG, array('registration_status' => 'confirmed'), array('id' => 49));

/* ===================================================================== */
t_section('Reminder emails (simulated dates)');
remove_all_filters('pre_wp_mail');
$sent_mail = array();
$mail_ok = true;
add_filter('pre_wp_mail', function ($null, $atts) use (&$sent_mail, &$mail_ok) {
    $sent_mail[] = $atts;
    return $mail_ok;
}, 10, 2);
$at = function ($local) {
    $ts = (new DateTimeImmutable($local, wp_timezone()))->getTimestamp();
    remove_all_filters('sbr_roster_now');
    add_filter('sbr_roster_now', function () use ($ts) {
        return $ts;
    });
};
$wpdb->query("UPDATE $STATUS SET reminder_7d_sent_at = NULL, reminder_1d_sent_at = NULL");
$wpdb->update($EV, array('event_date' => '2026-11-05'), array('id' => 2));
$created_now = array_map('intval', $wpdb->get_col("SELECT id FROM $REG WHERE event_id = 2 AND id > 49"));
$wpdb->update($REG, array('registration_status' => 'cancelled'), array('id' => 47));
$run = function () use (&$sent_mail) {
    $sent_mail = array();
    $r = SBR_Roster::run_reminders();
    sort($r);
    return $r;
};
$at('2026-10-28 10:00');
t_ok($run() === array(), '8 days before: nothing sent');
$at('2026-10-29 09:00');
$r = $run();
$ids7 = wp_list_pluck($r, 'registration_id');
sort($ids7);
t_ok($ids7 === array(36, 40, 44, 45, 46, 49) && count(array_unique(wp_list_pluck($r, 'kind'))) === 1 && $r[0]['kind'] === '7d', '7 days before: 7-day reminder to incomplete, non-cancelled registrations made before the window', wp_json_encode($r));
t_ok(!in_array(47, $ids7, true), 'cancelled registration skipped');
t_ok(!array_intersect($ids7, $created_now), 'registrations made after the 7-day window opened are skipped (their confirmation had the link)');
$m = $sent_mail ? $sent_mail[0] : null;
t_ok($m && strpos($m['subject'], 'Reminder: team roster') === 0 && t_roster_link($m['message']) && strpos($m['message'], 'in one week') !== false, 'reminder email has the subject, a roster link and the timing', $m ? $m['message'] : '');
t_ok(t_http('GET', t_roster_link($m['message']))['code'] === 200, 'the reminder link works');
$at('2026-10-29 10:00');
t_ok($run() === array(), 'the next hourly run sends nothing again (each reminder goes once)');
SBR_Roster::save(46, SBR_Roster::parse_rows(array(
    array('first_name' => 'A', 'last_name' => 'One'), array('first_name' => 'B', 'last_name' => 'Two'), array('first_name' => 'C', 'last_name' => 'Three'),
    array('first_name' => 'D', 'last_name' => 'Four'), array('first_name' => 'E', 'last_name' => 'Five'), array('first_name' => 'F', 'last_name' => 'Six'),
))['students'], false);
$at('2026-11-04 08:00');
$r = $run();
$ids1 = wp_list_pluck($r, 'registration_id');
sort($ids1);
// Registrations made by this test (Oct 30) are before the 1-day window, so they get it too, unless complete.
$want1 = array(36, 40, 44, 45, 49);
foreach ($created_now as $cid) {
    $creg = $wpdb->get_row("SELECT * FROM $REG WHERE id = $cid");
    if ($creg && !SBR_Roster::is_complete($creg, SBR_Roster::get_students($cid)) && $creg->registration_status !== 'cancelled') {
        $want1[] = $cid;
    }
}
sort($want1);
t_ok($ids1 === $want1 && !in_array(46, $ids1, true) && $r[0]['kind'] === '1d', '1 day before: 1-day reminder; a roster completed meanwhile (46) is skipped', wp_json_encode($r));
t_ok($sent_mail && strpos($sent_mail[0]['message'], 'tomorrow') !== false, '1-day reminder says tomorrow');
t_ok($run() === array(), 'run again: nothing');
// missed windows: only the 1-day one, only before the team's session starts
$wpdb->query("UPDATE $STATUS SET reminder_7d_sent_at = NULL, reminder_1d_sent_at = NULL");
$at('2026-11-05 09:00');
$r = $run();
t_ok(wp_list_pluck($r, 'registration_id') === array(36) && $r[0]['kind'] === '1d', 'job missed both windows: on event morning only the afternoon team (session not started) gets the 1-day reminder', wp_json_encode($r));
t_ok($sent_mail && strpos($sent_mail[0]['message'], 'check-in') !== false, 'and, the roster being locked, it says to bring names to check-in');
$at('2026-11-05 12:30');
t_ok($run() === array(), 'after the event has started: nothing');
// a mail failure releases the claim so the next run retries
$wpdb->query("UPDATE $STATUS SET reminder_7d_sent_at = NULL, reminder_1d_sent_at = NULL");
$mail_ok = false;
$at('2026-10-29 09:00');
$run();
t_ok(intval($wpdb->get_var("SELECT COUNT(*) FROM $STATUS WHERE reminder_7d_sent_at IS NOT NULL")) === 0, 'a failed send is not marked as sent');
$mail_ok = true;
t_ok(count($run()) === 5, 'and is retried on the next run (5: registration 46 is complete now)');
remove_all_filters('sbr_roster_now');
$wpdb->update($REG, array('registration_status' => 'cancelled'), array('id' => 47));

/* ===================================================================== */
$t = $GLOBALS['sbr_t'];
echo "\n" . ($t['fail'] ? 'FAILED' : 'PASSED') . ": {$t['pass']} passed, {$t['fail']} failed\n";
if ($t['fail']) {
    exit(1);
}
