<?php
/**
 * Robot simulator bridge for the tutor agent loop.
 *
 * Runs model-written Python through edsim (the ed-robot-simulator checkout)
 * via bin/edsim_run.py. edsim executes the program in plain CPython and is
 * NOT a sandbox, and the program comes from a model that students can steer,
 * so every run happens inside a bubblewrap jail: no network, no host
 * filesystem except /usr (read-only) and the edsim checkout (read-only),
 * address-space and CPU rlimits, and a wall-clock kill.
 *
 * Server configuration (wp-config.php; never stored in the database):
 *
 *   define( 'CODEROBOTS_EDSIM_DIR', '/opt/ed-robot-simulator' );   // required
 *   define( 'CODEROBOTS_EDSIM_PYTHON', '/opt/ed-robot-simulator/.venv/bin/python' ); // optional
 *   define( 'CODEROBOTS_BWRAP', '/usr/bin/bwrap' );                 // optional
 *
 * The PHP user (www-data) must be able to read the edsim directory. The
 * Python interpreter must live under /usr or inside CODEROBOTS_EDSIM_DIR
 * (a venv there whose python symlinks into /usr is fine).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The browser went away while the simulator was running. */
class CodeRobots_Simulator_Cancelled extends RuntimeException {}

class CodeRobots_Simulator {

	/** Wall-clock limit per run (edsim itself stops at 20 s). */
	const TIMEOUT_SECONDS = 35;
	/** RLIMIT_AS for the jailed interpreter. */
	const MEMORY_BYTES = 1073741824;

	/** CodeRobots hw_mode → edsim platform. */
	const PLATFORMS = array(
		'spike'    => 'spike',
		'microbit' => 'microbit',
		'lego'     => 'csai',
	);

	/** Scenario used when the model does not pick a valid one. */
	const DEFAULT_SCENARIOS = array(
		'spike'    => 'spike_base',
		'microbit' => 'mb_cutebot',
		'csai'     => 'csai_base',
	);

	/** @var string */
	private $dir;
	/** @var string */
	private $python;
	/** @var string */
	private $bwrap;

	private function __construct( $dir, $python, $bwrap ) {
		$this->dir    = $dir;
		$this->python = $python;
		$this->bwrap  = $bwrap;
	}

	/**
	 * The configured simulator, or null when it is not set up on this server.
	 *
	 * @return CodeRobots_Simulator|null
	 */
	public static function instance() {
		$dir = defined( 'CODEROBOTS_EDSIM_DIR' ) ? untrailingslashit( (string) CODEROBOTS_EDSIM_DIR ) : '';
		if ( '' === $dir || ! is_dir( $dir . '/edsim' ) ) {
			return null;
		}
		$python = defined( 'CODEROBOTS_EDSIM_PYTHON' ) ? (string) CODEROBOTS_EDSIM_PYTHON : $dir . '/.venv/bin/python';
		$bwrap  = defined( 'CODEROBOTS_BWRAP' ) ? (string) CODEROBOTS_BWRAP : '/usr/bin/bwrap';
		if ( ! is_executable( $bwrap ) || ! function_exists( 'proc_open' ) ) {
			return null;
		}
		// Path of the interpreter as seen inside the jail.
		if ( 0 === strpos( $python, $dir . '/' ) ) {
			$python = '/edsim/' . substr( $python, strlen( $dir ) + 1 );
		} elseif ( 0 !== strpos( $python, '/usr/' ) ) {
			return null;
		}
		return new self( $dir, $python, $bwrap );
	}

	/** edsim platform name for a CodeRobots hw_mode, or null. */
	public static function platform_for( $hw_mode ) {
		return isset( self::PLATFORMS[ $hw_mode ] ) ? self::PLATFORMS[ $hw_mode ] : null;
	}

