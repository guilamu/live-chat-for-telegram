<?php
/**
 * Opening hours.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Schedule
 *
 * Decides whether anyone is around, and what to tell the member when nobody is.
 *
 * Being closed never blocks the message. The member is signed in and their topic is waiting, so
 * turning them away would only cost the site a question it was going to receive anyway — they are
 * simply told when to expect an answer.
 *
 * @since 0.1.0
 */
class LCFT_Schedule {

	/**
	 * How many days ahead to look for the next opening before giving up.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const LOOKAHEAD_DAYS = 14;

	/**
	 * Indicates whether the desk is currently staffed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_open() {

		if ( ! LCFT_Settings::get( 'schedule_enabled', false ) ) {
			return true;
		}

		$now = self::now();
		$day = self::get_day( $now );

		if ( empty( $day['enabled'] ) ) {
			return false;
		}

		$minutes = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );

		$open  = self::to_minutes( $day['open'] );
		$close = self::to_minutes( $day['close'] );

		// A closing time earlier than the opening time means the shift runs past midnight.
		if ( $close <= $open ) {
			return $minutes >= $open || $minutes < $close;
		}

		return $minutes >= $open && $minutes < $close;
	}

	/**
	 * Returns the message shown when the desk is closed.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_notice() {

		if ( self::is_open() ) {
			return '';
		}

		$custom = trim( (string) LCFT_Settings::get( 'closed_message', '' ) );

		if ( '' !== $custom ) {
			return $custom;
		}

		return self::automatic_notice();
	}

	/**
	 * Returns the closed message built from the next opening, used when no custom one is set.
	 *
	 * Public, and indifferent to whether the desk is closed right now, so the settings screen can
	 * preview it at any time of day.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function automatic_notice() {

		$next = self::next_opening();

		if ( ! $next ) {
			return __( 'We are closed at the moment. Leave your message and we will get back to you.', 'live-chat-for-telegram' );
		}

		return self::describe( $next );
	}

	/**
	 * Returns the next moment the desk opens.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable|null Null when nothing is open in the next fortnight.
	 */
	public static function next_opening() {

		if ( ! LCFT_Settings::get( 'schedule_enabled', false ) ) {
			return null;
		}

		$now = self::now();

		for ( $offset = 0; $offset <= self::LOOKAHEAD_DAYS; $offset++ ) {

			$candidate = $now->modify( sprintf( '+%d days', $offset ) );
			$day       = self::get_day( $candidate );

			if ( empty( $day['enabled'] ) ) {
				continue;
			}

			$opens = $candidate->setTime(
				(int) substr( $day['open'], 0, 2 ),
				(int) substr( $day['open'], 3, 2 )
			);

			// Today's opening may already be behind us, in which case this day does not count.
			if ( $opens > $now ) {
				return $opens;
			}
		}

		return null;
	}


	/**
	 * Returns the timezone the opening hours are read in.
	 *
	 * Defaults to the site's own timezone (Settings › General), which on many installs is still
	 * the UTC it shipped with — hence a dedicated setting, so the hours can follow the team's
	 * clock without touching the rest of the site.
	 *
	 * @since 1.2.0
	 *
	 * @return DateTimeZone
	 */
	public static function timezone() {

		$setting = trim( (string) LCFT_Settings::get( 'schedule_timezone', '' ) );

		if ( '' === $setting ) {
			return wp_timezone();
		}

		// Manual offsets, as offered by WordPress' own timezone picker: "UTC+2", "UTC-5.5".
		if ( preg_match( '/^UTC([+-])(\d{1,2})(?:\.(\d+))?$/', $setting, $matches ) ) {
			$minutes = isset( $matches[3] ) ? (int) round( (float) ( '0.' . $matches[3] ) * 60 ) : 0;
			$setting = sprintf( '%s%02d:%02d', $matches[1], (int) $matches[2], $minutes );
		}

		try {
			return new DateTimeZone( $setting );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * Returns the current time in the schedule's timezone.
	 *
	 * @since 1.2.0
	 *
	 * @return DateTimeImmutable
	 */
	public static function now() {
		return new DateTimeImmutable( 'now', self::timezone() );
	}


	// # INTERNALS -----------------------------------------------------------------------------------------------------

	/**
	 * Returns the hours that apply on a given day, exceptions included.
	 *
	 * @since 0.1.0
	 *
	 * @param DateTimeImmutable $date The day.
	 *
	 * @return array enabled, open and close.
	 */
	protected static function get_day( $date ) {

		$defaults = array(
			'enabled' => false,
			'open'    => '09:00',
			'close'   => '18:00',
		);

		$exceptions = (array) LCFT_Settings::get( 'schedule_exceptions', array() );
		$key        = $date->format( 'Y-m-d' );

		// A one off closure or a special day wins over the weekly pattern.
		if ( isset( $exceptions[ $key ] ) ) {
			return wp_parse_args( (array) $exceptions[ $key ], $defaults );
		}

		$schedule = (array) LCFT_Settings::get( 'schedule', LCFT_Settings::default_schedule() );
		$weekday  = (int) $date->format( 'w' );

		if ( ! isset( $schedule[ $weekday ] ) ) {
			return $defaults;
		}

		return wp_parse_args( (array) $schedule[ $weekday ], $defaults );
	}

	/**
	 * Converts "09:30" into minutes since midnight.
	 *
	 * @since 0.1.0
	 *
	 * @param string $time The time.
	 *
	 * @return int
	 */
	protected static function to_minutes( $time ) {

		$parts = explode( ':', (string) $time );

		$hours   = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$minutes = isset( $parts[1] ) ? (int) $parts[1] : 0;

		return $hours * 60 + $minutes;
	}

	/**
	 * Announces when the desk next opens, in the most natural terms for how far off it is: a
	 * countdown within the hour, then a time today, tomorrow, or on a named day.
	 *
	 * @since 0.1.0
	 * @since 1.2.0 Counts down in minutes within the hour, and reads as a full sentence.
	 *
	 * @param DateTimeImmutable $date The next opening.
	 *
	 * @return string
	 */
	protected static function describe( $date ) {

		$now     = self::now();
		$minutes = (int) ceil( ( $date->getTimestamp() - $now->getTimestamp() ) / 60 );

		if ( $minutes < 60 ) {
			return sprintf(
				/* translators: %d: A number of minutes, under 60. */
				__( 'Support will be available in %d min.', 'live-chat-for-telegram' ),
				max( 1, $minutes )
			);
		}

		$time = wp_date( get_option( 'time_format' ), $date->getTimestamp(), self::timezone() );

		if ( $date->format( 'Y-m-d' ) === $now->format( 'Y-m-d' ) ) {
			/* translators: %s: The opening time. */
			return sprintf( __( 'Support will be available from %s.', 'live-chat-for-telegram' ), $time );
		}

		if ( $date->format( 'Y-m-d' ) === $now->modify( '+1 day' )->format( 'Y-m-d' ) ) {
			/* translators: %s: The opening time. */
			return sprintf( __( 'Support will be available tomorrow, from %s.', 'live-chat-for-telegram' ), $time );
		}

		return sprintf(
			/* translators: 1: A weekday name. 2: The opening time. */
			__( 'Support will be available on %1$s, from %2$s.', 'live-chat-for-telegram' ),
			wp_date( 'l', $date->getTimestamp(), self::timezone() ),
			$time
		);
	}
}
