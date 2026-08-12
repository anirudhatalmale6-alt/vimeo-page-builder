<?php
/**
 * The parts of the no-login page that are easier to assert from inside WP:
 * passcode, rate limit, key rotation, and the off switch.
 */

function vpb_set( $args ) {
	update_option( VPB_OPTION, array_merge( vpb_settings(), $args ) );
}

function vpb_post( $key, $passcode = '', $vimeo = '22439234' ) {
	$_POST = array(
		'action'   => 'vpb_public_build',
		'key'      => $key,
		'nonce'    => wp_create_nonce( 'vpb_public_build' ),
		'vimeo'    => $vimeo,
		'passcode' => $passcode,
		'force'    => '0',
	);
	$_REQUEST = $_POST;
}

$ref = new ReflectionClass( 'VPB_Public' );
$key_ok = $ref->getMethod( 'key_ok' );      $key_ok->setAccessible( true );
$pc_ok  = $ref->getMethod( 'passcode_ok' ); $pc_ok->setAccessible( true );
$rate   = $ref->getMethod( 'under_rate_limit' ); $rate->setAccessible( true );

/**
 * Tally kept in a static, not a global. wp-cli's eval-file runs this inside a
 * function scope, so `global $pass` binds to a different variable than the
 * top-level one and every count comes out as zero.
 */
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

echo "key checks:\n";
$k = VPB_Public::new_key();
vpb_set( array( 'public_enabled' => 1, 'public_key' => $k, 'public_passcode' => '' ) );
chk( 'correct key accepted',              $key_ok->invoke( null, $k ), true );
chk( 'wrong key rejected',                $key_ok->invoke( null, 'nope' ), false );
chk( 'empty key rejected',                $key_ok->invoke( null, '' ), false );
chk( 'key with trailing space rejected',  $key_ok->invoke( null, $k . ' ' ), false );
chk( 'prefix of the key rejected',        $key_ok->invoke( null, substr( $k, 0, 20 ) ), false );

echo "\nthe off switch:\n";
vpb_set( array( 'public_enabled' => 0 ) );
chk( 'correct key refused while disabled', $key_ok->invoke( null, $k ), false );
vpb_set( array( 'public_enabled' => 1 ) );

echo "\nkey rotation:\n";
$k2 = VPB_Public::new_key();
vpb_set( array( 'public_key' => $k2 ) );
chk( 'old key stops working after rotation', $key_ok->invoke( null, $k ), false );
chk( 'new key works',                        $key_ok->invoke( null, $k2 ), true );
chk( 'rotated key is a different value',     $k === $k2, false );

echo "\npasscode:\n";
vpb_set( array( 'public_passcode' => '' ) );
chk( 'no passcode set -> anything passes', $pc_ok->invoke( null, '' ), true );
vpb_set( array( 'public_passcode' => 'letmein' ) );
chk( 'correct passcode accepted',          $pc_ok->invoke( null, 'letmein' ), true );
chk( 'wrong passcode rejected',            $pc_ok->invoke( null, 'Letmein' ), false );
chk( 'blank passcode rejected when set',   $pc_ok->invoke( null, '' ), false );
vpb_set( array( 'public_passcode' => '' ) );

echo "\nrate limit (" . VPB_Public::RATE_LIMIT . "/hour):\n";
// Clear the counter for THIS caller's IP. Assuming 0.0.0.0 here made an earlier
// run look like an off-by-one, when the limiter was correct all along.
$ip_m = $ref->getMethod( 'client_ip' ); $ip_m->setAccessible( true );
$rate_key = 'vpb_rate_' . md5( $ip_m->invoke( null ) );
delete_transient( $rate_key );

$allowed = 0;
for ( $i = 0; $i < VPB_Public::RATE_LIMIT + 5; $i++ ) {
	if ( $rate->invoke( null ) ) { $allowed++; }
}
chk( 'allows exactly the limit', $allowed, VPB_Public::RATE_LIMIT );
chk( 'still blocked after the limit', $rate->invoke( null ), false );
delete_transient( $rate_key );
chk( 'clearing the counter lets it through again', $rate->invoke( null ), true );
delete_transient( $rate_key );

echo "\nwhat the public page can reach:\n";
$has_nopriv_build    = (bool) has_action( 'wp_ajax_nopriv_vpb_public_build' );
$has_nopriv_admin    = (bool) has_action( 'wp_ajax_nopriv_vpb_build' );
chk( 'public build endpoint exists for logged-out users', $has_nopriv_build, true );
chk( 'admin build endpoint NOT exposed to logged-out users', $has_nopriv_admin, false );

echo "\nlogging:\n";
$log = VPB_Public::get_log();
chk( 'log is an array', is_array( $log ), true );
if ( $log ) {
	$e = $log[0];
	printf( "  latest entry: %s  ip=%s  video=%s  post=%s\n",
		$e['time'], $e['ip'], $e['video'], $e['post'] );
	chk( 'entry records the IP', ! empty( $e['ip'] ), true );
}

chk( "__total__", null, null );
