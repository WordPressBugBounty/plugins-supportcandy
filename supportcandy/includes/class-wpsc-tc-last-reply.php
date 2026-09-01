<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_TC_Last_Reply' ) ) :

	final class WPSC_TC_Last_Reply {

		/**
		 * Initialize the class
		 *
		 * @return void
		 */
		public static function init() {

			// ticket conditions.
			add_filter( 'wpsc_ticket_conditions', array( __CLASS__, 'load_ticket_condition' ) );
			add_action( 'wpsc_tc_print_operators', array( __CLASS__, 'tc_print_operators' ), 10, 2 );
			add_action( 'wpsc_tc_print_operand', array( __CLASS__, 'tc_print_operand' ), 10, 3 );
			add_filter( 'wpsc_tc_is_valid', array( __CLASS__, 'tc_is_valid' ), 10, 4 );
		}

		/**
		 * Load ticket condition for last reply
		 *
		 * @param array $conditions - conditions filter array.
		 * @return array
		 */
		public static function load_ticket_condition( $conditions ) {

			$conditions['last_reply'] = esc_attr__( 'Last Reply', 'supportcandy' );
			return $conditions;
		}

		/**
		 * Print operator for last reply.
		 *
		 * @param string $slug - slug to check.
		 * @param array  $filter - preset condition.
		 * @return void
		 */
		public static function tc_print_operators( $slug, $filter ) {

			if ( $slug != 'last_reply' ) {
				return;
			}

			?>
			<div class="item conditional">
				<select class="operator" onchange="wpsc_tc_get_operand(this, '<?php echo esc_attr( $slug ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_tc_get_operand' ) ); ?>');">
					<option value=""><?php echo esc_attr( wpsc__( 'Compare As', 'supportcandy' ) ); ?></option>
					<option <?php isset( $filter['operator'] ) && selected( $filter['operator'], 'LIKE' ); ?> value="LIKE"><?php echo esc_attr( wpsc__( 'Has Words', 'supportcandy' ) ); ?></option>
					<option <?php isset( $filter['operator'] ) && selected( $filter['operator'], 'NOT LIKE' ); ?> value="NOT LIKE"><?php echo esc_attr( wpsc__( 'Does Not Have Words', 'supportcandy' ) ); ?></option>
				</select>
			</div>
			<?php
		}

		/**
		 * Print operand for last reply.
		 *
		 * @param string $slug - slug to check.
		 * @param string $operator - operator value.
		 * @param array  $filter - preset condition.
		 * @return void
		 */
		public static function tc_print_operand( $slug, $operator, $filter ) {

			if ( $slug != 'last_reply' ) {
				return;
			}

			$value = isset( $filter['operand_val_1'] ) ? stripslashes( $filter['operand_val_1'] ) : '';
			?>
			<div class="item conditional operand single">
				<textarea class="operand_val_1" placeholder="<?php esc_attr_e( 'One condition per line!', 'supportcandy' ); ?>" style="width: 100%;"><?php echo esc_attr( $value ); ?></textarea>
			</div>
			<?php
		}

		/**
		 * Check whether condition for the last reply is valid
		 *
		 * @param boolean     $is_valid - filter value.
		 * @param string      $slug - slug to check.
		 * @param array       $condition - condition to check.
		 * @param WPSC_Ticket $ticket - ticket object on which condition to check.
		 * @return boolean
		 */
		public static function tc_is_valid( $is_valid, $slug, $condition, $ticket ) {

			if ( $slug != 'last_reply' ) {
				return $is_valid;
			}

			$threads = WPSC_Thread::find(
				array(
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'ticket',
							'compare' => '=',
							'val'     => $ticket->id,
						),
						array(
							'slug'    => 'type',
							'compare' => '=',
							'val'     => 'reply',
						),
					),
					'orderby'        => 'id',
					'order'          => 'DESC',
					'items_per_page' => 1,
				)
			);

			if ( empty( $threads['results'] ) ) {
				return $condition['operator'] == 'NOT LIKE';
			}

			$last_reply = $threads['results'][0];
			$value = strtolower( stripslashes( wp_strip_all_tags( $last_reply->body ) ) );
			$terms = array_filter(
				array_map(
					function ( $term ) {
						return strtolower( trim( $term ) );
					},
					explode( PHP_EOL, $condition['operand_val_1'] )
				)
			);

			switch ( $condition['operator'] ) {

				case 'LIKE':
					$is_valid = false;
					foreach ( $terms as $term ) {
						$index = strpos( $value, trim( stripslashes( $term ) ) );
						if ( is_numeric( $index ) ) {
							$is_valid = true;
							break;
						}
					}
					break;

				case 'NOT LIKE':
					$is_valid = true;
					foreach ( $terms as $term ) {
						$index = strpos( $value, trim( stripslashes( $term ) ) );
						if ( is_numeric( $index ) ) {
							$is_valid = false;
							break;
						}
					}
					break;
			}

			return $is_valid;
		}
	}
endif;

WPSC_TC_Last_Reply::init();
