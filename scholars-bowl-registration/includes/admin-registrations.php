<?php
if (!defined('ABSPATH')) {
    exit;
}


// Handle test transaction deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_test' && isset($_GET['registration_id'])) {
    check_admin_referer('delete_test_registration_' . intval($_GET['registration_id']));
    
    $registration_id = intval($_GET['registration_id']);
    
    // Verify it's a test transaction before deleting
    $is_test = $wpdb->get_var($wpdb->prepare("SELECT is_test_mode FROM $table_name WHERE id = %d", $registration_id));
    
    if ($is_test) {
        $wpdb->delete($table_name, array('id' => $registration_id), array('%d'));
        echo '<div class="notice notice-success is-dismissible"><p>Test registration deleted successfully.</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Cannot delete: This is not a test transaction.</p></div>';
    }
}

global $wpdb;
$table_name = $wpdb->prefix . 'scholars_bowl_registrations';

// Handle payment status updates
if (isset($_POST['action']) && $_POST['action'] === 'mark_as_paid' && check_admin_referer('sbr_mark_paid', 'sbr_nonce')) {
    $registration_id = intval($_POST['registration_id']);
    $payment_notes = sanitize_textarea_field($_POST['payment_notes']);

    $update_data = [
        'payment_status' => 'completed',
        'amount_paid' => floatval($_POST['amount_paid']),
        'payment_notes' => $payment_notes,
        'registration_status' => 'confirmed'
    ];

    // Calculate balance due and determine payment status
    $registration = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $registration_id));
    if ($registration) {
        $amount_paid_new = floatval($_POST['amount_paid']);
        $total_amount_paid = $amount_paid_new + ($registration->amount_paid ?: 0);
        $balance_due = max(0, $registration->total_amount - $total_amount_paid);

        $update_data['amount_paid'] = $total_amount_paid;
        $update_data['balance_due'] = $balance_due;

        // Update payment status based on whether this is a partial or full payment
        if ($balance_due > 0.01) {  // Using 0.01 to account for rounding
            $update_data['payment_status'] = 'partial';
        } else {
            $update_data['payment_status'] = 'completed';
        }
    }

    $result = $wpdb->update(
        $table_name,
        $update_data,
        ['id' => $registration_id],
        ['%s', '%f', '%s', '%s', '%f', '%f', '%s'],
        ['%d']
    );

    if ($result !== false) {
        // Send payment confirmation email
        global $sbr_instance;
        if (!$sbr_instance) {
            $sbr_instance = new ScholarsBowlRegistration();
        }
        $sbr_instance->send_payment_confirmation($registration_id, floatval($_POST['amount_paid']), $payment_notes);

        echo '<div class="notice notice-success"><p>Payment status updated successfully! Confirmation email sent.</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Error updating payment status.</p></div>';
    }
}

// Handle send payment link requests
if (isset($_POST['action']) && $_POST['action'] === 'send_payment_link' && check_admin_referer('sbr_send_link', 'sbr_nonce')) {
    $registration_id = intval($_POST['registration_id']);

    global $sbr_instance;
    if (!$sbr_instance) {
        $sbr_instance = new ScholarsBowlRegistration();
    }

    $result = $sbr_instance->send_payment_completion_link($registration_id);

    if ($result) {
        echo '<div class=\"notice notice-success\"><p>Payment completion link sent successfully!</p></div>';
    } else {
        echo '<div class=\"notice notice-error\"><p>Error sending payment link. Registration may be fully paid or not found.</p></div>';
    }
}

// Handle registration status updates
if (isset($_POST['action']) && $_POST['action'] === 'update_status' && check_admin_referer('sbr_update_status', 'sbr_nonce')) {
    $registration_id = intval($_POST['registration_id']);
    $new_status = sanitize_text_field($_POST['registration_status']);
    $notes = sanitize_textarea_field($_POST['status_notes']);

    // Get current status for email notification
    $current_registration = $wpdb->get_row($wpdb->prepare("SELECT registration_status FROM $table_name WHERE id = %d", $registration_id));
    $old_status = $current_registration ? $current_registration->registration_status : 'pending';

    $result = $wpdb->update(
        $table_name,
        [
            'registration_status' => $new_status,
            'payment_notes' => $notes
        ],
        ['id' => $registration_id],
        ['%s', '%s'],
        ['%d']
    );

    if ($result !== false) {
        // Send status change notification email
        global $sbr_instance;
        if (!$sbr_instance) {
            $sbr_instance = new ScholarsBowlRegistration();
        }
        $sbr_instance->send_status_change_notification($registration_id, $old_status, $new_status, $notes);

        echo '<div class="notice notice-success"><p>Registration status updated successfully! Notification email sent.</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Error updating registration status.</p></div>';
    }
}

// The export function lives in export-csv.php (1.0.7) so it can run before any page output.
require_once SBR_PLUGIN_PATH . 'includes/export-csv.php';

// Handle export requests
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $registrations_for_export = $wpdb->get_results("SELECT * FROM $table_name ORDER BY registration_date DESC");
    export_registrations_csv($registrations_for_export);
}

// Handle filtering
$where_conditions = [];
$where_params = [];

if (!empty($_GET['payment_status'])) {
    $where_conditions[] = "payment_status = %s";
    $where_params[] = sanitize_text_field($_GET['payment_status']);
}

if (isset($_GET['test_mode']) && $_GET['test_mode'] !== '') {
    $where_conditions[] = "is_test_mode = %d";
    $where_params[] = intval($_GET['test_mode']);
}

if (!empty($_GET['registration_status'])) {
    $where_conditions[] = "registration_status = %s";
    $where_params[] = sanitize_text_field($_GET['registration_status']);
}

if (!empty($_GET['event_filter'])) {
    $where_conditions[] = "event_id = %d";
    $where_params[] = intval($_GET['event_filter']);
}

