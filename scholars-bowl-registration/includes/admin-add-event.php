<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$events_table = $wpdb->prefix . 'scholars_bowl_events';

// Check if editing existing event
$event = null;
$is_edit = false;

if (isset($_GET['event_id']) && !empty($_GET['event_id'])) {
    $event_id = intval($_GET['event_id']);
    $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events_table WHERE id = %d", $event_id));
    $is_edit = $event !== null;
}

// Set default values (pull from global settings where appropriate)
$defaults = array(
    'event_name' => '',
    'event_slug' => '',
    'event_date' => date('Y-m-d', strtotime('+2 months')),
    'event_location' => 'Wadley Campus of Southern Union State Community College',
    'early_bird_deadline' => date('Y-m-d', strtotime('+1 month')),
    'registration_deadline' => '',
    'payment_deadline' => '',
    'early_bird_rate' => '14.00',
    'standard_rate' => '15.00',
    'deposit_amount' => '50.00',
    'max_students' => '6',
    'min_students' => '1',
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
    'from_email' => '',
    'reply_to_email' => '',
    'from_name' => '',
    'stripe_mode' => 'test',
    'stripe_test_publishable_key' => '',
    'stripe_test_secret_key' => '',
    'stripe_live_publishable_key' => '',
    'stripe_live_secret_key' => '',
    'stripe_publishable_key' => '', // Leave empty to use global by default
    'stripe_secret_key' => '', // Leave empty to use global by default
    'event_status' => 'active'
);

// Use event data if editing, otherwise use defaults
$data = $event ? (array) $event : $defaults;

// Tournament structure (1.1.0)
$sbr_config = $event ? SBR_Tournament::get_config($event) : SBR_Tournament::default_config();
$sbr_awards = SBR_Tournament::api_awards($sbr_config);
$sbr_roster_deadline_value = '';
if (!empty($data['roster_deadline']) && strpos((string) $data['roster_deadline'], '0000') !== 0) {
    $sbr_roster_deadline_value = date('Y-m-d\TH:i', strtotime($data['roster_deadline']));
}

