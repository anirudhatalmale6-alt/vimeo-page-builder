<?php
/**
 * Parser test: every shape a human might paste into that one field.
 */

$cases = array(
	// input                                                          => expected id / hash
	'76979871'                                                        => array( '76979871', '' ),
	'  76979871  '                                                    => array( '76979871', '' ),
	'https://vimeo.com/76979871'                                      => array( '76979871', '' ),
	'http://vimeo.com/76979871'                                       => array( '76979871', '' ),
	'vimeo.com/76979871'                                              => array( '76979871', '' ),
	'https://vimeo.com/76979871/8272103f6e'                           => array( '76979871', '8272103f6e' ),
	'https://vimeo.com/76979871?share=copy'                           => array( '76979871', '' ),
	'https://vimeo.com/76979871#t=30s'                                => array( '76979871', '' ),
	'https://player.vimeo.com/video/76979871'                         => array( '76979871', '' ),
	'https://player.vimeo.com/video/76979871?h=8272103f6e&badge=0'    => array( '76979871', '8272103f6e' ),
	'https://vimeo.com/channels/staffpicks/76979871'                  => array( '76979871', '' ),
	'https://vimeo.com/groups/motion/videos/76979871'                 => array( '76979871', '' ),
	'https://vimeo.com/user12345678/76979871'                         => array( '76979871', '' ),
	'<iframe src="https://player.vimeo.com/video/76979871?h=8272103f6e" width="640" height="360" frameborder="0" allowfullscreen></iframe>'
	                                                                  => array( '76979871', '8272103f6e' ),
	'<iframe src="https://player.vimeo.com/video/76979871&amp;h=abc123" ></iframe>'
	                                                                  => array( '76979871', 'abc123' ),
);

$fail_cases = array( '', '   ', 'not a video', 'https://youtube.com/watch?v=dQw4w9WgXcQ' );

$pass = 0;
$fail = 0;

foreach ( $cases as $in => $want ) {
	$got = VPB_Vimeo::parse( $in );

	if ( is_wp_error( $got ) ) {
		printf( "FAIL  %-70s -> ERROR %s\n", substr( $in, 0, 70 ), $got->get_error_message() );
		$fail++;
		continue;
	}

	if ( $got['id'] === $want[0] && $got['hash'] === $want[1] ) {
		$pass++;
	} else {
		printf( "FAIL  %-70s -> id=%s hash=%s (wanted id=%s hash=%s)\n",
			substr( $in, 0, 70 ), $got['id'], $got['hash'], $want[0], $want[1] );
		$fail++;
	}
}

foreach ( $fail_cases as $in ) {
	$got = VPB_Vimeo::parse( $in );
	if ( is_wp_error( $got ) ) {
		$pass++;
	} else {
		printf( "FAIL  %-70s -> should have been rejected, got id=%s\n", '"' . $in . '"', $got['id'] );
		$fail++;
	}
}

echo "\nparser: {$pass} passed, {$fail} failed\n";
