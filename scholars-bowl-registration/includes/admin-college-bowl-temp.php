<?php
if (!defined("ABSPATH")) exit;

function college_bowl_admin_page() {
    global \$wpdb;
    \$table_name = \$wpdb->prefix . "college_bowl_payments";
    
    if (isset(\$_GET["export_csv"]) && \$_GET["export_csv"] === "1") {
        cbp_export_csv();
        exit;
    }
    
    \$payments = \$wpdb->get_results("SELECT * FROM {\$table_name} ORDER BY payment_date DESC");
    \$total_payments = count(\$payments);
    \$total_revenue = \$wpdb->get_var("SELECT SUM(amount) FROM {\$table_name}");
    \$total_teams = \$wpdb->get_var("SELECT SUM(num_teams) FROM {\$table_name}");
    echo "<div class=\"wrap\"><h1>College Bowl Payments</h1>";
    echo "<div style=\"display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:20px 0\">";
    echo "<div style=\"background:#fff;padding:20px;border-left:4px solid #0073aa\"><h3>Total Payments</h3><p style=\"font-size:32px;font-weight:bold\">{\$total_payments}</p></div>";
    echo "<div style=\"background:#fff;padding:20px;border-left:4px solid #46b450\"><h3>Total Revenue</h3><p style=\"font-size:32px;font-weight:bold\">$" . number_format(\$total_revenue, 2) . "</p></div>";
    echo "<div style=\"background:#fff;padding:20px;border-left:4px solid #dc3232\"><h3>Total Teams</h3><p style=\"font-size:32px;font-weight:bold\">{\$total_teams}</p></div></div>";
    echo "<div style=\"margin:20px 0\"><a href=\"?page=college-bowl-payments&export_csv=1\" class=\"button button-primary\">Export to CSV</a></div>";
    echo "<table class=\"wp-list-table widefat fixed striped\"><thead><tr><th>ID</th><th>Date</th><th>Name</th><th>Email</th><th>College</th><th>Teams</th><th>Amount</th><th>Transaction ID</th></tr></thead><tbody>";
    if (empty(\$payments)) {
        echo "<tr><td colspan=\"8\" style=\"text-align:center;padding:40px\">No payments received yet.</td></tr>";
    } else {
        foreach (\$payments as \$p) {
            echo "<tr><td>{\$p->id}</td>";
            echo "<td>" . date("M j, Y g:i A", strtotime(\$p->payment_date)) . "</td>";
            echo "<td>" . esc_html(\$p->name) . "</td>";
            echo "<td><a href=\"mailto:" . esc_attr(\$p->email) . "\">" . esc_html(\$p->email) . "</a></td>";
            echo "<td>" . esc_html(\$p->college_name) . "</td>";
            echo "<td>{\$p->num_teams}</td>";
            echo "<td><strong>$" . number_format(\$p->amount, 2) . "</strong></td>";
            echo "<td><code>" . esc_html(\$p->stripe_payment_intent_id) . "</code></td></tr>";
        }
    }
    echo "</tbody></table></div>";
}

function cbp_export_csv() {
    global \$wpdb;
    \$table_name = \$wpdb->prefix . "college_bowl_payments";
    \$payments = \$wpdb->get_results("SELECT * FROM {\$table_name} ORDER BY payment_date DESC", ARRAY_A);
    \$filename = "college-bowl-payments-" . date("Y-m-d") . ".csv";
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=" . \$filename);
    \$output = fopen("php://output", "w");
    fputcsv(\$output, array("ID", "Payment Date", "Name", "Email", "College", "Teams", "Amount", "Transaction ID", "IP Address"));
    foreach (\$payments as \$payment) {
        fputcsv(\$output, array(\$payment["id"], \$payment["payment_date"], \$payment["name"], \$payment["email"], \$payment["college_name"], \$payment["num_teams"], \$payment["amount"], \$payment["stripe_payment_intent_id"], \$payment["ip_address"]));
    }
    fclose(\$output);
}
