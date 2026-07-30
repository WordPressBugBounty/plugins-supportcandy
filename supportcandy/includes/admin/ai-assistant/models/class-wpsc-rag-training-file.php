<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_RAG_Training_File' ) ) :

	final class WPSC_RAG_Training_File {

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
		public static $schema;

		/**
		 * Prevent fields to modify
		 *
		 * @var array
		 */
		public static $prevent_modify;

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
				'id'               => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'status'           => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'provider'         => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'source'           => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'source_id'        => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'doc_source'       => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'name'             => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'file_path'        => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'provider_file_id' => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'meta_data'        => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
				'post_updated_on'  => array(
					'has_ref'          => true,
					'ref_class'        => 'datetime',
					'has_multiple_val' => false,
				),
				'date_updated'     => array(
					'has_ref'          => true,
					'ref_class'        => 'datetime',
					'has_multiple_val' => false,
				),
				'date_created'     => array(
					'has_ref'          => true,
					'ref_class'        => 'datetime',
					'has_multiple_val' => false,
				),
			);

			self::$schema = $schema;

			// Prevent modify.
			$prevent_modify       = array( 'id' );
			self::$prevent_modify = apply_filters( 'wpsc_ai_training_prevent_modify', $prevent_modify );
		}

		/**
		 * Constructor function
		 *
		 * @param int $id - optional record id.
		 */
		public function __construct( $id = 0 ) {

			global $wpdb;

			$id = intval( $id );

			if ( isset( self::$cache[ $id ] ) ) {
				$this->data = self::$cache[ $id ]->data;
				return;
			}

			if ( $id > 0 ) {

				$ai_training = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}psmsc_ai_training WHERE id = %d",
						$id
					),
					ARRAY_A
				);
				if ( ! is_array( $ai_training ) ) {
					return;
				}

				foreach ( $ai_training as $key => $val ) {
					$this->data[ $key ] = $val !== null ? $val : '';
				}

				self::$cache[ $id ] = $this;
			}
		}

		/**
		 * Magic get function to use with object arrow function
		 *
		 * @param mixed $var_name - (Required) property slug.
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
		 * @param mixed $var_name - (Required) property slug.
		 * @param mixed $value - (Required) value to set for a property.
		 * @return void
		 */
		public function __set( $var_name, $value ) {

			if (
				! isset( $this->data[ $var_name ] ) ||
				in_array( $var_name, self::$prevent_modify )
			) {
				return;
			}

			$data_val = '';
			if ( self::$schema[ $var_name ]['has_multiple_val'] && is_array( $value ) ) {

				$data_vals = array_map(
					fn( $val ) => is_object( $val ) ? WPSC_Functions::set_object( self::$schema[ $var_name ]['ref_class'], $val ) : $val,
					$value
				);

				$data_val = $data_vals ? implode( '|', $data_vals ) : '';

			} else {

				$data_val = is_object( $value ) ? WPSC_Functions::set_object( self::$schema[ $var_name ]['ref_class'], $value ) : $value;
			}

			if ( $this->data[ $var_name ] == $data_val ) {
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

			if ( empty( $this->data['id'] ) ) {
				return false;
			}

			$data = $this->data;

			unset( $data['id'] );
			$success = $wpdb->update(
				$wpdb->prefix . 'psmsc_ai_training',
				$data,
				array( 'id' => $this->data['id'] )
			);

			$this->is_modified        = false;
			self::$cache[ $this->id ] = $this;
			return $success ? true : false;
		}

		/**
		 * Insert new record
		 *
		 * @param array $data - insert data.
		 * @return WPSC_RAG_Training_File
		 */
		public static function insert( $data ) {

			global $wpdb;

			$success = $wpdb->insert(
				$wpdb->prefix . 'psmsc_ai_training',
				$data
			);

			if ( ! $success ) {
				return false;
			}

			$cl = new WPSC_RAG_Training_File( $wpdb->insert_id );
			return $cl;
		}

		/**
		 * Delete record of given ID
		 *
		 * @param WPSC_RAG_Training_File $ai_training - AI training object.
		 * @return boolean
		 */
		public static function destroy( $ai_training ) {

			global $wpdb;

			$success = $wpdb->delete(
				$wpdb->prefix . 'psmsc_ai_training',
				array( 'id' => $ai_training->id )
			);
			if ( ! $success ) {
				return false;
			}

			unset( self::$cache[ $ai_training->id ] );
			return true;
		}

		/**
		 * Set data to create new object using direct data. Used in find method
		 *
		 * @param array $data - object data to set.
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

			$filter['items_per_page'] = isset( $filter['items_per_page'] ) ? $filter['items_per_page'] : 0;
			$filter['page_no']        = isset( $filter['page_no'] ) ? $filter['page_no'] : 1;
			$filter['orderby']        = isset( $filter['orderby'] ) ? $filter['orderby'] : 'id';
			$filter['order']          = isset( $filter['order'] ) ? $filter['order'] : 'ASC';

			$sql   = 'SELECT * FROM ' . $wpdb->prefix . 'psmsc_ai_training ait ';
			$where = self::get_where( $filter );

			$order = WPSC_Functions::parse_order( $filter );
			$limit = WPSC_Functions::parse_limit( $filter );

			$sql = $sql . $where . $order . $limit;
			$results = $wpdb->get_results( $sql, ARRAY_A );

			// total results.
			$sql = 'SELECT count(DISTINCT ait.id) FROM ' . $wpdb->prefix . 'psmsc_ai_training ait ';
			$total_items = $wpdb->get_var( $sql . $where );

			$response = WPSC_Functions::parse_response( $results, $total_items, $filter );

			// Return array.
			if ( ! $is_object ) {
				return $response;
			}

			// create and return array of objects.
			$temp_results = array();
			foreach ( $response['results'] as $cl ) {

				$ob   = new WPSC_RAG_Training_File();
				$data = array();
				foreach ( $cl as $key => $val ) {
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

				// AI training table cols.
				foreach ( self::$schema as $slug => $schema ) {
					if ( ! $schema['has_ref'] && $slug != 'id' ) {
						$search_query[] = $wpdb->prepare( 'CONVERT(ait.' . $slug . ' USING utf8) LIKE %s', $like );
					}
				}
				$where[] = implode( ' OR ', $search_query );
			}

			return 'WHERE (' . implode( ') AND (', $where ) . ') ';
		}

		/**
		 * Load current class to ref classes
		 *
		 * @param array $classes - ref classes array.
		 * @return array
		 */
		public static function load_ref_class( $classes ) {

			$classes['wpsc_ai_training'] = array(
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

			$sql   = 'SELECT COUNT(DISTINCT ait.id) FROM ' . $wpdb->prefix . 'psmsc_ai_training ait ';
			$where = self::get_where( $filter );

			$sql = $sql . $where;

			return (int) $wpdb->get_var( $sql );
		}

		/**
		 * Pluck specific column values based on given filters
		 *
		 * @param string $column - column name to pluck.
		 * @param array  $filter - array containing array items like search, where, orderby, order, page_no, items_per_page, etc.
		 * @return array
		 */
		public static function pluck( $column, $filter = array() ) {

			global $wpdb;

			if ( empty( $column ) ) {
				return array();
			}

			// Validate column using schema.
			if ( ! array_key_exists( $column, self::$schema ) ) {
				return array();
			}

			$table = $wpdb->prefix . 'psmsc_ai_training';

			$filter['orderby'] = isset( $filter['orderby'] ) ? $filter['orderby'] : 'id';
			$filter['order']   = isset( $filter['order'] ) ? $filter['order'] : 'ASC';

			$sql   = "SELECT DISTINCT {$column} FROM {$table} ait ";
			$where = self::get_where( $filter );
			$order = WPSC_Functions::parse_order( $filter );
			$limit = WPSC_Functions::parse_limit( $filter );

			$sql = $sql . $where . $order . $limit;

			return $wpdb->get_col( $sql );
		}

		/**
		 * Safe delete record of given ID. If provider file id is available then update status to delete otherwise hard delete.
		 *
		 * @param WPSC_RAG_Training_File $ai_training_data - AI training object.
		 * @return boolean
		 */
		public static function safe_delete( $ai_training_data ) {

			if ( ! $ai_training_data->provider_file_id ) {
				return self::destroy( $ai_training_data );
			} else {
				$ai_training_data->status = WPSC_PS_AIT_Status::DELETE;
				$ai_training_data->save();
				return true;
			}
			return false;
		}
	}
endif;
WPSC_RAG_Training_File::init();