	/**
	 * Scenario presets for one edsim platform: name => description.
	 * Cached for a day per edsim checkout.
	 *
	 * @return array<string, string>
	 */
	public function scenarios( $platform ) {
		$key = 'coderobots_edsim_sc_' . md5( $this->dir . '|' . (string) @filemtime( $this->dir . '/edsim/scenarios' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$all = get_transient( $key );
		if ( ! is_array( $all ) ) {
			$out = $this->exec( array( 'cmd' => 'scenarios' ) );
			$all = isset( $out['scenarios'] ) && is_array( $out['scenarios'] ) ? $out['scenarios'] : array();
			if ( $all ) {
				set_transient( $key, $all, DAY_IN_SECONDS );
			}
		}
		$list = array();
		foreach ( $all as $name => $sc ) {
			if ( isset( $sc['platform'] ) && $sc['platform'] === $platform ) {
				$list[ (string) $name ] = isset( $sc['description'] ) ? (string) $sc['description'] : '';
			}
		}
		if ( ! $list ) {
			$list[ self::DEFAULT_SCENARIOS[ $platform ] ] = '';
		}
		return $list;
	}

	/**
	 * Run a program.
	 *
	 * @param callable|null $on_idle Called about every 10 s while waiting; return false to abort.
	 * @return array{ok:bool, text:string, error:array|null}
	 * @throws RuntimeException When the harness itself fails (not when the program fails).
	 */
	public function run( $code, $platform, $scenario, $on_idle = null ) {
		$out = $this->exec(
			array(
				'cmd'      => 'run',
				'code'     => (string) $code,
				'platform' => $platform,
				'scenario' => $scenario,
			),
			$on_idle
		);
		if ( isset( $out['harness_error'] ) ) {
			throw new RuntimeException( 'Simulator error: ' . $out['harness_error'] );
		}
		if ( ! isset( $out['text'] ) ) {
			throw new RuntimeException( 'Simulator returned no result.' );
		}
		return array(
			'ok'    => ! empty( $out['ok'] ),
			'text'  => (string) $out['text'],
			'error' => isset( $out['error'] ) && is_array( $out['error'] ) ? $out['error'] : null,
		);
	}

	/** bwrap + prlimit command line for the jailed runner. */
	private function command() {
		return array(
			$this->bwrap,
			'--ro-bind', '/usr', '/usr',
			'--symlink', 'usr/lib', '/lib',
			'--symlink', 'usr/lib64', '/lib64',
			'--symlink', 'usr/bin', '/bin',
			'--ro-bind', $this->dir, '/edsim',
			'--ro-bind', CODEROBOTS_DIR . 'bin/edsim_run.py', '/runner.py',
			'--proc', '/proc',
			'--dev', '/dev',
			'--tmpfs', '/tmp',
			'--unshare-all',
			'--die-with-parent',
			'--new-session',
			'--clearenv',
			'--setenv', 'EDSIM_DIR', '/edsim',
			'--setenv', 'PYTHONDONTWRITEBYTECODE', '1',
			'--chdir', '/tmp',
			'/usr/bin/prlimit',
			'--as=' . self::MEMORY_BYTES,
			'--cpu=' . self::TIMEOUT_SECONDS,
			'--fsize=1048576',
			'--',
			$this->python,
			'-I',
			'/runner.py',
		);
	}

	/**
	 * @return array Decoded runner output.
	 * @throws RuntimeException On spawn failure, timeout or unreadable output.
	 */
	private function exec( array $request, $on_idle = null ) {
		$pipes = array();
		$proc  = proc_open(
			$this->command(),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $proc ) ) {
			throw new RuntimeException( 'Could not start the simulator.' );
		}
		fwrite( $pipes[0], (string) wp_json_encode( $request ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout    = '';
		$stderr    = '';
		$start     = microtime( true );
		$last_ping = $start;
		$timed_out = false;
		$aborted   = false;
		while ( true ) {
			$read   = array( $pipes[1], $pipes[2] );
			$write  = null;
			$except = null;
			if ( false === stream_select( $read, $write, $except, 1 ) ) {
				break;
			}
			foreach ( $read as $r ) {
				$chunk = (string) fread( $r, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( $r === $pipes[1] ) {
					$stdout .= $chunk;
				} elseif ( strlen( $stderr ) < 4000 ) {
					$stderr .= $chunk;
				}
			}
			if ( feof( $pipes[1] ) && feof( $pipes[2] ) ) {
				break;
			}
			$now = microtime( true );
			if ( $now - $start > self::TIMEOUT_SECONDS || strlen( $stdout ) > 1000000 ) {
				$timed_out = true;
				break;
			}
			if ( $on_idle && $now - $last_ping >= 10 ) {
				$last_ping = $now;
				if ( false === call_user_func( $on_idle ) ) {
					$aborted = true;
					break;
				}
			}
		}
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $timed_out || $aborted ) {
			proc_terminate( $proc, 9 );
		}
		proc_close( $proc );

		if ( $aborted ) {
			throw new CodeRobots_Simulator_Cancelled( 'Request cancelled.' );
		}
		if ( $timed_out ) {
			throw new RuntimeException( 'The simulator took too long and was stopped.' );
		}
		$out = json_decode( $stdout, true );
		if ( ! is_array( $out ) ) {
			throw new RuntimeException( 'Simulator failed: ' . trim( substr( '' !== $stderr ? $stderr : $stdout, 0, 300 ) ) );
		}
		return $out;
	}
}
