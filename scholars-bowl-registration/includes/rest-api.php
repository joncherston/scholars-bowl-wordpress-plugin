<?php
/**
 * Read-only REST API for the Scholars' Bowl scoring system (added in 1.0.7).
 *
 *   GET /wp-json/sbr/v1/events
 *   GET /wp-json/sbr/v1/registrations?event_id=N
 *
 * Every request must carry the API token from Scholars' Bowl → API Access in an
 * "X-SBR-Token" header (or "Authorization: Bearer <token>"), or come from an
 * administrator (for example with a WordPress Application Password).
 *
 * Only an explicit list of columns is ever returned: no payment amounts, notes,
 * Stripe keys, or payment tokens. Payment information is limited to its status.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBR_REST_API {
    const TOKEN_OPTION = 'sbr_api_token_hash';
    const TOKEN_CREATED_OPTION = 'sbr_api_token_created';
    const NAMESPACE_V1 = 'sbr/v1';

    /** Columns that may be read from each table. Anything else is never selected. */
    private static $event_columns = array(
        'id', 'event_name', 'event_slug', 'event_date', 'event_status',
        'morning_classifications', 'afternoon_classifications',
        'morning_session_time', 'afternoon_session_time'
    );
    private static $registration_columns = array(
        'id', 'event_id', 'school_name', 'contact_name', 'contact_email', 'contact_phone',
        'competing_students', 'additional_students', 'additional_adults', 'coaches',
        'grade_level', 'school_classification', 'session_time',
        'registration_status', 'payment_status', 'is_test_mode',
        'registration_date', 'last_updated'
    );

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 20);
    }

    public static function register_routes() {
        register_rest_route(self::NAMESPACE_V1, '/events', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_events'),
            'permission_callback' => array(__CLASS__, 'authorize'),
        ));
        register_rest_route(self::NAMESPACE_V1, '/registrations', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_registrations'),
            'permission_callback' => array(__CLASS__, 'authorize'),
            'args' => array(
                'event_id' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && intval($value) > 0;
                    },
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    /** The token sent with the request, from X-SBR-Token or an Authorization: Bearer header. */
    private static function provided_token($request) {
        $token = $request->get_header('x_sbr_token');
        if (!$token) {
            $auth = $request->get_header('authorization');
            if ($auth && stripos($auth, 'Bearer ') === 0) {
                $token = substr($auth, 7);
            }
        }
        return is_string($token) ? trim($token) : '';
    }

    public static function authorize($request) {
        if (current_user_can('manage_options')) {
            return true;
        }
        $token = self::provided_token($request);
        $hash = (string) get_option(self::TOKEN_OPTION, '');
        if ($token !== '' && $hash !== '' && hash_equals($hash, hash('sha256', $token))) {
            return true;
        }
        return new WP_Error('sbr_unauthorized', 'A valid API token is required.', array('status' => 401));
    }

    /** Whitelisted columns that actually exist in the live table (the schema has grown over time). */
    private static function existing_columns($table, $wanted) {
        global $wpdb;
        $have = $wpdb->get_col("DESCRIBE {$table}", 0);
        return array_values(array_intersect($wanted, is_array($have) ? $have : array()));
    }

    private static function select_sql($columns) {
        return implode(', ', array_map(function ($c) {
            return '`' . $c . '`';
        }, $columns));
    }

    private static function split_list($value) {
        $parts = array_map('trim', explode(',', (string) $value));
        return array_values(array_filter($parts, 'strlen'));
    }

    private static function respond($payload) {
        $payload['generated_at'] = gmdate('c');
        $payload['plugin_version'] = defined('SBR_VERSION') ? SBR_VERSION : null;
        $response = new WP_REST_Response($payload, 200);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public static function get_events($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'scholars_bowl_events';
        $columns = self::existing_columns($table, self::$event_columns);
        $rows = $wpdb->get_results('SELECT ' . self::select_sql($columns) . " FROM {$table} ORDER BY event_date DESC, id DESC", ARRAY_A);
        $events = array();
        foreach ((array) $rows as $r) {
            $events[] = array(
                'id' => intval($r['id']),
                'name' => isset($r['event_name']) ? $r['event_name'] : '',
                'slug' => isset($r['event_slug']) ? $r['event_slug'] : '',
                'date' => isset($r['event_date']) ? $r['event_date'] : null,
                'status' => isset($r['event_status']) ? $r['event_status'] : null,
                'morning_classifications' => self::split_list(isset($r['morning_classifications']) ? $r['morning_classifications'] : ''),
                'afternoon_classifications' => self::split_list(isset($r['afternoon_classifications']) ? $r['afternoon_classifications'] : ''),
                'morning_session_time' => isset($r['morning_session_time']) ? $r['morning_session_time'] : null,
                'afternoon_session_time' => isset($r['afternoon_session_time']) ? $r['afternoon_session_time'] : null,
            );
        }
        return self::respond(array('events' => $events));
    }

    public static function get_registrations($request) {
        global $wpdb;
        $event_id = absint($request->get_param('event_id'));
        $table = $wpdb->prefix . 'scholars_bowl_registrations';
        $columns = self::existing_columns($table, self::$registration_columns);
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT ' . self::select_sql($columns) . " FROM {$table} WHERE event_id = %d ORDER BY id", $event_id),
            ARRAY_A
        );
        $int = function ($r, $k) {
            return isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null ? intval($r[$k]) : null;
        };
        $str = function ($r, $k) {
            return isset($r[$k]) && $r[$k] !== '' ? (string) $r[$k] : null;
        };
        $registrations = array();
        foreach ((array) $rows as $r) {
            // The form stores classification in grade_level; some code paths use school_classification.
            $classification = $str($r, 'grade_level');
            if ($classification === null) {
                $classification = $str($r, 'school_classification');
            }
            $registrations[] = array(
                'id' => intval($r['id']),
                'event_id' => $int($r, 'event_id'),
                'school_name' => $str($r, 'school_name'),
                'contact_name' => $str($r, 'contact_name'),
                'contact_email' => $str($r, 'contact_email'),
                'contact_phone' => $str($r, 'contact_phone'),
                'competing_students' => $int($r, 'competing_students'),
                'additional_students' => $int($r, 'additional_students'),
                'additional_adults' => $int($r, 'additional_adults'),
                'coaches' => $int($r, 'coaches'),
                'classification' => $classification,
                'session_time' => $str($r, 'session_time'),
                'registration_status' => $str($r, 'registration_status') !== null ? $r['registration_status'] : 'pending',
                'payment_status' => $str($r, 'payment_status'),
                'is_test' => !empty($r['is_test_mode']),
                'registration_date' => $str($r, 'registration_date'),
                'last_updated' => $str($r, 'last_updated'),
            );
        }
        return self::respond(array('event_id' => $event_id, 'registrations' => $registrations));
    }

    /* ---------- Admin: API Access page ---------- */

    public static function admin_menu() {
        add_submenu_page(
            'scholars-bowl-events',
            'API Access',
            'API Access',
            'manage_options',
            'scholars-bowl-api',
            array(__CLASS__, 'admin_page')
        );
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        $new_token = null;
        $message = '';
        if (isset($_POST['sbr_api_action']) && check_admin_referer('sbr_api_token')) {
            if ($_POST['sbr_api_action'] === 'generate') {
                $new_token = bin2hex(random_bytes(32));
                update_option(self::TOKEN_OPTION, hash('sha256', $new_token), false);
                update_option(self::TOKEN_CREATED_OPTION, current_time('mysql'), false);
                $message = 'A new token was created. Copy it now; it will not be shown again. Any previous token no longer works.';
            } elseif ($_POST['sbr_api_action'] === 'revoke') {
                delete_option(self::TOKEN_OPTION);
                delete_option(self::TOKEN_CREATED_OPTION);
                $message = 'The token was revoked. The API now refuses all requests that do not come from an administrator.';
            }
        }
        $has_token = get_option(self::TOKEN_OPTION, '') !== '';
        $created = get_option(self::TOKEN_CREATED_OPTION, '');
        $base = rest_url(self::NAMESPACE_V1);
        ?>
        <div class="wrap">
            <h1>Scholars' Bowl API Access</h1>
            <p>The scoring system can read events and registrations from this site through a read-only API.
               It never returns payment amounts, notes, or Stripe keys.</p>
            <?php if ($message): ?>
                <div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            <?php if ($new_token): ?>
                <h2>Your new token</h2>
                <p><input type="text" readonly class="large-text code" value="<?php echo esc_attr($new_token); ?>" onclick="this.select();" /></p>
                <p>Paste it into the scoring system: <strong>Admin → Website connection → API token</strong>, along with this site's address.</p>
            <?php endif; ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Status</th><td><?php echo $has_token ? 'Token active (created ' . esc_html($created) . ')' : 'No token. The API only answers administrators.'; ?></td></tr>
                <tr><th scope="row">Events</th><td><code><?php echo esc_html($base . '/events'); ?></code></td></tr>
                <tr><th scope="row">Registrations</th><td><code><?php echo esc_html($base . '/registrations?event_id=ID'); ?></code></td></tr>
                <tr><th scope="row">Header</th><td><code>X-SBR-Token: &lt;token&gt;</code></td></tr>
            </table>
            <form method="post">
                <?php wp_nonce_field('sbr_api_token'); ?>
                <p>
                    <button type="submit" name="sbr_api_action" value="generate" class="button button-primary"
                        onclick="return <?php echo $has_token ? "confirm('Replace the current token? The scoring system will need the new one.')" : 'true'; ?>;">
                        <?php echo $has_token ? 'Generate a new token' : 'Generate token'; ?>
                    </button>
                    <?php if ($has_token): ?>
                        <button type="submit" name="sbr_api_action" value="revoke" class="button" onclick="return confirm('Revoke the token?');">Revoke token</button>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
    }
}
