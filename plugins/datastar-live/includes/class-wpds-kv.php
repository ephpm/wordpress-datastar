<?php
/**
 * WPDS_KV — the plugin's bridge onto ePHPm's KV store (the WP→SSE event bus).
 *
 * Three transports:
 *
 *  1. 'ephpm'  — native ephpm_kv_* SAPI functions, used ONLY when
 *     DATASTAR_LIVE_KV_HOST is 'native' (or unset). That is the
 *     single-instance deployment where WordPress runs inside the same
 *     ePHPm that serves the SSE streams. It must NOT be auto-detected:
 *     in the two-container topology the WP container is ALSO an ePHPm,
 *     so the functions exist — but they'd write into the WP container's
 *     own local store, which nobody is streaming from (found the hard
 *     way during validation).
 *  2. 'predis' — Predis\Client, if some other plugin (e.g. redis-cache)
 *     already autoloaded it. Points at the SSE container's RESP listener.
 *  3. 'resp'   — a ~60-line raw RESP2 client over fsockopen(). Zero
 *     dependencies; this is the path the two-container demo exercises.
 *     ePHPm's [kv.redis_compat] listener speaks enough of the Redis
 *     protocol for everything we need (GET/SET/SETEX/INCR/AUTH).
 *
 * All failures are swallowed (logged via error_log): the live layer is an
 * enhancement — a KV outage must never break comment posting.
 */

defined( 'ABSPATH' ) || exit;

final class WPDS_KV {

	private static ?WPDS_KV $instance = null;

	/** 'ephpm' | 'predis' | 'resp' | 'none' */
	private string $backend = 'none';

	/** @var \Predis\Client|null */
	private $predis = null;

	/** @var resource|null RESP socket (lazily opened, per-request). */
	private $sock = null;

	private string $host;
	private int $port;
	private string $password;

	public static function instance(): WPDS_KV {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->host     = defined( 'DATASTAR_LIVE_KV_HOST' ) ? DATASTAR_LIVE_KV_HOST : '';
		$this->port     = defined( 'DATASTAR_LIVE_KV_PORT' ) ? DATASTAR_LIVE_KV_PORT : 6379;
		$this->password = defined( 'DATASTAR_LIVE_KV_PASSWORD' ) ? DATASTAR_LIVE_KV_PASSWORD : '';

		$want_native = ( '' === $this->host || 'native' === $this->host );
		if ( $want_native && function_exists( 'ephpm_kv_incr' ) && function_exists( 'ephpm_kv_set' ) ) {
			// Same-instance deployment: WP runs inside the ePHPm that owns
			// the store the SSE streams read. Opt-in via
			// DATASTAR_LIVE_KV_HOST = 'native' (or leaving it unset).
			$this->backend = 'ephpm';
		} elseif ( class_exists( '\Predis\Client' ) ) {
			$this->backend = 'predis';
			$this->predis  = new \Predis\Client(
				array(
					'host'    => $this->host,
					'port'    => $this->port,
					'timeout' => 0.5,
				) + ( '' !== $this->password ? array( 'password' => $this->password ) : array() )
			);
		} else {
			$this->backend = 'resp';
		}
	}

	public function backend(): string {
		return $this->backend;
	}

	/** INCR — returns the new value, or null on failure. */
	public function incr( string $key ): ?int {
		try {
			switch ( $this->backend ) {
				case 'ephpm':
					$v = ephpm_kv_incr( $key );
					return false === $v ? null : (int) $v;
				case 'predis':
					return (int) $this->predis->incr( $key );
				case 'resp':
					$r = $this->resp_cmd( array( 'INCR', $key ) );
					return is_int( $r ) ? $r : null;
			}
		} catch ( \Throwable $e ) {
			error_log( '[datastar-live] KV incr failed: ' . $e->getMessage() );
		}
		return null;
	}

	/** SET (with optional TTL seconds) — returns success. */
	public function set( string $key, string $value, int $ttl_s = 0 ): bool {
		try {
			switch ( $this->backend ) {
				case 'ephpm':
					return (bool) ephpm_kv_set( $key, $value, $ttl_s > 0 ? $ttl_s * 1000 : 0 );
				case 'predis':
					$ttl_s > 0 ? $this->predis->setex( $key, $ttl_s, $value ) : $this->predis->set( $key, $value );
					return true;
				case 'resp':
					$cmd = $ttl_s > 0
						? array( 'SETEX', $key, (string) $ttl_s, $value )
						: array( 'SET', $key, $value );
					return 'OK' === $this->resp_cmd( $cmd );
			}
		} catch ( \Throwable $e ) {
			error_log( '[datastar-live] KV set failed: ' . $e->getMessage() );
		}
		return false;
	}

