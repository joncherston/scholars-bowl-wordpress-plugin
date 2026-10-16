<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>Global Settings</h1>
    <p>These settings apply globally to all events. Individual events can override these defaults.</p>

    <form method="post" action="">
        <?php wp_nonce_field('sbr_settings', 'sbr_settings_nonce'); ?>

        <h2>Payment Processing</h2>
        <p class="description">Configure your Stripe keys for both test and live modes. Events can be set to use either mode independently.</p>

        <div class="stripe-mode-toggle" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa;">
            <h3 style="margin-top: 0;">Stripe Mode Control</h3>
            <div id="stripe-mode-indicator">
                <span class="mode-badge test-mode">🧪 Test Mode</span>
                <span class="mode-badge live-mode" style="display: none;">🔴 Live Mode</span>
            </div>
            <div style="margin: 15px 0;">
                <button type="button" id="toggle-stripe-mode" class="button button-secondary">
                    <span id="toggle-text">Switch to Live Mode</span>
                </button>
                <input type="hidden" id="sbr_stripe_mode" name="sbr_stripe_mode" value="<?php echo esc_attr(get_option('sbr_stripe_mode', 'test')); ?>">
            </div>
            <p class="description">Configure both test and live keys below, then use the toggle to switch between modes. This setting will be used by default for new events.</p>
        </div>

        <h3>Test Mode Keys (for testing payments)</h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="sbr_stripe_test_publishable_key">Test Publishable Key</label>
                </th>
                <td>
                    <input type="text" id="sbr_stripe_test_publishable_key" name="sbr_stripe_test_publishable_key"
                           value="<?php echo esc_attr(get_option('sbr_stripe_test_publishable_key')); ?>"
                           class="large-text" placeholder="pk_test_..." />
                    <p class="description">Your Stripe test publishable key (starts with pk_test_).</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sbr_stripe_test_secret_key">Test Secret Key</label>
                </th>
                <td>
                    <input type="password" id="sbr_stripe_test_secret_key" name="sbr_stripe_test_secret_key"
                           value="<?php echo esc_attr(get_option('sbr_stripe_test_secret_key')); ?>"
                           class="large-text" placeholder="sk_test_..." />
                    <p class="description">Your Stripe test secret key (starts with sk_test_). Safe for testing.</p>
                </td>
            </tr>
        </table>

        <h3>Live Mode Keys (for real payments)</h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="sbr_stripe_live_publishable_key">Live Publishable Key</label>
                </th>
                <td>
                    <input type="text" id="sbr_stripe_live_publishable_key" name="sbr_stripe_live_publishable_key"
                           value="<?php echo esc_attr(get_option('sbr_stripe_live_publishable_key')); ?>"
                           class="large-text" placeholder="pk_live_..." />
                    <p class="description">Your Stripe live publishable key (starts with pk_live_).</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sbr_stripe_live_secret_key">Live Secret Key</label>
                </th>
                <td>
                    <input type="password" id="sbr_stripe_live_secret_key" name="sbr_stripe_live_secret_key"
                           value="<?php echo esc_attr(get_option('sbr_stripe_live_secret_key')); ?>"
                           class="large-text" placeholder="sk_live_..." />
                    <p class="description">Your Stripe live secret key (starts with sk_live_). Keep this secure!</p>
                </td>
            </tr>
        </table>


        <h2>Email Configuration</h2>
        <p class="description">Configure default email settings for confirmation emails and notifications.</p>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="sbr_default_from_email">Default From Email Address</label>
                </th>
                <td>
                    <input type="email" id="sbr_default_from_email" name="sbr_default_from_email"
                           value="<?php echo esc_attr(get_option('sbr_default_from_email')); ?>"
                           class="regular-text" placeholder="noreply@yourdomain.com" />
                    <p class="description">Default email address that confirmation emails will be sent from.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sbr_default_reply_to_email">Default Reply-To Email Address</label>
                </th>
                <td>
                    <input type="email" id="sbr_default_reply_to_email" name="sbr_default_reply_to_email"
                           value="<?php echo esc_attr(get_option('sbr_default_reply_to_email')); ?>"
                           class="regular-text" placeholder="contact@yourdomain.com" />
                    <p class="description">Default email address for recipients to reply to.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sbr_default_from_name">Default From Name</label>
                </th>
                <td>
                    <input type="text" id="sbr_default_from_name" name="sbr_default_from_name"
                           value="<?php echo esc_attr(get_option('sbr_default_from_name')); ?>"
                           class="regular-text" placeholder="Your Organization Name" />
                    <p class="description">Default name that will appear as the sender of confirmation emails.</p>
                </td>
            </tr>
        </table>

        <h2>Default Contact Information</h2>
        <p class="description">These defaults will be used when creating new events.</p>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="sbr_organization_name">Default Organization Name</label>
                </th>
                <td>
                    <input type="text" id="sbr_organization_name" name="sbr_organization_name"
                           value="<?php echo esc_attr(get_option('sbr_organization_name')); ?>"
                           class="regular-text" />
                    <p class="description">Name of the organization (used in payment instructions and correspondence).</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sbr_organizer_name">Default Organizer Name</label>
                </th>
                <td>
                    <input type="text" id="sbr_organizer_name" name="sbr_organizer_name"
                           value="<?php echo esc_attr(get_option('sbr_organizer_name')); ?>"
                           class="regular-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sbr_organizer_email">Default Organizer Email</label>
                </th>
                <td>
                    <input type="email" id="sbr_organizer_email" name="sbr_organizer_email"
                           value="<?php echo esc_attr(get_option('sbr_organizer_email')); ?>"
                           class="regular-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sbr_organizer_phone">Default Organizer Phone</label>
                </th>
                <td>
                    <input type="tel" id="sbr_organizer_phone" name="sbr_organizer_phone"
                           value="<?php echo esc_attr(get_option('sbr_organizer_phone')); ?>"
                           class="regular-text" />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sbr_payment_address">Default Payment Mailing Address</label>
                </th>
                <td>
                    <textarea id="sbr_payment_address" name="sbr_payment_address"
                              rows="4" cols="50"><?php echo esc_textarea(get_option('sbr_payment_address')); ?></textarea>
                    <p class="description">Default address for check/cash payments.</p>
                </td>
            </tr>
        </table>


        <h2>Registration Deadline</h2>
        <p class="description">Set a default deadline for when registrations will close. Individual events can override this default.</p>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="sbr_default_registration_deadline">Default Registration Deadline</label>
                </th>
                <td>
                    <input type="date" id="sbr_default_registration_deadline" name="sbr_default_registration_deadline"
                           value="<?php echo esc_attr(get_option('sbr_default_registration_deadline')); ?>"
                           class="regular-text" />
                    <p class="description">After this date/time, the registration form will be replaced with a "Registration Closed" message. Leave blank for no deadline.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="sbr_registration_closed_message">Registration Closed Message</label>
                </th>
                <td>
                    <textarea id="sbr_registration_closed_message" name="sbr_registration_closed_message"
                              rows="4" cols="50" class="large-text"><?php echo esc_textarea(get_option('sbr_registration_closed_message', 'Registration for this event has closed. Please contact us if you have any questions.')); ?></textarea>
                    <p class="description">This message will be displayed when registration is closed. You can use HTML for formatting.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Save Global Settings'); ?>
    </form>

    <div class="card" style="margin-top: 20px;">
        <h2>Multi-Event System</h2>
        <p>This plugin now supports multiple events with individual configurations. Each event can have its own:</p>
        <ul>
            <li>Event name, date, and location</li>
            <li>Pricing structure (early bird rates, deposits)</li>
            <li>Session times and classifications</li>
            <li>Registration limits</li>
            <li>Payment settings (inherits global Stripe keys by default)</li>
        </ul>

        <h3>Legacy Settings Migration</h3>
        <p>If you were using the old single-event system, your settings have been migrated to a default event called "Scholars' Bowl Competition 2025".</p>

        <h3>How to Use</h3>
        <ol>
            <li><strong>Configure global Stripe keys above</strong> - All events will use these by default</li>
            <li><strong>Create events</strong> using "Add New Event" with specific configurations</li>
            <li><strong>Use event-specific shortcodes</strong> like: <code>[scholars_bowl_registration event="event-slug"]</code></li>
        </ol>
    </div>
