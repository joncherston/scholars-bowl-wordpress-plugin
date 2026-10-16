<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>Stripe Setup Guide</h1>
    <p>Follow this comprehensive guide to set up Stripe payments for your Scholars' Bowl registrations.</p>

    <div class="card">
        <h2>📋 Overview</h2>
        <p>Stripe is a secure payment processor that allows you to accept credit card payments online. This guide will walk you through:</p>
        <ul>
            <li>Creating a Stripe account</li>
            <li>Getting your API keys</li>
            <li>Setting up test and live modes</li>
            <li>Testing your payment setup</li>
            <li>Going live with real payments</li>
        </ul>
    </div>

    <div class="card">
        <h2>🚀 Step 1: Create Your Stripe Account</h2>
        <ol>
            <li>Go to <a href="https://stripe.com" target="_blank">stripe.com</a> and click "Start now"</li>
            <li>Fill out the registration form with your organization's information</li>
            <li>Verify your email address</li>
            <li>Complete your account setup with business details</li>
        </ol>
        <div class="notice notice-info">
            <p><strong>Note:</strong> You'll need to provide business information including tax ID, bank account details, and business verification documents.</p>
        </div>
    </div>

    <div class="card">
        <h2>🔑 Step 2: Get Your API Keys</h2>
        <h3>Test Mode Keys (for testing)</h3>
        <ol>
            <li>Log into your Stripe Dashboard</li>
            <li>Make sure you're in "Test mode" (toggle in the left sidebar)</li>
            <li>Go to "Developers" → "API keys"</li>
            <li>Copy your <strong>Publishable key</strong> (starts with <code>pk_test_</code>)</li>
            <li>Copy your <strong>Secret key</strong> (starts with <code>sk_test_</code>)</li>
            <li>Add these keys to your <a href="<?php echo admin_url('admin.php?page=scholars-bowl-settings'); ?>">Settings page</a></li>
        </ol>

        <h3>Live Mode Keys (for real payments)</h3>
        <ol>
            <li>Complete your Stripe account activation (business verification)</li>
            <li>Switch to "Live mode" in your Stripe Dashboard</li>
            <li>Go to "Developers" → "API keys"</li>
            <li>Copy your <strong>Live Publishable key</strong> (starts with <code>pk_live_</code>)</li>
            <li>Copy your <strong>Live Secret key</strong> (starts with <code>sk_live_</code>)</li>
            <li>Add these keys to your <a href="<?php echo admin_url('admin.php?page=scholars-bowl-settings'); ?>">Settings page</a></li>
        </ol>

        <div class="notice notice-warning">
            <p><strong>Security Warning:</strong> Never share your secret keys publicly. Store them securely and only enter them in your WordPress admin.</p>
        </div>
    </div>

    <div class="card">
        <h2>🧪 Step 3: Test Your Payment Setup</h2>
        <h3>Using Test Mode</h3>
        <ol>
            <li>Create a test event and set it to "Test Mode"</li>
            <li>Use the test registration form</li>
            <li>Use these test card numbers:</li>
        </ol>

        <table class="wp-list-table widefat">
            <thead>
                <tr>
                    <th>Card Number</th>
                    <th>Brand</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>4242424242424242</code></td>
                    <td>Visa</td>
                    <td>✅ Successful payment</td>
                </tr>
                <tr>
                    <td><code>4000000000000002</code></td>
                    <td>Visa</td>
                    <td>❌ Card declined</td>
                </tr>
                <tr>
                    <td><code>4000000000009995</code></td>
                    <td>Visa</td>
                    <td>⚠️ Insufficient funds</td>
                </tr>
            </tbody>
        </table>

        <p><strong>Test Details:</strong></p>
        <ul>
            <li>Use any future expiration date (e.g., 12/34)</li>
            <li>Use any 3-digit CVC (e.g., 123)</li>
            <li>Use any valid ZIP code</li>
        </ul>

        <div class="notice notice-info">
            <p><strong>What to test:</strong></p>
            <ul>
                <li>Registration form loads correctly</li>
                <li>Payment form appears when selecting credit card</li>
                <li>Test payments complete successfully</li>
                <li>Confirmation emails are sent</li>
                <li>Registration appears in your admin panel</li>
            </ul>
        </div>
    </div>

    <div class="card">
        <h2>🔴 Step 4: Going Live</h2>
        <h3>Prerequisites</h3>
        <ul>
            <li>✅ Stripe account fully activated</li>
            <li>✅ Business verification completed</li>
            <li>✅ Bank account connected</li>
            <li>✅ Test payments working correctly</li>
        </ul>

        <h3>Switch to Live Mode</h3>
        <ol>
            <li>Add your live API keys to the <a href="<?php echo admin_url('admin.php?page=scholars-bowl-settings'); ?>">Settings page</a></li>
            <li>Edit your event and change from "Test Mode" to "Live Mode"</li>
            <li>Test with a small amount first</li>
            <li>Monitor your Stripe Dashboard for incoming payments</li>
        </ol>

        <div class="notice notice-error">
            <p><strong>Important:</strong> In Live Mode, real credit cards will be charged real money. Make sure you're ready before switching!</p>
        </div>
    </div>

    <div class="card">
        <h2>💡 Tips & Best Practices</h2>
        <ul>
            <li><strong>Start with Test Mode:</strong> Always test thoroughly before going live</li>
            <li><strong>Monitor Payments:</strong> Check your Stripe Dashboard regularly</li>
            <li><strong>Handle Disputes:</strong> Respond promptly to any payment disputes</li>
            <li><strong>Keep Records:</strong> Export registration data regularly</li>
            <li><strong>Update Information:</strong> Keep your Stripe account information current</li>
        </ul>
    </div>

    <div class="card">
        <h2>🆘 Troubleshooting</h2>
        <h3>Common Issues</h3>

        <h4>Payment form doesn't appear</h4>
        <ul>
            <li>Check that Stripe keys are entered correctly</li>
            <li>Verify the event is set to correct mode (test/live)</li>
            <li>Check browser console for JavaScript errors</li>
        </ul>

        <h4>Payments fail with "Invalid API key"</h4>
        <ul>
            <li>Verify you're using the correct key for your mode</li>
            <li>Check for extra spaces when copying keys</li>
            <li>Ensure test keys start with pk_test_/sk_test_</li>
            <li>Ensure live keys start with pk_live_/sk_live_</li>
        </ul>

        <h4>Test payments work but live payments fail</h4>
        <ul>
            <li>Complete Stripe account activation</li>
            <li>Verify business information</li>
            <li>Check for any account restrictions</li>
        </ul>

        <h3>Getting Help</h3>
        <ul>
            <li><a href="https://stripe.com/docs" target="_blank">Stripe Documentation</a></li>
            <li><a href="https://support.stripe.com" target="_blank">Stripe Support</a></li>
            <li>Check your WordPress error logs</li>
            <li>Contact your web developer</li>
        </ul>
    </div>

    <div class="card">
        <h2>✅ Quick Setup Checklist</h2>
        <div class="setup-checklist">
            <label><input type="checkbox"> Created Stripe account</label><br>
            <label><input type="checkbox"> Completed business verification</label><br>
            <label><input type="checkbox"> Added test API keys to Settings</label><br>
            <label><input type="checkbox"> Created test event in Test Mode</label><br>
            <label><input type="checkbox"> Successfully processed test payment</label><br>
            <label><input type="checkbox"> Confirmed registration appears in admin</label><br>
            <label><input type="checkbox"> Confirmed confirmation email sent</label><br>
            <label><input type="checkbox"> Added live API keys to Settings</label><br>
            <label><input type="checkbox"> Connected bank account to Stripe</label><br>
            <label><input type="checkbox"> Ready to switch event to Live Mode</label><br>
        </div>
    </div>
</div>

<style>
.card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.card h2 {
    margin-top: 0;
    color: #1d2327;
    border-bottom: 1px solid #ddd;
    padding-bottom: 10px;
}

.card h3 {
    color: #1d2327;
    margin-top: 20px;
}

.card h4 {
    color: #1d2327;
    margin-top: 15px;
    margin-bottom: 5px;
}

.card ul, .card ol {
    padding-left: 20px;
}

.card code {
    background: #f0f0f1;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: Monaco, Consolas, monospace;
}

.setup-checklist label {
    display: block;
    margin: 8px 0;
    font-weight: 500;
}

.setup-checklist input[type="checkbox"] {
    margin-right: 8px;
}

.wp-list-table {
    margin: 15px 0;
}

.wp-list-table th {
    font-weight: 600;
}

.wp-list-table td code {
    font-weight: 600;
    color: #0073aa;
}
</style>