	/** GET — returns the value or null. */
	public function get( string $key ): ?string {
		try {
			switch ( $this->backend ) {
				case 'ephpm':
					$v = ephpm_kv_get( $key );
					return null === $v || false === $v ? null : (string) $v;
				case 'predis':
					$v = $this->predis->get( $key );
					return null === $v ? null : (string) $v;
				case 'resp':
					$r = $this->resp_cmd( array( 'GET', $key ) );
					return is_string( $r ) ? $r : null;
			}
		} catch ( \Throwable $e ) {
			error_log( '[datastar-live] KV get failed: ' . $e->getMessage() );
		}
		return null;
	}

	/** True if a PING round-trips (or the native functions exist). */
	public function available(): bool {
		if ( 'ephpm' === $this->backend ) {
			return true;
		}
		try {
			if ( 'predis' === $this->backend ) {
				return 'PONG' === (string) $this->predis->ping();
			}
			return 'PONG' === $this->resp_cmd( array( 'PING' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	// ── Raw RESP2 client ────────────────────────────────────────────────

	/**
	 * Send one command, read one reply.
	 *
	 * @param string[] $argv Command + arguments.
	 * @return string|int|null Simple string / bulk string / integer reply;
	 *                         null for RESP Null; throws on protocol errors.
	 */
	private function resp_cmd( array $argv ) {
		$s = $this->resp_sock();
		$out = '*' . count( $argv ) . "\r\n";
		foreach ( $argv as $a ) {
			$out .= '$' . strlen( $a ) . "\r\n" . $a . "\r\n";
		}
		if ( fwrite( $s, $out ) !== strlen( $out ) ) {
			$this->resp_drop();
			throw new \RuntimeException( 'short write to KV' );
		}
		return $this->resp_reply( $s );
	}

	/** @return string|int|null */
	private function resp_reply( $s ) {
		$line = fgets( $s );
		if ( false === $line ) {
			$this->resp_drop();
			throw new \RuntimeException( 'KV connection closed' );
		}
		$type    = $line[0];
		$payload = substr( rtrim( $line, "\r\n" ), 1 );
		switch ( $type ) {
			case '+':
				return $payload;                       // simple string
			case ':':
				return (int) $payload;                 // integer
			case '$':
				$len = (int) $payload;
				if ( $len < 0 ) {
					return null;                       // null bulk
				}
				$buf = '';
				while ( strlen( $buf ) < $len + 2 ) {  // value + trailing CRLF
					$chunk = fread( $s, $len + 2 - strlen( $buf ) );
					if ( false === $chunk || '' === $chunk ) {
						$this->resp_drop();
						throw new \RuntimeException( 'short read from KV' );
					}
					$buf .= $chunk;
				}
				return substr( $buf, 0, $len );
			case '-':
				throw new \RuntimeException( 'KV error reply: ' . $payload );
			default:
				$this->resp_drop();
				throw new \RuntimeException( 'unexpected RESP type: ' . $type );
		}
	}

	/** @return resource */
	private function resp_sock() {
		if ( is_resource( $this->sock ) ) {
			return $this->sock;
		}
		$errno = 0;
		$err   = '';
		$s     = @fsockopen( $this->host, $this->port, $errno, $err, 0.5 );
		if ( false === $s ) {
			throw new \RuntimeException( "connect {$this->host}:{$this->port} failed: {$err} ({$errno})" );
		}
		stream_set_timeout( $s, 0, 500_000 ); // 500 ms I/O budget
		$this->sock = $s;
		if ( '' !== $this->password ) {
			$out = "*2\r\n\$4\r\nAUTH\r\n\$" . strlen( $this->password ) . "\r\n{$this->password}\r\n";
			fwrite( $s, $out );
			$this->resp_reply( $s ); // throws on -ERR
		}
		return $s;
	}

	private function resp_drop(): void {
		if ( is_resource( $this->sock ) ) {
			fclose( $this->sock );
		}
		$this->sock = null;
	}
}
