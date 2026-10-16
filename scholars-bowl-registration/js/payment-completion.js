/**
 * Payment Completion JavaScript
 * Handles Stripe payment processing for balance completion
 */
(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        if (typeof sbrPaymentData === 'undefined' || !sbrPaymentData.stripe_key) {
            console.error('SBR: Payment data not available');
            return;
        }

        initializePaymentCompletion();
    });

    function initializePaymentCompletion() {
        // Initialize Stripe
        const stripe = Stripe(sbrPaymentData.stripe_key);
        const elements = stripe.elements();

        // Create card element
        const cardElement = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#424770',
                    '::placeholder': {
                        color: '#aab7c4',
                    },
                },
                invalid: {
                    color: '#9e2146',
                },
            },
        });

        // Mount card element
        cardElement.mount('#sbr-card-element');

        // Handle card element errors
        cardElement.on('change', function(event) {
            const displayError = document.getElementById('sbr-card-errors');
            if (event.error) {
                displayError.textContent = event.error.message;
            } else {
                displayError.textContent = '';
            }
        });

        // Handle form submission
        const form = document.getElementById('sbr-payment-completion-form');
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            handlePaymentSubmission(stripe, cardElement);
        });

        // Handle retry button
        $(document).on('click', '#sbr-retry-payment', function() {
            $('#sbr-payment-error').hide();
            $('#sbr-payment-completion-form').show();
        });
    }

    async function handlePaymentSubmission(stripe, cardElement) {
        const submitButton = document.getElementById('sbr-submit-payment');
        const processingDiv = document.getElementById('sbr-payment-processing');
        const form = document.getElementById('sbr-payment-completion-form');

        // Validate honeypot field
        const honeypot = form.querySelector('input[name="website"]');
        if (honeypot && honeypot.value.trim() !== '') {
            showError('Form submission detected as spam. Please try again.');
            return;
        }

        // Disable submit button and show processing
        submitButton.disabled = true;
        processingDiv.style.display = 'block';

        // Get form data
        const formData = new FormData(form);
        const billingDetails = {
            name: formData.get('billing_name'),
            email: formData.get('billing_email'),
            address: {
                line1: formData.get('billing_address'),
                city: formData.get('billing_city'),
                state: formData.get('billing_state'),
                postal_code: formData.get('billing_zip'),
                country: 'US',
            },
        };

        try {
            // Create payment method
            const {error, paymentMethod} = await stripe.createPaymentMethod({
                type: 'card',
                card: cardElement,
                billing_details: billingDetails,
            });

            if (error) {
                throw new Error(error.message);
            }

            // Process payment on server
            await processPaymentOnServer(paymentMethod.id);

        } catch (error) {
            console.error('Payment error:', error);
            showError(error.message || 'An error occurred processing your payment. Please try again.');
        } finally {
            // Re-enable submit button and hide processing
            submitButton.disabled = false;
            processingDiv.style.display = 'none';
        }
    }

    async function processPaymentOnServer(paymentMethodId) {
        const response = await fetch(sbrPaymentData.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'sbr_complete_payment',
                nonce: sbrPaymentData.nonce,
                token: sbrPaymentData.token,
                payment_method_id: paymentMethodId,
            }),
        });

        const result = await response.json();

        if (result.success) {
            showSuccess();
        } else if (result.client_secret) {
            // Handle 3D Secure authentication
            await handle3DSecure(result.client_secret);
        } else {
            throw new Error(result.message || 'Payment processing failed');
        }
    }

    async function handle3DSecure(clientSecret) {
        const stripe = Stripe(sbrPaymentData.stripe_key);

        const {error} = await stripe.confirmCardPayment(clientSecret);

        if (error) {
            throw new Error(error.message);
        } else {
            showSuccess();
        }
    }

    function showSuccess() {
        document.getElementById('sbr-payment-completion-form').style.display = 'none';
        document.getElementById('sbr-payment-success').style.display = 'block';

        // Scroll to success message
        document.getElementById('sbr-payment-success').scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });

        // Optional: Redirect after delay
        setTimeout(function() {
            if (window.location.search.includes('redirect_to=')) {
                const redirectUrl = new URLSearchParams(window.location.search).get('redirect_to');
                if (redirectUrl) {
                    window.location.href = decodeURIComponent(redirectUrl);
                }
            }
        }, 3000);
    }

    function showError(message) {
        document.getElementById('sbr-error-message').textContent = message;
        document.getElementById('sbr-payment-completion-form').style.display = 'none';
        document.getElementById('sbr-payment-error').style.display = 'block';

        // Scroll to error message
        document.getElementById('sbr-payment-error').scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }

    // Form validation helpers
    function validateForm(form) {
        const requiredFields = form.querySelectorAll('input[required]');
        let isValid = true;

        requiredFields.forEach(function(field) {
            if (!field.value.trim()) {
                showFieldError(field, 'This field is required');
                isValid = false;
            } else {
                clearFieldError(field);
            }
        });

        // Validate email
        const emailField = form.querySelector('input[type="email"]');
        if (emailField && emailField.value && !isValidEmail(emailField.value)) {
            showFieldError(emailField, 'Please enter a valid email address');
            isValid = false;
        }

        // Validate ZIP code
        const zipField = form.querySelector('input[name="billing_zip"]');
        if (zipField && zipField.value && !isValidZip(zipField.value)) {
            showFieldError(zipField, 'Please enter a valid ZIP code');
            isValid = false;
        }

        return isValid;
    }

    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    function isValidZip(zip) {
        const zipRegex = /^\d{5}(-\d{4})?$/;
        return zipRegex.test(zip);
    }

    function showFieldError(field, message) {
        clearFieldError(field);
        field.classList.add('error');

        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    }

    function clearFieldError(field) {
        field.classList.remove('error');
        const errorDiv = field.parentNode.querySelector('.field-error');
        if (errorDiv) {
            errorDiv.remove();
        }
    }

    // Add some basic form validation CSS
    const style = document.createElement('style');
    style.textContent = `
        .sbr-form-field input.error {
            border-color: #dc3545;
            box-shadow: 0 0 0 2px rgba(220,53,69,0.1);
        }
        .field-error {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
    `;
    document.head.appendChild(style);

})(jQuery);