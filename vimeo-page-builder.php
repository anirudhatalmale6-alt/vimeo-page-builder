<?php
/**
 * Plugin Name: Vimeo Page Builder
 * Description: One field, one button. Paste a Vimeo ID and it clones your master Elementor page, renames it, re-points the video and publishes it.
 * Version:     1.1.0
 * Author:      Anirudha Talmale
 * License:     GPL-2.0-or-later
 * Text Domain: vimeo-page-builder
 *
 * The whole point of this plugin is that it never tries to rebuild your layout.
 * It copies the master page's Elementor JSON verbatim and changes only the video
 * reference inside it, so an Elementor update cannot shift it out from under you.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VPB_VERSION', '1.1.0' );
define( 'VPB_FILE', __FILE__ );
define( 'VPB_DIR', plugin_dir_path( __FILE__ ) );
define( 'VPB_URL', plugin_dir_url( __FILE__ ) );
define( 'VPB_OPTION', 'vpb_settings' );

require_once VPB_DIR . 'includes/class-vpb-vimeo.php';
require_once VPB_DIR . 'includes/class-vpb-builder.php';
require_once VPB_DIR . 'includes/class-vpb-admin.php';

/**
 * Stored settings, with defaults applied.
 */
function vpb_settings() {
	$defaults = array(
		'template_id'    => 0,
		'source_vimeo'   => '',      // ID currently sitting in the master page; auto-detected if blank
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'title_format'   => '{id}',  // {id} = Vimeo ID, {title} = title pulled from Vimeo
		'parent_id'      => 0,
		'capability'     => 'edit_pages',
		'verify_vimeo'   => 1,       // check the video actually exists before building
	);

	$saved = get_option( VPB_OPTION, array() );

	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * The capability a user needs in order to build pages.
 *
 * Settings are always admin-only; this governs the build screen alone, so that
 * staff can publish video pages without being handed the keys to the site.
 */
function vpb_build_cap() {
	$s   = vpb_settings();
	$cap = ! empty( $s['capability'] ) ? $s['capability'] : 'edit_pages';

	return apply_filters( 'vpb_build_capability', $cap );
}

new VPB_Admin();

/**
 * Elementor is a hard dependency - without it there is no master page to clone.
 */
add_action( 'admin_notices', function () {
	if ( did_action( 'elementor/loaded' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>Vimeo Page Builder</strong> needs Elementor to be installed and active.</p></div>';
} );
