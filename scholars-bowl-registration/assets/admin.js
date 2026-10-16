jQuery(document).ready(function($) {

    // Show/hide Stripe secret key
    $('#toggle-stripe-key').on('click', function(e) {
        e.preventDefault();
        const $input = $('#sbr_stripe_secret_key');
        const type = $input.attr('type');

        if (type === 'password') {
            $input.attr('type', 'text');
            $(this).text('Hide');
        } else {
            $input.attr('type', 'password');
            $(this).text('Show');
        }
    });

    // Form validation
    $('#sbr-settings-form').on('submit', function(e) {
        let isValid = true;
        const requiredFields = ['sbr_event_name', 'sbr_event_date', 'sbr_early_bird_deadline'];

        requiredFields.forEach(function(fieldId) {
            const $field = $('#' + fieldId);
            if (!$field.val()) {
                $field.css('border-color', '#dc3545');
                isValid = false;
            } else {
                $field.css('border-color', '#ced4da');
            }
        });

        // Validate email if provided
        const organizerEmail = $('#sbr_organizer_email').val();
        if (organizerEmail && !isValidEmail(organizerEmail)) {
            $('#sbr_organizer_email').css('border-color', '#dc3545');
            isValid = false;
        }

        // Validate rates are positive numbers
        const rates = ['sbr_early_bird_rate', 'sbr_standard_rate', 'sbr_deposit_amount'];
        rates.forEach(function(fieldId) {
            const $field = $('#' + fieldId);
            const value = parseFloat($field.val());
            if (isNaN(value) || value < 0) {
                $field.css('border-color', '#dc3545');
                isValid = false;
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please correct the highlighted fields before saving.');
        }
    });

    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Auto-calculate session time when grade level changes (if we had this field in admin)
    function updateSessionInfo(gradeLevel) {
        let sessionTime = '';
        if (['Private', '1A', '2A', '3A'].includes(gradeLevel)) {
            sessionTime = 'Morning (8:30 AM)';
        } else if (['4A', '5A', '6A'].includes(gradeLevel)) {
            sessionTime = 'Afternoon (12:15 PM)';
        }
        return sessionTime;
    }

    // Export functionality
    $('.export-btn').on('click', function(e) {
        e.preventDefault();
        const format = $(this).data('format');
        const url = ajaxurl + '?action=sbr_export_registrations&format=' + format + '&_wpnonce=' + sbr_admin.nonce;
        window.open(url, '_blank');
    });

    // Filter registrations
    $('#filter-registrations').on('submit', function(e) {
        // Let the form submit normally to reload the page with filters
    });

    // Copy shortcode to clipboard
    $('#copy-shortcode').on('click', function(e) {
        e.preventDefault();
        const shortcode = '[scholars_bowl_registration]';

        if (navigator.clipboard) {
            navigator.clipboard.writeText(shortcode).then(function() {
                showNotice('Shortcode copied to clipboard!', 'success');
            });
        } else {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = shortcode;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            showNotice('Shortcode copied to clipboard!', 'success');
        }
    });

    function showNotice(message, type = 'info') {
        const notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after(notice);

        setTimeout(function() {
            notice.fadeOut();
        }, 3000);
    }

    // Registration details modal (if implementing full details view)
    window.viewRegistrationDetails = function(registrationId) {
        // This would fetch full registration details via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sbr_get_registration_details',
                registration_id: registrationId,
                nonce: sbr_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showRegistrationModal(response.data);
                }
            }
        });
    };

    function showRegistrationModal(registration) {
        const modal = $('<div class="sbr-modal-overlay"></div>');
        const content = `
            <div class="sbr-modal">
                <div class="sbr-modal-header">
                    <h2>Registration #${registration.id}</h2>
                    <button class="sbr-modal-close">&times;</button>
                </div>
                <div class="sbr-modal-content">
                    <div class="registration-details">
                        <h3>School Information</h3>
                        <p><strong>School:</strong> ${registration.school_name}</p>
                        <p><strong>Contact:</strong> ${registration.contact_name}</p>
                        <p><strong>Email:</strong> ${registration.contact_email}</p>
                        <p><strong>Phone:</strong> ${registration.contact_phone}</p>

                        <h3>Team Information</h3>
                        <p><strong>Competing Students:</strong> ${registration.competing_students}</p>
                        <p><strong>Additional Attendees:</strong> ${registration.additional_attendees}</p>
                        <p><strong>Coaches:</strong> ${registration.coaches}</p>
                        <p><strong>Grade Level:</strong> ${registration.grade_level}</p>
                        <p><strong>Session:</strong> ${registration.session_time}</p>

                        <h3>Payment Information</h3>
                        <p><strong>Total Amount:</strong> $${parseFloat(registration.total_amount).toFixed(2)}</p>
                        <p><strong>Payment Option:</strong> ${registration.payment_option}</p>
                        <p><strong>Payment Method:</strong> ${registration.payment_method}</p>
                        <p><strong>Payment Status:</strong> ${registration.payment_status}</p>

                        <h3>Registration Date</h3>
                        <p>${registration.registration_date}</p>
                    </div>
                </div>
            </div>
        `;

        modal.html(content);
        $('body').append(modal);

        modal.find('.sbr-modal-close, .sbr-modal-overlay').on('click', function(e) {
            if (e.target === this) {
                modal.remove();
            }
        });
    }

    // Add some helpful tooltips
    $('[data-tooltip]').each(function() {
        const $this = $(this);
        const tooltip = $this.data('tooltip');
        $this.attr('title', tooltip);
    });

    // Confirm before performing destructive actions
    $('.delete-registration').on('click', function(e) {
        if (!confirm('Are you sure you want to delete this registration? This action cannot be undone.')) {
            e.preventDefault();
        }
    });

    // Real-time validation feedback
    $('input[type="email"]').on('blur', function() {
        const $this = $(this);
        const email = $this.val();

        if (email && !isValidEmail(email)) {
            $this.css('border-color', '#dc3545');
            $this.siblings('.error-message').remove();
            $this.after('<span class="error-message" style="color: #dc3545; font-size: 12px;">Please enter a valid email address.</span>');
        } else {
            $this.css('border-color', '#ced4da');
            $this.siblings('.error-message').remove();
        }
    });

    // Number validation
    $('input[type="number"]').on('input', function() {
        const $this = $(this);
        const value = parseFloat($this.val());
        const min = parseFloat($this.attr('min'));
        const max = parseFloat($this.attr('max'));

        if (isNaN(value) || (min !== undefined && value < min) || (max !== undefined && value > max)) {
            $this.css('border-color', '#dc3545');
        } else {
            $this.css('border-color', '#ced4da');
        }
    });
});