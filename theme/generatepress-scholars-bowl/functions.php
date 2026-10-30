<?php
/**
 * GeneratePress - Scholars Bowl Child Theme Functions
 *
 * @package GeneratePress-Scholars-Bowl
 */

/**
 * Self-hosted brand fonts (2.1.0): Libre Franklin (headings, numbers) and Mulish (text),
 * SIL Open Font License, files in fonts/. No requests to Google Fonts.
 * The Scholars' Bowl Registration plugin uses the same 'suscc-fonts' handle, so they load once.
 */
function sb_enqueue_brand_fonts() {
	wp_register_style( 'suscc-fonts', get_stylesheet_directory_uri() . '/fonts/fonts.css', array(), wp_get_theme()->get( 'Version' ) );
	wp_enqueue_style( 'suscc-fonts' );
}
add_action( 'wp_enqueue_scripts', 'sb_enqueue_brand_fonts', 5 );

/**
 * Preload the two font files every page uses, so text doesn't reflow when they arrive.
 */
function sb_preload_brand_fonts() {
	$base = get_stylesheet_directory_uri() . '/fonts/';
	foreach ( array( 'mulish-latin-400-normal.woff2', 'libre-franklin-latin-700-normal.woff2' ) as $file ) {
		printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( $base . $file ) );
	}
}
add_action( 'wp_head', 'sb_preload_brand_fonts', 2 );

/**
 * Register the custom logo meta box for pages
 */
function sb_add_custom_logo_meta_box() {
	add_meta_box(
		'sb_custom_header_logo',
		'Custom Header Logo',
		'sb_custom_logo_meta_box_html',
		'page',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'sb_add_custom_logo_meta_box' );

/**
 * Render the custom logo meta box HTML - Simple dropdown version
 */
function sb_custom_logo_meta_box_html( $post ) {
	// Add nonce for security
	wp_nonce_field( 'sb_custom_logo_nonce', 'sb_custom_logo_nonce' );
	
	// Get the current custom logo ID
	$custom_logo_id = get_post_meta( $post->ID, '_sb_custom_header_logo', true );
	
	// Get all images from media library
	$images = get_posts( array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => 100,
		'orderby'        => 'title',
		'order'          => 'ASC',
	) );
	
	?>
	<div class="sb-custom-logo-wrapper">
		<p class="description">
			Select a custom logo to display in the header when using the "Custom Header Logo" page template.
		</p>
		
		<p>
			<label for="sb_custom_header_logo"><strong>Select Logo:</strong></label><br>
			<select id="sb_custom_header_logo" name="sb_custom_header_logo" style="width: 100%; margin-top: 5px;">
				<option value="">— Use Default Site Logo —</option>
				<?php foreach ( $images as $image ) : ?>
					<option value="<?php echo esc_attr( $image->ID ); ?>" <?php selected( $custom_logo_id, $image->ID ); ?>>
						<?php echo esc_html( $image->post_title ); ?> (ID: <?php echo $image->ID; ?>)
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		
		<?php if ( $custom_logo_id ) : 
			$preview_url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
			if ( $preview_url ) :
		?>
			<p><strong>Current Selection:</strong></p>
			<div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
				<img src="<?php echo esc_url( $preview_url ); ?>" style="max-width: 100%; height: auto;" />
			</div>
		<?php 
			endif;
		endif; ?>
		
		<p class="description" style="margin-top: 10px;">
			<strong>Note:</strong> Make sure you have selected the "Custom Header Logo" page template above for this to work.
		</p>
	</div>
	<?php
}

/**
 * Save the custom logo meta data
 */
function sb_save_custom_logo_meta( $post_id ) {
	// Security checks
	if ( ! isset( $_POST['sb_custom_logo_nonce'] ) ) {
		return;
	}
	
	if ( ! wp_verify_nonce( $_POST['sb_custom_logo_nonce'], 'sb_custom_logo_nonce' ) ) {
		return;
	}
	
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	
	// Save or delete the custom logo ID
	if ( isset( $_POST['sb_custom_header_logo'] ) ) {
		$logo_id = sanitize_text_field( $_POST['sb_custom_header_logo'] );
		if ( ! empty( $logo_id ) ) {
			update_post_meta( $post_id, '_sb_custom_header_logo', $logo_id );
		} else {
			delete_post_meta( $post_id, '_sb_custom_header_logo' );
		}
	}
}
add_action( 'save_post', 'sb_save_custom_logo_meta' );

/**
 * Filter the logo output to use custom logo on designated pages
 */
function sb_filter_custom_header_logo( $output ) {
	// Only run on singular pages
	if ( ! is_singular( 'page' ) ) {
		return $output;
	}
	
	// Check if we're on a page using the custom logo template
	$template = get_page_template_slug();
	if ( $template !== 'page-custom-logo.php' ) {
		return $output;
	}
	
	// Get the custom logo ID
	$custom_logo_id = get_post_meta( get_the_ID(), '_sb_custom_header_logo', true );
	
	if ( ! $custom_logo_id ) {
		return $output;
	}
	
	// Get the custom logo image
	$custom_logo = wp_get_attachment_image(
		$custom_logo_id,
		'full',
		false,
		array(
			'class' => 'header-image is-logo-image',
			'alt'   => get_bloginfo( 'name' ),
			'title' => get_bloginfo( 'name' ),
		)
	);
	
	if ( ! $custom_logo ) {
		return $output;
	}
	
	// Build the new logo HTML
	$new_output = sprintf(
		'<div class="site-logo">
			<a href="%1$s" rel="home">
				%2$s
			</a>
		</div>',
		esc_url( home_url( '/' ) ),
		$custom_logo
	);
	
	return $new_output;
}
add_filter( 'generate_logo_output', 'sb_filter_custom_header_logo' );

/**
 * Alternative: Filter the theme_mod for custom_logo
 */
function sb_filter_custom_logo_id( $logo_id ) {
	if ( ! is_singular( 'page' ) ) {
		return $logo_id;
	}
	
	$template = get_page_template_slug();
	if ( $template !== 'page-custom-logo.php' ) {
		return $logo_id;
	}
	
	$custom_logo_id = get_post_meta( get_the_ID(), '_sb_custom_header_logo', true );
	
	if ( $custom_logo_id ) {
		return $custom_logo_id;
	}
	
	return $logo_id;
}
add_filter( 'theme_mod_custom_logo', 'sb_filter_custom_logo_id' );

/**
 * Add body class when custom logo is active
 */
function sb_custom_logo_body_class( $classes ) {
	if ( is_singular( 'page' ) ) {
		$template = get_page_template_slug();
		$custom_logo_id = get_post_meta( get_the_ID(), '_sb_custom_header_logo', true );
		
		if ( $template === 'page-custom-logo.php' && $custom_logo_id ) {
			$classes[] = 'has-custom-page-logo';
		}
	}
	return $classes;
}
add_filter( 'body_class', 'sb_custom_logo_body_class' );
require_once get_stylesheet_directory() . '/registration-report.php';