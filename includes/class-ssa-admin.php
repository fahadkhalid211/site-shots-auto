<?php
defined( 'ABSPATH' ) || exit;

class SSA_Admin {

	const SLUG = 'site-shots-auto';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ssa_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_ssa_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_ssa_gh_run', array( __CLASS__, 'ajax_gh_run' ) );
		add_action( 'admin_post_ssa_zip', array( __CLASS__, 'do_zip' ) );
		add_action( 'admin_post_ssa_csv', array( __CLASS__, 'do_csv' ) );
		add_action( 'admin_post_ssa_clear', array( __CLASS__, 'do_clear' ) );
	}

	public static function menu() {
		add_management_page( 'Site Shots', 'Site Shots', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'ssa_group',
			SSA_Settings::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'SSA_Settings', 'sanitize' ),
			)
		);
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ssa-admin', SSA_URL . 'assets/admin.css', array(), SSA_VER );
		wp_enqueue_script( 'ssa-admin', SSA_URL . 'assets/admin.js', array( 'jquery' ), SSA_VER, true );
		wp_localize_script(
			'ssa-admin',
			'SSA',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ssa_nonce' ),
			)
		);
	}

	/* ---------- AJAX ---------- */

	public static function ajax_start() {
		check_ajax_referer( 'ssa_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No access.' );
		}
		$one = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' !== $one ) {
			if ( ! SSA_URLs::is_local( $one ) ) {
				wp_send_json_error( 'URL must belong to this site.' );
			}
			$urls = array( $one );
		} else {
			$urls = SSA_URLs::collect();
		}
		if ( ! $urls ) {
			wp_send_json_error( 'No URLs found.' );
		}
		$ready = SSA_Capture::prepare();
		if ( true !== $ready ) {
			wp_send_json_error( $ready );
		}
		SSA_Queue::start( $urls );
		wp_send_json_success( array( 'total' => count( $urls ) ) );
	}

	public static function ajax_gh_run() {
		check_ajax_referer( 'ssa_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No access.' );
		}
		$r = SSA_GitHub::dispatch();
		if ( true !== $r ) {
			wp_send_json_error( $r );
		}
		wp_send_json_success( 'Workflow started on GitHub. Wait a few minutes, then click Sync.' );
	}

	public static function ajax_step() {
		check_ajax_referer( 'ssa_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No access.' );
		}
		wp_send_json_success( SSA_Queue::step() );
	}

	/* ---------- Downloads ---------- */

	private static function csv_string() {
		$idx  = get_option( SSA_Queue::IDX, array() );
		$base = SSA_Capture::dir_url();
		$f    = fopen( 'php://temp', 'w+' );
		fputcsv( $f, array( 'page_url', 'image_url', 'file', 'captured_at', 'status' ), ',', '"', '' );
		foreach ( $idx as $r ) {
			fputcsv(
				$f,
				array(
					$r['url'],
					$r['file'] ? $base . $r['file'] : '',
					$r['file'],
					$r['time'] ? gmdate( 'c', $r['time'] ) : '',
					$r['status'],
				),
				',',
				'"',
				''
			);
		}
		rewind( $f );
		$c = stream_get_contents( $f );
		fclose( $f );
		return $c;
	}

	public static function do_csv() {
		check_admin_referer( 'ssa_csv' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No access.' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="site-shots-map.csv"' );
		echo self::csv_string(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function do_zip() {
		check_admin_referer( 'ssa_zip' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No access.' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( 'PHP ZipArchive extension missing.' );
		}
		$dir = SSA_Capture::dir();
		$tmp = wp_tempnam( 'ssa' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( 'Could not create ZIP.' );
		}
		foreach ( get_option( SSA_Queue::IDX, array() ) as $r ) {
			if ( ! empty( $r['file'] ) && is_file( $dir . $r['file'] ) ) {
				$zip->addFile( $dir . $r['file'], $r['file'] );
			}
		}
		$zip->addFromString( 'url-map.csv', self::csv_string() );
		$zip->close();

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="site-shots.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp );
		@unlink( $tmp );
		exit;
	}

	public static function do_clear() {
		check_admin_referer( 'ssa_clear' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No access.' );
		}
		$dir = SSA_Capture::dir();
		foreach ( (array) glob( $dir . '*.{png,jpg}', GLOB_BRACE ) as $f ) {
			@unlink( $f );
		}
		delete_option( SSA_Queue::IDX );
		delete_option( SSA_Queue::Q );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}

	/* ---------- Pages ---------- */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'shots'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'shots'    => 'Screenshots',
			'settings' => 'Settings',
			'status'   => 'Status',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'shots';
		}

		echo '<div class="wrap"><h1>Site Shots Auto</h1><h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( admin_url( 'tools.php?page=' . self::SLUG . '&tab=' . $k ) ),
				$k === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( 'settings' === $tab ) {
			self::tab_settings();
		} elseif ( 'status' === $tab ) {
			self::tab_status();
		} else {
			self::tab_shots();
		}
		echo '</div>';
	}

	private static function tab_shots() {
		$urls = SSA_URLs::collect();
		$idx  = get_option( SSA_Queue::IDX, array() );
		$dir  = SSA_Capture::dir();
		$base = SSA_Capture::dir_url();

		$zip   = wp_nonce_url( admin_url( 'admin-post.php?action=ssa_zip' ), 'ssa_zip' );
		$csv   = wp_nonce_url( admin_url( 'admin-post.php?action=ssa_csv' ), 'ssa_csv' );
		$clear = wp_nonce_url( admin_url( 'admin-post.php?action=ssa_clear' ), 'ssa_clear' );

		$gh = 'github' === SSA_Settings::get()['engine'];
		echo '<p class="ssa-bar">';
		if ( $gh ) {
			echo '<button type="button" class="button" id="ssa-gh-run">1. Run capture on GitHub</button> ';
			echo '<button type="button" class="button button-primary" id="ssa-all">2. Sync images (' . (int) count( $urls ) . ')</button> ';
		} else {
			echo '<button type="button" class="button button-primary" id="ssa-all">Capture all pages (' . (int) count( $urls ) . ')</button> ';
		}
		echo '<a class="button" href="' . esc_url( $zip ) . '">Download ZIP</a> ';
		echo '<a class="button" href="' . esc_url( $csv ) . '">Export URL map (CSV)</a> ';
		echo '<a class="button" href="' . esc_url( $clear ) . '" onclick="return confirm(\'Delete all screenshots?\');">Delete all</a>';
		echo '</p>';

		echo '<div id="ssa-progress" style="display:none"><div class="ssa-track"><div class="ssa-fill"></div></div><p class="ssa-text"></p></div>';
		echo '<p class="description">Files saved in <code>wp-content/uploads/site-shots/</code>. Keep this page open during manual runs.' . ( $gh ? ' GitHub engine: step 1 makes screenshots (3-15 min). Step 2 downloads them here.' : '' ) . '</p>';

		echo '<table class="widefat striped ssa-table"><thead><tr><th>Preview</th><th>Page URL</th><th>Image</th><th>Status</th><th></th></tr></thead><tbody>';
		foreach ( $urls as $u ) {
			$r   = isset( $idx[ md5( $u ) ] ) ? $idx[ md5( $u ) ] : null;
			$has = $r && ! empty( $r['file'] ) && is_file( $dir . $r['file'] );
			echo '<tr>';
			echo '<td>';
			if ( $has ) {
				$img = $base . $r['file'] . '?v=' . (int) $r['time'];
				echo '<a href="' . esc_url( $img ) . '" target="_blank" rel="noopener"><img class="ssa-thumb" src="' . esc_url( $img ) . '" alt=""></a>';
			}
			echo '</td>';
			echo '<td><a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( $u ) . '</a></td>';
			echo '<td>' . ( $has ? '<a href="' . esc_url( $base . $r['file'] ) . '" target="_blank" rel="noopener"><code>' . esc_html( $r['file'] ) . '</code></a>' : '&mdash;' ) . '</td>';
			echo '<td>';
			if ( ! $r ) {
				echo 'Not captured';
			} elseif ( 'ok' === $r['status'] ) {
				echo '&#10003; ' . esc_html( wp_date( 'Y-m-d H:i', (int) $r['time'] ) );
			} else {
				echo '<span class="ssa-err">Failed: ' . esc_html( $r['msg'] ) . '</span>';
			}
			echo '</td>';
			echo '<td><button type="button" class="button ssa-one" data-url="' . esc_attr( $u ) . '">' . ( $gh ? 'Re-sync' : 'Recapture' ) . '</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function tab_settings() {
		$s = SSA_Settings::get();
		$o = SSA_Settings::OPT;

		echo '<form method="post" action="options.php">';
		settings_fields( 'ssa_group' );
		echo '<table class="form-table" role="presentation">';

		// Engine.
		echo '<tr><th>Engine</th><td><select name="' . esc_attr( $o ) . '[engine]">';
		$engines = array(
			'puppeteer' => 'Puppeteer-core (full page, best, needs Node + Chrome/Edge)',
			'chrome'    => 'Chrome CLI (tall window, needs Chrome on server)',
			'mshots'    => 'WordPress mShots (free web service, viewport only, public sites)',
			'github'    => 'GitHub Actions (full page, free, works on shared hosting)',
		);
		foreach ( $engines as $k => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $s['engine'], $k, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		self::row_text( $o, 'github_repo', 'GitHub repo', $s['github_repo'], 'GitHub engine. Format: username/repo' );
		self::row_text( $o, 'github_branch', 'GitHub output branch', $s['github_branch'], 'Default: shots' );
		self::row_text( $o, 'github_ref', 'Workflow branch', $s['github_ref'], 'Branch holding the workflow file. Usually main.' );
		printf(
			'<tr><th>GitHub token</th><td><input type="password" class="regular-text" autocomplete="new-password" name="%s[github_token]" value="" placeholder="%s"><p class="description">Optional. Needed for: "Run capture on GitHub" button, private repos. Fine-grained token. Repo access: this repo only. Permissions: Actions = read/write, Contents = read. Type - to clear.</p></td></tr>',
			esc_attr( $o ),
			'' !== $s['github_token'] ? esc_attr( '(saved)' ) : ''
		);
		self::row_text( $o, 'node_path', 'Node path', $s['node_path'], 'Default: node' );
		self::row_text( $o, 'chrome_path', 'Chrome path', $s['chrome_path'], 'Empty = auto-detect Chrome, Chromium or Edge.' );
		self::row_num( $o, 'width', 'Viewport width (px)', $s['width'] );
		self::row_num( $o, 'max_height', 'Max height (px)', $s['max_height'], 'Chrome CLI engine only.' );
		self::row_num( $o, 'wait_ms', 'Extra wait (ms)', $s['wait_ms'], 'Time for animations and lazy content.' );
		self::row_num( $o, 'timeout', 'Timeout per page (sec)', $s['timeout'] );

		echo '<tr><th>Format</th><td><select name="' . esc_attr( $o ) . '[format]">';
		printf( '<option value="png" %s>PNG</option>', selected( $s['format'], 'png', false ) );
		printf( '<option value="jpg" %s>JPG</option>', selected( $s['format'], 'jpg', false ) );
		echo '</select> <span class="description">Puppeteer only.</span></td></tr>';

		echo '<tr><th>Post types</th><td>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			printf(
				'<label style="margin-right:14px"><input type="checkbox" name="%s[post_types][]" value="%s" %s> %s</label>',
				esc_attr( $o ),
				esc_attr( $pt->name ),
				checked( in_array( $pt->name, (array) $s['post_types'], true ), true, false ),
				esc_html( $pt->labels->name )
			);
		}
		echo '</td></tr>';

		echo '<tr><th>Extra URLs</th><td><textarea name="' . esc_attr( $o ) . '[extra_urls]" rows="5" class="large-text code">' . esc_textarea( $s['extra_urls'] ) . '</textarea>';
		echo '<p class="description">One per line. Same domain only. Paths like <code>/shop/</code> work.</p></td></tr>';

		echo '<tr><th>Hide elements</th><td><textarea name="' . esc_attr( $o ) . '[hide_selectors]" rows="6" class="large-text code">' . esc_textarea( $s['hide_selectors'] ) . '</textarea>';
		echo '<p class="description">CSS selectors, one per line. Hidden before capture. Puppeteer only.</p></td></tr>';

		echo '<tr><th>Auto schedule</th><td><select name="' . esc_attr( $o ) . '[schedule]">';
		foreach ( array( 'off' => 'Off', 'daily' => 'Daily', 'weekly' => 'Weekly' ) as $k => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $s['schedule'], $k, false ), esc_html( $label ) );
		}
		echo '</select> <span class="description">Uses WP-Cron. Use a real server cron for reliability.</span></td></tr>';

		echo '</table>';
		submit_button();
		echo '</form>';
	}

	private static function row_text( $o, $key, $label, $val, $desc = '' ) {
		printf(
			'<tr><th>%s</th><td><input type="text" class="regular-text" name="%s[%s]" value="%s">%s</td></tr>',
			esc_html( $label ),
			esc_attr( $o ),
			esc_attr( $key ),
			esc_attr( $val ),
			$desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : ''
		);
	}

	private static function row_num( $o, $key, $label, $val, $desc = '' ) {
		printf(
			'<tr><th>%s</th><td><input type="number" class="small-text" name="%s[%s]" value="%d">%s</td></tr>',
			esc_html( $label ),
			esc_attr( $o ),
			esc_attr( $key ),
			(int) $val,
			$desc ? ' <span class="description">' . esc_html( $desc ) . '</span>' : ''
		);
	}

	private static function tab_status() {
		$s        = SSA_Settings::get();
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		$proc_ok  = function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled, true );
		$node_v   = $proc_ok ? SSA_Capture::exec( array( $s['node_path'], '-v' ), 10, SSA_DIR ) : '';
		$node_ok  = (bool) preg_match( '/^v\d+/', $node_v );
		$pup_ok   = is_dir( SSA_DIR . 'bin/node_modules/puppeteer-core' );
		$chrome   = SSA_Capture::find_chrome( $s );

		$rows = array(
			array( 'proc_open available', $proc_ok, $proc_ok ? 'Yes' : 'Disabled. Puppeteer and Chrome engines cannot run.' ),
			array( 'Node.js', $node_ok, $node_ok ? $node_v : 'Not found. Set Node path.' ),
			array( 'puppeteer-core installed', $pup_ok, $pup_ok ? 'Yes' : 'Run: cd ' . SSA_DIR . 'bin && npm install' ),
			array( 'Chrome / Chromium', '' !== $chrome, '' !== $chrome ? $chrome : 'Not found. Install Chrome or Edge, or set Chrome path.' ),
			array( 'ZipArchive (ZIP download)', class_exists( 'ZipArchive' ), class_exists( 'ZipArchive' ) ? 'Yes' : 'Missing' ),
			array( 'Uploads writable', wp_is_writable( SSA_Capture::dir() ), SSA_Capture::dir() ),
			array( 'WP-Cron enabled', ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ), ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'Disabled. Trigger wp-cron.php from server cron.' : 'Yes' ),
		);

		if ( 'github' === $s['engine'] ) {
			$rows = array_values( array_filter( $rows, function ( $r ) {
				return ! in_array( $r[0], array( 'Node.js', 'puppeteer-core installed', 'Chrome / Chromium', 'proc_open available' ), true );
			} ) );
		}
		echo '<table class="widefat striped" style="max-width:820px"><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td style="width:240px"><strong>%s</strong></td><td style="width:30px">%s</td><td><code>%s</code></td></tr>',
				esc_html( $r[0] ),
				$r[1] ? '<span style="color:#1a7f37">&#10003;</span>' : '<span style="color:#b32d2e">&#10007;</span>',
				esc_html( $r[2] )
			);
		}
		if ( 'github' === $s['engine'] ) {
			$ok   = SSA_GitHub::prepare();
			$c    = SSA_GitHub::cached();
			$rest = rest_url( 'site-shots/v1/urls' );
			printf( '<tr><td><strong>GitHub repo</strong></td><td>%s</td><td><code>%s</code></td></tr>', $s['github_repo'] ? '<span style="color:#1a7f37">&#10003;</span>' : '<span style="color:#b32d2e">&#10007;</span>', esc_html( $s['github_repo'] ? $s['github_repo'] : 'Not set' ) );
			printf( '<tr><td><strong>GitHub images found</strong></td><td>%s</td><td><code>%s</code></td></tr>', true === $ok ? '<span style="color:#1a7f37">&#10003;</span>' : '<span style="color:#b32d2e">&#10007;</span>', esc_html( true === $ok ? 'Generated ' . ( isset( $c['generated'] ) ? $c['generated'] : '' ) : $ok ) );
			printf( '<tr><td><strong>Token saved</strong></td><td>%s</td><td><code>%s</code></td></tr>', '' !== $s['github_token'] ? '<span style="color:#1a7f37">&#10003;</span>' : '&ndash;', esc_html( '' !== $s['github_token'] ? 'Yes' : 'No. Optional.' ) );
			printf( '<tr><td><strong>URL list endpoint</strong></td><td></td><td><a href="%1$s" target="_blank" rel="noopener"><code>%1$s</code></a></td></tr>', esc_url( $rest ) );
		}
		echo '</tbody></table>';
		echo '<p class="description">Current engine: <strong>' . esc_html( $s['engine'] ) . '</strong>. No shell access? Pick mShots.</p>';
	}
}
