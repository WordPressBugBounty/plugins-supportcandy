<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Stats_Cache' ) ) :

	/**
	 * Shared read-through cache for computed ticket statistics - used by both the dashboard
	 * cards/widgets and the Reports section.
	 *
	 * Every expensive aggregate query is wrapped with remember(), keyed by the caller's own slug
	 * plus whatever arguments affect its result (e.g. a date range). Instead of tracking down and
	 * deleting each cached key by hand whenever a ticket changes, a single "generation" number is
	 * bumped on any ticket event that could affect these numbers; that number is folded into every
	 * cache key, so a bump instantly makes all previously cached keys unreachable without needing to
	 * know what they were. On sites without a persistent object cache (Redis/Memcached), a transient
	 * is really just two rows in wp_options, and WordPress only ever cleans those up lazily (on a
	 * request for that exact key, or via its own non-guaranteed periodic sweep) - so an orphaned
	 * prior-generation row can otherwise sit in wp_options indefinitely. To avoid that, every key this
	 * class writes is recorded against the generation it belongs to, and bump_generation() explicitly
	 * delete_transient()'s all of them before advancing the counter.
	 *
	 * The Reports section uses remember_report() instead: longer-lived entries that ignore the
	 * generation and are only refreshed on expiry or when the viewer explicitly recalculates.
	 */
	final class WPSC_Stats_Cache {

		/**
		 * Option name holding the current cache generation number.
		 *
		 * @var string
		 */
		const GENERATION_OPTION = 'wpsc_stats_cache_gen';

		/**
		 * Prefix for the option that tracks which transient keys were written under a given
		 * generation, e.g. 'wpsc_stats_cache_keys_14'. Used to explicitly clean up that generation's
		 * transients once it's superseded, instead of leaving them for WordPress to lazily expire.
		 *
		 * @var string
		 */
		const KEYS_OPTION_PREFIX = 'wpsc_stats_cache_keys_';

		/**
		 * Safety-net TTL (seconds) applied to every cached entry.
		 *
		 * @var int
		 */
		const DEFAULT_TTL = 300; // 5 * MINUTE_IN_SECONDS, kept literal so this file has no load-order dependency.

		/**
		 * TTL (seconds) for Reports-section entries written by remember_report().
		 *
		 * @var int
		 */
		const REPORT_TTL = 43200; // 12 * HOUR_IN_SECONDS.

		/**
		 * Option name holding the Reports-section cache version. Unlike the generation above it is
		 * NOT bumped on ticket events - only by flush_reports() - so report entries survive until
		 * their TTL runs out or the viewer explicitly recalculates.
		 *
		 * @var string
		 */
		const REPORT_VERSION_OPTION = 'wpsc_stats_cache_report_ver';

		/**
		 * Request flag (POST) asking remember_report() to skip the cached value and recompute.
		 *
		 * @var string
		 */
		const RECALCULATE_PARAM = 'wpsc_rp_recalculate';

		/**
		 * Response header carrying when the data served by this request was calculated (unix time).
		 * The Reports UI reads it to show "last calculated" without every report's response shape
		 * having to change.
		 *
		 * @var string
		 */
		const COMPUTED_AT_HEADER = 'X-WPSC-Report-Computed-At';

		/**
		 * Oldest calculation time among the report entries served in this request.
		 *
		 * @var int|null
		 */
		private static $report_computed_at = null;

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Any of these events can change what a cached card/widget/report would show. Bumping the
			// generation on all of them is deliberately broad (a tag change also invalidates entries
			// that never look at tags) - over-invalidating once in a while is cheap; missing one of
			// these and showing stale numbers is the bug we are specifically trying to avoid.
			$events = array(
				'wpsc_create_new_ticket',
				'wpsc_delete_ticket',
				'wpsc_ticket_delete_permanently',
				'wpsc_ticket_archive',
				'wpsc_ticket_restore',
				'wpsc_change_ticket_status',
				'wpsc_change_assignee',
				'wpsc_change_ticket_category',
				'wpsc_change_ticket_priority',
				'wpsc_change_raised_by',
				'wpsc_change_tag',
				'wpsc_change_ticket_fields', // generic custom-field edits (single-select, checkbox, multi-select, radio-button, ...).
				'wpsc_change_agentonly_fields', // same, for agent-only custom fields.
				'wpsc_post_reply',
				'wpsc_submit_note',
				'wpsc_change_usergroup', // fired by the usergroup addon; harmless no-op if that addon isn't active.
				'wpsc_change_ticket_rating', // fired by the satisfaction-survey addon; harmless no-op if inactive.
				'wpsc_agent_role_update', // agent role capability changes.
				'after_set_add_agent', // agent added (note: fires once per bulk "add agent" submit, not once per agent).
				'wpsc_delete_agent', // agent removed/deactivated - also fires when its WP user account is deleted.
				'wpsc_agentgroup_created', // fired by the agentgroup addon; harmless no-op if inactive.
				'wpsc_agentgroup_updated', // same - agents/supervisors added or removed from an agentgroup.
				'wpsc_agentgroup_deleted', // same - agentgroup removed entirely.
				'wpsc_set_add_new_usergroup', // fired by the usergroup addon; harmless no-op if inactive.
				'wpsc_clone_usergroup', // same - a usergroup created by cloning an existing one.
				'wpsc_set_edit_usergroup', // same - covers members/supervisors added or removed (and any other edit).
				'wpsc_before_destroy_usergroup', // same - usergroup deleted entirely (explicitly, or automatically once its last member is gone).
				'delete_user', // a WP user's agent/usergroup membership can be cleaned up silently when their account is deleted.
			);

			foreach ( $events as $event ) {
				add_action( $event, array( __CLASS__, 'bump_generation' ) );
			}
		}

		/**
		 * Advance the cache generation, instantly invalidating every previously cached entry, and
		 * explicitly delete the now-orphaned transients for the generation being retired so they
		 * don't linger in wp_options waiting for WordPress's own lazy expiry.
		 *
		 * @return void
		 */
		public static function bump_generation() {

			$retiring_generation = self::generation();

			update_option( self::GENERATION_OPTION, $retiring_generation + 1, false );

			self::flush_generation_keys( $retiring_generation );
		}

		/**
		 * Current cache generation number.
		 *
		 * @return int
		 */
		private static function generation() {

			return (int) get_option( self::GENERATION_OPTION, 1 );
		}

		/**
		 * Record that $key was written under the current generation, so it can be explicitly
		 * deleted once that generation is retired.
		 *
		 * @param string $key Transient key, as produced in remember().
		 * @return void
		 */
		private static function track_key( $key ) {

			$option_name = self::KEYS_OPTION_PREFIX . self::generation();
			$keys        = get_option( $option_name, array() );

			if ( ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
				update_option( $option_name, $keys, false );
			}
		}

		/**
		 * Delete every transient recorded against $generation, then discard the tracking option
		 * itself - both the cached values and the bookkeeping that pointed at them are cleaned up
		 * together, leaving nothing behind in wp_options for a retired generation.
		 *
		 * @param int $generation Generation number being retired.
		 * @return void
		 */
		private static function flush_generation_keys( $generation ) {

			$option_name = self::KEYS_OPTION_PREFIX . $generation;
			$keys        = get_option( $option_name, array() );

			foreach ( $keys as $key ) {
				delete_transient( $key );
			}

			delete_option( $option_name );
		}

		/**
		 * Get a cached value, computing and caching it on a miss.
		 *
		 * @param string   $slug        Unique identifier for the card/widget/report, e.g. 'agent-workload'.
		 * @param array    $args        Any inputs that affect the result, e.g. a date range. Two calls
		 *                              with different $args for the same slug are cached separately.
		 * @param callable $callback    Computes the value on a cache miss. Called with no arguments.
		 * @param boolean  $per_viewer  Pass true when the result differs per viewing agent (i.e. the
		 *                              query uses WPSC_Current_User::get_tl_system_query()/get_atl_system_query()
		 *                              or otherwise depends on who is looking). Left false, one cache
		 *                              entry is shared by every viewer - only set this when the data
		 *                              actually differs per viewer, otherwise this cache loses most of
		 *                              its benefit (each viewer pays the full cost on their own).
		 * @param integer  $ttl         Safety-net TTL in seconds, only relevant for changes that bypass
		 *                              the hooks in init() (e.g. a direct DB import).
		 * @return mixed
		 */
		public static function remember( $slug, $args, $callback, $per_viewer = false, $ttl = self::DEFAULT_TTL ) {

			$key_parts = array( $slug, self::generation(), wp_json_encode( $args ) );

			if ( $per_viewer ) {
				$key_parts[] = self::viewer_key_part();
			}

			$key = 'wpsc_sc_' . md5( implode( '|', $key_parts ) );

			$value = get_transient( $key );
			if ( false === $value ) {
				$value = call_user_func( $callback );
				set_transient( $key, $value, $ttl );
				self::track_key( $key );
			}

			return $value;
		}

		/**
		 * Reports-section variant of remember(): cached for REPORT_TTL and deliberately NOT
		 * invalidated by ticket events, so a report shows the same numbers until they expire or the
		 * viewer hits "Recalculate" (which sends RECALCULATE_PARAM and forces a fresh computation that
		 * overwrites the entry). The calculation time is stored with the value and reported back via
		 * COMPUTED_AT_HEADER so the UI can tell the viewer how old the numbers are.
		 *
		 * Must be called before the handler prints anything, otherwise the header can't be sent.
		 *
		 * @param string   $slug       Unique identifier for the report, e.g. 'rp-ticket-statistics'.
		 * @param array    $args       Any inputs that affect the result (date range, filters, ...).
		 * @param callable $callback   Computes the value on a cache miss. Called with no arguments.
		 * @param boolean  $per_viewer Same meaning as in remember().
		 * @return mixed
		 */
		public static function remember_report( $slug, $args, $callback, $per_viewer = false ) {

			$key_parts = array( $slug, (int) get_option( self::REPORT_VERSION_OPTION, 1 ), wp_json_encode( $args ) );

			if ( $per_viewer ) {
				$key_parts[] = self::viewer_key_part();
			}

			$key = 'wpsc_rpc_' . md5( implode( '|', $key_parts ) );

			// Only ever reached from report handlers that have already verified their nonce and the
			// viewer's 'view-reports' capability.
			$recalculate = ! empty( $_POST[ self::RECALCULATE_PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			$entry = $recalculate ? false : get_transient( $key );
			if ( ! is_array( $entry ) || ! array_key_exists( 'value', $entry ) ) {
				$entry = array(
					'value'       => call_user_func( $callback ),
					'computed_at' => time(),
				);
				set_transient( $key, $entry, self::REPORT_TTL );
			}

			self::$report_computed_at = null === self::$report_computed_at
				? $entry['computed_at']
				: min( self::$report_computed_at, $entry['computed_at'] );

			if ( ! headers_sent() ) {
				header( self::COMPUTED_AT_HEADER . ': ' . self::$report_computed_at );
			}

			return $entry['value'];
		}

		/**
		 * Make every Reports-section entry unreachable, for changes that bypass the ticket hooks but
		 * alter report numbers (e.g. the bulk ticket-metrics recalculation). The orphaned transients
		 * are left to expire (REPORT_TTL) and be swept by WordPress's daily expired-transient cleanup.
		 *
		 * @return void
		 */
		public static function flush_reports() {

			update_option( self::REPORT_VERSION_OPTION, (int) get_option( self::REPORT_VERSION_OPTION, 1 ) + 1, false );
		}

		/**
		 * Cache key part identifying the current viewer, for per-viewer entries.
		 *
		 * @return string
		 */
		private static function viewer_key_part() {

			$current_user = WPSC_Current_User::$current_user;
			return $current_user->is_agent ? 'agent_' . $current_user->agent->id : 'customer_' . $current_user->customer->id;
		}
	}
endif;
WPSC_Stats_Cache::init();
