jQuery(document).ready(function($) {
    console.log("SBR Frontend JS Loaded - Version 1.0.8");
    let stripe, elements, cardElement;

    // Initialize Stripe
    if (typeof Stripe !== 'undefined' && sbr_ajax.stripe_key) {
        stripe = Stripe(sbr_ajax.stripe_key);
        elements = stripe.elements();

        // Create card element
        cardElement = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#424770',
                    '::placeholder': {
                        color: '#aab7c4',
                    },
                },
            },
        });

        cardElement.mount('#card-element');

        // Handle real-time validation errors from the card Element
        cardElement.on('change', function(event) {
            const displayError = document.getElementById('card-errors');
            if (event.error) {
                displayError.textContent = event.error.message;
            } else {
                displayError.textContent = '';
            }
        });
    }

    // Update cost calculation when form values change
    $('#competing_students, #additional_students, #additional_adults, #coaches, input[name="payment_option"]').on('change', function() {
        calculateTotal();
    });

    // Handle payment method change
    $('input[name="payment_method"]').on('change', function() {
        const method = $(this).val();
        togglePaymentSections(method);
    });

    function togglePaymentSections(method) {
        $('.payment-section').removeClass('active');

        // Smooth hide animation
        $('.payment-section').fadeOut(200, function() {
            if (method === 'credit') {
                $('#credit-card-section').addClass('active').fadeIn(300);
            } else if (method === 'check') {
                $('#check-payment-section').addClass('active').fadeIn(300);
            } else if (method === 'cash') {
                $('#cash-payment-section').addClass('active').fadeIn(300);
            }
        });
    }

    function calculateTotal() {
        console.log("calculateTotal called");
        const competingStudents = parseInt($('#competing_students').val()) || 0;
        const additionalStudents = parseInt($('#additional_students').val()) || 0;
        const additionalAdults = parseInt($('#additional_adults').val()) || 0;
        const additionalAttendees = additionalStudents + additionalAdults;
        const coaches = parseInt($('#coaches').val()) || 0;
        const paymentOption = $('input[name="payment_option"]:checked').val();

        if (competingStudents === 0) {
            updateCostDisplay(0, 0, 0);
            return;
        }

        $.ajax({
            url: sbr_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sbr_calculate_total',
                nonce: sbr_ajax.nonce,
                competing_students: competingStudents,
                additional_students: additionalStudents,
                additional_adults: additionalAdults,
                additional_attendees: additionalAttendees,
                coaches: coaches,
                event_id: $('#event_id').val() || 0
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    const totalAttendees = competingStudents + additionalStudents + additionalAdults + coaches;
                    const paymentAmount = paymentOption === 'deposit' ? data.deposit : data.total;

                    updateCostDisplay(totalAttendees, data.total, paymentAmount);
                    $('#current-rate').text(data.rate.toFixed(2));

                    if (data.is_early_bird) {
                        $('.pricing-info').removeClass('standard-pricing').addClass('early-bird-notice');
                    }
                }
            }
        });
    }

    function updateCostDisplay(attendees, total, payment) {
        // Add animation class to cost summary
        $('.cost-summary').addClass('cost-update');

        // Update values with smooth transition
        $('#total-attendees').fadeOut(150, function() {
            $(this).text(attendees).fadeIn(150);
        });

        $('#total-amount').fadeOut(150, function() {
            $(this).text(total.toFixed(2)).fadeIn(150);
        });

        $('#payment-amount').fadeOut(150, function() {
            $(this).text(payment.toFixed(2)).fadeIn(150);
        });

        // Highlight payment amount
        $('.payment-amount').addClass('payment-amount-highlight');

        // Remove animation classes after animation completes
        setTimeout(function() {
            $('.cost-summary').removeClass('cost-update');
            $('.payment-amount').removeClass('payment-amount-highlight');
        }, 600);
    }

    // Handle form submission
    $('#sbr-registration-form').on('submit', function(e) {
        e.preventDefault();

        const $form = $(this);
        const $submitBtn = $('#submit-registration');
        const $messages = $('#form-messages');

        // Add processing state with animation
        $submitBtn.prop('disabled', true).html('<span>Processing...</span><div class="sbr-loading"></div>');

        // Show processing overlay
        showProcessingOverlay();

        // Clear previous messages with animation
        $messages.fadeOut(200).removeClass('success-message error-message');

        // Validate form
        if (!validateForm()) {
            $submitBtn.prop('disabled', false).html('<span>Complete Registration</span>');
            hideProcessingOverlay();
            return;
        }

        const formData = new FormData(this);
        formData.append('action', 'sbr_submit_registration');
        formData.append('nonce', sbr_ajax.nonce);
        formData.append('event_id', $('#event_id').val() || 0);

        // Submit registration
        $.ajax({
            url: sbr_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    const data = response.data;

                    if (data.requires_payment && $('input[name="payment_method"]:checked').val() === 'credit') {
                        // Process Stripe payment
                        processStripePayment(data.registration_id, data.amount);
                    } else {
                        // Show success message for check/cash payments
                        showMessage(data.message, 'success');
                        $form[0].reset();
                        calculateTotal();
                    }
                } else {
                    showMessage(response.data || 'Registration failed. Please try again.', 'error');
                }

                if (!response.data.requires_payment || $('input[name="payment_method"]:checked').val() !== 'credit') {
                    $submitBtn.prop('disabled', false).html('<span>Complete Registration</span>');
                    hideProcessingOverlay();
                }
            },
            error: function() {
                showMessage('An error occurred. Please try again.', 'error');
                $submitBtn.prop('disabled', false).html('<span>Complete Registration</span>');
                hideProcessingOverlay();
            }
        });
    });

    function processStripePayment(registrationId, amount) {
        const $submitBtn = $('#submit-registration');

        stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
            billing_details: {
                name: $('#contact_name').val(),
                email: $('#contact_email').val(),
            },
        }).then(function(result) {
            if (result.error) {
                showMessage(result.error.message, 'error');
                $submitBtn.prop('disabled', false).html('<span>Complete Registration</span>');
                hideProcessingOverlay();
            } else {
                // Submit payment to server
                $.ajax({
                    url: sbr_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'sbr_process_payment',
                        nonce: sbr_ajax.nonce,
                        registration_id: registrationId,
                        payment_method_id: result.paymentMethod.id
                    },
                    success: function(response) {
                        if (response.success) {
                            showMessage(response.data.message, 'success');
                            $('#sbr-registration-form')[0].reset();
                            calculateTotal();
                        } else {
                            showMessage(response.data || 'Payment failed. Please try again.', 'error');
                        }
                        $submitBtn.prop('disabled', false).html('<span>Complete Registration</span>');
                        hideProcessingOverlay();
                    },
                    error: function() {
                        showMessage('Payment processing failed. Please try again.', 'error');
                        $submitBtn.prop('disabled', false).html('<span>Complete Registration</span>');
                        hideProcessingOverlay();
                    }
                });
            }
        });
    }

    function validateForm() {
        let isValid = true;
        const requiredFields = ['school_name', 'contact_name', 'contact_email', 'contact_phone', 'competing_students', 'grade_level'];

        requiredFields.forEach(function(fieldName) {
            const $field = $('[name="' + fieldName + '"]');
            if (!$field.val() || $field.val() === '') {
                $field.addClass('form-field-error field-shake');
                setTimeout(function() {
                    $field.removeClass('field-shake');
                }, 500);
                isValid = false;
            } else {
                $field.removeClass('form-field-error');
            }
        });

        // Validate email format
        const email = $('#contact_email').val();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email && !emailRegex.test(email)) {
            $('#contact_email').addClass('form-field-error field-shake');
            setTimeout(function() {
                $('#contact_email').removeClass('field-shake');
            }, 500);
            isValid = false;
        }

        // Validate payment method and option selection
        if (!$('input[name="payment_option"]:checked').length) {
            showMessage('Please select a payment option.', 'error');
            isValid = false;
        }

        if (!$('input[name="payment_method"]:checked').length) {
            showMessage('Please select a payment method.', 'error');
            isValid = false;
        }

        if (!isValid) {
            showMessage('Please fill in all required fields correctly.', 'error');
        }

        return isValid;
    }

    function showMessage(message, type) {
        const $messages = $('#form-messages');
        $messages.removeClass('success-message error-message')
                .addClass(type + '-message show')
                .text(message)
                .fadeIn(300);

        // Scroll to message with smooth animation
        $('html, body').animate({
            scrollTop: $messages.offset().top - 100
        }, 600, 'swing');

        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                $messages.fadeOut(400);
            }, 5000);
        }
    }

    // Initialize payment sections
    togglePaymentSections('credit');

    // Initial calculation
    calculateTotal();

    // Update session info when grade level changes
    $('#grade_level').on('change', function() {
        const gradeLevel = $(this).val();
        let sessionInfo = '';

        if (['Private', '1A', '2A', '3A'].includes(gradeLevel)) {
            sessionInfo = 'Morning Session (8:30 AM)';
        } else if (['4A', '5A', '6A'].includes(gradeLevel)) {
            sessionInfo = 'Afternoon Session (12:15 PM)';
        }

        // Add session info display with animation
        if (sessionInfo && gradeLevel) {
            showSessionInfo(sessionInfo);
        }
    });

    // Enhanced animations and interactions
    function showProcessingOverlay() {
        if ($('.processing-overlay').length === 0) {
            $('body').append(`
                <div class="processing-overlay">
                    <div style="text-align: center;">
                        <div class="processing-spinner"></div>
                        <div class="processing-text">Processing your registration...</div>
                    </div>
                </div>
            `);
        }
        $('.processing-overlay').addClass('active');
    }

    function hideProcessingOverlay() {
        $('.processing-overlay').removeClass('active');
        setTimeout(function() {
            $('.processing-overlay').remove();
        }, 300);
    }

    function showSessionInfo(sessionInfo) {
        // Remove existing session info
        $('.session-info-display').remove();

        // Create and show new session info
        const $sessionDisplay = $(`
            <div class="session-info-display session-highlight" style="opacity: 0;">
                <strong>Selected Session:</strong> ${sessionInfo}
            </div>
        `);

        $('#grade_level').closest('.form-row').after($sessionDisplay);
        $sessionDisplay.animate({ opacity: 1 }, 300);
    }

    // Add smooth focus animations to form fields
    $('.form-row input, .form-row select, .form-row textarea').on('focus', function() {
        $(this).addClass('sbr-smooth-transition');
    }).on('blur', function() {
        $(this).removeClass('form-field-error');
    });

    // Add hover effects to form sections
    $('.form-section').hover(
        function() {
            $(this).addClass('sbr-smooth-transition');
        },
        function() {
            $(this).removeClass('sbr-smooth-transition');
        }
    );

    // Enhance radio button interactions
    $('.radio-group input[type="radio"]').on('change', function() {
        $(this).closest('.radio-group').find('label').removeClass('selected');
        $(this).closest('label').addClass('selected');
    });

    // Add loading state to AJAX calls
    $(document).ajaxStart(function() {
        $('body').addClass('ajax-loading');
    }).ajaxStop(function() {
        $('body').removeClass('ajax-loading');
    });

    // Progressive form enhancement
    setTimeout(function() {
        $('.form-section').addClass('loaded');
    }, 100);
});
