<?php
/**
 * Plugin Name: SBR dev mail log
 * Description: Development only. Writes every wp_mail() call to a JSON-lines file instead of sending it.
 * Install by copying into wp-content/mu-plugins/ on a local test site. Never on the live site.
 */
add_filter('pre_wp_mail', function ($null, $atts) {
    $file = getenv('SBR_MAIL_LOG') ?: WP_CONTENT_DIR . '/sbr-mail.log';
    file_put_contents($file, wp_json_encode(array(
        'time' => gmdate('c'),
        'to' => $atts['to'],
        'subject' => $atts['subject'],
        'message' => $atts['message'],
        'headers' => $atts['headers'],
    )) . "\n", FILE_APPEND | LOCK_EX);
    return true;
}, 10, 2);
