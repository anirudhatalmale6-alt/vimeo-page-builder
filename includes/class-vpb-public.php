<?php
/**
 * A build screen reachable without a WordPress login.
 *
 * There are two ways in, and they are not equally safe, so they are not treated
 * the same:
 *
 *   1. The long secret key      site.com/?vpb=<40 chars>
 *      The key itself is the credential - unguessable, so the builder is shown
 *      straight away.
 *
 *   2. A short friendly address  site.com/video-builder
 *      Chosen because the long one is miserable to type on a phone. But a short
 *      address is guessable, so the key can no longer be the protection: the
 *      passcode is, and it is asked for BEFORE anything else is rendered. Someone
 *      who stumbles onto the URL sees a passcode box and no hint that a tool
 *      exists behind it.
 *
 * Either way this can only build pages. It cannot edit, delete, or reach settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VPB_Public {

	const QUERY_VAR   = 'vpb';
	const RATE_LIMIT  = 30;      // builds per hour, per IP
	const FAIL_LIMIT  = 10;      // wrong passcodes per hour, per IP
	const LOG_OPTION  = 'vpb_public_log';
	const LOG_KEEP    = 100;
	const COOKIE      = 'vpb_gate';
	const GATE_HOURS  = 12;

	/**
	 * Slugs that must never be swallowed by the friendly address.
	 */
	const RESERVED = array( 'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'sitemap', 'robots', 'xmlrpc' );

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
		add_action( 'wp_ajax_vpb_public_build', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_vpb_public_build', array( $this, 'ajax' ) );
	}

	/* ------------------------------------------------------------- addresses */

	/**
	 * The long key URL. Always valid while the feature is on.
	 */
	public static function url() {
		$s = vpb_settings();

		if ( empty( $s['public_key'] ) ) {
			return '';
		}

		return add_query_arg( self::QUERY_VAR, $s['public_key'], home_url( '/' ) );
	}

	/**
	 * The short URL, if one has been set. Empty when it has not.
	 */
	public static function friendly_url() {
		$s    = vpb_settings();
		$path = self::clean_path( isset( $s['public_path'] ) ? $s['public_path'] : '' );

		if ( '' === $path || empty( $s['public_enabled'] ) ) {
			return '';
		}

		return home_url( '/' . $path . '/' );
	}

	/**
	 * Normalise a user-typed address into a bare slug, or '' if unusable.
	 */
	public static function clean_path( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		// Tolerate someone pasting a whole URL, or leading/trailing slashes.
		if ( false !== strpos( $raw, '://' ) ) {
			$raw = (string) wp_parse_url( $raw, PHP_URL_PATH );
		}

		$raw  = trim( $raw, '/' );
		$slug = sanitize_title( $raw );

		if ( '' === $slug || in_array( $slug, self::RESERVED, true ) ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Is something already living at that address?
	 *
	 * Used to warn in Settings rather than to block - the check catches the case
	 * that actually happened here, where the obvious choice of address was the
	 * master page itself.
	 */
	public static function path_conflict( $slug ) {
		if ( '' === $slug ) {
			return 0;
		}

		$existing = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );

		return $existing ? (int) $existing->ID : 0;
	}

	public static function new_key() {
		return wp_generate_password( 40, false, false );
	}

	/* ----------------------------------------------------------- credentials */

	/**
	 * Does this request carry the right key?
	 *
	 * hash_equals keeps the comparison constant-time, so the key cannot be
	 * recovered a character at a time by measuring how long the check takes.
	 */
	private static function key_ok( $given ) {
		$s = vpb_settings();

		if ( empty( $s['public_enabled'] ) || empty( $s['public_key'] ) ) {
			return false;
		}

		return hash_equals( (string) $s['public_key'], (string) $given );
	}

	/**
	 * Check the shared passcode, forgivingly.
	 *
	 * Deliberately case-insensitive and whitespace-trimmed. This is a door code
	 * a colleague types on a phone, not a password: the field is type="password"
	 * so phone keyboards will not auto-capitalise it, and a passcode like
	 * "Armadillo" would then fail for a reason nobody could see.
	 *
	 * The forgiveness costs nothing real. What stops guessing is the attempt
	 * limit below, not the shift key.
	 */
	private static function passcode_ok( $given ) {
		$s = vpb_settings();

		$want = trim( (string) $s['public_passcode'] );

		if ( '' === $want ) {
			return true;   // not configured, so nothing to check
		}

		$given = trim( (string) $given );

		// Compare a fixed-length digest of each so the comparison stays
		// constant-time even though the inputs differ in length.
		return hash_equals(
			hash( 'sha256', strtolower( $want ) ),
			hash( 'sha256', strtolower( $given ) )
		);
	}

	/* ---------------------------------------------------------- the gate pass */

	/**
	 * A short-lived token proving this browser already typed the passcode.
	 *
	 * Bound to the passcode and to the secret key, so changing either one logs
	 * everybody out immediately - which is what you want the moment you suspect
	 * the address has got out.
	 */
	private static function gate_token( $expires ) {
		$s = vpb_settings();

		return hash_hmac(
			'sha256',
			'vpb-gate|' . $expires . '|' . $s['public_key'] . '|' . strtolower( trim( (string) $s['public_passcode'] ) ),
			wp_salt( 'auth' )
		);
	}

	private static function gate_set() {
		$expires = time() + ( self::GATE_HOURS * HOUR_IN_SECONDS );

		setcookie(
			self::COOKIE,
			$expires . '|' . self::gate_token( $expires ),
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function gate_ok() {
		$s = vpb_settings();

		if ( empty( $s['public_enabled'] ) ) {
			return false;
		}

		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';

		if ( '' === $raw || false === strpos( $raw, '|' ) ) {
			return false;
		}

		list( $expires, $token ) = explode( '|', $raw, 2 );

		if ( ! ctype_digit( (string) $expires ) || (int) $expires < time() ) {
			return false;
		}

		return hash_equals( self::gate_token( (int) $expires ), $token );
	}

	/* ------------------------------------------------------------- throttling */

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Crude per-IP throttle. Enough to stop a leaked URL being used to bury the
	 * site in pages before anyone notices.
	 *
	 * @return bool True when the request is allowed.
	 */
	private static function under_rate_limit() {
		$key   = 'vpb_rate_' . md5( self::client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Wrong-passcode throttle.
	 *
	 * This is what actually makes a short guessable address safe. A single
	 * dictionary word would fall over in seconds to a script; ten tries an hour
	 * makes that pointless, while never getting in the way of someone who simply
	 * fat-fingered it.
	 */
	private static function fail_key() {
		return 'vpb_pcfail_' . md5( self::client_ip() );
	}

	private static function locked_out() {
		return (int) get_transient( self::fail_key() ) >= self::FAIL_LIMIT;
	}

	private static function record_fail() {
		$key = self::fail_key();

		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	private static function clear_fails() {
		delete_transient( self::fail_key() );
	}

	/* ------------------------------------------------------------------- log */

	/**
	 * Keep a short history of what was built through the public page, since
	 * there is no WordPress user attached to these builds.
	 */
	private static function log( $entry ) {
		$log = get_option( self::LOG_OPTION, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_KEEP ), false );
	}

	public static function get_log() {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/* ------------------------------------------------------------- rendering */

	/**
	 * Which door is this request knocking on?
	 *
	 * @return string 'key', 'path', or '' for neither.
	 */
	private static function requested_door() {
		$s = vpb_settings();

		$given = isset( $_GET[ self::QUERY_VAR ] ) ? wp_unslash( $_GET[ self::QUERY_VAR ] ) : '';

		if ( '' !== $given ) {
			// A wrong key behaves exactly like a page that does not exist, so
			// the URL gives nothing away to anyone poking at it.
			return self::key_ok( $given ) ? 'key' : '';
		}

		if ( empty( $s['public_enabled'] ) ) {
			return '';
		}

		$want = self::clean_path( isset( $s['public_path'] ) ? $s['public_path'] : '' );

		if ( '' === $want ) {
			return '';
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );

		// home_url() may itself sit in a subdirectory install.
		$base = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) + 1 );
		}

		// Matched case-insensitively on purpose. A phone address bar will happily
		// capitalise the first letter of a typed URL, and "Video-Builder" failing
		// silently is the same avoidable support call as a capitalised passcode.
		// The address is not the secret here - the passcode is.
		return ( strtolower( rawurldecode( $path ) ) === $want ) ? 'path' : '';
	}

	public function maybe_render() {
		$door = self::requested_door();

		if ( '' === $door ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true );
		header( 'Referrer-Policy: no-referrer' );

		// The short address is not a real WordPress page, so by the time we get
		// here the main query has already decided this is a 404. Say otherwise -
		// a page that renders fine while returning 404 trips up phone browsers,
		// caches and anything else reading the status rather than the body.
		status_header( 200 );

		global $wp_query;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
		}

		$s       = vpb_settings();
		$has_pc  = '' !== trim( (string) $s['public_passcode'] );

		// The long key is unguessable, so it stands on its own and the passcode
		// is collected on the build form itself. The short address is not, so it
		// gets gated first.
		if ( 'key' === $door ) {
			$this->render_builder( 'key', $has_pc );
			exit;
		}

		// --- short address from here down ---

		if ( ! $has_pc ) {
			// Refused rather than served. A guessable address with no passcode is
			// an open publish button on the public internet; Settings will not let
			// this be saved, so reaching here means something was edited directly.
			status_header( 404 );
			$this->render_nothing();
			exit;
		}

		if ( self::gate_ok() ) {
			$this->render_builder( 'gate', false );
			exit;
		}

		$error = '';

		if ( isset( $_POST['vpb_passcode'] ) ) {
			if ( self::locked_out() ) {
				$error = 'Too many tries. Wait an hour and try again.';
			} elseif ( self::passcode_ok( wp_unslash( $_POST['vpb_passcode'] ) ) ) {
				self::clear_fails();
				self::gate_set();
				$this->render_builder( 'gate', false );
				exit;
			} else {
				self::record_fail();
				$error = 'Not right. Try again.';
			}
		}

		$this->render_gate( $error );
		exit;
	}

	/**
	 * Deliberately blank. Says nothing about what is or is not here.
	 */
	private function render_nothing() {
		echo '<!DOCTYPE html><html><head><meta name="robots" content="noindex"><title>Not found</title></head><body></body></html>';
	}

	/**
	 * Step one on the short address: a passcode box and nothing else.
	 *
	 * No site name, no logo, no mention of Vimeo or of building anything. If this
	 * URL is ever found by someone it was not meant for, it should read as a dead
	 * end rather than an invitation.
	 */
	private function render_gate( $error = '' ) {
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<title>Passcode</title>
<style><?php echo $this->css(); // phpcs:ignore ?></style>
</head>
<body>
<div class="wrap wrap-gate">
	<div class="box">
		<form method="post" action="">
			<label for="pc">Passcode</label>
			<input type="password" name="vpb_passcode" id="pc" autocomplete="current-password" autofocus
				autocapitalize="none" spellcheck="false">
			<?php if ( $error ) : ?>
				<p class="err"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<button type="submit">CONTINUE</button>
		</form>
	</div>
</div>
</body>
</html>
		<?php
	}

	/**
	 * @param string $mode    'key' (passcode still to be collected) or 'gate' (already proven).
	 * @param bool   $need_pc Whether to show the passcode field on the build form.
	 */
	private function render_builder( $mode, $need_pc ) {
		$s       = vpb_settings();
		$ready   = $s['template_id'] && get_post( $s['template_id'] );
		$show_co = ( false !== strpos( $s['title_format'], '{company}' ) );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<title>Build A Video Page</title>
<style><?php echo $this->css(); // phpcs:ignore ?></style>
</head>
<body>
<div class="wrap">
	<h1>Build A Video Page</h1>

	<?php if ( ! $ready ) : ?>
		<div class="box">
			<p class="err">This tool is not set up yet. Ask for the master page to be chosen in the admin first.</p>
		</div>
	<?php else : ?>
		<div class="box">
			<label for="v">Vimeo video ID</label>
			<p class="hint">Paste the ID or the whole Vimeo link.</p>
			<input type="text" id="v" placeholder="1211689555" autocomplete="off" spellcheck="false" inputmode="text">

			<?php if ( $show_co ) : ?>
				<label for="co" class="second">Company name</label>
				<input type="text" id="co" placeholder="Key Financial Inc" autocomplete="off">
			<?php endif; ?>

			<?php if ( $need_pc ) : ?>
				<label for="pc" class="second">Passcode</label>
				<input type="password" id="pc" autocomplete="current-password" autocapitalize="none" spellcheck="false">
			<?php endif; ?>

			<button type="button" id="go">BUILD</button>

			<div id="out" class="out" hidden></div>
		</div>
	<?php endif; ?>
</div>

<script>
(function(){
	var v = document.getElementById('v'),
		co = document.getElementById('co'),
		pc = document.getElementById('pc'),
		go = document.getElementById('go'),
		out = document.getElementById('out');

	if (!go) { return; }

	var AJAX  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		<?php // On the gated short address the cookie is the credential, so the
			  // secret key is deliberately NOT written into the page. ?>
		KEY   = <?php echo wp_json_encode( 'key' === $mode ? $s['public_key'] : '' ); ?>,
		NONCE = <?php echo wp_json_encode( wp_create_nonce( 'vpb_public_build' ) ); ?>;

	function esc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }

	function show(cls, html){
		out.className = 'out ' + cls;
		out.innerHTML = html;
		out.hidden = false;
	}

	function build(force){
		var val = (v.value || '').trim();

		if (!val){ show('err', '<p>Paste a Vimeo ID or link first.</p>'); v.focus(); return; }

		go.disabled = true;
		go.textContent = 'BUILDING…';
		show('', '<p>Working…</p>');

		var body = new URLSearchParams();
		body.append('action', 'vpb_public_build');
		body.append('key', KEY);
		body.append('nonce', NONCE);
		body.append('vimeo', val);
		body.append('company', co ? (co.value || '') : '');
		body.append('passcode', pc ? (pc.value || '') : '');
		body.append('force', force ? '1' : '0');

		fetch(AJAX, { method:'POST', body: body, credentials:'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(res){
				if (!res || !res.success){
					show('err', '<p>' + esc(res && res.data ? res.data.message : 'Something went wrong.') + '</p>');
					return;
				}
				var d = res.data;

				if (d.status === 'exists'){
					show('warn',
						'<p><strong>Already built.</strong> A page for ' + esc(d.vimeo_id) + ' exists.</p>' +
						'<p><a href="' + esc(d.permalink) + '" target="_blank" rel="noopener">View page</a> ' +
						'<a href="#" id="force">Build another anyway</a></p>');
					var f = document.getElementById('force');
					if (f) { f.addEventListener('click', function(e){ e.preventDefault(); build(true); }); }
					return;
				}

				var html = d.is_live
					? '<p><strong>Done.</strong> ' + esc(d.title) + ' is live.</p>'
					: '<p><strong>Done.</strong> ' + esc(d.title) + ' has been saved as a draft &mdash; it is not public yet.</p>';
				if (d.video_name) { html += '<p>Video: “' + esc(d.video_name) + '”</p>'; }
				if (d.warning) { html += '<p><em>' + esc(d.warning) + '</em></p>'; }
				html += '<p><a href="' + esc(d.permalink) + '" target="_blank" rel="noopener">View page</a></p>';

				show(d.warning ? 'warn' : 'ok', html);
				v.value = '';
				if (co) { co.value = ''; }
				v.focus();
			})
			.catch(function(){
				show('err', '<p>The request failed. Check your connection and try again.</p>');
			})
			.then(function(){
				go.disabled = false;
				go.textContent = 'BUILD';
			});
	}

	go.addEventListener('click', function(){ build(false); });

	[v, co, pc].forEach(function(el){
		if (!el) { return; }
		el.addEventListener('keydown', function(e){
			if (e.key === 'Enter'){ e.preventDefault(); build(false); }
		});
	});
})();
</script>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------ ajax */

	public function ajax() {
		$key = isset( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : '';

		// Two acceptable credentials: the long key, or a gate cookie earned by
		// typing the passcode on the short address.
		$via_key  = self::key_ok( $key );
		$via_gate = self::gate_ok();

		if ( ! $via_key && ! $via_gate ) {
			wp_send_json_error( array( 'message' => 'This link is no longer valid. Ask for a new one.' ), 403 );
		}

		check_ajax_referer( 'vpb_public_build', 'nonce' );

		// A gate cookie already proves the passcode, so do not ask twice.
		if ( ! $via_gate ) {
			if ( self::locked_out() ) {
				wp_send_json_error( array( 'message' => 'Too many wrong passcodes. Wait an hour and try again.' ), 429 );
			}

			if ( ! self::passcode_ok( isset( $_POST['passcode'] ) ? wp_unslash( $_POST['passcode'] ) : '' ) ) {
				self::record_fail();
				wp_send_json_error( array( 'message' => 'Wrong passcode.' ), 403 );
			}

			self::clear_fails();
		}

		if ( ! self::under_rate_limit() ) {
			wp_send_json_error( array(
				'message' => 'That is a lot of pages in one hour. Stopping here as a safety measure - try again later, or ask for it to be lifted.',
			), 429 );
		}

		$raw     = isset( $_POST['vimeo'] ) ? wp_unslash( $_POST['vimeo'] ) : '';
		$company = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
		$force   = ! empty( $_POST['force'] );

		$result = VPB_Builder::build( $raw, $force, $company );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( 'built' === $result['status'] ) {
			update_post_meta( $result['post_id'], '_vpb_built_via', $via_gate ? 'short link' : 'public link' );

			self::log( array(
				'time'  => current_time( 'mysql' ),
				'ip'    => self::client_ip(),
				'video' => $result['vimeo_id'],
				'post'  => $result['post_id'],
				'title' => $result['title'],
			) );
		}

		wp_send_json_success( $result );
	}

	/* --------------------------------------------------------------- styling */

	private function css() {
		return <<<CSS
*{box-sizing:border-box}
body{margin:0;padding:24px 16px;background:#f0f0f1;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327}
.wrap{max-width:640px;margin:0 auto}
.wrap-gate{max-width:400px;margin-top:12vh}
h1{font-size:24px;margin:8px 0 20px}
.box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px}
label{display:block;font-weight:600;margin-bottom:2px}
label.second{margin-top:18px}
.hint{margin:0 0 12px;color:#646970;font-size:14px}
input{width:100%;font-size:18px;padding:12px;border:1px solid #8c8f94;border-radius:4px;line-height:1.3}
input:focus{border-color:#2271b1;outline:2px solid transparent;box-shadow:0 0 0 1px #2271b1}
button{margin-top:20px;width:100%;background:#2271b1;color:#fff;border:0;border-radius:4px;padding:15px;font-size:17px;font-weight:600;letter-spacing:.5px;cursor:pointer}
button:hover{background:#135e96}
button:disabled{opacity:.6;cursor:default}
.out{margin-top:20px;padding:14px 16px;border-radius:4px;border-left:4px solid #72aee6;background:#f6f7f7}
.out.ok{border-left-color:#00a32a;background:#f0f6f1}
.out.warn{border-left-color:#dba617;background:#fcf9e8}
.out.err{border-left-color:#d63638;background:#fcf0f1}
.out p{margin:.35em 0}
.out a{font-weight:600;margin-right:14px}
.err{color:#d63638;margin:10px 0 0}
@media (max-width:480px){body{padding:16px 12px}.box{padding:18px}.wrap-gate{margin-top:6vh}}
CSS;
	}
}