if (!empty($_GET['search'])) {
    $search = '%' . $wpdb->esc_like(sanitize_text_field($_GET['search'])) . '%';
    $where_conditions[] = "(school_name LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s)";
    $where_params[] = $search;
    $where_params[] = $search;
    $where_params[] = $search;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Handle sorting
$valid_sort_columns = ['id', 'school_name', 'contact_name', 'total_amount', 'payment_status', 'registration_status', 'registration_date'];
$sort_column = isset($_GET['orderby']) && in_array($_GET['orderby'], $valid_sort_columns) ? $_GET['orderby'] : 'registration_date';
$sort_direction = (isset($_GET['order']) && $_GET['order'] === 'asc') ? 'ASC' : 'DESC';

$query = "SELECT * FROM $table_name $where_clause ORDER BY $sort_column $sort_direction";

if (!empty($where_params)) {
    $registrations = $wpdb->get_results($wpdb->prepare($query, $where_params));
} else {
    $registrations = $wpdb->get_results($query);
}

$total_registrations = count($registrations);
$total_revenue = $wpdb->get_var("SELECT SUM(amount_paid) FROM $table_name WHERE payment_status = 'completed'");
$pending_payments = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE payment_status = 'pending'");
$outstanding_balance = $wpdb->get_var("SELECT SUM(balance_due) FROM $table_name WHERE balance_due > 0");
?>

<div class="wrap">
<script type="text/javascript">
    var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";
    var sbrAdminNonce = "<?php echo wp_create_nonce('sbr_admin_nonce'); ?>";
</script>
    <h1>Scholars' Bowl Registrations</h1>

    <div class="dashboard-widgets-wrap">
        <div class="metabox-holder">
            <div class="postbox-container" style="width: 100%;">
                <div class="meta-box-sortables">
                    <div class="postbox">
                        <h2 class="hndle">Registration Summary</h2>
                        <div class="inside">
                            <div class="main">
                                <ul>
                                    <li><strong>Total Registrations:</strong> <?php echo $total_registrations; ?></li>
                                    <li><strong>Total Revenue:</strong> $<?php echo number_format($total_revenue ?: 0, 2); ?></li>
                                    <li><strong>Pending Payments:</strong> <?php echo $pending_payments; ?></li>
                                    <li><strong>Outstanding Balance:</strong> $<?php echo number_format($outstanding_balance ?: 0, 2); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtering and Export Controls -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get" action="">
                <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">

                <input type="text" name="search" placeholder="Search schools, contacts..."
                       value="<?php echo esc_attr($_GET['search'] ?? ''); ?>" class="search-input">

                <select name="payment_status">
                    <option value="">All Payment Statuses</option>
                    <option value="pending" <?php selected($_GET['payment_status'] ?? '', 'pending'); ?>>Pending</option>
                    <option value="partial" <?php selected($_GET['payment_status'] ?? '', 'partial'); ?>>Partial (Deposit)</option>
                    <option value="completed" <?php selected($_GET['payment_status'] ?? '', 'completed'); ?>>Completed</option>
                    <option value="processing" <?php selected($_GET['payment_status'] ?? '', 'processing'); ?>>Processing</option>
                    <option value="failed" <?php selected($_GET['payment_status'] ?? '', 'failed'); ?>>Failed</option>
                </select>
                
                <select name="test_mode" style="margin-left: 10px;">
                    <option value="">All Transactions</option>
                    <option value="0" <?php selected($_GET['test_mode'] ?? '', '0'); ?>>Live Only</option>
                    <option value="1" <?php selected($_GET['test_mode'] ?? '', '1'); ?>>Test Only</option>
                </select>

                <select name="registration_status">
                    <option value="">All Registration Statuses</option>
                    <option value="pending" <?php selected($_GET['registration_status'] ?? '', 'pending'); ?>>Pending</option>
                    <option value="confirmed" <?php selected($_GET['registration_status'] ?? '', 'confirmed'); ?>>Confirmed</option>
                    <option value="cancelled" <?php selected($_GET['registration_status'] ?? '', 'cancelled'); ?>>Cancelled</option>
                    <option value="waitlisted" <?php selected($_GET['registration_status'] ?? '', 'waitlisted'); ?>>Waitlisted</option>
                    <option value="incomplete" <?php selected($_GET['registration_status'] ?? '', 'incomplete'); ?>>Incomplete</option>
                </select>

                <?php
                // Get available events for filtering
                $events_table = $wpdb->prefix . 'scholars_bowl_events';
                $events = $wpdb->get_results("SELECT id, event_name FROM $events_table ORDER BY event_name");
                if (!empty($events)): ?>
                <select name="event_filter">
                    <option value="">All Events</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?php echo $event->id; ?>" <?php selected($_GET['event_filter'] ?? '', $event->id); ?>>
                        <?php echo esc_html($event->event_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <input type="submit" name="filter_action" value="Filter" class="button">
                <a href="<?php echo admin_url('admin.php?page=' . $_GET['page']); ?>" class="button">Clear Filters</a>
            </form>
        </div>

        <div class="alignright actions">
            <button type="button" class="button button-primary" onclick="openAddRegistrationModal()" style="margin-right: 10px;">
                <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span> Add New Registration
            </button>
            <a href="<?php echo add_query_arg(['action' => 'export_csv'], $_SERVER['REQUEST_URI']); ?>"
               class="button button-primary">Export to CSV</a>
        </div>

        <br class="clear">
    </div>

    <?php if (empty($registrations)): ?>
        <div class="notice notice-info">
            <p>No registrations found. Use the shortcode <code>[scholars_bowl_registration]</code> on a page to enable registrations.</p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <?php
                    function sortable_header($column, $label, $current_orderby = '', $current_order = 'desc') {
                        $base_url = admin_url('admin.php');
                        $query_args = $_GET;
                        $query_args['orderby'] = $column;

                        // Toggle order if same column
                        if ($current_orderby === $column) {
                            $query_args['order'] = ($current_order === 'asc') ? 'desc' : 'asc';
                            $arrow = ($current_order === 'asc') ? ' ↑' : ' ↓';
                        } else {
                            $query_args['order'] = 'asc';
                            $arrow = '';
                        }

                        $url = add_query_arg($query_args, $base_url);
                        return '<a href="' . esc_url($url) . '">' . esc_html($label) . $arrow . '</a>';
                    }

                    $current_orderby = $_GET['orderby'] ?? '';
                    $current_order = $_GET['order'] ?? 'desc';
                    ?>
                    <th><?php echo sortable_header('id', 'ID', $current_orderby, $current_order); ?></th>
                    <th><?php echo sortable_header('school_name', 'School', $current_orderby, $current_order); ?></th>
                    <th><?php echo sortable_header('contact_name', 'Contact', $current_orderby, $current_order); ?></th>
                    <th>Dietary</th>
                    <th>Students</th>
                    <th>Additional<br><small>(S / A)</small></th>
                    <th>Coaches</th>
                    <th>School Classification</th>
                    <th>Session</th>
                    <th><?php echo sortable_header('total_amount', 'Amount Details', $current_orderby, $current_order); ?></th>
                    <th><?php echo sortable_header('payment_status', 'Payment Status', $current_orderby, $current_order); ?></th>
                    <th><?php echo sortable_header('registration_status', 'Registration Status', $current_orderby, $current_order); ?></th>
                    <th><?php echo sortable_header('registration_date', 'Registration Date', $current_orderby, $current_order); ?></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $registration): ?>
                    <tr>
                        <td><?php echo $registration->id; ?></td>
                        <td>
                            <strong><?php echo esc_html($registration->school_name); ?></strong>
                        </td>
                        <td>
                            <?php echo esc_html($registration->contact_name); ?><br>
                            <small>
                                <a href="mailto:<?php echo esc_attr($registration->contact_email); ?>">
                                    <?php echo esc_html($registration->contact_email); ?>
                                </a><br>
                                <?php echo esc_html($registration->contact_phone); ?>
                                <?php if (!empty($registration->dietary_restrictions)): ?>
                                    <br><span style="color: #d63638; font-weight: 600;">⚠ Dietary Notes</span>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td style="max-width: 200px;">
                            <?php if (!empty($registration->dietary_restrictions)): ?>
                                <div style="background: #fff3cd; padding: 5px 8px; border-left: 3px solid #ffc107; font-size: 12px; border-radius: 3px;">
                                    <strong>Dietary Restrictions:</strong><br>
                                    <?php echo nl2br(esc_html($registration->dietary_restrictions)); ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #999;">None specified</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $registration->competing_students; ?></td>
                        <td><?php echo ($registration->additional_students ?? 0) . " / " . ($registration->additional_adults ?? 0); ?></td>
                        <td><?php echo $registration->coaches; ?></td>
                        <td><?php echo esc_html($registration->grade_level); ?></td>
                        <td>
                            <?php
                            echo ucfirst($registration->session_time);
                            echo $registration->session_time === 'morning' ? ' (8:30 AM)' : ' (12:15 PM)';
                            ?>
                        </td>
                        <td>
                            <strong>Total: $<?php echo number_format($registration->total_amount, 2); ?></strong><br>
                            <small>
                                <?php
                                $amount_paid = $registration->amount_paid ?: 0;
                                $balance_due = $registration->balance_due ?: 0;
                                $payment_option = $registration->payment_option ?? 'full';
                                ?>
                                Paid: $<?php echo number_format($amount_paid, 2); ?><br>
                                <?php if ($balance_due > 0): ?>
                                    <?php if ($payment_option === 'deposit' && $amount_paid > 0): ?>
                                        <span style="color: #0073aa;">Deposit Paid: $<?php echo number_format($amount_paid, 2); ?></span><br>
                                        <span style="color: #dc3232;">Balance Due: $<?php echo number_format($balance_due, 2); ?></span>
                                    <?php else: ?>
                                        <span style="color: #dc3232;">Balance Due: $<?php echo number_format($balance_due, 2); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($payment_option === 'deposit' && $amount_paid < $registration->total_amount): ?>
                                        <span style="color: #0073aa;">Deposit Paid: $<?php echo number_format($amount_paid, 2); ?></span><br>
                                        <span style="color: #dc3232;">Balance Due: $<?php echo number_format($registration->total_amount - $amount_paid, 2); ?></span>
                                    <?php else: ?>
                                        <span style="color: #46b450;">Paid in Full</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <span class="status-<?php echo $registration->payment_status; ?>">
                                <?php echo ucfirst($registration->payment_status); ?>
                                <?php if (!empty($registration->is_test_mode)): ?>
                                    <span class="test-badge" style="background: #ff9800; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">TEST</span>
                                <?php endif; ?>
                            </span>
                            <br>
                            <small><?php echo esc_html($registration->payment_method); ?></small>
                            <?php if (!empty($registration->payment_notes)): ?>
                                <br><small><em>Note: <?php echo esc_html($registration->payment_notes); ?></em></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="reg-status-<?php echo $registration->registration_status ?: 'pending'; ?>">
                                <?php echo ucfirst($registration->registration_status ?: 'Pending'); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo date('M j, Y g:i A', strtotime($registration->registration_date)); ?>
                        </td>
                        <td>
                            <a href="#" class="button button-small"
                               onclick="viewRegistration(<?php echo $registration->id; ?>); return false;">View</a>
                            <?php if ($registration->payment_status !== 'completed' || ($registration->balance_due ?: 0) > 0): ?>
                                <a href="#" class="button button-small button-primary"
                                   onclick="markAsPaid(<?php echo $registration->id; ?>)">
                                   <?php echo ($registration->payment_status === 'partial') ? 'Add Payment' : 'Mark Paid'; ?>
                                </a>
                                <?php if (($registration->balance_due ?: 0) > 0): ?>
                                    <a href="#" class="button button-small"
                                       onclick="sendPaymentLink(<?php echo $registration->id; ?>)">Send Payment Link</a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <a href="#" class="button button-small"
                               onclick="updateStatus(<?php echo $registration->id; ?>)">Update Status</a>
                            <a href="mailto:<?php echo esc_attr($registration->contact_email); ?>"
                               class="button button-small">Email</a>
                            <?php if ($registration->payment_method !== 'credit'): ?>
                                <button class="button button-small toggle-test-mode" 
                                        data-registration-id="<?php echo $registration->id; ?>"
                                        data-current-mode="<?php echo $registration->is_test_mode ? '1' : '0'; ?>">
                                    Mark as <?php echo $registration->is_test_mode ? 'Live' : 'Test'; ?>
                                </button>
                            <?php endif; ?>
                            <?php if (!empty($registration->is_test_mode)): ?>
                                <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'delete_test', 'registration_id' => $registration->id)), 'delete_test_registration_' . $registration->id); ?>"
                                   class="button button-small delete-test"
                                   style="color: #b32d2e;"
                                   onclick="return confirm('Are you sure you want to delete this TEST registration? This action cannot be undone.');">Delete Test</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_registrations; ?> items</span>
            </div>
        </div>

        <!-- Registration Details Modal -->
        <div id="registration-modal" style="display: none;">
            <div class="registration-modal-content">
                <span class="close-modal">&times;</span>
                <div id="registration-details"></div>
            </div>
        </div>

        <!-- Mark as Paid Modal -->
        <div id="payment-modal" style="display: none;">
            <div class="registration-modal-content">
                <span class="close-modal">&times;</span>
                <h2>Mark Payment as Received</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('sbr_mark_paid', 'sbr_nonce'); ?>
                    <input type="hidden" name="action" value="mark_as_paid">
                    <input type="hidden" name="registration_id" id="payment_registration_id">

                    <table class="form-table">
                        <tr>
                            <th scope="row">Amount Paid</th>
                            <td>
                                <input type="number" name="amount_paid" id="payment_amount" step="0.01" min="0" required>
                                <p class="description">Enter the additional amount actually received. This will be added to any existing payments.</p>
                                <div id="payment_summary" style="margin-top: 10px; padding: 10px; background-color: #f0f0f0; border-radius: 3px; display: none;">
                                    <strong>Payment Summary:</strong><br>
                                    <span id="current_paid">Current amount paid: $0.00</span><br>
                                    <span id="balance_remaining">Balance remaining: $0.00</span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Payment Notes</th>
                            <td>
                                <textarea name="payment_notes" id="payment_notes" rows="3" cols="50" placeholder="Check number, cash received, etc."></textarea>
                                <p class="description">Optional notes about this payment.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="submit" class="button-primary" value="Mark as Paid">
                        <button type="button" class="button" onclick="closeModal('payment-modal')">Cancel</button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Update Status Modal -->
        <div id="status-modal" style="display: none;">
            <div class="registration-modal-content">
                <span class="close-modal">&times;</span>
                <h2>Update Registration Status</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('sbr_update_status', 'sbr_nonce'); ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="registration_id" id="status_registration_id">

                    <table class="form-table">
                        <tr>
                            <th scope="row">Registration Status</th>
                            <td>
                                <select name="registration_status" id="registration_status" required>
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="waitlisted">Waitlisted</option>
                                    <option value="incomplete">Incomplete</option>
                                </select>
                                <p class="description">Select the new registration status.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Status Notes</th>
                            <td>
                                <textarea name="status_notes" id="status_notes" rows="3" cols="50" placeholder="Reason for status change, additional notes..."></textarea>
                                <p class="description">Optional notes about this status change.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="submit" class="button-primary" value="Update Status">
                        <button type="button" class="button" onclick="closeModal('status-modal')">Cancel</button>
                    </p>
                </form>
            </div>
        </div>
    <?php endif; ?>

        <!-- Send Payment Link Modal -->
        <div id="payment-link-modal" style="display: none;">
            <div class="registration-modal-content">
                <span class="close-modal">&times;</span>
                <h2>Payment Completion Link</h2>
                <p>You can either send the link via email or copy it to send manually.</p>
                
                <div id="payment-link-display" style="display: none; margin: 20px 0; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                    <p><strong>Payment Link:</strong></p>
                    <input type="text" id="payment-link-url" readonly style="width: 100%; padding: 8px; margin-bottom: 10px; font-family: monospace; font-size: 12px;">
                    <button type="button" class="button button-primary" onclick="copyPaymentLink()">
                        <span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span> Copy Link
                    </button>
                    <span id="copy-success" style="color: #46b450; margin-left: 10px; display: none;">✓ Copied!</span>
                    <p style="margin-top: 10px; font-size: 13px; color: #666;">
                        <strong>Note:</strong> This link expires in 24 hours. You can copy and paste it into an email or text message.
                    </p>
                </div>
                
                <form method="post" action="">
                    <?php wp_nonce_field('sbr_send_link', 'sbr_nonce'); ?>
                    <input type="hidden" name="action" value="send_payment_link">
                    <input type="hidden" name="registration_id" id="link_registration_id">

                    <p class="submit">
                        <button type="button" class="button button-primary" onclick="generateAndShowLink()">Generate & Copy Link</button>
                        <input type="submit" name="submit" class="button" value="Generate & Send via Email">
                        <button type="button" class="button" onclick="closeModal('payment-link-modal')">Cancel</button>
                    </p>
                </form>
            </div>
        </div>

    <style>
    .status-completed { color: #46b450; font-weight: bold; }
    .status-partial { color: #0073aa; font-weight: bold; }
    .status-pending { color: #ffba00; font-weight: bold; }
    .status-processing { color: #0073aa; font-weight: bold; }
    .status-failed { color: #dc3232; font-weight: bold; }

    .reg-status-confirmed { color: #46b450; font-weight: bold; }
    .reg-status-pending { color: #ffba00; font-weight: bold; }
    .reg-status-cancelled { color: #dc3232; font-weight: bold; }
    .reg-status-waitlisted { color: #0073aa; font-weight: bold; }
    .reg-status-incomplete { color: #d63638; font-weight: bold; }

    #registration-modal,
    #add-registration-modal,
    #payment-modal,
    #status-modal,
    #payment-link-modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }

    .registration-modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 80%;
        max-width: 600px;
        border-radius: 5px;
        position: relative;
        max-height: 85vh;
        overflow-y: auto;
    }

    .close-modal {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        position: absolute;
        right: 15px;
        top: 10px;
    }

    .close-modal:hover,
    .close-modal:focus {
        color: black;
        text-decoration: none;
    }

    .form-table th {
        width: 150px;
    }

    .form-table input[type="number"],
    .form-table textarea,
    .form-table select {
        width: 100%;
        max-width: 300px;
    }

    .tablenav .actions {
        padding: 8px 0;
    }

    .tablenav .actions select,
    .tablenav .actions .search-input {
        margin-right: 5px;
        vertical-align: top;
    }

    .search-input {
        width: 200px;
    }

    .wp-list-table th a {
        color: #0073aa;
        text-decoration: none;
    }

    .wp-list-table th a:hover {
        color: #005a87;
    }

    .tablenav {
        margin: 20px 0;
    }
    </style>

    <script>
    function viewRegistration(registrationId) {
        // This would typically fetch full registration details via AJAX
        // For now, we'll show basic info from the table
        var modal = document.getElementById('registration-modal');
        var detailsDiv = document.getElementById('registration-details');

        detailsDiv.innerHTML = "<h2>Registration #" + registrationId + "</h2><p>Loading details...</p>";
        
        // Fetch registration details via AJAX
        jQuery.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "sbr_get_registration_details",
                registration_id: registrationId,
                nonce: sbrAdminNonce
            },
            success: function(response) {
                if (response.success) {
                    detailsDiv.innerHTML = response.data.html;
                } else {
                    detailsDiv.innerHTML = "<h2>Registration #" + registrationId + "</h2><p style='color: red;'>Error: " + response.data + "</p>";
                }
            },
            error: function() {
                detailsDiv.innerHTML = "<h2>Registration #" + registrationId + "</h2><p style='color: red;'>Failed to load registration details.</p>";
            }
        });
        showModal('registration-modal');
    }

    function markAsPaid(registrationId) {
        document.getElementById('payment_registration_id').value = registrationId;

        // Find the registration row to get current amounts
        var rows = document.querySelectorAll('tbody tr');
        var currentPaid = 0;
        var totalAmount = 0;
        var paymentOption = '';

        for (var i = 0; i < rows.length; i++) {
            var firstCell = rows[i].querySelector('td');
            if (firstCell && firstCell.textContent.trim() == registrationId) {
                // Extract amounts from the amount details cell
                var amountCell = rows[i].children[8]; // Amount Details column
                var totalText = amountCell.querySelector('strong').textContent;
                totalAmount = parseFloat(totalText.replace(/[^\d.]/g, ''));

                // Try to find current paid amount
                var paidText = amountCell.textContent.match(/Paid: \$([0-9,.]+)/);
                if (paidText) {
                    currentPaid = parseFloat(paidText[1].replace(/,/g, ''));
                }

                break;
            }
        }

        // Calculate remaining balance
        var balanceRemaining = Math.max(0, totalAmount - currentPaid);

        // Update the payment summary
        document.getElementById('current_paid').textContent = 'Current amount paid: $' + currentPaid.toFixed(2);
        document.getElementById('balance_remaining').textContent = 'Balance remaining: $' + balanceRemaining.toFixed(2);
        document.getElementById('payment_summary').style.display = 'block';

        // Set default payment amount to remaining balance
        document.getElementById('payment_amount').value = balanceRemaining.toFixed(2);

        showModal('payment-modal');
    }

    function updateStatus(registrationId) {
        document.getElementById('status_registration_id').value = registrationId;
        showModal('status-modal');
    }

    function sendPaymentLink(registrationId) {
        document.getElementById('link_registration_id').value = registrationId;
        showModal('payment-link-modal');
    }

    function showModal(modalId) {
        var modal = document.getElementById(modalId);
        modal.style.display = 'block';

        // Set up close handlers
        var closeBtn = modal.querySelector('.close-modal');
        closeBtn.onclick = function() {
            closeModal(modalId);
        }

        // Close when clicking outside
        window.onclick = function(event) {
            if (event.target == modal) {
                closeModal(modalId);
            }
        }
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }


    // Generate payment link and display it
    function generateAndShowLink() {
        var registrationId = jQuery('#link_registration_id').val();
        
        if (!registrationId) {
            alert('Error: No registration ID found');
            return;
        }
        
        // Show loading state
        jQuery('#payment-link-display').hide();
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sbr_generate_payment_link',
                registration_id: registrationId,
                nonce: '<?php echo wp_create_nonce('sbr_generate_link'); ?>'
            },
            success: function(response) {
                if (response.success && response.data.link) {
                    jQuery('#payment-link-url').val(response.data.link);
                    jQuery('#payment-link-display').show();
                    
                    // Auto-copy to clipboard
                    copyPaymentLink();
                } else {
                    alert('Error: ' + (response.data || 'Could not generate payment link'));
                }
            },
            error: function(xhr, status, error) {
                alert('Error generating link: ' + error);
            }
        });
    }
    
    // Copy payment link to clipboard
    function copyPaymentLink() {
        var linkInput = document.getElementById('payment-link-url');
        linkInput.select();
        linkInput.setSelectionRange(0, 99999); // For mobile devices
        
        try {
            document.execCommand('copy');
            
            // Show success message
            var successMsg = document.getElementById('copy-success');
            successMsg.style.display = 'inline';
            setTimeout(function() {
                successMsg.style.display = 'none';
            }, 3000);
        } catch (err) {
            alert('Failed to copy link. Please copy it manually.');
        }
    }

    // Handle toggle test mode button clicks
    jQuery(document).ready(function($) {
        $('.toggle-test-mode').on('click', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var registrationId = button.data('registration-id');
            var currentMode = button.data('current-mode');
            var newMode = currentMode == '1' ? 0 : 1;
            
            if (!confirm('Are you sure you want to mark this registration as ' + (newMode == 1 ? 'TEST' : 'LIVE') + '?')) {
                return;
            }
            
            button.prop('disabled', true).text('Updating...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sbr_toggle_test_mode',
                    registration_id: registrationId,
                    is_test_mode: newMode,
                    nonce: '<?php echo wp_create_nonce('sbr_toggle_test_mode'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Update button state
                        button.data('current-mode', newMode);
                        button.text('Mark as ' + (newMode == 1 ? 'Live' : 'Test'));
                        button.prop('disabled', false);
                        
                        // Refresh the page to show updated status
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data || 'Failed to update test mode'));
                        button.prop('disabled', false);
                        button.text('Mark as ' + (currentMode == 1 ? 'Live' : 'Test'));
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error updating test mode: ' + error);
                    button.prop('disabled', false);
                    button.text('Mark as ' + (currentMode == 1 ? 'Live' : 'Test'));
                }
            });
        });
    });
    </script>
