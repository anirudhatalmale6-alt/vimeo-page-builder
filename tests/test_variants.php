<?php
/**
 * The master page will not always be a tidy Elementor video widget.
 * These are the other shapes a real site throws at us.
 */

wp_set_current_user( 1 );

function vpb_make_template( $title, $data ) {
	$id = wp_insert_post( array(
		'post_title'  => $title,
		'post_type'   => 'page',
		'post_status' => 'publish',
	) );
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_template_type', 'wp-page' );
	return $id;
}

function vpb_try( $label, $template_id, $new_id, $expect_fail = false ) {
	// Each variant must start clean, otherwise the duplicate guard fires and
	// every run after the first just hands back the previous variant's page.
	foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => -1, 'post_status' => 'any' ) ) as $p ) {
		if ( get_post_meta( $p->ID, '_vpb_vimeo_id', true ) ) {
			wp_delete_post( $p->ID, true );
		}
	}

	$s                = vpb_settings();
	$s['template_id'] = $template_id;
	$s['source_vimeo']= '';
	$s['verify_vimeo']= 0;   // offline: we are testing the swap, not Vimeo
	update_option( VPB_OPTION, $s );

	$detected = VPB_Builder::detect_vimeo_id( $template_id );
	$all      = VPB_Builder::all_vimeo_ids( $template_id );
	echo "\n=== {$label} ===\n";
	echo "  detected in master : " . ( $detected ? $detected : '(none)' )
		. ( count( $all ) > 1 ? '   [all: ' . implode( ', ', $all ) . ']' : '' ) . "\n";

	$r = VPB_Builder::build( $new_id );

	if ( is_wp_error( $r ) ) {
		echo "  result             : ERROR [" . $r->get_error_code() . "] " . substr( $r->get_error_message(), 0, 100 ) . "\n";
		echo "  expected failure   : " . ( $expect_fail ? 'yes - correct' : 'NO - PROBLEM' ) . "\n";
		return;
	}

	if ( $expect_fail ) {
		echo "  result             : built #{$r['post_id']} - EXPECTED THIS TO FAIL\n";
		return;
	}

	$d    = json_decode( get_post_meta( $r['post_id'], '_elementor_data', true ), true );
	$refs = array();
	array_walk_recursive( $d, function ( $v, $k ) use ( &$refs ) {
		if ( is_string( $v ) && preg_match_all( '#vimeo\.com/(?:video/)?(\d+)#i', $v, $m ) ) {
			foreach ( $m[1] as $hit ) { $refs[] = "{$k}:{$hit}"; }
		}
	} );

	// "Stale" means the video we set out to replace is still referenced.
	// A SECOND, unrelated video on the master page staying put is the correct
	// outcome, not a failure - so only the replaced ID counts against us.
	// The expected ID also has to be parsed out, since $new_id may be a full URL.
	$parsed   = VPB_Vimeo::parse( $new_id );
	$want     = is_wp_error( $parsed ) ? $new_id : $parsed['id'];
	$replaced = $detected;

	$stale = false;
	foreach ( $refs as $ref ) {
		if ( $replaced && false !== strpos( $ref, ':' . $replaced ) && $replaced !== $want ) {
			$stale = true;
		}
	}

	$has_new = false;
	foreach ( $refs as $ref ) {
		if ( false !== strpos( $ref, ':' . $want ) ) { $has_new = true; }
	}

	echo "  built              : #{$r['post_id']} slug=" . get_post_field( 'post_name', $r['post_id'] ) . "\n";
	echo "  status             : {$r['status']}\n";
	echo "  widgets swapped    : " . ( isset( $r['swapped'] ) ? $r['swapped'] : 'n/a' ) . "\n";
	echo "  vimeo refs after   : " . implode( ', ', array_unique( $refs ) ) . "\n";
	echo "  new video present  : " . ( $has_new ? 'yes' : 'NO - PROBLEM' ) . "\n";
	echo "  old video gone     : " . ( $stale ? 'NO - PROBLEM' : 'yes' ) . "\n";

	// Show the full rewritten URLs so a stale privacy hash cannot hide.
	$urls = array();
	array_walk_recursive( $d, function ( $v ) use ( &$urls ) {
		if ( is_string( $v ) && false !== stripos( $v, 'vimeo' ) ) {
			if ( preg_match_all( '#https?://[^\s"\'<>\\\\]*vimeo[^\s"\'<>\\\\]*#i', $v, $m ) ) {
				foreach ( $m[0] as $u ) { $urls[] = $u; }
			}
		}
	} );
	foreach ( array_unique( $urls ) as $u ) {
		echo "  url                : {$u}\n";
	}

	return isset( $r['post_id'] ) ? $r['post_id'] : 0;
}

