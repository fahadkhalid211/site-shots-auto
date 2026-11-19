<?php
defined( 'ABSPATH' ) || exit;

class SSA_Capture {

	public static function dir() {
		$u = wp_upload_dir();
		$d = trailingslashit( $u['basedir'] ) . 'site-shots/';
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
			@file_put_contents( $d . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		return $d;
	}

	public static function dir_url() {
		$u = wp_upload_dir();
		return trailingslashit( $u['baseurl'] ) . 'site-shots/';
	}

	public static function ext( $s ) {
		switch ( $s['engine'] ) {
			case 'chrome':
				return 'png';
			case 'mshots':
				return 'jpg';
			default:
				return 'jpg' === $s['format'] ? 'jpg' : 'png';
		}
	}

	public static function slug( $url ) {
		$p    = wp_parse_url( $url );
		$path = trim( isset( $p['path'] ) ? $p['path'] : '', '/' );
		$slug = '' === $path ? 'home' : str_replace( '/', '__', $path );
		$slug = sanitize_file_name( $slug );
		if ( ! empty( $p['query'] ) ) {
			$slug .= '-' . substr( md5( $p['query'] ), 0, 6 );
		}
		return '' !== $slug ? $slug : 'page-' . substr( md5( $url ), 0, 8 );
	}

	public static function hide_list( $s ) {
		$list = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['hide_selectors'] ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$list[] = $line;
			}
		}
		return $list;
	}

	public static function find_chrome( $s ) {
		if ( ! empty( $s['chrome_path'] ) ) {
			return $s['chrome_path'];
		}
		$paths = array();
		foreach ( array( 'LOCALAPPDATA', 'PROGRAMFILES', 'PROGRAMFILES(X86)' ) as $env ) {
			$base = getenv( $env );
			if ( $base ) {
				$paths[] = $base . '\\Google\\Chrome\\Application\\chrome.exe';
				$paths[] = $base . '\\Microsoft\\Edge\\Application\\msedge.exe';
				$paths[] = $base . '\\Chromium\\Application\\chrome.exe';
			}
		}
		$paths = array_merge(
			$paths,
			array(
			'/usr/bin/google-chrome',
			'/usr/bin/google-chrome-stable',
			'/usr/bin/chromium',
			'/usr/bin/chromium-browser',
			'/snap/bin/chromium',
			'/usr/bin/microsoft-edge',
			'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
			'/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
			'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
			'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
			'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
			)
		);
		foreach ( $paths as $p ) {
			if ( @is_file( $p ) ) {
				return $p;
			}
		}
		return '';
	}

	/** Run a command without a shell. Returns combined output string. */
	public static function exec( array $cmd, $timeout, $cwd = null ) {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( ! function_exists( 'proc_open' ) || in_array( 'proc_open', $disabled, true ) ) {
			return 'proc_open disabled on this host.';
		}
		$pipes = array();
		$proc  = @proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd );
		if ( ! is_resource( $proc ) ) {
			return 'Could not start process.';
		}
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$out   = '';
		$start = time();
		while ( true ) {
			$out .= stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			$st   = proc_get_status( $proc );
			if ( ! $st['running'] ) {
				break;
			}
			if ( time() - $start > $timeout ) {
				proc_terminate( $proc, 9 );
				$out .= ' [timeout]';
				break;
			}
			usleep( 100000 );
		}
		$out .= stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
		return trim( $out );
	}

	/** Run before a batch. Returns true or error string. */
	public static function prepare() {
		$s = SSA_Settings::get();
		if ( 'github' === $s['engine'] ) {
			return SSA_GitHub::prepare();
		}
		return true;
	}

	/** Capture one URL. Returns array( ok, file, msg ). */
	public static function run( $url ) {
		$s   = SSA_Settings::get();
		$dir = self::dir();
		$ext = self::ext( $s );

		$gh_item = null;
		if ( 'github' === $s['engine'] ) {
			$gh_item = SSA_GitHub::item( $url );
			if ( ! $gh_item || empty( $gh_item['ok'] ) || empty( $gh_item['file'] ) ) {
				$why = ( $gh_item && ! empty( $gh_item['error'] ) ) ? $gh_item['error'] : 'Not captured on GitHub yet. Run workflow.';
				return array( 'ok' => false, 'file' => '', 'msg' => $why );
			}
			$ext = strtolower( pathinfo( $gh_item['file'], PATHINFO_EXTENSION ) ) === 'jpg' ? 'jpg' : 'png';
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) $s['timeout'] + 30 );
		}

		$name  = self::slug( $url ) . '.' . $ext;
		$final = $dir . $name;
		$tmp   = $dir . 'tmp-' . wp_generate_password( 8, false ) . '.' . $ext;

		switch ( $s['engine'] ) {
			case 'chrome':
				$out = self::via_chrome( $url, $tmp, $s );
				break;
			case 'mshots':
				$out = self::via_mshots( $url, $tmp, $s );
				break;
			case 'github':
				$out = self::via_github( $gh_item, $tmp );
				break;
			default:
				$out = self::via_puppeteer( $url, $tmp, $s, $ext );
		}

		if ( is_file( $tmp ) && filesize( $tmp ) > 1000 ) {
			if ( ! @rename( $tmp, $final ) ) {
				@copy( $tmp, $final );
				@unlink( $tmp );
			}
			return array( 'ok' => true, 'file' => $name, 'msg' => '' );
		}

		@unlink( $tmp );
		$out = trim( (string) $out );
		if ( strlen( $out ) > 300 ) {
			$out = '...' . substr( $out, -300 );
		}
		return array( 'ok' => false, 'file' => '', 'msg' => '' !== $out ? $out : 'Capture failed.' );
	}

	private static function via_puppeteer( $url, $tmp, $s, $ext ) {
		if ( ! is_dir( SSA_DIR . 'bin/node_modules/puppeteer-core' ) ) {
			return 'Missing node_modules. Run "npm install" inside plugin bin folder.';
		}
		$chrome = self::find_chrome( $s );
		if ( '' === $chrome ) {
			return 'Chrome, Chromium or Edge not found. Install one, or set Chrome path in Settings.';
		}
		$nav_ms = max( 15000, ( (int) $s['timeout'] - 15 ) * 1000 );
		$cmd    = array(
			$s['node_path'],
			SSA_DIR . 'bin/capture.js',
			'--url=' . $url,
			'--out=' . $tmp,
			'--width=' . (int) $s['width'],
			'--wait=' . (int) $s['wait_ms'],
			'--format=' . ( 'jpg' === $ext ? 'jpg' : 'png' ),
			'--hide=' . implode( '||', self::hide_list( $s ) ),
			'--timeout=' . $nav_ms,
			'--chrome=' . $chrome,
		);
		$out = self::exec( $cmd, (int) $s['timeout'], SSA_DIR . 'bin' );
		if ( '' === $out ) {
			$out = 'No output. Check Node path in Settings (Status tab).';
		}
		return $out;
	}

	private static function via_chrome( $url, $tmp, $s ) {
		$bin = self::find_chrome( $s );
		if ( '' === $bin ) {
			return 'Chrome or Chromium not found. Set path in settings.';
		}
		$cmd = array(
			$bin,
			'--headless=new',
			'--disable-gpu',
			'--no-sandbox',
			'--disable-dev-shm-usage',
			'--hide-scrollbars',
			'--force-device-scale-factor=1',
			'--user-data-dir=' . trailingslashit( get_temp_dir() ) . 'ssa-chrome-profile',
			'--window-size=' . (int) $s['width'] . ',' . (int) $s['max_height'],
			'--virtual-time-budget=' . max( 3000, (int) $s['wait_ms'] + 5000 ),
			'--screenshot=' . $tmp,
			$url,
		);
		return self::exec( $cmd, (int) $s['timeout'] );
	}

	/** Image made by GitHub Actions. Download into uploads. */
	private static function via_github( $item, $tmp ) {
		$body = SSA_GitHub::get_file( 'shots/' . rawurlencode( $item['file'] ) );
		if ( is_wp_error( $body ) ) {
			return $body->get_error_message();
		}
		file_put_contents( $tmp, $body );
		return 'ok';
	}

	/** Free WordPress.com mshots. Viewport only. Public sites only. */
	private static function via_mshots( $url, $tmp, $s ) {
		$api      = 'https://s.wordpress.com/mshots/v1/' . rawurlencode( $url ) . '?w=' . (int) $s['width'];
		$deadline = time() + max( 30, (int) $s['timeout'] );
		$last     = 'no response';
		do {
			$r = wp_remote_get( $api, array( 'timeout' => 30, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 (compatible; SiteShotsAuto)' ) );
			if ( is_wp_error( $r ) ) {
				return $r->get_error_message();
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$ct   = strtolower( (string) wp_remote_retrieve_header( $r, 'content-type' ) );
			$body = wp_remote_retrieve_body( $r );
			$last = 'HTTP ' . $code . ' ' . $ct . ' ' . strlen( $body ) . ' bytes';
			// Real shot = jpeg/png. Placeholder "generating" = gif.
			if ( 200 === $code && ( 0 === strpos( $ct, 'image/jpeg' ) || 0 === strpos( $ct, 'image/png' ) || 0 === strpos( $ct, 'image/webp' ) ) && strlen( $body ) > 3000 ) {
				file_put_contents( $tmp, $body );
				return 'ok';
			}
			sleep( 4 );
		} while ( time() < $deadline );
		return 'mShots still generating (' . $last . '). Click Recapture again in 1 min. Site must be public.';
	}
}
