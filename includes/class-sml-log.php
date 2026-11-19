<?php
/**
 * Smart Login's log: what happened when an SMS or an email was sent, and other
 * problems, in one file the admin can read from Settings → Logs.
 *
 * Lines look like  [2026-11-19 10:15:30] [ERROR] [SMS] provider=ippanel_ws to=0912 *** 4567 …
 *
 * What is written depends on Settings → Logs → "What to log":
 *   errors  problems only (the default)
 *   all     problems and sends that worked
 *   off     nothing
 *
 * Nothing sensitive is ever stored: callers never pass codes, passwords or API
 * keys, and write() also shortens any email address or long number that
 * appears in a message (a mail server's error text often repeats the
 * recipient).
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Log {

	/** Rotate once the file passes this size; one previous file is kept. */
	const MAX_BYTES = 2097152; // 2 MB.

	/** Longest message kept per line. */
	const MAX_MESSAGE = 600;

	/**
	 * Current logging level. Sites that switched on the old "debug log" toggle
	 * and never touched the new setting keep logging everything.
	 *
	 * @return string 'errors'|'all'|'off'
	 */
	public static function level() {
		$raw = get_option( SML_Settings::OPTION_NAME, array() );

		if ( is_array( $raw ) && isset( $raw['log_level'] ) && in_array( $raw['log_level'], array( 'errors', 'all', 'off' ), true ) ) {
			return $raw['log_level'];
		}

		return ( is_array( $raw ) && ! empty( $raw['enable_debug_log'] ) ) ? 'all' : 'errors';
	}

	/**
	 * @param string $entry_level 'error' or 'info'.
	 * @return bool Whether an entry of that kind is written right now.
	 */
	public static function enabled( $entry_level ) {
		$level = self::level();

		if ( 'off' === $level ) {
			return false;
		}

		return 'error' === $entry_level || 'all' === $level;
	}

	/**
	 * Appends one entry.
	 *
	 * @param string $entry_level 'error' (a problem) or 'info' (something that worked).
	 * @param string $channel     'SMS', 'Email', 'Google', 'Register'… or '' for other.
	 * @param string $message     Plain text. Never a code, password or key.
	 */
	public static function write( $entry_level, $channel, $message ) {
		$entry_level = 'error' === $entry_level ? 'error' : 'info';

		if ( ! self::enabled( $entry_level ) ) {
			return;
		}

		$dir = self::dir();
		if ( '' === $dir ) {
			return;
		}

		$file = trailingslashit( $dir ) . self::filename();
		self::maybe_rotate( $file );

		$channel = preg_replace( '/[^A-Za-z0-9_ -]/', '', (string) $channel );
		$line    = sprintf(
			'[%s] [%s] [%s] %s' . PHP_EOL,
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $entry_level ),
			$channel,
			self::clean( $message )
		);

		// Some file systems (NFS shares, certain containers) cannot lock files;
		// a log line is worth more than the lock, so retry without it.
		if ( false === file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, $line, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * One line, no markup or control characters, with addresses and long
	 * numbers shortened, cut to a sane length.
	 *
	 * @param string $message
	 * @return string
	 */
	public static function clean( $message ) {
		$text = wp_strip_all_tags( (string) $message );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = preg_replace_callback(
			'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
			function ( $m ) {
				return self::mask_email( $m[0] );
			},
			$text
		);
		$text = preg_replace_callback(
			'/\+?\d[\d\s().\-]{7,}\d/',
			function ( $m ) {
				$digits = preg_replace( '/\D/', '', $m[0] );

				return strlen( $digits ) >= 8 ? substr( $digits, 0, 4 ) . ' *** ' . substr( $digits, -4 ) : $m[0];
			},
			$text
		);

		return mb_substr( trim( preg_replace( '/\s+/', ' ', $text ) ), 0, self::MAX_MESSAGE );
	}

	/**
	 * @param string $email
	 * @return string e.g. a***@gmail.com
	 */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );

		if ( false === $at || $at < 1 ) {
			return '***';
		}

		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Newest entries first, from the current file and, when more are wanted,
	 * the previous one.
	 *
	 * @param int    $limit       How many to return.
	 * @param string $channel     '' for all, 'Other' for entries without a channel, or a channel name.
	 * @param bool   $only_errors Only problems.
	 * @return array<int,array{time:string,level:string,channel:string,message:string}>
	 */
	public static function read( $limit = 200, $channel = '', $only_errors = false ) {
		$dir = self::dir();
		if ( '' === $dir ) {
			return array();
		}

		$base    = trailingslashit( $dir ) . self::filename();
		$entries = array();

		foreach ( array( $base, $base . '.1' ) as $file ) {
			if ( ! file_exists( $file ) ) {
				continue;
			}

			// Only the tail of a big file: it is the newest part.
			$size = (int) filesize( $file );
			$fh   = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				continue;
			}
			if ( $size > 262144 ) {
				fseek( $fh, -262144, SEEK_END );
				fgets( $fh ); // drop the partial first line
			}
			$chunk = (string) stream_get_contents( $fh );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			$lines = array_reverse( preg_split( '/\R/', $chunk, -1, PREG_SPLIT_NO_EMPTY ) );
			foreach ( $lines as $line ) {
				$entry = self::parse( $line );

				if ( $only_errors && 'error' !== $entry['level'] ) {
					continue;
				}
				if ( '' !== $channel ) {
					$wanted = 'Other' === $channel ? '' : $channel;
					if ( $entry['channel'] !== $wanted ) {
						continue;
					}
				}

				$entries[] = $entry;
				if ( count( $entries ) >= $limit ) {
					return $entries;
				}
			}
		}

		return $entries;
	}

	/**
	 * @param string $line
	 * @return array{time:string,level:string,channel:string,message:string}
	 */
	protected static function parse( $line ) {
		if ( 1 === preg_match( '/^\[([^\]]+)\]\s+\[(ERROR|INFO)\]\s+\[([^\]]*)\]\s?(.*)$/', $line, $m ) ) {
			return array(
				'time'    => $m[1],
				'level'   => strtolower( $m[2] ),
				'channel' => $m[3],
				'message' => $m[4],
			);
		}

		// Lines written by earlier versions: "[time] message".
		$time    = '';
		$message = $line;
		if ( 1 === preg_match( '/^\[([^\]]+)\]\s?(.*)$/', $line, $m ) ) {
			$time    = $m[1];
			$message = $m[2];
		}

		$channel = '';
		if ( 0 === strpos( $message, 'SMS' ) ) {
			$channel = 'SMS';
		} elseif ( 0 === strpos( $message, 'Email' ) || 0 === strpos( $message, 'wp_mail' ) ) {
			$channel = 'Email';
		} elseif ( 0 === strpos( $message, 'Google' ) ) {
			$channel = 'Google';
		}

		return array(
			'time'    => $time,
			'level'   => ( false !== stripos( $message, 'fail' ) || false !== stripos( $message, 'error' ) ) ? 'error' : 'info',
			'channel' => $channel,
			'message' => $message,
		);
	}

	/**
	 * Deletes the log (and its rotated copy).
	 */
	public static function clear() {
		$dir = self::dir();
		if ( '' === $dir ) {
			return;
		}

		$base = trailingslashit( $dir ) . self::filename();
		foreach ( array( $base, $base . '.1' ) as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * @return int Bytes used by the log files.
	 */
	public static function size() {
		$dir = self::dir();
		if ( '' === $dir ) {
			return 0;
		}

		$base  = trailingslashit( $dir ) . self::filename();
		$total = 0;
		foreach ( array( $base, $base . '.1' ) as $file ) {
			if ( file_exists( $file ) ) {
				$total += (int) filesize( $file );
			}
		}

		return $total;
	}

	/**
	 * The log directory, created on first use with deny rules for both Apache
	 * (.htaccess) and IIS (web.config).
	 *
	 * @return string Absolute path, or '' when it could not be created.
	 */
	protected static function dir() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return '';
		}

		$log_dir = trailingslashit( $upload_dir['basedir'] ) . 'smart-login-logs';

		if ( ! file_exists( $log_dir ) && ! wp_mkdir_p( $log_dir ) ) {
			return '';
		}

		if ( ! file_exists( $log_dir . '/.htaccess' ) ) {
			file_put_contents( $log_dir . '/.htaccess', "Order deny,allow\nDeny from all" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		// nginx ignores .htaccess entirely and IIS needs its own rule, which is
		// why the filename below is unguessable rather than trusting any single
		// server's deny directive to be honoured.
		if ( ! file_exists( $log_dir . '/web.config' ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$log_dir . '/web.config',
				'<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization>'
					. '<deny users="*" /></authorization></system.webServer></configuration>'
			);
		}
		if ( ! file_exists( $log_dir . '/index.php' ) ) {
			file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $log_dir;
	}

	protected static function filename() {
		$key = get_option( 'sml_log_key' );

		if ( ! $key || ! is_string( $key ) ) {
			$key = wp_generate_password( 20, false, false );
			update_option( 'sml_log_key', $key, false );
		}

		return 'debug-' . sanitize_key( $key ) . '.log';
	}

	protected static function maybe_rotate( $file ) {
		if ( ! file_exists( $file ) || filesize( $file ) < self::MAX_BYTES ) {
			return;
		}

		$previous = $file . '.1';
		if ( file_exists( $previous ) ) {
			wp_delete_file( $previous );
		}

		@rename( $file, $previous ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