/* ---- variant A: raw iframe inside an HTML widget ---- */
$a = vpb_make_template( 'TPL raw iframe', array(
	array(
		'id' => 'sA', 'elType' => 'section', 'settings' => array(),
		'elements' => array( array(
			'id' => 'cA', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array( array(
				'id' => 'wA', 'elType' => 'widget', 'widgetType' => 'html',
				'settings' => array(
					'html' => '<div class="wrap"><iframe src="https://player.vimeo.com/video/76979871?h=abc123&title=0" width="640" height="360" frameborder="0" allowfullscreen></iframe></div>',
				),
			) ),
		) ),
	),
) );
vpb_try( 'A. raw iframe in an HTML widget', $a, '22439234' );

/* ---- variant B: section background video ---- */
$b = vpb_make_template( 'TPL background video', array(
	array(
		'id' => 'sB', 'elType' => 'section',
		'settings' => array(
			'background_background'   => 'video',
			'background_video_link'   => 'https://vimeo.com/76979871',
			'background_video_start'  => 0,
		),
		'elements' => array( array(
			'id' => 'cB', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array( array(
				'id' => 'hB', 'elType' => 'widget', 'widgetType' => 'heading',
				'settings' => array( 'title' => 'Hero' ),
			) ),
		) ),
	),
) );
vpb_try( 'B. section background video', $b, '22439234' );

/* ---- variant C: video widget AND a button linking to the same video ---- */
$c = vpb_make_template( 'TPL widget + link', array(
	array(
		'id' => 'sC', 'elType' => 'section', 'settings' => array(),
		'elements' => array( array(
			'id' => 'cC', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array(
				array(
					'id' => 'vC', 'elType' => 'widget', 'widgetType' => 'video',
					'settings' => array( 'video_type' => 'vimeo', 'vimeo_url' => 'https://vimeo.com/76979871' ),
				),
				array(
					'id' => 'bC', 'elType' => 'widget', 'widgetType' => 'button',
					'settings' => array(
						'text' => 'Open on Vimeo',
						'link' => array( 'url' => 'https://vimeo.com/76979871', 'is_external' => 'on' ),
					),
				),
			),
		) ),
	),
) );
vpb_try( 'C. video widget plus a link to the same video', $c, '22439234' );

/* ---- variant D: nested inner section, video buried deep ---- */
$d = vpb_make_template( 'TPL nested', array(
	array(
		'id' => 'sD', 'elType' => 'section', 'settings' => array(),
		'elements' => array( array(
			'id' => 'cD', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array( array(
				'id' => 'iD', 'elType' => 'section', 'isInner' => true, 'settings' => array(),
				'elements' => array( array(
					'id' => 'icD', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
					'elements' => array( array(
						'id' => 'vD', 'elType' => 'widget', 'widgetType' => 'video',
						'settings' => array( 'video_type' => 'vimeo', 'vimeo_url' => 'https://vimeo.com/76979871' ),
					) ),
				) ),
			) ),
		) ),
	),
) );
vpb_try( 'D. video nested inside an inner section', $d, '22439234' );

/* ---- variant E: no video at all (the global-header scenario) ---- */
$e = vpb_make_template( 'TPL no video', array(
	array(
		'id' => 'sE', 'elType' => 'section', 'settings' => array(),
		'elements' => array( array(
			'id' => 'cE', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array( array(
				'id' => 'hE', 'elType' => 'widget', 'widgetType' => 'heading',
				'settings' => array( 'title' => 'No video on this page' ),
			) ),
		) ),
	),
) );
vpb_try( 'E. master page with no video (should refuse)', $e, '22439234', true );

