/* Scholars' Bowl registration form. The registration and payment requests are the same as
   in 1.0.x; 1.1.0 adds the optional student rows, field-level error messages that screen
   readers announce, and no motion for visitors who ask for reduced motion. */
jQuery(document).ready(function ($) {
    var $form = $('#sbr-registration-form');
    if (!$form.length || typeof sbr_ajax === 'undefined') {
        return;
    }
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) {
        $.fx.off = true;
    }
    var stripe, elements, cardElement;

    // Initialize Stripe
    if (typeof Stripe !== 'undefined' && sbr_ajax.stripe_key) {
        stripe = Stripe(sbr_ajax.stripe_key);
        elements = stripe.elements();
        cardElement = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#1b2233',
                    fontFamily: 'Mulish, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                    '::placeholder': { color: '#6b7385' }
                },
                invalid: { color: '#b42318' }
            }
        });
        cardElement.mount('#card-element');
        cardElement.on('change', function (event) {
            document.getElementById('card-errors').textContent = event.error ? event.error.message : '';
        });
    }

    var labels = {
        school_name: 'Enter the school name.',
        contact_name: 'Enter the contact name.',
        contact_email: 'Enter the email address.',
        contact_phone: 'Enter a phone number.',
        competing_students: 'Choose the number of competing students.',
        grade_level: 'Choose your school classification.'
    };

    /* ---------- cost summary ---------- */

    $('#competing_students, #additional_students, #additional_adults, #coaches, input[name="payment_option"]').on('change', function () {
        calculateTotal();
    });

    function calculateTotal() {
        var competingStudents = parseInt($('#competing_students').val(), 10) || 0;
        var additionalStudents = parseInt($('#additional_students').val(), 10) || 0;
        var additionalAdults = parseInt($('#additional_adults').val(), 10) || 0;
        var coaches = parseInt($('#coaches').val(), 10) || 0;
        var paymentOption = $('input[name="payment_option"]:checked').val();

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
                additional_attendees: additionalStudents + additionalAdults,
                coaches: coaches,
                event_id: $('#event_id').val() || 0
            },
            success: function (response) {
                if (response.success) {
                    var data = response.data;
                    var totalAttendees = competingStudents + additionalStudents + additionalAdults + coaches;
                    var paymentAmount = paymentOption === 'deposit' ? data.deposit : data.total;
                    updateCostDisplay(totalAttendees, data.total, paymentAmount);
                    $('#current-rate').text(Number(data.rate).toFixed(2));
                }
            }
        });
    }

    function updateCostDisplay(attendees, total, payment) {
        $('#total-attendees').text(attendees);
        $('#total-amount').text(Number(total).toFixed(2));
        $('#payment-amount').text(Number(payment).toFixed(2));
    }

    /* ---------- payment method sections ---------- */

    $('input[name="payment_method"]').on('change', function () {
        togglePaymentSections($(this).val());
    });

    function togglePaymentSections(method) {
        $('.payment-section').removeClass('active');
        var target = { credit: '#credit-card-section', check: '#check-payment-section', cash: '#cash-payment-section' }[method];
        if (target) {
            $(target).addClass('active');
        }
    }

    /* ---------- additional attendees (students + adults share one limit) ---------- */

    var maxAdditional = parseInt($form.data('max-additional'), 10);
    var $studentSelect = $('#additional_students');
    var $adultSelect = $('#additional_adults');

    function rebuild($select, available, current) {
        $select.empty();
        for (var i = 0; i <= available; i++) {
            $select.append($('<option>', { value: i, text: i, selected: i === current }));
        }
    }

    function updateAdditional() {
        if (isNaN(maxAdditional)) {
            return;
        }
        var students = parseInt($studentSelect.val(), 10) || 0;
        var adults = parseInt($adultSelect.val(), 10) || 0;
        rebuild($adultSelect, maxAdditional - students, adults);
        rebuild($studentSelect, maxAdditional - adults, students);
        var total = students + adults;
        var remaining = maxAdditional - total;
        $('.additional-helper').text('Total additional attendees: ' + total + ' of ' + maxAdditional + (remaining > 0 ? ' (' + remaining + ' remaining)' : ''));
    }

    $studentSelect.add($adultSelect).on('change', updateAdditional);
    updateAdditional();

    /* ---------- optional student rows ---------- */

    var $rosterRows = $('.sbr-roster-section .sbr-student-row[data-row]');

    function updateRosterRows() {
        if (!$rosterRows.length) {
            return;
        }
        var count = Math.min(parseInt($('#competing_students').val(), 10) || 0, $rosterRows.length);
        $rosterRows.each(function (i) {
            var show = i < count;
            $(this).prop('hidden', !show).prop('disabled', !show);
        });
        $('.sbr-roster-section .sbr-student-row-head').prop('hidden', count === 0);
        $('.sbr-roster-hint').text(count === 0
            ? 'Choose the number of competing students above to add names.'
            : (parseInt($('#competing_students').val(), 10) > $rosterRows.length ? 'You can name up to ' + $rosterRows.length + ' students.' : ''));
    }

    $('#competing_students').on('change', updateRosterRows);
    updateRosterRows();

    /* ---------- validation ---------- */

    function fieldError($field, message) {
        var id = $field.attr('id');
        if (!id) {
            return;
        }
        var errId = id + '_error';
        $('#' + errId).remove();
        var described = ($field.attr('aria-describedby') || '').split(' ').filter(function (x) { return x && x !== errId; });
        if (message) {
            $field.attr('aria-invalid', 'true').addClass('form-field-error');
            var $err = $('<p class="sbr-field-error">').attr('id', errId).text(message);
            $field.closest('.form-row, .sbr-field').append($err);
            described.push(errId);
        } else {
            $field.removeAttr('aria-invalid').removeClass('form-field-error');
        }
        if (described.length) {
            $field.attr('aria-describedby', described.join(' '));
        } else {
            $field.removeAttr('aria-describedby');
        }
    }

    function validateForm() {
        var problems = [];
        $.each(labels, function (name, message) {
            var $field = $form.find('[name="' + name + '"]');
            var ok = $.trim($field.val() || '') !== '';
            fieldError($field, ok ? '' : message);
            if (!ok) {
                problems.push({ field: $field, message: message });
            }
        });

        var $email = $('#contact_email');
        var email = $.trim($email.val());
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            fieldError($email, 'Enter an email address like name@school.org.');
            problems.push({ field: $email, message: 'Enter an email address like name@school.org.' });
        }

        // A student row with anything in it needs a first and last name.
        $rosterRows.filter(':not([disabled])').each(function (i) {
            var $row = $(this);
            var $first = $row.find('input[name$="[first_name]"]');
            var $last = $row.find('input[name$="[last_name]"]');
            var used = $.trim($first.val()) || $.trim($last.val()) || $row.find('select').val();
            var n = i + 1;
            [[$first, 'Student ' + n + ': enter a first name, or clear the row.'], [$last, 'Student ' + n + ': enter a last name, or clear the row.']].forEach(function (pair) {
                var bad = used && !$.trim(pair[0].val());
                fieldError(pair[0], bad ? pair[1] : '');
                if (bad) {
                    problems.push({ field: pair[0], message: pair[1] });
                }
            });
        });

        if (!$('input[name="payment_option"]:checked').length) {
            problems.push({ field: $('input[name="payment_option"]').first(), message: 'Choose a payment type.' });
        }
        if (!$('input[name="payment_method"]:checked').length) {
            problems.push({ field: $('input[name="payment_method"]').first(), message: 'Choose a payment method.' });
        }

        if (problems.length) {
            var $list = $('<ul>');
            problems.forEach(function (p) {
                var id = p.field.attr('id');
                var $li = $('<li>');
                if (id) {
                    $li.append($('<a>').attr('href', '#' + id).text(p.message));
                } else {
                    $li.text(p.message);
                }
                $list.append($li);
            });
            showMessage($('<div>').append($('<p>').append($('<strong>').text('Please correct ' + (problems.length === 1 ? 'this' : 'these ' + problems.length) + ' before submitting:'))).append($list), 'error', true);
        }
        return problems.length === 0;
    }

    $form.on('input change', 'input, select, textarea', function () {
        if ($(this).attr('aria-invalid') === 'true' && $.trim($(this).val() || '') !== '') {
            fieldError($(this), '');
        }
    });

    $('#form-messages').on('click', 'a[href^="#"]', function (e) {
        var target = document.getElementById(this.getAttribute('href').slice(1));
        if (target) {
            e.preventDefault();
            target.focus();
        }
    });

    /* ---------- submit ---------- */

    function setBusy(busy) {
        var $btn = $('#submit-registration');
        $btn.prop('disabled', busy).attr('aria-busy', busy ? 'true' : null);
        $btn.html(busy ? '<span>Processing…</span><span class="sbr-loading" aria-hidden="true"></span>' : '<span>Complete Registration</span>');
        if (busy) {
            showProcessingOverlay();
        } else {
            hideProcessingOverlay();
        }
    }

    function resetForm() {
        $form[0].reset();
        $form.find('[aria-invalid]').each(function () { fieldError($(this), ''); });
        updateAdditional();
        updateRosterRows();
        togglePaymentSections($('input[name="payment_method"]:checked').val() || 'credit');
        $('.session-info-display').remove();
        calculateTotal();
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        $('#form-messages').removeClass('success-message error-message show').empty().hide();

        if (!validateForm()) {
            return;
        }
        setBusy(true);

        var formData = new FormData(this);
        formData.append('action', 'sbr_submit_registration');
        formData.append('nonce', sbr_ajax.nonce);
        formData.append('event_id', $('#event_id').val() || 0);
        var method = $('input[name="payment_method"]:checked').val();

        $.ajax({
            url: sbr_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success && response.data.requires_payment && method === 'credit') {
                    processStripePayment(response.data.registration_id, response.data.amount);
                    return;
                }
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    resetForm();
                } else {
                    showMessage(response.data || 'Registration failed. Please try again.', 'error');
                }
                setBusy(false);
            },
            error: function () {
                showMessage('An error occurred. Please try again.', 'error');
                setBusy(false);
            }
        });
    });

    function processStripePayment(registrationId, amount) {
        if (!stripe || !cardElement) {
            showMessage('Card payments are not available right now. Please choose check or cash, or contact the tournament organizer.', 'error');
            setBusy(false);
            return;
        }
        stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
            billing_details: {
                name: $('#contact_name').val(),
                email: $('#contact_email').val()
            }
        }).then(function (result) {
            if (result.error) {
                showMessage(result.error.message, 'error');
                setBusy(false);
                return;
            }
            $.ajax({
                url: sbr_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'sbr_process_payment',
                    nonce: sbr_ajax.nonce,
                    registration_id: registrationId,
                    payment_method_id: result.paymentMethod.id
                },
                success: function (response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        resetForm();
                    } else {
                        showMessage(response.data || 'Payment failed. Please try again.', 'error');
                    }
                    setBusy(false);
                },
                error: function () {
                    showMessage('Payment processing failed. Please try again.', 'error');
                    setBusy(false);
                }
            });
        });
    }

    /** Show a message above the submit button and move focus to it, so it is read out. */
    function showMessage(message, type, isHtml) {
        var $messages = $('#form-messages');
        $messages.removeClass('success-message error-message')
            .addClass(type + '-message show sbr-alert ' + (type === 'success' ? 'sbr-alert-success' : 'sbr-alert-error'))
            .attr('role', type === 'success' ? 'status' : 'alert');
        if (isHtml) {
            $messages.empty().append(message);
        } else {
            $messages.empty().append($('<p>').text(String(message)));
        }
        $messages.show();
        if ($messages[0].scrollIntoView) {
            $messages[0].scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
        }
        $messages.trigger('focus');
    }

    function showProcessingOverlay() {
        if ($('.processing-overlay').length === 0) {
            $('body').append('<div class="processing-overlay" role="status"><div class="processing-box"><div class="processing-spinner" aria-hidden="true"></div><div class="processing-text">Processing your registration…</div></div></div>');
        }
        $('.processing-overlay').addClass('active');
    }

    function hideProcessingOverlay() {
        $('.processing-overlay').remove();
    }

    /* ---------- session shown for the chosen classification ---------- */

    $('#grade_level').on('change', function () {
        $('.session-info-display').remove();
        var session = $(this).find('option:selected').data('session');
        if (session) {
            $('<p class="session-info-display">').append($('<strong>').text('Session: ')).append(document.createTextNode(session))
                .insertAfter($(this));
        }
    });

    togglePaymentSections($('input[name="payment_method"]:checked').val() || 'credit');
    calculateTotal();
});
