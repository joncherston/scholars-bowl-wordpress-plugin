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

<div class="sbr-payment-completion">
    <div class="sbr-completion-header">
        <h2>Complete Your Payment</h2>
        <p>Please complete your remaining balance to finalize your registration.</p>
    </div>

    <div class="sbr-registration-summary">
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
        <div style="position: absolute; left: -9999px;">
            <label for="website">Website (leave blank):</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="sbr-payment-section">
            <h3>Payment Information</h3>

            <div class="sbr-form-row">
                <div class="sbr-form-field">
                    <label for="sbr-card-element">Credit or Debit Card *</label>
                    <div id="sbr-card-element" class="sbr-stripe-element">
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

            <div class="sbr-payment-processing" id="sbr-payment-processing" style="display: none;">
                <div class="sbr-processing-spinner"></div>
                <span>Processing payment...</span>
            </div>

            <div class="sbr-submit-section">
                <button type="submit" id="sbr-submit-payment" class="sbr-submit-btn">
                    Complete Payment - $<?php echo number_format($token_data->amount_due, 2); ?>
                </button>
                <p class="sbr-security-note">
                    <small>🔒 Your payment is processed securely through Stripe. We do not store your credit card information.</small>
                </p>
            </div>
        </div>
    </form>

    <div class="sbr-payment-success" id="sbr-payment-success" style="display: none;">
        <div class="sbr-success-icon">✓</div>
        <h3>Payment Completed Successfully!</h3>
        <p>Thank you for completing your payment. You will receive a confirmation email shortly.</p>
        <p>Your registration is now fully paid and confirmed.</p>
    </div>

    <div class="sbr-payment-error" id="sbr-payment-error" style="display: none;">
        <div class="sbr-error-icon">⚠</div>
        <h3>Payment Error</h3>
        <p id="sbr-error-message">There was an error processing your payment. Please try again.</p>
        <button type="button" id="sbr-retry-payment" class="sbr-btn-secondary">Try Again</button>
    </div>
</div>

<style>
/* Payment Completion Styles */
.sbr-payment-completion {
    max-width: 600px;
    margin: 0 auto;
    padding: 2rem;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.sbr-completion-header {
    text-align: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}

.sbr-completion-header h2 {
    color: #007cba;
    margin-bottom: 0.5rem;
}

.sbr-registration-summary {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    border-left: 4px solid #007cba;
}

.sbr-registration-summary h3 {
    margin-top: 0;
    color: #007cba;
}

.sbr-summary-grid {
    display: grid;
    gap: 0.75rem;
}

.sbr-summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sbr-amount {
    font-size: 1.25rem;
    font-weight: bold;
    color: #007cba;
}

.sbr-payment-section {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #ddd;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.sbr-payment-section h3 {
    margin-top: 0;
    color: #333;
    border-bottom: 1px solid #eee;
    padding-bottom: 0.75rem;
}

.sbr-form-row {
    display: grid;
    gap: 1rem;
    margin-bottom: 1rem;
    grid-template-columns: 1fr;
}

@media (min-width: 600px) {
    .sbr-form-row {
        grid-template-columns: 1fr 1fr;
    }

    .sbr-form-row:last-of-type .sbr-form-field:first-child {
        max-width: 120px;
    }
}

.sbr-form-field {
    display: flex;
    flex-direction: column;
}

.sbr-form-field label {
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #333;
}

.sbr-form-field input {
    padding: 0.75rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
}

.sbr-form-field input:focus {
    outline: none;
    border-color: #007cba;
    box-shadow: 0 0 0 2px rgba(0,124,186,0.1);
}

.sbr-stripe-element {
    background-color: white;
    padding: 0.75rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    transition: border-color 0.3s ease;
}

.sbr-stripe-element.StripeElement--focus {
    border-color: #007cba;
    box-shadow: 0 0 0 2px rgba(0,124,186,0.1);
}

.sbr-stripe-element.StripeElement--invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 2px rgba(220,53,69,0.1);
}

.sbr-card-errors {
    color: #dc3545;
    font-size: 0.875rem;
    margin-top: 0.5rem;
    min-height: 1.25rem;
}

.sbr-payment-total {
    background: #e8f4f8;
    padding: 1rem;
    border-radius: 4px;
    margin: 1.5rem 0;
    border-left: 4px solid #007cba;
}

.sbr-total-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 1.125rem;
    font-weight: bold;
}

.sbr-total-amount {
    color: #007cba;
    font-size: 1.25rem;
}

.sbr-payment-processing {
    background: #e8f4f8;
    padding: 1rem;
    border-radius: 4px;
    text-align: center;
    margin: 1rem 0;
    border: 1px solid #bee5eb;
}

.sbr-processing-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #007cba;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-right: 0.5rem;
    vertical-align: middle;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.sbr-submit-section {
    text-align: center;
    margin-top: 1.5rem;
}

.sbr-submit-btn {
    background: #007cba;
    color: white;
    padding: 1rem 2rem;
    border: none;
    border-radius: 6px;
    font-size: 1.125rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    min-width: 250px;
}

.sbr-submit-btn:hover:not(:disabled) {
    background: #005a87;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,124,186,0.3);
}

.sbr-submit-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.sbr-security-note {
    margin-top: 1rem;
    color: #666;
}

.sbr-payment-success,
.sbr-payment-error {
    text-align: center;
    padding: 2rem;
    border-radius: 8px;
    margin-top: 2rem;
}

.sbr-payment-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.sbr-payment-error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.sbr-success-icon,
.sbr-error-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
}

.sbr-success-icon {
    color: #28a745;
}

.sbr-error-icon {
    color: #dc3545;
}

.sbr-btn-secondary {
    background: #6c757d;
    color: white;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    margin-top: 1rem;
}

.sbr-btn-secondary:hover {
    background: #5a6268;
}

.sbr-error {
    background: #f8d7da;
    color: #721c24;
    padding: 1rem;
    border-radius: 4px;
    border: 1px solid #f5c6cb;
    text-align: center;
}

/* Mobile responsive */
@media (max-width: 600px) {
    .sbr-payment-completion {
        padding: 1rem;
    }

    .sbr-registration-summary,
    .sbr-payment-section {
        padding: 1rem;
    }

    .sbr-submit-btn {
        width: 100%;
        min-width: auto;
    }
}
</style>