<?php
/**
 * Title formatting with the optional company name, including the ugly cases:
 * token present but nothing typed, HTML pasted in, only-a-token formats.
 */

wp_set_current_user( 1 );

function vpb_clean() {
	foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => -1, 'post_status' => 'any' ) ) as $p ) {
		if ( get_post_meta( $p->ID, '_vpb_vimeo_id', true ) ) {
			wp_delete_post( $p->ID, true );
		}
	}
}

function vpb_case( $format, $company, $vid = '22439234' ) {
	vpb_clean();

	$s                 = vpb_settings();
	$s['template_id']  = 5;
	$s['title_format'] = $format;
	$s['verify_vimeo'] = 0;
	update_option( VPB_OPTION, $s );

	$r = VPB_Builder::build( $vid, false, $company );

	if ( is_wp_error( $r ) ) {
		printf( "  %-26s + %-18s -> ERROR %s\n", $format, '"' . $company . '"', $r->get_error_message() );
		return;
	}

	printf( "  %-26s + %-18s -> title %-42s slug %s\n",
		$format,
		'"' . $company . '"',
		'"' . get_the_title( $r['post_id'] ) . '"',
		get_post_field( 'post_name', $r['post_id'] )
	);
}

echo "title format + company name:\n";
vpb_case( '{id}',                 '' );
vpb_case( '{id}',                 'Key Financial Inc' );
vpb_case( '{company} - {id}',     'Key Financial Inc' );
vpb_case( '{company} - {id}',     '' );                       // token, nothing typed
vpb_case( '{id} - {company}',     '' );                       // trailing separator
vpb_case( '{company}',            '' );                       // nothing left at all
vpb_case( '{company}',            'Key Financial Inc' );
vpb_case( '{company} | {id}',     '  Key   Financial  ' );     // sloppy spacing
vpb_case( '{company} - {id}',     '<b>Key</b> Financial' );    // pasted HTML
vpb_case( '{company} - {id}',     'Smith & Sons, "The Best"' ); // punctuation

echo "\nslug is always the bare ID regardless of title - confirmed above.\n";

// Meta is recorded for later searching.
vpb_clean();
$s = vpb_settings(); $s['title_format'] = '{company} - {id}'; update_option( VPB_OPTION, $s );
$r = VPB_Builder::build( '22439234', false, 'Key Financial Inc' );
echo "\n_vpb_company meta: " . get_post_meta( $r['post_id'], '_vpb_company', true ) . "\n";
echo "returned company  : " . $r['company'] . "\n";

// Admin search must find it by company name.
$hits = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 's' => 'Key Financial', 'fields' => 'ids' ) );
echo "admin search 'Key Financial' finds: " . count( $hits ) . " page(s)\n";

// Restore every setting this file touched. Leaving verify_vimeo off leaked into
// a later suite and made it look like the Vimeo check had regressed - it hadn't.
vpb_clean();
$s = vpb_settings();
$s['title_format'] = '{id}';
$s['verify_vimeo'] = 1;
update_option( VPB_OPTION, $s );
echo "\nsettings restored: title_format={id}, verify_vimeo=1\n";