</div>

<script type="text/javascript">
    function switchToEditMode(registrationId) {
        var modal = document.getElementById("registration-modal");
        var detailsDiv = document.getElementById("registration-details");
        
        detailsDiv.innerHTML = "<h2>Loading edit form...</h2>";
        
        jQuery.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "sbr_get_registration_details",
                registration_id: registrationId,
                mode: "edit",
                nonce: sbrAdminNonce
            },
            success: function(response) {
                if (response.success) {
                    detailsDiv.innerHTML = response.data.html;
                } else {
                    detailsDiv.innerHTML = "<h2>Error</h2><p style=\"color: red;\">" + response.data + "</p>";
                }
            },
            error: function() {
                detailsDiv.innerHTML = "<h2>Error</h2><p style=\"color: red;\">Failed to load edit form.</p>";
            }
        });
    }
    
    function cancelEdit(registrationId) {
        // Reload in view mode
        viewRegistration(registrationId);
    }
    
    function calculateRegistrationTotals() {
        var basePrice = parseFloat(jQuery("#event_base_price").val()) || 0;
        var additionalPrice = parseFloat(jQuery("#event_additional_price").val()) || 0;
        var coachPrice = parseFloat(jQuery("#event_coach_price").val()) || 0;
        var freeCoaches = parseInt(jQuery("#event_free_coaches").val()) || 0;
        
        var competingStudents = parseInt(jQuery("#competing_students").val()) || 0;
        var additionalStudents = parseInt(jQuery("#additional_students").val()) || 0;
        var additionalAdults = parseInt(jQuery("#additional_adults").val()) || 0;
        var coaches = parseInt(jQuery("#coaches").val()) || 0;
        
        var discountAmount = parseFloat(jQuery("#discount_amount").val()) || 0;
        
        // Calculate base total
        var total = basePrice; // Base price for first 4 students
        
        // Add additional students beyond first 4
        if (competingStudents > 4) {
            total += (competingStudents - 4) * additionalPrice;
        }
        
        // Add additional student attendees
        total += additionalStudents * additionalPrice;
        
        // Add additional adult attendees
        total += additionalAdults * additionalPrice;
        
        // Add coach fees (after free coaches)
        if (coaches > freeCoaches) {
            total += (coaches - freeCoaches) * coachPrice;
        }
        
        // Apply discount
        var totalAfterDiscount = Math.max(0, total - discountAmount);
        
        // Update display
        jQuery("#calculated_total").text("$" + total.toFixed(2));
        if (discountAmount > 0) {
            jQuery("#discount_display").show();
            jQuery("#total_after_discount").text("$" + totalAfterDiscount.toFixed(2));
        } else {
            jQuery("#discount_display").hide();
        }
        
        return totalAfterDiscount;
    }
    
    function saveRegistrationChanges(registrationId) {
        var saveButton = jQuery("#save-registration-btn");
        saveButton.prop("disabled", true).text("Saving...");
        
        var formData = {
            action: "sbr_save_registration_edit",
            registration_id: registrationId,
            nonce: sbrAdminNonce,
            school_name: jQuery("#school_name").val(),
            contact_name: jQuery("#contact_name").val(),
            contact_email: jQuery("#contact_email").val(),
            contact_phone: jQuery("#contact_phone").val(),
            grade_level: jQuery("#grade_level").val(),
            session_time: jQuery("#session_time").val(),
            competing_students: jQuery("#competing_students").val(),
            additional_students: jQuery("#additional_students").val(),
            additional_adults: jQuery("#additional_adults").val(),
            coaches: jQuery("#coaches").val(),
            dietary_restrictions: jQuery("#dietary_restrictions").val(),
            discount_amount: jQuery("#discount_amount").val(),
            discount_reason: jQuery("#discount_reason").val(),
            payment_status: jQuery("#payment_status").val(),
            registration_status: jQuery("#registration_status").val(),
            payment_notes: jQuery("#payment_notes").val(),
            status_notes: jQuery("#status_notes").val()
        };
        
        jQuery.ajax({
            url: ajaxurl,
            type: "POST",
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert("Registration updated successfully!");
                    // Show option to send confirmation email
                    var sendEmail = confirm("Would you like to send a confirmation email to the registrant?");
                    if (sendEmail) {
                        sendConfirmationEmail(registrationId);
                    } else {
                        // Reload the page to show updated data
                        location.reload();
                    }
                } else {
                    alert("Error: " + response.data);
                    saveButton.prop("disabled", false).text("Save Changes");
                }
            },
            error: function() {
                alert("Failed to save changes. Please try again.");
                saveButton.prop("disabled", false).text("Save Changes");
            }
        });
    }
    
    function sendConfirmationEmail(registrationId) {
        jQuery.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "sbr_send_confirmation_email",
                registration_id: registrationId,
                nonce: sbrAdminNonce
            },
            success: function(response) {
                if (response.success) {
                    alert("Confirmation email sent successfully!");
                    location.reload();
                } else {
                    alert("Registration saved but email failed: " + response.data);
                    location.reload();
                }
            },
            error: function() {
                alert("Registration saved but email failed to send.");
                location.reload();
            }
        });
    }
    function openAddRegistrationModal() {
        showModal('add-registration-modal');
        // Reset all form fields
        jQuery('#add-registration-form input[type="text"], #add-registration-form input[type="email"], #add-registration-form input[type="tel"], #add-registration-form input[type="number"], #add-registration-form textarea').val('');
        jQuery('#add-registration-form select').prop('selectedIndex', 0);
        jQuery('#add-registration-form input[type="number"]').val('0');
        document.getElementById('duplicate_warning').style.display = 'none';
        document.getElementById('new_discount_reason_row').style.display = 'none';
        document.getElementById('new_discount_display').style.display = 'none';
        document.getElementById('new_calculated_total').textContent = '$0.00';
        document.getElementById('new_balance_due').textContent = '$0.00';
    }
    
    function loadEventPricing() {
        var eventId = jQuery("#new_event_id").val();
        if (!eventId) return;
        
        jQuery.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "sbr_get_event_pricing",
                event_id: eventId,
                nonce: sbrAdminNonce
            },
            success: function(response) {
                if (response.success) {
                    jQuery("#new_event_base_price").val(response.data.base_price);
                    jQuery("#new_event_additional_price").val(response.data.additional_price);
                    jQuery("#new_event_coach_price").val(response.data.coach_price);
                    jQuery("#new_event_free_coaches").val(response.data.free_coaches);
                    calculateNewRegistrationTotal();
                }
            }
        });
    }
    
    function calculateNewRegistrationTotal() {
        var basePrice = parseFloat(jQuery("#new_event_base_price").val()) || 0;
        var additionalPrice = parseFloat(jQuery("#new_event_additional_price").val()) || 0;
        var coachPrice = parseFloat(jQuery("#new_event_coach_price").val()) || 0;
        var freeCoaches = parseInt(jQuery("#new_event_free_coaches").val()) || 0;
        
        var competingStudents = parseInt(jQuery("#new_competing_students").val()) || 0;
        var additionalStudents = parseInt(jQuery("#new_additional_students").val()) || 0;
        var additionalAdults = parseInt(jQuery("#new_additional_adults").val()) || 0;
        var coaches = parseInt(jQuery("#new_coaches").val()) || 0;
        
        var discountAmount = parseFloat(jQuery("#new_discount_amount").val()) || 0;
        var amountPaid = parseFloat(jQuery("#new_amount_paid").val()) || 0;
        
        // Show/hide discount reason field
        if (discountAmount > 0) {
            jQuery("#new_discount_reason_row").show();
        } else {
            jQuery("#new_discount_reason_row").hide();
        }
        
        // Calculate total
        var total = basePrice;
        if (competingStudents > 4) {
            total += (competingStudents - 4) * additionalPrice;
        }
        total += additionalStudents * additionalPrice;
        total += additionalAdults * additionalPrice;
        if (coaches > freeCoaches) {
            total += (coaches - freeCoaches) * coachPrice;
        }
        
        var totalAfterDiscount = Math.max(0, total - discountAmount);
        var balanceDue = Math.max(0, totalAfterDiscount - amountPaid);
        
        // Update display
        jQuery("#new_calculated_total").text("$" + total.toFixed(2));
        if (discountAmount > 0) {
            jQuery("#new_discount_display").show();
            jQuery("#new_discount_value").text("$" + discountAmount.toFixed(2));
            jQuery("#new_total_after_discount").text("$" + totalAfterDiscount.toFixed(2));
        } else {
            jQuery("#new_discount_display").hide();
        }
        jQuery("#new_balance_due").text("$" + balanceDue.toFixed(2));
    }
    
    function checkDuplicateRegistration() {
        var eventId = jQuery("#new_event_id").val();
        var schoolName = jQuery("#new_school_name").val();
        var contactEmail = jQuery("#new_contact_email").val();
        
        if (!eventId || (!schoolName && !contactEmail)) {
            jQuery("#duplicate_warning").hide();
            return;
        }
        
        jQuery.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "sbr_check_duplicate_registration",
                event_id: eventId,
                school_name: schoolName,
                contact_email: contactEmail,
                nonce: sbrAdminNonce
            },
            success: function(response) {
                if (response.success && response.data.is_duplicate) {
                    jQuery("#duplicate_message").text(response.data.message);
                    jQuery("#duplicate_warning").show();
                } else {
                    jQuery("#duplicate_warning").hide();
                }
            }
        });
    }
    
    function saveNewRegistration() {
        var saveButton = jQuery("#save-new-registration-btn");
        saveButton.prop("disabled", true).text("Creating...");
        
        // Validate required fields
        var discountAmount = parseFloat(jQuery("#new_discount_amount").val()) || 0;
        var discountReason = jQuery("#new_discount_reason").val();
        
        if (discountAmount > 0 && !discountReason) {
            alert("Please provide a reason for the discount.");
            saveButton.prop("disabled", false).text("Create Registration");
            return;
        }
        
        var formData = {
            action: "sbr_create_registration_manual",
            nonce: sbrAdminNonce,
            event_id: jQuery("#new_event_id").val(),
            school_name: jQuery("#new_school_name").val(),
            contact_name: jQuery("#new_contact_name").val(),
            contact_email: jQuery("#new_contact_email").val(),
            contact_phone: jQuery("#new_contact_phone").val(),
            grade_level: jQuery("#new_grade_level").val(),
            session_time: jQuery("#new_session_time").val(),
            competing_students: jQuery("#new_competing_students").val(),
            additional_students: jQuery("#new_additional_students").val(),
            additional_adults: jQuery("#new_additional_adults").val(),
            coaches: jQuery("#new_coaches").val(),
            dietary_restrictions: jQuery("#new_dietary_restrictions").val(),
            discount_amount: discountAmount,
            discount_reason: discountReason,
            payment_method: jQuery("#new_payment_method").val(),
            payment_status: jQuery("#new_payment_status").val(),
            amount_paid: jQuery("#new_amount_paid").val()
        };
        
        jQuery.ajax({
            url: ajaxurl,
            type: "POST",
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert("Registration created successfully!");
                    var sendEmail = confirm("Would you like to send a confirmation email to the registrant?");
                    if (sendEmail) {
                        sendConfirmationEmail(response.data.registration_id);
                    } else {
                        location.reload();
                    }
                } else {
                    alert("Error: " + response.data);
                    saveButton.prop("disabled", false).text("Create Registration");
                }
            },
            error: function() {
                alert("Failed to create registration. Please try again.");
                saveButton.prop("disabled", false).text("Create Registration");
            }
        });
    }


