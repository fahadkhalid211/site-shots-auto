<?php
defined( 'ABSPATH' ) || exit;

class SSA_Queue {

	const Q   = 'ssa_queue';
	const IDX = 'ssa_index';

	public static function init() {
		add_action( 'ssa_scheduled', array( __CLASS__, 'scheduled' ) );
		add_action( 'ssa_cron_step', array( __CLASS__, 'cron_step' ) );
		add_action( 'update_option_' . SSA_Settings::OPT, array( __CLASS__, 'reschedule' ) );
		add_action( 'add_option_' . SSA_Settings::OPT, array( __CLASS__, 'reschedule' ) );
	}

	public static function start( array $urls ) {
		update_option(
			self::Q,
			array(
				'items' => array_values( $urls ),
				'total' => count( $urls ),
				'done'  => 0,
			),
			false
		);
	}

	/** Process one URL from queue. */
	public static function step() {
		$q = get_option( self::Q, array() );
		if ( empty( $q['items'] ) ) {
			$t = isset( $q['total'] ) ? (int) $q['total'] : 0;
			return array( 'finished' => true, 'done' => $t, 'total' => $t, 'url' => '', 'ok' => true, 'msg' => '' );
		}

		$url = array_shift( $q['items'] );
		$r   = SSA_Capture::run( $url );
		self::record( $url, $r );

		$q['done'] = isset( $q['done'] ) ? (int) $q['done'] + 1 : 1;
		update_option( self::Q, $q, false );

		return array(
			'finished' => empty( $q['items'] ),
			'done'     => $q['done'],
			'total'    => (int) $q['total'],
			'url'      => $url,
			'ok'       => $r['ok'],
			'msg'      => $r['msg'],
		);
	}

	private static function record( $url, $r ) {
		$idx = get_option( self::IDX, array() );
		$k   = md5( $url );
		$old = isset( $idx[ $k ] ) ? $idx[ $k ] : array();

		$idx[ $k ] = array(
			'url'    => $url,
			'file'   => $r['ok'] ? $r['file'] : ( isset( $old['file'] ) ? $old['file'] : '' ),
			'time'   => $r['ok'] ? time() : ( isset( $old['time'] ) ? $old['time'] : 0 ),
			'status' => $r['ok'] ? 'ok' : 'error',
			'msg'    => $r['ok'] ? '' : $r['msg'],
		);
		update_option( self::IDX, $idx, false );
	}

	/* ---------- WP-Cron ---------- */

	public static function reschedule() {
		wp_clear_scheduled_hook( 'ssa_scheduled' );
		$s = SSA_Settings::get();
		if ( in_array( $s['schedule'], array( 'daily', 'weekly' ), true ) ) {
			wp_schedule_event( time() + 300, $s['schedule'], 'ssa_scheduled' );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( 'ssa_scheduled' );
		wp_clear_scheduled_hook( 'ssa_cron_step' );
	}

	public static function scheduled() {
		$urls = SSA_URLs::collect();
		if ( ! $urls || true !== SSA_Capture::prepare() ) {
			return;
		}
		self::start( $urls );
		wp_schedule_single_event( time() + 5, 'ssa_cron_step' );
	}

	public static function cron_step() {
		$r = self::step();
		if ( empty( $r['finished'] ) ) {
			wp_schedule_single_event( time() + 5, 'ssa_cron_step' );
		}
	}
}
