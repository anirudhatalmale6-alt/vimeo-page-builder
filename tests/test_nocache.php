<?php
/**
 * v1.3.1 - the build address must opt itself out of page caching.
 *
 * Found on the client's live site: a page cache had frozen the build address and
 * was serving a saved copy of the passcode screen, so the 12-hour sign-in did
 * nothing and whichever visitor happened to fill the cache decided what everyone
 * else saw.
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

$s = vpb_settings();

echo "settings under test: path=" . $s['public_path'] . " enabled=" . $s['public_enabled'] . "\n\n";

$pub  = new VPB_Public();
$ref  = new ReflectionClass( 'VPB_Public' );
$door = $ref->getMethod( 'requested_door' ); $door->setAccessible( true );

/**
 * The constant can only be defined once per process, so check the decision
 * rather than the constant: run the same guard and report whether it would fire.
 */
function would_nocache( $pub, $door ) {
	return '' !== $door->invoke( null );
}

echo "which requests opt out of caching:\n";

$_GET = array(); $_SERVER['REQUEST_URI'] = '/video-builder/';
chk( 'the short address opts out',            would_nocache( $pub, $door ), true );

$_SERVER['REQUEST_URI'] = '/Video-Builder';
chk( 'capitalised short address opts out',    would_nocache( $pub, $door ), true );

$_GET = array( 'vpb' => $s['public_key'] ); $_SERVER['REQUEST_URI'] = '/';
chk( 'the long key address opts out',         would_nocache( $pub, $door ), true );

$_GET = array();
$_SERVER['REQUEST_URI'] = '/';
chk( 'the home page is NOT affected',         would_nocache( $pub, $door ), false );

$_SERVER['REQUEST_URI'] = '/1211689555/';
chk( 'a built video page is NOT affected',    would_nocache( $pub, $door ), false );

$_SERVER['REQUEST_URI'] = '/basic-clone/';
chk( 'the master page is NOT affected',       would_nocache( $pub, $door ), false );

$_SERVER['REQUEST_URI'] = '/some/deep/path/';
chk( 'unrelated pages are NOT affected',      would_nocache( $pub, $door ), false );

echo "\nthe constant really does get defined:\n";
$_SERVER['REQUEST_URI'] = '/video-builder/';
$pub->never_cache_this();
chk( 'DONOTCACHEPAGE defined',   defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, true );
chk( 'DONOTCACHEOBJECT defined', defined( 'DONOTCACHEOBJECT' ) && DONOTCACHEOBJECT, true );
chk( 'DONOTCACHEDB defined',     defined( 'DONOTCACHEDB' ) && DONOTCACHEDB, true );

echo "\nWP Rocket exclusions:\n";
$uris = apply_filters( 'rocket_cache_reject_uri', array() );
chk( 'the build address is excluded by name',
	(bool) preg_grep( '#/video-builder/#', $uris ), true );
chk( 'nothing else was added',  count( $uris ), 2 );

echo "\nrunning it twice must not fatal on redefining constants:\n";
$pub->never_cache_this();
chk( 'second call is harmless', true, true );

echo "\nno-store header is sent with the page:\n";
// maybe_render() exits, so assert on the header list from a separate request in
// test_short.py instead; here just prove the string is present in the source.
$src = file_get_contents( VPB_DIR . 'includes/class-vpb-public.php' );
chk( 'Cache-Control: no-store present', false !== strpos( $src, 'no-store' ), true );

chk( '__total__', null, null );
