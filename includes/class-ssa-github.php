<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pulls screenshots made by GitHub Actions (free) into WordPress.
 */
class SSA_GitHub {

	const TRANSIENT = 'ssa_gh_index';

	private static function norm( $url ) {
		return untrailingslashit( preg_replace( '/#.*$/', '', (string) $url ) );
	}

	public static function configured() {
		$s = SSA_Settings::get();
		return '' !== $s['github_repo'];
	}

	/** GET a file from repo branch. Returns body string or WP_Error. */
	public static function get_file( $path ) {
		$s    = SSA_Settings::get();
		$repo = $s['github_repo'];
		if ( '' === $repo ) {
			return new WP_Error( 'ssa', 'GitHub repo not set in Settings.' );
		}
		$branch = $s['github_branch'];
		$args   = array(
			'timeout'    => 60,
			'user-agent' => 'site-shots-auto',
			'headers'    => array(),
		);
		if ( '' !== $s['github_token'] ) {
			$url                           = 'https://api.github.com/repos/' . $repo . '/contents/' . $path . '?ref=' . rawurlencode( $branch );
			$args['headers']['Authorization'] = 'Bearer ' . $s['github_token'];
			$args['headers']['Accept']        = 'application/vnd.github.raw+json';
		} else {
			$url = 'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode( $branch ) . '/' . $path;
		}
		$r = wp_remote_get( $url, $args );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 200 !== $code ) {
			$hint = 404 === $code ? ' Run the GitHub workflow first. Private repo needs token.' : '';
			return new WP_Error( 'ssa', 'GitHub returned HTTP ' . $code . ' for ' . $path . '.' . $hint );
		}
		return wp_remote_retrieve_body( $r );
	}

	/** Fetch shots/index.json and cache map. Returns true or error string. */
	public static function prepare() {
		$body = self::get_file( 'shots/index.json' );
		if ( is_wp_error( $body ) ) {
			return $body->get_error_message();
		}
		$j = json_decode( $body, true );
		if ( ! is_array( $j ) || empty( $j['items'] ) ) {
			return 'index.json empty or invalid.';
		}
		$map = array();
		foreach ( $j['items'] as $it ) {
			if ( ! empty( $it['url'] ) ) {
				$map[ self::norm( $it['url'] ) ] = $it;
			}
		}
		set_transient(
			self::TRANSIENT,
			array(
				'generated' => isset( $j['generated'] ) ? $j['generated'] : '',
				'map'       => $map,
			),
			30 * MINUTE_IN_SECONDS
		);
		return true;
	}

	public static function cached() {
		$c = get_transient( self::TRANSIENT );
		return is_array( $c ) ? $c : array();
	}

	public static function item( $url ) {
		$c = self::cached();
		if ( empty( $c['map'] ) ) {
			if ( true !== self::prepare() ) {
				return null;
			}
			$c = self::cached();
		}
		$k = self::norm( $url );
		return isset( $c['map'][ $k ] ) ? $c['map'][ $k ] : null;
	}

	/** Trigger workflow_dispatch. Needs token. Returns true or error string. */
	public static function dispatch() {
		$s = SSA_Settings::get();
		if ( '' === $s['github_repo'] ) {
			return 'GitHub repo not set.';
		}
		if ( '' === $s['github_token'] ) {
			return 'Token needed to start workflow from WordPress. Or click "Run workflow" on GitHub.';
		}
		$r = wp_remote_post(
			'https://api.github.com/repos/' . $s['github_repo'] . '/actions/workflows/site-shots.yml/dispatches',
			array(
				'timeout'    => 30,
				'user-agent' => 'site-shots-auto',
				'headers'    => array(
					'Authorization'        => 'Bearer ' . $s['github_token'],
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'Content-Type'         => 'application/json',
				),
				'body'       => wp_json_encode( array( 'ref' => $s['github_ref'] ) ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 204 === $code ) {
			return true;
		}
		$msg = json_decode( wp_remote_retrieve_body( $r ), true );
		return 'GitHub HTTP ' . $code . ( is_array( $msg ) && ! empty( $msg['message'] ) ? ': ' . $msg['message'] : '' );
	}
}
