<?php
if ( !defined( 'myCRED_VERSION' ) ) exit;
/**
 * Events Manager
 * @since 1.2
 * @version 1.0
 */
if ( !class_exists( 'myCRED_Events_Manager_Gateway' ) && defined( 'EM_VERSION' ) ) {
	class myCRED_Events_Manager_Gateway {

		public $label = '';
		public $prefs;
		public $core;
		public $booking_cols = 0;

		/**
		 * Construct
		 */
		function __construct() {
			// Default settings
			$defaults = array(
				'setup'    => 'off',
				'rate'     => 100,
				'log'      => array(
					'purchase' => __( 'Payment for tickets to %link_with_title%', 'mycred' ),
					'refund'   => __( 'Ticket refund for %link_with_title%', 'mycred' )
				),
				'refund'   => 0,
				'labels'   => array(
					'header' => __( 'Pay using your %_plural% balance', 'mycred' ),
					'button' => __( 'Pay Now', 'mycred' ),
					'link'   => __( 'Pay', 'mycred' )
				),
				'messages' => array(
					'success' => __( 'Thank you for your payment!', 'mycred' ),
					'error'   => __( "I'm sorry but you can not pay for these tickets using %_plural%", 'mycred' )
				)
			);

			// Settings
			$settings = get_option( 'mycred_eventsmanager_gateway_prefs' );
			$this->prefs = mycred_apply_defaults( $defaults, $settings );

			// Load myCRED
			$this->core = mycred_get_settings();
			
			// Apply Whitelabeling
			$this->label = apply_filters( 'mycred_label', myCRED_NAME );
		}

		/**
		 * Load Gateway
		 * @since 1.2
		 * @version 1.0
		 */
		public function load() {
			// Settings
			add_action( 'em_options_page_footer_bookings', array( $this, 'settings_page' ) );
			add_action( 'em_options_save',                 array( $this, 'save_settings' ) );

			// In case gateway has not yet been enabled bail here.
			if ( !$this->use_gateway() ) return;

			// Currency
			add_filter( 'em_get_currencies',               array( $this, 'add_currency' ) );
			if ( $this->single_currency() )
				add_filter( 'em_get_currency_formatted',   array( $this, 'format_price' ), 10, 4 );

			// Adjust Ticket Columns
			add_filter( 'em_booking_form_tickets_cols',       array( $this, 'ticket_columns' ), 10, 2 );
			add_action( 'em_booking_form_tickets_col_mycred', array( $this, 'ticket_col' ), 10, 2     );

			// Add Pay Button
			add_filter( 'em_my_bookings_booking_actions', array( $this, 'add_pay_button' ), 10, 2 );
			add_action( 'em_my_bookings_booking_loop',    array( $this, 'payment_box' )           );
			add_action( 'em_template_my_bookings_footer', array( $this, 'insert_scripting' )      );

			// Ajax Payments
			add_action( 'wp_ajax_mycred-pay-em-booking',  array( $this, 'process_payment' ) );
			if ( $this->prefs['refund'] != 0 )
				add_filter( 'em_booking_set_status', array( $this, 'refunds' ), 10, 2 );
		}

		/**
		 * Add Currency
		 * Adds "Points" as a form of currency
		 * @since 1.2
		 * @version 1.0
		 */
		public function add_currency( $currencies ) {
			$currencies->names['XMY'] = $this->core->plural();
			if ( empty( $this->core->before ) && !empty( $this->core->after ) ) {
				$currencies->symbols['XMY'] = $this->core->after;
				$currencies->symbols['XMY'] = $this->core->after;
			}
			elseif ( !empty( $this->core->before ) && empty( $this->core->after ) ) {
				$currencies->true_symbols['XMY'] = $this->core->before;
				$currencies->true_symbols['XMY'] = $this->core->after;
			}

			return $currencies;
		}

		/**
		 * Format Price
		 * @since 1.2
		 * @version 1.0
		 */
		public function format_price( $formatted_price, $price, $currency, $format ) {
			return $this->core->format_creds( $price );
		}

		/**
		 * Use Gateway
		 * Checks if this gateway has been enabled.
		 * @since 1.2
		 * @version 1.0
		 */
		public function use_gateway() {
			if ( $this->prefs['setup'] == 'off' ) return false;
			return true; 
		}

		/**
		 * Check if using Single Currency
		 * @since 1.2
		 * @version 1.0
		 */
		public function single_currency() {
			if ( $this->prefs['setup'] == 'single' ) return true;
			return false;
		}

		/**
		 * Can Pay Check
		 * Checks if the user can pay for their booking.
		 * @since 1.2
		 * @version 1.0
		 */
		public function can_pay( $EM_Booking ) {
			$EM_Event = $EM_Booking->get_event();
			// You cant pay for free events
			if ( $EM_Event->is_free() ) return false;

			// Only pending events can be paid for
			if ( $EM_Booking->booking_status == 0 && get_option( 'dbem_bookings_user_cancellation' ) && $EM_Event->get_bookings()->has_open_time() ) {
				$balance = $this->core->get_users_cred( $EM_Booking->person->ID );
				if ( $balance <= 0 ) return false;

				$price = $this->core->number( $EM_Booking->booking_price );
				if ( $price == 0 ) return true;
				if ( !$this->single_currency() ) {
					$exchange_rate = $this->prefs['rate'];
					$price = $this->core->number( $exchange_rate*$price );
				}

				if ( $balance-$price < 0 ) return false;

				return true;
			}
			return false;
		}
		
		/**
		 * Has Paid
		 * Checks if the user has paid for booking
		 * @since 1.2
		 * @version 1.0
		 */
		public function has_paid( $EM_Booking ) {
			if ( $this->core->has_entry( 'ticket_purchase', $EM_Booking->event->post_id, $EM_Booking->person->ID, 's:3:"bid";i:' . $EM_Booking->booking_id . ';' ) ) return true;
			return false;
		}

		/**
		 * AJAX: Process Payment
		 * @since 1.2
		 * @version 1.0
		 */
		public function process_payment() {
			// Security
			//check_ajax_referer( 'mycred-pay-em-booking', 'token' );
			
			// Requirements
			if ( !isset( $_POST['booking_id'] ) || !is_user_logged_in() ) die( 'ERROR_1' );
			
			// Get Booking
			$booking_id = $_POST['booking_id'];
			$booking = em_get_booking( $booking_id );
			
			// User
			if ( $this->core->exclude_user( $booking->person->ID ) ) die( 'ERROR_2' );
			
			// User can not pay for this
			if ( !$this->can_pay( $booking ) ) {
				$message = $this->prefs['messages']['error'];
				$status = 'ERROR';
				
				// Let others play
				do_action( 'mycred_em_booking_cantpay', $booking, $this );
			}
			// User has not yet paid
			elseif ( !$this->has_paid( $booking ) ) {
				// Price
				$price = $this->core->number( $booking->booking_price );
				if ( !$this->single_currency() ) {
					$exchange_rate = $this->prefs['rate'];
					$price = $this->core->number( $exchange_rate*$price );
				}

				// Charge
				$this->core->add_creds(
					'ticket_purchase',
					$booking->person->ID,
					0-$price,
					$this->prefs['log']['purchase'],
					$booking->event->post_id,
					array( 'ref_type' => 'post', 'bid' => $booking_id )
				);

				// Update Booking if approval is required (with option to disable this feature)
				if ( get_option( 'dbem_bookings_approval' ) == 1 && apply_filters( 'mycred_em_approve_on_pay', true, $booking, $this ) )
					$booking->approve();

				$message = $this->prefs['messages']['success'];
				$status = 'OK';

				// Let others play
				do_action( 'mycred_em_booking_paid', $booking, $this );
			}
			else {
				$message = '';
				$status = '';
			}
			
			die( json_encode( array( 'status' => $status, 'message' => $message ) ) );
		}

		/**
		 * Refunds
		 * @since 1.2
		 * @version 1.0
		 */
		public function refunds( $result, $EM_Booking ) {
			// Cancellation
			if ( $EM_Booking->booking_status == 3 && $EM_Booking->previous_status != 3 ) {
				$refund = $this->prefs['refund'];
				
				// Make sure user has paid for this to refund
				if ( $this->has_paid( $EM_Booking ) ) {
					// Price
					$price = $EM_Booking->booking_price;
					if ( $this->single_currency() )
						$price = $this->core->number( $price );
					else {
						$exchange_rate = $this->prefs['rate'];
						$price = $this->core->number( $exchange_rate*$price );
					}
					
					$event_id = $EM_Booking->get_event();
				
					// Charge
					$this->core->add_creds(
						'ticket_purchase_refund',
						$EM_Booking->person->ID,
						$price,
						$this->prefs['log']['refund'],
						$EM_Booking->event->post_id,
						array( 'ref_type' => 'post', 'bid' => $booking_id )
					);
				}
			}
			return $result;
		}

		/**
		 * Adjust Ticket Columns
		 * @since 1.2
		 * @version 1.0
		 */
		public function ticket_columns( $columns, $EM_Event ) {
			if ( !$EM_Event->is_free() ) {
				unset( $columns['price'] );
				unset( $columns['type'] );
				unset( $columns['spaces'] );

				$columns['type'] = __( 'Ticket Type', 'mycred' );

				if ( $this->single_currency() ) {
					$columns['mycred'] = __( 'Price', 'mycred' );
				}
				else {
					$columns['price'] = __( 'Price', 'mycred' );
					$columns['mycred'] = $this->core->plural();
				}
				$columns['spaces'] = __( 'Spaces', 'mycred' );
			}

			$this->booking_cols = count( $columns );
			return $columns;
		}

		/**
		 * Adjust Ticket Column Content
		 * @since 1.2
		 * @version 1.0
		 */
		public function ticket_col( $EM_Ticket, $EM_Event ) {
			if ( $this->single_currency() )
				$price = $EM_Ticket->get_price(true);
			else
				$price = $this->prefs['rate']*$EM_Ticket->get_price(true); ?>

<td class="em-bookings-ticket-table-points"><?php echo $this->core->format_creds( $price ); ?></td>
<?php
		}

		/**
		 * Add Pay Action
		 * @since 1.2
		 * @version 1.0
		 */
		public function add_pay_button( $cancel_link, $EM_Booking ) {
			if ( $this->can_pay( $EM_Booking ) && !$this->has_paid( $EM_Booking ) ) {
				if ( !empty( $cancel_link ) )
					$cancel_link .= ' &bull; ';

				$cancel_link .= '<a href="javascript:void(0)" class="mycred-show-pay" data-booking="' . $EM_Booking->booking_id . '">' . $this->prefs['labels']['link'] . '</a>';
			}
			return $cancel_link;
		}

		/**
		 * Payment Box
		 * @since 1.2
		 * @version 1.0
		 */
		public function payment_box( $EM_Booking ) {
			if ( $EM_Booking->booking_status == 0 ) {
				$balance = $this->core->get_users_cred( $EM_Booking->person->ID );
				if ( $balance <= 0 ) return;

				$price = $EM_Booking->booking_price;
				if ( $price == 0 ) return;
				if ( !$this->single_currency() ) {
					$exchange_rate = $this->prefs['rate'];
					$price = $this->core->number( $exchange_rate*$price );
				} ?>

								<tr id="mycred-payment-<?php echo $EM_Booking->booking_id; ?>" style="display: none;">
									<td colspan="5">
										<h5><?php echo $this->core->template_tags_general( $this->prefs['labels']['header'] ); ?></h5>
										<?php do_action( 'mycred_em_before_payment_box', $this ); ?>

										<table style="width:100%;margin-bottom: 0;">
											<tr>
												<td class="info"><?php _e( 'Current Balance', 'mycred' ); ?></td>
												<td class="amount"><?php echo $this->core->format_creds( $balance ); ?></td>
											</tr>
											<tr>
												<td class="info"><?php _e( 'Total Cost', 'mycred' ); ?></td>
												<td class="amount"><?php echo $this->core->format_creds( $price ); ?></td>
											</tr>
											<tr>
												<td class="info"><?php _e( 'Balance After Payment', 'mycred' ); ?></td>
												<td class="amount"><?php echo $this->core->format_creds( $balance-$price ); ?></td>
											</tr>
											<tr>
												<td colspan="2" class="action" style="text-align: right;">
													<input type="hidden" name="mycred-booking-<?php echo $EM_Booking->booking_id; ?>" value="<?php echo $EM_Booking->booking_id; ?>" />
													<input type="hidden" name="mycred-booking-<?php echo $EM_Booking->booking_id; ?>-token" value="<?php echo wp_create_nonce( 'mycred-pay-booking' ); ?>" />
													<input type="button" class="button button-primary button-medium mycred-pay" value="<?php echo $this->prefs['labels']['button']; ?>" />
												</td>
											</tr>
										</table>
										<p id="mycred-message-<?php echo $EM_Booking->booking_id; ?>"></p>
										<?php do_action( 'mycred_em_after_payment_box', $this ); ?>

									</td>
								</tr>
<?php
			}
		}

		/**
		 * Payment Box Scripting
		 * @since 1.2
		 * @version 1.0
		 */
		public function insert_scripting() {
			$ajax_url = admin_url( 'admin-ajax.php' ); ?>

<script type="text/javascript">
jQuery(function($) {
	$('a.mycred-show-pay').click(function() {
		var box = $(this).attr( 'data-booking' );
		$('tr#mycred-payment-'+box).toggle();
	});

	$('input.mycred-pay').click(function() {
		var button = $(this);
		var label = $(this).val();
		var token = button.prev().val();
		var booking = button.prev().prev().val();
		var table = button.parent().parent().parent();
		var message = $('p#mycred-message-'+booking);

		console.log( 'Token: ' + token );
		$.ajax({
			type : "POST",
			data : {
				action     : 'mycred-pay-em-booking',
				token      : token,
				booking_id : booking
			},
			dataType : "JSON",
			url : '<?php echo $ajax_url; ?>',
			// Before we start
			beforeSend : function() {
				button.val( 'Processing...' );
				button.attr( 'disabled', 'disabled' );
			},
			// On Successful Communication
			success    : function( data ) {
				if ( data.status == 'OK' ) {
					table.hide();
					message.show();
					message.html( data.message );
				}
				else {
					button.attr( 'disabled', 'disabled' );
					button.hide().delay( 1000 );
					message.show();
					message.html( data.message );
				}
				
				// Debug
				console.log( data );
			},
			// Error (sent to console)
			error      : function( jqXHR, textStatus, errorThrown ) {
				// Debug - uncomment to use
				console.log( jqXHR + ' : ' + textStatus + ' : ' + errorThrown );
				button.val( 'Error - Try Again' );
				button.removeAttr( 'disabled' );
			}
		});
	});
});
</script>
<?php
		}

		/**
		 * Gateway Settings
		 * @since 1.2
		 * @version 1.0
		 */
		public function settings_page() {
			if ( $this->prefs['setup'] == 'multi' )
				$box = 'display: block;';
			else
				$box = 'display: none;';

			$exchange_message = sprintf(
				__( 'How many %s is 1 %s worth?', 'mycred' ),
				$this->core->plural(),
				em_get_currency_symbol()
			); ?>

<div class="postbox" id="em-opt-mycred">
				<div class="handlediv" title="<?php _e( 'Click to toggle', 'mycred' ); ?>"><br /></div><h3><span><?php echo sprintf( __( '%s Payments', 'mycred' ), $this->label ); ?></span></h3>
				<div class="inside">
					<?php do_action( 'mycred_em_before_settings', $this ); ?>

					<h4><?php _e( 'Setup', 'mycred' ); ?></h4>
					<table class="form-table">
						<tr>
							<th scope="row"><?php _e( 'Payments', 'mycred' ); ?></th>
							<td>
								<input type="radio" name="mycred_gateway[setup]" id="mycred-gateway-setup-off" value="off"<?php checked( $this->prefs['setup'], 'off' ); ?> /> <label for="mycred-gateway-setup-off"><?php echo $this->core->template_tags_general( __( 'Disabled - Users CAN NOT pay for tickets using %plural%.', 'mycred' ) ); ?></label><br />
								<input type="radio" name="mycred_gateway[setup]" id="mycred-gateway-setup-single" value="single"<?php checked( $this->prefs['setup'], 'single' ); ?> /> <label for="mycred-gateway-setup-single"><?php echo $this->core->template_tags_general( __( 'Single - Users can ONLY pay for tickets using %plural%.', 'mycred' ) ); ?></label><br />
								<input type="radio" name="mycred_gateway[setup]" id="mycred-gateway-setup-multi" value="multi"<?php checked( $this->prefs['setup'], 'multi' ); ?> /> <label for="mycred-gateway-setup-multi"><?php echo $this->core->template_tags_general( __( 'Multi - Users can pay for tickets using other gateways or %plural%.', 'mycred' ) ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Refunds', 'mycred' ); ?></th>
							<td>
								<input name="mycred_gateway[refund]" type="text" id="mycred-gateway-log-refund" value="<?php echo $this->prefs['refund']; ?>" size="5" /> %<br />
								<span class="description"><?php _e( 'The percentage of the paid amount to refund if a booking gets cancelled. User zero for no refunds. No refunds are given to "Rejected" bookings.', 'mycred' ); ?></span>
							</td>
						</tr>
					</table>
					<table class="form-table" id="mycred-exchange-rate" style="<?php echo $box; ?>">
						<tr>
							<th scope="row"><?php _e( 'Exchange Rate', 'mycred' ); ?></th>
							<td>
								<input name="mycred_gateway[rate]" type="text" id="mycred-gateway-rate" size="6" value="<?php echo $this->prefs['rate']; ?>" /><br />
								<span class="description"><?php echo $exchange_message; ?></span>
							</td>
						</tr>
					</table>
					<h4><?php _e( 'Log Templates', 'mycred' ); ?></h4>
					<table class="form-table">
						<tr>
							<th scope="row"><?php _e( 'Purchases', 'mycred' ); ?></th>
							<td>
								<input name="mycred_gateway[log][purchase]" type="text" id="mycred-gateway-log-purchase" style="width: 95%;" value="<?php echo $this->prefs['log']['purchase']; ?>" size="45" /><br />
								<span class="description"><?php _e( 'Available template tags: General and Post related.', 'mycred' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Refunds', 'mycred' ); ?></th>
							<td>
								<input name="mycred_gateway[log][refund]" type="text" id="mycred-gateway-log-refund" style="width: 95%;" value="<?php echo $this->prefs['log']['refund']; ?>" size="45" /><br />
								<span class="description"><?php _e( 'Available template tags: General and Post related.', 'mycred' ); ?></span>
							</td>
						</tr>
					</table>
<script type="text/javascript">
jQuery(function($){
	$('input[name="mycred_gateway[setup]"]').change(function(){
		if ( $(this).val() == 'multi' ) {
			$('#mycred-exchange-rate').show();
		}
		else {
			$('#mycred-exchange-rate').hide();
		}
	});
});
</script>
					<h4><?php _e( 'Labels', 'mycred' ); ?></h4>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><?php _e( 'Payment Link Label', 'mycred' ); ?></th>
							<td>
								<input name="mycred_gateway[labels][link]" type="text" id="mycred-gateway-labels-link" style="width: 95%" value="<?php echo $this->prefs['labels']['link']; ?>" size="45" /><br />
								<span class="description"><?php _e( 'The payment link shows / hides the payment form under "My Bookings". No HTML allowed.', 'mycred' ); ?></span>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php _e( 'Payment Header', 'mycred' ); ?></th>
							<td>
								<input name="mycred_gateway[labels][header]" type="text" id="mycred-gateway-labels-header" style="width: 95%" value="<?php echo $this->prefs['labels']['header']; ?>" size="45" /><br />
								<span class="description"><?php _e( 'Shown on top of the payment form. No HTML allowed.', 'mycred' ); ?></span>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php _e( 'Button Label', 'mycred' ); ?></th>
							<td>
								<input name="mycred_gateway[labels][button]" type="text" id="mycred-gateway-labels-button" style="width: 95%" value="<?php echo $this->prefs['labels']['button']; ?>" size="45" /><br />
								<span class="description"><?php _e( 'The button label for payments. No HTML allowed!', 'mycred' ); ?></span>
							</td>
						</tr>
					</table>
					<h4><?php _e( 'Messages', 'mycred' ); ?></h4>
					<table class='form-table'>
						<tr valign="top">
							<th scope="row"><?php _e( 'Successful Payments', 'mycred' ); ?></th>
							<td>
								<input type="text" name="mycred_gateway[messages][success]" id="mycred-gateway-messages-success" style="width: 95%;" value="<?php echo stripslashes( $this->prefs['messages']['success'] ); ?>" /><br />
								<span class="description"><?php _e( 'No HTML allowed! Available template tags: General', 'mycred' ); ?></span>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php _e( 'Insufficient Funds', 'mycred' ); ?></th>
							<td>
								<input type="text" name="mycred_gateway[messages][error]" id="mycred-gateway-messages-error" style="width: 95%;" value="<?php echo stripslashes( $this->prefs['messages']['error'] ); ?>" /><br />
								<span class="description"><?php _e( 'No HTML allowed! Available template tags: General', 'mycred' ); ?></span>
							</td>
						</tr>
					</table>
					<?php do_action( 'mycred_em_after_settings', $this ); ?>

				</div> <!-- . inside -->
				</div> <!-- .postbox -->
<?php
		}

		/**
		 * Save Settings
		 * @since 1.2
		 * @version 1.0
		 */
		public function save_settings() {
			if ( !isset( $_POST['mycred_gateway'] ) || !is_array( $_POST['mycred_gateway'] ) ) return;

			// Prep
			$data = $_POST['mycred_gateway'];
			$new_settings = array();

			// Setup
			$new_settings['setup'] = $data['setup'];
			$new_settings['refund'] = abs( $data['refund'] );

			// Logs
			$new_settings['log']['purchase'] = trim( stripslashes( $data['log']['purchase'] ) );
			$new_settings['log']['refund'] = trim( stripslashes( $data['log']['refund'] ) );
			
			if ( $new_settings['setup'] == 'multi' )
				$new_settings['rate'] = sanitize_text_field( $data['rate'] );
			else
				$new_settings['rate'] = $this->prefs['rate'];

			// Override Pricing Options
			if ( $new_settings['setup'] == 'single' ) {
				update_option( 'dbem_bookings_currency_decimal_point', $this->core->format['separators']['decimal'] );
				update_option( 'dbem_bookings_currency_thousands_sep', $this->core->format['separators']['thousand'] );
				update_option( 'dbem_bookings_currency', 'XMY' );
				if ( empty( $this->core->before ) && !empty( $this->core->after ) )
					$format = '@ #';
				elseif ( !empty( $this->core->before ) && empty( $this->core->after ) )
					$format = '# @';
				update_option( 'dbem_bookings_currency_format', $format );
			}

			// Labels
			$new_settings['labels']['link'] = sanitize_text_field( stripslashes( $data['labels']['link'] ) );
			$new_settings['labels']['header'] = sanitize_text_field( stripslashes( $data['labels']['header'] ) );
			$new_settings['labels']['button'] = sanitize_text_field( stripslashes( $data['labels']['button'] ) );

			// Messages
			$new_settings['messages']['success'] = sanitize_text_field( stripslashes( $data['messages']['success'] ) );
			$new_settings['messages']['error'] = sanitize_text_field( stripslashes( $data['messages']['error'] ) );

			// Save Settings
			$current = $this->prefs;
			$this->prefs = mycred_apply_defaults( $current, $new_settings );
			update_option( 'mycred_eventsmanager_gateway_prefs', $this->prefs );

			// Let others play
			do_action( 'mycred_em_save_settings', $this );
		}
	}

	$events = new myCRED_Events_Manager_Gateway();
	$events->load();
}
?>