/* ---- variant F: a number elsewhere that must NOT be touched ---- */
$f = vpb_make_template( 'TPL collision', array(
	array(
		'id' => 'sF', 'elType' => 'section',
		'settings' => array( 'z_index' => 76979871, 'custom_css' => '.x{content:"76979871 is a phone number"}' ),
		'elements' => array( array(
			'id' => 'cF', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array(
				array(
					'id' => 'vF', 'elType' => 'widget', 'widgetType' => 'video',
					'settings' => array( 'video_type' => 'vimeo', 'vimeo_url' => 'https://vimeo.com/76979871' ),
				),
				array(
					'id' => 'tF', 'elType' => 'widget', 'widgetType' => 'text-editor',
					'settings' => array( 'editor' => '<p>Call us on 76979871 extension 4.</p>' ),
				),
			),
		) ),
	),
) );
$fid = vpb_try( 'F. same number appearing in unrelated text', $f, '22439234' );

if ( $fid ) {
	$fd = json_decode( get_post_meta( $fid, '_elementor_data', true ), true );
	echo "\n  --- collision detail on #{$fid} ---\n";
	echo "  z_index    : " . var_export( $fd[0]['settings']['z_index'], true ) . "   (should still be 76979871)\n";
	echo "  custom_css : " . $fd[0]['settings']['custom_css'] . "\n";
	echo "  body text  : " . trim( wp_strip_all_tags( $fd[0]['elements'][0]['elements'][1]['settings']['editor'] ) ) . "\n";
	echo "  vimeo_url  : " . $fd[0]['elements'][0]['elements'][0]['settings']['vimeo_url'] . "\n";
}

/* ---- variant G: two DIFFERENT videos - only the hero one may change ---- */
$g = vpb_make_template( 'TPL two videos', array(
	array(
		'id' => 'sG', 'elType' => 'section', 'settings' => array(),
		'elements' => array( array(
			'id' => 'cG', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array(
				array(
					'id' => 'v1G', 'elType' => 'widget', 'widgetType' => 'video',
					'settings' => array( 'video_type' => 'vimeo', 'vimeo_url' => 'https://vimeo.com/76979871' ),
				),
				array(
					'id' => 'v2G', 'elType' => 'widget', 'widgetType' => 'video',
					'settings' => array( 'video_type' => 'vimeo', 'vimeo_url' => 'https://vimeo.com/30873239' ),
				),
			),
		) ),
	),
) );
$gid = vpb_try( 'G. two different videos on one page', $g, '22439234' );

if ( $gid ) {
	$gd = json_decode( get_post_meta( $gid, '_elementor_data', true ), true );
	$k  = $gd[0]['elements'][0]['elements'];
	echo "\n  --- two-video detail on #{$gid} ---\n";
	echo "  hero video       : " . $k[0]['settings']['vimeo_url'] . "   (should be the new one)\n";
	echo "  testimonial video: " . $k[1]['settings']['vimeo_url'] . "   (should be UNCHANGED 30873239)\n";
}

/* ---- variant H: unlisted master video, public replacement ---- */
$h = vpb_make_template( 'TPL unlisted iframe', array(
	array(
		'id' => 'sH', 'elType' => 'section', 'settings' => array(),
		'elements' => array( array(
			'id' => 'cH', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array( array(
				'id' => 'wH', 'elType' => 'widget', 'widgetType' => 'html',
				'settings' => array(
					'html' => '<iframe src="https://player.vimeo.com/video/76979871?h=oldhash99&title=0&byline=0" width="640" height="360"></iframe>',
				),
			) ),
		) ),
	),
) );
vpb_try( 'H. iframe whose old privacy hash must not survive', $h, '22439234' );

/* ---- variant I: unlisted replacement into a plain-link master ---- */
$i = vpb_make_template( 'TPL plain link', array(
	array(
		'id' => 'sI', 'elType' => 'section', 'settings' => array(),
		'elements' => array( array(
			'id' => 'cI', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ),
			'elements' => array( array(
				'id' => 'wI', 'elType' => 'widget', 'widgetType' => 'html',
				'settings' => array( 'html' => '<a href="https://vimeo.com/76979871">Watch</a>' ),
			) ),
		) ),
	),
) );
vpb_try( 'I. new video is unlisted - hash must be added', $i, 'https://vimeo.com/347119375/8f2a1b9c3d' );