/** One division's fields. $i is the form index ('__INDEX__' in the template). */
if (!function_exists('sbr_division_fields')) :
function sbr_division_fields($i, $d) {
    $base = 'sbr_divisions[' . $i . ']';
    $id = 'sbr_div_' . $i;
    ob_start();
    ?>
    <fieldset class="sbr-division" data-index="<?php echo esc_attr($i); ?>">
        <legend><span class="sbr-division-title"><?php echo esc_html($d['name'] !== '' ? $d['name'] : 'New division'); ?></span></legend>
        <input type="hidden" name="<?php echo esc_attr($base); ?>[key]" value="<?php echo esc_attr($d['key']); ?>">
        <div class="sbr-division-grid">
            <p>
                <label for="<?php echo esc_attr($id); ?>_name">Division name</label>
                <input type="text" id="<?php echo esc_attr($id); ?>_name" name="<?php echo esc_attr($base); ?>[name]" value="<?php echo esc_attr($d['name']); ?>" class="regular-text sbr-division-name" maxlength="60">
            </p>
            <p>
                <label for="<?php echo esc_attr($id); ?>_session">Session</label>
                <select id="<?php echo esc_attr($id); ?>_session" name="<?php echo esc_attr($base); ?>[session]">
                    <?php foreach (SBR_Tournament::SESSIONS as $k => $label): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($d['session'], $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <fieldset class="sbr-division-classes">
                <legend>Classifications</legend>
                <?php foreach (SBR_Tournament::CLASSIFICATIONS as $c): ?>
                    <label class="sbr-check"><input type="checkbox" name="<?php echo esc_attr($base); ?>[classifications][]" value="<?php echo esc_attr($c); ?>" <?php checked(in_array($c, $d['classifications'], true)); ?>> <?php echo esc_html($c); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <p>
                <label for="<?php echo esc_attr($id); ?>_format">Format</label>
                <select id="<?php echo esc_attr($id); ?>_format" name="<?php echo esc_attr($base); ?>[format]" class="sbr-division-format">
                    <?php foreach (SBR_Tournament::FORMATS as $k => $label): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($d['format'], $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="sbr-bracket-only">
                <label for="<?php echo esc_attr($id); ?>_seeding">Seeding rounds</label>
                <input type="number" id="<?php echo esc_attr($id); ?>_seeding" name="<?php echo esc_attr($base); ?>[seeding_rounds]" value="<?php echo esc_attr($d['seeding_rounds']); ?>" min="1" max="12" class="small-text">
                <span class="description">Round-robin rounds before the bracket.</span>
            </p>
            <p class="sbr-bracket-only">
                <label for="<?php echo esc_attr($id); ?>_size">Bracket size</label>
                <select id="<?php echo esc_attr($id); ?>_size" name="<?php echo esc_attr($base); ?>[bracket_size]">
                    <?php foreach (SBR_Tournament::BRACKET_SIZES as $n): ?>
                        <option value="<?php echo intval($n); ?>" <?php selected(intval($d['bracket_size']), $n); ?>><?php echo intval($n); ?> teams</option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="sbr-bracket-only">
                <label class="sbr-check"><input type="checkbox" name="<?php echo esc_attr($base); ?>[third_place_game]" value="1" <?php checked(!empty($d['third_place_game'])); ?>> Third-place game</label>
            </p>
        </div>
        <p><button type="button" class="button-link button-link-delete sbr-remove-division">Remove this division</button></p>
    </fieldset>
    <?php
    return ob_get_clean();
}
endif;
?>

<div class="wrap">
    <h1><?php echo $is_edit ? 'Edit Event' : 'Add New Event'; ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field('save_event', 'save_event_nonce'); ?>

        <?php if ($is_edit): ?>
            <input type="hidden" name="event_id" value="<?php echo $event->id; ?>">
        <?php endif; ?>

        <table class="form-table">
            <h2>Basic Information</h2>

            <tr>
                <th scope="row">
                    <label for="event_name">Event Name *</label>
                </th>
                <td>
                    <input type="text" id="event_name" name="event_name"
                           value="<?php echo esc_attr($data['event_name']); ?>"
                           class="regular-text" required />
                    <p class="description">The display name for this event.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_slug">Event Slug *</label>
                </th>
                <td>
                    <input type="text" id="event_slug" name="event_slug"
                           value="<?php echo esc_attr($data['event_slug']); ?>"
                           class="regular-text" required pattern="[a-z0-9-]+" />
                    <p class="description">Used in shortcode: [scholars_bowl_registration event="<strong>event-slug</strong>"]. Use lowercase letters, numbers, and hyphens only.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_date">Event Date *</label>
                </th>
                <td>
                    <input type="date" id="event_date" name="event_date"
                           value="<?php echo esc_attr($data['event_date']); ?>" required />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_location">Event Location *</label>
                </th>
                <td>
                    <textarea id="event_location" name="event_location"
                              class="large-text" rows="3" required><?php echo esc_textarea($data['event_location']); ?></textarea>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_status">Event Status</label>
                </th>
                <td>
                    <select id="event_status" name="event_status">
                        <option value="active" <?php selected($data['event_status'], 'active'); ?>>Active</option>
                        <option value="inactive" <?php selected($data['event_status'], 'inactive'); ?>>Inactive</option>
                    </select>
                    <p class="description">Inactive events won't accept new registrations.</p>
                </td>
            </tr>
        </table>

        <table class="form-table">
            <h2>Pricing & Registration Limits</h2>

            <tr>
                <th scope="row">
                    <label for="early_bird_deadline">Early Bird Deadline</label>
                </th>
                <td>
                    <input type="date" id="early_bird_deadline" name="early_bird_deadline"
                           value="<?php echo esc_attr($data['early_bird_deadline']); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="registration_deadline">Registration Deadline</label>
                </th>
                <td>
                    <input type="date" id="registration_deadline" name="registration_deadline"
                           value="<?php echo esc_attr($data['registration_deadline']); ?>" />
                    <p class="description">After this date, the registration form will be replaced with a 'Registration Closed' message. Leave blank to use default or no deadline.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="payment_deadline">Payment Deadline</label>
                </th>
                <td>
                    <input type="date" id="payment_deadline" name="payment_deadline"
                           value="<?php echo esc_attr($data['payment_deadline']); ?>" />
                    <p class="description">Last day for balance payments. Payment completion links will expire at 11:59 PM on this date. Leave blank for default 60-day expiration.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="early_bird_rate">Early Bird Rate ($)</label>
                </th>
                <td>
                    <input type="number" id="early_bird_rate" name="early_bird_rate"
                           value="<?php echo esc_attr($data['early_bird_rate']); ?>"
                           step="0.01" min="0" class="small-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="standard_rate">Standard Rate ($)</label>
                </th>
                <td>
                    <input type="number" id="standard_rate" name="standard_rate"
                           value="<?php echo esc_attr($data['standard_rate']); ?>"
                           step="0.01" min="0" class="small-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="deposit_amount">Deposit Amount ($)</label>
                </th>
                <td>
                    <input type="number" id="deposit_amount" name="deposit_amount"
                           value="<?php echo esc_attr($data['deposit_amount']); ?>"
                           step="0.01" min="0" class="small-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="min_students">Minimum Students per Team</label>
                </th>
                <td>
                    <input type="number" id="min_students" name="min_students"
                           value="<?php echo esc_attr($data['min_students']); ?>"
                           min="1" max="20" class="small-text" />
                    <p class="description">Minimum number of competing students required for registration.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="max_students">Maximum Students per Team</label>
                </th>
                <td>
                    <input type="number" id="max_students" name="max_students"
                           value="<?php echo esc_attr($data['max_students']); ?>"
                           min="1" max="20" class="small-text" />
                    <p class="description">Maximum number of competing students allowed per team.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="max_additional">Max Additional Attendees</label>
                </th>
                <td>
                    <input type="number" id="max_additional" name="max_additional"
                           value="<?php echo esc_attr($data['max_additional']); ?>"
                           min="0" max="20" class="small-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="free_coaches">Free Coaches</label>
                </th>
                <td>
                    <input type="number" id="free_coaches" name="free_coaches"
                           value="<?php echo esc_attr($data['free_coaches']); ?>"
                           min="0" max="10" class="small-text" />
                    <p class="description">Number of coaches that attend for free per team.</p>
                </td>
            </tr>
        </table>

        <table class="form-table">
            <h2>Session Configuration</h2>

            <tr>
                <th scope="row">
                    <label for="morning_session_time">Morning Session Time</label>
                </th>
                <td>
                    <input type="text" id="morning_session_time" name="morning_session_time"
                           value="<?php echo esc_attr($data['morning_session_time']); ?>"
                           class="regular-text" placeholder="e.g., 8:30 AM" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="afternoon_session_time">Afternoon Session Time</label>
                </th>
                <td>
                    <input type="text" id="afternoon_session_time" name="afternoon_session_time"
                           value="<?php echo esc_attr($data['afternoon_session_time']); ?>"
                           class="regular-text" placeholder="e.g., 12:15 PM" />
                </td>
            </tr>

            <tr>
                <th scope="row">Classifications by session</th>
                <td>
                    <p id="sbr-session-classes"><strong>Morning:</strong> <span data-session="morning"><?php echo esc_html($data['morning_classifications'] ?: '—'); ?></span><br>
                       <strong>Afternoon:</strong> <span data-session="afternoon"><?php echo esc_html($data['afternoon_classifications'] ?: '—'); ?></span></p>
                    <p class="description">Set by the divisions below. The registration form offers these classifications under each session.</p>
                </td>
            </tr>
        </table>

        <h2>Tournament Structure</h2>
        <p class="description" style="max-width: 760px;">The separate tournaments run on the day. Each classification can be in only one division.
            The scoring system reads this; changing it after scoring has started does not change games already played.</p>
        <?php if (!empty($sbr_config['synthesized'])): ?>
            <div class="notice notice-info inline"><p>This event was created before divisions existed, so these are the standard divisions for its classifications. Saving the event stores them.</p></div>
        <?php endif; ?>
        <div id="sbr-divisions">
            <?php foreach (array_values($sbr_config['divisions']) as $i => $division) {
                echo sbr_division_fields($i, $division);
            } ?>
        </div>
        <p><button type="button" class="button" id="sbr-add-division">Add a division</button></p>
        <template id="sbr-division-template"><?php echo sbr_division_fields('__INDEX__', array('key' => '', 'name' => '', 'session' => 'morning', 'classifications' => array(), 'format' => 'bracket', 'seeding_rounds' => 3, 'bracket_size' => 4, 'third_place_game' => true)); ?></template>

        <table class="form-table">
            <h2>Awards</h2>
            <tr>
                <th scope="row"><label for="sbr_class_places">Places per classification</label></th>
                <td><input type="number" id="sbr_class_places" name="sbr_awards[class_places]" value="<?php echo intval($sbr_awards['class_places']); ?>" min="0" max="10" class="small-text">
                    <p class="description">Team awards in each classification (2 = 1st and 2nd place).</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="sbr_division_places">Places per division</label></th>
                <td><input type="number" id="sbr_division_places" name="sbr_awards[division_places]" value="<?php echo intval($sbr_awards['division_places']); ?>" min="0" max="10" class="small-text">
                    <p class="description">Overall team awards in each division (1 = the division winner).</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="sbr_individual_places">All-tournament certificates</label></th>
                <td><input type="number" id="sbr_individual_places" name="sbr_awards[individual_places]" value="<?php echo intval($sbr_awards['individual_places']); ?>" min="0" max="30" class="small-text">
                    <p class="description">Top individual scorers recognized in each division.</p></td>
            </tr>
        </table>

        <table class="form-table">
            <h2>Team Rosters</h2>
            <tr>
                <th scope="row"><label for="roster_deadline">Roster deadline</label></th>
                <td><input type="datetime-local" id="roster_deadline" name="roster_deadline" value="<?php echo esc_attr($sbr_roster_deadline_value); ?>">
                    <p class="description">When coaches can no longer change their roster online (site time, <?php echo esc_html(wp_timezone_string()); ?>).
                    Leave blank for 11:59 pm the day before the event. After it, changes are made at check-in.</p></td>
            </tr>
        </table>

        <table class="form-table">
            <h2>Contact Information</h2>

            <tr>
                <th scope="row">
                    <label for="organization_name">Organization Name</label>
                </th>
                <td>
                    <input type="text" id="organization_name" name="organization_name"
                           value="<?php echo esc_attr($data['organization_name']); ?>"
                           class="regular-text" />
                    <p class="description">Name of the organization hosting this event (used in payment instructions and correspondence).</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="organizer_name">Organizer Name</label>
                </th>
                <td>
                    <input type="text" id="organizer_name" name="organizer_name"
                           value="<?php echo esc_attr($data['organizer_name']); ?>"
                           class="regular-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="organizer_email">Organizer Email</label>
                </th>
                <td>
                    <input type="email" id="organizer_email" name="organizer_email"
                           value="<?php echo esc_attr($data['organizer_email']); ?>"
                           class="regular-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="organizer_phone">Organizer Phone</label>
                </th>
                <td>
                    <input type="tel" id="organizer_phone" name="organizer_phone"
                           value="<?php echo esc_attr($data['organizer_phone']); ?>"
                           class="regular-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="payment_address">Payment Address</label>
                </th>
                <td>
                    <textarea id="payment_address" name="payment_address"
                              class="large-text" rows="4"><?php echo esc_textarea($data['payment_address']); ?></textarea>
                    <p class="description">Address for check payments (displayed on registration form).</p>
                </td>
            </tr>
        </table>

        <table class="form-table">
            <h2>Email Configuration (Optional Overrides)</h2>
            <tr>
                <td colspan="2">
                    <div class="notice notice-info inline" style="margin: 0; padding: 10px;">
                        <p><strong>Email Settings:</strong> Leave these fields empty to use the global email settings configured in <a href="<?php echo admin_url('admin.php?page=scholars-bowl-settings'); ?>">Settings</a>. Only fill them in if this event needs different email settings.</p>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="from_email">From Email Address</label>
                </th>
                <td>
                    <input type="email" id="from_email" name="from_email"
                           value="<?php echo esc_attr($data['from_email']); ?>"
                           class="regular-text" placeholder="<?php echo esc_attr(get_option('sbr_default_from_email', 'noreply@yourdomain.com')); ?>" />
                    <p class="description">
                        Event-specific from email address. If empty, uses global default:
                        <strong><?php echo esc_html(get_option('sbr_default_from_email', 'Not configured')); ?></strong>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="reply_to_email">Reply-To Email Address</label>
                </th>
                <td>
                    <input type="email" id="reply_to_email" name="reply_to_email"
                           value="<?php echo esc_attr($data['reply_to_email']); ?>"
                           class="regular-text" placeholder="<?php echo esc_attr(get_option('sbr_default_reply_to_email', 'contact@yourdomain.com')); ?>" />
                    <p class="description">
                        Event-specific reply-to email address. If empty, uses global default:
                        <strong><?php echo esc_html(get_option('sbr_default_reply_to_email', 'Not configured')); ?></strong>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="from_name">From Name</label>
                </th>
                <td>
                    <input type="text" id="from_name" name="from_name"
                           value="<?php echo esc_attr($data['from_name']); ?>"
                           class="regular-text" placeholder="<?php echo esc_attr(get_option('sbr_default_from_name', 'Your Organization')); ?>" />
                    <p class="description">
                        Event-specific sender name. If empty, uses global default:
                        <strong><?php echo esc_html(get_option('sbr_default_from_name', 'Not configured')); ?></strong>
                    </p>
                </td>
            </tr>
        </table>

        <table class="form-table">
            <h2>Payment Settings</h2>
            <tr>
                <td colspan="2">
                    <div class="notice notice-info inline" style="margin: 0; padding: 10px;">
                        <p><strong>Stripe Payment Integration:</strong> Configure payment mode and keys for this event.
                        <a href="<?php echo admin_url('admin.php?page=scholars-bowl-settings'); ?>">Configure Global Stripe Settings</a></p>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="stripe_mode">Payment Mode</label>
                </th>
                <td>
                    <select id="stripe_mode" name="stripe_mode">
                        <option value="test" <?php selected($data['stripe_mode'], 'test'); ?>>🧪 Test Mode (Safe for testing)</option>
                        <option value="live" <?php selected($data['stripe_mode'], 'live'); ?>>🔴 Live Mode (Real payments)</option>
                    </select>
                    <p class="description">
                        <strong>Test Mode:</strong> Uses test keys - no real charges are made.<br>
                        <strong>Live Mode:</strong> Uses live keys - real money will be charged!
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label>Key Configuration</label>
                </th>
                <td>
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa;">
                        <p><strong>Option 1: Use Global Keys (Recommended)</strong></p>
                        <p>Leave the key fields below empty to use the global Stripe keys configured in Settings.
                        The system will automatically use test or live keys based on the mode selected above.</p>

                        <p><strong>Option 2: Event-Specific Keys</strong></p>
                        <p>Only fill in the fields below if this specific event needs different Stripe keys than the global settings.</p>
                    </div>
                </td>
            </tr>

            <tr class="stripe-test-keys" style="display: <?php echo $data['stripe_mode'] === 'test' ? 'table-row' : 'none'; ?>">
                <th scope="row">
                    <label for="stripe_test_publishable_key">Test Publishable Key (Optional)</label>
                </th>
                <td>
                    <input type="text" id="stripe_test_publishable_key" name="stripe_test_publishable_key"
                           value="<?php echo esc_attr($data['stripe_test_publishable_key']); ?>"
                           class="large-text" placeholder="pk_test_..." />
                    <p class="description">Leave empty to use global test key.</p>
                </td>
            </tr>

            <tr class="stripe-test-keys" style="display: <?php echo $data['stripe_mode'] === 'test' ? 'table-row' : 'none'; ?>">
                <th scope="row">
                    <label for="stripe_test_secret_key">Test Secret Key (Optional)</label>
                </th>
                <td>
                    <input type="password" id="stripe_test_secret_key" name="stripe_test_secret_key"
                           value="<?php echo esc_attr($data['stripe_test_secret_key']); ?>"
                           class="large-text" placeholder="sk_test_..." />
                    <p class="description">Leave empty to use global test key.</p>
                </td>
            </tr>

            <tr class="stripe-live-keys" style="display: <?php echo $data['stripe_mode'] === 'live' ? 'table-row' : 'none'; ?>">
                <th scope="row">
                    <label for="stripe_live_publishable_key">Live Publishable Key (Optional)</label>
                </th>
                <td>
                    <input type="text" id="stripe_live_publishable_key" name="stripe_live_publishable_key"
                           value="<?php echo esc_attr($data['stripe_live_publishable_key']); ?>"
                           class="large-text" placeholder="pk_live_..." />
                    <p class="description">Leave empty to use global live key.</p>
                </td>
            </tr>

            <tr class="stripe-live-keys" style="display: <?php echo $data['stripe_mode'] === 'live' ? 'table-row' : 'none'; ?>">
                <th scope="row">
                    <label for="stripe_live_secret_key">Live Secret Key (Optional)</label>
                </th>
                <td>
                    <input type="password" id="stripe_live_secret_key" name="stripe_live_secret_key"
                           value="<?php echo esc_attr($data['stripe_live_secret_key']); ?>"
                           class="large-text" placeholder="sk_live_..." />
                    <p class="description">Leave empty to use global live key.</p>
                </td>
            </tr>

            <!-- Legacy keys for backward compatibility -->
            <tr>
                <th scope="row">
                    <label for="stripe_publishable_key">Legacy Publishable Key</label>
                </th>
                <td>
                    <input type="text" id="stripe_publishable_key" name="stripe_publishable_key"
                           value="<?php echo esc_attr($data['stripe_publishable_key']); ?>"
                           class="large-text" />
                    <p class="description">Legacy field for backward compatibility.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="stripe_secret_key">Legacy Secret Key</label>
                </th>
                <td>
                    <input type="password" id="stripe_secret_key" name="stripe_secret_key"
                           value="<?php echo esc_attr($data['stripe_secret_key']); ?>"
                           class="large-text" />
                    <p class="description">Legacy field for backward compatibility.</p>
                </td>
            </tr>
        </table>

        <?php submit_button($is_edit ? 'Update Event' : 'Create Event'); ?>
    </form>

    <?php if ($is_edit): ?>
        <div class="card" style="margin-top: 20px;">
            <h3>Shortcode for this Event</h3>
            <p>Use this shortcode to display the registration form for this event:</p>
            <code>[scholars_bowl_registration event="<?php echo esc_attr($data['event_slug']); ?>"]</code>
            <button type="button" class="button copy-shortcode" data-shortcode='[scholars_bowl_registration event="<?php echo esc_attr($data['event_slug']); ?>"]'>Copy Shortcode</button>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Auto-generate slug from event name
    $('#event_name').on('input', function() {
        if ($('#event_slug').val() === '' || $('#event_slug').data('auto-generated')) {
            var slug = $(this).val()
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            $('#event_slug').val(slug).data('auto-generated', true);
        }
    });

    $('#event_slug').on('input', function() {
        $(this).data('auto-generated', false);
    });

    // Copy shortcode functionality
    $('.copy-shortcode').on('click', function() {
        var shortcode = $(this).data('shortcode');
        navigator.clipboard.writeText(shortcode).then(function() {
            $(this).text('Copied!').addClass('button-primary');
            setTimeout(function() {
                $(this).text('Copy Shortcode').removeClass('button-primary');
            }.bind(this), 2000);
        }.bind(this));
    });

    // Toggle Stripe key fields based on mode
    $('#stripe_mode').on('change', function() {
        var mode = $(this).val();
        if (mode === 'test') {
            $('.stripe-test-keys').show();
            $('.stripe-live-keys').hide();
        } else {
            $('.stripe-test-keys').hide();
            $('.stripe-live-keys').show();
        }
    });

    // Tournament structure: add/remove divisions, bracket fields, derived session lists
    var $divisions = $('#sbr-divisions');
    var nextIndex = $divisions.children('.sbr-division').length;
    var order = ['Private', '1A', '2A', '3A', '4A', '5A', '6A', '7A'];
    function refreshDivisions() {
        var sessions = { morning: [], afternoon: [] };
        $divisions.children('.sbr-division').each(function () {
            var $d = $(this);
            var bracket = $d.find('.sbr-division-format').val() === 'bracket';
            $d.find('.sbr-bracket-only').toggle(bracket);
            var name = $.trim($d.find('.sbr-division-name').val());
            $d.find('.sbr-division-title').text(name || 'New division');
            var session = $d.find('select[name$="[session]"]').val();
            $d.find('input[type=checkbox][name$="[classifications][]"]:checked').each(function () {
                sessions[session].push(this.value);
            });
        });
        $.each(sessions, function (key, list) {
            list.sort(function (a, b) { return order.indexOf(a) - order.indexOf(b); });
            $('#sbr-session-classes [data-session="' + key + '"]').text(list.length ? list.join(', ') : '—');
        });
    }
    $divisions.on('change input', 'input, select', refreshDivisions);
    $('#sbr-add-division').on('click', function () {
        var html = $('#sbr-division-template').html().replace(/__INDEX__/g, String(nextIndex++));
        var $new = $(html).appendTo($divisions);
        refreshDivisions();
        $new.find('.sbr-division-name').trigger('focus');
    });
    $divisions.on('click', '.sbr-remove-division', function () {
        var $d = $(this).closest('.sbr-division');
        var name = $.trim($d.find('.sbr-division-name').val()) || 'this division';
        if (confirm('Remove ' + name + '?')) {
            $d.remove();
            refreshDivisions();
            $('#sbr-add-division').trigger('focus');
        }
    });
    refreshDivisions();

    // Validate minimum vs maximum students
    $('#min_students, #max_students').on('change', function() {
        var minStudents = parseInt($('#min_students').val()) || 1;
        var maxStudents = parseInt($('#max_students').val()) || 6;

        if (minStudents > maxStudents) {
            alert('Minimum students cannot be greater than maximum students.');
            if ($(this).attr('id') === 'min_students') {
                $(this).val(maxStudents);
            } else {
                $(this).val(minStudents);
            }
        }
    });
});
</script>

<style>
.form-table h2 {
    color: #1d2327;
    font-size: 1.3em;
    margin: 20px 0 10px 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #ccd0d4;
}
.card {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px;
    margin-top: 20px;
}
.copy-shortcode {
    margin-left: 10px;
}
.sbr-division {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-left: 4px solid #24408f;
    border-radius: 4px;
    padding: 4px 16px 8px;
    margin: 0 0 12px;
    max-width: 960px;
}
.sbr-division > legend {
    font-weight: 600;
    font-size: 14px;
    padding: 0 6px;
}
.sbr-division-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 4px 20px;
    align-items: start;
}
.sbr-division-grid label {
    display: block;
    font-weight: 600;
    margin-bottom: 2px;
}
.sbr-division-grid label.sbr-check,
.sbr-division-classes label.sbr-check {
    display: inline-block;
    font-weight: 400;
    margin-right: 10px;
}
.sbr-division-classes {
    grid-column: 1 / -1;
    margin: 4px 0;
}
.sbr-division-classes legend {
    font-weight: 600;
    margin-bottom: 4px;
}
</style>