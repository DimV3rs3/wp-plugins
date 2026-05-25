<?php
/**
 * Single page for wsc_yard CPT — minimal redirect to associated wsp_yard if exists.
 *
 * @package WorldStatCourtyard
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<div class="wsp-container">
	<h1><?php the_title(); ?></h1>
	<?php the_content(); ?>
</div>
<?php
get_footer();
