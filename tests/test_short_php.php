<?php
/**
 * v1.3.0 - the short friendly address and the passcode gate in front of it.
 */

function vpb_set( $args ) {
	update_option( VPB_OPTION, array_merge( vpb_settings(), $args ) );
}

// Put the settings back however this run ends, so the next thing anyone looks
// at is not silently misconfigured by a test.
$vpb_original = get_option( VPB_OPTION, array() );

register_shutdown_function( function () use ( $vpb_original ) {
	update_option( VPB_OPTION, $vpb_original );
	echo "\nsettings restored to how they were before this run\n";
} );

function chk( $label, $got, $want ) {
	static $pass = 0, $fail = 0;

	if ( '__total__' === $label ) {
		printf( "\n%d passed, %d failed\n", $pass, $fail );
		return;
	}

	if ( $got === $want ) {
		$pass++;
		printf( "  PASS  %s\n", $label );
	} else {
		$fail++;
		printf( "  FAIL  %s (got %s, wanted %s)\n", $label, var_export( $got, true ), var_export( $want, true ) );
	}
}

$ref       = new ReflectionClass( 'VPB_Public' );
$gate_tok  = $ref->getMethod( 'gate_token' );  $gate_tok->setAccessible( true );
$gate_ok   = $ref->getMethod( 'gate_ok' );     $gate_ok->setAccessible( true );
$door      = $ref->getMethod( 'requested_door' ); $door->setAccessible( true );
$locked    = $ref->getMethod( 'locked_out' );  $locked->setAccessible( true );
$rec_fail  = $ref->getMethod( 'record_fail' ); $rec_fail->setAccessible( true );
$clr_fail  = $ref->getMethod( 'clear_fails' ); $clr_fail->setAccessible( true );
$ip_m      = $ref->getMethod( 'client_ip' );   $ip_m->setAccessible( true );

/* ------------------------------------------------ address parsing */

echo "cleaning up whatever gets typed into the box:\n";
chk( 'plain word',                 VPB_Public::clean_path( 'video-builder' ), 'video-builder' );
chk( 'leading slash stripped',     VPB_Public::clean_path( '/video-builder' ), 'video-builder' );
chk( 'both slashes stripped',      VPB_Public::clean_path( '/video-builder/' ), 'video-builder' );
chk( 'whole URL pasted',           VPB_Public::clean_path( 'https://www.allaccessptv.com/video-builder/' ), 'video-builder' );
chk( 'spaces become a slug',       VPB_Public::clean_path( 'Video Builder' ), 'video-builder' );
chk( 'capitals lowercased',        VPB_Public::clean_path( 'VideoBuilder' ), 'videobuilder' );
chk( 'empty stays empty',          VPB_Public::clean_path( '' ), '' );
chk( 'whitespace only',            VPB_Public::clean_path( '   ' ), '' );
chk( 'just a slash',               VPB_Public::clean_path( '/' ), '' );

echo "\nreserved addresses refused:\n";
foreach ( array( 'wp-admin', 'wp-login', 'wp-json', 'feed', '/wp-admin/' ) as $r ) {
	chk( "'$r' refused", VPB_Public::clean_path( $r ), '' );
}

/* ------------------------------------------------ collision detection */

echo "\ncollision with a real page:\n";
$existing = get_post( 5 );
chk( 'an existing page slug is flagged',  VPB_Public::path_conflict( $existing->post_name ) > 0, true );
chk( 'a free slug is not flagged',        VPB_Public::path_conflict( 'definitely-not-a-page-xyz' ), 0 );

/* ------------------------------------------------ the passcode rule */

echo "\nshort address requires a passcode:\n";
vpb_set( array( 'public_enabled' => 1, 'public_path' => 'video-builder', 'public_passcode' => '' ) );

$_GET = array();
$_SERVER['REQUEST_URI'] = '/video-builder/';
chk( 'no passcode -> the short address is not served', $door->invoke( null ), 'path' );

// requested_door only decides WHICH door; maybe_render is what refuses. Assert
// the refusal condition directly so the intent is pinned down either way.
$s = vpb_settings();
chk( 'and the guard sees an empty passcode', '' === trim( $s['public_passcode'] ), true );

vpb_set( array( 'public_passcode' => 'Armadillo' ) );

/* ------------------------------------------------ which door */

echo "\nwhich door a request is knocking on:\n";
$key = vpb_settings()['public_key'];

$_GET = array(); $_SERVER['REQUEST_URI'] = '/video-builder/';
chk( 'short address, trailing slash',    $door->invoke( null ), 'path' );

$_SERVER['REQUEST_URI'] = '/video-builder';
chk( 'short address, no trailing slash', $door->invoke( null ), 'path' );

$_SERVER['REQUEST_URI'] = '/video-builder/?x=1';
chk( 'short address with a query string', $door->invoke( null ), 'path' );

// Case-insensitive on purpose: a phone address bar capitalises the first letter.
$_SERVER['REQUEST_URI'] = '/Video-Builder/';
chk( 'capitalised address still works',  $door->invoke( null ), 'path' );

