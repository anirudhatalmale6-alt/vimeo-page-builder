<?php
/**
 * Cloning the master Elementor page and re-pointing its video.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VPB_Builder {

	/**
	 * Elementor meta we deliberately do NOT copy.
	 *
	 * These are all generated caches keyed to the ORIGINAL post ID. Copying them
	 * gives the new page the master page's stylesheet, which is the classic way a
	 * cloned Elementor page comes out looking broken. Left absent, Elementor
	 * simply regenerates them the first time the new page is viewed.
	 */
	const SKIP_META = array(
		'_elementor_css',
		'_elementor_page_assets',
		'_elementor_element_cache',
		'_elementor_screenshot',
	);

	/**
	 * The slug we are insisting on for the duration of one wp_insert_post() call.
	 *
	 * @var string
	 */
	private static $keep_slug = '';

	/**
	 * Let a page keep a purely numeric slug.
	 *
	 * WordPress deliberately refuses to give a hierarchical post a slug that is
	 * nothing but digits - see wp_unique_post_slug(), which treats /1234567/ as
	 * clashing with pagination like /page/2/ and quietly appends "-2". Since the
	 * whole requirement here is that the URL IS the Vimeo ID, we override that,
	 * but only while our own insert is running and only when the slug is genuinely
	 * unused. Everything else on the site keeps WordPress's normal behaviour.
	 *
	 * @param string $slug          Slug WordPress settled on.
	 * @param int    $post_id       Post being saved.
	 * @param string $post_status   Status.
	 * @param string $post_type     Post type.
	 * @param int    $post_parent   Parent ID.
	 * @param string $original_slug Slug we asked for.
	 * @return string
	 */
	public static function keep_numeric_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( '' === self::$keep_slug || $original_slug !== self::$keep_slug ) {
			return $slug;
		}

		if ( ! preg_match( '/^\d+$/', $original_slug ) ) {
			return $slug;
		}

		// Only override the pagination guard - never a real collision with an
		// existing page, which would leave two posts fighting over one URL.
		global $wpdb;

		$taken = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_parent = %d AND ID != %d AND post_status != 'trash' LIMIT 1",
			$original_slug,
			$post_type,
			$post_parent,
			$post_id
		) );

		return $taken ? $slug : $original_slug;
	}

	/**
	 * Read the master page's Elementor JSON as an array.
	 *
	 * @return array|WP_Error
	 */
	public static function template_data( $template_id ) {
		$template_id = (int) $template_id;

		if ( ! $template_id || ! get_post( $template_id ) ) {
			return new WP_Error( 'vpb_no_template', 'The master page has not been chosen yet, or no longer exists. Set it under Video Pages > Settings.' );
		}

		$raw = get_post_meta( $template_id, '_elementor_data', true );

		if ( empty( $raw ) ) {
			return new WP_Error( 'vpb_not_elementor', 'That master page has no Elementor content saved against it. Open it in Elementor, hit Update once, then try again.' );
		}

		$data = is_array( $raw ) ? $raw : json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vpb_bad_json', 'The master page\'s Elementor content could not be read. It may be corrupted.' );
		}

		return $data;
	}

	/**
	 * Every distinct Vimeo ID referenced anywhere in a page, in the order found.
	 *
	 * Note this works on the DECODED tree rather than the stored JSON string.
	 * json_encode() escapes forward slashes, so a URL is stored as
	 * "player.vimeo.com\/video\/123" - a regex written for a normal URL silently
	 * matches nothing against the raw meta value.
	 *
	 * @return string[]
	 */
	public static function all_vimeo_ids( $template_id ) {
		$data = self::template_data( $template_id );

		if ( is_wp_error( $data ) ) {
			return array();
		}

		$ids = array();

		// Fields Elementor itself uses for a Vimeo video, checked first so the
		// page's actual video wins over an incidental link in some body copy.
		self::walk( $data, function ( $node ) use ( &$ids ) {
			if ( empty( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
				return;
			}
			foreach ( array( 'vimeo_url', 'background_video_link', 'background_slideshow_video_link' ) as $key ) {
				if ( empty( $node['settings'][ $key ] ) || ! is_string( $node['settings'][ $key ] ) ) {
					continue;
				}
				if ( false === stripos( $node['settings'][ $key ], 'vimeo' ) ) {
					continue;
				}
				$p = VPB_Vimeo::parse( $node['settings'][ $key ] );
				if ( ! is_wp_error( $p ) && ! in_array( $p['id'], $ids, true ) ) {
					$ids[] = $p['id'];
				}
			}
		} );

		// Anything else: hand-pasted iframes, HTML widgets, shortcodes, buttons.
		self::map_strings( $data, function ( $str ) use ( &$ids ) {
			if ( false !== stripos( $str, 'vimeo' )
				&& preg_match_all( '#vimeo\.com/(?:video/)?(\d{6,12})#i', $str, $m ) ) {
				foreach ( $m[1] as $hit ) {
					if ( ! in_array( $hit, $ids, true ) ) {
						$ids[] = $hit;
					}
				}
			}
			return $str;
		} );

		return $ids;
	}

	/**
	 * The Vimeo ID sitting in the master page.
	 *
	 * @return string Empty string when nothing was found.
	 */
	public static function detect_vimeo_id( $template_id ) {
		$ids = self::all_vimeo_ids( $template_id );

		return $ids ? $ids[0] : '';
	}

	/**
	 * Walk every element node in an Elementor tree, depth first.
	 *
	 * @param array    $nodes Elementor elements array.
	 * @param callable $fn    Receives each node by value.
	 */
	private static function walk( $nodes, $fn ) {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			call_user_func( $fn, $node );
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::walk( $node['elements'], $fn );
			}
		}
	}

	/**
	 * Produce a copy of the Elementor tree pointing at a different Vimeo video.
	 *
	 * Two passes, because there is more than one way a video ends up on a page:
	 *
	 *   1. Structured - any Elementor video widget set to Vimeo gets its
	 *      vimeo_url rewritten outright. This is correct even if the widget's
	 *      current URL is something we failed to recognise.
	 *   2. Textual - the old ID is swapped for the new one anywhere else it
	 *      appears, which catches hand-pasted iframes, HTML widgets, background
	 *      videos and buttons linking to the video.
	 *
	 * @param array  $data     Template tree.
	 * @param string $old_id   ID currently in the template.
	 * @param string $new_id   ID to put in.
	 * @param string $new_hash Unlisted-video hash, if any.
	 * @return array{data:array,widgets:int,text:int}
	 */
	public static function swap( $data, $old_id, $new_id, $new_hash = '' ) {
		$new_url = VPB_Vimeo::url( $new_id, $new_hash );
		$counts  = array(
			'widgets' => 0,
			'text'    => 0,
		);

		$data = self::map( $data, function ( $node ) use ( $new_url, $old_id, &$counts ) {
			if ( ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
				return $node;
			}

			$is_video_widget = ( isset( $node['widgetType'] ) && 'video' === $node['widgetType'] );
			$is_vimeo        = ( isset( $node['settings']['video_type'] ) && 'vimeo' === $node['settings']['video_type'] );

			// An Elementor video widget only reads vimeo_url when video_type is
			// vimeo, so a widget already holding a vimeo_url counts too.
			if ( ! $is_video_widget || ! ( $is_vimeo || ! empty( $node['settings']['vimeo_url'] ) ) ) {
				return $node;
			}

			// When we know which video we are replacing, leave any OTHER video on
			// the page alone. A master page with a hero video and, say, a
			// testimonial video must not have both rewritten to the same clip.
			if ( $old_id ) {
				$current = isset( $node['settings']['vimeo_url'] ) ? (string) $node['settings']['vimeo_url'] : '';
				if ( '' !== $current && false === strpos( $current, $old_id ) ) {
					return $node;
				}
			}

			$node['settings']['video_type'] = 'vimeo';
			$node['settings']['vimeo_url']  = $new_url;
			$counts['widgets']++;

			return $node;
		} );

		// Textual pass. Only runs when we know what we are looking for, so that
		// a blank or wrong "current ID" setting can never mangle the layout.
		//
		// This walks individual string values rather than running a regex over
		// the encoded JSON. Doing it at blob level would risk rewriting an
		// unrelated number that merely happens to equal the old video ID - an
		// attachment ID, a z-index, a phone number - and would also have to
		// contend with JSON slash escaping. Per-value is both safer and simpler.
		if ( $old_id && $old_id !== $new_id ) {
			$hit = 0;

			$data = self::map_strings( $data, function ( $str ) use ( $old_id, $new_id, $new_hash, &$hit ) {
				$before = $str;

				if ( false !== stripos( $str, 'vimeo' ) ) {
					$str = self::rewrite_vimeo_url( $str, $old_id, $new_id, $new_hash );
				}

				// A field holding nothing but the bare ID, e.g. a shortcode attribute.
				if ( trim( $str ) === $old_id ) {
					$str = $new_id;
				}

				if ( $str !== $before ) {
					$hit++;
				}

				return $str;
			} );

			$counts['text'] = $hit;
		}

		return array(
			'data'    => $data,
			'widgets' => $counts['widgets'],
			'text'    => $counts['text'],
		);
	}

	/**
	 * Point every Vimeo URL in a string at a different video.
	 *
	 * The fiddly part is the privacy hash on unlisted videos. It travels either
	 * in the path (vimeo.com/ID/HASH) or as ?h=HASH on a player URL, and it
	 * belongs to one specific video. Swapping the ID while leaving the old hash
	 * behind produces a URL that looks right and refuses to play, which is a
	 * miserable thing to debug - so the hash is always dealt with alongside the
	 * ID it came with.
	 *
	 * @param string $str      Haystack, e.g. an iframe embed or a link.
	 * @param string $old_id   ID being replaced.
	 * @param string $new_id   ID going in.
	 * @param string $new_hash Privacy hash for the new video, if it has one.
	 * @return string
	 */
	private static function rewrite_vimeo_url( $str, $old_id, $new_id, $new_hash ) {
		$quoted = preg_quote( $old_id, '#' );

		// vimeo.com/OLD, vimeo.com/video/OLD, each optionally followed by /HASH.
		$str = preg_replace_callback(
			'#(vimeo\.com/)(video/)?' . $quoted . '(?:/([A-Za-z0-9]+))?(?![\d])#i',
			function ( $m ) use ( $new_id, $new_hash ) {
				$is_player = ! empty( $m[2] );
				$url       = $m[1] . ( $is_player ? 'video/' : '' ) . $new_id;

				// Player URLs carry the hash as ?h=, handled below; plain
				// vimeo.com links carry it in the path.
				if ( $new_hash && ! $is_player ) {
					$url .= '/' . $new_hash;
				}

				return $url;
			},
			$str
		);

		if ( false === strpos( $str, $new_id ) ) {
			return $str;
		}

		if ( $new_hash ) {
			$str = preg_replace( '/([?&](?:amp;)?h=)[A-Za-z0-9]+/i', '${1}' . $new_hash, $str );
		} else {
			// Drop the old hash, keeping the query string well formed whether it
			// sat first, in the middle, or last.
			$str = preg_replace( '/([?&])(?:amp;)?h=[A-Za-z0-9]+&(?:amp;)?/i', '${1}', $str );
			$str = preg_replace( '/[?&](?:amp;)?h=[A-Za-z0-9]+/i', '', $str );
		}

		return $str;
	}

	/**
	 * Recursively transform every string value anywhere in a nested array.
	 *
	 * Keys are left untouched; only leaf strings are passed to the callback.
	 */
	private static function map_strings( $value, $fn ) {
		if ( is_string( $value ) ) {
			return call_user_func( $fn, $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::map_strings( $v, $fn );
			}
		}

		return $value;
	}

	/**
	 * Recursively transform every node in an Elementor tree.
	 */
	private static function map( $nodes, $fn ) {
		$out = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				$out[] = $node;
				continue;
			}

			$node = call_user_func( $fn, $node );

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$node['elements'] = self::map( $node['elements'], $fn );
			}

			$out[] = $node;
		}

		return $out;
	}

	/**
	 * Has a page already been built for this video?
	 *
	 * Checked by meta first (survives someone renaming the page) and by slug
	 * second (catches pages built before this plugin existed).
	 *
	 * @return int Post ID, or 0.
	 */
	public static function existing( $vimeo_id, $post_type ) {
		$q = get_posts( array(
			'post_type'        => $post_type,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => '_vpb_vimeo_id',
			'meta_value'       => $vimeo_id,
			'suppress_filters' => false,
		) );

		if ( ! empty( $q ) ) {
			return (int) $q[0];
		}

		$page = get_page_by_path( sanitize_title( $vimeo_id ), OBJECT, $post_type );

		return $page ? (int) $page->ID : 0;
	}

	/**
	 * Build a page for a Vimeo video.
	 *
	 * @param string $raw     Whatever the user pasted.
	 * @param bool   $force   Build again even if a page already exists.
	 * @param string $company Optional company name, for the page title.
	 * @return array|WP_Error
	 */
	public static function build( $raw, $force = false, $company = '' ) {
		$s = vpb_settings();

		$parsed = VPB_Vimeo::parse( $raw );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$vimeo_id   = $parsed['id'];
		$vimeo_hash = $parsed['hash'];

		$data = self::template_data( $s['template_id'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$post_type = post_type_exists( $s['post_type'] ) ? $s['post_type'] : 'page';

		// Already built? Hand back the existing one rather than making a second
		// page on a "-2" URL, which is the usual way these libraries get messy.
		$dupe = self::existing( $vimeo_id, $post_type );
		if ( $dupe && ! $force ) {
			return array(
				'status'    => 'exists',
				'post_id'   => $dupe,
				'vimeo_id'  => $vimeo_id,
				'permalink' => get_permalink( $dupe ),
				'edit_url'  => self::elementor_edit_url( $dupe ),
				'message'   => 'A page for this video already exists.',
			);
		}

		// Confirm the video is real before publishing a page that points at nothing.
		$title_from_vimeo = '';
		$warning          = '';

		if ( ! empty( $s['verify_vimeo'] ) ) {
			$look = VPB_Vimeo::lookup( $vimeo_id, $vimeo_hash );
			if ( $look['ok'] ) {
				$title_from_vimeo = $look['title'];
			} else {
				// Hard stop only when Vimeo positively says the video is not there.
				if ( 'no video with that ID' === $look['reason'] ) {
					return new WP_Error(
						'vpb_no_video',
						sprintf( 'Vimeo has no video with the ID %s. Check the number and try again.', $vimeo_id )
					);
				}
				$warning = 'Could not confirm the video with Vimeo (' . $look['reason'] . '). The page was still built - worth opening it to check the video plays.';
			}
		}

		$source_id = ! empty( $s['source_vimeo'] ) ? $s['source_vimeo'] : self::detect_vimeo_id( $s['template_id'] );

		$swap = self::swap( $data, $source_id, $vimeo_id, $vimeo_hash );

		if ( 0 === $swap['widgets'] && 0 === $swap['text'] ) {
			return new WP_Error(
				'vpb_nothing_swapped',
				'No video could be found inside the master page, so there was nothing to change. If the video sits in a global Elementor header rather than in the page itself, cloning the page will not touch it - tell me and I will point this at the header instead.'
			);
		}

		$company = trim( wp_strip_all_tags( (string) $company ) );

		$title = str_replace(
			array( '{id}', '{title}', '{company}' ),
			array( $vimeo_id, $title_from_vimeo, $company ),
			$s['title_format'] ? $s['title_format'] : '{id}'
		);

		// Tidy up what a blank token leaves behind, e.g. "{company} - {id}" with
		// no company given should not produce a title starting with " - ".
		$title = trim( preg_replace( '/\s{2,}/', ' ', $title ) );
		$title = trim( $title, " -–—|,:" );

		if ( '' === $title ) {
			$title = $vimeo_id;
		}

		$slug = sanitize_title( $vimeo_id );

		self::$keep_slug = $force ? '' : $slug;
		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'keep_numeric_slug' ), 10, 6 );

		$post_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_type'    => $post_type,
			'post_status'  => $s['post_status'],
			'post_parent'  => (int) $s['parent_id'],
			'post_content' => get_post_field( 'post_content', $s['template_id'] ),
		), true );

		remove_filter( 'wp_unique_post_slug', array( __CLASS__, 'keep_numeric_slug' ), 10 );
		self::$keep_slug = '';

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::copy_meta( (int) $s['template_id'], $post_id );

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $swap['data'] ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		// Our own breadcrumbs, used for duplicate detection and the recent list.
		update_post_meta( $post_id, '_vpb_vimeo_id', $vimeo_id );
		if ( $vimeo_hash ) {
			update_post_meta( $post_id, '_vpb_vimeo_hash', $vimeo_hash );
		}
		if ( $company ) {
			update_post_meta( $post_id, '_vpb_company', $company );
		}
		update_post_meta( $post_id, '_vpb_source_template', (int) $s['template_id'] );
		update_post_meta( $post_id, '_vpb_built_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_vpb_built_by', get_current_user_id() );

		self::flush_elementor_css( $post_id );

		return array(
			'status'     => 'built',
			'post_id'    => $post_id,
			'vimeo_id'   => $vimeo_id,
			'title'      => $title,
			'permalink'  => get_permalink( $post_id ),
			'edit_url'   => self::elementor_edit_url( $post_id ),
			'video_name' => $title_from_vimeo,
			'company'    => $company,
			'warning'    => $warning,
			'swapped'    => $swap['widgets'],
		);
	}

	/**
	 * Copy the master page's Elementor settings onto the new page.
	 */
	private static function copy_meta( $from, $to ) {
		$all = get_post_meta( $from );

		if ( ! is_array( $all ) ) {
			return;
		}

		$also = array( '_wp_page_template', '_thumbnail_id' );

		foreach ( $all as $key => $values ) {
			$is_elementor = ( 0 === strpos( $key, '_elementor' ) );

			if ( ! $is_elementor && ! in_array( $key, $also, true ) ) {
				continue;
			}

			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				// get_post_meta() without a key returns raw serialized strings.
				update_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * Make sure the new page gets its own stylesheet rather than inheriting a stale one.
	 */
	private static function flush_elementor_css( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );

		// Best effort. Wrapped because this is Elementor internals, and the whole
		// design goal here is that an Elementor update cannot break the plugin.
		try {
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				$css = new \Elementor\Core\Files\CSS\Post( $post_id );
				$css->update();
			}
		} catch ( \Throwable $e ) {
			// Nothing to do - Elementor will regenerate on first view regardless.
		}
	}

	/**
	 * Deep link straight into the Elementor editor for a post.
	 */
	public static function elementor_edit_url( $post_id ) {
		return add_query_arg(
			array(
				'post'   => $post_id,
				'action' => 'elementor',
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * The most recently built pages, for the list under the build box.
	 */
	public static function recent( $limit = 10 ) {
		$s = vpb_settings();

		return get_posts( array(
			'post_type'        => post_type_exists( $s['post_type'] ) ? $s['post_type'] : 'page',
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'      => $limit,
			'meta_key'         => '_vpb_built_at',
			'orderby'          => 'meta_value',
			'order'            => 'DESC',
			'suppress_filters' => false,
		) );
	}
}
