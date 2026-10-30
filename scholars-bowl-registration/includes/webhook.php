<?php
/**
 * Change notifications to the scoring app (added in 1.1.0).
 *
 * When an event or registration changes, the site POSTs a small signed message to
 * {scoring_url}/api/wordpress/notify. The message names what changed and carries no
 * personal data; the app then reads the details through the REST API.
 *
 *   Body:    {"site": home_url(), "type": "event.saved|event.deleted|registration.saved|roster.saved",
 *             "event_id": 12, "registration_id": 34|null, "sent_at": "2026-10-20T13:00:00Z"}
 *   Headers: Content-Type: application/json
 *            X-SBR-Timestamp: <unix seconds>
 *            X-SBR-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">
 *   Key:     the stored API token hash (option sbr_api_token_hash, the hex SHA-256 of the token),
 *            which the app can compute from the token it already has.
 *
 * Notifications are collected during the request, de-duplicated, and sent at shutdown after
 * the response has been handed to the visitor where the server allows it (PHP-FPM), with a
 * 3-second timeout. A failure is recorded and never affects the visitor's request.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBR_Webhook {
    const URL_OPTION = 'sbr_scoring_url';
    const ENABLED_OPTION = 'sbr_scoring_notify';
    const LAST_OPTION = 'sbr_scoring_notify_last';
    const TYPES = array('event.saved', 'event.deleted', 'registration.saved', 'roster.saved');
    /** More registration notices than this for one event in one request become one notice with registration_id null. */
    const BURST = 5;

    private static $queue = array();
    private static $hooked = false;

    /** https://..., or http:// only for localhost / 127.0.0.1 / [::1]. Returns the cleaned URL or ''. */
    public static function sanitize_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $local = in_array($host, array('localhost', '127.0.0.1', '::1'), true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        $clean = $scheme . '://' . (strpos($parts['host'], ':') !== false && $parts['host'][0] !== '[' ? '[' . $parts['host'] . ']' : $parts['host'])
            . (isset($parts['port']) ? ':' . intval($parts['port']) : '')
            . (isset($parts['path']) ? rtrim($parts['path'], '/') : '');
        return esc_url_raw($clean, array('http', 'https')) ? $clean : '';
    }

    public static function endpoint() {
        $base = self::sanitize_url(get_option(self::URL_OPTION, ''));
        return $base === '' ? '' : $base . '/api/wordpress/notify';
    }

    public static function enabled() {
        return (bool) get_option(self::ENABLED_OPTION, false) && self::endpoint() !== '' && self::key() !== '';
    }

    private static function key() {
        return (string) get_option('sbr_api_token_hash', '');
    }

    /** Queue a notification for the end of this request. */
    public static function notify($type, $event_id, $registration_id = null) {
        if (!in_array($type, self::TYPES, true) || !self::enabled()) {
            return;
        }
        $event_id = intval($event_id);
        $registration_id = $registration_id === null ? null : intval($registration_id);
        if ($event_id <= 0 && $registration_id) {
            global $wpdb;
            $event_id = intval($wpdb->get_var($wpdb->prepare('SELECT event_id FROM ' . SBR_Schema::table('registrations') . ' WHERE id = %d', $registration_id)));
        }
        if ($event_id <= 0) {
            return;
        }
        self::$queue[$type . '|' . $event_id . '|' . ($registration_id === null ? '' : $registration_id)] = array($type, $event_id, $registration_id);
        if (!self::$hooked) {
            self::$hooked = true;
            add_action('shutdown', array(__CLASS__, 'flush'), PHP_INT_MAX - 10);
        }
    }

    /** The queued messages after coalescing bursts. */
    public static function pending() {
        $out = array();
        $per_event = array();
        foreach (self::$queue as $item) {
            list($type, $event_id, $reg) = $item;
            if ($reg !== null) {
                $per_event[$type . '|' . $event_id][] = $item;
            } else {
                $out[$type . '|' . $event_id . '|'] = $item;
            }
        }
        foreach ($per_event as $k => $items) {
            if (count($items) > self::BURST) {
                list($type, $event_id) = $items[0];
                $out[$type . '|' . $event_id . '|'] = array($type, $event_id, null);
            } else {
                foreach ($items as $item) {
                    $out[$item[0] . '|' . $item[1] . '|' . $item[2]] = $item;
                }
            }
        }
        return array_values($out);
    }

    public static function flush() {
        $items = self::pending();
        self::$queue = array();
        if (!$items || !self::enabled()) {
            return;
        }
        // Hand the response to the visitor first where the server supports it.
        if (PHP_SAPI !== 'cli') {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
        }
        foreach (array_slice($items, 0, 20) as $item) {
            self::send($item[0], $item[1], $item[2]);
        }
    }

    /** Build the body and headers (public so tests can check the signature). */
    public static function build($type, $event_id, $registration_id, $timestamp = null) {
        $timestamp = $timestamp ?: time();
        $body = wp_json_encode(array(
            'site' => home_url(),
            'type' => $type,
            'event_id' => intval($event_id),
            'registration_id' => $registration_id === null ? null : intval($registration_id),
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
        ), JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::key());
        return array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-SBR-Timestamp' => (string) $timestamp,
                'X-SBR-Signature' => $signature,
            ),
        );
    }

    /** Send one message now. Returns array('ok' => bool, 'detail' => string). */
    public static function send($type, $event_id, $registration_id = null) {
        $url = self::endpoint();
        if ($url === '' || self::key() === '') {
            return array('ok' => false, 'detail' => 'Not configured');
        }
        $msg = self::build($type, $event_id, $registration_id);
        $response = wp_remote_post($url, array(
            'timeout' => 3,
            'redirection' => 0,
            'blocking' => true,
            'headers' => $msg['headers'],
            'body' => $msg['body'],
            'user-agent' => 'SBR/' . (defined('SBR_VERSION') ? SBR_VERSION : '') . '; ' . home_url(),
        ));
        if (is_wp_error($response)) {
            $result = array('ok' => false, 'detail' => $response->get_error_message());
        } else {
            $code = intval(wp_remote_retrieve_response_code($response));
            $result = array('ok' => $code >= 200 && $code < 300, 'detail' => 'HTTP ' . $code);
        }
        update_option(self::LAST_OPTION, array(
            'time' => gmdate('Y-m-d H:i:s'),
            'type' => $type,
            'event_id' => intval($event_id),
            'registration_id' => $registration_id,
            'ok' => $result['ok'],
            'detail' => substr(sanitize_text_field($result['detail']), 0, 200),
        ), false);
        return $result;
    }

    /** Settings block on the API Access page; handles its own form. */
    public static function render_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $notice = '';
        $notice_type = 'success';
        if (isset($_POST['sbr_webhook_action']) && check_admin_referer('sbr_webhook_settings')) {
            $action = sanitize_key(wp_unslash($_POST['sbr_webhook_action']));
            if ($action === 'save') {
                $raw = isset($_POST['sbr_scoring_url']) ? trim(wp_unslash((string) $_POST['sbr_scoring_url'])) : '';
                $clean = self::sanitize_url($raw);
                if ($raw !== '' && $clean === '') {
                    $notice = 'The scoring app URL must start with https:// (http:// is allowed only for localhost). Nothing was saved.';
                    $notice_type = 'error';
                } else {
                    update_option(self::URL_OPTION, $clean, false);
                    update_option(self::ENABLED_OPTION, !empty($_POST['sbr_scoring_notify']) ? 1 : 0, false);
                    $notice = 'Scoring app settings saved.';
                }
            } elseif ($action === 'test') {
                global $wpdb;
                $event_id = intval($wpdb->get_var('SELECT id FROM ' . SBR_Schema::table('events') . ' ORDER BY event_date DESC, id DESC LIMIT 1'));
                $r = self::send('event.saved', max(1, $event_id), null);
                $notice = $r['ok'] ? 'Test notification delivered (' . $r['detail'] . ').' : 'Test notification failed: ' . $r['detail'];
                $notice_type = $r['ok'] ? 'success' : 'error';
            }
        }
        $url = get_option(self::URL_OPTION, '');
        $on = (bool) get_option(self::ENABLED_OPTION, false);
        $last = get_option(self::LAST_OPTION, array());
        $has_token = get_option('sbr_api_token_hash', '') !== '';
        ?>
        <h2 id="sbr-scoring-notify">Notify the scoring app of changes</h2>
        <p>When events, registrations or rosters change, this site can tell the scoring app right away, so it refreshes
           without waiting. The message says only what changed (no names or contact details) and is signed with the API token.</p>
        <?php if ($notice): ?>
            <div class="notice notice-<?php echo esc_attr($notice_type); ?>" role="<?php echo $notice_type === 'error' ? 'alert' : 'status'; ?>"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>
        <?php if (!$has_token): ?>
            <div class="notice notice-warning inline"><p>Generate an API token above first. Notifications are signed with it and are not sent without one.</p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('sbr_webhook_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="sbr_scoring_url">Scoring app URL</label></th>
                    <td>
                        <input type="url" id="sbr_scoring_url" name="sbr_scoring_url" class="regular-text code" value="<?php echo esc_attr($url); ?>" placeholder="https://scoring.suscholarsbowl.com" aria-describedby="sbr_scoring_url_help">
                        <p class="description" id="sbr_scoring_url_help">Must start with https:// (http:// only for localhost). Messages go to <code>&lt;URL&gt;/api/wordpress/notify</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Notifications</th>
                    <td><label><input type="checkbox" name="sbr_scoring_notify" value="1" <?php checked($on); ?>> Send change notifications to the scoring app</label></td>
                </tr>
                <tr>
                    <th scope="row">Last notification</th>
                    <td><?php
                        if ($last && !empty($last['time'])) {
                            echo esc_html(($last['ok'] ? 'Delivered' : 'Failed') . ' — ' . $last['type'] . ' — ' . $last['detail'] . ' — ' . $last['time'] . ' UTC');
                        } else {
                            echo 'None sent yet.';
                        }
                    ?></td>
                </tr>
            </table>
            <p>
                <button type="submit" name="sbr_webhook_action" value="save" class="button button-primary">Save scoring app settings</button>
                <?php if (self::endpoint() !== '' && $has_token): ?>
                    <button type="submit" name="sbr_webhook_action" value="test" class="button">Send a test notification</button>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }
}