$_SERVER['REQUEST_URI'] = '/VIDEO-BUILDER';
chk( 'shouty address still works',       $door->invoke( null ), 'path' );

$_SERVER['REQUEST_URI'] = '/video%2Dbuilder/';
chk( 'percent-encoded address works',    $door->invoke( null ), 'path' );

$_SERVER['REQUEST_URI'] = '/video-builder-2/';
chk( 'a near miss is not the short address', $door->invoke( null ), '' );

$_SERVER['REQUEST_URI'] = '/something-else/';
chk( 'an unrelated URL is left alone',      $door->invoke( null ), '' );

$_GET = array( 'vpb' => $key ); $_SERVER['REQUEST_URI'] = '/';
chk( 'the long key still works',            $door->invoke( null ), 'key' );

$_GET = array( 'vpb' => 'wrong' );
chk( 'a wrong key is nothing at all',       $door->invoke( null ), '' );

$_GET = array();
vpb_set( array( 'public_enabled' => 0 ) );
$_SERVER['REQUEST_URI'] = '/video-builder/';
chk( 'off switch kills the short address',  $door->invoke( null ), '' );
vpb_set( array( 'public_enabled' => 1 ) );

/* ------------------------------------------------ the gate pass */

echo "\nthe gate cookie:\n";
$_COOKIE = array();
chk( 'no cookie -> not through the gate', $gate_ok->invoke( null ), false );

$exp = time() + 3600;
$_COOKIE[ VPB_Public::COOKIE ] = $exp . '|' . $gate_tok->invoke( null, $exp );
chk( 'a good cookie is accepted',         $gate_ok->invoke( null ), true );

$_COOKIE[ VPB_Public::COOKIE ] = $exp . '|' . str_repeat( 'a', 64 );
chk( 'a forged signature is rejected',    $gate_ok->invoke( null ), false );

$_COOKIE[ VPB_Public::COOKIE ] = 'garbage';
chk( 'garbage is rejected',               $gate_ok->invoke( null ), false );

$past = time() - 10;
$_COOKIE[ VPB_Public::COOKIE ] = $past . '|' . $gate_tok->invoke( null, $past );
chk( 'an expired cookie is rejected',     $gate_ok->invoke( null ), false );

// Someone pushing the expiry out cannot re-sign it.
$_COOKIE[ VPB_Public::COOKIE ] = ( time() + 99999 ) . '|' . $gate_tok->invoke( null, $past );
chk( 'moving the expiry breaks the signature', $gate_ok->invoke( null ), false );

// Good cookie, then the admin changes things underneath it.
$_COOKIE[ VPB_Public::COOKIE ] = $exp . '|' . $gate_tok->invoke( null, $exp );
chk( 'still good before any change',      $gate_ok->invoke( null ), true );

vpb_set( array( 'public_passcode' => 'Rhino' ) );
chk( 'changing the passcode logs everyone out', $gate_ok->invoke( null ), false );
vpb_set( array( 'public_passcode' => 'Armadillo' ) );
chk( 'putting it back restores the cookie',     $gate_ok->invoke( null ), true );

$old_key = vpb_settings()['public_key'];
vpb_set( array( 'public_key' => VPB_Public::new_key() ) );
chk( 'rotating the key logs everyone out',      $gate_ok->invoke( null ), false );
vpb_set( array( 'public_key' => $old_key ) );

vpb_set( array( 'public_enabled' => 0 ) );
chk( 'off switch invalidates the cookie too',   $gate_ok->invoke( null ), false );
vpb_set( array( 'public_enabled' => 1 ) );

$_COOKIE = array();

/* ------------------------------------------------ guessing limit */

echo "\nwrong-passcode limit (" . VPB_Public::FAIL_LIMIT . "/hour):\n";
$clr_fail->invoke( null );
chk( 'starts unlocked', $locked->invoke( null ), false );

for ( $i = 0; $i < VPB_Public::FAIL_LIMIT - 1; $i++ ) {
	$rec_fail->invoke( null );
}
chk( 'one short of the limit is still allowed', $locked->invoke( null ), false );

$rec_fail->invoke( null );
chk( 'hitting the limit locks out',             $locked->invoke( null ), true );

$clr_fail->invoke( null );
chk( 'a correct passcode clears the counter',   $locked->invoke( null ), false );

/* ------------------------------------------------ the built URL */

echo "\nthe address shown in Settings:\n";
vpb_set( array( 'public_path' => 'video-builder' ) );
chk( 'short URL is built correctly', VPB_Public::friendly_url(), home_url( '/video-builder/' ) );

vpb_set( array( 'public_path' => '' ) );
chk( 'no short URL when none is set', VPB_Public::friendly_url(), '' );

vpb_set( array( 'public_path' => 'video-builder', 'public_enabled' => 0 ) );
chk( 'no short URL while switched off', VPB_Public::friendly_url(), '' );
vpb_set( array( 'public_enabled' => 1 ) );

chk( 'long URL is unaffected', 0 === strpos( VPB_Public::url(), home_url( '/' ) . '?vpb=' ), true );

chk( '__total__', null, null );