</script>


<!-- Add New Registration Modal -->
<div id="add-registration-modal" style="display: none;">
    <div class="registration-modal-content" style="max-width: 800px;">
        <span class="close-modal" onclick="closeModal('add-registration-modal')">&times;</span>
        <h2>Add New Registration</h2>
        
        <div id="add-registration-form">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="new_event_id">Event *</label></th>
                    <td>
                        <select id="new_event_id" name="event_id" required onchange="loadEventPricing()">
                            <option value="">Select Event...</option>
                            <?php
                            $events = $wpdb->get_results("SELECT id, event_name, event_date FROM {$wpdb->prefix}scholars_bowl_events ORDER BY event_date DESC");
                            foreach ($events as $event):
                            ?>
                                <option value="<?php echo $event->id; ?>">
                                    <?php echo esc_html($event->event_name . ' - ' . date('M j, Y', strtotime($event->event_date))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_school_name">School Name *</label></th>
                    <td>
                        <input type="text" id="new_school_name" name="school_name" class="regular-text" required onblur="checkDuplicateRegistration()">
                        <div id="duplicate_warning" style="display: none; margin-top: 10px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
                            <strong>⚠️ Possible Duplicate:</strong> <span id="duplicate_message"></span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_contact_name">Contact Name *</label></th>
                    <td><input type="text" id="new_contact_name" name="contact_name" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_contact_email">Contact Email *</label></th>
                    <td><input type="email" id="new_contact_email" name="contact_email" class="regular-text" required onblur="checkDuplicateRegistration()"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_contact_phone">Contact Phone *</label></th>
                    <td><input type="tel" id="new_contact_phone" name="contact_phone" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_grade_level">School Classification *</label></th>
                    <td>
                        <select id="new_grade_level" name="grade_level" required>
                            <option value="">Select...</option>
                            <option value="6A">6A</option>
                            <option value="5A">5A</option>
                            <option value="4A">4A</option>
                            <option value="3A">3A</option>
                            <option value="2A">2A</option>
                            <option value="Private">Private</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_session_time">Session *</label></th>
                    <td>
                        <select id="new_session_time" name="session_time" required>
                            <option value="morning">Morning (8:30 AM)</option>
                            <option value="afternoon">Afternoon (12:15 PM)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_competing_students">Competing Students *</label></th>
                    <td>
                        <select id="new_competing_students" name="competing_students" required onchange="calculateNewRegistrationTotal()">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $i == 4 ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <small>Number of students competing (typically 4)</small>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_additional_students">Additional Student Attendees</label></th>
                    <td>
                        <select id="new_additional_students" name="additional_students" onchange="calculateNewRegistrationTotal()">
                            <?php for ($i = 0; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <small>Additional students beyond competing team</small>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_additional_adults">Additional Adult Attendees</label></th>
                    <td>
                        <select id="new_additional_adults" name="additional_adults" onchange="calculateNewRegistrationTotal()">
                            <?php for ($i = 0; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <small>Parents, administrators, or other adults</small>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_coaches">Number of Coaches</label></th>
                    <td>
                        <select id="new_coaches" name="coaches" onchange="calculateNewRegistrationTotal()">
                            <?php for ($i = 0; $i <= 4; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $i == 1 ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <small id="coach_pricing_note">First coach free, additional coaches charged</small>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_dietary_restrictions">Dietary Restrictions</label></th>
                    <td>
                        <textarea id="new_dietary_restrictions" name="dietary_restrictions" rows="3" class="large-text"></textarea>
                        <small>Any dietary restrictions or allergies for attendees</small>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_discount_amount">Discount Amount</label></th>
                    <td>
                        <input type="number" id="new_discount_amount" name="discount_amount" step="0.01" min="0" value="0" onchange="calculateNewRegistrationTotal()">
                        <small>Fixed discount amount (if applicable)</small>
                    </td>
                </tr>
                <tr id="new_discount_reason_row" style="display: none;">
                    <th scope="row"><label for="new_discount_reason">Discount Reason *</label></th>
                    <td>
                        <textarea id="new_discount_reason" name="discount_reason" rows="2" class="large-text"></textarea>
                        <small>Required when applying a discount</small>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_payment_method">Payment Method *</label></th>
                    <td>
                        <select id="new_payment_method" name="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                            <option value="stripe">Credit Card (Stripe)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_payment_status">Payment Status *</label></th>
                    <td>
                        <select id="new_payment_status" name="payment_status" required>
                            <option value="pending">Pending</option>
                            <option value="partial">Partial Payment</option>
                            <option value="completed">Paid in Full</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_amount_paid">Amount Paid</label></th>
                    <td>
                        <input type="number" id="new_amount_paid" name="amount_paid" step="0.01" min="0" value="0" onchange="calculateNewRegistrationTotal()">
                        <small>Amount already paid by registrant</small>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Calculated Total</th>
                    <td>
                        <div style="font-size: 16px; padding: 10px; background: #f0f0f0; border-radius: 3px;">
                            <strong>Total Amount:</strong> <span id="new_calculated_total">$0.00</span><br>
                            <span id="new_discount_display" style="display: none;">
                                <strong>Discount:</strong> <span id="new_discount_value">$0.00</span><br>
                                <strong>Amount After Discount:</strong> <span id="new_total_after_discount">$0.00</span><br>
                            </span>
                            <strong>Balance Due:</strong> <span id="new_balance_due">$0.00</span>
                        </div>
                        <!-- Hidden fields for event pricing -->
                        <input type="hidden" id="new_event_base_price" value="0">
                        <input type="hidden" id="new_event_additional_price" value="0">
                        <input type="hidden" id="new_event_coach_price" value="0">
                        <input type="hidden" id="new_event_free_coaches" value="0">
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="button" class="button button-primary" id="save-new-registration-btn" onclick="saveNewRegistration()">
                    Create Registration
                </button>
                <button type="button" class="button" onclick="closeModal('add-registration-modal')">Cancel</button>
            </p>
        </div>
    </div>
</div>

</div>
