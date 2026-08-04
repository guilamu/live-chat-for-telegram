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

		$next = self::next_opening();

		if ( ! $next ) {
			return __( 'We are closed at the moment. Leave your message and we will get back to you.', 'live-chat-for-telegram' );
		}

		return sprintf(
			/* translators: %s: A day and time, for example "Monday at 9:00 am". */
			__( 'We are closed at the moment. Leave your message and we will answer from %s.', 'live-chat-for-telegram' ),
			self::describe( $next )
		);
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


	// # INTERNALS -----------------------------------------------------------------------------------------------------

	/**
	 * Returns the current time in the site's timezone.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	protected static function now() {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

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
	 * Describes a moment the way the notice should read.
	 *
	 * @since 0.1.0
	 *
	 * @param DateTimeImmutable $date The moment.
	 *
	 * @return string
	 */
	protected static function describe( $date ) {

		$now = self::now();

		$time = wp_date( get_option( 'time_format' ), $date->getTimestamp() );

		if ( $date->format( 'Y-m-d' ) === $now->format( 'Y-m-d' ) ) {
			/* translators: %s: A time of day. */
			return sprintf( __( 'today at %s', 'live-chat-for-telegram' ), $time );
		}

		if ( $date->format( 'Y-m-d' ) === $now->modify( '+1 day' )->format( 'Y-m-d' ) ) {
			/* translators: %s: A time of day. */
			return sprintf( __( 'tomorrow at %s', 'live-chat-for-telegram' ), $time );
		}

		return sprintf(
			/* translators: 1: A weekday name. 2: A time of day. */
			__( '%1$s at %2$s', 'live-chat-for-telegram' ),
			wp_date( 'l', $date->getTimestamp() ),
			$time
		);
	}
}
