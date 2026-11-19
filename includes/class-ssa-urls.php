<?php
defined( 'ABSPATH' ) || exit;

class SSA_URLs {

	/** Same-host http(s) URLs only. Blocks SSRF to other hosts. */
	public static function is_local( $url ) {
		$h    = wp_parse_url( $url );
		$home = wp_parse_url( home_url() );
		if ( empty( $h['host'] ) || empty( $h['scheme'] ) || empty( $home['host'] ) ) {
			return false;
		}
		if ( ! in_array( $h['scheme'], array( 'http', 'https' ), true ) ) {
			return false;
		}
		return strtolower( $h['host'] ) === strtolower( $home['host'] );
	}

	/** All URLs to capture. */
	public static function collect() {
		$s    = SSA_Settings::get();
		$urls = array( home_url( '/' ) );

		$types = array_filter( (array) $s['post_types'], 'post_type_exists' );
		if ( $types ) {
			$q = new WP_Query(
				array(
					'post_type'        => $types,
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'has_password'     => false,
					'no_found_rows'    => true,
					'orderby'          => 'type title',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);
			foreach ( $q->posts as $id ) {
				$link = get_permalink( $id );
				if ( $link ) {
					$urls[] = $link;
				}
			}
		}

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['extra_urls'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === strpos( $line, '/' ) ) {
				$line = home_url( $line );
			}
			$urls[] = esc_url_raw( $line );
		}

		$urls = array_filter( $urls, array( __CLASS__, 'is_local' ) );
		$urls = array_values( array_unique( $urls ) );

		return apply_filters( 'ssa_urls', $urls );
	}
}
