<?php
/**
 * Template Name: Custom Header Logo
 * Template Post Type: page
 * 
 * This template allows you to use a different logo in the header
 * for specific pages. Select this template in Page Attributes,
 * then upload your custom logo in the "Custom Header Logo" meta box.
 *
 * @package GeneratePress-Scholars-Bowl
 */

// This template uses the default page structure
// The logo replacement is handled via filters in functions.php
get_header();
?>

<div id="primary" <?php generate_do_element_classes( 'content' ); ?>>
    <main id="main" <?php generate_do_element_classes( 'main' ); ?>>
        <?php
        /**
         * generate_before_main_content hook.
         *
         * @since 0.1
         */
        do_action( 'generate_before_main_content' );

        while ( have_posts() ) :
            the_post();

            get_template_part( 'content', 'page' );

            // If comments are open or we have at least one comment, load up the comment template.
            if ( comments_open() || get_comments_number() ) :
                comments_template();
            endif;

        endwhile;

        /**
         * generate_after_main_content hook.
         *
         * @since 0.1
         */
        do_action( 'generate_after_main_content' );
        ?>
    </main>
</div>

<?php
/**
 * generate_after_primary_content_area hook.
 *
 * @since 2.0
 */
do_action( 'generate_after_primary_content_area' );

generate_construct_sidebars();

get_footer();