</div>

<style>
.card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}
.card h2 { margin-top: 0; }
.card ul { padding-left: 20px; }
.card ol { padding-left: 20px; }
.card code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }

.mode-badge {
    display: inline-block;
    padding: 8px 15px;
    border-radius: 20px;
    font-weight: bold;
    font-size: 14px;
    margin-right: 10px;
}

.mode-badge.test-mode {
    background: #e7f3ff;
    color: #0073aa;
    border: 2px solid #0073aa;
}

.mode-badge.live-mode {
    background: #ffe7e7;
    color: #dc3232;
    border: 2px solid #dc3232;
}

.stripe-mode-toggle {
    border-radius: 6px;
}

#stripe-mode-indicator {
    margin: 10px 0;
}

#toggle-stripe-mode {
    padding: 10px 20px;
    font-weight: bold;
    border-radius: 6px;
    transition: all 0.3s ease;
}

#toggle-stripe-mode:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

#toggle-stripe-mode.test-mode {
    background: #0073aa;
    color: white;
    border-color: #005a87;
}

#toggle-stripe-mode.live-mode {
    background: #dc3232;
    color: white;
    border-color: #a00;
}

.stripe-mode-control {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}
</style>

<script>
jQuery(document).ready(function($) {
    var currentMode = $('#sbr_stripe_mode').val() || 'test';

    function updateModeIndicator() {
        var testPubKey = $('#sbr_stripe_test_publishable_key').val().trim();
        var testSecKey = $('#sbr_stripe_test_secret_key').val().trim();
        var livePubKey = $('#sbr_stripe_live_publishable_key').val().trim();
        var liveSecKey = $('#sbr_stripe_live_secret_key').val().trim();

        var hasTestKeys = testPubKey && testSecKey;
        var hasLiveKeys = livePubKey && liveSecKey;

        // Show current active mode
        if (currentMode === 'live') {
            $('.mode-badge.test-mode').hide();
            if (hasLiveKeys) {
                $('.mode-badge.live-mode').show().text('🔴 Live Mode (Active)');
            } else {
                $('.mode-badge.live-mode').show().text('🔴 Live Mode (No Keys)');
            }
            $('#toggle-text').text('Switch to Test Mode');
            $('#toggle-stripe-mode').removeClass('test-mode').addClass('live-mode');
        } else {
            $('.mode-badge.live-mode').hide();
            if (hasTestKeys) {
                $('.mode-badge.test-mode').show().text('🧪 Test Mode (Active)');
            } else {
                $('.mode-badge.test-mode').show().text('🧪 Test Mode (No Keys)');
            }
            $('#toggle-text').text('Switch to Live Mode');
            $('#toggle-stripe-mode').removeClass('live-mode').addClass('test-mode');
        }

        // Enable/disable toggle button based on available keys
        var canToggle = (currentMode === 'test' && hasLiveKeys) || (currentMode === 'live' && hasTestKeys);
        $('#toggle-stripe-mode').prop('disabled', !canToggle);

        if (!canToggle) {
            var missingKeys = currentMode === 'test' ? 'live' : 'test';
            $('#toggle-stripe-mode').attr('title', 'Configure ' + missingKeys + ' keys to enable toggle');
        } else {
            $('#toggle-stripe-mode').removeAttr('title');
        }
    }

    // Handle toggle button click
    $('#toggle-stripe-mode').on('click', function() {
        currentMode = currentMode === 'test' ? 'live' : 'test';
        $('#sbr_stripe_mode').val(currentMode);
        updateModeIndicator();

        // Show success message
        var modeText = currentMode === 'test' ? 'Test' : 'Live';
        $('<div class="notice notice-info is-dismissible"><p><strong>Mode switched to ' + modeText + '!</strong> Don\'t forget to save your settings.</p></div>')
            .insertAfter('h1').delay(5000).fadeOut();
    });

    // Update indicator when keys change
    $('#sbr_stripe_test_publishable_key, #sbr_stripe_test_secret_key, #sbr_stripe_live_publishable_key, #sbr_stripe_live_secret_key').on('input', updateModeIndicator);

    // Initial update
    updateModeIndicator();
});
</script>