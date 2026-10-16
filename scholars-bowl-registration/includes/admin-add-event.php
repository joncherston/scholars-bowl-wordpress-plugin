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
                <th scope="row">
                    <label for="morning_classifications">Morning Session School Classifications</label>
                </th>
                <td>
                    <input type="text" id="morning_classifications" name="morning_classifications"
                           value="<?php echo esc_attr($data['morning_classifications']); ?>"
                           class="large-text" placeholder="e.g., Private,1A,2A,3A" />
                    <p class="description">Comma-separated list of school classifications for morning session.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="afternoon_classifications">Afternoon Session School Classifications</label>
                </th>
                <td>
                    <input type="text" id="afternoon_classifications" name="afternoon_classifications"
                           value="<?php echo esc_attr($data['afternoon_classifications']); ?>"
                           class="large-text" placeholder="e.g., 4A,5A,6A" />
                    <p class="description">Comma-separated list of school classifications for afternoon session.</p>
                </td>
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
</style>