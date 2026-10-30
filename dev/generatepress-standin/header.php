<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text skip-link" href="#content">Skip to content</a>
<header class="site-header" id="masthead" aria-label="Site">
<div class="inside-header grid-container">
<div class="site-branding"><p class="main-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></p><p class="site-description"><?php bloginfo('description'); ?></p></div>
<nav class="main-navigation" id="site-navigation" aria-label="Primary"><div class="inside-navigation grid-container"><div id="primary-menu" class="main-nav">
<?php wp_nav_menu(array('theme_location' => 'primary', 'container' => false, 'menu_class' => 'menu sf-menu', 'fallback_cb' => function () { echo '<ul class="menu sf-menu">'; wp_list_pages(array('title_li' => '')); echo '</ul>'; })); ?>
</div></div></nav>
</div>
</header>
<div class="site grid-container container hfeed" id="page">
<div class="site-content" id="content">
