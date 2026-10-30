<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get event data from shortcode attributes
global $wpdb;
$events_table = $wpdb->prefix . 'scholars_bowl_events';
$event_slug = isset($atts['event']) ? $atts['event'] : 'scholars-bowl-2025';

$event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $events_table WHERE event_slug = %s AND event_status = 'active'", $event_slug));

if (!$event) {
    echo '<div class="sbr-error"><p>Event not found or inactive. Please check the shortcode event parameter.</p></div>';
    return;
}

// Extract event data
$event_name = $event->event_name;
$event_date = $event->event_date;
$event_location = $event->event_location;
$early_bird_deadline = $event->early_bird_deadline;
$early_bird_rate = $event->early_bird_rate;
$standard_rate = $event->standard_rate;
$min_students = $event->min_students;
$max_students = $event->max_students;
$max_additional = $event->max_additional;
$morning_session_time = $event->morning_session_time;
$afternoon_session_time = $event->afternoon_session_time;
$morning_classifications = explode(',', $event->morning_classifications);
$afternoon_classifications = explode(',', $event->afternoon_classifications);

$current_date = current_time('Y-m-d');
$is_early_bird = ($current_date <= $early_bird_deadline);
$current_rate = $is_early_bird ? $early_bird_rate : $standard_rate;


// Check registration deadline
$registration_deadline = $event->registration_deadline ? $event->registration_deadline : get_option('sbr_default_registration_deadline');
$registration_closed_message = get_option('sbr_registration_closed_message', 'Registration for this event has closed. Please contact us if you have any questions.');

$is_registration_closed = false;
if ($registration_deadline) {
    $current_date = current_time('Y-m-d');
    $is_registration_closed = ($current_date > $registration_deadline);
}

// If registration is closed, show message and return
if ($is_registration_closed) {
    ?>
    <div id="scholars-bowl-registration" class="sbr-scope">
        <div class="sbr-header sbr-card">
            <h2 class="sbr-page-title"><?php echo esc_html($event_name); ?></h2>
            <dl class="sbr-facts">
                <div><dt>Date</dt><dd><?php echo esc_html(date('F j, Y', strtotime($event_date))); ?></dd></div>
                <div><dt>Location</dt><dd><?php echo esc_html($event_location); ?></dd></div>
            </dl>
        </div>
        <div class="sbr-closed-message sbr-alert sbr-alert-warning" role="status">
            <h3>Registration closed</h3>
            <p><?php echo wp_kses_post($registration_closed_message); ?></p>
            <?php if ($event->organizer_email): ?>
                <p><strong>Contact:</strong> <a href="mailto:<?php echo esc_attr($event->organizer_email); ?>"><?php echo esc_html($event->organizer_email); ?></a></p>
            <?php endif; ?>
            <?php if ($event->organizer_phone): ?>
                <p><strong>Phone:</strong> <?php echo esc_html($event->organizer_phone); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return;
}

$min_students = intval($min_students);
$max_students = intval($max_students);
$max_additional = intval($max_additional);
$roster_ready = class_exists('SBR_Roster') && SBR_Schema::roster_ready();
$roster_deadline_text = $roster_ready ? SBR_Roster::format_deadline($event) : '';
$req = '<span class="sbr-req" aria-hidden="true">*</span>';
?>

