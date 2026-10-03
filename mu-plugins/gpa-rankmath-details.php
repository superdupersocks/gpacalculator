<?php
/**
 * Plugin Name: GPA: Rank Math reads blocks inside Details
 * Description: The collapsed "On this page" list wraps the Rank Math TOC block in a core Details block. Rank Math's
 *              schema parser only looks inside groups and columns, so without this the TOC's SiteNavigationElement
 *              (and any Rank Math FAQ/HowTo placed in a Details block) silently disappears from the page's schema.
 *              Lives in mu-plugins so no theme deploy, from any branch, can drop it. functions.php may carry the same
 *              filter; adding the block name twice is harmless.
 * Version:     1.0.0
 */
add_filter( 'rank_math/schema/nested_blocks', function ( $blocks ) {
	if ( ! in_array( 'core/details', $blocks, true ) ) {
		$blocks[] = 'core/details';
	}
	return $blocks;
} );
