<?php
/**
 * Everything to do with reading a Vimeo reference out of whatever a human pasted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VPB_Vimeo {

	/**
	 * Pull a Vimeo ID (and private hash, if present) out of arbitrary pasted text.
	 *
	 * Staff paste all sorts of things, so we accept all of these:
	 *   76979871
	 *   https://vimeo.com/76979871
	 *   https://vimeo.com/76979871/8272103f6e          (unlisted video + hash)
	 *   https://vimeo.com/76979871?share=copy
	 *   https://player.vimeo.com/video/76979871?h=8272103f6e
	 *   https://vimeo.com/channels/staffpicks/76979871
	 *   https://vimeo.com/groups/name/videos/76979871
	 *   an <iframe> embed code copied straight out of Vimeo
	 *
	 * @param string $raw Whatever was typed into the box.
	 * @return array{id:string,hash:string}|WP_Error
	 */
	public static function parse( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return new WP_Error( 'vpb_empty', 'Paste a Vimeo ID or URL first.' );
		}

		// An iframe embed was pasted - dig the src out and carry on with that.
		if ( preg_match( '#<iframe[^>]+src=["\']([^"\']+)["\']#i', $raw, $m ) ) {
			$raw = html_entity_decode( $m[1] );
		}

		$id   = '';
		$hash = '';

		// Bare numeric ID.
		if ( preg_match( '/^\d{6,12}$/', $raw ) ) {
			return array(
				'id'   => $raw,
				'hash' => '',
			);
		}

		// player.vimeo.com/video/ID  with the hash in ?h=
		if ( preg_match( '#player\.vimeo\.com/video/(\d+)#i', $raw, $m ) ) {
			$id = $m[1];
			if ( preg_match( '#[?&]h=([A-Za-z0-9]+)#', $raw, $h ) ) {
				$hash = $h[1];
			}
			return array(
				'id'   => $id,
				'hash' => $hash,
			);
		}

		// vimeo.com/…/ID  optionally followed by /hash
		if ( preg_match( '#vimeo\.com/(?:[^/\s?]+/)*?(\d{6,12})(?:/([A-Za-z0-9]+))?#i', $raw, $m ) ) {
			$id   = $m[1];
			$hash = isset( $m[2] ) ? $m[2] : '';

			// A hash can also arrive as ?h= on a vimeo.com link.
			if ( '' === $hash && preg_match( '#[?&]h=([A-Za-z0-9]+)#', $raw, $h ) ) {
				$hash = $h[1];
			}

			return array(
				'id'   => $id,
				'hash' => $hash,
			);
		}

		// Last resort: a long number sitting inside some other string.
		if ( preg_match( '/(\d{7,12})/', $raw, $m ) ) {
			return array(
				'id'   => $m[1],
				'hash' => '',
			);
		}

		return new WP_Error(
			'vpb_unparseable',
			'That does not look like a Vimeo ID or URL. Expected something like 76979871 or https://vimeo.com/76979871'
		);
	}

	/**
	 * Canonical vimeo.com URL for an ID, preserving an unlisted-video hash.
	 */
	public static function url( $id, $hash = '' ) {
		$url = 'https://vimeo.com/' . $id;
		if ( $hash ) {
			$url .= '/' . $hash;
		}

		return $url;
	}

	/**
	 * Ask Vimeo whether the video actually exists, and what it is called.
	 *
	 * This is a courtesy check so a typo is caught before a page goes live rather
	 * than after. It is deliberately not fatal: Vimeo being slow, or the video
	 * being privacy-restricted to a domain, must not stop staff from working.
	 *
	 * @return array{ok:bool,title:string,thumb:string,reason:string}
	 */
	public static function lookup( $id, $hash = '' ) {
		$endpoint = add_query_arg(
			array(
				'url'   => rawurlencode( self::url( $id, $hash ) ),
				'width' => 320,
			),
			'https://vimeo.com/api/oembed.json'
		);

		$res = wp_remote_get( $endpoint, array( 'timeout' => 8 ) );

		if ( is_wp_error( $res ) ) {
			return array(
				'ok'     => false,
				'title'  => '',
				'thumb'  => '',
				'reason' => 'could not reach Vimeo (' . $res->get_error_message() . ')',
			);
		}

		$code = wp_remote_retrieve_response_code( $res );

		if ( 404 === $code ) {
			return array(
				'ok'     => false,
				'title'  => '',
				'thumb'  => '',
				'reason' => 'no video with that ID',
			);
		}

		if ( 403 === $code ) {
			return array(
				'ok'     => false,
				'title'  => '',
				'thumb'  => '',
				'reason' => 'video is private or domain-restricted',
			);
		}

		if ( 200 !== $code ) {
			return array(
				'ok'     => false,
				'title'  => '',
				'thumb'  => '',
				'reason' => 'Vimeo returned status ' . $code,
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		return array(
			'ok'     => true,
			'title'  => isset( $body['title'] ) ? (string) $body['title'] : '',
			'thumb'  => isset( $body['thumbnail_url'] ) ? (string) $body['thumbnail_url'] : '',
			'reason' => '',
		);
	}
}