<div id="scholars-bowl-registration" class="sbr-scope">
    <div class="sbr-header sbr-card">
        <h2 class="sbr-page-title"><?php echo esc_html($event_name); ?></h2>
        <dl class="sbr-facts">
            <div><dt>Date</dt><dd><?php echo esc_html(date('F j, Y', strtotime($event_date))); ?></dd></div>
            <div><dt>Location</dt><dd><?php echo esc_html($event_location); ?></dd></div>
        </dl>
        <div class="pricing-info">
            <?php if ($is_early_bird): ?>
                <p class="early-bird-notice">
                    <strong>Early bird pricing:</strong> $<?php echo esc_html(number_format($early_bird_rate, 2)); ?> per student
                    (until <?php echo esc_html(date('F j, Y', strtotime($early_bird_deadline))); ?>)
                </p>
            <?php else: ?>
                <p class="standard-pricing">
                    <strong>Standard pricing:</strong> $<?php echo esc_html(number_format($standard_rate, 2)); ?> per student
                </p>
            <?php endif; ?>
        </div>
    </div>

    <form id="sbr-registration-form" method="post" novalidate data-max-additional="<?php echo esc_attr($max_additional); ?>">
        <?php wp_nonce_field('sbr_nonce', 'sbr_nonce'); ?>
        <input type="hidden" id="event_id" name="event_id" value="<?php echo esc_attr($event->id); ?>">
        <input type="hidden" id="event_slug" name="event_slug" value="<?php echo esc_attr($event->event_slug); ?>">

        <!-- Honeypot field for spam protection (should remain empty) -->
        <div class="sbr-hp" aria-hidden="true">
            <label for="website">Website (leave blank):</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <p class="sbr-required-note">Fields marked <span class="sbr-req" aria-hidden="true">*</span><span class="sbr-sr-only">with an asterisk</span> are required.</p>

        <div class="form-section sbr-card">
            <h3>School Information</h3>
            <div class="form-row">
                <label for="school_name">School name <?php echo $req; ?></label>
                <input type="text" id="school_name" name="school_name" required aria-required="true" autocomplete="organization">
            </div>
        </div>

        <div class="form-section sbr-card">
            <h3>Contact Information</h3>
            <div class="form-row">
                <label for="contact_name">Contact name <?php echo $req; ?></label>
                <input type="text" id="contact_name" name="contact_name" required aria-required="true" autocomplete="name">
            </div>
            <div class="sbr-grid-2">
                <div class="form-row">
                    <label for="contact_email">Email address <?php echo $req; ?></label>
                    <input type="email" id="contact_email" name="contact_email" required aria-required="true" autocomplete="email">
                </div>
                <div class="form-row">
                    <label for="contact_phone">Phone number <?php echo $req; ?></label>
                    <input type="tel" id="contact_phone" name="contact_phone" required aria-required="true" autocomplete="tel">
                </div>
            </div>
            <div class="form-row">
                <label for="dietary_restrictions">Dietary restrictions / allergies</label>
                <textarea id="dietary_restrictions" name="dietary_restrictions" rows="3" aria-describedby="dietary_help"
                    placeholder="Please list any dietary restrictions, food allergies, or special dietary needs for your team (optional)"></textarea>
                <p class="description" id="dietary_help">Optional: help us accommodate your team's dietary needs.</p>
            </div>
        </div>

        <div class="form-section sbr-card">
            <h3>Team Information</h3>
            <div class="form-row">
                <label for="grade_level">School classification <?php echo $req; ?></label>
                <select id="grade_level" name="grade_level" required aria-required="true">
                    <option value="">Select...</option>
                    <optgroup label="Morning Session (<?php echo esc_attr($morning_session_time); ?>)">
                        <?php foreach ($morning_classifications as $classification): ?>
                            <?php $classification = trim($classification); ?>
                            <?php if (!empty($classification)): ?>
                                <option value="<?php echo esc_attr($classification); ?>" data-session="<?php echo esc_attr('Morning Session (' . $morning_session_time . ')'); ?>">
                                    <?php echo esc_html($classification === 'Private' ? 'Private School' : $classification); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Afternoon Session (<?php echo esc_attr($afternoon_session_time); ?>)">
                        <?php foreach ($afternoon_classifications as $classification): ?>
                            <?php $classification = trim($classification); ?>
                            <?php if (!empty($classification)): ?>
                                <option value="<?php echo esc_attr($classification); ?>" data-session="<?php echo esc_attr('Afternoon Session (' . $afternoon_session_time . ')'); ?>">
                                    <?php echo esc_html($classification); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div class="sbr-grid-2">
                <div class="form-row">
                    <label for="competing_students">Number of competing students <?php echo $req; ?></label>
                    <select id="competing_students" name="competing_students" required aria-required="true" aria-describedby="competing_help">
                        <option value="">Select...</option>
                        <?php for ($i = $min_students; $i <= $max_students; $i++): ?>
                            <option value="<?php echo intval($i); ?>"><?php echo intval($i); ?></option>
                        <?php endfor; ?>
                    </select>
                    <small id="competing_help">Between <?php echo intval($min_students); ?> and <?php echo intval($max_students); ?> competing students per team</small>
                </div>

                <div class="form-row">
                    <label for="coaches">Number of coaches</label>
                    <select id="coaches" name="coaches" aria-describedby="coaches_help">
                        <?php for ($i = 0; $i <= 4; $i++): ?>
                            <option value="<?php echo intval($i); ?>"><?php echo intval($i); ?></option>
                        <?php endfor; ?>
                    </select>
                    <small id="coaches_help">First <?php echo intval($event->free_coaches); ?> coaches are free, maximum 4 coaches total</small>
                </div>
            </div>

            <div class="sbr-grid-2">
                <div class="form-row">
                    <label for="additional_students">Additional student attendees</label>
                    <select id="additional_students" name="additional_students" aria-describedby="additional_help">
                        <?php for ($i = 0; $i <= $max_additional; $i++): ?>
                            <option value="<?php echo intval($i); ?>"><?php echo intval($i); ?></option>
                        <?php endfor; ?>
                    </select>
                    <small id="additional_help">Combined total of additional students and adults cannot exceed <?php echo intval($max_additional); ?></small>
                </div>

                <div class="form-row">
                    <label for="additional_adults">Additional adult attendees</label>
                    <select id="additional_adults" name="additional_adults" aria-describedby="adults_help">
                        <?php for ($i = 0; $i <= $max_additional; $i++): ?>
                            <option value="<?php echo intval($i); ?>"><?php echo intval($i); ?></option>
                        <?php endfor; ?>
                    </select>
                    <small id="adults_help">Parents, administrators, or other adult attendees</small>
                </div>
            </div>
            <p class="additional-helper" aria-live="polite"></p>
        </div>

        <?php if ($roster_ready): ?>
        <div class="form-section sbr-card sbr-roster-section">
            <h3>Competing Students <span class="sbr-optional">(optional)</span></h3>
            <p id="roster_help">If you know your team, enter your competing students' names and grades. If not, leave this blank:
               your confirmation email has a private link for adding or changing names until <?php echo esc_html($roster_deadline_text); ?>.
               Names don't change the price.</p>
            <?php echo SBR_Roster::rows_html(array(), array(), 'sbr', 0); ?>
            <p class="sbr-roster-hint sbr-muted" aria-live="polite"></p>
        </div>
        <?php endif; ?>

        <div class="form-section sbr-card">
            <h3>Payment Options</h3>
            <fieldset class="form-row sbr-fieldset">
                <legend>Payment type <?php echo $req; ?></legend>
                <div class="radio-group">
                    <label>
                        <input type="radio" name="payment_option" value="deposit" checked>
                        <span>Pay $<span id="deposit-amount"><?php echo esc_html(number_format($event->deposit_amount, 2)); ?></span> deposit now (flat fee)</span>
                    </label>
                    <label>
                        <input type="radio" name="payment_option" value="full">
                        <span>Pay full amount now</span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="form-row sbr-fieldset">
                <legend>Payment method <?php echo $req; ?></legend>
                <div class="radio-group">
                    <label>
                        <input type="radio" name="payment_method" value="credit" checked>
                        <span>Credit card (Stripe)</span>
                    </label>
                    <label>
                        <input type="radio" name="payment_method" value="check">
                        <span>Check (mail payment)</span>
                    </label>
                    <label>
                        <input type="radio" name="payment_method" value="cash">
                        <span>Cash (in-person payment)</span>
                    </label>
                </div>
            </fieldset>
        </div>

        <div class="form-section sbr-card cost-summary" aria-labelledby="cost_summary_heading">
            <h3 id="cost_summary_heading">Cost Summary</h3>
            <div id="cost-breakdown" aria-live="polite" aria-atomic="true">
                <div class="cost-line">
                    <span>Current rate</span>
                    <span class="sbr-num">$<span id="current-rate"><?php echo esc_html(number_format($current_rate, 2)); ?></span> per student</span>
                </div>
                <div class="cost-line">
                    <span>Total attendees</span>
                    <span class="sbr-num" id="total-attendees">0</span>
                </div>
                <div class="cost-line total-cost">
                    <span>Total amount</span>
                    <span class="sbr-num">$<span id="total-amount">0.00</span></span>
                </div>
                <div class="cost-line payment-amount">
                    <span>Payment amount</span>
                    <span class="sbr-num">$<span id="payment-amount">0.00</span></span>
                </div>
            </div>
        </div>

        <div id="credit-card-section" class="form-section sbr-card payment-section">
            <h3>Credit Card Information</h3>
            <div id="card-element" class="sbr-stripe-element">
                <!-- Stripe Elements will create form elements here -->
            </div>
            <div id="card-errors" class="sbr-field-error" role="alert"></div>
        </div>

        <div id="check-payment-section" class="form-section sbr-card payment-section">
            <h3>Check Payment Instructions</h3>
            <div class="payment-instructions">
                <p><strong>Send your check payment to:</strong></p>
                <div class="address-block">
                    <?php if (!empty($event->organization_name)): ?>
                        <?php echo esc_html($event->organization_name); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($event->organizer_name)): ?>
                        c/o <?php echo esc_html($event->organizer_name); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($event->payment_address)): ?>
                        <?php echo nl2br(esc_html($event->payment_address)); ?>
                    <?php else: ?>
                        <em>Payment address will be provided in confirmation email</em>
                    <?php endif; ?>
                </div>
                <p><strong>Make check payable to:</strong> <?php echo esc_html($event->organization_name ?: 'Organization Name'); ?></p>
                <p><em>Include your registration confirmation number on the memo line when you receive it.</em></p>
            </div>
        </div>

        <div id="cash-payment-section" class="form-section sbr-card payment-section">
            <h3>Cash Payment Instructions</h3>
            <p>After submitting this form, you will receive confirmation and cash payment instructions via email, including:</p>
            <ul>
                <li>Cash payment delivery details</li>
                <li>Payment amount and deadline</li>
                <li>Contact information for payment coordination</li>
                <li>Registration confirmation details</li>
            </ul>
        </div>

        <div class="form-actions">
            <div id="form-messages" tabindex="-1"></div>
            <button type="submit" id="submit-registration" class="sbr-submit-btn sbr-btn sbr-btn-primary sbr-btn-large">
                <span>Complete Registration</span>
            </button>
        </div>
    </form>
</div>
