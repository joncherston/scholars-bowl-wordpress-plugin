<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$events_table = $wpdb->prefix . 'scholars_bowl_events';

// Handle event actions
if (isset($_GET['action']) && isset($_GET['event_id']) && current_user_can('manage_options')) {
    $event_id = intval($_GET['event_id']);
    $action_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

    if ($_GET['action'] === 'delete' && wp_verify_nonce($action_nonce, 'delete_event_' . $event_id)) {
        if ($wpdb->delete($events_table, array('id' => $event_id), array('%d'))) {
            SBR_Webhook::notify('event.deleted', $event_id);
        }
        echo '<div class="notice notice-success"><p>Event deleted successfully!</p></div>';
    }

    if ($_GET['action'] === 'toggle_status' && wp_verify_nonce($action_nonce, 'toggle_status_' . $event_id)) {
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT event_status FROM $events_table WHERE id = %d", $event_id));
        $new_status = ($current_status === 'active') ? 'inactive' : 'active';
        $toggle = array('event_status' => $new_status);
        if (SBR_Schema::has_column($events_table, 'updated_at')) {
            $toggle['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        $wpdb->update($events_table, $toggle, array('id' => $event_id));
        SBR_Webhook::notify('event.saved', $event_id);
        echo '<div class="notice notice-success"><p>Event status updated successfully!</p></div>';
    }
}

$events = $wpdb->get_results("SELECT * FROM $events_table ORDER BY event_date DESC");
?>

<div class="wrap">
    <h1>
        Scholars' Bowl Events
        <a href="<?php echo admin_url('admin.php?page=scholars-bowl-add-event'); ?>" class="page-title-action">Add New Event</a>
    </h1>

    <?php if (empty($events)): ?>
        <div class="notice notice-info">
            <p>No events found. <a href="<?php echo admin_url('admin.php?page=scholars-bowl-add-event'); ?>">Create your first event</a>.</p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col">Event Name</th>
                    <th scope="col">Event Date</th>
                    <th scope="col">Divisions</th>
                    <th scope="col">Status</th>
                    <th scope="col">Shortcode</th>
                    <th scope="col">Registrations</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <?php
                    $registrations_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}scholars_bowl_registrations WHERE event_id = %d", $event->id));
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($event->event_name); ?></strong>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo admin_url('admin.php?page=scholars-bowl-add-event&event_id=' . $event->id); ?>">Edit</a> |
                                </span>
                                <span class="view">
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=scholars-bowl-events&action=toggle_status&event_id=' . $event->id), 'toggle_status_' . $event->id); ?>">
                                        <?php echo $event->event_status === 'active' ? 'Deactivate' : 'Activate'; ?>
                                    </a> |
                                </span>
                                <span class="delete">
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=scholars-bowl-events&action=delete&event_id=' . $event->id), 'delete_event_' . $event->id); ?>"
                                       onclick="return confirm('Are you sure you want to delete this event? This will also delete all registrations for this event.');" class="submitdelete">Delete</a>
                                </span>
                            </div>
                        </td>
                        <td><?php echo esc_html(date('F j, Y', strtotime($event->event_date))); ?></td>
                        <td>
                            <?php
                            $sbr_config = SBR_Tournament::get_config($event);
                            foreach ($sbr_config['divisions'] as $sbr_division) {
                                echo '<div>' . esc_html($sbr_division['name']) . ' <small class="description">(' . esc_html(ucfirst($sbr_division['session']) . ', ' . ($sbr_division['format'] === 'bracket' ? 'bracket' : 'round robin')) . ')</small></div>';
                            }
                            if (!empty($sbr_config['synthesized'])) {
                                echo '<small class="description">Defaults; edit the event to change.</small>';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="status-<?php echo esc_attr($event->event_status); ?>">
                                <?php echo $event->event_status === 'active' ? '🟢 Active' : '🔴 Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <code>[scholars_bowl_registration event="<?php echo esc_attr($event->event_slug); ?>"]</code>
                            <button type="button" class="button button-small copy-shortcode" data-shortcode='[scholars_bowl_registration event="<?php echo esc_attr($event->event_slug); ?>"]'>Copy</button>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=scholars-bowl-registrations&event_id=' . $event->id); ?>">
                                <?php echo intval($registrations_count); ?> registration<?php echo intval($registrations_count) !== 1 ? 's' : ''; ?>
                            </a>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=scholars-bowl-add-event&event_id=' . $event->id); ?>" class="button button-small">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="card" style="margin-top: 20px;">
        <h2>Usage Instructions</h2>
        <p>To display a registration form for a specific event, use the shortcode format:</p>
        <code>[scholars_bowl_registration event="event-slug"]</code>

        <h3>Examples:</h3>
        <ul>
            <li><code>[scholars_bowl_registration event="scholars-bowl-2025"]</code> - High School Scholars' Bowl</li>
            <li><code>[scholars_bowl_registration event="college-bowl-2024"]</code> - College Bowl Tournament</li>
            <li><code>[scholars_bowl_registration event="regional-championship"]</code> - Regional Championship</li>
        </ul>

        <p><strong>Note:</strong> Each event has its own configuration, pricing, and registration database. You can create multiple events and embed different registration forms on different pages.</p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('.copy-shortcode').on('click', function() {
        var shortcode = $(this).data('shortcode');
        navigator.clipboard.writeText(shortcode).then(function() {
            var button = $(this);
            button.text('Copied!').addClass('button-primary');
            setTimeout(function() {
                button.text('Copy').removeClass('button-primary');
            }, 2000);
        }.bind(this));
    });
});
</script>

<style>
.status-active { color: #46b450; font-weight: 600; }
.status-inactive { color: #dc3232; font-weight: 600; }
.copy-shortcode { margin-left: 10px; }
.card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; }
.card h2 { margin-top: 0; }
.card h3 { color: #1d2327; }
.card ul { padding-left: 20px; }
.card code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
</style>