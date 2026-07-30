<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Sessions' ) ) :

	final class WPSC_ACB_Sessions {

		/**
		 * Object data in key => val pair.
		 *
		 * @var array
		 */
		private $data = array();

		/**
		 * Set whether or not current object properties modified
		 *
		 * @var boolean
		 */
		private $is_modified = false;

		/**
		 * Schema for this model
		 *
		 * @var array
		 */
		public static $schema = array();

		/**
		 * Prevent fields to modify
		 *
		 * @var array
		 */
		public static $prevent_modify = array();

		/**
		 * DB object caching
		 *
		 * @var array
		 */
		private static $cache = array();

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Apply schema for this model.
			add_action( 'init', array( __CLASS__, 'apply_schema' ), 2 );

			// Get object of this class.
			add_filter( 'wpsc_load_ref_classes', array( __CLASS__, 'load_ref_class' ) );
		}

		/**
		 * Apply schema for this model
		 *
		 * @return void
		 */
		public static function apply_schema() {

			$schema = array(
				'id'            => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'session_id'    => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'visitor_id'    => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'subject'       => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'provider'      => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'reaction'      => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'ticket_id'     => array(
					'has_ref'          => true,
					'ref_class'        => 'wpsc_ticket',
					'has_multiple_val' => false,
				),
				'status'        => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'token_count'   => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'last_activity' => array(
					'has_ref'          => true,
					'ref_class'        => 'datetime',
					'has_multiple_val' => false,
				),
				'date_created'  => array(
					'has_ref'          => true,
					'ref_class'        => 'datetime',
					'has_multiple_val' => false,
				),
			);
			self::$schema = apply_filters( 'wpsc_acb_sessions_schema', $schema );

			// Prevent modify.
			$prevent_modify       = array( 'id', 'session_id', 'visitor_id' );
			self::$prevent_modify = apply_filters( 'wpsc_acb_sessions_prevent_modify', $prevent_modify );
		}

		/**
		 * Model constructor
		 *
		 * @param int $id - Optional. Data record id to retrieve object for.
		 * @return void
		 */
		public function __construct( $id = 0 ) {

			global $wpdb;
			$id = intval( $id );

			if ( isset( self::$cache[ $id ] ) ) {
				$this->data = self::$cache[ $id ]->data;
				return;
			}

			if ( $id > 0 ) {
				$tag = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}psmsc_acb_sessions WHERE id = %d", $id ), ARRAY_A );
				if ( ! is_array( $tag ) ) {
					return;
				}

				foreach ( $tag as $key => $val ) {
					$this->data[ $key ] = $val !== null ? $val : '';
				}
				self::$cache[ $id ] = $this;
			}
		}

		/**
		 * Magic get function to use with object arrow function
		 *
		 * @param string $var_name - variable name.
		 * @return mixed
		 */
		public function __get( $var_name ) {

			if ( ! isset( $this->data[ $var_name ] ) ||
				$this->data[ $var_name ] == null ||
				$this->data[ $var_name ] == ''
			) {
				return self::$schema[ $var_name ]['has_multiple_val'] ? array() : '';
			}

			if ( self::$schema[ $var_name ]['has_multiple_val'] ) {

				$response = array();
				$values   = $this->data[ $var_name ] ? explode( '|', $this->data[ $var_name ] ) : array();
				foreach ( $values as $val ) {
					$response[] = self::$schema[ $var_name ]['has_ref'] ?
					WPSC_Functions::get_object( self::$schema[ $var_name ]['ref_class'], $val ) :
					$val;
				}
				return $response;
			} else {

				return self::$schema[ $var_name ]['has_ref'] && $this->data[ $var_name ] ?
					WPSC_Functions::get_object( self::$schema[ $var_name ]['ref_class'], $this->data[ $var_name ] ) :
					$this->data[ $var_name ];
			}
		}

		/**
		 * Magic function to use setting object field with arrow function
		 *
		 * @param string $var_name - (Required) property slug.
		 * @param mixed  $value - (Required) value to set for a property.
		 * @return void
		 */
		public function __set( $var_name, $value ) {

			if ( ! isset( $this->data[ $var_name ] ) ) {
				return;
			}

			if ( in_array( $var_name, self::$prevent_modify ) ) {
				return;
			}

			$data_val = '';
			if ( self::$schema[ $var_name ]['has_multiple_val'] ) {
				$data_vals = array_map(
					fn( $val ) => is_object( $val ) ? WPSC_Functions::set_object( self::$schema[ $var_name ]['ref_class'], $val ) : $val,
					$value
				);
				$data_val = $data_vals ? implode( '|', $data_vals ) : '';
			} else {

				$data_val = is_object( $value ) ? WPSC_Functions::set_object( self::$schema[ $var_name ]['ref_class'], $value ) : $value;
			}

			if ( isset( $this->data[ $var_name ] ) && $this->data[ $var_name ] == $data_val ) {
				return;
			}

			$this->data[ $var_name ] = $data_val;
			$this->is_modified       = true;
		}

		/**
		 * Save changes made
		 *
		 * @return boolean
		 */
		public function save() {

			global $wpdb;

			if ( ! $this->is_modified ) {
				return true;
			}

			$data    = $this->data;
			$success = true;

			if ( ! isset( $data['id'] ) ) {

				$cr = self::insert( $data );
				if ( $cr ) {
					$this->data = $cr->data;
					$success    = true;
				} else {
					$success = false;
				}
			} else {

				unset( $data['id'] );
				$success = $wpdb->update(
					$wpdb->prefix . 'psmsc_acb_sessions',
					$data,
					array( 'id' => $this->data['id'] )
				);
			}
			$this->is_modified        = false;
			self::$cache[ $this->id ] = $this;
			return $success ? true : false;
		}

		/**
		 * Insert new record
		 *
		 * @param array $data - insert data.
		 * @return boolean|WPSC_ACB_Sessions
		 */
		public static function insert( $data ) {

			global $wpdb;
			$success = $wpdb->insert(
				$wpdb->prefix . 'psmsc_acb_sessions',
				$data
			);

			if ( ! $success ) {
				return false;
			}

			$log = new WPSC_ACB_Sessions( $wpdb->insert_id );
			self::$cache[ $log->id ] = $log;
			return $log;
		}

		/**
		 * Update record based on given date
		 *
		 * @param string $data - date to update records.
		 * @return WPSC_ACB_Sessions
		 */
		public static function update( $data ) {

			global $wpdb;

			$wpdb->update(
				$wpdb->prefix . 'psmsc_acb_sessions',
				$data,
				array( 'id' => $data['id'] )
			);
			return new WPSC_ACB_Sessions( $data['id'] );
		}

		/**
		 * Increment token count for a session using atomic SQL update.
		 *
		 * @param int $session_id Session internal ID.
		 * @param int $tokens Number of tokens to add.
		 * @return bool
		 */
		public static function increment_token_count( $session_id, $tokens ) {

			global $wpdb;

			$session_id = absint( $session_id );
			$tokens = (int) $tokens;

			if ( $session_id <= 0 || $tokens <= 0 ) {
				return true;
			}

			$success = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}psmsc_acb_sessions SET token_count = token_count + %d WHERE id = %d",
					$tokens,
					$session_id
				)
			);

			if ( false === $success ) {
				return false;
			}

			if ( isset( self::$cache[ $session_id ] ) ) {
				$current = (int) ( self::$cache[ $session_id ]->data['token_count'] ?? 0 );
				self::$cache[ $session_id ]->data['token_count'] = $current + $tokens;
			}

			return true;
		}

		/**
		 * Make it inactive so that garbage collector will delete files associated in
		 * background and then delete the record. This will improve its performance.
		 *
		 * @param WPSC_ACB_Sessions $log - log object.
		 * @return boolean
		 */
		public static function destroy( $log ) {

			global $wpdb;

			$success = $wpdb->delete(
				$wpdb->prefix . 'psmsc_acb_messages',
				array( 'session_id' => $log->id )
			);

			$success = $wpdb->delete(
				$wpdb->prefix . 'psmsc_acb_sessions',
				array( 'id' => $log->id )
			);

			unset( self::$cache[ $log->id ] );
			return ! $success ? false : true;
		}

		/**
		 * Set data to create new object using direct data. Used in find method
		 *
		 * @param array $data - data to set for object.
		 * @return void
		 */
		private function set_data( $data ) {

			foreach ( $data as $var_name => $val ) {
				$this->data[ $var_name ] = $val !== null ? $val : '';
			}
			self::$cache[ $this->id ] = $this;
		}

		/**
		 * Find records based on given filters
		 *
		 * @param array   $filter - array containing array items like search, where, orderby, order, page_no, items_per_page, etc.
		 * @param boolean $is_object - return data as array or object. Default object.
		 * @return mixed
		 */
		public static function find( $filter = array(), $is_object = true ) {

			global $wpdb;

			$filter['items_per_page'] = isset( $filter['items_per_page'] ) ? $filter['items_per_page'] : 20;
			$filter['page_no']        = isset( $filter['page_no'] ) ? $filter['page_no'] : 1;
			$filter['orderby']        = isset( $filter['orderby'] ) ? $filter['orderby'] : 'date_created';
			$filter['order']          = isset( $filter['order'] ) ? $filter['order'] : 'ASC';

			$sql   = 'SELECT acbs.* FROM ' . $wpdb->prefix . 'psmsc_acb_sessions acbs ';
			$where = self::get_where( $filter );

			// Add table alias to orderby.
			$filter['orderby'] = 'acbs.' . $filter['orderby'];

			$order = WPSC_Functions::parse_order( $filter );
			$limit = WPSC_Functions::parse_limit( $filter );

			$group_by = 'GROUP BY acbs.id ';

			$sql = $sql . $where . $group_by . $order . $limit;
			$results     = $wpdb->get_results( $sql, ARRAY_A );

			// total results.
			$sql = 'SELECT count(DISTINCT acbs.id) FROM ' . $wpdb->prefix . 'psmsc_acb_sessions acbs ';
			$total_items = $wpdb->get_var( $sql . $where );

			$response = WPSC_Functions::parse_response( $results, $total_items, $filter );

			// Return array.
			if ( ! $is_object ) {
				return $response;
			}

			// create and return array of objects.
			$temp_results = array();
			foreach ( $response['results'] as $customer ) {

				$ob   = new WPSC_ACB_Sessions();
				$data = array();
				foreach ( $customer as $key => $val ) {
					$data[ $key ] = $val;
				}
				$ob->set_data( $data );
				$temp_results[] = $ob;
			}
			$response['results'] = $temp_results;
			return $response;
		}

		/**
		 * Get where for find method
		 *
		 * @param array $filter - user filter.
		 * @return array
		 */
		private static function get_where( $filter ) {
			$where = array( '1=1' );

			// Load meta filters.
			$meta_query = isset( $filter['meta_query'] ) ? $filter['meta_query'] : array();
			if ( $meta_query ) {
				$where[] = WPSC_Functions::parse_user_filters( __CLASS__, $meta_query );
			}

			global $wpdb;
			$search = WPSC_Functions::get_filter_search_str( $filter );
			if ( $search ) {
				$escaped_search = esc_sql( $wpdb->esc_like( $search ) );
				$like = '%' . $escaped_search . '%';

				$search_query = array();

				// AI chatbot sessions table cols.
				foreach ( self::$schema as $slug => $schema ) {
					if ( ! $schema['has_ref'] && $slug != 'id' ) {
						$search_query[] = $wpdb->prepare( 'CONVERT(acbs.' . $slug . ' USING utf8) LIKE %s', $like );
					}
				}
				$where[] = implode( ' OR ', $search_query );
			}

			return 'WHERE (' . implode( ') AND (', $where ) . ') ';
		}

		/**
		 * Load current class to reference classes
		 *
		 * @param array $classes - Associative array of class names indexed by its slug.
		 * @return array
		 */
		public static function load_ref_class( $classes ) {

			$classes['wpsc_acb_sessions'] = array(
				'class'    => __CLASS__,
				'save-key' => 'id',
			);
			return $classes;
		}

		/**
		 * Count total records based on given filters
		 *
		 * @param array $filter - array containing array items like search, where, orderby, order, page_no, items_per_page, etc.
		 * @return int
		 */
		public static function count( $filter = array() ) {

			global $wpdb;

			$sql   = 'SELECT COUNT(DISTINCT acbs.id) FROM ' . $wpdb->prefix . 'psmsc_acb_sessions acbs ';
			$where = self::get_where( $filter );

			$sql = $sql . $where;

			return (int) $wpdb->get_var( $sql );
		}

		/**
		 * Get session by session uuid
		 *
		 * @param string $session_id - session uuid.
		 * @return WPSC_ACB_Sessions|null
		 */
		public static function get_session_by_session_uuid( $session_id ) {
			global $wpdb;

			$sql = $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}psmsc_acb_sessions WHERE session_id = %s",
				$session_id
			);

			$result = $wpdb->get_row( $sql, ARRAY_A );

			if ( ! $result ) {
				return null;
			}

			$session = new WPSC_ACB_Sessions();
			$session->set_data( $result );

			return $session;
		}
	}
endif;

WPSC_ACB_Sessions::init();
