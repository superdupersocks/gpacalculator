<?php
// Rebuild the unregistered legacy crops (960x700, 400x300) from the new charts under their old names.
global $wpdb;
$n = 0;
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name REGEXP '^[0-4]-[0-9]-gpa$'" ) as $pid ) {
	$att  = (int) get_post_thumbnail_id( $pid );
	$file = get_attached_file( $att );
	$meta = wp_get_attachment_metadata( $att );
	foreach ( array( 'gpa-legacy-960x700' => array( 960, 700 ), 'gpa-legacy-400x300' => array( 400, 300 ) ) as $key => $wh ) {
		$out = preg_replace( '/\.png$/', "-{$wh[0]}x{$wh[1]}.png", $file );
		$ed  = wp_get_image_editor( $file );
		if ( is_wp_error( $ed ) || is_wp_error( $ed->resize( $wh[0], $wh[1], true ) ) ) { echo "$att $key resize failed\n"; continue; }
		$saved = $ed->save( $out, 'image/png' );
		if ( is_wp_error( $saved ) ) { echo "$att $key save failed\n"; continue; }
		$ed2 = wp_get_image_editor( $out );
		$webp = ! is_wp_error( $ed2 ) && ! is_wp_error( $ed2->save( $out . '.webp', 'image/webp' ) );
		$meta['sizes'][ $key ] = array( 'file' => basename( $out ), 'width' => $wh[0], 'height' => $wh[1], 'mime-type' => 'image/png', 'filesize' => filesize( $out ) );
		$n += $webp ? 1 : 0;
	}
	wp_update_attachment_metadata( $att, $meta );
}
echo "$n legacy crops rebuilt (png + webp)\n";
