<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Current_Agent_Profile' ) ) :

	final class WPSC_Current_Agent_Profile {

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Get aget profile layout.
			add_action( 'wp_ajax_wpsc_get_agent_profile', array( __CLASS__, 'get_agent_profile' ) );
			add_action( 'wp_ajax_nopriv_wpsc_get_agent_profile', array( __CLASS__, 'get_agent_profile' ) );

			// Set agent general settings.
			add_action( 'wp_ajax_wpsc_set_agent_settings', array( __CLASS__, 'set_agent_settings' ) );
			add_action( 'wp_ajax_nopriv_wpsc_set_agent_settings', array( __CLASS__, 'set_agent_settings' ) );

			// Get agent leaves.
			add_action( 'wp_ajax_wpsc_get_ap_leaves_actions', array( __CLASS__, 'get_leaves_actions' ) );
			add_action( 'wp_ajax_nopriv_wpsc_get_ap_leaves_actions', array( __CLASS__, 'get_leaves_actions' ) );

			// Set agent leaves.
			add_action( 'wp_ajax_wpsc_set_ap_leaves_actions', array( __CLASS__, 'set_leaves_actions' ) );
			add_action( 'wp_ajax_nopriv_wpsc_set_ap_leaves_actions', array( __CLASS__, 'set_leaves_actions' ) );
		}

		/**
		 * Get current agent profile layout
		 *
		 * @return void
		 */
		public static function get_agent_profile() {

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$menu_tabs = array(
				'general' => array(
					'slug'     => 'general',
					'icon'     => 'control',
					'label'    => esc_attr__( 'General Settings', 'supportcandy' ),
					'callback' => 'get_general_settings',
				),
			);

			$wh_settings = get_option( 'wpsc-wh-settings' );

			if ( $wh_settings['allow-agent-modify-wh'] ) {
				$menu_tabs['working-hrs'] = array(
					'slug'     => 'working-hrs',
					'icon'     => 'clock',
					'label'    => esc_attr__( 'Working hours', 'supportcandy' ),
					'callback' => 'get_working_hrs',
				);

				$menu_tabs['exceptions'] = array(
					'slug'     => 'exceptions',
					'icon'     => 'clock',
					'label'    => esc_attr__( 'Exceptions', 'supportcandy' ),
					'callback' => 'get_exceptions',
				);
			}

			if ( $wh_settings['allow-agent-modify-leaves'] ) {
				$menu_tabs['leaves'] = array(
					'slug'     => 'leaves',
					'icon'     => 'calendar-times',
					'label'    => esc_attr__( 'Leaves', 'supportcandy' ),
					'callback' => 'get_leaves',
				);
			}

			$menu_tabs = apply_filters( 'wpsc_ap_menu_items', $menu_tabs );
			?>
			<div class="wpsc-tff wpsc-xs-12 wpsc-sm-12 wpsc-md-12 wpsc-lg-12">
				<div class="wpsc-up-container">
					<div class="wpsc-up-picture img">
						<?php echo get_avatar( $current_user->customer->email, 50 ); ?>
					</div>
					<div class="wpsc-up-info">
						<div class="wpsc-up-name"><?php echo esc_attr( $current_user->customer->name ); ?></div>
						<div class="wpsc-up-email"><?php echo esc_attr( $current_user->customer->email ); ?></div>
					</div>
				</div>
			</div>
			<div class="wpsc-up-section" style="margin: 0px 20px;">
				<div class="wpsc-up-tab">
					<?php
					foreach ( $menu_tabs as $tab_key => $tab_menu ) {
						?>
						<label class="wpsc-profile-tab <?php echo ( $tab_key === 'general' ) ? 'active' : ''; ?>" data-toggle-target="wpsc-up-<?php echo esc_attr( $tab_key ); ?>"><?php echo esc_attr( $tab_menu['label'] ); ?></label>
						<?php
					}
					?>
				</div>
				<hr>
				<section>
					<?php
					foreach ( $menu_tabs as $section_key => $section_menu ) {
						?>
						<div class="wpsc-up-content wpsc-up-<?php echo esc_attr( $section_key ); ?> <?php echo ( $section_key === 'general' ) ? 'active' : ''; ?>">
							<?php echo esc_attr( self::{$section_menu['callback']}() ); ?>
						</div>
						<?php
					}
					?>
				</section>
			</div>
			<script>
				jQuery('.wpsc-profile-tab').on('click', function(event) {

					event.preventDefault();
					if ( jQuery(this).hasClass('active') ) return;

					jQuery(this).toggleClass('active');
					var temp = jQuery(this).data('toggle-target');
					jQuery('.wpsc-up-content').removeClass('active').filter('.'+temp).addClass('active');
					jQuery('.wpsc-profile-tab').not(this).removeClass('active');
					if( temp == 'wpsc-up-leaves' ) {
						wpsc_render_leaves_calender();
					}
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Get general settings
		 *
		 * @return void
		 */
		public static function get_general_settings() {

			$current_user = WPSC_Current_User::$current_user;
			$editor       = $current_user->agent->get_signature_editor();
			$editor       = $editor ? $editor : 'html';
			$rich_editing = get_user_meta( $current_user->user->ID, 'rich_editing', true );
			$rich_editing = filter_var( $rich_editing, FILTER_VALIDATE_BOOLEAN );

			if ( ! $current_user->is_agent ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$default_filters = get_option( 'wpsc-atl-default-filters' );
			$saved_filters   = $current_user->get_saved_filters();
			$default_filter  = $current_user->agent->get_default_filter();
			$tab = $current_user->agent->get_default_tab();
			?>
			<form class="wpsc-agent-settings" onsubmit="return false;" action="#">
				<div class="wpsc-tff wpsc-xs-12 wpsc-sm-12 wpsc-md-12 wpsc-lg-12">
					<div class="wpsc-tff-label">
						<span class="name"><?php esc_attr_e( 'Signature', 'supportcandy' ); ?></span>
					</div>
					<span class="extra-info"><?php esc_attr_e( 'Signature used for emails', 'supportcandy' ); ?></span>
					<div class = "textarea-container">
						<?php
						if ( $rich_editing ) {
							?>
							<div class = "wpsc_tinymce_editor_btns">
								<div class="inner-container">
									<button class="visual wpsc-switch-editor <?php echo esc_attr( $editor ) == 'html' ? 'active' : ''; ?>" type="button" onclick="wpsc_get_tinymce(this, 'signature-html', 'signature_body');"><?php esc_attr_e( 'Visual', 'supportcandy' ); ?></button>
									<button class="text wpsc-switch-editor <?php echo esc_attr( $editor ) == 'text' ? 'active' : ''; ?>" type="button" onclick="wpsc_get_textarea(this, 'signature-html')"><?php esc_attr_e( 'Text', 'supportcandy' ); ?></button>
								</div>
							</div>
							<?php
						}
						?>
						<textarea name="signature-html" id="signature-html" class="wpsc_textarea"><?php echo esc_attr( stripslashes( $current_user->agent->get_signature() ) ); ?></textarea>
					</div>
				</div>
				<div class="wpsc-tff wpsc-xs-12 wpsc-sm-12 wpsc-md-12 wpsc-lg-12">
					<div class="wpsc-tff-label">
						<span class="name"><?php esc_attr_e( 'Default filter', 'supportcandy' ); ?></span>
					</div>
					<span class="extra-info"><?php esc_attr_e( 'Default filter for ticket list', 'supportcandy' ); ?></span>
					<select name="default-filter">
						<optgroup label="<?php esc_attr_e( 'Default filters', 'supportcandy' ); ?>">
							<?php
							foreach ( $default_filters as $index => $filter ) :
								if ( ! $filter['is_enable'] ) {
									continue;
								}
								$selected = $default_filter == $index || $default_filter == 'default-' . $index ? 'selected' : '';
								?>
								<option <?php echo esc_attr( $selected ); ?> value="<?php echo is_numeric( $index ) ? 'default-' . esc_attr( $index ) : esc_attr( $index ); ?>">
									<?php
									$filter_label = $filter['label'] ? WPSC_Translations::get( 'wpsc-atl-' . $index, stripslashes( $filter['label'] ) ) : stripslashes( $filter['label'] );
									echo esc_attr( $filter_label );
									?>
								</option>
								<?php
							endforeach;
							?>
						</optgroup>
						<optgroup label="<?php esc_attr_e( 'Saved filters', 'supportcandy' ); ?>">
							<?php
							foreach ( $saved_filters as $index => $filter ) :
								?>
								<option <?php selected( $default_filter, 'saved-' . $index ); ?> value="saved-<?php echo esc_attr( $index ); ?>"><?php echo esc_attr( $filter['label'] ); ?></option>
								<?php
							endforeach;
							?>
						</optgroup>
					</select>
				</div>
				<div class="wpsc-tff wpsc-xs-12 wpsc-sm-12 wpsc-md-12 wpsc-lg-12">
					<div class="wpsc-tff-label">
						<span class="name"><?php esc_attr_e( 'Default tab', 'supportcandy' ); ?></span>
					</div>
					<span class="extra-info"><?php esc_attr_e( 'Select dafault section', 'supportcandy' ); ?></span>
					<select id="wpsc-dt" name="default-tab">
						<option <?php selected( $tab, 'dashboard' ); ?> value="dashboard"><?php esc_attr_e( 'Dashboard', 'supportcandy' ); ?></option>
						<option <?php selected( $tab, 'ticket-list' ); ?> value="ticket-list"><?php esc_attr_e( 'Ticket list', 'supportcandy' ); ?></option>
						<option <?php selected( $tab, 'new-ticket' ); ?> value="new-ticket"><?php esc_attr_e( 'New ticket', 'supportcandy' ); ?></option>
					</select>
				</div>
				<div class="wpsc-tff wpsc-xs-12 wpsc-sm-12 wpsc-md-12 wpsc-lg-12">
					<div class="submit-container">
						<button class="wpsc-button normal primary" onclick="wpsc_set_agent_settings(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_set_agent_settings' ) ); ?>');"><?php esc_attr_e( 'Save Changes', 'supportcandy' ); ?></button>
					</div>
				</div>
				<input type="hidden" name="action" value="wpsc_set_agent_settings">
				<input type="hidden" id="editor" name="editor" value="<?php echo esc_attr( $editor ); ?>">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_agent_settings' ) ); ?>">

				<script>
					<?php
					if ( $editor == 'html' && $rich_editing ) :
						?>
						jQuery('.wpsc-switch-editor.visual').trigger('click');
						<?php
					endif;
					?>

					function wpsc_get_tinymce(el, selector, body_id){
						jQuery(el).parent().find('.text').removeClass('active');
						jQuery(el).addClass('active');
						tinymce.remove('#'+selector);
						tinymce.init({ 
							selector:'#'+selector,
							body_id: body_id,
							menubar: false,
							statusbar: false,
							height : '200',
							plugins: [
							'lists link image directionality'
							],
							image_advtab: true,
							toolbar: 'bold italic underline blockquote | alignleft aligncenter alignright | bullist numlist | rtl | link image',
							directionality: '<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>',
							branding: false,
							autoresize_bottom_margin: 20,
							browser_spellcheck : true,
							relative_urls : false,
							remove_script_host : false,
							convert_urls : true,
							setup: function (editor) {
							}
						});
						jQuery('#editor').val('html');
					}
					function wpsc_get_textarea(el, selector){
						jQuery(el).parent().find('.visual').removeClass('active');
						jQuery(el).addClass('active');
						tinymce.remove('#'+selector);
						jQuery('#editor').val('text');
					}
					function wpsc_set_agent_settings(el, nonce) {
						var dataform = new FormData(jQuery('form.wpsc-agent-settings')[0]);
						var is_tinymce = (typeof tinyMCE != "undefined") && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden();
						var signature = is_tinymce ? tinyMCE.activeEditor.getContent().trim() : jQuery('#signature-html').val();
						dataform.append('signature', signature);
						dataform.append('_ajax_nonce', nonce);
						jQuery(el).text(supportcandy.translations.please_wait);
						jQuery.ajax({
							url: supportcandy.ajax_url,
							type: 'POST',
							data: dataform,
							processData: false,
							contentType: false,
							error: function (res) {
								window.location.reload();
							},
							success: function (res, textStatus, xhr) {
								window.location.reload();
							}
						});
					}
				</script>
			</form>
			<?php
		}

		/**
		 * Set agent general settings
		 *
		 * @return void
		 */
		public static function set_agent_settings() {

			if ( check_ajax_referer( 'wpsc_set_agent_settings', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( new WP_Error( '001', 'Unauthorized' ), 401 );
			}

			$signature = isset( $_POST['signature'] ) ? wp_kses_post( wp_unslash( $_POST['signature'] ) ) : '';

			// replace new line with br if no text editor.
			$editor = isset( $_POST['editor'] ) ? sanitize_text_field( wp_unslash( $_POST['editor'] ) ) : 'html';
			$current_user->agent->set_signature_editor( $editor );
			$current_user->agent->set_signature( $signature );

			$default_filter = isset( $_POST['default-filter'] ) ? sanitize_text_field( wp_unslash( $_POST['default-filter'] ) ) : 'all';
			$current_user->agent->set_default_filter( $default_filter );

			$default_tab = isset( $_POST['default-tab'] ) ? sanitize_text_field( wp_unslash( $_POST['default-tab'] ) ) : 'dashboard';
			$current_user->agent->set_default_tab( $default_tab );
			wp_die();
		}

		/**
		 * Get working hours
		 *
		 * @return void
		 */
		public static function get_working_hrs() {

			$settings = get_option( 'wpsc-wh-settings', array() );

			$current_user = WPSC_Current_User::$current_user;
			if ( ! (
				WPSC_Functions::is_site_admin() ||
				( $current_user->is_agent && $settings['allow-agent-modify-wh'] )
			) ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$working_hrs = $current_user->agent->get_working_hrs();
			?>

			<form action="#" onsubmit="return false;" class="wpsc-frm-agent-wh">
				<table class="wpsc-working-hrs">
				<?php
				for ( $i = 1; $i <= 7; $i++ ) :
					$start_time = $working_hrs[ $i ]->start_time;
					$end_time   = $working_hrs[ $i ]->end_time;
					$style      = $start_time == 'off' ? 'display: none;' : '';
					?>
						<tr>
							<td class="dayName"><?php echo esc_attr( WPSC_Functions::get_day_name( $i ) ); ?>:</td>
							<td>
								<select class="wpsc-wh-start-time" name="wh[<?php echo esc_attr( $i ); ?>][start_time]">
									<?php WPSC_Working_Hour::get_start_time_slots( $start_time ); ?>
								</select>
							</td>
							<td style="<?php echo esc_attr( $style ); ?>">-</td>
							<td style="<?php echo esc_attr( $style ); ?>">
								<select class="wpsc-wh-end-time" name="wh[<?php echo esc_attr( $i ); ?>][end_time]">
									<?php WPSC_Working_Hour::get_end_time_slots( $start_time, $end_time ); ?>
								</select>
							</td>
						</tr>
						<?php
					endfor;
				?>
				</table>
				<input type="hidden" name="action" value="wpsc_set_agent_wh_hrs">
				<input type="hidden" name="agent_id" value="<?php echo esc_attr( $current_user->agent->id ); ?>">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_agent_wh_hrs' ) ); ?>">
			</form>
			<div class="setting-footer-actions">
				<button 
					class="wpsc-button normal primary margin-right"
					onclick="wpsc_set_agent_wh_hrs(this);">
					<?php esc_attr_e( 'Submit', 'supportcandy' ); ?>
				</button>
			</div>
			<script>
				var end_times = [];
				<?php
					$current_slot     = new DateTime( '2020-01-01 00:15:00' );
					$second_last_slot = new DateTime( '2020-01-01 23:45:00' );
					$last_slot        = new DateTime( '2020-01-01 23:59:59' );

				do {
					$time = $current_slot->format( 'H:i:s' )
					?>
						end_times.push({
							val: '<?php echo esc_attr( $time ); ?>',
							display_val: '<?php echo esc_attr( $current_slot->format( 'H:i' ) ); ?>',
						});
						<?php
						if ( $current_slot == $second_last_slot ) {
							$current_slot->add( new DateInterval( 'PT14M59S' ) );
						} else {
							$current_slot->add( new DateInterval( 'PT15M' ) );
						}
				} while ( $current_slot <= $last_slot );
				?>
				supportcandy.temp.end_times = end_times;

				// Change event
				jQuery('.wpsc-wh-start-time').change(function(){
					var start_time = jQuery(this).val();
					var td1 = jQuery(this).parent().next();
					var td2 = td1.next();
					if (start_time === 'off') {
						td1.hide();
						td2.hide();
						return;
					} else {
						td1.show();
						td2.show();
					}
					var tempArr = start_time.split(":");
					var startDate = new Date(2020, 0, 1, tempArr[0], tempArr[1], tempArr[2]);
					var cmbEndTime = jQuery(this).closest('tr').find('.wpsc-wh-end-time');
					cmbEndTime.find('option').remove();
					jQuery.each(supportcandy.temp.end_times, function(index, end_time){
						var tempArr = end_time.val.split(":");
						var endDate = new Date(2020, 0, 1, tempArr[0], tempArr[1], tempArr[2]);
						if (startDate < endDate) {
							var obj = document.createElement('OPTION');
							var displayVal = document.createTextNode(end_time.display_val);
							obj.setAttribute("value", end_time.val);
							obj.appendChild(displayVal);
							cmbEndTime.append(obj);
						}
					});
				});
			</script>
			<?php
		}

		/**
		 * Get exceptions
		 *
		 * @return void
		 */
		public static function get_exceptions() {

			$settings = get_option( 'wpsc-wh-settings', array() );
			$current_user = WPSC_Current_User::$current_user;
			if ( ! (
				WPSC_Functions::is_site_admin() ||
				( $current_user->is_agent && $settings['allow-agent-modify-wh'] )
			) ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$exceptions = $current_user->agent->get_wh_exceptions();
			$unique_id  = uniqid( 'wpsc_' );
			?>
			<div class="wpsc-exceptions-container" style="padding: 15px !important;">
				<table class="wpsc-setting-tbl <?php echo esc_attr( $unique_id ); ?>">
					<thead>
						<tr>
							<th><?php esc_attr_e( 'Title', 'supportcandy' ); ?></th>
							<th><?php esc_attr_e( 'Date', 'supportcandy' ); ?></th>
							<th><?php esc_attr_e( 'Schedule', 'supportcandy' ); ?></th>
							<th><?php esc_attr_e( 'Actions', 'supportcandy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $exceptions as $exception ) {
							?>
							<tr data-id="<?php echo esc_attr( $exception->id ); ?>">
								<td><?php echo esc_attr( $exception->title ); ?></td>
								<td><?php echo esc_attr( $exception->exception_date->format( 'F d, Y' ) ); ?></td>
								<td>
									<?php
										$start_time = explode( ':', $exception->start_time );
										$start_time = $start_time[0] . ':' . $start_time[1];
										$end_time   = explode( ':', $exception->end_time );
										$end_time   = $end_time[0] . ':' . $end_time[1];
										/* translators: %1$s: start time, %2$s: end time e.g. 04:00 - 05:00 */
										printf( esc_attr__( '%1$s - %2$s', 'supportcandy' ), esc_attr( $start_time ), esc_attr( $end_time ) );
									?>
								</td>
								<td>
									<a class="wpsc-link"><span class="edit <?php echo esc_attr( $unique_id ); ?>"><?php esc_attr_e( 'Edit', 'wpsc-cr' ); ?></span></a> | 
									<a class="wpsc-link"><span class="delete <?php echo esc_attr( $unique_id ); ?>"><?php esc_attr_e( 'Delete', 'supportcandy' ); ?></span></a>
								</td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
				<script>
					// Add datatable
					jQuery('table.<?php echo esc_attr( $unique_id ); ?>').DataTable({
						ordering: false,
						pageLength: 20,
						bLengthChange: false,
						columnDefs: [ 
							{ targets: -1, searchable: false },
							{ targets: '_all', className: 'dt-left' }
						],
						dom: 'Bfrtip',
						buttons: [
							{
								text: '<?php echo esc_attr( wpsc__( 'Add new', 'supportcandy' ) ); ?>',
								className: 'wpsc-button small primary',
								action: function ( e, dt, node, config ) {
									jQuery('.wpsc-exceptions-container').html(supportcandy.loader_html);
									var data = { 
										action: 'wpsc_get_add_agent_wh_exception', 
										agent_id: <?php echo esc_attr( $current_user->agent->id ); ?>,
										_ajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_add_agent_wh_exception' ) ); ?>'
									};
									jQuery.post(supportcandy.ajax_url, data, function (response) {
										jQuery('.wpsc-exceptions-container').html(response);
									});
								}
							}
						],
						language: supportcandy.translations.datatables
					});
					// Edit.
					jQuery('span.edit.<?php echo esc_attr( $unique_id ); ?>').click(function(){

						var exception_id = jQuery(this).closest('tr').data('id');
						jQuery('.wpsc-exceptions-container').html(supportcandy.loader_html);
						var data = { 
							action: 'wpsc_get_edit_agent_wh_exception', 
							agent_id: <?php echo esc_attr( $current_user->agent->id ); ?>,
							exception_id,
							_ajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_edit_agent_wh_exception' ) ); ?>'
						};
						jQuery.post(supportcandy.ajax_url, data, function (res) {
							jQuery('.wpsc-exceptions-container').html(res);
						});
					});
					// Delete.
					jQuery('span.delete.<?php echo esc_attr( $unique_id ); ?>').click(function(){

						var flag = confirm(supportcandy.translations.confirm);
						if (!flag) return;
						var exception_id = jQuery(this).closest('tr').data('id');
						jQuery('.wpsc-exceptions-container').html(supportcandy.loader_html);
						var data = { 
							action: 'wpsc_delete_agent_wh_exception', 
							agent_id: <?php echo esc_attr( $current_user->agent->id ); ?>,
							exception_id,
							_ajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'wpsc_delete_agent_wh_exception' ) ); ?>'
						};
						jQuery.post(supportcandy.ajax_url, data, function (res) {
							window.location.reload();
						});
					});
					supportcandy.temp.uniqueId = '<?php echo esc_attr( $unique_id ); ?>';
				</script>
			</div>
			<?php
		}

		/**
		 * Get get leaves
		 *
		 * @return void
		 */
		public static function get_leaves() {

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$wh_settings = get_option( 'wpsc-wh-settings' );
			if ( ! $wh_settings['allow-agent-modify-leaves'] ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			// get non-recurring holidays.
			$non_recurring_holidays = array();
			$holidays               = WPSC_Holiday::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'agent',
							'compare' => '=',
							'val'     => $current_user->agent->id,
						),
						array(
							'slug'    => 'is_recurring',
							'compare' => '=',
							'val'     => 0,
						),
					),
				)
			)['results'];
			foreach ( $holidays as $holiday ) {
				$non_recurring_holidays[] = $holiday->holiday->format( 'Y-m-d' );
			}

			// get recurring holidays.
			$recurring_holidays = array();
			$holidays           = WPSC_Holiday::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'agent',
							'compare' => '=',
							'val'     => $current_user->agent->id,
						),
						array(
							'slug'    => 'is_recurring',
							'compare' => '=',
							'val'     => 1,
						),
					),
				)
			)['results'];
			foreach ( $holidays as $holiday ) {
				$recurring_holidays[] = $holiday->holiday->format( 'm-d' );
			}

			$locale = explode( '_', get_locale() );
			?>
			<div class="wpsc-leaves-container" style="padding: 15px !important;">
				<div id="wpsc-calendar"></div>
				<script>
					supportcandy.temp.holidayList = {
						'nonRecurring': <?php echo wp_json_encode( $non_recurring_holidays ); ?>,
						'recurring': <?php echo wp_json_encode( $recurring_holidays ); ?>
					};
					function wpsc_render_leaves_calender() {
						var calendarEl = document.getElementById('wpsc-calendar');
						var calendar = new FullCalendar.Calendar(calendarEl, {
							initialView: 'dayGridMonth',
							selectable: true,
							locale: '<?php echo esc_attr( $locale[0] ); ?>',
							dayCellDidMount: function(args) {

								// non-recurring.
								var dateToCompare = args.date.toLocaleDateString('en-CA');
								if (jQuery.inArray(dateToCompare, supportcandy.temp.holidayList.nonRecurring) != -1) {
									jQuery(args.el).css('background-color', '#f0932b');
								}

								// recurring.
								var strArr = dateToCompare.split('-');
								if (jQuery.inArray(strArr[1] + '-' + strArr[2], supportcandy.temp.holidayList.recurring) != -1) {
									jQuery(args.el).css('background-color', '#eb4d4b');
								}

							},
							select: function(info) {

								var start = info.start;
								var end = info.end;
								end.setDate(end.getDate()-1);

								var dateSelected = [];
								do {
									var d = start.toLocaleDateString('en-CA');
									dateSelected.push(d);
									start.setDate(parseInt(start.getDate())+1);
								} while (start <= end);

								wpsc_get_ap_leaves_actions(dateSelected, '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_ap_leaves_actions' ) ); ?>');
							}
						}).render();
					}
				</script>
			</div>
			<?php
		}

		/**
		 * Load leaves actions for selected dates
		 *
		 * @return void
		 */
		public static function get_leaves_actions() {

			if ( check_ajax_referer( 'wpsc_get_ap_leaves_actions', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$wh_settings = get_option( 'wpsc-wh-settings' );
			if ( ! $wh_settings['allow-agent-modify-leaves'] ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$date_selected = isset( $_POST['dateSelected'] ) ? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['dateSelected'] ) ) ) : array();
			if ( ! $date_selected ) {
				wp_send_json_error( 'Bad Request', 401 );
			}

			$title     = esc_attr__( 'Add/Delete Holidays', 'supportcandy' );
			$unique_id = uniqid( 'wpsc_' );

			ob_start();
			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-ap-holiday-actions">

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Action', 'supportcandy' ); ?></label>
					</div>
					<select class="<?php echo esc_attr( $unique_id ); ?>" name="holiday-action">
						<option value="add"><?php esc_attr_e( 'Add new holidays', 'supportcandy' ); ?></option>
						<option value="delete"><?php esc_attr_e( 'Delete existing holidays', 'supportcandy' ); ?></option>
					</select>
				</div>

				<div class="wpsc-input-group is-recurring">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Repeate every year', 'supportcandy' ); ?></label>
					</div>
					<select name="is-recurring">
						<option value="0"><?php esc_attr_e( 'No', 'supportcandy' ); ?></option>
						<option value="1"><?php esc_attr_e( 'Yes', 'supportcandy' ); ?></option>
					</select>
				</div>

				<input type="hidden" name="action" value="wpsc_set_ap_leaves_actions">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_ap_leaves_actions' ) ); ?>">

			</form>
			<script>
				jQuery('.<?php echo esc_attr( $unique_id ); ?>').change(function(){
					if (jQuery(this).val() == 'add') {
						jQuery('.wpsc-input-group.is-recurring').show();
					} else {
						jQuery('.wpsc-input-group.is-recurring').hide();
					}
				});
			</script>
			<?php
			$body = ob_get_clean();

			ob_start();
			?>
			<button class="wpsc-button small primary" onclick="wpsc_set_ap_leaves_actions(this);">
				<?php esc_attr_e( 'Submit', 'supportcandy' ); ?>
			</button>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php esc_attr_e( 'Cancel', 'supportcandy' ); ?>
			</button>
			<?php
			$footer = ob_get_clean();

			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);

			wp_send_json( $response );
		}

		/**
		 * Set leaves actions
		 *
		 * @return void
		 */
		public static function set_leaves_actions() {

			if ( check_ajax_referer( 'wpsc_set_ap_leaves_actions', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			global $wpdb;

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$wh_settings = get_option( 'wpsc-wh-settings' );
			if ( ! $wh_settings['allow-agent-modify-leaves'] ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$date_selected = isset( $_POST['dateSelected'] ) ? sanitize_text_field( wp_unslash( $_POST['dateSelected'] ) ) : '';
			$date_selected = $date_selected ? array_filter( array_map( 'sanitize_text_field', explode( ',', $date_selected ) ) ) : array();
			if ( ! $date_selected ) {
				wp_send_json_error( 'Bad Request', 401 );
			}

			$action = isset( $_POST['holiday-action'] ) ? sanitize_text_field( wp_unslash( $_POST['holiday-action'] ) ) : '';
			if ( ! $action ) {
				wp_send_json_error( 'Bad Request', 401 );
			}

			$is_recurring = isset( $_POST['is-recurring'] ) ? intval( $_POST['is-recurring'] ) : '';
			if ( ! is_numeric( $is_recurring ) ) {
				wp_send_json_error( 'Bad Request', 401 );
			}

			foreach ( $date_selected as $date ) {

				$date = new DateTime( $date . ' 00:00:00' );

				// delete non-recurring record if exists.
				$wpdb->delete(
					$wpdb->prefix . 'psmsc_holidays',
					array(
						'holiday' => $date->format( 'Y-m-d H:i:s' ),
						'agent'   => $current_user->agent->id,
					)
				);

				// delete recurring record if exists.
				$wpdb->query( "DELETE FROM {$wpdb->prefix}psmsc_holidays WHERE agent=" . $current_user->agent->id . ' AND DAYOFMONTH(holiday)=' . $date->format( 'd' ) . ' AND MONTH(holiday)=' . $date->format( 'm' ) . ' AND is_recurring=1' );

				// add record.
				if ( $action == 'add' ) {
					WPSC_Holiday::insert(
						array(
							'agent'        => $current_user->agent->id,
							'holiday'      => $date->format( 'Y-m-d H:i:s' ),
							'is_recurring' => $is_recurring,
						)
					);
				}
			}

			// get non-recurring holidays.
			$non_recurring_holidays = array();
			$holidays               = WPSC_Holiday::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'agent',
							'compare' => '=',
							'val'     => $current_user->agent->id,
						),
						array(
							'slug'    => 'is_recurring',
							'compare' => '=',
							'val'     => 0,
						),
					),
				)
			)['results'];
			foreach ( $holidays as $holiday ) {
				$non_recurring_holidays[] = $holiday->holiday->format( 'Y-m-d' );
			}

			// get recurring holidays.
			$recurring_holidays = array();
			$holidays           = WPSC_Holiday::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'agent',
							'compare' => '=',
							'val'     => $current_user->agent->id,
						),
						array(
							'slug'    => 'is_recurring',
							'compare' => '=',
							'val'     => 1,
						),
					),
				)
			)['results'];
			foreach ( $holidays as $holiday ) {
				$recurring_holidays[] = $holiday->holiday->format( 'm-d' );
			}

			$response = array(
				'action'       => $action,
				'is_recurring' => $is_recurring,
				'holidayList'  => array(
					'nonRecurring' => $non_recurring_holidays,
					'recurring'    => $recurring_holidays,
				),
			);

			wp_send_json( $response, 200 );
		}
	}
endif;

WPSC_Current_Agent_Profile::init();
