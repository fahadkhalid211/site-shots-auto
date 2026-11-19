<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public endpoint: lists page URLs + capture settings.
 * Same info as your sitemap. Read by the GitHub workflow.
 */
class SSA_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'site-shots/v1',
			'/urls',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'urls' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function urls() {
		$s = SSA_Settings::get();
		return rest_ensure_response(
			array(
				'site'    => home_url(),
				'urls'    => SSA_URLs::collect(),
				'width'   => (int) $s['width'],
				'wait_ms' => (int) $s['wait_ms'],
				'format'  => $s['format'],
				'hide'    => SSA_Capture::hide_list( $s ),
			)
		);
	}
}
