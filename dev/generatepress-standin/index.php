<?php get_header(); ?>
<div id="primary" <?php generate_do_element_classes('content'); ?>><main id="main" <?php generate_do_element_classes('main'); ?>>
<?php while (have_posts()) { the_post(); get_template_part('content', 'page'); } ?>
</main></div>
<?php generate_construct_sidebars(); get_footer();
