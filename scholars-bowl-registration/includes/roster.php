<?php
/**
 * Team rosters (added in 1.1.0).
 *
 * Coaches may name up to six competing students on the registration form, or later
 * through a private link (https://site/team-roster/<token>/). Roster data lives in its own
 * tables and is never read by pricing, Stripe, totals, or invoices.
 *
 *   {prefix}scholars_bowl_students       one row per named student
 *   {prefix}scholars_bowl_roster_tokens  SHA-256 hashes of roster links (the link itself is never stored)
 *   {prefix}scholars_bowl_roster_status  when the roster was last saved; when reminders were sent
 *
 * A roster is complete when the number of named students (first and last name) is at
 * least min(competing_students, 6), and at least 1.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBR_Roster {
    const MAX_STUDENTS = 6;
    const GRADES = array(6, 7, 8, 9, 10, 11, 12);
    const FAIL_LIMIT = 20;            // bad roster links per IP per hour
    const QUERY_VAR = 'sbr_roster_token';
    const CRON_HOOK = 'sbr_roster_reminders';

    /** Errors and posted rows when a roster form submission fails validation. */
    private static $page_state = array();

    public static function init() {
        add_action('init', array(__CLASS__, 'register_rewrite'), 20);
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'maybe_render_page'), 5);
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_reminders'));
        add_action('init', array(__CLASS__, 'schedule_cron'), 30);
        add_action('wp_ajax_sbr_admin_save_roster', array(__CLASS__, 'ajax_admin_save'));
        add_action('wp_ajax_sbr_resend_roster_link', array(__CLASS__, 'ajax_resend_link'));
    }

    /* ------------------------------------------------------------------ basics */

    public static function now() {
        $ts = intval(apply_filters('sbr_roster_now', time()));
        return (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone());
    }

    private static function utc_now() {
        return gmdate('Y-m-d H:i:s', self::now()->getTimestamp());
    }

    public static function iso_utc($mysql_utc) {
        if (empty($mysql_utc) || strpos((string) $mysql_utc, '0000') === 0) {
            return null;
        }
        $ts = strtotime($mysql_utc . ' UTC');
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }

    public static function register_rewrite() {
        add_rewrite_rule('^team-roster/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        if (get_option('sbr_flush_rewrite')) {
            delete_option('sbr_flush_rewrite');
            flush_rewrite_rules(false);
        }
    }

    public static function query_vars($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function schedule_cron() {
        if (SBR_Schema::roster_ready() && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function url($token) {
        if (get_option('permalink_structure')) {
            return home_url('/team-roster/' . rawurlencode($token) . '/');
        }
        return add_query_arg(self::QUERY_VAR, rawurlencode($token), home_url('/'));
    }

    private static function registration($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SBR_Schema::table('registrations') . ' WHERE id = %d', $id));
    }

    private static function event($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SBR_Schema::table('events') . ' WHERE id = %d', $id));
    }

    /* ------------------------------------------------------------------ tokens */

    /**
     * Create a new roster link for a registration and return the token (64 hex characters).
     * Only its SHA-256 hash is stored. With $revoke_others, earlier links stop working.
     */
    public static function issue_token($registration_id, $purpose = 'email', $revoke_others = false) {
        global $wpdb;
        if (!SBR_Schema::roster_ready()) {
            return false;
        }
        $table = SBR_Schema::table('roster_tokens');
        if ($revoke_others) {
            $wpdb->query($wpdb->prepare("UPDATE $table SET revoked_at = %s WHERE registration_id = %d AND revoked_at IS NULL", self::utc_now(), $registration_id));
        }
        $token = bin2hex(random_bytes(32));
        $ok = $wpdb->insert($table, array(
            'registration_id' => intval($registration_id),
            'token_hash' => hash('sha256', $token),
            'purpose' => substr(sanitize_key($purpose), 0, 20),
            'created_at' => self::utc_now(),
        ), array('%d', '%s', '%s', '%s'));
        return $ok ? $token : false;
    }

    /** The registration a roster link belongs to, or null. */
    public static function registration_for_token($token) {
        global $wpdb;
        $token = (string) $token;
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !SBR_Schema::roster_ready()) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT registration_id, token_hash FROM ' . SBR_Schema::table('roster_tokens') . ' WHERE token_hash = %s AND revoked_at IS NULL',
            $hash
        ));
        if (!$row || !hash_equals((string) $row->token_hash, $hash)) {
            return null;
        }
        return self::registration(intval($row->registration_id));
    }

    /* ------------------------------------------------------------------ students */

    public static function get_students($registration_id) {
        global $wpdb;
        if (!SBR_Schema::roster_ready()) {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, registration_id, sort_order, first_name, last_name, grade FROM ' . SBR_Schema::table('students') . ' WHERE registration_id = %d ORDER BY sort_order, id',
            $registration_id
        ));
    }

    /** Students for many registrations at once: array(registration_id => rows). */
    public static function students_by_registration($registration_ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', (array) $registration_ids)));
        $out = array_fill_keys($ids, array());
        if (!$ids || !SBR_Schema::roster_ready()) {
            return $out;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, registration_id, sort_order, first_name, last_name, grade FROM ' . SBR_Schema::table('students') . " WHERE registration_id IN ($in) ORDER BY registration_id, sort_order, id",
                $chunk
            ));
            foreach ((array) $rows as $r) {
                $out[intval($r->registration_id)][] = $r;
            }
        }
        return $out;
    }

    /** roster_updated_at (UTC, MySQL format) for many registrations: array(registration_id => string|null). */
    public static function status_by_registration($registration_ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', (array) $registration_ids)));
        $out = array();
        if (!$ids || !SBR_Schema::roster_ready()) {
            return $out;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT registration_id, roster_updated_at, reminder_7d_sent_at, reminder_1d_sent_at FROM ' . SBR_Schema::table('roster_status') . " WHERE registration_id IN ($in)",
                $chunk
            ));
            foreach ((array) $rows as $r) {
                $out[intval($r->registration_id)] = $r;
            }
        }
        return $out;
    }

    public static function named($students) {
        $n = 0;
        foreach ((array) $students as $s) {
            if (trim((string) $s->first_name) !== '' && trim((string) $s->last_name) !== '') {
                $n++;
            }
        }
        return $n;
    }

    public static function required($registration) {
        $competing = intval(is_object($registration) ? $registration->competing_students : $registration);
        return max(1, min($competing, self::MAX_STUDENTS));
    }

    public static function is_complete($registration, $students) {
        return self::named($students) >= self::required($registration);
    }

    /**
     * Validate submitted rows (from the registration form, the roster page, or the admin).
     * Blank rows are dropped. A row with anything in it needs a first and last name;
     * grade is optional but must be 6–12.
     *
     * @return array array('students' => array of rows, 'errors' => array(field => message))
     */
    public static function parse_rows($rows) {
        $students = array();
        $errors = array();
        $rows = is_array($rows) ? array_values($rows) : array();
        foreach (array_slice($rows, 0, self::MAX_STUDENTS) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $n = $i + 1;
            $first = isset($row['first_name']) ? trim(sanitize_text_field((string) $row['first_name'])) : '';
            $last = isset($row['last_name']) ? trim(sanitize_text_field((string) $row['last_name'])) : '';
            $grade_raw = isset($row['grade']) ? trim((string) $row['grade']) : '';
            $id = isset($row['id']) ? absint($row['id']) : 0;
            if ($first === '' && $last === '' && $grade_raw === '') {
                continue;
            }
            if ($first === '') {
                $errors["students_{$i}_first_name"] = "Student $n: enter a first name, or clear the row.";
            }
            if ($last === '') {
                $errors["students_{$i}_last_name"] = "Student $n: enter a last name, or clear the row.";
            }
            if (mb_strlen($first) > 100) {
                $errors["students_{$i}_first_name"] = "Student $n: the first name is too long.";
            }
            if (mb_strlen($last) > 100) {
                $errors["students_{$i}_last_name"] = "Student $n: the last name is too long.";
            }
            $grade = null;
            if ($grade_raw !== '') {
                if (!ctype_digit($grade_raw) || !in_array(intval($grade_raw), self::GRADES, true)) {
                    $errors["students_{$i}_grade"] = "Student $n: grade must be 6 through 12.";
                } else {
                    $grade = intval($grade_raw);
                }
            }
            $students[] = array('id' => $id, 'first_name' => $first, 'last_name' => $last, 'grade' => $grade, 'row' => $i);
        }
        return array('students' => $students, 'errors' => $errors);
    }

    /**
     * Replace a registration's roster with $students (output of parse_rows). Existing rows
     * keep their ids when they are submitted back with them.
     */
    public static function save($registration_id, $students, $notify = true) {
        global $wpdb;
        if (!SBR_Schema::roster_ready()) {
            return false;
        }
        $table = SBR_Schema::table('students');
        $registration_id = intval($registration_id);
        $now = self::utc_now();
        $existing = array();
        foreach (self::get_students($registration_id) as $s) {
            $existing[intval($s->id)] = $s;
        }
        $kept = array();
        $order = 0;
        foreach (array_slice((array) $students, 0, self::MAX_STUDENTS) as $s) {
            $order++;
            $data = array('sort_order' => $order, 'first_name' => $s['first_name'], 'last_name' => $s['last_name'], 'grade' => $s['grade'], 'updated_at' => $now);
            if (!empty($s['id']) && isset($existing[$s['id']]) && !isset($kept[$s['id']])) {
                $old = $existing[$s['id']];
                $changed = intval($old->sort_order) !== $order || $old->first_name !== $s['first_name'] || $old->last_name !== $s['last_name'] || (string) $old->grade !== (string) $s['grade'];
                if ($changed) {
                    // wpdb writes a null value as SQL NULL.
                    $wpdb->update($table, $data, array('id' => intval($s['id']), 'registration_id' => $registration_id));
                }
                $kept[$s['id']] = true;
            } else {
                $wpdb->insert($table, $data + array('registration_id' => $registration_id, 'created_at' => $now));
            }
        }
        foreach (array_keys($existing) as $id) {
            if (!isset($kept[$id])) {
                $wpdb->delete($table, array('id' => $id, 'registration_id' => $registration_id), array('%d', '%d'));
            }
        }
        self::touch_status($registration_id, array('roster_updated_at' => $now));
        if ($notify && class_exists('SBR_Webhook')) {
            SBR_Webhook::notify('roster.saved', 0, $registration_id);
        }
        return true;
    }

    private static function touch_status($registration_id, $fields) {
        global $wpdb;
        $table = SBR_Schema::table('roster_status');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT registration_id FROM $table WHERE registration_id = %d", $registration_id));
        if ($exists === null) {
            $wpdb->insert($table, array('registration_id' => intval($registration_id)) + $fields);
        } elseif ($fields) {
            $wpdb->update($table, $fields, array('registration_id' => intval($registration_id)));
        }
    }

    /* ------------------------------------------------------------------ locking */

    public static function deadline($event) {
        return SBR_Tournament::roster_deadline($event);
    }

    public static function is_locked($event, $registration = null) {
        if ($registration && strtolower((string) $registration->registration_status) === 'cancelled') {
            return true;
        }
        $deadline = self::deadline($event);
        return $deadline ? self::now() > $deadline : false;
    }

    public static function format_deadline($event) {
        $d = self::deadline($event);
        if (!$d) {
            return '';
        }
        return wp_date('l, F j, Y \a\t g:i a T', $d->getTimestamp());
    }

    /* ------------------------------------------------------------------ rate limit */

    private static function client_key() {
        // Same client address the registration form's spam limit uses.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (isset($GLOBALS['sbr_instance']) && $GLOBALS['sbr_instance'] instanceof ScholarsBowlRegistration) {
            $ip = $GLOBALS['sbr_instance']->get_client_ip();
        }
        return 'sbr_roster_fail_' . md5($ip);
    }

    public static function too_many_failures() {
        $data = get_transient(self::client_key());
        return is_array($data) && intval($data['count']) >= self::FAIL_LIMIT;
    }

    public static function record_failure() {
        $key = self::client_key();
        $data = get_transient($key);
        if (!is_array($data) || empty($data['start']) || time() - intval($data['start']) >= HOUR_IN_SECONDS) {
            $data = array('count' => 0, 'start' => time());
        }
        $data['count']++;
        set_transient($key, $data, max(60, HOUR_IN_SECONDS - (time() - intval($data['start']))));
    }

    /* ------------------------------------------------------------------ public page */

    public static function maybe_render_page() {
        $token = get_query_var(self::QUERY_VAR);
        if ($token === '' || $token === null) {
            if (!isset($_GET[self::QUERY_VAR])) {
                return;
            }
            $token = wp_unslash($_GET[self::QUERY_VAR]);
        }
        $token = strtolower(trim((string) $token));

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: no-referrer');
        }

        if (self::too_many_failures()) {
            self::output_page(429, 'Too many attempts', self::message_html('Too many attempts', 'This device has tried too many roster links that did not work. Please wait an hour and try again, or contact the tournament organizer.', 'error'));
        }
        $registration = self::registration_for_token($token);
        if (!$registration) {
            self::record_failure();
            self::output_page(404, 'Roster link not found', self::message_html('This roster link does not work', 'The link may be incomplete, or a newer link may have replaced it. Use the most recent roster link from your email, or ask the tournament organizer to send a new one.', 'error'));
        }
        $event = self::event($registration->event_id);
        if (!$event) {
            self::output_page(404, 'Roster link not found', self::message_html('This event is no longer available', 'Please contact the tournament organizer.', 'error'));
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['sbr_roster_nonce'])) {
            self::handle_post($registration, $event, $token);
        }
        self::output_page(200, 'Team roster', self::page_html($registration, $event, $token));
    }

    private static function handle_post($registration, $event, $token) {
        $nonce = sanitize_text_field(wp_unslash($_POST['sbr_roster_nonce']));
        if (!wp_verify_nonce($nonce, 'sbr_roster_save_' . $registration->id)) {
            self::$page_state = array('errors' => array('form' => 'This page was open too long and could not be saved. Your changes are shown below; please press Save again.'), 'rows' => self::posted_rows());
            return;
        }
        if (self::is_locked($event, $registration)) {
            self::$page_state = array('errors' => array('form' => 'The roster is locked, so changes were not saved. Roster changes are made at check-in.'), 'rows' => null);
            return;
        }
        $rows = self::posted_rows();
        $parsed = self::parse_rows($rows);
        if ($parsed['errors']) {
            self::$page_state = array('errors' => $parsed['errors'], 'rows' => $rows);
            return;
        }
        self::save($registration->id, $parsed['students']);
        wp_safe_redirect(add_query_arg('saved', '1', self::url($token)), 303);
        exit;
    }

    private static function posted_rows() {
        $rows = isset($_POST['students']) && is_array($_POST['students']) ? wp_unslash($_POST['students']) : array();
        $out = array();
        for ($i = 0; $i < self::MAX_STUDENTS; $i++) {
            $r = isset($rows[$i]) && is_array($rows[$i]) ? $rows[$i] : array();
            $out[$i] = array(
                'id' => isset($r['id']) ? absint($r['id']) : 0,
                'first_name' => isset($r['first_name']) ? (string) $r['first_name'] : '',
                'last_name' => isset($r['last_name']) ? (string) $r['last_name'] : '',
                'grade' => isset($r['grade']) ? (string) $r['grade'] : '',
            );
        }
        return $out;
    }

    private static function output_page($status, $title, $html) {
        status_header($status);
        add_filter('pre_get_document_title', function () use ($title) {
            return $title . ' – ' . get_bloginfo('name');
        }, 99);
        add_filter('wp_robots', 'wp_robots_no_robots');
        add_filter('body_class', function ($c) {
            $c[] = 'sbr-roster-page';
            return $c;
        });
        get_header();
        $content_classes = function_exists('generate_do_element_classes');
        echo '<div id="primary" ';
        if ($content_classes) {
            generate_do_element_classes('content');
        } else {
            echo 'class="content-area"';
        }
        echo '><main id="main" ';
        if ($content_classes) {
            generate_do_element_classes('main');
        } else {
            echo 'class="site-main"';
        }
        echo '><div class="inside-article sbr-roster-article">' . $html . '</div></main></div>';
        get_footer();
        exit;
    }

    private static function message_html($heading, $text, $kind) {
        return '<div class="sbr-scope sbr-roster"><div class="sbr-card"><h1 class="sbr-page-title">' . esc_html($heading) . '</h1>'
            . '<div class="sbr-alert sbr-alert-' . esc_attr($kind) . '" role="' . ($kind === 'error' ? 'alert' : 'status') . '"><p>' . esc_html($text) . '</p></div></div></div>';
    }

    private static function session_label($event, $registration) {
        $session = $registration->session_time === 'afternoon' ? 'afternoon' : 'morning';
        $time = $session === 'afternoon' ? $event->afternoon_session_time : $event->morning_session_time;
        return ucfirst($session) . ($time ? ' (' . $time . ')' : '');
    }

    private static function page_html($registration, $event, $token) {
        $students = self::get_students($registration->id);
        $locked = self::is_locked($event, $registration);
        $cancelled = strtolower((string) $registration->registration_status) === 'cancelled';
        $named = self::named($students);
        $required = self::required($registration);
        $complete = $named >= $required;
        $state = self::$page_state;
        $errors = isset($state['errors']) ? $state['errors'] : array();
        $rows = isset($state['rows']) && is_array($state['rows']) ? $state['rows'] : null;
        if ($rows === null) {
            $rows = array();
            foreach (array_values($students) as $i => $s) {
                $rows[$i] = array('id' => $s->id, 'first_name' => $s->first_name, 'last_name' => $s->last_name, 'grade' => $s->grade === null ? '' : (string) $s->grade);
            }
        }
        $classification = !empty($registration->grade_level) ? $registration->grade_level : (isset($registration->school_classification) ? $registration->school_classification : '');
        $deadline_text = self::format_deadline($event);

        ob_start();
        ?>
        <div class="sbr-scope sbr-roster">
            <div class="sbr-card sbr-roster-head">
                <p class="sbr-eyebrow"><?php echo esc_html($event->event_name); ?></p>
                <h1 class="sbr-page-title">Team roster: <?php echo esc_html($registration->school_name); ?></h1>
                <dl class="sbr-facts">
                    <div><dt>Date</dt><dd><?php echo esc_html(date_i18n('l, F j, Y', strtotime($event->event_date))); ?></dd></div>
                    <div><dt>Session</dt><dd><?php echo esc_html(self::session_label($event, $registration)); ?></dd></div>
                    <?php if ($classification !== ''): ?><div><dt>Classification</dt><dd><?php echo esc_html($classification); ?></dd></div><?php endif; ?>
                    <div><dt>Competing students</dt><dd class="sbr-num"><?php echo intval($registration->competing_students); ?></dd></div>
                </dl>
                <p class="sbr-status-line">
                    <?php if ($complete): ?>
                        <span class="sbr-badge sbr-badge-ok"><span aria-hidden="true">✓</span> Roster complete</span>
                    <?php else: ?>
                        <span class="sbr-badge sbr-badge-warn"><span aria-hidden="true">!</span> Roster incomplete</span>
                    <?php endif; ?>
                    <span class="sbr-muted"><?php echo esc_html(sprintf('%d of %d competing student%s named', $named, $required, $required === 1 ? '' : 's')); ?></span>
                </p>
            </div>

            <?php if (isset($_GET['saved']) && empty($errors)): ?>
                <div class="sbr-alert sbr-alert-success" role="status" tabindex="-1" id="sbr-roster-saved"><p><strong>Roster saved.</strong> Thank you. You can come back to this page to make changes until the deadline.</p></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="sbr-alert sbr-alert-error" role="alert" id="sbr-roster-errors" tabindex="-1">
                    <p><strong>The roster was not saved.</strong></p>
                    <ul>
                        <?php foreach ($errors as $field => $msg): ?>
                            <li><?php if ($field !== 'form'): ?><a href="#<?php echo esc_attr($field); ?>"><?php echo esc_html($msg); ?></a><?php else: echo esc_html($msg); endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($locked): ?>
                <div class="sbr-card">
                    <h2>Competing students</h2>
                    <div class="sbr-alert sbr-alert-info" role="status"><p>
                        <?php if ($cancelled): ?>
                            This registration has been cancelled, so the roster can no longer be changed.
                        <?php else: ?>
                            The roster locked on <?php echo esc_html($deadline_text); ?>. Changes are made at check-in on the day of the tournament.
                        <?php endif; ?>
                    </p></div>
                    <?php if ($students): ?>
                        <table class="sbr-table">
                            <caption class="sbr-sr-only">Competing students</caption>
                            <thead><tr><th scope="col">#</th><th scope="col">First name</th><th scope="col">Last name</th><th scope="col">Grade</th></tr></thead>
                            <tbody>
                            <?php foreach (array_values($students) as $i => $s): ?>
                                <tr><td class="sbr-num"><?php echo intval($i + 1); ?></td><td><?php echo esc_html($s->first_name); ?></td><td><?php echo esc_html($s->last_name); ?></td><td class="sbr-num"><?php echo $s->grade === null ? '<span class="sbr-muted">—</span>' : intval($s->grade); ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="sbr-muted">No students were named before the deadline.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form method="post" class="sbr-card sbr-roster-form" novalidate>
                    <h2>Competing students</h2>
                    <p>Enter up to <?php echo intval(self::MAX_STUDENTS); ?> students. Leave a row blank if you don't need it.
                       You can change names until <strong><?php echo esc_html($deadline_text); ?></strong>; after that, changes are made at check-in.</p>
                    <?php wp_nonce_field('sbr_roster_save_' . $registration->id, 'sbr_roster_nonce'); ?>
                    <?php echo self::rows_html($rows, $errors, 'sbr-r'); ?>
                    <div class="sbr-actions">
                        <button type="submit" class="sbr-btn sbr-btn-primary">Save roster</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (!empty($event->organizer_email)): ?>
                <p class="sbr-muted sbr-help">Questions? Contact <?php echo esc_html($event->organizer_name ?: 'the tournament organizer'); ?> at
                    <a href="mailto:<?php echo esc_attr($event->organizer_email); ?>"><?php echo esc_html($event->organizer_email); ?></a>.</p>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var el = document.getElementById('sbr-roster-errors') || document.getElementById('sbr-roster-saved');
            if (el) { el.focus(); }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Editable student rows. Field names: students[i][first_name|last_name|grade|id].
     * $prefix makes element ids unique when more than one roster is on a page.
     */
    public static function rows_html($rows, $errors = array(), $prefix = 'sbr', $count = null) {
        $count = $count === null ? self::MAX_STUDENTS : $count;
        ob_start();
        ?>
        <div class="sbr-student-rows">
            <div class="sbr-student-row sbr-student-row-head" aria-hidden="true">
                <span>#</span><span>First name</span><span>Last name</span><span>Grade</span>
            </div>
            <?php for ($i = 0; $i < self::MAX_STUDENTS; $i++):
                $r = isset($rows[$i]) ? $rows[$i] : array();
                $first = isset($r['first_name']) ? $r['first_name'] : '';
                $last = isset($r['last_name']) ? $r['last_name'] : '';
                $grade = isset($r['grade']) ? (string) $r['grade'] : '';
                $n = $i + 1;
                $hidden = $i >= $count && $first === '' && $last === '';
                $fields = array('first_name' => 'First name', 'last_name' => 'Last name', 'grade' => 'Grade');
                ?>
                <fieldset class="sbr-student-row" data-row="<?php echo intval($i); ?>"<?php echo $hidden ? ' hidden disabled' : ''; ?>>
                    <legend class="sbr-student-legend"><span class="sbr-sr-only">Student </span><span class="sbr-num"><?php echo intval($n); ?></span></legend>
                    <input type="hidden" name="students[<?php echo intval($i); ?>][id]" value="<?php echo intval(isset($r['id']) ? $r['id'] : 0); ?>">
                    <?php foreach ($fields as $f => $label):
                        $err_key = "students_{$i}_{$f}";
                        $id = $prefix === 'sbr-r' ? $err_key : $prefix . '_' . $err_key;
                        $has_err = isset($errors[$err_key]);
                        ?>
                        <div class="sbr-field sbr-field-<?php echo esc_attr($f); ?>">
                            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?><span class="sbr-sr-only"> for student <?php echo intval($n); ?></span></label>
                            <?php if ($f === 'grade'): ?>
                                <select id="<?php echo esc_attr($id); ?>" name="students[<?php echo intval($i); ?>][grade]"<?php echo $has_err ? ' aria-invalid="true" aria-describedby="' . esc_attr($id) . '_err"' : ''; ?>>
                                    <option value="">—</option>
                                    <?php foreach (self::GRADES as $g): ?>
                                        <option value="<?php echo intval($g); ?>" <?php selected($grade, (string) $g); ?>><?php echo intval($g); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" id="<?php echo esc_attr($id); ?>" name="students[<?php echo intval($i); ?>][<?php echo esc_attr($f); ?>]"
                                       value="<?php echo esc_attr($f === 'first_name' ? $first : $last); ?>" maxlength="100"
                                       autocomplete="off" spellcheck="false"<?php echo $has_err ? ' aria-invalid="true" aria-describedby="' . esc_attr($id) . '_err"' : ''; ?>>
                            <?php endif; ?>
                            <?php if ($has_err): ?><p class="sbr-field-error" id="<?php echo esc_attr($id); ?>_err"><?php echo esc_html($errors[$err_key]); ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endfor; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------ email */

    public static function mail_headers($event) {
        $organizer_email = isset($event->organizer_email) ? $event->organizer_email : '';
        $from_email = !empty($event->from_email) ? $event->from_email : get_option('sbr_default_from_email', '');
        $reply_to = !empty($event->reply_to_email) ? $event->reply_to_email : get_option('sbr_default_reply_to_email', '');
        $from_name = !empty($event->from_name) ? $event->from_name : get_option('sbr_default_from_name', '');
        if (empty($from_email)) {
            $from_email = $organizer_email;
        }
        if (empty($reply_to)) {
            $reply_to = $organizer_email;
        }
        if (empty($from_name)) {
            $from_name = !empty($event->organizer_name) ? $event->organizer_name : (!empty($event->organization_name) ? $event->organization_name : "Scholars' Bowl Team");
        }
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $from_name = str_replace(array("\r", "\n", '<', '>', '"'), '', $from_name);
        if (!empty($from_email) && is_email($from_email)) {
            $headers[] = "From: {$from_name} <{$from_email}>";
        }
        if (!empty($reply_to) && is_email($reply_to)) {
            $headers[] = "Reply-To: {$reply_to}";
        }
        return $headers;
    }

    /** Status line, e.g. "Complete: all 4 competing students are named." */
    public static function status_text($registration, $students) {
        $named = self::named($students);
        $required = self::required($registration);
        if ($named >= $required) {
            return sprintf('Complete: %d of %d competing student%s named.', $named, $required, $required === 1 ? '' : 's');
        }
        return sprintf('Incomplete: %d of %d competing student%s named.', $named, $required, $required === 1 ? '' : 's');
    }

    /**
     * The roster part of the registration confirmation email. Creates a new roster link.
     * Returns '' if rosters are unavailable, so the confirmation email is never blocked.
     */
    public static function confirmation_email_section($registration_id) {
        try {
            $registration = self::registration($registration_id);
            if (!$registration || !SBR_Schema::roster_ready()) {
                return '';
            }
            $event = self::event($registration->event_id);
            if (!$event) {
                return '';
            }
            $token = self::issue_token($registration->id, 'confirmation');
            if (!$token) {
                return '';
            }
            $students = self::get_students($registration->id);
            $text = "\nTeam Roster:\n";
            $text .= 'Roster status: ' . self::status_text($registration, $students) . "\n";
            if (self::is_locked($event, $registration)) {
                $text .= "The roster deadline has passed. Roster changes are made at check-in. You can view your roster here:\n";
            } elseif (self::is_complete($registration, $students)) {
                $text .= 'You can review or change your students\' names until ' . self::format_deadline($event) . " here:\n";
            } else {
                $text .= 'Please add the names and grades of your competing students by ' . self::format_deadline($event) . " here:\n";
            }
            $text .= self::url($token) . "\n";
            $text .= "This link is private to your team. You can use it as often as you need until the deadline; after that, roster changes are made at check-in.\n";
            return $text;
        } catch (Throwable $e) {
            error_log('SBR: roster email section failed: ' . $e->getMessage());
            return '';
        }
    }

    /** Email a fresh roster link (admin "Resend roster link"). Earlier links stop working. */
    public static function send_link_email($registration_id, &$link = null) {
        $registration = self::registration($registration_id);
        if (!$registration || !is_email($registration->contact_email)) {
            return false;
        }
        $event = self::event($registration->event_id);
        if (!$event) {
            return false;
        }
        $token = self::issue_token($registration->id, 'admin', true);
        if (!$token) {
            return false;
        }
        $link = self::url($token);
        $students = self::get_students($registration->id);
        $subject = 'Team roster link - ' . $event->event_name;
        $m = "Dear {$registration->contact_name},\n\n";
        $m .= "Here is the link to the team roster for {$registration->school_name} at the {$event->event_name} on " . date_i18n('F j, Y', strtotime($event->event_date)) . ".\n\n";
        $m .= 'Roster status: ' . self::status_text($registration, $students) . "\n\n";
        if (self::is_locked($event, $registration)) {
            $m .= "The roster deadline has passed, so the roster is shown read-only. Roster changes are made at check-in.\n\n";
        } else {
            $m .= 'You can add or change your competing students\' names and grades until ' . self::format_deadline($event) . ":\n\n";
        }
        $m .= $link . "\n\n";
        $m .= "This link replaces any roster link we sent before. It is private to your team.\n\n";
        if (!empty($event->organizer_email)) {
            $m .= "If you have any questions, please contact us at {$event->organizer_email}.\n\n";
        }
        $m .= "Thank you,\nScholars' Bowl Team";
        return wp_mail($registration->contact_email, $subject, $m, self::mail_headers($event));
    }

    /* ------------------------------------------------------------------ reminders */

    /**
     * Hourly. Emails the contact 7 days and 1 day before the event when the roster is
     * incomplete and the registration isn't cancelled. Each reminder is claimed in the
     * database before it is sent, so it goes at most once. If the job missed the 7-day
     * window, only the 1-day reminder is sent, and only before the team's session starts.
     * A reminder is skipped for a registration made after its window opened (the
     * confirmation email already had the link).
     */
    public static function run_reminders() {
        global $wpdb;
        if (!SBR_Schema::roster_ready()) {
            return array();
        }
        $now = self::now();
        $sent = array();
        $events = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SBR_Schema::table('events') . ' WHERE event_date >= %s AND event_date <= %s',
            $now->modify('-1 day')->format('Y-m-d'),
            $now->modify('+8 days')->format('Y-m-d')
        ));
        foreach ((array) $events as $event) {
            try {
                $day = new DateTimeImmutable($event->event_date . ' 00:00:00', wp_timezone());
            } catch (Exception $e) {
                continue;
            }
            $w7 = $day->modify('-7 days');
            $w1 = $day->modify('-1 day');
            if ($now < $w7) {
                continue;
            }
            $regs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SBR_Schema::table('registrations') . ' WHERE event_id = %d', $event->id));
            if (!$regs) {
                continue;
            }
            $ids = wp_list_pluck($regs, 'id');
            $students = self::students_by_registration($ids);
            $status = self::status_by_registration($ids);
            foreach ($regs as $reg) {
                if (strtolower((string) $reg->registration_status) === 'cancelled') {
                    continue;
                }
                if (in_array(strtolower((string) $reg->payment_status), array('failed', 'processing'), true)) {
                    continue; // an unfinished card registration: the coach never got a confirmation
                }
                if (!is_email($reg->contact_email)) {
                    continue;
                }
                if (self::is_complete($reg, isset($students[$reg->id]) ? $students[$reg->id] : array())) {
                    continue;
                }
                $start = SBR_Tournament::session_start($event, $reg->session_time);
                if (!$start || $now >= $start) {
                    continue;
                }
                $registered = strtotime((string) $reg->registration_date . ' UTC');
                $st = isset($status[$reg->id]) ? $status[$reg->id] : null;
                if ($now >= $w1) {
                    $kind = '1d';
                    if ($registered && $registered >= $w1->getTimestamp()) {
                        continue;
                    }
                } else {
                    $kind = '7d';
                    if ($registered && $registered >= $w7->getTimestamp()) {
                        continue;
                    }
                }
                $col = $kind === '1d' ? 'reminder_1d_sent_at' : 'reminder_7d_sent_at';
                if ($st && !empty($st->$col)) {
                    continue;
                }
                if (!self::claim($reg->id, $col)) {
                    continue;
                }
                if (self::send_reminder($reg, $event, $kind, isset($students[$reg->id]) ? $students[$reg->id] : array())) {
                    $sent[] = array('registration_id' => intval($reg->id), 'kind' => $kind);
                } else {
                    // Not sent: release the claim so the next run tries again.
                    $wpdb->query($wpdb->prepare('UPDATE ' . SBR_Schema::table('roster_status') . " SET $col = NULL WHERE registration_id = %d", $reg->id));
                }
            }
        }
        return $sent;
    }

    private static function claim($registration_id, $col) {
        global $wpdb;
        self::touch_status($registration_id, array());
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . SBR_Schema::table('roster_status') . " SET $col = %s WHERE registration_id = %d AND $col IS NULL",
            self::utc_now(),
            $registration_id
        ));
        return intval($n) === 1;
    }

    private static function send_reminder($reg, $event, $kind, $students) {
        $token = self::issue_token($reg->id, 'reminder_' . $kind);
        if (!$token) {
            return false;
        }
        $when = $kind === '1d' ? 'tomorrow' : 'in one week';
        $date = date_i18n('l, F j, Y', strtotime($event->event_date));
        $subject = 'Reminder: team roster for ' . $event->event_name;
        $m = "Dear {$reg->contact_name},\n\n";
        $m .= "The {$event->event_name} is {$when}, on {$date}. We don't have a complete roster for {$reg->school_name} yet.\n\n";
        $m .= 'Roster status: ' . self::status_text($reg, $students) . "\n\n";
        if (self::is_locked($event, $reg)) {
            $m .= "The roster deadline has passed. Please bring your students' names and grades to check-in.\n\n";
        } else {
            $m .= 'Please add the names and grades of your competing students by ' . self::format_deadline($event) . ":\n\n";
        }
        $m .= self::url($token) . "\n\n";
        $m .= "If you don't know your team yet, that's fine: roster changes can also be made at check-in.\n\n";
        if (!empty($event->organizer_email)) {
            $m .= "Questions? Contact us at {$event->organizer_email}.\n\n";
        }
        $m .= "Thank you,\nScholars' Bowl Team";
        return wp_mail($reg->contact_email, $subject, $m, self::mail_headers($event));
    }

    /* ------------------------------------------------------------------ admin */

    /** Badge text for the registrations list, e.g. "Complete 4/4". */
    public static function badge_html($registration, $students) {
        $named = self::named($students);
        $required = self::required($registration);
        $ok = $named >= $required;
        return '<span class="sbr-roster-badge ' . ($ok ? 'is-complete' : 'is-incomplete') . '">'
            . '<span aria-hidden="true">' . ($ok ? '✓ ' : '! ') . '</span>'
            . ($ok ? 'Complete' : 'Incomplete') . ' <span class="sbr-roster-count">' . intval($named) . '/' . intval($required) . '</span></span>';
    }

    /** Roster panel inside the registration View/Edit dialog. */
    public static function admin_panel_html($registration) {
        if (!SBR_Schema::roster_ready()) {
            return '<div class="sbr-admin-roster"><h3>Team Roster</h3><p>Roster tables are not set up yet. Reload this page to finish the 1.1.0 database upgrade.</p></div>';
        }
        $event = self::event($registration->event_id);
        $students = self::get_students($registration->id);
        $rows = array();
        foreach (array_values($students) as $i => $s) {
            $rows[$i] = array('id' => $s->id, 'first_name' => $s->first_name, 'last_name' => $s->last_name, 'grade' => $s->grade === null ? '' : (string) $s->grade);
        }
        $status = self::status_by_registration(array($registration->id));
        $st = isset($status[$registration->id]) ? $status[$registration->id] : null;
        ob_start();
        ?>
        <div class="sbr-admin-roster" data-registration-id="<?php echo intval($registration->id); ?>" style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #dcdcde;">
            <h3>Team Roster <?php echo self::badge_html($registration, $students); ?></h3>
            <p class="description">
                <?php echo $event ? esc_html('Coaches can edit until ' . self::format_deadline($event) . '. You can edit at any time.') : ''; ?>
                <?php if ($st && $st->roster_updated_at): ?>Last saved <?php echo esc_html(get_date_from_gmt($st->roster_updated_at, 'M j, Y g:i a')); ?>.<?php endif; ?>
                <?php if ($st && ($st->reminder_7d_sent_at || $st->reminder_1d_sent_at)): ?>
                    Reminders sent: <?php echo esc_html(implode(', ', array_filter(array(
                        $st->reminder_7d_sent_at ? '7-day (' . get_date_from_gmt($st->reminder_7d_sent_at, 'M j') . ')' : '',
                        $st->reminder_1d_sent_at ? '1-day (' . get_date_from_gmt($st->reminder_1d_sent_at, 'M j') . ')' : '',
                    )))); ?>.
                <?php endif; ?>
            </p>
            <form class="sbr-admin-roster-form" onsubmit="return false;">
                <?php echo self::rows_html($rows, array(), 'sbr_admin_' . intval($registration->id)); ?>
                <p>
                    <button type="button" class="button button-primary sbr-roster-save">Save roster</button>
                    <button type="button" class="button sbr-roster-resend">Email a new roster link</button>
                    <span class="sbr-roster-msg" role="status" aria-live="polite" style="margin-left: 8px;"></span>
                </p>
                <p class="sbr-roster-link" hidden><label>New roster link (also emailed; earlier links no longer work):<br>
                    <input type="text" readonly class="large-text code" onclick="this.select();"></label></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function ajax_admin_save() {
        check_ajax_referer('sbr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        $registration = self::registration(absint($_POST['registration_id'] ?? 0));
        if (!$registration) {
            wp_send_json_error('Registration not found');
        }
        $rows = isset($_POST['students']) && is_array($_POST['students']) ? wp_unslash($_POST['students']) : array();
        $parsed = self::parse_rows($rows);
        if ($parsed['errors']) {
            wp_send_json_error(implode(' ', array_values($parsed['errors'])));
        }
        self::save($registration->id, $parsed['students']);
        $students = self::get_students($registration->id);
        wp_send_json_success(array(
            'message' => 'Roster saved. ' . self::status_text($registration, $students),
            'badge' => self::badge_html($registration, $students),
        ));
    }

    public static function ajax_resend_link() {
        check_ajax_referer('sbr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        $id = absint($_POST['registration_id'] ?? 0);
        $link = null;
        $ok = self::send_link_email($id, $link);
        if (!$ok && !$link) {
            wp_send_json_error('Could not create a roster link for this registration.');
        }
        wp_send_json_success(array(
            'message' => $ok ? 'A new roster link was emailed to the contact.' : 'A new link was created, but the email could not be sent. Copy the link below.',
            'link' => $link,
        ));
    }

    /* ------------------------------------------------------------------ CSV */

    public static function csv_headers() {
        $h = array('Roster Status', 'Students Named');
        for ($i = 1; $i <= self::MAX_STUDENTS; $i++) {
            $h[] = "Student $i First Name";
            $h[] = "Student $i Last Name";
            $h[] = "Student $i Grade";
        }
        return $h;
    }

    /** Keep spreadsheet programs from reading a typed name as a formula. */
    private static function csv_safe($value) {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    public static function csv_values($registration, $students) {
        $students = array_values((array) $students);
        $v = array(self::is_complete($registration, $students) ? 'Complete' : 'Incomplete', self::named($students));
        for ($i = 0; $i < self::MAX_STUDENTS; $i++) {
            $s = isset($students[$i]) ? $students[$i] : null;
            $v[] = $s ? self::csv_safe($s->first_name) : '';
            $v[] = $s ? self::csv_safe($s->last_name) : '';
            $v[] = $s && $s->grade !== null ? (string) $s->grade : '';
        }
        return $v;
    }
}
