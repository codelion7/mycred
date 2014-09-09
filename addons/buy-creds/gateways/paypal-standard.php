<?php
if ( ! defined( 'myCRED_VERSION' ) ) exit;

/**
 * myCRED_PayPal class
 * PayPal Payments Standard - Payment Gateway
 * @since 0.1
 * @version 1.2
 */
if ( ! class_exists( 'myCRED_PayPal_Standard' ) ) {
	class myCRED_PayPal_Standard extends myCRED_Payment_Gateway {

		/**
		 * Construct
		 */
		function __construct( $gateway_prefs ) {
			$types = mycred_get_types();
			$default_exchange = array();
			foreach ( $types as $type => $label )
				$default_exchange[ $type ] = 1;

			parent::__construct( array(
				'id'               => 'paypal-standard',
				'label'            => 'PayPal',
				'gateway_logo_url' => plugins_url( 'assets/images/paypal.png', myCRED_PURCHASE ),
				'defaults'         => array(
					'sandbox'          => 0,
					'currency'         => '',
					'account'          => '',
					'item_name'        => __( 'Purchase of myCRED %plural%', 'mycred' ),
					'exchange'         => $default_exchange
				)
			), $gateway_prefs );
		}

		/**
		 * IPN - Is Valid Call
		 * Replaces the default check
		 * @since 1.4
		 * @version 1.0
		 */
		public function IPN_is_valid_call() {
			// PayPal Host
			if ( $this->sandbox_mode )
				$host = 'www.sandbox.paypal.com';
			else
				$host = 'www.paypal.com';

			$data = $this->POST_to_data();

			// Prep Respons
			$request = 'cmd=_notify-validate';
			$get_magic_quotes_exists = false;
			if ( function_exists( 'get_magic_quotes_gpc' ) )
				$get_magic_quotes_exists = true;

			foreach ( $data as $key => $value ) {
				if ( $get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1 )
					$value = urlencode( stripslashes( $value ) );
				else
					$value = urlencode( $value );

				$request .= "&$key=$value";
			}

			// Call PayPal
			$curl_attempts = apply_filters( 'mycred_paypal_standard_max_attempts', 3 );
			$attempt = 1;
			$result = '';
			// We will make a x number of curl attempts before finishing with a fsock.
			do {

				$call = curl_init( "https://$host/cgi-bin/webscr" );
				curl_setopt( $call, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
				curl_setopt( $call, CURLOPT_POST, 1 );
				curl_setopt( $call, CURLOPT_RETURNTRANSFER, 1 );
				curl_setopt( $call, CURLOPT_POSTFIELDS, $request );
				curl_setopt( $call, CURLOPT_SSL_VERIFYPEER, 1 );
				curl_setopt( $call, CURLOPT_CAINFO, myCRED_PURCHASE_DIR . '/cacert.pem' );
				curl_setopt( $call, CURLOPT_SSL_VERIFYHOST, 2 );
				curl_setopt( $call, CURLOPT_FRESH_CONNECT, 1 );
				curl_setopt( $call, CURLOPT_FORBID_REUSE, 1 );
				curl_setopt( $call, CURLOPT_HTTPHEADER, array( 'Connection: Close' ) );
				$result = curl_exec( $call );

				// End on success
				if ( $result !== false ) {
					curl_close( $call );
					break;
				}

				curl_close( $call );

				// Final try
				if ( $attempt == $curl_attempts ) {
					$header = "POST /cgi-bin/webscr HTTP/1.0\r\n";
					$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
					$header .= "Content-Length: " . strlen( $request ) . "\r\n\r\n";
					$fp = fsockopen( 'ssl://' . $host, 443, $errno, $errstr, 30 );
					if ( $fp ) {
						fputs( $fp, $header . $request );
						while ( ! feof( $fp ) ) {
							$result = fgets( $fp, 1024 );
						}
						fclose( $fp );
					}
				}
				$attempt++;

			} while ( $attempt <= $curl_attempts );
			
			if ( strcmp( $result, "VERIFIED" ) == 0 ) {
				return true;
			}

			return false;
		}

		/**
		 * Process Handler
		 * @since 0.1
		 * @version 1.3
		 */
		public function process() {

			// Required fields
			if ( isset( $_POST['custom'] ) && isset( $_POST['txn_id'] ) && isset( $_POST['mc_gross'] ) ) {

				// Get Pending Payment
				$pending_post_id = sanitize_key( $_POST['custom'] );
				$pending_payment = $this->get_pending_payment( $pending_post_id );
				if ( $pending_payment !== false ) {

					// Verify Call with PayPal
					if ( $this->IPN_is_valid_call() ) {

						$errors = false;
						$new_call = array();

						// Check amount paid
						if ( $_POST['mc_gross'] != $pending_payment['cost'] ) {
							$new_call[] = sprintf( __( 'Price mismatch. Expected: %s Received: %s', 'mycred' ), $pending_payment['cost'], $_POST['mc_gross'] );
							$errors = true;
						}

						// Check currency
						if ( $_POST['mc_currency'] != $pending_payment['currency'] ) {
							$new_call[] = sprintf( __( 'Currency mismatch. Expected: %s Received: %s', 'mycred' ), $pending_payment['currency'], $_POST['mc_currency'] );
							$errors = true;
						}

						// Check status
						if ( $_POST['payment_status'] != 'Completed' ) {
							$new_call[] = sprintf( __( 'Payment not completed. Received: %s', 'mycred' ), $_POST['payment_status'] );
							$errors = true;
						}

						// Credit payment
						if ( $errors === false ) {

							// If account is credited, delete the post and it's comments.
							if ( ! $this->complete_payment( $pending_payment, $_POST['txn_id'] ) )
								$this->trash_pending_payment( $pending_post_id );
							else
								$new_call[] = __( 'Failed to credit users account.', 'mycred' );

						}
						
						// Log Call
						if ( ! empty( $new_call ) )
							$this->log_call( $pending_post_id, $new_call );

					}
					
				}
			
			}

		}

		/**
		 * Results Handler
		 * @since 0.1
		 * @version 1.0.1
		 */
		public function returning() {
			if ( isset( $_REQUEST['tx'] ) && isset( $_REQUEST['st'] ) && $_REQUEST['st'] == 'Completed' ) {
				$this->get_page_header( __( 'Success', 'mycred' ), $this->get_thankyou() );
				echo '<h1 style="text-align:center;">' . __( 'Thank you for your purchase', 'mycred' ) . '</h1>';
				$this->get_page_footer();
				exit;
			}
		}

		/**
		 * Buy Handler
		 * @since 0.1
		 * @version 1.2
		 */
		public function buy() {
			if ( ! isset( $this->prefs['account'] ) || empty( $this->prefs['account'] ) )
				wp_die( __( 'Please setup this gateway before attempting to make a purchase!', 'mycred' ) );

			// Location
			if ( $this->sandbox_mode )
				$location = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
			else
				$location = 'https://www.paypal.com/cgi-bin/webscr';

			// Type
			$type = $this->get_point_type();
			$mycred = mycred( $type );

			// Amount
			$amount = $mycred->number( $_REQUEST['amount'] );
			$amount = abs( $amount );

			// Get Cost
			$cost = $this->get_cost( $amount, $type );

			$to = $this->get_to();
			$from = $this->current_user_id;

			// Revisiting pending payment
			if ( isset( $_REQUEST['revisit'] ) ) {
				$this->transaction_id = strtoupper( $_REQUEST['revisit'] );
			}
			else {
				$post_id = $this->add_pending_payment( array( $to, $from, $amount, $cost, $this->prefs['currency'], $type ) );
				$this->transaction_id = get_the_title( $post_id );
			}

			// Thank you page
			$thankyou_url = $this->get_thankyou();

			// Cancel page
			$cancel_url = $this->get_cancelled( $this->transaction_id );

			// Item Name
			$item_name = str_replace( '%number%', $amount, $this->prefs['item_name'] );
			$item_name = $mycred->template_tags_general( $item_name );

			// Hidden form fields
			$hidden_fields = array(
				'cmd'           => '_xclick',
				'business'      => $this->prefs['account'],
				'item_name'     => $item_name,
				'quantity'      => 1,
				'amount'        => $cost,
				'currency_code' => $this->prefs['currency'],
				'no_shipping'   => 1,
				'no_note'       => 1,
				'custom'        => $this->transaction_id,
				'return'        => $thankyou_url,
				'notify_url'    => $this->callback_url(),
				'rm'            => 2,
				'cbt'           => __( 'Return to ', 'mycred' ) . get_bloginfo( 'name' ),
				'cancel_return' => $cancel_url
			);

			// Generate processing page
			$this->get_page_header( __( 'Processing payment &hellip;', 'mycred' ) );
			$this->get_page_redirect( $hidden_fields, $location );
			$this->get_page_footer();

			// Exit
			unset( $this );
			exit;
		}

		/**
		 * Preferences
		 * @since 0.1
		 * @version 1.0
		 */
		function preferences() {
			$prefs = $this->prefs; ?>

<label class="subheader" for="<?php echo $this->field_id( 'currency' ); ?>"><?php _e( 'Currency', 'mycred' ); ?></label>
<ol>
	<li>
		<?php $this->currencies_dropdown( 'currency', 'mycred-gateway-paypal-currency' ); ?>
	</li>
</ol>
<label class="subheader" for="<?php echo $this->field_id( 'account' ); ?>"><?php _e( 'Account Email', 'mycred' ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( 'account' ); ?>" id="<?php echo $this->field_id( 'account' ); ?>" value="<?php echo $prefs['account']; ?>" class="long" /></div>
	</li>
</ol>
<label class="subheader" for="<?php echo $this->field_id( 'item_name' ); ?>"><?php _e( 'Item Name', 'mycred' ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( 'item_name' ); ?>" id="<?php echo $this->field_id( 'item_name' ); ?>" value="<?php echo $prefs['item_name']; ?>" class="long" /></div>
		<span class="description"><?php _e( 'Description of the item being purchased by the user.', 'mycred' ); ?></span>
	</li>
</ol>
<label class="subheader"><?php _e( 'Exchange Rates', 'mycred' ); ?></label>
<ol>
	<?php $this->exchange_rate_setup(); ?>
</ol>
<label class="subheader"><?php _e( 'IPN Address', 'mycred' ); ?></label>
<ol>
	<li>
		<code style="padding: 12px;display:block;"><?php echo $this->callback_url(); ?></code>
		<p><?php _e( 'For this gateway to work, you must login to your PayPal account and under "Profile" > "Selling Tools" enable "Instant Payment Notifications". Make sure the "Notification URL" is set to the above address and that you have selected "Receive IPN messages (Enabled)".', 'mycred' ); ?></p>
	</li>
</ol>
<?php
		}

		/**
		 * Sanatize Prefs
		 * @since 0.1
		 * @version 1.3
		 */
		public function sanitise_preferences( $data ) {
			$new_data = array();

			$new_data['sandbox']   = ( isset( $data['sandbox'] ) ) ? 1 : 0;
			$new_data['currency']  = sanitize_text_field( $data['currency'] );
			$new_data['account']   = sanitize_text_field( $data['account'] );
			$new_data['item_name'] = sanitize_text_field( $data['item_name'] );

			// If exchange is less then 1 we must start with a zero
			if ( isset( $data['exchange'] ) ) {
				foreach ( (array) $data['exchange'] as $type => $rate ) {
					if ( $rate != 1 && in_array( substr( $rate, 0, 1 ), array( '.', ',' ) ) )
						$data['exchange'][ $type ] = (float) '0' . $rate;
				}
			}
			$new_data['exchange'] = $data['exchange'];

			return $new_data;
		}
	}
}
?>