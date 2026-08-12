<?php
/**
 * Admin screens: the one-field build box, and the settings behind it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VPB_Admin {

	const SLUG          = 'vimeo-page-builder';
	const SETTINGS_SLUG = 'vimeo-page-builder-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_vpb_build', array( $this, 'ajax_build' ) );
	}

	public function menu() {
		add_menu_page(
			'Video Pages',
			'Video Pages',
			vpb_build_cap(),
			self::SLUG,
			array( $this, 'render_build' ),
			'dashicons-video-alt3',
			26
		);

		add_submenu_page(
			self::SLUG,
			'Build A Video Page',
			'Build New',
			vpb_build_cap(),
			self::SLUG,
			array( $this, 'render_build' )
		);

		add_submenu_page(
			self::SLUG,
			'Vimeo Page Builder Settings',
			'Settings',
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_register_style( 'vpb-admin', false, array(), VPB_VERSION );
		wp_enqueue_style( 'vpb-admin' );
		wp_add_inline_style( 'vpb-admin', $this->css() );

		wp_register_script( 'vpb-admin', false, array( 'jquery' ), VPB_VERSION, true );
		wp_enqueue_script( 'vpb-admin' );
		wp_add_inline_script( 'vpb-admin', $this->js() );
		wp_localize_script( 'vpb-admin', 'VPB', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'vpb_build' ),
		) );
	}

	/* ---------------------------------------------------------------- build */

	public function render_build() {
		if ( ! current_user_can( vpb_build_cap() ) ) {
			wp_die( 'You do not have permission to build video pages.' );
		}

		$s     = vpb_settings();
		$ready = $s['template_id'] && get_post( $s['template_id'] );
		?>
		<div class="wrap vpb-wrap">
			<h1>Build A Video Page</h1>

			<?php if ( ! $ready ) : ?>
				<div class="notice notice-warning">
					<p>
						No master page has been chosen yet.
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">Choose one in Settings</a>
							and this screen is ready to use.
						<?php else : ?>
							Ask an administrator to set one up under Video Pages &gt; Settings.
						<?php endif; ?>
					</p>
				</div>
			<?php else : ?>

				<div class="vpb-card">
					<label for="vpb-input" class="vpb-label">Vimeo video ID</label>
					<p class="vpb-hint">Paste the ID or the whole Vimeo link &mdash; either works.</p>

					<div class="vpb-row">
						<input type="text" id="vpb-input" class="vpb-input" placeholder="76979871" autocomplete="off" spellcheck="false">
						<button type="button" id="vpb-go" class="button button-primary vpb-go">BUILD</button>
						<a href="#" id="vpb-view" class="vpb-view" target="_blank" rel="noopener" hidden>View page &rarr;</a>
					</div>

					<?php if ( false !== strpos( $s['title_format'], '{company}' ) ) : ?>
						<div class="vpb-row vpb-row-second">
							<input type="text" id="vpb-company" class="vpb-input vpb-input-sub"
								placeholder="Company name" autocomplete="off">
						</div>
						<p class="vpb-hint vpb-hint-sub">
							Used in the page title so it can be found in the admin later.
							The URL is always the video ID on its own.
						</p>
					<?php endif; ?>

					<div id="vpb-result" class="vpb-result" hidden></div>
				</div>

				<?php $this->render_recent(); ?>

			<?php endif; ?>
		</div>
		<?php
	}

	private function render_recent() {
		$recent = VPB_Builder::recent( 10 );

		if ( empty( $recent ) ) {
			return;
		}
		?>
		<h2 class="vpb-h2">Recently built</h2>
		<table class="widefat striped vpb-table">
			<thead>
				<tr>
					<th>Video ID</th>
					<th>Page</th>
					<th>Built</th>
					<th>By</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $recent as $p ) :
				$vid  = get_post_meta( $p->ID, '_vpb_vimeo_id', true );
				$when = get_post_meta( $p->ID, '_vpb_built_at', true );
				$who  = get_userdata( (int) get_post_meta( $p->ID, '_vpb_built_by', true ) );
				?>
				<tr>
					<td><code><?php echo esc_html( $vid ); ?></code></td>
					<td><?php echo esc_html( get_the_title( $p ) ); ?>
						<?php if ( 'publish' !== $p->post_status ) : ?>
							<span class="vpb-pill"><?php echo esc_html( $p->post_status ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $when ? mysql2date( 'j M Y, H:i', $when ) : '' ); ?></td>
					<td><?php echo esc_html( $who ? $who->display_name : '' ); ?></td>
					<td class="vpb-actions">
						<a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" rel="noopener">View</a>
						<a href="<?php echo esc_url( VPB_Builder::elementor_edit_url( $p->ID ) ); ?>">Edit</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ------------------------------------------------------------- settings */

	public function save_settings() {
		if ( ! isset( $_POST['vpb_settings_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'vpb_save_settings', 'vpb_settings_nonce' );

		$in  = wp_unslash( $_POST );
		$new = array(
			'template_id'  => isset( $in['template_id'] ) ? (int) $in['template_id'] : 0,
			'source_vimeo' => isset( $in['source_vimeo'] ) ? preg_replace( '/\D/', '', $in['source_vimeo'] ) : '',
			'post_type'    => ( isset( $in['post_type'] ) && 'post' === $in['post_type'] ) ? 'post' : 'page',
			'post_status'  => ( isset( $in['post_status'] ) && 'draft' === $in['post_status'] ) ? 'draft' : 'publish',
			'title_format' => isset( $in['title_format'] ) ? sanitize_text_field( $in['title_format'] ) : '{id}',
			'parent_id'    => isset( $in['parent_id'] ) ? (int) $in['parent_id'] : 0,
			'capability'   => isset( $in['capability'] ) ? sanitize_key( $in['capability'] ) : 'edit_pages',
			'verify_vimeo' => empty( $in['verify_vimeo'] ) ? 0 : 1,
		);

		// Blank "current ID" means: work it out from the master page.
		if ( '' === $new['source_vimeo'] && $new['template_id'] ) {
			$new['source_vimeo'] = VPB_Builder::detect_vimeo_id( $new['template_id'] );
		}

		update_option( VPB_OPTION, $new );

		wp_safe_redirect( add_query_arg(
			array(
				'page'       => self::SETTINGS_SLUG,
				'vpb_saved'  => 1,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Administrators only.' );
		}

		$s        = vpb_settings();
		$all      = $s['template_id'] ? VPB_Builder::all_vimeo_ids( $s['template_id'] ) : array();
		$detected = $all ? $all[0] : '';
		$target   = ! empty( $s['source_vimeo'] ) ? $s['source_vimeo'] : $detected;
		?>
		<div class="wrap vpb-wrap">
			<h1>Vimeo Page Builder Settings</h1>

			<?php if ( ! empty( $_GET['vpb_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<?php if ( $s['template_id'] && ! $detected ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong>No Vimeo video found inside that master page.</strong>
						If the video sits in a global Elementor header (Templates &gt; Theme Builder) rather than in the page
						itself, cloning the page will not change it &mdash; that needs a different approach.
					</p>
				</div>
			<?php endif; ?>

			<?php if ( count( $all ) > 1 ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong>That master page has more than one Vimeo video on it</strong>
						(<?php echo esc_html( implode( ', ', $all ) ); ?>).
						Only <code><?php echo esc_html( $target ); ?></code> will be replaced when a page is built &mdash;
						the others are left exactly as they are, which is usually what you want if one of them is a fixed
						testimonial or intro clip. If the wrong one is being swapped, put the right ID in the box below.
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'vpb_save_settings', 'vpb_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="template_id">Master page</label></th>
						<td>
							<select name="template_id" id="template_id" class="regular-text">
								<option value="0">&mdash; choose &mdash;</option>
								<?php foreach ( $this->elementor_pages() as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $s['template_id'], $p->ID ); ?>>
										<?php echo esc_html( get_the_title( $p ) . ' (#' . $p->ID . ', ' . $p->post_type . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">The page that gets copied. Only pages with Elementor content are listed.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="source_vimeo">Video ID in the master page</label></th>
						<td>
							<input type="text" name="source_vimeo" id="source_vimeo" class="regular-text"
								value="<?php echo esc_attr( $s['source_vimeo'] ); ?>"
								placeholder="<?php echo esc_attr( $detected ? $detected : 'auto-detected' ); ?>">
							<p class="description">
								<?php if ( $detected ) : ?>
									Detected automatically as <code><?php echo esc_html( $detected ); ?></code>. Leave blank unless you need to override it.
								<?php else : ?>
									Leave blank to detect it automatically when the master page is saved.
								<?php endif; ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Create as</th>
						<td>
							<label><input type="radio" name="post_type" value="page" <?php checked( $s['post_type'], 'page' ); ?>> Page</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="post_type" value="post" <?php checked( $s['post_type'], 'post' ); ?>> Post</label>
						</td>
					</tr>

					<tr>
						<th scope="row">Publish as</th>
						<td>
							<label><input type="radio" name="post_status" value="publish" <?php checked( $s['post_status'], 'publish' ); ?>> Live immediately</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="post_status" value="draft" <?php checked( $s['post_status'], 'draft' ); ?>> Draft, for checking first</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="title_format">Page title</label></th>
						<td>
							<input type="text" name="title_format" id="title_format" class="regular-text"
								value="<?php echo esc_attr( $s['title_format'] ); ?>">
							<p class="description">
								<code>{id}</code> is the Vimeo ID, <code>{title}</code> is the video's title on Vimeo,
								<code>{company}</code> is a name typed at build time.
								The URL always uses the ID on its own, regardless of what you put here.
							</p>
							<p class="description">
								Include <code>{company}</code> and a second box appears on the build screen. Handy if you
								are given a company name alongside each video &mdash; a list of pages titled only
								<code>1211689555</code> is hard to search through a year from now.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="parent_id">Parent page</label></th>
						<td>
							<?php
							wp_dropdown_pages( array(
								'name'              => 'parent_id',
								'id'                => 'parent_id',
								'selected'          => $s['parent_id'],
								'show_option_none'  => '— none —',
								'option_none_value' => 0,
							) );
							?>
							<p class="description">Optional. Nests the built pages under a parent, e.g. /videos/76979871/.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="capability">Who can build</label></th>
						<td>
							<select name="capability" id="capability">
								<option value="edit_pages" <?php selected( $s['capability'], 'edit_pages' ); ?>>Editors and above</option>
								<option value="edit_posts" <?php selected( $s['capability'], 'edit_posts' ); ?>>Authors and above</option>
								<option value="manage_options" <?php selected( $s['capability'], 'manage_options' ); ?>>Administrators only</option>
							</select>
							<p class="description">Settings stay administrator-only either way.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Check with Vimeo</th>
						<td>
							<label>
								<input type="checkbox" name="verify_vimeo" value="1" <?php checked( $s['verify_vimeo'], 1 ); ?>>
								Confirm the video exists before building
							</label>
							<p class="description">Catches a mistyped ID before a page goes live. Adds about a second.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Pages and posts that actually have Elementor content saved.
	 */
	private function elementor_pages() {
		return get_posts( array(
			'post_type'        => array( 'page', 'post', 'elementor_library' ),
			'post_status'      => array( 'publish', 'draft', 'private' ),
			'numberposts'      => 200,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'meta_key'         => '_elementor_data',
			'suppress_filters' => false,
		) );
	}

	/* ----------------------------------------------------------------- ajax */

	public function ajax_build() {
		check_ajax_referer( 'vpb_build', 'nonce' );

		if ( ! current_user_can( vpb_build_cap() ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to build video pages.' ) );
		}

		$raw     = isset( $_POST['vimeo'] ) ? wp_unslash( $_POST['vimeo'] ) : '';
		$force   = ! empty( $_POST['force'] );
		$company = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';

		$result = VPB_Builder::build( $raw, $force, $company );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			) );
		}

		wp_send_json_success( $result );
	}

	/* --------------------------------------------------------------- assets */

	private function css() {
		return <<<CSS
.vpb-wrap .vpb-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:24px;max-width:820px;margin:18px 0}
.vpb-wrap .vpb-label{display:block;font-size:15px;font-weight:600;margin-bottom:2px}
.vpb-wrap .vpb-hint{margin:0 0 14px;color:#646970}
.vpb-wrap .vpb-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.vpb-wrap .vpb-row-second{margin-top:10px}
.vpb-wrap .vpb-input{flex:1 1 320px;font-size:18px;padding:10px 12px;line-height:1.3}
.vpb-wrap .vpb-input-sub{font-size:15px;padding:8px 12px;max-width:518px}
.vpb-wrap .vpb-hint-sub{margin:6px 0 0;font-size:12px;color:#787c82}
.vpb-wrap .vpb-go{font-size:15px!important;height:auto!important;padding:10px 28px!important;font-weight:600;letter-spacing:.4px}
.vpb-wrap .vpb-go[disabled]{opacity:.6}
.vpb-wrap .vpb-view{font-weight:600;text-decoration:none;white-space:nowrap}
.vpb-wrap .vpb-result{margin-top:18px;padding:14px 16px;border-radius:4px;border-left:4px solid #72aee6;background:#f6f7f7}
.vpb-wrap .vpb-result.is-ok{border-left-color:#00a32a;background:#f0f6f1}
.vpb-wrap .vpb-result.is-warn{border-left-color:#dba617;background:#fcf9e8}
.vpb-wrap .vpb-result.is-err{border-left-color:#d63638;background:#fcf0f1}
.vpb-wrap .vpb-result p{margin:.3em 0}
.vpb-wrap .vpb-result .vpb-links a{margin-right:16px;font-weight:600}
.vpb-wrap .vpb-h2{margin-top:32px}
.vpb-wrap .vpb-table{max-width:820px}
.vpb-wrap .vpb-table td,.vpb-wrap .vpb-table th{padding:9px 12px}
.vpb-wrap .vpb-actions a{margin-right:12px}
.vpb-wrap .vpb-pill{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:9px;background:#dba617;color:#fff;font-size:11px;text-transform:uppercase}
CSS;
	}

	private function js() {
		return <<<'JS'
jQuery(function($){
	var $in  = $('#vpb-input'),
		$go  = $('#vpb-go'),
		$out = $('#vpb-result'),
		$view= $('#vpb-view');

	function show(cls, html){
		$out.attr('class','vpb-result '+cls).html(html).prop('hidden', false);
	}

	function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }

	function build(force){
		var val = $.trim($in.val()),
			co  = $.trim($('#vpb-company').val() || '');

		if (!val){
			show('is-err','<p>Paste a Vimeo ID or link first.</p>');
			$in.trigger('focus');
			return;
		}

		$go.prop('disabled', true).text('BUILDING…');
		$view.prop('hidden', true);
		show('', '<p>Working…</p>');

		$.post(VPB.ajax, { action:'vpb_build', nonce:VPB.nonce, vimeo:val, company:co, force:force ? 1 : 0 })
		.done(function(res){
			if (!res || !res.success){
				var m = (res && res.data && res.data.message) ? res.data.message : 'Something went wrong.';
				show('is-err','<p>'+esc(m)+'</p>');
				return;
			}

			var d = res.data;

			$view.attr('href', d.permalink).prop('hidden', false);

			if (d.status === 'exists'){
				show('is-warn',
					'<p><strong>Already built.</strong> A page for video '+esc(d.vimeo_id)+' exists, so nothing was created.</p>'+
					'<p class="vpb-links"><a href="'+esc(d.permalink)+'" target="_blank" rel="noopener">View page</a>'+
					'<a href="'+esc(d.edit_url)+'">Edit in Elementor</a>'+
					'<a href="#" class="vpb-force">Build a second one anyway</a></p>');
				return;
			}

			var html = '<p><strong>Done.</strong> '+esc(d.title)+' is live.</p>';

			if (d.video_name){
				html += '<p>Video: “'+esc(d.video_name)+'”</p>';
			}
			if (d.warning){
				html += '<p><em>'+esc(d.warning)+'</em></p>';
			}

			html += '<p class="vpb-links"><a href="'+esc(d.permalink)+'" target="_blank" rel="noopener">View page</a>'+
					'<a href="'+esc(d.edit_url)+'">Edit in Elementor</a></p>';

			show(d.warning ? 'is-warn' : 'is-ok', html);
			$in.val('');
			$('#vpb-company').val('');
			$in.trigger('focus');
		})
		.fail(function(xhr){
			show('is-err','<p>The request failed'+(xhr && xhr.status ? ' (status '+xhr.status+')' : '')+'. Try again, and tell your developer if it keeps happening.</p>');
		})
		.always(function(){
			$go.prop('disabled', false).text('BUILD');
		});
	}

	$go.on('click', function(){ build(false); });

	$(document).on('keydown', '#vpb-input, #vpb-company', function(e){
		if (e.which === 13){ e.preventDefault(); build(false); }
	});

	$out.on('click','.vpb-force', function(e){
		e.preventDefault();
		build(true);
	});
});
JS;
	}
}
