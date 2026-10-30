<?php
/**
 * Payment Completion Template
 * Secure payment completion page for balance payments
 */

if (!defined('ABSPATH')) {
    exit;
}

global $sbrPaymentData;
?>

<div class="sbr-payment-completion sbr-scope">
    <div class="sbr-completion-header sbr-card">
        <h2>Complete Your Payment</h2>
        <p>Please complete your remaining balance to finalize your registration.</p>
    </div>

    <div class="sbr-registration-summary sbr-card">
        <h3>Registration Details</h3>
        <div class="sbr-summary-grid">
            <div class="sbr-summary-item">
                <strong>School:</strong> <?php echo esc_html($token_data->school_name); ?>
            </div>
            <div class="sbr-summary-item">
                <strong>Contact:</strong> <?php echo esc_html($token_data->contact_name); ?>
            </div>
            <div class="sbr-summary-item">
                <strong>Amount Due:</strong> <span class="sbr-amount">$<?php echo number_format($token_data->amount_due, 2); ?></span>
            </div>
        </div>
    </div>

    <form id="sbr-payment-completion-form" class="sbr-payment-form">
        <!-- Anti-spam honeypot field -->
        <div class="sbr-hp" aria-hidden="true">
            <label for="website">Website (leave blank):</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="sbr-payment-section sbr-card">
            <h3>Payment Information</h3>

            <div class="sbr-form-row">
                <div class="sbr-form-field">
                    <p class="sbr-label" id="sbr-card-label">Credit or debit card <span class="sbr-req" aria-hidden="true">*</span></p>
                    <div id="sbr-card-element" class="sbr-stripe-element" aria-labelledby="sbr-card-label">
                        <!-- Stripe Elements will create form elements here -->
                    </div>
                    <div id="sbr-card-errors" class="sbr-card-errors" role="alert"></div>
                </div>
            </div>

            <div class="sbr-form-row">
                <div class="sbr-form-field">
                    <label for="billing_name">Name on Card *</label>
                    <input type="text" id="billing_name" name="billing_name" required>
                </div>
                <div class="sbr-form-field">
                    <label for="billing_email">Billing Email *</label>
                    <input type="email" id="billing_email" name="billing_email" required
                           value="<?php echo esc_attr($token_data->contact_email); ?>">
                </div>
            </div>

            <div class="sbr-form-row">
                <div class="sbr-form-field">
                    <label for="billing_address">Billing Address *</label>
                    <input type="text" id="billing_address" name="billing_address" required>
                </div>
                <div class="sbr-form-field">
                    <label for="billing_city">City *</label>
                    <input type="text" id="billing_city" name="billing_city" required>
                </div>
            </div>

            <div class="sbr-form-row">
                <div class="sbr-form-field">
                    <label for="billing_state">State *</label>
                    <input type="text" id="billing_state" name="billing_state" required maxlength="2"
                           placeholder="AL">
                </div>
                <div class="sbr-form-field">
                    <label for="billing_zip">ZIP Code *</label>
                    <input type="text" id="billing_zip" name="billing_zip" required
                           pattern="[0-9]{5}(-[0-9]{4})?" placeholder="12345">
                </div>
            </div>

            <div class="sbr-payment-total">
                <div class="sbr-total-line">
                    <span class="sbr-total-label">Amount to Pay:</span>
                    <span class="sbr-total-amount">$<?php echo number_format($token_data->amount_due, 2); ?></span>
                </div>
            </div>

            <div class="sbr-payment-processing" id="sbr-payment-processing" role="status" style="display: none;">
                <div class="sbr-processing-spinner" aria-hidden="true"></div>
                <span>Processing payment...</span>
            </div>

            <div class="sbr-submit-section">
                <button type="submit" id="sbr-submit-payment" class="sbr-submit-btn">
                    Complete Payment - $<?php echo number_format($token_data->amount_due, 2); ?>
                </button>
                <p class="sbr-security-note">
                    <small><span aria-hidden="true">🔒</span> Your payment is processed securely through Stripe. We do not store your credit card information.</small>
                </p>
            </div>
        </div>
    </form>

    <div class="sbr-payment-success sbr-card" id="sbr-payment-success" role="status" style="display: none;">
        <div class="sbr-success-icon" aria-hidden="true">✓</div>
        <h3>Payment Completed Successfully!</h3>
        <p>Thank you for completing your payment. You will receive a confirmation email shortly.</p>
        <p>Your registration is now fully paid and confirmed.</p>
    </div>

    <div class="sbr-payment-error sbr-card" id="sbr-payment-error" role="alert" style="display: none;">
        <div class="sbr-error-icon" aria-hidden="true">!</div>
        <h3>Payment Error</h3>
        <p id="sbr-error-message">There was an error processing your payment. Please try again.</p>
        <button type="button" id="sbr-retry-payment" class="sbr-btn-secondary">Try Again</button>
    </div>
</div>
<?php /* Styles: assets/frontend.css (1.1.0). */ ?>
