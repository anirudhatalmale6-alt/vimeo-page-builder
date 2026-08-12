<?php
/**
 * End-to-end build test against the seeded master page.
 */

wp_set_current_user( 1 );

function vpb_report( $label, $res ) {
	echo "\n=== {$label} ===\n";
	if ( is_wp_error( $res ) ) {
		echo "WP_Error [" . $res->get_error_code() . "] " . $res->get_error_message() . "\n";
		return null;
	}
	foreach ( $res as $k => $v ) {
		printf( "  %-11s %s\n", $k, is_scalar( $v ) ? $v : wp_json_encode( $v ) );
	}
	return $res;
}

/* 1. A normal build. */
$a = vpb_report( 'build 1234567 (bare id)', VPB_Builder::build( '1234567' ) );

/* 2. Same video again - must not create a second page. */
$b = vpb_report( 'build 1234567 again', VPB_Builder::build( '1234567' ) );

/* 3. A full URL with an unlisted hash. */
$c = vpb_report( 'build url + hash', VPB_Builder::build( 'https://vimeo.com/347119375/8f2a1b9c3d' ) );

/* 4. Rubbish input. */
vpb_report( 'build garbage', VPB_Builder::build( 'hello world' ) );

/* 5. Empty input. */
vpb_report( 'build empty', VPB_Builder::build( '' ) );

/* ---- now inspect what actually landed in the database ---- */

if ( $a && isset( $a['post_id'] ) ) {
	$id  = $a['post_id'];
	$raw = get_post_meta( $id, '_elementor_data', true );
	$d   = json_decode( $raw, true );

	echo "\n=== integrity of built page {$id} ===\n";
	echo "  json decodes        : " . ( is_array( $d ) ? 'yes' : 'NO' ) . "\n";
	echo "  slug                : " . get_post_field( 'post_name', $id ) . "\n";
	echo "  title               : " . get_the_title( $id ) . "\n";
	echo "  status              : " . get_post_status( $id ) . "\n";
	echo "  page template       : " . get_post_meta( $id, '_wp_page_template', true ) . "\n";
	echo "  edit mode           : " . get_post_meta( $id, '_elementor_edit_mode', true ) . "\n";
	echo "  template type       : " . get_post_meta( $id, '_elementor_template_type', true ) . "\n";
	echo "  page settings       : " . wp_json_encode( get_post_meta( $id, '_elementor_page_settings', true ) ) . "\n";
	echo "  _elementor_css copied: " . ( metadata_exists( 'post', $id, '_elementor_css' ) ? 'YES (bad)' : 'no (good)' ) . "\n";

	// The structure must be identical to the master, bar the video URL.
	$master = json_decode( get_post_meta( 5, '_elementor_data', true ), true );

	$strip = function ( $tree ) use ( &$strip ) {
		$out = array();
		foreach ( $tree as $n ) {
			$row = array(
				'id'     => isset( $n['id'] ) ? $n['id'] : '',
				'elType' => isset( $n['elType'] ) ? $n['elType'] : '',
				'widget' => isset( $n['widgetType'] ) ? $n['widgetType'] : '',
			);
			if ( ! empty( $n['elements'] ) ) {
				$row['kids'] = $strip( $n['elements'] );
			}
			$out[] = $row;
		}
		return $out;
	};

	$same = ( wp_json_encode( $strip( $master ) ) === wp_json_encode( $strip( $d ) ) );
	echo "  structure identical : " . ( $same ? 'yes' : 'NO' ) . "\n";

	// Find every vimeo reference in both.
	$urls = array();
	array_walk_recursive( $d, function ( $v, $k ) use ( &$urls ) {
		if ( is_string( $v ) && false !== stripos( $v, 'vimeo' ) ) {
			$urls[] = "{$k} = {$v}";
		}
	} );
	echo "  vimeo refs in new   : " . implode( ' | ', $urls ) . "\n";

	$murls = array();
	array_walk_recursive( $master, function ( $v, $k ) use ( &$murls ) {
		if ( is_string( $v ) && false !== stripos( $v, 'vimeo' ) ) {
			$murls[] = "{$k} = {$v}";
		}
	} );
	echo "  vimeo refs in master: " . implode( ' | ', $murls ) . "\n";

	// Non-video settings must be untouched.
	echo "  hero bg colour      : " . $d[0]['settings']['background_color'] . "\n";
	echo "  heading text        : " . $d[0]['elements'][0]['elements'][0]['settings']['title'] . "\n";
	echo "  button link         : " . $d[1]['elements'][0]['elements'][1]['settings']['link']['url'] . "\n";
}

if ( $c && isset( $c['post_id'] ) ) {
	$d = json_decode( get_post_meta( $c['post_id'], '_elementor_data', true ), true );
	echo "\n=== unlisted-hash page {$c['post_id']} ===\n";
	echo "  slug     : " . get_post_field( 'post_name', $c['post_id'] ) . "\n";
	echo "  vimeo_url: " . $d[0]['elements'][0]['elements'][1]['settings']['vimeo_url'] . "\n";
	echo "  hash meta: " . get_post_meta( $c['post_id'], '_vpb_vimeo_hash', true ) . "\n";
}

echo "\n=== all pages now ===\n";
foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => -1, 'post_status' => 'any' ) ) as $p ) {
	printf( "  #%-3d %-28s /%s\n", $p->ID, get_the_title( $p ), $p->post_name );
}
