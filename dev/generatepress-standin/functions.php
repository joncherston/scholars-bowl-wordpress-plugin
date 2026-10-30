<?php
// LOCAL STAND-IN for GeneratePress (screenshots only).
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('custom-logo');
    register_nav_menus(array('primary' => 'Primary Menu'));
});
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('generate-style', get_template_directory_uri() . '/main.css', array(), '3.6.0');
    if (is_child_theme()) {
        wp_enqueue_style('generate-child', get_stylesheet_uri(), array('generate-style'), filemtime(get_stylesheet_directory() . '/style.css'));
    }
});
add_filter('body_class', function ($c) { $c[] = 'separate-containers'; $c[] = 'no-sidebar'; return $c; });
function generate_do_element_classes($context) {
    $map = array('content' => 'content-area', 'main' => 'site-main');
    echo 'class="' . esc_attr(isset($map[$context]) ? $map[$context] : $context) . '"';
}
function generate_construct_sidebars() {}
