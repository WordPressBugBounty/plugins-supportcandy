<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Logs' ) ) :

	final class WPSC_PS_AI_Logs {

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
				'id'           => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'customer'     => array(
					'has_ref'          => true,
					'ref_class'        => 'wpsc_customer',
					'has_multiple_val' => false,
				),
				'ticket'       => array(
					'has_ref'          => true,
					'ref_class'        => 'wpsc_ticket',
					'has_multiple_val' => false,
				),
				'provider'     => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'model'        => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'feature'      => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'tokens'       => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'prompt'       => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'date_created' => array(
					'has_ref'          => true,
					'ref_class'        => 'datetime',
					'has_multiple_val' => false,
				),
			);
			self::$schema = apply_filters( 'wpsc_ai_logger_schema', $schema );

			// Prevent modify.
			$prevent_modify       = array( 'id', 'ticket' );
			self::$prevent_modify = apply_filters( 'wpsc_ai_logger_prevent_modify', $prevent_modify );
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
				$tag = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}psmsc_ai_logs WHERE id = %d", $id ), ARRAY_A );
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
					$wpdb->prefix . 'psmsc_ai_logs',
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
		 * @return boolean|WPSC_PS_AI_Logs
		 */
		public static function insert( $data ) {

			global $wpdb;
			$success = $wpdb->insert(
				$wpdb->prefix . 'psmsc_ai_logs',
				$data
			);

			if ( ! $success ) {
				return false;
			}

			$log = new WPSC_PS_AI_Logs( $wpdb->insert_id );
			self::$cache[ $log->id ] = $log;
			return $log;
		}

		/**
		 * Make it inactive so that garbage collector will delete files associated in
		 * background and then delete the record. This will improve its performance.
		 *
		 * @param WPSC_PS_AI_Logs $log - log object.
		 * @return boolean
		 */
		public static function destroy( $log ) {

			global $wpdb;
			$success = $wpdb->delete(
				$wpdb->prefix . 'psmsc_ai_logs',
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

			$sql   = 'SELECT t.* FROM ' . $wpdb->prefix . 'psmsc_ai_logs t ';
			$where = self::get_where( $filter );

			// Add table alias to orderby.
			$filter['orderby'] = 't.' . $filter['orderby'];

			$order = WPSC_Functions::parse_order( $filter );
			$limit = WPSC_Functions::parse_limit( $filter );

			$group_by = 'GROUP BY t.id ';

			$sql = $sql . $where . $group_by . $order . $limit;
			$results     = $wpdb->get_results( $sql, ARRAY_A );

			// total results.
			$sql = 'SELECT count(DISTINCT t.id) FROM ' . $wpdb->prefix . 'psmsc_ai_logs t ';
			$total_items = $wpdb->get_var( $sql . $where );

			$response = WPSC_Functions::parse_response( $results, $total_items, $filter );

			// Return array.
			if ( ! $is_object ) {
				return $response;
			}

			// create and return array of objects.
			$temp_results = array();
			foreach ( $response['results'] as $customer ) {

				$ob   = new WPSC_PS_AI_Logs();
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

			$where = '';

			// Set user defined filters.
			$meta_query = isset( $filter['meta_query'] ) && $filter['meta_query'] ? $filter['meta_query'] : array();
			if ( $meta_query ) {
				$meta_query = WPSC_Functions::parse_user_filters( __CLASS__, $meta_query );
				$where      = $meta_query . ' ';
			}

			return $where ? 'WHERE ' . $where : '';
		}

		/**
		 * Load current class to reference classes
		 *
		 * @param array $classes - Associative array of class names indexed by its slug.
		 * @return array
		 */
		public static function load_ref_class( $classes ) {

			$classes['wpsc_ps_ai_logs'] = array(
				'class'    => __CLASS__,
				'save-key' => 'id',
			);
			return $classes;
		}

		/**
		 * Get the sum of tokens for a specific customer.
		 *
		 * @param int $customer_id The customer ID to filter logs.
		 * @return int|null The total tokens for the customer, or null if none found.
		 */
		public static function get_token_count_by_id( $customer_id ) {

			global $wpdb;
			$result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT SUM(l.tokens) AS total_tokens, COUNT(*) AS requests_count FROM {$wpdb->prefix}psmsc_ai_logs l WHERE l.customer = %d",
					$customer_id
				),
				ARRAY_A
			);
			return array(
				'total_tokens'   => intval( $result['total_tokens'] ),
				'requests_count' => intval( $result['requests_count'] ),
			);
		}

		/**
		 * Get the sum of tokens for a specific customer within a given date range.
		 *
		 * @param string $from_date From date (inclusive), in a format accepted by MySQL.
		 * @param string $to_date   To date (inclusive), in a format accepted by MySQL.
		 * @return array {
		 *   @type int $total_tokens   Total number of tokens used in the specified period.
		 *    @type int $requests_count Number of requests made in the specified period.
		 * }
		 */
		public static function get_usage_count_by_date( $from_date, $to_date ) {

			global $wpdb;
			$result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT SUM(l.tokens) AS total_tokens, COUNT(*) AS requests_count FROM {$wpdb->prefix}psmsc_ai_logs l WHERE l.date_created BETWEEN %s AND %s",
					$from_date,
					$to_date
				),
				ARRAY_A
			);
			return array(
				'total_tokens'   => intval( $result['total_tokens'] ),
				'requests_count' => intval( $result['requests_count'] ),
			);
		}

		/**
		 * Get usage statistics (total tokens and number of requests) within a given date range for a specific customer.
		 *
		 * @param int    $customer_id The customer ID to filter logs.
		 * @param string $from_date From date (inclusive), in a format accepted by MySQL.
		 * @param string $to_date   To date (inclusive), in a format accepted by MySQL.
		 * @return array {
		 *   @type int $total_tokens   Total number of tokens used in the specified period.
		 *    @type int $requests_count Number of requests made in the specified period.
		 * }
		 */
		public static function get_usage_count_by_date_and_id( $customer_id, $from_date, $to_date ) {

			global $wpdb;
			$result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT SUM(l.tokens) AS total_tokens, COUNT(*) AS requests_count FROM {$wpdb->prefix}psmsc_ai_logs l WHERE l.customer = %d AND l.date_created BETWEEN %s AND %s",
					$customer_id,
					$from_date,
					$to_date
				),
				ARRAY_A
			);
			return array(
				'total_tokens'   => intval( $result['total_tokens'] ),
				'requests_count' => intval( $result['requests_count'] ),
			);
		}

		/**
		 * Get counts and sum of tokens for each distinct feature value (e.g., ticket_summary, reply_polish).
		 *
		 * @param string $from_date - from date.
		 * @param string $to_date - to date.
		 * @return array[] Array of grouped results.
		 */
		public static function get_count_by_features( $from_date = '', $to_date = '' ) {

			global $wpdb;

			$table = $wpdb->prefix . 'psmsc_ai_logs';
			$where  = '1=1';
			$params = array();

			if ( ! empty( $from_date ) && ! empty( $to_date ) ) {

				$from_date = sanitize_text_field( $from_date );
				$to_date   = sanitize_text_field( $to_date );

				$where   .= ' AND l.date_created BETWEEN %s AND %s';
				$params[] = $from_date;
				$params[] = $to_date;
			}

			$sql = " SELECT l.feature,
				COUNT(*) AS counts,
				COALESCE(SUM(l.tokens),0) AS total_tokens
				FROM {$table} l
				WHERE {$where}
				GROUP BY l.feature
			";

			$query = ! empty( $params ) ? $wpdb->prepare( $sql, $params ) : $sql;
			$results = $wpdb->get_results( $query, ARRAY_A );
			return is_array( $results ) ? $results : array();
		}
	}
endif;

WPSC_PS_AI_Logs::init();
