<?php
/**
 * A build screen reachable without a WordPress login, on a secret URL.
 *
 * The secret key IS the credential here, so it is treated like one: compared in
 * constant time, never printed on any page but the admin settings screen, kept
 * out of search engines, rate limited, and rotatable the moment it leaks. It can
 * only build pages - it cannot edit, delete, or reach settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VPB_Public {

	const QUERY_VAR   = 'vpb';
	const RATE_LIMIT  = 30;      // builds per hour, per IP
	const LOG_OPTION  = 'vpb_public_log';
	const LOG_KEEP    = 100;

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
		add_action( 'wp_ajax_vpb_public_build', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_vpb_public_build', array( $this, 'ajax' ) );
	}

	/**
	 * The full URL to hand to whoever is doing the building.
	 */
	public static function url() {
		$s = vpb_settings();

		if ( empty( $s['public_key'] ) ) {
			return '';
		}

		return add_query_arg( self::QUERY_VAR, $s['public_key'], home_url( '/' ) );
	}

	public static function new_key() {
		return wp_generate_password( 40, false, false );
	}

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

	private static function passcode_ok( $given ) {
		$s = vpb_settings();

		if ( empty( $s['public_passcode'] ) ) {
			return true;   // not configured, so nothing to check
		}

		return hash_equals( (string) $s['public_passcode'], (string) $given );
	}

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

	public function maybe_render() {
		$given = isset( $_GET[ self::QUERY_VAR ] ) ? wp_unslash( $_GET[ self::QUERY_VAR ] ) : '';

		if ( '' === $given ) {
			return;
		}

		// A wrong key behaves exactly like a page that does not exist, so the
		// URL gives nothing away to anyone poking at it.
		if ( ! self::key_ok( $given ) ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true );
		header( 'Referrer-Policy: no-referrer' );

		$this->render();
		exit;
	}

	private function render() {
		$s        = vpb_settings();
		$ready    = $s['template_id'] && get_post( $s['template_id'] );
		$needs_pc = ! empty( $s['public_passcode'] );
		$show_co  = ( false !== strpos( $s['title_format'], '{company}' ) );
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

			<?php if ( $needs_pc ) : ?>
				<label for="pc" class="second">Passcode</label>
				<input type="password" id="pc" autocomplete="current-password">
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
		KEY   = <?php echo wp_json_encode( $s['public_key'] ); ?>,
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

				var html = '<p><strong>Done.</strong> ' + esc(d.title) + ' is live.</p>';
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

		if ( ! self::key_ok( $key ) ) {
			wp_send_json_error( array( 'message' => 'This link is no longer valid. Ask for a new one.' ), 403 );
		}

		check_ajax_referer( 'vpb_public_build', 'nonce' );

		$passcode = isset( $_POST['passcode'] ) ? wp_unslash( $_POST['passcode'] ) : '';

		if ( ! self::passcode_ok( $passcode ) ) {
			wp_send_json_error( array( 'message' => 'Wrong passcode.' ), 403 );
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
			update_post_meta( $result['post_id'], '_vpb_built_via', 'public link' );

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
.err{color:#d63638}
@media (max-width:480px){body{padding:16px 12px}.box{padding:18px}}
CSS;
	}
}
