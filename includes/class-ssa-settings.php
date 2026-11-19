<?php
defined( 'ABSPATH' ) || exit;

class SSA_Settings {

	const OPT = 'ssa_settings';

	public static function defaults() {
		return array(
			'engine'         => 'puppeteer', // puppeteer | chrome | mshots | github
			'node_path'      => 'node',
			'chrome_path'    => '',
			'width'          => 1440,
			'max_height'     => 8000,
			'wait_ms'        => 1500,
			'format'         => 'png',
			'timeout'        => 90,
			'hide_selectors' => "#cookie-notice\n#CybotCookiebotDialog\n.cc-window\n.cookie-banner\n#onetrust-banner-sdk\n.cmplz-cookiebanner",
			'post_types'     => array( 'page', 'post' ),
			'extra_urls'     => '',
			'schedule'       => 'off', // off | daily | weekly
			'github_repo'    => '',
			'github_branch'  => 'shots',
			'github_ref'     => 'main',
			'github_token'   => '',
		);
	}

	public static function get() {
		return wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
	}

	public static function sanitize( $in ) {
		$d   = self::defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		$out['engine']      = ( isset( $in['engine'] ) && in_array( $in['engine'], array( 'puppeteer', 'chrome', 'mshots', 'github' ), true ) ) ? $in['engine'] : $d['engine'];
		$out['node_path']   = isset( $in['node_path'] ) && '' !== trim( $in['node_path'] ) ? sanitize_text_field( $in['node_path'] ) : 'node';
		$out['chrome_path'] = isset( $in['chrome_path'] ) ? sanitize_text_field( $in['chrome_path'] ) : '';
		$out['width']       = max( 320, min( 3840, (int) ( $in['width'] ?? $d['width'] ) ) );
		$out['max_height']  = max( 1000, min( 30000, (int) ( $in['max_height'] ?? $d['max_height'] ) ) );
		$out['wait_ms']     = max( 0, min( 15000, (int) ( $in['wait_ms'] ?? $d['wait_ms'] ) ) );
		$out['timeout']     = max( 20, min( 300, (int) ( $in['timeout'] ?? $d['timeout'] ) ) );
		$out['format']      = ( isset( $in['format'] ) && 'jpg' === $in['format'] ) ? 'jpg' : 'png';
		$out['schedule']    = ( isset( $in['schedule'] ) && in_array( $in['schedule'], array( 'off', 'daily', 'weekly' ), true ) ) ? $in['schedule'] : 'off';

		$out['hide_selectors'] = isset( $in['hide_selectors'] ) ? sanitize_textarea_field( $in['hide_selectors'] ) : '';
		$out['extra_urls']     = isset( $in['extra_urls'] ) ? sanitize_textarea_field( $in['extra_urls'] ) : '';

		$public            = array_keys( get_post_types( array( 'public' => true ) ) );
		$out['post_types'] = isset( $in['post_types'] ) ? array_values( array_intersect( (array) $in['post_types'], $public ) ) : array();


		// GitHub.
		$repo = isset( $in['github_repo'] ) ? trim( sanitize_text_field( $in['github_repo'] ) ) : '';
		$repo = preg_replace( '#^https?://github\.com/#i', '', $repo );
		$repo = preg_replace( '#\.git$#', '', trim( $repo, '/' ) );
		$out['github_repo'] = preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';

		$branch = isset( $in['github_branch'] ) ? trim( sanitize_text_field( $in['github_branch'] ) ) : '';
		$out['github_branch'] = preg_match( '#^[A-Za-z0-9._/-]+$#', $branch ) ? $branch : 'shots';

		$ref = isset( $in['github_ref'] ) ? trim( sanitize_text_field( $in['github_ref'] ) ) : '';
		$out['github_ref'] = preg_match( '#^[A-Za-z0-9._/-]+$#', $ref ) ? $ref : 'main';

		// Blank token field keeps saved token. Type "-" to clear.
		$tok = isset( $in['github_token'] ) ? trim( sanitize_text_field( $in['github_token'] ) ) : '';
		if ( '' === $tok ) {
			$old = get_option( self::OPT, array() );
			$tok = isset( $old['github_token'] ) ? $old['github_token'] : '';
		} elseif ( '-' === $tok ) {
			$tok = '';
		}
		$out['github_token'] = $tok;

		return $out;
	}
}
